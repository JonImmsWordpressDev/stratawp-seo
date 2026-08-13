<?php
/**
 * Tests for template-variable rewriting during Yoast / Rank Math migration.
 *
 * Pure-PHP, no WordPress. Covers the static rewrite helpers only; the
 * migration runner itself (WP options/meta) is smoke-tested via WP-CLI.
 *
 * Regression context: Yoast shares the %%var%% delimiters but not the
 * variable names, so migrated templates stored %%term_title%% / %%name%%
 * verbatim. The renderer strips unknown variables, which turned every tag
 * and category archive title into just "Archives – Site".
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-search-appearance.php';
require_once __DIR__ . '/../../includes/class-migration.php';

/**
 * @covers SWPS_Migration
 * @covers SWPS_Search_Appearance
 */
final class MigrationTemplateVarsTest extends TestCase {

	public function test_yoast_term_title_is_mapped_to_title(): void {
		$this->assertSame(
			'%%title%% Archives %%page%% %%sep%% %%sitename%%',
			SWPS_Migration::rewrite_template_vars(
				'%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
				'yoast'
			)
		);
	}

	public function test_yoast_author_name_is_mapped_to_author(): void {
		$this->assertSame(
			'%%author%%, Author at %%sitename%% %%page%%',
			SWPS_Migration::rewrite_template_vars(
				'%%name%%, Author at %%sitename%% %%page%%',
				'yoast'
			)
		);
	}

	public function test_yoast_archive_title_and_pagenumber_aliases(): void {
		$this->assertSame(
			'%%title%% %%page%% %%sep%% %%sitename%%',
			SWPS_Migration::rewrite_template_vars(
				'%%archive_title%% %%pagenumber%% %%sep%% %%sitename%%',
				'yoast'
			)
		);
	}

	public function test_unsupported_variables_are_dropped_not_stored(): void {
		$this->assertSame(
			'%%sitename%% %%page%% %%sep%%',
			SWPS_Migration::rewrite_template_vars(
				'%%sitename%% %%page%% %%sep%% %%sitedesc%%',
				'yoast'
			)
		);
	}

	public function test_rank_math_term_and_name_map_to_supported_vars(): void {
		$this->assertSame(
			'%%title%% Archives %%page%% %%sep%% %%sitename%%',
			SWPS_Migration::rewrite_template_vars(
				'%term% Archives %page% %sep% %sitename%',
				'rank_math'
			)
		);

		$this->assertSame(
			'%%author%% %%sep%% %%sitename%%',
			SWPS_Migration::rewrite_template_vars(
				'%name% %sep% %sitename%',
				'rank_math'
			)
		);
	}

	public function test_rank_math_current_date_vars_are_dropped(): void {
		$this->assertSame(
			'%%title%% %%sep%% %%sitename%%',
			SWPS_Migration::rewrite_template_vars(
				'%title% %currentyear% %sep% %sitename%',
				'rank_math'
			)
		);
	}

	public function test_supported_template_passes_through_unchanged(): void {
		$template = '%%title%% %%page%% %%sep%% %%sitename%%';
		$this->assertSame( $template, SWPS_Migration::rewrite_template_vars( $template, 'yoast' ) );
	}

	public function test_normalize_legacy_vars_rewrites_yoast_names(): void {
		$this->assertSame(
			'%%title%% Archives %%sep%% %%sitename%%',
			SWPS_Search_Appearance::normalize_legacy_vars( '%%term_title%% Archives %%sep%% %%sitename%%' )
		);
	}
}
