<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Square panel on the Connections tab.
 *
 * Included per Square connection from views/connections.php.
 *
 * @var array $connection
 */

$square      = new FW_POS_Provider_Square();
$credentials = FW_POS_Provider::credentials( $connection );
$connected   = $square->is_connected( $connection );
$environment = isset( $credentials['environment'] ) ? $credentials['environment'] : 'sandbox';
$webhook_url = FW_POS_REST_Controller::webhook_url( 'square', (int) $connection['id'] );
?>

<div class="fw-pos-square">

	<?php if ( $square->needs_reconnect( $connection ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<?php
				esc_html_e(
					'Square has stopped accepting this connection — the merchant revoked access, or the application was changed. No amount of retrying will fix that, so events will keep failing until you reconnect.',
					'fw'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<h4>
		<?php esc_html_e( 'Square', 'fw' ); ?>
		<span class="fw-pos-badge <?php echo $connected ? 'is-applied' : 'is-neutral'; ?>">
			<?php echo $connected ? esc_html__( 'Connected', 'fw' ) : esc_html__( 'Not connected', 'fw' ); ?>
		</span>
		<span class="fw-pos-badge is-neutral"><?php echo esc_html( $environment ); ?></span>
	</h4>

	<form method="post">
		<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
		<input type="hidden" name="fw_pos_square_action" value="save_app">
		<input type="hidden" name="connection_id" value="<?php echo esc_attr( $connection['id'] ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Environment', 'fw' ); ?></th>
				<td>
					<label><input type="radio" name="sq_environment" value="sandbox" <?php checked( $environment, 'sandbox' ); ?>> <?php esc_html_e( 'Sandbox', 'fw' ); ?></label>
					<label><input type="radio" name="sq_environment" value="production" <?php checked( $environment, 'production' ); ?>> <?php esc_html_e( 'Production', 'fw' ); ?></label>
					<p class="description">
						<?php
						esc_html_e(
							'Sandbox and production ids are disjoint — a sandbox catalog id means nothing in production — so create a SEPARATE connection for each rather than switching this one over. Keeping both lets you reproduce a problem in sandbox while the shop trades.',
							'fw'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sq_app_id"><?php esc_html_e( 'Application ID', 'fw' ); ?></label></th>
				<td>
					<input name="sq_app_id" id="sq_app_id" type="text" class="regular-text"
						value="<?php echo esc_attr( isset( $credentials['application_id'] ) ? $credentials['application_id'] : '' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sq_app_secret"><?php esc_html_e( 'Application secret', 'fw' ); ?></label></th>
				<td>
					<input name="sq_app_secret" id="sq_app_secret" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo empty( $credentials['application_secret'] ) ? '' : esc_attr__( 'saved — leave blank to keep', 'fw' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sq_signature_key"><?php esc_html_e( 'Webhook signature key', 'fw' ); ?></label></th>
				<td>
					<input name="sq_signature_key" id="sq_signature_key" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo empty( $credentials['webhook_signature_key'] ) ? '' : esc_attr__( 'saved — leave blank to keep', 'fw' ); ?>">
					<p class="description">
						<?php esc_html_e( 'Shown in the Square dashboard when you create the webhook subscription. Without it every delivery fails signature verification.', 'fw' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button"><?php esc_html_e( 'Save Square details', 'fw' ); ?></button>
		</p>
	</form>

	<h4><?php esc_html_e( 'What to register in the Square dashboard', 'fw' ); ?></h4>

	<table class="fw-pos-credential">
		<tr>
			<th><?php esc_html_e( 'OAuth redirect URL', 'fw' ); ?></th>
			<td><code><?php echo esc_html( FW_POS_Square_Page::redirect_uri() ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Webhook URL', 'fw' ); ?></th>
			<td><code><?php echo esc_html( $webhook_url ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Events', 'fw' ); ?></th>
			<td><code>payment.created, payment.updated, refund.created, refund.updated, inventory.count.updated</code></td>
		</tr>
	</table>

	<p class="description">
		<?php
		esc_html_e(
			'The webhook URL must be registered EXACTLY as shown. Square includes the URL in the signature it computes, so a trailing slash or a different host makes every delivery fail verification — which looks like a wrong signature key and is not.',
			'fw'
		);
		?>
	</p>

	<p>
		<?php if ( ! $connected ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( FW_POS_Square_Page::action_url( 'connect', $connection['id'] ) ); ?>">
				<?php esc_html_e( 'Connect with Square', 'fw' ); ?>
			</a>
		<?php else : ?>
			<a class="button" href="<?php echo esc_url( FW_POS_Square_Page::action_url( 'import_catalog', $connection['id'] ) ); ?>">
				<?php esc_html_e( 'Import catalog', 'fw' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( FW_POS_Square_Page::action_url( 'backfill', $connection['id'] ) ); ?>">
				<?php esc_html_e( 'Backfill last 7 days', 'fw' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( FW_POS_Square_Page::action_url( 'disconnect', $connection['id'] ) ); ?>">
				<?php esc_html_e( 'Disconnect', 'fw' ); ?>
			</a>
		<?php endif; ?>
	</p>

	<?php if ( $connected ) : ?>
		<?php
		$locations = $square->locations( $connection );
		$map       = isset( $credentials['location_map'] ) && is_array( $credentials['location_map'] ) ? $credentials['location_map'] : [];
		?>

		<?php if ( $locations ) : ?>
			<h4><?php esc_html_e( 'Locations', 'fw' ); ?></h4>

			<p class="description">
				<?php
				esc_html_e(
					'Square creates an online location alongside the physical one even for a single-shop seller, so "the first location" is a reliable way to sync counter sales against the wrong stock. Map them deliberately. An unmapped location shows its raw Square id in the log — visibly wrong rather than plausibly wrong.',
					'fw'
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_pos_square_action" value="save_locations">
				<input type="hidden" name="connection_id" value="<?php echo esc_attr( $connection['id'] ); ?>">

				<table class="form-table" role="presentation">
					<?php foreach ( $locations as $location ) : ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $location['name'] ); ?>
								<br><code><?php echo esc_html( $location['id'] ); ?></code>
								<?php if ( ! empty( $location['type'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $location['type'] ); ?></span>
								<?php endif; ?>
							</th>
							<td>
								<input type="text" class="regular-text"
									name="sq_location[<?php echo esc_attr( $location['id'] ); ?>]"
									value="<?php echo esc_attr( isset( $map[ $location['id'] ] ) ? $map[ $location['id'] ] : '' ); ?>"
									placeholder="<?php esc_attr_e( 'local reference, e.g. high-street', 'fw' ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" class="button"><?php esc_html_e( 'Save locations', 'fw' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of mapped variations */
				esc_html__( '%d Square variations are mapped to SKUs.', 'fw' ),
				(int) FW_POS_Square_Catalog::mapped_count( (int) $connection['id'] )
			);
			?>
		</p>
	<?php endif; ?>

</div>
