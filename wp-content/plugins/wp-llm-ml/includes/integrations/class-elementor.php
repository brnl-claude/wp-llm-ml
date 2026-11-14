<?php
/**
 * Elementor (free) integration.
 *
 * Every translation is a normal Elementor document with its own layout, so
 * Elementor needs no special treatment to render it. This class adds:
 * - a "Language switcher" widget,
 * - a "Translations" section in the page settings panel of the editor, with
 *   links to edit or create the other language versions,
 * - link localisation for Elementor output (see Links).
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Admin\Admin;
use WPLLMML\Languages;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor integration.
 */
class Elementor {

	/**
	 * Register hooks (they only fire when Elementor is active).
	 */
	public function boot() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		add_action( 'elementor/documents/register_controls', array( $this, 'register_document_controls' ) );
	}

	/**
	 * Register the switcher widget.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 */
	public function register_widget( $manager ) {
		$manager->register( new Elementor_Switcher_Widget() );
	}

	/**
	 * Translations panel in the editor's page settings.
	 *
	 * @param \Elementor\Core\Base\Document $document Document.
	 */
	public function register_document_controls( $document ) {
		if ( ! Languages::is_multilingual() || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}
		$post = get_post( $document->get_main_id() );
		if ( ! $post || ! Translations::is_translatable_type( $post->post_type ) ) {
			return;
		}

		$own          = Translations::get_lang( $post );
		$own_lang     = Languages::get( $own );
		$translations = Translations::get_translations( $post );
		if ( ! $own_lang ) {
			return;
		}
		$rows         = '';
		foreach ( Languages::all() as $code => $lang ) {
			if ( $code === $own ) {
				$rows .= sprintf( '<li><strong>%s</strong> — %s</li>', esc_html( $lang['name'] ), esc_html__( 'this page', 'wp-llm-ml' ) );
				continue;
			}
			if ( isset( $translations[ $code ] ) ) {
				$id     = $translations[ $code ];
				$status = get_post_status_object( get_post_status( $id ) );
				$rows  .= sprintf(
					'<li><strong>%1$s</strong> — <a href="%2$s" target="_top">%3$s</a> <em>(%4$s)</em></li>',
					esc_html( $lang['name'] ),
					esc_url( admin_url( 'post.php?post=' . $id . '&action=elementor' ) ),
					esc_html__( 'Edit with Elementor', 'wp-llm-ml' ),
					esc_html( $status ? $status->label : '' )
				);
			} else {
				$rows .= sprintf(
					'<li><strong>%1$s</strong> — <a href="%2$s" target="_top">%3$s</a></li>',
					esc_html( $lang['name'] ),
					esc_url( Admin::create_translation_url( $post->ID, $code, 'elementor' ) ),
					esc_html__( 'Create translation', 'wp-llm-ml' )
				);
			}
		}

		$document->start_controls_section(
			'wpllmml_translations',
			array(
				'label' => sprintf( /* translators: %s: language name */ __( 'Translations (%s)', 'wp-llm-ml' ), $own_lang['name'] ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			)
		);
		$document->add_control(
			'wpllmml_translations_list',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => '<ul class="wpllmml-elementor-translations" style="line-height:1.9">' . $rows . '</ul><p style="opacity:.7">' . esc_html__( 'Save this page before switching.', 'wp-llm-ml' ) . '</p>',
				'content_classes' => 'elementor-descriptor',
			)
		);
		$document->end_controls_section();
	}
}
