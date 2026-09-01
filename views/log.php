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
				<span class="fw-pos-status__label"><?php esc_html_e( 'Store', 'fw' ); ?></span>
				<strong>
					<?php echo $status['has_driver'] ? esc_html( $status['store_label'] ) : esc_html__( 'Not connected', 'fw' ); ?>
				</strong>
			</div>
			<div class="fw-pos-status__item">
				<span class="fw-pos-status__label"><?php esc_html_e( 'Mode', 'fw' ); ?></span>
				<strong>
					<?php echo $status['live'] ? esc_html__( 'Live', 'fw' ) : esc_html__( 'Test', 'fw' ); ?>
				</strong>
			</div>
			<div class="fw-pos-status__item">
				<span class="fw-pos-status__label"><?php esc_html_e( 'Unmatched', 'fw' ); ?></span>
				<strong><?php echo (int) $status['unmatched']; ?></strong>
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
					<?php if ( 'ambiguous' === $status['store_why'] ) : ?>
						<?php
						esc_html_e(
							'More than one e-commerce plugin is installed, so POS Sync will not guess which one owns your stock. Choose it on the Settings tab. Until then events are recorded and skipped, not lost.',
							'fw'
						);
						?>
					<?php else : ?>
						<?php
						esc_html_e(
							'No supported e-commerce plugin is active, so events are being recorded and then skipped rather than changing any stock. That is deliberate — nothing is lost, and every skipped event can be re-run once a store is connected.',
							'fw'
						);
						?>
					<?php endif; ?>
				</p>
			</div>
		<?php elseif ( ! $status['live'] ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: store name */
						esc_html__( 'Test mode: events are matched against %s and logged in full, but no stock is changed. Switch to live on the Settings tab when the log looks right.', 'fw' ),
						esc_html( $status['store_label'] )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $status['has_driver'] ) : ?>
			<form method="post" class="fw-pos-requeue">
				<?php wp_nonce_field( FW_POS_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_pos_action" value="requeue_skipped">
				<input type="hidden" name="reason" value="no_store_driver">
				<button type="submit" class="button-link">
					<?php esc_html_e( 'Re-queue events that were skipped before a store was connected', 'fw' ); ?>
				</button>
			</form>
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

	<?php elseif ( 'items' === $tab ) : ?>

		<?php $items_table = $this->get_items_table(); ?>

		<p class="description fw-pos-intro">
			<?php
			esc_html_e(
				'Items the till has reported, and the products they resolve to. Matching is by SKU first and barcode second — never by name, because two products called "Blue Hoodie" would silently swap stock. Anything that matches nothing waits here rather than being guessed at or auto-created.',
				'fw'
			);
			?>
		</p>

		<?php if ( $items_table ) : ?>
			<?php $items_table->views(); ?>
			<form method="post">
				<?php wp_nonce_field( 'bulk-pos_items' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_POS_Admin_Page::PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="items">
				<?php
				$items_table->search_box( __( 'Search items', 'fw' ), 'fw-pos-item-search' );
				$items_table->display();
				?>
			</form>
		<?php endif; ?>

	<?php elseif ( 'connections' === $tab ) : ?>

		<?php
		$connections_view = $this->view_path( 'connections' );

		if ( $connections_view ) {
			include $connections_view;
		}
		?>

	<?php elseif ( 'terminal' === $tab ) : ?>

		<?php
		$terminal_view = $this->view_path( 'virtual-terminal' );

		if ( $terminal_view ) {
			include $terminal_view;
		}
		?>

	<?php elseif ( 'import' === $tab ) : ?>

		<?php
		$import_view = $this->view_path( 'import' );

		if ( $import_view ) {
			include $import_view;
		}
		?>

	<?php elseif ( 'health' === $tab ) : ?>

		<?php
		$health_view = $this->view_path( 'health' );

		if ( $health_view ) {
			include $health_view;
		}
		?>

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


	<?php endif; ?>

</div>
