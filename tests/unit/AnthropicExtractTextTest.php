<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-anthropic-provider.php';

/**
 * Newer Claude models (Sonnet 5+) run adaptive thinking by default, so the
 * response `content` array can open with `thinking` blocks before the `text`
 * block. extract_text() must collect text across all blocks instead of
 * assuming content[0] is the text block (the old read made every thinking
 * response look like "Received empty response from Claude").
 */
final class AnthropicExtractTextTest extends TestCase {

	public function test_classic_text_first_response(): void {
		$content = array(
			array(
				'type' => 'text',
				'text' => 'Hello world',
			),
		);
		$this->assertSame( 'Hello world', SWPS_Anthropic_Provider::extract_text( $content ) );
	}

	public function test_thinking_block_precedes_text_block(): void {
		// Sonnet 5 shape: adaptive thinking on by default, display "omitted"
		// leaves the thinking field an empty string but the block still exists.
		$content = array(
			array(
				'type'      => 'thinking',
				'thinking'  => '',
				'signature' => 'sig_abc',
			),
			array(
				'type' => 'text',
				'text' => '{"edits":[]}',
			),
		);
		$this->assertSame( '{"edits":[]}', SWPS_Anthropic_Provider::extract_text( $content ) );
	}

	public function test_multiple_text_blocks_are_concatenated_in_order(): void {
		$content = array(
			array(
				'type' => 'text',
				'text' => 'part one, ',
			),
			array(
				'type'     => 'thinking',
				'thinking' => 'interleaved reasoning',
			),
			array(
				'type' => 'text',
				'text' => 'part two',
			),
		);
		$this->assertSame( 'part one, part two', SWPS_Anthropic_Provider::extract_text( $content ) );
	}

	public function test_thinking_only_response_yields_empty_string(): void {
		// Model spent the whole output budget thinking (stop_reason max_tokens).
		$content = array(
			array(
				'type'     => 'thinking',
				'thinking' => '',
			),
		);
		$this->assertSame( '', SWPS_Anthropic_Provider::extract_text( $content ) );
	}

	public function test_empty_and_malformed_content_is_tolerated(): void {
		$this->assertSame( '', SWPS_Anthropic_Provider::extract_text( array() ) );
		$this->assertSame(
			'ok',
			SWPS_Anthropic_Provider::extract_text(
				array(
					'not-a-block',
					array( 'type' => 'text' ), // Missing text key.
					array(
						'type' => 'text',
						'text' => 'ok',
					),
				)
			)
		);
	}
}
