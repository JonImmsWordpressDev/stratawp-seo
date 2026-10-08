<?php
/**
 * Regenerates tests/fixtures/editor/golden.json from inputs.json using the PHP
 * check engine (the source of truth). The JS mirror is tested against this file.
 *
 * Usage: php bin/gen-editor-golden.php          (writes golden.json)
 *        php bin/gen-editor-golden.php --stdout (prints it, used by a test)
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once __DIR__ . '/../includes/editor/class-editor-text.php';
require_once __DIR__ . '/../includes/editor/class-editor-check-registry.php';
require_once __DIR__ . '/../includes/editor/class-editor-check-engine.php';

$root   = dirname( __DIR__ ) . '/tests/fixtures/editor';
$inputs = json_decode( (string) file_get_contents( $root . '/inputs.json' ), true );
$cases  = array();

foreach ( $inputs as $case ) {
	$input = $case['input'];
	if ( isset( $case['content_repeat'] ) ) {
		$input['content_html'] = '<p>' . str_repeat( $case['content_repeat']['text'], (int) $case['content_repeat']['times'] ) . '</p>';
	}
	$output  = SWPS_Editor_Check_Engine::run( $input );
	$cases[] = array(
		'name'   => $case['name'],
		'input'  => $input,
		'output' => $output,
		'score'  => SWPS_Editor_Check_Engine::score( $output ),
	);
}

$json = json_encode(
	array(
		'registry' => SWPS_Editor_Check_Registry::for_js(),
		'cases'    => $cases,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . "\n";

if ( in_array( '--stdout', $argv, true ) ) {
	echo $json;
} else {
	file_put_contents( $root . '/golden.json', $json );
	echo "Wrote {$root}/golden.json\n";
}
