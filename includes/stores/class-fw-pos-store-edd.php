<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Easy Digital Downloads store driver.
 *
 * ## EDD has no core stock, and this driver says so
 *
 * The honest finding, which is more useful than a driver that pretends
 * otherwise: **Easy Digital Downloads does not manage inventory in core.** It
 * sells digital goods, where the whole point is that there is no finite
 * quantity. There is an optional per-download *purchase limit*
 * (`_edd_download_limit`), but that is a cap on how many times a file may be
 * sold, not a stock level — decrementing it from till sales would be a
 * different thing wearing the same word.
 *
 * So this driver:
 *
 *  - reports `stock_not_managed` for any product without an explicit inventory
 *    integration, which the applier already treats as a correct outcome rather
 *    than a failure;
 *  - honours the `_edd_stock` meta that the common inventory add-ons use, when
 *    it is present;
 *  - declares no capabilities it cannot deliver.
 *
 * An EDD shop selling only downloads will therefore see events recorded, logged
 * and skipped with a legible reason. That is the right answer. Writing a driver
 * that invented stock for digital goods would have been worse than not shipping
 * one.
 */
class FW_POS_Store_EDD extends FW_POS_Store {

	/** The meta key EDD inventory add-ons conventionally use. */
	const STOCK_META = '_edd_stock';

	/**
	 * @return string
	 */
	public function get_id() {
		return 'edd';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'Easy Digital Downloads', 'fw' );
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		return class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'EDD' );
	}

	/**
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Easy Digital Downloads is not installed.', 'fw' );
	}

	/**
	 * @return array<string,bool>
	 */
	public function get_capabilities() {
		return array_merge(
			$this->default_capabilities(),
			[
				// Variable pricing is not variable SKUs, so no.
				'variations'      => false,
				'partial_refunds' => false,
				'create_orders'   => false,
				'backorders'      => false,
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

		if ( '' === $sku ) {
			return null;
		}

		$found = get_posts(
			[
				'post_type'        => 'download',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// EDD add-ons store a SKU under `edd_sku`; the meta query is the
				// only lookup EDD core makes possible.
				'meta_query'       => [
					[
						'key'   => 'edd_sku',
						'value' => $sku,
					],
				],
			]
		);

		return $found ? 'edd_download:' . (int) $found[0] : null;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return string
	 */
	public function describe( $store_ref ) {
		$id = $this->id_from_ref( $store_ref );

		return $id ? (string) get_the_title( $id ) : '';
	}

	/**
	 * @param string      $store_ref
	 * @param int         $quantity
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		$before = $this->current_stock( $store_ref );

		if ( null === $before ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		update_post_meta( $this->id_from_ref( $store_ref ), self::STOCK_META, max( 0, (int) $quantity ) );

		return $this->stock_ok( $before, max( 0, (int) $quantity ) );
	}

	/**
	 * @param string      $store_ref
	 * @param int         $delta
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		$before = $this->current_stock( $store_ref );

		if ( null === $before ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		if ( 0 === (int) $delta ) {
			return $this->stock_ok( $before, $before );
		}

		$after = max( 0, $before + (int) $delta );

		update_post_meta( $this->id_from_ref( $store_ref ), self::STOCK_META, $after );

		return $this->stock_ok( $before, $after );
	}

	/**
	 * @param array $event
	 * @param array $payload
	 *
	 * @return array
	 */
	public function create_order( array $event, array $payload ) {
		return [
			'ok'        => false,
			'order_ref' => null,
			'error'     => 'unsupported',
		];
	}

	/**
	 * @param string $order_ref
	 * @param array  $lines
	 * @param bool   $restock
	 *
	 * @return array
	 */
	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [
			'ok'    => false,
			'error' => 'unsupported',
		];
	}

	/**
	 * @param string $term
	 * @param int    $limit
	 *
	 * @return array[]
	 */
	public function search_products( $term, $limit = 20 ) {
		if ( ! $this->is_available() ) {
			return [];
		}

		$found = get_posts(
			[
				'post_type'      => 'download',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ),
				's'              => (string) $term,
			]
		);

		$products = [];

		foreach ( $found as $post ) {
			$sku = (string) get_post_meta( $post->ID, 'edd_sku', true );

			if ( '' === $sku ) {
				continue;
			}

			$products[] = [
				'sku'       => $sku,
				'name'      => $post->post_title,
				'store_ref' => 'edd_download:' . (int) $post->ID,
				'stock'     => $this->current_stock( 'edd_download:' . (int) $post->ID ),
			];
		}

		return $products;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

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
	 * Null when this download has no inventory at all — which for plain EDD is
	 * every download, and is not an error.
	 *
	 * @param string $store_ref
	 *
	 * @return int|null
	 */
	private function current_stock( $store_ref ) {
		$id = $this->id_from_ref( $store_ref );

		if ( ! $id ) {
			return null;
		}

		$stock = get_post_meta( $id, self::STOCK_META, true );

		return '' === $stock || null === $stock ? null : (int) $stock;
	}
}
