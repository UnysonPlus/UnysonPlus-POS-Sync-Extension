<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Field authority — who owns which piece of a product.
 *
 * ## Why this exists at all
 *
 * Two systems editing the same field will diverge. Not might: will. The only
 * stable arrangement is to declare an owner per field and have the other side
 * defer, and the only reason that feels heavy-handed is that the alternative —
 * "last write wins" — fails silently and slowly, which looks fine right up until
 * a stocktake.
 *
 * ## The default split, and why it is the right way round
 *
 * The POS owns **stock**, because the shop floor is physical reality: what is on
 * the shelf is not a matter of opinion, and the till is where it is counted.
 *
 * The store owns **content** — title, description, images, SEO — because POS
 * item names are terse counter labels ("TSH BLU M") written to fit a receipt,
 * and letting them overwrite a carefully written product page is a one-way
 * destruction of work nobody asked for.
 *
 * ## Declared versus enforced
 *
 * This class declares the full model, but only the rules with an actual code
 * path are enforced today: POS Sync writes **stock** and nothing else, so stock
 * authority is live and the rest is documentation of intent for when a content
 * sync exists. That distinction is deliberate and `is_enforced()` reports it,
 * because a policy screen that implies it is protecting your product titles
 * when nothing writes them would be a lie told by a checkbox.
 *
 * @see https://docs.unysonplus.com/extensions/pos-sync/architecture#3-authority
 */
class FW_POS_Policy {

	const OWNER_POS   = 'pos';
	const OWNER_STORE = 'store';

	/**
	 * The fields, their default owner, and whether anything enforces it yet.
	 *
	 * @return array<string,array{owner:string,enforced:bool,label:string,why:string}>
	 */
	public static function fields() {
		return [
			'stock' => [
				'owner'    => self::OWNER_POS,
				'enforced' => true,
				'label'    => __( 'Stock level', 'fw' ),
				'why'      => __( 'The shop floor is physical reality, and the till is where it is counted.', 'fw' ),
			],
			'price' => [
				'owner'    => self::OWNER_STORE,
				'enforced' => false,
				'label'    => __( 'Price', 'fw' ),
				'why'      => __( 'Online promotions usually differ from counter pricing, so the store keeps its own. Recorded from the till for the log, never applied.', 'fw' ),
			],
			'content' => [
				'owner'    => self::OWNER_STORE,
				'enforced' => false,
				'label'    => __( 'Title, description, images', 'fw' ),
				'why'      => __( 'POS item names are terse receipt labels. Letting them overwrite a product page destroys work nobody asked to lose.', 'fw' ),
			],
			'identifiers' => [
				'owner'    => self::OWNER_POS,
				'enforced' => false,
				'label'    => __( 'SKU and barcode', 'fw' ),
				'why'      => __( 'The POS is where they are printed and scanned.', 'fw' ),
			],
		];
	}

	/**
	 * Who owns a field for a given item.
	 *
	 * The per-item override wins over the site default — that is the whole
	 * reason it exists. The case it is for is an online-only bundle, or a
	 * made-to-order product, whose stock the till should never touch.
	 *
	 * @param string     $field
	 * @param array|null $item An items-table row, or null for the site default.
	 *
	 * @return string
	 */
	public static function owner( $field, $item = null ) {
		$fields = self::fields();
		$owner  = isset( $fields[ $field ] ) ? $fields[ $field ]['owner'] : self::OWNER_STORE;

		/**
		 * Filter the default owner of a field.
		 *
		 * @param string     $owner
		 * @param string     $field
		 * @param array|null $item
		 */
		$owner = (string) apply_filters( 'fw_pos_field_owner', $owner, $field, $item );

		// Only stock has a per-item override, because it is the only field
		// anything writes. Offering overrides for the rest would be a UI that
		// does nothing.
		if ( 'stock' === $field && $item && ! empty( $item['policy'] ) ) {
			return self::OWNER_STORE === $item['policy'] ? self::OWNER_STORE : self::OWNER_POS;
		}

		return $owner;
	}

	/**
	 * May the POS write stock for this item?
	 *
	 * @param array|null $item
	 *
	 * @return bool
	 */
	public static function pos_owns_stock( $item = null ) {
		return self::OWNER_POS === self::owner( 'stock', $item );
	}

	/**
	 * Is a field's ownership actually acted on, or only declared?
	 *
	 * @param string $field
	 *
	 * @return bool
	 */
	public static function is_enforced( $field ) {
		$fields = self::fields();

		return ! empty( $fields[ $field ]['enforced'] );
	}

	/**
	 * The reason string recorded when a write is refused by policy.
	 *
	 * @param string $sku
	 *
	 * @return string
	 */
	public static function refusal( $sku ) {
		return 'policy_store_owned: ' . $sku;
	}

	/**
	 * Human explanation of a policy refusal, for the log.
	 *
	 * @return string
	 */
	public static function explain_refusal() {
		return __( 'This item is set to store-owned stock, so the till is not allowed to change it. That is a deliberate per-product override, not a fault.', 'fw' );
	}
}
