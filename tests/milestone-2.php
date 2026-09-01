<?php
/**
 * Functional check for POS Sync Milestone 2 — the store driver seam.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-2.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * Runs against a FAKE store driver, not WooCommerce. That is deliberate: the
 * point of the seam is that everything above it is cart-agnostic, so the tests
 * for the matcher and the applier must not need a cart. A fake driver also lets
 * us assert on capability negotiation and failure modes that a real cart makes
 * awkward to produce on demand.
 *
 * Exercises:
 *   1. the seam    — capability defaults, honest declaration, opaque refs
 *   2. matching    — SKU, GTIN, never name; unmatched queued not invented
 *   3. applying    — sales decrement, refunds restock, counts set
 *   4. atomicity   — one unmatched line skips the WHOLE event
 *   5. retry class — transient vs decision
 *   6. recovery    — mapping a SKU re-queues what it previously blocked
 */

$dir = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/pos-sync/';

require_once $dir . 'includes/class-fw-pos-schema.php';
require_once $dir . 'includes/class-fw-pos-ledger.php';
require_once $dir . 'includes/class-fw-pos-queue.php';
require_once $dir . 'includes/class-fw-pos-log.php';
require_once $dir . 'includes/class-fw-pos-matcher.php';
require_once $dir . 'includes/stores/class-fw-pos-store.php';
require_once $dir . 'includes/stores/class-fw-pos-store-woocommerce.php';
require_once $dir . 'includes/stores/class-fw-pos-stores.php';
require_once $dir . 'includes/class-fw-pos-applier.php';
require_once $dir . 'includes/class-fw-pos-secrets.php';
require_once $dir . 'includes/class-fw-pos-connections.php';

global $wpdb;

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

/**
 * A store that exists only in memory. Implements the contract honestly,
 * including refusing what it says it cannot do.
 */
class FW_POS_Store_Fake extends FW_POS_Store {

	public $stock         = [];   // sku => qty
	public $skus          = [];   // sku => ref
	public $gtins         = [];   // gtin => ref
	public $orders        = [];
	public $fail_next     = false;
	public $caps_override = [];

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
			[
				'partial_refunds' => true,
				'variations'      => true,
				'create_orders'   => true,
			],
			$this->caps_override
		);
	}

	public function find_by_sku( $sku, $gtin = null ) {
		$sku = trim( (string) $sku );

		if ( '' !== $sku && isset( $this->skus[ $sku ] ) ) {
			return $this->skus[ $sku ];
		}

		$gtin = trim( (string) $gtin );

		if ( '' !== $gtin && isset( $this->gtins[ $gtin ] ) ) {
			return $this->gtins[ $gtin ];
		}

		return null;
	}

	public function describe( $store_ref ) {
		return 'Fake ' . $store_ref;
	}

	private function sku_for_ref( $ref ) {
		$found = array_search( $ref, $this->skus, true );

		return false === $found ? null : $found;
	}

	public function set_stock( $store_ref, $quantity, $location_ref = null ) {
		if ( $this->fail_next ) {
			$this->fail_next = false;

			return $this->stock_error( 'boom' );
		}

		$sku = $this->sku_for_ref( $store_ref );

		if ( null === $sku ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = isset( $this->stock[ $sku ] ) ? $this->stock[ $sku ] : 0;
		$this->stock[ $sku ] = (int) $quantity;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function adjust_stock( $store_ref, $delta, $location_ref = null ) {
		if ( $this->fail_next ) {
			$this->fail_next = false;

			return $this->stock_error( 'boom' );
		}

		$sku = $this->sku_for_ref( $store_ref );

		if ( null === $sku ) {
			return $this->stock_error( 'product_not_found' );
		}

		$before              = isset( $this->stock[ $sku ] ) ? $this->stock[ $sku ] : 0;
		$this->stock[ $sku ] = $before + (int) $delta;

		return $this->stock_ok( $before, $this->stock[ $sku ] );
	}

	public function create_order( array $event, array $payload ) {
		$this->orders[] = $event['external_id'];

		return [
			'ok'        => true,
			'order_ref' => 'fake_order:' . count( $this->orders ),
			'error'     => null,
		];
	}

	public function refund_order( $order_ref, array $lines, $restock = true ) {
		return [
			'ok'    => true,
			'error' => null,
		];
	}
}

// ---------------------------------------------------------------- harness

$fake = new FW_POS_Store_Fake();
$fake->skus  = [ 'HOODIE-BLU-M' => 'product:1', 'SOCKS-GRY' => 'product:2', 'MUG-CER' => 'variation:3' ];
$fake->gtins = [ '5012345678900' => 'product:2' ];
$fake->stock = [ 'HOODIE-BLU-M' => 10, 'SOCKS-GRY' => 40, 'MUG-CER' => 5 ];

add_filter(
	'fw_pos_store_drivers',
	function () {
		return [ 'fake' => 'FW_POS_Store_Fake' ];
	}
);

// The registry instantiates its own copy, so hand it ours instead.
$reflect = new ReflectionClass( 'FW_POS_Stores' );
$prop    = $reflect->getProperty( 'drivers' );
$prop->setAccessible( true );
$prop->setValue( null, [ 'fake' => $fake ] );
$active = $reflect->getProperty( 'active' );
$active->setAccessible( true );
$active->setValue( null, $fake );

/** Minimal stand-in for the extension object the applier needs. */
class FW_POS_Fake_Ext {
	public $live          = true;
	public $create_orders = false;

	public function is_live() {
		return $this->live;
	}

	public function should_create_orders() {
		return $this->create_orders;
	}
}

$ext = new FW_POS_Fake_Ext();
( new FW_POS_Applier( $ext ) )->register();

$queue = new FW_POS_Queue();

FW_POS_Schema::uninstall();
FW_POS_Schema::install();


// wp-cli's eval-file runs this file inside a function, so top-level variables
// are LOCALS — a `global $queue` inside fire() would find nothing. Publish the
// two things fire() needs explicitly.
$GLOBALS['pos_queue'] = $queue;
$GLOBALS['pos_seq']   = 0;

/** Record an event and drain the queue. */
function fire( $type, $payload, $occurred_at = '2026-09-03T10:00:00Z' ) {
	$queue = $GLOBALS['pos_queue'];

	$GLOBALS['pos_seq']++;
	$id = $type . '-' . $GLOBALS['pos_seq'];

	FW_POS_Ledger::record_event(
		[
			'connection_id' => 1,
			'external_id'   => $id,
			'type'          => $type,
			'occurred_at'   => $occurred_at,
			'payload'       => $payload,
		]
	);

	$queue->run();

	return FW_POS_Ledger::find_by_external_id( 1, $id );
}

echo "\n=== 1. The seam ===\n";

check( 'driver reports its id', 'fake' === $fake->get_id() );
check( 'supports() reads capabilities', $fake->supports( 'partial_refunds' ) );
check( 'undeclared capability is false, not undefined', ! $fake->supports( 'multi_location_stock' ) );
check( 'unknown capability is false', ! $fake->supports( 'teleportation' ) );

$woo = new FW_POS_Store_WooCommerce();
check( 'Woo driver declares its id', 'woocommerce' === $woo->get_id() );
check(
	'Woo declares multi-location stock FALSE (core Woo has one stock figure)',
	empty( $woo->get_capabilities()['multi_location_stock'] )
);
check( 'Woo declares partial refunds TRUE', ! empty( $woo->get_capabilities()['partial_refunds'] ) );
check( 'registry resolved an active driver', FW_POS_Stores::active() === $fake );

echo "\n=== 2. Matching ===\n";

$matcher = new FW_POS_Matcher( $fake );

$out = $matcher->resolve( [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ] );
check( 'matches on SKU', 'product:1' === $out['lines'][0]['store_ref'] );

$out = $matcher->resolve( [ [ 'sku' => 'NOT-A-SKU', 'gtin' => '5012345678900', 'quantity' => 1 ] ] );
check( 'falls back to GTIN', ! empty( $out['lines'] ) && 'product:2' === $out['lines'][0]['store_ref'] );

$out = $matcher->resolve( [ [ 'name' => 'Blue Hoodie', 'sku' => 'GHOST-1', 'quantity' => 1 ] ] );
check( 'does NOT match on name', empty( $out['lines'] ) && ! empty( $out['unmatched'] ) );
check( 'unmatched item is queued for a human', null !== FW_POS_Ledger::get_item_by_sku( 'GHOST-1' ) );
check(
	'unmatched item is not auto-created as matched',
	FW_POS_Ledger::ITEM_UNMATCHED === FW_POS_Ledger::get_item_by_sku( 'GHOST-1' )['status']
);

$out = $matcher->resolve( [ [ 'quantity' => 1 ] ] );
check( 'a line with no identifier at all is unmatched', ! empty( $out['unmatched'] ) );

// An ignored item is dropped, not treated as a failure.
$bag = FW_POS_Ledger::upsert_item( [ 'sku' => 'CARRIER-BAG', 'name' => 'Carrier bag' ] );
FW_POS_Ledger::ignore_item( $bag );
$out = $matcher->resolve( [ [ 'sku' => 'CARRIER-BAG', 'quantity' => 1 ] ] );
check( 'ignored item is dropped silently', empty( $out['lines'] ) && empty( $out['unmatched'] ) );

// A human mapping must survive a fresh lookup that would disagree.
$manual = FW_POS_Ledger::upsert_item( [ 'sku' => 'HOODIE-BLU-M' ] );
FW_POS_Ledger::set_item_match( $manual, 'product:999' );
$out = $matcher->resolve( [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ] );
check( 'a human mapping beats a fresh lookup', 'product:999' === $out['lines'][0]['store_ref'] );
FW_POS_Ledger::set_item_match( $manual, 'product:1' ); // restore

echo "\n=== 3. Applying ===\n";

$fake->stock['HOODIE-BLU-M'] = 10;

$e = fire( 'sale', [ 'line_items' => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 2 ] ] ] );
check( 'sale applied', 'applied' === $e['state'], $e['state'] . ' / ' . (string) $e['error'] );
check( 'sale DECREMENTS stock', 8 === $fake->stock['HOODIE-BLU-M'], (string) $fake->stock['HOODIE-BLU-M'] );

$result = json_decode( (string) $e['result'], true );
check( 'before/after recorded on the event', 10 === $result['moves'][0]['before'] && 8 === $result['moves'][0]['after'] );

$e = fire( 'refund', [ 'line_items' => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ] ] );
check( 'refund applied', 'applied' === $e['state'], (string) $e['error'] );
check( 'refund RESTOCKS', 9 === $fake->stock['HOODIE-BLU-M'], (string) $fake->stock['HOODIE-BLU-M'] );

$e = fire( 'refund', [ 'restock' => false, 'line_items' => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ] ] );
check( 'restock:false does NOT restock', 9 === $fake->stock['HOODIE-BLU-M'], (string) $fake->stock['HOODIE-BLU-M'] );
check( 'and is still a success, not an error', 'applied' === $e['state'], $e['state'] );

$e = fire( 'inventory', [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 25 ] ] ] );
check( 'absolute count SETS stock', 25 === $fake->stock['SOCKS-GRY'], (string) $fake->stock['SOCKS-GRY'] );

$e = fire( 'inventory', [ 'mode' => 'relative', 'counts' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => -5 ] ] ] );
check( 'relative count ADJUSTS stock', 20 === $fake->stock['SOCKS-GRY'], (string) $fake->stock['SOCKS-GRY'] );

echo "\n=== 4. Atomicity ===\n";

$before = $fake->stock['HOODIE-BLU-M'];

$e = fire(
	'sale',
	[
		'line_items' => [
			[ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ],
			[ 'sku' => 'GHOST-2', 'quantity' => 1 ],
		],
	]
);

check( 'one unmatched line skips the WHOLE event', 'skipped' === $e['state'], $e['state'] );
check( 'the matched line was NOT partially applied', $before === $fake->stock['HOODIE-BLU-M'],
	$before . ' -> ' . $fake->stock['HOODIE-BLU-M'] );
check( 'the reason names the offending SKU', false !== strpos( (string) $e['error'], 'GHOST-2' ), (string) $e['error'] );

echo "\n=== 5. Retry classification ===\n";

$fake->fail_next = true;
$e = fire( 'sale', [ 'line_items' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 1 ] ] ] );
check( 'a store write failure is TRANSIENT (stays pending to retry)', 'pending' === $e['state'], $e['state'] );
check( 'and burns an attempt', (int) $e['attempts'] > 0, (string) $e['attempts'] );

$e = fire( 'sale', [ 'line_items' => [] ] );
check( 'an empty sale is a DECISION (skipped, not retried)', 'skipped' === $e['state'], $e['state'] );
check( 'and burns no attempts', 0 === (int) $e['attempts'], (string) $e['attempts'] );

// Capability negotiation: a driver that cannot do partials must refuse.
$fake->caps_override = [ 'partial_refunds' => false ];
$e = fire(
	'refund',
	[
		'sale_external_id' => 'sale-1',
		'partial'          => true,
		'line_items'       => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 1 ] ],
	]
);
check( 'partial refund refused when unsupported', 'skipped' === $e['state'], $e['state'] );
check( 'with a legible reason', 'partial_refunds_unsupported' === $e['error'], (string) $e['error'] );
$fake->caps_override = [];

echo "\n=== 6. Test mode + order creation ===\n";

$ext->live = false;
$before    = $fake->stock['SOCKS-GRY'];

$e = fire( 'sale', [ 'line_items' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 3 ] ] ] );
check( 'test mode still APPLIES the event (it is not an error)', 'applied' === $e['state'], $e['state'] );
check( 'test mode does NOT move stock', $before === $fake->stock['SOCKS-GRY'] );

$result = json_decode( (string) $e['result'], true );
check( 'test mode records what WOULD have happened', ! empty( $result['planned'] ) && ! empty( $result['test_mode'] ) );

$ext->live = true;

$orders_before = count( $fake->orders );
fire( 'sale', [ 'line_items' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 1 ] ] ] );
check( 'order creation is OFF by default', count( $fake->orders ) === $orders_before );

$ext->create_orders = true;
fire( 'sale', [ 'line_items' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 1 ] ] ] );
check( 'order created when opted in', count( $fake->orders ) === $orders_before + 1 );
$ext->create_orders = false;

echo "\n=== 7. Recovery ===\n";

// GHOST-2 blocked a sale in section 4. Map it and the event becomes replayable.
$ghost = FW_POS_Ledger::get_item_by_sku( 'GHOST-2' );
check( 'the blocked SKU is waiting in the queue', null !== $ghost );

$fake->skus['GHOST-2']  = 'product:4';
$fake->stock['GHOST-2'] = 7;
FW_POS_Ledger::set_item_match( $ghost['id'], 'product:4' );

$requeued = FW_POS_Ledger::requeue_skipped( 'unmatched_sku' );
check( 'mapping re-queues what it previously blocked', $requeued >= 1, (string) $requeued );

$hoodie_before = $fake->stock['HOODIE-BLU-M'];
$queue->run();

check( 'the re-queued sale now applies', 6 === $fake->stock['GHOST-2'], (string) $fake->stock['GHOST-2'] );
check( 'and its other line applies too', $hoodie_before - 1 === $fake->stock['HOODIE-BLU-M'],
	$hoodie_before . ' -> ' . $fake->stock['HOODIE-BLU-M'] );

echo "\n=== 8. No driver ===\n";

$active->setValue( null, false );

$e = fire( 'sale', [ 'line_items' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 1 ] ] ] );
check( 'with no driver, events skip with no_store_driver', 'no_store_driver' === $e['error'], (string) $e['error'] );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
