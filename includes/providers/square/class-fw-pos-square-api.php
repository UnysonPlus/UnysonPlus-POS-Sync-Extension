<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Square HTTP client.
 *
 * Thin on purpose. There is no official PHP SDK dependency here: Square's SDK
 * pulls a Composer tree into a WordPress plugin, and this driver needs perhaps
 * eight endpoints. `wp_remote_*` is already present, already respects the site's
 * proxy and timeout configuration, and is already mockable in tests through
 * `pre_http_request` — which is what makes the whole driver testable with no
 * Square account.
 *
 * Two things every call needs and every hand-rolled client forgets:
 *
 *  - **`Square-Version`.** Square pins behaviour to a dated API version. Omit
 *    the header and you silently get whatever the account defaults to, which
 *    changes under you.
 *  - **The right host.** Sandbox and production are different domains with
 *    disjoint ids. A sandbox catalog id means nothing in production, so mixing
 *    them produces "not found" errors that look like data corruption.
 */
class FW_POS_Square_API {

	/** The API version this driver is written against. */
	const VERSION = '2026-01-22';

	const HOST_PRODUCTION = 'https://connect.squareup.com';
	const HOST_SANDBOX    = 'https://connect.squareupsandbox.com';

	/** @var array */
	private $connection;

	/**
	 * @param array $connection
	 */
	public function __construct( array $connection ) {
		$this->connection = $connection;
	}

	/**
	 * @param array $connection
	 *
	 * @return string
	 */
	public static function host( array $connection ) {
		$credentials = FW_POS_Provider::credentials( $connection );
		$environment = isset( $credentials['environment'] ) ? $credentials['environment'] : 'sandbox';

		return 'production' === $environment ? self::HOST_PRODUCTION : self::HOST_SANDBOX;
	}

	/**
	 * @param string $path e.g. '/v2/locations'
	 * @param array  $args { @type string $method, @type array $body, @type array $query }
	 *
	 * @return array{ok:bool,status:int,data:array,error:string}
	 */
	public function request( $path, array $args = [] ) {
		$credentials = FW_POS_Provider::credentials( $this->connection );
		$token       = isset( $credentials['access_token'] ) ? $credentials['access_token'] : '';

		if ( '' === $token ) {
			return self::fail( 'not_connected', __( 'This connection has no Square access token.', 'fw' ) );
		}

		return $this->raw(
			$path,
			array_merge(
				$args,
				[
					'headers' => array_merge(
						isset( $args['headers'] ) ? $args['headers'] : [],
						[ 'Authorization' => 'Bearer ' . $token ]
					),
				]
			)
		);
	}

	/**
	 * A request with no bearer token — for the OAuth endpoints, which
	 * authenticate with the application secret instead.
	 *
	 * @param string $path
	 * @param array  $args
	 *
	 * @return array
	 */
	public function raw( $path, array $args = [] ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$url    = self::host( $this->connection ) . $path;

		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		$request = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				[
					'Square-Version' => self::VERSION,
					'Content-Type'   => 'application/json',
					'Accept'         => 'application/json',
				],
				isset( $args['headers'] ) ? $args['headers'] : []
			),
		];

		if ( isset( $args['body'] ) ) {
			$request['body'] = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $request );

		if ( is_wp_error( $response ) ) {
			// Transient by nature — the caller retries rather than giving up.
			return self::fail( 'transport', $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $decoded ) ? $decoded : [];

		if ( $status >= 200 && $status < 300 ) {
			return [
				'ok'     => true,
				'status' => $status,
				'data'   => $data,
				'error'  => '',
			];
		}

		return [
			'ok'     => false,
			'status' => $status,
			'data'   => $data,
			'error'  => self::describe_error( $status, $data ),
		];
	}

	/**
	 * Square returns an `errors` array; surface the first one usefully rather
	 * than reporting a bare status that sends someone to the dashboard to guess.
	 *
	 * @param int   $status
	 * @param array $data
	 *
	 * @return string
	 */
	private static function describe_error( $status, array $data ) {
		if ( ! empty( $data['errors'][0] ) ) {
			$error = $data['errors'][0];

			return sprintf(
				'%s: %s',
				isset( $error['code'] ) ? $error['code'] : 'error',
				isset( $error['detail'] ) ? $error['detail'] : ''
			);
		}

		return sprintf( 'HTTP %d', $status );
	}

	/**
	 * @param string $code
	 * @param string $message
	 *
	 * @return array
	 */
	private static function fail( $code, $message ) {
		return [
			'ok'     => false,
			'status' => 0,
			'data'   => [],
			'error'  => $code . ': ' . $message,
		];
	}

	/**
	 * Is a failure worth retrying?
	 *
	 * 429 and 5xx are; a 401 or a 400 will be just as true next time. The queue
	 * relies on this distinction, so getting it wrong either fills the log with
	 * doomed retries or gives up on a rate limit that would have cleared.
	 *
	 * @param array $result
	 *
	 * @return bool
	 */
	public static function is_transient( array $result ) {
		if ( 0 === (int) $result['status'] ) {
			return true; // Network failure.
		}

		return 429 === (int) $result['status'] || (int) $result['status'] >= 500;
	}
}
