<?php
/**
 * Minimal client for OpenAI-compatible chat completion APIs.
 * Default provider: HostYourAI (EU hosted), https://hostyourai.com/api/v1.
 *
 * The API key can be set in wp-config.php (recommended):
 *     define( 'WPLLMML_AI_API_KEY', 'hyai-rt-...' );
 *
 * Nothing is sent anywhere unless a key and model are configured and an
 * editor explicitly asks for a translation or spell check.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Ai;

use WPLLMML\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Chat completions client.
 */
class Client {

	/**
	 * AI settings.
	 *
	 * @return array base_url, api_key, model, temperature, instructions
	 */
	public static function config() {
		$ai = (array) Settings::get( 'ai', array() );
		if ( defined( 'WPLLMML_AI_API_KEY' ) && WPLLMML_AI_API_KEY ) {
			$ai['api_key'] = WPLLMML_AI_API_KEY;
		}
		if ( defined( 'WPLLMML_AI_BASE_URL' ) && WPLLMML_AI_BASE_URL ) {
			$ai['base_url'] = WPLLMML_AI_BASE_URL;
		}
		return wp_parse_args(
			$ai,
			array(
				'base_url'     => 'https://hostyourai.com/api/v1',
				'api_key'      => '',
				'model'        => '',
				'temperature'  => 0.2,
				'instructions' => '',
			)
		);
	}

	/**
	 * Is the AI provider ready to use?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$c = self::config();
		return '' !== trim( (string) $c['api_key'] ) && '' !== trim( (string) $c['model'] ) && '' !== trim( (string) $c['base_url'] );
	}

	/**
	 * Run a chat completion.
	 *
	 * @param array $messages List of [role, content].
	 * @param array $options  temperature, max_tokens, timeout, abort (callable: true stops the request).
	 * @return string|\WP_Error Assistant message.
	 */
	public function chat( array $messages, array $options = array() ) {
		$c = self::config();
		if ( ! self::is_configured() ) {
			return new \WP_Error( 'wpllmml_ai_not_configured', __( 'The AI provider is not configured (API key and model are required).', 'wp-llm-ml' ) );
		}
		$body = array(
			'model'       => $c['model'],
			'messages'    => $messages,
			'temperature' => isset( $options['temperature'] ) ? (float) $options['temperature'] : (float) $c['temperature'],
			'stream'      => false,
		);
		if ( ! empty( $options['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $options['max_tokens'];
		}
		$endpoint = trailingslashit( $c['base_url'] ) . 'chat/completions';
		$hook     = null;
		if ( ! empty( $options['abort'] ) && is_callable( $options['abort'] ) && defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			// Lets a running request be stopped: cURL calls this about once a second
			// while it waits; the abort check itself runs at most every 2 seconds.
			$abort = $options['abort'];
			$hook  = static function ( $handle, $request, $url ) use ( $abort, $endpoint ) {
				if ( $url !== $endpoint ) {
					return;
				}
				$last = time();
				curl_setopt( $handle, CURLOPT_NOPROGRESS, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				curl_setopt( // phpcs:ignore WordPress.WP.AlternativeFunctions
					$handle,
					CURLOPT_XFERINFOFUNCTION,
					static function () use ( $abort, &$last ) {
						if ( time() - $last < 2 ) {
							return 0;
						}
						$last = time();
						return call_user_func( $abort ) ? 1 : 0;
					}
				);
			};
			add_action( 'http_api_curl', $hook, 10, 3 );
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => isset( $options['timeout'] ) ? (int) $options['timeout'] : 600,
				'headers' => array(
					'Authorization' => 'Bearer ' . $c['api_key'],
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( apply_filters( 'wpllmml_ai_request_body', $body, $messages ) ),
			)
		);
		if ( $hook ) {
			remove_action( 'http_api_curl', $hook, 10 );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status, 2: error message */
			return new \WP_Error( 'wpllmml_ai_http', sprintf( __( 'AI provider error (HTTP %1$d): %2$s', 'wp-llm-ml' ), $code, $message ) );
		}
		if ( ! isset( $data['choices'][0]['message']['content'] ) || ! is_string( $data['choices'][0]['message']['content'] ) ) {
			return new \WP_Error( 'wpllmml_ai_response', __( 'Unexpected response from the AI provider.', 'wp-llm-ml' ) );
		}
		return self::strip_reasoning( $data['choices'][0]['message']['content'] );
	}

	/**
	 * Remove <think>...</think> blocks some reasoning models emit.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_reasoning( $text ) {
		return trim( (string) preg_replace( '#<think>.*?</think>#s', '', $text ) );
	}

	/**
	 * Model ids offered by the provider.
	 *
	 * @return string[]|\WP_Error
	 */
	public function models() {
		$c = self::config();
		if ( '' === trim( (string) $c['api_key'] ) ) {
			return new \WP_Error( 'wpllmml_ai_not_configured', __( 'Enter an API key first.', 'wp-llm-ml' ) );
		}
		$response = wp_remote_get(
			trailingslashit( $c['base_url'] ) . 'models',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $c['api_key'],
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			/* translators: %d: HTTP status */
			return new \WP_Error( 'wpllmml_ai_http', sprintf( __( 'Could not list models (HTTP %d). Check the URL and API key.', 'wp-llm-ml' ), $code ) );
		}
		$ids = array();
		foreach ( $data['data'] as $model ) {
			if ( isset( $model['id'] ) ) {
				$ids[] = (string) $model['id'];
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Extract the first JSON array/object from a model reply.
	 *
	 * @param string $text  Reply.
	 * @param string $open  "[" or "{".
	 * @return mixed|null
	 */
	public static function extract_json( $text, $open = '[' ) {
		$text  = self::strip_reasoning( $text );
		$text  = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $text );
		$close = '[' === $open ? ']' : '}';
		$start = strpos( $text, $open );
		$end   = strrpos( $text, $close );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}
}
