<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * WooCommerce store driver — the reference implementation of FW_POS_Store.
 *
 * Notes that are not obvious and cost time to rediscover:
 *
 *  - **Stock goes through `wc_update_product_stock()`**, never a direct meta
 *    write. Woo's own hooks, stock-status transitions and low-stock
 *    notifications hang off it; writing `_stock` by hand leaves a product that
 *    says "in stock" at zero.
 *  - **Variable products carry their SKUs on the VARIATION**, not the parent.
 *    A lookup that only checks parents misses most real catalogs, so
 *    `find_by_sku()` falls through to a variation query.
 *  - **HPOS-safe.** All order access goes through the CRUD layer
 *    (`wc_get_order()`, `$order->save()`), never a `wp_posts` query, so the
 *    driver works with High-Performance Order Storage on or off.
 *  - **`wc_get_product_id_by_sku()` is exact-match and case-sensitive** in
 *    practice. Trailing whitespace from a POS export is a common cause of
 *    "unmatched" items, so the SKU is trimmed before lookup — but NOT
 *    case-folded, because two products differing only in case are a catalog
 *    problem we must not paper over.
 */
class FW_POS_Store_WooCommerce extends FW_POS_Store {

	/**
	 * @return string
	 */
	public function get_id() {
		return 'woocommerce';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'WooCommerce', 'fw' );
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * @return array<string,bool>
	 */
	public function get_capabilities() {
		return array_merge(
			$this->default_capabilities(),
			[
				'partial_refunds' => true,
				'variations'      => true,
				'create_orders'   => true,
				'backorders'      => true,

				// Core Woo has ONE stock figure per product. Per-location stock
				// needs a multi-inventory plugin, so this stays false and the
				// applier maps every location onto the single stock source
				// rather than pretending otherwise.
				'multi_location_stock' => false,
			]
		);
	}

	/**
	 * @param string      $sku
	 * @param string|null $gtin
	 *
	 * @return string|null
	 */
	public function find_by_sku( $sku, $gtin = null ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$sku = trim( (string) $sku );

		if ( '' !== $sku ) {
			$id = wc_get_product_id_by_sku( $sku );

			if ( $id ) {
				return $this->ref_for( $id );
			}
		}

		// GTIN fallback. Woo 9.2+ has a first-class `global_unique_id` field;
		// older sites keep it in one of several conventional meta keys, so both
		// are tried before giving up.
		$gtin = trim( (string) $gtin );

		if ( '' !== $gtin ) {
			$id = $this->find_by_gtin( $gtin );

			if ( $id ) {
				return $this->ref_for( $id );
			}
		}

		return null;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return string
	 */
	public function describe( $store_ref ) {
		$id = $this->id_from_ref( $store_ref );

		if ( ! $id ) {
			return '';
		}

		$product = wc_get_product( $id );

		return $product ? $product->get_name() : '';
	}

	/**
	 * @param string      $store_ref
	 * @param int         $quantity
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		$product = $this->product_from_ref( $store_ref );

		if ( ! $product ) {
			return $this->stock_error( 'product_not_found' );
		}

		if ( ! $product->get_manage_stock() ) {
			// Not an error: plenty of catalogs deliberately do not track stock
			// on some products. Reporting it as a failure would retry forever.
			return $this->stock_error( 'stock_not_managed' );
		}

		$before = $product->get_stock_quantity();
		$after  = wc_update_product_stock( $product, max( 0, (int) $quantity ), 'set' );

		if ( false === $after ) {
			return $this->stock_error( 'stock_write_failed' );
		}

		return $this->stock_ok( $before, $after );
	}

	/**
	 * @param string      $store_ref
	 * @param int         $delta
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		$product = $this->product_from_ref( $store_ref );

		if ( ! $product ) {
			return $this->stock_error( 'product_not_found' );
		}

		if ( ! $product->get_manage_stock() ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		$delta = (int) $delta;

		if ( 0 === $delta ) {
			$current = $product->get_stock_quantity();

			return $this->stock_ok( $current, $current );
		}

		$before = $product->get_stock_quantity();

		// `increase`/`decrease` are Woo's own atomic operations — they run as a
		// single SQL statement, so two tills selling the last item at the same
		// moment cannot both read "1" and both write "0".
		$after = wc_update_product_stock(
			$product,
			abs( $delta ),
			$delta < 0 ? 'decrease' : 'increase'
		);

		if ( false === $after ) {
			return $this->stock_error( 'stock_write_failed' );
		}

		return $this->stock_ok( $before, $after );
	}

	/**
	 * @param array $event
	 * @param array $payload
	 *
	 * @return array
	 */
	public function create_order( array $event, array $payload ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return [
				'ok'        => false,
				'order_ref' => null,
				'error'     => 'wc_create_order_unavailable',
			];
		}

		try {
			$order = wc_create_order(
				[
					'created_via' => 'pos_sync',
					'status'      => 'completed',
				]
			);

			if ( is_wp_error( $order ) ) {
				return [
					'ok'        => false,
					'order_ref' => null,
					'error'     => $order->get_error_code(),
				];
			}

			$lines = isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ? $payload['line_items'] : [];

			foreach ( $lines as $line ) {
				$ref     = isset( $line['store_ref'] ) ? (string) $line['store_ref'] : '';
				$product = $ref ? $this->product_from_ref( $ref ) : null;

				if ( ! $product ) {
					continue;
				}

				// The till already moved the stock; adding the line must not
				// move it a second time.
				$order->add_product(
					$product,
					isset( $line['quantity'] ) ? (int) $line['quantity'] : 1,
					[
						'subtotal' => $this->minor_to_decimal( $line, $payload ),
						'total'    => $this->minor_to_decimal( $line, $payload ),
					]
				);
			}

			$order->set_payment_method( 'pos_sync' );
			$order->set_payment_method_title( __( 'Point of sale', 'fw' ) );
			$order->update_meta_data( '_pos_external_id', (string) $event['external_id'] );
			$order->update_meta_data( '_pos_connection_id', (int) $event['connection_id'] );
			$order->update_meta_data( '_pos_event_id', (int) $event['id'] );

			if ( ! empty( $event['location_ref'] ) ) {
				$order->update_meta_data( '_pos_location_ref', (string) $event['location_ref'] );
			}

			$order->add_order_note(
				sprintf(
					/* translators: %s: POS transaction id */
					__( 'Imported from the point of sale (transaction %s).', 'fw' ),
					(string) $event['external_id']
				)
			);

			$order->calculate_totals( false );
			$order->save();

			return [
				'ok'        => true,
				'order_ref' => 'order:' . $order->get_id(),
				'error'     => null,
			];
		} catch ( Exception $e ) {
			return [
				'ok'        => false,
				'order_ref' => null,
				'error'     => 'order_exception: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * @param string $order_ref
	 * @param array  $lines
	 * @param bool   $restock
	 *
	 * @return array
	 */
	public function refund_order( $order_ref, array $lines, $restock = true ) {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return [
				'ok'    => false,
				'error' => 'wc_create_refund_unavailable',
			];
		}

		$id    = (int) $this->id_from_ref( $order_ref );
		$order = $id ? wc_get_order( $id ) : null;

		if ( ! $order ) {
			return [
				'ok'    => false,
				'error' => 'order_not_found',
			];
		}

		$args = [
			'order_id'       => $id,
			'reason'         => __( 'Refunded at the point of sale', 'fw' ),
			'restock_items'  => (bool) $restock,
			'refund_payment' => false, // The till already handled the money.
		];

		// An empty line set means a full refund; a subset is a partial one.
		if ( ! empty( $lines ) ) {
			$args['line_items'] = $this->refund_line_items( $order, $lines );
			$args['amount']     = $this->refund_amount( $args['line_items'] );
		} else {
			$args['amount'] = $order->get_total() - $order->get_total_refunded();
		}

		$refund = wc_create_refund( $args );

		if ( is_wp_error( $refund ) ) {
			return [
				'ok'    => false,
				'error' => $refund->get_error_code(),
			];
		}

		return [
			'ok'    => true,
			'error' => null,
		];
	}

	/**
	 * @param string $term
	 * @param int    $limit
	 *
	 * @return array[]
	 */
	public function search_products( $term, $limit = 20 ) {
		if ( ! $this->is_available() || ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$term = trim( (string) $term );

		// Variations are included explicitly: variable products carry their SKUs
		// there, so a picker that lists only parents shows nothing sellable.
		$args = [
			'limit'    => max( 1, (int) $limit ),
			'status'   => 'publish',
			'type'     => [ 'simple', 'variation', 'variable' ],
			'orderby'  => 'title',
			'order'    => 'ASC',
			'return'   => 'objects',
		];

		if ( '' !== $term ) {
			$args['s'] = $term;
		}

		$found = [];

		foreach ( (array) wc_get_products( $args ) as $product ) {
			$sku = trim( (string) $product->get_sku() );

			// No SKU means nothing to match a till line against, so listing it
			// would only invite someone to pick an unusable product.
			if ( '' === $sku ) {
				continue;
			}

			$found[] = [
				'sku'       => $sku,
				'name'      => $product->get_name(),
				'store_ref' => $this->ref_for( $product->get_id() ),
				'stock'     => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
			];
		}

		return $found;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the reference for a product id, distinguishing variations so the
	 * admin screens can show what actually matched.
	 *
	 * @param int $id
	 *
	 * @return string
	 */
	private function ref_for( $id ) {
		$product = wc_get_product( $id );

		if ( $product && $product->is_type( 'variation' ) ) {
			return 'variation:' . (int) $id;
		}

		return 'product:' . (int) $id;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return int
	 */
	private function id_from_ref( $store_ref ) {
		$parts = explode( ':', (string) $store_ref, 2 );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return WC_Product|null
	 */
	private function product_from_ref( $store_ref ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$id = $this->id_from_ref( $store_ref );

		if ( ! $id ) {
			return null;
		}

		$product = wc_get_product( $id );

		return $product ? $product : null;
	}

	/**
	 * Find a product by barcode.
	 *
	 * Woo 9.2 added `global_unique_id` (its GTIN/EAN/UPC field). Sites older
	 * than that, or using a barcode plugin, keep it in meta under one of a few
	 * conventional keys — filterable, because there is no standard.
	 *
	 * @param string $gtin
	 *
	 * @return int
	 */
	private function find_by_gtin( $gtin ) {
		global $wpdb;

		/**
		 * Meta keys searched for a product barcode, in order.
		 *
		 * @param string[] $keys
		 */
		$keys = apply_filters(
			'fw_pos_gtin_meta_keys',
			[ '_global_unique_id', '_gtin', '_barcode', '_ean', '_upc' ]
		);

		foreach ( $keys as $key ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
					$key,
					$gtin
				)
			);

			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Translate resolved POS lines into the shape wc_create_refund() expects.
	 *
	 * @param WC_Order $order
	 * @param array    $lines
	 *
	 * @return array
	 */
	private function refund_line_items( $order, array $lines ) {
		$wanted = [];

		foreach ( $lines as $line ) {
			if ( ! empty( $line['sku'] ) ) {
				$wanted[ trim( (string) $line['sku'] ) ] = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			}
		}

		$items = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			if ( ! $product ) {
				continue;
			}

			$sku = trim( (string) $product->get_sku() );

			if ( '' === $sku || ! isset( $wanted[ $sku ] ) ) {
				continue;
			}

			$quantity = min( (int) $wanted[ $sku ], (int) $item->get_quantity() );

			if ( $quantity < 1 ) {
				continue;
			}

			// Refund the line's proportional value, so a partial refund of a
			// multi-quantity line does not refund the whole line's total.
			$per_unit = (int) $item->get_quantity() > 0
				? (float) $item->get_total() / (int) $item->get_quantity()
				: 0.0;

			$items[ $item_id ] = [
				'qty'          => $quantity,
				'refund_total' => round( $per_unit * $quantity, wc_get_price_decimals() ),
			];
		}

		return $items;
	}

	/**
	 * @param array $line_items
	 *
	 * @return float
	 */
	private function refund_amount( array $line_items ) {
		$total = 0.0;

		foreach ( $line_items as $item ) {
			$total += isset( $item['refund_total'] ) ? (float) $item['refund_total'] : 0.0;
		}

		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Convert a line's minor-unit price to the decimal string Woo wants.
	 *
	 * Amounts are integers on the wire precisely so they are exact; this is the
	 * single point where that is given up, at the boundary of a system that
	 * models money as a float.
	 *
	 * @param array $line
	 * @param array $payload
	 *
	 * @return float
	 */
	private function minor_to_decimal( array $line, array $payload ) {
		$unit     = isset( $line['unit_price'] ) ? (int) $line['unit_price'] : 0;
		$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;

		return round( ( $unit * $quantity ) / 100, wc_get_price_decimals() );
	}
}
