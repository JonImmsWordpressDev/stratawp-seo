<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-anthropic-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-openai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-google-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-xai-provider.php';

final class RemoteModelsParserTest extends TestCase {

	public function test_anthropic_maps_id_to_display_name(): void {
		$body = array( 'data' => array(
			array( 'id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8', 'type' => 'model' ),
		) );
		$this->assertSame(
			array( 'claude-opus-4-8' => 'Claude Opus 4.8' ),
			SWPS_Anthropic_Provider::parse_models_response( $body )
		);
	}

	public function test_openai_keeps_chat_models_only(): void {
		$body = array( 'data' => array(
			array( 'id' => 'gpt-5' ),
			array( 'id' => 'o4-mini' ),
			array( 'id' => 'text-embedding-3-large' ),
			array( 'id' => 'whisper-1' ),
			array( 'id' => 'dall-e-3' ),
		) );
		$out = SWPS_OpenAI_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'gpt-5', $out );
		$this->assertArrayHasKey( 'o4-mini', $out );
		$this->assertArrayNotHasKey( 'text-embedding-3-large', $out );
		$this->assertArrayNotHasKey( 'whisper-1', $out );
		$this->assertArrayNotHasKey( 'dall-e-3', $out );
	}

	public function test_google_keeps_generatecontent_only(): void {
		$body = array( 'models' => array(
			array( 'name' => 'models/gemini-3.0-pro', 'displayName' => 'Gemini 3.0 Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/text-embedding-004', 'displayName' => 'Embedding 004', 'supportedGenerationMethods' => array( 'embedContent' ) ),
		) );
		$out = SWPS_Google_Provider::parse_models_response( $body );
		$this->assertSame( array( 'gemini-3.0-pro' => 'Gemini 3.0 Pro' ), $out );
	}

	public function test_xai_keeps_grok_only(): void {
		$body = array( 'data' => array(
			array( 'id' => 'grok-5' ),
			array( 'id' => 'grok-2-image' ),
			array( 'id' => 'text-embedding' ),
		) );
		$out = SWPS_XAI_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'grok-5', $out );
		$this->assertArrayNotHasKey( 'text-embedding', $out );
		$this->assertArrayNotHasKey( 'grok-2-image', $out );
	}

	public function test_openai_keeps_chatgpt_latest_alias(): void {
		$body = array( 'data' => array( array( 'id' => 'chatgpt-4o-latest' ) ) );
		$this->assertArrayHasKey( 'chatgpt-4o-latest', SWPS_OpenAI_Provider::parse_models_response( $body ) );
	}

	public function test_malformed_body_returns_empty(): void {
		$this->assertSame( array(), SWPS_Anthropic_Provider::parse_models_response( array() ) );
		$this->assertSame( array(), SWPS_OpenAI_Provider::parse_models_response( array( 'nope' => 1 ) ) );
	}

	public function test_google_excludes_nontext_models_reporting_generatecontent(): void {
		$body = array( 'models' => array(
			array( 'name' => 'models/gemini-3-pro', 'displayName' => 'Gemini 3 Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemma-4-31b-it', 'displayName' => 'Gemma 4 31B', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-2.5-flash-image', 'displayName' => 'Nano Banana', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-2.5-flash-preview-tts', 'displayName' => 'TTS', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/lyria-3-pro-preview', 'displayName' => 'Lyria', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/gemini-robotics-er-1.5-preview', 'displayName' => 'Robotics', 'supportedGenerationMethods' => array( 'generateContent' ) ),
			array( 'name' => 'models/nano-banana-pro-preview', 'displayName' => 'Nano Banana Pro', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		) );
		$out = SWPS_Google_Provider::parse_models_response( $body );
		$this->assertArrayHasKey( 'gemini-3-pro', $out );
		$this->assertArrayHasKey( 'gemma-4-31b-it', $out );
		$this->assertArrayNotHasKey( 'gemini-2.5-flash-image', $out );
		$this->assertArrayNotHasKey( 'gemini-2.5-flash-preview-tts', $out );
		$this->assertArrayNotHasKey( 'lyria-3-pro-preview', $out );
		$this->assertArrayNotHasKey( 'gemini-robotics-er-1.5-preview', $out );
		$this->assertArrayNotHasKey( 'nano-banana-pro-preview', $out );
	}
}
