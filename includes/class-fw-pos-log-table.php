<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The event log grid.
 *
 * A real WP_List_Table rather than a hand-rolled grid, matching the Newsletter
 * CRM's subscriber screen — it gets sorting, pagination, screen options, search
 * and bulk actions from core for free, and looks like the rest of wp-admin.
 */
class FW_POS_Log_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'pos_event',
				'plural'   => 'pos_events',
				'ajax'     => false,
			]
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return [
			'received'    => __( 'Received', 'fw' ),
			'occurred'    => __( 'Occurred at till', 'fw' ),
			'type'        => __( 'Type', 'fw' ),
			'external_id' => __( 'Transaction', 'fw' ),
			'summary'     => __( 'Items', 'fw' ),
			'state'       => __( 'Outcome', 'fw' ),
		];
	}

	/**
	 * Status links, so "show me what failed" is one click.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts  = FW_POS_Log::counts();
		$current = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base    = remove_query_arg( [ 'state', 'paged' ] );
		$total   = array_sum( $counts );

		$views = [
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'fw' ),
				$total
			),
		];

		foreach ( FW_POS_Log::states() as $state => $label ) {
			if ( empty( $counts[ $state ] ) ) {
				continue;
			}

			$views[ $state ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'state', $state, $base ) ),
				$current === $state ? ' class="current"' : '',
				esc_html( $label ),
				(int) $counts[ $state ]
			);
		}

		return $views;
	}

	/**
	 * Load the current page of rows.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'fw_pos_events_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters on a GET screen.
		$args = [
			'state'    => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		];
		// phpcs:enable WordPress.Security.NonceVerification

		$this->items = FW_POS_Ledger::query_events( $args );

		$this->set_pagination_args(
			[
				'total_items' => FW_POS_Ledger::count_events( $args ),
				'per_page'    => $per_page,
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}

	/**
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'received':
				return esc_html( FW_POS_Log::local_time( $item['received_at'] ) );

			case 'occurred':
				return esc_html( FW_POS_Log::local_time( $item['occurred_at'] ) );

			case 'type':
				return esc_html( FW_POS_Log::type_label( $item['type'] ) );

			case 'summary':
				return esc_html( FW_POS_Log::summarize( $item ) );

			default:
				return '';
		}
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_external_id( $item ) {
		$out = '<code>' . esc_html( $item['external_id'] ) . '</code>';

		if ( ! empty( $item['location_ref'] ) ) {
			$out .= '<br><span class="description">' . esc_html( $item['location_ref'] ) . '</span>';
		}

		// The event id is what a support conversation is conducted in — the
		// stored payload is verbatim, so an id alone reproduces the whole thing.
		$out .= '<br><span class="description">' . sprintf(
			/* translators: %d: event id */
			esc_html__( 'Event #%d', 'fw' ),
			(int) $item['id']
		) . '</span>';

		return $out;
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function column_state( $item ) {
		$out = sprintf(
			'<span class="fw-pos-badge %s">%s</span>',
			esc_attr( FW_POS_Log::state_class( $item['state'] ) ),
			esc_html( FW_POS_Log::state_label( $item['state'] ) )
		);

		if ( ! empty( $item['error'] ) ) {
			$out .= '<p class="description fw-pos-reason">' . esc_html( FW_POS_Log::explain( $item['error'] ) ) . '</p>';
		}

		if ( FW_POS_Ledger::STATE_PENDING === $item['state'] && (int) $item['attempts'] > 0 ) {
			$out .= '<p class="description">' . sprintf(
				/* translators: %d: number of attempts so far */
				esc_html__( 'Retrying — %d attempts so far.', 'fw' ),
				(int) $item['attempts']
			) . '</p>';
		}

		return $out;
	}

	/**
	 * Type filter beside the search box.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$current = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="fw-pos-type">' . esc_html__( 'Filter by type', 'fw' ) . '</label>';
		echo '<select name="type" id="fw-pos-type">';
		echo '<option value="">' . esc_html__( 'All types', 'fw' ) . '</option>';

		foreach ( FW_POS_Log::types() as $type => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $type ),
				selected( $current, $type, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		submit_button( __( 'Filter', 'fw' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Empty state.
	 *
	 * Worth writing properly: on a fresh install this is the ONLY thing on the
	 * screen, so it is the page's real first impression and the natural place
	 * to say what happens next.
	 */
	public function no_items() {
		esc_html_e( 'No events yet. Once a till is connected, every sale, refund and stock count it sends will be listed here — including the ones that were deliberately skipped, and why.', 'fw' );
	}
}
