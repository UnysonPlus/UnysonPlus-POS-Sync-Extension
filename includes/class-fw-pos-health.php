<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Health metrics, retention, and the alert that matters.
 *
 * ## The failure this is built around
 *
 * A POS integration's worst failure mode is not an error — it is **silence**. A
 * till whose webhook subscription was deleted does not throw; it simply stops
 * sending, the log stops growing, and everything looks calm. Stock drifts for
 * days and the first anyone knows is a customer buying something that is not
 * there, or a stocktake that does not add up.
 *
 * So the alert this class sends is not "something failed". It is **"a till that
 * normally reports has gone quiet"** — the thing nothing else can notice.
 *
 * ## Why queue age, not queue depth
 *
 * Twenty events waiting is a busy Saturday. One event waiting for six hours is
 * a broken cron. Depth alone cannot tell those apart, so the threshold is on
 * the age of the oldest waiting event.
 */
class FW_POS_Health {

	const HOOK           = 'fw_pos_health_check';
	const ALERT_OPTION   = 'fw_ext_pos_sync_last_alert';

	/** Warn when the oldest pending event is older than this. */
	const STALE_QUEUE_SECONDS = 1800;

	/** Warn when a connection that has reported before goes quiet for this long. */
	const SILENT_CONNECTION_SECONDS = 21600;

	/** Never send the same alert more than once in this window. */
	const ALERT_COOLDOWN = DAY_IN_SECONDS;

	/** @var FW_Extension_POS_Sync */
	private $ext;

	/**
	 * @param FW_Extension_POS_Sync $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;
	}

	public function register() {
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the schedule. Called on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The hourly check: prune, then alert if anything is wrong.
	 *
	 * @return array{problems:string[],pruned:int}
	 */
	public function run() {
		$settings = $this->ext->get_settings();
		$pruned   = FW_POS_Ledger::prune( (int) $settings['retention'] );
		$problems = self::problems();

		if ( $problems ) {
			$this->maybe_alert( $problems );
		}

		return [
			'problems' => $problems,
			'pruned'   => $pruned,
		];
	}

	/* ---------------------------------------------------------------------- *
	 * Metrics
	 * ---------------------------------------------------------------------- */

	/**
	 * Everything the dashboard shows, in one call.
	 *
	 * @return array
	 */
	public static function snapshot() {
		if ( ! FW_POS_Schema::is_installed() ) {
			return [ 'installed' => false ];
		}

		$since  = time() - DAY_IN_SECONDS;
		$recent = FW_POS_Ledger::state_counts_since( $since );
		$oldest = FW_POS_Ledger::oldest_pending_at();
		$store  = FW_POS_Stores::active();

		$applied = isset( $recent[ FW_POS_Ledger::STATE_APPLIED ] ) ? $recent[ FW_POS_Ledger::STATE_APPLIED ] : 0;
		$failed  = isset( $recent[ FW_POS_Ledger::STATE_FAILED ] ) ? $recent[ FW_POS_Ledger::STATE_FAILED ] : 0;
		$total   = array_sum( $recent );

		return [
			'installed'      => true,
			'pending'        => FW_POS_Ledger::pending_count(),
			'oldest_pending' => $oldest,
			'queue_age'      => $oldest ? time() - strtotime( $oldest . ' UTC' ) : 0,
			'scheduler'      => FW_POS_Queue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron',
			'recent'         => $recent,
			'recent_total'   => $total,
			'applied_24h'    => $applied,
			'failed_24h'     => $failed,
			// Deliberately failed/total and not failed/(failed+applied): a burst
			// of skips is itself worth seeing in the denominator.
			'failure_rate'   => $total > 0 ? round( ( $failed / $total ) * 100, 1 ) : 0.0,
			'unmatched'      => FW_POS_Ledger::count_items( [ 'status' => FW_POS_Ledger::ITEM_UNMATCHED ] ),
			'store'          => $store ? $store->get_label() : '',
			'capabilities'   => $store ? $store->get_capabilities() : [],
			'connections'    => self::connection_health(),
			'report'         => FW_POS_Reconciler::last_report(),
			'problems'       => self::problems(),
		];
	}

	/**
	 * Per-connection health.
	 *
	 * @return array[]
	 */
	public static function connection_health() {
		$since = time() - DAY_IN_SECONDS;
		$rows  = [];

		foreach ( FW_POS_Connections::all() as $connection ) {
			$last_seen = $connection['last_seen_at'] ? strtotime( $connection['last_seen_at'] . ' UTC' ) : 0;

			$rows[] = [
				'id'        => (int) $connection['id'],
				'name'      => $connection['name'],
				'type'      => $connection['type'],
				'mode'      => $connection['mode'],
				'status'    => $connection['status'],
				'last_seen' => $connection['last_seen_at'],
				'silent_for' => $last_seen ? time() - $last_seen : null,
				'skew'      => (int) $connection['last_skew'],
				'events_24h' => FW_POS_Ledger::count_for_connection( (int) $connection['id'], $since ),
			];
		}

		return $rows;
	}

	/**
	 * What is currently wrong, in plain language.
	 *
	 * @return string[]
	 */
	public static function problems() {
		if ( ! FW_POS_Schema::is_installed() ) {
			return [ __( 'The POS Sync database tables are missing.', 'fw' ) ];
		}

		$problems = [];
		$oldest   = FW_POS_Ledger::oldest_pending_at();

		if ( $oldest ) {
			$age = time() - strtotime( $oldest . ' UTC' );

			if ( $age > self::STALE_QUEUE_SECONDS ) {
				$problems[] = sprintf(
					/* translators: %s: human-readable duration */
					__( 'Events have been waiting to apply for %s. Background processing has probably stopped — check that WP-Cron or Action Scheduler is running.', 'fw' ),
					human_time_diff( time() - $age, time() )
				);
			}
		}

		foreach ( self::connection_health() as $connection ) {
			if ( FW_POS_Connections::STATUS_ACTIVE !== $connection['status'] ) {
				continue;
			}

			// Only a connection that HAS reported can go quiet. One that never
			// has is simply not set up yet, which is not an incident.
			if ( null !== $connection['silent_for'] && $connection['silent_for'] > self::SILENT_CONNECTION_SECONDS ) {
				$problems[] = sprintf(
					/* translators: 1: connection name, 2: human-readable duration */
					__( '%1$s has not reported for %2$s. A till that stops sending looks exactly like a quiet day, so this is worth checking.', 'fw' ),
					$connection['name'],
					human_time_diff( time() - $connection['silent_for'], time() )
				);
			}

			if ( abs( $connection['skew'] ) > 120 ) {
				$problems[] = sprintf(
					/* translators: 1: connection name, 2: seconds of drift */
					__( '%1$s reported a clock %2$d seconds out. Event ordering depends on that clock, so drift can let an old event overwrite a newer one.', 'fw' ),
					$connection['name'],
					$connection['skew']
				);
			}
		}

		$failed = FW_POS_Ledger::count_events( [ 'state' => FW_POS_Ledger::STATE_FAILED ] );

		if ( $failed > 0 ) {
			$problems[] = sprintf(
				/* translators: %d: number of failed events */
				_n(
					'%d event failed after every retry and needs attention.',
					'%d events failed after every retry and need attention.',
					$failed,
					'fw'
				),
				$failed
			);
		}

		return $problems;
	}

	/* ---------------------------------------------------------------------- *
	 * Alerting
	 * ---------------------------------------------------------------------- */

	/**
	 * Email the admin, at most once a day per distinct set of problems.
	 *
	 * The cooldown is keyed on a hash of the problems themselves rather than on
	 * time alone, so a NEW problem still gets through immediately while the same
	 * one does not arrive hourly. An alert that cries wolf every hour is an
	 * alert people filter, and then the one that mattered is filtered too.
	 *
	 * @param string[] $problems
	 */
	private function maybe_alert( array $problems ) {
		$fingerprint = md5( implode( '|', $problems ) );
		$last        = (array) get_option( self::ALERT_OPTION, [] );

		if ( isset( $last['fingerprint'], $last['at'] )
			&& $last['fingerprint'] === $fingerprint
			&& ( time() - (int) $last['at'] ) < self::ALERT_COOLDOWN
		) {
			return;
		}

		update_option(
			self::ALERT_OPTION,
			[
				'fingerprint' => $fingerprint,
				'at'          => time(),
			],
			false
		);

		$to = apply_filters( 'fw_pos_alert_recipient', get_option( 'admin_email' ) );

		if ( ! $to ) {
			return;
		}

		$body = implode(
			"\n\n",
			array_merge(
				[ __( 'POS Sync has noticed something worth looking at:', 'fw' ) ],
				array_map(
					function ( $problem ) {
						return '• ' . $problem;
					},
					$problems
				),
				[
					sprintf(
						/* translators: %s: admin URL */
						__( 'Details: %s', 'fw' ),
						admin_url( 'admin.php?page=' . FW_POS_Admin_Page::PAGE_SLUG . '&tab=health' )
					),
				]
			)
		);

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] POS Sync needs attention', 'fw' ),
				get_bloginfo( 'name' )
			),
			$body
		);
	}
}
