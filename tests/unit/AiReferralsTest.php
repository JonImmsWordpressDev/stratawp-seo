<?php
/**
 * Tests for SWPS_AI_Referrals — classifier, bot source map, engagement comparison.
 *
 * Pure-static core: no WordPress functions required. The runtime wrapper
 * classify_visit() uses apply_filters(), so we stub it here; the core
 * classify() method is WP-free and tested directly.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

// Stub WordPress functions used only in the runtime wrapper, not in the core.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Thin wrapper around parse_url() that mirrors the WP function signature.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* constant, or -1 for the full array.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once __DIR__ . '/../../includes/class-ai-referrals.php';

/**
 * @covers SWPS_AI_Referrals
 */
class AiReferralsTest extends TestCase {

	// -------------------------------------------------------------------------
	// classify() — referrer matching
	// -------------------------------------------------------------------------

	public function test_exact_host_match_returns_source(): void {
		$this->assertSame( 'chatgpt', SWPS_AI_Referrals::classify( 'https://chatgpt.com/c/abc' ) );
	}

	public function test_subdomain_suffix_match(): void {
		$this->assertSame( 'chatgpt', SWPS_AI_Referrals::classify( 'https://www.chatgpt.com/share/xyz' ) );
	}

	public function test_case_insensitive_host(): void {
		$this->assertSame( 'chatgpt', SWPS_AI_Referrals::classify( 'https://ChatGPT.COM/c/abc' ) );
	}

	public function test_perplexity_referrer(): void {
		$this->assertSame( 'perplexity', SWPS_AI_Referrals::classify( 'https://perplexity.ai/search/foo' ) );
	}

	public function test_claude_referrer(): void {
		$this->assertSame( 'claude', SWPS_AI_Referrals::classify( 'https://claude.ai/chat/abc' ) );
	}

	public function test_gemini_referrer(): void {
		$this->assertSame( 'gemini', SWPS_AI_Referrals::classify( 'https://gemini.google.com/app' ) );
	}

	public function test_copilot_referrer(): void {
		$this->assertSame( 'copilot', SWPS_AI_Referrals::classify( 'https://copilot.microsoft.com/' ) );
	}

	public function test_you_referrer(): void {
		$this->assertSame( 'you', SWPS_AI_Referrals::classify( 'https://you.com/search?q=test' ) );
	}

	public function test_deepseek_referrer(): void {
		$this->assertSame( 'deepseek', SWPS_AI_Referrals::classify( 'https://chat.deepseek.com/' ) );
	}

	public function test_grok_referrer(): void {
		$this->assertSame( 'grok', SWPS_AI_Referrals::classify( 'https://grok.com/chat' ) );
	}

	public function test_meta_ai_referrer(): void {
		$this->assertSame( 'meta', SWPS_AI_Referrals::classify( 'https://meta.ai/chat' ) );
	}

	public function test_chat_openai_maps_to_chatgpt(): void {
		$this->assertSame( 'chatgpt', SWPS_AI_Referrals::classify( 'https://chat.openai.com/share/abc' ) );
	}

	public function test_non_ai_referrer_returns_empty(): void {
		$this->assertSame( '', SWPS_AI_Referrals::classify( 'https://google.com/search?q=foo' ) );
	}

	public function test_partial_host_match_not_accepted(): void {
		// notchatgpt.com must NOT match chatgpt.com.
		$this->assertSame( '', SWPS_AI_Referrals::classify( 'https://notchatgpt.com/' ) );
	}

	public function test_empty_referrer_no_page_url_returns_empty(): void {
		$this->assertSame( '', SWPS_AI_Referrals::classify( '' ) );
	}

	public function test_invalid_referrer_url_returns_empty(): void {
		$this->assertSame( '', SWPS_AI_Referrals::classify( 'not a url' ) );
	}

	// -------------------------------------------------------------------------
	// classify() — page_url utm_source fallback
	// -------------------------------------------------------------------------

	public function test_utm_source_chatgpt_dot_com_maps_to_chatgpt(): void {
		$this->assertSame(
			'chatgpt',
			SWPS_AI_Referrals::classify( '', 'https://example.com/post/?utm_source=chatgpt.com' )
		);
	}

	public function test_utm_source_perplexity_ai_maps_to_perplexity(): void {
		$this->assertSame(
			'perplexity',
			SWPS_AI_Referrals::classify( '', 'https://example.com/post/?utm_source=perplexity.ai' )
		);
	}

	public function test_utm_source_bare_source_value_maps(): void {
		// utm_source=chatgpt (bare source value, not a host) should resolve.
		$this->assertSame(
			'chatgpt',
			SWPS_AI_Referrals::classify( '', 'https://example.com/post/?utm_source=chatgpt' )
		);
	}

	public function test_utm_source_takes_precedence_when_referrer_empty(): void {
		$this->assertSame(
			'claude',
			SWPS_AI_Referrals::classify( '', 'https://example.com/?utm_source=claude.ai' )
		);
	}

	public function test_referrer_wins_over_utm_source(): void {
		// If referrer is set and matches, it takes priority (utm_source irrelevant).
		$this->assertSame(
			'perplexity',
			SWPS_AI_Referrals::classify(
				'https://perplexity.ai/search',
				'https://example.com/?utm_source=chatgpt.com'
			)
		);
	}

	public function test_utm_source_unknown_value_returns_empty(): void {
		$this->assertSame(
			'',
			SWPS_AI_Referrals::classify( '', 'https://example.com/?utm_source=unknownbot' )
		);
	}

	public function test_no_utm_source_in_page_url_returns_empty(): void {
		$this->assertSame( '', SWPS_AI_Referrals::classify( '', 'https://example.com/post/' ) );
	}

	// -------------------------------------------------------------------------
	// classify() — custom map override
	// -------------------------------------------------------------------------

	public function test_custom_map_overrides_default(): void {
		$map = array( 'custom-ai.example' => 'custom_ai' );
		$this->assertSame( 'custom_ai', SWPS_AI_Referrals::classify( 'https://custom-ai.example/chat', '', $map ) );
	}

	public function test_custom_map_ignores_default_entries(): void {
		$map = array( 'custom-ai.example' => 'custom_ai' );
		// chatgpt.com is NOT in the custom map, so it should return ''.
		$this->assertSame( '', SWPS_AI_Referrals::classify( 'https://chatgpt.com/', '', $map ) );
	}

	// -------------------------------------------------------------------------
	// bot_source_map()
	// -------------------------------------------------------------------------

	public function test_bot_source_map_returns_array(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertIsArray( $map );
	}

	public function test_gptbot_maps_to_chatgpt(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'chatgpt', $map['gptbot'] );
	}

	public function test_oai_searchbot_maps_to_chatgpt(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'chatgpt', $map['oai_searchbot'] );
	}

	public function test_chatgpt_user_maps_to_chatgpt(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'chatgpt', $map['chatgpt_user'] );
	}

	public function test_claudebot_maps_to_claude(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'claude', $map['claudebot'] );
	}

	public function test_claude_web_maps_to_claude(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'claude', $map['claude_web'] );
	}

	public function test_anthropic_ai_maps_to_claude(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'claude', $map['anthropic_ai'] );
	}

	public function test_perplexitybot_maps_to_perplexity(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'perplexity', $map['perplexitybot'] );
	}

	public function test_perplexity_user_maps_to_perplexity(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'perplexity', $map['perplexity_user'] );
	}

	public function test_google_extended_maps_to_gemini(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'gemini', $map['google_extended'] );
	}

	public function test_bots_without_visit_counterpart_omitted(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		// applebot_extended, ccbot, bytespider, amazonbot, duckassistbot
		// have no visit-side counterpart and should not be in the map.
		$this->assertArrayNotHasKey( 'applebot_extended', $map );
		$this->assertArrayNotHasKey( 'ccbot', $map );
		$this->assertArrayNotHasKey( 'bytespider', $map );
		$this->assertArrayNotHasKey( 'amazonbot', $map );
		$this->assertArrayNotHasKey( 'duckassistbot', $map );
	}

	public function test_meta_externalagent_maps_to_meta(): void {
		$map = SWPS_AI_Referrals::bot_source_map();
		$this->assertSame( 'meta', $map['meta_externalagent'] );
	}

	// -------------------------------------------------------------------------
	// engagement_comparison()
	// -------------------------------------------------------------------------

	public function test_returns_null_when_ai_sample_below_min(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 10, 'avg_time' => 120, 'avg_scroll' => 50, 'bounce_rate' => 0.4 ),
			array( 'views' => 500, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);
		$this->assertNull( $result );
	}

	public function test_returns_null_when_organic_sample_below_min(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 500, 'avg_time' => 120, 'avg_scroll' => 50, 'bounce_rate' => 0.4 ),
			array( 'views' => 5, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);
		$this->assertNull( $result );
	}

	public function test_returns_null_when_both_samples_below_min(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 10, 'avg_time' => 120, 'avg_scroll' => 50, 'bounce_rate' => 0.4 ),
			array( 'views' => 10, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);
		$this->assertNull( $result );
	}

	public function test_returns_ratio_metrics_when_sufficient_sample(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 100, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 500, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'time_ratio', $result );
		$this->assertArrayHasKey( 'scroll_ratio', $result );
		$this->assertArrayHasKey( 'bounce_ratio', $result );
	}

	public function test_time_ratio_calculated_correctly(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 100, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 100, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);

		// 120/80 = 1.5.
		$this->assertEqualsWithDelta( 1.5, $result['time_ratio'], 0.001 );
	}

	public function test_scroll_ratio_calculated_correctly(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 100, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 100, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);

		// 60/40 = 1.5.
		$this->assertEqualsWithDelta( 1.5, $result['scroll_ratio'], 0.001 );
	}

	public function test_bounce_ratio_calculated_correctly(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 100, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 100, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);

		// 0.3/0.6 = 0.5.
		$this->assertEqualsWithDelta( 0.5, $result['bounce_ratio'], 0.001 );
	}

	public function test_exact_min_n_threshold_is_sufficient(): void {
		// Exactly 30 should not return null.
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 30, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 30, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);
		$this->assertNotNull( $result );
	}

	public function test_result_includes_ai_and_organic_counts(): void {
		$result = SWPS_AI_Referrals::engagement_comparison(
			array( 'views' => 100, 'avg_time' => 120, 'avg_scroll' => 60, 'bounce_rate' => 0.3 ),
			array( 'views' => 500, 'avg_time' => 80, 'avg_scroll' => 40, 'bounce_rate' => 0.6 ),
			30
		);

		$this->assertArrayHasKey( 'ai_n', $result );
		$this->assertArrayHasKey( 'organic_n', $result );
		$this->assertSame( 100, $result['ai_n'] );
		$this->assertSame( 500, $result['organic_n'] );
	}

	// -------------------------------------------------------------------------
	// classify_visit() — runtime wrapper applies filter
	// -------------------------------------------------------------------------

	public function test_classify_visit_returns_source_via_default_map(): void {
		// apply_filters is stubbed to return the value unchanged above.
		$this->assertSame( 'chatgpt', SWPS_AI_Referrals::classify_visit( 'https://chatgpt.com/c/abc' ) );
	}

	public function test_classify_visit_returns_empty_for_non_ai(): void {
		$this->assertSame( '', SWPS_AI_Referrals::classify_visit( 'https://example.com/' ) );
	}
}
