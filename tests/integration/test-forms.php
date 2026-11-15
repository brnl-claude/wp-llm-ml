<?php
/**
 * Forminator integration tests (run inside WordPress via tests/bin/wp-eval.php,
 * with the mock AI at WPLLMML_MOCK_AI and the site at WPLLMML_SITE_URL).
 *
 * @package WPLLMML
 */

use WPLLMML\Duplicator;
use WPLLMML\Integrations\Forminator;
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
	'instructions' => '',
);
Settings::save( $settings );
$site = getenv( 'WPLLMML_SITE_URL' ) ? getenv( 'WPLLMML_SITE_URL' ) : 'http://localhost:8890';

section( 'Forminator is part of the test site' );
check( 'active', Forminator::active() );
$form = require dirname( __DIR__ ) . '/fixtures/forminator-form.php';
check( 'test form created', is_int( $form ) && $form > 0, $form );

/**
 * Fields of a form by element id.
 *
 * @param int $form_id Form.
 * @return array
 */
function wpllmml_form_fields( $form_id ) {
	$meta = get_post_meta( $form_id, Forminator::FORM_META, true );
	$out  = array();
	foreach ( $meta['fields'] as $field ) {
		$out[ $field['element_id'] ] = $field;
	}
	return $out;
}

section( 'Create & translate a form with AI' );
$nl = Forminator::make_copy( $form, 'nl' );
check( 'copy created', is_int( $nl ) && $nl !== $form, $nl );
$meta   = get_post_meta( $nl, Forminator::FORM_META, true );
$fields = wpllmml_form_fields( $nl );
check( 'labels translated', 'NL:First Name' === $fields['name-1']['field_label'] && 'NL:First Name' === $fields['name-1']['fname_label'] && 'NL:Email Address' === $fields['email-1']['field_label'], $fields['name-1'] );
check( 'placeholder and description', 'NL:Tell me what brings you here' === $fields['textarea-1']['placeholder'] && 'NL:Optional' === $fields['textarea-1']['description'] );
check( 'option labels translated, values kept', 'NL:An introductory session' === $fields['select-1']['options'][0]['label'] && 'intro' === $fields['select-1']['options'][0]['value'] );
check( 'HTML block translated', 'NL:<p>We reply within <strong>two days</strong>.</p>' === $fields['html-1']['variations'] );
check( 'button, messages and thank-you text', 'NL:Send' === $meta['settings']['submitData']['custom-submit-text'] && 0 === strpos( $meta['settings']['thankyou-message'], 'NL:' ) && 0 === strpos( $meta['behaviors'][0]['thankyou-message'], 'NL:' ) );
$mail = $meta['notifications'][1];
check( 'e-mail to the visitor translated, codes kept', 'NL:Thank you, {name-1}' === $mail['email-subject'] && false !== strpos( $mail['email-editor'], '{all_fields}' ) && 0 === strpos( $mail['email-editor'], 'NL:' ), $mail );
check( 'recipients untouched', '{email-1}' === $mail['recipients'] );
check( 'styles and ids untouched', '#254DEB' === $meta['settings']['input-focus-outline-color'] && array_keys( $fields ) === array_keys( wpllmml_form_fields( $form ) ) );
check( 'named after the original with the language', 'Book consult (NL)' === Forminator::name( $nl ) && 'book-consult-nl' === get_post_field( 'post_title', $nl ) );
check( 'knows its own id', (int) $nl === (int) $meta['settings']['form_id'] );
check( 'published like the original', 'publish' === get_post_status( $nl ) );
check( 'linked to the original', $nl === Forminator::copy_of( $form, 'nl' ) && (int) $form === (int) get_post_meta( $nl, Forminator::META_SOURCE, true ) );
check( 'original untouched', 'First Name' === wpllmml_form_fields( $form )['name-1']['field_label'] );
$sources = wp_list_pluck( Forminator::source_forms(), 'ID' );
check( 'the Forms tab lists originals only', in_array( $form, $sources, true ) && ! in_array( $nl, $sources, true ) );

section( 'Translate again: the same copy, with the fields of the original' );
$meta = get_post_meta( $nl, Forminator::FORM_META, true );
$meta['fields'][0]['field_label'] = 'Voornaam (handmatig)';
update_post_meta( $nl, Forminator::FORM_META, $meta );
$source                     = get_post_meta( $form, Forminator::FORM_META, true );
$extra                      = $source['fields'][1];
$extra['id']                = 'text-9';
$extra['element_id']        = 'text-9';
$extra['type']              = 'text';
$extra['field_label']       = 'Where did you hear about me?';
$source['fields'][]         = $extra;
update_post_meta( $form, Forminator::FORM_META, $source );
$again = Forminator::make_copy( $form, 'nl' );
check( 'same copy', $again === $nl );
$fields = wpllmml_form_fields( $nl );
check( 'texts translated afresh', 'NL:First Name' === $fields['name-1']['field_label'] );
check( 'new field of the original added', isset( $fields['text-9'] ) && 'NL:Where did you hear about me?' === $fields['text-9']['field_label'] );

section( 'Codes and text keys' );
check( 'a translation that lost a code keeps the original', 'Dear {name-1},' === Forminator::keep_codes( 'Dear {name-1},', 'Beste naam,' ) && 'Beste {name-1},' === Forminator::keep_codes( 'Dear {name-1},', 'Beste {name-1},' ) );
$keys = array();
foreach ( array( 'field_label', 'fname_label', 'placeholder', 'description', 'required_message', 'custom-submit-text', 'thankyou-message', 'email-subject', 'email-editor', 'variations', 'label', 'indicator-label' ) as $key ) {
	$keys[ $key ] = Forminator::is_text_key( $key );
}
foreach ( array( 'value', 'element_id', 'type', 'formName', 'recipients', 'email-recipients', 'cform-label-font-family', 'description-position', 'submission-behaviour', 'input-focus-outline-color' ) as $key ) {
	$keys[ $key ] = ! Forminator::is_text_key( $key );
}
check( 'texts recognised, settings left alone', ! in_array( false, $keys, true ), array_keys( $keys, false, true ) );

section( 'Copy without AI' );
$ro = Forminator::make_copy( $form, 'ro', false );
check( 'plain copy', is_int( $ro ) && 'First Name' === wpllmml_form_fields( $ro )['name-1']['field_label'] && 'Book consult (RO)' === Forminator::name( $ro ) );
check( 'refuses a copy as source and the default language', is_wp_error( Forminator::make_copy( $nl, 'ro' ) ) && is_wp_error( Forminator::make_copy( $form, 'en' ) ) );

section( 'Pages show the form in the visitor\'s language (HTTP)' );
$form_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Form page',
		'post_name'    => 'form-page-' . strtolower( wp_generate_password( 6, false ) ),
		'post_content' => '[forminator_form id="' . $form . '"]',
	)
);
$form_page_nl = Duplicator::create( $form_page, 'nl' );
$form_page_ro = Duplicator::create( $form_page, 'ro' );
wp_update_post( array( 'ID' => $form_page_nl, 'post_status' => 'publish' ) );
wp_update_post( array( 'ID' => $form_page_ro, 'post_status' => 'publish' ) );
$get = static function ( $url ) {
	$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
	return is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
};
$en_html = $get( get_permalink( $form_page ) );
$nl_html = $get( get_permalink( $form_page_nl ) );
$ro_html = $get( get_permalink( $form_page_ro ) );
preg_match_all( '/data-form="forminator-module-(\d+)"/', $en_html, $en_forms );
check( 'English page: the original', array( (string) $form ) === array_unique( $en_forms[1] ) && false !== strpos( $en_html, 'First Name' ), $en_forms[1] );
check( 'Dutch page: the Dutch copy', false !== strpos( $nl_html, 'data-form="forminator-module-' . $nl . '"' ) && false === strpos( $nl_html, 'data-form="forminator-module-' . $form . '"' ) );
check( 'Dutch labels and button', false !== strpos( $nl_html, 'NL:First Name' ) && false !== strpos( $nl_html, 'NL:Send' ) );
check( 'Romanian page: the Romanian copy', false !== strpos( $ro_html, 'data-form="forminator-module-' . $ro . '"' ) );
check( 'the page content still has the original id', false !== strpos( get_post_field( 'post_content', $form_page_nl ), 'id="' . $form . '"' ) );
wp_update_post( array( 'ID' => $ro, 'post_status' => 'draft' ) );
check( 'a draft copy is not shown', false !== strpos( $get( get_permalink( $form_page_ro ) ), 'data-form="forminator-module-' . $form . '"' ) );

section( 'Deleting a copy' );
wp_delete_post( $nl, true );
check( 'forgotten', 0 === Forminator::copy_of( $form, 'nl' ) );
check( 'the page falls back to the original', false !== strpos( $get( get_permalink( $form_page_nl ) ), 'data-form="forminator-module-' . $form . '"' ) );

foreach ( array( $form_page_nl, $form_page_ro, $form_page ) as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_post( $ro, true );
wp_delete_post( $form, true );
Translations::flush();
$settings['ai']['api_key'] = '';
Settings::save( $settings );
finish();
