<?php
/**
 * Model tests (run inside WordPress via tests/bin/wp-eval.php after seeding):
 * translation creation, Elementor data integrity, slugs, groups, queries.
 * Leaves published nl/ro translations behind for the HTTP tests.
 *
 * @package WPLLMML
 */

use WPLLMML\Duplicator;
use WPLLMML\Translations;

require_once __DIR__ . '/assert.php';
wp_set_current_user( 1 );

$by_path = static function ( $path, $type = 'page' ) {
	$post = get_page_by_path( $path, OBJECT, $type );
	return $post ? (int) $post->ID : 0;
};
$home    = (int) get_option( 'page_on_front' );
$gestalt = $by_path( 'gestalt-therapy' );
$gather  = $by_path( 'gestalt-gatherings' );
$walks   = $by_path( 'gestalt-gatherings/silent-walks' );
$blog    = (int) get_option( 'page_for_posts' );
$reiki   = $by_path( 'reiki' );
$book    = $by_path( 'book-consult' );
$post1   = $by_path( 'liminal-space', 'post' );

// Clean slate: remove translations from earlier runs.
foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpllmml_lang', 'meta_value' => 'nl', 'wpllmml_lang' => 'all' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpllmml_lang', 'meta_value' => 'ro', 'wpllmml_lang' => 'all' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
Translations::flush();

section( 'Languages' );
check( 'three languages configured', WPLLMML\Languages::codes() === array( 'en', 'nl', 'ro' ) );
check( 'default is en', 'en' === WPLLMML\Languages::default_code() );
check( 'existing page counts as default language', 'en' === Translations::get_lang( $gestalt ) );

section( 'Create translation of an Elementor page' );
$raw_before = get_post_meta( $gestalt, '_elementor_data', true );
$nl_gestalt = Duplicator::create( $gestalt, 'nl' );
check( 'translation created', is_int( $nl_gestalt ) && $nl_gestalt > 0, $nl_gestalt );
check( 'new post is draft', 'draft' === get_post_status( $nl_gestalt ) );
check( 'language nl', 'nl' === Translations::get_lang( $nl_gestalt ) );
check( 'linked both ways', Translations::get_translation( $gestalt, 'nl' ) === $nl_gestalt && Translations::get_translation( $nl_gestalt, 'en' ) === $gestalt );
check( 'Elementor data byte-identical', get_post_meta( $nl_gestalt, '_elementor_data', true ) === $raw_before );
$decoded = json_decode( get_post_meta( $nl_gestalt, '_elementor_data', true ), true );
check( 'Elementor data decodes', is_array( $decoded ) && 'section' === $decoded[0]['elType'] );
check( 'quotes and newline kept in text editor', false !== strpos( $decoded[0]['elements'][0]['elements'][1]['settings']['editor'], "\"Gestalt Therapy\". I offer" ) && false !== strpos( $decoded[0]['elements'][0]['elements'][1]['settings']['editor'], "\nRead more" ) );
check( 'Elementor edit mode copied', 'builder' === get_post_meta( $nl_gestalt, '_elementor_edit_mode', true ) );
check( 'page template copied', 'elementor_header_footer' === get_post_meta( $nl_gestalt, '_wp_page_template', true ) );
check( 'page settings (array meta) copied', array( 'hide_title' => 'yes' ) === get_post_meta( $nl_gestalt, '_elementor_page_settings', true ) );
check( 'GeneratePress layout meta copied', 'no-sidebar' === get_post_meta( $nl_gestalt, '_generate-sidebar-layout-meta', true ) );
check( 'Yoast description copied', '' !== get_post_meta( $nl_gestalt, '_yoast_wpseo_metadesc', true ) );
check( 'Elementor CSS cache not copied', '' === get_post_meta( $nl_gestalt, '_elementor_css', true ) );
check( 'duplicate translation refused', is_wp_error( Duplicator::create( $gestalt, 'nl' ) ) );
check( 'same-language translation refused', is_wp_error( Duplicator::create( $gestalt, 'en' ) ) );

section( 'Slugs' );
wp_update_post( array( 'ID' => $nl_gestalt, 'post_status' => 'publish', 'post_title' => 'Gestalttherapie', 'post_name' => 'gestalt-therapie' ) );
check( 'translated slug kept', 'gestalt-therapie' === get_post_field( 'post_name', $nl_gestalt ) );
$nl_reiki = Duplicator::create( $reiki, 'nl' );
wp_update_post( array( 'ID' => $nl_reiki, 'post_status' => 'publish' ) );
check( 'same slug allowed across languages', 'reiki' === get_post_field( 'post_name', $nl_reiki ), get_post_field( 'post_name', $nl_reiki ) );
$dupe = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Reiki 2', 'post_name' => 'reiki' ) );
check( 'same slug in same language still made unique', 'reiki' !== get_post_field( 'post_name', $dupe ), get_post_field( 'post_name', $dupe ) );
wp_delete_post( $dupe, true );

section( 'Hierarchy' );
$nl_walks = Duplicator::create( $walks, 'nl' );
check( 'child of untranslated parent keeps original parent', $gather === (int) get_post_field( 'post_parent', $nl_walks ) );
$nl_gather = Duplicator::create( $gather, 'nl' );
check( 'child adopted by new parent translation', $nl_gather === (int) get_post_field( 'post_parent', $nl_walks ) );
wp_update_post( array( 'ID' => $nl_gather, 'post_status' => 'publish', 'post_title' => 'Bijeenkomsten', 'post_name' => 'bijeenkomsten' ) );
wp_update_post( array( 'ID' => $nl_walks, 'post_status' => 'publish', 'post_title' => 'Stille wandelingen', 'post_name' => 'stille-wandelingen' ) );
check( 'nested permalink', home_url( '/nl/bijeenkomsten/stille-wandelingen/' ) === get_permalink( $nl_walks ), get_permalink( $nl_walks ) );

section( 'Front page, blog, posts' );
$nl_home = Duplicator::create( $home, 'nl' );
wp_update_post( array( 'ID' => $nl_home, 'post_status' => 'publish', 'post_title' => 'Home NL' ) );
check( 'front page translation permalink is /nl/', home_url( '/nl/' ) === get_permalink( $nl_home ), get_permalink( $nl_home ) );
check( 'front page permalink unchanged', home_url( '/' ) === get_permalink( $home ) );
$ro_home = Duplicator::create( $home, 'ro' );
wp_update_post( array( 'ID' => $ro_home, 'post_status' => 'publish', 'post_title' => 'Acasă' ) );
$nl_blog = Duplicator::create( $blog, 'nl' );
wp_update_post( array( 'ID' => $nl_blog, 'post_status' => 'publish' ) );
check( 'blog translation shares slug', home_url( '/nl/blog/' ) === get_permalink( $nl_blog ), get_permalink( $nl_blog ) );
$nl_post = Duplicator::create( $post1, 'nl' );
wp_update_post( array( 'ID' => $nl_post, 'post_status' => 'publish', 'post_title' => 'De liminale ruimte', 'post_name' => 'liminale-ruimte' ) );
check( 'post permalink', home_url( '/nl/liminale-ruimte/' ) === get_permalink( $nl_post ), get_permalink( $nl_post ) );
check( 'category copied', wp_get_post_categories( $nl_post ) === wp_get_post_categories( $post1 ) );
$nl_book = Duplicator::create( $book, 'nl' );
wp_update_post( array( 'ID' => $nl_book, 'post_status' => 'publish', 'post_title' => 'Afspraak maken', 'post_name' => 'afspraak' ) );
$nl_draft = Duplicator::create( $by_path( 'coaching' ), 'nl' ); // Stays draft.

section( 'Queries' );
$ids = static function ( $args ) {
	return wp_list_pluck( get_posts( array_merge( array( 'numberposts' => -1, 'post_type' => 'post' ), $args ) ), 'ID' );
};
check( 'explicit nl query finds the nl post only', $ids( array( 'wpllmml_lang' => 'nl' ) ) === array( $nl_post ) );
check( 'explicit en query excludes nl post', ! in_array( $nl_post, $ids( array( 'wpllmml_lang' => 'en' ) ), true ) && in_array( $post1, $ids( array( 'wpllmml_lang' => 'en' ) ), true ) );
check( "'all' disables filtering", in_array( $nl_post, $ids( array( 'wpllmml_lang' => 'all' ) ), true ) && in_array( $post1, $ids( array( 'wpllmml_lang' => 'all' ) ), true ) );
check( 'front-end context (this runner) filters to the request language', ! in_array( $nl_post, $ids( array() ), true ) && in_array( $post1, $ids( array() ), true ) );

section( 'Groups' );
check( 'group lists en, nl, ro for home', array_keys( Translations::get_translations( $home ) ) === array( 'en', 'nl', 'ro' ) );
Translations::unlink( $ro_home );
check( 'unlink removes ro', array_keys( Translations::get_translations( $home ) ) === array( 'en', 'nl' ) );
check( 'link restores ro', true === Translations::link( $ro_home, $home ) && array_keys( Translations::get_translations( $home ) ) === array( 'en', 'nl', 'ro' ) );
check( 'linking a second nl page is refused', is_wp_error( Translations::link( $nl_gestalt, $home ) ) );

section( 'Strings and widgets (fixtures for the HTTP tests)' );
WPLLMML\Strings::save(
	array(
		md5( 'The Library Within' )                 => array(
			's' => 'The Library Within',
			'c' => 'Site title',
			't' => array( 'nl' => 'De Bibliotheek Binnenin' ),
		),
		md5( 'About me' )                           => array(
			's' => 'About me',
			'c' => 'Menu',
			't' => array( 'nl' => 'Over mij' ),
		),
		md5( 'gestalt therapy' )                    => array(
			's' => 'gestalt therapy',
			'c' => 'Category',
			't' => array( 'nl' => 'gestalttherapie' ),
		),
	)
);
check( 'string translation stored', 'Over mij' === WPLLMML\Strings::translate( 'About me', 'nl' ) && 'About me' === WPLLMML\Strings::translate( 'About me', 'ro' ) );
$blocks                 = get_option( 'widget_block', array() );
$blocks[34]             = array( 'content' => '<!-- wp:paragraph --><p>NL-ONLY-WIDGET</p><!-- /wp:paragraph -->' );
update_option( 'widget_block', $blocks );
$sidebars               = get_option( 'sidebars_widgets', array() );
$sidebars['footer-bar'] = array( 'block-33', 'block-34' );
update_option( 'sidebars_widgets', $sidebars );
$settings                 = WPLLMML\Settings::all();
$settings['widget_langs'] = array( 'block-34' => 'nl' );
WPLLMML\Settings::save( $settings );
check( 'widget language saved', array( 'block-34' => 'nl' ) === WPLLMML\Settings::get( 'widget_langs' ) );

// Ids for the HTTP tests.
file_put_contents(
	getenv( 'WPLLMML_IDS_FILE' ) ? getenv( 'WPLLMML_IDS_FILE' ) : sys_get_temp_dir() . '/wpllmml-ids.json',
	wp_json_encode( compact( 'home', 'gestalt', 'gather', 'walks', 'blog', 'reiki', 'book', 'post1', 'nl_gestalt', 'nl_reiki', 'nl_walks', 'nl_gather', 'nl_home', 'ro_home', 'nl_blog', 'nl_post', 'nl_book', 'nl_draft' ) )
);

finish();
