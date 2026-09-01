<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Virtual Terminal tab.
 *
 * Included from views/log.php, so `$status` is resolved and `$this` is the
 * admin page.
 *
 * @var array $status
 */

$connections = FW_POS_Connections::all( [ 'status' => FW_POS_Connections::STATUS_ACTIVE ] );
$results     = FW_POS_Terminal_Page::take_results();
$store       = FW_POS_Stores::active();
$products    = $store ? $store->search_products( '', 50 ) : [];

// phpcs:disable WordPress.Security.NonceVerification -- display state only.
$selected_conn = isset( $_GET['vt_connection'] ) ? (int) $_GET['vt_connection'] : ( $connections ? (int) $connections[0]['id'] : 0 );
$transport     = isset( $_GET['vt_transport'] ) && 'internal' === $_GET['vt_transport'] ? 'internal' : 'http';
// phpcs:enable WordPress.Security.NonceVerification
?>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'Fires correctly-signed events at this site\'s own endpoint, exactly as a till would — so you can build, verify and demonstrate the whole thing without a card terminal. It is also the pre-launch check before a shop opens, and the fastest way to reproduce a customer\'s problem.',
		'fw'
	);
	?>
</p>

<?php if ( empty( $connections ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'There are no active connections to sign as. Create one on the Connections tab first — the terminal signs exactly like a real till, which means it needs a real credential.', 'fw' ); ?>
		</p>
	</div>
	<?php return; ?>
<?php endif; ?>

<form method="get" class="fw-pos-vt-controls">
	<input type="hidden" name="page" value="<?php echo esc_attr( FW_POS_Admin_Page::PAGE_SLUG ); ?>">
	<input type="hidden" name="tab" value="terminal">

	<label for="vt_connection"><?php esc_html_e( 'Sign as', 'fw' ); ?></label>
	<select name="vt_connection" id="vt_connection">
		<?php foreach ( $connections as $connection ) : ?>
			<option value="<?php echo esc_attr( $connection['id'] ); ?>" <?php selected( $selected_conn, (int) $connection['id'] ); ?>>
				<?php
				printf(
					'%s (%s)',
					esc_html( $connection['name'] ),
					esc_html( FW_POS_Connections::MODE_LIVE === $connection['mode'] ? __( 'live', 'fw' ) : __( 'test', 'fw' ) )
				);
				?>
			</option>
		<?php endforeach; ?>
	</select>

	<label for="vt_transport"><?php esc_html_e( 'Transport', 'fw' ); ?></label>
	<select name="vt_transport" id="vt_transport">
		<option value="http" <?php selected( $transport, 'http' ); ?>><?php esc_html_e( 'Real HTTP request', 'fw' ); ?></option>
		<option value="internal" <?php selected( $transport, 'internal' ); ?>><?php esc_html_e( 'In-process (skip the network)', 'fw' ); ?></option>
	</select>

	<button type="submit" class="button"><?php esc_html_e( 'Apply', 'fw' ); ?></button>
</form>

<p class="description fw-pos-transport-note">
	<?php if ( 'internal' === $transport ) : ?>
		<?php
		esc_html_e(
			'In-process dispatch proves the handler is correct, but it will pass even when a security plugin blocks /wp-json/, the web server strips headers, or loopback requests are firewalled — which are the usual reasons a real till\'s events never arrive. Use it to isolate a problem, not to sign off.',
			'fw'
		);
		?>
	<?php else : ?>
		<?php
		esc_html_e(
			'A real HTTP request to this site\'s own endpoint, so the whole path is exercised: web server, security plugins, and the handler. This is the one that tells you a till will actually get through.',
			'fw'
		);
		?>
	<?php endif; ?>
</p>

<?php if ( $results ) : ?>
	<div class="fw-pos-vt-results">
		<h2>
			<?php echo esc_html( $results['label'] ); ?>
			<span class="fw-pos-badge <?php echo $results['ok'] ? 'is-applied' : 'is-failed'; ?>">
				<?php echo $results['ok'] ? esc_html__( 'Passed', 'fw' ) : esc_html__( 'Failed', 'fw' ); ?>
			</span>
		</h2>

		<?php if ( ! empty( $results['why'] ) ) : ?>
			<p class="description"><?php echo esc_html( $results['why'] ); ?></p>
		<?php endif; ?>

		<ol class="fw-pos-vt-steps">
			<?php foreach ( $results['steps'] as $step ) : ?>
				<li class="<?php echo $step['ok'] ? 'is-ok' : 'is-bad'; ?>">
					<span class="fw-pos-vt-mark" aria-hidden="true"><?php echo $step['ok'] ? '✓' : '✕'; ?></span>
					<span><?php echo esc_html( $step['label'] ); ?></span>
					<?php if ( ! empty( $step['note'] ) ) : ?>
						<code><?php echo esc_html( $step['note'] ); ?></code>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<p class="description">
			<?php esc_html_e( 'Every event this fired is on the Log tab, with its payload stored verbatim.', 'fw' ); ?>
		</p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Scenarios', 'fw' ); ?></h2>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'The happy path is the easy part. These are the cases that break naive integrations — each one declares what should happen and then checks it, so this is a self-test rather than a fire-and-squint tool. Run them all before a shop goes live.',
		'fw'
	);
	?>
</p>

<table class="wp-list-table widefat striped fw-pos-scenarios">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Scenario', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'What should happen', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Why it matters', 'fw' ); ?></th>
			<th scope="col"></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( FW_POS_Simulator::scenarios() as $id => $scenario ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $scenario['label'] ); ?></strong></td>
				<td><?php echo esc_html( $scenario['expect'] ); ?></td>
				<td class="description"><?php echo esc_html( $scenario['why'] ); ?></td>
				<td>
					<form method="post">
						<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_pos_vt_action" value="scenario">
						<input type="hidden" name="scenario" value="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="vt_connection" value="<?php echo esc_attr( $selected_conn ); ?>">
						<input type="hidden" name="vt_transport" value="<?php echo esc_attr( $transport ); ?>">
						<input type="hidden" name="vt_sku" value="<?php echo esc_attr( $products ? $products[0]['sku'] : 'POS-DEMO-1' ); ?>">
						<button type="submit" class="button button-small"><?php esc_html_e( 'Run', 'fw' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Fire a single event', 'fw' ); ?></h2>

<form method="post" class="fw-pos-vt-manual">
	<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
	<input type="hidden" name="fw_pos_vt_action" value="fire">
	<input type="hidden" name="vt_connection" value="<?php echo esc_attr( $selected_conn ); ?>">
	<input type="hidden" name="vt_transport" value="<?php echo esc_attr( $transport ); ?>">

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Event', 'fw' ); ?></th>
			<td>
				<select name="vt_type">
					<option value="sale"><?php esc_html_e( 'Sale — reduces stock', 'fw' ); ?></option>
					<option value="refund"><?php esc_html_e( 'Refund — puts stock back', 'fw' ); ?></option>
					<option value="inventory"><?php esc_html_e( 'Stocktake — sets an absolute level', 'fw' ); ?></option>
					<option value="adjustment"><?php esc_html_e( 'Adjustment — a relative change', 'fw' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="vt_sku"><?php esc_html_e( 'SKU', 'fw' ); ?></label></th>
			<td>
				<?php if ( $products ) : ?>
					<select name="vt_sku" id="vt_sku">
						<?php foreach ( $products as $product ) : ?>
							<option value="<?php echo esc_attr( $product['sku'] ); ?>">
								<?php
								printf(
									'%s — %s%s',
									esc_html( $product['sku'] ),
									esc_html( $product['name'] ),
									null === $product['stock']
										? ''
										: esc_html( sprintf( ' (%d in stock)', $product['stock'] ) )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Only products with a SKU are listed — a product without one cannot be matched to a till line, so offering it would only waste your time.', 'fw' ); ?>
					</p>
				<?php else : ?>
					<input name="vt_sku" id="vt_sku" type="text" class="regular-text" value="POS-DEMO-1">
					<p class="description">
						<?php esc_html_e( 'No store is connected, or it cannot list products. Type any SKU — an unmatched one is a perfectly good test of the Unmatched screen.', 'fw' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="vt_qty"><?php esc_html_e( 'Quantity', 'fw' ); ?></label></th>
			<td><input name="vt_qty" id="vt_qty" type="number" min="1" value="1" class="small-text"></td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Fire event', 'fw' ); ?></button>
	</p>
</form>

<h2><?php esc_html_e( 'Copy it as cURL', 'fw' ); ?></h2>

<p class="description">
	<?php
	esc_html_e(
		'The same request, as a command an integrator can run from their own environment. Hand this to whoever is configuring the till — it is faster than describing the signing in prose, and it cannot drift from what the endpoint actually accepts.',
		'fw'
	);
	?>
</p>

<?php
$connection = FW_POS_Connections::get( $selected_conn );

if ( $connection ) :
	$simulator = new FW_POS_Simulator( $connection );
	$example   = $simulator->curl_for(
		'sale',
		$simulator->sale( 'demo-' . gmdate( 'Ymd-His' ), $products ? $products[0]['sku'] : 'POS-DEMO-1' )
	);
	?>
	<pre class="fw-pos-example"><?php echo esc_html( $example ); ?></pre>
<?php endif; ?>
