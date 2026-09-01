<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Square OAuth — connecting an account, and keeping the token alive.
 *
 * ## Why OAuth rather than a pasted access token
 *
 * Square will happily issue a personal access token you can paste into a
 * settings field, and it is tempting because it is one text input. It is also
 * long-lived, full-scope, and ends up in support tickets and screenshots. OAuth
 * costs one redirect and gives scoped, refreshable, individually revocable
 * access — and a merchant can see and withdraw it from their own Square
 * dashboard, which they cannot do with a token someone typed into WordPress.
 *
 * ## Refresh before expiry, not after
 *
 * Square access tokens last about 30 days. Refreshing only when a call fails
 * with 401 means the first sale after expiry is the one that fails — and it
 * fails at the worst moment, with a customer at the counter. So the token is
 * refreshed when it is within a day of expiring, on any call, and the failure
 * path is the fallback rather than the plan.
 *
 * When the refresh itself fails — the merchant revoked access, the application
 * was deleted — that is NOT retried into oblivion. The connection is marked
 * needing reconnection and says so on the admin screen, because no amount of
 * retrying fixes a withdrawn grant.
 */
class FW_POS_Square_OAuth {

	/** Refresh when the token expires within this long. */
	const REFRESH_MARGIN = DAY_IN_SECONDS;

	const STATE_TRANSIENT = 'fw_pos_square_state_';

	/**
	 * Where Square sends the merchant to authorize.
	 *
	 * @param array  $connection
	 * @param string $application_id
	 * @param string $environment sandbox|production
	 *
	 * @return string
	 */
	public static function authorize_url( array $connection, $application_id, $environment ) {
		$host = 'production' === $environment
			? 'https://connect.squareup.com'
			: 'https://connect.squareupsandbox.com';

		// A one-shot state value, tied to this connection, so the callback
		// cannot be replayed or pointed at a different connection.
		$state = wp_generate_password( 32, false, false );

		set_transient(
			self::STATE_TRANSIENT . $state,
			(int) $connection['id'],
			15 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			[
				'client_id'    => $application_id,
				'scope'        => implode(
					'+',
					[
						'MERCHANT_PROFILE_READ',
						'PAYMENTS_READ',
						'ORDERS_READ',
						'ITEMS_READ',
						'INVENTORY_READ',
					]
				),
				'session'      => 'false',
				'state'        => $state,
			],
			$host . '/oauth2/authorize'
		);
	}

	/**
	 * Resolve a state value back to the connection that started the flow.
	 *
	 * Single use: consumed on read, so a captured callback URL is worthless the
	 * moment it has been used once.
	 *
	 * @param string $state
	 *
	 * @return int Connection id, or 0.
	 */
	public static function consume_state( $state ) {
		$key = self::STATE_TRANSIENT . preg_replace( '/[^A-Za-z0-9]/', '', (string) $state );
		$id  = (int) get_transient( $key );

		if ( $id ) {
			delete_transient( $key );
		}

		return $id;
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param array  $connection
	 * @param string $code
	 * @param string $application_id
	 * @param string $application_secret
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function exchange( array $connection, $code, $application_id, $application_secret ) {
		$api = new FW_POS_Square_API( $connection );

		$result = $api->raw(
			'/oauth2/token',
			[
				'method' => 'POST',
				'body'   => [
					'client_id'     => $application_id,
					'client_secret' => $application_secret,
					'code'          => $code,
					'grant_type'    => 'authorization_code',
				],
			]
		);

		if ( ! $result['ok'] ) {
			return [
				'ok'    => false,
				'error' => $result['error'],
			];
		}

		return self::store_tokens( $connection, $result['data'], $application_id, $application_secret );
	}

	/**
	 * Refresh an access token.
	 *
	 * @param array $connection
	 *
	 * @return array{ok:bool,error:string,permanent:bool}
	 */
	public static function refresh( array $connection ) {
		$credentials = FW_POS_Provider::credentials( $connection );

		foreach ( [ 'refresh_token', 'application_id', 'application_secret' ] as $key ) {
			if ( empty( $credentials[ $key ] ) ) {
				return [
					'ok'        => false,
					'error'     => 'missing_' . $key,
					'permanent' => true,
				];
			}
		}

		$api = new FW_POS_Square_API( $connection );

		$result = $api->raw(
			'/oauth2/token',
			[
				'method' => 'POST',
				'body'   => [
					'client_id'     => $credentials['application_id'],
					'client_secret' => $credentials['application_secret'],
					'refresh_token' => $credentials['refresh_token'],
					'grant_type'    => 'refresh_token',
				],
			]
		);

		if ( ! $result['ok'] ) {
			// A 4xx here means the grant is gone — revoked by the merchant, or
			// the application deleted. Retrying cannot fix that, and pretending
			// otherwise just delays telling someone.
			$permanent = ! FW_POS_Square_API::is_transient( $result );

			if ( $permanent ) {
				FW_POS_Provider::store_credentials( (int) $connection['id'], [ 'needs_reconnect' => true ] );
			}

			return [
				'ok'        => false,
				'error'     => $result['error'],
				'permanent' => $permanent,
			];
		}

		$stored = self::store_tokens(
			$connection,
			$result['data'],
			$credentials['application_id'],
			$credentials['application_secret']
		);

		return $stored + [ 'permanent' => false ];
	}

	/**
	 * Refresh if the token is close to expiring. Cheap no-op otherwise.
	 *
	 * @param array $connection
	 *
	 * @return array The (possibly refreshed) connection row.
	 */
	public static function ensure_fresh( array $connection ) {
		$credentials = FW_POS_Provider::credentials( $connection );

		if ( empty( $credentials['expires_at'] ) || ! empty( $credentials['needs_reconnect'] ) ) {
			return $connection;
		}

		if ( (int) $credentials['expires_at'] - time() > self::REFRESH_MARGIN ) {
			return $connection;
		}

		self::refresh( $connection );

		$fresh = FW_POS_Connections::get( (int) $connection['id'] );

		return $fresh ? $fresh : $connection;
	}

	/**
	 * @param array  $connection
	 * @param array  $data Square's token response.
	 * @param string $application_id
	 * @param string $application_secret
	 *
	 * @return array{ok:bool,error:string}
	 */
	private static function store_tokens( array $connection, array $data, $application_id, $application_secret ) {
		if ( empty( $data['access_token'] ) ) {
			return [
				'ok'    => false,
				'error' => 'no_access_token_in_response',
			];
		}

		$credentials = [
			'access_token'       => $data['access_token'],
			'application_id'     => $application_id,
			'application_secret' => $application_secret,
			'needs_reconnect'    => false,
		];

		// Only overwrite the refresh token when one was actually returned —
		// some grants return none, and clobbering it would strand the
		// connection at the next expiry with no way back.
		if ( ! empty( $data['refresh_token'] ) ) {
			$credentials['refresh_token'] = $data['refresh_token'];
		}

		if ( ! empty( $data['merchant_id'] ) ) {
			$credentials['merchant_id'] = $data['merchant_id'];
		}

		if ( ! empty( $data['expires_at'] ) ) {
			$credentials['expires_at'] = strtotime( $data['expires_at'] );
		}

		FW_POS_Provider::store_credentials( (int) $connection['id'], $credentials );

		return [
			'ok'    => true,
			'error' => '',
		];
	}

	/**
	 * Withdraw our own access, then forget the tokens.
	 *
	 * Revoking at Square first matters: clearing locally and skipping the call
	 * leaves a live grant the merchant can see in their dashboard for an
	 * application that no longer uses it.
	 *
	 * @param array $connection
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function disconnect( array $connection ) {
		$credentials = FW_POS_Provider::credentials( $connection );

		if ( ! empty( $credentials['access_token'] ) && ! empty( $credentials['application_id'] ) ) {
			$api = new FW_POS_Square_API( $connection );

			$api->raw(
				'/oauth2/revoke',
				[
					'method'  => 'POST',
					'headers' => [ 'Authorization' => 'Client ' . $credentials['application_secret'] ],
					'body'    => [
						'client_id'    => $credentials['application_id'],
						'access_token' => $credentials['access_token'],
					],
				]
			);
		}

		FW_POS_Provider::clear_credentials( (int) $connection['id'] );

		return [
			'ok'    => true,
			'error' => '',
		];
	}
}
