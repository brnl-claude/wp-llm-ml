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
	 * found in, otherwise anywhere.
	 *
	 * The check reads plain text, but fields hold HTML: the fragment may be
	 * split by formatting (`<em>parts</em>”`), contain entities (`&#8221;`)
	 * or other white space. The plain-text position is mapped back to the
	 * HTML and only the part that differs is changed, so formatting stays.
	 *
	 * @param int         $post_id    Post id.
	 * @param string      $original   Wrong fragment (plain text).
	 * @param string      $suggestion Correction (plain text).
	 * @param int|null    $field      Field index from check_post().
	 * @param string|null $before     Text before the fragment (check_post()'s
	 *                                "before"), to pick the right occurrence.
	 * @return bool|\WP_Error True when applied, false when not found.
	 */
	public function apply( $post_id, $original, $suggestion, $field = null, $before = null ) {
		if ( '' === $original ) {
			return false;
		}
		$items = Fields::collect( $post_id );
		$order = array_keys( $items );
		if ( null !== $field && isset( $items[ (int) $field ] ) ) {
			$order = array_merge( array( (int) $field ), array_diff( $order, array( (int) $field ) ) );
		}
		foreach ( $order as $i ) {
			$html = self::replace_in_html( $items[ $i ][2], (string) $original, (string) $suggestion, $before );
			if ( null === $html ) {
				continue;
			}
			$items[ $i ][2] = $html;
			$saved          = Fields::save( $post_id, $items );
			return is_wp_error( $saved ) ? $saved : true;
		}
		return false;
	}

	/**
	 * Apply a plain-text correction to HTML.
	 *
	 * @param string      $html       Field text (HTML or plain).
	 * @param string      $original   Wrong fragment.
	 * @param string      $suggestion Correction.
	 * @param string|null $before     Text before the fragment, if known.
	 * @return string|null New text, null when the fragment is not there.
	 */
	public static function replace_in_html( $html, $original, $suggestion, $before = null ) {
		list( $plain, $starts, $ends ) = self::text_map( $html );
		$pos = self::find( $plain, $original, $before );
		if ( false === $pos ) {
			return null;
		}
		// Only the characters that differ are replaced (a comma is inserted,
		// a letter swapped), so tags and entities around them stay as they are.
		$a   = mb_str_split( $original );
		$b   = mb_str_split( $suggestion );
		$pre = 0;
		while ( $pre < count( $a ) && $pre < count( $b ) && $a[ $pre ] === $b[ $pre ] ) {
			++$pre;
		}
		$suf = 0;
		while ( $suf < count( $a ) - $pre && $suf < count( $b ) - $pre && $a[ count( $a ) - 1 - $suf ] === $b[ count( $b ) - 1 - $suf ] ) {
			++$suf;
		}
		$from   = $pos + strlen( implode( '', array_slice( $a, 0, $pre ) ) );
		$to     = $pos + strlen( $original ) - strlen( implode( '', array_slice( $a, count( $a ) - $suf ) ) );
		$insert = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), implode( '', array_slice( $b, $pre, count( $b ) - $pre - $suf ) ) );
		if ( $from < $to ) {
			$start   = $starts[ $from ];
			$end     = $ends[ $to - 1 ];
			$segment = substr( $html, $start, $end - $start );
			// Tags inside the replaced part are kept (after the new text).
			preg_match_all( '/<[^>]*>/', $segment, $tags );
			return substr( $html, 0, $start ) . $insert . implode( '', $tags[0] ) . substr( $html, $end );
		}
		// Only an insertion: right before the next character, or after the last.
		$at = $from < strlen( $plain ) ? $starts[ $from ] : $ends[ $from - 1 ];
		return substr( $html, 0, $at ) . $insert . substr( $html, $at );
	}

	/**
	 * Position of a fragment in plain text; with several occurrences, the
	 * one preceded by the given context.
	 *
	 * @param string      $plain    Plain text.
	 * @param string      $fragment Fragment.
	 * @param string|null $before   Text before it ("…" for shortened).
	 * @return int|false
	 */
	private static function find( $plain, $fragment, $before ) {
		$first = strpos( $plain, $fragment );
		if ( false === $first || null === $before || '' === $before ) {
			return $first;
		}
		$context = ltrim( (string) $before, '…' );
		for ( $pos = $first; false !== $pos; $pos = strpos( $plain, $fragment, $pos + 1 ) ) {
			if ( '' === $context || substr( substr( $plain, 0, $pos ), -strlen( $context ) ) === $context ) {
				return $pos;
			}
		}
		return $first;
	}

	/**
	 * A field's text as check_post() reads it (see plain()), with for every
	 * byte of it the place in the original text it came from.
	 *
	 * @param string $html Field text.
	 * @return array plain text, start offsets, end offsets (per plain byte).
	 */
	private static function text_map( $html ) {
		$html   = (string) $html;
		$plain  = '';
		$starts = array();
		$ends   = array();
		$length = strlen( $html );
		$breaks = array( '</p>', '<br>', '<br />', '</li>', '</h1>', '</h2>', '</h3>', '</h4>' );
		$space  = false;
		$i      = 0;
		while ( $i < $length ) {
			if ( '<' === $html[ $i ] ) {
				$close = strpos( $html, '>', $i );
				$next  = false === $close ? $length : $close + 1;
				if ( ! in_array( substr( $html, $i, $next - $i ), $breaks, true ) ) {
					$i = $next;
					continue;
				}
				$char = "\n";
			} elseif ( '&' === $html[ $i ] && preg_match( '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/Ai', $html, $m, 0, $i ) ) {
				$char = html_entity_decode( $m[0], ENT_QUOTES, 'UTF-8' );
				$next = $i + strlen( $m[0] );
			} else {
				$byte = ord( $html[ $i ] );
				$size = $byte < 0x80 ? 1 : ( 0xC0 === ( $byte & 0xE0 ) ? 2 : ( 0xE0 === ( $byte & 0xF0 ) ? 3 : ( 0xF0 === ( $byte & 0xF8 ) ? 4 : 1 ) ) );
				$char = substr( $html, $i, $size );
				$next = $i + $size;
			}
			if ( preg_match( '/^\s+$/u', $char ) ) {
				// White space collapses to one space, none at the start.
				if ( '' === $plain || $space ) {
					$i = $next;
					continue;
				}
				$char  = ' ';
				$space = true;
			} else {
				$space = false;
			}
			$bytes = strlen( $char );
			for ( $n = 0; $n < $bytes; $n++ ) {
				$starts[] = $i;
				$ends[]   = $next;
			}
			$plain .= $char;
			$i      = $next;
		}
		return array( $plain, $starts, $ends );
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
