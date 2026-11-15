<?php
/**
 * Search engine and AI crawler signals: hreflang alternates (incl.
 * x-default), og:locale alternates, and the document language (which follows
 * the locale automatically: <html lang>, Yoast schema inLanguage, og:locale).
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * SEO output.
 */
class Seo {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

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
	 * @param Switcher $switcher Switcher.
	 */
	public function __construct( Router $router, Switcher $switcher ) {
		$this->router   = $router;
		$this->switcher = $switcher;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'print_alternates' ), 2 );
	}

	/**
	 * Alternates fit for hreflang (published, indexable pages only).
	 *
	 * @return array code => url
	 */
	public function hreflang_alternates() {
		if ( is_404() || is_search() || is_feed() || is_preview() ) {
			return array();
		}
		$out = array();
		foreach ( $this->switcher->alternates() as $code => $alt ) {
			if ( $alt[1] ) {
				$out[ $code ] = $alt[0];
			}
		}
		// hreflang needs the page itself plus at least one other language.
		return count( $out ) > 1 ? $out : array();
	}

	/**
	 * Print <link rel="alternate" hreflang> and og:locale:alternate tags.
	 */
	public function print_alternates() {
		$alternates = $this->hreflang_alternates();
		if ( ! $alternates ) {
			return;
		}
		$default = Languages::default_code();
		echo "\n<!-- LLM Multilingual -->\n";
		foreach ( $alternates as $code => $url ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( Languages::hreflang( $code ) ), esc_url( $url ) );
		}
		if ( isset( $alternates[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $alternates[ $default ] ) );
		}
		foreach ( $alternates as $code => $url ) {
			if ( $code === $this->router->lang ) {
				continue;
			}
			$lang = Languages::get( $code );
			$og   = str_replace( '-', '_', $lang['locale'] );
			if ( false === strpos( $og, '_' ) ) {
				$og = strtolower( $og ) . '_' . strtoupper( $og );
			}
			printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $og ) );
		}
	}
}
