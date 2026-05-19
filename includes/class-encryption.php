<?php
/**
 * API key / secret encryption.
 *
 * New values are encrypted with AES-256-GCM (authenticated encryption:
 * tampering is detected). Legacy AES-256-CBC values written by earlier
 * versions are still transparently decrypted, and are upgraded to GCM
 * the next time the owning option is re-saved.
 *
 * The key is derived from wp_salt( 'auth' ). If the site's salts are
 * rotated, previously stored values can no longer be decrypted — in
 * that case decrypt() fails safe (returns '' and logs) rather than
 * returning the still-encrypted blob, so a corrupt credential is never
 * sent to a third-party API. The affected settings field renders empty,
 * prompting the admin to re-enter the secret.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM encryption for stored API keys and OAuth secrets, with
 * transparent decryption of legacy AES-256-CBC values.
 */
class SWPS_Encryption {

	private const METHOD_GCM    = 'aes-256-gcm';
	private const METHOD_LEGACY = 'aes-256-cbc';

	/** Prefix on every encrypted value (legacy CBC and current GCM). */
	private const PREFIX = 'encrypted:';

	/** Prefix identifying the authenticated (GCM) format. */
	private const PREFIX_V2 = 'encrypted:v2:';

	private const GCM_IV_LEN  = 12;
	private const GCM_TAG_LEN = 16;

	/**
	 * Encrypt a value with AES-256-GCM.
	 *
	 * @param string $value Plain text value.
	 * @return string Encrypted "encrypted:v2:<base64(iv|tag|ciphertext)>",
	 *                or '' if encryption failed (never the plaintext).
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		try {
			$iv = random_bytes( self::GCM_IV_LEN );
		} catch ( \Exception $e ) {
			self::log( 'no CSPRNG available for IV generation' );
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$value,
			self::METHOD_GCM,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::GCM_TAG_LEN
		);

		if ( false === $ciphertext ) {
			// Do NOT fall back to storing the plaintext secret.
			self::log( 'openssl_encrypt failed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of raw ciphertext, not obfuscation.
		return self::PREFIX_V2 . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value.
	 *
	 * Plain (unencrypted) input is returned unchanged — this supports
	 * installs that stored a secret before encryption existed. A value
	 * that IS encrypted but cannot be decrypted (rotated salts, tampered
	 * or truncated blob) returns '' so callers treat it as "no secret"
	 * instead of using a corrupt one.
	 *
	 * @param string $value Stored value.
	 * @return string Decrypted plain text, '' on failure, or the input
	 *                unchanged when it was never encrypted.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( str_starts_with( $value, self::PREFIX_V2 ) ) {
			return self::decrypt_gcm( substr( $value, strlen( self::PREFIX_V2 ) ) );
		}

		if ( str_starts_with( $value, self::PREFIX ) ) {
			return self::decrypt_legacy_cbc( substr( $value, strlen( self::PREFIX ) ) );
		}

		// Not encrypted — pre-encryption stored value.
		return $value;
	}

	/**
	 * Decrypt an AES-256-GCM payload.
	 *
	 * @param string $payload base64( iv | tag | ciphertext ).
	 * @return string Plain text, or '' on failure.
	 */
	private static function decrypt_gcm( string $payload ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext transport encoding.
		$raw = base64_decode( $payload, true );

		if ( false === $raw || strlen( $raw ) <= self::GCM_IV_LEN + self::GCM_TAG_LEN ) {
			self::log( 'GCM payload malformed' );
			return '';
		}

		$iv         = substr( $raw, 0, self::GCM_IV_LEN );
		$tag        = substr( $raw, self::GCM_IV_LEN, self::GCM_TAG_LEN );
		$ciphertext = substr( $raw, self::GCM_IV_LEN + self::GCM_TAG_LEN );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::METHOD_GCM,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			self::log( 'GCM decrypt failed (rotated salts or tampered value)' );
			return '';
		}

		return $plaintext;
	}

	/**
	 * Decrypt a legacy AES-256-CBC payload written by older versions.
	 *
	 * @param string $payload "<base64(iv)>:<ciphertext>".
	 * @return string Plain text, or '' on failure.
	 */
	private static function decrypt_legacy_cbc( string $payload ): string {
		$parts = explode( ':', $payload, 2 );

		if ( count( $parts ) !== 2 ) {
			self::log( 'legacy payload malformed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the legacy IV transport encoding.
		$iv         = base64_decode( $parts[0], true );
		$ciphertext = $parts[1];

		if ( false === $iv ) {
			self::log( 'legacy IV malformed' );
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, self::METHOD_LEGACY, self::get_key(), 0, $iv );

		if ( false === $plaintext ) {
			self::log( 'legacy CBC decrypt failed (rotated salts)' );
			return '';
		}

		return $plaintext;
	}

	/**
	 * Whether a value is in either encrypted format.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Whether a value is encrypted in the legacy (non-authenticated)
	 * CBC format and should be re-encrypted on next save.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_legacy_format( string $value ): bool {
		return str_starts_with( $value, self::PREFIX )
			&& ! str_starts_with( $value, self::PREFIX_V2 );
	}

	/**
	 * Derive the 32-byte key from the WordPress auth salt.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Log an encryption failure without ever logging secret material.
	 *
	 * @param string $reason Short, non-sensitive description.
	 * @return void
	 */
	private static function log( string $reason ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SWPS_Encryption: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unrecoverable crypto failure; no secret material logged.
		}
	}
}
