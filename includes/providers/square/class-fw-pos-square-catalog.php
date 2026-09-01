<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Square catalog import and variation → SKU mapping.
 *
 * ## SKUs live on the VARIATION, not the item
 *
 * This is the single most important Square fact for this driver. A Square
 * catalog `ITEM` is the product ("T-shirt"); its `ITEM_VARIATION` children are
 * the sellable things ("T-shirt, Medium") and they are what carry the SKU, the
 * price and the inventory count. An import that walks `ITEM` objects finds
 * almost no SKUs at all and looks like an empty catalog.
 *
 * Order lines and inventory counts both reference the **variation** id, which
 * is why the map this class builds is keyed on it.
 *
 * ## The map is stored, not derived
 *
 * Resolving a variation id to a SKU on every webhook would mean an API call per
 * line item, on the hot path, against a rate-limited API. So the import writes
 * the mapping into `fw_pos_map` once and webhooks read it locally. A variation
 * that appears in a sale before the catalog has been imported simply does not
 * resolve, surfaces as unmatched, and is fixed by re-importing — which is a
 * better failure than a slow one.
 */
class FW_POS_Square_Catalog {

	const ENTITY = 'item';

	/**
	 * Pull the catalog and record every variation that carries a SKU.
	 *
	 * @param array                  $connection
	 * @param FW_POS_Square_API|null $api
	 *
	 * @return array{ok:bool,seen:int,matched:int,error:string}
	 */
	public static function import( array $connection, $api = null ) {
		$api   = $api ? $api : new FW_POS_Square_API( $connection );
		$store = FW_POS_Stores::active();

		$seen    = 0;
		$matched = 0;
		$cursor  = '';

		do {
			$query = [
				'types' => 'ITEM',
			];

			if ( '' !== $cursor ) {
				$query['cursor'] = $cursor;
			}

			$result = $api->request( '/v2/catalog/list', [ 'query' => $query ] );

			if ( ! $result['ok'] ) {
				return [
					'ok'      => false,
					'seen'    => $seen,
					'matched' => $matched,
					'error'   => $result['error'],
				];
			}

			foreach ( (array) ( isset( $result['data']['objects'] ) ? $result['data']['objects'] : [] ) as $object ) {
				if ( empty( $object['item_data']['variations'] ) ) {
					continue;
				}

				$item_name = isset( $object['item_data']['name'] ) ? (string) $object['item_data']['name'] : '';

				foreach ( (array) $object['item_data']['variations'] as $variation ) {
					$sku = isset( $variation['item_variation_data']['sku'] )
						? trim( (string) $variation['item_variation_data']['sku'] )
						: '';

					if ( '' === $sku || empty( $variation['id'] ) ) {
						// No SKU means nothing to match a WordPress product
						// against. Recording it would fill the unmatched queue
						// with rows nobody can act on.
						continue;
					}

					$seen++;

					self::remember( (int) $connection['id'], (string) $variation['id'], $sku );

					$variation_name = isset( $variation['item_variation_data']['name'] )
						? (string) $variation['item_variation_data']['name']
						: '';

					$item_id = FW_POS_Ledger::upsert_item(
						[
							'sku'  => $sku,
							'name' => trim( $item_name . ( $variation_name ? ' — ' . $variation_name : '' ) ),
						]
					);

					// Try to bind it to a product now, so the merchant sees a
					// real unmatched list rather than one full of items that
					// would in fact have resolved.
					if ( $item_id && $store ) {
						$existing = FW_POS_Ledger::get_item( $item_id );

						if ( $existing && '' === (string) $existing['store_ref'] ) {
							$ref = $store->find_by_sku( $sku );

							if ( $ref ) {
								FW_POS_Ledger::set_item_match( $item_id, $ref );
								$matched++;
							}
						} elseif ( $existing ) {
							$matched++;
						}
					}
				}
			}

			$cursor = isset( $result['data']['cursor'] ) ? (string) $result['data']['cursor'] : '';
		} while ( '' !== $cursor );

		return [
			'ok'      => true,
			'seen'    => $seen,
			'matched' => $matched,
			'error'   => '',
		];
	}

	/**
	 * Record a variation id → SKU mapping.
	 *
	 * @param int    $connection_id
	 * @param string $variation_id
	 * @param string $sku
	 */
	public static function remember( $connection_id, $variation_id, $sku ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'map' );

		// The UNIQUE (connection_id, entity, external_id) index makes this a
		// safe upsert: a re-import of a variation whose SKU changed updates it
		// rather than creating a second, contradictory row.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, entity, external_id, local_id, created_at)
				VALUES (%d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE local_id = VALUES(local_id)", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $connection_id,
				self::ENTITY,
				(string) $variation_id,
				(string) $sku,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * The SKU a Square variation id maps to.
	 *
	 * Not scoped to a connection on purpose: a variation id is globally unique
	 * within a Square account, and a shop with two connections against the same
	 * merchant would otherwise have to import the catalog twice to get the same
	 * answer.
	 *
	 * @param string $variation_id
	 *
	 * @return string
	 */
	public static function sku_for( $variation_id ) {
		global $wpdb;

		$variation_id = trim( (string) $variation_id );

		if ( '' === $variation_id ) {
			return '';
		}

		$table = FW_POS_Schema::table( 'map' );

		$sku = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT local_id FROM {$table} WHERE entity = %s AND external_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				self::ENTITY,
				$variation_id
			)
		);

		return $sku ? (string) $sku : '';
	}

	/**
	 * The whole variation → SKU map for a connection.
	 *
	 * @param int $connection_id
	 *
	 * @return array<string,string> variation id => SKU
	 */
	public static function variation_map( $connection_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'map' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT external_id, local_id FROM {$table} WHERE connection_id = %d AND entity = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $connection_id,
				self::ENTITY
			),
			ARRAY_A
		);

		$map = [];

		foreach ( $rows as $row ) {
			$map[ (string) $row['external_id'] ] = (string) $row['local_id'];
		}

		return $map;
	}

	/**
	 * How many variations are mapped for a connection.
	 *
	 * @param int $connection_id
	 *
	 * @return int
	 */
	public static function mapped_count( $connection_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'map' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE connection_id = %d AND entity = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $connection_id,
				self::ENTITY
			)
		);
	}
}
