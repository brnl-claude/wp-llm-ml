<?php
/**
 * Translations of texts that are not posts: site title, tagline, menu
 * labels, category names, widget texts, the GeneratePress copyright line.
 *
 * Theme and plugin interface texts ("Read more", form messages...) come from
 * the regular WordPress language packs, which load automatically because the
 * locale follows the visitor's language.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * String translations.
 */
class Strings {

	const OPTION = 'wpllmml_strings';

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Cached option.
	 *
	 * @var array|null
	 */
	private static $table = null;

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
		if ( ! Languages::is_multilingual() || ! in_array( $this->router->context, array( 'front', 'ajax-front', 'rest' ), true ) ) {
			return;
		}
		if ( $this->router->lang === Languages::default_code() ) {
			return;
		}
		$t = array( $this, 'filter' );
		add_filter( 'option_blogname', $t, 20 );
		add_filter( 'option_blogdescription', $t, 20 );
		add_filter( 'theme_mod_generate_copyright', $t, 20 );
		add_filter( 'widget_title', $t, 20 );
		add_filter( 'widget_text', $t, 5 );
		add_filter( 'widget_block_content', $t, 5 );
		add_filter( 'get_term', array( $this, 'filter_term' ), 20 );
	}

	/**
	 * The whole table: md5(original) => [s => original, c => context, t => [code => text]].
	 *
	 * @return array
	 */
	public static function table() {
		if ( null === self::$table ) {
			$table       = get_option( self::OPTION, array() );
			self::$table = is_array( $table ) ? $table : array();
		}
		return self::$table;
	}

	/**
	 * Save the whole table.
	 *
	 * @param array $table Table.
	 */
	public static function save( array $table ) {
		update_option( self::OPTION, $table, true );
		self::$table = $table;
	}

	/**
	 * Translate a string into a language.
	 *
	 * @param string $original Original text.
	 * @param string $lang     Language code.
	 * @return string
	 */
	public static function translate( $original, $lang ) {
		if ( ! is_string( $original ) || '' === trim( $original ) || Languages::default_code() === $lang ) {
			return $original;
		}
		$table = self::table();
		$key   = md5( $original );
		if ( isset( $table[ $key ]['t'][ $lang ] ) && '' !== trim( $table[ $key ]['t'][ $lang ] ) ) {
			return $table[ $key ]['t'][ $lang ];
		}
		return $original;
	}

	/**
	 * Filter callback for the current language.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function filter( $value ) {
		return is_string( $value ) ? self::translate( $value, $this->router->lang ) : $value;
	}

	/**
	 * Term names and descriptions.
	 *
	 * @param \WP_Term|mixed $term Term.
	 * @return \WP_Term|mixed
	 */
	public function filter_term( $term ) {
		if ( ! $term instanceof \WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return $term;
		}
		$name        = self::translate( $term->name, $this->router->lang );
		$description = self::translate( $term->description, $this->router->lang );
		if ( $name === $term->name && $description === $term->description ) {
			return $term;
		}
		$term              = clone $term;
		$term->name        = $name;
		$term->description = $description;
		return $term;
	}

	/**
	 * Collect translatable strings from the site (shown on the Strings screen).
	 *
	 * @return array list of [original, context]
	 */
	public static function discover() {
		$found = array();
		$add   = static function ( $text, $context ) use ( &$found ) {
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$found[ md5( $text ) ] = array( $text, $context );
			}
		};

		$add( get_option( 'blogname' ), __( 'Site title', 'wp-llm-ml' ) );
		$add( get_option( 'blogdescription' ), __( 'Tagline', 'wp-llm-ml' ) );
		$add( get_theme_mod( 'generate_copyright' ), __( 'Copyright (GeneratePress)', 'wp-llm-ml' ) );

		// Menu labels. Items that point to a translated page use that page's
		// title automatically, so only custom labels and labels of pages
		// without any translation are listed.
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$linked_translated = 'post_type' === $item->type && count( Translations::get_translations( (int) $item->object_id ) ) > 1;
				if ( '' !== $item->post_title ) {
					/* translators: %s: menu name */
					$add( $item->post_title, sprintf( __( 'Menu: %s', 'wp-llm-ml' ), $menu->name ) );
				} elseif ( ! $linked_translated && ! empty( $item->title ) ) {
					/* translators: %s: menu name */
					$add( $item->title, sprintf( __( 'Menu: %s', 'wp-llm-ml' ), $menu->name ) );
				}
				if ( '' !== $item->attr_title ) {
					$add( $item->attr_title, sprintf( __( 'Menu: %s', 'wp-llm-ml' ), $menu->name ) );
				}
			}
		}

		// Category and tag names.
		$terms = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( in_array( $term->taxonomy, array( 'nav_menu', 'post_format' ), true ) ) {
					continue;
				}
				$tax = get_taxonomy( $term->taxonomy );
				$add( $term->name, $tax ? $tax->labels->singular_name : $term->taxonomy );
				$add( $term->description, $tax ? $tax->labels->singular_name : $term->taxonomy );
			}
		}

		// Widgets in active sidebars.
		foreach ( self::active_widgets() as $widget ) {
			$label = $widget['label'];
			$inst  = $widget['instance'];
			if ( ! empty( $inst['title'] ) ) {
				$add( $inst['title'], $label );
			}
			if ( ! empty( $inst['text'] ) ) {
				$add( $inst['text'], $label );
			}
			if ( ! empty( $inst['content'] ) ) {
				// Block widget markup, translated before do_blocks() runs.
				$add( $inst['content'], $label );
			}
		}

		return array_values( (array) apply_filters( 'wpllmml_discovered_strings', $found ) );
	}

	/**
	 * Widgets placed in sidebars.
	 *
	 * @return array list of [id, sidebar, label, instance]
	 */
	public static function active_widgets() {
		global $wp_registered_sidebars;
		$out      = array();
		$sidebars = wp_get_sidebars_widgets();
		foreach ( (array) $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}
			$sidebar_name = isset( $wp_registered_sidebars[ $sidebar_id ]['name'] ) ? $wp_registered_sidebars[ $sidebar_id ]['name'] : $sidebar_id;
			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
					continue;
				}
				$all      = get_option( 'widget_' . $m[1], array() );
				$instance = isset( $all[ (int) $m[2] ] ) && is_array( $all[ (int) $m[2] ] ) ? $all[ (int) $m[2] ] : array();
				$out[]    = array(
					'id'       => $widget_id,
					'sidebar'  => $sidebar_id,
					'label'    => $sidebar_name . ' › ' . $widget_id,
					'instance' => $instance,
				);
			}
		}
		return $out;
	}
}
