<?php
/**
 * Updates from GitHub Releases through WordPress' own update system.
 *
 * The plugin header declares `Update URI: https://github.com/brnl-claude/wp-llm-ml`,
 * so WordPress never confuses it with a wordpress.org plugin and asks this
 * class (via the `update_plugins_github.com` filter) whether a newer version
 * exists. New releases then appear under Dashboard → Updates like any other
 * plugin update.
 *
 * The only request made is an anonymous GET of the latest release from the
 * public GitHub API, at most twice a day (cached). Disable it with
 *     define( 'WPLLMML_DISABLE_UPDATES', true );
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub release updater.
 */
class Updater {

	const REPO      = 'brnl-claude/wp-llm-ml';
	const ASSET     = 'wp-llm-ml.zip';
	const TRANSIENT = 'wpllmml_latest_release';
	const SLUG      = 'wp-llm-ml';

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( defined( 'WPLLMML_DISABLE_UPDATES' ) && WPLLMML_DISABLE_UPDATES ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 0 );
	}

	/**
	 * Latest published release (cached).
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null version, package, url, notes, published
	 */
	public function latest( $force = false ) {
		$cached = $force ? false : get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		$url      = (string) apply_filters( 'wpllmml_update_api_url', 'https://api.github.com/repos/' . self::REPO . '/releases/latest' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-llm-ml-updater',
				),
			)
		);
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			// Remember the failure for an hour so a GitHub outage never slows wp-admin.
			set_site_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return null;
		}
		$package = '';
		foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
				$package = $asset['browser_download_url'];
			}
		}
		$release = array(
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : 'https://github.com/' . self::REPO . '/releases',
			'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
		set_site_transient( self::TRANSIENT, $release, 12 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Answer WordPress' update check for this plugin.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( WPLLMML_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = $this->latest();
		if ( ! $release || '' === $release['package'] ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => '7.1',
			'requires'     => '6.5',
			'requires_php' => '7.4',
		);
	}

	/**
	 * "View details" popup in the plugins and updates screens.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = $this->latest();
		if ( ! $release ) {
			return $result;
		}
		$notes = '' !== $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '';
		return (object) array(
			'name'          => 'LLM Multilingual',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'wp-llm-ml contributors',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '6.5',
			'tested'        => '7.1',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => esc_html__( 'Lightweight multilingual plugin without upsells or tracking: one page per language, browser language detection, language switcher, hreflang, Elementor and GeneratePress support, optional AI translation.', 'wp-llm-ml' ),
				'changelog'   => $notes,
			),
		);
	}

	/**
	 * Re-check after an update was installed.
	 */
	public function forget() {
		delete_site_transient( self::TRANSIENT );
	}
}
