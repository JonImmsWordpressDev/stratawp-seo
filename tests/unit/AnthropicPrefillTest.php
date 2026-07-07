<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-anthropic-provider.php';

/**
 * Assistant-message prefill was removed in the Claude 4.6 family; every model
 * since rejects it with a 400 ("This model does not support assistant message
 * prefill"). model_supports_prefill() must allowlist only the older,
 * prefill-capable generations.
 */
final class AnthropicPrefillTest extends TestCase {

	/** @dataProvider prefill_capable_models */
	public function test_older_models_support_prefill( string $model ): void {
		$this->assertTrue( SWPS_Anthropic_Provider::model_supports_prefill( $model ), $model );
	}

	public function prefill_capable_models(): array {
		return array(
			array( 'claude-2.1' ),
			array( 'claude-3-haiku-20240307' ),
			array( 'claude-3-5-sonnet-20241022' ),
			array( 'claude-3-7-sonnet-20250219' ),
			array( 'claude-sonnet-4-20250514' ),
			array( 'claude-opus-4-20250514' ),
			array( 'claude-opus-4-0' ),
			array( 'claude-opus-4-1-20250805' ),
			array( 'claude-sonnet-4-5' ),
			array( 'claude-sonnet-4-5-20250929' ),
			array( 'claude-opus-4-5-20251101' ),
			array( 'claude-haiku-4-5' ),
			array( 'claude-haiku-4-5-20251001' ),
		);
	}

	/** @dataProvider prefill_rejecting_models */
	public function test_newer_models_do_not_support_prefill( string $model ): void {
		$this->assertFalse( SWPS_Anthropic_Provider::model_supports_prefill( $model ), $model );
	}

	public function prefill_rejecting_models(): array {
		return array(
			array( 'claude-sonnet-4-6' ),
			array( 'claude-opus-4-6' ),
			array( 'claude-opus-4-7' ),
			array( 'claude-opus-4-8' ),
			// Bare 5-series aliases: the old denylist regex required a hyphen
			// after the version ("5-"), letting these through and causing the
			// prefill 400 reported with Sonnet 5 selected.
			array( 'claude-sonnet-5' ),
			array( 'claude-fable-5' ),
			array( 'claude-haiku-5' ),
			// Hypothetical dated 5-series / future IDs must also skip prefill.
			array( 'claude-sonnet-5-20260901' ),
			array( 'claude-opus-4-9' ),
		);
	}
}
