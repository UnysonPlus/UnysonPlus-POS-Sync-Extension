<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Health tab — the screen that answers "is this actually working?"
 *
 * Included from views/log.php.
 *
 * @var array $status
 */

$health = FW_POS_Health::snapshot();

if ( empty( $health['installed'] ) ) {
	echo '<p>' . esc_html__( 'The database tables are missing.', 'fw' ) . '</p>';

	return;
}

$report = $health['report'];
?>

<?php if ( ! empty( $health['problems'] ) ) : ?>
	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Worth looking at', 'fw' ); ?></strong></p>
		<ul class="fw-pos-problems">
			<?php foreach ( $health['problems'] as $problem ) : ?>
				<li><?php echo esc_html( $problem ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php else : ?>
	<div class="notice notice-success inline">
		<p><?php esc_html_e( 'Nothing looks wrong. Events are flowing, the queue is keeping up, and every connection has reported recently.', 'fw' ); ?></p>
	</div>
<?php endif; ?>

<div class="fw-pos-status">
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Waiting', 'fw' ); ?></span>
		<strong><?php echo (int) $health['pending']; ?></strong>
	</div>
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Oldest wait', 'fw' ); ?></span>
		<strong>
			<?php
			echo $health['queue_age']
				? esc_html( human_time_diff( time() - $health['queue_age'], time() ) )
				: '—';
			?>
		</strong>
	</div>
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Applied (24h)', 'fw' ); ?></span>
		<strong><?php echo (int) $health['applied_24h']; ?></strong>
	</div>
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Failed (24h)', 'fw' ); ?></span>
		<strong><?php echo (int) $health['failed_24h']; ?></strong>
	</div>
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Failure rate', 'fw' ); ?></span>
		<strong><?php echo esc_html( $health['failure_rate'] ); ?>%</strong>
	</div>
	<div class="fw-pos-status__item">
		<span class="fw-pos-status__label"><?php esc_html_e( 'Unmatched', 'fw' ); ?></span>
		<strong><?php echo (int) $health['unmatched']; ?></strong>
	</div>
</div>

<p class="description">
	<?php
	printf(
		/* translators: 1: store name or "none", 2: scheduler name */
		esc_html__( 'Store: %1$s · Scheduler: %2$s', 'fw' ),
		esc_html( $health['store'] ? $health['store'] : __( 'not connected', 'fw' ) ),
		esc_html( $health['scheduler'] )
	);
	?>
</p>

<h2><?php esc_html_e( 'Connections', 'fw' ); ?></h2>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'A POS integration\'s worst failure is not an error — it is silence. A till whose webhook subscription was deleted does not throw; it just stops sending, and the log looks calm while stock drifts for days. This table is how you notice.',
		'fw'
	);
	?>
</p>

<table class="wp-list-table widefat striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Connection', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last heard from', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Events (24h)', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Clock', 'fw' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $health['connections'] ) ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'No connections yet.', 'fw' ); ?></td></tr>
		<?php endif; ?>

		<?php foreach ( $health['connections'] as $connection ) : ?>
			<?php
			$silent = null !== $connection['silent_for']
				&& $connection['silent_for'] > FW_POS_Health::SILENT_CONNECTION_SECONDS
				&& FW_POS_Connections::STATUS_ACTIVE === $connection['status'];
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( $connection['name'] ); ?></strong>
					<?php if ( FW_POS_Connections::STATUS_ACTIVE !== $connection['status'] ) : ?>
						<span class="fw-pos-badge is-neutral"><?php esc_html_e( 'Revoked', 'fw' ); ?></span>
					<?php endif; ?>
					<br><span class="description"><?php echo esc_html( $connection['type'] . ' · ' . $connection['mode'] ); ?></span>
				</td>
				<td>
					<?php if ( null === $connection['silent_for'] ) : ?>
						<span class="description"><?php esc_html_e( 'never — not set up yet', 'fw' ); ?></span>
					<?php else : ?>
						<?php echo esc_html( human_time_diff( time() - $connection['silent_for'], time() ) ); ?>
						<?php esc_html_e( 'ago', 'fw' ); ?>
						<?php if ( $silent ) : ?>
							<span class="fw-pos-badge is-failed"><?php esc_html_e( 'quiet', 'fw' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</td>
				<td><?php echo (int) $connection['events_24h']; ?></td>
				<td>
					<?php if ( abs( $connection['skew'] ) > 120 ) : ?>
						<span class="fw-pos-badge is-pending"><?php echo esc_html( $connection['skew'] ); ?>s</span>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'ok', 'fw' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Reconciliation', 'fw' ); ?></h2>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'Everything else here is built to make the event stream correct. It will still miss things — a deleted webhook subscription, an outage that outlasts the vendor\'s retries, a stock change the POS made without emitting anything. The only way to catch that is to periodically ask the POS what it thinks the numbers are and compare.',
		'fw'
	);
	?>
</p>

<p>
	<form method="post" class="fw-pos-inline-form">
		<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
		<input type="hidden" name="fw_pos_action" value="reconcile">
		<button type="submit" class="button"><?php esc_html_e( 'Run reconciliation now', 'fw' ); ?></button>
	</form>
</p>

<?php if ( $report ) : ?>
	<p class="description">
		<?php
		printf(
			/* translators: 1: how long ago, 2: number of items checked */
			esc_html__( 'Last run %1$s ago, checking %2$d items.', 'fw' ),
			esc_html( human_time_diff( (int) $report['ran_at'], time() ) ),
			(int) $report['checked']
		);
		?>
	</p>

	<?php if ( ! empty( $report['skipped'] ) ) : ?>
		<ul class="fw-pos-problems">
			<?php foreach ( $report['skipped'] as $skipped ) : ?>
				<li class="description"><?php echo esc_html( $skipped ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( empty( $report['drift'] ) ) : ?>
		<p><strong><?php esc_html_e( 'No drift. The POS and the store agree.', 'fw' ); ?></strong></p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Connection', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'POS says', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Store says', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Difference', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['drift'] as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['sku'] ); ?></code></td>
						<td><?php echo esc_html( $row['connection'] ); ?></td>
						<td><strong><?php echo (int) $row['pos']; ?></strong></td>
						<td><?php echo (int) $row['store']; ?></td>
						<td>
							<span class="fw-pos-badge <?php echo $row['difference'] < 0 ? 'is-failed' : 'is-pending'; ?>">
								<?php echo esc_html( sprintf( '%+d', (int) $row['difference'] ) ); ?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php
			esc_html_e(
				'Nothing has been changed. Applying this queues the POS figures as ordinary stocktake events, so they pass through event-time ordering and the authority policy exactly like a real count — and appear in the log, rather than stock changing with no explanation.',
				'fw'
			);
			?>
		</p>

		<form method="post">
			<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_pos_action" value="apply_reconciliation">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply these corrections', 'fw' ); ?></button>
		</form>
	<?php endif; ?>
<?php else : ?>
	<p class="description"><?php esc_html_e( 'No sweep has run yet. One runs automatically each night.', 'fw' ); ?></p>
<?php endif; ?>

<h2><?php esc_html_e( 'Who owns what', 'fw' ); ?></h2>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'Two systems editing the same field will diverge — not might, will. The only stable arrangement is to declare an owner per field and have the other side defer.',
		'fw'
	);
	?>
</p>

<table class="wp-list-table widefat striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Field', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Owner', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Why', 'fw' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( FW_POS_Policy::fields() as $key => $field ) : ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $field['label'] ); ?></strong>
					<?php if ( ! $field['enforced'] ) : ?>
						<br><span class="description"><?php esc_html_e( 'declared, not yet enforced — nothing writes this field', 'fw' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<span class="fw-pos-badge <?php echo FW_POS_Policy::OWNER_POS === $field['owner'] ? 'is-applied' : 'is-neutral'; ?>">
						<?php echo esc_html( FW_POS_Policy::OWNER_POS === $field['owner'] ? __( 'POS', 'fw' ) : __( 'Store', 'fw' ) ); ?>
					</span>
				</td>
				<td class="description"><?php echo esc_html( $field['why'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p class="description">
	<?php esc_html_e( 'Stock can be overridden per product on the Unmatched tab — for an online-only bundle or a made-to-order item whose stock the till should never touch.', 'fw' ); ?>
</p>

<h2 id="diagnostics"><?php esc_html_e( 'Diagnostic report', 'fw' ); ?></h2>

<?php $incompatible = FW_POS_Stores::incompatible(); ?>

<?php if ( $incompatible ) : ?>
	<div class="notice notice-warning inline">
		<p><strong><?php esc_html_e( 'A cart is installed that a driver could not use', 'fw' ); ?></strong></p>
		<ul class="fw-pos-problems">
			<?php foreach ( $incompatible as $id => $reason ) : ?>
				<li><code><?php echo esc_html( $id ); ?></code> — <?php echo esc_html( $reason ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p><?php esc_html_e( 'This is exactly the case the report below is for. Sending it turns a five-message conversation into a five-minute fix.', 'fw' ); ?></p>
	</div>
<?php endif; ?>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'Several drivers here — FluentCart, SureCart, Clover, and the CSV importer — were written against documented APIs and have never been run against a live install. If one of them does not work for you, this report contains what is actually needed to fix it: which driver, which versions, exactly which expectation was not met, and what the recent failures said.',
		'fw'
	);
	?>
</p>

<p class="description">
	<strong><?php esc_html_e( 'It is safe to paste in public.', 'fw' ); ?></strong>
	<?php
	esc_html_e(
		'No API keys, secrets, tokens or signature keys are included — not even truncated. No customer names or emails. Event payloads are summarised structurally (types, counts, SKUs, reasons) rather than included verbatim, because that is where personal data lives. Your site URL is left out unless you tick the box.',
		'fw'
	);
	?>
</p>

<?php
// phpcs:ignore WordPress.Security.NonceVerification
$include_url = isset( $_GET['diag_url'] );
$report      = FW_POS_Diagnostics::report( $include_url );
?>

<p>
	<?php if ( $include_url ) : ?>
		<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => FW_POS_Admin_Page::PAGE_SLUG, 'tab' => 'health' ], admin_url( 'admin.php' ) ) ); ?>#diagnostics">
			<?php esc_html_e( 'Leave the site URL out', 'fw' ); ?>
		</a>
	<?php else : ?>
		<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => FW_POS_Admin_Page::PAGE_SLUG, 'tab' => 'health', 'diag_url' => 1 ], admin_url( 'admin.php' ) ) ); ?>#diagnostics">
			<?php esc_html_e( 'Include my site URL', 'fw' ); ?>
		</a>
	<?php endif; ?>

	<a class="button button-primary" target="_blank" rel="noopener"
		href="<?php echo esc_url( FW_POS_Diagnostics::issues_url() ); ?>">
		<?php esc_html_e( 'Open the issue tracker', 'fw' ); ?>
	</a>
</p>

<p class="description">
	<?php esc_html_e( 'Select all of the box below, copy it, and paste it into a new issue along with what you expected to happen.', 'fw' ); ?>
</p>

<textarea class="fw-pos-diagnostics" readonly rows="18" onclick="this.select();"><?php echo esc_textarea( $report ); ?></textarea>

<h2><?php esc_html_e( 'Retention', 'fw' ); ?></h2>

<p class="description">
	<?php
	esc_html_e(
		'Settled events are pruned on the retention schedule set on the Settings tab. Failed events are never pruned, whatever that setting says — they are the ones somebody still has to look at, and a retention policy that deletes the evidence of a problem is worse than none.',
		'fw'
	);
	?>
</p>

<form method="post">
	<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
	<input type="hidden" name="fw_pos_action" value="prune">
	<button type="submit" class="button"><?php esc_html_e( 'Prune now', 'fw' ); ?></button>
</form>
