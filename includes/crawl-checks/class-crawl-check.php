<?php
/**
 * Base class for crawl-time SEO checks.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class SWPS_Crawl_Check {

	/**
	 * Unique check identifier, e.g. 'missing_title'.
	 */
	abstract public function id(): string;

	/**
	 * Severity level: one of 'error', 'warning', 'notice'.
	 */
	abstract public function severity(): string;

	/**
	 * Human-readable title, e.g. "Pages don't have title tags".
	 */
	abstract public function title(): string;

	/**
	 * Short remediation guidance shown in the dashboard drill-down.
	 */
	abstract public function how_to_fix(): string;

	/**
	 * Per-page check. $page is the merged fact array (fetch + parse_html facts).
	 * Return an issue row or null.
	 *
	 * @param array $page Merged fact array.
	 * @return array|null Issue row or null.
	 */
	public function check_page( array $page ): ?array {
		return null;
	}

	/**
	 * Aggregate check over a finished run. Returns issue rows.
	 *
	 * @param int $run_id Run ID.
	 * @return array[] Issue rows.
	 */
	public function check_run( int $run_id ): array {
		return array();
	}

	/**
	 * Format an issue row.
	 *
	 * @param string $url URL where the issue was found.
	 * @param array  $detail Optional detail array.
	 * @return array Issue row with keys: type, url, detail, severity.
	 */
	final protected function issue( string $url, array $detail = array() ): array {
		return array(
			'type'     => $this->id(),
			'url'      => $url,
			'detail'   => $detail,
			'severity' => $this->severity(),
		);
	}
}
