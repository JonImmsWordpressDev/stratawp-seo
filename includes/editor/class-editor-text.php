<?php
/**
 * Pure text helpers for the editor check engine.
 *
 * No WordPress calls: these run in PHPUnit without WordPress loaded and must
 * behave exactly like src/editor/analysis/text.js (a golden-file test pins it).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Text {

	/**
	 * Max sanitising passes in plain(); mirrored in the JS PLAIN_MAX_PASSES.
	 */
	private const PLAIN_MAX_PASSES = 10;

	private const FOLD_FROM = 'àáâãäåçèéêëìíîïñòóôõöùúûüýÿ';
	private const FOLD_TO   = 'aaaaaaceeeeiiiinooooouuuuyy';

	public static function lower( string $s ): string {
		return mb_strtolower( $s, 'UTF-8' );
	}

	/**
	 * Fold common Latin accents so a keyword like "café" matches the slug "cafe".
	 */
	public static function fold( string $s ): string {
		static $map = null;
		if ( null === $map ) {
			$map = array_combine( mb_str_split( self::FOLD_FROM ), str_split( self::FOLD_TO ) );
		}
		return strtr( $s, $map );
	}

	public static function slugify( string $s ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '-', self::fold( self::lower( $s ) ) ), '-' );
	}

	public static function host_key( string $host ): string {
		return (string) preg_replace( '/^www\./', '', self::lower( trim( $host ) ) );
	}

	/**
	 * Strip block comments, scripts, shortcodes and tags; decode a few entities.
	 */
	public static function plain( string $html ): string {
		// Repeat until stable so re-forming markup cannot survive one pass. Keep the
		// step order identical to src/editor/analysis/text.js.
		$text   = $html;
		$passes = 0;
		do {
			$previous = $text;
			$text     = (string) preg_replace( '/<!--.*?-->/s', ' ', $text );
			$text     = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text );
			$text     = (string) preg_replace( '#</(?:p|div|h[1-6]|li|blockquote|tr|ul|ol)>|<br\s*/?>#i', "\n", $text );
			$text     = (string) preg_replace( '/\[\/?[a-z_][\w-]*(?:\s[^\]]*)?\]/i', ' ', $text );
			$text     = (string) preg_replace( '/<[^>]*>/', '', $text );
			++$passes;
		} while ( $text !== $previous && $passes < self::PLAIN_MAX_PASSES );
		$text = (string) preg_replace_callback(
			'/&(amp|nbsp|quot|#039|#8217|lt|gt);/',
			static function ( array $m ): string {
				$map = array(
					'amp'   => '&',
					'nbsp'  => ' ',
					'quot'  => '"',
					'#039'  => "'",
					'#8217' => "'",
					'lt'    => '<',
					'gt'    => '>',
				);
				return $map[ $m[1] ];
			},
			$text
		);
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n[ \n]*/', "\n", $text );
		return trim( $text );
	}

	/**
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$w = preg_split( '/[^\p{L}\p{N}\'’-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $w ) ? $w : array();
	}

	/**
	 * @return string[]
	 */
	public static function sentences( string $text ): array {
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$parts = preg_split( '/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( (array) $parts as $p ) {
				$p = trim( $p );
				if ( '' !== $p && count( self::words( $p ) ) > 0 ) {
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	/**
	 * Plain text of each paragraph. Uses <p> elements; falls back to lines.
	 *
	 * @return string[]
	 */
	public static function paragraphs( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$t = self::plain( $inner );
				if ( '' !== $t ) {
					$out[] = $t;
				}
			}
		}
		if ( ! $out ) {
			foreach ( explode( "\n", self::plain( $html ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array{level:int,text:string}>
	 */
	public static function headings( string $html ): array {
		$out = array();
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$out[] = array(
					'level' => (int) $row[1],
					'text'  => self::plain( $row[2] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Alt text of every <img>, trimmed. Missing alt is an empty string.
	 *
	 * @return string[]
	 */
	public static function image_alts( string $html ): array {
		$out = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$alt = '';
				if ( preg_match( '/(?<![\w-])alt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a ) ) {
					$alt = '' !== $a[1] ? $a[1] : ( $a[2] ?? '' );
				}
				$out[] = trim( $alt );
			}
		}
		return $out;
	}

	/**
	 * @return array{internal:int,external:int}
	 */
	public static function link_counts( string $html, string $host ): array {
		$host     = self::host_key( $host );
		$internal = 0;
		$external = 0;
		if ( preg_match_all( '/<a\b[^>]*?(?<![\w-])href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$href = trim( '' !== ( $row[1] ?? '' ) ? $row[1] : ( $row[2] ?? '' ) );
				if ( '' === $href || preg_match( '/^(#|mailto:|tel:|javascript:)/i', $href ) ) {
					continue;
				}
				if ( preg_match( '#^(?:https?:)?//([^/:?\#]+)#i', $href, $h ) ) {
					if ( self::host_key( $h[1] ) === $host ) {
						++$internal;
					} else {
						++$external;
					}
				} else {
					++$internal;
				}
			}
		}
		return array(
			'internal' => $internal,
			'external' => $external,
		);
	}
}
