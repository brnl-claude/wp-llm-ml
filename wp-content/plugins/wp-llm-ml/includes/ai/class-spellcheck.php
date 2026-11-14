<?php
/**
 * AI spelling and grammar advice for a post in its own language, with the
 * option to apply a suggestion.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Ai;

use WPLLMML\Languages;
use WPLLMML\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Spell checker.
 */
class Spellcheck {

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
	 * Check a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|\WP_Error list of [original, suggestion, explanation]
	 */
	public function check_post( $post_id ) {
		$lang  = Languages::get( Translations::get_lang( $post_id ) );
		$texts = array();
		foreach ( Fields::collect( $post_id ) as $item ) {
			$plain = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '</li>', '</h1>', '</h2>', '</h3>', '</h4>' ), "\n", $item[2] ) ), ENT_QUOTES ) ) );
			if ( Fields::is_text( $plain ) ) {
				$texts[] = $plain;
			}
		}
		$texts = array_values( array_unique( $texts ) );
		if ( ! $texts ) {
			return array();
		}
		$admin_lang = Languages::catalog();
		$user_code  = strtolower( substr( get_user_locale(), 0, 2 ) );
		$explain_in = isset( $admin_lang[ $user_code ] ) ? $admin_lang[ $user_code ]['english'] : 'English';
		$name       = $lang ? $lang['english'] : 'the text\'s language';

		$system = "You are a meticulous proofreader for {$name}. Check the numbered texts for spelling mistakes, typos, grammar and punctuation errors.\n"
			. "Return ONLY a JSON array (no code fences). Each item: {\"id\": <text id>, \"original\": <the erroneous fragment copied verbatim from the text, a few words long>, \"suggestion\": <the corrected fragment>, \"explanation\": <one short sentence in {$explain_in}>}.\n"
			. "Report only real errors, not stylistic preferences. Names and deliberate terminology are not errors. Return [] when there are no errors.";

		$found  = array();
		$chunks = array_chunk( $texts, 25, true );
		foreach ( $chunks as $chunk ) {
			$payload = array();
			foreach ( $chunk as $i => $text ) {
				$payload[] = array(
					'id'   => $i,
					'text' => $text,
				);
			}
			$reply = $this->client->chat(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					),
				),
				array( 'temperature' => 0 )
			);
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$items = Client::extract_json( $reply, '[' );
			if ( ! is_array( $items ) ) {
				return new \WP_Error( 'wpllmml_ai_format', __( 'The AI reply could not be read.', 'wp-llm-ml' ) );
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['original'] ) || ! isset( $item['suggestion'] ) ) {
					continue;
				}
				$original   = (string) $item['original'];
				$suggestion = (string) $item['suggestion'];
				$id         = isset( $item['id'] ) ? (int) $item['id'] : -1;
				// Keep only findings that really occur in the text.
				if ( $original === $suggestion || ! isset( $texts[ $id ] ) || false === strpos( $texts[ $id ], $original ) ) {
					continue;
				}
				$found[] = array(
					'original'    => $original,
					'suggestion'  => $suggestion,
					'explanation' => isset( $item['explanation'] ) ? (string) $item['explanation'] : '',
				);
			}
		}
		return $found;
	}

	/**
	 * Replace the first occurrence of a fragment in the post's texts.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $original   Wrong fragment.
	 * @param string $suggestion Correction.
	 * @return bool|\WP_Error True when applied, false when not found.
	 */
	public function apply( $post_id, $original, $suggestion ) {
		if ( '' === $original ) {
			return false;
		}
		$items = Fields::collect( $post_id );
		foreach ( $items as $i => $item ) {
			$pos = strpos( $item[2], $original );
			if ( false === $pos ) {
				// Texts are HTML: try the entity-encoded form too.
				$encoded = esc_html( $original );
				$pos     = strpos( $item[2], $encoded );
				if ( false === $pos ) {
					continue;
				}
				$original   = $encoded;
				$suggestion = esc_html( $suggestion );
			}
			$items[ $i ][2] = substr_replace( $item[2], $suggestion, $pos, strlen( $original ) );
			$saved          = Fields::save( $post_id, $items );
			return is_wp_error( $saved ) ? $saved : true;
		}
		return false;
	}
}
