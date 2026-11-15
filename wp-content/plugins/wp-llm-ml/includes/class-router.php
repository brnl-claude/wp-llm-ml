<?php
/**
 * Request routing: language from the URL prefix (/nl/...), locale switching,
 * translated front/posts pages, slug resolution per language and canonical
 * redirects. Default-language URLs stay exactly as they were.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Determines and enforces the language of the current request.
 */
class Router {

	/**
	 * Current request language code.
	 *
	 * @var string
	 */
	public $lang = '';

	/**
	 * Request context: front | admin | ajax-front | ajax-admin | rest | cron | cli.
	 *
	 * @var string
	 */
	public $context = 'front';

	/**
	 * Language prefix found in the URL ('' for the default language).
	 *
	 * @var string
	 */
	private $prefix = '';

	/**
	 * REQUEST_URI before the prefix was stripped.
	 *
	 * @var string|null
	 */
	private $original_uri = null;

	/**
	 * True once WordPress is rendering a front-end page (after redirects ran).
	 *
	 * @var bool
	 */
	private $rendering = false;

	/**
	 * Set while reading raw (unfiltered) options.
	 *
	 * @var bool
	 */
	private $bypass = false;

	/**
	 * Hook everything up. Called while plugins load.
	 */
	public function boot() {
		$this->context = $this->detect_context();
		$this->lang    = $this->detect_language();

		add_filter( 'locale', array( $this, 'filter_locale' ), 5 );
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_filter( 'do_parse_request', array( $this, 'strip_prefix' ), 1 );
		add_action( 'parse_request', array( $this, 'restore_uri' ), 1 );
		add_filter( 'request', array( $this, 'resolve_request' ), 20 );
		add_filter( 'option_page_on_front', array( $this, 'filter_page_on_front' ) );
		add_filter( 'option_page_for_posts', array( $this, 'filter_page_for_posts' ) );
		add_action( 'wp', array( $this, 'follow_previewed_post' ) );
		add_action( 'template_redirect', array( $this, 'enforce_language' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'filter_redirect_canonical' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'start_rendering' ), PHP_INT_MAX );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 3 );
		add_filter( 'wp_unique_post_slug', array( $this, 'allow_same_slug' ), 10, 6 );
	}

	/* ------------------------------------------------------------------
	 * Detection
	 * ------------------------------------------------------------------ */

	/**
	 * What kind of request is this?
	 *
	 * @return string
	 */
	private function detect_context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( wp_doing_ajax() ) {
			return $this->referer_is_admin() ? 'ajax-admin' : 'ajax-front';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		$path = $this->request_path();
		$rest = trailingslashit( $this->home_path() ) . rest_get_url_prefix() . '/';
		if ( isset( $_GET['rest_route'] ) || 0 === strpos( $path, $rest ) || preg_match( '#^' . preg_quote( trailingslashit( $this->home_path() ), '#' ) . '[a-z_-]+/' . preg_quote( rest_get_url_prefix(), '#' ) . '/#', $path ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'rest';
		}
		return 'front';
	}

	/**
	 * Language of the current request.
	 *
	 * @return string
	 */
	private function detect_language() {
		$default = Languages::default_code();
		switch ( $this->context ) {
			case 'admin':
			case 'ajax-admin':
			case 'cron':
			case 'cli':
				return $default;
			case 'ajax-front':
				// phpcs:ignore WordPress.Security.NonceVerification
				if ( isset( $_REQUEST['lang'] ) && Languages::exists( sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) ) ) {
					return sanitize_key( wp_unslash( $_REQUEST['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				}
				$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_PATH ) : '';
				$code    = $this->prefix_of_path( $referer );
				return $code ? $code : $default;
		}
		$code = $this->prefix_of_path( $this->request_path() );
		if ( $code ) {
			$this->prefix = $code;
			return $code;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( 'rest' === $this->context && isset( $_GET['lang'] ) && Languages::exists( sanitize_key( wp_unslash( $_GET['lang'] ) ) ) ) {
			return sanitize_key( wp_unslash( $_GET['lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return $default;
	}

	/**
	 * Was the AJAX request sent from wp-admin?
	 *
	 * @return bool
	 */
	private function referer_is_admin() {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return true; // No referer: assume admin, never change language.
		}
		$referer = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_PATH );
		$admin   = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
		return 0 === strpos( $referer, $admin );
	}

	/**
	 * Path of the current request URI.
	 *
	 * @return string
	 */
	private function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * Path of the home URL, e.g. "/" or "/blog/".
	 *
	 * @return string
	 */
	public function home_path() {
		$path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		return trailingslashit( is_string( $path ) ? $path : '' );
	}

	/**
	 * Non-default language code that prefixes a path, or ''.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public function prefix_of_path( $path ) {
		$home = $this->home_path();
		if ( 0 !== strpos( $path, $home ) ) {
			return '';
		}
		$rest = substr( $path, strlen( $home ) );
		$seg  = strtolower( (string) strtok( $rest, '/' ) );
		if ( '' !== $seg && $seg !== Languages::default_code() && Languages::exists( $seg ) ) {
			return $seg;
		}
		return '';
	}

	/* ------------------------------------------------------------------
	 * Locale
	 * ------------------------------------------------------------------ */

	/**
	 * Use the request language's locale outside wp-admin.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		if ( in_array( $this->context, array( 'admin', 'ajax-admin', 'cli', 'cron' ), true ) ) {
			return $locale;
		}
		$lang = Languages::get( $this->lang );
		return $lang ? $lang['locale'] : $locale;
	}

	/* ------------------------------------------------------------------
	 * Parsing
	 * ------------------------------------------------------------------ */

	/**
	 * Hide the language prefix from WordPress' rewrite matching.
	 *
	 * @param bool $do_parse Whether to parse.
	 * @return bool
	 */
	public function strip_prefix( $do_parse ) {
		if ( '' === $this->prefix || null !== $this->original_uri ) {
			return $do_parse;
		}
		$this->original_uri     = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = $this->strip_from_uri( $this->original_uri, $this->prefix );
		if ( ! empty( $_SERVER['PATH_INFO'] ) ) {
			$_SERVER['PATH_INFO'] = $this->strip_from_uri( $_SERVER['PATH_INFO'], $this->prefix ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return $do_parse;
	}

	/**
	 * Remove "/nl" from the start of a URI (after the home path).
	 *
	 * @param string $uri  URI.
	 * @param string $code Language code.
	 * @return string
	 */
	public function strip_from_uri( $uri, $code ) {
		$home = $this->home_path();
		$re   = '#^' . preg_quote( $home . $code, '#' ) . '(?=/|\?|$)/?#i';
		return (string) preg_replace( $re, $home, $uri, 1 );
	}

	/**
	 * Put the original URI back once WordPress has parsed the request.
	 *
	 * @param \WP $wp WordPress environment.
	 */
	public function restore_uri( $wp ) {
		if ( null === $this->original_uri ) {
			return;
		}
		$_SERVER['REQUEST_URI'] = $this->original_uri;
		$wp->request            = trim( $this->prefix . '/' . $wp->request, '/' );
	}

	/**
	 * Resolve slugs inside the request language, so translations may share a
	 * slug (/reiki/ and /nl/reiki/).
	 *
	 * @param array $qv Query vars.
	 * @return array
	 */
	public function resolve_request( $qv ) {
		if ( 'front' !== $this->context ) {
			return $qv;
		}
		if ( ! empty( $qv['pagename'] ) && Translations::is_translatable_type( 'page' ) ) {
			$id = $this->find_in_language_or_default( $qv['pagename'], 'page' );
			if ( $id ) {
				unset( $qv['pagename'] );
				$qv['page_id'] = $id;
			}
			return $qv;
		}
		if ( ! empty( $qv['name'] ) ) {
			$type = ! empty( $qv['post_type'] ) && is_string( $qv['post_type'] ) ? $qv['post_type'] : 'post';
			if ( Translations::is_translatable_type( $type ) ) {
				$finder = is_post_type_hierarchical( $type ) && ! empty( $qv[ $type ] ) ? $qv[ $type ] : $qv['name'];
				$id     = $this->find_in_language_or_default( $finder, $type );
				if ( $id ) {
					unset( $qv['name'] );
					if ( 'post' !== $type ) {
						unset( $qv[ $type ] );
						$qv['post_type'] = $type;
					}
					$qv['p'] = $id;
				}
			}
		}
		return $qv;
	}

	/**
	 * Find a post in the request language, falling back to the default
	 * language (enforce_language() then redirects to the right URL).
	 *
	 * @param string $path      Slug path.
	 * @param string $post_type Post type.
	 * @return int
	 */
	private function find_in_language_or_default( $path, $post_type ) {
		$id = $this->find_by_path( $path, $post_type, $this->lang );
		if ( ! $id && $this->lang !== Languages::default_code() ) {
			$id = $this->find_by_path( $path, $post_type, Languages::default_code() );
		}
		return $id;
	}

	/**
	 * Find a post by (hierarchical) path within one language.
	 *
	 * @param string $path      Slug path, e.g. "parent/child".
	 * @param string $post_type Post type.
	 * @param string $lang      Language code.
	 * @return int 0 when not found (WordPress then resolves it as usual).
	 */
	public function find_by_path( $path, $post_type, $lang ) {
		global $wpdb;
		// Same normalisation as get_page_by_path().
		$path  = str_replace( '%2F', '/', rawurlencode( urldecode( (string) $path ) ) );
		$path  = str_replace( '%20', ' ', $path );
		$parts = array_values( array_filter( array_map( 'sanitize_title_for_query', explode( '/', $path ) ), 'strlen' ) );
		if ( ! $parts ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $parts ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_parent, post_type, post_status FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_status NOT IN ('trash','auto-draft','inherit')", $parts ), OBJECT_K );
		if ( ! $rows ) {
			return 0;
		}
		$leaf   = end( $parts );
		$wanted = implode( '/', $parts );
		foreach ( $rows as $row ) {
			if ( $row->post_type !== $post_type || $row->post_name !== $leaf ) {
				continue;
			}
			// Rebuild the full path from the parent chain.
			$chain  = array( $row->post_name );
			$parent = (int) $row->post_parent;
			while ( $parent ) {
				if ( ! isset( $rows[ $parent ] ) ) {
					$chain = array();
					break;
				}
				array_unshift( $chain, $rows[ $parent ]->post_name );
				$parent = (int) $rows[ $parent ]->post_parent;
			}
			if ( implode( '/', $chain ) !== $wanted || Translations::get_lang( (int) $row->ID ) !== $lang ) {
				continue;
			}
			// A draft translation must not hide the published page for visitors.
			if ( 'publish' === $row->post_status || current_user_can( 'read_post', (int) $row->ID ) ) {
				return (int) $row->ID;
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------
	 * Front page and posts page per language
	 * ------------------------------------------------------------------ */

	/**
	 * Unfiltered page_on_front.
	 *
	 * @return int
	 */
	public function raw_front_page() {
		$this->bypass = true;
		$id           = (int) get_option( 'page_on_front' );
		$this->bypass = false;
		return 'page' === get_option( 'show_on_front' ) ? $id : 0;
	}

	/**
	 * Unfiltered page_for_posts.
	 *
	 * @return int
	 */
	public function raw_posts_page() {
		$this->bypass = true;
		$id           = (int) get_option( 'page_for_posts' );
		$this->bypass = false;
		return 'page' === get_option( 'show_on_front' ) ? $id : 0;
	}

	/**
	 * Translated static front page.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function filter_page_on_front( $value ) {
		return $this->translated_option_page( $value );
	}

	/**
	 * Translated posts page.
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public function filter_page_for_posts( $value ) {
		return $this->translated_option_page( $value );
	}

	/**
	 * Swap a page id option for its translation in the request language.
	 *
	 * @param mixed $value Page id.
	 * @return mixed
	 */
	private function translated_option_page( $value ) {
		if ( $this->bypass || ! $value || ! in_array( $this->context, array( 'front', 'ajax-front', 'rest' ), true ) || $this->lang === Languages::default_code() ) {
			return $value;
		}
		$translation = Translations::get_translation( (int) $value, $this->lang, ! current_user_can( 'edit_pages' ) );
		return $translation ? $translation : $value;
	}

	/* ------------------------------------------------------------------
	 * Redirects
	 * ------------------------------------------------------------------ */

	/**
	 * Previews (WordPress or Elementor) render in the previewed post's language.
	 */
	public function follow_previewed_post() {
		if ( ! $this->is_preview_request() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && Translations::is_translatable_type( $post->post_type ) ) {
			$code = Translations::get_lang( $post );
			if ( $code !== $this->lang && Languages::exists( $code ) ) {
				$this->lang = $code;
				$lang       = Languages::get( $code );
				switch_to_locale( $lang['locale'] );
			}
		}
	}

	/**
	 * Is this a preview (WordPress, Elementor or Customizer)?
	 *
	 * @return bool
	 */
	private function is_preview_request() {
		// phpcs:ignore WordPress.Security.NonceVerification
		return is_preview() || isset( $_GET['elementor-preview'] ) || isset( $_GET['preview_id'] ) || is_customize_preview();
	}

	/**
	 * A post shown under the wrong language prefix is redirected: to its
	 * translation in the requested language when there is one (301), else to
	 * the post in its own language (302, a translation may be added later).
	 */
	public function enforce_language() {
		if ( 'front' !== $this->context || $this->is_preview_request() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! Translations::is_translatable_type( $post->post_type ) ) {
			return;
		}
		$post_lang = Translations::get_lang( $post );
		if ( $post_lang === $this->lang ) {
			return;
		}
		if ( ! Languages::exists( $post_lang ) ) {
			// Content of a removed language is not shown.
			$translation = Translations::get_translation( $post, $this->lang, true );
			if ( $translation ) {
				wp_safe_redirect( get_permalink( $translation ), 302, 'LLM Multilingual' );
				exit;
			}
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		// An unprefixed URL names no language explicitly: send the post to its
		// own URL. A prefixed one does: prefer the translation in that language.
		$translation = '' !== $this->prefix ? Translations::get_translation( $post, $this->lang, true ) : 0;
		$target      = $translation ? get_permalink( $translation ) : get_permalink( $post );
		$status      = ( $translation || '' === $this->prefix ) ? 301 : 302;
		if ( $target && apply_filters( 'wpllmml_enforce_language_redirect', true, $post, $target ) ) {
			wp_safe_redirect( $target, $status, 'LLM Multilingual' );
			exit;
		}
	}

	/**
	 * WordPress builds canonical redirects from the stripped request; add the
	 * prefix back and avoid redirecting a URL to itself.
	 *
	 * @param string|false $redirect_url  Target.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public function filter_redirect_canonical( $redirect_url, $requested_url ) {
		if ( ! $redirect_url || $this->lang === Languages::default_code() ) {
			return $redirect_url;
		}
		$path = (string) wp_parse_url( $redirect_url, PHP_URL_PATH );
		if ( $this->prefix_of_path( $path ? $path : '/' ) === '' ) {
			$redirect_url = $this->add_prefix( $redirect_url, $this->lang );
		}
		return $redirect_url === $requested_url ? false : $redirect_url;
	}

	/**
	 * From here on we are rendering output.
	 */
	public function start_rendering() {
		$this->rendering = true;
	}

	/**
	 * Is a front-end page being rendered?
	 *
	 * @return bool
	 */
	public function is_rendering() {
		return $this->rendering;
	}

	/**
	 * While rendering a non-default language, links to the home page point to
	 * that language's home page (logo, search form, breadcrumbs).
	 *
	 * @param string      $url    URL.
	 * @param string      $path   Path argument.
	 * @param string|null $scheme Scheme.
	 * @return string
	 */
	public function filter_home_url( $url, $path, $scheme ) {
		if ( ! $this->rendering || 'rest' === $scheme || $this->lang === Languages::default_code() ) {
			return $url;
		}
		if ( '' !== $path && '/' !== $path ) {
			return $url;
		}
		return $this->add_prefix( $url, $this->lang );
	}

	/* ------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * Insert a language prefix into a URL of this site. Idempotent.
	 *
	 * @param string $url  URL (absolute or root-relative).
	 * @param string $code Language code.
	 * @return string
	 */
	public function add_prefix( $url, $code ) {
		if ( $code === Languages::default_code() || ! Languages::exists( $code ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return $url;
		}
		$home      = $this->home_path();          // "/" or "/sub/".
		$home_base = rtrim( $home, '/' );         // "" or "/sub".
		$path      = isset( $parts['path'] ) ? $parts['path'] : '';
		$check     = trailingslashit( '' === $path ? $home_base : $path );
		if ( 0 !== strpos( $check, $home ) ) {
			return $url; // Not a URL inside this site.
		}
		if ( '' !== $this->prefix_of_path( $check ) ) {
			return $url; // Already prefixed.
		}
		// What follows the home path: "" (no path given), "/" or "/slug/".
		$rest     = '' === $path ? '' : (string) substr( $path, strlen( $home_base ) );
		$new_path = $home_base . '/' . $code . $rest;

		$origin = '';
		if ( isset( $parts['host'] ) ) {
			$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}
		return $origin . $new_path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Home page URL of a language (unfiltered).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function language_home( $code ) {
		$home = trailingslashit( set_url_scheme( get_option( 'home' ) ) );
		return $code === Languages::default_code() ? $home : $home . $code . '/';
	}

	/**
	 * The requested path without the language prefix (for archive alternates).
	 *
	 * @return string e.g. "/category/news/?s=x"
	 */
	public function unprefixed_request_uri() {
		$uri = null !== $this->original_uri ? $this->original_uri : ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' !== $this->prefix ? $this->strip_from_uri( $uri, $this->prefix ) : $uri;
	}

	/* ------------------------------------------------------------------
	 * Slugs
	 * ------------------------------------------------------------------ */

	/**
	 * Allow translations to reuse a slug that is taken in another language.
	 *
	 * @param string $slug          Unique slug WordPress chose.
	 * @param int    $post_id       Post id.
	 * @param string $post_status   Status.
	 * @param string $post_type     Type.
	 * @param int    $post_parent   Parent.
	 * @param string $original_slug Desired slug.
	 * @return string
	 */
	public function allow_same_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		global $wpdb, $wp_rewrite;
		if ( $slug === $original_slug || ! Translations::is_translatable_type( $post_type ) ) {
			return $slug;
		}
		// Slugs WordPress rejects for reasons other than duplicates.
		if ( ( $wp_rewrite && in_array( $original_slug, (array) $wp_rewrite->feeds, true ) ) || 'embed' === $original_slug || preg_match( '@^(page)?\d+$@', $original_slug ) ) {
			return $slug;
		}
		$lang = '' !== Duplicator::$creating_lang ? Duplicator::$creating_lang : Translations::get_lang( $post_id );
		$hier = is_post_type_hierarchical( $post_type );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$hier
				? $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN (%s, 'attachment') AND ID != %d AND post_parent = %d AND post_status NOT IN ('trash','auto-draft','inherit')", $original_slug, $post_type, $post_id, $post_parent )
				: $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d AND post_status NOT IN ('trash','auto-draft','inherit')", $original_slug, $post_type, $post_id )
		);
		foreach ( $ids as $other ) {
			if ( 'attachment' === get_post_type( (int) $other ) || Translations::get_lang( (int) $other ) === $lang ) {
				return $slug; // Real conflict within the same language.
			}
		}
		return $original_slug;
	}
}
