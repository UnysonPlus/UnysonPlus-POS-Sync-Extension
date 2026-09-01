<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Request signing and verification.
 *
 * The signing string is deliberately the simplest thing that works:
 *
 *     {timestamp}\n{raw_body}
 *
 * No method, no URL, no header canonicalisation. Every one of those is a place
 * where two implementations can disagree about whitespace, casing or ordering,
 * and every disagreement produces a 401 that takes an afternoon to find. Two
 * fields, one separator, and the bytes exactly as sent.
 *
 * Two checks, in this order:
 *
 *  1. **Timestamp window** — ±5 minutes. Bounds how long a captured request
 *     stays acceptable at all.
 *  2. **Signature** — compared with `hash_equals()`, which is constant-time. A
 *     naive `===` returns faster on an early mismatch and leaks the signature
 *     one byte at a time to anyone patient enough to measure.
 *
 * ## Why there is no nonce cache
 *
 * An earlier version remembered each accepted signature for the window's length
 * and rejected a repeat as `replayed_request`. That has been removed, and the
 * reasoning is worth keeping because it looks like a security regression and is
 * the opposite.
 *
 * Every ingest route is **idempotent by construction**: the ledger's
 * `UNIQUE (connection_id, external_id)` index means a repeated event is
 * recorded as a duplicate and changes nothing. So an attacker replaying a
 * captured request achieves exactly nothing — they cannot alter the payload
 * (the signature covers it) and re-sending it is a no-op. The nonce cache
 * bought no additional protection.
 *
 * What it did do was break legitimate traffic. Plenty of webhook senders sign
 * a delivery **once** and re-send the identical bytes, headers included, when
 * they do not get a 2xx — GitHub's redelivery works exactly this way. Against a
 * nonce cache that legitimate retry comes back `401`, which reads as an
 * authentication failure: the sort of thing that makes a POS stop retrying, or
 * page an operator at 6am about a shop that is working fine.
 *
 * So: idempotency handles repeats, the signature handles tampering, and the
 * window bounds exposure. A cache that can only turn a working retry into an
 * auth error is not worth having.
 */
class FW_POS_Signature {

	const HEADER_KEY       = 'X-UPOS-Key';
	const HEADER_SIGNATURE = 'X-UPOS-Signature';
	const HEADER_TIMESTAMP = 'X-UPOS-Timestamp';

	/** Seconds either side of server time that a request may be signed. */
	const WINDOW = 300;

	/**
	 * Compute a signature. Also used by the docs, the Virtual Terminal and the
	 * tests, so there is exactly one implementation to be wrong.
	 *
	 * @param string $secret
	 * @param string $timestamp
	 * @param string $body Raw body, exactly as it will be sent.
	 *
	 * @return string
	 */
	public static function sign( $secret, $timestamp, $body ) {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . "\n" . $body, (string) $secret );
	}

	/**
	 * Verify a request.
	 *
	 * @param string $secret
	 * @param string $timestamp
	 * @param string $body
	 * @param string $signature
	 *
	 * @return array{ok:bool,code:string,skew:int}
	 */
	public static function verify( $secret, $timestamp, $body, $signature ) {
		$timestamp = (string) $timestamp;
		$signature = (string) $signature;

		if ( '' === $timestamp || '' === $signature ) {
			return self::fail( 'missing_signature_headers' );
		}

		if ( ! ctype_digit( ltrim( $timestamp, '-' ) ) ) {
			return self::fail( 'malformed_timestamp' );
		}

		$skew = time() - (int) $timestamp;

		if ( abs( $skew ) > self::WINDOW ) {
			// The most common cause by far is a sender whose clock is wrong, so
			// the skew travels with the failure to make that diagnosable.
			return [
				'ok'   => false,
				'code' => 'timestamp_outside_window',
				'skew' => $skew,
			];
		}

		if ( '' === (string) $secret ) {
			// The connection exists but its secret could not be decrypted —
			// almost always rotated salts. Fails closed, and says so.
			return self::fail( 'secret_unavailable' );
		}

		$expected = self::sign( $secret, $timestamp, (string) $body );

		if ( ! hash_equals( $expected, $signature ) ) {
			return self::fail( 'signature_mismatch' );
		}

		// Deliberately no replay check here — see the class docblock. A repeated
		// delivery falls through to the ledger, which records it as a duplicate
		// and returns 200, which is what a retrying sender needs to see.
		return [
			'ok'   => true,
			'code' => '',
			'skew' => $skew,
		];
	}

	/**
	 * @param string $code
	 *
	 * @return array
	 */
	private static function fail( $code ) {
		return [
			'ok'   => false,
			'code' => $code,
			'skew' => 0,
		];
	}

	/**
	 * Human explanation for a failure code, for the log and the error body.
	 *
	 * @param string $code
	 *
	 * @return string
	 */
	public static function explain( $code ) {
		$messages = [
			'missing_signature_headers' => __( 'The request did not carry the signature and timestamp headers.', 'fw' ),
			'malformed_timestamp'       => __( 'The timestamp header was not a Unix timestamp in seconds.', 'fw' ),
			'timestamp_outside_window'  => __( 'The request was signed more than five minutes from this server\'s clock. Check the sender\'s clock.', 'fw' ),
			'secret_unavailable'        => __( 'This connection\'s secret could not be read. If the site\'s security salts were changed, rotate the secret and reconfigure the till.', 'fw' ),
			'signature_mismatch'        => __( 'The signature did not match. The usual cause is the body being re-serialized after signing — sign the exact bytes you send.', 'fw' ),
		];

		return isset( $messages[ $code ] ) ? $messages[ $code ] : $code;
	}
}
