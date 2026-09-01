<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CSV Import tab.
 *
 * @var array $status
 */

$connections = FW_POS_Connections::all( [ 'status' => FW_POS_Connections::STATUS_ACTIVE ] );
$result      = FW_POS_Import_Page::take_result();
?>

<div class="notice notice-warning inline">
	<p>
		<strong><?php esc_html_e( 'Experimental', 'fw' ); ?></strong> —
		<?php
		esc_html_e(
			'the CSV importer has not yet been proven against a real till export. It goes through the same ledger as everything else, so a bad import is visible in the log and re-importing is harmless — but check the preview before committing, and please send a diagnostic report if it mishandles your file.',
			'fw'
		);
		?>
		<a href="<?php echo esc_url( add_query_arg( [ 'page' => FW_POS_Admin_Page::PAGE_SLUG, 'tab' => 'health' ], admin_url( 'admin.php' ) ) ); ?>#diagnostics">
			<?php esc_html_e( 'Diagnostic report', 'fw' ); ?>
		</a>
	</p>
</div>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'For a till that cannot call a webhook but can export a file. Each row becomes an ordinary ledger event, so you get idempotency, event-time ordering, matching and the audit log exactly like a live integration — just at the granularity of a daily export.',
		'fw'
	);
	?>
</p>

<?php if ( empty( $connections ) ) : ?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'Create a connection first — imported events are attributed to one, so you can tell which till a row came from.', 'fw' ); ?></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<?php if ( $result ) : ?>
	<div class="notice notice-<?php echo empty( $result['errors'] ) ? 'success' : 'warning'; ?>">
		<p>
			<?php if ( ! empty( $result['dry_run'] ) ) : ?>
				<strong><?php esc_html_e( 'Preview only — nothing was imported.', 'fw' ); ?></strong>
			<?php endif; ?>
			<?php
			printf(
				/* translators: 1: rows read, 2: transactions, 3: recorded, 4: duplicates */
				esc_html__( 'Read %1$d rows into %2$d transactions — %3$d recorded, %4$d already seen.', 'fw' ),
				(int) $result['rows'],
				count( $result['preview'] ),
				(int) $result['recorded'],
				(int) $result['duplicates']
			);
			?>
		</p>

		<?php if ( ! empty( $result['duplicates'] ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Rows already seen were skipped rather than applied twice — that is idempotency working, and it is why re-importing a file you are unsure about is safe.', 'fw' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $result['errors'] ) ) : ?>
			<ul class="fw-pos-problems">
				<?php foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
				<?php if ( count( $result['errors'] ) > 20 ) : ?>
					<li><?php printf( esc_html__( '…and %d more.', 'fw' ), count( $result['errors'] ) - 20 ); ?></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $result['preview'] ) ) : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Transaction', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'When', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Lines', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_slice( $result['preview'], 0, 50 ) as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['external_id'] ); ?></code></td>
						<td><?php echo esc_html( FW_POS_Log::type_label( $row['type'] ) ); ?></td>
						<td><?php echo esc_html( $row['occurred_at'] ); ?></td>
						<td><?php echo (int) $row['lines']; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>

<h2><?php esc_html_e( 'Import a file', 'fw' ); ?></h2>

<form method="post" enctype="multipart/form-data">
	<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
	<input type="hidden" name="fw_pos_import_action" value="import">

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="import_connection"><?php esc_html_e( 'Attribute to', 'fw' ); ?></label></th>
			<td>
				<select name="import_connection" id="import_connection">
					<?php foreach ( $connections as $connection ) : ?>
						<option value="<?php echo esc_attr( $connection['id'] ); ?>">
							<?php echo esc_html( $connection['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="import_file"><?php esc_html_e( 'CSV file', 'fw' ); ?></label></th>
			<td>
				<input type="file" name="import_file" id="import_file" accept=".csv,text/csv,text/plain" required>
				<p class="description">
					<?php esc_html_e( 'Column names are matched loosely, so "SKU", "Sku Code" and "product_sku" all work. A "sku" or "gtin" column and a "quantity" column are required; a transaction id and a date are strongly recommended.', 'fw' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Run', 'fw' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="import_dry_run" value="1" checked>
					<?php esc_html_e( 'Preview first — parse the file and report, import nothing', 'fw' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Process file', 'fw' ); ?></button>
	</p>
</form>

<h2><?php esc_html_e( 'The format', 'fw' ); ?></h2>

<pre class="fw-pos-example"><?php echo esc_html( FW_POS_Batch_Importer::example() ); ?></pre>

<p class="description">
	<?php
	esc_html_e(
		'Rows sharing a transaction id become ONE event with several line items — that is what makes a partial refund distinguishable from a full one. A negative quantity is read as a return. If a row has no transaction id, one is derived from the row\'s own contents, so re-importing the same file de-duplicates — but editing a row and re-importing creates a new event for it, which is the honest reading of "this line changed".',
		'fw'
	);
	?>
</p>
