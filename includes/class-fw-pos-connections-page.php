<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Actions for the Connections tab.
 *
 * Split out of FW_POS_Admin_Page because connections are the security surface —
 * creating, rotating and revoking credentials — and it should be obvious where
 * that code lives rather than buried among tab plumbing.
 *
 * Everything here runs on `load-{$hook_suffix}`, before any output, so each
 * action can PRG-redirect and a newly issued secret can be handed across the
 * redirect exactly once.
 */
class FW_POS_Connections_Page {

	/** Where a just-issued credential is parked for one page load. */
	const TRANSIENT_ISSUED = 'fw_ext_pos_sync_issued_';

	/**
	 * @param FW_POS_Admin_Page $page
	 */
	public static function handle_actions( $page ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_POST['fw_pos_conn_action'] )
			? sanitize_key( wp_unslash( $_POST['fw_pos_conn_action'] ) )
			// phpcs:ignore WordPress.Security.NonceVerification
			: ( isset( $_GET['fw_pos_conn_action'] ) ? sanitize_key( wp_unslash( $_GET['fw_pos_conn_action'] ) ) : '' );

		if ( '' === $action ) {
			return;
		}

		if ( ! current_user_can( FW_POS_Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( FW_POS_Admin_Page::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification
		$id = isset( $_REQUEST['connection_id'] ) ? (int) $_REQUEST['connection_id'] : 0;

		switch ( $action ) {
			case 'create':
				self::create();
				break;

			case 'rotate':
				self::rotate( $id );
				break;

			case 'revoke':
				FW_POS_Connections::revoke( $id );
				FW_POS_Admin_Page::notice(
					'success',
					__( 'Connection revoked. Its key stops working immediately; its events stay in the log, which is exactly what you want to look at next.', 'fw' )
				);
				break;

			case 'restore':
				FW_POS_Connections::update( $id, [ 'status' => FW_POS_Connections::STATUS_ACTIVE ] );
				FW_POS_Admin_Page::notice( 'success', __( 'Connection restored.', 'fw' ) );
				break;

			case 'delete':
				FW_POS_Connections::delete( $id );
				FW_POS_Admin_Page::notice( 'success', __( 'Connection deleted.', 'fw' ) );
				break;

			case 'set_mode':
				// phpcs:ignore WordPress.Security.NonceVerification
				$mode = isset( $_REQUEST['mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['mode'] ) ) : 'test';
				FW_POS_Connections::update( $id, [ 'mode' => $mode ] );
				FW_POS_Admin_Page::notice(
					'success',
					FW_POS_Connections::MODE_LIVE === $mode
						? __( 'Connection switched to live. Its events will now change real stock.', 'fw' )
						: __( 'Connection switched to test. Its events will be logged in full but change no stock.', 'fw' )
				);
				break;

			default:
				return;
		}

		FW_POS_Admin_Page::redirect( 'connections' );
	}

	/**
	 * Issue a new connection and park the credential for one page load.
	 */
	private static function create() {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the caller.
		$name   = isset( $_POST['conn_name'] ) ? sanitize_text_field( wp_unslash( $_POST['conn_name'] ) ) : '';
		$mode   = isset( $_POST['conn_mode'] ) ? sanitize_key( wp_unslash( $_POST['conn_mode'] ) ) : 'test';
		$scopes = isset( $_POST['conn_scopes'] ) && is_array( $_POST['conn_scopes'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['conn_scopes'] ) )
			: [];
		$location = isset( $_POST['conn_location'] ) ? sanitize_text_field( wp_unslash( $_POST['conn_location'] ) ) : '';
		$type     = isset( $_POST['conn_type'] ) ? sanitize_key( wp_unslash( $_POST['conn_type'] ) ) : 'generic';
		// phpcs:enable WordPress.Security.NonceVerification

		if ( '' === trim( $name ) ) {
			FW_POS_Admin_Page::notice( 'error', __( 'Give the connection a name — it appears on every log line, and one connection per till is what makes a misbehaving terminal identifiable.', 'fw' ) );

			return;
		}

		$created = FW_POS_Connections::create(
			[
				'name'         => $name,
				'type'         => array_key_exists( $type, FW_POS_Providers::choices() ) ? $type : 'generic',
				'mode'         => $mode,
				'scopes'       => $scopes,
				'location_ref' => $location,
			]
		);

		if ( ! $created ) {
			FW_POS_Admin_Page::notice( 'error', __( 'The connection could not be created.', 'fw' ) );

			return;
		}

		self::park_credential( $created['api_key'], $created['secret'], $name );

		FW_POS_Admin_Page::notice(
			'success',
			'generic' === $type
				? __( 'Connection created. Copy the secret now — it is shown once.', 'fw' )
				: __( 'Connection created. Fill in the vendor details below to connect it. (The generic key and secret are issued anyway, so the same connection can also be driven directly if you ever need to.)', 'fw' )
		);
	}

	/**
	 * @param int $id
	 */
	private static function rotate( $id ) {
		$connection = FW_POS_Connections::get( $id );

		if ( ! $connection ) {
			return;
		}

		$secret = FW_POS_Connections::rotate_secret( $id );

		if ( null === $secret ) {
			FW_POS_Admin_Page::notice( 'error', __( 'The secret could not be rotated.', 'fw' ) );

			return;
		}

		self::park_credential( $connection['api_key'], $secret, $connection['name'] );

		FW_POS_Admin_Page::notice(
			'warning',
			__( 'A new secret has been issued. The old one stopped working immediately, so update the till now. The key is unchanged — only the secret field needs replacing.', 'fw' )
		);
	}

	/**
	 * The secret is shown exactly once, then forgotten.
	 *
	 * Not because it cannot be read back — it can, that is how signatures are
	 * verified — but because a credential sitting permanently on a settings page
	 * ends up in screenshots and support tickets. Losing it costs a rotation,
	 * which is one field at the till.
	 *
	 * @param string $key
	 * @param string $secret
	 * @param string $name
	 */
	private static function park_credential( $key, $secret, $name ) {
		set_transient(
			self::TRANSIENT_ISSUED . get_current_user_id(),
			[
				'name'   => $name,
				'key'    => $key,
				'secret' => $secret,
			],
			MINUTE_IN_SECONDS * 10
		);
	}

	/**
	 * Retrieve and immediately forget a just-issued credential.
	 *
	 * @return array|null
	 */
	public static function take_issued() {
		$issued = get_transient( self::TRANSIENT_ISSUED . get_current_user_id() );

		if ( $issued ) {
			delete_transient( self::TRANSIENT_ISSUED . get_current_user_id() );
		}

		return $issued ? $issued : null;
	}

	/**
	 * A nonced action URL.
	 *
	 * @param string $action
	 * @param int    $id
	 * @param array  $extra
	 *
	 * @return string
	 */
	public static function action_url( $action, $id, array $extra = [] ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					[
						'page'                => FW_POS_Admin_Page::PAGE_SLUG,
						'tab'                 => 'connections',
						'fw_pos_conn_action'  => $action,
						'connection_id'       => (int) $id,
					],
					$extra
				),
				admin_url( 'admin.php' )
			),
			FW_POS_Admin_Page::NONCE
		);
	}

	/**
	 * A ready-to-run signed request, so an integrator can prove the endpoint
	 * works before touching their till software.
	 *
	 * Generated with a placeholder secret rather than the real one — the point
	 * is to show the SHAPE, and putting a live credential in copyable text that
	 * lands in chat logs and screenshots is exactly the habit to avoid.
	 *
	 * @param array $connection
	 *
	 * @return string
	 */
	public static function example_request( array $connection ) {
		$body = wp_json_encode(
			[
				'external_id' => 'demo-' . gmdate( 'Ymd-His' ),
				'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'currency'    => 'GBP',
				'total'       => 3500,
				'line_items'  => [
					[
						'sku'        => 'YOUR-SKU',
						'quantity'   => 1,
						'unit_price' => 3500,
					],
				],
			]
		);

		$url = FW_POS_REST_Controller::base_url() . 'sale';

		return implode(
			"\n",
			[
				'SECRET=your-connection-secret',
				"BODY='" . $body . "'",
				'TS=$(date +%s)',
				'SIG="sha256=$(printf \'%s\\n%s\' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d" " -f1)"',
				'',
				'curl -X POST ' . $url . ' \\',
				'  -H "Content-Type: application/json" \\',
				'  -H "X-UPOS-Key: ' . $connection['api_key'] . '" \\',
				'  -H "X-UPOS-Timestamp: $TS" \\',
				'  -H "X-UPOS-Signature: $SIG" \\',
				'  --data-raw "$BODY"',
			]
		);
	}
}
