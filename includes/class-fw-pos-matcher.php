<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Resolves till line items to store products.
 *
 * ## SKU first, GTIN second, never title
 *
 * Title matching looks helpful for about a week and then silently moves stock
 * between two products both called "Blue Hoodie". There is no recovery from
 * that, because nothing in the log says it happened. So the matcher has exactly
 * two keys, both of them identifiers a human deliberately assigned.
 *
 * ## Unmatched items are queued, never auto-created
 *
 * Creating products from till data produces catalogs full of `MISC-1` and
 * `Item 4` within days, and a merchant then has to clean up a mess that looks
 * like their own doing. Instead an unmatched item lands in a queue where one
 * click maps it, creates a draft, or marks it permanently ignored — the last
 * being for the carrier bags and service charges every real shop rings up.
 *
 * ## Nothing is partially applied
 *
 * If any line in a sale cannot be resolved, the whole event is skipped. Half a
 * sale is worse than none: the stock is wrong in a way nobody can see, whereas
 * a skipped event sits in the log saying exactly what it needs.
 */
class FW_POS_Matcher {

	/** @var FW_POS_Store */
	private $store;

	/**
	 * @param FW_POS_Store $store
	 */
	public function __construct( FW_POS_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Resolve every line in a payload.
	 *
	 * Each returned line gains `store_ref` (the match) and `item_id` (the
	 * ledger row). Ignored items are dropped from the returned set — they are
	 * not stock, so there is nothing to apply — but they do not make the event
	 * unresolvable.
	 *
	 * @param array  $lines Line items or inventory counts.
	 * @param string $key   'line_items' or 'counts'; only affects the label.
	 *
	 * @return array{lines:array,unmatched:array}
	 */
	public function resolve( array $lines, $key = 'line_items' ) {
		$resolved  = [];
		$unmatched = [];

		foreach ( $lines as $line ) {
			$sku  = isset( $line['sku'] ) ? trim( (string) $line['sku'] ) : '';
			$gtin = isset( $line['gtin'] ) ? trim( (string) $line['gtin'] ) : '';

			if ( '' === $sku && '' === $gtin ) {
				// Nothing to match on at all. Recorded as unmatched with a blank
				// SKU so the queue screen shows *something* actionable rather
				// than the line vanishing.
				$unmatched[] = [
					'sku'    => '',
					'reason' => 'no_identifier',
				];

				continue;
			}

			// Record what the till reported, whether or not it matches. This is
			// what populates the unmatched queue, and it also means a later
			// mapping has a row to attach to.
			$item_id = FW_POS_Ledger::upsert_item(
				[
					'sku'  => $sku,
					'gtin' => $gtin,
					'name' => isset( $line['name'] ) ? (string) $line['name'] : '',
				]
			);

			$item = $item_id ? FW_POS_Ledger::get_item( $item_id ) : null;

			// A deliberate "not a stock item" decision wins over everything.
			if ( $item && FW_POS_Ledger::ITEM_IGNORED === $item['status'] ) {
				continue;
			}

			// A human-made mapping wins over a fresh lookup: someone bound this
			// SKU to a specific product for a reason, and re-deriving it every
			// time would quietly undo that.
			$store_ref = ( $item && '' !== (string) $item['store_ref'] )
				? (string) $item['store_ref']
				: $this->store->find_by_sku( $sku, $gtin );

			if ( ! $store_ref ) {
				$unmatched[] = [
					'sku'     => $sku,
					'gtin'    => $gtin,
					'item_id' => $item_id,
					'reason'  => 'unmatched_sku',
				];

				continue;
			}

			// Persist a lookup that succeeded, so the unmatched screen does not
			// list an item that is in fact resolvable, and so the next event
			// skips the lookup.
			if ( $item && '' === (string) $item['store_ref'] ) {
				FW_POS_Ledger::set_item_match( $item_id, $store_ref );
			}

			$line['store_ref'] = $store_ref;
			$line['item_id']   = $item_id;

			$resolved[] = $line;
		}

		return [
			'lines'     => $resolved,
			'unmatched' => $unmatched,
		];
	}

	/**
	 * A one-line reason naming the offending SKUs, for the audit log.
	 *
	 * The SKUs matter: "unmatched_sku" alone sends someone hunting through a
	 * payload, and the whole point of the log is that it answers the question.
	 *
	 * @param array $unmatched
	 *
	 * @return string
	 */
	public static function describe_unmatched( array $unmatched ) {
		$skus = [];

		foreach ( $unmatched as $miss ) {
			$skus[] = '' !== (string) $miss['sku'] ? (string) $miss['sku'] : '(no SKU)';
		}

		return 'unmatched_sku: ' . implode( ', ', array_slice( $skus, 0, 5 ) )
			. ( count( $skus ) > 5 ? sprintf( ' +%d more', count( $skus ) - 5 ) : '' );
	}
}
