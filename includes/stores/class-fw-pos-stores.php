<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The store driver registry.
 *
 * Exactly one driver is active at a time. Two carts writing the same stock from
 * the same event stream would fight, and there is no sensible arbitration — so
 * the choice is explicit, stored in settings, and defaulted only when there is
 * genuinely nothing to choose between.
 */
class FW_POS_Stores {

	/** @var FW_POS_Store[]|null */
	private static $drivers = null;

	/** @var FW_POS_Store|false|null */
	private static $active = null;

	/**
	 * Every registered driver, keyed by id.
	 *
	 * @return FW_POS_Store[]
	 */
	public static function all() {
		if ( null !== self::$drivers ) {
			return self::$drivers;
		}

		/**
		 * Register a store driver.
		 *
		 * Third parties add their own cart here; the value is a class name
		 * extending FW_POS_Store, instantiated lazily.
		 *
		 * @param array<string,string> $drivers id => class name
		 */
		$classes = apply_filters(
			'fw_pos_store_drivers',
			[
				'woocommerce' => 'FW_POS_Store_WooCommerce',
				'fluentcart'  => 'FW_POS_Store_FluentCart',
				'surecart'    => 'FW_POS_Store_SureCart',
				'edd'         => 'FW_POS_Store_EDD',
			]
		);

		self::$drivers = [];

		foreach ( $classes as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$driver = new $class();

			if ( $driver instanceof FW_POS_Store ) {
				self::$drivers[ $driver->get_id() ] = $driver;
			}
		}

		return self::$drivers;
	}

	/**
	 * Drivers whose cart is actually installed right now.
	 *
	 * @return FW_POS_Store[]
	 */
	public static function available() {
		return array_filter(
			self::all(),
			function ( $driver ) {
				return $driver->is_available();
			}
		);
	}

	/**
	 * The active driver, or null when there is none.
	 *
	 * Resolution: the configured id if it is available; otherwise the single
	 * available driver when there is exactly one. With several available and
	 * none configured, this returns null on purpose — guessing which cart owns
	 * a shop's stock is not a decision code should make silently.
	 *
	 * @return FW_POS_Store|null
	 */
	public static function active() {
		if ( null !== self::$active ) {
			return self::$active ? self::$active : null;
		}

		$available = self::available();
		$configured = '';

		$settings = (array) fw_get_db_ext_settings_option( 'pos-sync' );

		if ( ! empty( $settings['store_driver'] ) ) {
			$configured = (string) $settings['store_driver'];
		}

		/**
		 * Override the active store driver id.
		 *
		 * @param string $configured
		 */
		$configured = (string) apply_filters( 'fw_pos_store_driver', $configured );

		if ( '' !== $configured && isset( $available[ $configured ] ) ) {
			self::$active = $available[ $configured ];

			return self::$active;
		}

		if ( 1 === count( $available ) ) {
			self::$active = reset( $available );

			return self::$active;
		}

		self::$active = false;

		return null;
	}

	/**
	 * Why there is no active driver, for the admin screen.
	 *
	 * @return string 'none_installed'|'ambiguous'|''
	 */
	public static function inactive_reason() {
		if ( self::active() ) {
			return '';
		}

		return count( self::available() ) > 1 ? 'ambiguous' : 'none_installed';
	}

	/**
	 * Choices for the settings dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		$choices = [ '' => __( 'Detect automatically', 'fw' ) ];

		foreach ( self::all() as $id => $driver ) {
			$label = $driver->get_label();

			// The badge travels with the name everywhere the driver is offered,
			// so it cannot be chosen without seeing it.
			if ( 'stable' !== $driver->maturity() ) {
				$label .= ' — ' . __( 'experimental', 'fw' );
			}

			$choices[ $id ] = $driver->is_available()
				? $label
				: sprintf(
					/* translators: %s: cart name */
					__( '%s (not available)', 'fw' ),
					$label
				);
		}

		return $choices;
	}

	/**
	 * Drivers that are present but whose expectations were not met, with the
	 * reason. This is the list a bug report is made of.
	 *
	 * @return array<string,string>
	 */
	public static function incompatible() {
		$found = [];

		foreach ( self::all() as $id => $driver ) {
			if ( $driver->is_available() ) {
				continue;
			}

			$reason = $driver->unavailable_reason();

			// Only report drivers whose CART is present — "not installed" is not
			// an incompatibility, and listing every uninstalled cart as a
			// problem would bury the one that matters.
			if ( $reason && false === stripos( $reason, 'not installed' ) ) {
				$found[ $id ] = $reason;
			}
		}

		return $found;
	}

	/**
	 * Drop the memoised state. Tests and the settings screen need this after
	 * changing the configured driver within one request.
	 */
	public static function reset() {
		self::$drivers = null;
		self::$active  = null;
	}
}
