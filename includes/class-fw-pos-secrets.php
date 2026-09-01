<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * At-rest protection for connection secrets.
 *
 * ## Why these are ENCRYPTED and not hashed
 *
 * The instinct with any stored credential is to hash it, and for a password
 * that is right — you only ever need to check a guess against it. An HMAC
 * shared secret is a different animal: verifying a signature means
 * *recomputing* it, which requires the original bytes. A hash cannot be
 * un-hashed, so hashing the secret would make every request fail.
 *
 * So it is encrypted with a key derived from the site's own WordPress salts,
 * which live in `wp-config.php` rather than the database.
 *
 * ## What that does and does not protect against
 *
 * **Does:** a database-only exposure — a leaked backup, an SQL injection, a
 * misconfigured phpMyAdmin, a developer handed a DB dump. In all of those the
 * secrets are ciphertext and the key is somewhere the attacker did not reach.
 * That is the common case and it is worth defending.
 *
 * **Does not:** full filesystem compromise. Anyone who can read
 * `wp-config.php` can read the salts and decrypt everything. This is not
 * security theatre — it is a real and useful boundary — but it is a boundary,
 * not a vault, and pretending otherwise would be worse than saying so.
 *
 * If the salts are rotated, existing secrets become undecryptable and every
 * connection must be re-issued. That is the correct failure: it fails closed,
 * loudly, at the point of use, rather than silently accepting bad signatures.
 */
class FW_POS_Secrets {

	const CIPHER = 'aes-256-cbc';

	/** Marks a value this class produced, so a plaintext legacy value is detectable. */
	const PREFIX = 'upos1:';

	/**
	 * Generate a new secret. 32 bytes of CSPRNG output, hex-encoded.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Generate a public key. Prefixed so it is recognisable in a log or a
	 * support ticket, and so a leaked one can be searched for.
	 *
	 * @param string $mode test|live
	 *
	 * @return string
	 */
	public static function generate_key( $mode = 'test' ) {
		return 'upos_' . ( 'live' === $mode ? 'live' : 'test' ) . '_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plaintext
	 *
	 * @return string
	 */
	public static function protect( $plaintext ) {
		if ( ! self::available() ) {
			// Storing it in the clear is worse than not shipping the feature —
			// but refusing to store it at all breaks the site. Store it, and
			// make the degraded state visible on the admin screen.
			return (string) $plaintext;
		}

		$iv         = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ciphertext = openssl_encrypt( (string) $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return (string) $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Recover a stored secret.
	 *
	 * @param string $stored
	 *
	 * @return string Empty string when it cannot be decrypted.
	 */
	public static function reveal( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		// Written before encryption was available, or by a site without
		// OpenSSL. Still usable; the admin screen flags it.
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( false === $raw ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $raw ) <= $iv_length ) {
			return '';
		}

		$plaintext = openssl_decrypt(
			substr( $raw, $iv_length ),
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, $iv_length )
		);

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Is a stored value actually encrypted?
	 *
	 * @param string $stored
	 *
	 * @return bool
	 */
	public static function is_protected( $stored ) {
		return 0 === strpos( (string) $stored, self::PREFIX );
	}

	/**
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_cipher_iv_length' );
	}

	/**
	 * The encryption key, derived from the site's salts.
	 *
	 * `wp_salt()` returns the values from wp-config.php, so the key lives on the
	 * filesystem and not in the database — which is the entire point.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|fw-pos-sync', true );
	}
}
