<?php
/**
 * API key encryption using AES-256-CBC.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SWPS_Encryption {

    private const METHOD = 'aes-256-cbc';
    private const PREFIX = 'encrypted:';

    /**
     * Encrypt a value.
     *
     * @param string $value Plain text value.
     * @return string Encrypted string as "encrypted:iv:ciphertext".
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) || self::is_encrypted( $value ) ) {
            return $value;
        }

        $key = self::get_key();
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::METHOD ) );

        $encrypted = openssl_encrypt( $value, self::METHOD, $key, 0, $iv );

        if ( false === $encrypted ) {
            return $value;
        }

        return self::PREFIX . base64_encode( $iv ) . ':' . $encrypted;
    }

    /**
     * Decrypt a value.
     *
     * @param string $value Encrypted string.
     * @return string Decrypted plain text.
     */
    public static function decrypt( string $value ): string {
        if ( empty( $value ) || ! self::is_encrypted( $value ) ) {
            return $value;
        }

        $parts = explode( ':', substr( $value, strlen( self::PREFIX ) ), 2 );

        if ( count( $parts ) !== 2 ) {
            return $value;
        }

        $iv         = base64_decode( $parts[0] );
        $ciphertext = $parts[1];
        $key        = self::get_key();

        $decrypted = openssl_decrypt( $ciphertext, self::METHOD, $key, 0, $iv );

        return false === $decrypted ? $value : $decrypted;
    }

    /**
     * Check if a value is already encrypted.
     *
     * @param string $value Value to check.
     * @return bool
     */
    public static function is_encrypted( string $value ): bool {
        return str_starts_with( $value, self::PREFIX );
    }

    /**
     * Get the encryption key derived from WordPress salts.
     *
     * @return string 32-byte key.
     */
    private static function get_key(): string {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }
}
