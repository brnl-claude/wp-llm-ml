<?php
/**
 * Put a spelling mistake the mock AI recognises ("Welcom to") in the
 * Coaching page, for the spell check browser test.
 *
 * @package WPLLMML
 */

$coaching = get_page_by_path( 'coaching' );
$data     = WPLLMML\Ai\Fields::elementor_data( $coaching->ID );
$data[0]['elements'][0]['elements'][1]['settings']['editor'] = str_replace( 'Welcome to', 'Welcom to', $data[0]['elements'][0]['elements'][1]['settings']['editor'] );
WPLLMML\Ai\Fields::save_elementor_data( $coaching->ID, $data );
echo "Typo in Coaching\n";
