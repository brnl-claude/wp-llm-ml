<?php
/**
 * HTTP tests against the running test site (after test-model.php):
 * routing, redirects, SEO tags, menus, switcher, link localisation, query
 * filtering, REST API, Yoast sitemaps, browser detection script.
 *
 * Usage: php tests/integration/test-http.php http://localhost:8890 <ids.json>
 *
 * @package WPLLMML
 */

require_once __DIR__ . '/assert.php';

$base = rtrim( isset( $argv[1] ) ? $argv[1] : 'http://localhost:8890', '/' );

/**
 * GET without following redirects.
 *
 * @param string $url URL.
 * @return array [status, location, body]
 */
function fetch( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => 60,
		)
	);
	$raw    = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$size   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );
	$headers = substr( $raw, 0, $size );
	preg_match( '/^location:\s*(\S+)/im', $headers, $m );
	return array( $status, isset( $m[1] ) ? $m[1] : '', substr( $raw, $size ) );
}

/**
 * All matches of a regex group.
 *
 * @param string $re   Regex.
 * @param string $html HTML.
 * @param int    $g    Group.
 * @return array
 */
function all( $re, $html, $g = 1 ) {
	preg_match_all( $re, $html, $m );
	return $m[ $g ];
}

section( 'Routing and redirects' );
$cases = array(
	array( '/', 200, '' ),
	array( '/nl/', 200, '' ),
	array( '/ro/', 200, '' ),
	array( '/gestalt-therapy/', 200, '' ),
	array( '/nl/gestalt-therapie/', 200, '' ),
	array( '/nl/reiki/', 200, '' ),
	array( '/reiki/', 200, '' ),
	array( '/nl/bijeenkomsten/stille-wandelingen/', 200, '' ),
	array( '/gestalt-gatherings/silent-walks/', 200, '' ),
	array( '/nl/blog/', 200, '' ),
	array( '/nl/liminale-ruimte/', 200, '' ),
	array( '/nl/category/gestalt-therapy/', 200, '' ),
	array( '/nl/?s=liminal', 200, '' ),
	array( '/nl', 301, '/nl/' ),
	array( '/nl/gestalt-therapy/', 301, '/nl/gestalt-therapie/' ),
	array( '/gestalt-therapie/', 301, '/nl/gestalt-therapie/' ),
	array( '/nl/coaching/', 302, '/coaching/' ),
	array( '/ro/reiki/', 302, '/reiki/' ),
	array( '/nl/does-not-exist/', 404, '' ),
);
foreach ( $cases as $case ) {
	list( $path, $status, $location ) = $case;
	list( $got, $loc )                = fetch( $base . $path );
	check( sprintf( '%-40s %d %s', $path, $status, $location ), $got === $status && ( '' === $location || $base . $location === $loc ), "$got $loc" );
}

section( 'Dutch page: language signals' );
list( , , $nl ) = fetch( $base . '/nl/gestalt-therapie/' );
check( '<html lang="nl-NL">', false !== strpos( $nl, '<html lang="nl-NL"' ) );
check( 'canonical is the Dutch URL', false !== strpos( $nl, '<link rel="canonical" href="' . $base . '/nl/gestalt-therapie/"' ) );
$alts = array_combine( all( '/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', $nl ), all( '/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', $nl, 2 ) );
check( 'hreflang en + nl + x-default', array( 'en' => $base . '/gestalt-therapy/', 'nl' => $base . '/nl/gestalt-therapie/', 'x-default' => $base . '/gestalt-therapy/' ) === $alts, $alts );
check( 'og:locale nl_NL (Yoast)', false !== strpos( $nl, '<meta property="og:locale" content="nl_NL"' ) );
check( 'og:locale:alternate en_GB', false !== strpos( $nl, '<meta property="og:locale:alternate" content="en_GB"' ) );
check( 'schema inLanguage nl-NL (Yoast)', (bool) preg_match( '/"inLanguage":\s*"nl-NL"/', $nl ) );
check( 'site title translated in <title>', false !== strpos( $nl, '<title>Gestalttherapie - De Bibliotheek Binnenin</title>' ) );

section( 'Dutch page: menu, switcher, links' );
$nav  = preg_match( '#<nav[^>]*id="site-navigation".*?</nav>#s', $nl, $m ) ? $m[0] : '';
$menu = array_combine( array_map( 'trim', array_map( 'strip_tags', all( '#<a href="[^"]*"[^>]*>(.*?)</a>#s', $nav ) ) ), all( '#<a href="([^"]*)"#', $nav ) );
check( 'menu: home → /nl/', isset( $menu['Home NL'] ) && $base . '/nl/' === $menu['Home NL'], $menu );
check( 'menu: translated page title and URL', isset( $menu['Gestalttherapie'] ) && $base . '/nl/gestalt-therapie/' === $menu['Gestalttherapie'] );
check( 'menu: sub-menu item translated', isset( $menu['Stille wandelingen'] ) );
check( 'menu: untranslated draft keeps original', isset( $menu['Coaching'] ) && $base . '/coaching/' === $menu['Coaching'] );
check( 'menu: custom label translated via strings', isset( $menu['Over mij'] ) );
check( 'menu: custom link localised', isset( $menu['Contact'] ) && $base . '/nl/afspraak/' === $menu['Contact'] );
check( 'menu: current item marked', (bool) preg_match( '#current-menu-item[^>]*>\s*<a href="' . preg_quote( $base . '/nl/gestalt-therapie/', '#' ) . '"#', $nav ) );
$switch = array_combine( all( '#data-wpllmml-lang="([a-z]+)">#', $nav ), all( '#<a [^>]*href="([^"]+)"[^>]*data-wpllmml-lang#', $nav ) );
check( 'switcher: en → English page, ro → /ro/ home', array( 'nl' => $base . '/nl/gestalt-therapie/', 'en' => $base . '/gestalt-therapy/', 'ro' => $base . '/ro/' ) === $switch, $switch );
check( 'switcher: current language marked', (bool) preg_match( '#aria-current="true" hreflang="nl"#', $nav ) );
check( 'site title links to /nl/ and is translated', (bool) preg_match( '#class="main-title"[^>]*>\s*<a href="' . preg_quote( $base . '/nl/', '#' ) . '" rel="home">De Bibliotheek Binnenin</a>#', $nl ) );
check( 'Elementor text link localised', false !== strpos( $nl, 'href="' . $base . '/nl/gestalt-therapie/">about Gestalt therapy' ) );
check( 'Elementor button link localised', (bool) preg_match( '#elementor-button[^"]*"[^>]*href="' . preg_quote( $base . '/nl/afspraak/', '#' ) . '"|href="' . preg_quote( $base . '/nl/afspraak/', '#' ) . '"[^>]*elementor-button#', $nl ) );
check( 'link without translation unchanged', false !== strpos( $nl, 'href="' . $base . '/privacy-policy/"' ) );
check( 'nl-only widget shown in nl', false !== strpos( $nl, 'NL-ONLY-WIDGET' ) );
check( 'detection script present, no redirect from nl', false !== strpos( $nl, 'id="wpllmml-detect"' ) && false !== strpos( $nl, '"redirect":false' ) );

section( 'English pages unchanged' );
list( , , $en ) = fetch( $base . '/gestalt-therapy/' );
check( '<html lang="en-GB"> (UK English variant)', false !== strpos( $en, '<html lang="en-GB"' ) );
check( 'og:locale en_GB (Yoast)', false !== strpos( $en, '<meta property="og:locale" content="en_GB"' ) );
check( 'hreflang stays language-only "en"', false !== strpos( $en, '<link rel="alternate" hreflang="en" href="' . $base . '/gestalt-therapy/"' ) );
check( 'English title', false !== strpos( $en, '<title>Gestalt Therapy - The Library Within</title>' ) );
check( 'English Elementor link untouched', false !== strpos( $en, 'href="' . $base . '/book-consult/"' ) );
check( 'nl-only widget hidden in en', false === strpos( $en, 'NL-ONLY-WIDGET' ) );
check( 'menu label untranslated in en', false !== strpos( $en, '>About me<' ) );
list( , , $home ) = fetch( $base . '/' );
check( 'home: redirect allowed on default home', false !== strpos( $home, '"redirect":true' ) );
check( 'home: hreflang for en, nl, ro', 3 === count( all( '/<link rel="alternate" hreflang="(en|nl|ro)"/', $home ) ) );

section( 'Lists filtered by language' );
list( , , $blog_nl ) = fetch( $base . '/nl/blog/' );
check( 'nl blog shows nl post', false !== strpos( $blog_nl, 'De liminale ruimte' ) );
check( 'nl blog hides en posts', false === strpos( $blog_nl, 'That which enables' ) && false === strpos( $blog_nl, '>The Liminal Space<' ) );
list( , , $blog_en ) = fetch( $base . '/blog/' );
check( 'en blog hides nl post', false === strpos( $blog_en, 'De liminale ruimte' ) && false !== strpos( $blog_en, 'That which enables' ) );
list( , , $cat_nl ) = fetch( $base . '/nl/category/gestalt-therapy/' );
check( 'nl category archive: nl post only', false !== strpos( $cat_nl, 'De liminale ruimte' ) && false === strpos( $cat_nl, 'That which enables' ) );
check( 'category name translated', false !== stripos( $cat_nl, 'gestalttherapie' ) );
list( , , $search ) = fetch( $base . '/nl/?s=liminal' );
check( 'nl search: nl post only', false !== strpos( $search, 'De liminale ruimte' ) && false === strpos( $search, '>The Liminal Space<' ) );
list( , , $post_nl ) = fetch( $base . '/nl/liminale-ruimte/' );
check( 'no previous/next link to English posts', false === strpos( $post_nl, 'That which enables' ) );

section( 'REST API' );
list( , , $json ) = fetch( $base . '/wp-json/wp/v2/pages?lang=nl&per_page=100' );
$pages            = json_decode( $json, true );
$langs            = array_unique( array_map( static function ( $p ) { return $p['wpllmml']['lang']; }, (array) $pages ) );
check( '?lang=nl returns only nl pages', array( 'nl' ) === array_values( $langs ), $langs );
list( , , $json ) = fetch( $base . '/nl/wp-json/wp/v2/posts' );
$posts            = json_decode( $json, true );
check( '/nl/wp-json/ prefix filters posts', 1 === count( (array) $posts ) && 'nl' === $posts[0]['wpllmml']['lang'] );
check( 'translations field lists en version', isset( $posts[0]['wpllmml']['translations']['en']['link'] ) );
list( , , $json ) = fetch( $base . '/wp-json/wpllmml/v1/languages' );
$languages        = json_decode( $json, true );
check( 'languages endpoint', 3 === count( (array) $languages ) && 'en' === $languages[0]['code'] && $languages[0]['default'] );
list( $status, , $json ) = fetch( $base . '/wp-json/wp/v2/pages?lang=xx' );
check( 'invalid lang rejected', 400 === $status );

section( 'Yoast sitemaps' );
list( , , $xml ) = fetch( $base . '/page-sitemap.xml' );
check( 'sitemap contains Dutch URL', false !== strpos( $xml, '<loc>' . $base . '/nl/gestalt-therapie/</loc>' ) );
check( 'sitemap excludes draft translation', false === strpos( $xml, '/nl/coaching/' ) );
check( 'sitemap declares xhtml namespace', false !== strpos( $xml, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"' ) );
check( 'sitemap has hreflang alternates', false !== strpos( $xml, '<xhtml:link rel="alternate" hreflang="nl" href="' . $base . '/nl/gestalt-therapie/" />' ) );
check( 'sitemap is valid XML', false !== @simplexml_load_string( $xml ) );

finish();
