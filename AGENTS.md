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
Connections includes/class-fw-pos-connections.php — credentials; owns its own table
REST        includes/rest/…                      — the ONLY public surface
Simulator   includes/class-fw-pos-simulator.php  — fires signed events at our own endpoint
Providers   includes/providers/…                 — vendor drivers; each signs its own way
Policy      includes/class-fw-pos-policy.php     — who owns which field
Reconciler  includes/class-fw-pos-reconciler.php — the nightly "what did we miss?" sweep
Health      includes/class-fw-pos-health.php     — metrics, retention, the silence alarm
Log/Admin   includes/class-fw-pos-log*.php · class-fw-pos-items-table.php · views/log.php
```

Three rules keep it honest, and every future milestone depends on them:

- **Nothing outside a repository writes SQL.** `FW_POS_Ledger` owns the events and items
  tables; `FW_POS_Connections` owns the connections table. Need a new query? Add a method
  to the repository that owns that table. Never `$wpdb->prepare()` in the queue, the
  applier, the REST controller, an admin class, or a driver.
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

## The webhook API

`FW_POS_REST_Controller` is the only public surface. It does as little as possible —
verify, validate, write one row, return `202` — and everything else happens on the queue.

- **Sign over the RAW body.** `$request->get_body()` is the bytes as received. Using
  `get_json_params()` and re-encoding changes key order and whitespace, and then no
  correctly signed request verifies. This is the single most common integration bug on
  both sides of the wire.
- **The signing string is `{timestamp}\n{raw_body}`.** No method, no URL, no header
  canonicalisation — every one of those is a place two implementations can disagree.
- **Compare with `hash_equals()`.** A `===` returns faster on an early mismatch and leaks
  the signature a byte at a time to anyone patient enough to measure.
- **A duplicate is `200`, not an error.** A POS that gets an error back retries forever.
- **`400` for a bad payload, and never retry it** — it will be just as wrong next time.
  The error names the exact path (`line_items[1].quantity`) because "invalid payload"
  sends an integrator hunting.
- **`occurred_at` must carry an offset**, enforced by the schema pattern. It is the
  ordering key, and an ambiguous ordering key is what rewinds stock.
- **There is deliberately NO replay/nonce cache.** An earlier version had one and it was
  removed. Every route is idempotent by construction, so replaying a captured request is a
  no-op — the cache added no protection. What it did add was a `401` for senders that sign
  once and re-send identical bytes (GitHub-style redelivery), turning a working retry into
  an auth error. Do not reintroduce it without solving that.

### Secrets are ENCRYPTED, not hashed

The instinct is to hash a stored credential, and for a password that is right. An HMAC
shared secret is different: verifying a signature means *recomputing* it, which needs the
original bytes. A hash would make every request fail.

`FW_POS_Secrets` encrypts with a key derived from `wp_salt()`, which lives in
`wp-config.php` rather than the database. That protects a leaked backup, an SQL injection
or a DB dump handed to a contractor. It does **not** protect against filesystem
compromise — anyone who can read `wp-config.php` can decrypt everything. Say so; do not
imply a vault.

Rotating the salts makes existing secrets undecryptable, and every connection must be
re-issued. That is the correct failure: closed, loud, and at the point of use.

### Connections

One per till. Rotating changes the **secret** but not the **key**, so a compromised
credential is one field to change at the till; revoking is the operation that kills the
key. A revoked connection keeps its events — a revoked till's history is exactly what you
want to look at next.

`FW_POS_Connections::is_live()` requires **both** the global mode and the connection's own
to say live. The global one is a master switch, so someone flipping the site to test to
investigate a problem does not have to remember which of six tills is set individually.

### The validator

`FW_POS_Validator` implements the JSON Schema subset the three documents use, because
WordPress ships no such library and one more dependency to maintain forever is worse.
Unknown keywords are ignored, as JSON Schema specifies — so if you add a keyword to a
schema, **add it to the validator too**, or it silently validates nothing.

## The Virtual Terminal

`FW_POS_Simulator` composes signed events and fires them at our own endpoint. It is a
shipping feature — the merchant's pre-launch check and the support-reproduction tool — not
a dev-only harness.

- **Two transports, and they are not interchangeable.** `internal` (`rest_do_request()`)
  proves the handler; `http` (`wp_remote_post()`) proves the whole path. In-process passes
  on a site where a security plugin blocks `/wp-json/`, so `http` is the default and the
  UI says why. Never collapse them into one.
- **It can sign because secrets are recoverable.** If the secret were hashed this screen
  could not exist — which is a decent sanity check on that decision.
- **Scenarios declare their expectation and the runner checks it.** A self-test that always
  passes is worse than no self-test; `milestone-4.php` deliberately breaks a behaviour and
  asserts the scenario notices.
- **The cURL export carries a placeholder secret, never the real one.** Copyable text ends
  up in chat logs.

## The provider seam

`FW_POS_Provider` is the mirror of `FW_POS_Store`. A provider turns one vendor's webhook
into normalized ledger events; it never touches a cart. A store driver applies events; it
never knows which till sent them.

- **Every vendor signs differently, and that is fine.** `verify_webhook()` is the
  provider's problem. What is fixed is the *output* — `normalize()` returns events in the
  same shape the generic endpoint produces, so everything downstream is identical whether
  the event came from Square or a shell script.
- **Zero events is a SUCCESS.** Vendors send many event types we did not subscribe to.
  Answering anything but 2xx makes them retry an event we will never want.
- **`backfill()` and `import_catalog()` are concrete with no-op defaults**, same reasoning
  as `search_products()`: a provider that can only receive webhooks must be implementable
  without stubs that throw.
- **Provider credentials live in `connections.credentials`**, encrypted, separate from
  `secret`. One is our shared secret that we rotate; the other is someone else's tokens on
  their expiry schedule. `store_credentials()` **merges** rather than replaces, because a
  refresh often returns no new refresh token and a replace would strand the connection.

### Square — things that will cost you an afternoon

- **SKUs are on the `ITEM_VARIATION`, not the `ITEM`.** An import that walks items finds
  almost no SKUs and looks like an empty catalog. Order lines and inventory counts both
  reference the *variation* id, which is why the map is keyed on it.
- **Square signs `notification_url + body`, base64.** Not our scheme. The URL is part of
  the signature, so a trailing slash, a different host, or a proxy rewriting the host makes
  every delivery fail — and it looks exactly like a wrong signature key. Generate the URL
  through `FW_POS_REST_Controller::webhook_url()` so the admin screen and the verifier
  cannot disagree.
- **Payments and orders arrive separately.** `payment.created` has the money, the Order has
  the items. A payment is resolved against its order first; one with no resolvable order is
  skipped rather than recorded as a sale of nothing.
- **Only `IN_STOCK` is a level.** `SOLD`, `WASTE` and `IN_TRANSIT` are movements between
  states and would double-count against it.
- **Modifiers are not stock items.** "Oat milk" on a coffee would otherwise create a
  phantom unmatched entry for every coffee sold.
- **A declined payment must move no stock** — only `COMPLETED`.
- **Multi-location is normal even for one shop**, because Square creates an online location
  unasked. Never take "the first location". An unmapped location passes its raw Square id
  through to the log on purpose: visibly wrong beats plausibly wrong.
- **Sandbox and production ids are disjoint.** Use separate connections, not a toggle.

## Operations

### Reconciliation reports; it does not silently fix

A sweep that quietly corrected differences would hide the fact that events are being lost,
which is the more important problem. So the nightly run produces a report and applying it
is a separate action.

**Corrections go through `record_event()`, never straight to the cart.** They carry the
report's own `ran_at` as `occurred_at`, so a reconciliation older than a genuine count that
has since landed is refused by the ordering rule. Applying a stale reconciliation would be
this layer causing the exact failure it exists to prevent — `milestone-6.php` asserts it
cannot.

Three things are deliberately **not** reported as drift, and re-adding any of them would
train people to ignore the report:

- **Unmatched items** — already surfaced on their own screen.
- **Store-owned items** — differing is the whole point of the override.
- **Products with stock management off** — there is no number to compare.

`store_quantity()` reads the current level through `adjust_stock( $ref, 0 )`, which is
defined as a no-op that reports the level. Adding a `get_stock()` to the seam for one
caller would have forced every future driver to implement it.

### The alarm is silence, not errors

A POS integration's worst failure is a till that stops sending: nothing throws, the log
looks calm, and stock drifts for days. So `problems()` reports a connection that **has**
reported and then went quiet — and deliberately does **not** report one that has never
reported at all, because that is an unconfigured connection, not an incident.

Queue **age**, not depth, is the other signal. Twenty events waiting is a busy Saturday;
one waiting six hours is a broken cron.

Alerts de-duplicate on a fingerprint of the problem text, not on time alone, so a new
problem gets through immediately while the same one does not arrive hourly. An alert that
cries wolf is an alert people filter — and then the one that mattered is filtered too.

### Policy: declared versus enforced

`FW_POS_Policy::fields()` declares the full ownership model, but only `stock` has a code
path, and `is_enforced()` reports that honestly. Do not let the UI imply it is protecting
product titles while nothing writes them.

A per-product override refuses **one line** without failing the event — it is a
configuration choice, not an error.

### Retention never prunes failures

`prune()` removes `applied`, `duplicate` and `skipped` only. `failed` and `pending` are
kept whatever the retention setting says: failures are what somebody still has to look at,
and a retention policy that deletes the evidence of a problem is worse than none.

## Experimental drivers

FluentCart, SureCart and Clover were written against documented APIs with **no live
install to verify against**. That is a real limitation, and the way it is handled is the
part to preserve:

- **`maturity()` returns `experimental`**, and the badge travels with the driver name
  everywhere it is offered. It cannot be chosen without being seen.
- **`is_available()` verifies assumptions, not presence.** It checks that every function
  the driver actually calls exists. Detecting the plugin alone would let a half-right
  driver run.
- **`unavailable_reason()` distinguishes "not installed" from "installed but incompatible."**
  The second is a bug report; the first is not. `FW_POS_Stores::incompatible()` returns only
  the second, so the real problem is not buried under every uninstalled cart.
- **The failure mode is safe.** A wrong assumption means the driver never activates: events
  are still recorded, resolve to `no_store_driver`, and stay re-queueable. A half-right
  driver silently writing wrong stock is the only expensive outcome, and it is the one that
  cannot happen.

**When adding a call to an experimental driver, add it to that driver's required list.**
For FluentCart the list is literally `required_functions()`, so the list IS the
compatibility contract.

To promote one to `stable`: run `tests/milestone-2.php` against a real install, fix what
`is_available()` rejects, then change `maturity()` and delete the warning in the docblock.

## The diagnostic report

`FW_POS_Diagnostics::report()` exists because "it didn't work" cannot be acted on, and the
reporter should not have to know what to include.

**It must stay safe to paste in public.** Never add:

- API keys, secrets, OAuth tokens or signature keys — not even truncated, since a prefix
  plus a merchant id often identifies an account.
- Customer names, emails or addresses from payloads.
- Connection names (a shop or a person: "Priya's till"). They are numbered instead.
- The site URL, unless the reporter opts in.

Event payloads are the awkward case — the most useful thing in a bug report and the most
likely place for personal data — so they are **summarised structurally** (types, counts,
SKUs, reasons), never included verbatim. `milestone-7.php` asserts the absence of each
secret by value; that group of assertions must never be weakened.

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
- **`milestone-3.php`** — 63 assertions: secrets, signing, auth, validation, ingest, modes,
  connection management. Requests go through `rest_do_request()`, **not** by calling the
  controller directly — a test that calls the callback by hand proves the callback works
  and nothing about whether the endpoint does.
- **`milestone-7.php`** — 64 assertions: maturity badges, unverified drivers refusing to
  act, the CSV importer end to end, Clover's doorbell shape, and the diagnostic report
  including that it leaks no secrets. It does **not** claim FluentCart/SureCart/Clover
  work — it proves they cannot do harm while unproven, which is the property that matters.
- **`milestone-6.php`** — 46 assertions: the policy engine and its per-item override, the
  reconciliation sweep and what it correctly ignores, corrections going through the ledger
  (including a stale one being refused), the silence alarm, and retention.
- **`milestone-5.php`** — 49 assertions: Square's signature scheme, catalog import, event
  normalization, token refresh, location mapping, the provider endpoint. **No Square
  account and no network**: every call is answered from a canned response through
  `pre_http_request`, and an unmocked call fails loudly rather than reaching the internet.
  The fixtures are the shapes Square actually returns, so when Square changes them this is
  the one file that has to change.
- **`milestone-4.php`** — 44 assertions: signing parity, both transports, every scenario,
  and the cURL export. It uses `internal` throughout: `http` is environment-dependent, and
  a suite that fails because a dev box cannot reach its own loopback is a suite people
  learn to ignore. A real HTTP `404` there means the extension is not *active* in the
  target install, which in-process registration cannot change — the suite says so instead
  of failing.

Run all seven after any change below the admin layer. This keeps paying: Milestone 3's
suite caught a `class_exists()` guard in Milestone 2's applier that wrapped only half a
branch, and Milestone 4's duplicate scenario caught the nonce cache rejecting legitimate
re-deliveries.

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
