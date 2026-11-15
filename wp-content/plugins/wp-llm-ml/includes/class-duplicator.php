<?php
/**
 * Creates a translation by copying a post (content, meta, terms) into a new
 * draft in another language. Elementor data survives intact because meta
 * values are slashed before they are written.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Post duplication for translations.
 */
class Duplicator {

	/**
	 * Language of the post currently being created (read by the slug filter).
	 *
	 * @var string
	 */
	public static $creating_lang = '';

	/**
	 * Meta keys never copied to a translation.
	 *
	 * @return string[]
	 */
	public static function excluded_meta_keys() {
		return (array) apply_filters(
			'wpllmml_excluded_meta_keys',
			array(
				Translations::META_LANG,
				Translations::META_GROUP,
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
				'_wp_old_date',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
				'_wp_desired_post_slug',
				// Elementor caches: regenerated for the new post.
				'_elementor_css',
				'_elementor_element_cache',
				'_elementor_page_assets',
				'_elementor_screenshot',
				'_elementor_screenshot_failed',
				// A canonical pointing at the source page would de-index the translation.
				'_yoast_wpseo_canonical',
				'_yoast_wpseo_redirect',
				'_yoast_wpseo_estimated-reading-time-minutes',
			)
		);
	}

	/**
	 * Create a draft translation of a post.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $code      Target language.
	 * @return int|\WP_Error New post id.
	 */
	public static function create( $source_id, $code ) {
		$source = get_post( $source_id );
		if ( ! $source || ! Translations::is_translatable_type( $source->post_type ) ) {
			return new \WP_Error( 'wpllmml_invalid', __( 'This content cannot be translated.', 'wp-llm-ml' ) );
		}
		if ( ! Languages::exists( $code ) ) {
			return new \WP_Error( 'wpllmml_invalid_lang', __( 'Unknown language.', 'wp-llm-ml' ) );
		}
		if ( Translations::get_lang( $source ) === $code ) {
			return new \WP_Error( 'wpllmml_same_lang', __( 'The post is already in this language.', 'wp-llm-ml' ) );
		}
		$existing = Translations::get_translation( $source, $code );
		if ( $existing ) {
			return new \WP_Error( 'wpllmml_exists', __( 'A translation in this language already exists.', 'wp-llm-ml' ) );
		}

		$parent = (int) $source->post_parent;
		if ( $parent ) {
			$parent_translation = Translations::get_translation( $parent, $code );
			if ( $parent_translation ) {
				$parent = $parent_translation;
			}
		}

		$postarr = array(
			'post_type'             => $source->post_type,
			'post_status'           => 'draft',
			'post_author'           => get_current_user_id() ? get_current_user_id() : $source->post_author,
			'post_title'            => $source->post_title,
			'post_name'             => $source->post_name,
			'post_content'          => $source->post_content,
			'post_excerpt'          => $source->post_excerpt,
			'post_content_filtered' => $source->post_content_filtered,
			'post_parent'           => $parent,
			'menu_order'            => $source->menu_order,
			'comment_status'        => $source->comment_status,
			'ping_status'           => $source->ping_status,
			'post_password'         => $source->post_password,
			'post_mime_type'        => $source->post_mime_type,
		);
		$postarr = apply_filters( 'wpllmml_duplicate_postarr', $postarr, $source, $code );

		self::$creating_lang = $code;
		// wp_insert_post() unslashes its input; slash so content with backslashes survives.
		$new_id              = wp_insert_post( wp_slash( $postarr ), true );
		self::$creating_lang = '';
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		self::copy_meta( $source->ID, $new_id );
		self::copy_terms( $source, $new_id );

		update_post_meta( $new_id, Translations::META_LANG, $code );
		$link = Translations::link( $new_id, $source->ID );
		if ( is_wp_error( $link ) ) {
			wp_delete_post( $new_id, true );
			return $link;
		}

		self::adopt_children( $source, $new_id, $code );
		Translations::flush();
		Sync::set_base( $new_id, $source->ID );

		do_action( 'wpllmml_translation_created', $new_id, $source->ID, $code );
		return $new_id;
	}

	/**
	 * Copy post meta, keeping serialized and JSON values byte-identical.
	 *
	 * @param int $from Source id.
	 * @param int $to   Target id.
	 */
	public static function copy_meta( $from, $to ) {
		$excluded = self::excluded_meta_keys();
		$all      = get_post_meta( $from );
		foreach ( (array) $all as $key => $values ) {
			if ( in_array( $key, $excluded, true ) || 0 === strpos( $key, '_wpllmml_' ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// add_post_meta() unslashes its input: Elementor's JSON (\" and \n
				// escapes) would be corrupted without wp_slash().
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * Copy taxonomy terms.
	 *
	 * @param \WP_Post $source Source post.
	 * @param int      $to     Target id.
	 */
	private static function copy_terms( \WP_Post $source, $to ) {
		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy );
			}
		}
	}

	/**
	 * Translations of the source's children that still hang under the source
	 * (because the parent had no translation yet) move under the new parent.
	 *
	 * @param \WP_Post $source Source post.
	 * @param int      $new_id New translation.
	 * @param string   $code   Language.
	 */
	private static function adopt_children( \WP_Post $source, $new_id, $code ) {
		if ( ! is_post_type_hierarchical( $source->post_type ) ) {
			return;
		}
		$children = get_children(
			array(
				'post_parent' => $source->ID,
				'post_type'   => $source->post_type,
				'post_status'  => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'       => 'ids',
				'wpllmml_lang' => 'all',
			)
		);
		foreach ( $children as $child_id ) {
			if ( Translations::get_lang( $child_id ) === $code ) {
				wp_update_post(
					array(
						'ID'          => $child_id,
						'post_parent' => $new_id,
					)
				);
			}
		}
	}
}
