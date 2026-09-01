<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The ingest endpoint — `/wp-json/unysonplus-pos/v1/…`
 *
 * This is the feature that makes POS Sync work with tills nobody has written a
 * driver for. Rather than an integration per vendor there is one documented,
 * signed, versioned wire format, and anything that can make an HTTPS request
 * can use it: a POS with outbound webhooks, a middleware platform, or a shop's
 * own till software.
 *
 * ## It does as little as possible
 *
 * Verify, validate, write one row, return 202. Everything else — matching,
 * ordering, the store write — happens on the queue. A vendor that does not get
 * a prompt 2xx marks the delivery failed and retries, so doing the cart write
 * inline turns one slow product update into a storm of duplicate deliveries.
 *
 * ## A duplicate is a SUCCESS
 *
 * A replayed webhook returns 200 with `duplicate: true`, not an error. A POS
 * that gets an error back keeps retrying forever, which is exactly the storm
 * the unique index exists to absorb.
 *
 * @see https://docs.unysonplus.com/extensions/pos-sync/webhook-api
 */
class FW_POS_REST_Controller {

	const NAMESPACE_V1 = 'unysonplus-pos/v1';

	/** @var FW_Extension_POS_Sync */
	private $ext;

	/**
	 * @param FW_Extension_POS_Sync $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;
	}

	public function register() {
		add_action( 'rest_api_init', [ $this, '_action_rest_api_init' ] );
	}

	/**
	 * @internal
	 */
	public function _action_rest_api_init() {
		$routes = [
			'sale'      => FW_POS_Ledger::TYPE_SALE,
			'refund'    => FW_POS_Ledger::TYPE_REFUND,
			'inventory' => FW_POS_Ledger::TYPE_INVENTORY,
		];

		foreach ( $routes as $route => $type ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/' . $route,
				[
					'methods'             => 'POST',
					'callback'            => function ( $request ) use ( $type ) {
						return $this->handle_event( $request, $type );
					},
					// Authentication is the signature, checked inside the
					// handler so the failure reason can be specific. Returning
					// true here is deliberate, not an oversight.
					'permission_callback' => '__return_true',
				]
			);
		}

		// One route per provider. They cannot share the generic one: every
		// vendor signs its own way, and pretending otherwise would mean
		// bending Square's scheme into ours at the door.
		register_rest_route(
			self::NAMESPACE_V1,
			'/provider/(?P<provider>[a-z0-9_-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_provider_webhook' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ping',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_ping' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/schema/(?P<name>[a-z]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_schema' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Handlers
	 * ---------------------------------------------------------------------- */

	/**
	 * Key only, no signature — so an integrator can separate "can I reach the
	 * site" from "is my signing correct" instead of debugging both at once.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function handle_ping( $request ) {
		$connection = FW_POS_Connections::get_by_key( $request->get_header( 'x_upos_key' ) );

		if ( ! $connection ) {
			return $this->error( 401, 'unknown_key', __( 'No connection matches that key.', 'fw' ) );
		}

		if ( FW_POS_Connections::STATUS_ACTIVE !== $connection['status'] ) {
			return $this->error( 401, 'revoked_key', __( 'That connection has been revoked.', 'fw' ) );
		}

		return new WP_REST_Response(
			[
				'ok'         => true,
				'connection' => $connection['name'],
				'mode'       => $connection['mode'],
				'schema'     => 'v1',
				'server_time' => time(),
			],
			200
		);
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function handle_schema( $request ) {
		$schema = FW_POS_Validator::load( $request->get_param( 'name' ) );

		if ( ! $schema ) {
			return $this->error( 404, 'unknown_schema', __( 'No such schema.', 'fw' ) );
		}

		return new WP_REST_Response( $schema, 200 );
	}

	/**
	 * The ingest path, shared by all three event routes.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $type
	 *
	 * @return WP_REST_Response
	 */
	private function handle_event( $request, $type ) {
		if ( ! FW_POS_Schema::is_installed() ) {
			return $this->error( 503, 'not_installed', __( 'POS Sync is not ready to receive events.', 'fw' ) );
		}

		// ---- 1. Identify the connection
		$connection = FW_POS_Connections::get_by_key( $request->get_header( 'x_upos_key' ) );

		if ( ! $connection ) {
			return $this->error( 401, 'unknown_key', __( 'No connection matches that key.', 'fw' ) );
		}

		if ( FW_POS_Connections::STATUS_ACTIVE !== $connection['status'] ) {
			return $this->error( 401, 'revoked_key', __( 'That connection has been revoked.', 'fw' ) );
		}

		// ---- 2. Verify the signature over the RAW body
		//
		// get_body() is the bytes as received. Using get_json_params() here and
		// re-encoding would change key order and whitespace, and no correctly
		// signed request would ever verify.
		$body = $request->get_body();

		$verified = FW_POS_Signature::verify(
			FW_POS_Connections::secret_for( $connection ),
			$request->get_header( 'x_upos_timestamp' ),
			$body,
			$request->get_header( 'x_upos_signature' )
		);

		// Record the observed clock skew whether or not the request verified —
		// a till whose clock is wrong is exactly the case we want visible.
		FW_POS_Connections::touch( (int) $connection['id'], $verified['skew'] );

		if ( ! $verified['ok'] ) {
			return $this->error(
				401,
				$verified['code'],
				FW_POS_Signature::explain( $verified['code'] ),
				[ 'skew_seconds' => $verified['skew'] ]
			);
		}

		// ---- 3. Scope
		$scope = FW_POS_Connections::scope_for_type( $type );

		if ( ! FW_POS_Connections::has_scope( $connection, $scope ) ) {
			return $this->error(
				403,
				'missing_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'This connection does not hold the %s scope.', 'fw' ),
					$scope
				)
			);
		}

		// ---- 4. Shape
		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return $this->error( 400, 'malformed_json', __( 'The request body was not valid JSON.', 'fw' ) );
		}

		$schema = FW_POS_Validator::load( $type );

		if ( $schema ) {
			$result = ( new FW_POS_Validator() )->validate( $payload, $schema );

			if ( ! $result['ok'] ) {
				// 400, and do not retry: the payload is wrong and will still be
				// wrong next time. The path is named so it can be fixed.
				return $this->error(
					400,
					'schema_invalid',
					$result['errors'][0],
					[
						'errors' => $result['errors'],
						'schema' => $type . '.v1',
					]
				);
			}
		}

		// ---- 5. Record
		$recorded = FW_POS_Ledger::record_event(
			[
				'connection_id' => (int) $connection['id'],
				'external_id'   => isset( $payload['external_id'] ) ? $payload['external_id'] : '',
				'type'          => $type,
				'occurred_at'   => isset( $payload['occurred_at'] ) ? $payload['occurred_at'] : '',
				'location_ref'  => isset( $payload['location_ref'] ) ? $payload['location_ref'] : $connection['location_ref'],
				'payload'       => $payload,
			]
		);

		if ( ! $recorded['ok'] ) {
			return $this->error( 500, 'record_failed', __( 'The event could not be recorded.', 'fw' ) );
		}

		if ( $recorded['duplicate'] ) {
			// 200, not an error. Tell the sender to stop retrying.
			return new WP_REST_Response(
				[
					'ok'        => true,
					'event_id'  => $recorded['event_id'],
					'state'     => $recorded['state'],
					'duplicate' => true,
				],
				200
			);
		}

		FW_POS_Queue::schedule();

		return new WP_REST_Response(
			[
				'ok'        => true,
				'event_id'  => $recorded['event_id'],
				'state'     => $recorded['state'],
				'duplicate' => false,
			],
			202
		);
	}

	/**
	 * A vendor's own webhook.
	 *
	 * Deliberately NOT the generic path. The vendor signs with its own scheme
	 * over its own canonical string, sends its own payload shape, and has its
	 * own idea of what an event is — so the provider verifies and normalizes,
	 * and only then does the result join the same ledger everything else uses.
	 *
	 * A vendor cannot supply our `X-UPOS-Key`, so the connection is identified
	 * by a `c` query parameter on the URL registered with them. That is not a
	 * credential and is not treated as one: the signature is what authenticates
	 * the request, and an attacker who guesses the connection id gains nothing
	 * without the signature key.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response
	 */
	public function handle_provider_webhook( $request ) {
		if ( ! FW_POS_Schema::is_installed() ) {
			return $this->error( 503, 'not_installed', __( 'POS Sync is not ready to receive events.', 'fw' ) );
		}

		$connection = FW_POS_Connections::get( (int) $request->get_param( 'c' ) );

		if ( ! $connection ) {
			return $this->error( 401, 'unknown_connection', __( 'No connection matches.', 'fw' ) );
		}

		if ( FW_POS_Connections::STATUS_ACTIVE !== $connection['status'] ) {
			return $this->error( 401, 'revoked_connection', __( 'That connection has been revoked.', 'fw' ) );
		}

		$provider = FW_POS_Providers::get( (string) $request->get_param( 'provider' ) );

		if ( ! $provider || $provider->get_id() !== $connection['type'] ) {
			return $this->error( 400, 'provider_mismatch', __( 'That connection is not for this provider.', 'fw' ) );
		}

		$body    = $request->get_body();
		$headers = [];

		foreach ( (array) $request->get_headers() as $name => $values ) {
			$headers[ strtolower( str_replace( '_', '-', $name ) ) ] = is_array( $values ) ? reset( $values ) : $values;
		}

		$verified = $provider->verify_webhook( $connection, $body, $headers, self::webhook_url( $provider->get_id(), (int) $connection['id'] ) );

		if ( empty( $verified['ok'] ) ) {
			return $this->error( 401, $verified['code'], __( 'The vendor signature did not verify.', 'fw' ) );
		}

		FW_POS_Connections::touch( (int) $connection['id'], 0 );

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return $this->error( 400, 'malformed_json', __( 'The request body was not valid JSON.', 'fw' ) );
		}

		$events = $provider->normalize( $connection, $payload );

		// Zero events is a SUCCESS. Vendors send many event types we did not
		// subscribe to and cannot act on; answering anything but 2xx would make
		// them retry an event we will never want.
		if ( empty( $events ) ) {
			return new WP_REST_Response(
				[
					'ok'      => true,
					'ignored' => true,
				],
				200
			);
		}

		$recorded  = [];
		$duplicate = true;

		foreach ( $events as $event ) {
			$result = FW_POS_Ledger::record_event( $event );

			if ( $result['ok'] ) {
				$recorded[] = $result['event_id'];

				if ( ! $result['duplicate'] ) {
					$duplicate = false;
				}
			}
		}

		if ( ! $duplicate ) {
			FW_POS_Queue::schedule();
		}

		return new WP_REST_Response(
			[
				'ok'        => true,
				'event_ids' => $recorded,
				'duplicate' => $duplicate,
			],
			$duplicate ? 200 : 202
		);
	}

	/**
	 * The URL a vendor should be configured with.
	 *
	 * This exact string is part of Square's signature, so it must match what is
	 * registered in their dashboard byte for byte. Generating it in one place
	 * is what stops the admin screen and the verifier disagreeing about a
	 * trailing slash.
	 *
	 * @param string $provider
	 * @param int    $connection_id
	 *
	 * @return string
	 */
	public static function webhook_url( $provider, $connection_id ) {
		return add_query_arg(
			'c',
			(int) $connection_id,
			self::base_url() . 'provider/' . $provider
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int    $status
	 * @param string $code
	 * @param string $message
	 * @param array  $extra
	 *
	 * @return WP_REST_Response
	 */
	private function error( $status, $code, $message, array $extra = [] ) {
		return new WP_REST_Response(
			array_merge(
				[
					'ok'      => false,
					'code'    => $code,
					'message' => $message,
				],
				$extra
			),
			$status
		);
	}

	/**
	 * The public base URL, honouring a development tunnel.
	 *
	 * Sandbox webhooks are delivered from a vendor's servers and cannot reach
	 * `localhost`, so a dev site behind a tunnel needs the generated URL to be
	 * the tunnel's, not the site's.
	 *
	 * @return string
	 */
	public static function base_url() {
		if ( defined( 'FW_POS_PUBLIC_URL' ) && FW_POS_PUBLIC_URL ) {
			return rtrim( FW_POS_PUBLIC_URL, '/' ) . '/wp-json/' . self::NAMESPACE_V1 . '/';
		}

		return rest_url( self::NAMESPACE_V1 . '/' );
	}
}
