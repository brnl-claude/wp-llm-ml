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
	 * @param int   $post_id Post id.
	 * @param array $args    should_continue (callable, checked between AI
	 *                       requests), on_progress (callable: done, total parts).
	 * @return array|\WP_Error Findings: original, suggestion, explanation, and
	 *                         for showing them in context: before, after (the
	 *                         surrounding text), where (field label), field
	 *                         (index for apply()).
	 */
	public function check_post( $post_id, array $args = array() ) {
		$lang   = Languages::get( Translations::get_lang( $post_id ) );
		$items  = Fields::collect( $post_id );
		$texts  = array();
		$fields = array();
		$seen   = array();
		foreach ( $items as $i => $item ) {
			$plain = self::plain( $item[2] );
			if ( ! Fields::is_text( $plain ) || isset( $seen[ $plain ] ) ) {
				continue;
			}
			$seen[ $plain ] = true;
			$texts[]        = $plain;
			$fields[]       = $i;
		}
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
		$keys   = array();
		$chunks = array_chunk( $texts, 25, true );
		foreach ( $chunks as $n => $chunk ) {
			if ( ! empty( $args['should_continue'] ) && ! call_user_func( $args['should_continue'] ) ) {
				return new \WP_Error( 'wpllmml_cancelled', __( 'Cancelled.', 'wp-llm-ml' ) );
			}
			if ( ! empty( $args['on_progress'] ) ) {
				call_user_func( $args['on_progress'], $n, count( $chunks ), count( $texts ) );
			}
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
			$list = Client::extract_json( $reply, '[' );
			if ( ! is_array( $list ) ) {
				return new \WP_Error( 'wpllmml_ai_format', __( 'The AI reply could not be read.', 'wp-llm-ml' ) );
			}
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) || empty( $item['original'] ) || ! isset( $item['suggestion'] ) ) {
					continue;
				}
				$original   = (string) $item['original'];
				$suggestion = (string) $item['suggestion'];
				$id         = isset( $item['id'] ) ? (int) $item['id'] : -1;
				// Keep only findings that really occur in the text, once.
				$pos = isset( $texts[ $id ] ) ? strpos( $texts[ $id ], $original ) : false;
				$key = $id . "\0" . $original;
				if ( $original === $suggestion || false === $pos || isset( $keys[ $key ] ) ) {
					continue;
				}
				$keys[ $key ] = true;
				$found[]      = array(
					'original'    => $original,
					'suggestion'  => $suggestion,
					'explanation' => isset( $item['explanation'] ) ? (string) $item['explanation'] : '',
					'before'      => self::context_before( substr( $texts[ $id ], 0, $pos ) ),
					'after'       => self::context_after( substr( $texts[ $id ], $pos + strlen( $original ) ) ),
					'where'       => self::where( $items[ $fields[ $id ] ] ),
					'field'       => $fields[ $id ],
				);
			}
		}
		if ( ! empty( $args['on_progress'] ) ) {
			call_user_func( $args['on_progress'], count( $chunks ), count( $chunks ), count( $texts ) );
		}
		return $found;
	}

	/**
	 * A field's text as plain text (paragraphs and list items as new lines,
	 * then all white space collapsed).
	 *
	 * @param string $html Text.
	 * @return string
	 */
	private static function plain( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '</li>', '</h1>', '</h2>', '</h3>', '</h4>' ), "\n", (string) $html ) ), ENT_QUOTES ) ) );
	}

	/**
	 * The end of the text before a finding (about a sentence).
	 *
	 * @param string $text Text before.
	 * @return string
	 */
	private static function context_before( $text ) {
		$max = 110;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, -$max );
		$space = mb_strpos( $cut, ' ' );
		return '…' . ( false === $space ? $cut : mb_substr( $cut, $space + 1 ) );
	}

	/**
	 * The start of the text after a finding.
	 *
	 * @param string $text Text after.
	 * @return string
	 */
	private static function context_after( $text ) {
		$max = 110;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return ( false === $space ? $cut : mb_substr( $cut, 0, $space ) ) . '…';
	}

	/**
	 * Where a text is, for people: "Title", "Heading", "Meta description"...
	 *
	 * @param array $item Item from Fields::collect().
	 * @return string
	 */
	private static function where( array $item ) {
		list( $kind, $key ) = $item;
		if ( 'post' === $kind ) {
			return 'post_title' === $key ? __( 'Title', 'wp-llm-ml' ) : __( 'Excerpt', 'wp-llm-ml' );
		}
		if ( 'content' === $kind ) {
			return __( 'Content', 'wp-llm-ml' );
		}
		if ( 'meta' === $kind ) {
			$labels = array(
				'_yoast_wpseo_title'                 => __( 'SEO title', 'wp-llm-ml' ),
				'_yoast_wpseo_metadesc'              => __( 'Meta description', 'wp-llm-ml' ),
				'_yoast_wpseo_focuskw'               => __( 'Focus keyphrase', 'wp-llm-ml' ),
				'_yoast_wpseo_opengraph-title'       => __( 'Social title', 'wp-llm-ml' ),
				'_yoast_wpseo_opengraph-description' => __( 'Social description', 'wp-llm-ml' ),
				'_yoast_wpseo_twitter-title'         => __( 'X title', 'wp-llm-ml' ),
				'_yoast_wpseo_twitter-description'   => __( 'X description', 'wp-llm-ml' ),
			);
			return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		$type = isset( $item[3] ) ? (string) $item[3] : '';
		if ( '' !== $type && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type );
			if ( $widget && method_exists( $widget, 'get_title' ) && '' !== (string) $widget->get_title() ) {
				return (string) $widget->get_title();
			}
		}
		return '' !== $type ? ucwords( str_replace( array( '-', '_' ), ' ', preg_replace( '/^e-/', '', $type ) ) ) : 'Elementor';
	}

	/**
	 * Replace a fragment in the post's texts: first in the field it was
	 * found in, otherwise its first occurrence anywhere.
	 *
	 * @param int      $post_id    Post id.
	 * @param string   $original   Wrong fragment.
	 * @param string   $suggestion Correction.
	 * @param int|null $field      Field index from check_post().
	 * @return bool|\WP_Error True when applied, false when not found.
	 */
	public function apply( $post_id, $original, $suggestion, $field = null ) {
		if ( '' === $original ) {
			return false;
		}
		$items = Fields::collect( $post_id );
		$order = array_keys( $items );
		if ( null !== $field && isset( $items[ (int) $field ] ) ) {
			$order = array_merge( array( (int) $field ), array_diff( $order, array( (int) $field ) ) );
		}
		foreach ( $order as $i ) {
			$item = $items[ $i ];
			$from = $original;
			$to   = $suggestion;
			$pos  = strpos( $item[2], $from );
			if ( false === $pos ) {
				// Texts are HTML: try the entity-encoded form too.
				$from = esc_html( $original );
				$to   = esc_html( $suggestion );
				$pos  = strpos( $item[2], $from );
				if ( false === $pos ) {
					continue;
				}
			}
			$items[ $i ][2] = substr_replace( $item[2], $to, $pos, strlen( $from ) );
			$saved          = Fields::save( $post_id, $items );
			return is_wp_error( $saved ) ? $saved : true;
		}
		return false;
	}

	/* ------------------------------------------------------------------
	 * Background run: the check can take longer than a web server waits
	 * for one request, so the browser starts it, gets an answer at once
	 * and polls for the result.
	 * ------------------------------------------------------------------ */

	/**
	 * Storage key of a check.
	 *
	 * @param int    $post_id Post.
	 * @param string $token   Token.
	 * @return string
	 */
	private static function key( $post_id, $token ) {
		return 'wpllmml_spell_' . md5( (int) $post_id . '|' . $token );
	}

	/**
	 * Register a check.
	 *
	 * @param int $post_id Post.
	 * @return string Token.
	 */
	public static function start( $post_id ) {
		$token = wp_generate_password( 20, false );
		set_transient(
			self::key( $post_id, $token ),
			array(
				'status'  => 'running',
				'done'    => 0,
				'total'   => 0,
				'texts'   => 0,
				'started' => time(),
				'pulse'   => time(),
			),
			HOUR_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Run a registered check and store its result.
	 *
	 * @param int    $post_id Post.
	 * @param string $token   Token.
	 */
	public function run( $post_id, $token ) {
		$key   = self::key( $post_id, $token );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || 'running' !== $state['status'] ) {
			return;
		}
		$result = $this->check_post(
			$post_id,
			array(
				'should_continue' => static function () use ( $key ) {
					wp_cache_delete( $key, 'transient' );
					wp_cache_delete( '_transient_' . $key, 'options' );
					$now = get_transient( $key );
					return is_array( $now ) && 'running' === $now['status'];
				},
				'on_progress'     => static function ( $done, $total, $texts ) use ( $key, &$state ) {
					$state = array_merge(
						$state,
						array(
							'done'  => $done,
							'total' => $total,
							'texts' => $texts,
							'pulse' => time(),
						)
					);
					set_transient( $key, $state, HOUR_IN_SECONDS );
				},
			)
		);
		wp_cache_delete( $key, 'transient' );
		$now = get_transient( $key );
		if ( is_array( $now ) && 'cancelled' === $now['status'] ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			$state['status']  = 'error';
			$state['message'] = $result->get_error_message();
		} else {
			$state['status']   = 'done';
			$state['findings'] = $result;
		}
		set_transient( $key, $state, HOUR_IN_SECONDS );
	}

	/**
	 * State of a check (polled by the browser).
	 *
	 * @param int    $post_id Post.
	 * @param string $token   Token.
	 * @return array status (running|done|error|cancelled), done, total, texts, findings, message.
	 */
	public static function status( $post_id, $token ) {
		$state = get_transient( self::key( $post_id, $token ) );
		if ( ! is_array( $state ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'This check is no longer available. Start it again.', 'wp-llm-ml' ),
			);
		}
		// One AI request may take up to the client's time limit (600 s).
		if ( 'running' === $state['status'] && time() - (int) $state['pulse'] > 660 ) {
			$state['status']  = 'error';
			$state['message'] = __( 'The server stopped the check, probably because of a time limit. Try again.', 'wp-llm-ml' );
		}
		return $state;
	}

	/**
	 * Stop a check (the dialog was closed).
	 *
	 * @param int    $post_id Post.
	 * @param string $token   Token.
	 */
	public static function cancel( $post_id, $token ) {
		$key   = self::key( $post_id, $token );
		$state = get_transient( $key );
		if ( is_array( $state ) && 'running' === $state['status'] ) {
			$state['status'] = 'cancelled';
			set_transient( $key, $state, HOUR_IN_SECONDS );
		}
	}
}
