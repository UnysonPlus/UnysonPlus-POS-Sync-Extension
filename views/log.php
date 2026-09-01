<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ → POS Sync screen.
 *
 * Included from FW_POS_Admin_Page::_render(), so `$this` is the admin page and
 * `$tab`, `$tabs` and `$notice` are already resolved.
 *
 * @var string     $tab
 * @var array      $tabs
 * @var array|null $notice
 */

$status = $this->get_status();
?>
<div class="wrap fw-pos-sync">

	<h1><?php esc_html_e( 'POS Sync', 'fw' ); ?></h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['text'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $status['installed'] ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'The POS Sync database tables are missing. Nothing can be recorded until they exist.', 'fw' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_pos_action" value="reinstall_schema">
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create the tables', 'fw' ); ?></button></p>
			</form>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( [ 'page' => FW_POS_Admin_Page::PAGE_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<?php if ( 'log' === $tab ) : ?>

		<div class="fw-pos-status">
			<div class="fw-pos-status__item">
				<span class="fw-pos-status__label"><?php esc_html_e( 'Waiting to apply', 'fw' ); ?></span>
				<strong><?php echo (int) $status['pending']; ?></strong>
			</div>
			<div class="fw-pos-status__item">
				<span class="fw-pos-status__label"><?php esc_html_e( 'Scheduler', 'fw' ); ?></span>
				<strong><?php echo esc_html( $status['scheduler'] ); ?></strong>
			</div>
			<div class="fw-pos-status__item">
				<span class="fw-pos-status__label"><?php esc_html_e( 'Store connected', 'fw' ); ?></span>
				<strong>
					<?php echo $status['has_driver'] ? esc_html__( 'Yes', 'fw' ) : esc_html__( 'Not yet', 'fw' ); ?>
				</strong>
			</div>
			<div class="fw-pos-status__actions">
				<form method="post">
					<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
					<input type="hidden" name="fw_pos_action" value="run_queue">
					<button type="submit" class="button"><?php esc_html_e( 'Process queue now', 'fw' ); ?></button>
				</form>
			</div>
		</div>

		<?php if ( ! $status['has_driver'] ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					esc_html_e(
						'No e-commerce plugin is connected yet, so events are being recorded and then skipped rather than changing any stock. That is deliberate — nothing is lost, and every skipped event can be re-run once a store driver is available.',
						'fw'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php $table = $this->get_table(); ?>

		<?php if ( $table ) : ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_POS_Admin_Page::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="log">
				<?php
				$table->search_box( __( 'Search transactions', 'fw' ), 'fw-pos-search' );
				$table->display();
				?>
			</form>
		<?php endif; ?>

	<?php else : ?>

		<form method="post">
			<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_pos_action" value="save_settings">

			<?php
			$ext = fw()->extensions->get( 'pos-sync' );

			echo fw()->backend->render_options( // phpcs:ignore WordPress.Security.EscapeOutput
				$ext->get_settings_options(),
				(array) fw_get_db_ext_settings_option( 'pos-sync' )
			);
			?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fw' ); ?></button>
			</p>
		</form>

		<hr>

		<h3><?php esc_html_e( 'Endpoint', 'fw' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Tills and middleware will post to this base URL once connections are available in a later release.', 'fw' ); ?>
		</p>
		<p><code><?php echo esc_html( $status['endpoint'] ); ?></code></p>

	<?php endif; ?>

</div>
