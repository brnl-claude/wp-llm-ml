<?php
/**
 * Seeds a test site that mirrors thelibrarywithin.com: GeneratePress,
 * Elementor pages (section/column layout with the widgets the live site
 * uses), Yoast SEO, a primary menu with a sub-menu, a static front page,
 * a blog page, posts in a category and a footer block widget.
 *
 * Run through tests/bin/wp-eval.php.
 *
 * @package WPLLMML
 */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'The Library Within', 'admin', 'admin@example.test', true, '', 'admin' );
	echo "WordPress installed\n";
}
wp_set_current_user( 1 );

switch_theme( 'generatepress' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogdescription', 'Therapy - Counselling - Coaching' );
foreach ( array( 'elementor/elementor.php', 'wordpress-seo/wp-seo.php', 'wp-llm-ml/wp-llm-ml.php' ) as $plugin ) {
	if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
		continue; // Package test: installed later from the zip.
	}
	$result = activate_plugin( $plugin, '', false, true );
	if ( is_wp_error( $result ) ) {
		echo 'Activation failed: ' . $plugin . ' ' . $result->get_error_message() . "\n";
	}
}
update_option( 'elementor_onboarded', true );
update_option( 'elementor_disable_color_schemes', 'yes' );
// Silent activation skips Elementor's installer: create its default kit.
if ( class_exists( '\Elementor\Core\Kits\Manager' ) && ! get_post( (int) get_option( 'elementor_active_kit' ) ) ) {
	delete_option( 'elementor_active_kit' );
	\Elementor\Core\Kits\Manager::create_default_kit();
}

$home = home_url( '/' );

/**
 * Elementor data with the widget types used on the live site.
 *
 * @param string $title Page title.
 * @param string $home  Home URL.
 * @return array
 */
function wpllmml_seed_elementor( $title, $home ) {
	$id = static function () {
		return substr( md5( uniqid( '', true ) ), 0, 7 );
	};
	return array(
		array(
			'id'       => $id(),
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'       => $title,
								'header_size' => 'h1',
							),
							'elements'   => array(),
						),
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'text-editor',
							'settings'   => array(
								'editor' => "<p>Welcome to \"{$title}\". I offer sessions in English, Romanian and Dutch.\nRead more <a href=\"{$home}gestalt-therapy/\">about Gestalt therapy</a> or see the <a href=\"{$home}privacy-policy/\" target=\"_blank\">privacy policy</a>.</p>",
							),
							'elements'   => array(),
						),
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'button',
							'settings'   => array(
								'text' => 'Book a consult',
								'link' => array(
									'url'         => $home . 'book-consult/',
									'is_external' => '',
									'nofollow'    => '',
								),
							),
							'elements'   => array(),
						),
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'icon-list',
							'settings'   => array(
								'icon_list' => array(
									array(
										'_id'           => $id(),
										'text'          => 'Online sessions',
										'selected_icon' => array(
											'value'   => 'fas fa-check',
											'library' => 'fa-solid',
										),
									),
									array(
										'_id'  => $id(),
										'text' => 'In person in Utrecht',
									),
								),
							),
							'elements'   => array(),
						),
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'toggle',
							'settings'   => array(
								'tabs' => array(
									array(
										'_id'         => $id(),
										'tab_title'   => 'What is Gestalt?',
										'tab_content' => '<p>Gestalt therapy focuses on awareness in the here and now.</p>',
									),
								),
							),
							'elements'   => array(),
						),
						array(
							'id'         => $id(),
							'elType'     => 'widget',
							'widgetType' => 'testimonial',
							'settings'   => array(
								'testimonial_content' => 'Adina helped me find calm again.',
								'testimonial_name'    => 'Maria',
								'testimonial_job'     => 'Client',
							),
							'elements'   => array(),
						),
					),
				),
			),
		),
	);
}

/**
 * Create or update a page.
 *
 * @param string $title     Title.
 * @param string $slug      Slug.
 * @param int    $parent    Parent.
 * @param bool   $elementor Build with Elementor.
 * @return int
 */
function wpllmml_seed_page( $title, $slug, $parent = 0, $elementor = true ) {
	$existing = get_page_by_path( $parent ? get_post_field( 'post_name', $parent ) . '/' . $slug : $slug );
	$id       = $existing ? $existing->ID : wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_parent'  => $parent,
			'post_content' => '<p>' . $title . ' — plain content.</p>',
		)
	);
	if ( $existing && 'publish' !== $existing->post_status ) {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
	}
	if ( $elementor ) {
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
		update_post_meta( $id, '_wp_page_template', 'elementor_header_footer' );
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( wpllmml_seed_elementor( $title, home_url( '/' ) ) ) ) );
		update_post_meta( $id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
	}
	update_post_meta( $id, '_yoast_wpseo_metadesc', 'About ' . $title . ': Gestalt therapy, counselling and coaching in English, Romanian and Dutch.' );
	update_post_meta( $id, '_generate-sidebar-layout-meta', 'no-sidebar' );
	return (int) $id;
}

$pages = array(
	'home'     => wpllmml_seed_page( 'Home', 'home' ),
	'gestalt'  => wpllmml_seed_page( 'Gestalt Therapy', 'gestalt-therapy' ),
	'coaching' => wpllmml_seed_page( 'Coaching', 'coaching' ),
	'gather'   => wpllmml_seed_page( 'Gatherings', 'gestalt-gatherings' ),
	'reiki'    => wpllmml_seed_page( 'Reiki', 'reiki' ),
	'adina'    => wpllmml_seed_page( 'About me', 'adina' ),
	'blog'     => wpllmml_seed_page( 'Blog', 'blog', 0, false ),
	'book'     => wpllmml_seed_page( 'Book consult', 'book-consult' ),
	'privacy'  => wpllmml_seed_page( 'Privacy Policy', 'privacy-policy', 0, false ),
	'terms'    => wpllmml_seed_page( 'Terms & Conditions', 'terms-conditions', 0, false ),
);
$pages['walks'] = wpllmml_seed_page( 'Silent Walks', 'silent-walks', $pages['gather'] );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $pages['home'] );
update_option( 'page_for_posts', $pages['blog'] );
update_option( 'wp_page_for_privacy_policy', $pages['privacy'] );

// Posts in a category.
$cat = term_exists( 'gestalt therapy', 'category' );
$cat = $cat ? (int) $cat['term_id'] : (int) wp_insert_term( 'gestalt therapy', 'category', array( 'slug' => 'gestalt-therapy' ) )['term_id'];
foreach ( array( 'support-in-gestalt-therapy' => 'That which enables', 'liminal-space' => 'The Liminal Space' ) as $slug => $title ) {
	if ( ! get_page_by_path( $slug, OBJECT, 'post' ) ) {
		wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_name'     => $slug,
				'post_content'  => "<!-- wp:paragraph -->\n<p>{$title}: a reflection on support. See <a href=\"{$home}coaching/\">coaching</a>.</p>\n<!-- /wp:paragraph -->",
				'post_category' => array( $cat ),
			)
		);
	}
}

// Primary menu.
$menu = wp_get_nav_menu_object( 'Primary' );
$menu_id = $menu ? $menu->term_id : wp_create_nav_menu( 'Primary' );
if ( ! wp_get_nav_menu_items( $menu_id ) ) {
	$add = static function ( $page_id, $parent = 0, $title = '' ) use ( $menu_id ) {
		return wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-object-id' => $page_id,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
				'menu-item-title'     => $title,
			)
		);
	};
	$add( $pages['home'] );
	$add( $pages['gestalt'] );
	$add( $pages['coaching'] );
	$parent = $add( $pages['gather'] );
	$add( $pages['walks'], $parent );
	$add( $pages['reiki'] );
	$add( $pages['adina'], 0, 'About me' );
	$add( $pages['blog'] );
	wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-type'   => 'custom',
			'menu-item-title'  => 'Contact',
			'menu-item-url'    => $home . 'book-consult/',
			'menu-item-status' => 'publish',
		)
	);
}
set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu_id ) );

// Footer bar block widget like the live site.
$blocks = get_option( 'widget_block', array() );
$blocks[33] = array( 'content' => '<!-- wp:paragraph --><p><a href="' . $home . 'terms-conditions">Terms &amp; Conditions</a> | <a href="' . $home . 'privacy-policy/">Privacy Policy</a></p><!-- /wp:paragraph -->' );
$blocks['_multiwidget'] = 1;
update_option( 'widget_block', $blocks );
$sidebars = get_option( 'sidebars_widgets', array() );
$sidebars['footer-bar'] = array( 'block-33' );
update_option( 'sidebars_widgets', $sidebars );

// Plugin: English (default), Dutch, Romanian; switcher in the primary menu.
if ( class_exists( 'WPLLMML\\Settings' ) ) {
	wpllmml_seed_settings();
}

/**
 * Plugin settings used by the tests.
 */
function wpllmml_seed_settings() {
	$settings                  = WPLLMML\Settings::all();
	$settings['languages']     = array(
		array( 'code' => 'en', 'locale' => 'en_GB', 'name' => 'English' ), // The owner prefers UK English.
		array( 'code' => 'nl', 'locale' => 'nl_NL', 'name' => 'Nederlands' ),
		array( 'code' => 'ro', 'locale' => 'ro_RO', 'name' => 'Română' ),
	);
	$settings['default']       = 'en';
	$settings['menu_location'] = 'primary';
	WPLLMML\Settings::save( $settings );
}

flush_rewrite_rules();
echo 'Seeded pages: ' . wp_json_encode( $pages ) . "\n";
