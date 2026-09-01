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

$manifest['version']    = '1.0.0';
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
