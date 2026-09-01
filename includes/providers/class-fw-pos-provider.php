<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The POS provider contract — the mirror image of FW_POS_Store, on the till side
 * of the ledger.
 *
 * A provider driver's entire job is to turn one vendor's webhook into normalized
 * ledger events. It never touches a cart, never writes stock, and never knows
 * which e-commerce plugin is installed. That separation is the whole reason
 * adding a till and adding a cart are independent jobs.
 *
 * ## Every vendor signs differently, and that is fine
 *
 * The generic endpoint uses our scheme (`{timestamp}\n{body}`). Square signs
 * `notification_url + body` and base64-encodes it. Clover does something else
 * again. There is no point pretending otherwise, so `verify_webhook()` is the
 * provider's own problem and the framework does not impose a shape on it.
 *
 * What IS imposed is what comes out: `normalize()` returns events in the same
 * shape the generic endpoint produces, so everything downstream — idempotency,
 * ordering, matching, the store seam — is identical whether the event came from
 * a Square webhook or a shell script.
 *
 * ## Optional capabilities are concrete, not abstract
 *
 * `backfill()` and `import_catalog()` have working no-op defaults. A provider
 * that can only receive webhooks should be implementable without writing stubs
 * that throw — the same reasoning as `FW_POS_Store::search_products()`.
 *
 * @see https://docs.unysonplus.com/extensions/pos-sync/architecture
 */
abstract class FW_POS_Provider {

	/**
	 * Machine id: 'square', 'clover', …
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Is this connection usable — connected, with credentials that have not
	 * been revoked?
	 *
	 * @param array $connection
	 *
	 * @return bool
	 */
	abstract public function is_connected( array $connection );

	/**
	 * Verify an inbound webhook using the vendor's own scheme.
	 *
	 * @param array  $connection
	 * @param string $raw_body Bytes exactly as received.
	 * @param array  $headers  Lowercased header name => value.
	 * @param string $url      The notification URL the vendor was configured with.
	 *
	 * @return array{ok:bool,code:string}
	 */
	abstract public function verify_webhook( array $connection, $raw_body, array $headers, $url );

	/**
	 * Turn a vendor payload into zero or more normalized ledger events.
	 *
	 * Zero is a perfectly good answer — most vendors send event types we do not
	 * care about, and silently ignoring them is correct.
	 *
	 * @param array $connection
	 * @param array $payload
	 *
	 * @return array[] Each in FW_POS_Ledger::record_event() shape.
	 */
	abstract public function normalize( array $connection, array $payload );

	/* ---------------------------------------------------------------------- *
	 * Optional — concrete, with safe defaults
	 * ---------------------------------------------------------------------- */

	/**
	 * How much this driver has been proven — `stable` or `experimental`.
	 *
	 * See FW_POS_Store::maturity(). It matters more here: a provider driver
	 * decides what reaches the ledger at all.
	 *
	 * @return string
	 */
	public function maturity() {
		return 'stable';
	}

	/**
	 * Pull recent history so the ledger does not start empty.
	 *
	 * @param array $connection
	 * @param int   $since Unix timestamp.
	 *
	 * @return array{ok:bool,count:int,error:string}
	 */
	public function backfill( array $connection, $since ) {
		return [
			'ok'    => true,
			'count' => 0,
			'error' => '',
		];
	}

	/**
	 * Pull the vendor catalog and record what it contains, so unmatched items
	 * can be mapped before the first sale rather than during it.
	 *
	 * @param array $connection
	 *
	 * @return array{ok:bool,seen:int,matched:int,error:string}
	 */
	public function import_catalog( array $connection ) {
		return [
			'ok'      => true,
			'seen'    => 0,
			'matched' => 0,
			'error'   => '',
		];
	}

	/**
	 * Authoritative stock levels straight from the vendor, keyed by SKU.
	 *
	 * This is what reconciliation compares against, and it is deliberately a
	 * separate call rather than something derived from the event stream: the
	 * whole point of a sweep is to catch what the event stream MISSED, so
	 * deriving it from those same events would only confirm its own gaps.
	 *
	 * Returning nothing is a valid answer — a provider with no inventory API
	 * simply cannot be reconciled, and saying so beats inventing numbers.
	 *
	 * @param array $connection
	 * @param array $skus Restrict to these; empty means everything known.
	 *
	 * @return array<string,int>
	 */
	public function fetch_counts( array $connection, array $skus = [] ) {
		return [];
	}

	/**
	 * The vendor locations available on this connection, for mapping.
	 *
	 * @param array $connection
	 *
	 * @return array[] Each: { id, name }
	 */
	public function locations( array $connection ) {
		return [];
	}

	/* ---------------------------------------------------------------------- *
	 * Credential storage — shared, because every OAuth provider needs it
	 * ---------------------------------------------------------------------- */

	/**
	 * Read a connection's provider credentials.
	 *
	 * @param array $connection
	 *
	 * @return array
	 */
	public static function credentials( array $connection ) {
		$raw = isset( $connection['credentials'] ) ? (string) $connection['credentials'] : '';

		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( FW_POS_Secrets::reveal( $raw ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Write a connection's provider credentials, merging onto what is there.
	 *
	 * Merging rather than replacing on purpose: a token refresh returns a new
	 * access token and, on some providers, no refresh token — and a replace
	 * would quietly discard the one thing needed to refresh again.
	 *
	 * @param int   $connection_id
	 * @param array $credentials
	 *
	 * @return bool
	 */
	public static function store_credentials( $connection_id, array $credentials ) {
		global $wpdb;

		$existing = FW_POS_Connections::get( $connection_id );
		$merged   = array_merge( $existing ? self::credentials( $existing ) : [], $credentials );

		return false !== $wpdb->update(
			FW_POS_Schema::table( 'connections' ),
			[
				'credentials' => FW_POS_Secrets::protect( wp_json_encode( $merged ) ),
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $connection_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Forget a connection's provider credentials, leaving the connection itself
	 * and its event history intact.
	 *
	 * @param int $connection_id
	 *
	 * @return bool
	 */
	public static function clear_credentials( $connection_id ) {
		global $wpdb;

		return false !== $wpdb->update(
			FW_POS_Schema::table( 'connections' ),
			[
				'credentials' => '',
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $connection_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}
}
