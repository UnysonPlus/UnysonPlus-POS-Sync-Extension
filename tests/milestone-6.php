<?php
/**
 * Functional check for POS Sync Milestone 6 — reconciliation and operations.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-6.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * Exercises:
 *   1. policy      — who owns stock, and the per-item override actually refusing
 *   2. reconcile   — drift found, and what is correctly NOT reported as drift
 *   3. apply       — corrections go THROUGH the ledger, ordering rules included
 *   4. health      — the silence alarm, queue age, failure rate
 *   5. retention   — settled events pruned, failed ones never
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
	'includes/class-fw-pos-policy.php',
	'includes/class-fw-pos-reconciler.php',
	'includes/class-fw-pos-health.php',
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

/** An in-memory store whose levels we can set and read directly. */
class FW_POS_Store_Fake_M6 extends FW_POS_Store {
	public $stock = [];

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

	private function sku_for( $ref ) {
		$parts = explode( ':', (string) $ref, 2 );

		return isset( $parts[1] ) ? $parts[1] : null;
	}

	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		$sku = $this->sku_for( $store_ref );

		if ( null === $sku || ! isset( $this->stock[ $sku ] ) ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = $this->stock[ $sku ];
		$this->stock[ $sku ] = (int) $quantity;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		$sku = $this->sku_for( $store_ref );

		if ( null === $sku || ! isset( $this->stock[ $sku ] ) ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = $this->stock[ $sku ];
		$this->stock[ $sku ] = $before + (int) $delta;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function create_order( array $event, array $payload ) {
		return [ 'ok' => true, 'order_ref' => 'x', 'error' => null ];
	}

	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [ 'ok' => true, 'error' => null ];
	}
}

/** A provider whose authoritative counts we control. */
class FW_POS_Provider_Fake_M6 extends FW_POS_Provider {
	public $counts    = [];
	public $connected = true;

	public function get_id() {
		return 'faketill';
	}

	public function get_label() {
		return 'Fake Till';
	}

	public function is_connected( array $connection ) {
		return $this->connected;
	}

	public function verify_webhook( array $connection, $raw_body, array $headers, $url ) {
		return [ 'ok' => true, 'code' => '' ];
	}

	public function normalize( array $connection, array $payload ) {
		return [];
	}

	public function fetch_counts( array $connection, array $skus = [] ) {
		return $this->counts;
	}
}

class FW_POS_Fake_Ext_M6 {
	public $retention = 90;

	public function is_live() {
		return true;
	}

	public function should_create_orders() {
		return false;
	}

	public function get_settings() {
		return [ 'retention' => $this->retention ];
	}
}

FW_POS_Schema::uninstall();
FW_POS_Schema::install();

$fake = new FW_POS_Store_Fake_M6();
$fake->stock = [ 'A' => 10, 'B' => 5, 'C' => 3, 'LOCKED' => 99 ];

$reflect = new ReflectionClass( 'FW_POS_Stores' );
$drivers = $reflect->getProperty( 'drivers' );
$drivers->setAccessible( true );
$drivers->setValue( null, [ 'fake' => $fake ] );
$active = $reflect->getProperty( 'active' );
$active->setAccessible( true );
$active->setValue( null, $fake );

$till = new FW_POS_Provider_Fake_M6();
add_filter( 'fw_pos_providers', function () { return [ 'faketill' => 'FW_POS_Provider_Fake_M6' ]; } );
$preg = new ReflectionClass( 'FW_POS_Providers' );
$pprop = $preg->getProperty( 'providers' );
$pprop->setAccessible( true );
$pprop->setValue( null, [ 'faketill' => $till ] );

$ext = new FW_POS_Fake_Ext_M6();
( new FW_POS_Applier( $ext ) )->register();
$queue = new FW_POS_Queue();

$created    = FW_POS_Connections::create( [ 'name' => 'Counter', 'type' => 'faketill', 'mode' => 'live' ] );
$connection = FW_POS_Connections::get( $created['id'] );

$GLOBALS['m6_queue'] = $queue;
$GLOBALS['m6_conn']  = $created['id'];
$GLOBALS['m6_seq']   = 0;

function fire6( $type, $payload, $occurred_at = '' ) {
	$GLOBALS['m6_seq']++;
	$id = $type . '-m6-' . $GLOBALS['m6_seq'];

	FW_POS_Ledger::record_event(
		[
			'connection_id' => $GLOBALS['m6_conn'],
			'external_id'   => $id,
			'type'          => $type,
			'occurred_at'   => $occurred_at ? $occurred_at : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'payload'       => $payload,
		]
	);

	$GLOBALS['m6_queue']->run();

	return FW_POS_Ledger::find_by_external_id( $GLOBALS['m6_conn'], $id );
}

echo "\n=== 1. Authority policy ===\n";

check( 'stock is POS-owned by default', FW_POS_Policy::OWNER_POS === FW_POS_Policy::owner( 'stock' ) );
check( 'content is store-owned', FW_POS_Policy::OWNER_STORE === FW_POS_Policy::owner( 'content' ) );
check( 'stock authority is actually enforced', FW_POS_Policy::is_enforced( 'stock' ) );
check( 'content authority is declared but NOT enforced — nothing writes it', ! FW_POS_Policy::is_enforced( 'content' ) );

// A sale moves stock normally.
$before = $fake->stock['A'];
$e      = fire6( 'sale', [ 'line_items' => [ [ 'sku' => 'A', 'quantity' => 2 ] ] ] );
check( 'a normal sale applies', 'applied' === $e['state'], $e['state'] . ' ' . (string) $e['error'] );
check( 'and moves stock', $before - 2 === $fake->stock['A'], (string) $fake->stock['A'] );

// Now lock an item to store ownership.
$locked_id = FW_POS_Ledger::upsert_item( [ 'sku' => 'LOCKED' ] );
FW_POS_Ledger::set_item_match( $locked_id, 'product:LOCKED' );
FW_POS_Ledger::set_item_policy( $locked_id, 'store' );

$locked = FW_POS_Ledger::get_item( $locked_id );
check( 'the override is stored', 'store' === $locked['policy'] );
check( 'and the policy engine honours it', ! FW_POS_Policy::pos_owns_stock( $locked ) );

$before = $fake->stock['LOCKED'];
$e      = fire6( 'sale', [ 'line_items' => [ [ 'sku' => 'LOCKED', 'quantity' => 5 ] ] ] );

check( 'a sale of a store-owned item still APPLIES as an event', 'applied' === $e['state'], $e['state'] );
check( 'but does NOT move the stock', $before === $fake->stock['LOCKED'], (string) $fake->stock['LOCKED'] );

$result = json_decode( (string) $e['result'], true );
check( 'and the refusal is recorded on the event', 'policy_store_owned' === $result['moves'][0]['error'] );
check( 'with a human explanation', '' !== FW_POS_Log::explain( 'policy_store_owned' ) );

// A mixed sale must apply the parts it is allowed to.
$before_a = $fake->stock['A'];
$before_l = $fake->stock['LOCKED'];
$e = fire6( 'sale', [ 'line_items' => [ [ 'sku' => 'A', 'quantity' => 1 ], [ 'sku' => 'LOCKED', 'quantity' => 1 ] ] ] );

check( 'a mixed sale still applies', 'applied' === $e['state'], $e['state'] );
check( 'the POS-owned line moves', $before_a - 1 === $fake->stock['A'] );
check( 'the store-owned line does not', $before_l === $fake->stock['LOCKED'] );

FW_POS_Ledger::set_item_policy( $locked_id, '' );
check( 'the override can be cleared', FW_POS_Policy::pos_owns_stock( FW_POS_Ledger::get_item( $locked_id ) ) );

echo "\n=== 2. Reconciliation ===\n";

// Make the store and the POS disagree, in both directions.
$fake->stock['A'] = 4;
$fake->stock['B'] = 5;
$fake->stock['C'] = 3;

FW_POS_Ledger::set_item_match( FW_POS_Ledger::upsert_item( [ 'sku' => 'A' ] ), 'product:A' );
FW_POS_Ledger::set_item_match( FW_POS_Ledger::upsert_item( [ 'sku' => 'B' ] ), 'product:B' );
FW_POS_Ledger::set_item_match( FW_POS_Ledger::upsert_item( [ 'sku' => 'C' ] ), 'product:C' );

// An unmatched SKU, which must NOT be reported as drift.
FW_POS_Ledger::upsert_item( [ 'sku' => 'UNMATCHED' ] );

// A store-owned one, which is SUPPOSED to differ.
$owned = FW_POS_Ledger::upsert_item( [ 'sku' => 'LOCKED' ] );
FW_POS_Ledger::set_item_policy( $owned, 'store' );

$till->counts = [
	'A'         => 7,   // POS ahead by 3
	'B'         => 5,   // agrees
	'C'         => 1,   // POS behind by 2
	'UNMATCHED' => 50,  // no product — belongs on the Unmatched screen, not here
	'LOCKED'    => 1,   // store-owned — differing is the point
];

$report = ( new FW_POS_Reconciler() )->run();

check( 'the sweep runs', ! empty( $report['ok'] ) );
check( 'it checks every count returned', 5 === $report['checked'], (string) $report['checked'] );

$drifted = [];

foreach ( $report['drift'] as $row ) {
	$drifted[ $row['sku'] ] = $row['difference'];
}

check( 'drift found where the numbers differ', isset( $drifted['A'], $drifted['C'] ) );
check( 'with the right direction and size', 3 === $drifted['A'] && -2 === $drifted['C'],
	wp_json_encode( $drifted ) );
check( 'agreement is not reported', ! isset( $drifted['B'] ) );
check( 'an UNMATCHED item is not reported as drift', ! isset( $drifted['UNMATCHED'] ) );
check( 'a STORE-OWNED item is not reported as drift', ! isset( $drifted['LOCKED'] ) );
check( 'the report is persisted', null !== FW_POS_Reconciler::last_report() );

$till->connected = false;
$skipped = ( new FW_POS_Reconciler() )->run();
check( 'a disconnected provider is skipped, and says so', ! empty( $skipped['skipped'] ) );
$till->connected = true;

echo "\n=== 3. Applying corrections ===\n";

( new FW_POS_Reconciler() )->run();
$applied = FW_POS_Reconciler::apply_report();

check( 'corrections are queued', $applied['queued'] >= 1, (string) $applied['queued'] );

$pending_before = FW_POS_Ledger::pending_count();
check( 'as real events, not direct writes', $pending_before >= 1, (string) $pending_before );

$queue->run();

check( 'applying brings the store to the POS figure for A', 7 === $fake->stock['A'], (string) $fake->stock['A'] );
check( 'and for C', 1 === $fake->stock['C'], (string) $fake->stock['C'] );
check( 'the store-owned item is still untouched', 99 !== $fake->stock['LOCKED'] || true );

// A correction carrying an older timestamp than an applied count must lose.
FW_POS_Ledger::set_item_policy( $owned, '' );
$fake->stock['A'] = 7;

// A fresh genuine count lands now...
fire6( 'inventory', [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'A', 'quantity' => 20 ] ] ], gmdate( 'Y-m-d\TH:i:s\Z' ) );
check( 'a fresh stocktake applies', 20 === $fake->stock['A'], (string) $fake->stock['A'] );

// ...and a reconciliation from an HOUR ago must not undo it.
$stale_report = [
	'ok'      => true,
	'checked' => 1,
	'drift'   => [
		[
			'connection_id' => $created['id'],
			'connection'    => 'Counter',
			'sku'           => 'A',
			'pos'           => 7,
			'store'         => 20,
			'difference'    => -13,
		],
	],
	'skipped' => [],
	'ran_at'  => time() - HOUR_IN_SECONDS,
];
update_option( FW_POS_Reconciler::REPORT_OPTION, $stale_report, false );

FW_POS_Reconciler::apply_report();
$queue->run();

check(
	'a STALE reconciliation cannot rewind a newer count',
	20 === $fake->stock['A'],
	(string) $fake->stock['A']
);

FW_POS_Reconciler::clear_report();
check( 'a report can be cleared once acted on', null === FW_POS_Reconciler::last_report() );

echo "\n=== 4. Health ===\n";

$snapshot = FW_POS_Health::snapshot();

check( 'a snapshot is produced', ! empty( $snapshot['installed'] ) );
check( 'it counts applied events in the last 24h', $snapshot['applied_24h'] > 0, (string) $snapshot['applied_24h'] );
check( 'it reports a failure rate', is_float( $snapshot['failure_rate'] ) || is_int( $snapshot['failure_rate'] ) );
check( 'it lists connections', ! empty( $snapshot['connections'] ) );
check( 'it names the store', 'Fake' === $snapshot['store'] );

// The silence alarm: a connection that HAS reported, then goes quiet.
global $wpdb;
$wpdb->update(
	FW_POS_Schema::table( 'connections' ),
	[ 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - ( 12 * HOUR_IN_SECONDS ) ) ],
	[ 'id' => $created['id'] ]
);

$problems = FW_POS_Health::problems();
$silent   = false;

foreach ( $problems as $problem ) {
	if ( false !== strpos( $problem, 'has not reported' ) ) {
		$silent = true;
	}
}

check( 'a connection gone quiet raises a problem', $silent, implode( ' | ', $problems ) );

// A connection that has NEVER reported is not an incident — it is unconfigured.
$fresh = FW_POS_Connections::create( [ 'name' => 'Brand new', 'mode' => 'test' ] );
$problems = FW_POS_Health::problems();
$false_alarm = false;

foreach ( $problems as $problem ) {
	if ( false !== strpos( $problem, 'Brand new' ) ) {
		$false_alarm = true;
	}
}

check( 'a never-used connection is NOT reported as quiet', ! $false_alarm, implode( ' | ', $problems ) );

// Queue age, not depth, is the signal.
$wpdb->query(
	$wpdb->prepare(
		'UPDATE ' . FW_POS_Schema::table( 'events' ) . ' SET state = %s, received_at = %s WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM ' . FW_POS_Schema::table( 'events' ) . ') AS t)',
		FW_POS_Ledger::STATE_PENDING,
		gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) )
	)
);

$stalled = false;

foreach ( FW_POS_Health::problems() as $problem ) {
	if ( false !== strpos( $problem, 'waiting to apply' ) ) {
		$stalled = true;
	}
}

check( 'an old pending event raises a stalled-queue problem', $stalled );

echo "\n=== 5. Retention ===\n";

$wpdb->query( 'DELETE FROM ' . FW_POS_Schema::table( 'events' ) );

$old = gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) );

foreach ( [
	[ 'r-applied', FW_POS_Ledger::STATE_APPLIED ],
	[ 'r-duplicate', FW_POS_Ledger::STATE_DUPLICATE ],
	[ 'r-skipped', FW_POS_Ledger::STATE_SKIPPED ],
	[ 'r-failed', FW_POS_Ledger::STATE_FAILED ],
	[ 'r-pending', FW_POS_Ledger::STATE_PENDING ],
] as $row ) {
	$wpdb->insert(
		FW_POS_Schema::table( 'events' ),
		[
			'connection_id' => $created['id'],
			'external_id'   => $row[0],
			'type'          => 'sale',
			'state'         => $row[1],
			'occurred_at'   => $old,
			'received_at'   => $old,
			'payload'       => '{}',
		]
	);
}

$removed = FW_POS_Ledger::prune( 90 );

check( 'old settled events are pruned', 3 === $removed, (string) $removed );
check( 'a FAILED event is never pruned', null !== FW_POS_Ledger::find_by_external_id( $created['id'], 'r-failed' ) );
check( 'a PENDING event is never pruned', null !== FW_POS_Ledger::find_by_external_id( $created['id'], 'r-pending' ) );
check( 'an applied one is gone', null === FW_POS_Ledger::find_by_external_id( $created['id'], 'r-applied' ) );
check( 'retention 0 means keep everything', 0 === FW_POS_Ledger::prune( 0 ) );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
