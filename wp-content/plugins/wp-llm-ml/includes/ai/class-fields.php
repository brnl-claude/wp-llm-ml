<?php
/**
 * Reads and writes the human-readable texts of a post: title, excerpt,
 * content, Yoast SEO fields and Elementor widget texts (classic and atomic
 * widgets). Used by the AI translator and the spell checker.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Text fields of a post.
 */
class Fields {

	/**
	 * Translatable Yoast SEO meta keys.
	 *
	 * @return string[]
	 */
	public static function seo_meta_keys() {
		return (array) apply_filters(
			'wpllmml_seo_meta_keys',
			array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'_yoast_wpseo_opengraph-title',
				'_yoast_wpseo_opengraph-description',
				'_yoast_wpseo_twitter-title',
				'_yoast_wpseo_twitter-description',
			)
		);
	}

	/**
	 * Is the post built with Elementor?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_elementor( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $post_id, '_elementor_data', true );
	}

	/**
	 * Collect texts.
	 *
	 * @param int $post_id Post id.
	 * @return array list of [kind, key, text]
	 */
	public static function collect( $post_id ) {
		$post  = get_post( $post_id );
		$items = array();
		if ( ! $post ) {
			return $items;
		}
		$items[] = array( 'post', 'post_title', $post->post_title );
		if ( '' !== $post->post_excerpt ) {
			$items[] = array( 'post', 'post_excerpt', $post->post_excerpt );
		}
		if ( self::is_elementor( $post_id ) ) {
			$data = self::elementor_data( $post_id );
			self::walk(
				$data,
				static function ( &$text ) use ( &$items ) {
					$items[] = array( 'elementor', '', $text );
				}
			);
		} elseif ( '' !== trim( $post->post_content ) ) {
			foreach ( self::split_content( $post->post_content ) as $i => $chunk ) {
				$items[] = array( 'content', (string) $i, $chunk );
			}
		}
		foreach ( self::seo_meta_keys() as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$items[] = array( 'meta', $key, $value );
			}
		}
		return $items;
	}

	/**
	 * Write texts back (same order as collect()).
	 *
	 * @param int   $post_id Post id.
	 * @param array $items   Items from collect() with updated texts.
	 * @return true|\WP_Error
	 */
	public static function save( $post_id, array $items ) {
		$postarr   = array( 'ID' => $post_id );
		$content   = array();
		$elementor = array();
		foreach ( $items as $item ) {
			list( $kind, $key, $text ) = $item;
			if ( 'post' === $kind ) {
				$postarr[ $key ] = $text;
			} elseif ( 'content' === $kind ) {
				$content[ (int) $key ] = $text;
			} elseif ( 'elementor' === $kind ) {
				$elementor[] = $text;
			} elseif ( 'meta' === $kind ) {
				update_post_meta( $post_id, $key, wp_slash( $text ) );
			}
		}
		if ( $content ) {
			ksort( $content );
			$postarr['post_content'] = implode( '', $content );
		}
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $elementor ) {
			$data = self::elementor_data( $post_id );
			self::walk(
				$data,
				static function ( &$text ) use ( &$elementor ) {
					if ( $elementor ) {
						$text = array_shift( $elementor );
					}
				}
			);
			self::save_elementor_data( $post_id, $data );
		}
		return true;
	}

	/**
	 * Decoded Elementor data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function elementor_data( $post_id ) {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Store Elementor data. Uses Elementor's document API when available, which
	 * also regenerates the plain-text post_content and the CSS cache.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Elements.
	 */
	public static function save_elementor_data( $post_id, array $data ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( $document && method_exists( $document, 'save' ) ) {
				$saved = $document->save( array( 'elements' => $data ) );
				if ( $saved ) {
					return;
				}
			}
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
	}

	/**
	 * Split block/HTML content into pieces of reasonable size (at block or
	 * paragraph boundaries), so long posts can be translated in batches.
	 *
	 * @param string $content Content.
	 * @param int    $max     Max chars per piece.
	 * @return string[]
	 */
	public static function split_content( $content, $max = 3000 ) {
		$parts  = preg_split( '/(?=<!-- wp:)|(?<=<\/p>)\s*(?=<p[\s>])/', $content );
		$pieces = array();
		$buffer = '';
		foreach ( (array) $parts as $part ) {
			if ( '' !== $buffer && strlen( $buffer ) + strlen( $part ) > $max ) {
				$pieces[] = $buffer;
				$buffer   = '';
			}
			$buffer .= $part;
		}
		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}
		return $pieces;
	}

	/**
	 * Visit every translatable text in Elementor data (by reference).
	 *
	 * @param array    $elements Elements (modified in place).
	 * @param callable $visit    function( string &$text ).
	 */
	public static function walk( array &$elements, callable $visit ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$type     = isset( $element['widgetType'] ) ? $element['widgetType'] : ( isset( $element['elType'] ) ? $element['elType'] : '' );
				$controls = self::text_controls( $type );
				foreach ( $element['settings'] as $key => &$value ) {
					if ( is_string( $value ) && isset( $controls[ $key ] ) && true === $controls[ $key ] ) {
						if ( self::is_text( $value ) ) {
							$visit( $value );
						}
					} elseif ( is_array( $value ) && isset( $controls[ $key ] ) && is_array( $controls[ $key ] ) ) {
						foreach ( $value as &$row ) {
							foreach ( $controls[ $key ] as $field ) {
								if ( is_array( $row ) && isset( $row[ $field ] ) && is_string( $row[ $field ] ) && self::is_text( $row[ $field ] ) ) {
									$visit( $row[ $field ] );
								}
							}
						}
						unset( $row );
					} elseif ( is_array( $value ) && isset( $value['$$type'] ) ) {
						self::walk_atomic( $key, $value, $visit );
					}
				}
				unset( $value );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $visit );
			}
		}
	}

	/**
	 * Atomic (Elementor 4 "V4") widget props: html / html-v3 values.
	 *
	 * @param string   $key   Setting key.
	 * @param array    $prop  Prop value (by reference).
	 * @param callable $visit Visitor.
	 */
	private static function walk_atomic( $key, array &$prop, callable $visit ) {
		if ( in_array( $key, array( 'tag', 'classes', 'link', 'attributes', 'id', '_cssid' ), true ) ) {
			return;
		}
		if ( 'html' === $prop['$$type'] && isset( $prop['value'] ) && is_string( $prop['value'] ) && self::is_text( $prop['value'] ) ) {
			$visit( $prop['value'] );
		} elseif ( 'html-v3' === $prop['$$type'] && isset( $prop['value']['content']['value'] ) && is_string( $prop['value']['content']['value'] ) && self::is_text( $prop['value']['content']['value'] ) ) {
			$visit( $prop['value']['content']['value'] );
		}
	}

	/**
	 * Does a value contain words (not a URL, number, colour or empty)?
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_text( $value ) {
		$plain = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $plain || preg_match( '#^(https?:|mailto:|tel:|/|\#|www\.)#i', $plain ) ) {
			return false;
		}
		return (bool) preg_match( '/\p{L}/u', $plain );
	}

	/**
	 * Text-type controls of an Elementor element: key => true, or repeater
	 * key => [field keys]. Read from Elementor itself when loaded.
	 *
	 * @param string $type Widget type or element type.
	 * @return array
	 */
	public static function text_controls( $type ) {
		static $cache = array();
		if ( isset( $cache[ $type ] ) ) {
			return $cache[ $type ];
		}
		$map      = array();
		$controls = null;
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->widgets_manager ) ) {
			$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $type );
			if ( ! $widget && isset( \Elementor\Plugin::$instance->elements_manager ) ) {
				$widget = \Elementor\Plugin::$instance->elements_manager->get_element_types( $type );
			}
			if ( $widget && method_exists( $widget, 'get_controls' ) ) {
				$controls = $widget->get_controls();
			}
		}
		$text_types = array( 'text', 'textarea', 'wysiwyg' );
		if ( is_array( $controls ) ) {
			foreach ( $controls as $name => $control ) {
				if ( self::is_excluded_key( $name ) || ! isset( $control['type'] ) ) {
					continue;
				}
				if ( in_array( $control['type'], $text_types, true ) ) {
					$map[ $name ] = true;
				} elseif ( 'repeater' === $control['type'] && ! empty( $control['fields'] ) ) {
					$fields = array();
					foreach ( $control['fields'] as $field_name => $field ) {
						$field_name = isset( $field['name'] ) ? $field['name'] : $field_name;
						if ( isset( $field['type'] ) && in_array( $field['type'], $text_types, true ) && ! self::is_excluded_key( $field_name ) ) {
							$fields[] = $field_name;
						}
					}
					if ( $fields ) {
						$map[ $name ] = $fields;
					}
				}
			}
		} else {
			// Fallback when Elementor is not loaded.
			foreach ( array( 'title', 'editor', 'text', 'description', 'title_text', 'description_text', 'button_text', 'testimonial_content', 'testimonial_job', 'caption', 'alert_title', 'alert_description', 'blockquote_content', 'inner_text', 'prefix', 'suffix', 'link_text' ) as $key ) {
				$map[ $key ] = true;
			}
			$map['tabs']      = array( 'tab_title', 'tab_content' );
			$map['icon_list'] = array( 'text' );
		}
		$cache[ $type ] = (array) apply_filters( 'wpllmml_elementor_text_controls', $map, $type );
		return $cache[ $type ];
	}

	/**
	 * Settings that hold code, ids or CSS rather than visible text.
	 *
	 * @param string $name Control name.
	 * @return bool
	 */
	private static function is_excluded_key( $name ) {
		// Includes *_name: names of people (testimonials) are not translated.
		return (bool) preg_match( '/^_|css|class|(^|_)id$|anchor|^html$|attribute|selector|custom_|(^|_)url$|^link$|_link$|shortcode|^api_|_key$|font|lottie|svg|(^|_)name$/', (string) $name );
	}
}
