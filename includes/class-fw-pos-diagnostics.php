<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The diagnostic report.
 *
 * Several drivers here were written against documented APIs and never run
 * against a live install — FluentCart, SureCart and Clover in particular. The
 * honest response to that is not only to label them, but to make it easy for
 * whoever hits the failure to send back something actually useful.
 *
 * ## What makes a report worth receiving
 *
 * "It didn't work" cannot be acted on. What can be acted on is: which driver,
 * which version of which cart, exactly which expected function was missing,
 * what the last failures said, and what the environment is. That is a
 * five-minute fix instead of a five-message conversation, and it is entirely
 * mechanical to collect — so it should not be the reporter's job to know what
 * to include.
 *
 * ## What is deliberately NOT in it
 *
 * The report is meant to be pasted into a public issue tracker, so it must be
 * safe to paste in public. Never included:
 *
 *  - API keys, secrets, OAuth tokens or webhook signature keys, in any form —
 *    not even truncated, since a prefix plus a merchant id is often enough to
 *    identify an account.
 *  - Customer names, emails or addresses from event payloads.
 *  - The site's own URL, unless the reporter opts in.
 *
 * Event payloads are the awkward case: they are the most useful thing in a bug
 * report and the most likely place for personal data. So payloads are
 * **structurally summarised** — types, counts, SKUs and error reasons — rather
 * than included verbatim.
 */
class FW_POS_Diagnostics {

	/** Where a report should be sent. Filterable for white-labelled builds. */
	const ISSUES_URL = 'https://github.com/UnysonPlus/UnysonPlus-POS-Sync-Extension/issues/new';

	/**
	 * Build the report as plain text, ready to paste.
	 *
	 * @param bool $include_site_url
	 *
	 * @return string
	 */
	public static function report( $include_site_url = false ) {
		$lines = [];

		$lines[] = '### POS Sync diagnostic report';
		$lines[] = '';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		if ( $include_site_url ) {
			$lines[] = 'Site: ' . home_url();
		}

		$lines = array_merge(
			$lines,
			[ '' ],
			self::environment(),
			[ '' ],
			self::store_drivers(),
			[ '' ],
			self::providers(),
			[ '' ],
			self::connections(),
			[ '' ],
			self::activity(),
			[ '' ],
			self::failures()
		);

		$lines[] = '';
		$lines[] = '---';
		$lines[] = 'No keys, secrets, tokens or customer details are included in this report.';

		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------- *
	 * Sections
	 * ---------------------------------------------------------------------- */

	/**
	 * @return string[]
	 */
	private static function environment() {
		global $wp_version, $wpdb;

		$lines   = [ '#### Environment', '' ];
		$lines[] = '- POS Sync: ' . self::extension_version();
		$lines[] = '- WordPress: ' . $wp_version;
		$lines[] = '- PHP: ' . PHP_VERSION;
		$lines[] = '- MySQL: ' . $wpdb->db_version();
		$lines[] = '- Multisite: ' . ( is_multisite() ? 'yes' : 'no' );
		$lines[] = '- OpenSSL available: ' . ( FW_POS_Secrets::available() ? 'yes' : 'NO — secrets stored in the clear' );
		$lines[] = '- Scheduler: ' . ( FW_POS_Queue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' );
		$lines[] = '- WP-Cron disabled: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'YES' : 'no' );
		$lines[] = '- Schema version: ' . get_option( FW_POS_Schema::DB_VERSION_OPTION, 'not installed' )
			. ' (expected ' . FW_POS_Schema::DB_VERSION . ')';
		$lines[] = '- Tables present: ' . ( FW_POS_Schema::is_installed() ? 'yes' : 'NO' );
		$lines[] = '- Public URL override: ' . ( defined( 'FW_POS_PUBLIC_URL' ) && FW_POS_PUBLIC_URL ? 'set' : 'not set' );

		return $lines;
	}

	/**
	 * The section that matters most for an unverified driver: exactly which
	 * expectation was not met.
	 *
	 * @return string[]
	 */
	private static function store_drivers() {
		$lines  = [ '#### Store drivers', '' ];
		$active = FW_POS_Stores::active();

		foreach ( FW_POS_Stores::all() as $id => $driver ) {
			$available = $driver->is_available();

			$lines[] = sprintf(
				'- **%s** (`%s`) — %s%s%s',
				$driver->get_label(),
				$id,
				$available ? 'available' : 'unavailable',
				$active && $active->get_id() === $id ? ', ACTIVE' : '',
				'stable' === self::maturity_of( $driver ) ? '' : ' [' . self::maturity_of( $driver ) . ']'
			);

			if ( ! $available && method_exists( $driver, 'unavailable_reason' ) ) {
				$reason = $driver->unavailable_reason();

				if ( $reason ) {
					// This is the line that turns "doesn't work" into a fix.
					$lines[] = '  - reason: ' . $reason;
				}
			}

			if ( $available ) {
				$capabilities = [];

				foreach ( (array) $driver->get_capabilities() as $capability => $supported ) {
					$capabilities[] = $capability . '=' . ( $supported ? 'yes' : 'no' );
				}

				$lines[] = '  - capabilities: ' . implode( ', ', $capabilities );
			}
		}

		if ( ! $active ) {
			$lines[] = '';
			$lines[] = '> No active store driver (' . FW_POS_Stores::inactive_reason() . ').';
		}

		return $lines;
	}

	/**
	 * @return string[]
	 */
	private static function providers() {
		$lines = [ '#### POS providers', '' ];

		foreach ( FW_POS_Providers::all() as $id => $provider ) {
			$lines[] = sprintf(
				'- **%s** (`%s`)%s',
				$provider->get_label(),
				$id,
				'stable' === self::maturity_of( $provider ) ? '' : ' [' . self::maturity_of( $provider ) . ']'
			);
		}

		return $lines;
	}

	/**
	 * Connections WITHOUT their credentials — name, type, mode, health only.
	 *
	 * @return string[]
	 */
	private static function connections() {
		$lines = [ '#### Connections', '' ];
		$rows  = FW_POS_Health::connection_health();

		if ( empty( $rows ) ) {
			$lines[] = '- none';

			return $lines;
		}

		foreach ( $rows as $index => $row ) {
			// Numbered rather than named: a connection name can be a shop or a
			// person ("Priya's till").
			$lines[] = sprintf(
				'- #%d — type `%s`, mode `%s`, %s, %d events in 24h, clock skew %ds, last seen %s',
				$index + 1,
				$row['type'],
				$row['mode'],
				$row['status'],
				$row['events_24h'],
				$row['skew'],
				null === $row['silent_for'] ? 'never' : human_time_diff( time() - $row['silent_for'], time() ) . ' ago'
			);
		}

		return $lines;
	}

	/**
	 * @return string[]
	 */
	private static function activity() {
		$lines = [ '#### Activity (last 24h)', '' ];

		if ( ! FW_POS_Schema::is_installed() ) {
			$lines[] = '- tables missing';

			return $lines;
		}

		$recent = FW_POS_Ledger::state_counts_since( time() - DAY_IN_SECONDS );

		if ( empty( $recent ) ) {
			$lines[] = '- no events';
		}

		foreach ( $recent as $state => $count ) {
			$lines[] = sprintf( '- %s: %d', $state, $count );
		}

		$lines[] = sprintf( '- waiting now: %d', FW_POS_Ledger::pending_count() );
		$lines[] = sprintf(
			'- unmatched items: %d',
			FW_POS_Ledger::count_items( [ 'status' => FW_POS_Ledger::ITEM_UNMATCHED ] )
		);

		return $lines;
	}

	/**
	 * The last failures and skips, summarised structurally.
	 *
	 * SKUs are included because they are what a matching bug is about, and a
	 * SKU is not personal data. Payload bodies are not, because they can carry
	 * a customer's email.
	 *
	 * @return string[]
	 */
	private static function failures() {
		$lines = [ '#### Recent failures and skips', '' ];

		if ( ! FW_POS_Schema::is_installed() ) {
			return $lines;
		}

		$found = false;

		foreach ( [ FW_POS_Ledger::STATE_FAILED, FW_POS_Ledger::STATE_SKIPPED ] as $state ) {
			$events = FW_POS_Ledger::query_events(
				[
					'state'    => $state,
					'per_page' => 10,
					'page'     => 1,
				]
			);

			foreach ( $events as $event ) {
				$found = true;

				$lines[] = sprintf(
					'- `%s` %s — %s',
					$state,
					$event['type'],
					$event['error'] ? $event['error'] : '(no reason recorded)'
				);

				$summary = self::summarise_payload( $event );

				if ( $summary ) {
					$lines[] = '  - ' . $summary;
				}
			}
		}

		if ( ! $found ) {
			$lines[] = '- none';
		}

		return $lines;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Structure, not content. Never returns a raw payload.
	 *
	 * @param array $event
	 *
	 * @return string
	 */
	private static function summarise_payload( array $event ) {
		$payload = json_decode( (string) $event['payload'], true );

		if ( ! is_array( $payload ) ) {
			return '';
		}

		$parts = [];

		foreach ( [ 'line_items', 'counts' ] as $key ) {
			if ( empty( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
				continue;
			}

			$skus = [];

			foreach ( array_slice( $payload[ $key ], 0, 5 ) as $line ) {
				$skus[] = isset( $line['sku'] ) && '' !== $line['sku'] ? (string) $line['sku'] : '(no sku)';
			}

			$parts[] = sprintf( '%s: %d [%s]', $key, count( $payload[ $key ] ), implode( ', ', $skus ) );
		}

		if ( ! empty( $payload['mode'] ) ) {
			$parts[] = 'mode: ' . $payload['mode'];
		}

		if ( ! empty( $payload['meta']['provider'] ) ) {
			$parts[] = 'provider: ' . $payload['meta']['provider'];
		}

		if ( ! empty( $payload['meta']['source'] ) ) {
			$parts[] = 'source: ' . $payload['meta']['source'];
		}

		return implode( ' · ', $parts );
	}

	/**
	 * @param object $driver
	 *
	 * @return string
	 */
	private static function maturity_of( $driver ) {
		return method_exists( $driver, 'maturity' ) ? (string) $driver->maturity() : 'stable';
	}

	/**
	 * @return string
	 */
	private static function extension_version() {
		$extension = function_exists( 'fw' ) ? fw()->extensions->get( 'pos-sync' ) : null;

		return $extension && $extension->manifest ? $extension->manifest->get_version() : 'unknown';
	}

	/**
	 * Where to send it.
	 *
	 * @return string
	 */
	public static function issues_url() {
		/**
		 * Filter where the diagnostic report should be sent.
		 *
		 * @param string $url
		 */
		return (string) apply_filters( 'fw_pos_diagnostics_url', self::ISSUES_URL );
	}
}
