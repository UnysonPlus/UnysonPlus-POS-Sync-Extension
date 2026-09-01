<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Clover provider driver.
 *
 * ## Written against the documented API, not a live merchant
 *
 * Same caveat as the FluentCart and SureCart store drivers, and it matters more
 * here because a provider driver decides what reaches the ledger. It is handled
 * the same way: the driver is marked **experimental**, and everything it cannot
 * do confidently it refuses rather than guesses.
 *
 * ## Clover's webhooks are a notification, not a payload
 *
 * This is the structural difference from Square and the thing that shapes the
 * whole driver. Square sends you the payment. Clover sends you something closer
 * to a doorbell:
 *
 *     { "appId": "...", "merchants": { "MID": [
 *         { "objectId": "O:XYZ", "type": "CREATE", "ts": 1788000000 }
 *     ] } }
 *
 * `objectId` is a *type-prefixed reference* (`O:` order, `P:` payment,
 * `I:` inventory item) and you must fetch the object yourself. So a Clover
 * webhook costs at least one API round trip before anything can be recorded,
 * and a burst of till activity is a burst of fetches. The queue absorbs that,
 * but it is why `normalize()` here does real work rather than reshaping a
 * payload.
 *
 * ## Verification is a shared code, not a signature
 *
 * Clover sends `X-Clover-Auth` containing the verification code you configured,
 * rather than an HMAC over the body. That is materially weaker than Square's
 * scheme — it proves the sender knows a secret, but not that the body is
 * untampered. It is compared with `hash_equals()` all the same, and the
 * consequences are bounded by the fact that the driver **re-fetches every
 * object from Clover's API** before recording anything: a forged body can at
 * worst make us ask Clover about an id, and Clover's answer is what we act on.
 *
 * That is a genuinely useful property of the doorbell design, and it is why
 * this driver is safe to ship despite the weaker verification.
 */
class FW_POS_Provider_Clover extends FW_POS_Provider {

	const AUTH_HEADER = 'x-clover-auth';

	const HOST_PRODUCTION = 'https://api.clover.com';
	const HOST_SANDBOX    = 'https://apisandbox.dev.clover.com';

	/**
	 * @return string
	 */
	public function get_id() {
		return 'clover';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'Clover', 'fw' );
	}

	/**
	 * Not verified against a live merchant. Surfaced in the UI so nobody
	 * discovers it during a trading day.
	 *
	 * @return string
	 */
	public function maturity() {
		return 'experimental';
	}

	/**
	 * @param array $connection
	 *
	 * @return bool
	 */
	public function is_connected( array $connection ) {
		$credentials = self::credentials( $connection );

		return ! empty( $credentials['access_token'] ) && ! empty( $credentials['merchant_id'] );
	}

	/**
	 * @param array  $connection
	 * @param string $raw_body
	 * @param array  $headers
	 * @param string $url
	 *
	 * @return array{ok:bool,code:string}
	 */
	public function verify_webhook( array $connection, $raw_body, array $headers, $url ) {
		$credentials = self::credentials( $connection );
		$expected    = isset( $credentials['verification_code'] ) ? (string) $credentials['verification_code'] : '';
		$provided    = isset( $headers[ self::AUTH_HEADER ] ) ? (string) $headers[ self::AUTH_HEADER ] : '';

		if ( '' === $expected ) {
			return [
				'ok'   => false,
				'code' => 'no_verification_code',
			];
		}

		if ( '' === $provided ) {
			return [
				'ok'   => false,
				'code' => 'missing_clover_auth',
			];
		}

		if ( ! hash_equals( $expected, $provided ) ) {
			return [
				'ok'   => false,
				'code' => 'clover_auth_mismatch',
			];
		}

		return [
			'ok'   => true,
			'code' => '',
		];
	}

	/**
	 * Turn Clover's notification into events, fetching each referenced object.
	 *
	 * @param array $connection
	 * @param array $payload
	 *
	 * @return array[]
	 */
	public function normalize( array $connection, array $payload ) {
		if ( ! $this->is_connected( $connection ) ) {
			return [];
		}

		$credentials = self::credentials( $connection );
		$merchant    = (string) $credentials['merchant_id'];

		$notifications = isset( $payload['merchants'][ $merchant ] )
			? (array) $payload['merchants'][ $merchant ]
			: [];

		$events = [];

		foreach ( $notifications as $notification ) {
			$reference = isset( $notification['objectId'] ) ? (string) $notification['objectId'] : '';
			$change    = isset( $notification['type'] ) ? strtoupper( (string) $notification['type'] ) : '';

			// A DELETE tells us an object is gone. There is no sensible stock
			// movement to derive from that, and inventing one would be worse
			// than ignoring it.
			if ( '' === $reference || 'DELETE' === $change ) {
				continue;
			}

			list( $kind, $id ) = array_pad( explode( ':', $reference, 2 ), 2, '' );

			if ( 'O' !== strtoupper( $kind ) || '' === $id ) {
				// Only orders carry line items. Payment and inventory objects
				// are handled through the order they belong to, which avoids
				// counting the same sale twice from two notifications.
				continue;
			}

			$event = $this->order_event( $connection, $merchant, $id );

			if ( $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Fetch one order and turn it into a sale, if it is one.
	 *
	 * @param array  $connection
	 * @param string $merchant
	 * @param string $order_id
	 *
	 * @return array|null
	 */
	private function order_event( array $connection, $merchant, $order_id ) {
		$order = $this->request(
			$connection,
			sprintf( '/v3/merchants/%s/orders/%s', rawurlencode( $merchant ), rawurlencode( $order_id ) ),
			[ 'expand' => 'lineItems' ]
		);

		if ( ! $order || empty( $order['lineItems']['elements'] ) ) {
			return null;
		}

		// Only a paid order is a sale. An open tab is not stock leaving the
		// shop, and treating it as one would decrement on every keystroke at
		// the till.
		$state = isset( $order['state'] ) ? strtoupper( (string) $order['state'] ) : '';

		if ( 'PAID' !== $state && 'LOCKED' !== $state ) {
			return null;
		}

		$lines  = [];
		$counts = [];

		foreach ( (array) $order['lineItems']['elements'] as $line ) {
			// Clover repeats a line per unit rather than carrying a quantity,
			// so identical items arrive as several rows. Collapsing them here
			// is what stops a sale of three appearing as three sales of one.
			$sku = isset( $line['item']['code'] ) ? trim( (string) $line['item']['code'] ) : '';

			if ( '' === $sku ) {
				$sku = isset( $line['alternateName'] ) ? trim( (string) $line['alternateName'] ) : '';
			}

			if ( '' === $sku ) {
				continue;
			}

			if ( ! isset( $counts[ $sku ] ) ) {
				$counts[ $sku ] = [
					'sku'        => $sku,
					'name'       => isset( $line['name'] ) ? (string) $line['name'] : '',
					'quantity'   => 0,
					'unit_price' => isset( $line['price'] ) ? (int) $line['price'] : 0,
				];
			}

			$counts[ $sku ]['quantity']++;
		}

		foreach ( $counts as $line ) {
			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return null;
		}

		$occurred = isset( $order['modifiedTime'] ) ? (int) $order['modifiedTime'] : 0;

		// Clover timestamps are milliseconds.
		$occurred_at = $occurred
			? gmdate( 'Y-m-d\TH:i:s\Z', (int) floor( $occurred / 1000 ) )
			: gmdate( 'Y-m-d\TH:i:s\Z' );

		return [
			'connection_id' => (int) $connection['id'],
			'external_id'   => 'cl-order-' . $order_id,
			'type'          => FW_POS_Ledger::TYPE_SALE,
			'occurred_at'   => $occurred_at,
			'location_ref'  => (string) $connection['location_ref'],
			'payload'       => [
				'external_id'  => 'cl-order-' . $order_id,
				'occurred_at'  => $occurred_at,
				'location_ref' => (string) $connection['location_ref'],
				'currency'     => isset( $order['currency'] ) ? (string) $order['currency'] : 'USD',
				'total'        => isset( $order['total'] ) ? (int) $order['total'] : 0,
				'line_items'   => $lines,
				'meta'         => [ 'provider' => 'clover' ],
			],
		];
	}

	/**
	 * @param array  $connection
	 * @param string $path
	 * @param array  $query
	 *
	 * @return array|null
	 */
	private function request( array $connection, $path, array $query = [] ) {
		$credentials = self::credentials( $connection );
		$host        = ( isset( $credentials['environment'] ) && 'production' === $credentials['environment'] )
			? self::HOST_PRODUCTION
			: self::HOST_SANDBOX;

		$url = $host . $path;

		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $credentials['access_token'],
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
