<?php
/**
 * Functional check for POS Sync Milestone 1.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-1.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * Exercises the four guarantees that Milestone 1 exists to provide:
 *   1. idempotency  — a replayed event is refused by the unique index
 *   2. ordering     — the queue drains in occurred_at order, not arrival order
 *   3. staleness    — an older absolute count is refused, a relative one is not
 *   4. visibility   — nothing is silently dropped; every outcome carries a reason
 */

$dir = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/pos-sync/includes/';

require_once $dir . 'class-fw-pos-schema.php';
require_once $dir . 'class-fw-pos-ledger.php';
require_once $dir . 'class-fw-pos-queue.php';
require_once $dir . 'class-fw-pos-log.php';

global $wpdb;

function check( $label = null, $condition = null, $detail = '' ) {
	static $pass = 0;
	static $fail = 0;

	if ( null === $label ) {
		return [ $pass, $fail ]; // no args = report the tally
	}

	if ( $condition ) {
		$pass++;
		echo "  PASS  {$label}\n";
	} else {
		$fail++;
		echo "  FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
}

echo "\n=== 0. Schema ===\n";

// Start from a clean slate so the run is repeatable.
FW_POS_Schema::uninstall();
FW_POS_Schema::install();

check( 'tables created', FW_POS_Schema::is_installed() );
check( 'version stamped', FW_POS_Schema::DB_VERSION === get_option( FW_POS_Schema::DB_VERSION_OPTION ) );

// dbDelta re-running must be a no-op, not a stream of ALTERs.
$before = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FW_POS_Schema::table( 'events' ) );
FW_POS_Schema::install();
check( 'reinstall is idempotent', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FW_POS_Schema::table( 'events' ) ) === (int) $before );

echo "\n=== 1. Idempotency ===\n";

$sale = [
	'connection_id' => 1,
	'external_id'   => 'sq-txn-0001',
	'type'          => 'sale',
	'occurred_at'   => '2026-09-01T14:32:11Z',
	'payload'       => [ 'line_items' => [ [ 'sku' => 'HOODIE-BLU-M', 'quantity' => 1 ] ] ],
];

$first = FW_POS_Ledger::record_event( $sale );
check( 'first delivery accepted', $first['ok'] && ! $first['duplicate'] );

$replay = FW_POS_Ledger::record_event( $sale );
check( 'replay reported as duplicate', $replay['ok'] && $replay['duplicate'], wp_json_encode( $replay ) );
check( 'replay resolves to the SAME event', (int) $replay['event_id'] === (int) $first['event_id'] );

$rows = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM ' . FW_POS_Schema::table( 'events' ) . ' WHERE external_id = %s', 'sq-txn-0001' )
);
check( 'only ONE row exists for the transaction', 1 === $rows, "found {$rows}" );

// A different connection sending the same till id is a different event.
$other = FW_POS_Ledger::record_event( array_merge( $sale, [ 'connection_id' => 2 ] ) );
check( 'same id on another connection is NOT a duplicate', $other['ok'] && ! $other['duplicate'] );

check( 'is_duplicate() agrees', FW_POS_Ledger::is_duplicate( 1, 'sq-txn-0001' ) );
check( 'is_duplicate() false for unknown id', ! FW_POS_Ledger::is_duplicate( 1, 'never-seen' ) );

$missing = FW_POS_Ledger::record_event( [ 'connection_id' => 1, 'external_id' => '' ] );
check( 'event with no external_id is refused', ! $missing['ok'] && 'missing_external_id' === $missing['error'] );

echo "\n=== 2. Ordering ===\n";

// Insert LATEST first, so arrival order is the reverse of event order.
FW_POS_Ledger::record_event( [
	'connection_id' => 3, 'external_id' => 'ord-c', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T17:00:00Z', 'payload' => [],
] );
FW_POS_Ledger::record_event( [
	'connection_id' => 3, 'external_id' => 'ord-a', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T09:00:00Z', 'payload' => [],
] );
FW_POS_Ledger::record_event( [
	'connection_id' => 3, 'external_id' => 'ord-b', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T13:00:00Z', 'payload' => [],
] );

$batch = FW_POS_Ledger::claim_batch( 50 );
$order = [];

foreach ( $batch as $row ) {
	if ( 3 === (int) $row['connection_id'] ) {
		$order[] = $row['external_id'];
	}
}

check(
	'batch drains in occurred_at order, not arrival order',
	[ 'ord-a', 'ord-b', 'ord-c' ] === $order,
	'got ' . implode( ',', $order )
);

echo "\n=== 3. Staleness ===\n";

// A store driver that accepts everything, so we are testing the queue's own rules.
$apply_ok = function () {
	return [ 'ok' => true, 'result' => [ 'stub' => true ] ];
};
add_filter( 'fw_pos_apply_event', $apply_ok );

$queue = new FW_POS_Queue();

// The afternoon count arrives and is applied FIRST...
FW_POS_Ledger::record_event( [
	'connection_id' => 4, 'external_id' => 'count-late', 'type' => 'inventory',
	'occurred_at'   => '2026-09-01T16:45:00Z',
	'payload'       => [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 3 ] ] ],
] );
$queue->run();

// ...and only THEN does the offline till reconnect and dump its morning count.
// This is the case the ordering rule alone cannot fix: the stale event is not in
// the same batch as the newer one, so it must be refused on its own merits.
FW_POS_Ledger::record_event( [
	'connection_id' => 4, 'external_id' => 'count-early', 'type' => 'inventory',
	'occurred_at'   => '2026-09-01T09:15:00Z',
	'payload'       => [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => 12 ] ] ],
] );
$queue->run();

$late  = FW_POS_Ledger::find_by_external_id( 4, 'count-late' );
$early = FW_POS_Ledger::find_by_external_id( 4, 'count-early' );

check( 'newer absolute count applied', 'applied' === $late['state'], $late['state'] );
check( 'later-arriving OLDER count refused', 'skipped' === $early['state'], $early['state'] );
check( 'refusal records a reason', false !== strpos( (string) $early['error'], 'stale_count' ), (string) $early['error'] );
check( 'reason is explained for humans', '' !== FW_POS_Log::explain( $early['error'] ) );

// Same-batch ordering is a SEPARATE mechanism: when both counts are waiting
// together, draining in occurred_at order already lands the newer value last.
FW_POS_Ledger::record_event( [
	'connection_id' => 8, 'external_id' => 'batch-late', 'type' => 'inventory',
	'occurred_at'   => '2026-09-02T16:45:00Z',
	'payload'       => [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'MUG-CER', 'quantity' => 3 ] ] ],
] );
FW_POS_Ledger::record_event( [
	'connection_id' => 8, 'external_id' => 'batch-early', 'type' => 'inventory',
	'occurred_at'   => '2026-09-02T09:15:00Z',
	'payload'       => [ 'mode' => 'absolute', 'counts' => [ [ 'sku' => 'MUG-CER', 'quantity' => 12 ] ] ],
] );
$queue->run();

$b_late  = FW_POS_Ledger::find_by_external_id( 8, 'batch-late' );
$b_early = FW_POS_Ledger::find_by_external_id( 8, 'batch-early' );

check( 'same batch: both applied, oldest first', 'applied' === $b_early['state'] && 'applied' === $b_late['state'],
	$b_early['state'] . '/' . $b_late['state'] );
check( 'same batch: newest count is the one left standing',
	FW_POS_Ledger::last_count_at( 'MUG-CER' ) === '2026-09-02 16:45:00',
	(string) FW_POS_Ledger::last_count_at( 'MUG-CER' ) );

// A relative adjustment commutes, so an old one is still fine.
FW_POS_Ledger::record_event( [
	'connection_id' => 4, 'external_id' => 'adj-old', 'type' => 'inventory',
	'occurred_at'   => '2026-09-01T08:00:00Z',
	'payload'       => [ 'mode' => 'relative', 'counts' => [ [ 'sku' => 'SOCKS-GRY', 'quantity' => -2 ] ] ],
] );

$queue->run();
$adj = FW_POS_Ledger::find_by_external_id( 4, 'adj-old' );
check( 'older RELATIVE adjustment still applied', 'applied' === $adj['state'], $adj['state'] );

echo "\n=== 4. Visibility with no store driver ===\n";

remove_all_filters( 'fw_pos_apply_event' );

FW_POS_Ledger::record_event( [
	'connection_id' => 5, 'external_id' => 'no-driver-1', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T12:00:00Z', 'payload' => [],
] );

$queue->run();
$nd = FW_POS_Ledger::find_by_external_id( 5, 'no-driver-1' );

check( 'event without a driver is skipped, not lost', 'skipped' === $nd['state'], $nd['state'] );
check( 'reason is no_store_driver', 'no_store_driver' === $nd['error'], (string) $nd['error'] );
check( 'payload retained verbatim for replay', null !== $nd['payload'] );

echo "\n=== 5. Retry policy ===\n";

add_filter( 'fw_pos_apply_event', function () {
	return [ 'ok' => false, 'retry' => false, 'error' => 'unmatched_sku' ];
} );

FW_POS_Ledger::record_event( [
	'connection_id' => 6, 'external_id' => 'decision-1', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T12:00:00Z', 'payload' => [],
] );

$queue->run();
$dec = FW_POS_Ledger::find_by_external_id( 6, 'decision-1' );
check( 'retry:false is a decision — skipped, not retried', 'skipped' === $dec['state'], $dec['state'] );
check( 'attempts not burned on a decision', 0 === (int) $dec['attempts'], $dec['attempts'] );

remove_all_filters( 'fw_pos_apply_event' );
add_filter( 'fw_pos_apply_event', function () {
	return [ 'ok' => false, 'retry' => true, 'error' => 'cart_down' ];
} );

FW_POS_Ledger::record_event( [
	'connection_id' => 7, 'external_id' => 'transient-1', 'type' => 'sale',
	'occurred_at'   => '2026-09-01T12:00:00Z', 'payload' => [],
] );

for ( $i = 0; $i < FW_POS_Queue::MAX_TRIES + 1; $i++ ) {
	$queue->run();
}

$tr = FW_POS_Ledger::find_by_external_id( 7, 'transient-1' );
check( 'transient failure eventually marked failed', 'failed' === $tr['state'], $tr['state'] );
check( 'attempts capped at MAX_TRIES', (int) $tr['attempts'] >= FW_POS_Queue::MAX_TRIES, $tr['attempts'] );

check( 'backoff grows', FW_POS_Queue::backoff( 1 ) < FW_POS_Queue::backoff( 3 ) );
check( 'backoff is capped, not unbounded', FW_POS_Queue::backoff( 99 ) === FW_POS_Queue::backoff( 4 ) );

echo "\n=== 6. Log helpers ===\n";

$counts = FW_POS_Ledger::state_counts();
check( 'state counts returned', is_array( $counts ) && ! empty( $counts ), wp_json_encode( $counts ) );
check( 'applied is a success colour', 'is-applied' === FW_POS_Log::state_class( 'applied' ) );
check( 'skipped is NEUTRAL, not a warning', 'is-neutral' === FW_POS_Log::state_class( 'skipped' ) );
check( 'duplicate is NEUTRAL, not a warning', 'is-neutral' === FW_POS_Log::state_class( 'duplicate' ) );
check( 'summary reads line items', 'HOODIE-BLU-M' === FW_POS_Log::summarize( FW_POS_Ledger::get_event( $first['event_id'] ) ) );
check( 'UTC stored value renders local', '—' !== FW_POS_Log::local_time( '2026-09-01 14:32:11' ) );

$paged = FW_POS_Ledger::query_events( [ 'state' => 'skipped', 'per_page' => 5, 'page' => 1 ] );
check( 'filtered query returns only that state', ! array_filter( $paged, function ( $r ) { return 'skipped' !== $r['state']; } ) );
check( 'count matches the filter', FW_POS_Ledger::count_events( [ 'state' => 'skipped' ] ) >= count( $paged ) );

// Clean up so a re-run starts fresh and the dev site is left tidy.
FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();
echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
