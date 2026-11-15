<?php
/**
 * Per-language widget visibility: show a widget (for example a MailPoet
 * form or a footer text) only in one language.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Widget visibility.
 */
class Widgets {

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
		if ( Languages::is_multilingual() && in_array( $this->router->context, array( 'front', 'ajax-front' ), true ) ) {
			add_filter( 'sidebars_widgets', array( $this, 'filter_sidebars' ) );
		}
	}

	/**
	 * Remove widgets assigned to another language.
	 *
	 * @param array $sidebars Sidebar => widget ids.
	 * @return array
	 */
	public function filter_sidebars( $sidebars ) {
		$langs = (array) Settings::get( 'widget_langs', array() );
		if ( ! $langs || ! is_array( $sidebars ) ) {
			return $sidebars;
		}
		foreach ( $sidebars as $sidebar => $ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $ids ) ) {
				continue;
			}
			$sidebars[ $sidebar ] = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $langs ) {
						return empty( $langs[ $id ] ) || $langs[ $id ] === $this->router->lang;
					}
				)
			);
		}
		return $sidebars;
	}
}
