<?php
/**
 * Point the plugin at the mock AI (tests/fixtures/mock-ai.php at
 * WPLLMML_MOCK_AI), for the browser tests.
 *
 * @package WPLLMML
 */

$settings       = WPLLMML\Settings::all();
$settings['ai'] = array(
	'base_url'     => getenv( 'WPLLMML_MOCK_AI' ),
	'api_key'      => 'test-key',
	'model'        => 'mock-model',
	'temperature'  => 0.2,
	'instructions' => '',
);
WPLLMML\Settings::save( $settings );
echo "Mock AI on\n";
