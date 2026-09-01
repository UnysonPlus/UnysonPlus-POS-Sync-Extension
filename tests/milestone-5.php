<?php
/**
 * Functional check for POS Sync Milestone 5 — the Square driver.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-5.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * NO SQUARE ACCOUNT AND NO NETWORK REQUIRED. Every Square call is intercepted
 * through `pre_http_request` and answered from a canned response, which is why
 * this driver could be written at all without hardware. The fixtures are the
 * shapes Square actually returns; when Square changes them, this is the file
 * that has to change, and that is the point — the coupling is visible in one
 * place rather than spread through the driver.
 *
 * Exercises:
 *   1. signing     — Square's scheme (url+body, base64), which is NOT ours
 *   2. catalog     — SKUs come from VARIATIONS, and the map is stored
 *   3. normalizing — payments, refunds, inventory, and what is correctly ignored
 *   4. tokens      — refresh before expiry; a revoked grant fails permanently
 *   5. locations   — mapped explicitly, never guessed
 *   6. endpoint    — the provider route verifies, normalizes and records
 */

$dir = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/pos-sync/';

foreach ( [
	'includes/class-fw-pos-schema.php',
	'includes/class-fw-pos-ledger.php',
	'includes/class-fw-pos-queue.php',
	'includes/class-fw-pos-log.php',
	'includes/class-fw-pos-matcher.php',
	'includes/stores/class-fw-pos-store.php',
	'includes/stores/class-fw-pos-store-woocommerce.php',
	'includes/stores/class-fw-pos-stores.php',
	'includes/class-fw-pos-applier.php',
	'includes/class-fw-pos-secrets.php',
	'includes/class-fw-pos-connections.php',
	'includes/rest/class-fw-pos-signature.php',
	'includes/rest/class-fw-pos-validator.php',
	'includes/rest/class-fw-pos-rest-controller.php',
	'includes/class-fw-pos-simulator.php',
	'includes/providers/class-fw-pos-provider.php',
	'includes/providers/class-fw-pos-providers.php',
	'includes/providers/square/class-fw-pos-square-api.php',
	'includes/providers/square/class-fw-pos-square-oauth.php',
	'includes/providers/square/class-fw-pos-square-catalog.php',
	'includes/providers/square/class-fw-pos-square-webhooks.php',
	'includes/providers/square/class-fw-pos-provider-square.php',
] as $file ) {
	require_once $dir . $file;
}

function check( $label = null, $condition = null, $detail = '' ) {
	static $pass = 0;
	static $fail = 0;

	if ( null === $label ) {
		return [ $pass, $fail ];
	}

	if ( $condition ) {
		$pass++;
		echo "  PASS  {$label}\n";
	} else {
		$fail++;
		echo "  FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
}

/* ---------------------------------------------------------------- HTTP mock */

$GLOBALS['sq_responses'] = [];
$GLOBALS['sq_calls']     = [];

/**
 * Queue a canned response for the next request whose URL contains $match.
 */
function sq_reply( $match, array $body, $status = 200 ) {
	$GLOBALS['sq_responses'][] = [
		'match'  => $match,
		'body'   => $body,
		'status' => $status,
	];
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		$GLOBALS['sq_calls'][] = $url;

		foreach ( $GLOBALS['sq_responses'] as $index => $canned ) {
			if ( false === strpos( $url, $canned['match'] ) ) {
				continue;
			}

			unset( $GLOBALS['sq_responses'][ $index ] );

			return [
				'headers'  => [],
				'body'     => wp_json_encode( $canned['body'] ),
				'response' => [
					'code'    => $canned['status'],
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			];
		}

		// An unmocked Square call is a test bug, not a network problem — say so
		// loudly rather than letting it silently reach the internet.
		return new WP_Error( 'sq_unmocked', 'No canned response for ' . $url );
	},
	10,
	3
);

/* ------------------------------------------------------------------- store */

class FW_POS_Store_Fake_M5 extends FW_POS_Store {
	public $stock = [ 'TSHIRT-M' => 10 ];

	public function get_id() {
		return 'fake';
	}

	public function get_label() {
		return 'Fake';
	}

	public function is_available() {
		return true;
	}

	public function get_capabilities() {
		return $this->default_capabilities();
	}

	public function find_by_sku( $sku, $gtin = null ) {
		return isset( $this->stock[ trim( (string) $sku ) ] ) ? 'product:' . trim( (string) $sku ) : null;
	}

	public function describe( $store_ref ) {
		return $store_ref;
	}

	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		return $this->stock_ok( 0, (int) $quantity );
	}

	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		return $this->stock_ok( 0, (int) $delta );
	}

	public function create_order( array $event, array $payload ) {
		return [ 'ok' => true, 'order_ref' => 'x', 'error' => null ];
	}

	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [ 'ok' => true, 'error' => null ];
	}
}

class FW_POS_Fake_Ext_M5 {
	public function is_live() {
		return true;
	}

	public function should_create_orders() {
		return false;
	}
}

FW_POS_Schema::uninstall();
FW_POS_Schema::install();

$fake    = new FW_POS_Store_Fake_M5();
$reflect = new ReflectionClass( 'FW_POS_Stores' );
$prop    = $reflect->getProperty( 'drivers' );
$prop->setAccessible( true );
$prop->setValue( null, [ 'fake' => $fake ] );
$active = $reflect->getProperty( 'active' );
$active->setAccessible( true );
$active->setValue( null, $fake );

$ext = new FW_POS_Fake_Ext_M5();
( new FW_POS_REST_Controller( $ext ) )->register();
do_action( 'rest_api_init', rest_get_server() );

$created = FW_POS_Connections::create( [ 'name' => 'Square counter', 'type' => 'square', 'mode' => 'live' ] );

FW_POS_Provider::store_credentials(
	$created['id'],
	[
		'environment'           => 'sandbox',
		'access_token'          => 'sandbox-token',
		'refresh_token'         => 'sandbox-refresh',
		'application_id'        => 'sandbox-app',
		'application_secret'    => 'sandbox-secret',
		'expires_at'            => time() + ( 30 * DAY_IN_SECONDS ),
		'webhook_signature_key' => 'whsk_test_key',
	]
);

$connection = FW_POS_Connections::get( $created['id'] );
$square     = new FW_POS_Provider_Square();

echo "\n=== 1. Square's signature scheme ===\n";

$url  = 'https://example.test/wp-json/unysonplus-pos/v1/provider/square?c=' . $created['id'];
$body = '{"type":"payment.created"}';
$sig  = base64_encode( hash_hmac( 'sha256', $url . $body, 'whsk_test_key', true ) );

$v = $square->verify_webhook( $connection, $body, [ 'x-square-hmacsha256-signature' => $sig ], $url );
check( 'a correct Square signature verifies', ! empty( $v['ok'] ), $v['code'] );

check(
	'it is NOT our scheme — url+body, base64, not timestamp\\nbody hex',
	$sig !== FW_POS_Signature::sign( 'whsk_test_key', (string) time(), $body )
);

$v = $square->verify_webhook( $connection, $body, [ 'x-square-hmacsha256-signature' => $sig ], $url . '/' );
check( 'a different URL fails — the URL is part of the signature', empty( $v['ok'] ) );

$v = $square->verify_webhook( $connection, $body . ' ', [ 'x-square-hmacsha256-signature' => $sig ], $url );
check( 'a modified body fails', empty( $v['ok'] ) );

$v = $square->verify_webhook( $connection, $body, [], $url );
check( 'a missing signature header fails', empty( $v['ok'] ) && 'missing_square_signature' === $v['code'] );

echo "\n=== 2. Catalog import ===\n";

sq_reply(
	'/v2/catalog/list',
	[
		'objects' => [
			[
				'type'      => 'ITEM',
				'id'        => 'ITEM_1',
				'item_data' => [
					'name'       => 'T-shirt',
					'variations' => [
						[
							'id'                  => 'VAR_M',
							'item_variation_data' => [ 'name' => 'Medium', 'sku' => 'TSHIRT-M' ],
						],
						[
							'id'                  => 'VAR_L',
							'item_variation_data' => [ 'name' => 'Large', 'sku' => 'TSHIRT-L' ],
						],
						[
							// No SKU — nothing to match against, correctly skipped.
							'id'                  => 'VAR_NOSKU',
							'item_variation_data' => [ 'name' => 'Small' ],
						],
					],
				],
			],
		],
	]
);

$import = $square->import_catalog( $connection );

check( 'the catalog imports', ! empty( $import['ok'] ), $import['error'] );
check( 'it counts VARIATIONS with a SKU, not items', 2 === $import['seen'], (string) $import['seen'] );
check( 'a variation with no SKU is skipped', '' === FW_POS_Square_Catalog::sku_for( 'VAR_NOSKU' ) );
check( 'the variation → SKU map is stored', 'TSHIRT-M' === FW_POS_Square_Catalog::sku_for( 'VAR_M' ) );
check( 'and for the other variation too', 'TSHIRT-L' === FW_POS_Square_Catalog::sku_for( 'VAR_L' ) );
check( 'a SKU present in the store is matched', 1 === $import['matched'], (string) $import['matched'] );
check( 'a SKU absent from the store waits unmatched',
	FW_POS_Ledger::ITEM_UNMATCHED === FW_POS_Ledger::get_item_by_sku( 'TSHIRT-L' )['status'] );

echo "\n=== 3. Normalizing ===\n";

$api = new FW_POS_Square_API( $connection );

sq_reply(
	'/v2/orders/ORDER_1',
	[
		'order' => [
			'line_items' => [
				[
					'name'              => 'T-shirt',
					'item_type'         => 'ITEM',
					'catalog_object_id' => 'VAR_M',
					'quantity'          => '2',
					'base_price_money'  => [ 'amount' => 1500, 'currency' => 'GBP' ],
				],
				[
					// A modifier is not a stock item; including it would create
					// a phantom unmatched entry for every coffee sold.
					'name'      => 'Gift wrap',
					'item_type' => 'MODIFIER',
					'quantity'  => '1',
				],
			],
		],
	]
);

$events = FW_POS_Square_Webhooks::normalize(
	$connection,
	[
		'type' => 'payment.created',
		'data' => [
			'object' => [
				'payment' => [
					'id'           => 'PAY_1',
					'status'       => 'COMPLETED',
					'order_id'     => 'ORDER_1',
					'location_id'  => 'LOC_SHOP',
					'created_at'   => '2026-09-04T10:00:00Z',
					'amount_money' => [ 'amount' => 3000, 'currency' => 'GBP' ],
				],
			],
		],
	],
	$api
);

check( 'a completed payment becomes one event', 1 === count( $events ), (string) count( $events ) );
check( 'typed as a sale', FW_POS_Ledger::TYPE_SALE === $events[0]['type'] );
check( 'with a stable, prefixed external id', 'sq-pay-PAY_1' === $events[0]['external_id'] );
check( 'the variation resolved to its SKU', 'TSHIRT-M' === $events[0]['payload']['line_items'][0]['sku'] );
check( 'the modifier was dropped', 1 === count( $events[0]['payload']['line_items'] ) );
check( 'quantity carried through', 2 === $events[0]['payload']['line_items'][0]['quantity'] );
check( 'money stays in minor units', 3000 === $events[0]['payload']['total'] );

$declined = FW_POS_Square_Webhooks::normalize(
	$connection,
	[
		'type' => 'payment.created',
		'data' => [ 'object' => [ 'payment' => [ 'id' => 'PAY_X', 'status' => 'FAILED', 'order_id' => 'ORDER_1' ] ] ],
	],
	$api
);
check( 'a DECLINED payment moves no stock', empty( $declined ) );

$ignored = FW_POS_Square_Webhooks::normalize( $connection, [ 'type' => 'customer.created' ], $api );
check( 'an unsubscribed event type is ignored, not an error', [] === $ignored );

sq_reply( '/v2/orders/ORDER_R', [ 'order' => [ 'line_items' => [
	[ 'name' => 'T-shirt', 'item_type' => 'ITEM', 'catalog_object_id' => 'VAR_M', 'quantity' => '1' ],
] ] ] );

$refunds = FW_POS_Square_Webhooks::normalize(
	$connection,
	[
		'type' => 'refund.created',
		'data' => [ 'object' => [ 'refund' => [
			'id'         => 'REF_1',
			'status'     => 'COMPLETED',
			'payment_id' => 'PAY_1',
			'order_id'   => 'ORDER_R',
			'created_at' => '2026-09-04T11:00:00Z',
		] ] ],
	],
	$api
);

check( 'a refund becomes a refund event', 1 === count( $refunds ) && FW_POS_Ledger::TYPE_REFUND === $refunds[0]['type'] );
check( 'linked back to the sale it refunds', 'sq-pay-PAY_1' === $refunds[0]['payload']['sale_external_id'] );

$inventory = FW_POS_Square_Webhooks::normalize(
	$connection,
	[
		'type' => 'inventory.count.updated',
		'data' => [ 'object' => [ 'inventory_counts' => [
			[ 'catalog_object_id' => 'VAR_M', 'state' => 'IN_STOCK', 'quantity' => '7', 'calculated_at' => '2026-09-04T12:00:00Z' ],
			// SOLD is a movement between states, not a level — counting it
			// would double-count against the IN_STOCK figure.
			[ 'catalog_object_id' => 'VAR_M', 'state' => 'SOLD', 'quantity' => '3', 'calculated_at' => '2026-09-04T12:00:00Z' ],
		] ] ],
	],
	$api
);

check( 'an inventory update becomes one event', 1 === count( $inventory ) );
check( 'only IN_STOCK is treated as a level', 1 === count( $inventory[0]['payload']['counts'] ) );
check( 'as an absolute count', 'absolute' === $inventory[0]['payload']['mode'] );
check( 'resolved to a SKU', 'TSHIRT-M' === $inventory[0]['payload']['counts'][0]['sku'] );

echo "\n=== 4. Tokens ===\n";

$fresh = FW_POS_Square_OAuth::ensure_fresh( $connection );
check( 'a token far from expiry is not refreshed', empty( $GLOBALS['sq_responses'] ) && $fresh['id'] === $connection['id'] );

FW_POS_Provider::store_credentials( $created['id'], [ 'expires_at' => time() + 3600 ] );
$expiring = FW_POS_Connections::get( $created['id'] );

sq_reply( '/oauth2/token', [
	'access_token'  => 'refreshed-token',
	'refresh_token' => 'refreshed-refresh',
	'expires_at'    => gmdate( 'c', time() + ( 30 * DAY_IN_SECONDS ) ),
] );

FW_POS_Square_OAuth::ensure_fresh( $expiring );
$after = FW_POS_Provider::credentials( FW_POS_Connections::get( $created['id'] ) );

check( 'a token near expiry IS refreshed before it fails', 'refreshed-token' === $after['access_token'], $after['access_token'] );
check( 'the refresh token is updated too', 'refreshed-refresh' === $after['refresh_token'] );

// A response with no refresh_token must not clobber the stored one.
FW_POS_Provider::store_credentials( $created['id'], [ 'expires_at' => time() + 3600 ] );
sq_reply( '/oauth2/token', [ 'access_token' => 'second-token', 'expires_at' => gmdate( 'c', time() + 86400 ) ] );
FW_POS_Square_OAuth::ensure_fresh( FW_POS_Connections::get( $created['id'] ) );
$after = FW_POS_Provider::credentials( FW_POS_Connections::get( $created['id'] ) );

check( 'a refresh returning no refresh_token keeps the old one', 'refreshed-refresh' === $after['refresh_token'], $after['refresh_token'] );

// A revoked grant is permanent — retrying cannot fix it.
sq_reply( '/oauth2/token', [ 'errors' => [ [ 'code' => 'UNAUTHORIZED', 'detail' => 'revoked' ] ] ], 401 );
$result = FW_POS_Square_OAuth::refresh( FW_POS_Connections::get( $created['id'] ) );

check( 'a revoked grant fails', empty( $result['ok'] ) );
check( 'and is marked PERMANENT, not retried forever', ! empty( $result['permanent'] ) );
check( 'the connection is flagged as needing reconnection',
	$square->needs_reconnect( FW_POS_Connections::get( $created['id'] ) ) );
check( 'and reports itself as not connected', ! $square->is_connected( FW_POS_Connections::get( $created['id'] ) ) );

// A 500 is transient and must NOT burn the grant.
FW_POS_Provider::store_credentials( $created['id'], [ 'needs_reconnect' => false ] );
sq_reply( '/oauth2/token', [ 'errors' => [ [ 'code' => 'INTERNAL', 'detail' => 'oops' ] ] ], 500 );
$result = FW_POS_Square_OAuth::refresh( FW_POS_Connections::get( $created['id'] ) );

check( 'a 500 on refresh is transient', empty( $result['ok'] ) && empty( $result['permanent'] ) );
check( 'and does NOT flag the connection', ! $square->needs_reconnect( FW_POS_Connections::get( $created['id'] ) ) );

echo "\n=== 5. Locations ===\n";

FW_POS_Provider::store_credentials( $created['id'], [ 'access_token' => 'sandbox-token', 'expires_at' => time() + ( 30 * DAY_IN_SECONDS ) ] );
$connection = FW_POS_Connections::get( $created['id'] );

sq_reply( '/v2/locations', [ 'locations' => [
	[ 'id' => 'LOC_SHOP', 'name' => 'High Street', 'type' => 'PHYSICAL' ],
	[ 'id' => 'LOC_ONLINE', 'name' => 'Online', 'type' => 'MOBILE' ],
] ] );

$locations = $square->locations( $connection );
check( 'locations are listed', 2 === count( $locations ), (string) count( $locations ) );
check( 'including the online one Square creates unasked', 'LOC_ONLINE' === $locations[1]['id'] );

sq_reply( '/v2/orders/ORDER_1', [ 'order' => [ 'line_items' => [
	[ 'name' => 'T-shirt', 'item_type' => 'ITEM', 'catalog_object_id' => 'VAR_M', 'quantity' => '1' ],
] ] ] );

$events = $square->normalize( $connection, [
	'type' => 'payment.created',
	'data' => [ 'object' => [ 'payment' => [
		'id' => 'PAY_L1', 'status' => 'COMPLETED', 'order_id' => 'ORDER_1',
		'location_id' => 'LOC_SHOP', 'created_at' => '2026-09-04T10:00:00Z',
		'amount_money' => [ 'amount' => 1500, 'currency' => 'GBP' ],
	] ] ],
] );

check( 'an UNMAPPED location passes the Square id through, visibly',
	'LOC_SHOP' === $events[0]['location_ref'], $events[0]['location_ref'] );

FW_POS_Provider_Square::set_location_map( $created['id'], [ 'LOC_SHOP' => 'high-street' ] );
$connection = FW_POS_Connections::get( $created['id'] );

sq_reply( '/v2/orders/ORDER_1', [ 'order' => [ 'line_items' => [
	[ 'name' => 'T-shirt', 'item_type' => 'ITEM', 'catalog_object_id' => 'VAR_M', 'quantity' => '1' ],
] ] ] );

$events = $square->normalize( $connection, [
	'type' => 'payment.created',
	'data' => [ 'object' => [ 'payment' => [
		'id' => 'PAY_L2', 'status' => 'COMPLETED', 'order_id' => 'ORDER_1',
		'location_id' => 'LOC_SHOP', 'created_at' => '2026-09-04T10:00:00Z',
		'amount_money' => [ 'amount' => 1500, 'currency' => 'GBP' ],
	] ] ],
] );

check( 'a MAPPED location is translated', 'high-street' === $events[0]['location_ref'], $events[0]['location_ref'] );
check( 'and the payload agrees', 'high-street' === $events[0]['payload']['location_ref'] );

echo "\n=== 6. The provider endpoint ===\n";

$route = '/unysonplus-pos/v1/provider/square';
$url   = FW_POS_REST_Controller::webhook_url( 'square', $created['id'] );

sq_reply( '/v2/orders/ORDER_1', [ 'order' => [ 'line_items' => [
	[ 'name' => 'T-shirt', 'item_type' => 'ITEM', 'catalog_object_id' => 'VAR_M', 'quantity' => '1' ],
] ] ] );

$body = wp_json_encode( [
	'type' => 'payment.created',
	'data' => [ 'object' => [ 'payment' => [
		'id' => 'PAY_E1', 'status' => 'COMPLETED', 'order_id' => 'ORDER_1',
		'location_id' => 'LOC_SHOP', 'created_at' => '2026-09-04T10:00:00Z',
		'amount_money' => [ 'amount' => 1500, 'currency' => 'GBP' ],
	] ] ],
] );

$request = new WP_REST_Request( 'POST', $route );
$request->set_query_params( [ 'c' => $created['id'], 'provider' => 'square' ] );
$request->set_header( 'content-type', 'application/json' );
$request->set_header( 'x-square-hmacsha256-signature', base64_encode( hash_hmac( 'sha256', $url . $body, 'whsk_test_key', true ) ) );
$request->set_body( $body );

$response = rest_do_request( $request );
check( 'a signed Square webhook is accepted', 202 === $response->get_status(), (string) $response->get_status() );
check( 'and recorded', ! empty( $response->get_data()['event_ids'] ) );

$event = FW_POS_Ledger::find_by_external_id( $created['id'], 'sq-pay-PAY_E1' );
check( 'the event reached the ledger', null !== $event );
check( 'attributed to the Square connection', $event && (int) $event['connection_id'] === (int) $created['id'] );

$request = new WP_REST_Request( 'POST', $route );
$request->set_query_params( [ 'c' => $created['id'], 'provider' => 'square' ] );
$request->set_header( 'x-square-hmacsha256-signature', 'nope' );
$request->set_body( $body );
check( 'an unsigned webhook is refused', 401 === rest_do_request( $request )->get_status() );

// An event type we ignore must still be a 2xx, or Square retries it forever.
$ignored_body = wp_json_encode( [ 'type' => 'customer.created' ] );
$request      = new WP_REST_Request( 'POST', $route );
$request->set_query_params( [ 'c' => $created['id'], 'provider' => 'square' ] );
$request->set_header( 'x-square-hmacsha256-signature', base64_encode( hash_hmac( 'sha256', $url . $ignored_body, 'whsk_test_key', true ) ) );
$request->set_body( $ignored_body );

$response = rest_do_request( $request );
check( 'an ignored event type is still 2xx, so Square stops retrying', 200 === $response->get_status(), (string) $response->get_status() );
check( 'and says it was ignored', ! empty( $response->get_data()['ignored'] ) );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
