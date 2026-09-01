<?php
/**
 * Functional check for POS Sync Milestone 3 — the signed webhook API.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-3.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * Requests go through WP_REST_Server via rest_do_request(), not by calling the
 * controller directly, so routing, header handling and status codes are all
 * genuinely under test. A test that calls the callback by hand proves the
 * callback works and nothing about whether the endpoint does.
 *
 * Exercises:
 *   1. secrets     — encrypted at rest, recoverable (a hash could not be)
 *   2. signing     — one implementation, byte-exact, constant-time compare
 *   3. auth        — unknown / revoked keys, scopes, replay, clock skew
 *   4. validation  — the schema refuses what would corrupt the ledger
 *   5. ingest      — 202, duplicate is 200 not an error, payload verbatim
 *   6. modes       — connection test mode beats a live site setting
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

/** Minimal stand-in for the extension object. */
class FW_POS_Fake_Ext_M3 {
	public $live = true;

	public function is_live() {
		return $this->live;
	}

	public function should_create_orders() {
		return false;
	}
}

FW_POS_Schema::uninstall();
FW_POS_Schema::install();

$ext = new FW_POS_Fake_Ext_M3();
( new FW_POS_REST_Controller( $ext ) )->register();

// The routes register on rest_api_init; force a server so they exist.
do_action( 'rest_api_init', rest_get_server() );

$created = FW_POS_Connections::create(
	[
		'name'   => 'Front counter',
		'mode'   => 'live',
		'scopes' => [ 'sale:write', 'inventory:write' ], // deliberately NOT refund:write
	]
);

$GLOBALS['pos_key']    = $created['api_key'];
$GLOBALS['pos_secret'] = $created['secret'];
$GLOBALS['pos_conn']   = $created['id'];

/**
 * Fire a signed request at the real REST server.
 *
 * @return WP_REST_Response
 */
function post_signed( $route, array $payload, array $overrides = [] ) {
	$body      = wp_json_encode( $payload );
	$timestamp = isset( $overrides['timestamp'] ) ? (string) $overrides['timestamp'] : (string) time();
	$secret    = isset( $overrides['secret'] ) ? $overrides['secret'] : $GLOBALS['pos_secret'];
	$key       = isset( $overrides['key'] ) ? $overrides['key'] : $GLOBALS['pos_key'];

	$signature = isset( $overrides['signature'] )
		? $overrides['signature']
		: FW_POS_Signature::sign( $secret, $timestamp, $body );

	// Send a DIFFERENT body than was signed, to prove tampering is caught.
	if ( isset( $overrides['body'] ) ) {
		$body = $overrides['body'];
	}

	$request = new WP_REST_Request( 'POST', '/unysonplus-pos/v1/' . $route );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'x-upos-key', $key );
	$request->set_header( 'x-upos-timestamp', $timestamp );
	$request->set_header( 'x-upos-signature', $signature );
	$request->set_body( $body );

	return rest_do_request( $request );
}

function sale_payload( $id, $overrides = [] ) {
	return array_merge(
		[
			'external_id' => $id,
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'currency'    => 'GBP',
			'total'       => 3500,
			'line_items'  => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1, 'unit_price' => 3500 ] ],
		],
		$overrides
	);
}

echo "\n=== 1. Secrets at rest ===\n";

$row = FW_POS_Connections::get( $created['id'] );

check( 'a secret was issued', 64 === strlen( $created['secret'] ) );
check( 'the stored value is NOT the plaintext', $row['secret'] !== $created['secret'] );

if ( FW_POS_Secrets::available() ) {
	check( 'stored encrypted, not hashed', FW_POS_Secrets::is_protected( $row['secret'] ) );
	check(
		'and is RECOVERABLE — a hash could not verify an HMAC',
		FW_POS_Connections::secret_for( $row ) === $created['secret']
	);
} else {
	check( 'OpenSSL missing: degraded to plaintext, and says so', ! FW_POS_Secrets::is_protected( $row['secret'] ) );
}

check( 'the key is prefixed and recognisable', 0 === strpos( $created['api_key'], 'upos_live_' ) );

echo "\n=== 2. Signing ===\n";

$sig_a = FW_POS_Signature::sign( 'secret', '1000', '{"a":1}' );
$sig_b = FW_POS_Signature::sign( 'secret', '1000', '{"a":1}' );

check( 'signing is deterministic', $sig_a === $sig_b );
check( 'a different body changes the signature', $sig_a !== FW_POS_Signature::sign( 'secret', '1000', '{"a":2}' ) );
check( 'a different timestamp changes the signature', $sig_a !== FW_POS_Signature::sign( 'secret', '1001', '{"a":1}' ) );
check( 'a different secret changes the signature', $sig_a !== FW_POS_Signature::sign( 'other', '1000', '{"a":1}' ) );
check( 'the signing string is timestamp\\n+body', $sig_a === 'sha256=' . hash_hmac( 'sha256', "1000\n" . '{"a":1}', 'secret' ) );

echo "\n=== 3. Authentication ===\n";

$r = post_signed( 'sale', sale_payload( 'auth-ok-1' ) );
check( 'a correctly signed request is accepted', 202 === $r->get_status(), (string) $r->get_status() );

$r = post_signed( 'sale', sale_payload( 'auth-badkey' ), [ 'key' => 'upos_live_nope' ] );
check( 'unknown key is 401', 401 === $r->get_status(), (string) $r->get_status() );
check( 'and says which problem it is', 'unknown_key' === $r->get_data()['code'] );

$r = post_signed( 'sale', sale_payload( 'auth-badsig' ), [ 'signature' => 'sha256=deadbeef' ] );
check( 'a wrong signature is 401', 401 === $r->get_status() );
check( 'reported as signature_mismatch', 'signature_mismatch' === $r->get_data()['code'] );

$r = post_signed( 'sale', sale_payload( 'auth-tamper' ), [ 'body' => '{"external_id":"tampered"}' ] );
check( 'a tampered body is refused', 401 === $r->get_status() );

$r = post_signed( 'sale', sale_payload( 'auth-old' ), [ 'timestamp' => time() - 3600 ] );
check( 'an expired timestamp is 401', 401 === $r->get_status() );
check( 'reported as outside the window', 'timestamp_outside_window' === $r->get_data()['code'] );
check( 'and the skew is reported so a wrong clock is diagnosable', abs( $r->get_data()['skew_seconds'] ) > 3000 );

// Re-delivery: the same signature twice.
$body      = wp_json_encode( sale_payload( 'auth-replay' ) );
$timestamp = (string) time();
$signature = FW_POS_Signature::sign( $GLOBALS['pos_secret'], $timestamp, $body );

$make = function () use ( $body, $timestamp, $signature ) {
	$request = new WP_REST_Request( 'POST', '/unysonplus-pos/v1/sale' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'x-upos-key', $GLOBALS['pos_key'] );
	$request->set_header( 'x-upos-timestamp', $timestamp );
	$request->set_header( 'x-upos-signature', $signature );
	$request->set_body( $body );

	return rest_do_request( $request );
};

$first  = $make();
$second = $make();

check( 'the first delivery of a signature is accepted', 202 === $first->get_status(), (string) $first->get_status() );

// Byte-identical re-delivery — what a sender that signs ONCE and re-sends does,
// GitHub-style. It must be de-duplicated, not refused: the endpoint is
// idempotent by construction, so a repeat is a no-op, and answering 401 would
// turn a working retry into an auth error.
check( 'identical re-delivery is accepted, not refused', 200 === $second->get_status(), (string) $second->get_status() );
check( 'and de-duplicated', ! empty( $second->get_data()['duplicate'] ) );
check( 'resolving to the same event', (int) $second->get_data()['event_id'] === (int) $first->get_data()['event_id'] );

// Scope: this connection was not granted refund:write.
$r = post_signed(
	'refund',
	[
		'external_id' => 'scope-1',
		'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'line_items'  => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ],
	]
);
check( 'a missing scope is 403, not 401', 403 === $r->get_status(), (string) $r->get_status() );
check( 'and names the scope needed', false !== strpos( $r->get_data()['message'], 'refund:write' ) );

// Revoked key.
FW_POS_Connections::revoke( $created['id'] );
$r = post_signed( 'sale', sale_payload( 'auth-revoked' ) );
check( 'a revoked key is refused', 401 === $r->get_status() );
check( 'distinguishably from an unknown one', 'revoked_key' === $r->get_data()['code'] );
FW_POS_Connections::update( $created['id'], [ 'status' => FW_POS_Connections::STATUS_ACTIVE ] );

echo "\n=== 4. Validation ===\n";

$r = post_signed( 'sale', sale_payload( 'val-1', [ 'total' => 'thirty-five' ] ) );
check( 'a non-integer total is 400', 400 === $r->get_status(), (string) $r->get_status() );
check( 'the error names the field', false !== strpos( $r->get_data()['message'], 'total' ), $r->get_data()['message'] );

$r = post_signed( 'sale', sale_payload( 'val-2', [ 'line_items' => [] ] ) );
check( 'a sale with no lines is 400', 400 === $r->get_status() );

$r = post_signed( 'sale', sale_payload( 'val-3', [ 'line_items' => [ [ 'quantity' => 1 ] ] ] ) );
check( 'a line with neither SKU nor GTIN is 400', 400 === $r->get_status() );
check( 'and says which it wants', false !== strpos( $r->get_data()['message'], 'sku' ), $r->get_data()['message'] );

$r = post_signed( 'sale', sale_payload( 'val-4', [ 'line_items' => [ [ 'sku' => 'X', 'quantity' => -2 ] ] ] ) );
check( 'a negative quantity on a sale is 400', 400 === $r->get_status() );

// The offset requirement — an ambiguous ordering key is what rewinds stock.
$r = post_signed( 'sale', sale_payload( 'val-5', [ 'occurred_at' => '2026-09-03 14:00:00' ] ) );
check( 'a timestamp with NO offset is refused', 400 === $r->get_status(), (string) $r->get_status() );

$r = post_signed( 'sale', sale_payload( 'val-6', [ 'occurred_at' => '2026-09-03T14:00:00+01:00' ] ) );
check( 'a timestamp WITH an offset is accepted', 202 === $r->get_status(), (string) $r->get_status() );

$r = post_signed(
	'inventory',
	[
		'external_id' => 'val-7',
		'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'mode'        => 'sideways',
		'counts'      => [ [ 'sku' => 'X', 'quantity' => 1 ] ],
	]
);
check( 'an unknown inventory mode is refused by the enum', 400 === $r->get_status() );

echo "\n=== 5. Ingest ===\n";

$r    = post_signed( 'sale', sale_payload( 'ingest-1' ) );
$data = $r->get_data();

check( 'a new event is 202 Accepted', 202 === $r->get_status() );
check( 'not applied inline — queued', 'pending' === $data['state'] );
check( 'an event id is returned for support', ! empty( $data['event_id'] ) );

$event = FW_POS_Ledger::get_event( $data['event_id'] );
check( 'the event is attributed to the connection', (int) $event['connection_id'] === (int) $created['id'] );
check( 'the payload is stored verbatim', false !== strpos( (string) $event['payload'], 'HOODIE-BLU-M' ) );

// A genuine retry: same external_id, freshly signed (so not a replay).
sleep( 1 );
$r2    = post_signed( 'sale', sale_payload( 'ingest-1' ) );
$data2 = $r2->get_data();

check( 'a retried delivery is 200, NOT an error', 200 === $r2->get_status(), (string) $r2->get_status() );
check( 'flagged as a duplicate', ! empty( $data2['duplicate'] ) );
check( 'resolving to the same event', (int) $data2['event_id'] === (int) $data['event_id'] );

$rows = FW_POS_Ledger::count_events( [ 'search' => 'ingest-1' ] );
check( 'only one row exists for the transaction', 1 === $rows, (string) $rows );

// The connection's last-seen is maintained.
$row = FW_POS_Connections::get( $created['id'] );
check( 'the connection records being heard from', ! empty( $row['last_seen_at'] ) );

echo "\n=== 6. Ping and schema ===\n";

$request = new WP_REST_Request( 'GET', '/unysonplus-pos/v1/ping' );
$request->set_header( 'x-upos-key', $GLOBALS['pos_key'] );
$r = rest_do_request( $request );

check( 'ping works with the key alone, no signature', 200 === $r->get_status(), (string) $r->get_status() );
check( 'and names the connection', 'Front counter' === $r->get_data()['connection'] );
check( 'and reports server time, so clock skew is self-diagnosable', ! empty( $r->get_data()['server_time'] ) );

$request = new WP_REST_Request( 'GET', '/unysonplus-pos/v1/ping' );
$request->set_header( 'x-upos-key', 'nope' );
check( 'ping rejects an unknown key', 401 === rest_do_request( $request )->get_status() );

$r = rest_do_request( new WP_REST_Request( 'GET', '/unysonplus-pos/v1/schema/sale' ) );
check( 'the sale schema is published', 200 === $r->get_status() );
check( 'and is a real schema document', ! empty( $r->get_data()['properties']['external_id'] ) );

$r = rest_do_request( new WP_REST_Request( 'GET', '/unysonplus-pos/v1/schema/nonsense' ) );
check( 'an unknown schema is 404', 404 === $r->get_status() );

echo "\n=== 7. Modes ===\n";

check( 'a live connection on a live site is live', FW_POS_Connections::is_live( FW_POS_Connections::get( $created['id'] ), $ext ) );

FW_POS_Connections::update( $created['id'], [ 'mode' => 'test' ] );
check(
	'a TEST connection is not live even on a live site',
	! FW_POS_Connections::is_live( FW_POS_Connections::get( $created['id'] ), $ext )
);

FW_POS_Connections::update( $created['id'], [ 'mode' => 'live' ] );
$ext->live = false;
check(
	'the site-wide switch overrides a live connection',
	! FW_POS_Connections::is_live( FW_POS_Connections::get( $created['id'] ), $ext )
);
$ext->live = true;

echo "\n=== 8. Connection management ===\n";

$old_secret = FW_POS_Connections::secret_for( FW_POS_Connections::get( $created['id'] ) );
$new_secret = FW_POS_Connections::rotate_secret( $created['id'] );

check( 'rotating issues a different secret', $new_secret !== $old_secret );
check( 'the key is UNCHANGED by a rotation', FW_POS_Connections::get( $created['id'] )['api_key'] === $created['api_key'] );

$r = post_signed( 'sale', sale_payload( 'rot-1' ), [ 'secret' => $old_secret ] );
check( 'the old secret stops working immediately', 401 === $r->get_status(), (string) $r->get_status() );

$r = post_signed( 'sale', sale_payload( 'rot-2' ), [ 'secret' => $new_secret ] );
check( 'the new secret works', 202 === $r->get_status(), (string) $r->get_status() );

$second = FW_POS_Connections::create( [ 'name' => 'Market stall', 'mode' => 'test' ] );
check( 'a second connection gets a different key', $second['api_key'] !== $created['api_key'] );
check( 'and a different secret', $second['secret'] !== $new_secret );

// Same till id from two connections is two distinct events, not a duplicate.
$GLOBALS['pos_key']    = $second['api_key'];
$GLOBALS['pos_secret'] = $second['secret'];

$r = post_signed( 'sale', sale_payload( 'rot-2' ) );
check( 'the same transaction id on ANOTHER connection is not a duplicate', 202 === $r->get_status(), (string) $r->get_status() );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
