<?php
/**
 * Language filtering of post queries: blog, archives, search, feeds, widgets
 * and previous/next post links only show content in the visitor's language.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Query filtering.
 */
class Query {

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
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
		foreach ( array( 'previous', 'next' ) as $dir ) {
			add_filter( "get_{$dir}_post_join", array( $this, 'adjacent_join' ), 10, 5 );
			add_filter( "get_{$dir}_post_where", array( $this, 'adjacent_where' ), 10, 5 );
		}
	}

	/**
	 * Add a language constraint to a query.
	 *
	 * Explicit `wpllmml_lang` query arg: a code, or 'all' to disable filtering.
	 *
	 * @param \WP_Query $q Query.
	 */
	public function filter_query( $q ) {
		$lang = $q->get( 'wpllmml_lang' );
		if ( 'all' === $lang ) {
			return;
		}
		if ( '' === $lang || null === $lang ) {
			if ( ! in_array( $this->router->context, array( 'front', 'ajax-front' ), true ) ) {
				return;
			}
			// Direct lookups (a specific post or page) are never filtered. The
			// posts page keeps its page_id/pagename var but lists posts.
			if ( ! $q->is_posts_page ) {
				foreach ( array( 'p', 'page_id', 'name', 'pagename', 'attachment', 'attachment_id', 'post__in' ) as $var ) {
					if ( ! empty( $q->get( $var ) ) ) {
						return;
					}
				}
				if ( $q->is_singular() ) {
					return;
				}
			}
			$lang = $this->router->lang;
		}
		if ( ! Languages::exists( $lang ) || ! $this->targets_translatable( $q ) ) {
			return;
		}
		$q->set( 'meta_query', self::merge_meta_query( $q->get( 'meta_query' ), $lang ) );
	}

	/**
	 * Does the query only ask for translatable post types?
	 *
	 * @param \WP_Query $q Query.
	 * @return bool
	 */
	private function targets_translatable( $q ) {
		$types = $q->get( 'post_type' );
		if ( empty( $types ) ) {
			$types = $q->is_attachment() ? 'attachment' : 'post';
		}
		if ( 'any' === $types ) {
			return true;
		}
		foreach ( (array) $types as $type ) {
			if ( ! Translations::is_translatable_type( $type ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Combine an existing meta_query with the language clause.
	 *
	 * @param mixed  $existing Existing meta query.
	 * @param string $lang     Language code.
	 * @return array
	 */
	public static function merge_meta_query( $existing, $lang ) {
		if ( Languages::default_code() === $lang ) {
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => Translations::META_LANG,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Translations::META_LANG,
					'value' => $lang,
				),
			);
		} else {
			$clause = array(
				array(
					'key'   => Translations::META_LANG,
					'value' => $lang,
				),
			);
		}
		if ( empty( $existing ) || ! is_array( $existing ) ) {
			return $clause;
		}
		return array(
			'relation' => 'AND',
			$existing,
			$clause,
		);
	}

	/**
	 * JOIN for previous/next post links.
	 *
	 * @param string $join           JOIN clause.
	 * @param bool   $in_same_term   Unused.
	 * @param array  $excluded_terms Unused.
	 * @param string $taxonomy       Unused.
	 * @param object $post           Current post.
	 * @return string
	 */
	public function adjacent_join( $join, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		global $wpdb;
		if ( ! $post || ! Translations::is_translatable_type( $post->post_type ) ) {
			return $join;
		}
		return $join . $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS wpllmml_pm ON ( p.ID = wpllmml_pm.post_id AND wpllmml_pm.meta_key = %s )", Translations::META_LANG );
	}

	/**
	 * WHERE for previous/next post links.
	 *
	 * @param string $where          WHERE clause.
	 * @param bool   $in_same_term   Unused.
	 * @param array  $excluded_terms Unused.
	 * @param string $taxonomy       Unused.
	 * @param object $post           Current post.
	 * @return string
	 */
	public function adjacent_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		global $wpdb;
		if ( ! $post || ! Translations::is_translatable_type( $post->post_type ) ) {
			return $where;
		}
		$lang = Translations::get_lang( $post );
		if ( Languages::default_code() === $lang ) {
			return $where . $wpdb->prepare( ' AND ( wpllmml_pm.meta_value IS NULL OR wpllmml_pm.meta_value = %s )', $lang );
		}
		return $where . $wpdb->prepare( ' AND wpllmml_pm.meta_value = %s', $lang );
	}
}
