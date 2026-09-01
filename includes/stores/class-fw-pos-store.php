<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The store driver contract — the seam between the ledger and whatever
 * e-commerce plugin is installed.
 *
 * This is the only place in POS Sync that is allowed to know a specific cart
 * exists. Everything above it deals in SKUs, integer quantities and opaque
 * references, which is what keeps the ledger portable.
 *
 * ## Everything crossing this boundary is a primitive
 *
 * No `WC_Product`, no `WC_Order`, no post IDs with implied meaning. A product
 * is identified by an opaque `store_ref` string that only the driver that
 * issued it has to understand (`product:42`, `variation:87`, `fc_item:9`).
 * The moment a cart-specific type crosses this line the abstraction is over.
 *
 * ## Why it was written against two implementations
 *
 * An interface designed against a single implementation always encodes that
 * implementation's assumptions, and you discover it at the second one, when it
 * is expensive. So this was drafted while sketching **both** WooCommerce (post
 * meta, variations, `wc_*` helpers) and FluentCart (custom tables, a different
 * variant model). Two things changed because of that exercise and are worth
 * preserving:
 *
 *  - `find_by_sku()` returns an opaque **string**, not an int. A post ID is a
 *    WooCommerce-shaped answer; FluentCart's items are rows in its own tables
 *    and would not survive it.
 *  - `get_capabilities()` exists at all. Carts genuinely differ on partial
 *    refunds and per-location stock, and the ledger has to degrade rather than
 *    fatal when one cannot do something.
 *
 * ## Declare capabilities honestly
 *
 * Claiming `partial_refunds` you cannot deliver produces silently wrong
 * refunds, which is strictly worse than declaring `false` and having the
 * refund recorded as skipped with a legible reason.
 *
 * @see https://docs.unysonplus.com/extensions/pos-sync/store-drivers
 */
abstract class FW_POS_Store {

	/**
	 * Machine id: 'woocommerce', 'fluentcart', …
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Human label for the settings screen.
	 *
	 * @return string
	 */
	abstract public function get_label();

	/**
	 * Is this cart present and usable right now?
	 *
	 * Checked at runtime, not at load: a plugin can be deactivated between the
	 * event arriving and the queue draining it.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * What this driver can actually do.
	 *
	 * @return array {
	 *     @type bool $partial_refunds      Refund a subset of a sale's lines.
	 *     @type bool $multi_location_stock Distinct stock per location.
	 *     @type bool $variations           Variant-level SKUs.
	 *     @type bool $create_orders        Record a till sale as a store order.
	 *     @type bool $backorders           Stock may go below zero.
	 * }
	 */
	abstract public function get_capabilities();

	/**
	 * Resolve a SKU (or GTIN) to an opaque store reference.
	 *
	 * @param string      $sku
	 * @param string|null $gtin Fallback when the SKU matches nothing.
	 *
	 * @return string|null 'product:42', 'variation:87', … or null when unmatched.
	 */
	abstract public function find_by_sku( $sku, $gtin = null );

	/**
	 * Human-readable name for a store reference, for the admin screens.
	 *
	 * @param string $store_ref
	 *
	 * @return string
	 */
	abstract public function describe( $store_ref );

	/**
	 * Set an absolute stock level.
	 *
	 * @param string      $store_ref
	 * @param int         $quantity
	 * @param string|null $location_ref
	 *
	 * @return array{ok:bool,before:?int,after:?int,error:?string}
	 */
	abstract public function set_stock( $store_ref, $quantity, $location_ref = null );

	/**
	 * Apply a relative delta.
	 *
	 * @param string      $store_ref
	 * @param int         $delta        Negative for a sale, positive for a return.
	 * @param string|null $location_ref
	 *
	 * @return array{ok:bool,before:?int,after:?int,error:?string}
	 */
	abstract public function adjust_stock( $store_ref, $delta, $location_ref = null );

	/**
	 * Record a completed till sale as a store order.
	 *
	 * Only called when the driver declares `create_orders` AND the site has
	 * opted in — see FW_POS_Applier for why that is off by default.
	 *
	 * @param array $event   The event row.
	 * @param array $payload The decoded payload, line items already resolved.
	 *
	 * @return array{ok:bool,order_ref:?string,error:?string}
	 */
	abstract public function create_order( array $event, array $payload );

	/**
	 * Refund a previously created order.
	 *
	 * @param string $order_ref
	 * @param array  $lines   Resolved lines; empty means the whole order.
	 * @param bool   $restock
	 *
	 * @return array{ok:bool,error:?string}
	 */
	abstract public function refund_order( $order_ref, array $lines, $restock = true );

	/* ---------------------------------------------------------------------- *
	 * Shared helpers — concrete on purpose
	 * ---------------------------------------------------------------------- */

	/**
	 * Does this driver support a named capability?
	 *
	 * @param string $capability
	 *
	 * @return bool
	 */
	public function supports( $capability ) {
		$capabilities = (array) $this->get_capabilities();

		return ! empty( $capabilities[ $capability ] );
	}

	/**
	 * The capability defaults. A driver overriding `get_capabilities()` should
	 * merge onto these so a capability added later defaults to "no" rather than
	 * to undefined, which reads as "no" but hides the omission.
	 *
	 * @return array<string,bool>
	 */
	protected function default_capabilities() {
		return [
			'partial_refunds'      => false,
			'multi_location_stock' => false,
			'variations'           => false,
			'create_orders'        => false,
			'backorders'           => false,
		];
	}

	/**
	 * Shorthand for a failed stock operation.
	 *
	 * @param string $error
	 *
	 * @return array
	 */
	protected function stock_error( $error ) {
		return [
			'ok'     => false,
			'before' => null,
			'after'  => null,
			'error'  => (string) $error,
		];
	}

	/**
	 * Shorthand for a successful stock operation.
	 *
	 * @param int|null $before
	 * @param int|null $after
	 *
	 * @return array
	 */
	protected function stock_ok( $before, $after ) {
		return [
			'ok'     => true,
			'before' => null === $before ? null : (int) $before,
			'after'  => null === $after ? null : (int) $after,
			'error'  => null,
		];
	}
}
