<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-aeo-schema-migrator.php';

/**
 * Covers the pure QAPage→FAQPage conversion (issue #44). The WP-dependent
 * bulk runner migrate_all() is exercised via manual WP-CLI smoke.
 */
final class AeoSchemaMigratorTest extends TestCase {

	public function test_converts_single_question_qapage_to_faqpage(): void {
		$qa = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				'name'           => 'How long does sourdough ferment?',
				'answerCount'    => 1,
				'upvoteCount'    => 0,
				'acceptedAnswer' => array(
					'@type'       => 'Answer',
					'text'        => '4-6 hours at room temperature.',
					'upvoteCount' => 0,
				),
			),
		);

		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$this->assertSame( 'FAQPage', $faq['@type'] );
		$this->assertSame( 'https://schema.org', $faq['@context'] );
		$this->assertCount( 1, $faq['mainEntity'] );

		$q = $faq['mainEntity'][0];
		$this->assertSame( 'Question', $q['@type'] );
		$this->assertSame( 'How long does sourdough ferment?', $q['name'] );
		$this->assertSame( '4-6 hours at room temperature.', $q['acceptedAnswer']['text'] );

		// QAPage-only fields are stripped.
		$this->assertArrayNotHasKey( 'answerCount', $q );
		$this->assertArrayNotHasKey( 'upvoteCount', $q );
		$this->assertArrayNotHasKey( 'upvoteCount', $q['acceptedAnswer'] );
	}

	public function test_converts_array_of_questions(): void {
		$qa = array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'Q1?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A1.' ),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'Q2?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A2.' ),
				),
			),
		);

		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$this->assertCount( 2, $faq['mainEntity'] );
		$this->assertSame( 'Q2?', $faq['mainEntity'][1]['name'] );
	}

	public function test_promotes_suggested_answer_when_no_accepted_answer(): void {
		$qa = array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'           => 'Question',
				'name'            => 'Which flour?',
				'suggestedAnswer' => array(
					array( '@type' => 'Answer', 'text' => 'Bread flour works best.' ),
				),
			),
		);

		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$this->assertSame( 'Bread flour works best.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_falls_back_to_question_text_when_name_missing(): void {
		$qa = array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				'text'           => 'Is it gluten free?',
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'No.' ),
			),
		);

		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$this->assertSame( 'Is it gluten free?', $faq['mainEntity'][0]['name'] );
	}

	public function test_drops_questions_with_no_usable_answer(): void {
		$qa = array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'Has answer?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes.' ),
				),
				array(
					'@type' => 'Question',
					'name'  => 'No answer here',
				),
			),
		);

		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$this->assertCount( 1, $faq['mainEntity'] );
		$this->assertSame( 'Has answer?', $faq['mainEntity'][0]['name'] );
	}

	public function test_returns_null_when_nothing_convertible(): void {
		$this->assertNull( SWPS_AEO_Schema_Migrator::convert_schema( array( '@type' => 'QAPage' ) ) );
		$this->assertNull( SWPS_AEO_Schema_Migrator::convert_schema( array(
			'@type'      => 'QAPage',
			'mainEntity' => array( '@type' => 'Question', 'name' => 'No answer' ),
		) ) );
	}

	public function test_converted_output_passes_generator_faqpage_validation(): void {
		// The conversion must produce markup the (now strict) FAQPage validator
		// accepts — otherwise we'd swap one invalid type for another.
		require_once __DIR__ . '/../../includes/class-aeo-schema-generator.php';
		require_once __DIR__ . '/FakeAIProvider.php';

		$qa = array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type'          => 'Question',
				'name'           => 'Q?',
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A.' ),
			),
		);
		$faq = SWPS_AEO_Schema_Migrator::convert_schema( $qa );

		$provider                = new FakeAIProvider();
		$provider->next_response = $faq;
		$gen                     = new SWPS_AEO_Schema_Generator(
			$provider,
			__DIR__ . '/../../includes/data/aeo-schema-fields.json'
		);
		$r = $gen->generate( 'faqpage', 'X', '<p>Y</p>' );

		$this->assertNull( $r['error'] );
		$this->assertSame( 'FAQPage', $r['json']['@type'] );
	}
}
