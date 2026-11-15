<?php
/**
 * For the Forms tab browser test: a Forminator form ("Contact form test") on a
 * page that has a Dutch translation (/contact-form-test/, /nl/contact-form-test/).
 *
 * @package WPLLMML
 */

wp_set_current_user( 1 );
$wpllmml_form_name = 'Contact form test';
$form_id           = require __DIR__ . '/forminator-form.php';
// Not $page: that is a WordPress global which saving a post can overwrite.
$form_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Contact form test',
		'post_name'    => 'contact-form-test',
		'post_content' => '[forminator_form id="' . $form_id . '"]',
	)
);
$nl = WPLLMML\Duplicator::create( $form_page, 'nl' );
wp_update_post(
	array(
		'ID'          => $nl,
		'post_status' => 'publish',
	)
);
echo "Form $form_id on page $form_page (nl $nl)\n";
