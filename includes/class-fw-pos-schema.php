<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Schema owner. The ONLY class in the extension that emits DDL.
 *
 * Follows the discipline established by the Newsletter CRM installer — see that
 * extension's AGENTS.md for the full rationale. In brief:
 *
 *  - `dbDelta()` is picky by contract: two spaces after PRIMARY KEY, one field
 *    per line, lowercase types, `KEY` never `INDEX`, and the collation string
 *    from `$wpdb->get_charset_collate()`. Break any of those and it silently
 *    re-runs ALTERs on every load.
 *  - Guarded by an autoloaded option, so it costs one `get_option()` per request
 *    once current. Checked on every `_init()`, which makes activation *and*
 *    plugin-update upgrades self-healing with no activation hook to miss.
 *  - `dbDelta()` adds columns and indexes; it never drops or renames. Anything
 *    destructive is a numbered, version-guarded block in `migrate()`.
 *  - `$wpdb->prefix` (per-site), not `base_prefix` — on multisite each site owns
 *    its own till data, which is right for the demos network and real networks.
 *  - Deactivating NEVER drops a table. Removal is an explicit, opt-in action.
 */
class FW_POS_Schema {

	/** Bump whenever the schema below changes, or it never reaches an existing site. */
	const DB_VERSION = '1.3.0';

	const DB_VERSION_OPTION = 'fw_ext_pos_sync_db_version';

	/**
	 * Fully-qualified table name for a logical table.
	 *
	 * @param string $table items|events|map|connections
	 *
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . 'fw_pos_' . $table;
	}

	/**
	 * Install or upgrade if the stored version differs. Cheap no-op otherwise.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade every table, run migrations, stamp the version.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$from    = (string) get_option( self::DB_VERSION_OPTION, '' );

		foreach ( self::schema( $collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::migrate( $from );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * The schema. One CREATE TABLE per logical table, dbDelta-formatted.
	 *
	 * Design notes:
	 *
	 *  - **`external_id` is varchar(100), not 190.** The UNIQUE index on
	 *    (connection_id, external_id) has to fit inside InnoDB's 767-byte key
	 *    limit under utf8mb4 (4 bytes per character). 8 + 190*4 = 768 overflows
	 *    it by a single byte; 8 + 100*4 = 408 leaves room. No POS in existence
	 *    issues a transaction id anywhere near 100 characters.
	 *  - **That unique index IS the idempotency guarantee.** A replayed webhook
	 *    is refused by the database, not by application code that a future
	 *    caller might forget to run. See `FW_POS_Ledger::record_event()`.
	 *  - **`occurred_at` and `received_at` are separate columns and separate
	 *    concepts.** `occurred_at` is when the sale happened at the till and is
	 *    the ordering key; `received_at` is when it reached WordPress and exists
	 *    only for diagnostics. Conflating them is what lets a reconnecting
	 *    offline till rewind an afternoon's stock.
	 *  - **`state` and `type` are varchar, not ENUM**, so adding `void` or
	 *    `held` later is a PHP whitelist change rather than a schema migration.
	 *  - **`payload` stores the request verbatim.** It is what makes an event
	 *    replayable and auditable a year later, and it is the reason a support
	 *    question can be answered from an event id alone.
	 *  - **`credentials` holds a provider's own tokens**, encrypted, as JSON: a
	 *    Square OAuth access token, its refresh token, the expiry, the merchant
	 *    id, the webhook signature key. Separate from `secret` because they are
	 *    different things with different lifecycles — `secret` is OUR shared
	 *    secret for the generic endpoint and is rotated by us, while these are
	 *    someone else's tokens that expire and refresh on their schedule.
	 *    Squeezing both into one column would mean a Square reconnection
	 *    silently invalidating a generic webhook's credential.
	 *  - **A connection's `secret` is stored ENCRYPTED, not hashed.** HMAC
	 *    verification has to recompute the signature, which means the plaintext
	 *    secret must be recoverable — a hash cannot do that. See
	 *    FW_POS_Secrets for what that does and does not protect against.
	 *  - **`items.policy` is the per-product authority override**, empty for the
	 *    site default. It exists as a column rather than an option because the
	 *    lookup happens once per line item on the hot path, and an option array
	 *    of every overridden SKU would be read and unserialised on every event.
	 *  - **`sku` is indexed but NOT unique.** A SKU legitimately exists in the
	 *    POS before it exists in the store, and two connections may report the
	 *    same SKU. Matching resolves that; the schema must not pre-empt it.
	 *
	 * @param string $collate
	 *
	 * @return array
	 */
	private static function schema( $collate ) {
		$items       = self::table( 'items' );
		$events      = self::table( 'events' );
		$map         = self::table( 'map' );
		$connections = self::table( 'connections' );

		$sql = [];

		$sql[] = "CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL auto_increment,
	sku varchar(100) NOT NULL default '',
	gtin varchar(64) NOT NULL default '',
	name varchar(190) NOT NULL default '',
	store_ref varchar(64) NOT NULL default '',
	status varchar(20) NOT NULL default 'unmatched',
	policy varchar(20) NOT NULL default '',
	last_count_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY sku (sku),
	KEY gtin (gtin),
	KEY status (status),
	KEY store_ref (store_ref)
) {$collate};";

		$sql[] = "CREATE TABLE {$events} (
	id bigint(20) unsigned NOT NULL auto_increment,
	connection_id bigint(20) unsigned NOT NULL default 0,
	external_id varchar(100) NOT NULL default '',
	type varchar(20) NOT NULL default 'sale',
	state varchar(20) NOT NULL default 'pending',
	occurred_at datetime NOT NULL default '0000-00-00 00:00:00',
	received_at datetime NOT NULL default '0000-00-00 00:00:00',
	applied_at datetime default NULL,
	location_ref varchar(100) NOT NULL default '',
	payload longtext,
	result longtext,
	error text,
	attempts smallint(5) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY connection_external (connection_id,external_id),
	KEY state_occurred (state,occurred_at),
	KEY occurred_at (occurred_at),
	KEY type (type),
	KEY received_at (received_at)
) {$collate};";

		// One row per till or integration. Separate from events so a connection
		// can be rotated, revoked or renamed without touching a shop's audit
		// trail — and so a misbehaving terminal is identifiable by name rather
		// than by a bare id in the log.
		$sql[] = "CREATE TABLE {$connections} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	type varchar(30) NOT NULL default 'generic',
	api_key varchar(64) NOT NULL default '',
	secret longtext,
	credentials longtext,
	mode varchar(10) NOT NULL default 'test',
	scopes varchar(255) NOT NULL default '',
	location_ref varchar(100) NOT NULL default '',
	status varchar(20) NOT NULL default 'active',
	last_seen_at datetime default NULL,
	last_skew smallint(6) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY api_key (api_key),
	KEY status (status),
	KEY type (type)
) {$collate};";

		$sql[] = "CREATE TABLE {$map} (
	id bigint(20) unsigned NOT NULL auto_increment,
	connection_id bigint(20) unsigned NOT NULL default 0,
	entity varchar(20) NOT NULL default 'item',
	external_id varchar(100) NOT NULL default '',
	local_id varchar(64) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY connection_entity_external (connection_id,entity,external_id),
	KEY local_id (local_id)
) {$collate};";

		return $sql;
	}

	/**
	 * Destructive / data-shape changes dbDelta cannot express.
	 *
	 * Each step is guarded by the version it upgrades FROM, so a site three
	 * versions behind runs every step in order. Empty on the first release —
	 * the shape of the thing, ready for the first change that needs it.
	 *
	 * @param string $from The stored version before this install ran.
	 */
	private static function migrate( $from ) {
		if ( '' === $from ) {
			return; // Fresh install; the schema above is already current.
		}

		// 1.1.0 added the connections table. dbDelta creates it above, so there
		// is nothing destructive to do here — but events recorded by 1.0.x carry
		// connection_id 0, which is correct: they predate connections entirely
		// and must not be retro-attributed to whichever one is created first.
		//
		// 1.2.0 added connections.credentials. Also additive — dbDelta handles
		// it — and existing generic connections correctly have it NULL, since a
		// generic webhook has no provider tokens.
		//
		// 1.3.0 added items.policy. Additive; existing rows correctly default to
		// empty, which means "follow the site setting".
		//
		// Future destructive steps take this shape:
		//
		// if ( version_compare( $from, '1.2.0', '<' ) ) {
		//     global $wpdb;
		//     $wpdb->query( 'ALTER TABLE ' . self::table( 'events' ) . ' DROP COLUMN legacy_col' );
		// }
	}

	/**
	 * Drop every table and forget the version.
	 *
	 * ONLY the explicit, opt-in "Remove all data" action may call this. It is
	 * never wired to deactivation: turning an extension off to test something
	 * must not destroy a shop's audit trail.
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( [ 'events', 'map', 'items', 'connections' ] as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Are the tables actually present?
	 *
	 * Cheap sanity check for the admin screen — a site restored from a partial
	 * backup can carry the version option without the tables.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		global $wpdb;

		$table = self::table( 'events' );

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
