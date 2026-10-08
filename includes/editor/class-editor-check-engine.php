<?php
/**
 * Pure check engine. Source of truth for the instant checks; the JS mirror in
 * src/editor/analysis/checks.js must produce identical output (golden test).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Check_Engine {

	private const TRANSITIONS = array(
		'however',
		'therefore',
		'moreover',
		'furthermore',
		'consequently',
		'meanwhile',
		'additionally',
		'similarly',
		'for example',
		'for instance',
		'in addition',
		'as a result',
		'in conclusion',
		'on the other hand',
		'in contrast',
		'finally',
		'first',
		'second',
		'third',
		'next',
		'then',
		'also',
		'because',
		'although',
		'while',
		'since',
		'instead',
		'otherwise',
		'nevertheless',
		'thus',
		'hence',
		'besides',
		'likewise',
		'specifically',
		'in fact',
		'in summary',
	);

	private const PASSIVE = '/\b(?:is|are|was|were|be|been|being)\s+(?:\w+ed|\w+en)\b/i';

	/**
	 * @param array<string,mixed> $input Raw input; see the task interface.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input ): array {
		$preset = array_merge(
			array(
				'min_words' => 300,
				'title_min' => 30,
				'title_max' => 60,
				'desc_min'  => 70,
				'desc_max'  => 160,
			),
			is_array( $input['preset'] ?? null ) ? $input['preset'] : array()
		);

		$keywords = array();
		foreach ( (array) ( $input['keywords'] ?? array() ) as $row ) {
			$row        = (array) $row;
			$keywords[] = array(
				'keyword'        => trim( (string) ( $row['keyword'] ?? '' ) ),
				'used_elsewhere' => isset( $row['used_elsewhere'] ) ? (bool) $row['used_elsewhere'] : null,
			);
		}
		if ( ! $keywords ) {
			$keywords[] = array(
				'keyword'        => '',
				'used_elsewhere' => null,
			);
		}

		$lang = (string) ( $input['lang'] ?? '' );

		return array(
			'title'            => (string) ( $input['title'] ?? '' ),
			'slug'             => (string) ( $input['slug'] ?? '' ),
			'content_html'     => (string) ( $input['content_html'] ?? '' ),
			'meta_title'       => (string) ( $input['meta_title'] ?? '' ),
			'meta_description' => (string) ( $input['meta_description'] ?? '' ),
			'lang'             => '' !== $lang ? $lang : 'en',
			'host'             => (string) ( $input['host'] ?? '' ),
			'preset'           => array_map( 'intval', $preset ),
			'keywords'         => $keywords,
		);
	}

	/**
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function run( array $input ): array {
		$in  = self::normalize( $input );
		$ctx = self::context( $in );
		$out = array(
			'global'   => self::global_checks( $in, $ctx ),
			'keywords' => array(),
		);
		foreach ( $in['keywords'] as $row ) {
			$out['keywords'][] = array(
				'keyword' => $row['keyword'],
				'results' => self::keyword_checks( $row, $in, $ctx ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $output Result of run().
	 * @return array{overall:int,seo:?int,readability:?int,status:string}
	 */
	public static function score( array $output ): array {
		$weights = SWPS_Editor_Check_Registry::DIMENSION_WEIGHTS;
		$sum     = array();
		$den     = array();
		$add     = static function ( string $dim, float $w, string $status ) use ( &$sum, &$den ): void {
			if ( 'na' === $status ) {
				return;
			}
			$v           = 'pass' === $status ? 1.0 : ( 'warn' === $status ? 0.5 : 0.0 );
			$sum[ $dim ] = ( $sum[ $dim ] ?? 0.0 ) + $w * $v;
			$den[ $dim ] = ( $den[ $dim ] ?? 0.0 ) + $w;
		};

		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'global' === $def['scope'] ) {
				$add( $def['dimension'], 1.0, $output['global'][ $def['id'] ]['status'] ?? 'na' );
				continue;
			}
			foreach ( $output['keywords'] as $i => $kw ) {
				$add( $def['dimension'], 0 === $i ? 1.0 : 0.25, $kw['results'][ $def['id'] ]['status'] ?? 'na' );
			}
		}

		$dim_score = array();
		foreach ( $weights as $dim => $w ) {
			if ( isset( $den[ $dim ] ) && $den[ $dim ] > 0 ) {
				$dim_score[ $dim ] = $sum[ $dim ] / $den[ $dim ];
			}
		}

		$overall = self::weighted( $dim_score, $weights, 'all' );
		$has_kw  = '' !== ( $output['keywords'][0]['keyword'] ?? '' );
		$score   = (int) $overall;

		if ( ! $has_kw ) {
			$status = 'no_keyword';
		} elseif ( $score >= 75 ) {
			$status = 'good';
		} elseif ( $score >= 50 ) {
			$status = 'needs_work';
		} else {
			$status = 'poor';
		}

		return array(
			'overall'     => $score,
			'seo'         => self::weighted( $dim_score, $weights, 'seo' ),
			'readability' => self::weighted( $dim_score, $weights, 'readability' ),
			'status'      => $status,
		);
	}

	/**
	 * @param array<string,float> $dim_score Score 0 to 1 per dimension.
	 * @param array<string,int>   $weights   Dimension weights.
	 */
	private static function weighted( array $dim_score, array $weights, string $subset ): ?int {
		$num = 0.0;
		$den = 0.0;
		foreach ( $weights as $dim => $w ) {
			if ( ! isset( $dim_score[ $dim ] ) ) {
				continue;
			}
			if ( 'seo' === $subset && 'readability' === $dim ) {
				continue;
			}
			if ( 'readability' === $subset && 'readability' !== $dim ) {
				continue;
			}
			$num += $w * $dim_score[ $dim ];
			$den += $w;
		}
		return $den > 0 ? self::rnd( $num / $den * 100 ) : null;
	}

	/**
	 * Round half up with plain IEEE arithmetic. PHP's round() pre-rounds and
	 * would disagree with JS Math.round on values like 28.499999999999996, so
	 * both sides use floor(x + 0.5).
	 */
	private static function rnd( float $x ): int {
		return (int) floor( $x + 0.5 );
	}

	/**
	 * @param array<string,mixed> $in Normalized input.
	 * @return array<string,mixed>
	 */
	private static function context( array $in ): array {
		$html      = $in['content_html'];
		$text      = SWPS_Editor_Text::plain( $html );
		$sentences = SWPS_Editor_Text::sentences( $text );

		return array(
			'title'      => '' !== trim( $in['meta_title'] ) ? $in['meta_title'] : $in['title'],
			'text'       => $text,
			'word_count' => count( SWPS_Editor_Text::words( $text ) ),
			'wordbased'  => ! preg_match( '/^(ja|zh|ko|th)/i', $in['lang'] ),
			'english'    => (bool) preg_match( '/^en/i', $in['lang'] ),
			'paragraphs' => SWPS_Editor_Text::paragraphs( $html ),
			'headings'   => SWPS_Editor_Text::headings( $html ),
			'alts'       => SWPS_Editor_Text::image_alts( $html ),
			'links'      => SWPS_Editor_Text::link_counts( $html, $in['host'] ),
			'sentences'  => $sentences,
			'swords'     => array_map( array( 'SWPS_Editor_Text', 'words' ), $sentences ),
		);
	}

	/**
	 * @param mixed $value Optional numeric value.
	 * @return array{status:string,value:mixed}
	 */
	private static function r( string $status, $value = null ): array {
		return array(
			'status' => $status,
			'value'  => $value,
		);
	}

	private static function has( string $haystack, string $needle_lower ): bool {
		return false !== mb_strpos( SWPS_Editor_Text::lower( $haystack ), $needle_lower );
	}

	/**
	 * @param array<string,mixed> $in  Normalized input.
	 * @param array<string,mixed> $c   Context.
	 * @return array<string,array{status:string,value:mixed}>
	 */
	private static function global_checks( array $in, array $c ): array {
		$p = $in['preset'];
		$g = array();

		$tl                = mb_strlen( trim( $c['title'] ) );
		$g['title_length'] = 0 === $tl
			? self::r( 'fail', 0 )
			: self::r( $tl >= $p['title_min'] && $tl <= $p['title_max'] ? 'pass' : 'warn', $tl );

		$dl                      = mb_strlen( trim( $in['meta_description'] ) );
		$g['description_length'] = 0 === $dl
			? self::r( 'fail', 0 )
			: self::r( $dl >= $p['desc_min'] && $dl <= $p['desc_max'] ? 'pass' : 'warn', $dl );

		if ( $c['wordbased'] ) {
			$wc                  = $c['word_count'];
			$g['content_length'] = self::r( $wc >= $p['min_words'] ? 'pass' : ( $wc * 2 >= $p['min_words'] ? 'warn' : 'fail' ), $wc );
		} else {
			$g['content_length'] = self::r( 'na' );
		}

		$g['internal_links'] = self::r( $c['links']['internal'] > 0 ? 'pass' : 'warn', $c['links']['internal'] );
		$g['external_links'] = self::r( $c['links']['external'] > 0 ? 'pass' : 'warn', $c['links']['external'] );

		if ( $c['wordbased'] && $c['word_count'] >= 300 ) {
			$max = 0;
			foreach ( (array) preg_split( '#<h[1-6]\b[^>]*>.*?</h[1-6]>#is', $in['content_html'] ) as $seg ) {
				$max = max( $max, count( SWPS_Editor_Text::words( SWPS_Editor_Text::plain( (string) $seg ) ) ) );
			}
			$g['subheading_distribution'] = self::r( $max > 300 ? 'warn' : 'pass', $max );
		} else {
			$g['subheading_distribution'] = self::r( 'na' );
		}

		if ( $c['alts'] ) {
			$missing                  = count(
				array_filter(
					$c['alts'],
					static function ( string $a ): bool {
						return '' === $a;
					}
				)
			);
			$g['image_alt_missing'] = self::r( 0 === $missing ? 'pass' : 'warn', $missing );
		} else {
			$g['image_alt_missing'] = self::r( 'na' );
		}

		$n     = count( $c['sentences'] );
		$ready = $c['wordbased'] && $n >= 3;

		if ( $ready ) {
			$long                  = count(
				array_filter(
					$c['swords'],
					static function ( array $w ): bool {
						return count( $w ) > 20;
					}
				)
			);
			$pct                   = self::rnd( $long / $n * 100 );
			$g['sentence_length'] = self::r( $pct <= 25 ? 'pass' : ( $pct <= 35 ? 'warn' : 'fail' ), $pct );
		} else {
			$g['sentence_length'] = self::r( 'na' );
		}

		if ( $c['wordbased'] && $c['paragraphs'] ) {
			$long                   = 0;
			foreach ( $c['paragraphs'] as $para ) {
				if ( count( SWPS_Editor_Text::words( $para ) ) > 150 ) {
					++$long;
				}
			}
			$g['paragraph_length'] = self::r( 0 === $long ? 'pass' : 'warn', $long );
		} else {
			$g['paragraph_length'] = self::r( 'na' );
		}

		if ( $ready && $c['english'] ) {
			$re    = '/\b(?:' . implode( '|', array_map( 'preg_quote', self::TRANSITIONS ) ) . ')\b/i';
			$hits  = 0;
			$pass  = 0;
			foreach ( $c['sentences'] as $s ) {
				if ( preg_match( $re, $s ) ) {
					++$hits;
				}
				if ( preg_match( self::PASSIVE, $s ) ) {
					++$pass;
				}
			}
			$tp                      = self::rnd( $hits / $n * 100 );
			$pp                      = self::rnd( $pass / $n * 100 );
			$g['transition_words'] = self::r( $tp >= 20 ? 'pass' : ( $tp >= 10 ? 'warn' : 'fail' ), $tp );
			$g['passive_voice']    = self::r( $pp <= 10 ? 'pass' : ( $pp <= 15 ? 'warn' : 'fail' ), $pp );
		} else {
			$g['transition_words'] = self::r( 'na' );
			$g['passive_voice']    = self::r( 'na' );
		}

		if ( $ready ) {
			$run  = 1;
			$best = 1;
			$prev = null;
			foreach ( $c['swords'] as $w ) {
				$first = SWPS_Editor_Text::lower( $w[0] );
				$run   = $first === $prev ? $run + 1 : 1;
				$best  = max( $best, $run );
				$prev  = $first;
			}
			$g['consecutive_starters'] = self::r( $best >= 3 ? 'warn' : 'pass', $best );
		} else {
			$g['consecutive_starters'] = self::r( 'na' );
		}

		return $g;
	}

	/**
	 * @param array<string,mixed> $row Keyword row.
	 * @param array<string,mixed> $in  Normalized input.
	 * @param array<string,mixed> $c   Context.
	 * @return array<string,array{status:string,value:mixed}>
	 */
	private static function keyword_checks( array $row, array $in, array $c ): array {
		$ids = array();
		foreach ( SWPS_Editor_Check_Registry::all() as $def ) {
			if ( 'keyword' === $def['scope'] ) {
				$ids[] = $def['id'];
			}
		}

		$kw = $row['keyword'];
		if ( '' === $kw ) {
			return array_fill_keys( $ids, self::r( 'na' ) );
		}

		$k   = SWPS_Editor_Text::lower( $kw );
		$res = array();

		$res['kw_in_title'] = self::r( self::has( $c['title'], $k ) ? 'pass' : 'fail' );

		$desc                    = trim( $in['meta_description'] );
		$res['kw_in_description'] = self::r( '' !== $desc && self::has( $desc, $k ) ? 'pass' : 'fail' );

		$slug_kw = SWPS_Editor_Text::slugify( $kw );
		if ( '' === $slug_kw ) {
			$res['kw_in_slug'] = self::r( 'na' );
		} else {
			$slug              = SWPS_Editor_Text::fold( SWPS_Editor_Text::lower( $in['slug'] ) );
			$res['kw_in_slug'] = self::r( false !== mb_strpos( $slug, $slug_kw ) ? 'pass' : 'fail' );
		}

		$res['kw_in_intro'] = $c['paragraphs']
			? self::r( self::has( $c['paragraphs'][0], $k ) ? 'pass' : 'fail' )
			: self::r( 'na' );

		$subs = array_values(
			array_filter(
				$c['headings'],
				static function ( array $h ): bool {
					return 2 === $h['level'] || 3 === $h['level'];
				}
			)
		);
		$hit  = false;
		foreach ( $subs as $h ) {
			if ( self::has( $h['text'], $k ) ) {
				$hit = true;
				break;
			}
		}
		$res['kw_in_subheading'] = $subs ? self::r( $hit ? 'pass' : 'fail' ) : self::r( 'na' );

		$alt_hit = false;
		foreach ( $c['alts'] as $alt ) {
			if ( self::has( $alt, $k ) ) {
				$alt_hit = true;
				break;
			}
		}
		$res['kw_in_image_alt'] = $c['alts'] ? self::r( $alt_hit ? 'pass' : 'fail' ) : self::r( 'na' );

		if ( $c['wordbased'] && $c['word_count'] > 0 ) {
			$occ     = mb_substr_count( SWPS_Editor_Text::lower( $c['text'] ), $k );
			$kwords  = max( 1, count( SWPS_Editor_Text::words( $kw ) ) );
			$percent = $occ * $kwords / $c['word_count'] * 100;
			$density = floor( $percent * 100 + 0.5 ) / 100;
			if ( 0 === $occ ) {
				$st = 'fail';
			} elseif ( $density < 0.5 ) {
				$st = 'warn';
			} elseif ( $density <= 3 ) {
				$st = 'pass';
			} else {
				$st = 'fail';
			}
			$res['kw_density'] = self::r( $st, $density );
		} else {
			$res['kw_density'] = self::r( 'na' );
		}

		$title_lower = SWPS_Editor_Text::lower( $c['title'] );
		$idx         = mb_strpos( $title_lower, $k );
		if ( false === $idx ) {
			$res['kw_title_position'] = self::r( 'na' );
		} else {
			$res['kw_title_position'] = self::r( $idx * 2 <= mb_strlen( $title_lower ) ? 'pass' : 'warn', $idx );
		}

		$res['kw_in_conclusion'] = count( $c['paragraphs'] ) >= 3
			? self::r( self::has( $c['paragraphs'][ count( $c['paragraphs'] ) - 1 ], $k ) ? 'pass' : 'warn' )
			: self::r( 'na' );

		if ( null === $row['used_elsewhere'] ) {
			$res['kw_unique'] = self::r( 'na' );
		} else {
			$res['kw_unique'] = self::r( $row['used_elsewhere'] ? 'fail' : 'pass' );
		}

		return $res;
	}
}
