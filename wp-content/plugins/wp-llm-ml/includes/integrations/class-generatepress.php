<?php
/**
 * GeneratePress (and GP Premium) integration: logo and site title link to
 * the language's home page, the copyright line is translatable and its links
 * follow the visitor's language. Menus, widgets and the switcher need nothing
 * theme-specific because GeneratePress uses the standard WordPress APIs.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Languages;
use WPLLMML\Links;
use WPLLMML\Router;

defined( 'ABSPATH' ) || exit;

/**
 * GeneratePress integration.
 */
class Generatepress {

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
	 * Constructor.
	 *
	 * @param Router $router Router.
	 * @param Links  $links  Links.
	 */
	public function __construct( Router $router, Links $links ) {
		$this->router = $router;
		$this->links  = $links;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( ! Languages::is_multilingual() ) {
			return;
		}
		add_filter( 'generate_logo_href', array( $this, 'home' ) );
		add_filter( 'generate_site_title_href', array( $this, 'home' ) );
		add_filter( 'generate_copyright', array( $this->links, 'localize_html' ), 100 );
	}

	/**
	 * Home URL of the current language.
	 *
	 * @return string
	 */
	public function home() {
		return $this->router->language_home( $this->router->lang );
	}
}
