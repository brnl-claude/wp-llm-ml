<?php
/**
 * Language-aware permalinks and localisation of internal links in content
 * (Elementor buttons, footer widgets, copyright text...).
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Link building and rewriting.
 */
class Links {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	const INDEX_TRANSIENT = 'wpllmml_link_index';

	/**
	 * Per-request URL maps: lang => [normalised source URL => target URL].
	 *
	 * @var array
	 */
	private $maps = array();

	/**
	 * Link index (see index()).
	 *
	 * @var array|null
	 */
	private $index = null;

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
		// Keep the link index fresh, also while only one language is configured.
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'update_option_permalink_structure', 'update_option_page_on_front', 'update_option_show_on_front', 'update_option_' . Settings::OPTION ) as $hook ) {
			add_action( $hook, array( $this, 'flush_index' ) );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'maybe_flush_on_meta' ), 10, 3 );
		}
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 20, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 20 );

		// Internal links inside content follow the visitor's language.
		add_filter( 'the_content', array( $this, 'localize_html' ), 100 );
		add_filter( 'elementor/frontend/the_content', array( $this, 'localize_html' ), 100 );
		add_filter( 'widget_text', array( $this, 'localize_html' ), 100 );
		add_filter( 'widget_text_content', array( $this, 'localize_html' ), 100 );
		add_filter( 'widget_block_content', array( $this, 'localize_html' ), 100 );
		add_filter( 'wp_nav_menu', array( $this, 'localize_html' ), 100 );
	}

	/**
	 * Permalink of a post or custom post type.
	 *
	 * @param string       $url  URL.
	 * @param \WP_Post|int $post Post.
	 * @return string
	 */
	public function filter_post_link( $url, $post ) {
		$post = get_post( $post );
		if ( ! $post || ! Translations::is_translatable_type( $post->post_type ) ) {
			return $url;
		}
		return $this->router->add_prefix( $url, Translations::get_lang( $post ) );
	}

	/**
	 * Permalink of a page; translations of the front page map to /xx/.
	 *
	 * @param string $url     URL.
	 * @param int    $post_id Page id.
	 * @return string
	 */
	public function filter_page_link( $url, $post_id ) {
		if ( ! Translations::is_translatable_type( 'page' ) ) {
			return $url;
		}
		$lang  = Translations::get_lang( $post_id );
		$front = $this->router->raw_front_page();
		if ( $front && ( (int) $post_id === $front || in_array( (int) $post_id, Translations::get_translations( $front ), true ) ) ) {
			return $this->router->language_home( $lang );
		}
		return $this->router->add_prefix( $url, $lang );
	}

	/**
	 * Term archives are shared between languages: keep the visitor's prefix.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function filter_term_link( $url ) {
		if ( ! $this->router->is_rendering() ) {
			return $url;
		}
		return $this->router->add_prefix( $url, $this->router->lang );
	}

	/**
	 * Normalise a URL for lookups: path only (same host), trailing slash.
	 *
	 * @param string $url URL.
	 * @return string '' when not a URL of this site.
	 */
	public function normalize( $url ) {
		$url   = html_entity_decode( trim( (string) $url ), ENT_QUOTES );
		$parts = wp_parse_url( $url );
		if ( false === $parts || ( ! isset( $parts['path'] ) && ! isset( $parts['host'] ) ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) ) {
			$home_host = wp_parse_url( get_option( 'home' ), PHP_URL_HOST );
			$host      = strtolower( $parts['host'] );
			if ( strtolower( (string) $home_host ) !== $host && 'www.' . strtolower( (string) $home_host ) !== $host && strtolower( (string) $home_host ) !== 'www.' . $host ) {
				return '';
			}
		} elseif ( isset( $parts['path'] ) && '/' !== substr( $parts['path'], 0, 1 ) ) {
			return ''; // Relative paths such as "foo/bar" or "#anchor".
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		return strtolower( trailingslashit( rawurldecode( $path ) ) );
	}

	/**
	 * Index of all published posts that have translations:
	 * group => [code => [id, permalink]]. Built with one query and stored in
	 * a transient that is cleared whenever posts, links or settings change.
	 *
	 * @return array
	 */
	public function index() {
		global $wpdb;
		if ( null !== $this->index ) {
			return $this->index;
		}
		$index = get_transient( self::INDEX_TRANSIENT );
		if ( ! is_array( $index ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, g.meta_value AS grp, l.meta_value AS lang FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} g ON ( g.post_id = p.ID AND g.meta_key = %s )
					LEFT JOIN {$wpdb->postmeta} l ON ( l.post_id = p.ID AND l.meta_key = %s )
					WHERE p.post_status = 'publish' AND p.post_password = '' ORDER BY p.ID ASC",
					Translations::META_GROUP,
					Translations::META_LANG
				)
			);
			_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ), false, true );
			$index = array();
			foreach ( $rows as $row ) {
				$code = $row->lang ? $row->lang : Languages::default_code();
				if ( ! isset( $index[ $row->grp ][ $code ] ) ) {
					$index[ $row->grp ][ $code ] = array( (int) $row->ID, get_permalink( (int) $row->ID ) );
				}
			}
			set_transient( self::INDEX_TRANSIENT, $index, DAY_IN_SECONDS );
		}
		$this->index = $index;
		return $index;
	}

	/**
	 * Forget the link index (posts, translations or settings changed).
	 */
	public function flush_index() {
		delete_transient( self::INDEX_TRANSIENT );
		$this->index = null;
		$this->maps  = array();
	}

	/**
	 * Clear the index when one of our meta keys changes.
	 *
	 * @param mixed  $meta_id  Meta id(s).
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Key.
	 */
	public function maybe_flush_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, array( Translations::META_LANG, Translations::META_GROUP ), true ) ) {
			$this->flush_index();
		}
	}

	/**
	 * Map of URLs in other languages to their translation in $lang.
	 *
	 * @param string $lang Target language.
	 * @return array normalised source URL => target URL
	 */
	public function url_map( $lang ) {
		if ( isset( $this->maps[ $lang ] ) ) {
			return $this->maps[ $lang ];
		}
		$map = array();
		foreach ( $this->index() as $members ) {
			if ( empty( $members[ $lang ] ) ) {
				continue;
			}
			foreach ( $members as $code => $member ) {
				$source = $code === $lang ? '' : $this->normalize( $member[1] );
				if ( '' !== $source ) {
					$map[ $source ] = $members[ $lang ][1];
				}
			}
		}
		$this->maps[ $lang ] = $map;
		return $map;
	}

	/**
	 * Post id behind a permalink, for published posts that have translations.
	 * (url_to_postid() cannot see language prefixes or shared slugs.)
	 *
	 * @param string $url URL.
	 * @return int
	 */
	public function post_id_for_url( $url ) {
		if ( ! isset( $this->maps['#ids'] ) ) {
			$this->maps['#ids'] = array();
			foreach ( $this->index() as $members ) {
				foreach ( $members as $member ) {
					$this->maps['#ids'][ $this->normalize( $member[1] ) ] = $member[0];
				}
			}
		}
		$norm = $this->normalize( $url );
		return isset( $this->maps['#ids'][ $norm ] ) ? $this->maps['#ids'][ $norm ] : 0;
	}

	/**
	 * Translate one URL into the target language (or return it unchanged).
	 *
	 * @param string      $url  URL.
	 * @param string|null $lang Target language (default: current).
	 * @return string
	 */
	public function localize_url( $url, $lang = null ) {
		$lang = null === $lang ? $this->router->lang : $lang;
		if ( $lang === Languages::default_code() || ! Languages::exists( $lang ) ) {
			return $url;
		}
		$key = $this->normalize( $url );
		if ( '' === $key ) {
			return $url;
		}
		$map = $this->url_map( $lang );
		if ( isset( $map[ $key ] ) ) {
			$parts  = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ) );
			$suffix = ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
			return $map[ $key ] . $suffix;
		}
		return $url;
	}

	/**
	 * Rewrite href attributes of internal links to the visitor's language.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function localize_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, 'href' ) ) {
			return $html;
		}
		if ( ! in_array( $this->router->context, array( 'front', 'ajax-front' ), true ) || $this->router->lang === Languages::default_code() ) {
			return $html;
		}
		if ( ! apply_filters( 'wpllmml_localize_links', true, $html ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/<a\b[^>]*>/i',
			function ( $tag ) {
				// Links that name a language (switchers, hreflang) are deliberate.
				if ( preg_match( '/\s(hreflang|data-wpllmml-lang)\s*=/i', $tag[0] ) ) {
					return $tag[0];
				}
				return (string) preg_replace_callback(
					'/(\shref\s*=\s*)(["\'])([^"\']*)\2/i',
					function ( $m ) {
						$new = $this->localize_url( $m[3] );
						return $m[1] . $m[2] . ( $new === $m[3] ? $m[3] : esc_url( $new ) ) . $m[2];
					},
					$tag[0],
					1
				);
			},
			$html
		);
	}
}
