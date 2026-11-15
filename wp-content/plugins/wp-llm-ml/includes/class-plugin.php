<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services.
	 *
	 * @var Router
	 */
	public $router;

	/**
	 * Links.
	 *
	 * @var Links
	 */
	public $links;

	/**
	 * Switcher.
	 *
	 * @var Switcher
	 */
	public $switcher;

	/**
	 * Singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	public function boot() {
		$this->router = new Router();
		$this->router->boot();

		$this->links    = new Links( $this->router );
		$this->switcher = new Switcher( $this->router );

		$this->links->boot();
		$this->switcher->boot();
		( new Query( $this->router ) )->boot();
		( new Strings( $this->router ) )->boot();
		( new Menus( $this->router, $this->links, $this->switcher ) )->boot();
		( new Widgets( $this->router ) )->boot();
		( new Detect( $this->router, $this->switcher ) )->boot();
		( new Seo( $this->router, $this->switcher ) )->boot();
		( new Rest( $this->router ) )->boot();
		( new Abilities() )->boot();
		( new Updater() )->boot();

		( new Integrations\Elementor() )->boot();
		( new Integrations\Generatepress( $this->router, $this->links ) )->boot();
		( new Integrations\Yoast( $this->links ) )->boot();

		if ( is_admin() ) {
			( new Admin\Admin( $this->router ) )->boot();
		}
		( new Admin\Admin_Bar( $this->router ) )->boot();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'save_post', array( $this, 'tag_new_post' ), 5, 2 );
	}

	/**
	 * Load the plugin's own interface translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-llm-ml', false, dirname( plugin_basename( WPLLMML_FILE ) ) . '/languages' );
	}

	/**
	 * Store the language explicitly on save (missing meta means "default
	 * language"; storing it keeps posts stable if the default ever changes).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function tag_new_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ! Translations::is_translatable_type( $post->post_type ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, Translations::META_LANG, true ) ) {
			return;
		}
		update_post_meta( $post_id, Translations::META_LANG, Translations::get_lang( $post_id ) );
	}

	/**
	 * Activation: store defaults so the settings screen starts filled in.
	 */
	public static function activate() {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}
	}
}
