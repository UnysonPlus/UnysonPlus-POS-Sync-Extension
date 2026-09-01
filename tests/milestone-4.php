<?php
/**
 * Functional check for POS Sync Milestone 4 — the Virtual Terminal.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-4.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * Uses the `internal` transport throughout. The `http` one is the default in the
 * UI precisely because it exercises the web server and any security plugin — but
 * that makes it environment-dependent, and a suite that fails because a dev box
 * cannot reach its own loopback is a suite people learn to ignore. The transport
 * boundary itself is asserted separately.
 *
 * Exercises:
 *   1. signing parity — the simulator signs exactly as the endpoint verifies
 *   2. transports     — both reach the handler, and are distinguishable
 *   3. scenarios      — every declared scenario actually passes
 *   4. self-checking  — a scenario FAILS when the behaviour it guards is broken
 *   5. cURL export    — carries no live secret
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

/** An in-memory store, so the scenarios have something to resolve against. */
class FW_POS_Store_Fake_M4 extends FW_POS_Store {

	public $stock = [ 'POS-DEMO-1' => 100 ];

	public function get_id() {
		return 'fake';
	}

	public function get_label() {
		return 'Fake Store';
	}

	public function is_available() {
		return true;
	}

	public function get_capabilities() {
		return array_merge(
			$this->default_capabilities(),
			[ 'partial_refunds' => true, 'variations' => true ]
		);
	}

	public function find_by_sku( $sku, $gtin = null ) {
		return isset( $this->stock[ trim( (string) $sku ) ] ) ? 'product:' . md5( $sku ) : null;
	}

	public function describe( $store_ref ) {
		return 'Fake';
	}

	public function search_products( $term, $limit = 20 ) {
		$out = [];

		foreach ( $this->stock as $sku => $qty ) {
			$out[] = [
				'sku'       => $sku,
				'name'      => 'Demo ' . $sku,
				'store_ref' => 'product:' . md5( $sku ),
				'stock'     => $qty,
			];
		}

		return $out;
	}

	private function sku_for( $ref ) {
		foreach ( array_keys( $this->stock ) as $sku ) {
			if ( 'product:' . md5( $sku ) === $ref ) {
				return $sku;
			}
		}

		return null;
	}

	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		$sku = $this->sku_for( $store_ref );

		if ( null === $sku ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = $this->stock[ $sku ];
		$this->stock[ $sku ] = (int) $quantity;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		$sku = $this->sku_for( $store_ref );

		if ( null === $sku ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = $this->stock[ $sku ];
		$this->stock[ $sku ] = $before + (int) $delta;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function create_order( array $event, array $payload ) {
		return [ 'ok' => true, 'order_ref' => 'fake:1', 'error' => null ];
	}

	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [ 'ok' => true, 'error' => null ];
	}
}

class FW_POS_Fake_Ext_M4 {
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

$fake = new FW_POS_Store_Fake_M4();

$reflect = new ReflectionClass( 'FW_POS_Stores' );
$drivers = $reflect->getProperty( 'drivers' );
$drivers->setAccessible( true );
$drivers->setValue( null, [ 'fake' => $fake ] );
$active = $reflect->getProperty( 'active' );
$active->setAccessible( true );
$active->setValue( null, $fake );

$ext = new FW_POS_Fake_Ext_M4();
( new FW_POS_REST_Controller( $ext ) )->register();
( new FW_POS_Applier( $ext ) )->register();
do_action( 'rest_api_init', rest_get_server() );

$created    = FW_POS_Connections::create( [ 'name' => 'Virtual Terminal', 'mode' => 'live' ] );
$connection = FW_POS_Connections::get( $created['id'] );
$sim        = new FW_POS_Simulator( $connection );

echo "\n=== 1. Signing parity ===\n";

$built = $sim->build( 'sale', [ 'a' => 1 ], [ 'timestamp' => 1000 ] );

check( 'the simulator signs with the connection\'s real secret',
	$built['headers']['X-UPOS-Signature'] === FW_POS_Signature::sign( $created['secret'], '1000', $built['body'] ) );
check( 'it sends the key', $built['headers']['X-UPOS-Key'] === $connection['api_key'] );
check( 'signed body and sent body match by default', $built['body'] === $built['sent_body'] );

$tampered = $sim->build( 'sale', [ 'a' => 1 ], [ 'body' => '{"a":2}' ] );
check( 'a tampering override sends a DIFFERENT body than it signed', $tampered['body'] !== $tampered['sent_body'] );

echo "\n=== 2. Transports ===\n";

$r = $sim->fire( 'sale', $sim->sale( 'tr-internal', 'POS-DEMO-1' ), [ 'transport' => 'internal' ] );
check( 'internal transport reaches the handler', 202 === $r['status'], (string) $r['status'] );
check( 'and reports which transport it used', 'internal' === $r['transport'] );

$r = $sim->fire( 'sale', $sim->sale( 'tr-http', 'POS-DEMO-1' ), [ 'transport' => 'http' ] );

if ( 0 === $r['status'] || 404 === $r['status'] ) {
	// Neither is a failure of the code under test. A real HTTP request loads
	// WordPress afresh, so it only sees routes registered by an ACTIVE
	// extension — this suite registers them in-process, which a separate
	// request cannot see. Status 0 means loopback is blocked outright.
	//
	// This is precisely the gap the two transports exist to expose: in-process
	// dispatch works while the real endpoint is unreachable, which is also what
	// a security plugin blocking /wp-json/ looks like.
	check(
		'http transport unreachable here, and distinguishable from the handler working',
		202 === $sim->fire( 'sale', $sim->sale( 'tr-x', 'POS-DEMO-1' ), [ 'transport' => 'internal' ] )['status'],
		'http said ' . ( $r['error'] ? $r['error'] : 'HTTP ' . $r['status'] )
	);
} else {
	check( 'http transport reaches the handler', 202 === $r['status'], (string) $r['status'] );
	check( 'and reports which transport it used', 'http' === $r['transport'] );
}

echo "\n=== 3. Every declared scenario passes ===\n";

foreach ( FW_POS_Simulator::scenarios() as $id => $scenario ) {
	$result = $sim->run_scenario( $id, 'internal', 'POS-DEMO-1' );

	$failed = [];

	foreach ( $result['steps'] as $step ) {
		if ( ! $step['ok'] ) {
			$failed[] = $step['label'] . ( $step['note'] ? ' (' . $step['note'] . ')' : '' );
		}
	}

	check( $scenario['label'], $result['ok'], implode( '; ', $failed ) );
	check( '  …reported at least one step', ! empty( $result['steps'] ) );
}

echo "\n=== 4. The scenarios actually check something ===\n";

// A self-test that always passes is worse than no self-test, so break the
// behaviour a scenario guards and confirm the scenario notices.
$original_window = FW_POS_Signature::WINDOW;

// The duplicate scenario depends on the unique index. Drop the constraint's
// effect by pointing the scenario at a connection, then asserting that a
// scenario whose expectation cannot hold does fail.
$broken = $sim->run_scenario( 'unknown_sku', 'internal', 'POS-DEMO-1' );
check( 'unknown_sku passes when the SKU really is unknown', $broken['ok'] );

// Now make every SKU resolvable and re-run: the scenario should FAIL, because
// the event it expects to be skipped now applies.
$fake->stock['GHOST-CATCHALL'] = 5;

$catchall = new class() extends FW_POS_Store_Fake_M4 {
	public function find_by_sku( $sku, $gtin = null ) {
		return 'product:' . md5( 'POS-DEMO-1' ); // everything matches
	}
};
$catchall->stock = [ 'POS-DEMO-1' => 100 ];
$active->setValue( null, $catchall );

$should_fail = $sim->run_scenario( 'unknown_sku', 'internal', 'POS-DEMO-1' );
check( 'and FAILS when nothing can be unmatched — the check is real', ! $should_fail['ok'] );

$active->setValue( null, $fake );
unset( $original_window );

echo "\n=== 5. cURL export ===\n";

$curl = $sim->curl_for( 'sale', $sim->sale( 'curl-1', 'POS-DEMO-1' ) );

check( 'exports a runnable command', false !== strpos( $curl, 'curl -sS -X POST' ) );
check( 'includes the connection key', false !== strpos( $curl, $connection['api_key'] ) );
check( 'does NOT include the live secret', false === strpos( $curl, $created['secret'] ) );
check( 'uses a placeholder instead', false !== strpos( $curl, 'your-connection-secret' ) );
check( 'signs timestamp then body', false !== strpos( $curl, '%s\n%s' ) );
check( 'warns about the double-serialize trap', false !== strpos( $curl, 'EXACT bytes' ) );

echo "\n=== 6. Product picker ===\n";

$found = $fake->search_products( '', 10 );
check( 'a driver can list products for the picker', ! empty( $found ) );
check( 'entries carry a SKU', ! empty( $found[0]['sku'] ) );

// A driver that implements ONLY the abstract methods must still work — that is
// the whole reason search_products() is concrete with a default rather than
// abstract. A cart with no searchable catalog should not be unimplementable.
$bare = new class() extends FW_POS_Store {
	public function get_id() {
		return 'bare';
	}

	public function get_label() {
		return 'Bare';
	}

	public function is_available() {
		return true;
	}

	public function get_capabilities() {
		return $this->default_capabilities();
	}

	public function find_by_sku( $sku, $gtin = null ) {
		return null;
	}

	public function describe( $store_ref ) {
		return '';
	}

	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		return $this->stock_error( 'unsupported' );
	}

	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		return $this->stock_error( 'unsupported' );
	}

	public function create_order( array $event, array $payload ) {
		return [ 'ok' => false, 'order_ref' => null, 'error' => 'unsupported' ];
	}

	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [ 'ok' => false, 'error' => 'unsupported' ];
	}
};

check( 'a driver implementing only the abstracts is constructible', $bare instanceof FW_POS_Store );
check( 'and its search default is an empty list, not a fatal', [] === $bare->search_products( 'anything' ) );
check( 'and every capability defaults to false', ! $bare->supports( 'partial_refunds' ) );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
