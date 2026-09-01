<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The reconciliation sweep — the part that assumes everything above it will
 * eventually fail.
 *
 * ## Why an event stream is not enough
 *
 * Everything else in this extension is built to make the event stream correct:
 * idempotent, ordered, retried, logged. It will still miss things. A webhook
 * subscription gets deleted during a dashboard tidy-up. A site is down for an
 * hour and the vendor gives up after its retry budget. Someone adjusts stock in
 * the POS in a way that emits no event. A plugin conflict swallows a request.
 *
 * None of those are hypothetical, and none of them announce themselves — the
 * log looks healthy because the events that would have appeared in it never
 * arrived. The only way to catch that class of failure is to periodically ask
 * the POS what it thinks the numbers are and compare.
 *
 * ## It reports; it does not silently fix
 *
 * A sweep that quietly corrected differences would hide the fact that events
 * are being lost, which is the more important problem. So the nightly run
 * produces a **report**, and applying it is a separate, deliberate action.
 *
 * ## Resync goes through the ledger, not around it
 *
 * When you do apply it, the correction is recorded as ordinary absolute-count
 * events rather than written straight to the cart. That way idempotency,
 * event-time ordering, matching and the authority policy all apply to it
 * exactly as they would to a real stocktake — and it shows up in the log as
 * something that happened, rather than stock changing with no explanation.
 */
class FW_POS_Reconciler {

	const HOOK          = 'fw_pos_reconcile';
	const REPORT_OPTION = 'fw_ext_pos_sync_reconcile_report';

	/**
	 * Schedule the nightly sweep.
	 */
	public function register() {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Small hours, when a shop is closed and a burst of API calls
			// competes with nothing.
			wp_schedule_event( self::next_night(), 'daily', self::HOOK );
		}
	}

	/**
	 * @return int
	 */
	private static function next_night() {
		$next = strtotime( 'tomorrow 03:15' );

		return $next ? $next : ( time() + DAY_IN_SECONDS );
	}

	/**
	 * Remove the schedule. Called on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/* ---------------------------------------------------------------------- *
	 * The sweep
	 * ---------------------------------------------------------------------- */

	/**
	 * Compare every connected POS against the store and record the differences.
	 *
	 * @return array{ok:bool,checked:int,drift:array[],skipped:string[],ran_at:int}
	 */
	public function run() {
		$report = [
			'ok'      => true,
			'checked' => 0,
			'drift'   => [],
			'skipped' => [],
			'ran_at'  => time(),
		];

		$store = FW_POS_Stores::active();

		if ( ! $store || ! $store->is_available() ) {
			$report['ok']        = false;
			$report['skipped'][] = 'no_store_driver';

			return $this->save( $report );
		}

		foreach ( FW_POS_Connections::all( [ 'status' => FW_POS_Connections::STATUS_ACTIVE ] ) as $connection ) {
			$provider = FW_POS_Providers::for_connection( $connection );

			if ( ! $provider ) {
				// A generic-webhook till has no API to ask. That is not a
				// failure, but it does mean this connection cannot be
				// reconciled, and saying so is more useful than omitting it.
				$report['skipped'][] = sprintf( '%s: no provider API to query', $connection['name'] );

				continue;
			}

			if ( ! $provider->is_connected( $connection ) ) {
				$report['skipped'][] = sprintf( '%s: not connected', $connection['name'] );

				continue;
			}

			$counts = $provider->fetch_counts( $connection );

			if ( empty( $counts ) ) {
				$report['skipped'][] = sprintf( '%s: returned no counts', $connection['name'] );

				continue;
			}

			foreach ( $counts as $sku => $pos_quantity ) {
				$report['checked']++;

				$item = FW_POS_Ledger::get_item_by_sku( $sku );

				if ( ! $item || '' === (string) $item['store_ref'] ) {
					// Unmatched items are already surfaced on their own screen;
					// repeating them here would bury the actual drift.
					continue;
				}

				if ( ! FW_POS_Policy::pos_owns_stock( $item ) ) {
					// Store-owned stock is SUPPOSED to differ. Reporting it as
					// drift would train people to ignore the report.
					continue;
				}

				$store_quantity = $this->store_quantity( $store, $item['store_ref'] );

				if ( null === $store_quantity ) {
					continue; // Stock not managed on this product.
				}

				if ( (int) $store_quantity === (int) $pos_quantity ) {
					continue;
				}

				$report['drift'][] = [
					'connection_id' => (int) $connection['id'],
					'connection'    => $connection['name'],
					'sku'           => (string) $sku,
					'pos'           => (int) $pos_quantity,
					'store'         => (int) $store_quantity,
					'difference'    => (int) $pos_quantity - (int) $store_quantity,
				];
			}
		}

		return $this->save( $report );
	}

	/**
	 * Turn a stored report into corrective events.
	 *
	 * Deliberately routed through `record_event()` — see the class docblock.
	 * The events carry the report's own timestamp as `occurred_at`, so if a
	 * newer genuine count has landed since the sweep ran, the ordering rule
	 * refuses the correction rather than undoing it. Applying a stale
	 * reconciliation is exactly the failure this whole layer exists to prevent,
	 * so it must not be the thing that causes it.
	 *
	 * @return array{queued:int,report:array|null}
	 */
	public static function apply_report() {
		$report = self::last_report();

		if ( ! $report || empty( $report['drift'] ) ) {
			return [
				'queued' => 0,
				'report' => $report,
			];
		}

		$by_connection = [];

		foreach ( $report['drift'] as $row ) {
			$by_connection[ (int) $row['connection_id'] ][] = [
				'sku'      => $row['sku'],
				'quantity' => (int) $row['pos'],
			];
		}

		$queued   = 0;
		$occurred = gmdate( 'Y-m-d\TH:i:s\Z', (int) $report['ran_at'] );

		foreach ( $by_connection as $connection_id => $counts ) {
			$external_id = 'recon-' . (int) $report['ran_at'] . '-' . $connection_id;

			$recorded = FW_POS_Ledger::record_event(
				[
					'connection_id' => $connection_id,
					'external_id'   => $external_id,
					'type'          => FW_POS_Ledger::TYPE_INVENTORY,
					'occurred_at'   => $occurred,
					'payload'       => [
						'external_id' => $external_id,
						'occurred_at' => $occurred,
						'mode'        => 'absolute',
						'counts'      => $counts,
						'meta'        => [ 'source' => 'reconciliation' ],
					],
				]
			);

			if ( $recorded['ok'] && ! $recorded['duplicate'] ) {
				$queued++;
			}
		}

		if ( $queued ) {
			FW_POS_Queue::schedule();
		}

		return [
			'queued' => $queued,
			'report' => $report,
		];
	}

	/* ---------------------------------------------------------------------- *
	 * Report storage
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $report
	 *
	 * @return array
	 */
	private function save( array $report ) {
		// Not autoloaded: it can be a few hundred rows on a big catalog and is
		// read on exactly one screen.
		update_option( self::REPORT_OPTION, $report, false );

		/**
		 * Fires after a reconciliation sweep.
		 *
		 * @param array $report
		 */
		do_action( 'fw_pos_reconciled', $report );

		return $report;
	}

	/**
	 * @return array|null
	 */
	public static function last_report() {
		$report = get_option( self::REPORT_OPTION );

		return is_array( $report ) ? $report : null;
	}

	/**
	 * Discard a report once it has been acted on, so a stale one cannot be
	 * applied twice by accident.
	 */
	public static function clear_report() {
		delete_option( self::REPORT_OPTION );
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * The store's current quantity for a reference, or null when it does not
	 * track stock for it.
	 *
	 * Read through `adjust_stock( 0 )` rather than a new interface method: a
	 * zero adjustment is defined to be a no-op that reports the current level,
	 * so every existing driver already answers this correctly without being
	 * changed. Adding a `get_stock()` to the seam for one caller would have
	 * meant every future driver implementing it.
	 *
	 * @param FW_POS_Store $store
	 * @param string       $store_ref
	 *
	 * @return int|null
	 */
	private function store_quantity( FW_POS_Store $store, $store_ref ) {
		$result = $store->adjust_stock( $store_ref, 0 );

		if ( empty( $result['ok'] ) ) {
			return null;
		}

		return null === $result['after'] ? null : (int) $result['after'];
	}
}
