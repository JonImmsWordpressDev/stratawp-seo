<?php
/**
 * Tests for SWPS_Abilities pure helpers: validate_input() and redact().
 *
 * Pure-PHP, no WordPress.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/trait-ability-defs.php';
require_once __DIR__ . '/../../includes/class-abilities.php';

/**
 * @covers SWPS_Abilities
 */
class AbilitiesTest extends TestCase {

	// -------------------------------------------------------------------------
	// validate_input() — required property missing
	// -------------------------------------------------------------------------

	public function test_validate_input_returns_wp_error_when_required_missing(): void {
		$schema = array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			),
		);

		$result = SWPS_Abilities::validate_input( array(), $schema );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_ability_missing_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// validate_input() — type mismatch: expects integer, gets string
	// -------------------------------------------------------------------------

	public function test_validate_input_returns_wp_error_on_type_mismatch(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'limit' => array( 'type' => 'integer' ),
			),
		);

		$result = SWPS_Abilities::validate_input( array( 'limit' => 'ten' ), $schema );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_ability_invalid_type', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// validate_input() — type check: string
	// -------------------------------------------------------------------------

	public function test_validate_input_returns_wp_error_on_string_type_mismatch(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'title' => array( 'type' => 'string' ),
			),
		);

		$result = SWPS_Abilities::validate_input( array( 'title' => 42 ), $schema );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_ability_invalid_type', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// validate_input() — type check: boolean
	// -------------------------------------------------------------------------

	public function test_validate_input_returns_wp_error_on_bool_type_mismatch(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'enabled' => array( 'type' => 'boolean' ),
			),
		);

		$result = SWPS_Abilities::validate_input( array( 'enabled' => 'yes' ), $schema );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_ability_invalid_type', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// validate_input() — enum check
	// -------------------------------------------------------------------------

	public function test_validate_input_returns_wp_error_on_enum_violation(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'type' => array(
					'type' => 'string',
					'enum' => array( '301', '302' ),
				),
			),
		);

		$result = SWPS_Abilities::validate_input( array( 'type' => '999' ), $schema );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swps_ability_invalid_enum', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// validate_input() — unknown props stripped
	// -------------------------------------------------------------------------

	public function test_validate_input_strips_unknown_properties(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'days' => array( 'type' => 'integer' ),
			),
		);

		$result = SWPS_Abilities::validate_input(
			array(
				'days'    => 30,
				'unknown' => 'should_be_dropped',
			),
			$schema
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'days', $result );
		$this->assertArrayNotHasKey( 'unknown', $result );
	}

	// -------------------------------------------------------------------------
	// validate_input() — valid input passes through
	// -------------------------------------------------------------------------

	public function test_validate_input_passes_valid_input(): void {
		$schema = array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'days'    => array( 'type' => 'integer' ),
			),
		);

		$result = SWPS_Abilities::validate_input( array( 'post_id' => 42, 'days' => 30 ), $schema );
		$this->assertIsArray( $result );
		$this->assertSame( 42, $result['post_id'] );
		$this->assertSame( 30, $result['days'] );
	}

	// -------------------------------------------------------------------------
	// validate_input() — no schema (passthrough)
	// -------------------------------------------------------------------------

	public function test_validate_input_passes_when_no_schema(): void {
		$result = SWPS_Abilities::validate_input( array( 'foo' => 'bar' ), array() );
		$this->assertIsArray( $result );
	}

	// -------------------------------------------------------------------------
	// redact() — api_key field redacted
	// -------------------------------------------------------------------------

	public function test_redact_removes_api_key_field(): void {
		$input    = array( 'api_key' => 'sk-secret', 'days' => 30 );
		$redacted = SWPS_Abilities::redact( $input );
		$this->assertArrayNotHasKey( 'api_key', $redacted );
		$this->assertArrayHasKey( 'days', $redacted );
	}

	// -------------------------------------------------------------------------
	// redact() — secret / token / password patterns
	// -------------------------------------------------------------------------

	public function test_redact_removes_secret_token_password(): void {
		$input = array(
			'my_secret'    => 'abc',
			'auth_token'   => 'xyz',
			'password'     => 'hunter2',
			'legit_field'  => 'keep_me',
		);

		$redacted = SWPS_Abilities::redact( $input );

		$this->assertArrayNotHasKey( 'my_secret', $redacted );
		$this->assertArrayNotHasKey( 'auth_token', $redacted );
		$this->assertArrayNotHasKey( 'password', $redacted );
		$this->assertArrayHasKey( 'legit_field', $redacted );
	}

	// -------------------------------------------------------------------------
	// Registry integrity — every def has required fields
	// -------------------------------------------------------------------------

	public function test_all_defs_have_required_fields(): void {
		$abilities = new SWPS_Abilities();
		$defs      = $abilities->get_defs();
		$required  = array( 'name', 'label', 'description', 'write', 'callback' );

		foreach ( $defs as $def ) {
			foreach ( $required as $field ) {
				$this->assertArrayHasKey(
					$field,
					$def,
					"Ability def missing field '{$field}' in: " . ( $def['name'] ?? '(no name)' )
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Registry integrity — write flags present and are boolean
	// -------------------------------------------------------------------------

	public function test_all_defs_have_boolean_write_flag(): void {
		$abilities = new SWPS_Abilities();

		foreach ( $abilities->get_defs() as $def ) {
			$this->assertIsBool(
				$def['write'],
				"write flag in '{$def['name']}' should be bool"
			);
		}
	}

	// -------------------------------------------------------------------------
	// Registry integrity — read abilities default ON, write OFF
	// -------------------------------------------------------------------------

	public function test_write_abilities_default_off(): void {
		$abilities = new SWPS_Abilities();

		foreach ( $abilities->get_defs() as $def ) {
			$default = $abilities->is_enabled_by_default( $def['name'] );
			if ( $def['write'] ) {
				$this->assertFalse(
					$default,
					"WRITE ability '{$def['name']}' should default OFF"
				);
			} else {
				$this->assertTrue(
					$default,
					"READ ability '{$def['name']}' should default ON"
				);
			}
		}
	}
}
