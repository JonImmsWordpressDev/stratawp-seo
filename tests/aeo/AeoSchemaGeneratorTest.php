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
}
