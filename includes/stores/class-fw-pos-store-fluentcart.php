<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * FluentCart store driver.
 *
 * ## Read this before trusting it
 *
 * This driver was written against FluentCart's **documented** public surface,
 * not against a running install — we do not have one. That is a real
 * limitation and it is handled by making the driver **prove itself before it
 * does anything**: `is_available()` checks that every function this class
 * actually calls exists, and returns false if any is missing.
 *
 * The consequence is the important part. If FluentCart's API differs from what
 * is assumed here, the driver simply never activates: the Settings screen shows
 * it as unavailable, events keep being recorded, and they resolve to
 * `no_store_driver` — visible, recoverable, and re-queueable once a working
 * driver exists. What cannot happen is a half-right driver silently writing the
 * wrong numbers into a real shop's stock, which is the only outcome here that
 * would actually be expensive.
 *
 * If you have a FluentCart install: run `tests/milestone-2.php` against it, fix
 * whatever `is_available()` rejects, and delete this warning.
 *
 * ## Why it is the right second target anyway
 *
 * FluentCart stores products and orders in **custom tables**, not the
 * post/meta model. That is precisely why `FW_POS_Store` was drafted against it:
 * an interface that quietly assumed `get_post_meta()` would break here, and
 * `find_by_sku()` returning an opaque string rather than a post ID is a direct
 * result of that exercise.
 */
class FW_POS_Store_FluentCart extends FW_POS_Store {

	/**
	 * The API this driver calls. Every one is checked before the driver is
	 * offered, so the list IS the compatibility contract — add a call to the
	 * class, add it here.
	 *
	 * @return string[]
	 */
	private function required_functions() {
		return [
			'fluent_cart_get_variation_by_sku',
			'fluent_cart_update_stock',
			'fluent_cart_get_variation',
		];
	}

	/**
	 * @return string
	 */
	public function get_id() {
		return 'fluentcart';
	}

	/**
	 * @return string
	 */
	public function get_label() {
		return __( 'FluentCart', 'fw' );
	}

	/**
	 * Present AND compatible. Both halves matter: a FluentCart whose API has
	 * moved is worse than no FluentCart, because it looks like it should work.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! defined( 'FLUENT_CART_VERSION' ) && ! class_exists( 'FluentCart\App\Application' ) ) {
			return false;
		}

		foreach ( $this->required_functions() as $function ) {
			if ( ! function_exists( $function ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Why the driver is unavailable, for the Settings screen.
	 *
	 * "Not installed" and "installed but this driver does not fit it" are very
	 * different problems and want very different responses.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		if ( ! defined( 'FLUENT_CART_VERSION' ) && ! class_exists( 'FluentCart\App\Application' ) ) {
			return __( 'FluentCart is not installed.', 'fw' );
		}

		$missing = [];

		foreach ( $this->required_functions() as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing[] = $function;
			}
		}

		if ( $missing ) {
			return sprintf(
				/* translators: %s: comma-separated function names */
				__( 'FluentCart is installed, but this driver expects functions it does not provide (%s). It was written against a documented API and not verified against a live install — please report the version you are running.', 'fw' ),
				implode( ', ', $missing )
			);
		}

		return '';
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

		$sku       = trim( (string) $sku );
		$variation = $sku ? fluent_cart_get_variation_by_sku( $sku ) : null;

		if ( ! $variation ) {
			return null;
		}

		$id = is_object( $variation ) && isset( $variation->id )
			? $variation->id
			: ( is_array( $variation ) && isset( $variation['id'] ) ? $variation['id'] : 0 );

		return $id ? 'fc_variation:' . (int) $id : null;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return string
	 */
	public function describe( $store_ref ) {
		if ( ! $this->is_available() ) {
			return '';
		}

		$variation = fluent_cart_get_variation( $this->id_from_ref( $store_ref ) );

		if ( ! $variation ) {
			return '';
		}

		if ( is_object( $variation ) && isset( $variation->title ) ) {
			return (string) $variation->title;
		}

		return is_array( $variation ) && isset( $variation['title'] ) ? (string) $variation['title'] : '';
	}

	/**
	 * @param string      $store_ref
	 * @param int         $quantity
	 * @param string|null $location_ref
	 *
	 * @return array
	 */
	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		return $this->write( $store_ref, max( 0, (int) $quantity ), 'set' );
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

		// A zero adjustment is defined as a no-op that reports the level, and
		// reconciliation relies on that — so answer it without a write.
		if ( 0 === (int) $delta ) {
			return $this->stock_ok( $current, $current );
		}

		return $this->write( $store_ref, max( 0, $current + (int) $delta ), 'set', $current );
	}

	/**
	 * Not supported: FluentCart order creation is not part of the surface this
	 * driver was written against, and inventing it would be guessing at
	 * somebody's money. Declared false in capabilities, so nothing calls it.
	 *
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
	 * @return int
	 */
	private function id_from_ref( $store_ref ) {
		$parts = explode( ':', (string) $store_ref, 2 );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}

	/**
	 * @param string $store_ref
	 *
	 * @return int|null
	 */
	private function current_stock( $store_ref ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$variation = fluent_cart_get_variation( $this->id_from_ref( $store_ref ) );

		if ( ! $variation ) {
			return null;
		}

		$manage = is_object( $variation )
			? ( isset( $variation->manage_stock ) ? $variation->manage_stock : null )
			: ( isset( $variation['manage_stock'] ) ? $variation['manage_stock'] : null );

		if ( ! $manage ) {
			return null;
		}

		$quantity = is_object( $variation )
			? ( isset( $variation->total_stock ) ? $variation->total_stock : null )
			: ( isset( $variation['total_stock'] ) ? $variation['total_stock'] : null );

		return null === $quantity ? null : (int) $quantity;
	}

	/**
	 * @param string   $store_ref
	 * @param int      $quantity
	 * @param string   $mode
	 * @param int|null $known_before
	 *
	 * @return array
	 */
	private function write( $store_ref, $quantity, $mode, $known_before = null ) {
		if ( ! $this->is_available() ) {
			return $this->stock_error( 'driver_unavailable' );
		}

		$id = $this->id_from_ref( $store_ref );

		if ( ! $id ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before = null === $known_before ? $this->current_stock( $store_ref ) : $known_before;

		if ( null === $before ) {
			return $this->stock_error( 'stock_not_managed' );
		}

		$written = fluent_cart_update_stock( $id, (int) $quantity );

		if ( false === $written ) {
			return $this->stock_error( 'stock_write_failed' );
		}

		return $this->stock_ok( $before, (int) $quantity );
	}
}
