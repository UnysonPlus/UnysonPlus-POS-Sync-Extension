<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Connections tab.
 *
 * Included from views/log.php, so `$status` is already resolved and `$this` is
 * the admin page.
 *
 * @var array $status
 */

$connections = FW_POS_Connections::all();
$issued      = FW_POS_Connections_Page::take_issued();
$scopes      = FW_POS_Connections::scopes();
?>

<?php if ( $issued ) : ?>
	<div class="notice notice-success fw-pos-issued">
		<h3><?php esc_html_e( 'Copy the secret now — it will not be shown again', 'fw' ); ?></h3>
		<p class="description">
			<?php
			esc_html_e(
				'The secret is stored encrypted and can still be used to verify signatures, but it is deliberately never displayed again: a credential sitting on a settings page ends up in screenshots and support tickets. If you lose it, rotate — that is one field to change at the till.',
				'fw'
			);
			?>
		</p>
		<table class="fw-pos-credential">
			<tr>
				<th><?php esc_html_e( 'Connection', 'fw' ); ?></th>
				<td><?php echo esc_html( $issued['name'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Key', 'fw' ); ?></th>
				<td><code><?php echo esc_html( $issued['key'] ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Secret', 'fw' ); ?></th>
				<td><code><?php echo esc_html( $issued['secret'] ); ?></code></td>
			</tr>
		</table>
	</div>
<?php endif; ?>

<?php if ( ! $status['encryption'] ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			esc_html_e(
				'PHP\'s OpenSSL extension is not available on this server, so connection secrets are stored in the clear. They still work, but a database backup would expose them. Ask your host to enable OpenSSL.',
				'fw'
			);
			?>
		</p>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Endpoint', 'fw' ); ?></h2>

<p class="description">
	<?php
	esc_html_e(
		'Any POS with outbound webhooks, any middleware (Zapier, Make, n8n) or a shop\'s own till software can post here. You are giving an integrator a documented format, not waiting for a vendor-specific driver.',
		'fw'
	);
	?>
</p>

<p><code><?php echo esc_html( $status['endpoint'] ); ?></code></p>

<?php if ( $status['tunnelled'] ) : ?>
	<p class="description">
		<?php esc_html_e( 'This is a development tunnel URL, set by the FW_POS_PUBLIC_URL constant. Sandbox webhooks are delivered from a vendor\'s servers and cannot reach localhost, which is what the tunnel is for.', 'fw' ); ?>
	</p>
<?php endif; ?>

<h2><?php esc_html_e( 'Connections', 'fw' ); ?></h2>

<p class="description fw-pos-intro">
	<?php
	esc_html_e(
		'One connection per till. A shop with three registers wants to know which one sent the event that looks wrong, revoke a stolen tablet without taking the shop offline, and put a new integration in test mode while the others keep trading — a single site-wide key makes all three impossible.',
		'fw'
	);
	?>
</p>

<table class="wp-list-table widefat fixed striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Name', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Key', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Mode', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Scopes', 'fw' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last seen', 'fw' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $connections ) ) : ?>
			<tr>
				<td colspan="6">
					<?php esc_html_e( 'No connections yet. Create one below, then point a till or a middleware step at the endpoint above.', 'fw' ); ?>
				</td>
			</tr>
		<?php endif; ?>

		<?php foreach ( $connections as $connection ) : ?>
			<?php $revoked = FW_POS_Connections::STATUS_ACTIVE !== $connection['status']; ?>
			<tr<?php echo $revoked ? ' class="fw-pos-revoked"' : ''; ?>>
				<td>
					<strong><?php echo esc_html( $connection['name'] ); ?></strong>
					<?php if ( $revoked ) : ?>
						<span class="fw-pos-badge is-failed"><?php esc_html_e( 'Revoked', 'fw' ); ?></span>
					<?php endif; ?>

					<div class="row-actions">
						<?php if ( ! $revoked ) : ?>
							<span>
								<a href="<?php echo esc_url( FW_POS_Connections_Page::action_url( 'set_mode', $connection['id'], [ 'mode' => FW_POS_Connections::MODE_LIVE === $connection['mode'] ? 'test' : 'live' ] ) ); ?>">
									<?php
									echo FW_POS_Connections::MODE_LIVE === $connection['mode']
										? esc_html__( 'Switch to test', 'fw' )
										: esc_html__( 'Switch to live', 'fw' );
									?>
								</a> |
							</span>
							<span>
								<a href="<?php echo esc_url( FW_POS_Connections_Page::action_url( 'rotate', $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Rotate secret', 'fw' ); ?>
								</a> |
							</span>
							<span class="delete">
								<a href="<?php echo esc_url( FW_POS_Connections_Page::action_url( 'revoke', $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Revoke', 'fw' ); ?>
								</a>
							</span>
						<?php else : ?>
							<span>
								<a href="<?php echo esc_url( FW_POS_Connections_Page::action_url( 'restore', $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Restore', 'fw' ); ?>
								</a> |
							</span>
							<span class="delete">
								<a href="<?php echo esc_url( FW_POS_Connections_Page::action_url( 'delete', $connection['id'] ) ); ?>">
									<?php esc_html_e( 'Delete', 'fw' ); ?>
								</a>
							</span>
						<?php endif; ?>
					</div>
				</td>
				<td>
					<?php
					$type_choices = FW_POS_Providers::choices();
					echo esc_html( isset( $type_choices[ $connection['type'] ] ) ? $type_choices[ $connection['type'] ] : $connection['type'] );
					?>
				</td>
				<td><code><?php echo esc_html( $connection['api_key'] ); ?></code></td>
				<td>
					<span class="fw-pos-badge <?php echo FW_POS_Connections::MODE_LIVE === $connection['mode'] ? 'is-applied' : 'is-neutral'; ?>">
						<?php echo esc_html( FW_POS_Connections::MODE_LIVE === $connection['mode'] ? __( 'Live', 'fw' ) : __( 'Test', 'fw' ) ); ?>
					</span>
				</td>
				<td>
					<?php
					$granted = array_filter( array_map( 'trim', explode( ',', (string) $connection['scopes'] ) ) );
					echo esc_html( $granted ? implode( ', ', $granted ) : __( 'none', 'fw' ) );
					?>
				</td>
				<td>
					<?php echo esc_html( $connection['last_seen_at'] ? FW_POS_Log::local_time( $connection['last_seen_at'] ) : __( 'never', 'fw' ) ); ?>
					<?php if ( abs( (int) $connection['last_skew'] ) > 120 ) : ?>
						<br>
						<span class="fw-pos-badge is-pending">
							<?php
							printf(
								/* translators: %d: seconds of clock skew */
								esc_html__( 'clock out by %ds', 'fw' ),
								(int) $connection['last_skew']
							);
							?>
						</span>
						<p class="description">
							<?php esc_html_e( 'A drifting till clock corrupts the ordering key. Fix it before going live.', 'fw' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( ! $revoked && 'generic' !== $connection['type'] ) : ?>
				<tr class="fw-pos-example-row">
					<td colspan="6">
						<?php
						$provider_view = $this->view_path( $connection['type'] );

						if ( $provider_view ) {
							include $provider_view;
						}
						?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( ! $revoked && 'generic' === $connection['type'] ) : ?>
				<tr class="fw-pos-example-row">
					<td colspan="6">
						<details>
							<summary><?php esc_html_e( 'Show an example signed request', 'fw' ); ?></summary>
							<p class="description">
								<?php
								esc_html_e(
									'Sign the exact bytes you send. Building the JSON twice — once to sign, once to send — reorders keys or changes whitespace and invalidates the signature. That is the single most common integration bug.',
									'fw'
								);
								?>
							</p>
							<pre class="fw-pos-example"><?php echo esc_html( FW_POS_Connections_Page::example_request( $connection ) ); ?></pre>
						</details>
					</td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Add a connection', 'fw' ); ?></h2>

<form method="post" class="fw-pos-add-connection">
	<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
	<input type="hidden" name="fw_pos_conn_action" value="create">

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="conn_name"><?php esc_html_e( 'Name', 'fw' ); ?></label></th>
			<td>
				<input name="conn_name" id="conn_name" type="text" class="regular-text" required
					placeholder="<?php esc_attr_e( 'Front counter', 'fw' ); ?>">
				<p class="description"><?php esc_html_e( 'Name it after the physical till. This appears on every log line.', 'fw' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="conn_type"><?php esc_html_e( 'Type', 'fw' ); ?></label></th>
			<td>
				<select name="conn_type" id="conn_type">
					<?php foreach ( FW_POS_Providers::choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					<?php esc_html_e( 'A generic connection uses our own signed format — the right answer for a till with no first-party driver, or for a middleware step. A vendor connection uses that vendor\'s own webhooks and signing.', 'fw' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Mode', 'fw' ); ?></th>
			<td>
				<label><input type="radio" name="conn_mode" value="test" checked> <?php esc_html_e( 'Test — record everything, change no stock', 'fw' ); ?></label><br>
				<label><input type="radio" name="conn_mode" value="live"> <?php esc_html_e( 'Live — apply events to the store', 'fw' ); ?></label>
				<p class="description">
					<?php esc_html_e( 'Start in test. Push a real trading day through it and compare the log against the till\'s own report before switching.', 'fw' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Scopes', 'fw' ); ?></th>
			<td>
				<?php foreach ( $scopes as $scope => $label ) : ?>
					<label>
						<input type="checkbox" name="conn_scopes[]" value="<?php echo esc_attr( $scope ); ?>" checked>
						<?php echo esc_html( $label ); ?> <code><?php echo esc_html( $scope ); ?></code>
					</label><br>
				<?php endforeach; ?>
				<p class="description">
					<?php esc_html_e( 'Grant only what this connection needs. A monitoring integration has no business holding inventory:write.', 'fw' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="conn_location"><?php esc_html_e( 'Location', 'fw' ); ?></label></th>
			<td>
				<input name="conn_location" id="conn_location" type="text" class="regular-text">
				<p class="description">
					<?php esc_html_e( 'Optional. Used when an event does not carry its own location reference.', 'fw' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Create connection', 'fw' ); ?></button>
	</p>
</form>
