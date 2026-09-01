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

$manifest['version']    = '1.0.1';
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
