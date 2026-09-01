<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * SureCart store driver.
 *
 * ## Same warning as FluentCart, and one extra
 *
 * Written against SureCart's documented surface, **not verified against a live
 * install**. `is_available()` checks every call this class makes, so a mismatch
 * disables the driver rather than writing wrong numbers into a shop.
 *
 * The extra caveat is structural rather than a matter of verification:
 * SureCart's catalog and inventory are **hosted on SureCart's servers**, not in
 * the WordPress database. So every stock write here is a remote API call, with
 * everything that implies — latency on the queue, rate limits, and a failure
 * mode that is somebody else's outage rather than a local error.
 *
 * That is survivable because the queue already retries transient failures and
 * the ledger already records what was attempted. But it does mean a SureCart
 * shop is a slower and more fragile sync than a WooCommerce one, and it is
 * worth saying rather than discovering. The same reasoning that puts
 * [Ecwid last](https://docs.unysonplus.com/extensions/pos-sync/store-drivers)
 * applies here in milder form: if SureCart ever ships its own POS integration,
 * that is likely the better path for a SureCart merchant.
 */
class FW_POS_Store_SureCart extends FW_POS_Store {

	/**
	 * @return string
	 */
	public function get_id() {
		return 'surecart';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'SureCart', 'fw' );
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\SureCart\Models\Variant' )
			&& class_exists( '\SureCart\Models\Product' )
			&& method_exists( '\SureCart\Models\Variant', 'where' );
	}

	/**
	 * @return string
	 */
	public function unavailable_reason() {
		if ( ! defined( 'SURECART_PLUGIN_FILE' ) && ! class_exists( '\SureCart\Models\Product' ) ) {
			return __( 'SureCart is not installed.', 'fw' );
		}

		return __( 'SureCart is installed, but its model API is not the one this driver expects. It was written against a documented API and not verified against a live install — please report the version you are running.', 'fw' );
	}

	/**
	 * Written against a documented API, never run against a live install.
	 *
	 * @return string
	 */
	public function maturity() {
		return 'experimental';
	}

	/**
	 * @return array<string,bool>
	 */
	public function get_capabilities() {
		return array_merge(
			$this->default_capabilities(),
			[
				'variations'      => true,
				'partial_refunds' => false,
				'create_orders'   => false,

				// Hosted inventory. Stock exists, but only when the merchant has
				// enabled it per variant — so it is claimed, and each write
				// checks rather than assuming.
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
		$variant = $this->variant_by_sku( $sku );

		return $variant && ! empty( $variant->id ) ? 'sc_variant:' . $variant->id : null;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return string
	 */
	public function describe( $store_ref ) {
		$variant = $this->variant( $store_ref );

		if ( ! $variant ) {
			return '';
		}

		foreach ( [ 'option_1', 'sku' ] as $field ) {
			if ( ! empty( $variant->{$field} ) ) {
				return (string) $variant->{$field};
			}
		}

		return '';
	}

	/**
	 * @param string      $store_ref
	 * @param int         $quantity
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		return $this->write( $store_ref, max( 0, (int) $quantity ) );
	}

	/**
	 * @param string      $store_ref
	 * @param int         $delta
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		$current = $this->current_stock( $store_ref );

		if ( null === $current ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		if ( 0 === (int) $delta ) {
			return $this->stock_ok( $current, $current );
		}

		return $this->write( $store_ref, max( 0, $current + (int) $delta ), $current );
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

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $store_ref
	 *
	 * @return string
	 */
	private function id_from_ref( $store_ref ) {
		$parts = explode( ':', (string) $store_ref, 2 );

		return isset( $parts[1] ) ? (string) $parts[1] : '';
	}

	/**
	 * Every remote call is wrapped: SureCart throws on API failure, and an
	 * uncaught exception in the queue worker would take out the whole batch
	 * rather than the one event that could not be applied.
	 *
	 * @param string $sku
	 *
	 * @return object|null
	 */
	private function variant_by_sku( $sku ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$sku = trim( (string) $sku );

		if ( '' === $sku ) {
			return null;
		}

		try {
			$found = \SureCart\Models\Variant::where( [ 'sku' => $sku ] )->get();
		} catch ( Exception $e ) {
			return null;
		}

		if ( is_wp_error( $found ) || empty( $found ) ) {
			return null;
		}

		return is_array( $found ) ? reset( $found ) : $found;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return object|null
	 */
	private function variant( $store_ref ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$id = $this->id_from_ref( $store_ref );

		if ( '' === $id ) {
			return null;
		}

		try {
			$variant = \SureCart\Models\Variant::find( $id );
		} catch ( Exception $e ) {
			return null;
		}

		return is_wp_error( $variant ) || ! $variant ? null : $variant;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return int|null
	 */
	private function current_stock( $store_ref ) {
		$variant = $this->variant( $store_ref );

		if ( ! $variant || empty( $variant->stock_enabled ) ) {
			return null;
		}

		return isset( $variant->available_stock ) ? (int) $variant->available_stock : null;
	}

	/**
	 * @param string   $store_ref
	 * @param int      $quantity
	 * @param int|null $known_before
	 *
	 * @return array
	 */
	private function write( $store_ref, $quantity, $known_before = null ) {
		$variant = $this->variant( $store_ref );

		if ( ! $variant ) {
			return $this->stock_error( 'product_not_found' );
		}

		if ( empty( $variant->stock_enabled ) ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		$before = null === $known_before
			? ( isset( $variant->available_stock ) ? (int) $variant->available_stock : null )
			: $known_before;

		try {
			$updated = \SureCart\Models\Variant::update(
				[
					'id'              => $this->id_from_ref( $store_ref ),
					'available_stock' => (int) $quantity,
				]
			);
		} catch ( Exception $e ) {
			// Somebody else's outage. Transient by nature, and the queue's
			// backoff is the right response.
			return $this->stock_error( 'remote_write_failed: ' . $e->getMessage() );
		}

		if ( is_wp_error( $updated ) ) {
			return $this->stock_error( 'remote_write_failed: ' . $updated->get_error_code() );
		}

		return $this->stock_ok( $before, (int) $quantity );
	}
}
