<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Square provider driver.
 *
 * Chosen as the first — and for now only — first-party vendor driver because it
 * has the best free sandbox of any POS (no application review, a full catalog
 * and inventory surface, and webhook replay from the dashboard), the cleanest
 * API, and the largest share of the small-retail market this is aimed at.
 *
 * It composes rather than sprawls: OAuth, webhooks and catalog each own their
 * own file, and this class is the thin thing that implements FW_POS_Provider on
 * top of them.
 *
 * ## Locations are mapped explicitly, never guessed
 *
 * Multi-location is normal in Square even for a single-shop seller — an online
 * location usually exists alongside the physical one, and Square creates it
 * without being asked. Taking "the first location" is therefore a reliable way
 * to sync a shop's counter sales against the wrong stock, so an unmapped
 * location either falls back to a deliberately configured default or is
 * recorded as skipped. It is never inferred.
 */
class FW_POS_Provider_Square extends FW_POS_Provider {

	/**
	 * @return string
	 */
	public function get_id() {
		return 'square';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'Square', 'fw' );
	}

	/**
	 * @param array $connection
	 *
	 * @return bool
	 */
	public function is_connected( array $connection ) {
		$credentials = self::credentials( $connection );

		return ! empty( $credentials['access_token'] ) && empty( $credentials['needs_reconnect'] );
	}

	/**
	 * Does this connection need a human to re-authorize it?
	 *
	 * @param array $connection
	 *
	 * @return bool
	 */
	public function needs_reconnect( array $connection ) {
		$credentials = self::credentials( $connection );

		return ! empty( $credentials['needs_reconnect'] );
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

		return FW_POS_Square_Webhooks::verify(
			isset( $credentials['webhook_signature_key'] ) ? $credentials['webhook_signature_key'] : '',
			$raw_body,
			$headers,
			$url
		);
	}

	/**
	 * @param array $connection
	 * @param array $payload
	 *
	 * @return array[]
	 */
	public function normalize( array $connection, array $payload ) {
		// A webhook is the one place a stale token surfaces at the worst
		// moment, so the refresh check happens here rather than lazily.
		$connection = FW_POS_Square_OAuth::ensure_fresh( $connection );

		$events = FW_POS_Square_Webhooks::normalize( $connection, $payload );

		return $this->apply_location_mapping( $connection, $events );
	}

	/**
	 * @param array $connection
	 *
	 * @return array[]
	 */
	public function locations( array $connection ) {
		if ( ! $this->is_connected( $connection ) ) {
			return [];
		}

		$api    = new FW_POS_Square_API( $connection );
		$result = $api->request( '/v2/locations' );

		if ( ! $result['ok'] ) {
			return [];
		}

		$locations = [];

		foreach ( (array) ( isset( $result['data']['locations'] ) ? $result['data']['locations'] : [] ) as $location ) {
			if ( empty( $location['id'] ) ) {
				continue;
			}

			$locations[] = [
				'id'     => (string) $location['id'],
				'name'   => isset( $location['name'] ) ? (string) $location['name'] : (string) $location['id'],
				'type'   => isset( $location['type'] ) ? (string) $location['type'] : '',
				'status' => isset( $location['status'] ) ? (string) $location['status'] : '',
			];
		}

		return $locations;
	}

	/**
	 * @param array $connection
	 *
	 * @return array
	 */
	public function import_catalog( array $connection ) {
		if ( ! $this->is_connected( $connection ) ) {
			return [
				'ok'      => false,
				'seen'    => 0,
				'matched' => 0,
				'error'   => 'not_connected',
			];
		}

		return FW_POS_Square_Catalog::import( FW_POS_Square_OAuth::ensure_fresh( $connection ) );
	}

	/**
	 * Pull recent payments so the ledger does not start empty.
	 *
	 * Deliberately bounded and deliberately quiet: it records events through
	 * the ordinary ledger path, so idempotency applies and running it twice is
	 * harmless. A backfill that could double-apply would be worse than none.
	 *
	 * @param array $connection
	 * @param int   $since
	 *
	 * @return array
	 */
	public function backfill( array $connection, $since ) {
		if ( ! $this->is_connected( $connection ) ) {
			return [
				'ok'    => false,
				'count' => 0,
				'error' => 'not_connected',
			];
		}

		$connection = FW_POS_Square_OAuth::ensure_fresh( $connection );
		$api        = new FW_POS_Square_API( $connection );

		$result = $api->request(
			'/v2/payments',
			[
				'query' => [
					'begin_time' => gmdate( 'c', (int) $since ),
					'sort_order' => 'ASC',
					'limit'      => 100,
				],
			]
		);

		if ( ! $result['ok'] ) {
			return [
				'ok'    => false,
				'count' => 0,
				'error' => $result['error'],
			];
		}

		$count = 0;

		foreach ( (array) ( isset( $result['data']['payments'] ) ? $result['data']['payments'] : [] ) as $payment ) {
			// Re-use the webhook normalizer rather than a parallel code path,
			// so a backfilled sale and a live one are byte-identical in the
			// ledger. Two normalizers would drift within a release.
			$events = FW_POS_Square_Webhooks::normalize(
				$connection,
				[
					'type' => 'payment.created',
					'data' => [ 'object' => [ 'payment' => $payment ] ],
				],
				$api
			);

			foreach ( $this->apply_location_mapping( $connection, $events ) as $event ) {
				$recorded = FW_POS_Ledger::record_event( $event );

				if ( $recorded['ok'] && ! $recorded['duplicate'] ) {
					$count++;
				}
			}
		}

		if ( $count ) {
			FW_POS_Queue::schedule();
		}

		return [
			'ok'    => true,
			'count' => $count,
			'error' => '',
		];
	}

	/**
	 * Authoritative counts from Square, keyed by SKU.
	 *
	 * Reads the variation → SKU map to know what to ask about, then batches the
	 * lookup: Square's inventory API is rate limited and one call per variation
	 * would take a catalog of any size well past a sensible sweep.
	 *
	 * Only IN_STOCK is a level — SOLD and WASTE are movements between states and
	 * would double-count against it.
	 *
	 * @param array $connection
	 * @param array $skus
	 *
	 * @return array<string,int>
	 */
	public function fetch_counts( array $connection, array $skus = [] ) {
		if ( ! $this->is_connected( $connection ) ) {
			return [];
		}

		$connection = FW_POS_Square_OAuth::ensure_fresh( $connection );
		$map        = FW_POS_Square_Catalog::variation_map( (int) $connection['id'] );

		if ( empty( $map ) ) {
			// No catalog import yet, so there is nothing to ask about. Better
			// to return nothing than to report every SKU as drifted.
			return [];
		}

		if ( ! empty( $skus ) ) {
			$map = array_filter(
				$map,
				function ( $sku ) use ( $skus ) {
					return in_array( $sku, $skus, true );
				}
			);
		}

		$api    = new FW_POS_Square_API( $connection );
		$counts = [];

		foreach ( array_chunk( array_keys( $map ), 100, true ) as $chunk ) {
			$result = $api->request(
				'/v2/inventory/counts/batch-retrieve',
				[
					'method' => 'POST',
					'body'   => [
						'catalog_object_ids' => array_values( $chunk ),
						'states'             => [ 'IN_STOCK' ],
					],
				]
			);

			if ( ! $result['ok'] ) {
				// A partial answer is worse than none: reconciliation would
				// report every unfetched SKU as unchanged and hide real drift.
				return [];
			}

			foreach ( (array) ( isset( $result['data']['counts'] ) ? $result['data']['counts'] : [] ) as $count ) {
				$variation = isset( $count['catalog_object_id'] ) ? (string) $count['catalog_object_id'] : '';

				if ( '' === $variation || ! isset( $map[ $variation ] ) ) {
					continue;
				}

				if ( empty( $count['state'] ) || 'IN_STOCK' !== strtoupper( (string) $count['state'] ) ) {
					continue;
				}

				$counts[ $map[ $variation ] ] = (int) $count['quantity'];
			}
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------- *
	 * Locations
	 * ---------------------------------------------------------------------- */

	/**
	 * Translate Square location ids into the site's own location references.
	 *
	 * The mapping lives on the connection's credentials as
	 * `location_map: { square_location_id: local_ref }`. An unmapped location
	 * falls back to the connection's own `location_ref`, and if that is empty
	 * the Square id is passed through unchanged — which is honest: the log then
	 * shows a raw Square id, which is a visible prompt to map it, rather than a
	 * plausible-looking wrong answer.
	 *
	 * @param array   $connection
	 * @param array[] $events
	 *
	 * @return array[]
	 */
	private function apply_location_mapping( array $connection, array $events ) {
		$credentials = self::credentials( $connection );
		$map         = isset( $credentials['location_map'] ) && is_array( $credentials['location_map'] )
			? $credentials['location_map']
			: [];

		if ( empty( $map ) && '' === (string) $connection['location_ref'] ) {
			return $events;
		}

		foreach ( $events as $index => $event ) {
			$square_location = isset( $event['location_ref'] ) ? (string) $event['location_ref'] : '';

			if ( isset( $map[ $square_location ] ) && '' !== $map[ $square_location ] ) {
				$local = (string) $map[ $square_location ];
			} elseif ( '' === $square_location ) {
				$local = (string) $connection['location_ref'];
			} else {
				continue; // Unmapped and non-empty: leave the Square id visible.
			}

			$events[ $index ]['location_ref']            = $local;
			$events[ $index ]['payload']['location_ref'] = $local;
		}

		return $events;
	}

	/**
	 * Store a Square location id → local reference mapping.
	 *
	 * @param int   $connection_id
	 * @param array $map
	 *
	 * @return bool
	 */
	public static function set_location_map( $connection_id, array $map ) {
		return self::store_credentials( $connection_id, [ 'location_map' => $map ] );
	}
}
