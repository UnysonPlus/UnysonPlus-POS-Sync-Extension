<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'POS Sync', 'fw' );
$manifest['slug']        = 'unysonplus-pos-sync';
$manifest['description'] = __(
	'Keeps the online store in step with sales rung up on a physical till. A provider-agnostic ledger sits between any point-of-sale system and any e-commerce plugin, so adding a till and adding a cart are independent jobs. Records every sale, refund and stock movement idempotently, applies them in event-time order, and keeps a full audit log of what was applied and what was skipped.',
	'fw'
);

$manifest['version']    = '1.1.1';
$manifest['display']    = true;
$manifest['standalone'] = true;

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-POS-Sync-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-POS-Sync-Extension';
$manifest['github_branch'] = 'main';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog
 * -----------------------------------------------------------------------------
 * 1.1.0 - Milestone 7: expansion drivers, the CSV importer, maturity badges and
 *         a diagnostic report. Feature-complete against the published roadmap.
 *
 *         THREE OF THESE DRIVERS ARE UNVERIFIED, and that is handled rather
 *         than hidden. FluentCart, SureCart and Clover were written against
 *         documented APIs with no live install to check against, so each is
 *         marked `experimental` and each must PROVE ITSELF before it does
 *         anything: is_available() checks every function the driver actually
 *         calls, not merely that the plugin is present. If an assumption is
 *         wrong the driver never activates - events keep being recorded, they
 *         resolve to `no_store_driver`, and they stay re-queueable. What cannot
 *         happen is a half-right driver quietly writing wrong numbers into a
 *         real shop's stock, which is the only expensive outcome here.
 *
 *         The badge travels with the driver name everywhere it is offered, so
 *         it cannot be chosen without being seen, and unavailable_reason()
 *         distinguishes "not installed" from "installed but this driver does
 *         not fit it" - different problems wanting different responses.
 *
 *         Easy Digital Downloads gets the honest treatment rather than a
 *         pretend one: EDD has NO core inventory, because digital goods have no
 *         finite quantity. The driver honours the _edd_stock meta the inventory
 *         add-ons use and otherwise reports stock_not_managed, which the applier
 *         already treats as a correct outcome. Inventing stock for downloads
 *         would have been worse than shipping nothing.
 *
 *         Clover's webhooks are a doorbell, not a payload: a notification says
 *         "object O:XYZ changed" and you fetch it yourself. Its verification is
 *         a shared code rather than an HMAC, which is materially weaker - but
 *         the consequence is bounded, because every object is re-fetched from
 *         Clover's own API before anything is recorded. A forged body can at
 *         worst make us ask Clover about an id, and Clover's answer is what we
 *         act on.
 *
 *         The CSV importer is for tills that cannot call a webhook but can
 *         export a file. Rows sharing a transaction id become ONE event with
 *         several lines, which is what keeps a partial refund distinguishable
 *         from a full one; a negative quantity is read as a return; and a row
 *         with no id gets a deterministic content-derived one, so re-importing
 *         the same file de-duplicates while an edited row is correctly a new
 *         event. Everything goes through the ledger, so a merchant unsure
 *         whether yesterday's file went through can simply import it again.
 *
 *         The diagnostic report exists because "it didn't work" cannot be acted
 *         on. It collects which driver, which versions, exactly which expected
 *         function was missing, and what the recent failures said - and it is
 *         built to be safe to paste in public: no keys, secrets, tokens or
 *         signature keys in any form, no customer data, and event payloads
 *         summarised structurally rather than included verbatim. The site URL
 *         is opt-in. The test suite asserts the absence of each secret, because
 *         that is the property that must never regress.
 *
 * 1.0.5 - Milestone 6: reconciliation and operations - the layer that assumes
 *         everything above it will eventually miss something.
 *
 *         Everything else in this extension is built to make the event stream
 *         correct: idempotent, ordered, retried, logged. It will still miss
 *         things. A webhook subscription gets deleted during a dashboard
 *         tidy-up. A site is down longer than the vendor's retry budget.
 *         Someone adjusts stock in the POS in a way that emits no event. None
 *         of those announce themselves - the log looks healthy because the
 *         events that would have appeared in it never arrived. The only way to
 *         catch that class of failure is to periodically ask the POS what it
 *         thinks the numbers are and compare, which is what the nightly sweep
 *         does.
 *
 *         It REPORTS rather than silently fixing, because a sweep that quietly
 *         corrected differences would hide the fact that events are being lost,
 *         which is the more important problem. Applying is a separate action,
 *         and when you do, the correction is recorded as ordinary absolute-count
 *         events rather than written straight to the cart - so event-time
 *         ordering, matching and the authority policy all apply to it exactly as
 *         they would to a real stocktake. That also means a reconciliation older
 *         than a genuine count that has since landed is REFUSED rather than
 *         undoing it, which would otherwise be this layer causing the exact
 *         failure it exists to prevent.
 *
 *         The authority policy engine declares who owns which field - POS owns
 *         stock because the shop floor is physical reality, the store owns
 *         title/description/images because POS item names are terse receipt
 *         labels and overwriting a product page destroys work nobody asked to
 *         lose. Only rules with a code path are ENFORCED (stock), and the
 *         screen says which are merely declared, because a policy UI implying it
 *         protects your product titles when nothing writes them is a lie told by
 *         a checkbox. A per-product override exists for online-only bundles and
 *         made-to-order items; refusing one line does not fail the event.
 *
 *         The health dashboard is built around the worst failure mode, which is
 *         not an error but SILENCE: a till whose subscription was deleted does
 *         not throw, it just stops sending while the log looks calm and stock
 *         drifts for days. So the alarm is "a till that normally reports has
 *         gone quiet", and a connection that has NEVER reported is not an
 *         incident - it is unconfigured. Queue AGE rather than depth is the
 *         other signal: twenty events waiting is a busy Saturday, one event
 *         waiting six hours is a broken cron. Alerts are de-duplicated on a
 *         fingerprint of the problems themselves, so a new problem gets through
 *         immediately while the same one does not arrive hourly.
 *
 *         Retention prunes settled events on a schedule but NEVER failed ones,
 *         whatever the setting says - they are the ones somebody still has to
 *         look at, and a retention policy that deletes the evidence of a problem
 *         is worse than none.
 *
 *         Schema 1.3.0 adds items.policy for the per-product override; a column
 *         rather than an option because the lookup is once per line item on the
 *         hot path.
 *
 * 1.0.4 - Milestone 5: the Square driver, and the provider seam under it.
 *
 *         FW_POS_Provider is the mirror of FW_POS_Store on the till side. A
 *         provider turns one vendor's webhook into normalized ledger events and
 *         never touches a cart; a store driver applies events and never knows
 *         which till sent them. That is what keeps adding a POS and adding a
 *         cart independent jobs.
 *
 *         Every vendor signs differently and the framework does not pretend
 *         otherwise: verify_webhook() is the provider's own problem. What IS
 *         fixed is the output, so a Square sale and a shell script's sale are
 *         byte-identical by the time they reach idempotency, ordering, matching
 *         and the store seam.
 *
 *         Square specifics that cost real time to rediscover, all handled:
 *         SKUs live on the catalog VARIATION and not the ITEM, so an import
 *         that walks items finds nothing; Square signs notification_url + body
 *         base64-encoded, so a trailing slash or a proxy-rewritten host fails
 *         every delivery in a way that looks like a wrong signature key;
 *         payments and orders arrive separately, so a payment is resolved
 *         against its order before being recorded rather than becoming a sale
 *         of nothing; modifiers are not stock items; only IN_STOCK is a level,
 *         while SOLD and WASTE are movements that would double-count; and a
 *         declined payment must move no stock.
 *
 *         Multi-location is normal in Square even for a single-shop seller,
 *         because it creates an online location unasked - so taking "the first
 *         location" is a reliable way to sync counter sales against the wrong
 *         stock. Locations are mapped explicitly, and an unmapped one shows its
 *         raw Square id in the log: visibly wrong rather than plausibly wrong.
 *
 *         OAuth rather than a pasted access token, because a personal token is
 *         long-lived, full-scope and ends up in screenshots, while a grant is
 *         scoped and the merchant can withdraw it themselves. Tokens refresh a
 *         day BEFORE expiry rather than after a 401, so the first sale after
 *         expiry is not the one that fails - with a customer at the counter. A
 *         refresh that fails permanently (revoked grant, deleted application)
 *         flags the connection for reconnection instead of retrying something
 *         no retry can fix; a 5xx stays transient and does not burn the grant.
 *
 *         Schema 1.2.0 adds connections.credentials, encrypted, separate from
 *         `secret`: one is OUR shared secret that we rotate, the other is
 *         someone else's tokens on their expiry schedule, and merging them
 *         would let a Square reconnection invalidate a generic webhook.
 *
 *         49 assertions, and NO Square account or network needed - every call
 *         is answered from a canned response through pre_http_request, which is
 *         why the driver could be written without hardware at all.
 *
 * 1.0.3 - Milestone 4: the Virtual Terminal. Composes correctly-signed events
 *         and fires them at this site's own endpoint, exactly as a till would,
 *         so the whole extension can be built, verified and demonstrated with
 *         no hardware. It is also the merchant's pre-launch check and the
 *         fastest way to reproduce a support problem locally.
 *
 *         TWO TRANSPORTS, and the difference is the point. In-process dispatch
 *         proves the handler is correct - and passes even when a security
 *         plugin blocks /wp-json/, the web server strips headers, or loopback
 *         is firewalled, which are the usual reasons a real till's events never
 *         arrive. A real HTTP request proves the whole path, and is the default
 *         for exactly that reason. Shipping only the in-process one would
 *         produce a screen that says everything works on a site where nothing
 *         does.
 *
 *         Twelve adversarial scenarios, each declaring what SHOULD happen and
 *         then checking it, so this is a self-test rather than a fire-and-squint
 *         tool: duplicate delivery, an offline till reconnecting, a stale
 *         stocktake, an unknown SKU, a partial refund, an expired signature, a
 *         tampered body, byte-identical re-delivery, clock drift, a malformed
 *         payload and a timestamp with no offset.
 *
 *         REMOVED THE NONCE CACHE, which looks like a security regression and
 *         is the opposite. Every ingest route is idempotent by construction -
 *         the unique index means a repeat changes nothing - so an attacker
 *         replaying a captured request achieves precisely nothing, and the
 *         cache bought no protection. What it DID do was break legitimate
 *         traffic: plenty of senders sign a delivery once and re-send the
 *         identical bytes when they do not get a 2xx (GitHub's redelivery works
 *         this way), and against a nonce cache that retry came back 401 - an
 *         auth error, the sort of thing that makes a POS stop retrying or pages
 *         someone about a shop that is working fine. Found by the Virtual
 *         Terminal's own duplicate scenario.
 *
 *         FW_POS_Store gains search_products(), CONCRETE with an empty default
 *         rather than abstract. A cart with no searchable catalog should be
 *         implementable without a stub that throws; a picker that finds nothing
 *         degrades to typing a SKU, which is what the till does anyway.
 *
 * 1.0.2 - Milestone 3: the signed generic webhook API. Any till, any middleware
 *         (Zapier / Make / n8n) or a shop's own software can now feed the
 *         ledger - a documented wire format rather than an integration per
 *         vendor, so there is nothing to break when someone else's API moves.
 *
 *         Endpoints under unysonplus-pos/v1: POST /sale, /refund, /inventory,
 *         GET /ping and /schema/{name}. Ingest does as little as possible -
 *         verify, validate, write one row, return 202 - because a vendor that
 *         does not get a prompt 2xx marks the delivery failed and retries, so
 *         doing the cart write inline turns one slow product update into a
 *         storm of duplicate deliveries. A retried delivery returns 200 with
 *         duplicate:true, NOT an error: a POS that gets an error back retries
 *         forever, which is the storm the unique index exists to absorb.
 *
 *         Requests are signed HMAC-SHA256 over {timestamp}\n{raw_body} -
 *         deliberately the simplest string that works, since every extra
 *         canonicalisation step is a place two implementations can disagree
 *         and produce a 401 that takes an afternoon to find. Compared with
 *         hash_equals(), a +/-5 minute window, and a nonce cache so a request
 *         cannot be replayed even inside its own window.
 *
 *         CORRECTION to what the published docs previously said: a connection
 *         secret is stored ENCRYPTED, not hashed. Verifying an HMAC means
 *         recomputing it, which needs the original bytes, so hashing would make
 *         every request fail. It is encrypted with a key derived from the
 *         site's WordPress salts, which live in wp-config.php rather than the
 *         database - that protects a leaked backup or an SQL injection, and
 *         does not protect against filesystem compromise. Stated plainly
 *         because pretending otherwise would be worse.
 *
 *         Connections are one row per till, each with its own key, secret,
 *         scopes, location and mode. A shop with three registers needs to know
 *         which one sent the event that looks wrong, revoke a stolen tablet
 *         without taking the shop offline, and put a new integration in test
 *         mode while the others keep trading; a single site-wide key makes all
 *         three impossible. Rotating changes the secret but NOT the key, so a
 *         compromised credential is one field to update at the till. Clock skew
 *         is recorded per connection, because a drifting till clock corrupts
 *         the ordering key and a drift only ever logged in passing is a drift
 *         nobody notices until stock is wrong.
 *
 *         Payloads are validated against published JSON Schemas, served at
 *         /schema/{name} so integrators can check before sending. occurred_at
 *         must carry an explicit UTC offset: it is the ordering key, and an
 *         ambiguous ordering key is precisely what lets a reconnecting offline
 *         till rewind current stock.
 *
 *         Schema 1.1.0 adds the connections table. Events recorded by 1.0.x
 *         keep connection_id 0 - they predate connections and must not be
 *         retro-attributed to whichever one is created first.
 *
 *         Also fixes an applier bug found by the milestone 2 suite: the
 *         connection-mode lookup guarded its class_exists() check around only
 *         half the branch, so it fataled in exactly the situation the guard
 *         existed for.
 *
 * 1.0.1 - Milestone 2: the store driver seam, and WooCommerce on the end of it.
 *
 *         FW_POS_Store is the one place in the extension allowed to know a
 *         specific cart exists. Everything crossing it is a primitive - a SKU
 *         string, an integer quantity, an opaque store reference - so nothing
 *         above it acquires a WooCommerce shape.
 *
 *         It was deliberately drafted against TWO implementations, WooCommerce
 *         to ship and FluentCart on paper, because an interface designed
 *         against one always encodes that one's assumptions and you find out at
 *         the second, when it is expensive. Two things changed as a result and
 *         are worth keeping: find_by_sku() returns an opaque string rather than
 *         a post ID, which FluentCart's custom tables would not have survived;
 *         and get_capabilities() exists at all, because carts genuinely differ
 *         on partial refunds and per-location stock and the ledger has to
 *         degrade rather than fatal.
 *
 *         The WooCommerce driver writes stock through wc_update_product_stock()
 *         rather than product meta, so Woo's own stock-status transitions and
 *         low-stock notifications still fire; it is HPOS-safe; and it falls
 *         through to a variation query, because variable products carry their
 *         SKUs on the variation and a parent-only lookup misses most real
 *         catalogs.
 *
 *         Matching is SKU first, GTIN second, never name - two products called
 *         "Blue Hoodie" would otherwise swap stock with nothing in the log to
 *         say it happened. An item matching nothing goes to a new Unmatched
 *         screen rather than being auto-created, because inventing products
 *         from till data fills a catalog with MISC-1 within days. One click
 *         there maps it, clears it, or marks it permanently not-a-stock-item
 *         for the carrier bags and service charges every shop rings up - and
 *         mapping a SKU re-queues the events it previously blocked.
 *
 *         An event with any unresolvable line is skipped WHOLE. Half a sale
 *         leaves stock wrong in a way nobody can see; a skipped event says
 *         exactly what it needs.
 *
 *         Recording till sales as store orders is off by default: a shop's POS
 *         already reports its own takings, so mirroring every counter sale into
 *         WooCommerce double-counts revenue across the two systems and buries
 *         genuine online orders among walk-ins.
 *
 * 1.0.0 - Milestone 1: the ledger. The provider-agnostic core that every POS
 *         driver and every store driver will sit on top of, shipped before
 *         either exists because it is the part that has to be right.
 *
 *         A POS integration is really two independent problems — which till,
 *         and which cart — and coupling them makes adding either one O(N*M).
 *         So the normalized ledger is the product: POS drivers only write to
 *         it, store drivers only read from it, and neither knows the other
 *         exists. Everything genuinely hard about POS sync (idempotency,
 *         out-of-order offline batches, refund restocking, matching) lives
 *         here, once, and is testable with no POS and no cart present.
 *
 *         Three tables (fw_pos_items, fw_pos_events, fw_pos_map) owned by a
 *         single installer, following the schema discipline established by the
 *         Newsletter CRM. The events table carries UNIQUE (connection_id,
 *         external_id): that index IS the idempotency guarantee, so a replayed
 *         webhook is rejected by the database rather than by application code
 *         that someone might forget to call.
 *
 *         The queue applies events in occurred_at order rather than arrival
 *         order, because an offline till reconnecting dumps its backlog late —
 *         and ordering by arrival lets a stale morning stock count overwrite an
 *         accurate afternoon one. Absolute counts older than the last applied
 *         count for an item are refused; relative adjustments commute and are
 *         always safe. Runs on Action Scheduler when present, WP-Cron otherwise,
 *         with capped exponential backoff.
 *
 *         Adds Unyson+ -> POS Sync with the audit log, which is the screen
 *         anyone opens when a stock number looks wrong: what arrived, what was
 *         matched, what was applied, and what was skipped with the reason.
 *
 *         No store driver exists yet, so events currently resolve to
 *         "skipped: no_store_driver" — deliberately visible in the log rather
 *         than silently discarded. The WooCommerce driver lands in Milestone 2.
 */
