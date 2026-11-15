<?php
/**
 * Language switcher: alternates of the current page, HTML rendering,
 * [wpllmml_switcher] shortcode and classic widget.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Language switcher.
 */
class Switcher {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Cached alternates for this request.
	 *
	 * @var array|null
	 */
	private $alternates = null;

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
		add_shortcode( 'wpllmml_switcher', array( $this, 'shortcode' ) );
		add_action(
			'widgets_init',
			static function () {
				register_widget( Switcher_Widget::class );
			}
		);
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Front-end stylesheet (tiny, no JavaScript needed).
	 */
	public function enqueue() {
		if ( Languages::is_multilingual() ) {
			wp_enqueue_style( 'wpllmml-switcher', WPLLMML_URL . 'assets/css/switcher.css', array(), WPLLMML_VERSION );
		}
	}

	/**
	 * URLs of the current page in each language where it exists.
	 *
	 * @return array code => [url, seo] — seo = true when fit for hreflang.
	 */
	public function alternates() {
		if ( null !== $this->alternates ) {
			return $this->alternates;
		}
		$out = array();
		if ( is_front_page() ) {
			$front = $this->router->raw_front_page();
			if ( $front ) {
				foreach ( Translations::get_translations( $front ) as $code => $id ) {
					if ( Translations::is_public( $id ) || $code === $this->router->lang ) {
						$out[ $code ] = array( $this->router->language_home( $code ), true );
					}
				}
			} else {
				foreach ( Languages::codes() as $code ) {
					$out[ $code ] = array( $this->router->language_home( $code ), true );
				}
			}
		} elseif ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && Translations::is_translatable_type( $post->post_type ) ) {
				foreach ( Translations::get_translations( $post ) as $code => $id ) {
					if ( (int) $id === (int) $post->ID || Translations::is_public( $id ) ) {
						$out[ $code ] = array( get_permalink( $id ), Translations::is_public( $id ) );
					}
				}
			}
		} elseif ( is_home() ) {
			$posts_page   = $this->router->raw_posts_page();
			$translations = $posts_page ? Translations::get_translations( $posts_page ) : array();
			foreach ( Languages::codes() as $code ) {
				$url          = isset( $translations[ $code ] ) && Translations::is_public( $translations[ $code ] ) ? get_permalink( $translations[ $code ] ) : $this->swap_language( $code );
				$out[ $code ] = array( $url, true );
			}
		} elseif ( is_archive() || is_search() ) {
			// Archives exist in every language; not used for hreflang.
			foreach ( Languages::codes() as $code ) {
				$out[ $code ] = array( $this->swap_language( $code ), false );
			}
		}
		$this->alternates = (array) apply_filters( 'wpllmml_alternates', $out );
		return $this->alternates;
	}

	/**
	 * The current URL with another language prefix.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private function swap_language( $code ) {
		$home   = wp_parse_url( set_url_scheme( get_option( 'home' ) ) );
		$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $this->router->add_prefix( $origin . $this->router->unprefixed_request_uri(), $code );
	}

	/**
	 * Switcher entries for the current page.
	 *
	 * @return array list of [code, name, english, url, current, available]
	 */
	public function links() {
		$alternates = $this->alternates();
		$hide       = 'hide' === Settings::get( 'untranslated' );
		$out        = array();
		foreach ( Languages::all() as $code => $lang ) {
			$current   = $code === $this->router->lang;
			$available = isset( $alternates[ $code ] );
			if ( ! $available && $hide && ! $current ) {
				continue;
			}
			$out[] = array(
				'code'      => $code,
				'name'      => $lang['name'],
				'english'   => $lang['english'],
				'url'       => $available ? $alternates[ $code ][0] : $this->router->language_home( $code ),
				'current'   => $current,
				'available' => $available,
			);
		}
		return (array) apply_filters( 'wpllmml_switcher_links', $out );
	}

	/**
	 * Visible label of an entry.
	 *
	 * @param array  $link  Entry.
	 * @param string $label name | code | both.
	 * @return string
	 */
	public function label( $link, $label ) {
		switch ( $label ) {
			case 'code':
				return strtoupper( $link['code'] );
			case 'both':
				return strtoupper( $link['code'] ) . ' · ' . $link['name'];
			default:
				return $link['name'];
		}
	}

	/**
	 * Render the switcher.
	 *
	 * @param array $args style (list|inline|dropdown), label (name|code|both), show_current (bool), class.
	 * @return string
	 */
	public function render( $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'style'        => 'inline',
				'label'        => Settings::get( 'switcher_label', 'name' ),
				'show_current' => true,
				'class'        => '',
			)
		);
		$links = $this->links();
		if ( count( $links ) < 2 ) {
			return '';
		}
		$style = in_array( $args['style'], array( 'list', 'inline', 'dropdown' ), true ) ? $args['style'] : 'inline';
		$aria  = esc_attr__( 'Language', 'wp-llm-ml' );
		$class = trim( 'wpllmml-switcher wpllmml-switcher--' . $style . ' ' . $args['class'] );
		$item  = function ( $link ) use ( $args ) {
			$classes = array( 'wpllmml-switcher__item' );
			if ( $link['current'] ) {
				$classes[] = 'is-current';
			}
			if ( ! $link['available'] ) {
				$classes[] = 'is-untranslated';
			}
			$tag = Languages::hreflang( $link['code'] );
			return sprintf(
				'<li class="%1$s"><a href="%2$s" hreflang="%3$s" lang="%3$s" data-wpllmml-lang="%4$s"%5$s>%6$s</a></li>',
				esc_attr( implode( ' ', $classes ) ),
				esc_url( $link['url'] ),
				esc_attr( $tag ),
				esc_attr( $link['code'] ),
				$link['current'] ? ' aria-current="true"' : '',
				esc_html( $this->label( $link, $args['label'] ) )
			);
		};

		if ( 'dropdown' === $style ) {
			$current = null;
			$others  = '';
			foreach ( $links as $link ) {
				if ( $link['current'] ) {
					$current = $link;
				} else {
					$others .= $item( $link );
				}
			}
			if ( ! $current ) {
				$current = $links[0];
			}
			return sprintf(
				'<div class="%1$s"><details class="wpllmml-switcher__details"><summary class="wpllmml-switcher__toggle" aria-label="%2$s: %3$s">%4$s</summary><ul class="wpllmml-switcher__list">%5$s</ul></details></div>',
				esc_attr( $class ),
				$aria,
				esc_attr( $current['name'] ),
				esc_html( $this->label( $current, $args['label'] ) ),
				$others
			);
		}

		$html = '';
		foreach ( $links as $link ) {
			if ( $link['current'] && ! $args['show_current'] ) {
				continue;
			}
			$html .= $item( $link );
		}
		return sprintf( '<nav class="%1$s" aria-label="%2$s"><ul class="wpllmml-switcher__list">%3$s</ul></nav>', esc_attr( $class ), $aria, $html );
	}

	/**
	 * [wpllmml_switcher style="inline|list|dropdown" label="name|code|both" show_current="1"]
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'        => 'inline',
				'label'        => Settings::get( 'switcher_label', 'name' ),
				'show_current' => '1',
				'class'        => '',
			),
			$atts,
			'wpllmml_switcher'
		);
		$atts['show_current'] = ! in_array( strtolower( (string) $atts['show_current'] ), array( '0', 'false', 'no' ), true );
		return $this->render( $atts );
	}
}
