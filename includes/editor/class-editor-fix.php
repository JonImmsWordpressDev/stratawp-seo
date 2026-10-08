<?php
/**
 * Pure prompt building and proposal validation for editor fixes.
 *
 * No WordPress calls. The REST layer calls the provider and the budget guard;
 * this class decides whether what came back is safe to show the writer.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Editor_Fix {

	private const KINDS = array(
		'kw_in_title'      => 'meta_title',
		'kw_in_description' => 'meta_description',
		'kw_in_intro'      => 'paragraph',
		'kw_in_conclusion' => 'paragraph',
		'aeo_answer'       => 'insert',
	);

	private const SYSTEM = 'You are a careful SEO editor. Reply with JSON only, in exactly this form: {"value": "<text>"}. No commentary. If you cannot do the task confidently, reply {"value": ""}.';

	/**
	 * @return string[]
	 */
	public static function check_ids(): array {
		return array_keys( self::KINDS );
	}

	public static function supports( string $check_id ): bool {
		return isset( self::KINDS[ $check_id ] );
	}

	public static function kind( string $check_id ): string {
		return self::KINDS[ $check_id ] ?? '';
	}

	public static function which( string $check_id ): string {
		return 'kw_in_conclusion' === $check_id ? 'last' : 'first';
	}

	/**
	 * True when the paragraph the writer is looking at is still the first or
	 * last paragraph of the current content.
	 */
	public static function target_matches( string $content_html, string $check_id, string $target ): bool {
		$paras = SWPS_Editor_Text::paragraphs( $content_html );
		if ( ! $paras ) {
			return false;
		}
		$expected = 'last' === self::which( $check_id ) ? $paras[ count( $paras ) - 1 ] : $paras[0];
		return $expected === $target;
	}

	/**
	 * @param array<string,string> $ctx Context: title, meta_title, meta_description, target_text, question, lang.
	 * @return array{system:string,user:string}
	 */
	public static function build_prompt( string $check_id, string $keyword, array $ctx ): array {
		$lang  = '' !== ( $ctx['lang'] ?? '' ) ? $ctx['lang'] : 'en';
		$title = '' !== trim( $ctx['meta_title'] ?? '' ) ? trim( $ctx['meta_title'] ) : trim( $ctx['title'] ?? '' );

		switch ( self::kind( $check_id ) ) {
			case 'meta_title':
				$user = "Rewrite this page title so it naturally contains the exact keyword \"{$keyword}\", ideally near the start. Keep the meaning and keep it under 60 characters.\nCurrent title: {$title}\nLanguage: {$lang}";
				break;
			case 'meta_description':
				$user = "Write a meta description of 120 to 155 characters that contains the exact keyword \"{$keyword}\" and gives a reason to click.\nPage title: {$title}\nCurrent description: " . trim( $ctx['meta_description'] ?? '' ) . "\nLanguage: {$lang}";
				break;
			case 'paragraph':
				$user = "Rewrite this paragraph so it naturally contains the exact keyword \"{$keyword}\". Keep the meaning, keep every fact and keep the length within 30 percent. You may use only <a>, <strong> and <em> tags.\nLanguage: {$lang}\nParagraph:\n" . ( $ctx['target_text'] ?? '' );
				break;
			default:
				$user = "Answer this question in 2 to 4 plain sentences, under 80 words, for an article titled \"{$title}\" about \"{$keyword}\". Use only facts you are confident about. You may use only <a>, <strong> and <em> tags.\nLanguage: {$lang}\nQuestion: " . ( $ctx['question'] ?? '' );
				break;
		}

		return array(
			'system' => self::SYSTEM,
			'user'   => $user,
		);
	}

	/**
	 * @param array<string,string> $ctx    Same context as build_prompt().
	 * @param mixed                $parsed Decoded provider JSON.
	 * @return array<string,mixed>
	 */
	public static function validate( string $check_id, string $keyword, array $ctx, $parsed ): array {
		$kind  = self::kind( $check_id );
		$value = ( is_array( $parsed ) && isset( $parsed['value'] ) && is_string( $parsed['value'] ) ) ? trim( $parsed['value'] ) : '';

		if ( '' === $value ) {
			return self::bad( 'swps_fix_empty', __( 'The AI did not return usable text. Try again.', 'stratawp-seo' ) );
		}
		if ( preg_match( '/<\s*(?:script|iframe|style|object|embed)\b/i', $value ) ) {
			return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
		}

		$plain  = SWPS_Editor_Text::plain( $value );
		$has_kw = '' !== $keyword && false !== mb_strpos( SWPS_Editor_Text::lower( $plain ), SWPS_Editor_Text::lower( $keyword ) );

		switch ( $kind ) {
			case 'meta_title':
				$original = '' !== trim( $ctx['meta_title'] ?? '' ) ? trim( $ctx['meta_title'] ) : trim( $ctx['title'] ?? '' );
				if ( mb_strlen( $plain ) > 70 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested title is too long.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$value   = $plain;
				$compare = $plain;
				break;

			case 'meta_description':
				$original = trim( $ctx['meta_description'] ?? '' );
				$len      = mb_strlen( $plain );
				if ( $len < 50 || $len > 170 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested description is the wrong length.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$value   = $plain;
				$compare = $plain;
				break;

			case 'paragraph':
				$original = trim( $ctx['target_text'] ?? '' );
				if ( strip_tags( $value, '<a><strong><em>' ) !== $value ) {
					return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
				}
				$before = count( SWPS_Editor_Text::words( $original ) );
				$after  = count( SWPS_Editor_Text::words( $plain ) );
				if ( $before > 0 && ( $after * 2 < $before || $after > $before * 3 ) ) {
					return self::bad( 'swps_fix_length', __( 'The suggested paragraph is much longer or shorter than the original.', 'stratawp-seo' ) );
				}
				if ( ! $has_kw ) {
					return self::bad( 'swps_fix_keyword', __( 'The suggestion does not contain the keyword.', 'stratawp-seo' ) );
				}
				$compare = $plain;
				break;

			default:
				$original = '';
				if ( strip_tags( $value, '<a><strong><em>' ) !== $value ) {
					return self::bad( 'swps_fix_unsafe', __( 'The suggestion contained markup that is not allowed.', 'stratawp-seo' ) );
				}
				if ( count( SWPS_Editor_Text::words( $plain ) ) > 90 ) {
					return self::bad( 'swps_fix_length', __( 'The suggested answer is too long.', 'stratawp-seo' ) );
				}
				$compare = '';
				break;
		}

		if ( '' !== $original && $compare === $original ) {
			return self::bad( 'swps_fix_noop', __( 'The suggestion is the same as the current text.', 'stratawp-seo' ) );
		}

		return array(
			'ok'       => true,
			'proposal' => array(
				'check_id' => $check_id,
				'kind'     => $kind,
				'value'    => $value,
				'original' => $original,
			),
		);
	}

	/**
	 * @return array{ok:false,code:string,message:string}
	 */
	private static function bad( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		);
	}
}
