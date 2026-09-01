<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The audit log — presentation logic over the ledger repository.
 *
 * This is the screen anyone opens when a stock number looks wrong, so its job
 * is to make the *reason* legible, not just the outcome. Three of the four
 * terminal states are successes; only `failed` wants attention, and the labels
 * and colours here say so, because a merchant who reads "skipped" as "broken"
 * will file a bug about correct behaviour.
 *
 * Writes no SQL of its own — everything comes through FW_POS_Ledger.
 */
class FW_POS_Log {

	/**
	 * Human labels for event states.
	 *
	 * @return array<string,string>
	 */
	public static function states() {
		return [
			FW_POS_Ledger::STATE_PENDING   => __( 'Pending', 'fw' ),
			FW_POS_Ledger::STATE_APPLIED   => __( 'Applied', 'fw' ),
			FW_POS_Ledger::STATE_DUPLICATE => __( 'Duplicate', 'fw' ),
			FW_POS_Ledger::STATE_SKIPPED   => __( 'Skipped', 'fw' ),
			FW_POS_Ledger::STATE_FAILED    => __( 'Failed', 'fw' ),
		];
	}

	/**
	 * Human labels for event types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return [
			FW_POS_Ledger::TYPE_SALE      => __( 'Sale', 'fw' ),
			FW_POS_Ledger::TYPE_REFUND    => __( 'Refund', 'fw' ),
			FW_POS_Ledger::TYPE_VOID      => __( 'Void', 'fw' ),
			FW_POS_Ledger::TYPE_INVENTORY => __( 'Inventory', 'fw' ),
		];
	}

	/**
	 * @param string $state
	 *
	 * @return string
	 */
	public static function state_label( $state ) {
		$states = self::states();

		return isset( $states[ $state ] ) ? $states[ $state ] : ucfirst( (string) $state );
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	public static function type_label( $type ) {
		$types = self::types();

		return isset( $types[ $type ] ) ? $types[ $type ] : ucfirst( (string) $type );
	}

	/**
	 * CSS modifier for a state's badge.
	 *
	 * `duplicate` and `skipped` are deliberately neutral, not warnings — a
	 * retried webhook being refused is the system working.
	 *
	 * @param string $state
	 *
	 * @return string
	 */
	public static function state_class( $state ) {
		switch ( $state ) {
			case FW_POS_Ledger::STATE_APPLIED:
				return 'is-applied';

			case FW_POS_Ledger::STATE_FAILED:
				return 'is-failed';

			case FW_POS_Ledger::STATE_PENDING:
				return 'is-pending';

			default:
				return 'is-neutral';
		}
	}

	/**
	 * Turn a recorded reason into something a shop owner can act on.
	 *
	 * The stored reasons are machine tokens so they stay greppable and stable;
	 * this is the only place they are translated for humans. An unexplained
	 * "skipped" generates a support ticket — an explained one usually does not.
	 *
	 * @param string $error Raw reason from the events table.
	 *
	 * @return string
	 */
	public static function explain( $error ) {
		$error = (string) $error;

		if ( '' === $error ) {
			return '';
		}

		$known = [
			'no_store_driver'      => __( 'No e-commerce plugin is connected yet, so there was nothing to apply this to.', 'fw' ),
			'test_mode'            => __( 'The connection is in test mode — the event was recorded but no stock was changed.', 'fw' ),
			'missing_external_id'  => __( 'The sender did not include a transaction id, which is required to keep events from being applied twice.', 'fw' ),
			'insert_failed'        => __( 'The event could not be written to the database.', 'fw' ),
			'unmatched_sku'        => __( 'No product matches this SKU. Map it on the Unmatched screen and re-run the event.', 'fw' ),
			'apply_failed'         => __( 'The store rejected the change. See the error detail below.', 'fw' ),
			'policy_store_owned'   => __( 'This item is set to store-owned stock, so the till is not allowed to change it. That is a deliberate per-product override, not a fault.', 'fw' ),
		];

		foreach ( $known as $token => $message ) {
			if ( 0 === strpos( $error, $token ) ) {
				return $message;
			}
		}

		if ( 0 === strpos( $error, 'stale_count' ) ) {
			return __( 'A newer stock count for this item has already been applied, so this older one was refused. This is deliberate — it stops a till that was offline from rewinding current stock.', 'fw' );
		}

		return $error;
	}

	/**
	 * Short, human summary of what an event contained.
	 *
	 * @param array $event Row from the events table.
	 *
	 * @return string
	 */
	public static function summarize( array $event ) {
		$payload = json_decode( isset( $event['payload'] ) ? (string) $event['payload'] : '', true );

		if ( ! is_array( $payload ) ) {
			return '';
		}

		if ( FW_POS_Ledger::TYPE_INVENTORY === $event['type'] ) {
			$counts = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [];
			$mode   = isset( $payload['mode'] ) ? (string) $payload['mode'] : 'absolute';

			return sprintf(
				/* translators: 1: count mode, 2: number of items */
				_n( '%1$s count, %2$d item', '%1$s count, %2$d items', count( $counts ), 'fw' ),
				'absolute' === $mode ? __( 'Absolute', 'fw' ) : __( 'Relative', 'fw' ),
				count( $counts )
			);
		}

		$lines = isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ? $payload['line_items'] : [];

		if ( empty( $lines ) ) {
			return '';
		}

		$skus = [];

		foreach ( array_slice( $lines, 0, 3 ) as $line ) {
			if ( ! empty( $line['sku'] ) ) {
				$skus[] = (string) $line['sku'];
			}
		}

		$summary = implode( ', ', $skus );

		if ( count( $lines ) > 3 ) {
			$summary .= sprintf(
				/* translators: %d: number of additional line items */
				__( ' +%d more', 'fw' ),
				count( $lines ) - 3
			);
		}

		return $summary;
	}

	/**
	 * Format a stored UTC datetime in the site's timezone.
	 *
	 * Everything is stored UTC; everything is displayed local. Showing raw UTC
	 * to a shop owner comparing the log against their till's own report is a
	 * reliable way to manufacture a phantom bug.
	 *
	 * @param string $mysql_utc
	 *
	 * @return string
	 */
	public static function local_time( $mysql_utc ) {
		if ( empty( $mysql_utc ) || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}

		$timestamp = strtotime( $mysql_utc . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Counts for the status links above the table.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		return FW_POS_Ledger::state_counts();
	}
}
