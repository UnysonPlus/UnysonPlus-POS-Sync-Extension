<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Connections — one row per till or integration that may push events.
 *
 * The repository for the connections table, and the only class that writes SQL
 * against it. (The ledger owns the event and item tables; each repository owns
 * exactly one table, and nothing outside a repository writes SQL.)
 *
 * ## Why one connection per till, not one key per site
 *
 * A shop with three registers and a market stall wants to know *which* one sent
 * the event that looks wrong, revoke a stolen tablet without taking the shop
 * offline, and put a new integration in test mode while the others keep
 * trading. A single site-wide key makes all three impossible.
 *
 * ## Scopes
 *
 * A connection is granted only what it needs. A monitoring integration that
 * reads the log has no business holding `inventory:write`, and the cost of
 * making that distinction is one column.
 */
class FW_POS_Connections {

	const STATUS_ACTIVE  = 'active';
	const STATUS_REVOKED = 'revoked';

	const MODE_TEST = 'test';
	const MODE_LIVE = 'live';

	/**
	 * The grantable scopes, and what each one means.
	 *
	 * @return array<string,string>
	 */
	public static function scopes() {
		return [
			'sale:write'      => __( 'Report sales', 'fw' ),
			'refund:write'    => __( 'Report refunds and voids', 'fw' ),
			'inventory:write' => __( 'Report stock counts and adjustments', 'fw' ),
		];
	}

	/**
	 * The scope an event type requires.
	 *
	 * @param string $type
	 *
	 * @return string
	 */
	public static function scope_for_type( $type ) {
		switch ( $type ) {
			case FW_POS_Ledger::TYPE_REFUND:
			case FW_POS_Ledger::TYPE_VOID:
				return 'refund:write';

			case FW_POS_Ledger::TYPE_INVENTORY:
				return 'inventory:write';

			default:
				return 'sale:write';
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Writing
	 * ---------------------------------------------------------------------- */

	/**
	 * Create a connection and return it, including the plaintext secret.
	 *
	 * The secret is returned exactly once, here. It is not readable afterwards
	 * from the admin screen — not because it cannot be decrypted (it can, that
	 * is how signatures are verified) but because a credential that is casually
	 * visible on a settings page ends up in screenshots and support tickets.
	 * Losing it costs a rotation, which is cheap.
	 *
	 * @param array $args {
	 *     @type string $name
	 *     @type string $type         generic|square|…
	 *     @type string $mode         test|live
	 *     @type array  $scopes
	 *     @type string $location_ref
	 * }
	 *
	 * @return array{id:int,api_key:string,secret:string}|null
	 */
	public static function create( array $args ) {
		global $wpdb;

		$mode   = self::MODE_LIVE === ( isset( $args['mode'] ) ? $args['mode'] : '' ) ? self::MODE_LIVE : self::MODE_TEST;
		$key    = FW_POS_Secrets::generate_key( $mode );
		$secret = FW_POS_Secrets::generate_secret();
		$now    = current_time( 'mysql', true );

		$scopes = isset( $args['scopes'] ) && is_array( $args['scopes'] )
			? array_values( array_intersect( $args['scopes'], array_keys( self::scopes() ) ) )
			: array_keys( self::scopes() );

		$inserted = $wpdb->insert(
			FW_POS_Schema::table( 'connections' ),
			[
				'name'         => isset( $args['name'] ) ? substr( (string) $args['name'], 0, 190 ) : __( 'Untitled connection', 'fw' ),
				'type'         => isset( $args['type'] ) ? substr( (string) $args['type'], 0, 30 ) : 'generic',
				'api_key'      => $key,
				'secret'       => FW_POS_Secrets::protect( $secret ),
				'mode'         => $mode,
				'scopes'       => implode( ',', $scopes ),
				'location_ref' => isset( $args['location_ref'] ) ? substr( (string) $args['location_ref'], 0, 100 ) : '',
				'status'       => self::STATUS_ACTIVE,
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return null;
		}

		return [
			'id'      => (int) $wpdb->insert_id,
			'api_key' => $key,
			'secret'  => $secret,
		];
	}

	/**
	 * Issue a new secret for an existing connection, invalidating the old one.
	 *
	 * The key is deliberately NOT changed: rotating a compromised secret should
	 * be one field to update at the till, not a full reconfiguration. Revoking
	 * is the operation that kills the key.
	 *
	 * @param int $id
	 *
	 * @return string|null The new plaintext secret.
	 */
	public static function rotate_secret( $id ) {
		global $wpdb;

		$secret = FW_POS_Secrets::generate_secret();

		$updated = $wpdb->update(
			FW_POS_Schema::table( 'connections' ),
			[
				'secret'     => FW_POS_Secrets::protect( $secret ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false === $updated ? null : $secret;
	}

	/**
	 * @param int   $id
	 * @param array $fields name|mode|scopes|location_ref|status
	 *
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$data   = [ 'updated_at' => current_time( 'mysql', true ) ];
		$format = [ '%s' ];

		if ( isset( $fields['name'] ) ) {
			$data['name'] = substr( (string) $fields['name'], 0, 190 );
			$format[]     = '%s';
		}

		if ( isset( $fields['mode'] ) ) {
			$data['mode'] = self::MODE_LIVE === $fields['mode'] ? self::MODE_LIVE : self::MODE_TEST;
			$format[]     = '%s';
		}

		if ( isset( $fields['scopes'] ) && is_array( $fields['scopes'] ) ) {
			$data['scopes'] = implode( ',', array_intersect( $fields['scopes'], array_keys( self::scopes() ) ) );
			$format[]       = '%s';
		}

		if ( isset( $fields['location_ref'] ) ) {
			$data['location_ref'] = substr( (string) $fields['location_ref'], 0, 100 );
			$format[]             = '%s';
		}

		if ( isset( $fields['status'] ) ) {
			$data['status'] = self::STATUS_REVOKED === $fields['status'] ? self::STATUS_REVOKED : self::STATUS_ACTIVE;
			$format[]       = '%s';
		}

		return false !== $wpdb->update(
			FW_POS_Schema::table( 'connections' ),
			$data,
			[ 'id' => (int) $id ],
			$format,
			[ '%d' ]
		);
	}

	/**
	 * Revoke a connection. Its events stay in the log — a revoked till's history
	 * is exactly what you want to look at after revoking it.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function revoke( $id ) {
		return self::update( $id, [ 'status' => self::STATUS_REVOKED ] );
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( FW_POS_Schema::table( 'connections' ), [ 'id' => (int) $id ], [ '%d' ] );
	}

	/**
	 * Record that a connection was heard from, and how far its clock is out.
	 *
	 * Skew is stored rather than merely warned about, because a till that drifts
	 * corrupts the ordering key — and a drift that is only ever logged in
	 * passing is a drift nobody notices until stock is wrong.
	 *
	 * @param int $id
	 * @param int $skew_seconds
	 */
	public static function touch( $id, $skew_seconds = 0 ) {
		global $wpdb;

		$wpdb->update(
			FW_POS_Schema::table( 'connections' ),
			[
				'last_seen_at' => current_time( 'mysql', true ),
				'last_skew'    => max( -32767, min( 32767, (int) $skew_seconds ) ),
			],
			[ 'id' => (int) $id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Reading
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'connections' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Look a connection up by its public key.
	 *
	 * Revoked connections are returned too, so the endpoint can answer 401 for a
	 * revoked key rather than the indistinguishable "unknown key" — the
	 * difference matters when someone is debugging a till that used to work.
	 *
	 * @param string $api_key
	 *
	 * @return array|null
	 */
	public static function get_by_key( $api_key ) {
		global $wpdb;

		$api_key = trim( (string) $api_key );

		if ( '' === $api_key ) {
			return null;
		}

		$table = FW_POS_Schema::table( 'connections' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE api_key = %s", $api_key ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * @param array $args { @type string $status }
	 *
	 * @return array[]
	 */
	public static function all( array $args = [] ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'connections' );

		if ( ! empty( $args['status'] ) ) {
			return (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC, id ASC", (string) $args['status'] ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
		}

		return (array) $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY status ASC, name ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;

		$table = FW_POS_Schema::table( 'connections' );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_ACTIVE ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * The plaintext secret for signature verification.
	 *
	 * @param array $connection
	 *
	 * @return string
	 */
	public static function secret_for( array $connection ) {
		return FW_POS_Secrets::reveal( isset( $connection['secret'] ) ? $connection['secret'] : '' );
	}

	/**
	 * Does a connection hold a scope?
	 *
	 * @param array  $connection
	 * @param string $scope
	 *
	 * @return bool
	 */
	public static function has_scope( array $connection, $scope ) {
		$granted = array_filter( array_map( 'trim', explode( ',', (string) $connection['scopes'] ) ) );

		return in_array( $scope, $granted, true );
	}

	/**
	 * Is this connection allowed to change real stock?
	 *
	 * Both the global mode and the connection's own must say live. The global
	 * setting is a master switch — someone flipping the whole site to test to
	 * investigate a problem should not have to remember which of six tills is
	 * individually set to live.
	 *
	 * @param array|null            $connection
	 * @param FW_Extension_POS_Sync $ext
	 *
	 * @return bool
	 */
	public static function is_live( $connection, $ext ) {
		if ( ! $ext->is_live() ) {
			return false;
		}

		if ( ! $connection ) {
			// Events with no connection (recorded before connections existed, or
			// created in code) follow the global setting alone.
			return true;
		}

		return self::MODE_LIVE === $connection['mode'];
	}
}
