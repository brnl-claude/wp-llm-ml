<?php
/**
 * One menu for all languages: items pointing to pages/posts follow the
 * translation, labels are translated, and the switcher can be appended to a
 * theme menu location as regular menu items (so the theme styles it).
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Menu localisation.
 */
class Menus {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Switcher.
	 *
	 * @var Switcher
	 */
	private $switcher;

	/**
	 * Constructor.
	 *
	 * @param Router   $router   Router.
	 * @param Links    $links    Links.
	 * @param Switcher $switcher Switcher.
	 */
	public function __construct( Router $router, Links $links, Switcher $switcher ) {
		$this->router   = $router;
		$this->links    = $links;
		$this->switcher = $switcher;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( ! Languages::is_multilingual() || ! in_array( $this->router->context, array( 'front', 'ajax-front' ), true ) ) {
			return;
		}
		add_filter( 'wp_get_nav_menu_items', array( $this, 'localize_items' ), 20, 3 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'append_switcher' ), 20, 2 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'switcher_link_attributes' ), 10, 2 );
	}

	/**
	 * Language attributes on switcher menu links.
	 *
	 * @param array  $atts Attributes.
	 * @param object $item Menu item.
	 * @return array
	 */
	public function switcher_link_attributes( $atts, $item ) {
		if ( empty( $item->wpllmml_lang ) ) {
			return $atts;
		}
		$atts['hreflang']          = Languages::hreflang( $item->wpllmml_lang );
		$atts['lang']              = Languages::hreflang( $item->wpllmml_lang );
		$atts['data-wpllmml-lang'] = $item->wpllmml_lang;
		if ( $item->wpllmml_lang === $this->router->lang ) {
			$atts['aria-current'] = 'true';
		}
		return $atts;
	}

	/**
	 * Point items at translations and translate labels. Runs before WordPress
	 * computes "current-menu-item" classes, so those stay correct.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function localize_items( $items ) {
		$lang = $this->router->lang;
		if ( Languages::default_code() === $lang || ! is_array( $items ) ) {
			return $items;
		}
		$hide   = 'hide' === Settings::get( 'menu_untranslated' );
		$hidden = array();
		foreach ( $items as $index => $item ) {
			if ( ! is_object( $item ) || ! isset( $item->type ) ) {
				continue;
			}
			if ( isset( $hidden[ (int) $item->menu_item_parent ] ) ) {
				$hidden[ (int) $item->db_id ] = true;
				unset( $items[ $index ] );
				continue;
			}
			$custom_label = isset( $item->post_title ) && '' !== $item->post_title;
			if ( 'post_type' === $item->type && Translations::is_translatable_type( $item->object ) ) {
				$translation = Translations::get_translation( (int) $item->object_id, $lang, true );
				if ( $translation && (int) $translation !== (int) $item->object_id ) {
					$item->object_id = (string) $translation;
					$item->url       = get_permalink( $translation );
					if ( ! $custom_label ) {
						$item->title = get_the_title( $translation );
					}
				} elseif ( ! $translation && $hide && Translations::get_lang( (int) $item->object_id ) !== $lang ) {
					$hidden[ (int) $item->db_id ] = true;
					unset( $items[ $index ] );
					continue;
				}
			} elseif ( 'custom' === $item->type ) {
				$item->url = $this->links->localize_url( $item->url, $lang );
			}
			if ( $custom_label ) {
				$item->title = Strings::translate( $item->post_title, $lang );
			} elseif ( isset( $item->title ) ) {
				// Title of an untranslated page: a string translation may exist.
				$item->title = Strings::translate( $item->title, $lang );
			}
			if ( ! empty( $item->attr_title ) ) {
				$item->attr_title = Strings::translate( $item->attr_title, $lang );
			}
		}
		return array_values( $items );
	}

	/**
	 * Add the language switcher to the configured menu location.
	 *
	 * @param array     $items Sorted menu items.
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 * @return array
	 */
	public function append_switcher( $items, $args ) {
		$location = Settings::get( 'menu_location' );
		if ( '' === $location || empty( $args->theme_location ) || $args->theme_location !== $location ) {
			return $items;
		}
		$links = $this->switcher->links();
		if ( count( $links ) < 2 ) {
			return $items;
		}
		$order   = 1000;
		$base_id = 990000; // Fake ids outside the range of real menu items.
		$label   = Settings::get( 'switcher_label', 'name' );
		$current = null;
		foreach ( $links as $link ) {
			if ( $link['current'] ) {
				$current = $link;
			}
		}
		$make = function ( $id, $parent, $title, $url, $classes, $link = null ) use ( &$order ) {
			$item                   = new \stdClass();
			$item->ID               = $id;
			$item->db_id            = $id;
			$item->menu_item_parent = (string) $parent;
			$item->object_id        = (string) $id;
			$item->object           = 'custom';
			$item->type             = 'custom';
			$item->type_label       = 'Language';
			$item->title            = $title;
			$item->url              = $url;
			$item->target           = '';
			$item->attr_title       = '';
			$item->description      = '';
			$item->classes          = $classes;
			$item->xfn              = $link ? 'alternate' : '';
			$item->current          = false;
			$item->menu_order       = ++$order;
			$item->post_parent      = 0;
			$item->post_type        = 'nav_menu_item';
			$item->post_status      = 'publish';
			$item->post_title       = $title;
			$item->wpllmml_lang     = $link ? $link['code'] : '';
			return new \WP_Post( $item );
		};

		if ( 'inline' === Settings::get( 'menu_style' ) || ! $current ) {
			foreach ( $links as $i => $link ) {
				$classes = array( 'menu-item', 'menu-item-type-custom', 'wpllmml-menu-item', 'wpllmml-lang-' . $link['code'] );
				if ( $link['current'] ) {
					$classes[] = 'current-lang';
				}
				$items[] = $make( $base_id + $i + 1, 0, $this->switcher->label( $link, $label ), $link['url'], $classes, $link );
			}
			return $items;
		}

		$parent_id = $base_id;
		$items[]   = $make(
			$parent_id,
			0,
			$this->switcher->label( $current, $label ),
			$current['url'],
			array( 'menu-item', 'menu-item-type-custom', 'menu-item-has-children', 'wpllmml-menu-item', 'wpllmml-menu-parent', 'current-lang' ),
			$current
		);
		$i = 0;
		foreach ( $links as $link ) {
			if ( $link['current'] ) {
				continue;
			}
			$items[] = $make( $parent_id + ( ++$i ), $parent_id, $this->switcher->label( $link, $label ), $link['url'], array( 'menu-item', 'menu-item-type-custom', 'wpllmml-menu-item', 'wpllmml-lang-' . $link['code'] ), $link );
		}
		return $items;
	}
}
