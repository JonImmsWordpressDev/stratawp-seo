<?php
/**
 * Fetch-level checks: broken link, redirect loop, redirect chain.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_Check_Redirect_Loop extends SWPS_Crawl_Check {
	public function id(): string { return 'redirect_loop'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( 'Redirect loops', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL redirects in a cycle and never resolves. Fix the redirect rules so the chain reaches a 200 page.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		if ( empty( $page['loop'] ) ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'hops' => $page['hops'] ?? array(), 'found_on' => $page['found_on'] ?? '' ) );
	}
}

class SWPS_Check_Broken_Link extends SWPS_Crawl_Check {
	public function id(): string { return 'broken_link'; }
	public function severity(): string { return 'error'; }
	public function title(): string { return __( 'Broken pages and links', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL returned a 4xx/5xx status or no response. Fix the target page or update/remove links pointing to it.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		$status = (int) ( $page['status_code'] ?? 0 );
		if ( $status < 400 && 0 !== $status ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'status' => $status, 'found_on' => $page['found_on'] ?? '' ) );
	}
}

class SWPS_Check_Redirect_Chain extends SWPS_Crawl_Check {
	public function id(): string { return 'redirect_chain'; }
	public function severity(): string { return 'warning'; }
	public function title(): string { return __( 'Redirect chains', 'stratawp-seo' ); }
	public function how_to_fix(): string { return __( 'The URL takes 2+ redirect hops. Point the original link (and any redirect rules) directly at the final destination.', 'stratawp-seo' ); }
	public function check_page( array $page ): ?array {
		if ( count( $page['hops'] ?? array() ) < 2 ) {
			return null;
		}
		return $this->issue( $page['url'], array( 'hops' => $page['hops'], 'found_on' => $page['found_on'] ?? '' ) );
	}
}
