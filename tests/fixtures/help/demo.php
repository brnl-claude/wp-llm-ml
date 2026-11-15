<?php
/**
 * Demo content for the guide's screenshots (bin/help-screenshots.sh): run on
 * a site from tests/bin/setup-site.sh after the model tests, with the mock AI
 * at WPLLMML_MOCK_AI.
 *
 * @package WPLLMML
 */

use WPLLMML\Ai\Fields;
use WPLLMML\Integrations\Forminator;
use WPLLMML\Settings;
use WPLLMML\Strings;
use WPLLMML\Sync;
use WPLLMML\Translations;

wp_set_current_user( 1 );
require dirname( __DIR__ ) . '/ai-on.php';

// Two pages without translations, for the bulk action.
foreach ( array( 'Workshops', 'Retreats' ) as $title ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>' . $title . ' in English, Dutch and Romanian.</p><!-- /wp:paragraph -->',
		)
	);
}

// A Dutch translation that was updated after a layout change.
$gestalt = get_page_by_path( 'gestalt-therapy' );
$nl      = Translations::get_translation( $gestalt, 'nl' );
wp_update_post(
	array(
		'ID'          => $nl,
		'post_status' => 'publish',
		'post_title'  => 'Gestalttherapie',
	)
);
update_post_meta(
	$nl,
	Sync::META_REVIEW,
	array(
		'translated'   => 3,
		'untranslated' => 0,
		'at'           => time(),
	)
);

// A spelling mistake for the spelling dialog.
require dirname( __DIR__ ) . '/spell-typo.php';

// The site's booking form, with a Dutch copy.
$wpllmml_form_name = 'Book consult';
$form_id           = require dirname( __DIR__ ) . '/forminator-form.php';
Forminator::make_copy( $form_id, 'nl' );

// A few texts outside pages, translated.
$table = Strings::table();
foreach ( Strings::discover() as $found ) {
	$key = md5( $found[0] );
	if ( ! isset( $table[ $key ] ) ) {
		$table[ $key ] = array(
			's' => $found[0],
			'c' => isset( $found[1] ) ? $found[1] : '',
			't' => array(),
		);
	}
	if ( 'Therapy - Counselling - Coaching' === $found[0] ) {
		$table[ $key ]['t']['nl'] = 'Therapie - Counseling - Coaching';
	}
}
Strings::save( $table );
echo "Demo content ready\n";
