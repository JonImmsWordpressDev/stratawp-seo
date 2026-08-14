<?php
/**
 * Bitmask flags for swps_crawl_pages rows.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-facts flags using bitmask representation for efficient storage.
 */
class SWPS_Crawl_Page_Flags {

	/** Page facts flags. */
	public const HAS_VIEWPORT  = 1;
	public const HAS_DOCTYPE   = 2;
	public const HAS_LANG      = 4;
	public const HAS_CHARSET   = 8;
	public const HAS_NOINDEX   = 16;
	public const IS_CHALLENGE  = 32;
	public const IS_COMPRESSED = 64;
	public const IS_ARCHIVE    = 128;
	public const IS_PAGINATED  = 256;

	/** Fact key => bit mapping. */
	private const MAP = array(
		'has_viewport'  => self::HAS_VIEWPORT,
		'has_doctype'   => self::HAS_DOCTYPE,
		'has_lang'      => self::HAS_LANG,
		'has_charset'   => self::HAS_CHARSET,
		'has_noindex'   => self::HAS_NOINDEX,
		'is_challenge'  => self::IS_CHALLENGE,
		'is_compressed' => self::IS_COMPRESSED,
		'is_archive'    => self::IS_ARCHIVE,
		'is_paginated'  => self::IS_PAGINATED,
	);

	/**
	 * Pack facts array into a bitmask integer.
	 *
	 * Keys not present or false-ish are treated as unset (bit = 0).
	 *
	 * @param array $facts Associative array of fact keys => boolean values.
	 * @return int Bitmask integer.
	 */
	public static function pack( array $facts ): int {
		$flags = 0;
		foreach ( self::MAP as $key => $bit ) {
			if ( ! empty( $facts[ $key ] ) ) {
				$flags |= $bit;
			}
		}
		return $flags;
	}

	/**
	 * Check if a flag bit is set in the bitmask.
	 *
	 * @param int $flags The bitmask integer.
	 * @param int $flag  The individual flag constant to test.
	 * @return bool True if the flag is set.
	 */
	public static function has( int $flags, int $flag ): bool {
		return 0 !== ( $flags & $flag );
	}
}
