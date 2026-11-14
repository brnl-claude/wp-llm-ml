<?php
/**
 * Language configuration.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Read access to the configured languages.
 */
class Languages {

	/**
	 * Configured languages keyed by code.
	 *
	 * @var array|null
	 */
	private static $languages = null;

	/**
	 * Languages offered in the "add language" dropdown.
	 *
	 * @return array code => [locale, name (native), english, rtl]
	 */
	public static function catalog() {
		$list = array(
			'ar' => array( 'ar', 'العربية', 'Arabic', true ),
			'bg' => array( 'bg_BG', 'Български', 'Bulgarian', false ),
			'cs' => array( 'cs_CZ', 'Čeština', 'Czech', false ),
			'da' => array( 'da_DK', 'Dansk', 'Danish', false ),
			'de' => array( 'de_DE', 'Deutsch', 'German', false ),
			'el' => array( 'el', 'Ελληνικά', 'Greek', false ),
			'en' => array( 'en_US', 'English', 'English', false ),
			'es' => array( 'es_ES', 'Español', 'Spanish', false ),
			'fi' => array( 'fi', 'Suomi', 'Finnish', false ),
			'fr' => array( 'fr_FR', 'Français', 'French', false ),
			'he' => array( 'he_IL', 'עברית', 'Hebrew', true ),
			'hr' => array( 'hr', 'Hrvatski', 'Croatian', false ),
			'hu' => array( 'hu_HU', 'Magyar', 'Hungarian', false ),
			'it' => array( 'it_IT', 'Italiano', 'Italian', false ),
			'ja' => array( 'ja', '日本語', 'Japanese', false ),
			'ko' => array( 'ko_KR', '한국어', 'Korean', false ),
			'nb' => array( 'nb_NO', 'Norsk bokmål', 'Norwegian', false ),
			'nl' => array( 'nl_NL', 'Nederlands', 'Dutch', false ),
			'pl' => array( 'pl_PL', 'Polski', 'Polish', false ),
			'pt' => array( 'pt_PT', 'Português', 'Portuguese', false ),
			'ro' => array( 'ro_RO', 'Română', 'Romanian', false ),
			'ru' => array( 'ru_RU', 'Русский', 'Russian', false ),
			'sk' => array( 'sk_SK', 'Slovenčina', 'Slovak', false ),
			'sl' => array( 'sl_SI', 'Slovenščina', 'Slovenian', false ),
			'sr' => array( 'sr_RS', 'Српски', 'Serbian', false ),
			'sv' => array( 'sv_SE', 'Svenska', 'Swedish', false ),
			'tr' => array( 'tr_TR', 'Türkçe', 'Turkish', false ),
			'uk' => array( 'uk', 'Українська', 'Ukrainian', false ),
			'zh' => array( 'zh_CN', '中文', 'Chinese', false ),
		);
		$out  = array();
		foreach ( $list as $code => $row ) {
			$out[ $code ] = array(
				'code'    => $code,
				'locale'  => $row[0],
				'name'    => $row[1],
				'english' => $row[2],
				'rtl'     => $row[3],
			);
		}
		return apply_filters( 'wpllmml_language_catalog', $out );
	}

	/**
	 * All configured languages, default language first.
	 *
	 * @return array code => [code, locale, name, english, rtl]
	 */
	public static function all() {
		if ( null !== self::$languages ) {
			return self::$languages;
		}
		$catalog = self::catalog();
		$default = Settings::get( 'default' );
		$list    = array();
		foreach ( (array) Settings::get( 'languages', array() ) as $row ) {
			$code = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
			if ( '' === $code || isset( $list[ $code ] ) ) {
				continue;
			}
			$known         = isset( $catalog[ $code ] ) ? $catalog[ $code ] : array();
			$list[ $code ] = array(
				'code'    => $code,
				'locale'  => ! empty( $row['locale'] ) ? $row['locale'] : ( $known ? $known['locale'] : $code ),
				'name'    => ! empty( $row['name'] ) ? $row['name'] : ( $known ? $known['name'] : $code ),
				'english' => $known ? $known['english'] : ( ! empty( $row['name'] ) ? $row['name'] : $code ),
				'rtl'     => $known ? $known['rtl'] : false,
			);
		}
		if ( ! $list ) {
			$fallback          = Settings::defaults()['languages'][0];
			$list[ $fallback['code'] ] = array_merge( $fallback, array( 'english' => $fallback['name'], 'rtl' => false ) );
		}
		if ( ! isset( $list[ $default ] ) ) {
			$default = key( $list );
		}
		// Default language first.
		$list            = array( $default => $list[ $default ] ) + $list;
		self::$languages = $list;
		return $list;
	}

	/**
	 * Forget the cache (after settings change).
	 */
	public static function flush() {
		self::$languages = null;
	}

	/**
	 * Language codes.
	 *
	 * @return string[]
	 */
	public static function codes() {
		return array_keys( self::all() );
	}

	/**
	 * The default language code (the one without URL prefix).
	 *
	 * @return string
	 */
	public static function default_code() {
		$all = self::all();
		return (string) key( $all );
	}

	/**
	 * Is this a configured language code?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function exists( $code ) {
		return is_string( $code ) && isset( self::all()[ $code ] );
	}

	/**
	 * Get one language.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function get( $code ) {
		$all = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	/**
	 * Tag for hreflang attributes. Language-only ("nl") by default, so the page
	 * targets all Dutch speakers rather than only those in the Netherlands.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function hreflang( $code ) {
		return (string) apply_filters( 'wpllmml_hreflang', str_replace( '_', '-', $code ), $code );
	}

	/**
	 * Is there more than one language? (Most features are no-ops otherwise.)
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::all() ) > 1;
	}
}
