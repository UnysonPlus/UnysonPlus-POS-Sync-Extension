<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Unmatched items grid.
 *
 * Items the till has reported that POS Sync will not guess a product for. This
 * screen exists because auto-creating products from till data produces catalogs
 * full of `MISC-1` within days — but the queue is only useful if acting on it
 * is one click, which is what the row actions are for.
 */
class FW_POS_Items_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'pos_item',
				'plural'   => 'pos_items',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return [
			'cb'      => '<input type="checkbox" />',
			'sku'     => __( 'SKU', 'fw' ),
			'name'    => __( 'Reported name', 'fw' ),
			'gtin'    => __( 'Barcode', 'fw' ),
			'match'   => __( 'Matched product', 'fw' ),
			'updated' => __( 'Last seen', 'fw' ),
		];
	}

	/**
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts  = FW_POS_Ledger::item_status_counts();
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : FW_POS_Ledger::ITEM_UNMATCHED; // phpcs:ignore WordPress.Security.NonceVerification
		$base    = remove_query_arg( [ 'status', 'paged' ] );

		$labels = [
			FW_POS_Ledger::ITEM_UNMATCHED => __( 'Unmatched', 'fw' ),
			FW_POS_Ledger::ITEM_MATCHED   => __( 'Matched', 'fw' ),
			FW_POS_Ledger::ITEM_IGNORED   => __( 'Ignored', 'fw' ),
		];

		$views = [];

		foreach ( $labels as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0
			);
		}

		return $views;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_bulk_actions() {
		return [
			'ignore' => __( 'Mark as not a stock item', 'fw' ),
		];
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'fw_pos_items_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters on a GET screen.
		$args = [
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : FW_POS_Ledger::ITEM_UNMATCHED,
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		];
		// phpcs:enable WordPress.Security.NonceVerification

		$this->items = FW_POS_Ledger::query_items( $args );

		$this->set_pagination_args(
			[
				'total_items' => FW_POS_Ledger::count_items( $args ),
				'per_page'    => $per_page,
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="item_ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return esc_html( $item['name'] );

			case 'gtin':
				return $item['gtin'] ? '<code>' . esc_html( $item['gtin'] ) . '</code>' : '—';

			case 'updated':
				return esc_html( FW_POS_Log::local_time( $item['updated_at'] ) );

			default:
				return '';
		}
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_sku( $item ) {
		$sku = '' !== (string) $item['sku']
			? '<code>' . esc_html( $item['sku'] ) . '</code>'
			: '<em>' . esc_html__( 'no SKU sent', 'fw' ) . '</em>';

		$actions = [];

		if ( FW_POS_Ledger::ITEM_IGNORED !== $item['status'] ) {
			$actions['ignore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'ignore_item', (int) $item['id'] ) ),
				esc_html__( 'Not a stock item', 'fw' )
			);
		} else {
			$actions['restore'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'unignore_item', (int) $item['id'] ) ),
				esc_html__( 'Put back in the queue', 'fw' )
			);
		}

		if ( FW_POS_Ledger::ITEM_MATCHED === $item['status'] ) {
			$actions['unmatch'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'unmatch_item', (int) $item['id'] ) ),
				esc_html__( 'Clear match', 'fw' )
			);
		} elseif ( '' !== (string) $item['sku'] ) {
			$actions['retry'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'rematch_item', (int) $item['id'] ) ),
				esc_html__( 'Look again', 'fw' )
			);
		}

		return $sku . $this->row_actions( $actions );
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_match( $item ) {
		if ( FW_POS_Ledger::ITEM_IGNORED === $item['status'] ) {
			return '<span class="description">' . esc_html__( 'Not a stock item', 'fw' ) . '</span>';
		}

		if ( '' === (string) $item['store_ref'] ) {
			return '<span class="fw-pos-badge is-pending">' . esc_html__( 'Unmatched', 'fw' ) . '</span>';
		}

		$store = FW_POS_Stores::active();
		$label = $store ? $store->describe( $item['store_ref'] ) : '';

		return ( $label ? esc_html( $label ) . '<br>' : '' )
			. '<code>' . esc_html( $item['store_ref'] ) . '</code>';
	}

	/**
	 * A nonced GET action URL for a row action.
	 *
	 * @param string $action
	 * @param int    $item_id
	 *
	 * @return string
	 */
	private function action_url( $action, $item_id ) {
		return wp_nonce_url(
			add_query_arg(
				[
					'page'          => FW_POS_Admin_Page::PAGE_SLUG,
					'tab'           => 'items',
					'fw_pos_action' => $action,
					'item_id'       => $item_id,
				],
				admin_url( 'admin.php' )
			),
			FW_POS_Admin_Page::NONCE
		);
	}

	public function no_items() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : FW_POS_Ledger::ITEM_UNMATCHED;

		if ( FW_POS_Ledger::ITEM_UNMATCHED === $status ) {
			esc_html_e( 'Nothing unmatched — every item the till has reported so far resolves to a product. This is the state you want before going live.', 'fw' );

			return;
		}

		esc_html_e( 'Nothing here yet.', 'fw' );
	}
}
