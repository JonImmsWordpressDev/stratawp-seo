<?php
/**
 * Alt text quality checks: filename-like, too long, keyword-stuffed (per
 * page) and the same alt reused on many different image files (site-wide).
 *
 * All four read the `image_alts` fact from SWPS_Site_Crawler::parse_html():
 * a list of {src, alt} for images whose alt attribute is non-empty. Images
 * with no alt are SWPS_Check_Image_Missing_Alt's job, and decorative
 * alt="" is never flagged.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure alt text heuristics shared by the alt quality checks.
 */
class SWPS_Alt_Text_Quality {

	/** Alt longer than this many characters is flagged as too long. */
	public const MAX_LENGTH = 150;

	/** A word repeated this many times (or more) counts as stuffing. */
	public const STUFFED_REPEATS = 3;

	/** Comma-separated parts (or more) that make a keyword list. */
	public const KEYWORD_LIST_PARTS = 4;

	/** Max words per part for a comma-separated list to read as keywords. */
	public const KEYWORD_PART_WORDS = 3;

	/** The same alt on this many different image files (or more) is flagged. */
	public const DUPLICATE_MIN_FILES = 5;

	/** Words ignored by the repeated-word rule. */
	private const STOP_WORDS = array(
		'the',
		'and',
		'for',
		'with',
		'from',
		'this',
		'that',
		'are',
		'was',
		'were',
		'its',
		'his',
		'her',
		'their',
		'our',
		'your',
		'you',
		'has',
		'have',
		'had',
		'not',
		'but',
		'into',
		'onto',
		'over',
		'under',
		'than',
		'then',
		'who',
		'what',
		'which',
		'while',
		'one',
		'two',
	);

	/**
	 * Trim and collapse internal whitespace.
	 *
	 * @param string $alt Raw alt attribute value.
	 */
	public static function normalize( string $alt ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $alt ) );
	}

	/**
	 * True when the alt looks like a file name or a camera/OS default name
	 * rather than a description.
	 *
	 * @param string $alt Alt text.
	 */
	public static function looks_like_filename( string $alt ): bool {
		$alt = self::normalize( $alt );
		if ( '' === $alt ) {
			return false;
		}
		// Ends in an image file extension: "photo.jpg", "hero-banner.webp".
		if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic|heif)$/i', $alt ) ) {
			return true;
		}
		// Camera and phone defaults: IMG_1234, DSC0001, DSCN0042, PXL_20260101_..., GOPR0001.
		if ( preg_match( '/^(img|dsc[nf]?|dcim|pxl|mvimg|gopr|dji|sam)[\s_-]?\d{3,}/i', $alt ) ) {
			return true;
		}
		// OS screenshot defaults: "screenshot-2026-01-02", "Screenshot 2026-01-02 at 10.00.00", "Screen Shot 2020-...".
		if ( preg_match( '/^screen[\s_-]?shot[\s_-]*\d/i', $alt ) ) {
			return true;
		}
		// Slug-like: no spaces, joined by an underscore or by two or more
		// hyphens ("blue-running-shoes", "hero_image"). A single hyphen is
		// allowed so ordinary words like "T-shirt" or "e-commerce" pass.
		if ( ! str_contains( $alt, ' ' ) && preg_match( '/^[\p{L}\p{N}]+([_-][\p{L}\p{N}]+)+$/u', $alt ) ) {
			return str_contains( $alt, '_' ) || substr_count( $alt, '-' ) >= 2;
		}
		return false;
	}

	/**
	 * True when the alt is longer than MAX_LENGTH characters.
	 *
	 * @param string $alt Alt text.
	 */
	public static function is_too_long( string $alt ): bool {
		return mb_strlen( self::normalize( $alt ) ) > self::MAX_LENGTH;
	}

	/**
	 * True when the alt repeats the same word (3+ letters, not a stop word)
	 * STUFFED_REPEATS or more times, or is a comma-separated keyword list.
	 *
	 * @param string $alt Alt text.
	 */
	public static function is_stuffed( string $alt ): bool {
		$alt = mb_strtolower( self::normalize( $alt ) );
		if ( '' === $alt ) {
			return false;
		}

		preg_match_all( '/[\p{L}\p{N}]+/u', $alt, $m );
		$counts = array();
		foreach ( $m[0] as $word ) {
			if ( mb_strlen( $word ) < 3 || in_array( $word, self::STOP_WORDS, true ) || ! preg_match( '/\p{L}/u', $word ) ) {
				continue;
			}
			$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
			if ( $counts[ $word ] >= self::STUFFED_REPEATS ) {
				return true;
			}
		}

		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $alt ) ), 'strlen' ) );
		if ( count( $parts ) < self::KEYWORD_LIST_PARTS ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( count( preg_split( '/\s+/u', $part ) ) > self::KEYWORD_PART_WORDS ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Image URL reduced to the underlying file: no query or fragment, and no
	 * WordPress -{W}x{H} rendition suffix, so every size of one upload counts
	 * as the same file.
	 *
	 * @param string $src Absolute image URL.
	 */
	public static function file_key( string $src ): string {
		$src = (string) preg_replace( '/[?#].*$/', '', $src );
		return strtolower( (string) preg_replace( '#-\d+x\d+(\.[a-z0-9]{3,4})$#i', '$1', $src ) );
	}

	/**
	 * Offending images on one page for a predicate, deduped by src.
	 *
	 * @param array    $image_alts List of {src, alt} (parse_html()'s image_alts).
	 * @param callable $test       Predicate on the alt string.
	 * @return array{images: string[], alts: array<string, string>} Srcs, and src => alt.
	 */
	public static function offenders( array $image_alts, callable $test ): array {
		$alts = array();
		foreach ( $image_alts as $entry ) {
			$src = (string) ( $entry['src'] ?? '' );
			$alt = self::normalize( (string) ( $entry['alt'] ?? '' ) );
			if ( '' === $src || '' === $alt || isset( $alts[ $src ] ) ) {
				continue;
			}
			if ( $test( $alt ) ) {
				$alts[ $src ] = $alt;
			}
		}
		return array(
			'images' => array_keys( $alts ),
			'alts'   => $alts,
		);
	}
}

/**
 * Shared per-page behaviour for the alt quality checks: one issue per page
 * listing each offending image src and its alt.
 */
abstract class SWPS_Alt_Quality_Page_Check extends SWPS_Crawl_Check {

	/**
	 * Whether this alt fails the check.
	 *
	 * @param string $alt Normalized, non-empty alt text.
	 */
	abstract protected function fails( string $alt ): bool;

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Per-page check.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		$found = SWPS_Alt_Text_Quality::offenders(
			(array) ( $page['image_alts'] ?? array() ),
			fn( string $alt ): bool => $this->fails( $alt )
		);
		if ( array() === $found['images'] ) {
			return null;
		}
		return $this->issue( (string) $page['url'], $found );
	}
}

/**
 * Flags images whose alt text is a file name or a camera default.
 */
class SWPS_Check_Image_Alt_Filename extends SWPS_Alt_Quality_Page_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'image_alt_filename';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'warning';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Image alt text looks like a file name', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Replace file names and camera defaults such as IMG_1234 or screenshot-2026-01-02 with a short description of what the image shows.', 'stratawp-seo' );
	}

	/**
	 * Whether this alt fails the check.
	 *
	 * @param string $alt Alt text.
	 */
	protected function fails( string $alt ): bool {
		return SWPS_Alt_Text_Quality::looks_like_filename( $alt );
	}
}

/**
 * Flags images whose alt text is longer than 150 characters.
 */
class SWPS_Check_Image_Alt_Too_Long extends SWPS_Alt_Quality_Page_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'image_alt_too_long';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Image alt text is too long', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Keep alt text under 150 characters. Describe what matters in the image, and move longer explanations into a caption or the surrounding text.', 'stratawp-seo' );
	}

	/**
	 * Whether this alt fails the check.
	 *
	 * @param string $alt Alt text.
	 */
	protected function fails( string $alt ): bool {
		return SWPS_Alt_Text_Quality::is_too_long( $alt );
	}
}

/**
 * Flags images whose alt text repeats a word or is a keyword list.
 */
class SWPS_Check_Image_Alt_Stuffed extends SWPS_Alt_Quality_Page_Check {

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'image_alt_stuffed';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'warning';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'Image alt text looks keyword-stuffed', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Write alt text as a plain description of the image. Repeating a keyword or listing keywords separated by commas reads as spam to search engines and is no help to screen reader users.', 'stratawp-seo' );
	}

	/**
	 * Whether this alt fails the check.
	 *
	 * @param string $alt Alt text.
	 */
	protected function fails( string $alt ): bool {
		return SWPS_Alt_Text_Quality::is_stuffed( $alt );
	}
}

/**
 * Flags the same non-empty alt text used on 5+ different image files
 * across the crawled site. The same file reused on many pages is fine.
 */
class SWPS_Check_Image_Alt_Duplicate_Sitewide extends SWPS_Crawl_Check {

	/** Cap on the other pages listed per issue, to keep detail rows small. */
	private const MAX_OTHER_PAGES = 20;

	/**
	 * Unique check identifier.
	 */
	public function id(): string {
		return 'image_alt_duplicate_sitewide';
	}

	/**
	 * Severity level.
	 */
	public function severity(): string {
		return 'notice';
	}

	/**
	 * Human-readable title.
	 */
	public function title(): string {
		return __( 'The same alt text is used on many different images', 'stratawp-seo' );
	}

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	public function how_to_fix(): string {
		return __( 'Give each different image its own alt text that describes it. Generic alt text repeated across many images tells search engines and screen reader users nothing about any of them.', 'stratawp-seo' );
	}

	/**
	 * Pure core: alt texts used on DUPLICATE_MIN_FILES+ different image files.
	 *
	 * Skips redirect/error rows and challenge pages, like the other duplicate
	 * checks. Alt matching is case-insensitive.
	 *
	 * @param array $rows Page rows with a decoded image_alts list.
	 * @return array<string, array{alt: string, files: int, pages: array<string, string[]>}>
	 *               Lower-cased alt => display alt, distinct file count, and page URL => srcs on that page.
	 */
	public static function find_duplicates( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['status_code'] ?? 0 ) >= 300 ) {
				continue;
			}
			if ( SWPS_Crawl_Page_Flags::has( (int) ( $row['flags'] ?? 0 ), SWPS_Crawl_Page_Flags::IS_CHALLENGE ) ) {
				continue;
			}
			$url = (string) ( $row['url'] ?? '' );
			foreach ( (array) ( $row['image_alts'] ?? array() ) as $entry ) {
				$src = (string) ( $entry['src'] ?? '' );
				$alt = SWPS_Alt_Text_Quality::normalize( (string) ( $entry['alt'] ?? '' ) );
				if ( '' === $src || '' === $alt ) {
					continue;
				}
				$key = mb_strtolower( $alt );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'alt'   => $alt,
						'files' => array(),
						'pages' => array(),
					);
				}
				$groups[ $key ]['files'][ SWPS_Alt_Text_Quality::file_key( $src ) ] = true;
				if ( ! in_array( $src, $groups[ $key ]['pages'][ $url ] ?? array(), true ) ) {
					$groups[ $key ]['pages'][ $url ][] = $src;
				}
			}
		}

		$out = array();
		foreach ( $groups as $key => $group ) {
			$files = count( $group['files'] );
			if ( $files >= SWPS_Alt_Text_Quality::DUPLICATE_MIN_FILES ) {
				$out[ $key ] = array(
					'alt'   => $group['alt'],
					'files' => $files,
					'pages' => $group['pages'],
				);
			}
		}
		return $out;
	}

	/**
	 * Aggregate check over a finished run: one issue per page that uses a
	 * duplicated alt, listing that page's images and the other pages.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		$issues = array();
		foreach ( self::find_duplicates( SWPS_Crawl_Issues::pages_for_run( $run_id ) ) as $group ) {
			$urls = array_keys( $group['pages'] );
			foreach ( $group['pages'] as $url => $srcs ) {
				$issues[] = $this->issue(
					(string) $url,
					array(
						'alt'        => $group['alt'],
						'images'     => $srcs,
						'file_count' => $group['files'],
						'also_on'    => array_slice( array_values( array_diff( $urls, array( $url ) ) ), 0, self::MAX_OTHER_PAGES ),
					)
				);
			}
		}
		return $issues;
	}
}
