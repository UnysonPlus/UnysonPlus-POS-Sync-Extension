<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * POS Sync — keeps the online store in step with sales rung up on a physical till.
 *
 * The design in one line: a **normalized ledger in the middle, with a driver
 * seam on each side**. POS drivers only write to the ledger; store drivers only
 * read from it; neither knows the other exists. With N tills and M carts that
 * turns O(N*M) work into O(N+M), which is the entire reason this is its own
 * extension rather than a feature of the WooCommerce one.
 *
 * Layering (enforced, not aspirational):
 *
 *   Schema      class-fw-pos-schema.php   — the ONLY DDL. Tables + migrations.
 *   Ledger      class-fw-pos-ledger.php   — the ONLY SQL. Dumb repository.
 *   Queue       class-fw-pos-queue.php    — ordering, retries, the apply filter.
 *   Log/Admin   class-fw-pos-log*.php     — presentation over the repository.
 *
 * Nothing above the ledger writes SQL; the ledger holds no business rules and
 * fires no hooks. Keep it that way — it is what lets the store drivers (M2),
 * the webhook API (M3), the Virtual Terminal (M4) and the Square driver (M5)
 * arrive without a rewrite.
 *
 * Milestone 1 ships the middle only. There is no store driver yet, so events
 * are recorded and then skipped with a visible `no_store_driver` reason rather
 * than silently dropped — an empty log and a log full of explained skips are
 * very different support conversations.
 *
 * @see https://docs.unysonplus.com/extensions/pos-sync/architecture
 */
class FW_Extension_POS_Sync extends FW_Extension {

	/** @var FW_POS_Queue|null */
	private $queue = null;

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-pos-schema.php';
		require_once $dir . 'class-fw-pos-ledger.php';
		require_once $dir . 'class-fw-pos-queue.php';
		require_once $dir . 'class-fw-pos-log.php';

		// Schema check. One autoloaded get_option() when up to date, so it is
		// cheap enough to run on every load — which is what makes activation and
		// plugin-update upgrades self-healing, with no activation hook to miss.
		FW_POS_Schema::maybe_install();

		// The worker runs wherever cron runs, which is not only wp-admin.
		$this->queue = new FW_POS_Queue();
		$this->queue->register();

		if ( is_admin() ) {
			require_once $dir . 'class-fw-pos-log-table.php';
			require_once $dir . 'class-fw-pos-admin-page.php';

			new FW_POS_Admin_Page( $this );

			add_filter( 'set-screen-option', [ $this, '_filter_set_screen_option' ], 10, 3 );
			add_filter( 'set_screen_option_fw_pos_events_per_page', [ $this, '_filter_set_screen_option' ], 10, 3 );

			add_action( 'admin_enqueue_scripts', [ $this, '_action_admin_enqueue' ] );
		}

		// Timers go when the extension does. Data does not — see below.
		add_action( 'fw_extensions_before_deactivation', [ $this, '_action_before_deactivation' ] );
	}

	/**
	 * @internal
	 *
	 * @param mixed  $status
	 * @param string $option
	 * @param mixed  $value
	 *
	 * @return mixed
	 */
	public function _filter_set_screen_option( $status, $option, $value ) {
		if ( 'fw_pos_events_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}

		return $status;
	}

	/**
	 * @internal
	 */
	public function _action_admin_enqueue() {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, FW_POS_Admin_Page::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fw-pos-sync-admin',
			$this->get_uri( '/static/css/admin.css' ),
			[],
			$this->manifest->get_version()
		);
	}

	/**
	 * @internal
	 *
	 * Clear scheduled work on deactivation, but NEVER touch the tables. Turning
	 * an extension off to test something must not destroy a shop's audit trail,
	 * and a half-applied event stream is far worse than a paused one. Data
	 * removal is a separate, explicit action.
	 *
	 * The hook is network-wide and reports every extension being deactivated,
	 * so it has to check that this one is among them.
	 *
	 * @param array $extensions Extension names being deactivated.
	 */
	public function _action_before_deactivation( $extensions ) {
		$names = is_array( $extensions ) ? array_keys( $extensions ) : (array) $extensions;

		if ( ! in_array( $this->get_name(), $names, true ) && ! in_array( 'pos-sync', $names, true ) ) {
			return;
		}

		FW_POS_Queue::unschedule();
	}

	/* ---------------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------------- */

	/**
	 * Read the extension settings, falling back to the option defaults.
	 *
	 * One keyless read, then plain array lookups — not one DB query helper per
	 * key, matching the Breadcrumbs convention.
	 *
	 * @return array
	 */
	public function get_settings() {
		$values = (array) fw_get_db_ext_settings_option( $this->get_name() );

		$defaults = [
			'mode'         => 'test',
			'retention'    => '90',
			'batch_size'   => '20',
			'clock_skew'   => '2',
			'refuse_stale' => true,
		];

		$settings = [];

		foreach ( $defaults as $key => $default ) {
			$settings[ $key ] = isset( $values[ $key ] ) && '' !== $values[ $key ] ? $values[ $key ] : $default;
		}

		return $settings;
	}

	/**
	 * Is the extension allowed to write to the store?
	 *
	 * Test mode is the default for a new install on purpose: a shop should be
	 * able to watch a full trading day's events land in the log, and compare
	 * them against the till's own report, before anything touches real stock.
	 *
	 * @return bool
	 */
	public function is_live() {
		$settings = $this->get_settings();

		return 'live' === $settings['mode'];
	}
}
