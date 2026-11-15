<?php
/**
 * Public template functions.
 *
 * @package WPLLMML
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language code of the current request, e.g. "en" or "nl".
 *
 * @return string
 */
function wpllmml_current_language() {
	return WPLLMML\Plugin::instance()->router->lang;
}

/**
 * Language code of a post.
 *
 * @param int|WP_Post|null $post Post (default: current post).
 * @return string
 */
function wpllmml_get_post_language( $post = null ) {
	return WPLLMML\Translations::get_lang( get_post( $post ) );
}

/**
 * Translations of a post.
 *
 * @param int|WP_Post|null $post Post (default: current post).
 * @return array code => post id
 */
function wpllmml_get_translations( $post = null ) {
	return WPLLMML\Translations::get_translations( get_post( $post ) );
}

/**
 * Render the language switcher.
 *
 * @param array $args style (inline|list|dropdown), label (name|code|both), show_current.
 * @return string HTML.
 */
function wpllmml_switcher( $args = array() ) {
	return WPLLMML\Plugin::instance()->switcher->render( $args );
}

/**
 * Translate a registered string into the current language.
 *
 * @param string $text Original text (as entered in the default language).
 * @return string
 */
function wpllmml_translate_string( $text ) {
	return WPLLMML\Strings::translate( $text, wpllmml_current_language() );
}
