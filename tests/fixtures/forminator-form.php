<?php
/**
 * Creates a Forminator form like the site's "Book consult": Forminator's
 * contact form template plus a choice, an HTML block, a confirmation e-mail
 * to the visitor and custom texts. Returns the form id. Its name is
 * $wpllmml_form_name when set, else "Book consult".
 *
 * @package WPLLMML
 */

\Forminator_API::initialize( true );
$templates = WP_PLUGIN_DIR . '/forminator/library/modules/custom-forms/form-templates/';
foreach ( glob( WP_PLUGIN_DIR . '/forminator/library/abstracts/*template*.php' ) as $file ) {
	require_once $file;
}
require_once $templates . 'template-contact-form.php';
$template = new Forminator_Template_Contact_Form();
$form_id  = Forminator_API::add_form( isset( $wpllmml_form_name ) ? $wpllmml_form_name : 'Book consult', $template->fields(), $template->settings() );
if ( is_wp_error( $form_id ) ) {
	return $form_id;
}

$meta = get_post_meta( $form_id, 'forminator_form_meta', true );
foreach ( $meta['fields'] as $i => $field ) {
	if ( 'textarea-1' === $field['element_id'] ) {
		$meta['fields'][ $i ]['placeholder'] = 'Tell me what brings you here';
		$meta['fields'][ $i ]['description'] = 'Optional';
	}
}
$base               = $meta['fields'][0];
$meta['fields'][]   = array_merge(
	array_intersect_key( $base, array_flip( array( 'form_id', 'wrapper_id', 'cols' ) ) ),
	array(
		'id'          => 'select-1',
		'element_id'  => 'select-1',
		'type'        => 'select',
		'field_label' => 'What would you like?',
		'value_type'  => 'single',
		'options'     => array(
			array(
				'label' => 'An introductory session',
				'value' => 'intro',
				'key'   => '1',
			),
			array(
				'label' => 'A full session',
				'value' => 'full',
				'key'   => '2',
			),
		),
		'wrapper_id'  => 'wrapper-select-1',
		'form_id'     => 'wrapper-select-1',
	)
);
$meta['fields'][]   = array(
	'id'         => 'html-1',
	'element_id' => 'html-1',
	'type'       => 'html',
	'variations' => '<p>We reply within <strong>two days</strong>.</p>',
	'wrapper_id' => 'wrapper-html-1',
	'form_id'    => 'wrapper-html-1',
);
$meta['settings']['submitData']['custom-submit-text'] = 'Send';
$meta['notifications'][]                              = array(
	'slug'             => 'notification-visitor',
	'label'            => 'Confirmation to the visitor',
	'email-recipients' => 'default',
	'recipients'       => '{email-1}',
	'email-subject'    => 'Thank you, {name-1}',
	'email-editor'     => 'We received your message: <br/> {all_fields}',
	'type'             => 'default',
);
update_post_meta( $form_id, 'forminator_form_meta', $meta );
return (int) $form_id;
