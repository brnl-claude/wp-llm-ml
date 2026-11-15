<?php
/**
 * Forminator integration: one copy of a form per language, translated with
 * AI, and the right copy shown automatically.
 *
 * Pages keep the default-language form (`[forminator_form id="2324"]`); on
 * /nl/ the plugin renders the Dutch copy instead. The copy is a normal
 * Forminator form ("Book consult (NL)"): its texts can be edited in
 * Forminator, its submissions and e-mails are its own, so the language of
 * a submission is visible.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Admin\Admin;
use WPLLMML\Ai\Client;
use WPLLMML\Ai\Fields;
use WPLLMML\Ai\Translator;
use WPLLMML\Languages;
use WPLLMML\Router;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Forminator forms per language.
 */
class Forminator {

	const META_COPIES = '_wpllmml_form_copies';
	const META_SOURCE = '_wpllmml_form_source';
	const META_LANG   = '_wpllmml_form_lang';
	const FORM_META   = 'forminator_form_meta';
	const POST_TYPE   = 'forminator_forms';

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * True while rendering a swapped shortcode.
	 *
	 * @var bool
	 */
	private $swapping = false;

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
		add_filter( 'pre_do_shortcode_tag', array( $this, 'swap_shortcode' ), 10, 4 );
		add_action( 'wp_ajax_wpllmml_form_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_wpllmml_form_status', array( $this, 'ajax_status' ) );
		add_action( 'admin_post_wpllmml_form_copy', array( $this, 'handle_copy' ) );
		add_action( 'before_delete_post', array( $this, 'forget_deleted' ) );
	}

	/**
	 * Is Forminator active?
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( '\Forminator_API' ) && class_exists( '\Forminator_Base_Form_Model' );
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Show a form's copy in the visitor's language.
	 *
	 * @param false|string $output Short-circuit output.
	 * @param string       $tag    Shortcode.
	 * @param array|string $attr   Attributes.
	 * @param array        $m      Regex match.
	 * @return false|string
	 */
	public function swap_shortcode( $output, $tag, $attr, $m ) {
		if ( false !== $output || 'forminator_form' !== $tag || $this->swapping || ! is_array( $attr ) || empty( $attr['id'] ) ) {
			return $output;
		}
		$lang = $this->language();
		if ( '' === $lang || Languages::default_code() === $lang ) {
			return $output;
		}
		$copy = self::copy_of( (int) $attr['id'], $lang );
		if ( ! $copy ) {
			return $output;
		}
		$attr['id'] = $copy;
		$parts      = array();
		foreach ( $attr as $key => $value ) {
			$parts[] = is_int( $key ) ? $value : $key . '="' . esc_attr( $value ) . '"';
		}
		$this->swapping = true;
		$output         = do_shortcode( '[forminator_form ' . implode( ' ', $parts ) . ']' );
		$this->swapping = false;
		return $output;
	}

	/**
	 * Language of what is being rendered: the request's, or in Elementor's
	 * editor requests the language of the page being edited.
	 *
	 * @return string
	 */
	private function language() {
		if ( wp_doing_ajax() && isset( $_REQUEST['editor_post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return Translations::get_lang( absint( $_REQUEST['editor_post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return '';
		}
		return (string) $this->router->lang;
	}

	/**
	 * The published copy of a form in a language.
	 *
	 * @param int    $form_id Form.
	 * @param string $lang    Language.
	 * @return int 0 when there is none.
	 */
	public static function copy_of( $form_id, $lang ) {
		$copies = get_post_meta( $form_id, self::META_COPIES, true );
		$copy   = is_array( $copies ) && isset( $copies[ $lang ] ) ? (int) $copies[ $lang ] : 0;
		return $copy && self::POST_TYPE === get_post_type( $copy ) && 'publish' === get_post_status( $copy ) ? $copy : 0;
	}

	/**
	 * A copy (published or not) of a form in a language.
	 *
	 * @param int    $form_id Form.
	 * @param string $lang    Language.
	 * @return int
	 */
	private static function any_copy( $form_id, $lang ) {
		$copies = get_post_meta( $form_id, self::META_COPIES, true );
		$copy   = is_array( $copies ) && isset( $copies[ $lang ] ) ? (int) $copies[ $lang ] : 0;
		return $copy && self::POST_TYPE === get_post_type( $copy ) ? $copy : 0;
	}

	/**
	 * A deleted copy is no longer used; a deleted source forgets its copies.
	 *
	 * @param int $post_id Post.
	 */
	public function forget_deleted( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$source = (int) get_post_meta( $post_id, self::META_SOURCE, true );
		if ( $source ) {
			$copies = get_post_meta( $source, self::META_COPIES, true );
			if ( is_array( $copies ) ) {
				update_post_meta( $source, self::META_COPIES, array_diff( $copies, array( (int) $post_id ) ) );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Copies
	 * ------------------------------------------------------------------ */

	/**
	 * Forms in the default language (not copies), by title.
	 *
	 * @return \WP_Post[]
	 */
	public static function source_forms() {
		$forms = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		return array_values(
			array_filter(
				$forms,
				static function ( $form ) {
					return ! get_post_meta( $form->ID, self::META_SOURCE, true );
				}
			)
		);
	}

	/**
	 * A form's name as Forminator shows it.
	 *
	 * @param int $form_id Form.
	 * @return string
	 */
	public static function name( $form_id ) {
		$meta = get_post_meta( $form_id, self::FORM_META, true );
		return is_array( $meta ) && ! empty( $meta['settings']['formName'] ) ? (string) $meta['settings']['formName'] : get_the_title( $form_id );
	}

	/**
	 * Create (or refresh) the copy of a form in a language.
	 *
	 * @param int    $source_id Form in the default language.
	 * @param string $lang      Target language.
	 * @param bool   $ai        Translate the texts with AI (else: plain copy).
	 * @param array  $args      should_continue, on_progress (for the AI).
	 * @return int|\WP_Error Copy id.
	 */
	public static function make_copy( $source_id, $lang, $ai = true, array $args = array() ) {
		if ( ! self::active() ) {
			return new \WP_Error( 'wpllmml_no_forminator', __( 'Forminator is not active.', 'wp-llm-ml' ) );
		}
		$source_id = (int) $source_id;
		if ( self::POST_TYPE !== get_post_type( $source_id ) || get_post_meta( $source_id, self::META_SOURCE, true ) ) {
			return new \WP_Error( 'wpllmml_invalid', __( 'This form cannot be translated.', 'wp-llm-ml' ) );
		}
		if ( ! Languages::exists( $lang ) || Languages::default_code() === $lang ) {
			return new \WP_Error( 'wpllmml_invalid_lang', __( 'Unknown language.', 'wp-llm-ml' ) );
		}
		$meta = get_post_meta( $source_id, self::FORM_META, true );
		if ( ! is_array( $meta ) ) {
			return new \WP_Error( 'wpllmml_invalid', __( 'This form cannot be translated.', 'wp-llm-ml' ) );
		}
		if ( $ai ) {
			$meta = self::translate_meta( $meta, Languages::default_code(), $lang, $args );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
		}

		$copy_id = self::any_copy( $source_id, $lang );
		if ( ! $copy_id ) {
			// Forminator's own clone: same fields, settings, styles and e-mails.
			\Forminator_API::initialize( true ); // Loads Forminator's admin classes.
			$model = \Forminator_Base_Form_Model::get_model( $source_id );
			if ( ! is_object( $model ) ) {
				return new \WP_Error( 'wpllmml_invalid', __( 'This form cannot be translated.', 'wp-llm-ml' ) );
			}
			$copy_id = function_exists( 'forminator_clone_form_model' ) ? forminator_clone_form_model( $model, $source_id ) : 0;
			if ( is_wp_error( $copy_id ) || ! $copy_id ) {
				return is_wp_error( $copy_id ) ? $copy_id : new \WP_Error( 'wpllmml_copy_failed', __( 'The form could not be copied.', 'wp-llm-ml' ) );
			}
			update_post_meta( $copy_id, self::META_SOURCE, $source_id );
			update_post_meta( $copy_id, self::META_LANG, $lang );
			$copies          = get_post_meta( $source_id, self::META_COPIES, true );
			$copies          = is_array( $copies ) ? $copies : array();
			$copies[ $lang ] = (int) $copy_id;
			update_post_meta( $source_id, self::META_COPIES, $copies );
		}

		// The copy gets the source's form with the translated texts.
		$name                         = self::name( $source_id ) . ' (' . strtoupper( $lang ) . ')';
		$meta['settings']['formName'] = $name;
		$meta['settings']['form_id']  = (int) $copy_id;
		update_post_meta( $copy_id, self::FORM_META, wp_slash( $meta ) );
		delete_post_meta( $copy_id, 'forminator_temp_form_meta' );
		wp_update_post(
			array(
				'ID'          => $copy_id,
				'post_title'  => sanitize_title( $name ), // Forminator keeps a slug here.
				'post_status' => get_post_status( $source_id ),
			)
		);
		do_action( 'wpllmml_form_translated', $copy_id, $source_id, $lang );
		return (int) $copy_id;
	}

	/**
	 * Translate a form's texts: labels, placeholders, descriptions, options
	 * (not their values), messages, button texts and e-mails. Codes such as
	 * {name-1} and {all_fields} stay.
	 *
	 * @param array  $meta Form data.
	 * @param string $from Source language.
	 * @param string $to   Target language.
	 * @param array  $args Translator args.
	 * @return array|\WP_Error
	 */
	public static function translate_meta( array $meta, $from, $to, array $args = array() ) {
		$found = array();
		foreach ( array( 'fields', 'settings', 'behaviors', 'notifications' ) as $part ) {
			if ( isset( $meta[ $part ] ) ) {
				self::collect( $meta[ $part ], '', array( $part ), $found );
			}
		}
		if ( ! $found ) {
			return $meta;
		}
		$texts  = wp_list_pluck( $found, 1 );
		$result = ( new Translator() )->translate_strings(
			$texts,
			$from,
			$to,
			array_merge(
				array(
					'max_chars' => 24000,
					'max_items' => 500,
				),
				$args
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( $found as $i => $item ) {
			$text = isset( $result[ $i ] ) ? self::keep_codes( $item[1], (string) $result[ $i ] ) : $item[1];
			$meta = self::set_path( $meta, $item[0], $text );
		}
		return $meta;
	}

	/**
	 * Which setting keys hold text for people.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_text_key( $key ) {
		if ( in_array( $key, array( 'formName', 'form_name', 'value', 'type', 'id', 'element_id', 'slug' ), true ) ) {
			return false;
		}
		return (bool) preg_match( '/(^|[_-])(label|placeholder|description|message|text|title|subtitle|subject|editor|variations|content|btn_left|btn_right)$/', (string) $key );
	}

	/**
	 * Gather translatable texts with their paths.
	 *
	 * @param mixed  $data  Data.
	 * @param string $key   Key of $data.
	 * @param array  $path  Path to $data.
	 * @param array  $found Collected [path, text].
	 */
	private static function collect( $data, $key, array $path, array &$found ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $value ) {
				self::collect( $value, (string) $k, array_merge( $path, array( $k ) ), $found );
			}
			return;
		}
		if ( is_string( $data ) && self::is_text_key( $key ) && Fields::is_text( $data ) ) {
			$found[] = array( $path, $data );
		}
	}

	/**
	 * Set a value at a path.
	 *
	 * @param array $data  Data.
	 * @param array $path  Path.
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function set_path( array $data, array $path, $value ) {
		$key = array_shift( $path );
		if ( ! $path ) {
			$data[ $key ] = $value;
		} elseif ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
			$data[ $key ] = self::set_path( $data[ $key ], $path, $value );
		}
		return $data;
	}

	/**
	 * Keep the original text when the translation lost one of Forminator's
	 * codes ({name-1}, {all_fields}...), which the e-mails depend on.
	 *
	 * @param string $original    Original.
	 * @param string $translation Translation.
	 * @return string
	 */
	public static function keep_codes( $original, $translation ) {
		preg_match_all( '/\{[a-z0-9_\-]+\}/i', $original, $codes );
		foreach ( array_unique( $codes[0] ) as $code ) {
			if ( false === strpos( $translation, $code ) ) {
				return $original;
			}
		}
		return $translation;
	}

	/* ------------------------------------------------------------------
	 * Admin: Settings → Languages → Forms
	 * ------------------------------------------------------------------ */

	/**
	 * Forminator's form editor.
	 *
	 * @param int $form_id Form.
	 * @return string
	 */
	public static function edit_url( $form_id ) {
		return admin_url( 'admin.php?page=forminator-cform-wizard&id=' . (int) $form_id );
	}

	/**
	 * The Forms tab.
	 */
	public static function render_tab() {
		$default = Languages::default_code();
		$targets = array_diff_key( Languages::all(), array( $default => true ) );
		$ai      = Client::is_configured();
		echo '<div class="wpllmml-forms">';
		echo '<p class="description" style="max-width:52rem">' . esc_html__( 'Each Forminator form can have a copy per language. Pages keep the form in the default language; visitors of a page in another language automatically see the copy in their language. The copy is a normal Forminator form: you can adjust its texts in Forminator, and its submissions and e-mails are its own, so you can see which language a visitor used. After changing the fields of the original form, use "Translate again" so the copies get the same fields.', 'wp-llm-ml' ) . '</p>';
		if ( ! $ai ) {
			echo '<p>' . esc_html__( 'Set up AI (tab AI) to translate the copies automatically. Without AI, "Create copy" makes an untranslated copy that you translate in Forminator.', 'wp-llm-ml' ) . '</p>';
		}
		$forms = self::source_forms();
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'No Forminator forms yet.', 'wp-llm-ml' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped wpllmml-forms__table"><thead><tr><th>' . esc_html__( 'Form', 'wp-llm-ml' ) . '</th>';
		foreach ( $targets as $lang ) {
			echo '<th>' . esc_html( $lang['name'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $forms as $form ) {
			printf(
				'<tr><td><strong><a href="%1$s">%2$s</a></strong><br><code>[forminator_form id="%3$d"]</code></td>',
				esc_url( self::edit_url( $form->ID ) ),
				esc_html( self::name( $form->ID ) ),
				(int) $form->ID
			);
			foreach ( $targets as $code => $lang ) {
				$copy = self::any_copy( $form->ID, $code );
				echo '<td class="wpllmml-forms__cell" data-form="' . (int) $form->ID . '" data-lang="' . esc_attr( $code ) . '">';
				if ( $copy ) {
					printf( '<a href="%1$s">%2$s</a>', esc_url( self::edit_url( $copy ) ), esc_html( self::name( $copy ) ) );
					if ( 'publish' !== get_post_status( $copy ) ) {
						echo ' <em>(' . esc_html__( 'draft: not shown', 'wp-llm-ml' ) . ')</em>';
					}
					if ( $ai ) {
						printf(
							'<br><button type="button" class="button-link wpllmml-form-ai" data-form="%1$d" data-lang="%2$s" data-again="1">%3$s</button>',
							(int) $form->ID,
							esc_attr( $code ),
							/* translators: %s: language name */
							esc_html( sprintf( __( 'Translate again from %s', 'wp-llm-ml' ), Languages::get( $default )['name'] ) )
						);
					}
				} elseif ( $ai ) {
					printf(
						'<button type="button" class="button wpllmml-form-ai" data-form="%1$d" data-lang="%2$s">%3$s</button>',
						(int) $form->ID,
						esc_attr( $code ),
						esc_html__( 'Create & translate with AI', 'wp-llm-ml' )
					);
				} else {
					printf(
						'<a class="button" href="%1$s">%2$s</a>',
						esc_url( Admin::action_url( 'wpllmml_form_copy', $form->ID, array( 'lang' => $code ) ) ),
						esc_html__( 'Create copy', 'wp-llm-ml' )
					);
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * May the current user manage Forminator forms?
	 *
	 * @return bool
	 */
	private static function can_manage() {
		$cap = function_exists( 'forminator_get_permission' ) ? forminator_get_permission( 'forminator-cform' ) : 'manage_options';
		return current_user_can( $cap ) && current_user_can( 'manage_options' );
	}

	/**
	 * Plain copy without AI.
	 */
	public function handle_copy() {
		$form_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'wpllmml_form_copy_' . $form_id );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$result = self::make_copy( $form_id, $lang, false );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( self::edit_url( $result ) );
		exit;
	}

	/**
	 * Storage key of a running translation.
	 *
	 * @param int    $form_id Form.
	 * @param string $lang    Language.
	 * @param string $token   Token.
	 * @return string
	 */
	private static function key( $form_id, $lang, $token ) {
		return 'wpllmml_form_' . md5( $form_id . '|' . $lang . '|' . $token );
	}

	/**
	 * Translate a form: answer at once, then translate in this request (the
	 * browser polls ajax_status()).
	 */
	public function ajax_start() {
		check_ajax_referer( 'wpllmml_ajax' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		if ( ! Client::is_configured() ) {
			wp_send_json_error( __( 'The AI provider is not configured (API key and model are required).', 'wp-llm-ml' ) );
		}
		$form_id = isset( $_POST['form'] ) ? absint( $_POST['form'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$token   = wp_generate_password( 20, false );
		$key     = self::key( $form_id, $lang, $token );
		set_transient(
			$key,
			array(
				'status' => 'running',
				'pulse'  => time(),
			),
			HOUR_IN_SECONDS
		);
		Admin::respond_and_continue( array( 'token' => $token ) );
		$result = self::make_copy( $form_id, $lang, true );
		set_transient(
			$key,
			is_wp_error( $result )
				? array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'status' => 'done',
					'copy'   => $result,
					'title'  => self::name( $result ),
					'edit'   => self::edit_url( $result ),
				),
			HOUR_IN_SECONDS
		);
		exit;
	}

	/**
	 * Progress of a form translation.
	 */
	public function ajax_status() {
		check_ajax_referer( 'wpllmml_ajax' );
		if ( ! self::can_manage() ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		$form_id = isset( $_POST['form'] ) ? absint( $_POST['form'] ) : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$state   = get_transient( self::key( $form_id, $lang, $token ) );
		if ( ! is_array( $state ) ) {
			$state = array(
				'status'  => 'error',
				'message' => __( 'This translation is no longer available. Start it again.', 'wp-llm-ml' ),
			);
		} elseif ( 'running' === $state['status'] && time() - (int) $state['pulse'] > 660 ) {
			$state = array(
				'status'  => 'error',
				'message' => __( 'The server stopped the translation, probably because of a time limit. Try again.', 'wp-llm-ml' ),
			);
		}
		wp_send_json_success( $state );
	}
}

