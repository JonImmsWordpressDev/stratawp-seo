<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-aeo-schema-generator.php';
require_once __DIR__ . '/FakeAIProvider.php';

final class AeoSchemaGeneratorTest extends TestCase {

	private function fields_path(): string {
		return __DIR__ . '/../../includes/data/aeo-schema-fields.json';
	}

	public function test_generate_recipe_returns_valid_jsonld(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'Recipe',
			'name'               => 'Chocolate Chip Cookies',
			'recipeIngredient'   => array( '2 cups flour', '1 cup sugar' ),
			'recipeInstructions' => array(
				array( '@type' => 'HowToStep', 'text' => 'Cream butter and sugar.' ),
				array( '@type' => 'HowToStep', 'text' => 'Bake at 180°C.' ),
			),
		);

		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'recipe', 'My Cookies', '<p>...</p>' );

		$this->assertSame( 'Recipe', $r['json']['@type'] );
		$this->assertSame( 'https://schema.org', $r['json']['@context'] );
		$this->assertNull( $r['error'] );
	}

	public function test_validation_rejects_missing_required_field(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'Recipe',
			// Missing 'name' (required).
			'recipeIngredient'   => array( '...' ),
			'recipeInstructions' => array( array( '@type' => 'HowToStep', 'text' => '...' ) ),
		);
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertStringContainsString( 'name', (string) $r['error'] );
	}

	public function test_validation_rejects_wrong_type(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'           => 'https://schema.org',
			'@type'              => 'Article',
			'name'               => 'X',
			'recipeIngredient'   => array(),
			'recipeInstructions' => array(),
		);
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertStringContainsString( '@type', (string) $r['error'] );
	}

	public function test_validation_rejects_wrong_context(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'           => 'https://example.com',
			'@type'              => 'Recipe',
			'name'               => 'X',
			'recipeIngredient'   => array( 'a' ),
			'recipeInstructions' => array( array( '@type' => 'HowToStep', 'text' => 't' ) ),
		);
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertStringContainsString( '@context', (string) $r['error'] );
	}

	public function test_unknown_type_returns_error(): void {
		$gen = new SWPS_AEO_Schema_Generator( new FakeAIProvider(), $this->fields_path() );
		$r   = $gen->generate( 'event', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertSame( 'unsupported_type', $r['error'] );
	}

	public function test_provider_failure_returns_error(): void {
		$provider = new FakeAIProvider();
		$provider->should_fail = true;
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'recipe', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertStringContainsString( 'AI provider error', (string) $r['error'] );
	}

	public function test_qapage_prompt_includes_main_entity_shape_guidance(): void {
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				'name'           => 'How long does sourdough need to ferment?',
				'text'           => 'How long does sourdough need to ferment?',
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => '4-6 hours at room temperature.',
				),
			),
		);
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'qapage', 'How long does sourdough ferment?', '<p>4-6 hours.</p>' );

		// The prompt must teach the AI the Question shape so mainEntity isn't empty.
		$this->assertStringContainsString( 'mainEntity should be a Question object', $provider->last_user );
		$this->assertStringContainsString( 'acceptedAnswer', $provider->last_user );

		// Required-vs-recommended distinction must be explicit (regression for the
		// "Use empty arrays for fields you cannot derive" instruction that conflicted
		// with the validator's reject-empty-required-fields behavior).
		$this->assertStringContainsString( 'REQUIRED fields', $provider->last_user );
		$this->assertStringContainsString( 'never empty', $provider->last_user );

		// And a well-formed QAPage passes validation end-to-end.
		$this->assertSame( 'QAPage', $r['json']['@type'] );
		$this->assertNull( $r['error'] );
	}

	public function test_qapage_with_empty_main_entity_still_rejected(): void {
		// Regression for the live bug: AI returned mainEntity:[] for a QAPage,
		// the validator (correctly) flagged it as "empty required field: mainEntity".
		// The prompt fix reduces the likelihood; the validator still has the final say.
		$provider = new FakeAIProvider();
		$provider->next_response = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => array(),
		);
		$gen = new SWPS_AEO_Schema_Generator( $provider, $this->fields_path() );
		$r   = $gen->generate( 'qapage', 'X', '<p>Y</p>' );

		$this->assertNull( $r['json'] );
		$this->assertStringContainsString( 'mainEntity', (string) $r['error'] );
	}
}
