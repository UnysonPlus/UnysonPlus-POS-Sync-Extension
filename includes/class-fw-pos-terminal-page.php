<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Actions for the Virtual Terminal tab.
 *
 * Runs on `load-{$hook_suffix}` like every other action here, so a scenario can
 * PRG-redirect and hand its results across in a transient rather than leaving a
 * POST that re-fires on refresh — which, for a screen whose whole job is firing
 * events, would be a genuinely annoying bug.
 */
class FW_POS_Terminal_Page {

	const TRANSIENT_RESULTS = 'fw_ext_pos_sync_vt_';

	/**
	 * @param FW_POS_Admin_Page $page
	 */
	public static function handle_actions( $page ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_POST['fw_pos_vt_action'] ) ) {
			return;
		}

		if ( ! current_user_can( FW_POS_Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( FW_POS_Admin_Page::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$action    = sanitize_key( wp_unslash( $_POST['fw_pos_vt_action'] ) );
		$conn_id   = isset( $_POST['vt_connection'] ) ? (int) $_POST['vt_connection'] : 0;
		$transport = isset( $_POST['vt_transport'] ) && 'internal' === $_POST['vt_transport'] ? 'internal' : 'http';
		$sku       = isset( $_POST['vt_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['vt_sku'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$connection = FW_POS_Connections::get( $conn_id );

		if ( ! $connection ) {
			FW_POS_Admin_Page::notice( 'error', __( 'That connection no longer exists.', 'fw' ) );
			self::redirect( $conn_id, $transport );
		}

		$simulator = new FW_POS_Simulator( $connection );

		if ( 'scenario' === $action ) {
			self::run_scenario( $simulator, $transport, $sku );
		} elseif ( 'fire' === $action ) {
			self::fire_one( $simulator, $transport, $sku );
		}

		self::redirect( $conn_id, $transport );
	}

	/**
	 * @param FW_POS_Simulator $simulator
	 * @param string           $transport
	 * @param string           $sku
	 */
	private static function run_scenario( $simulator, $transport, $sku ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$id        = isset( $_POST['scenario'] ) ? sanitize_key( wp_unslash( $_POST['scenario'] ) ) : '';
		$scenarios = FW_POS_Simulator::scenarios();

		if ( ! isset( $scenarios[ $id ] ) ) {
			return;
		}

		$result = $simulator->run_scenario( $id, $transport, $sku ? $sku : 'POS-DEMO-1' );

		self::park_results(
			[
				'label' => $scenarios[ $id ]['label'],
				'why'   => $scenarios[ $id ]['why'],
				'ok'    => $result['ok'],
				'steps' => $result['steps'],
			]
		);
	}

	/**
	 * @param FW_POS_Simulator $simulator
	 * @param string           $transport
	 * @param string           $sku
	 */
	private static function fire_one( $simulator, $transport, $sku ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the caller.
		$type     = isset( $_POST['vt_type'] ) ? sanitize_key( wp_unslash( $_POST['vt_type'] ) ) : 'sale';
		$quantity = isset( $_POST['vt_qty'] ) ? max( 1, (int) $_POST['vt_qty'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification

		$id   = 'vt-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false );
		$opts = [ 'transport' => $transport ];

		switch ( $type ) {
			case 'refund':
				$response = $simulator->fire(
					'refund',
					[
						'external_id' => $id,
						'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'restock'     => true,
						'line_items'  => [
							[
								'sku'      => $sku,
								'quantity' => $quantity,
							],
						],
					],
					$opts
				);
				break;

			case 'inventory':
				$response = $simulator->fire( 'inventory', $simulator->count( $id, $sku, $quantity ), $opts );
				break;

			case 'adjustment':
				$response = $simulator->fire(
					'inventory',
					$simulator->count( $id, $sku, $quantity, '', 'relative' ),
					$opts
				);
				break;

			default:
				$payload = $simulator->sale( $id, $sku );
				$payload['line_items'][0]['quantity'] = $quantity;
				$payload['total']                     = 1000 * $quantity;

				$response = $simulator->fire( 'sale', $payload, $opts );
		}

		// Drain immediately so the log shows the outcome, not just "pending".
		// Waiting for cron to answer "did that work?" makes the screen useless
		// as a check.
		if ( in_array( (int) $response['status'], [ 200, 202 ], true ) ) {
			( new FW_POS_Queue() )->run();
		}

		self::park_results(
			[
				'label' => __( 'Single event', 'fw' ),
				'why'   => '',
				'ok'    => in_array( (int) $response['status'], [ 200, 202 ], true ),
				'steps' => [
					[
						'label' => sprintf(
							/* translators: 1: event type, 2: SKU */
							__( '%1$s of %2$s', 'fw' ),
							ucfirst( $type ),
							$sku
						),
						'ok'    => in_array( (int) $response['status'], [ 200, 202 ], true ),
						'note'  => $response['error']
							? $response['error']
							: sprintf( 'HTTP %d %s', (int) $response['status'], isset( $response['data']['code'] ) ? $response['data']['code'] : '' ),
					],
				],
			]
		);
	}

	/**
	 * @param array $results
	 */
	private static function park_results( array $results ) {
		set_transient( self::TRANSIENT_RESULTS . get_current_user_id(), $results, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * @return array|null
	 */
	public static function take_results() {
		$results = get_transient( self::TRANSIENT_RESULTS . get_current_user_id() );

		if ( $results ) {
			delete_transient( self::TRANSIENT_RESULTS . get_current_user_id() );
		}

		return $results ? $results : null;
	}

	/**
	 * @param int    $conn_id
	 * @param string $transport
	 */
	private static function redirect( $conn_id, $transport ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => FW_POS_Admin_Page::PAGE_SLUG,
					'tab'           => 'terminal',
					'vt_connection' => (int) $conn_id,
					'vt_transport'  => $transport,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
