<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The worker. Drains pending ledger events and hands each to the active store
 * driver, in event-time order, with retries.
 *
 * Why asynchronous at all: the ingest endpoint must acknowledge a POS webhook
 * in milliseconds. A vendor that does not get a prompt 2xx marks the delivery
 * failed and retries — so doing the cart write inline turns one slow product
 * update into a storm of duplicate deliveries. Ingest writes one row and
 * returns; everything else happens here.
 *
 * Scheduling: Action Scheduler when it is available (it ships with WooCommerce,
 * so on the target install it always is), WP-Cron otherwise. Both paths call
 * the same `run()`.
 */
class FW_POS_Queue {

	const HOOK      = 'fw_pos_process_queue';
	const GROUP     = 'fw-pos-sync';
	const BATCH     = 20;
	const MAX_TRIES = 5;

	/** Backoff in seconds per attempt, then capped at the last value. */
	const BACKOFF = [ 60, 300, 900, 3600 ];

	/**
	 * Wire the worker up. Called once from the extension's `_init()`.
	 */
	public function register() {
		add_action( self::HOOK, [ $this, 'run' ] );

		// WP-Cron fallback needs its schedule declared and an event standing.
		if ( ! self::has_action_scheduler() ) {
			add_filter( 'cron_schedules', [ $this, '_filter_cron_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval
			$this->ensure_cron_event();
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Scheduling
	 * ---------------------------------------------------------------------- */

	/**
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Ask for a drain as soon as possible.
	 *
	 * Called by the ingest layer after recording an event. Deliberately
	 * idempotent: several sales arriving together should produce one drain, not
	 * one per sale, so an existing pending action is left to do the work.
	 *
	 * @param int $delay Seconds to wait before running.
	 */
	public static function schedule( $delay = 0 ) {
		if ( self::has_action_scheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + (int) $delay, self::HOOK, [], self::GROUP );

				return;
			}

			if ( ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK, [], self::GROUP );
			}

			return;
		}

		$when = time() + max( 1, (int) $delay );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	/**
	 * @internal
	 *
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function _filter_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['fw_pos_five_minutes'] ) ) {
			$schedules['fw_pos_five_minutes'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (POS Sync)', 'fw' ),
			];
		}

		return $schedules;
	}

	/**
	 * A standing safety net for the WP-Cron path.
	 *
	 * The per-event scheduling above is what normally moves work along; this
	 * catches anything left behind when a single event was missed.
	 */
	private function ensure_cron_event() {
		if ( ! wp_next_scheduled( self::HOOK . '_sweep' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'fw_pos_five_minutes', self::HOOK . '_sweep' );
		}

		add_action( self::HOOK . '_sweep', [ $this, 'run' ] );
	}

	/**
	 * Remove everything this class scheduled. Called on deactivation — the data
	 * stays, the timers do not.
	 */
	public static function unschedule() {
		if ( self::has_action_scheduler() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK . '_sweep' );
	}

	/* ---------------------------------------------------------------------- *
	 * Processing
	 * ---------------------------------------------------------------------- */

	/**
	 * Drain one batch.
	 *
	 * @return array{processed:int,applied:int,skipped:int,failed:int}
	 */
	public function run() {
		$stats = [
			'processed' => 0,
			'applied'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		if ( ! FW_POS_Schema::is_installed() ) {
			return $stats;
		}

		// claim_batch() returns events in occurred_at order — see its docblock
		// for why that, and not arrival order, is the correct sequence.
		$events = FW_POS_Ledger::claim_batch( self::BATCH );

		foreach ( $events as $event ) {
			$outcome = $this->process( $event );

			$stats['processed']++;

			if ( isset( $stats[ $outcome ] ) ) {
				$stats[ $outcome ]++;
			}
		}

		// More waiting? Come straight back rather than idling until the next tick.
		if ( count( $events ) >= self::BATCH && FW_POS_Ledger::pending_count() > 0 ) {
			self::schedule();
		}

		return $stats;
	}

	/**
	 * Process a single event.
	 *
	 * @param array $event Row from the events table.
	 *
	 * @return string applied|skipped|failed
	 */
	private function process( array $event ) {
		$event_id = (int) $event['id'];
		$payload  = json_decode( (string) $event['payload'], true );
		$payload  = is_array( $payload ) ? $payload : [];

		// A stale absolute count must never be applied — this is the ordering
		// rule doing its job. Relative adjustments skip the check entirely
		// because they commute.
		$stale = $this->stale_reason( $event, $payload );

		if ( null !== $stale ) {
			FW_POS_Ledger::set_state( $event_id, FW_POS_Ledger::STATE_SKIPPED, null, $stale );

			return 'skipped';
		}

		/**
		 * Apply a normalized ledger event to the active store.
		 *
		 * Milestone 2 registers the WooCommerce driver here. Until one exists,
		 * nothing is listening and the event is skipped with a visible reason
		 * rather than silently dropped — an empty log and a log full of
		 * "no_store_driver" are very different support conversations.
		 *
		 * A listener returns:
		 *   array{ok:true, result?:array}                 applied
		 *   array{ok:false, retry:bool, error:string}     failed (retry) or skipped
		 *
		 * @param array|null $result
		 * @param array      $event   The event row.
		 * @param array      $payload The decoded payload.
		 */
		$result = apply_filters( 'fw_pos_apply_event', null, $event, $payload );

		if ( null === $result ) {
			FW_POS_Ledger::set_state(
				$event_id,
				FW_POS_Ledger::STATE_SKIPPED,
				null,
				'no_store_driver'
			);

			return 'skipped';
		}

		if ( ! empty( $result['ok'] ) ) {
			$this->record_counts( $event, $payload );

			FW_POS_Ledger::set_state(
				$event_id,
				FW_POS_Ledger::STATE_APPLIED,
				isset( $result['result'] ) ? $result['result'] : null
			);

			/**
			 * Fires after an event has been applied to the store.
			 *
			 * @param array $event
			 * @param array $result
			 */
			do_action( 'fw_pos_event_applied', $event, $result );

			return 'applied';
		}

		$error = isset( $result['error'] ) ? (string) $result['error'] : 'apply_failed';

		// A driver that says "don't retry" is reporting a decision, not a
		// fault — an unmatched SKU will still be unmatched in five minutes.
		if ( empty( $result['retry'] ) ) {
			FW_POS_Ledger::set_state( $event_id, FW_POS_Ledger::STATE_SKIPPED, null, $error );

			return 'skipped';
		}

		$attempts = FW_POS_Ledger::bump_attempts( $event_id );

		if ( $attempts >= self::MAX_TRIES ) {
			FW_POS_Ledger::set_state( $event_id, FW_POS_Ledger::STATE_FAILED, null, $error );

			/**
			 * Fires when an event has exhausted its retries.
			 *
			 * @param array  $event
			 * @param string $error
			 */
			do_action( 'fw_pos_event_failed', $event, $error );

			return 'failed';
		}

		// Left pending, so the next drain picks it up again.
		self::schedule( self::backoff( $attempts ) );

		return 'skipped';
	}

	/**
	 * Why this event should not be applied, or null if it should.
	 *
	 * Only absolute inventory counts can go stale. A sale or a relative
	 * adjustment describes a change, and changes commute — applying yesterday's
	 * "-1 sold" today still lands on the right number. An absolute count
	 * describes a state, and an older state must never replace a newer one.
	 *
	 * @param array $event
	 * @param array $payload
	 *
	 * @return string|null
	 */
	private function stale_reason( array $event, array $payload ) {
		if ( FW_POS_Ledger::TYPE_INVENTORY !== $event['type'] ) {
			return null;
		}

		$mode = isset( $payload['mode'] ) ? (string) $payload['mode'] : 'absolute';

		if ( 'absolute' !== $mode ) {
			return null;
		}

		$counts = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [];

		foreach ( $counts as $count ) {
			$sku = isset( $count['sku'] ) ? (string) $count['sku'] : '';

			if ( '' === $sku ) {
				continue;
			}

			$last = FW_POS_Ledger::last_count_at( $sku );

			if ( $last && strtotime( $last ) > strtotime( (string) $event['occurred_at'] ) ) {
				return sprintf(
					'stale_count: %s was counted at %s, this event is from %s',
					$sku,
					$last,
					$event['occurred_at']
				);
			}
		}

		return null;
	}

	/**
	 * Stamp the applied count time for every SKU in an absolute count, so the
	 * staleness check above has something to compare against next time.
	 *
	 * @param array $event
	 * @param array $payload
	 */
	private function record_counts( array $event, array $payload ) {
		if ( FW_POS_Ledger::TYPE_INVENTORY !== $event['type'] ) {
			return;
		}

		$mode = isset( $payload['mode'] ) ? (string) $payload['mode'] : 'absolute';

		if ( 'absolute' !== $mode ) {
			return;
		}

		$counts = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [];

		foreach ( $counts as $count ) {
			if ( ! empty( $count['sku'] ) ) {
				FW_POS_Ledger::touch_count( (string) $count['sku'], (string) $event['occurred_at'] );
			}
		}
	}

	/**
	 * Seconds to wait before retry number $attempt.
	 *
	 * Capped rather than unbounded: a cart that is down for an hour should be
	 * retried every hour, not backed off into next week.
	 *
	 * @param int $attempt
	 *
	 * @return int
	 */
	public static function backoff( $attempt ) {
		$index = max( 0, (int) $attempt - 1 );
		$last  = count( self::BACKOFF ) - 1;

		return self::BACKOFF[ min( $index, $last ) ];
	}
}
