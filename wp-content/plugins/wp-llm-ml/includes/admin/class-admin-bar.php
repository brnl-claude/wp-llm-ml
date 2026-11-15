<?php
/**
 * Toolbar menu on the front end: language of the current page and quick
 * links to edit or create its translations (Elementor-aware).
 *
 * @package WPLLMML
 */

namespace WPLLMML\Admin;

use WPLLMML\Languages;
use WPLLMML\Router;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Admin bar.
 */
class Admin_Bar {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Constructor.
	 *
	 * @param Router $router Router.
	 */
	public function __construct( Router $router ) {
		$this->router = $router;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( Languages::is_multilingual() ) {
			add_action( 'admin_bar_menu', array( $this, 'menu' ), 85 );
		}
	}

	/**
	 * Add the nodes.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function menu( $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! Translations::is_translatable_type( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$own          = Translations::get_lang( $post );
		$translations = Translations::get_translations( $post );
		$bar->add_node(
			array(
				'id'    => 'wpllmml',
				'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span>' . esc_html( strtoupper( $own ) ),
				'href'  => Admin::page_url(),
				'meta'  => array( 'title' => __( 'Languages', 'wp-llm-ml' ) ),
			)
		);
		foreach ( Languages::all() as $code => $lang ) {
			if ( $code === $own ) {
				continue;
			}
			if ( isset( $translations[ $code ] ) ) {
				$bar->add_node(
					array(
						'parent' => 'wpllmml',
						'id'     => 'wpllmml-' . $code,
						/* translators: %s: language name */
						'title'  => esc_html( sprintf( __( 'Edit %s', 'wp-llm-ml' ), $lang['name'] ) ),
						'href'   => Admin::editor_url( $translations[ $code ] ),
					)
				);
			} else {
				$builder = 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
				$bar->add_node(
					array(
						'parent' => 'wpllmml',
						'id'     => 'wpllmml-' . $code,
						/* translators: %s: language name */
						'title'  => esc_html( sprintf( __( 'Create %s translation', 'wp-llm-ml' ), $lang['name'] ) ),
						'href'   => Admin::create_translation_url( $post->ID, $code, $builder ? 'elementor' : 'edit' ),
					)
				);
			}
		}
	}
}
