<?php
/**
 * Functional check for POS Sync Milestone 7 — expansion drivers, the CSV
 * importer, maturity badges and the diagnostic report.
 *
 * Run with:
 *   php wp-cli.phar --path='<a WordPress install>' \
 *     eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-7.php
 *
 * It installs the tables, exercises them, and drops them again — safe to re-run,
 * and it leaves the site as it found it. Do not point it at a live shop.
 *
 * ## What this suite can and cannot prove
 *
 * FluentCart, SureCart and Clover are not installed here and cannot be. So this
 * suite does NOT claim those drivers work — it proves the thing that actually
 * protects a shop: that an unverified driver **refuses to activate** rather than
 * writing wrong numbers, that it says exactly why, and that the failure is
 * visible in the diagnostic report.
 *
 * The CSV importer and the diagnostics are fully exercised, because both are
 * self-contained.
 *
 * Exercises:
 *   1. maturity   — experimental drivers are labelled everywhere they appear
 *   2. safety     — an unavailable driver cannot be selected or write anything
 *   3. importer   — grouping, refunds, idempotency, and every rejection reason
 *   4. clover     — the doorbell shape, and its weaker verification
 *   5. diagnostics— useful content, and NO secrets
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
	'includes/stores/class-fw-pos-store-fluentcart.php',
	'includes/stores/class-fw-pos-store-surecart.php',
	'includes/stores/class-fw-pos-store-edd.php',
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
	'includes/providers/clover/class-fw-pos-provider-clover.php',
	'includes/class-fw-pos-policy.php',
	'includes/class-fw-pos-reconciler.php',
	'includes/class-fw-pos-health.php',
	'includes/class-fw-pos-batch-importer.php',
	'includes/class-fw-pos-diagnostics.php',
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

FW_POS_Schema::uninstall();
FW_POS_Schema::install();

FW_POS_Stores::reset();
FW_POS_Providers::reset();

echo "\n=== 1. Maturity is visible ===\n";

$fluentcart = new FW_POS_Store_FluentCart();
$surecart   = new FW_POS_Store_SureCart();
$edd        = new FW_POS_Store_EDD();
$woo        = new FW_POS_Store_WooCommerce();
$clover     = new FW_POS_Provider_Clover();
$square     = new FW_POS_Provider_Square();

check( 'FluentCart declares itself experimental', 'experimental' === $fluentcart->maturity() );
check( 'SureCart declares itself experimental', 'experimental' === $surecart->maturity() );
check( 'Clover declares itself experimental', 'experimental' === $clover->maturity() );
check( 'WooCommerce is stable', 'stable' === $woo->maturity() );
check( 'Square is stable', 'stable' === $square->maturity() );

$choices = FW_POS_Stores::choices();
check( 'the badge reaches the store dropdown',
	isset( $choices['fluentcart'] ) && false !== strpos( $choices['fluentcart'], 'experimental' ),
	isset( $choices['fluentcart'] ) ? $choices['fluentcart'] : 'missing' );

$provider_choices = FW_POS_Providers::choices();
check( 'and the provider dropdown',
	isset( $provider_choices['clover'] ) && false !== strpos( $provider_choices['clover'], 'experimental' ),
	isset( $provider_choices['clover'] ) ? $provider_choices['clover'] : 'missing' );
check( 'a stable driver carries no badge',
	isset( $provider_choices['square'] ) && false === strpos( $provider_choices['square'], 'experimental' ) );

echo "\n=== 2. An unverified driver cannot do harm ===\n";

// None of these carts are installed here, which is the point.
check( 'FluentCart is not available without FluentCart', ! $fluentcart->is_available() );
check( 'SureCart is not available without SureCart', ! $surecart->is_available() );
check( 'EDD is not available without EDD', ! $edd->is_available() );

check( 'and each says WHY, not just "no"',
	false !== stripos( $fluentcart->unavailable_reason(), 'not installed' )
	&& false !== stripos( $surecart->unavailable_reason(), 'not installed' ) );

// The critical property: an unavailable driver refuses to write.
$write = $fluentcart->set_stock( 'fc_variation:1', 5 );
check( 'an unavailable driver refuses to write stock', empty( $write['ok'] ), (string) $write['error'] );
check( 'and reports itself unavailable rather than pretending', 'driver_unavailable' === $write['error'] );

check( 'lookups return nothing rather than guessing', null === $fluentcart->find_by_sku( 'ANYTHING' ) );
check( 'and SureCart the same', null === $surecart->find_by_sku( 'ANYTHING' ) );

// Only WooCommerce should be offered as available on this install.
$available = array_keys( FW_POS_Stores::available() );
check( 'no unverified driver is offered as available', ! in_array( 'fluentcart', $available, true )
	&& ! in_array( 'surecart', $available, true ), implode( ',', $available ) );

// A cart that IS present but incompatible is what the report is for.
check( 'incompatible() ignores merely-uninstalled carts', [] === FW_POS_Stores::incompatible(),
	wp_json_encode( FW_POS_Stores::incompatible() ) );

echo "\n=== 3. EDD is honest about having no stock ===\n";

check( 'EDD declares no variations', empty( $edd->get_capabilities()['variations'] ) );
check( 'EDD declares no order creation', empty( $edd->get_capabilities()['create_orders'] ) );

echo "\n=== 4. CSV importer ===\n";

$connection = FW_POS_Connections::create( [ 'name' => 'Market stall', 'mode' => 'test' ] );

$csv = FW_POS_Batch_Importer::example();
$dry = FW_POS_Batch_Importer::import( $csv, $connection['id'], true );

check( 'the example file parses', ! empty( $dry['ok'] ), implode( '; ', $dry['errors'] ) );
check( 'it reads every row', 3 === $dry['rows'], (string) $dry['rows'] );
check( 'rows sharing a transaction id become ONE event', 2 === count( $dry['preview'] ), (string) count( $dry['preview'] ) );
check( 'a dry run records nothing', 0 === $dry['recorded'] );
check( 'the multi-line sale keeps both lines', 2 === $dry['preview'][0]['lines'], (string) $dry['preview'][0]['lines'] );
check( 'a refund row is typed as a refund',
	FW_POS_Ledger::TYPE_REFUND === $dry['preview'][1]['type'], $dry['preview'][1]['type'] );

$live = FW_POS_Batch_Importer::import( $csv, $connection['id'], false );
check( 'a real import records', 2 === $live['recorded'], (string) $live['recorded'] );

$again = FW_POS_Batch_Importer::import( $csv, $connection['id'], false );
check( 're-importing the same file is harmless', 0 === $again['recorded'] && 2 === $again['duplicates'],
	$again['recorded'] . '/' . $again['duplicates'] );

// Loose header matching, because a real export will not use our names.
$loose = "Receipt No,Sold At,Sku Code,Qty\nR-2001,2026-09-05 10:00:00,WIDGET,3\n";
$out   = FW_POS_Batch_Importer::import( $loose, $connection['id'], true );
check( 'header names are matched loosely', ! empty( $out['ok'] ) && 1 === $out['rows'], implode( '; ', $out['errors'] ) );

// A negative quantity in a sales export means a return.
$negative = "id,date,sku,quantity\nR-3001,2026-09-05 10:00:00,WIDGET,-2\n";
$out      = FW_POS_Batch_Importer::import( $negative, $connection['id'], true );
check( 'a negative quantity becomes a refund',
	FW_POS_Ledger::TYPE_REFUND === $out['preview'][0]['type'], $out['preview'][0]['type'] );

// Every rejection must name the line and the reason.
$no_sku = "id,date,quantity\nR-1,2026-09-05,1\n";
$out    = FW_POS_Batch_Importer::import( $no_sku, $connection['id'], true );
check( 'a file with no SKU column is refused', empty( $out['ok'] ) );
check( 'and says which column is missing', false !== stripos( implode( ' ', $out['errors'] ), 'sku' ) );

$no_qty = "id,date,sku\nR-1,2026-09-05,WIDGET\n";
$out    = FW_POS_Batch_Importer::import( $no_qty, $connection['id'], true );
check( 'a file with no quantity column is refused', empty( $out['ok'] ) );

$no_date = "id,sku,quantity\nR-1,WIDGET,1\n";
$out     = FW_POS_Batch_Importer::import( $no_date, $connection['id'], true );
check( 'a row with no date is rejected', ! empty( $out['errors'] ) );
check( 'and the reason explains why a date matters',
	false !== stripos( implode( ' ', $out['errors'] ), 'rewind' ) || false !== stripos( implode( ' ', $out['errors'] ), 'order' ),
	implode( '; ', $out['errors'] ) );

$bad_date = "id,date,sku,quantity\nR-1,not-a-date,WIDGET,1\n";
$out      = FW_POS_Batch_Importer::import( $bad_date, $connection['id'], true );
check( 'an unreadable date names the line', false !== stripos( implode( ' ', $out['errors'] ), 'line 2' ),
	implode( '; ', $out['errors'] ) );

// Excel's BOM must not break the first column.
$bom = "\xEF\xBB\xBFid,date,sku,quantity\nR-4001,2026-09-05,WIDGET,1\n";
$out = FW_POS_Batch_Importer::import( $bom, $connection['id'], true );
check( 'a UTF-8 BOM does not break the header', ! empty( $out['ok'] ), implode( '; ', $out['errors'] ) );

$out = FW_POS_Batch_Importer::import( '', $connection['id'], true );
check( 'an empty file is refused cleanly', empty( $out['ok'] ) );

echo "\n=== 5. Clover ===\n";

$cl = FW_POS_Connections::create( [ 'name' => 'Clover till', 'type' => 'clover', 'mode' => 'test' ] );
FW_POS_Provider::store_credentials(
	$cl['id'],
	[
		'environment'       => 'sandbox',
		'access_token'      => 'cl-token',
		'merchant_id'       => 'MID123',
		'verification_code' => 'verify-me',
	]
);
$cl_connection = FW_POS_Connections::get( $cl['id'] );

$v = $clover->verify_webhook( $cl_connection, '{}', [ 'x-clover-auth' => 'verify-me' ], '' );
check( 'the shared verification code is accepted', ! empty( $v['ok'] ), $v['code'] );

$v = $clover->verify_webhook( $cl_connection, '{}', [ 'x-clover-auth' => 'wrong' ], '' );
check( 'a wrong code is refused', empty( $v['ok'] ) && 'clover_auth_mismatch' === $v['code'] );

$v = $clover->verify_webhook( $cl_connection, '{}', [], '' );
check( 'a missing header is refused', empty( $v['ok'] ) && 'missing_clover_auth' === $v['code'] );

check( 'it is connected when it has a token and a merchant', $clover->is_connected( $cl_connection ) );

// The doorbell: a notification for another merchant is not ours.
$events = $clover->normalize(
	$cl_connection,
	[ 'merchants' => [ 'SOMEONE_ELSE' => [ [ 'objectId' => 'O:1', 'type' => 'CREATE' ] ] ] ]
);
check( 'a notification for another merchant is ignored', [] === $events );

// A DELETE has no derivable stock movement.
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'blocked', 'no network in tests' );
} );

$events = $clover->normalize(
	$cl_connection,
	[ 'merchants' => [ 'MID123' => [ [ 'objectId' => 'O:1', 'type' => 'DELETE' ] ] ] ]
);
check( 'a DELETE notification is ignored', [] === $events );

$events = $clover->normalize(
	$cl_connection,
	[ 'merchants' => [ 'MID123' => [ [ 'objectId' => 'P:1', 'type' => 'CREATE' ] ] ] ]
);
check( 'a payment notification is left to its order', [] === $events );

$events = $clover->normalize(
	$cl_connection,
	[ 'merchants' => [ 'MID123' => [ [ 'objectId' => 'O:1', 'type' => 'CREATE' ] ] ] ]
);
check( 'an unreachable API yields nothing rather than a broken event', [] === $events );

remove_all_filters( 'pre_http_request' );

echo "\n=== 6. Diagnostics ===\n";

$report = FW_POS_Diagnostics::report( false );

check( 'a report is produced', '' !== $report );
check( 'it names the extension version', false !== strpos( $report, 'POS Sync:' ) );
check( 'it reports PHP and WordPress', false !== strpos( $report, 'PHP:' ) && false !== strpos( $report, 'WordPress:' ) );
check( 'it reports the schema version', false !== strpos( $report, 'Schema version:' ) );
check( 'it lists store drivers', false !== strpos( $report, 'Store drivers' ) );
check( 'it flags experimental ones', false !== strpos( $report, '[experimental]' ) );
check( 'it gives the reason a driver is unavailable', false !== stripos( $report, 'not installed' ) );
check( 'it lists providers', false !== strpos( $report, 'POS providers' ) );
check( 'it summarises activity', false !== strpos( $report, 'Activity' ) );
check( 'it states its own redaction', false !== stripos( $report, 'No keys, secrets' ) );

// The security property. This is the one that must not regress.
$secret = FW_POS_Connections::secret_for( FW_POS_Connections::get( $connection['id'] ) );
check( 'the report does NOT contain a connection secret', false === strpos( $report, $secret ), 'LEAKED' );
check( 'nor an API key', false === strpos( $report, $connection['api_key'] ), 'LEAKED' );
check( 'nor a Clover token', false === strpos( $report, 'cl-token' ), 'LEAKED' );
check( 'nor a Clover verification code', false === strpos( $report, 'verify-me' ), 'LEAKED' );
check( 'nor the connection names', false === strpos( $report, 'Market stall' ), 'LEAKED' );

check( 'the site URL is omitted by default', false === strpos( $report, home_url() ) );
check( 'and included on request', false !== strpos( FW_POS_Diagnostics::report( true ), home_url() ) );

check( 'it points somewhere a report can be sent',
	false !== strpos( FW_POS_Diagnostics::issues_url(), 'github.com' ) );

FW_POS_Schema::uninstall();

list( $pass, $fail ) = check();

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n\n";

if ( $fail > 0 ) {
	exit( 1 );
}
