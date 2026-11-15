<?php
/**
 * One-time notice after the plugin was updated: "LLM Multilingual was
 * updated to x.y.z" with what's new (whats-new.json, written into the zip by
 * bin/build-zip.sh from CHANGELOG.md). Each editor sees it until they close
 * it; the next update shows it again with the new changes.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Admin;

use WPLLMML\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Update notice.
 */
class Update_Notice {

	const OPTION_VERSION = 'wpllmml_version';
	const OPTION_NOTICE  = 'wpllmml_update_notice';
	const USER_META      = 'wpllmml_update_notice_dismissed';

	/**
	 * Register hooks.
	 */
	public function boot() {
		add_action( 'admin_init', array( $this, 'track_version' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'wp_ajax_wpllmml_dismiss_update_notice', array( $this, 'ajax_dismiss' ) );
		add_action( 'admin_post_wpllmml_dismiss_update_notice', array( $this, 'dismiss_and_return' ) );
	}

	/**
	 * Is this a released version (not a development copy)?
	 *
	 * @return bool
	 */
	private static function is_release() {
		return (bool) preg_match( '/^\d+\.\d+\.\d+$/', WPLLMML_VERSION );
	}

	/**
	 * Remember the installed version; a change means the plugin was updated.
	 * A fresh installation shows no notice (activation stores the version).
	 */
	public function track_version() {
		if ( ! self::is_release() ) {
			return;
		}
		$stored = get_option( self::OPTION_VERSION );
		if ( WPLLMML_VERSION === $stored ) {
			return;
		}
		// No stored version but existing settings: updated from a version before this notice existed.
		$updated = false !== $stored || false !== get_option( Settings::OPTION );
		update_option( self::OPTION_VERSION, WPLLMML_VERSION, true );
		if ( $updated && ( false === $stored || version_compare( WPLLMML_VERSION, (string) $stored, '>' ) ) ) {
			update_option(
				self::OPTION_NOTICE,
				array(
					'version' => WPLLMML_VERSION,
					'from'    => false === $stored ? '' : (string) $stored,
				),
				true
			);
		}
	}

	/**
	 * Called on activation: a fresh install is not an update.
	 */
	public static function on_activate() {
		if ( self::is_release() && false === get_option( self::OPTION_VERSION ) && false === get_option( Settings::OPTION ) ) {
			add_option( self::OPTION_VERSION, WPLLMML_VERSION, '', true );
		}
	}

	/**
	 * What's new in the installed version.
	 *
	 * @return string[]
	 */
	public static function entries() {
		$file = WPLLMML_DIR . 'whats-new.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || ! isset( $data['version'], $data['entries'] ) || WPLLMML_VERSION !== $data['version'] ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) $data['entries'] ) ) );
	}

	/**
	 * The notice to show the current user, if any.
	 *
	 * @return array|null
	 */
	private static function pending() {
		$notice = get_option( self::OPTION_NOTICE );
		if ( ! is_array( $notice ) || empty( $notice['version'] ) || WPLLMML_VERSION !== $notice['version'] ) {
			return null;
		}
		if ( ! current_user_can( 'edit_pages' ) || get_user_meta( get_current_user_id(), self::USER_META, true ) === $notice['version'] ) {
			return null;
		}
		return $notice;
	}

	/**
	 * Minimal Markdown for changelog entries: `code` and **bold**.
	 *
	 * @param string $text Entry.
	 * @return string HTML.
	 */
	public static function format_entry( $text ) {
		$html = esc_html( $text );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		return (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html );
	}

	/**
	 * Print the notice.
	 */
	public function render() {
		$notice = self::pending();
		if ( ! $notice ) {
			return;
		}
		$entries = self::entries();
		$release = 'https://github.com/brnl-claude/wp-llm-ml/releases/tag/v' . rawurlencode( $notice['version'] );
		$close   = wp_nonce_url( add_query_arg( 'action', 'wpllmml_dismiss_update_notice', admin_url( 'admin-post.php' ) ), 'wpllmml_dismiss_update_notice' );
		?>
		<div class="notice notice-info is-dismissible wpllmml-update-notice" data-version="<?php echo esc_attr( $notice['version'] ); ?>">
			<p><strong>
				<?php
				/* translators: %s: version number */
				echo esc_html( sprintf( __( 'LLM Multilingual was updated to version %s.', 'wp-llm-ml' ), $notice['version'] ) );
				?>
			</strong>
			<?php if ( $entries ) : ?>
				<?php esc_html_e( "What's new:", 'wp-llm-ml' ); ?>
			<?php endif; ?>
			</p>
			<?php if ( $entries ) : ?>
				<ul class="wpllmml-update-notice__list" style="list-style:disc;margin:.2em 0 .6em 1.4em">
					<?php foreach ( $entries as $entry ) : ?>
						<li><?php echo self::format_entry( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in format_entry(). ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p>
				<a href="<?php echo esc_url( $release ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Release notes on GitHub', 'wp-llm-ml' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $close ); ?>" class="wpllmml-update-notice__close"><?php esc_html_e( 'Got it', 'wp-llm-ml' ); ?></a>
			</p>
		</div>
		<script>
		( function () {
			var notice = document.currentScript && document.currentScript.previousElementSibling;
			if ( ! notice || ! window.fetch ) {
				return;
			}
			function dismiss() {
				var body = new FormData();
				body.append( 'action', 'wpllmml_dismiss_update_notice' );
				body.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'wpllmml_dismiss_update_notice' ) ); ?> );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } );
			}
			notice.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.notice-dismiss' ) ) {
					dismiss();
				} else if ( e.target.closest( '.wpllmml-update-notice__close' ) ) {
					e.preventDefault();
					dismiss();
					notice.parentNode.removeChild( notice );
				}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Remember that the current user closed the notice.
	 */
	private static function dismiss() {
		$notice = get_option( self::OPTION_NOTICE );
		if ( is_array( $notice ) && ! empty( $notice['version'] ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, $notice['version'] );
		}
	}

	/**
	 * Close via AJAX (the × button or "Got it").
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'wpllmml_dismiss_update_notice' );
		self::dismiss();
		wp_send_json_success();
	}

	/**
	 * Close without JavaScript, then go back.
	 */
	public function dismiss_and_return() {
		check_admin_referer( 'wpllmml_dismiss_update_notice' );
		self::dismiss();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
