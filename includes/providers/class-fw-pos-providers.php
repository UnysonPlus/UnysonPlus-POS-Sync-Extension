<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The POS provider registry.
 *
 * Unlike stores, where exactly one driver is active, MANY providers can be
 * active at once — a shop can perfectly well run a Square terminal at the
 * counter and a bespoke market-stall integration on the generic endpoint. Each
 * connection names its own provider, so there is nothing to arbitrate.
 */
class FW_POS_Providers {

	/** @var FW_POS_Provider[]|null */
	private static $providers = null;

	/**
	 * @return FW_POS_Provider[] Keyed by id.
	 */
	public static function all() {
		if ( null !== self::$providers ) {
			return self::$providers;
		}

		/**
		 * Register a POS provider driver.
		 *
		 * @param array<string,string> $providers id => class name
		 */
		$classes = apply_filters(
			'fw_pos_providers',
			[
				'square' => 'FW_POS_Provider_Square',
				'clover' => 'FW_POS_Provider_Clover',
			]
		);

		self::$providers = [];

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$provider = new $class();

			if ( $provider instanceof FW_POS_Provider ) {
				self::$providers[ $provider->get_id() ] = $provider;
			}
		}

		return self::$providers;
	}

	/**
	 * @param string $id
	 *
	 * @return FW_POS_Provider|null
	 */
	public static function get( $id ) {
		$providers = self::all();

		return isset( $providers[ $id ] ) ? $providers[ $id ] : null;
	}

	/**
	 * The provider for a connection, or null for a generic one.
	 *
	 * @param array $connection
	 *
	 * @return FW_POS_Provider|null
	 */
	public static function for_connection( array $connection ) {
		$type = isset( $connection['type'] ) ? (string) $connection['type'] : 'generic';

		return 'generic' === $type ? null : self::get( $type );
	}

	/**
	 * Connection type choices for the admin.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		$choices = [ 'generic' => __( 'Generic webhook — any POS or middleware', 'fw' ) ];

		foreach ( self::all() as $id => $provider ) {
			$choices[ $id ] = 'stable' === $provider->maturity()
				? $provider->get_label()
				: sprintf(
					/* translators: %s: provider name */
					__( '%s — experimental', 'fw' ),
					$provider->get_label()
				);
		}

		return $choices;
	}

	/**
	 * Drop memoised state. Tests need this after registering a fake.
	 */
	public static function reset() {
		self::$providers = null;
	}
}
