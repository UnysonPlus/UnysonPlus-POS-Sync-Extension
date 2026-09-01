# POS Sync — working rules

Keeps the online store in step with sales rung up on a physical till. Read this before
editing anything in this folder.

Published design of record: <https://docs.unysonplus.com/extensions/pos-sync/architecture>
Roadmap (auto-derived from this source tree): <https://docs.unysonplus.com/extensions/pos-sync/roadmap>

## The layering is the design — do not shortcut it

```
Schema      includes/class-fw-pos-schema.php     — the ONLY DDL
Ledger      includes/class-fw-pos-ledger.php     — the ONLY SQL
Queue       includes/class-fw-pos-queue.php      — ordering, retries, the apply filter
Matcher     includes/class-fw-pos-matcher.php    — SKU/GTIN resolution, unmatched queue
Applier     includes/class-fw-pos-applier.php    — events → store writes
Stores      includes/stores/…                    — the ONLY cart-specific code
Log/Admin   includes/class-fw-pos-log*.php · class-fw-pos-items-table.php · views/log.php
```

Three rules keep it honest, and every future milestone depends on them:

- **Nothing above the ledger writes SQL.** Need a new query? Add a ledger method. Never
  `$wpdb->prepare()` in the queue, the applier, an admin class, or a driver.
- **The ledger holds no business rules and fires no hooks.** It is dumb on purpose, so a
  batch operation can call it in a loop with no side effects.
- **Nothing outside `stores/` knows which cart is installed.** No `wc_*` call, no
  `WC_Product`, no post ID with implied meaning may appear above the seam.

## Why this is not part of the WooCommerce extension

With **N** POS providers and **M** e-commerce plugins, POS logic living inside a
Woo-specific extension means every provider driver is rewritten to add a second cart —
O(N×M). The normalized ledger in the middle makes it O(N+M): POS drivers only write to
it, store drivers only read from it, neither knows the other exists.

If you are ever tempted to reach into `wc_*` from the ledger or the queue, that is the
line being crossed. The store driver seam (Milestone 2) is the only place cart-specific
code belongs.

## Schema discipline

`dbDelta` is picky by contract — **two spaces after `PRIMARY KEY`**, one field per line,
lowercase type keywords, `KEY` never `INDEX`, and the collation from
`$wpdb->get_charset_collate()`. Break any of those and it silently re-runs `ALTER`s on
every load. (The Newsletter CRM's AGENTS.md has the longer version; same rules.)

- Guarded by `fw_ext_pos_sync_db_version`, compared against `FW_POS_Schema::DB_VERSION`.
  **Bump that constant whenever you change the schema**, or the change never reaches an
  existing site.
- The check runs on every `_init()` — one autoloaded `get_option()` when current — so
  activation *and* plugin-update upgrades self-heal with no activation hook to miss.
- `dbDelta` adds columns and indexes; it **never drops or renames**. Anything destructive
  is a numbered, version-guarded block in `FW_POS_Schema::migrate()`.
- `$wpdb->prefix` (per-site), **not** `base_prefix` — each site on a network owns its own
  till data.
- **`external_id` is `varchar(100)`, and that is not arbitrary.** The UNIQUE index on
  (connection_id, external_id) must fit inside InnoDB's 767-byte key limit under utf8mb4:
  `8 + 190*4 = 768` overflows by one byte, `8 + 100*4 = 408` does not. Widening this
  column breaks the index that the entire idempotency guarantee rests on.
- **Deactivating never drops a table.** `FW_POS_Schema::uninstall()` is only ever called
  by an explicit, opt-in "Remove all data" action.

## Things that will bite you if you change them

- **`record_event()` INSERTs first and lets the unique index refuse a replay.** Do not
  "optimise" it into a SELECT-then-INSERT: two webhook deliveries racing each other both
  pass the SELECT and both insert. `is_duplicate()` exists for callers that want to answer
  early — it is **not** the idempotency mechanism and must never guard an insert.
- **A duplicate is a SUCCESS.** Return `200`, not an error. A POS that gets an error back
  retries forever, which is precisely the storm the unique index exists to absorb.
- **`claim_batch()` orders by `occurred_at`, never `id`.** `id` is arrival order by another
  name. A till that drops its connection at 09:00 and reconnects at 17:00 delivers its
  backlog after the whole afternoon; processing by arrival lets a 09:15 count of 12 land on
  top of an accurate 16:45 count of 3.
- **`occurred_at` and `received_at` are different concepts** and neither may be used for
  the other's job. `occurred_at` is the ordering key; `received_at` is diagnostics only.
- **Absolute counts can go stale; relative adjustments cannot.** A relative adjustment
  describes a change, and changes commute. An absolute count describes a state, and an
  older state must never replace a newer one — which is why `FW_POS_Queue::stale_reason()`
  only applies to `mode: absolute`. Keep the two distinguishable on the wire.
- **Timestamps are stored UTC and displayed local.** Showing raw UTC to a shop owner
  comparing the log against their till's report manufactures a phantom bug every time.
- **A skipped event is not an error.** Three of the four terminal states are successes;
  only `failed` wants attention. The badge colours and `FW_POS_Log::explain()` say so
  deliberately — a merchant who reads "skipped" as "broken" files a bug about correct
  behaviour.
- **Never drop an event you cannot apply.** No driver, unmatched SKU, test mode — all of
  them record a *reason* and stay in the log. An empty log and a log full of explained
  skips are very different support conversations.
- **`fw_pos_apply_event` returning `retry: false` means "decision", not "fault".** An
  unmatched SKU will still be unmatched in five minutes; retrying it five times just fills
  the log.

## The store seam

`FW_POS_Store` is the contract; `FW_POS_Stores` resolves exactly one active driver;
`FW_POS_Applier` is its only consumer and the sole implementation of the queue's
`fw_pos_apply_event` filter.

**Everything crossing the seam is a primitive.** A SKU string, an integer quantity, an
opaque `store_ref` (`product:42`, `variation:87`) only the issuing driver has to
understand. The moment a `WC_Product` crosses that line the abstraction is over.

**Why `find_by_sku()` returns a string, not an int.** A post ID is a WooCommerce-shaped
answer. The interface was drafted against FluentCart too, whose items are rows in its own
tables, and an int would not have survived that. Same reason `get_capabilities()` exists
at all — carts genuinely differ, and the ledger must degrade rather than fatal.

To add a driver: extend `FW_POS_Store`, implement all nine methods, register through
`fw_pos_store_drivers`. **Declare capabilities honestly** — claiming `partial_refunds` you
cannot deliver produces silently wrong refunds, which is strictly worse than declaring
`false` and having the refund skipped with a legible reason.

### Rules the applier enforces, which a driver must not undo

- **`retry: true` means transient, `retry: false` means a decision.** "The cart is down"
  is worth retrying; "this SKU does not exist" will be just as true in five minutes and
  retrying it five times only fills the log.
- **`stock_not_managed` is not a failure.** Plenty of catalogs deliberately do not track
  stock on some products. Treating it as an error retries forever.
- **An event with any unresolvable line is skipped WHOLE.** Half a sale leaves stock wrong
  in a way nobody can see.
- **Order creation is opt-in.** A shop's POS already reports its takings; mirroring every
  counter sale into the cart double-counts revenue and buries online orders among walk-ins.

### Matching

SKU first, GTIN second, **never name**. Two products called "Blue Hoodie" would swap stock
with nothing in the log to say it happened.

- **Unmatched items are queued, never auto-created.** Inventing products from till data
  fills a catalog with `MISC-1` within days, and the merchant then cleans up a mess that
  looks like their own doing.
- **A human mapping beats a fresh lookup.** Someone bound that SKU to a specific product
  for a reason; re-deriving it every event would quietly undo them.
- **`ignored` wins over everything.** It is how carrier bags and service charges stop
  filling the queue.
- **Mapping a SKU re-queues what it blocked** (`FW_POS_Ledger::requeue_skipped()`). That
  payoff is the whole reason skipped events keep their payload.

## Testing

There is no POS hardware and there does not need to be. Milestone 4 ships the Virtual
Terminal; until then the ledger and queue are directly testable — they have no POS and no
cart dependency, which is the point of building this layer first.

Two suites, both safe to re-run and both leaving the site as they found it:

```bash
php wp-cli.phar --path='<a WordPress install>' \
  eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-1.php
php wp-cli.phar --path='<a WordPress install>' \
  eval-file wp-content/plugins/unysonplus/framework/extensions/pos-sync/tests/milestone-2.php
```

- **`milestone-1.php`** — 36 assertions: idempotency, ordering, staleness, retry policy,
  log helpers.
- **`milestone-2.php`** — 44 assertions: the seam, matching, applying, atomicity, retry
  classification, test mode, recovery. It runs against a **fake in-memory driver, not
  WooCommerce**, which is the point: everything above the seam is cart-agnostic, so its
  tests must not need a cart. The fake also makes capability negotiation and store-write
  failures producible on demand.

> **wp-cli scoping.** Both suites publish what their helpers need through `$GLOBALS`.
> `wp eval-file` runs a file *inside a function*, so top-level variables are locals and a
> `global $x` in a helper finds nothing. This bit both suites once — silently in the first
> (a tally stuck at 0/0) and as a fatal in the second.

**Run it after any change to the ledger or the queue.** Two of its cases are worth
understanding before editing either, because they look like the same rule and are not:

- *Same batch* — two counts waiting together are applied oldest-first by `claim_batch()`,
  so the newer value simply lands last. That is the **ordering** rule.
- *Across batches* — the newer count was already applied when the offline till finally
  delivers the older one, so ordering cannot help and the older count must be refused on
  its own merits. That is the **staleness** rule.

Both are needed. An early version of the test only covered the first case and appeared to
prove the second.

The adversarial cases that matter are listed at
<https://docs.unysonplus.com/extensions/pos-sync/testing#adversarial-scenarios>. A change
to the queue or the ledger should be checked against **duplicate webhook**,
**out-of-order batch** and **stale absolute count** at minimum.

## Version marker

`manifest.php` → `$manifest['version']`. Bump on every meaningful change. The **core**
plugin version bumps separately, once per confirmed milestone, because it is what actually
ships an update to a live site.
