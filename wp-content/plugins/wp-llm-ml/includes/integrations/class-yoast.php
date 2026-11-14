<?php
/**
 * Yoast SEO integration.
 *
 * Most of it works without code: each translation has its own Yoast title,
 * description and canonical (its own permalink), og:locale and schema
 * inLanguage follow the locale, and translations appear in the XML sitemaps
 * and llms.txt with their /xx/ URLs. This class adds hreflang alternates to
 * Yoast's XML sitemaps.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Languages;
use WPLLMML\Links;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO integration.
 */
class Yoast {

	/**
	 * Links.
	 *
	 * @var Links
	 */
	private $links;

	/**
	 * Constructor.
	 *
	 * @param Links $links Links.
	 */
	public function __construct( Links $links ) {
		$this->links = $links;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_filter( 'wpseo_sitemap_urlset', array( $this, 'urlset_namespace' ) );
		add_filter( 'wpseo_sitemap_url', array( $this, 'sitemap_alternates' ), 10, 2 );
	}

	/**
	 * Declare the xhtml namespace used by <xhtml:link>.
	 *
	 * @param string $urlset Opening <urlset> tag.
	 * @return string
	 */
	public function urlset_namespace( $urlset ) {
		if ( false === strpos( $urlset, 'xmlns:xhtml' ) ) {
			$urlset = str_replace( '<urlset ', '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml" ', $urlset );
		}
		return $urlset;
	}

	/**
	 * Add <xhtml:link rel="alternate" hreflang> to a sitemap entry.
	 *
	 * @param string $output XML of the <url> entry.
	 * @param array  $url    Entry data (loc, mod, images...).
	 * @return string
	 */
	public function sitemap_alternates( $output, $url ) {
		if ( empty( $url['loc'] ) ) {
			return $output;
		}
		$post_id = $this->links->post_id_for_url( $url['loc'] );
		if ( ! $post_id ) {
			return $output;
		}
		$links = '';
		$count = 0;
		foreach ( Translations::get_translations( $post_id ) as $code => $id ) {
			if ( ! Translations::is_public( $id ) ) {
				continue;
			}
			++$count;
			$links .= "\t\t" . '<xhtml:link rel="alternate" hreflang="' . esc_attr( Languages::hreflang( $code ) ) . '" href="' . esc_url( get_permalink( $id ) ) . '" />' . "\n";
		}
		if ( $count < 2 ) {
			return $output;
		}
		return str_replace( '</url>', $links . "\t</url>", $output );
	}
}
