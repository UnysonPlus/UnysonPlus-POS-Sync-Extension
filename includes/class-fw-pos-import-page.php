<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Actions for the CSV Import tab.
 */
class FW_POS_Import_Page {

	const TRANSIENT_RESULT = 'fw_ext_pos_sync_import_';

	/** Refuse anything larger, before reading it into memory. */
	const MAX_BYTES = 8388608; // 8 MB

	/**
	 * @param FW_POS_Admin_Page $page
	 */
	public static function handle_actions( $page ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_POST['fw_pos_import_action'] ) ) {
			return;
		}

		if ( ! current_user_can( FW_POS_Admin_Page::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fw' ) );
		}

		check_admin_referer( FW_POS_Admin_Page::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$connection_id = isset( $_POST['import_connection'] ) ? (int) $_POST['import_connection'] : 0;
		$dry_run       = ! empty( $_POST['import_dry_run'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$csv = self::read_upload();

		if ( is_wp_error( $csv ) ) {
			FW_POS_Admin_Page::notice( 'error', $csv->get_error_message() );
			FW_POS_Admin_Page::redirect( 'import' );
		}

		$result            = FW_POS_Batch_Importer::import( $csv, $connection_id, $dry_run );
		$result['dry_run'] = $dry_run;

		set_transient( self::TRANSIENT_RESULT . get_current_user_id(), $result, MINUTE_IN_SECONDS * 10 );

		FW_POS_Admin_Page::redirect( 'import' );
	}

	/**
	 * Read the uploaded file, refusing anything that is not plausibly a CSV.
	 *
	 * Deliberately does NOT use `wp_handle_upload()`: that moves the file into
	 * the uploads directory, where it would sit permanently and publicly for a
	 * file that is read once and contains a shop's takings.
	 *
	 * @return string|WP_Error
	 */
	private static function read_upload() {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the caller.
		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'fw' ) );
		}

		$file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'The upload did not complete. A very large file may have exceeded the server limit.', 'fw' ) );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error(
				'too_large',
				sprintf(
					/* translators: %s: size limit */
					__( 'That file is larger than %s. Split it by day and import the parts — re-importing is harmless, so overlapping days are safe.', 'fw' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'not_uploaded', __( 'That file could not be read.', 'fw' ) );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $contents ) {
			return new WP_Error( 'unreadable', __( 'That file could not be read.', 'fw' ) );
		}

		// A CSV is text. Reject anything with a null byte before parsing it —
		// that is a binary file with a .csv name, and nothing good follows.
		if ( false !== strpos( $contents, "\0" ) ) {
			return new WP_Error( 'not_text', __( 'That does not look like a text file. Export as CSV rather than a spreadsheet.', 'fw' ) );
		}

		return $contents;
	}

	/**
	 * @return array|null
	 */
	public static function take_result() {
		$result = get_transient( self::TRANSIENT_RESULT . get_current_user_id() );

		if ( $result ) {
			delete_transient( self::TRANSIENT_RESULT . get_current_user_id() );
		}

		return $result ? $result : null;
	}
}
