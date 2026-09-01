<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Turns a normalized ledger event into store writes.
 *
 * This is the only implementation of the queue's `fw_pos_apply_event` filter
 * that ships with the extension, and it is the seam's consumer: it talks to
 * FW_POS_Store, never to WooCommerce.
 *
 * The contract it honours, from FW_POS_Queue:
 *
 *   ['ok' => true,  'result' => [...]]                 applied
 *   ['ok' => false, 'retry' => true,  'error' => '…']  transient — retried
 *   ['ok' => false, 'retry' => false, 'error' => '…']  a decision — skipped
 *
 * The retry flag is the important distinction. "The cart is down" is transient
 * and worth retrying; "this SKU does not exist" is a decision that will be just
 * as true in five minutes, and retrying it five times only fills the log.
 */
class FW_POS_Applier {

	/** @var FW_Extension_POS_Sync */
	private $ext;

	/**
	 * @param FW_Extension_POS_Sync $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;
	}

	/**
	 * Hook into the queue.
	 */
	public function register() {
		add_filter( 'fw_pos_apply_event', [ $this, '_filter_apply_event' ], 10, 3 );
	}

	/**
	 * @internal
	 *
	 * @param array|null $result
	 * @param array      $event
	 * @param array      $payload
	 *
	 * @return array|null
	 */
	public function _filter_apply_event( $result, $event, $payload ) {
		if ( null !== $result ) {
			return $result; // Someone else already handled it.
		}

		$store = FW_POS_Stores::active();

		if ( ! $store ) {
			// Returning null lets the queue record `no_store_driver`, which is
			// a more useful reason than anything this class could invent.
			return null;
		}

		if ( ! $store->is_available() ) {
			// The cart was deactivated between ingest and drain. Transient by
			// definition — it may well come back.
			return $this->fail( 'store_unavailable', true );
		}

		// Test mode runs the whole pipeline — matching included, so the
		// unmatched queue still fills up and a shop can fix its SKUs before
		// going live — and stops only at the write.
		//
		$dry_run = ! $this->is_live_for( $event );

		switch ( $event['type'] ) {
			case FW_POS_Ledger::TYPE_SALE:
				return $this->apply_sale( $store, $event, $payload, $dry_run );

			case FW_POS_Ledger::TYPE_REFUND:
			case FW_POS_Ledger::TYPE_VOID:
				return $this->apply_refund( $store, $event, $payload, $dry_run );

			case FW_POS_Ledger::TYPE_INVENTORY:
				return $this->apply_inventory( $store, $event, $payload, $dry_run );
		}

		return $this->fail( 'unknown_event_type: ' . $event['type'], false );
	}

	/**
	 * May this event change real stock?
	 *
	 * Both the site-wide setting and the connection's own must say live. The
	 * global one is a master switch: someone flipping the whole site to test to
	 * investigate a problem should not have to remember which of six tills is
	 * individually set to live.
	 *
	 * The guard covers the WHOLE branch, not just the lookup. An earlier version
	 * guarded `FW_POS_Connections::get()` with class_exists() and then called
	 * `FW_POS_Connections::is_live()` unconditionally, which fatals in exactly
	 * the situation the guard existed for.
	 *
	 * @param array $event
	 *
	 * @return bool
	 */
	private function is_live_for( array $event ) {
		if ( ! $this->ext->is_live() ) {
			return false;
		}

		// No connection (recorded in code, or before connections existed), or
		// the connections layer is not loaded: the global setting alone decides.
		if ( empty( $event['connection_id'] ) || ! class_exists( 'FW_POS_Connections' ) ) {
			return true;
		}

		return FW_POS_Connections::is_live(
			FW_POS_Connections::get( (int) $event['connection_id'] ),
			$this->ext
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Event types
	 * ---------------------------------------------------------------------- */

	/**
	 * A sale reduces stock by the quantity sold.
	 *
	 * @param FW_POS_Store $store
	 * @param array        $event
	 * @param array        $payload
	 * @param bool         $dry_run
	 *
	 * @return array
	 */
	private function apply_sale( FW_POS_Store $store, array $event, array $payload, $dry_run ) {
		$lines = isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ? $payload['line_items'] : [];

		if ( empty( $lines ) ) {
			return $this->fail( 'no_line_items', false );
		}

		$matched = ( new FW_POS_Matcher( $store ) )->resolve( $lines );

		if ( ! empty( $matched['unmatched'] ) ) {
			// Nothing is applied. Half a sale leaves stock wrong in a way
			// nobody can see; a skipped event says exactly what it needs.
			return $this->fail( FW_POS_Matcher::describe_unmatched( $matched['unmatched'] ), false );
		}

		if ( empty( $matched['lines'] ) ) {
			// Every line was an ignored non-stock item. Nothing to do, and
			// that is a correct outcome rather than a failure.
			return $this->ok( [ 'note' => 'all_lines_ignored' ] );
		}

		if ( $dry_run ) {
			return $this->ok( $this->preview( $matched['lines'], -1 ) + [ 'test_mode' => true ] );
		}

		$moves = [];

		foreach ( $matched['lines'] as $line ) {
			if ( ! $this->pos_may_write( $line ) ) {
				$moves[] = $this->refused( $line );

				continue;
			}

			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			$outcome  = $store->adjust_stock( $line['store_ref'], -abs( $quantity ), $event['location_ref'] );

			$moves[] = $this->move( $line, $outcome );

			// "Stock is not managed on this product" is a catalog decision, not
			// a failure — but a genuine write failure is transient and retried.
			if ( ! $outcome['ok'] && 'stock_not_managed' !== $outcome['error'] ) {
				return $this->fail( 'stock_write_failed: ' . $line['sku'] . ' — ' . $outcome['error'], true );
			}
		}

		$result = [ 'moves' => $moves ];

		// Recording the sale as a store order is OFF by default. Every till
		// sale becoming a WooCommerce order double-counts revenue against the
		// POS's own reporting and floods the order list — some shops want it,
		// most are surprised by it, so it is opt-in.
		if ( $this->ext->should_create_orders() && $store->supports( 'create_orders' ) ) {
			$payload['line_items'] = $matched['lines'];
			$order                 = $store->create_order( $event, $payload );

			if ( $order['ok'] ) {
				$result['order_ref'] = $order['order_ref'];
			} else {
				// The stock move already succeeded, so this is not worth
				// failing the event over — but it must be visible.
				$result['order_error'] = $order['error'];
			}
		}

		return $this->ok( $result );
	}

	/**
	 * A refund puts stock back, unless the goods came back damaged.
	 *
	 * @param FW_POS_Store $store
	 * @param array        $event
	 * @param array        $payload
	 * @param bool         $dry_run
	 *
	 * @return array
	 */
	private function apply_refund( FW_POS_Store $store, array $event, array $payload, $dry_run ) {
		$restock = ! isset( $payload['restock'] ) || (bool) $payload['restock'];
		$lines   = isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ? $payload['line_items'] : [];

		if ( ! $restock ) {
			// Damaged goods: the money went back, the stock does not.
			return $this->ok( [ 'note' => 'restock_declined' ] );
		}

		if ( empty( $lines ) ) {
			// A full refund with no lines needs the original sale to know what
			// to restock. Milestone 3 holds these until the sale arrives; for
			// now it is a legible skip rather than a guess.
			return $this->fail( 'refund_without_lines', false );
		}

		$matched = ( new FW_POS_Matcher( $store ) )->resolve( $lines );

		if ( ! empty( $matched['unmatched'] ) ) {
			return $this->fail( FW_POS_Matcher::describe_unmatched( $matched['unmatched'] ), false );
		}

		if ( empty( $matched['lines'] ) ) {
			return $this->ok( [ 'note' => 'all_lines_ignored' ] );
		}

		// A partial refund is a subset of the sale's lines. A driver that
		// cannot do partials must not silently refund everything.
		$is_partial = ! empty( $payload['sale_external_id'] ) && ! empty( $payload['partial'] );

		if ( $is_partial && ! $store->supports( 'partial_refunds' ) ) {
			return $this->fail( 'partial_refunds_unsupported', false );
		}

		if ( $dry_run ) {
			return $this->ok( $this->preview( $matched['lines'], 1 ) + [ 'test_mode' => true ] );
		}

		$moves = [];

		foreach ( $matched['lines'] as $line ) {
			if ( ! $this->pos_may_write( $line ) ) {
				$moves[] = $this->refused( $line );

				continue;
			}

			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			$outcome  = $store->adjust_stock( $line['store_ref'], abs( $quantity ), $event['location_ref'] );

			$moves[] = $this->move( $line, $outcome );

			if ( ! $outcome['ok'] && 'stock_not_managed' !== $outcome['error'] ) {
				return $this->fail( 'stock_write_failed: ' . $line['sku'] . ' — ' . $outcome['error'], true );
			}
		}

		return $this->ok( [ 'moves' => $moves ] );
	}

	/**
	 * A stocktake sets levels; an adjustment nudges them.
	 *
	 * @param FW_POS_Store $store
	 * @param array        $event
	 * @param array        $payload
	 * @param bool         $dry_run
	 *
	 * @return array
	 */
	private function apply_inventory( FW_POS_Store $store, array $event, array $payload, $dry_run ) {
		$counts = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [];
		$mode   = isset( $payload['mode'] ) ? (string) $payload['mode'] : 'absolute';

		if ( empty( $counts ) ) {
			return $this->fail( 'no_counts', false );
		}

		$matched = ( new FW_POS_Matcher( $store ) )->resolve( $counts, 'counts' );

		if ( ! empty( $matched['unmatched'] ) ) {
			return $this->fail( FW_POS_Matcher::describe_unmatched( $matched['unmatched'] ), false );
		}

		if ( empty( $matched['lines'] ) ) {
			return $this->ok( [ 'note' => 'all_lines_ignored' ] );
		}

		if ( $dry_run ) {
			return $this->ok(
				$this->preview( $matched['lines'], 'absolute' === $mode ? 0 : 1 ) + [
					'test_mode' => true,
					'mode'      => $mode,
				]
			);
		}

		$moves = [];

		foreach ( $matched['lines'] as $line ) {
			if ( ! $this->pos_may_write( $line ) ) {
				$moves[] = $this->refused( $line );

				continue;
			}

			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;

			$outcome = 'absolute' === $mode
				? $store->set_stock( $line['store_ref'], $quantity, $event['location_ref'] )
				: $store->adjust_stock( $line['store_ref'], $quantity, $event['location_ref'] );

			$moves[] = $this->move( $line, $outcome );

			if ( ! $outcome['ok'] && 'stock_not_managed' !== $outcome['error'] ) {
				return $this->fail( 'stock_write_failed: ' . $line['sku'] . ' — ' . $outcome['error'], true );
			}
		}

		return $this->ok(
			[
				'moves' => $moves,
				'mode'  => $mode,
			]
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Result shaping
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $result
	 *
	 * @return array
	 */
	private function ok( array $result ) {
		return [
			'ok'     => true,
			'result' => $result,
		];
	}

	/**
	 * @param string $error
	 * @param bool   $retry
	 *
	 * @return array
	 */
	private function fail( $error, $retry ) {
		return [
			'ok'    => false,
			'retry' => (bool) $retry,
			'error' => (string) $error,
		];
	}

	/**
	 * Is the POS allowed to write stock for this line?
	 *
	 * A per-product override exists for the awkward cases — an online-only
	 * bundle, a made-to-order item — whose stock the till has no business
	 * touching. Refusing one line does NOT fail the event: it is a deliberate
	 * configuration choice, not an error, and the rest of the sale still applies.
	 *
	 * @param array $line
	 *
	 * @return bool
	 */
	private function pos_may_write( array $line ) {
		if ( ! class_exists( 'FW_POS_Policy' ) ) {
			return true;
		}

		$item = ! empty( $line['item_id'] ) ? FW_POS_Ledger::get_item( (int) $line['item_id'] ) : null;

		return FW_POS_Policy::pos_owns_stock( $item );
	}

	/**
	 * A movement row for a line the policy refused.
	 *
	 * @param array $line
	 *
	 * @return array
	 */
	private function refused( array $line ) {
		return [
			'sku'    => isset( $line['sku'] ) ? (string) $line['sku'] : '',
			'ref'    => isset( $line['store_ref'] ) ? (string) $line['store_ref'] : '',
			'before' => null,
			'after'  => null,
			'error'  => 'policy_store_owned',
		];
	}

	/**
	 * One row of the stock-movement record kept on the event, so the log can
	 * show before/after without re-deriving anything.
	 *
	 * @param array $line
	 * @param array $outcome
	 *
	 * @return array
	 */
	private function move( array $line, array $outcome ) {
		return [
			'sku'    => isset( $line['sku'] ) ? (string) $line['sku'] : '',
			'ref'    => isset( $line['store_ref'] ) ? (string) $line['store_ref'] : '',
			'before' => $outcome['before'],
			'after'  => $outcome['after'],
			'error'  => $outcome['error'],
		];
	}

	/**
	 * What WOULD have happened, for test mode.
	 *
	 * @param array $lines
	 * @param int   $direction -1 sale, 1 return, 0 absolute
	 *
	 * @return array
	 */
	private function preview( array $lines, $direction ) {
		$planned = [];

		foreach ( $lines as $line ) {
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;

			$planned[] = [
				'sku'    => isset( $line['sku'] ) ? (string) $line['sku'] : '',
				'ref'    => isset( $line['store_ref'] ) ? (string) $line['store_ref'] : '',
				'change' => 0 === $direction ? 'set ' . $quantity : ( $direction * abs( $quantity ) ),
			];
		}

		return [ 'planned' => $planned ];
	}
}
