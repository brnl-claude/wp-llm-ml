<?php
/**
 * Translation model: every post has one language and may belong to a
 * translation group (the set of posts that are translations of each other).
 *
 * Storage: post meta `_wpllmml_lang` (missing = default language, so existing
 * content needs no migration) and `_wpllmml_group` (shared group id).
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Static API around post languages and translation groups.
 */
class Translations {

	const META_LANG  = '_wpllmml_lang';
	const META_GROUP = '_wpllmml_group';

	/**
	 * Per-request cache of groups: group id => [code => post id].
	 *
	 * @var array
	 */
	private static $groups = array();

	/**
	 * Post types whose posts can be translated.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = (array) Settings::get( 'post_types', array( 'post', 'page' ) );
		return array_values( array_unique( (array) apply_filters( 'wpllmml_post_types', $types ) ) );
	}

	/**
	 * Is the post type translatable?
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_translatable_type( $type ) {
		return in_array( $type, self::post_types(), true );
	}

	/**
	 * Language code of a post (default language when not set).
	 *
	 * A code of a language that was removed from the settings is returned
	 * as-is, so that content stays hidden instead of turning into duplicates
	 * in the default language.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return string
	 */
	public static function get_lang( $post ) {
		$post_id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		$code    = $post_id ? (string) get_post_meta( $post_id, self::META_LANG, true ) : '';
		return '' !== $code ? $code : Languages::default_code();
	}

	/**
	 * Set the language of a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    Language code.
	 * @return bool
	 */
	public static function set_lang( $post_id, $code ) {
		if ( ! Languages::exists( $code ) ) {
			return false;
		}
		$translations = self::get_translations( $post_id );
		if ( isset( $translations[ $code ] ) && (int) $translations[ $code ] !== (int) $post_id ) {
			return false; // Another post in the group already has this language.
		}
		update_post_meta( $post_id, self::META_LANG, $code );
		self::$groups = array();
		return true;
	}

	/**
	 * Group id of a post (its own id when it is not in a group yet).
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function group_id( $post_id ) {
		$group = (int) get_post_meta( $post_id, self::META_GROUP, true );
		return $group ? $group : (int) $post_id;
	}

	/**
	 * All posts in the translation group of a post, including itself.
	 *
	 * @param int|\WP_Post $post Post.
	 * @return array code => post id
	 */
	public static function get_translations( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! self::is_translatable_type( $post->post_type ) ) {
			return array();
		}
		$group = self::group_id( $post->ID );
		if ( ! isset( self::$groups[ $group ] ) ) {
			self::$groups[ $group ] = self::load_group( $group );
		}
		$list = self::$groups[ $group ];
		// The post itself always wins its own language slot.
		$list[ self::get_lang( $post ) ] = (int) $post->ID;
		return self::sort( $list );
	}

	/**
	 * Load a group from cache or database.
	 *
	 * @param int $group Group id.
	 * @return array code => post id
	 */
	private static function load_group( $group ) {
		global $wpdb;
		$key  = 'group_' . $group . '_' . wp_cache_get_last_changed( 'posts' );
		$list = wp_cache_get( $key, 'wpllmml' );
		if ( is_array( $list ) ) {
			return $list;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status NOT IN ('trash','auto-draft','inherit')
				ORDER BY p.ID ASC",
				self::META_GROUP,
				(string) $group
			)
		);
		// The group's founding post may not carry the meta yet.
		if ( ! in_array( (string) $group, $ids, true ) ) {
			$founder = get_post( $group );
			if ( $founder && ! in_array( $founder->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) && self::group_id( $founder->ID ) === (int) $group ) {
				array_unshift( $ids, (string) $group );
			}
		}
		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );
		$list = array();
		foreach ( $ids as $id ) {
			$code = self::get_lang( $id );
			if ( ! isset( $list[ $code ] ) ) { // On conflicts the oldest post wins.
				$list[ $code ] = $id;
			}
		}
		wp_cache_set( $key, $list, 'wpllmml', HOUR_IN_SECONDS );
		return $list;
	}

	/**
	 * Order a code => id list like the configured languages.
	 *
	 * @param array $list List.
	 * @return array
	 */
	private static function sort( array $list ) {
		$sorted = array();
		foreach ( Languages::codes() as $code ) {
			if ( isset( $list[ $code ] ) ) {
				$sorted[ $code ] = $list[ $code ];
			}
		}
		return $sorted;
	}

	/**
	 * The translation of a post in a language.
	 *
	 * @param int|\WP_Post $post           Post.
	 * @param string       $code           Language code.
	 * @param bool         $published_only Only return publicly visible translations.
	 * @return int 0 when none.
	 */
	public static function get_translation( $post, $code, $published_only = false ) {
		$list = self::get_translations( $post );
		if ( empty( $list[ $code ] ) ) {
			return 0;
		}
		$id = (int) $list[ $code ];
		if ( $published_only && ! self::is_public( $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Is a post publicly visible?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_public( $post_id ) {
		$post = get_post( $post_id );
		return $post && 'publish' === $post->post_status && '' === $post->post_password;
	}

	/**
	 * Put $post_id into the translation group of $target_id.
	 *
	 * @param int $post_id   Post to link.
	 * @param int $target_id Post whose group to join.
	 * @return true|\WP_Error
	 */
	public static function link( $post_id, $target_id ) {
		$post   = get_post( $post_id );
		$target = get_post( $target_id );
		if ( ! $post || ! $target || $post->ID === $target->ID ) {
			return new \WP_Error( 'wpllmml_invalid', __( 'Invalid posts.', 'wp-llm-ml' ) );
		}
		$lang     = self::get_lang( $post );
		$existing = self::get_translations( $target );
		if ( isset( $existing[ $lang ] ) && $existing[ $lang ] !== $post->ID ) {
			return new \WP_Error( 'wpllmml_conflict', __( 'That group already has a translation in this language.', 'wp-llm-ml' ) );
		}
		$group = self::group_id( $target->ID );
		update_post_meta( $target->ID, self::META_GROUP, $group );
		update_post_meta( $post->ID, self::META_GROUP, $group );
		self::$groups = array();
		return true;
	}

	/**
	 * Remove a post from its translation group.
	 *
	 * @param int $post_id Post id.
	 */
	public static function unlink( $post_id ) {
		$group = self::group_id( $post_id );
		if ( $group === (int) $post_id ) {
			// The founder leaves: hand the group over to the remaining members.
			$members = array_diff( self::get_translations( $post_id ), array( (int) $post_id ) );
			if ( $members ) {
				$new_group = (int) min( $members );
				foreach ( $members as $member ) {
					update_post_meta( $member, self::META_GROUP, $new_group );
				}
			}
		}
		delete_post_meta( $post_id, self::META_GROUP );
		self::$groups = array();
	}

	/**
	 * Forget per-request caches.
	 */
	public static function flush() {
		self::$groups = array();
	}
}
