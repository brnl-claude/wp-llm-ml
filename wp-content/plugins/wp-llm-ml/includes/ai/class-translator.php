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
	 * @param array    $args    {
	 *     @type int      $max_chars       Characters per AI request (default 6000).
	 *     @type int      $max_items       Texts per AI request (default 40).
	 *     @type callable $should_continue Returns false to stop (checked before and during each request).
	 *     @type callable $on_progress     Receives (texts done, total texts).
	 * }
	 * @return string[]|\WP_Error Same keys and order.
	 */
	public function translate_strings( array $strings, $from, $to, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'max_chars'       => (int) apply_filters( 'wpllmml_ai_batch_chars', 6000 ),
				'max_items'       => 40,
				'should_continue' => null,
				'on_progress'     => null,
			)
		);
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
		$unique  = array_values( array_unique( $todo ) );
		$done    = array();
		$count   = 0;
		$proceed = $args['should_continue'];
		foreach ( $this->batches( $unique, $args['max_chars'], $args['max_items'] ) as $batch ) {
			if ( $proceed && ! call_user_func( $proceed ) ) {
				return self::cancelled();
			}
			$translated = $this->translate_batch( $batch, $from, $to, $proceed );
			if ( is_wp_error( $translated ) ) {
				return ( $proceed && ! call_user_func( $proceed ) ) ? self::cancelled() : $translated;
			}
			foreach ( $batch as $j => $source ) {
				$done[ $source ] = $translated[ $j ];
			}
			$count += count( $batch );
			if ( $args['on_progress'] ) {
				call_user_func( $args['on_progress'], $count, count( $unique ) );
			}
		}
		foreach ( $todo as $i => $text ) {
			$result[ $i ] = isset( $done[ $text ] ) ? $done[ $text ] : $text;
		}
		return $result;
	}

	/**
	 * The error returned when a translation was stopped.
	 *
	 * @return \WP_Error
	 */
	public static function cancelled() {
		return new \WP_Error( 'wpllmml_cancelled', __( 'The translation was stopped.', 'wp-llm-ml' ) );
	}

	/**
	 * Split into batches of limited size, never inside a text.
	 *
	 * @param string[] $texts     Texts.
	 * @param int      $max_chars Characters per batch.
	 * @param int      $max_items Texts per batch.
	 * @return array[]
	 */
	private function batches( array $texts, $max_chars, $max_items ) {
		$batches = array();
		$batch   = array();
		$size    = 0;
		foreach ( $texts as $text ) {
			if ( $batch && ( $size + strlen( $text ) > $max_chars || count( $batch ) >= $max_items ) ) {
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
	 * Translate one batch. When the reply cannot be read (for example because a
	 * long answer was cut off), the batch is halved and each half translated, so
	 * texts keep as much context as possible.
	 *
	 * @param string[]      $batch   Texts.
	 * @param string        $from    Source.
	 * @param string        $to      Target.
	 * @param callable|null $proceed Returns false to abort the request.
	 * @return string[]|\WP_Error
	 */
	private function translate_batch( array $batch, $from, $to, $proceed = null ) {
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
		$options  = array();
		if ( $proceed ) {
			$options['abort'] = static function () use ( $proceed ) {
				return ! call_user_func( $proceed );
			};
		}
		$reply = $this->client->chat( $messages, $options );
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
		$half  = (int) ceil( count( $batch ) / 2 );
		$first = $this->translate_batch( array_slice( $batch, 0, $half ), $from, $to, $proceed );
		if ( is_wp_error( $first ) ) {
			return $first;
		}
		$second = $this->translate_batch( array_slice( $batch, $half ), $from, $to, $proceed );
		if ( is_wp_error( $second ) ) {
			return $second;
		}
		return array_merge( $first, $second );
	}

	/**
	 * Translate a page or post into its language from the source version.
	 *
	 * The whole page goes to the AI in one request where it fits, so the
	 * translation keeps its context (references, terminology, tone); a very
	 * long page is split between texts, never inside one. Nothing is written
	 * until everything is translated: a stopped translation leaves the page as
	 * it was.
	 *
	 * @param int         $post_id Translation to fill.
	 * @param string|null $from    Source language (default: the default language).
	 * @param string|null $to      Target language (default: the post's language).
	 * @param array       $args    should_continue / on_progress, see translate_strings().
	 * @return true|\WP_Error
	 */
	public function translate_post( $post_id, $from = null, $to = null, array $args = array() ) {
		$to   = $to ? $to : Translations::get_lang( $post_id );
		$from = $from ? $from : Languages::default_code();
		if ( $from === $to ) {
			return new \WP_Error( 'wpllmml_same_lang', __( 'Source and target language are the same.', 'wp-llm-ml' ) );
		}
		$source = (int) Translations::get_translation( $post_id, $from );
		if ( $source === (int) $post_id ) {
			$source = 0;
		}
		// Read the texts from the source; the translation is only touched at the end.
		$items      = Fields::collect( $source ? $source : $post_id );
		$translated = $this->translate_strings(
			wp_list_pluck( $items, 2 ),
			$from,
			$to,
			array(
				'max_chars'       => (int) apply_filters( 'wpllmml_ai_page_chars', 24000 ),
				'max_items'       => 500,
				'should_continue' => isset( $args['should_continue'] ) ? $args['should_continue'] : null,
				'on_progress'     => isset( $args['on_progress'] ) ? $args['on_progress'] : null,
			)
		);
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}
		if ( ! empty( $args['should_continue'] ) && ! call_user_func( $args['should_continue'] ) ) {
			return self::cancelled();
		}
		foreach ( $items as $i => $item ) {
			$items[ $i ][2] = $translated[ $i ];
		}
		if ( $source ) {
			// Same layout and fields as the source, so the texts land in the right places.
			$this->refresh_from_source( $source, $post_id );
		}
		$saved = Fields::save( $post_id, $items );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Drafts get a slug in the target language (published URLs never change).
		$post = get_post( $post_id );
		if ( $post && $source && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) && get_post_field( 'post_name', $source ) === $post->post_name ) {
			$slug = sanitize_title( $post->post_title );
			if ( '' !== $slug && (int) get_option( 'page_on_front' ) !== $source ) {
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
