<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The ledger repository. The ONLY class that writes SQL against the event and
 * item tables.
 *
 * Nothing above this class writes SQL, and this class holds no business rules
 * and fires no hooks — it is deliberately dumb, so a batch operation can call
 * it in a loop with no side effects. Business rules live in the queue and (from
 * Milestone 2) the store drivers.
 *
 * Event lifecycle:
 *
 *   pending ─┬─► applied     the store driver wrote it
 *            ├─► duplicate   already seen; the UNIQUE index refused the insert
 *            ├─► skipped     deliberately not applied (test mode, stale count,
 *            │               unmatched SKU, no store driver) — reason recorded
 *            └─► failed      the driver threw, after every retry
 *
 * `duplicate` and `skipped` are SUCCESSFUL outcomes. Only `failed` wants
 * attention, which is why the log filters on state and the health dashboard
 * (Milestone 6) counts them separately.
 */
class FW_POS_Ledger {

	/** Event states. */
	const STATE_PENDING   = 'pending';
	const STATE_APPLIED   = 'applied';
	const STATE_DUPLICATE = 'duplicate';
	const STATE_SKIPPED   = 'skipped';
	const STATE_FAILED    = 'failed';

	/** Event types. */
	const TYPE_SALE      = 'sale';
	const TYPE_REFUND    = 'refund';
	const TYPE_VOID      = 'void';
	const TYPE_INVENTORY = 'inventory';

	/**
	 * The states an event can be in and still be worth processing.
	 *
	 * @return string[]
	 */
	public static function open_states() {
		return [ self::STATE_PENDING ];
	}

	/* ---------------------------------------------------------------------- *
	 * Writing
	 * ---------------------------------------------------------------------- */

	/**
	 * Record an inbound event.
	 *
	 * This is the idempotency point, and it works by INSERTING FIRST and letting
	 * the UNIQUE (connection_id, external_id) index refuse a replay. It is
	 * deliberately not a SELECT-then-INSERT: two webhook deliveries racing each
	 * other would both pass the SELECT and both insert. Handing the decision to
	 * the database makes it atomic and impossible to forget.
	 *
	 * Every caller must treat a `duplicate` result as SUCCESS — a POS that gets
	 * an error back keeps retrying forever.
	 *
	 * @param array $event {
	 *     @type int    $connection_id
	 *     @type string $external_id   The POS's own transaction id. Required.
	 *     @type string $type          sale|refund|void|inventory
	 *     @type string $occurred_at   MySQL datetime, UTC. When it happened at the till.
	 *     @type string $location_ref
	 *     @type array  $payload       The normalized request, stored verbatim.
	 * }
	 *
	 * @return array {
	 *     @type bool     $ok
	 *     @type int|null $event_id
	 *     @type bool     $duplicate
	 *     @type string   $state
	 * }
	 */
	public static function record_event( array $event ) {
		global $wpdb;

		$external_id = isset( $event['external_id'] ) ? substr( (string) $event['external_id'], 0, 100 ) : '';

		if ( '' === $external_id ) {
			return [
				'ok'        => false,
				'event_id'  => null,
				'duplicate' => false,
				'state'     => self::STATE_FAILED,
				'error'     => 'missing_external_id',
			];
		}

		$now     = current_time( 'mysql', true );
		$payload = isset( $event['payload'] ) ? wp_json_encode( $event['payload'] ) : '';

		// Suppress the duplicate-key warning: a replay is expected traffic, not
		// an error worth writing to the PHP log on every retry.
		$suppress = $wpdb->suppress_errors( true );

		$inserted = $wpdb->insert(
			FW_POS_Schema::table( 'events' ),
			[
				'connection_id' => isset( $event['connection_id'] ) ? (int) $event['connection_id'] : 0,
				'external_id'   => $external_id,
				'type'          => isset( $event['type'] ) ? substr( (string) $event['type'], 0, 20 ) : self::TYPE_SALE,
				'state'         => self::STATE_PENDING,
				'occurred_at'   => self::normalize_datetime( isset( $event['occurred_at'] ) ? $event['occurred_at'] : $now ),
				'received_at'   => $now,
				'location_ref'  => isset( $event['location_ref'] ) ? substr( (string) $event['location_ref'], 0, 100 ) : '',
				'payload'       => $payload,
				'attempts'      => 0,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			// Either a duplicate (the expected case) or a genuine DB failure.
			$existing = self::find_by_external_id(
				isset( $event['connection_id'] ) ? (int) $event['connection_id'] : 0,
				$external_id
			);

			if ( $existing ) {
				return [
					'ok'        => true,
					'event_id'  => (int) $existing['id'],
					'duplicate' => true,
					'state'     => (string) $existing['state'],
				];
			}

			return [
				'ok'        => false,
				'event_id'  => null,
				'duplicate' => false,
				'state'     => self::STATE_FAILED,
				'error'     => 'insert_failed',
			];
		}

		return [
			'ok'        => true,
			'event_id'  => (int) $wpdb->insert_id,
			'duplicate' => false,
			'state'     => self::STATE_PENDING,
		];
	}

	/**
	 * Move an event to a terminal state.
	 *
	 * @param int         $event_id
	 * @param string      $state  One of the STATE_* constants.
	 * @param array|null  $result Structured outcome (stock before/after, order ref).
	 * @param string|null $error  Human-readable reason for skipped/failed.
	 *
	 * @return bool
	 */
	public static function set_state( $event_id, $state, $result = null, $error = null ) {
		global $wpdb;

		$data   = [ 'state' => $state ];
		$format = [ '%s' ];

		if ( self::STATE_APPLIED === $state ) {
			$data['applied_at'] = current_time( 'mysql', true );
			$format[]           = '%s';
		}

		if ( null !== $result ) {
			$data['result'] = wp_json_encode( $result );
			$format[]       = '%s';
		}

		if ( null !== $error ) {
			$data['error'] = (string) $error;
			$format[]      = '%s';
		}

		return (bool) $wpdb->update(
			FW_POS_Schema::table( 'events' ),
			$data,
			[ 'id' => (int) $event_id ],
			$format,
			[ '%d' ]
		);
	}

	/**
	 * Increment the attempt counter and return the new value.
	 *
	 * @param int $event_id
	 *
	 * @return int
	 */
	public static function bump_attempts( $event_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $event_id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", (int) $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Reading
	 * ---------------------------------------------------------------------- */

	/**
	 * Has this connection already sent this transaction?
	 *
	 * Convenience for callers that want to answer early (a REST endpoint
	 * short-circuiting before it does any work). It is NOT the idempotency
	 * mechanism — `record_event()` is, because only the unique index is
	 * race-free. Never use this as a guard before an insert.
	 *
	 * @param int    $connection_id
	 * @param string $external_id
	 *
	 * @return bool
	 */
	public static function is_duplicate( $connection_id, $external_id ) {
		return null !== self::find_by_external_id( $connection_id, $external_id );
	}

	/**
	 * @param int    $connection_id
	 * @param string $external_id
	 *
	 * @return array|null
	 */
	public static function find_by_external_id( $connection_id, $external_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connection_id = %d AND external_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $connection_id,
				(string) $external_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * @param int $event_id
	 *
	 * @return array|null
	 */
	public static function get_event( $event_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * The next batch of events to process, **in `occurred_at` order**.
	 *
	 * The ordering is the whole point and is not a detail to tidy up later. A
	 * till that loses its connection at 09:00 and reconnects at 17:00 delivers
	 * its backlog after the entire afternoon. Processing by arrival (or by `id`,
	 * which is arrival by another name) lets a 09:15 stock count of 12 land on
	 * top of an accurate 16:45 count of 3. Ordering by event time is what makes
	 * the stale value identifiable and refusable.
	 *
	 * `id` is the tie-breaker so the order is total and the batch is stable.
	 *
	 * @param int $limit
	 *
	 * @return array[]
	 */
	public static function claim_batch( $limit = 20 ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state = %s ORDER BY occurred_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATE_PENDING,
				(int) $limit
			),
			ARRAY_A
		);
	}

	/**
	 * How many events are waiting.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", self::STATE_PENDING ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Event rows for the log screen.
	 *
	 * @param array $args {
	 *     @type string $state
	 *     @type string $type
	 *     @type int    $connection_id
	 *     @type string $search   Matches external_id or location_ref.
	 *     @type int    $per_page
	 *     @type int    $page
	 * }
	 *
	 * @return array[]
	 */
	public static function query_events( array $args = [] ) {
		global $wpdb;

		$table  = FW_POS_Schema::table( 'events' );
		$where  = self::build_where( $args );
		$limit  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		// Newest first here — the log is read by humans looking for what just
		// happened, which is the opposite of the queue's processing order.
		$sql = "SELECT * FROM {$table} {$where['sql']} ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d";

		$params   = $where['params'];
		$params[] = $limit;
		$params[] = $offset;

		return (array) $wpdb->get_results(
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * @param array $args Same shape as query_events().
	 *
	 * @return int
	 */
	public static function count_events( array $args = [] ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );
		$where = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( empty( $where['params'] ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Event counts keyed by state, for the log's status links and the health
	 * dashboard.
	 *
	 * @return array<string,int>
	 */
	public static function state_counts() {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		$rows = (array) $wpdb->get_results(
			"SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ $row['state'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------- *
	 * Items
	 * ---------------------------------------------------------------------- */

	/**
	 * When did we last apply an ABSOLUTE count for this SKU?
	 *
	 * The queue compares an incoming absolute count against this to refuse a
	 * stale one. Relative adjustments do not consult it — they commute, so they
	 * are safe to apply in any order, which is exactly why the two modes are
	 * distinguished on the wire.
	 *
	 * @param string $sku
	 *
	 * @return string|null MySQL datetime, or null if never.
	 */
	public static function last_count_at( $sku ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_count_at FROM {$table} WHERE sku = %s ORDER BY last_count_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				(string) $sku
			)
		);

		return $value ? $value : null;
	}

	/**
	 * Record that an absolute count was applied for a SKU at a given event time.
	 *
	 * @param string $sku
	 * @param string $occurred_at MySQL datetime.
	 *
	 * @return void
	 */
	public static function touch_count( $sku, $occurred_at ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );
		$now   = current_time( 'mysql', true );

		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE sku = %s LIMIT 1", (string) $sku ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		if ( $id ) {
			$wpdb->update(
				$table,
				[
					'last_count_at' => $occurred_at,
					'updated_at'    => $now,
				],
				[ 'id' => (int) $id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			return;
		}

		$wpdb->insert(
			$table,
			[
				'sku'           => (string) $sku,
				'status'        => 'unmatched',
				'last_count_at' => $occurred_at,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/** Item statuses. */
	const ITEM_UNMATCHED = 'unmatched';
	const ITEM_MATCHED   = 'matched';
	const ITEM_IGNORED   = 'ignored';

	/**
	 * Record an item seen at the till, or refresh what we know about it.
	 *
	 * Keyed on SKU, which is the only identifier both systems reliably share.
	 * An existing row's `status` and `store_ref` are never overwritten here — a
	 * mapping a human made, or an item they deliberately ignored, must survive
	 * the next sale mentioning it.
	 *
	 * @param array $item {
	 *     @type string $sku  Required.
	 *     @type string $gtin
	 *     @type string $name
	 * }
	 *
	 * @return int|null Item id, or null when there is no SKU to key on.
	 */
	public static function upsert_item( array $item ) {
		global $wpdb;

		$sku = isset( $item['sku'] ) ? substr( (string) $item['sku'], 0, 100 ) : '';

		if ( '' === $sku ) {
			return null;
		}

		$table = FW_POS_Schema::table( 'items' );
		$now   = current_time( 'mysql', true );

		$existing = self::get_item_by_sku( $sku );

		if ( $existing ) {
			$update = [ 'updated_at' => $now ];
			$format = [ '%s' ];

			// Fill in blanks only — never clobber what is already known.
			foreach ( [ 'gtin', 'name' ] as $key ) {
				if ( '' === (string) $existing[ $key ] && ! empty( $item[ $key ] ) ) {
					$update[ $key ] = (string) $item[ $key ];
					$format[]       = '%s';
				}
			}

			$wpdb->update( $table, $update, [ 'id' => (int) $existing['id'] ], $format, [ '%d' ] );

			return (int) $existing['id'];
		}

		$wpdb->insert(
			$table,
			[
				'sku'        => $sku,
				'gtin'       => isset( $item['gtin'] ) ? substr( (string) $item['gtin'], 0, 64 ) : '',
				'name'       => isset( $item['name'] ) ? substr( (string) $item['name'], 0, 190 ) : '',
				'store_ref'  => '',
				'status'     => self::ITEM_UNMATCHED,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $sku
	 *
	 * @return array|null
	 */
	public static function get_item_by_sku( $sku ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sku = %s LIMIT 1", (string) $sku ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * @param int $item_id
	 *
	 * @return array|null
	 */
	public static function get_item( $item_id ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $item_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Bind an item to a store product, or clear the binding.
	 *
	 * @param int    $item_id
	 * @param string $store_ref Opaque driver reference, or '' to unmatch.
	 *
	 * @return bool
	 */
	public static function set_item_match( $item_id, $store_ref ) {
		global $wpdb;

		$store_ref = (string) $store_ref;

		return (bool) $wpdb->update(
			FW_POS_Schema::table( 'items' ),
			[
				'store_ref'  => $store_ref,
				'status'     => '' === $store_ref ? self::ITEM_UNMATCHED : self::ITEM_MATCHED,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $item_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Mark an item as deliberately not a stock item.
	 *
	 * Every real shop rings up things that are not products — a carrier bag, a
	 * service charge, a discount line. Without this they would sit in the
	 * unmatched queue forever and train people to ignore it.
	 *
	 * @param int $item_id
	 *
	 * @return bool
	 */
	public static function ignore_item( $item_id ) {
		global $wpdb;

		return (bool) $wpdb->update(
			FW_POS_Schema::table( 'items' ),
			[
				'status'     => self::ITEM_IGNORED,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $item_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array $args {
	 *     @type string $status
	 *     @type string $search   Matches sku, gtin or name.
	 *     @type int    $per_page
	 *     @type int    $page
	 * }
	 *
	 * @return array[]
	 */
	public static function query_items( array $args = [] ) {
		global $wpdb;

		$table  = FW_POS_Schema::table( 'items' );
		$where  = self::build_item_where( $args );
		$limit  = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		$sql      = "SELECT * FROM {$table} {$where['sql']} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$params   = $where['params'];
		$params[] = $limit;
		$params[] = $offset;

		return (array) $wpdb->get_results(
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * @param array $args Same shape as query_items().
	 *
	 * @return int
	 */
	public static function count_items( array $args = [] ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );
		$where = self::build_item_where( $args );
		$sql   = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( empty( $where['params'] ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Item counts keyed by status, for the Unmatched screen's status links.
	 *
	 * @return array<string,int>
	 */
	public static function item_status_counts() {
		global $wpdb;

		$table = FW_POS_Schema::table( 'items' );

		$rows = (array) $wpdb->get_results(
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Re-open every event that was skipped for a given reason, so it can be
	 * processed again.
	 *
	 * This is what makes "skip loudly instead of dropping" pay off: once a
	 * store driver is connected, or an unmatched SKU is mapped, the events that
	 * could not be applied before are still here and still replayable.
	 *
	 * @param string $reason_prefix e.g. 'no_store_driver', 'unmatched_sku'
	 *
	 * @return int Rows re-queued.
	 */
	public static function requeue_skipped( $reason_prefix ) {
		global $wpdb;

		$table = FW_POS_Schema::table( 'events' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = %s, error = NULL, attempts = 0 WHERE state = %s AND error LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
				self::STATE_PENDING,
				self::STATE_SKIPPED,
				$wpdb->esc_like( (string) $reason_prefix ) . '%'
			)
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Shared WHERE builder for query_items()/count_items().
	 *
	 * @param array $args
	 *
	 * @return array{sql:string,params:array}
	 */
	private static function build_item_where( array $args ) {
		$clauses = [];
		$params  = [];

		if ( ! empty( $args['status'] ) ) {
			$clauses[] = 'status = %s';
			$params[]  = (string) $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$clauses[] = '(sku LIKE %s OR gtin LIKE %s OR name LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		return [
			'sql'    => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'params' => $params,
		];
	}

	/**
	 * Shared WHERE builder for query_events()/count_events(), so the two can
	 * never disagree about what a filtered page contains.
	 *
	 * @param array $args
	 *
	 * @return array{sql:string,params:array}
	 */
	private static function build_where( array $args ) {
		$clauses = [];
		$params  = [];

		if ( ! empty( $args['state'] ) ) {
			$clauses[] = 'state = %s';
			$params[]  = (string) $args['state'];
		}

		if ( ! empty( $args['type'] ) ) {
			$clauses[] = 'type = %s';
			$params[]  = (string) $args['type'];
		}

		if ( ! empty( $args['connection_id'] ) ) {
			$clauses[] = 'connection_id = %d';
			$params[]  = (int) $args['connection_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			global $wpdb;

			$like      = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$clauses[] = '(external_id LIKE %s OR location_ref LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		return [
			'sql'    => $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '',
			'params' => $params,
		];
	}

	/**
	 * Coerce an incoming timestamp to a UTC MySQL datetime.
	 *
	 * A POS may send ISO 8601 with an offset, ISO without one, or a Unix
	 * timestamp. An ISO string with NO offset is ambiguous, and ambiguity in the
	 * ordering key is what causes stock to rewind — so it is read as UTC and the
	 * sender is expected to send an offset. The webhook layer (Milestone 3)
	 * rejects offset-less timestamps outright at the schema level; this is the
	 * defensive floor beneath it.
	 *
	 * @param mixed $value
	 *
	 * @return string MySQL datetime in UTC.
	 */
	public static function normalize_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		$value = (string) $value;

		if ( '' === $value ) {
			return current_time( 'mysql', true );
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
