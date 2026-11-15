<?php
/**
 * Layout sync tests (run inside WordPress via tests/bin/wp-eval.php, with
 * the mock AI from tests/fixtures/mock-ai.php at WPLLMML_MOCK_AI).
 *
 * @package WPLLMML
 */

use WPLLMML\Ai\Fields;
use WPLLMML\Ai\Translator;
use WPLLMML\Duplicator;
use WPLLMML\Settings;
use WPLLMML\Sync;
use WPLLMML\Translations;

require_once __DIR__ . '/assert.php';
define( 'WPLLMML_SYNC_NO_SPAWN', true ); // The test runs the queued sync itself.
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
$mock_log = sys_get_temp_dir() . '/wpllmml-mock-ai.log';
$calls    = static function () use ( $mock_log ) {
	clearstatcache();
	return is_file( $mock_log ) ? count( file( $mock_log ) ) : 0;
};

/**
 * Build a widget.
 *
 * @param string $id       Element id.
 * @param string $type     Widget type.
 * @param array  $settings Settings.
 * @return array
 */
function wpllmml_w( $id, $type, $settings ) {
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'widgetType' => $type,
		'settings'   => $settings,
		'elements'   => array(),
	);
}

/**
 * Wrap widgets in a section and column.
 *
 * @param array $widgets Widgets.
 * @return array
 */
function wpllmml_page( array $widgets ) {
	return array(
		array(
			'id'       => 'sec0001',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col0001',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
				),
			),
		),
	);
}

/**
 * Widgets of the (only) column, by id.
 *
 * @param int $post_id Post.
 * @return array
 */
function wpllmml_widgets( $post_id ) {
	$out  = array();
	$data = Fields::elementor_data( $post_id );
	foreach ( $data[0]['elements'][0]['elements'] as $w ) {
		$out[ $w['id'] ] = $w;
	}
	return $out;
}

/**
 * Save Elementor data like the editor does (fires elementor/document/after_save).
 *
 * @param int   $post_id Post.
 * @param array $data    Elements.
 */
function wpllmml_editor_save( $post_id, array $data ) {
	Fields::save_elementor_data( $post_id, $data );
}

$toggle = static function ( array $rows ) {
	return wpllmml_w( 'tog0001', 'toggle', array( 'tabs' => $rows ) );
};
$row    = static function ( $id, $title, $content ) {
	return array(
		'_id'         => $id,
		'tab_title'   => $title,
		'tab_content' => $content,
	);
};

// Source page in English.
$src = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Sync source', 'post_name' => 'sync-source' ) );
update_post_meta( $src, '_elementor_edit_mode', 'builder' );
update_post_meta( $src, '_elementor_template_type', 'wp-page' );
update_post_meta( $src, '_wp_page_template', 'elementor_header_footer' );
$v1 = wpllmml_page(
	array(
		wpllmml_w( 'hea0001', 'heading', array( 'title' => 'Welcome', 'header_size' => 'h2' ) ),
		wpllmml_w( 'txt0001', 'text-editor', array( 'editor' => '<p>About us</p>' ) ),
		wpllmml_w( 'btn0001', 'button', array( 'text' => 'Book now', 'link' => array( 'url' => home_url( '/book-consult/' ) ) ) ),
		wpllmml_w( 'sho0001', 'shortcode', array( 'shortcode' => '[forminator_form id="1"]' ) ),
		$toggle( array( $row( 'r1', 'Question one', '<p>Answer one</p>' ), $row( 'r2', 'Question two', '<p>Answer two</p>' ) ) ),
	)
);
update_post_meta( $src, '_elementor_data', wp_slash( wp_json_encode( $v1 ) ) );

section( 'A translation remembers the layout it was made from' );
$nl = Duplicator::create( $src, 'nl' );
check( 'base stored on creation', is_array( json_decode( get_post_meta( $nl, Sync::META_BASE, true ), true ) ) );
check( 'full AI translation', true === ( new Translator() )->translate_post( $nl, 'en', 'nl' ) );
wp_update_post( array( 'ID' => $nl, 'post_status' => 'publish' ) );
// The translator's own touches in Elementor: a manual text and another form.
$data = Fields::elementor_data( $nl );
$data[0]['elements'][0]['elements'][0]['settings']['title']     = 'Welkom (handmatig)';
$data[0]['elements'][0]['elements'][3]['settings']['shortcode'] = '[forminator_form id="2"]';
wpllmml_editor_save( $nl, $data );
$nl_before = wpllmml_widgets( $nl );
check( 'translation ready', 'NL:<p>About us</p>' === $nl_before['txt0001']['settings']['editor'] && 'Welkom (handmatig)' === $nl_before['hea0001']['settings']['title'] );

section( 'Saving the English page queues a background sync' );
$v2 = wpllmml_page(
	array(
		wpllmml_w( 'hea0001', 'heading', array( 'title' => 'Welcome', 'header_size' => 'h3' ) ),       // Style changed.
		$toggle( array( $row( 'r1', 'Question one', '<p>Answer one</p>' ), $row( 'r2', 'Question two', '<p>Answer two</p>' ), $row( 'r3', 'Question three', '<p>Answer three</p>' ) ) ), // Moved up, new row.
		wpllmml_w( 'txt0001', 'text-editor', array( 'editor' => '<p>About us and more</p>' ) ),        // Text changed.
		wpllmml_w( 'new0001', 'heading', array( 'title' => 'A new section', 'header_size' => 'h2' ) ), // New.
		wpllmml_w( 'sho0001', 'shortcode', array( 'shortcode' => '[forminator_form id="1"]' ) ),
		// The button was removed.
	)
);
wp_clear_scheduled_hook( Sync::HOOK, array( $src ) );
wpllmml_editor_save( $src, $v2 );
check( 'sync queued', (bool) wp_next_scheduled( Sync::HOOK, array( $src ) ) );
check( 'translation flagged as pending', '' !== get_post_meta( $nl, '_wpllmml_sync_pending', true ) );

section( 'The sync: layout follows English, translations stay' );
$before = $calls();
do_action( Sync::HOOK, $src );
$after = wpllmml_widgets( $nl );
check( 'one AI request for all new and changed texts', 1 === $calls() - $before, $calls() - $before );
check( 'order follows the source', array( 'hea0001', 'tog0001', 'txt0001', 'new0001', 'sho0001' ) === array_keys( $after ), array_keys( $after ) );
check( 'removed widget removed', ! isset( $after['btn0001'] ) );
check( 'style change applied', 'h3' === $after['hea0001']['settings']['header_size'] );
check( 'manual translation kept', 'Welkom (handmatig)' === $after['hea0001']['settings']['title'] );
check( 'changed text translated again', 'NL:<p>About us and more</p>' === $after['txt0001']['settings']['editor'], $after['txt0001']['settings']['editor'] );
check( 'new widget translated', 'NL:A new section' === $after['new0001']['settings']['title'] );
check( 'existing toggle rows keep their translation', 'NL:Question one' === $after['tog0001']['settings']['tabs'][0]['tab_title'] && 'NL:<p>Answer two</p>' === $after['tog0001']['settings']['tabs'][1]['tab_content'] );
check( 'new toggle row translated', 'NL:Question three' === $after['tog0001']['settings']['tabs'][2]['tab_title'] && 'NL:<p>Answer three</p>' === $after['tog0001']['settings']['tabs'][2]['tab_content'] );
check( 'per-language form kept', '[forminator_form id="2"]' === $after['sho0001']['settings']['shortcode'] );
$review = get_post_meta( $nl, Sync::META_REVIEW, true );
check( 'marked for review: 4 texts translated', is_array( $review ) && 4 === $review['translated'] && 0 === $review['untranslated'], $review );
check( 'pending flag cleared', '' === get_post_meta( $nl, '_wpllmml_sync_pending', true ) );
check( 'translation still published', 'publish' === get_post_status( $nl ) );
check( 'base is now the current source', Fields::elementor_data( $src ) === json_decode( get_post_meta( $nl, Sync::META_BASE, true ), true ) );

section( 'Nothing new: nothing to do' );
$before = $calls();
$stats  = Sync::sync_translation( $src, $nl );
check( 'no AI request', 0 === $calls() - $before && is_array( $stats ) && 0 === $stats['translated'] );
check( 'translation unchanged', wpllmml_widgets( $nl ) === $after );

section( 'A local change on the translation is kept until the source changes it' );
$data = Fields::elementor_data( $nl );
foreach ( $data[0]['elements'][0]['elements'] as $i => $w ) {
	if ( 'new0001' === $w['id'] ) {
		$data[0]['elements'][0]['elements'][ $i ]['settings']['header_size'] = 'h4';
	}
}
wpllmml_editor_save( $nl, $data );
check( 'saving the translation in Elementor clears the review mark', '' === get_post_meta( $nl, Sync::META_REVIEW, true ) );
Sync::sync_translation( $src, $nl );
check( 'local style kept while the source did not change it', 'h4' === wpllmml_widgets( $nl )['new0001']['settings']['header_size'] );
$v3 = $v2;
foreach ( $v3[0]['elements'][0]['elements'] as $i => $w ) {
	if ( 'new0001' === $w['id'] ) {
		$v3[0]['elements'][0]['elements'][ $i ]['settings']['header_size'] = 'h5';
	}
}
wpllmml_editor_save( $src, $v3 );
do_action( Sync::HOOK, $src );
check( 'source change wins for a layout setting', 'h5' === wpllmml_widgets( $nl )['new0001']['settings']['header_size'] );

section( 'An element added only to the translation stays where it was' );
$data  = Fields::elementor_data( $nl );
$cells = $data[0]['elements'][0]['elements'];
$pos   = array_search( 'txt0001', array_column( $cells, 'id' ), true );
array_splice( $cells, $pos + 1, 0, array( wpllmml_w( 'loc0001', 'text-editor', array( 'editor' => '<p>Alleen in het Nederlands</p>' ) ) ) );
$data[0]['elements'][0]['elements'] = $cells;
wpllmml_editor_save( $nl, $data );
$v3b = $v3;
$v3b[0]['elements'][0]['elements'][0]['settings']['align'] = 'center';
wpllmml_editor_save( $src, $v3b );
do_action( Sync::HOOK, $src );
$after = wpllmml_widgets( $nl );
check( 'local element kept after the element it followed', array( 'hea0001', 'tog0001', 'txt0001', 'loc0001', 'new0001', 'sho0001' ) === array_keys( $after ), array_keys( $after ) );
check( 'local element untouched', '<p>Alleen in het Nederlands</p>' === $after['loc0001']['settings']['editor'] );
check( 'source change applied too', 'center' === $after['hea0001']['settings']['align'] );
$v3 = $v3b;

section( 'Opting out: a translation can keep its own layout' );
Sync::set_follow( $nl, false );
$v3c = $v3;
$v3c[0]['elements'][0]['elements'][0]['settings']['align'] = 'right';
wpllmml_editor_save( $src, $v3c );
check( 'not flagged as pending', ! Sync::is_pending( $nl ) );
do_action( Sync::HOOK, $src );
check( 'not synced while opted out', 'center' === wpllmml_widgets( $nl )['hea0001']['settings']['align'] );
Sync::set_follow( $nl, true );
check( 'following again queues a sync', Sync::is_pending( $nl ) && (bool) wp_next_scheduled( Sync::HOOK, array( $src ) ) );
do_action( Sync::HOOK, $src );
check( 'synced after opting in again', 'right' === wpllmml_widgets( $nl )['hea0001']['settings']['align'] );
check( 'local element still there', isset( wpllmml_widgets( $nl )['loc0001'] ) );
$v3 = $v3c;

section( 'Without AI: new texts stay in English and are flagged' );
$settings['ai']['api_key'] = '';
Settings::save( $settings );
$v4 = $v3;
$v4[0]['elements'][0]['elements'][] = wpllmml_w( 'new0002', 'heading', array( 'title' => 'Another section', 'header_size' => 'h2' ) );
wpllmml_editor_save( $src, $v4 );
do_action( Sync::HOOK, $src );
check( 'new text in English', 'Another section' === wpllmml_widgets( $nl )['new0002']['settings']['title'] );
$review = get_post_meta( $nl, Sync::META_REVIEW, true );
check( 'flagged as untranslated', is_array( $review ) && 1 === $review['untranslated'], $review );
$settings['ai']['api_key'] = 'test-key';
Settings::save( $settings );

section( 'AI back: texts left in English are translated on the next sync' );
$v4b = $v4;
$v4b[0]['elements'][0]['elements'][0]['settings']['align'] = 'left';
wpllmml_editor_save( $src, $v4b );
do_action( Sync::HOOK, $src );
$after = wpllmml_widgets( $nl );
check( 'English text translated now', 'NL:Another section' === $after['new0002']['settings']['title'], $after['new0002']['settings']['title'] );
check( 'other texts untouched', 'Welkom (handmatig)' === $after['hea0001']['settings']['title'] && '<p>Alleen in het Nederlands</p>' === $after['loc0001']['settings']['editor'] );
check( 'retry list cleared', '' === get_post_meta( $nl, Sync::META_RETRY, true ) );
$v4 = $v4b;

section( 'Translations made before this feature (no history)' );
delete_post_meta( $nl, Sync::META_BASE );
$v5 = $v4;
$v5[0]['elements'][0]['elements'][0]['settings']['header_size'] = 'h1';
wpllmml_editor_save( $src, $v5 );
do_action( Sync::HOOK, $src );
$after = wpllmml_widgets( $nl );
check( 'layout change applied', 'h1' === $after['hea0001']['settings']['header_size'] );
check( 'translated texts kept', 'Welkom (handmatig)' === $after['hea0001']['settings']['title'] && 'NL:<p>About us and more</p>' === $after['txt0001']['settings']['editor'] );
check( 'per-language form kept', '[forminator_form id="2"]' === $after['sho0001']['settings']['shortcode'] );
check( 'without history a translation-only element follows the source (removed)', ! isset( $after['loc0001'] ) );

section( 'A translation built separately (other element ids) is never replaced' );
$own = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Eigen opmaak', 'post_name' => 'eigen-opmaak' ) );
update_post_meta( $own, '_elementor_edit_mode', 'builder' );
update_post_meta( $own, '_elementor_template_type', 'wp-page' );
$own_data = array(
	array(
		'id'       => 'own0001',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array( wpllmml_w( 'own0002', 'heading', array( 'title' => 'Mijn eigen pagina' ) ) ),
	),
);
update_post_meta( $own, '_elementor_data', wp_slash( wp_json_encode( $own_data ) ) );
Translations::set_lang( $own, 'ro' );
Translations::link( $own, $src );
$result = Sync::sync_translation( $src, $own );
check( 'refused', is_wp_error( $result ) && 'wpllmml_sync_unrelated' === $result->get_error_code() );
check( 'its layout is untouched', Fields::elementor_data( $own ) === $own_data );
check( 'it keeps its own layout from now on', ! Sync::follows( $own ) );
wp_delete_post( $own, true );

section( 'Translations of other pages and non-Elementor posts are left alone' );
$post_en = get_page_by_path( 'liminal-space', OBJECT, 'post' );
check( 'block editor post is not a sync source', $post_en && ! Sync::is_source( $post_en->ID ) );

wp_delete_post( $nl, true );
wp_delete_post( $src, true );
$settings['ai']['api_key'] = '';
Settings::save( $settings );
finish();
