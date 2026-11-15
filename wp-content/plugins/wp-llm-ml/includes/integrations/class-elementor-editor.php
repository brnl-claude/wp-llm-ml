<?php
/**
 * Language switcher in the Elementor editor's top bar (EN · NL · RO next to
 * the desktop/tablet/mobile switch). Switching opens the other language
 * version in the same editor without a page reload.
 *
 * The top bar is extended through Elementor's editor packages
 * (window.elementorV2.editorAppBar / editorDocuments), the same way
 * Elementor's own add-ons do. If a future Elementor version changes them,
 * the script detects that and adds nothing; the "Translations" section in
 * Page Settings (see Elementor) keeps working either way. A weekly CI job
 * tests against the latest Elementor release.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Admin\Admin;
use WPLLMML\Ai\Client;
use WPLLMML\Ai\Fields;
use WPLLMML\Languages;
use WPLLMML\Sync;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor editor top-bar language switcher.
 */
class Elementor_Editor {

	/**
	 * Script handles the switcher needs from Elementor.
	 *
	 * @var string[]
	 */
	const DEPS = array( 'react', 'elementor-v2-editor-app-bar', 'elementor-v2-editor-documents', 'elementor-v2-ui' );

	/**
	 * Register hooks.
	 */
	public function boot() {
		// Fires before Elementor's loader starts the editor: top-bar
		// extensions must be registered by then.
		add_action( 'elementor/editor/v2/scripts/enqueue', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_wpllmml_editor_group', array( $this, 'ajax_group' ) );
		add_action( 'wp_ajax_wpllmml_editor_reviewed', array( $this, 'ajax_reviewed' ) );
	}

	/**
	 * Enqueue the switcher in the editor.
	 */
	public function enqueue() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id || ! Languages::is_multilingual() || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::DEPS as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				return; // Elementor changed; the Page Settings section remains.
			}
		}
		wp_enqueue_style( 'wpllmml-elementor-editor', WPLLMML_URL . 'assets/css/elementor-editor.css', array(), WPLLMML_VERSION );
		wp_enqueue_script( 'wpllmml-elementor-editor', WPLLMML_URL . 'assets/js/elementor-editor.js', self::DEPS, WPLLMML_VERSION, true );
		$default = Languages::get( Languages::default_code() );
		wp_localize_script(
			'wpllmml-elementor-editor',
			'wpllmmlEditor',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wpllmml_ajax' ),
				'ai'    => Client::is_configured(),
				'group' => self::group( $post_id ),
				'i18n'  => array(
					'languages'    => __( 'Language versions of this page', 'wp-llm-ml' ),
					/* translators: 1: language name, 2: post status */
					'version'      => __( '%1$s (%2$s)', 'wp-llm-ml' ),
					'thisPage'     => __( 'You are editing this version.', 'wp-llm-ml' ),
					/* translators: %s: language name */
					'switchTo'     => __( 'Switch to %s', 'wp-llm-ml' ),
					/* translators: %s: language name */
					'missing'      => __( 'No %s version yet.', 'wp-llm-ml' ),
					'createAi'     => __( 'Create & translate with AI', 'wp-llm-ml' ),
					'createCopy'   => __( 'Create a copy to translate yourself', 'wp-llm-ml' ),
					'notElementor' => __( 'Not built with Elementor: opens in the WordPress editor.', 'wp-llm-ml' ),
					/* translators: %s: language name */
					'follows'      => sprintf( __( 'Layout follows the %s page: layout changes made there are copied here automatically; texts stay translated.', 'wp-llm-ml' ), $default['name'] ),
					'ownLayout'    => __( 'Has its own layout.', 'wp-llm-ml' ),
					/* translators: %s: language name */
					'syncing'      => sprintf( __( 'Updating from the %s page…', 'wp-llm-ml' ), $default['name'] ),
					/* translators: %s: error message */
					'syncFailed'   => __( 'The last update failed: %s', 'wp-llm-ml' ),
					'syncRetry'    => __( 'Update now', 'wp-llm-ml' ),
					'markReviewed' => __( 'Mark as reviewed', 'wp-llm-ml' ),
					/* translators: %s: elapsed time, e.g. 0:35 */
					'working'      => __( 'Translating with AI… %s', 'wp-llm-ml' ),
					'cancel'       => __( 'Cancel translation', 'wp-llm-ml' ),
					'saveFirst'    => __( 'The translation is made from the saved page; your unsaved changes are not included. Continue?', 'wp-llm-ml' ),
					'leave'        => __( 'A translation is still running. Leave this page?', 'wp-llm-ml' ),
					/* translators: %s: error message */
					'failed'       => __( 'Translation stopped: %s', 'wp-llm-ml' ),
					'error'        => __( 'Error', 'wp-llm-ml' ),
				),
			)
		);
	}

	/**
	 * Everything the switcher shows about a page's language versions.
	 *
	 * @param int $post_id Any version of the page.
	 * @return array|null Null when the post is not translatable.
	 */
	public static function group( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! Translations::is_translatable_type( $post->post_type ) || ! Languages::is_multilingual() ) {
			return null;
		}
		$default      = Languages::default_code();
		$default_name = Languages::get( $default )['name'];
		$translations = Translations::get_translations( $post );
		$source       = isset( $translations[ $default ] ) ? (int) $translations[ $default ] : 0;
		$from         = $source ? $source : (int) $post->ID;
		$type         = get_post_type_object( $post->post_type );
		$can_create   = $type && current_user_can( $type->cap->create_posts ) && current_user_can( 'edit_post', $from );
		$languages    = array();
		foreach ( Languages::all() as $code => $lang ) {
			$item = array(
				'code'  => $code,
				'label' => strtoupper( $code ),
				'name'  => $lang['name'],
				'id'    => 0,
			);
			if ( isset( $translations[ $code ] ) ) {
				$id     = (int) $translations[ $code ];
				$status = get_post_status_object( (string) get_post_status( $id ) );
				$review = Sync::review( $id );
				$item   = array_merge(
					$item,
					array(
						'id'          => $id,
						'statusLabel' => $status ? $status->label : '',
						'canEdit'     => current_user_can( 'edit_post', $id ),
						'elementor'   => Fields::is_elementor( $id ),
						'url'         => Admin::editor_url( $id ),
						'review'      => $review ? Admin::review_text( $review, $default_name ) : '',
						'follows'     => null,
						'sync'        => '',
						'syncError'   => '',
					)
				);
				if ( $code !== $default && $source && Fields::is_elementor( $source ) && $item['elementor'] ) {
					$item['follows'] = Sync::follows( $id );
					if ( $item['follows'] ) {
						$item['sync']      = Sync::state( $id );
						$item['syncError'] = Sync::error( $id );
					}
				}
			} elseif ( $can_create ) {
				$item['create'] = Admin::create_translation_url( $from, $code, 'elementor' );
			}
			$languages[] = $item;
		}
		return array(
			'source'    => $from,
			'languages' => $languages,
		);
	}

	/**
	 * Fresh group data (after switching, saving, or while a sync runs).
	 */
	public function ajax_group() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		wp_send_json_success( self::group( $post_id ) );
	}

	/**
	 * Clear a translation's review marker from the editor.
	 */
	public function ajax_reviewed() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		delete_post_meta( $post_id, Sync::META_REVIEW );
		wp_send_json_success( self::group( $post_id ) );
	}
}
