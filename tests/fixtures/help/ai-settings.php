<?php
/**
 * Realistic AI settings for the screenshot of the AI tab.
 *
 * @package WPLLMML
 */

$settings       = WPLLMML\Settings::all();
$settings['ai'] = array(
	'base_url'     => 'https://hostyourai.com/api/v1',
	'api_key'      => 'demo-key',
	'model'        => 'mistral-small-3.2-24b-instruct',
	'temperature'  => 0.2,
	'instructions' => 'Address the reader informally (je/jij). Keep Gestalt and Reiki untranslated. Warm, calm tone. In Romanian use ș and ț with a comma.',
);
WPLLMML\Settings::save( $settings );
echo "AI settings for the screenshot\n";
