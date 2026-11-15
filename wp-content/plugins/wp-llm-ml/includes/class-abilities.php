<?php
/**
 * WordPress Abilities API (WordPress 6.9+): lets AI agents and MCP clients
 * discover the site's languages and translations, and create or
 * machine-translate drafts with the same permission checks as the admin.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Ability registration.
 */
class Abilities {

	const CATEGORY = 'multilingual';

	/**
	 * Register hooks.
	 */
	public function boot() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Category.
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Multilingual', 'wp-llm-ml' ),
				'description' => __( 'Languages and translations of this site.', 'wp-llm-ml' ),
			)
		);
	}

	/**
	 * Abilities.
	 */
	public function register_abilities() {
		$post_id_schema = array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'ID of a post or page.',
				),
			),
			'required'   => array( 'post_id' ),
		);

		wp_register_ability(
			'wp-llm-ml/list-languages',
			array(
				'label'               => __( 'List site languages', 'wp-llm-ml' ),
				'description'         => __( 'Returns the languages this site is published in, the default language and the home page URL of each language.', 'wp-llm-ml' ),
				'category'            => self::CATEGORY,
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => static function () {
					return Rest::languages_payload();
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'public'      => true,
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'wp-llm-ml/get-translations',
			array(
				'label'               => __( 'Get translations of a post', 'wp-llm-ml' ),
				'description'         => __( 'Returns the language of a post or page and the ID, URL, title and status of each of its translations.', 'wp-llm-ml' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $post_id_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					$post_id = (int) $input['post_id'];
					if ( ! get_post( $post_id ) ) {
						return new \WP_Error( 'wpllmml_not_found', __( 'Post not found.', 'wp-llm-ml' ) );
					}
					return Rest::post_payload( $post_id );
				},
				'permission_callback' => static function ( $input ) {
					$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					return Translations::is_public( $post_id ) || current_user_can( 'edit_post', $post_id );
				},
				'meta'                => array(
					'public'      => true,
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'wp-llm-ml/create-translation',
			array(
				'label'               => __( 'Create a translation draft', 'wp-llm-ml' ),
				'description'         => __( 'Copies a post or page (including Elementor layout and SEO fields) into a new draft in another language and links it as its translation. Optionally machine-translates the draft with the configured AI provider.', 'wp-llm-ml' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'lang'      => array(
							'type' => 'string',
							'enum' => Languages::codes(),
						),
						'translate' => array(
							'type'        => 'boolean',
							'description' => 'Machine-translate the new draft with AI.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id', 'lang' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					$new_id = Duplicator::create( (int) $input['post_id'], (string) $input['lang'] );
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					$translated = false;
					if ( ! empty( $input['translate'] ) && Ai\Client::is_configured() ) {
						$result     = ( new Ai\Translator() )->translate_post( $new_id, Translations::get_lang( (int) $input['post_id'] ), (string) $input['lang'] );
						$translated = ! is_wp_error( $result );
					}
					return array(
						'post_id'    => $new_id,
						'status'     => get_post_status( $new_id ),
						'edit_url'   => get_edit_post_link( $new_id, 'raw' ),
						'translated' => $translated,
					);
				},
				'permission_callback' => static function ( $input ) {
					$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
					$type    = get_post_type_object( (string) get_post_type( $post_id ) );
					return $type && current_user_can( 'edit_post', $post_id ) && current_user_can( $type->cap->create_posts );
				},
				'meta'                => array(
					'public'      => true,
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);

		if ( Ai\Client::is_configured() ) {
			wp_register_ability(
				'wp-llm-ml/translate-text',
				array(
					'label'               => __( 'Translate text', 'wp-llm-ml' ),
					'description'         => __( 'Translates text or HTML between the site languages with the configured AI provider, keeping HTML tags intact.', 'wp-llm-ml' ),
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'text' => array( 'type' => 'string' ),
							'from' => array( 'type' => 'string' ),
							'to'   => array( 'type' => 'string' ),
						),
						'required'   => array( 'text', 'to' ),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static function ( $input ) {
						$from   = isset( $input['from'] ) && Languages::exists( $input['from'] ) ? $input['from'] : Languages::default_code();
						$result = ( new Ai\Translator() )->translate_strings( array( (string) $input['text'] ), $from, (string) $input['to'] );
						return is_wp_error( $result ) ? $result : array( 'text' => $result[0] );
					},
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'meta'                => array(
						'public'      => true,
						'annotations' => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		}
	}
}
