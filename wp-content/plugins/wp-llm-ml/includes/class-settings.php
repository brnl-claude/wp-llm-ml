<?php
/**
 * Plugin settings storage.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one autoloaded option; strings live in a second one.
 */
class Settings {

	const OPTION = 'wpllmml_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$locale = get_option( 'WPLANG' );
		$locale = $locale ? $locale : 'en_US';
		$code   = strtolower( substr( $locale, 0, 2 ) );
		$known  = Languages::catalog();
		$name   = isset( $known[ $code ] ) ? $known[ $code ]['name'] : $locale;

		return array(
			'languages'         => array(
				array(
					'code'   => $code,
					'locale' => $locale,
					'name'   => $name,
				),
			),
			'default'           => $code,
			'post_types'        => array( 'post', 'page' ),
			// Browser language detection: off | suggest | redirect.
			'detect'            => 'redirect',
			// Where automatic redirects may happen: home | all.
			'detect_scope'      => 'home',
			// Theme menu location that gets a language switcher ('' = none).
			'menu_location'     => '',
			// Menu switcher layout: dropdown | inline.
			'menu_style'        => 'dropdown',
			// Label shown in switchers: name | code | both.
			'switcher_label'    => 'name',
			// When a page has no translation: home (link to that language's home page) | hide.
			'untranslated'      => 'home',
			// Menu items pointing to untranslated content: keep | hide.
			'menu_untranslated' => 'keep',
			// Widget id => language code ('' = all languages).
			'widget_langs'      => array(),
			'ai'                => array(
				'base_url'    => 'https://hostyourai.com/api/v1',
				'api_key'     => '',
				'model'       => '',
				'temperature' => 0.2,
			),
			'delete_data'       => false,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$defaults    = self::defaults();
			$merged      = array_merge( $defaults, $stored );
			$merged['ai'] = array_merge( $defaults['ai'], isset( $stored['ai'] ) && is_array( $stored['ai'] ) ? $stored['ai'] : array() );
			self::$cache = $merged;
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Replace and persist settings.
	 *
	 * @param array $settings Full settings array.
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION, $settings, true );
		self::flush();
	}

	/**
	 * Forget the in-memory cache.
	 */
	public static function flush() {
		self::$cache = null;
		Languages::flush();
	}
}
