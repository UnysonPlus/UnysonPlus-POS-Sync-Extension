<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ → POS Sync screen.
 *
 * House conventions, matched deliberately (see Newsletter CRM / Site Migration):
 *  - add_submenu_page() under the shared `fw-extensions` parent, with position
 *    declared through the `fw_unysonplus_admin_submenu_order` filter rather than
 *    hard-coded.
 *  - Every POST action runs on `load-{$hook_suffix}`, before any output, so each
 *    one can PRG-redirect.
 *  - Native `nav-tab-wrapper` tabs — no hand-rolled pill UI.
 *  - Notices are handed across the redirect in a transient.
 *  - Settings are stored with fw_set_db_ext_settings_option() and rendered by
 *    fw()->backend->render_options(), because a settings form IS an options
 *    form — while the event log is not, so that part is a real WP_List_Table.
 */
class FW_POS_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-pos-sync';
	const NONCE            = 'fw_pos_sync_action';
	const TRANSIENT_NOTICE = 'fw_ext_pos_sync_notice_';

	/** @var FW_Extension_POS_Sync */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/** @var FW_POS_Log_Table|null */
	private $table = null;

	/** @var FW_POS_Items_Table|null */
	private $items_table = null;

	/**
	 * @param FW_Extension_POS_Sync $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', [ $this, '_action_admin_menu' ], 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', [ $this, '_filter_submenu_order' ] );
	}

	/* ---------------------------------------------------------------------- *
	 * Menu
	 * ---------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public static function capability() {
		return 'manage_options';
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'POS Sync', 'fw' ),
			__( 'POS Sync', 'fw' ),
			self::capability(),
			self::PAGE_SLUG,
			[ $this, '_render' ]
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, [ $this, '_load' ] );
		}
	}

	/**
	 * @internal
	 *
	 * @param array $order
	 *
	 * @return array
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! is_array( $order ) || in_array( self::PAGE_SLUG, $order, true ) ) {
			return $order;
		}

		$order[] = self::PAGE_SLUG;

		return $order;
	}

	/* ---------------------------------------------------------------------- *
	 * Load — every action runs here, before output
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _load() {
		$tab = $this->current_tab();

		add_screen_option(
			'per_page',
			'items' === $tab
				? [
					'label'   => __( 'Items per page', 'fw' ),
					'default' => 20,
					'option'  => 'fw_pos_items_per_page',
				]
				: [
					'label'   => __( 'Events per page', 'fw' ),
					'default' => 20,
					'option'  => 'fw_pos_events_per_page',
				]
		);

		$this->handle_actions();

		if ( 'log' === $tab ) {
			$this->table = new FW_POS_Log_Table();
			$this->table->prepare_items();
		} elseif ( 'items' === $tab ) {
			$this->items_table = new FW_POS_Items_Table();
			$this->items_table->prepare_items();
		}
	}

	/**
	 * Dispatch POST actions, then redirect (PRG) so a refresh cannot repeat one.
	 */
	private function handle_actions() {
		$this->handle_item_actions();
		$this->handle_bulk_item_actions();

		if ( empty( $_POST['fw_pos_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['fw_pos_action'] ) );
		$notice = null;

		switch ( $action ) {
			case 'save_settings':
				$notice = $this->save_settings();
				break;

			case 'run_queue':
				$queue  = new FW_POS_Queue();
				$stats  = $queue->run();
				$notice = [
					'type' => 'success',
					'text' => sprintf(
						/* translators: 1: processed, 2: applied, 3: skipped, 4: failed */
						__( 'Processed %1$d events — %2$d applied, %3$d skipped, %4$d failed.', 'fw' ),
						$stats['processed'],
						$stats['applied'],
						$stats['skipped'],
						$stats['failed']
					),
				];
				break;

			case 'reinstall_schema':
				FW_POS_Schema::install();
				$notice = [
					'type' => 'success',
					'text' => __( 'Database tables checked and brought up to date.', 'fw' ),
				];
				break;

			case 'requeue_skipped':
				// The payoff for skipping loudly instead of dropping: once a
				// store is connected or a SKU is mapped, the events that could
				// not be applied before are still here and still replayable.
				$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
				$count  = $reason ? FW_POS_Ledger::requeue_skipped( $reason ) : 0;

				if ( $count ) {
					FW_POS_Queue::schedule();
				}

				$notice = [
					'type' => 'success',
					'text' => sprintf(
						/* translators: %d: number of events re-queued */
						_n( '%d skipped event re-queued.', '%d skipped events re-queued.', $count, 'fw' ),
						$count
					),
				];
				break;
		}

		if ( $notice ) {
			set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::PAGE_SLUG,
					'tab'  => $this->current_tab(),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Row actions on the Unmatched screen. GET links with a nonce, PRG-redirected.
	 */
	private function handle_item_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_GET['fw_pos_action'] ) || empty( $_GET['item_id'] ) ) {
			return;
		}

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( self::NONCE );

		$action  = sanitize_key( wp_unslash( $_GET['fw_pos_action'] ) );
		$item_id = (int) $_GET['item_id'];
		$notice  = null;

		switch ( $action ) {
			case 'ignore_item':
				FW_POS_Ledger::ignore_item( $item_id );
				$notice = [
					'type' => 'success',
					'text' => __( 'Marked as not a stock item. It will be skipped from now on without filling the queue.', 'fw' ),
				];
				break;

			case 'unignore_item':
			case 'unmatch_item':
				FW_POS_Ledger::set_item_match( $item_id, '' );
				$notice = [
					'type' => 'success',
					'text' => __( 'Item returned to the unmatched queue.', 'fw' ),
				];
				break;

			case 'rematch_item':
				$notice = $this->rematch( $item_id );
				break;

			default:
				return;
		}

		if ( $notice ) {
			set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		}

		$this->redirect_to_tab( 'items' );
	}

	/**
	 * Bulk "not a stock item" on the Unmatched screen.
	 */
	private function handle_bulk_item_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_POST['item_ids'] ) || ! is_array( $_POST['item_ids'] ) ) {
			return;
		}

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( 'bulk-pos_items' );

		$table  = new FW_POS_Items_Table();
		$action = $table->current_action();

		if ( 'ignore' !== $action ) {
			return;
		}

		$ids = array_map( 'intval', wp_unslash( $_POST['item_ids'] ) ); // phpcs:ignore WordPress.Security

		foreach ( $ids as $id ) {
			FW_POS_Ledger::ignore_item( $id );
		}

		set_transient(
			self::TRANSIENT_NOTICE . get_current_user_id(),
			[
				'type' => 'success',
				'text' => sprintf(
					/* translators: %d: number of items */
					_n( '%d item marked as not a stock item.', '%d items marked as not stock items.', count( $ids ), 'fw' ),
					count( $ids )
				),
			],
			MINUTE_IN_SECONDS
		);

		$this->redirect_to_tab( 'items' );
	}

	/**
	 * Try the lookup again for one item — after the merchant has added the SKU
	 * to a product, which is the usual fix.
	 *
	 * On success this also re-queues every event previously skipped for an
	 * unmatched SKU, which is the whole reason those events were kept.
	 *
	 * @param int $item_id
	 *
	 * @return array{type:string,text:string}
	 */
	private function rematch( $item_id ) {
		$item  = FW_POS_Ledger::get_item( $item_id );
		$store = FW_POS_Stores::active();

		if ( ! $item || ! $store ) {
			return [
				'type' => 'error',
				'text' => __( 'Could not look that up — no store is connected.', 'fw' ),
			];
		}

		$ref = $store->find_by_sku( $item['sku'], $item['gtin'] );

		if ( ! $ref ) {
			return [
				'type' => 'warning',
				'text' => sprintf(
					/* translators: %s: SKU */
					__( 'Still no product with the SKU %s. Add it to the product this should track, then try again.', 'fw' ),
					$item['sku']
				),
			];
		}

		FW_POS_Ledger::set_item_match( $item_id, $ref );

		$requeued = FW_POS_Ledger::requeue_skipped( 'unmatched_sku' );

		if ( $requeued ) {
			FW_POS_Queue::schedule();
		}

		return [
			'type' => 'success',
			'text' => sprintf(
				/* translators: 1: product name, 2: number of events re-queued */
				__( 'Matched to %1$s. %2$d previously skipped events were re-queued.', 'fw' ),
				$store->describe( $ref ),
				$requeued
			),
		];
	}

	/**
	 * PRG redirect back to a tab.
	 *
	 * @param string $tab
	 */
	private function redirect_to_tab( $tab ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => self::PAGE_SLUG,
					'tab'  => $tab,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return array{type:string,text:string}
	 */
	private function save_settings() {
		$options = fw()->extensions->get( 'pos-sync' )->get_settings_options();
		$input   = isset( $_POST['fw_options'] ) ? fw_stripslashes_deep( $_POST['fw_options'] ) : []; // phpcs:ignore WordPress.Security
		$values  = fw_get_options_values_from_input( $options, $input );

		fw_set_db_ext_settings_option( 'pos-sync', null, $values );

		return [
			'type' => 'success',
			'text' => __( 'Settings saved.', 'fw' ),
		];
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log';

		return in_array( $tab, [ 'log', 'items', 'settings' ], true ) ? $tab : 'log';
	}

	/**
	 * @internal
	 */
	public function _render() {
		$tab    = $this->current_tab();
		$notice = get_transient( self::TRANSIENT_NOTICE . get_current_user_id() );

		if ( $notice ) {
			delete_transient( self::TRANSIENT_NOTICE . get_current_user_id() );
		}

		$unmatched = FW_POS_Schema::is_installed()
			? FW_POS_Ledger::count_items( [ 'status' => FW_POS_Ledger::ITEM_UNMATCHED ] )
			: 0;

		$tabs = [
			'log'      => __( 'Log', 'fw' ),
			'items'    => $unmatched
				? sprintf(
					/* translators: %d: number of unmatched items */
					__( 'Unmatched (%d)', 'fw' ),
					$unmatched
				)
				: __( 'Items', 'fw' ),
			'settings' => __( 'Settings', 'fw' ),
		];

		$view = $this->ext->locate_view_path( 'log' );

		if ( ! $view ) {
			return;
		}

		include $view;
	}

	/**
	 * The log table, for the view.
	 *
	 * @return FW_POS_Log_Table|null
	 */
	public function get_table() {
		return $this->table;
	}

	/**
	 * The items table, for the view.
	 *
	 * @return FW_POS_Items_Table|null
	 */
	public function get_items_table() {
		return $this->items_table;
	}

	/**
	 * Health facts the view shows above the table.
	 *
	 * Deliberately the things that explain a silent system: is the schema
	 * actually there, is a scheduler running, is a store driver connected, and
	 * how much is waiting. Milestone 6 grows this into a real dashboard.
	 *
	 * @return array
	 */
	public function get_status() {
		$store = FW_POS_Stores::active();

		return [
			'installed'   => FW_POS_Schema::is_installed(),
			'scheduler'   => FW_POS_Queue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron',
			'pending'     => FW_POS_Schema::is_installed() ? FW_POS_Ledger::pending_count() : 0,
			'has_driver'  => (bool) $store,
			'store_label' => $store ? $store->get_label() : '',
			'store_why'   => FW_POS_Stores::inactive_reason(),
			'live'        => $this->ext->is_live(),
			'unmatched'   => FW_POS_Schema::is_installed()
				? FW_POS_Ledger::count_items( [ 'status' => FW_POS_Ledger::ITEM_UNMATCHED ] )
				: 0,
			'endpoint'    => rest_url( 'unysonplus-pos/v1/' ),
		];
	}
}
