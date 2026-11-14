<?php
/**
 * AI translation of strings and whole posts (including Elementor layouts
 * and Yoast SEO fields).
 *
 * @package WPLLMML
 */

namespace WPLLMML\Ai;

use WPLLMML\Languages;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Translator.
 */
class Translator {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client|null $client Client (for tests).
	 */
	public function __construct( $client = null ) {
		$this->client = $client ? $client : new Client();
	}

	/**
	 * System prompt.
	 *
	 * @param string $from Source code.
	 * @param string $to   Target code.
	 * @return string
	 */
	private function prompt( $from, $to ) {
		$from_lang = Languages::get( $from );
		$to_lang   = Languages::get( $to );
		$from_name = $from_lang ? $from_lang['english'] : $from;
		$to_name   = $to_lang ? $to_lang['english'] : $to;
		$extra     = trim( (string) Client::config()['instructions'] );
		$prompt    = "You are a professional translator for a website. Translate every string in the JSON array from {$from_name} ({$from}) to {$to_name} ({$to}).\n"
			. "Rules:\n"
			. "- Return ONLY a JSON array of strings with exactly the same number of items, in the same order. No comments, no code fences.\n"
			. "- Keep HTML tags and attributes, HTML comments (such as <!-- wp:paragraph -->), shortcodes in square brackets, placeholders (%s, %1\$s, %%title%%, {{...}}), URLs, e-mail addresses and line breaks exactly as they are. Translate only human-readable text, including alt and title attribute values.\n"
			. "- Keep personal names, brand names and product names unchanged.\n"
			. "- Preserve tone, register and meaning; write natural, fluent {$to_name} as a native speaker would.\n";
		if ( '' !== $extra ) {
			$prompt .= "Site-specific instructions:\n" . $extra . "\n";
		}
		return (string) apply_filters( 'wpllmml_translation_prompt', $prompt, $from, $to );
	}

	/**
	 * Translate a list of strings.
	 *
	 * @param string[] $strings Texts.
	 * @param string   $from    Source language code.
	 * @param string   $to      Target language code.
	 * @return string[]|\WP_Error Same keys and order.
	 */
	public function translate_strings( array $strings, $from, $to ) {
		$strings = array_values( array_map( 'strval', $strings ) );
		$result  = $strings;
		$todo    = array();
		foreach ( $strings as $i => $text ) {
			if ( Fields::is_text( $text ) ) {
				$todo[ $i ] = $text;
			}
		}
		if ( ! $todo || $from === $to ) {
			return $result;
		}
		// Translate each distinct text once.
		$unique = array_values( array_unique( $todo ) );
		$done   = array();
		foreach ( $this->batches( $unique ) as $batch ) {
			$translated = $this->translate_batch( $batch, $from, $to );
			if ( is_wp_error( $translated ) ) {
				return $translated;
			}
			foreach ( $batch as $j => $source ) {
				$done[ $source ] = $translated[ $j ];
			}
		}
		foreach ( $todo as $i => $text ) {
			$result[ $i ] = isset( $done[ $text ] ) ? $done[ $text ] : $text;
		}
		return $result;
	}

	/**
	 * Split into batches of limited size.
	 *
	 * @param string[] $texts Texts.
	 * @return array[]
	 */
	private function batches( array $texts ) {
		$max_chars = (int) apply_filters( 'wpllmml_ai_batch_chars', 6000 );
		$batches   = array();
		$batch     = array();
		$size      = 0;
		foreach ( $texts as $text ) {
			if ( $batch && ( $size + strlen( $text ) > $max_chars || count( $batch ) >= 40 ) ) {
				$batches[] = $batch;
				$batch     = array();
				$size      = 0;
			}
			$batch[] = $text;
			$size   += strlen( $text );
		}
		if ( $batch ) {
			$batches[] = $batch;
		}
		return $batches;
	}

	/**
	 * Translate one batch; falls back to one-by-one when the reply is malformed.
	 *
	 * @param string[] $batch Texts.
	 * @param string   $from  Source.
	 * @param string   $to    Target.
	 * @return string[]|\WP_Error
	 */
	private function translate_batch( array $batch, $from, $to ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $this->prompt( $from, $to ),
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode( array_values( $batch ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			),
		);
		$reply    = $this->client->chat( $messages );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$decoded = Client::extract_json( $reply, '[' );
		if ( is_array( $decoded ) && count( $decoded ) === count( $batch ) && count( array_filter( $decoded, 'is_string' ) ) === count( $batch ) ) {
			return array_values( $decoded );
		}
		if ( 1 === count( $batch ) ) {
			// A single string answered as plain text is acceptable.
			$plain = trim( Client::strip_reasoning( $reply ) );
			if ( '' !== $plain && '[' !== substr( $plain, 0, 1 ) ) {
				return array( $plain );
			}
			return new \WP_Error( 'wpllmml_ai_format', __( 'The AI reply could not be read as a translation.', 'wp-llm-ml' ) );
		}
		$out = array();
		foreach ( $batch as $text ) {
			$single = $this->translate_batch( array( $text ), $from, $to );
			if ( is_wp_error( $single ) ) {
				return $single;
			}
			$out[] = $single[0];
		}
		return $out;
	}

	/**
	 * Translate a translation draft in place from its source language.
	 *
	 * @param int         $post_id Translation to fill.
	 * @param string|null $from    Source language (default: the default language).
	 * @param string|null $to      Target language (default: the post's language).
	 * @return true|\WP_Error
	 */
	public function translate_post( $post_id, $from = null, $to = null ) {
		$to   = $to ? $to : Translations::get_lang( $post_id );
		$from = $from ? $from : Languages::default_code();
		if ( $from === $to ) {
			return new \WP_Error( 'wpllmml_same_lang', __( 'Source and target language are the same.', 'wp-llm-ml' ) );
		}
		// Always translate from the current source text, not from earlier edits.
		$source = Translations::get_translation( $post_id, $from );
		if ( $source && $source !== (int) $post_id ) {
			$this->refresh_from_source( $source, $post_id );
		}

		$items      = Fields::collect( $post_id );
		$texts      = wp_list_pluck( $items, 2 );
		$translated = $this->translate_strings( $texts, $from, $to );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}
		foreach ( $items as $i => $item ) {
			$items[ $i ][2] = $translated[ $i ];
		}
		$saved = Fields::save( $post_id, $items );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Drafts get a slug in the target language (published URLs never change).
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) && $source && get_post_field( 'post_name', $source ) === $post->post_name ) {
			$slug = sanitize_title( $post->post_title );
			if ( '' !== $slug && (int) get_option( 'page_on_front' ) !== (int) $source ) {
				wp_update_post(
					array(
						'ID'        => $post_id,
						'post_name' => $slug,
					)
				);
			}
		}
		update_post_meta( $post_id, '_wpllmml_ai_translated', gmdate( 'c' ) );
		do_action( 'wpllmml_post_translated', $post_id, $from, $to );
		return true;
	}

	/**
	 * Copy the source's texts (title, content, Elementor layout, SEO fields)
	 * into the translation before translating, so it reflects the source.
	 *
	 * @param int $source_id Source post.
	 * @param int $target_id Translation.
	 */
	private function refresh_from_source( $source_id, $target_id ) {
		$source = get_post( $source_id );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $target_id,
					'post_title'   => $source->post_title,
					'post_excerpt' => $source->post_excerpt,
					'post_content' => $source->post_content,
				)
			)
		);
		foreach ( array_merge( array( '_elementor_data', '_elementor_edit_mode', '_elementor_page_settings' ), Fields::seo_meta_keys() ) as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' === $value || null === $value ) {
				delete_post_meta( $target_id, $key );
			} else {
				update_post_meta( $target_id, $key, wp_slash( $value ) );
			}
		}
	}
}
