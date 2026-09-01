<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Square half of the Connections tab: connect, map locations, import the
 * catalog, and show the exact webhook URL to register.
 *
 * Kept separate from FW_POS_Connections_Page because it is provider-specific by
 * nature, and because the next provider should be able to add its own without
 * touching generic connection handling.
 */
class FW_POS_Square_Page {

	/**
	 * @param FW_POS_Admin_Page $page
	 */
	public static function handle_actions( $page ) {
		// The OAuth callback arrives as a plain GET from Square, so it cannot
		// carry a WordPress nonce. The `state` value is what protects it: it is
		// single-use, tied to one connection, and expires in fifteen minutes.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['square_callback'] ) ) {
			self::handle_callback();
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_POST['fw_pos_square_action'] )
			? sanitize_key( wp_unslash( $_POST['fw_pos_square_action'] ) )
			// phpcs:ignore WordPress.Security.NonceVerification
			: ( isset( $_GET['fw_pos_square_action'] ) ? sanitize_key( wp_unslash( $_GET['fw_pos_square_action'] ) ) : '' );

		if ( '' === $action ) {
			return;
		}

		if ( ! current_user_can( FW_POS_Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( FW_POS_Admin_Page::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification
		$id         = isset( $_REQUEST['connection_id'] ) ? (int) $_REQUEST['connection_id'] : 0;
		$connection = FW_POS_Connections::get( $id );

		if ( ! $connection ) {
			FW_POS_Admin_Page::redirect( 'connections' );
		}

		switch ( $action ) {
			case 'save_app':
				self::save_app( $connection );
				break;

			case 'connect':
				self::start_oauth( $connection );
				break;

			case 'disconnect':
				FW_POS_Square_OAuth::disconnect( $connection );
				FW_POS_Admin_Page::notice( 'success', __( 'Square disconnected. The grant has been revoked at Square too, so it no longer shows in the merchant\'s dashboard.', 'fw' ) );
				break;

			case 'import_catalog':
				self::import_catalog( $connection );
				break;

			case 'save_locations':
				self::save_locations( $connection );
				break;

			case 'backfill':
				$result = ( new FW_POS_Provider_Square() )->backfill( $connection, time() - ( 7 * DAY_IN_SECONDS ) );

				FW_POS_Admin_Page::notice(
					$result['ok'] ? 'success' : 'error',
					$result['ok']
						? sprintf(
							/* translators: %d: number of events */
							__( 'Backfilled %d payments from the last seven days. Running it again is harmless — idempotency means nothing applies twice.', 'fw' ),
							$result['count']
						)
						: $result['error']
				);
				break;

			default:
				return;
		}

		FW_POS_Admin_Page::redirect( 'connections' );
	}

	/**
	 * @param array $connection
	 */
	private static function save_app( array $connection ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the caller.
		$app_id      = isset( $_POST['sq_app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sq_app_id'] ) ) : '';
		$app_secret  = isset( $_POST['sq_app_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sq_app_secret'] ) ) : '';
		$environment = isset( $_POST['sq_environment'] ) && 'production' === $_POST['sq_environment'] ? 'production' : 'sandbox';
		$signature   = isset( $_POST['sq_signature_key'] ) ? sanitize_text_field( wp_unslash( $_POST['sq_signature_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$credentials = [ 'environment' => $environment ];

		// Only overwrite a secret that was actually re-entered. The form shows
		// them blank, so saving the page to change the environment must not
		// wipe credentials that are working.
		if ( '' !== $app_id ) {
			$credentials['application_id'] = $app_id;
		}

		if ( '' !== $app_secret ) {
			$credentials['application_secret'] = $app_secret;
		}

		if ( '' !== $signature ) {
			$credentials['webhook_signature_key'] = $signature;
		}

		FW_POS_Provider::store_credentials( (int) $connection['id'], $credentials );

		FW_POS_Admin_Page::notice( 'success', __( 'Square application details saved.', 'fw' ) );
	}

	/**
	 * @param array $connection
	 */
	private static function start_oauth( array $connection ) {
		$credentials = FW_POS_Provider::credentials( $connection );

		if ( empty( $credentials['application_id'] ) ) {
			FW_POS_Admin_Page::notice( 'error', __( 'Save the Square application ID and secret first.', 'fw' ) );

			return;
		}

		wp_redirect(
			FW_POS_Square_OAuth::authorize_url(
				$connection,
				$credentials['application_id'],
				isset( $credentials['environment'] ) ? $credentials['environment'] : 'sandbox'
			)
		);
		exit;
	}

	/**
	 * Square sends the merchant back here with a code and our state value.
	 */
	private static function handle_callback() {
		if ( ! current_user_can( FW_POS_Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- state is the guard; see above.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		$connection_id = FW_POS_Square_OAuth::consume_state( $state );

		if ( ! $connection_id ) {
			FW_POS_Admin_Page::notice( 'error', __( 'That authorization link has expired or was already used. Start the connection again.', 'fw' ) );
			FW_POS_Admin_Page::redirect( 'connections' );
		}

		$connection = FW_POS_Connections::get( $connection_id );

		if ( ! $connection || '' === $code ) {
			FW_POS_Admin_Page::notice( 'error', __( 'Square did not return an authorization code.', 'fw' ) );
			FW_POS_Admin_Page::redirect( 'connections' );
		}

		$credentials = FW_POS_Provider::credentials( $connection );

		$result = FW_POS_Square_OAuth::exchange(
			$connection,
			$code,
			isset( $credentials['application_id'] ) ? $credentials['application_id'] : '',
			isset( $credentials['application_secret'] ) ? $credentials['application_secret'] : ''
		);

		FW_POS_Admin_Page::notice(
			$result['ok'] ? 'success' : 'error',
			$result['ok']
				? __( 'Connected to Square. Import the catalog next, so items resolve to products before the first sale rather than during it.', 'fw' )
				: $result['error']
		);

		FW_POS_Admin_Page::redirect( 'connections' );
	}

	/**
	 * @param array $connection
	 */
	private static function import_catalog( array $connection ) {
		$result = ( new FW_POS_Provider_Square() )->import_catalog( $connection );

		if ( ! $result['ok'] ) {
			FW_POS_Admin_Page::notice( 'error', $result['error'] );

			return;
		}

		FW_POS_Admin_Page::notice(
			'success',
			sprintf(
				/* translators: 1: variations seen, 2: matched to products */
				__( 'Imported %1$d Square variations with a SKU; %2$d matched a product. Anything unmatched is on the Unmatched tab — clear it before going live.', 'fw' ),
				$result['seen'],
				$result['matched']
			)
		);
	}

	/**
	 * @param array $connection
	 */
	private static function save_locations( array $connection ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$input = isset( $_POST['sq_location'] ) && is_array( $_POST['sq_location'] ) ? wp_unslash( $_POST['sq_location'] ) : [];
		$map   = [];

		foreach ( $input as $square_id => $local ) {
			$local = sanitize_text_field( $local );

			if ( '' !== $local ) {
				$map[ sanitize_text_field( $square_id ) ] = $local;
			}
		}

		FW_POS_Provider_Square::set_location_map( (int) $connection['id'], $map );

		FW_POS_Admin_Page::notice( 'success', __( 'Location mapping saved.', 'fw' ) );
	}

	/**
	 * A nonced action URL.
	 *
	 * @param string $action
	 * @param int    $id
	 *
	 * @return string
	 */
	public static function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				[
					'page'                 => FW_POS_Admin_Page::PAGE_SLUG,
					'tab'                  => 'connections',
					'fw_pos_square_action' => $action,
					'connection_id'        => (int) $id,
				],
				admin_url( 'admin.php' )
			),
			FW_POS_Admin_Page::NONCE
		);
	}

	/**
	 * The redirect URI to register in the Square dashboard.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return add_query_arg(
			[
				'page'            => FW_POS_Admin_Page::PAGE_SLUG,
				'tab'             => 'connections',
				'square_callback' => 1,
			],
			admin_url( 'admin.php' )
		);
	}
}
