<?php
/**
 * Layout sync: the default-language page leads the Elementor layout, its
 * translations keep only their own texts.
 *
 * When a page in the default language is saved in Elementor, every
 * Elementor translation of it is updated in the background:
 * - structure, order, styles and settings follow the source page;
 * - texts that are already translated stay as they are;
 * - new texts, and texts that changed in the source, are translated with AI
 *   (or stay in the source language when no AI is configured) and the
 *   translation is marked for review;
 * - settings changed on purpose in a translation (another form shortcode,
 *   image or link) and elements added only to the translation are kept.
 *
 * A translation can opt out ("keep its own layout") from the post lists.
 *
 * Elements are matched by their Elementor id, which translations created by
 * this plugin share with their source. A three-way comparison against the
 * source as it was at the previous sync (the "base") tells local changes in
 * the translation apart from changes in the source.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

use WPLLMML\Ai\Client;
use WPLLMML\Ai\Fields;
use WPLLMML\Ai\Job;
use WPLLMML\Ai\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Source → translation layout sync.
 */
class Sync {

	const HOOK        = 'wpllmml_sync_translations';
	const META_BASE   = '_wpllmml_sync_base';
	const META_REVIEW = '_wpllmml_review';
	const META_SYNCED = '_wpllmml_synced';
	const META_OFF    = '_wpllmml_sync_off';
	const META_RETRY  = '_wpllmml_sync_untranslated';
	const PENDING     = '_wpllmml_sync_pending';
	const META_ERROR  = '_wpllmml_sync_error';
	const LOCK        = 'wpllmml_sync_lock_';
	const ATTEMPTS    = '_wpllmml_sync_attempts';

	/**
	 * Seconds a queued sync may wait before the screens start it themselves
	 * (WP-Cron does not run on every host).
	 */
	const STALL_AFTER = 45;

	/**
	 * True while the sync itself saves a translation (not an editor's save).
	 *
	 * @var bool
	 */
	public static $writing = false;

	/**
	 * Source texts (md5 => true) that a previous sync could not translate:
	 * they are offered to the AI again even though the source kept them.
	 *
	 * @var array
	 */
	private static $retry = array();

	/**
	 * Translation being synced right now (for the fatal-error handler).
	 *
	 * @var int
	 */
	private static $current = 0;

	/**
	 * Register hooks.
	 */
	public function boot() {
		add_action( 'elementor/document/after_save', array( $this, 'on_elementor_save' ), 20, 2 );
		add_action( self::HOOK, array( $this, 'run_for_source' ) );
		add_action( 'wp_ajax_wpllmml_sync_run', array( $this, 'ajax_run' ) );
	}

	/**
	 * Run the queued sync of a source page in this request, after answering
	 * the browser. The editor and the post lists call this when a sync waits,
	 * so it does not depend on WP-Cron (whose loopback request fails on some
	 * hosts, for example behind a proxy or in a container).
	 */
	public function ajax_run() {
		check_ajax_referer( 'wpllmml_ajax' );
		$source_id = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;
		if ( ! $source_id || ! current_user_can( 'edit_post', $source_id ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'wp-llm-ml' ), 403 );
		}
		if ( ! self::is_source( $source_id ) ) {
			wp_send_json_success( array( 'started' => false ) );
		}
		Admin\Admin::respond_and_continue( array( 'started' => true ) );
		$this->run_for_source( $source_id );
		exit;
	}

	/**
	 * Settings that are per language by nature: a translation's own value is
	 * kept when it differs on purpose.
	 *
	 * @return string[]
	 */
	public static function per_language_keys() {
		return (array) apply_filters( 'wpllmml_sync_per_language_keys', array( 'shortcode', 'html', 'link', 'url', 'image', 'wp_gallery', 'video_url', 'youtube_url', 'vimeo_url', 'external_url' ) );
	}

	/**
	 * Page-level layout settings that follow the source page.
	 *
	 * @return string[]
	 */
	public static function layout_meta_keys() {
		return (array) apply_filters(
			'wpllmml_sync_meta_keys',
			array(
				'_elementor_page_settings',
				'_wp_page_template',
				'_generate-sidebar-layout-meta',
				'_generate-footer-widget-meta',
				'_generate-full-width-content',
				'_generate-disable-headline',
				'_generate-disable-header',
				'_generate-disable-nav',
				'_generate-disable-secondary-nav',
				'_generate-disable-top-bar',
				'_generate-disable-post-image',
				'_generate-disable-footer',
				'_generate-transparent-header',
			)
		);
	}

	/**
	 * Is this page the source (default language) of an Elementor group?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_source( $post_id ) {
		return Translations::is_translatable_type( (string) get_post_type( $post_id ) )
			&& Translations::get_lang( $post_id ) === Languages::default_code()
			&& Fields::is_elementor( $post_id )
			&& count( Translations::get_translations( $post_id ) ) > 1;
	}

	/**
	 * Does this translation follow its source's layout?
	 *
	 * @param int $target_id Translation.
	 * @return bool
	 */
	public static function follows( $target_id ) {
		return Fields::is_elementor( $target_id ) && ! get_post_meta( $target_id, self::META_OFF, true );
	}

	/**
	 * Turn following the source layout on or off for one translation.
	 *
	 * @param int  $target_id Translation.
	 * @param bool $on        Follow.
	 */
	public static function set_follow( $target_id, $on ) {
		if ( $on ) {
			delete_post_meta( $target_id, self::META_OFF );
			$source = Translations::get_translation( $target_id, Languages::default_code() );
			if ( $source && self::is_source( $source ) ) {
				self::schedule( $source );
			}
		} else {
			update_post_meta( $target_id, self::META_OFF, 1 );
			delete_post_meta( $target_id, self::PENDING );
			delete_post_meta( $target_id, self::META_ERROR );
		}
	}

	/**
	 * Sync state of a translation.
	 *
	 * @param int $target_id Translation.
	 * @return string running | queued | stalled (queued, nobody started it yet) | error | '' (up to date).
	 */
	public static function state( $target_id ) {
		if ( get_transient( self::LOCK . $target_id ) ) {
			return 'running';
		}
		$at = (int) get_post_meta( $target_id, self::PENDING, true );
		if ( $at ) {
			return time() - $at > self::STALL_AFTER ? 'stalled' : 'queued';
		}
		return self::error( $target_id ) ? 'error' : '';
	}

	/**
	 * Is a sync of this translation queued or running?
	 *
	 * @param int $target_id Translation.
	 * @return bool
	 */
	public static function is_pending( $target_id ) {
		return in_array( self::state( $target_id ), array( 'running', 'queued', 'stalled' ), true );
	}

	/**
	 * Why the last sync of a translation failed.
	 *
	 * @param int $target_id Translation.
	 * @return string Empty when it did not fail.
	 */
	public static function error( $target_id ) {
		$error = get_post_meta( $target_id, self::META_ERROR, true );
		return is_array( $error ) && ! empty( $error['message'] ) ? (string) $error['message'] : '';
	}

	/**
	 * Review marker of a translation: texts the sync changed since an editor
	 * last saved it.
	 *
	 * @param int $target_id Translation.
	 * @return array|null translated, untranslated, at.
	 */
	public static function review( $target_id ) {
		$review = get_post_meta( $target_id, self::META_REVIEW, true );
		return is_array( $review ) && ( ! empty( $review['translated'] ) || ! empty( $review['untranslated'] ) ) ? $review : null;
	}

	/**
	 * Elementor saved a document.
	 *
	 * @param \Elementor\Core\Base\Document $document Document.
	 * @param array                         $data     Saved data.
	 */
	public function on_elementor_save( $document, $data ) {
		if ( self::$writing || ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}
		if ( method_exists( $document, 'is_autosave' ) && $document->is_autosave() ) {
			return;
		}
		$post_id = (int) $document->get_main_id();
		if ( ! Translations::is_translatable_type( (string) get_post_type( $post_id ) ) ) {
			return;
		}
		if ( Translations::get_lang( $post_id ) !== Languages::default_code() ) {
			// An editor saved a translation: that counts as reviewed.
			delete_post_meta( $post_id, self::META_REVIEW );
			return;
		}
		if ( self::is_source( $post_id ) ) {
			self::schedule( $post_id );
		}
	}

	/**
	 * Queue a background sync for a source page and start it right away.
	 *
	 * @param int $source_id Source page.
	 * @param int $delay     Seconds to wait.
	 */
	public static function schedule( $source_id, $delay = 0 ) {
		foreach ( Translations::get_translations( $source_id ) as $id ) {
			if ( (int) $id !== (int) $source_id && self::follows( (int) $id ) ) {
				update_post_meta( (int) $id, self::PENDING, time() );
			}
		}
		if ( ! wp_next_scheduled( self::HOOK, array( (int) $source_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, array( (int) $source_id ) );
		}
		if ( ! $delay && ! ( defined( 'WPLLMML_SYNC_NO_SPAWN' ) && WPLLMML_SYNC_NO_SPAWN ) ) {
			spawn_cron();
		}
	}

	/**
	 * Cron: sync every Elementor translation of a source page.
	 *
	 * @param int $source_id Source page.
	 */
	public function run_for_source( $source_id ) {
		$source_id = (int) $source_id;
		if ( ! self::is_source( $source_id ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// This run takes over the queued cron event.
		wp_clear_scheduled_hook( self::HOOK, array( $source_id ) );
		$busy = false;
		// A second round picks up a source saved again while the first ran.
		for ( $round = 0; $round < 3; $round++ ) {
			$todo = array();
			foreach ( Translations::get_translations( $source_id ) as $target_id ) {
				$target_id = (int) $target_id;
				if ( $target_id !== $source_id && self::follows( $target_id ) && ( 0 === $round || get_post_meta( $target_id, self::PENDING, true ) ) ) {
					$todo[] = $target_id;
				}
			}
			if ( ! $todo ) {
				break;
			}
			foreach ( $todo as $target_id ) {
				$result = self::sync_translation( $source_id, $target_id );
				$busy   = $busy || ( is_wp_error( $result ) && 'wpllmml_sync_busy' === $result->get_error_code() );
			}
			if ( $busy ) {
				break;
			}
		}
		if ( $busy && ! wp_next_scheduled( self::HOOK, array( $source_id ) ) ) {
			// Another sync or an AI translation is busy with a translation: try again shortly.
			wp_schedule_single_event( time() + 60, self::HOOK, array( $source_id ) );
		}
	}

	/**
	 * Bring one translation in line with its source.
	 *
	 * @param int $source_id Source page.
	 * @param int $target_id Translation.
	 * @return array|\WP_Error Stats: translated (texts sent to AI), untranslated, kept.
	 */
	public static function sync_translation( $source_id, $target_id ) {
		if ( ! Fields::is_elementor( $target_id ) || ! Fields::is_elementor( $source_id ) ) {
			delete_post_meta( $target_id, self::PENDING );
			return new \WP_Error( 'wpllmml_sync_skip', 'Not an Elementor page.' );
		}
		$lock = self::LOCK . $target_id;
		if ( get_transient( $lock ) || Job::is_active( $target_id ) ) {
			return new \WP_Error( 'wpllmml_sync_busy', 'Busy.' );
		}
		// A run the server killed (no error, no shutdown) leaves its attempt
		// behind: after two of those, stop retrying and say so.
		$attempts = (int) get_post_meta( $target_id, self::ATTEMPTS, true );
		if ( $attempts >= 2 ) {
			delete_post_meta( $target_id, self::ATTEMPTS );
			self::fail( (int) $target_id, __( 'The server stopped the update twice before it finished, probably because of a time limit of the web server.', 'wp-llm-ml' ) );
			return new \WP_Error( 'wpllmml_sync_failed', 'Stopped twice.' );
		}
		update_post_meta( $target_id, self::ATTEMPTS, $attempts + 1 );
		set_transient( $lock, time(), 10 * MINUTE_IN_SECONDS );
		self::$current = (int) $target_id;
		static $handler = false;
		if ( ! $handler ) {
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
			$handler = true;
		}
		$user = get_current_user_id();
		try {
			$result = self::merge_and_save( (int) $source_id, (int) $target_id );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'wpllmml_sync_failed', $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
		}
		self::$writing = false;
		self::$retry   = array();
		wp_set_current_user( $user );
		self::$current = 0;
		delete_transient( $lock );
		delete_post_meta( $target_id, self::ATTEMPTS );
		if ( is_wp_error( $result ) && 'wpllmml_sync_unrelated' !== $result->get_error_code() ) {
			self::fail( (int) $target_id, $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Record why a sync failed, so the screens show it instead of waiting.
	 *
	 * @param int    $target_id Translation.
	 * @param string $message   Reason.
	 */
	private static function fail( $target_id, $message ) {
		update_post_meta(
			$target_id,
			self::META_ERROR,
			array(
				'message' => $message,
				'at'      => time(),
			)
		);
		delete_post_meta( $target_id, self::PENDING );
	}

	/**
	 * A fatal error (or time limit) ended the request during a sync.
	 */
	public static function on_shutdown() {
		if ( ! self::$current ) {
			return;
		}
		$error   = error_get_last();
		$message = $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true )
			? $error['message'] . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')'
			: __( 'The server stopped the update.', 'wp-llm-ml' );
		self::fail( self::$current, $message );
		delete_transient( self::LOCK . self::$current );
		delete_post_meta( self::$current, self::ATTEMPTS );
		self::$current = 0;
	}

	/**
	 * The sync itself (locked and guarded by sync_translation()).
	 *
	 * @param int $source_id Source page.
	 * @param int $target_id Translation.
	 * @return array|\WP_Error
	 */
	private static function merge_and_save( $source_id, $target_id ) {
		$source = Fields::elementor_data( $source_id );
		$target = Fields::elementor_data( $target_id );
		$raw    = get_post_meta( $target_id, self::META_BASE, true );
		$base   = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		$base   = is_array( $base ) ? $base : null;

		if ( null === $base && ! self::shares_layout( $source, $target ) ) {
			// Built separately (not copied from the source): its elements cannot
			// be matched, so syncing would replace it. It keeps its own layout.
			self::set_follow( $target_id, false );
			return new \WP_Error( 'wpllmml_sync_unrelated', 'The translation has its own layout.' );
		}

		$retry       = get_post_meta( $target_id, self::META_RETRY, true );
		self::$retry = is_array( $retry ) ? $retry : array();
		$pending     = array();
		$merged      = self::merge_elements( $source, $target, self::index( $target ), null === $base ? null : self::index( $base ), self::index( $source ), $pending );
		self::$retry = array();

		// Translate new and changed texts in one request (the page's context stays together).
		$from  = Languages::default_code();
		$to    = Translations::get_lang( $target_id );
		$texts    = array_values( $pending );
		$done     = $texts;
		$ai       = false;
		$ai_error = '';
		if ( $texts && Client::is_configured() ) {
			$result = ( new Translator() )->translate_strings( $texts, $from, $to, array( 'max_chars' => 24000, 'max_items' => 500 ) );
			if ( is_wp_error( $result ) ) {
				$ai_error = $result->get_error_message();
			} else {
				$done = $result;
				$ai   = true;
			}
		}
		$replace = array();
		foreach ( array_keys( $pending ) as $i => $token ) {
			$replace[ $token ] = $done[ $i ];
		}
		$merged = self::fill_tokens( $merged, $replace );

		// Save through Elementor (rebuilds CSS and the plain-text content) as
		// someone allowed to edit the page; in the background that is its
		// author. Nothing changed: no save, so no new revision.
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_set_current_user( (int) get_post_field( 'post_author', $target_id ) );
		}
		self::$writing = true;
		if ( $merged !== $target ) {
			Fields::save_elementor_data( $target_id, $merged );
		}
		foreach ( self::layout_meta_keys() as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' === $value || null === $value ) {
				delete_post_meta( $target_id, $key );
			} elseif ( get_post_meta( $target_id, $key, true ) !== $value ) {
				update_post_meta( $target_id, $key, wp_slash( $value ) );
			}
		}
		self::$writing = false;

		update_post_meta( $target_id, self::META_BASE, wp_slash( wp_json_encode( $source ) ) );
		update_post_meta( $target_id, self::META_SYNCED, time() );
		delete_post_meta( $target_id, self::META_ERROR );
		if ( Fields::elementor_data( $source_id ) === $source ) {
			delete_post_meta( $target_id, self::PENDING );
		} // Else the source was saved again meanwhile: stays queued for another round.
		if ( $texts && ! $ai ) {
			// Offer these to the AI again on the next sync.
			$retry = array();
			foreach ( $texts as $text ) {
				$retry[ md5( $text ) ] = true;
			}
			update_post_meta( $target_id, self::META_RETRY, $retry );
		} else {
			delete_post_meta( $target_id, self::META_RETRY );
		}
		if ( $texts ) {
			$review = get_post_meta( $target_id, self::META_REVIEW, true );
			$review = is_array( $review ) ? $review : array(
				'translated'   => 0,
				'untranslated' => 0,
			);
			$review['translated']   += $ai ? count( $texts ) : 0;
			$review['untranslated'] += $ai ? 0 : count( $texts );
			$review['at']            = time();
			if ( $ai_error ) {
				$review['error'] = $ai_error;
			} elseif ( $ai ) {
				unset( $review['error'] );
			}
			update_post_meta( $target_id, self::META_REVIEW, $review );
		}
		do_action( 'wpllmml_translation_synced', $target_id, $source_id, count( $texts ) );
		return array(
			'translated'   => $ai ? count( $texts ) : 0,
			'untranslated' => $ai ? 0 : count( $texts ),
		);
	}

	/**
	 * Remember the source layout a translation was made from (creation, full
	 * AI translation), so later syncs can tell its own changes apart.
	 *
	 * @param int $target_id Translation.
	 * @param int $source_id Source page.
	 */
	public static function set_base( $target_id, $source_id ) {
		if ( Fields::is_elementor( $source_id ) ) {
			update_post_meta( $target_id, self::META_BASE, wp_slash( wp_json_encode( Fields::elementor_data( $source_id ) ) ) );
		}
	}

	/**
	 * Was the translation copied from the source (most of its elements carry
	 * the source's element ids)?
	 *
	 * @param array $source Source elements.
	 * @param array $target Translation elements.
	 * @return bool
	 */
	public static function shares_layout( array $source, array $target ) {
		$t = self::index( $target );
		if ( ! $t ) {
			return true;
		}
		$shared = count( array_intersect_key( $t, self::index( $source ) ) );
		return $shared / count( $t ) >= 0.5;
	}

	/**
	 * Elements by id (all levels).
	 *
	 * @param array $elements Elements.
	 * @return array id => element
	 */
	private static function index( array $elements ) {
		$out = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$out[ $element['id'] ] = $element;
				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$out += self::index( $element['elements'] );
				}
			}
		}
		return $out;
	}

	/**
	 * Build the translation's new element tree: the source's structure with
	 * the translation's texts and local changes.
	 *
	 * @param array      $source      Source elements (this level).
	 * @param array|null $target_list Translation elements at the same level (null: none).
	 * @param array      $target      Translation elements by id (all levels).
	 * @param array|null $base        Source-at-last-sync elements by id (null: unknown).
	 * @param array      $source_ids  Source elements by id (all levels).
	 * @param array      $pending     Texts to translate: token => source text (filled in).
	 * @return array
	 */
	private static function merge_elements( array $source, $target_list, array $target, $base, array $source_ids, array &$pending ) {
		$out = array();
		foreach ( $source as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$id = isset( $element['id'] ) ? $element['id'] : '';
			$t  = ( '' !== $id && isset( $target[ $id ] ) ) ? $target[ $id ] : null;
			$b  = ( null !== $base && '' !== $id && isset( $base[ $id ] ) ) ? $base[ $id ] : null;
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$type                = isset( $element['widgetType'] ) ? $element['widgetType'] : ( isset( $element['elType'] ) ? $element['elType'] : '' );
				$element['settings'] = self::merge_settings(
					$type,
					$element['settings'],
					$t && isset( $t['settings'] ) && is_array( $t['settings'] ) ? $t['settings'] : null,
					null === $base ? null : ( $b && isset( $b['settings'] ) && is_array( $b['settings'] ) ? $b['settings'] : array() ),
					$pending
				);
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::merge_elements( $element['elements'], $t && isset( $t['elements'] ) && is_array( $t['elements'] ) ? $t['elements'] : null, $target, $base, $source_ids, $pending );
			}
			$out[] = $element;
		}
		if ( null !== $base && is_array( $target_list ) ) {
			$out = self::keep_local_additions( $out, $target_list, $base, $source_ids );
		}
		return $out;
	}

	/**
	 * Elements added only to the translation (in neither the source nor the
	 * base) stay, after the element they followed.
	 *
	 * @param array $out         Merged elements at this level.
	 * @param array $target_list Translation elements at this level.
	 * @param array $base        Base elements by id.
	 * @param array $source_ids  Source elements by id.
	 * @return array
	 */
	private static function keep_local_additions( array $out, array $target_list, array $base, array $source_ids ) {
		$after = null;
		foreach ( $target_list as $element ) {
			$id = is_array( $element ) && isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			if ( isset( $source_ids[ $id ] ) || isset( $base[ $id ] ) ) {
				if ( in_array( $id, array_column( $out, 'id' ), true ) ) {
					$after = $id;
				}
				continue;
			}
			$pos = 0;
			if ( null !== $after ) {
				$found = array_search( $after, array_column( $out, 'id' ), true );
				$pos   = false === $found ? count( $out ) : $found + 1;
			}
			array_splice( $out, $pos, 0, array( $element ) );
			$after = $id;
		}
		return $out;
	}

	/**
	 * Merge one element's settings.
	 *
	 * @param string     $type    Widget or element type.
	 * @param array      $s       Source settings now.
	 * @param array|null $t       Translation settings (null: element is new).
	 * @param array|null $b       Source settings at last sync (null: unknown).
	 * @param array      $pending Texts to translate.
	 * @return array
	 */
	private static function merge_settings( $type, array $s, $t, $b, array &$pending ) {
		$controls = Fields::text_controls( $type );
		$out      = array();
		$keys     = array_unique( array_merge( array_keys( $s ), null !== $t ? array_keys( $t ) : array() ) );
		foreach ( $keys as $key ) {
			$sv = array_key_exists( $key, $s ) ? $s[ $key ] : null;
			$tv = null !== $t && array_key_exists( $key, $t ) ? $t[ $key ] : null;
			$bv = null !== $b && array_key_exists( $key, $b ) ? $b[ $key ] : null;
			if ( null === $sv ) {
				// Only in the translation: a local addition is kept when we know the base.
				if ( null !== $b && null === $bv && null !== $tv ) {
					$out[ $key ] = $tv;
				}
				continue;
			}
			if ( isset( $controls[ $key ] ) && true === $controls[ $key ] ) {
				$out[ $key ] = self::merge_text( $sv, $tv, $bv, null !== $t, null !== $b, $pending );
			} elseif ( isset( $controls[ $key ] ) && is_array( $controls[ $key ] ) && is_array( $sv ) ) {
				$out[ $key ] = self::merge_repeater( $sv, is_array( $tv ) ? $tv : null, is_array( $bv ) ? $bv : null, $controls[ $key ], null !== $b, $pending );
			} elseif ( is_array( $sv ) && isset( $sv['$$type'] ) && in_array( $sv['$$type'], array( 'html', 'html-v3' ), true ) ) {
				$out[ $key ] = self::merge_atomic( $sv, $tv, $bv, null !== $t, null !== $b, $pending );
			} else {
				$out[ $key ] = self::merge_value( $key, $sv, $tv, $bv, null !== $t, null !== $b );
			}
		}
		return $out;
	}

	/**
	 * Repeater (toggle tabs, icon list items...): rows follow the source,
	 * matched by their _id.
	 *
	 * @param array      $s        Source rows.
	 * @param array|null $t        Translation rows.
	 * @param array|null $b        Base rows.
	 * @param string[]   $fields   Text fields in a row.
	 * @param bool       $has_base Base known.
	 * @param array      $pending  Texts to translate.
	 * @return array
	 */
	private static function merge_repeater( array $s, $t, $b, array $fields, $has_base, array &$pending ) {
		$by_id = static function ( $rows ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['_id'] ) ) {
					$out[ $row['_id'] ] = $row;
				}
			}
			return $out;
		};
		$t_rows = $by_id( $t );
		$b_rows = $by_id( $b );
		$out    = array();
		foreach ( $s as $row ) {
			if ( ! is_array( $row ) ) {
				$out[] = $row;
				continue;
			}
			$rid = isset( $row['_id'] ) ? $row['_id'] : null;
			$tr  = null !== $rid && isset( $t_rows[ $rid ] ) ? $t_rows[ $rid ] : null;
			$br  = null !== $rid && isset( $b_rows[ $rid ] ) ? $b_rows[ $rid ] : null;
			foreach ( $row as $field => $value ) {
				$tv = null !== $tr && array_key_exists( $field, $tr ) ? $tr[ $field ] : null;
				$bv = null !== $br && array_key_exists( $field, $br ) ? $br[ $field ] : null;
				if ( in_array( $field, $fields, true ) ) {
					$row[ $field ] = self::merge_text( $value, $tv, $bv, null !== $tr, $has_base && null !== $br, $pending );
				} elseif ( '_id' !== $field ) {
					$row[ $field ] = self::merge_value( $field, $value, $tv, $bv, null !== $tr, $has_base && null !== $br );
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Atomic (Elementor 4) html / html-v3 prop.
	 *
	 * @param array $s          Source prop.
	 * @param mixed $t          Translation prop.
	 * @param mixed $b          Base prop.
	 * @param bool  $has_target Element exists in the translation.
	 * @param bool  $has_base   Base known.
	 * @param array $pending    Texts to translate.
	 * @return array
	 */
	private static function merge_atomic( array $s, $t, $b, $has_target, $has_base, array &$pending ) {
		$get = static function ( $prop ) {
			if ( ! is_array( $prop ) || ! isset( $prop['$$type'] ) ) {
				return null;
			}
			if ( 'html' === $prop['$$type'] ) {
				return isset( $prop['value'] ) && is_string( $prop['value'] ) ? $prop['value'] : null;
			}
			return isset( $prop['value']['content']['value'] ) && is_string( $prop['value']['content']['value'] ) ? $prop['value']['content']['value'] : null;
		};
		$text = self::merge_text( $get( $s ), $get( $t ), $get( $b ), $has_target && null !== $get( $t ), $has_base, $pending );
		if ( 'html' === $s['$$type'] ) {
			$s['value'] = $text;
		} elseif ( isset( $s['value']['content']['value'] ) ) {
			$s['value']['content']['value'] = $text;
		}
		return $s;
	}

	/**
	 * A text: keep the translation unless the source text is new or changed.
	 *
	 * @param mixed $s          Source text.
	 * @param mixed $t          Translated text.
	 * @param mixed $b          Source text at last sync.
	 * @param bool  $has_target Element exists in the translation.
	 * @param bool  $has_base   Base known.
	 * @param array $pending    Texts to translate.
	 * @return mixed
	 */
	private static function merge_text( $s, $t, $b, $has_target, $has_base, array &$pending ) {
		if ( ! is_string( $s ) || ! Fields::is_text( $s ) ) {
			return $s;
		}
		$known = $has_target && is_string( $t ) && '' !== $t;
		// Keep the translation when the source text did not change since the
		// last sync (or, without history, when the text was translated at all),
		// unless an earlier sync had to leave it untranslated.
		$untranslated = $t === $s && isset( self::$retry[ md5( $s ) ] );
		if ( $known && ! $untranslated && ( $has_base ? $b === $s : $t !== $s ) ) {
			return $t;
		}
		$token             = "\x02wpllmml:" . count( $pending ) . "\x03";
		$pending[ $token ] = $s;
		return $token;
	}

	/**
	 * A setting that is not a text: follow the source, unless the
	 * translation changed it on purpose.
	 *
	 * @param string $key        Setting key.
	 * @param mixed  $s          Source value.
	 * @param mixed  $t          Translation value.
	 * @param mixed  $b          Base value.
	 * @param bool   $has_target Element exists in the translation.
	 * @param bool   $has_base   Base known.
	 * @return mixed
	 */
	private static function merge_value( $key, $s, $t, $b, $has_target, $has_base ) {
		if ( ! $has_target || null === $t ) {
			return $s;
		}
		$per_language = in_array( $key, self::per_language_keys(), true );
		if ( ! $has_base ) {
			// Without history only per-language settings may differ from the source.
			return ( $per_language && $t != $s ) ? $t : $s; // phpcs:ignore Universal.Operators.StrictComparisons
		}
		$local  = $t != $b; // phpcs:ignore Universal.Operators.StrictComparisons
		$remote = $s != $b; // phpcs:ignore Universal.Operators.StrictComparisons
		if ( $local && ( ! $remote || $per_language ) ) {
			return $t;
		}
		return $s;
	}

	/**
	 * Put the translated texts in place of their tokens.
	 *
	 * @param mixed $data    Element data.
	 * @param array $replace token => text.
	 * @return mixed
	 */
	private static function fill_tokens( $data, array $replace ) {
		if ( is_string( $data ) ) {
			return isset( $replace[ $data ] ) ? $replace[ $data ] : $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::fill_tokens( $v, $replace );
			}
		}
		return $data;
	}
}
