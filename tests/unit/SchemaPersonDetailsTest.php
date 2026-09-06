<?php
/**
 * Tests for SWPS_Schema_Graph::person_details() — the pure builder for the
 * descriptive properties of a personal-brand Person entity.
 *
 * No WordPress dependency — runs in the stub bootstrap environment.
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-schema-graph.php';

final class SchemaPersonDetailsTest extends TestCase {

	private const LOCAL = array(
		'enabled'     => 1,
		'email'       => 'hello@example.com',
		'street'      => '123 Private Lane',
		'locality'    => 'Omaha',
		'region'      => 'NE',
		'postal_code' => '68105',
		'country'     => 'US',
	);

	public function test_empty_input_returns_empty_array(): void {
		$this->assertSame( array(), SWPS_Schema_Graph::person_details( array() ) );
		$this->assertSame(
			array(),
			SWPS_Schema_Graph::person_details( array( 'job_title' => '  ', 'description' => "\n\t", 'image' => '', 'knows_about' => ", ,\n" ) )
		);
	}

	public function test_full_input_populates_every_property(): void {
		$out = SWPS_Schema_Graph::person_details(
			array(
				'job_title'   => ' Senior WordPress Developer ',
				'description' => "Builds custom\n  Gutenberg blocks.\r\n Based in Omaha.",
				'image'       => 'https://example.com/headshot.jpg',
				'knows_about' => "WordPress\nGutenberg, REST API\n\nWordPress",
			),
			self::LOCAL,
			'https://example.com/#localbusiness'
		);

		$this->assertSame( 'Senior WordPress Developer', $out['jobTitle'] );
		$this->assertSame( 'Builds custom Gutenberg blocks. Based in Omaha.', $out['description'] );
		$this->assertSame( array( '@type' => 'ImageObject', 'url' => 'https://example.com/headshot.jpg' ), $out['image'] );
		$this->assertSame( array( 'WordPress', 'Gutenberg', 'REST API' ), $out['knowsAbout'] );
		$this->assertSame( 'hello@example.com', $out['email'] );
		$this->assertSame(
			array( '@type' => 'PostalAddress', 'addressLocality' => 'Omaha', 'addressRegion' => 'NE', 'addressCountry' => 'US' ),
			$out['address']
		);
		$this->assertSame( array( '@id' => 'https://example.com/#localbusiness' ), $out['worksFor'] );
	}

	public function test_address_never_includes_street_or_postal_code(): void {
		$out = SWPS_Schema_Graph::person_details( array(), self::LOCAL, '' );
		$this->assertArrayNotHasKey( 'streetAddress', $out['address'] );
		$this->assertArrayNotHasKey( 'postalCode', $out['address'] );
	}

	public function test_local_seo_disabled_skips_address_email_and_works_for(): void {
		$local = array_merge( self::LOCAL, array( 'enabled' => 0 ) );
		$out   = SWPS_Schema_Graph::person_details( array( 'job_title' => 'Dev' ), $local, 'https://example.com/#localbusiness' );
		$this->assertSame( array( 'jobTitle' => 'Dev' ), $out );
	}

	public function test_works_for_requires_a_business_id(): void {
		$out = SWPS_Schema_Graph::person_details( array(), self::LOCAL, '' );
		$this->assertArrayNotHasKey( 'worksFor', $out );
	}

	public function test_explicit_email_beats_local_seo_email(): void {
		$out = SWPS_Schema_Graph::person_details( array( 'email' => 'me@example.org' ), self::LOCAL );
		$this->assertSame( 'me@example.org', $out['email'] );
	}

	public function test_invalid_image_url_and_email_are_dropped(): void {
		$out = SWPS_Schema_Graph::person_details(
			array( 'image' => 'not a url', 'email' => 'not-an-email' ),
			array( 'enabled' => 1, 'email' => 'also bad' )
		);
		$this->assertArrayNotHasKey( 'image', $out );
		$this->assertArrayNotHasKey( 'email', $out );
	}

	public function test_partial_address_keeps_only_filled_parts(): void {
		$out = SWPS_Schema_Graph::person_details( array(), array( 'enabled' => 1, 'locality' => 'Omaha', 'region' => '', 'country' => ' ' ) );
		$this->assertSame( array( '@type' => 'PostalAddress', 'addressLocality' => 'Omaha' ), $out['address'] );
	}
}
