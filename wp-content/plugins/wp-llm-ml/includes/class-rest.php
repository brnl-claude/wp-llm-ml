<?php
/**
 * REST API: language and translations on every translatable post, a `lang`
 * filter on collections, and /wp-json/wpllmml/v1/languages. Lets headless
 * clients, AI agents and crawlers discover all language versions.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * REST integration.
 */
class Rest {

	/**
	 * Router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Constructor.
	 *
	 * @param Router $router Router.
	 */
	public function __construct( Router $router ) {
		$this->router = $router;
	}

	/**
	 * Register hooks.
	 */
	public function boot() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register fields, params and routes.
	 */
	public function register() {
		foreach ( Translations::post_types() as $type ) {
			register_rest_field(
				$type,
				'wpllmml',
				array(
					'get_callback' => array( $this, 'get_field' ),
					'schema'       => array(
						'description' => __( 'Language and translations of the post.', 'wp-llm-ml' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit', 'embed' ),
						'readonly'    => true,
					),
				)
			);
			add_filter( "rest_{$type}_collection_params", array( $this, 'collection_params' ) );
			add_filter( "rest_{$type}_query", array( $this, 'collection_query' ), 10, 2 );
		}
		register_rest_route(
			'wpllmml/v1',
			'/languages',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( self::languages_payload() );
				},
			)
		);
	}

	/**
	 * Public description of the configured languages.
	 *
	 * @return array
	 */
	public static function languages_payload() {
		$router = Plugin::instance()->router;
		$out    = array();
		foreach ( Languages::all() as $code => $lang ) {
			$out[] = array(
				'code'     => $code,
				'locale'   => $lang['locale'],
				'name'     => $lang['name'],
				'english'  => $lang['english'],
				'hreflang' => Languages::hreflang( $code ),
				'default'  => Languages::default_code() === $code,
				'home'     => $router->language_home( $code ),
			);
		}
		return $out;
	}

	/**
	 * The `wpllmml` field.
	 *
	 * @param array $data Prepared post.
	 * @return array
	 */
	public function get_field( $data ) {
		return self::post_payload( (int) $data['id'] );
	}

	/**
	 * Language and translations of a post; unpublished translations only for editors.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function post_payload( $post_id ) {
		$translations = array();
		foreach ( Translations::get_translations( $post_id ) as $code => $id ) {
			if ( (int) $id === (int) $post_id ) {
				continue;
			}
			$public = Translations::is_public( $id );
			if ( ! $public && ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$translations[ $code ] = array(
				'id'     => (int) $id,
				'link'   => get_permalink( $id ),
				'title'  => get_the_title( $id ),
				'status' => get_post_status( $id ),
			);
		}
		return array(
			'lang'         => Translations::get_lang( $post_id ),
			'translations' => (object) $translations,
		);
	}

	/**
	 * Add the `lang` parameter to collections.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	public function collection_params( $params ) {
		$params['lang'] = array(
			'description' => __( 'Limit results to one language code, or "all".', 'wp-llm-ml' ),
			'type'        => 'string',
			'enum'        => array_merge( Languages::codes(), array( 'all' ) ),
		);
		return $params;
	}

	/**
	 * Apply the `lang` parameter (or the /xx/ prefix of the request).
	 *
	 * @param array            $args    Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function collection_query( $args, $request ) {
		$lang = $request->get_param( 'lang' );
		if ( ! $lang && Languages::default_code() !== $this->router->lang && 'rest' === $this->router->context ) {
			$lang = $this->router->lang;
		}
		if ( $lang ) {
			$args['wpllmml_lang'] = $lang;
		}
		return $args;
	}
}
