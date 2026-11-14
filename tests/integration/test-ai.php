<?php
/**
 * AI tests against tests/fixtures/mock-ai.php (run inside WordPress via
 * tests/bin/wp-eval.php, after test-model.php). Set WPLLMML_MOCK_AI to the
 * mock's base URL, e.g. http://localhost:8891/v1.
 *
 * @package WPLLMML
 */

use WPLLMML\Ai\Client;
use WPLLMML\Ai\Fields;
use WPLLMML\Ai\Spellcheck;
use WPLLMML\Ai\Translator;
use WPLLMML\Duplicator;
use WPLLMML\Settings;
use WPLLMML\Translations;

require_once __DIR__ . '/assert.php';
wp_set_current_user( 1 );

$settings       = Settings::all();
$settings['ai'] = array(
	'base_url'     => getenv( 'WPLLMML_MOCK_AI' ) ? getenv( 'WPLLMML_MOCK_AI' ) : 'http://localhost:8891/v1',
	'api_key'      => 'test-key',
	'model'        => 'mock-model',
	'temperature'  => 0.2,
	'instructions' => 'Keep the word Gestalt.',
);
Settings::save( $settings );

section( 'Client' );
check( 'configured', Client::is_configured() );
check( 'models listed', array( 'mock-large', 'mock-model' ) === ( new Client() )->models() );
$settings['ai']['api_key'] = 'wrong';
Settings::save( $settings );
$error = ( new Client() )->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );
check( 'HTTP error reported with provider message', is_wp_error( $error ) && false !== strpos( $error->get_error_message(), 'Invalid API key' ), is_wp_error( $error ) ? $error->get_error_message() : $error );
$settings['ai']['api_key'] = 'test-key';
Settings::save( $settings );
check( 'extract_json handles fences', array( 'a' ) === Client::extract_json( "```json\n[\"a\"]\n```" ) );

section( 'translate_strings' );
$tr  = new Translator();
$out = $tr->translate_strings( array( 'Hello', '', 'https://example.com/', 'Hello', '<p>Hi <a href="/x">there</a></p>' ), 'en', 'nl' );
check( 'texts translated, empties/URLs untouched, duplicates reused', array( 'NL:Hello', '', 'https://example.com/', 'NL:Hello', 'NL:<p>Hi <a href="/x">there</a></p>' ) === $out, $out );

section( 'translate_post on an Elementor page' );
$gestalt = get_page_by_path( 'gestalt-therapy' )->ID;
$old_ro  = Translations::get_translation( $gestalt, 'ro' );
if ( $old_ro ) {
	wp_delete_post( $old_ro, true );
	Translations::flush();
}
$ro = Duplicator::create( $gestalt, 'ro' );
check( 'ro draft created', is_int( $ro ) );
$result = $tr->translate_post( $ro, 'en', 'ro' );
check( 'translate_post succeeded', true === $result, is_wp_error( $result ) ? $result->get_error_message() : $result );
$post = get_post( $ro );
check( 'title translated', 'RO:Gestalt Therapy' === $post->post_title, $post->post_title );
check( 'slug follows translated title (draft)', 'rogestalt-therapy' === $post->post_name || 'ro-gestalt-therapy' === $post->post_name, $post->post_name );
$data     = Fields::elementor_data( $ro );
$widgets  = $data[0]['elements'][0]['elements'];
$settings_by_type = array();
foreach ( $widgets as $w ) {
	$settings_by_type[ $w['widgetType'] ] = $w['settings'];
}
check( 'heading translated', 'RO:Gestalt Therapy' === $settings_by_type['heading']['title'] );
check( 'heading size untouched', 'h1' === $settings_by_type['heading']['header_size'] );
check( 'text editor translated with HTML intact', 0 === strpos( $settings_by_type['text-editor']['editor'], 'RO:<p>Welcome to "Gestalt Therapy"' ) && false !== strpos( $settings_by_type['text-editor']['editor'], 'target="_blank"' ) );
check( 'button text translated, link untouched', 'RO:Book a consult' === $settings_by_type['button']['text'] && home_url( '/book-consult/' ) === $settings_by_type['button']['link']['url'] );
check( 'icon list items translated, icons untouched', 'RO:Online sessions' === $settings_by_type['icon-list']['icon_list'][0]['text'] && 'fas fa-check' === $settings_by_type['icon-list']['icon_list'][0]['selected_icon']['value'] );
check( 'toggle repeater translated', 'RO:What is Gestalt?' === $settings_by_type['toggle']['tabs'][0]['tab_title'] && 0 === strpos( $settings_by_type['toggle']['tabs'][0]['tab_content'], 'RO:<p>' ) );
check( 'testimonial translated, person name kept', 'RO:Adina helped me find calm again.' === $settings_by_type['testimonial']['testimonial_content'] && 'Maria' === $settings_by_type['testimonial']['testimonial_name'] );
check( 'element ids unchanged', $data[0]['id'] === Fields::elementor_data( $gestalt )[0]['id'] );
check( 'Yoast description translated', 0 === strpos( get_post_meta( $ro, '_yoast_wpseo_metadesc', true ), 'RO:About' ) );
check( 'source page untouched', 'Gestalt Therapy' === get_the_title( $gestalt ) && 'Gestalt Therapy' === Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][0]['settings']['title'] );
check( 'Elementor data still valid JSON in DB', is_array( json_decode( get_post_meta( $ro, '_elementor_data', true ), true ) ) );
$again = $tr->translate_post( $ro, 'en', 'ro' );
check( 're-translation starts from the source (no RO:RO:)', true === $again && 'RO:Gestalt Therapy' === get_post( $ro )->post_title );

section( 'Block editor post' );
$post_en = get_page_by_path( 'support-in-gestalt-therapy', OBJECT, 'post' )->ID;
$old     = Translations::get_translation( $post_en, 'ro' );
if ( $old ) {
	wp_delete_post( $old, true );
	Translations::flush();
}
$ro_post = Duplicator::create( $post_en, 'ro' );
check( 'post translated', true === $tr->translate_post( $ro_post, 'en', 'ro' ) );
check( 'block comments kept', false !== strpos( get_post( $ro_post )->post_content, '<!-- wp:paragraph -->' ) && false !== strpos( get_post( $ro_post )->post_content, 'RO:' ) );

section( 'Spell check' );
$data = Fields::elementor_data( $gestalt );
$data[0]['elements'][0]['elements'][1]['settings']['editor'] = str_replace( 'Welcome to', 'Welcom to', $data[0]['elements'][0]['elements'][1]['settings']['editor'] );
Fields::save_elementor_data( $gestalt, $data );
$findings = ( new Spellcheck() )->check_post( $gestalt );
check( 'one real finding, hallucination dropped', is_array( $findings ) && 1 === count( $findings ) && 'Welcom to' === $findings[0]['original'], $findings );
check( 'apply fixes the Elementor text', true === ( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to' ) );
check( 'text corrected', false !== strpos( Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][1]['settings']['editor'], 'Welcome to "Gestalt' ) );
check( 'apply returns false when text is gone', false === ( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to' ) );

section( 'Abilities' );
$abilities = function_exists( 'wp_get_abilities' ) ? array_keys( wp_get_abilities() ) : array();
check( 'abilities registered', in_array( 'wp-llm-ml/list-languages', $abilities, true ) && in_array( 'wp-llm-ml/translate-text', $abilities, true ), $abilities );
if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'wp-llm-ml/translate-text' ) ) {
	$res = wp_get_ability( 'wp-llm-ml/translate-text' )->execute( array( 'text' => 'Good morning', 'from' => 'en', 'to' => 'nl' ) );
	check( 'translate-text ability', is_array( $res ) && 'NL:Good morning' === $res['text'], $res );
	$res = wp_get_ability( 'wp-llm-ml/get-translations' )->execute( array( 'post_id' => $gestalt ) );
	check( 'get-translations ability', is_array( $res ) && 'en' === $res['lang'] && isset( $res['translations']->nl ), $res );
	$langs = wp_get_ability( 'wp-llm-ml/list-languages' )->execute();
	check( 'list-languages ability', is_array( $langs ) && 3 === count( $langs ) );
}

// Publish the ro translation for a visual check.
wp_update_post( array( 'ID' => $ro, 'post_status' => 'publish' ) );
echo "\nRomanian page: " . get_permalink( $ro ) . "\n";

// Leave AI unconfigured afterwards.
$settings['ai']['api_key'] = '';
Settings::save( $settings );
finish();
