<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Virtual Terminal engine — composes correctly-signed events and fires them
 * at this site's own endpoint, exactly as a till would.
 *
 * This is a shipping feature, not a dev tool. It is:
 *
 *  - the development test rig, so no hardware is needed to build any of this;
 *  - the merchant's **pre-launch check** — "did we wire this up right?" — before
 *    the shop opens;
 *  - the **support tool** for reproducing a customer's problem locally;
 *  - the **demo**: a sale visibly decrementing stock is the whole product.
 *
 * ## Two transports, and why the difference matters
 *
 * `http` sends a real HTTP request to the site's own URL. `internal` dispatches
 * through `rest_do_request()` in-process. They are NOT interchangeable:
 *
 *  - `internal` proves the handler is correct. It passes even when a security
 *    plugin blocks `/wp-json/`, the web server rewrites the Authorization
 *    header away, or loopback requests are firewalled — which are the top three
 *    reasons a real till's events never arrive.
 *  - `http` proves the whole path. It is the default for exactly that reason.
 *
 * Offering only the in-process one would produce a terminal that says
 * "everything works" on a site where nothing does.
 *
 * ## Why it can sign at all
 *
 * Because connection secrets are stored encrypted and recoverable rather than
 * hashed — see FW_POS_Secrets. A hashed secret would make this screen, and HMAC
 * verification generally, impossible.
 */
class FW_POS_Simulator {

	/** @var array The connection row to sign as. */
	private $connection;

	/**
	 * @param array $connection
	 */
	public function __construct( array $connection ) {
		$this->connection = $connection;
	}

	/* ---------------------------------------------------------------------- *
	 * Firing
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the exact bytes and headers for a request.
	 *
	 * @param string $route     sale|refund|inventory
	 * @param array  $payload
	 * @param array  $opts {
	 *     @type int    $timestamp Override the signing timestamp (for skew tests).
	 *     @type string $secret    Sign with a different secret (for failure tests).
	 *     @type string $body      Send a different body than was signed (tampering).
	 * }
	 *
	 * @return array{url:string,body:string,sent_body:string,headers:array}
	 */
	public function build( $route, array $payload, array $opts = [] ) {
		$body      = wp_json_encode( $payload );
		$timestamp = isset( $opts['timestamp'] ) ? (string) $opts['timestamp'] : (string) time();
		$secret    = isset( $opts['secret'] ) ? $opts['secret'] : FW_POS_Connections::secret_for( $this->connection );
		$signature = FW_POS_Signature::sign( $secret, $timestamp, $body );

		return [
			'url'       => FW_POS_REST_Controller::base_url() . $route,
			'body'      => $body,
			// A tampering test signs one body and sends another.
			'sent_body' => isset( $opts['body'] ) ? (string) $opts['body'] : $body,
			'headers'   => [
				'Content-Type'      => 'application/json',
				'X-UPOS-Key'        => $this->connection['api_key'],
				'X-UPOS-Timestamp'  => $timestamp,
				'X-UPOS-Signature'  => isset( $opts['signature'] ) ? (string) $opts['signature'] : $signature,
			],
		];
	}

	/**
	 * Fire a request and report what came back.
	 *
	 * @param string $route
	 * @param array  $payload
	 * @param array  $opts      Plus `transport` => http|internal.
	 *
	 * @return array{status:int,data:array,transport:string,error:string}
	 */
	public function fire( $route, array $payload, array $opts = [] ) {
		$transport = isset( $opts['transport'] ) && 'internal' === $opts['transport'] ? 'internal' : 'http';
		$built     = $this->build( $route, $payload, $opts );

		return 'internal' === $transport
			? $this->fire_internal( $route, $built )
			: $this->fire_http( $built );
	}

	/**
	 * @param array $built
	 *
	 * @return array
	 */
	private function fire_http( array $built ) {
		$response = wp_remote_post(
			$built['url'],
			[
				'headers'   => $built['headers'],
				'body'      => $built['sent_body'],
				'timeout'   => 20,
				// A dev tunnel or a local site with a self-signed certificate
				// would otherwise fail for a reason that has nothing to do with
				// what is being tested.
				'sslverify' => ! $this->is_local(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'status'    => 0,
				'data'      => [],
				'transport' => 'http',
				'error'     => $response->get_error_message(),
			];
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'status'    => (int) wp_remote_retrieve_response_code( $response ),
			'data'      => is_array( $decoded ) ? $decoded : [],
			'transport' => 'http',
			'error'     => '',
		];
	}

	/**
	 * @param string $route
	 * @param array  $built
	 *
	 * @return array
	 */
	private function fire_internal( $route, array $built ) {
		$request = new WP_REST_Request( 'POST', '/' . FW_POS_REST_Controller::NAMESPACE_V1 . '/' . $route );

		foreach ( $built['headers'] as $name => $value ) {
			$request->set_header( strtolower( str_replace( '-', '_', $name ) ), $value );
		}

		$request->set_body( $built['sent_body'] );

		$response = rest_do_request( $request );

		return [
			'status'    => (int) $response->get_status(),
			'data'      => (array) $response->get_data(),
			'transport' => 'internal',
			'error'     => '',
		];
	}

	/**
	 * @return bool
	 */
	private function is_local() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true )
			|| ( is_string( $host ) && (bool) preg_match( '/\.(test|local|localhost)$/i', $host ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Scenarios
	 * ---------------------------------------------------------------------- */

	/**
	 * The adversarial cases that break naive integrations.
	 *
	 * These are the point of the screen. The happy path is the easy part — what
	 * separates an integration that survives a trading week is what it does with
	 * a duplicate delivery, a reconnecting offline till, and a partial refund.
	 *
	 * Each declares what SHOULD happen, and the runner checks it, so this is a
	 * self-test rather than a "fire and squint at the log" tool.
	 *
	 * @return array<string,array>
	 */
	public static function scenarios() {
		return [
			'happy_path' => [
				'label'  => __( 'A normal sale', 'fw' ),
				'expect' => __( 'Accepted (202) and queued.', 'fw' ),
				'why'    => __( 'The baseline. If this fails, nothing else is worth reading.', 'fw' ),
			],
			'duplicate' => [
				'label'  => __( 'Duplicate delivery', 'fw' ),
				'expect' => __( 'Second delivery returns 200 with duplicate:true, and only ONE event exists.', 'fw' ),
				'why'    => __( 'Every POS retries a delivery it did not get a 2xx for. Returning an error would make it retry forever; applying it twice would move stock twice.', 'fw' ),
			],
			'out_of_order' => [
				'label'  => __( 'Offline till reconnects', 'fw' ),
				'expect' => __( 'Three sales delivered newest-first are applied oldest-first.', 'fw' ),
				'why'    => __( 'A till that loses its connection dumps its backlog late. Applying by arrival lets a stale morning event land on top of an accurate afternoon one.', 'fw' ),
			],
			'stale_count' => [
				'label'  => __( 'Stale stocktake', 'fw' ),
				'expect' => __( 'A count older than one already applied is skipped, with a reason.', 'fw' ),
				'why'    => __( 'This is the case ordering alone cannot fix: the old count arrives in a later batch, after the newer one is already committed.', 'fw' ),
			],
			'unknown_sku' => [
				'label'  => __( 'Unknown SKU', 'fw' ),
				'expect' => __( 'Accepted, then skipped whole — the item waits on the Unmatched screen.', 'fw' ),
				'why'    => __( 'Half a sale leaves stock wrong in a way nobody can see. Nothing is applied, nothing is invented, and the reason names the SKU.', 'fw' ),
			],
			'partial_refund' => [
				'label'  => __( 'Partial refund', 'fw' ),
				'expect' => __( 'A refund of one line from a two-line sale is accepted.', 'fw' ),
				'why'    => __( 'Where cheap integrations fall over — they refund the whole sale, or restock nothing.', 'fw' ),
			],
			'expired_signature' => [
				'label'  => __( 'Expired signature', 'fw' ),
				'expect' => __( '401 timestamp_outside_window. Nothing is written.', 'fw' ),
				'why'    => __( 'Bounds how long a captured request stays useful to an attacker.', 'fw' ),
			],
			'tampered_body' => [
				'label'  => __( 'Tampered body', 'fw' ),
				'expect' => __( '401 signature_mismatch. Nothing is written.', 'fw' ),
				'why'    => __( 'A valid signature over a different body must not be accepted.', 'fw' ),
			],
			'identical_redelivery' => [
				'label'  => __( 'Byte-identical re-delivery', 'fw' ),
				'expect' => __( 'The exact same signed bytes, sent twice, are accepted and de-duplicated — not rejected.', 'fw' ),
				'why'    => __( 'Many senders sign a delivery once and re-send the identical bytes when they do not get a 2xx. Refusing that as a replay turns a working retry into an auth error; idempotency already makes it a harmless no-op.', 'fw' ),
			],
			'clock_skew' => [
				'label'  => __( 'Till clock drifting', 'fw' ),
				'expect' => __( 'Accepted while inside the window, and the drift is recorded against the connection.', 'fw' ),
				'why'    => __( 'Skew is measured on the SIGNING timestamp. A drifting clock corrupts the ordering key, so it is surfaced rather than silently tolerated.', 'fw' ),
			],
			'bad_payload' => [
				'label'  => __( 'Malformed payload', 'fw' ),
				'expect' => __( '400 naming the exact field. Not retryable.', 'fw' ),
				'why'    => __( 'A payload that is wrong now will be wrong on every retry, so it must fail differently from a transient error.', 'fw' ),
			],
			'missing_offset' => [
				'label'  => __( 'Timestamp with no offset', 'fw' ),
				'expect' => __( '400 — the schema requires an explicit UTC offset.', 'fw' ),
				'why'    => __( 'occurred_at is the ordering key. An ambiguous ordering key is exactly what rewinds stock.', 'fw' ),
			],
		];
	}

	/**
	 * Run one scenario and report each step against what should have happened.
	 *
	 * @param string $id
	 * @param string $transport
	 * @param string $sku A SKU that exists, for the cases that need one.
	 *
	 * @return array{ok:bool,steps:array[]}
	 */
	public function run_scenario( $id, $transport = 'http', $sku = 'POS-DEMO-1' ) {
		$steps = [];
		$opts  = [ 'transport' => $transport ];
		$tag   = 'vt-' . $id . '-' . wp_generate_password( 6, false, false );

		switch ( $id ) {
			case 'happy_path':
				$r       = $this->fire( 'sale', $this->sale( $tag, $sku ), $opts );
				$steps[] = $this->step( __( 'Sale accepted', 'fw' ), $r, 202 );
				break;

			case 'duplicate':
				$payload = $this->sale( $tag, $sku );
				$first   = $this->fire( 'sale', $payload, $opts );
				$steps[] = $this->step( __( 'First delivery', 'fw' ), $first, 202 );

				// Signed a second later, as a real retry would be. (The
				// byte-identical case is its own scenario, and now also works.)
				$second  = $this->fire( 'sale', $payload, $opts + [ 'timestamp' => time() + 1 ] );
				$steps[] = $this->step( __( 'Retried delivery', 'fw' ), $second, 200 );
				$steps[] = $this->assert(
					__( 'Reported as a duplicate', 'fw' ),
					! empty( $second['data']['duplicate'] )
				);
				$steps[] = $this->assert(
					__( 'Only one event exists', 'fw' ),
					1 === FW_POS_Ledger::count_events( [ 'search' => $tag ] )
				);
				break;

			case 'out_of_order':
				// Delivered newest first; must be applied oldest first.
				foreach ( [ 3, 1, 2 ] as $offset ) {
					$r = $this->fire(
						'sale',
						$this->sale( $tag . '-' . $offset, $sku, [ 'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - ( $offset * 3600 ) ) ] ),
						$opts
					);

					$steps[] = $this->step(
						sprintf(
							/* translators: %d: hours ago */
							__( 'Sale from %dh ago accepted', 'fw' ),
							$offset
						),
						$r,
						202
					);
				}

				$batch = FW_POS_Ledger::claim_batch( 100 );
				$order = [];

				foreach ( $batch as $row ) {
					if ( 0 === strpos( $row['external_id'], $tag ) ) {
						$order[] = $row['external_id'];
					}
				}

				$steps[] = $this->assert(
					__( 'Queued oldest-first, not arrival order', 'fw' ),
					[ $tag . '-3', $tag . '-2', $tag . '-1' ] === $order,
					implode( ' → ', $order )
				);
				break;

			case 'stale_count':
				$fresh = $this->fire(
					'inventory',
					$this->count( $tag . '-new', $sku, 3, gmdate( 'Y-m-d\TH:i:s\Z' ) ),
					$opts
				);
				$steps[] = $this->step( __( 'Today\'s count accepted', 'fw' ), $fresh, 202 );

				$this->drain();
				$steps[] = $this->assert_state( __( 'Today\'s count applied', 'fw' ), $tag . '-new', 'applied' );

				$old = $this->fire(
					'inventory',
					$this->count( $tag . '-old', $sku, 99, gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ),
					$opts
				);
				$steps[] = $this->step( __( 'Yesterday\'s count accepted at the door', 'fw' ), $old, 202 );

				$this->drain();
				$steps[] = $this->assert_state( __( 'But refused as stale', 'fw' ), $tag . '-old', 'skipped', 'stale_count' );
				break;

			case 'unknown_sku':
				$payload = $this->sale( $tag, $sku );
				$payload['line_items'][] = [
					'sku'      => 'GHOST-' . wp_generate_password( 4, false, false ),
					'quantity' => 1,
				];

				$r       = $this->fire( 'sale', $payload, $opts );
				$steps[] = $this->step( __( 'Accepted at the door', 'fw' ), $r, 202 );

				$this->drain();
				$steps[] = $this->assert_state( __( 'Skipped whole, nothing partially applied', 'fw' ), $tag, 'skipped', 'unmatched_sku' );
				break;

			case 'partial_refund':
				$sale = $this->sale( $tag, $sku );
				$sale['line_items'][] = [
					'sku'        => $sku,
					'quantity'   => 1,
					'unit_price' => 500,
				];

				$steps[] = $this->step( __( 'Two-line sale accepted', 'fw' ), $this->fire( 'sale', $sale, $opts ), 202 );

				$refund = [
					'external_id'      => $tag . '-refund',
					'sale_external_id' => $tag,
					'occurred_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'partial'          => true,
					'restock'          => true,
					'line_items'       => [ [ 'sku' => $sku, 'quantity' => 1 ] ],
				];

				$steps[] = $this->step( __( 'Refund of one line accepted', 'fw' ), $this->fire( 'refund', $refund, $opts ), 202 );
				break;

			case 'expired_signature':
				$r = $this->fire( 'sale', $this->sale( $tag, $sku ), $opts + [ 'timestamp' => time() - 3600 ] );

				$steps[] = $this->step( __( 'Refused', 'fw' ), $r, 401 );
				$steps[] = $this->assert(
					__( 'Reported as outside the window', 'fw' ),
					isset( $r['data']['code'] ) && 'timestamp_outside_window' === $r['data']['code'],
					isset( $r['data']['code'] ) ? $r['data']['code'] : ''
				);
				break;

			case 'tampered_body':
				$r = $this->fire(
					'sale',
					$this->sale( $tag, $sku ),
					$opts + [ 'body' => wp_json_encode( [ 'external_id' => 'tampered' ] ) ]
				);

				$steps[] = $this->step( __( 'Refused', 'fw' ), $r, 401 );
				$steps[] = $this->assert(
					__( 'Reported as a signature mismatch', 'fw' ),
					isset( $r['data']['code'] ) && 'signature_mismatch' === $r['data']['code'],
					isset( $r['data']['code'] ) ? $r['data']['code'] : ''
				);
				break;

			case 'identical_redelivery':
				// Identical bytes AND identical signature, twice — what a sender
				// that signs once and re-sends actually does.
				$payload   = $this->sale( $tag, $sku );
				$timestamp = time();

				$first   = $this->fire( 'sale', $payload, $opts + [ 'timestamp' => $timestamp ] );
				$steps[] = $this->step( __( 'First delivery', 'fw' ), $first, 202 );

				$second  = $this->fire( 'sale', $payload, $opts + [ 'timestamp' => $timestamp ] );
				$steps[] = $this->step( __( 'Identical re-delivery accepted', 'fw' ), $second, 200 );
				$steps[] = $this->assert(
					__( 'De-duplicated rather than refused', 'fw' ),
					! empty( $second['data']['duplicate'] )
				);
				$steps[] = $this->assert(
					__( 'Still only one event', 'fw' ),
					1 === FW_POS_Ledger::count_events( [ 'search' => $tag ] )
				);
				break;

			case 'clock_skew':
				// Inside the window, but visibly out.
				$r       = $this->fire( 'sale', $this->sale( $tag, $sku ), $opts + [ 'timestamp' => time() - 200 ] );
				$steps[] = $this->step( __( 'Accepted while inside the window', 'fw' ), $r, 202 );

				$connection = FW_POS_Connections::get( (int) $this->connection['id'] );
				$steps[]    = $this->assert(
					__( 'Drift recorded against the connection', 'fw' ),
					$connection && abs( (int) $connection['last_skew'] ) > 100,
					$connection ? $connection['last_skew'] . 's' : ''
				);
				break;

			case 'bad_payload':
				$payload          = $this->sale( $tag, $sku );
				$payload['total'] = 'thirty-five';

				$r       = $this->fire( 'sale', $payload, $opts );
				$steps[] = $this->step( __( 'Refused', 'fw' ), $r, 400 );
				$steps[] = $this->assert(
					__( 'The error names the offending field', 'fw' ),
					isset( $r['data']['message'] ) && false !== strpos( $r['data']['message'], 'total' ),
					isset( $r['data']['message'] ) ? $r['data']['message'] : ''
				);
				break;

			case 'missing_offset':
				$r = $this->fire(
					'sale',
					$this->sale( $tag, $sku, [ 'occurred_at' => gmdate( 'Y-m-d H:i:s' ) ] ),
					$opts
				);

				$steps[] = $this->step( __( 'Refused', 'fw' ), $r, 400 );
				break;

			default:
				return [
					'ok'    => false,
					'steps' => [],
				];
		}

		$ok = true;

		foreach ( $steps as $step ) {
			$ok = $ok && $step['ok'];
		}

		return [
			'ok'    => $ok,
			'steps' => $steps,
		];
	}

	/* ---------------------------------------------------------------------- *
	 * cURL export
	 * ---------------------------------------------------------------------- */

	/**
	 * A runnable, correctly-signed request an integrator can paste anywhere.
	 *
	 * The secret is a placeholder rather than the real one. The point is to show
	 * the shape; putting a live credential into copyable text is how it ends up
	 * in a chat log.
	 *
	 * @param string $route
	 * @param array  $payload
	 *
	 * @return string
	 */
	public function curl_for( $route, array $payload ) {
		$body = wp_json_encode( $payload );
		$url  = FW_POS_REST_Controller::base_url() . $route;

		return implode(
			"\n",
			[
				'# Sign the EXACT bytes you send. Serialising twice — once to sign,',
				'# once to send — reorders keys and invalidates the signature.',
				'SECRET=your-connection-secret',
				"BODY='" . str_replace( "'", "'\\''", $body ) . "'",
				'TS=$(date +%s)',
				'SIG="sha256=$(printf \'%s\\n%s\' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d" " -f1)"',
				'',
				'curl -sS -X POST ' . $url . ' \\',
				'  -H "Content-Type: application/json" \\',
				'  -H "X-UPOS-Key: ' . $this->connection['api_key'] . '" \\',
				'  -H "X-UPOS-Timestamp: $TS" \\',
				'  -H "X-UPOS-Signature: $SIG" \\',
				'  --data-raw "$BODY"',
			]
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Payload builders
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $external_id
	 * @param string $sku
	 * @param array  $overrides
	 *
	 * @return array
	 */
	public function sale( $external_id, $sku, array $overrides = [] ) {
		return array_merge(
			[
				'external_id'  => $external_id,
				'occurred_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'location_ref' => (string) $this->connection['location_ref'],
				'currency'     => $this->currency(),
				'total'        => 1000,
				'line_items'   => [
					[
						'sku'        => $sku,
						'quantity'   => 1,
						'unit_price' => 1000,
					],
				],
				'meta'         => [ 'source' => 'virtual-terminal' ],
			],
			$overrides
		);
	}

	/**
	 * @param string $external_id
	 * @param string $sku
	 * @param int    $quantity
	 * @param string $occurred_at
	 * @param string $mode
	 *
	 * @return array
	 */
	public function count( $external_id, $sku, $quantity, $occurred_at = '', $mode = 'absolute' ) {
		return [
			'external_id'  => $external_id,
			'occurred_at'  => $occurred_at ? $occurred_at : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'location_ref' => (string) $this->connection['location_ref'],
			'mode'         => $mode,
			'counts'       => [
				[
					'sku'      => $sku,
					'quantity' => (int) $quantity,
				],
			],
		];
	}

	/**
	 * @return string
	 */
	private function currency() {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'GBP';
	}

	/* ---------------------------------------------------------------------- *
	 * Step helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Drain the queue synchronously, so a scenario can assert on the outcome
	 * rather than on "it was accepted".
	 */
	private function drain() {
		$queue = new FW_POS_Queue();
		$queue->run();
	}

	/**
	 * @param string $label
	 * @param array  $response
	 * @param int    $expected
	 *
	 * @return array
	 */
	private function step( $label, array $response, $expected ) {
		if ( $response['error'] ) {
			return [
				'label' => $label,
				'ok'    => false,
				'note'  => sprintf(
					/* translators: %s: transport error */
					__( 'The request could not be sent: %s', 'fw' ),
					$response['error']
				),
			];
		}

		return [
			'label' => $label,
			'ok'    => (int) $response['status'] === (int) $expected,
			'note'  => sprintf(
				/* translators: 1: actual status, 2: expected status */
				__( 'HTTP %1$d (expected %2$d)', 'fw' ),
				(int) $response['status'],
				(int) $expected
			),
		];
	}

	/**
	 * @param string $label
	 * @param bool   $condition
	 * @param string $note
	 *
	 * @return array
	 */
	private function assert( $label, $condition, $note = '' ) {
		return [
			'label' => $label,
			'ok'    => (bool) $condition,
			'note'  => (string) $note,
		];
	}

	/**
	 * @param string $label
	 * @param string $external_id
	 * @param string $expected_state
	 * @param string $expected_reason
	 *
	 * @return array
	 */
	private function assert_state( $label, $external_id, $expected_state, $expected_reason = '' ) {
		$event = FW_POS_Ledger::find_by_external_id( (int) $this->connection['id'], $external_id );

		if ( ! $event ) {
			return $this->assert( $label, false, __( 'the event was not found', 'fw' ) );
		}

		$ok = $event['state'] === $expected_state;

		if ( $ok && $expected_reason ) {
			$ok = false !== strpos( (string) $event['error'], $expected_reason );
		}

		return $this->assert(
			$label,
			$ok,
			$event['state'] . ( $event['error'] ? ' — ' . $event['error'] : '' )
		);
	}
}
