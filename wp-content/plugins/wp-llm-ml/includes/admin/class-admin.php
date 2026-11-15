<?php
/**
 * Admin: settings screen (Settings → Languages), translation column and
 * language filter in post lists, the "Language" box in the editor, and the
 * actions behind them. No notices outside this plugin's own screens except
 * a configuration problem, no upsells, no tracking.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Admin;

use WPLLMML\Ai\Client;
use WPLLMML\Ai\Job;
use WPLLMML\Ai\Spellcheck;
use WPLLMML\Ai\Translator;
use WPLLMML\Duplicator;
use WPLLMML\Languages;
use WPLLMML\Router;
use WPLLMML\Settings;
use WPLLMML\Strings;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and actions.
 */
class Admin {

	const PAGE = 'wpllmml';

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		// Settings → General → Site Language also sets the default language's variant.
		add_action( 'update_option_WPLANG', array( $this, 'sync_site_language' ), 10, 2 );
		add_action(
			'add_option_WPLANG',
			function ( $option, $value ) {
				$this->sync_site_language( '', $value );
			},
			10,
			2
		);
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPLLMML_FILE ), array( $this, 'action_links' ) );

		add_action( 'admin_post_wpllmml_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_wpllmml_save_strings', array( $this, 'save_strings' ) );
		add_action( 'admin_post_wpllmml_save_widgets', array( $this, 'save_widgets' ) );
		add_action( 'admin_post_wpllmml_save_ai', array( $this, 'save_ai' ) );
		add_action( 'admin_post_wpllmml_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_wpllmml_ai_translate', array( $this, 'handle_ai_translate' ) );
		add_action( 'admin_post_wpllmml_unlink', array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_wpllmml_install_packs', array( $this, 'handle_install_packs' ) );
		add_action( 'admin_post_wpllmml_ai_strings', array( $this, 'handle_ai_strings' ) );
		add_action( 'wp_ajax_wpllmml_spellcheck', array( $this, 'ajax_spellcheck' ) );
		add_action( 'wp_ajax_wpllmml_apply_fix', array( $this, 'ajax_apply_fix' ) );
		add_action( 'wp_ajax_wpllmml_models', array( $this, 'ajax_models' ) );
		add_action( 'wp_ajax_wpllmml_ai_strings_batch', array( $this, 'ajax_ai_strings_batch' ) );
		add_action( 'wp_ajax_wpllmml_ai_post_start', array( $this, 'ajax_ai_post_start' ) );
		add_action( 'wp_ajax_wpllmml_ai_post_run', array( $this, 'ajax_ai_post_run' ) );
		add_action( 'wp_ajax_wpllmml_ai_post_status', array( $this, 'ajax_ai_post_status' ) );
		add_action( 'wp_ajax_wpllmml_ai_post_cancel', array( $this, 'ajax_ai_post_cancel' ) );

		if ( Languages::is_multilingual() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
			add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
			add_action( 'pre_get_posts', array( $this, 'admin_list_filter' ), 10 );
			add_action( 'restrict_manage_posts', array( $this, 'list_filter_dropdown' ), 10, 1 );
			foreach ( Translations::post_types() as $type ) {
				add_filter( "manage_{$type}_posts_columns", array( $this, 'list_column' ) );
				add_action( "manage_{$type}_posts_custom_column", array( $this, 'list_column_content' ), 10, 2 );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * URL that creates a translation and opens it.
	 *
	 * @param int    $post_id Source post.
	 * @param string $lang    Target language.
	 * @param string $then    edit | elementor.
	 * @param bool   $ai      Machine-translate right away.
	 * @return string
	 */
	public static function create_translation_url( $post_id, $lang, $then = 'edit', $ai = false ) {
		$args = array(
			'action' => 'wpllmml_create',
			'post'   => (int) $post_id,
			'lang'   => $lang,
			'then'   => $then,
		);
		if ( $ai ) {
			$args['ai'] = 1;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'wpllmml_create_' . (int) $post_id );
	}

	/**
	 * Settings screen URL.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'general', $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Edit link for a post: Elementor editor for Elementor pages.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function editor_url( $post_id ) {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && did_action( 'elementor/loaded' ) ) {
			return admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' );
		}
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * Abort unless the current user may manage options and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private function check_admin( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * When the WordPress site language changes to another variant of the
	 * default language (English (US) → English (UK)), follow it.
	 *
	 * @param mixed $old_value Previous WPLANG.
	 * @param mixed $new_value New WPLANG ('' means English (US)).
	 */
	public function sync_site_language( $old_value, $new_value ) {
		$locale = '' !== (string) $new_value ? (string) $new_value : 'en_US';
		$code   = strtolower( (string) strtok( $locale, '_' ) );
		$s      = Settings::all();
		foreach ( (array) $s['languages'] as $i => $row ) {
			if ( isset( $row['code'] ) && $row['code'] === Languages::default_code() && $row['code'] === $code && ( ! isset( $row['locale'] ) || $row['locale'] !== $locale ) ) {
				$s['languages'][ $i ]['locale'] = $locale;
				Settings::save( $s );
				return;
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Menu, assets, notices
	 * ------------------------------------------------------------------ */

	/**
	 * Settings page.
	 */
	public function menu() {
		add_options_page( __( 'Languages', 'wp-llm-ml' ), __( 'Languages', 'wp-llm-ml' ), 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'wp-llm-ml' ) . '</a>' );
		return $links;
	}

	/**
	 * Styles and scripts on our screens and in the editor.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		$ours   = 'settings_page_' . self::PAGE === $hook;
		$editor = $screen && in_array( $screen->base, array( 'post', 'edit' ), true ) && Translations::is_translatable_type( $screen->post_type );
		if ( ! $ours && ! $editor ) {
			return;
		}
		wp_enqueue_style( 'wpllmml-admin', WPLLMML_URL . 'assets/css/admin.css', array(), WPLLMML_VERSION );
		wp_enqueue_script( 'wpllmml-admin', WPLLMML_URL . 'assets/js/admin.js', array(), WPLLMML_VERSION, true );
		wp_localize_script(
			'wpllmml-admin',
			'wpllmmlAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wpllmml_ajax' ),
				'i18n'  => array(
					'checking'     => __( 'Checking…', 'wp-llm-ml' ),
					'noErrors'     => __( 'No errors found.', 'wp-llm-ml' ),
					'apply'        => __( 'Apply', 'wp-llm-ml' ),
					'applied'      => __( 'Applied', 'wp-llm-ml' ),
					'notFound'     => __( 'Text not found (already changed?)', 'wp-llm-ml' ),
					'error'        => __( 'Error', 'wp-llm-ml' ),
					'saveFirst'    => __( 'Save your changes first: applying a suggestion saves the stored version of this page.', 'wp-llm-ml' ),
					'confirmAi'    => __( 'Replace the text of this translation with a new AI translation of the source page? Your edits in this translation will be overwritten.', 'wp-llm-ml' ),
					'loading'      => __( 'Loading…', 'wp-llm-ml' ),
					'busy'         => __( 'Working… this can take a minute. Please keep this page open.', 'wp-llm-ml' ),
					/* translators: 1: number done, 2: total, 3: language name */
					'progress'     => __( 'Translating into %3$s… %1$d of %2$d texts done.', 'wp-llm-ml' ),
					/* translators: 1: number of texts, 2: language name */
					'done'         => __( 'Done: %1$d %2$s texts translated and saved. Please review them.', 'wp-llm-ml' ),
					/* translators: %s: language name */
					'nothing'      => __( 'All %s fields are already filled in. Empty a field to have it translated again.', 'wp-llm-ml' ),
					/* translators: 1: error message, 2: number of texts saved before the error */
					'failed'       => __( 'Stopped: %1$s (%2$d texts translated before this were saved).', 'wp-llm-ml' ),
					'leave'        => __( 'Translation is still running. Leave this page?', 'wp-llm-ml' ),
					'preparing'    => __( 'Starting the translation…', 'wp-llm-ml' ),
					/* translators: %s: elapsed time, e.g. 1:05 */
					'postProgress' => __( 'Translating the whole page with AI… %s', 'wp-llm-ml' ),
					/* translators: 1: elapsed time, 2: texts done, 3: total texts */
					'postProgressParts' => __( 'Translating the whole page with AI… %1$s (%2$d of %3$d texts)', 'wp-llm-ml' ),
					'cancel'       => __( 'Cancel', 'wp-llm-ml' ),
					/* translators: %s: elapsed time */
					'listProgress' => __( 'Translating with AI… %s', 'wp-llm-ml' ),
					'listDone'     => __( 'Translated. Please review before publishing:', 'wp-llm-ml' ),
					'editElementor'=> __( 'Edit with Elementor', 'wp-llm-ml' ),
					'edit'         => __( 'Edit', 'wp-llm-ml' ),
					'preview'      => __( 'Preview', 'wp-llm-ml' ),
					'cancelled'    => __( 'Cancelled. Nothing was changed.', 'wp-llm-ml' ),
					'opening'      => __( 'Done. Opening the translation…', 'wp-llm-ml' ),
					'retry'        => __( 'Try again', 'wp-llm-ml' ),
					/* translators: %s: error message */
					'stopped'      => __( 'Stopped: %s', 'wp-llm-ml' ),
				),
			)
		);
	}

	/**
	 * Configuration problems only; shown to administrators.
	 */
	public function notices() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) && Languages::is_multilingual() && '' === (string) get_option( 'permalink_structure' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'LLM Multilingual needs pretty permalinks (Settings → Permalinks) for language URLs such as /nl/.', 'wp-llm-ml' ) . '</p></div>';
		}
		$this->print_flash();
	}

	/**
	 * Remember result messages for the next screen of the current user
	 * (instead of putting message text in the URL).
	 *
	 * @param array $args 'wpllmml_msg' / 'wpllmml_err' => URL-encoded text.
	 * @return array Empty array (nothing to add to the redirect URL).
	 */
	private static function store_flash( array $args ) {
		$flash = array();
		foreach ( array( 'wpllmml_msg' => 'success', 'wpllmml_err' => 'error' ) as $key => $type ) {
			if ( isset( $args[ $key ] ) ) {
				$flash[] = array( $type, rawurldecode( (string) $args[ $key ] ) );
			}
		}
		if ( $flash ) {
			set_transient( 'wpllmml_flash_' . get_current_user_id(), $flash, 5 * MINUTE_IN_SECONDS );
		}
		return array();
	}

	/**
	 * Show and forget stored result messages.
	 */
	private function print_flash() {
		$key   = 'wpllmml_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $flash as $item ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $item[0] ), esc_html( $item[1] ) );
		}
	}

	/**
	 * Locales of configured languages without an installed WordPress language pack.
	 *
	 * @return string[]
	 */
	public static function missing_language_packs() {
		$installed = get_available_languages();
		$missing   = array();
		foreach ( Languages::all() as $lang ) {
			if ( 'en_US' !== $lang['locale'] && ! in_array( $lang['locale'], $installed, true ) ) {
				$missing[] = $lang['locale'];
			}
		}
		return $missing;
	}

	/* ------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------ */

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'general' => __( 'Languages', 'wp-llm-ml' ),
			'strings' => __( 'Texts', 'wp-llm-ml' ),
			'widgets' => __( 'Widgets', 'wp-llm-ml' ),
			'ai'      => __( 'AI', 'wp-llm-ml' ),
			'help'    => __( 'How it works', 'wp-llm-ml' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		echo '<div class="wrap wpllmml-wrap"><h1>' . esc_html__( 'Languages', 'wp-llm-ml' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::page_url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		$method = 'render_' . $tab;
		$this->$method();
		echo '</div>';
	}

	/**
	 * Tab: languages and behaviour.
	 */
	private function render_general() {
		$s       = Settings::all();
		$catalog = Languages::catalog();
		$missing = self::missing_language_packs();
		if ( $missing ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: %s: list of locales */
			echo esc_html( sprintf( __( 'WordPress language packs missing for: %s. Without them theme and plugin texts (buttons, dates, form messages) stay in English.', 'wp-llm-ml' ), implode( ', ', $missing ) ) );
			echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wpllmml_install_packs">';
			wp_nonce_field( 'wpllmml_install_packs' );
			submit_button( __( 'Install language packs', 'wp-llm-ml' ), 'secondary wpllmml-busy', 'submit', false );
			echo '</form><p></p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpllmml_save_settings">
			<?php wp_nonce_field( 'wpllmml_save_settings' ); ?>
			<h2><?php esc_html_e( 'Languages', 'wp-llm-ml' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The default language keeps the current URLs. Other languages get a prefix, for example /nl/. The variant only changes spelling, date formats and the WordPress language pack (for example English (UK) instead of English (US)); URLs and hreflang stay the same.', 'wp-llm-ml' ); ?></p>
			<table class="widefat striped wpllmml-languages">
				<thead><tr>
					<th><?php esc_html_e( 'Default', 'wp-llm-ml' ); ?></th>
					<th><?php esc_html_e( 'Code (URL)', 'wp-llm-ml' ); ?></th>
					<th><?php esc_html_e( 'Variant (spelling, dates, language pack)', 'wp-llm-ml' ); ?></th>
					<th><?php esc_html_e( 'Name (as shown to visitors)', 'wp-llm-ml' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'wp-llm-ml' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( array_values( Languages::all() ) as $i => $lang ) : ?>
					<tr>
						<td><input type="radio" name="default" value="<?php echo esc_attr( $lang['code'] ); ?>" <?php checked( Languages::default_code(), $lang['code'] ); ?> aria-label="<?php esc_attr_e( 'Default language', 'wp-llm-ml' ); ?>"></td>
						<td><input type="text" class="small-text" name="languages[<?php echo (int) $i; ?>][code]" value="<?php echo esc_attr( $lang['code'] ); ?>" readonly></td>
						<td>
							<?php $variants = Languages::variants( $lang['code'] ); ?>
							<?php if ( $variants ) : ?>
								<?php
								if ( ! isset( $variants[ $lang['locale'] ] ) ) {
									$variants = array( $lang['locale'] => $lang['locale'] ) + $variants;
								}
								?>
								<select name="languages[<?php echo (int) $i; ?>][locale]" aria-label="<?php esc_attr_e( 'Variant', 'wp-llm-ml' ); ?>">
									<?php foreach ( $variants as $locale => $label ) : ?>
										<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $lang['locale'], $locale ); ?>><?php echo esc_html( $label . ' — ' . $locale ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" class="regular-text" name="languages[<?php echo (int) $i; ?>][locale]" value="<?php echo esc_attr( $lang['locale'] ); ?>">
							<?php endif; ?>
						</td>
						<td><input type="text" class="regular-text" name="languages[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $lang['name'] ); ?>"></td>
						<td><?php if ( Languages::default_code() !== $lang['code'] ) : ?><input type="checkbox" name="languages[<?php echo (int) $i; ?>][remove]" value="1" aria-label="<?php esc_attr_e( 'Remove', 'wp-llm-ml' ); ?>"><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="wpllmml-add"><?php esc_html_e( 'Add language:', 'wp-llm-ml' ); ?></label>
				<select id="wpllmml-add" name="add_language">
					<option value=""><?php esc_html_e( '— choose —', 'wp-llm-ml' ); ?></option>
					<?php foreach ( $catalog as $code => $lang ) : ?>
						<?php
						if ( Languages::exists( $code ) ) {
							continue;
						}
						?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $lang['name'] . ' — ' . $lang['english'] . ' (' . $code . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<h2><?php esc_html_e( 'Content', 'wp-llm-ml' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Translatable content types', 'wp-llm-ml' ); ?></th><td>
					<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
						<?php
						// Media and Elementor's internal types (templates, floating buttons) are shared.
						if ( 'attachment' === $type->name || 0 === strpos( $type->name, 'elementor' ) || 0 === strpos( $type->name, 'e-' ) ) {
							continue;
						}
						?>
						<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
					<?php endforeach; ?>
				</td></tr>
			</table>

			<h2><?php esc_html_e( 'Visitors', 'wp-llm-ml' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="wpllmml-detect"><?php esc_html_e( 'Browser language', 'wp-llm-ml' ); ?></label></th><td>
					<select id="wpllmml-detect" name="detect">
						<option value="redirect" <?php selected( $s['detect'], 'redirect' ); ?>><?php esc_html_e( 'Redirect first-time visitors to their language', 'wp-llm-ml' ); ?></option>
						<option value="suggest" <?php selected( $s['detect'], 'suggest' ); ?>><?php esc_html_e( 'Only suggest their language in a small bar', 'wp-llm-ml' ); ?></option>
						<option value="off" <?php selected( $s['detect'], 'off' ); ?>><?php esc_html_e( 'Off', 'wp-llm-ml' ); ?></option>
					</select>
					<select name="detect_scope" aria-label="<?php esc_attr_e( 'Where to redirect', 'wp-llm-ml' ); ?>">
						<option value="home" <?php selected( $s['detect_scope'], 'home' ); ?>><?php esc_html_e( 'on the home page (elsewhere: suggest)', 'wp-llm-ml' ); ?></option>
						<option value="all" <?php selected( $s['detect_scope'], 'all' ); ?>><?php esc_html_e( 'on every page', 'wp-llm-ml' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Runs in the browser, so it works with page caching. Search engines are never redirected. Only visitors arriving from outside the site on a default-language page are redirected, once. A language chosen in the switcher is remembered (functional cookie wpllmml_lang, no tracking).', 'wp-llm-ml' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-menu"><?php esc_html_e( 'Switcher in menu', 'wp-llm-ml' ); ?></label></th><td>
					<select id="wpllmml-menu" name="menu_location">
						<option value=""><?php esc_html_e( '— none —', 'wp-llm-ml' ); ?></option>
						<?php foreach ( get_registered_nav_menus() as $location => $label ) : ?>
							<option value="<?php echo esc_attr( $location ); ?>" <?php selected( $s['menu_location'], $location ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="menu_style" aria-label="<?php esc_attr_e( 'Menu switcher layout', 'wp-llm-ml' ); ?>">
						<option value="dropdown" <?php selected( $s['menu_style'], 'dropdown' ); ?>><?php esc_html_e( 'as a dropdown', 'wp-llm-ml' ); ?></option>
						<option value="inline" <?php selected( $s['menu_style'], 'inline' ); ?>><?php esc_html_e( 'all languages side by side', 'wp-llm-ml' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Also available: the "Language switcher" Elementor widget, the classic widget and the shortcode [wpllmml_switcher style="inline|list|dropdown" label="name|code|both"].', 'wp-llm-ml' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-label"><?php esc_html_e( 'Switcher shows', 'wp-llm-ml' ); ?></label></th><td>
					<select id="wpllmml-label" name="switcher_label">
						<option value="name" <?php selected( $s['switcher_label'], 'name' ); ?>><?php esc_html_e( 'Language name (Nederlands)', 'wp-llm-ml' ); ?></option>
						<option value="code" <?php selected( $s['switcher_label'], 'code' ); ?>><?php esc_html_e( 'Language code (NL)', 'wp-llm-ml' ); ?></option>
						<option value="both" <?php selected( $s['switcher_label'], 'both' ); ?>><?php esc_html_e( 'Both (NL · Nederlands)', 'wp-llm-ml' ); ?></option>
					</select>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-untranslated"><?php esc_html_e( 'Page not translated yet', 'wp-llm-ml' ); ?></label></th><td>
					<select id="wpllmml-untranslated" name="untranslated">
						<option value="home" <?php selected( $s['untranslated'], 'home' ); ?>><?php esc_html_e( 'Switcher links to the home page of that language', 'wp-llm-ml' ); ?></option>
						<option value="hide" <?php selected( $s['untranslated'], 'hide' ); ?>><?php esc_html_e( 'Hide that language in the switcher', 'wp-llm-ml' ); ?></option>
					</select><br>
					<select name="menu_untranslated" aria-label="<?php esc_attr_e( 'Menu items without translation', 'wp-llm-ml' ); ?>">
						<option value="keep" <?php selected( $s['menu_untranslated'], 'keep' ); ?>><?php esc_html_e( 'Menu items without translation link to the original', 'wp-llm-ml' ); ?></option>
						<option value="hide" <?php selected( $s['menu_untranslated'], 'hide' ); ?>><?php esc_html_e( 'Hide menu items without translation', 'wp-llm-ml' ); ?></option>
					</select>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'wp-llm-ml' ); ?></th><td>
					<label><input type="checkbox" name="delete_data" value="1" <?php checked( ! empty( $s['delete_data'] ) ); ?>> <?php esc_html_e( 'Delete settings and translation links when the plugin is deleted (translated pages themselves are kept)', 'wp-llm-ml' ); ?></label>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save the general tab.
	 */
	public function save_settings() {
		$this->check_admin( 'wpllmml_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_admin().
		$s       = Settings::all();
		$catalog = Languages::catalog();
		$old_default = Languages::default_code();

		$languages = array();
		$rows      = isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ? wp_unslash( $_POST['languages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $rows as $row ) {
			$code = isset( $row['code'] ) ? sanitize_key( $row['code'] ) : '';
			if ( '' === $code || ! empty( $row['remove'] ) || isset( $languages[ $code ] ) ) {
				continue;
			}
			$languages[ $code ] = array(
				'code'   => $code,
				'locale' => isset( $row['locale'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', $row['locale'] ) : $code,
				'name'   => isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : $code,
			);
		}
		$add = isset( $_POST['add_language'] ) ? sanitize_key( wp_unslash( $_POST['add_language'] ) ) : '';
		if ( '' !== $add && isset( $catalog[ $add ] ) && ! isset( $languages[ $add ] ) ) {
			$languages[ $add ] = array(
				'code'   => $add,
				'locale' => $catalog[ $add ]['locale'],
				'name'   => $catalog[ $add ]['name'],
			);
		}
		if ( ! $languages ) {
			$languages[ $old_default ] = Languages::get( $old_default );
		}
		$default = isset( $_POST['default'] ) ? sanitize_key( wp_unslash( $_POST['default'] ) ) : $old_default;
		if ( ! isset( $languages[ $default ] ) ) {
			$default = isset( $languages[ $old_default ] ) ? $old_default : (string) key( $languages );
		}
		if ( $default !== $old_default ) {
			self::tag_untagged_posts( $old_default );
		}

		$types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$pick  = static function ( $key, $allowed, $fallback ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};
		$locations = array_merge( array( '' ), array_keys( get_registered_nav_menus() ) );

		$s['languages']         = array_values( $languages );
		$s['default']           = $default;
		$s['post_types']        = array_values( array_filter( $types, 'post_type_exists' ) );
		$s['detect']            = $pick( 'detect', array( 'redirect', 'suggest', 'off' ), 'redirect' );
		$s['detect_scope']      = $pick( 'detect_scope', array( 'home', 'all' ), 'home' );
		$s['menu_location']     = isset( $_POST['menu_location'] ) && in_array( sanitize_key( wp_unslash( $_POST['menu_location'] ) ), $locations, true ) ? sanitize_key( wp_unslash( $_POST['menu_location'] ) ) : '';
		$s['menu_style']        = $pick( 'menu_style', array( 'dropdown', 'inline' ), 'dropdown' );
		$s['switcher_label']    = $pick( 'switcher_label', array( 'name', 'code', 'both' ), 'name' );
		$s['untranslated']      = $pick( 'untranslated', array( 'home', 'hide' ), 'home' );
		$s['menu_untranslated'] = $pick( 'menu_untranslated', array( 'keep', 'hide' ), 'keep' );
		$s['delete_data']       = ! empty( $_POST['delete_data'] );
		// phpcs:enable
		Settings::save( $s );
		wp_safe_redirect( self::page_url( 'general', self::store_flash( array( 'wpllmml_msg' => rawurlencode( __( 'Settings saved.', 'wp-llm-ml' ) ) ) ) ) );
		exit;
	}

	/**
	 * Store the language explicitly on posts that rely on "missing = default",
	 * before the default language changes.
	 *
	 * @param string $code Language to store.
	 */
	public static function tag_untagged_posts( $code ) {
		global $wpdb;
		$types = Translations::post_types();
		if ( ! $types ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s ) WHERE m.meta_id IS NULL AND p.post_type IN ($in) AND p.post_status NOT IN ('auto-draft','inherit')", array_merge( array( Translations::META_LANG ), $types ) ) );
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, Translations::META_LANG, $code );
		}
	}

	/**
	 * Tab: texts (string translations).
	 */
	private function render_strings() {
		$others = array_slice( Languages::all(), 1, null, true );
		if ( ! $others ) {
			echo '<p>' . esc_html__( 'Add a second language first.', 'wp-llm-ml' ) . '</p>';
			return;
		}
		$table = Strings::table();
		$found = Strings::discover();
		?>
		<p class="description"><?php esc_html_e( 'Texts that are not part of a page: site title, tagline, custom menu labels, category names, widget texts and the copyright line. Leave a field empty to show the original. Pages and posts are translated as separate pages instead.', 'wp-llm-ml' ); ?></p>
		<?php if ( Client::is_configured() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpllmml-inline-form">
				<input type="hidden" name="action" value="wpllmml_ai_strings">
				<?php wp_nonce_field( 'wpllmml_ai_strings' ); ?>
				<?php foreach ( $others as $code => $lang ) : ?>
					<button type="submit" class="button wpllmml-ai-fill" name="lang" value="<?php echo esc_attr( $code ); ?>" data-wpllmml-ai-lang="<?php echo esc_attr( $code ); ?>" data-wpllmml-lang-name="<?php echo esc_attr( $lang['name'] ); ?>">
						<?php /* translators: %s: language name */ echo esc_html( sprintf( __( 'Fill empty %s fields with AI', 'wp-llm-ml' ), $lang['name'] ) ); ?>
					</button>
				<?php endforeach; ?>
			</form>
			<div class="wpllmml-ai-progress" hidden>
				<progress max="1" value="0"></progress>
				<span class="spinner is-active"></span>
				<span class="wpllmml-ai-status" role="status" aria-live="polite"></span>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpllmml_save_strings">
			<?php wp_nonce_field( 'wpllmml_save_strings' ); ?>
			<table class="widefat striped wpllmml-strings">
				<thead><tr>
					<th><?php echo esc_html( Languages::get( Languages::default_code() )['name'] ); ?></th>
					<?php foreach ( $others as $lang ) : ?>
						<th><?php echo esc_html( $lang['name'] ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $found as $row ) : ?>
					<?php
					list( $original, $context ) = $row;
					$key  = md5( $original );
					$rows = min( 6, max( 1, (int) ceil( strlen( $original ) / 60 ) ) );
					?>
					<tr>
						<td><span class="wpllmml-context"><?php echo esc_html( $context ); ?></span><div class="wpllmml-original"><?php echo esc_html( $original ); ?></div><input type="hidden" name="strings[<?php echo esc_attr( $key ); ?>][s]" value="<?php echo esc_attr( $original ); ?>"><input type="hidden" name="strings[<?php echo esc_attr( $key ); ?>][c]" value="<?php echo esc_attr( $context ); ?>"></td>
						<?php foreach ( $others as $code => $lang ) : ?>
							<td><textarea rows="<?php echo (int) $rows; ?>" data-key="<?php echo esc_attr( $key ); ?>" data-lang="<?php echo esc_attr( $code ); ?>" lang="<?php echo esc_attr( Languages::hreflang( $code ) ); ?>" name="strings[<?php echo esc_attr( $key ); ?>][t][<?php echo esc_attr( $code ); ?>]" aria-label="<?php echo esc_attr( $lang['name'] ); ?>"><?php echo esc_textarea( isset( $table[ $key ]['t'][ $code ] ) ? $table[ $key ]['t'][ $code ] : '' ); ?></textarea></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save string translations.
	 */
	public function save_strings() {
		$this->check_admin( 'wpllmml_save_strings' );
		$posted = isset( $_POST['strings'] ) && is_array( $_POST['strings'] ) ? wp_unslash( $_POST['strings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$table  = Strings::table();
		foreach ( $posted as $row ) {
			if ( ! isset( $row['s'] ) ) {
				continue;
			}
			$original = (string) $row['s'];
			$key      = md5( $original );
			$t        = array();
			foreach ( Languages::codes() as $code ) {
				if ( isset( $row['t'][ $code ] ) && '' !== trim( $row['t'][ $code ] ) ) {
					$t[ $code ] = current_user_can( 'unfiltered_html' ) ? (string) $row['t'][ $code ] : wp_kses_post( $row['t'][ $code ] );
				}
			}
			if ( $t ) {
				$table[ $key ] = array(
					's' => $original,
					'c' => isset( $row['c'] ) ? sanitize_text_field( $row['c'] ) : '',
					't' => $t,
				);
			} else {
				unset( $table[ $key ] );
			}
		}
		Strings::save( $table );
		wp_safe_redirect( self::page_url( 'strings', self::store_flash( array( 'wpllmml_msg' => rawurlencode( __( 'Texts saved.', 'wp-llm-ml' ) ) ) ) ) );
		exit;
	}

	/**
	 * Fill empty string translations of one language with AI.
	 */
	public function handle_ai_strings() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- AI calls can take a while.
		}
		$this->check_admin( 'wpllmml_ai_strings' );
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( ! Languages::exists( $lang ) || Languages::default_code() === $lang ) {
			wp_safe_redirect( self::page_url( 'strings' ) );
			exit;
		}
		$table = Strings::table();
		$todo  = array();
		foreach ( Strings::discover() as $row ) {
			$key = md5( $row[0] );
			if ( empty( $table[ $key ]['t'][ $lang ] ) ) {
				$todo[ $key ] = $row;
			}
		}
		if ( $todo ) {
			$result = ( new Translator() )->translate_strings( wp_list_pluck( array_values( $todo ), 0 ), Languages::default_code(), $lang );
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( self::page_url( 'strings', self::store_flash( array( 'wpllmml_err' => rawurlencode( $result->get_error_message() ) ) ) ) );
				exit;
			}
			$i = 0;
			foreach ( $todo as $key => $row ) {
				$table[ $key ]                = isset( $table[ $key ] ) ? $table[ $key ] : array(
					's' => $row[0],
					'c' => $row[1],
					't' => array(),
				);
				$table[ $key ]['t'][ $lang ] = $result[ $i++ ];
			}
			Strings::save( $table );
		}
		/* translators: %d: number of texts */
		wp_safe_redirect( self::page_url( 'strings', self::store_flash( array( 'wpllmml_msg' => rawurlencode( sprintf( __( '%d texts translated. Please review them.', 'wp-llm-ml' ), count( $todo ) ) ) ) ) ) );
		exit;
	}

	/**
	 * Tab: widgets per language.
	 */
	private function render_widgets() {
		$langs   = (array) Settings::get( 'widget_langs', array() );
		$widgets = Strings::active_widgets();
		?>
		<p class="description"><?php esc_html_e( 'Show a widget in one language only. Example: make a Dutch copy of the newsletter form widget and set the English one to English and the copy to Nederlands.', 'wp-llm-ml' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpllmml_save_widgets">
			<?php wp_nonce_field( 'wpllmml_save_widgets' ); ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Widget', 'wp-llm-ml' ); ?></th><th><?php esc_html_e( 'Show in', 'wp-llm-ml' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $widgets as $widget ) : ?>
					<?php
					$inst    = $widget['instance'];
					$snippet = ! empty( $inst['title'] ) ? $inst['title'] : ( ! empty( $inst['text'] ) ? $inst['text'] : ( ! empty( $inst['content'] ) ? $inst['content'] : '' ) );
					$snippet = wp_trim_words( wp_strip_all_tags( (string) $snippet ), 12 );
					$current = isset( $langs[ $widget['id'] ] ) ? $langs[ $widget['id'] ] : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $widget['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $snippet ); ?></span></td>
						<td><select name="widget_langs[<?php echo esc_attr( $widget['id'] ); ?>]">
							<option value=""><?php esc_html_e( 'All languages', 'wp-llm-ml' ); ?></option>
							<?php foreach ( Languages::all() as $code => $lang ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $lang['name'] ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save widget languages.
	 */
	public function save_widgets() {
		$this->check_admin( 'wpllmml_save_widgets' );
		$posted = isset( $_POST['widget_langs'] ) && is_array( $_POST['widget_langs'] ) ? wp_unslash( $_POST['widget_langs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$langs  = array();
		foreach ( $posted as $id => $code ) {
			$id   = sanitize_text_field( $id );
			$code = sanitize_key( $code );
			if ( '' !== $code && Languages::exists( $code ) ) {
				$langs[ $id ] = $code;
			}
		}
		$s                 = Settings::all();
		$s['widget_langs'] = $langs;
		Settings::save( $s );
		wp_safe_redirect( self::page_url( 'widgets', self::store_flash( array( 'wpllmml_msg' => rawurlencode( __( 'Settings saved.', 'wp-llm-ml' ) ) ) ) ) );
		exit;
	}

	/**
	 * Tab: AI provider.
	 */
	private function render_ai() {
		$ai        = Client::config();
		$constant  = defined( 'WPLLMML_AI_API_KEY' ) && WPLLMML_AI_API_KEY;
		$has_key   = '' !== (string) $ai['api_key'];
		?>
		<p class="description"><?php esc_html_e( 'Optional. Used only when an editor clicks a translate or spell-check button; nothing is sent automatically. Works with any OpenAI-compatible API. HostYourAI (hosted in the EU, prompts are not stored or used for training) is the default.', 'wp-llm-ml' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpllmml_save_ai">
			<?php wp_nonce_field( 'wpllmml_save_ai' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="wpllmml-ai-url"><?php esc_html_e( 'API base URL', 'wp-llm-ml' ); ?></label></th><td>
					<input id="wpllmml-ai-url" type="url" class="regular-text code" name="base_url" value="<?php echo esc_attr( $ai['base_url'] ); ?>">
					<p class="description"><?php esc_html_e( 'HostYourAI: https://hostyourai.com/api/v1 — the plugin calls {base}/chat/completions and {base}/models.', 'wp-llm-ml' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-ai-key"><?php esc_html_e( 'API key', 'wp-llm-ml' ); ?></label></th><td>
					<?php if ( $constant ) : ?>
						<p><?php esc_html_e( 'Set in wp-config.php (WPLLMML_AI_API_KEY).', 'wp-llm-ml' ); ?></p>
					<?php else : ?>
						<input id="wpllmml-ai-key" type="password" class="regular-text code" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo $has_key ? esc_attr__( '(saved — leave empty to keep)', 'wp-llm-ml' ) : 'hyai-rt-…'; ?>">
						<?php if ( $has_key ) : ?>
							<label><input type="checkbox" name="clear_key" value="1"> <?php esc_html_e( 'Remove saved key', 'wp-llm-ml' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Safer: define( \'WPLLMML_AI_API_KEY\', \'…\' ); in wp-config.php instead of storing the key in the database.', 'wp-llm-ml' ); ?></p>
					<?php endif; ?>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-ai-model"><?php esc_html_e( 'Model', 'wp-llm-ml' ); ?></label></th><td>
					<input id="wpllmml-ai-model" type="text" class="regular-text code" name="model" list="wpllmml-models" value="<?php echo esc_attr( $ai['model'] ); ?>">
					<datalist id="wpllmml-models"></datalist>
					<button type="button" class="button" id="wpllmml-load-models"><?php esc_html_e( 'Load available models', 'wp-llm-ml' ); ?></button>
					<span id="wpllmml-models-status" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'Pick a capable multilingual instruct model (for example a Mistral Small/Large, Llama 3.3 70B or Qwen model). Larger models translate Dutch and Romanian noticeably better.', 'wp-llm-ml' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-ai-temp"><?php esc_html_e( 'Temperature', 'wp-llm-ml' ); ?></label></th><td>
					<input id="wpllmml-ai-temp" type="number" step="0.1" min="0" max="1" class="small-text" name="temperature" value="<?php echo esc_attr( (string) $ai['temperature'] ); ?>">
				</td></tr>
				<tr><th scope="row"><label for="wpllmml-ai-instructions"><?php esc_html_e( 'Instructions for translations', 'wp-llm-ml' ); ?></label></th><td>
					<textarea id="wpllmml-ai-instructions" class="large-text" rows="5" name="instructions" placeholder="<?php esc_attr_e( 'Example: Address the reader informally (je/jij, tu). Keep the terms Gestalt and Reiki untranslated. Tone: warm, calm and professional.', 'wp-llm-ml' ); ?>"><?php echo esc_textarea( (string) $ai['instructions'] ); ?></textarea>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Save AI settings.
	 */
	public function save_ai() {
		$this->check_admin( 'wpllmml_save_ai' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_admin().
		$s  = Settings::all();
		$ai = $s['ai'];
		$ai['base_url']     = isset( $_POST['base_url'] ) ? esc_url_raw( untrailingslashit( trim( wp_unslash( $_POST['base_url'] ) ) ) ) : $ai['base_url']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ai['model']        = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : $ai['model'];
		$ai['temperature']  = isset( $_POST['temperature'] ) ? max( 0, min( 1, (float) $_POST['temperature'] ) ) : 0.2;
		$ai['instructions'] = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
		if ( ! empty( $_POST['clear_key'] ) ) {
			$ai['api_key'] = '';
		} elseif ( ! empty( $_POST['api_key'] ) ) {
			$ai['api_key'] = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
		}
		// phpcs:enable
		$s['ai'] = $ai;
		Settings::save( $s );
		wp_safe_redirect( self::page_url( 'ai', self::store_flash( array( 'wpllmml_msg' => rawurlencode( __( 'Settings saved.', 'wp-llm-ml' ) ) ) ) ) );
		exit;
	}

	/**
	 * Tab: short manual.
	 */
	private function render_help() {
		?>
		<div class="wpllmml-help">
			<h2><?php esc_html_e( 'Translating a page', 'wp-llm-ml' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'In Pages, click the "+" of a language in the Languages column (or use the Language box in the editor, or the Translations section in Elementor\'s page settings).', 'wp-llm-ml' ); ?></li>
				<li><?php esc_html_e( 'A draft copy is created, including the Elementor layout, page settings and Yoast SEO fields, and linked as translation.', 'wp-llm-ml' ); ?></li>
				<li><?php esc_html_e( 'Translate the texts in Elementor as usual (or let AI do a first draft), check the slug and Yoast fields, and publish.', 'wp-llm-ml' ); ?></li>
			</ol>
			<h2><?php esc_html_e( 'What visitors and search engines get', 'wp-llm-ml' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Every language has its own URLs (/nl/..., /ro/...); default-language URLs do not change.', 'wp-llm-ml' ); ?></li>
				<li><?php esc_html_e( 'Server-rendered, fully translated HTML with the right <html lang>, hreflang and x-default alternates, og:locale and schema inLanguage — readable for search engines and AI crawlers without JavaScript.', 'wp-llm-ml' ); ?></li>
				<li><?php esc_html_e( 'Menus, internal links, the logo link, search, blog lists and previous/next links stay inside the visitor\'s language.', 'wp-llm-ml' ); ?></li>
				<li><?php esc_html_e( 'REST API: every post has a "wpllmml" field with its language and translations; collections accept ?lang=nl; /wp-json/wpllmml/v1/languages lists the languages. AI agents can use the abilities in the "Multilingual" category.', 'wp-llm-ml' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Post list
	 * ------------------------------------------------------------------ */

	/**
	 * Add the Languages column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function list_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['wpllmml'] = __( 'Languages', 'wp-llm-ml' );
			}
		}
		if ( ! isset( $new['wpllmml'] ) ) {
			$new['wpllmml'] = __( 'Languages', 'wp-llm-ml' );
		}
		return $new;
	}

	/**
	 * Languages column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function list_column_content( $column, $post_id ) {
		if ( 'wpllmml' !== $column ) {
			return;
		}
		$own          = Translations::get_lang( $post_id );
		$translations = Translations::get_translations( $post_id );
		$ai           = Client::is_configured();
		$can_edit     = current_user_can( 'edit_post', $post_id );
		$builder      = 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && did_action( 'elementor/loaded' );
		$default_name = Languages::get( Languages::default_code() )['name'];
		echo '<div class="wpllmml-langs-cell" data-wpllmml-job-host="1"><span class="wpllmml-langs">';
		foreach ( Languages::all() as $code => $lang ) {
			$label = strtoupper( $code );
			if ( $code === $own ) {
				printf( '<span class="wpllmml-lang is-own" title="%s">%s</span>', esc_attr( $lang['name'] ), esc_html( $label ) );
				continue;
			}
			if ( isset( $translations[ $code ] ) ) {
				$tid    = (int) $translations[ $code ];
				$status = get_post_status( $tid );
				$items  = array();
				if ( current_user_can( 'edit_post', $tid ) ) {
					if ( self::editor_url( $tid ) !== (string) get_edit_post_link( $tid, 'raw' ) ) {
						$items[] = sprintf( '<a href="%s">%s</a>', esc_url( self::editor_url( $tid ) ), esc_html__( 'Edit with Elementor', 'wp-llm-ml' ) );
					}
					$items[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $tid, 'raw' ) ), esc_html__( 'Edit', 'wp-llm-ml' ) );
				}
				$items[] = sprintf( '<a href="%s">%s</a>', esc_url( 'publish' === $status ? get_permalink( $tid ) : get_preview_post_link( $tid ) ), 'publish' === $status ? esc_html__( 'View', 'wp-llm-ml' ) : esc_html__( 'Preview', 'wp-llm-ml' ) );
				if ( $ai && $code !== Languages::default_code() && current_user_can( 'edit_post', $tid ) ) {
					$items[] = sprintf(
						'<button type="button" class="wpllmml-ai-post wpllmml-confirm" data-mode="retranslate" data-post="%1$d" data-lang="%2$s" data-context="list">%3$s</button>',
						$tid,
						esc_attr( $code ),
						/* translators: %s: language name */
						esc_html( sprintf( __( 'Translate again from %s with AI', 'wp-llm-ml' ), $default_name ) )
					);
				}
				$status_object = get_post_status_object( $status );
				self::print_lang_menu(
					'is-' . $status,
					$label,
					/* translators: 1: language, 2: status */
					sprintf( __( '%1$s translation (%2$s)', 'wp-llm-ml' ), $lang['name'], $status_object ? $status_object->label : $status ),
					$items,
					$code
				);
				continue;
			}
			if ( ! $can_edit ) {
				continue;
			}
			$items   = array();
			$items[] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( self::create_translation_url( $post_id, $code, $builder ? 'elementor' : 'edit' ) ),
				$builder ? esc_html__( 'Create translation (copy) and open Elementor', 'wp-llm-ml' ) : esc_html__( 'Create translation (copy)', 'wp-llm-ml' )
			);
			if ( $ai ) {
				$items[] = sprintf(
					'<button type="button" class="wpllmml-ai-post" data-mode="create" data-source="%1$d" data-lang="%2$s" data-context="list">%3$s</button>',
					(int) $post_id,
					esc_attr( $code ),
					esc_html__( 'Create & translate with AI', 'wp-llm-ml' )
				);
			}
			self::print_lang_menu(
				'is-missing',
				'+' . $label,
				/* translators: %s: language */
				sprintf( __( 'Create %s translation', 'wp-llm-ml' ), $lang['name'] ),
				$items,
				$code
			);
		}
		echo '</span></div>';
	}

	/**
	 * One language badge with its menu (a <details> element: works without
	 * JavaScript and with the keyboard).
	 *
	 * @param string   $class Badge state class.
	 * @param string   $label Badge text.
	 * @param string   $title Accessible description.
	 * @param string[] $items Menu entries (HTML, already escaped).
	 * @param string   $code  Language code.
	 */
	private static function print_lang_menu( $class, $label, $title, array $items, $code ) {
		printf(
			'<details class="wpllmml-lang-menu" data-lang="%1$s"><summary class="wpllmml-lang %2$s" title="%3$s" aria-label="%3$s">%4$s</summary><div class="wpllmml-lang-menu__items" role="menu">%5$s</div></details>',
			esc_attr( $code ),
			esc_attr( $class ),
			esc_attr( $title ),
			esc_html( $label ),
			implode( '', $items ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		);
	}

	/**
	 * Language dropdown above post lists.
	 *
	 * @param string $post_type Post type.
	 */
	public function list_filter_dropdown( $post_type ) {
		if ( ! Translations::is_translatable_type( $post_type ) ) {
			return;
		}
		$current = isset( $_GET['wpllmml_lang'] ) ? sanitize_key( wp_unslash( $_GET['wpllmml_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="wpllmml-filter">' . esc_html__( 'Filter by language', 'wp-llm-ml' ) . '</label><select id="wpllmml-filter" name="wpllmml_lang"><option value="">' . esc_html__( 'All languages', 'wp-llm-ml' ) . '</option>';
		foreach ( Languages::all() as $code => $lang ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $lang['name'] ) );
		}
		echo '</select>';
	}

	/**
	 * Apply the language dropdown.
	 *
	 * @param \WP_Query $q Query.
	 */
	public function admin_list_filter( $q ) {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! $q->is_main_query() || empty( $_GET['wpllmml_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['wpllmml_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( Languages::exists( $code ) ) {
			$q->set( 'wpllmml_lang', $code );
		}
	}

	/* ------------------------------------------------------------------
	 * Editor box
	 * ------------------------------------------------------------------ */

	/**
	 * Register the box.
	 *
	 * @param string   $post_type Type.
	 * @param \WP_Post $post      Post.
	 */
	public function add_meta_box( $post_type, $post ) {
		if ( Translations::is_translatable_type( $post_type ) && $post instanceof \WP_Post ) {
			add_meta_box( 'wpllmml-box', __( 'Language', 'wp-llm-ml' ), array( $this, 'render_meta_box' ), $post_type, 'side', 'high' );
		}
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_meta_box( $post ) {
		$own          = Translations::get_lang( $post );
		$translations = Translations::get_translations( $post );
		$is_new       = 'auto-draft' === $post->post_status;
		wp_nonce_field( 'wpllmml_box', 'wpllmml_box_nonce' );
		?>
		<p>
			<label for="wpllmml-lang"><strong><?php esc_html_e( 'Language of this page', 'wp-llm-ml' ); ?></strong></label><br>
			<select id="wpllmml-lang" name="wpllmml_lang">
				<?php foreach ( Languages::all() as $code => $lang ) : ?>
					<?php $taken = isset( $translations[ $code ] ) && (int) $translations[ $code ] !== (int) $post->ID; ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $own, $code ); ?> <?php disabled( $taken ); ?>><?php echo esc_html( $lang['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $is_new ) : ?>
			<p class="description"><?php esc_html_e( 'Save the page to create translations.', 'wp-llm-ml' ); ?></p>
			<?php
			return;
		endif;
		?>
		<ul class="wpllmml-box-list">
		<?php foreach ( Languages::all() as $code => $lang ) : ?>
			<?php
			if ( $code === $own ) {
				continue;
			}
			?>
			<li><strong><?php echo esc_html( $lang['name'] ); ?>:</strong>
			<?php if ( isset( $translations[ $code ] ) ) : ?>
				<?php $tid = $translations[ $code ]; ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $tid ) ); ?>"><?php esc_html_e( 'Edit', 'wp-llm-ml' ); ?></a>
				<?php if ( self::editor_url( $tid ) !== (string) get_edit_post_link( $tid, 'raw' ) ) : ?>
					| <a href="<?php echo esc_url( self::editor_url( $tid ) ); ?>"><?php esc_html_e( 'Elementor', 'wp-llm-ml' ); ?></a>
				<?php endif; ?>
				<span class="wpllmml-status">(<?php echo esc_html( get_post_status_object( get_post_status( $tid ) )->label ); ?>)</span>
			<?php else : ?>
				<a href="<?php echo esc_url( self::create_translation_url( $post->ID, $code ) ); ?>"><?php esc_html_e( 'Create', 'wp-llm-ml' ); ?></a>
				<?php if ( Client::is_configured() ) : ?>
					| <a class="wpllmml-ai-post" data-mode="create" data-source="<?php echo (int) $post->ID; ?>" data-lang="<?php echo esc_attr( $code ); ?>" href="<?php echo esc_url( self::create_translation_url( $post->ID, $code, 'edit', true ) ); ?>"><?php esc_html_e( 'Create & translate with AI', 'wp-llm-ml' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php
		if ( Client::is_configured() ) {
			$source = $own !== Languages::default_code() ? Translations::get_translation( $post, Languages::default_code() ) : 0;
			if ( $source ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'wpllmml_ai_translate', 'post' => $post->ID ), admin_url( 'admin-post.php' ) ), 'wpllmml_ai_translate_' . $post->ID );
				printf(
					'<p><a class="button wpllmml-confirm wpllmml-ai-post" data-mode="retranslate" data-post="%3$d" href="%1$s">%2$s</a></p>',
					esc_url( $url ),
					/* translators: %s: language name */
					esc_html( sprintf( __( 'Translate from %s with AI', 'wp-llm-ml' ), Languages::get( Languages::default_code() )['name'] ) ),
					(int) $post->ID
				);
			}
			printf(
				'<p><button type="button" class="button wpllmml-spellcheck" data-post="%1$d">%2$s</button></p><div class="wpllmml-spell-results" aria-live="polite"></div>',
				(int) $post->ID,
				esc_html__( 'Check spelling with AI', 'wp-llm-ml' )
			);
		}
		if ( count( $translations ) > 1 ) {
			$unlink = wp_nonce_url( add_query_arg( array( 'action' => 'wpllmml_unlink', 'post' => $post->ID ), admin_url( 'admin-post.php' ) ), 'wpllmml_unlink_' . $post->ID );
			echo '<p><a class="wpllmml-unlink" href="' . esc_url( $unlink ) . '">' . esc_html__( 'Unlink from its translations', 'wp-llm-ml' ) . '</a></p>';
		}
	}

	/**
	 * Save the language chosen in the box.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['wpllmml_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpllmml_box_nonce'] ) ), 'wpllmml_box' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || ! Translations::is_translatable_type( $post->post_type ) ) {
			return;
		}
		$code = isset( $_POST['wpllmml_lang'] ) ? sanitize_key( wp_unslash( $_POST['wpllmml_lang'] ) ) : '';
		if ( Languages::exists( $code ) && Translations::get_lang( $post_id ) !== $code ) {
			Translations::set_lang( $post_id, $code );
		}
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Create a translation (optionally AI-translated) and open it.
	 */
	public function handle_create() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- AI calls can take a while.
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'wpllmml_create_' . $post_id );
		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		if ( ! $type || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $type->cap->create_posts ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$then   = isset( $_GET['then'] ) ? sanitize_key( wp_unslash( $_GET['then'] ) ) : 'edit';
		$new_id = Duplicator::create( $post_id, $lang );
		if ( is_wp_error( $new_id ) ) {
			$existing = Translations::get_translation( $post_id, $lang );
			if ( $existing ) {
				wp_safe_redirect( 'elementor' === $then ? self::editor_url( $existing ) : (string) get_edit_post_link( $existing, 'raw' ) );
				exit;
			}
			wp_die( esc_html( $new_id->get_error_message() ) );
		}
		$args = array();
		if ( ! empty( $_GET['ai'] ) && Client::is_configured() ) { // phpcs:ignore WordPress.Security.NonceVerification
			$result = ( new Translator() )->translate_post( $new_id, Translations::get_lang( $post_id ), $lang );
			$args   = is_wp_error( $result )
				? array( 'wpllmml_err' => rawurlencode( $result->get_error_message() ) )
				: array( 'wpllmml_msg' => rawurlencode( __( 'Translated with AI. Please review the text before publishing.', 'wp-llm-ml' ) ) );
		}
		$url = 'elementor' === $then ? self::editor_url( $new_id ) : (string) get_edit_post_link( $new_id, 'raw' );
		wp_safe_redirect( add_query_arg( self::store_flash( $args ), $url ) );
		exit;
	}

	/**
	 * Re-translate a translation from the default language with AI.
	 */
	public function handle_ai_translate() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- AI calls can take a while.
		}
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'wpllmml_ai_translate_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$result = ( new Translator() )->translate_post( $post_id, Languages::default_code(), Translations::get_lang( $post_id ) );
		$args   = is_wp_error( $result )
			? array( 'wpllmml_err' => rawurlencode( $result->get_error_message() ) )
			: array( 'wpllmml_msg' => rawurlencode( __( 'Translated with AI. Please review the text before publishing.', 'wp-llm-ml' ) ) );
		wp_safe_redirect( add_query_arg( self::store_flash( $args ), (string) get_edit_post_link( $post_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Unlink a post from its group.
	 */
	public function handle_unlink() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'wpllmml_unlink_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		Translations::unlink( $post_id );
		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/**
	 * Install WordPress language packs for the configured languages, then
	 * theme and plugin translations.
	 */
	public function handle_install_packs() {
		$this->check_admin( 'wpllmml_install_packs' );
		if ( ! current_user_can( 'install_languages' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$installed = array();
		$failed    = array();
		foreach ( self::missing_language_packs() as $locale ) {
			if ( wp_download_language_pack( $locale ) ) {
				$installed[] = $locale;
			} else {
				$failed[] = $locale;
			}
		}
		// Theme and plugin translations for the new locales.
		wp_clean_update_cache();
		wp_update_plugins();
		wp_update_themes();
		$updates = wp_get_translation_updates();
		if ( $updates ) {
			$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
			$upgrader->bulk_upgrade( $updates );
		}
		$args = array();
		if ( $installed ) {
			/* translators: %s: locales */
			$args['wpllmml_msg'] = rawurlencode( sprintf( __( 'Installed: %s', 'wp-llm-ml' ), implode( ', ', $installed ) ) );
		}
		if ( $failed ) {
			/* translators: %s: locales */
			$args['wpllmml_err'] = rawurlencode( sprintf( __( 'Could not install: %s', 'wp-llm-ml' ), implode( ', ', $failed ) ) );
		}
		wp_safe_redirect( self::page_url( 'general', self::store_flash( $args ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Spell check a post.
	 */
	public function ajax_spellcheck() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$result = ( new Spellcheck() )->check_post( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Apply one spelling suggestion.
	 */
	public function ajax_apply_fix() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$original   = isset( $_POST['original'] ) ? (string) wp_unslash( $_POST['original'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$suggestion = isset( $_POST['suggestion'] ) ? (string) wp_unslash( $_POST['suggestion'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$suggestion = wp_kses_post( $suggestion );
		}
		$result = ( new Spellcheck() )->apply( $post_id, $original, $suggestion );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'applied' => (bool) $result ) );
	}

	/**
	 * Translate a small batch of texts (Texts tab) and save them right away,
	 * so the screen can show progress and no single request runs long enough
	 * to hit a web server timeout.
	 */
	public function ajax_ai_strings_batch() {
		check_ajax_referer( 'wpllmml_ajax' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$keys = isset( $_POST['keys'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['keys'] ) ) : array();
		if ( ! Languages::exists( $lang ) || Languages::default_code() === $lang ) {
			wp_send_json_error( __( 'Unknown language.', 'wp-llm-ml' ) );
		}
		if ( ! Client::is_configured() ) {
			wp_send_json_error( __( 'The AI provider is not configured (API key and model are required).', 'wp-llm-ml' ) );
		}
		// Only texts the site really has: the originals come from the server, never from the request.
		$todo = array();
		foreach ( Strings::discover() as $row ) {
			$key = md5( $row[0] );
			if ( in_array( $key, $keys, true ) ) {
				$todo[ $key ] = $row;
			}
		}
		if ( ! $todo ) {
			wp_send_json_success( array( 'translations' => (object) array() ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$result = ( new Translator() )->translate_strings( wp_list_pluck( array_values( $todo ), 0 ), Languages::default_code(), $lang );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		$table = Strings::table();
		$out   = array();
		$i     = 0;
		foreach ( $todo as $key => $row ) {
			$text = (string) $result[ $i++ ];
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$text = wp_kses_post( $text );
			}
			if ( ! isset( $table[ $key ] ) ) {
				$table[ $key ] = array(
					's' => $row[0],
					'c' => $row[1],
					't' => array(),
				);
			}
			$table[ $key ]['t'][ $lang ] = $text;
			$out[ $key ]                 = $text;
		}
		Strings::save( $table );
		wp_send_json_success( array( 'translations' => (object) $out ) );
	}

	/**
	 * Page translation with AI, step 1 (quick): create the translation draft if
	 * asked and register the job. The browser then calls ..._run, which does the
	 * work in the background, and polls ..._status.
	 */
	public function ajax_ai_post_start() {
		check_ajax_referer( 'wpllmml_ajax' );
		if ( ! Client::is_configured() ) {
			wp_send_json_error( __( 'The AI provider is not configured (API key and model are required).', 'wp-llm-ml' ) );
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( 'create' === $mode ) {
			$source = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;
			$lang   = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
			$type   = get_post_type_object( (string) get_post_type( $source ) );
			if ( ! $type || ! current_user_can( 'edit_post', $source ) || ! current_user_can( $type->cap->create_posts ) ) {
				wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
			}
			$post_id = Translations::get_translation( $source, $lang );
			if ( ! $post_id ) {
				// An earlier attempt may already have created the draft; reuse it then.
				$post_id = Duplicator::create( $source, $lang );
				if ( is_wp_error( $post_id ) ) {
					wp_send_json_error( $post_id->get_error_message() );
				}
			}
			$from = Translations::get_lang( $source );
		} else {
			$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
			if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
			}
			$lang = Translations::get_lang( $post_id );
			$from = Languages::default_code();
		}
		$job = Job::start( $post_id, $from, $lang );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( $job->get_error_message(), 409 );
		}
		wp_send_json_success(
			array(
				'post'  => (int) $post_id,
				'token' => $job['token'],
			)
		);
	}

	/**
	 * Page translation, step 2: answer the browser at once, then translate the
	 * whole page in this same request on the server. Its own response is not
	 * waited for, so a gateway timeout cannot interrupt the editor.
	 */
	public function ajax_ai_post_run() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		self::respond_and_continue( array( 'started' => true ) );
		Job::run( $post_id, $token );
		exit;
	}

	/**
	 * Page translation, step 3 (repeated): the browser's heartbeat and status poll.
	 */
	public function ajax_ai_post_status() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$status = Job::status( $post_id, $token );
		if ( 'done' === $status['status'] ) {
			Job::clear( $post_id );
			// From the editor the page reloads into the translation with a notice;
			// from a post list the row is updated in place.
			if ( ! isset( $_POST['context'] ) || 'list' !== $_POST['context'] ) {
				self::store_flash( array( 'wpllmml_msg' => rawurlencode( __( 'Translated with AI. Please review the text before publishing.', 'wp-llm-ml' ) ) ) );
			}
			$status['redirect'] = (string) get_edit_post_link( $post_id, 'raw' );
			$status['edit']     = (string) get_edit_post_link( $post_id, 'raw' );
			$status['editor']   = self::editor_url( $post_id );
			$status['preview']  = (string) get_preview_post_link( $post_id );
		}
		wp_send_json_success( $status );
	}

	/**
	 * Stop a running page translation (Cancel button, or the editor leaving the page).
	 */
	public function ajax_ai_post_cancel() {
		check_ajax_referer( 'wpllmml_ajax' );
		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		wp_send_json_success( array( 'cancelled' => Job::cancel( $post_id, $token ) ) );
	}

	/**
	 * Send a JSON success response and close the connection, then let PHP
	 * continue (PHP-FPM, LiteSpeed, or Content-Length + Connection: close).
	 *
	 * @param array $data Response data.
	 */
	private static function respond_and_continue( array $data ) {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$json = (string) wp_json_encode(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Length: ' . strlen( $json ) );
			header( 'Connection: close' );
			header( 'Cache-Control: no-store' );
		}
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			flush();
		}
	}

	/**
	 * List the provider's models (uses the saved key).
	 */
	public function ajax_models() {
		check_ajax_referer( 'wpllmml_ajax' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$models = ( new Client() )->models();
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( $models->get_error_message() );
		}
		wp_send_json_success( $models );
	}
}
