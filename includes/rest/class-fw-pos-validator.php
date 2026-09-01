<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A small JSON Schema validator.
 *
 * WordPress ships no JSON Schema library and pulling one in for three documents
 * would be a dependency to maintain forever, so this implements the subset the
 * schemas actually use: `type`, `required`, `properties`, `items`, `minItems`,
 * `enum`, `minimum`, `maxLength`, `minLength`, `pattern`, `anyOf`.
 *
 * Deliberately NOT a general-purpose validator. If a future schema needs a
 * keyword that is not here, add the keyword — do not add a library, and do not
 * quietly ignore it, which is the failure mode that makes hand-rolled
 * validators dangerous. Unknown keywords are ignored by design (that is what
 * JSON Schema says to do), so the discipline is on whoever writes the schema.
 *
 * Errors name the exact path (`line_items[1].quantity`), because "invalid
 * payload" sends an integrator hunting and a path does not.
 */
class FW_POS_Validator {

	/** @var string[] */
	private $errors = [];

	/**
	 * @param array $data
	 * @param array $schema
	 *
	 * @return array{ok:bool,errors:string[]}
	 */
	public function validate( $data, array $schema ) {
		$this->errors = [];

		$this->check( $data, $schema, '' );

		return [
			'ok'     => empty( $this->errors ),
			'errors' => $this->errors,
		];
	}

	/**
	 * @param mixed  $value
	 * @param array  $schema
	 * @param string $path
	 */
	private function check( $value, array $schema, $path ) {
		if ( isset( $schema['type'] ) && ! $this->is_type( $value, $schema['type'] ) ) {
			$this->error( $path, sprintf( 'must be %s', $schema['type'] ) );

			// Every other keyword assumes the right type; carrying on produces
			// a cascade of confusing secondary errors.
			return;
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$this->error( $path, sprintf( 'must be one of: %s', implode( ', ', $schema['enum'] ) ) );
		}

		if ( is_string( $value ) ) {
			$this->check_string( $value, $schema, $path );
		}

		if ( is_int( $value ) && isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$this->error( $path, sprintf( 'must be at least %d', $schema['minimum'] ) );
		}

		if ( is_array( $value ) && $this->is_list( $value ) ) {
			$this->check_array( $value, $schema, $path );
		}

		if ( is_array( $value ) && ! $this->is_list( $value ) ) {
			$this->check_object( $value, $schema, $path );
		}
	}

	/**
	 * @param string $value
	 * @param array  $schema
	 * @param string $path
	 */
	private function check_string( $value, array $schema, $path ) {
		if ( isset( $schema['minLength'] ) && strlen( $value ) < $schema['minLength'] ) {
			$this->error( $path, sprintf( 'must not be shorter than %d characters', $schema['minLength'] ) );
		}

		if ( isset( $schema['maxLength'] ) && strlen( $value ) > $schema['maxLength'] ) {
			$this->error( $path, sprintf( 'must not exceed %d characters', $schema['maxLength'] ) );
		}

		if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\\/', $schema['pattern'] ) . '/', $value ) ) {
			$this->error( $path, 'is not in the expected format' );
		}
	}

	/**
	 * @param array  $value
	 * @param array  $schema
	 * @param string $path
	 */
	private function check_array( array $value, array $schema, $path ) {
		if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] ) {
			$this->error( $path, sprintf( 'must contain at least %d item(s)', $schema['minItems'] ) );
		}

		if ( ! isset( $schema['items'] ) || ! is_array( $schema['items'] ) ) {
			return;
		}

		foreach ( $value as $index => $item ) {
			$this->check( $item, $schema['items'], $path . '[' . $index . ']' );
		}
	}

	/**
	 * @param array  $value
	 * @param array  $schema
	 * @param string $path
	 */
	private function check_object( array $value, array $schema, $path ) {
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $key ) {
				if ( ! array_key_exists( $key, $value ) ) {
					$this->error( $this->join( $path, $key ), 'is required' );
				}
			}
		}

		// anyOf is used only for "sku OR gtin", so it is implemented for that
		// shape: at least one branch must be satisfied, and the message says
		// what the choice actually is rather than "failed anyOf".
		if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
			$satisfied = false;
			$wanted    = [];

			foreach ( $schema['anyOf'] as $branch ) {
				if ( empty( $branch['required'] ) || ! is_array( $branch['required'] ) ) {
					continue;
				}

				$wanted = array_merge( $wanted, $branch['required'] );

				if ( ! array_diff( $branch['required'], array_keys( $value ) ) ) {
					$satisfied = true;
					break;
				}
			}

			if ( ! $satisfied && $wanted ) {
				$this->error( $path, sprintf( 'must include one of: %s', implode( ' or ', array_unique( $wanted ) ) ) );
			}
		}

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return;
		}

		foreach ( $schema['properties'] as $key => $sub ) {
			if ( array_key_exists( $key, $value ) && is_array( $sub ) ) {
				$this->check( $value[ $key ], $sub, $this->join( $path, $key ) );
			}
		}
	}

	/**
	 * @param mixed  $value
	 * @param string $type
	 *
	 * @return bool
	 */
	private function is_type( $value, $type ) {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) && ! $this->is_list( $value );

			case 'array':
				return is_array( $value ) && $this->is_list( $value );

			case 'string':
				return is_string( $value );

			case 'integer':
				// JSON has one number type, so 5.0 arriving as a float is an
				// integer for our purposes; 5.5 is not.
				return is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value );

			case 'number':
				return is_int( $value ) || is_float( $value );

			case 'boolean':
				return is_bool( $value );

			default:
				return true;
		}
	}

	/**
	 * An empty array is treated as a list, which is what an empty JSON array
	 * decodes to and what every use here means.
	 *
	 * @param array $value
	 *
	 * @return bool
	 */
	private function is_list( array $value ) {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @param string $path
	 * @param string $key
	 *
	 * @return string
	 */
	private function join( $path, $key ) {
		return '' === $path ? $key : $path . '.' . $key;
	}

	/**
	 * @param string $path
	 * @param string $message
	 */
	private function error( $path, $message ) {
		$this->errors[] = ( '' === $path ? 'the payload' : $path ) . ' ' . $message;
	}

	/* ---------------------------------------------------------------------- *
	 * Schema loading
	 * ---------------------------------------------------------------------- */

	/**
	 * Load a published schema document.
	 *
	 * @param string $name sale|refund|inventory
	 * @param string $version
	 *
	 * @return array|null
	 */
	public static function load( $name, $version = 'v1' ) {
		$name = preg_replace( '/[^a-z]/', '', (string) $name );
		$file = dirname( __FILE__ ) . '/schema/' . $name . '.' . $version . '.json';

		if ( ! $name || ! file_exists( $file ) ) {
			return null;
		}

		$decoded = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_array( $decoded ) ? $decoded : null;
	}
}
