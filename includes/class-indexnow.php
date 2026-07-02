<?php
/**
 * IndexNow integration — instantly notify Bing/Yandex/Seznam/Naver of URL changes.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns key management, the /{key}.txt verification file, lifecycle triggers,
 * the debounced submit queue, HTTP submission, the activity log, and AJAX.
 */
class SWPS_IndexNow {

	const CRON_HOOK           = 'swps_indexnow_flush';
	const ENDPOINT            = 'https://api.indexnow.org/indexnow';
	const MAX_LOG             = 50;
	const MAX_URLS_PER_REQUEST = 10000;
	const DEBOUNCE_SECONDS    = 60;
	const DAILY_CAP           = 10000;

	const OPT_ENABLED     = 'swps_indexnow_enabled';
	const OPT_AUTO        = 'swps_indexnow_auto_submit';
	const OPT_KEY         = 'swps_indexnow_api_key';
	const OPT_POST_TYPES  = 'swps_indexnow_post_types';
	const OPT_QUEUE       = 'swps_indexnow_queue';
	const OPT_LOG         = 'swps_indexnow_log';
	const OPT_DAILY_COUNT = 'swps_indexnow_daily_count';
	const OPT_DAILY_DATE  = 'swps_indexnow_daily_date';

	const META_LAST_URL  = '_swps_indexnow_last_url';
	const META_SUBMITTED = '_swps_indexnow_submitted';

	const SETTINGS_GROUP = 'swps_indexnow_settings';

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'flush' ) );
	}

	/**
	 * wp-cron handler — drains the debounce queue and submits. Filled in Task 7.
	 */
	public function flush(): void {
	}
}
