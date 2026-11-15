<?php
/**
 * AI tests against tests/fixtures/mock-ai.php (run inside WordPress via
 * tests/bin/wp-eval.php, after test-model.php). Set WPLLMML_MOCK_AI to the
 * mock's base URL, e.g. http://localhost:8891/v1.
 *
 * @package WPLLMML
 */

use WPLLMML\Ai\Client;
use WPLLMML\Ai\Fields;
use WPLLMML\Ai\Job;
use WPLLMML\Ai\Spellcheck;
use WPLLMML\Ai\Translator;
use WPLLMML\Duplicator;
use WPLLMML\Settings;
use WPLLMML\Translations;

require_once __DIR__ . '/assert.php';
// Elementor saves below would start a background layout sync; test-sync.php covers that.
define( 'WPLLMML_SYNC_NO_SPAWN', true );
wp_set_current_user( 1 );

$settings       = Settings::all();
$settings['ai'] = array(
	'base_url'     => getenv( 'WPLLMML_MOCK_AI' ) ? getenv( 'WPLLMML_MOCK_AI' ) : 'http://localhost:8891/v1',
	'api_key'      => 'test-key',
	'model'        => 'mock-model',
	'temperature'  => 0.2,
	'instructions' => 'Keep the word Gestalt.',
);
Settings::save( $settings );

section( 'Client' );
check( 'configured', Client::is_configured() );
check( 'models listed', array( 'mock-large', 'mock-model' ) === ( new Client() )->models() );
$settings['ai']['api_key'] = 'wrong';
Settings::save( $settings );
$error = ( new Client() )->chat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );
check( 'HTTP error reported with provider message', is_wp_error( $error ) && false !== strpos( $error->get_error_message(), 'Invalid API key' ), is_wp_error( $error ) ? $error->get_error_message() : $error );
$settings['ai']['api_key'] = 'test-key';
Settings::save( $settings );
check( 'extract_json handles fences', array( 'a' ) === Client::extract_json( "```json\n[\"a\"]\n```" ) );

section( 'translate_strings' );
$tr  = new Translator();
$out = $tr->translate_strings( array( 'Hello', '', 'https://example.com/', 'Hello', '<p>Hi <a href="/x">there</a></p>' ), 'en', 'nl' );
check( 'texts translated, empties/URLs untouched, duplicates reused', array( 'NL:Hello', '', 'https://example.com/', 'NL:Hello', 'NL:<p>Hi <a href="/x">there</a></p>' ) === $out, $out );

section( 'translate_post on an Elementor page' );
$gestalt = get_page_by_path( 'gestalt-therapy' )->ID;
$old_ro  = Translations::get_translation( $gestalt, 'ro' );
if ( $old_ro ) {
	wp_delete_post( $old_ro, true );
	Translations::flush();
}
$ro = Duplicator::create( $gestalt, 'ro' );
check( 'ro draft created', is_int( $ro ) );
$result = $tr->translate_post( $ro, 'en', 'ro' );
check( 'translate_post succeeded', true === $result, is_wp_error( $result ) ? $result->get_error_message() : $result );
$post = get_post( $ro );
check( 'title translated', 'RO:Gestalt Therapy' === $post->post_title, $post->post_title );
check( 'slug follows translated title (draft)', 'rogestalt-therapy' === $post->post_name || 'ro-gestalt-therapy' === $post->post_name, $post->post_name );
$data     = Fields::elementor_data( $ro );
$widgets  = $data[0]['elements'][0]['elements'];
$settings_by_type = array();
foreach ( $widgets as $w ) {
	$settings_by_type[ $w['widgetType'] ] = $w['settings'];
}
check( 'heading translated', 'RO:Gestalt Therapy' === $settings_by_type['heading']['title'] );
check( 'heading size untouched', 'h1' === $settings_by_type['heading']['header_size'] );
check( 'text editor translated with HTML intact', 0 === strpos( $settings_by_type['text-editor']['editor'], 'RO:<p>Welcome to "Gestalt Therapy"' ) && false !== strpos( $settings_by_type['text-editor']['editor'], 'target="_blank"' ) );
check( 'button text translated, link untouched', 'RO:Book a consult' === $settings_by_type['button']['text'] && home_url( '/book-consult/' ) === $settings_by_type['button']['link']['url'] );
check( 'icon list items translated, icons untouched', 'RO:Online sessions' === $settings_by_type['icon-list']['icon_list'][0]['text'] && 'fas fa-check' === $settings_by_type['icon-list']['icon_list'][0]['selected_icon']['value'] );
check( 'toggle repeater translated', 'RO:What is Gestalt?' === $settings_by_type['toggle']['tabs'][0]['tab_title'] && 0 === strpos( $settings_by_type['toggle']['tabs'][0]['tab_content'], 'RO:<p>' ) );
check( 'testimonial translated, person name kept', 'RO:Adina helped me find calm again.' === $settings_by_type['testimonial']['testimonial_content'] && 'Maria' === $settings_by_type['testimonial']['testimonial_name'] );
check( 'element ids unchanged', $data[0]['id'] === Fields::elementor_data( $gestalt )[0]['id'] );
check( 'Yoast description translated', 0 === strpos( get_post_meta( $ro, '_yoast_wpseo_metadesc', true ), 'RO:About' ) );
check( 'source page untouched', 'Gestalt Therapy' === get_the_title( $gestalt ) && 'Gestalt Therapy' === Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][0]['settings']['title'] );
check( 'Elementor data still valid JSON in DB', is_array( json_decode( get_post_meta( $ro, '_elementor_data', true ), true ) ) );
$again = $tr->translate_post( $ro, 'en', 'ro' );
check( 're-translation starts from the source (no RO:RO:)', true === $again && 'RO:Gestalt Therapy' === get_post( $ro )->post_title );

section( 'Block editor post' );
$post_en = get_page_by_path( 'support-in-gestalt-therapy', OBJECT, 'post' )->ID;
$old     = Translations::get_translation( $post_en, 'ro' );
if ( $old ) {
	wp_delete_post( $old, true );
	Translations::flush();
}
$ro_post = Duplicator::create( $post_en, 'ro' );
check( 'post translated', true === $tr->translate_post( $ro_post, 'en', 'ro' ) );
check( 'block comments kept', false !== strpos( get_post( $ro_post )->post_content, '<!-- wp:paragraph -->' ) && false !== strpos( get_post( $ro_post )->post_content, 'RO:' ) );

section( 'Spell check' );
$data = Fields::elementor_data( $gestalt );
$data[0]['elements'][0]['elements'][1]['settings']['editor'] = str_replace( 'Welcome to', 'Welcom to', $data[0]['elements'][0]['elements'][1]['settings']['editor'] );
Fields::save_elementor_data( $gestalt, $data );
$findings = ( new Spellcheck() )->check_post( $gestalt );
check( 'one real finding, hallucination dropped', is_array( $findings ) && 1 === count( $findings ) && 'Welcom to' === $findings[0]['original'], $findings );
check( 'shown in its sentence', '' === $findings[0]['before'] && 0 === strpos( $findings[0]['after'], ' "Gestalt Therapy". I offer sessions' ), $findings[0] );
check( 'long context shortened at a word', '…' === mb_substr( $findings[0]['after'], -1 ) && mb_strlen( $findings[0]['after'] ) <= 112, $findings[0]['after'] );
check( 'where: the Elementor widget', 'Text Editor' === $findings[0]['where'], $findings[0]['where'] );
$field = $findings[0]['field'];
check( 'field points at the text', 'elementor' === Fields::collect( $gestalt )[ $field ][0] && false !== strpos( Fields::collect( $gestalt )[ $field ][2], 'Welcom to' ) );
check( 'apply fixes the Elementor text', true === ( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to', $field ) );
check( 'text corrected', false !== strpos( Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][1]['settings']['editor'], 'Welcome to "Gestalt' ) );
check( 'apply returns false when text is gone', false === ( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to' ) );

section( 'Spell check: corrections land in the HTML, around formatting and entities' );
$fix = static function ( $html, $original, $suggestion, $before = null ) {
	return Spellcheck::replace_in_html( $html, $original, $suggestion, $before );
};
check( 'quote as entity, inside formatting (the reported case)', '<p>the sum of its <em>parts</em>,&#8221; I now focus</p>' === $fix( '<p>the sum of its <em>parts</em>&#8221; I now focus</p>', 'parts” I', 'parts,” I' ) );
check( 'curly quote as character', '<p>its parts,” I now</p>' === $fix( '<p>its parts” I now</p>', 'parts” I', 'parts,” I' ) );
// The check reads white space (also &nbsp; and line breaks) as one normal space.
check( 'non-breaking space and line breaks', '<p>one,&nbsp;two</p>' === $fix( '<p>one&nbsp;two</p>', 'one two', 'one, two' ) && "<p>Hello,</p>\n<p>world</p>" === $fix( "<p>Hello</p>\n<p>world</p>", 'Hello world', 'Hello, world' ) );
check( 'a word inside a link keeps the link', 'Read <a href="/x">the bok</a> now' !== $fix( 'Read <a href="/x">the bok</a> now', 'the bok', 'the book' ) && 'Read <a href="/x">the book</a> now' === $fix( 'Read <a href="/x">the bok</a> now', 'the bok', 'the book' ) );
check( 'entity in a title', 'Tom &amp; Jerry' === $fix( 'Tom &amp; Jery', 'Tom & Jery', 'Tom & Jerry' ) );
check( 'second occurrence picked by its context', 'teh cat and the dog' === $fix( 'teh cat and teh dog', 'teh dog', 'the dog' ) && 'teh cat and the dog' === $fix( 'teh cat and teh dog', 'teh', 'the', '…cat and ' ) );
check( 'replacement across formatting keeps the tags', 'a <b>XY</b>' === $fix( 'a <b>x</b>y', 'xy', 'XY' ) );
check( 'a less-than sign is escaped', 'a &lt; b' === $fix( 'a b', 'a b', 'a < b' ) );
check( 'not there: nothing', null === $fix( '<p>nothing here</p>', 'parts” I', 'parts,” I' ) );

section( 'Spell check: the same mistake in two places' );
wp_update_post( array( 'ID' => $gestalt, 'post_title' => 'Welcom to Gestalt Therapy' ) );
$data = Fields::elementor_data( $gestalt );
$data[0]['elements'][0]['elements'][1]['settings']['editor'] = str_replace( 'Welcome to', 'Welcom to', $data[0]['elements'][0]['elements'][1]['settings']['editor'] );
Fields::save_elementor_data( $gestalt, $data );
$findings = ( new Spellcheck() )->check_post( $gestalt );
$wheres   = wp_list_pluck( $findings, 'where' );
check( 'both found, each with its place', array( 'Title', 'Text Editor' ) === $wheres, $wheres );
( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to', $findings[1]['field'] );
check( 'apply changes the field it was found in', 'Welcom to Gestalt Therapy' === get_post( $gestalt )->post_title && false !== strpos( Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][1]['settings']['editor'], 'Welcome to "Gestalt' ) );
( new Spellcheck() )->apply( $gestalt, 'Welcom to', 'Welcome to', $findings[0]['field'] );
check( 'and the title', 'Welcome to Gestalt Therapy' === get_post( $gestalt )->post_title );
wp_update_post( array( 'ID' => $gestalt, 'post_title' => 'Gestalt Therapy' ) );

section( 'Spell check in the background' );
$data = Fields::elementor_data( $gestalt );
$data[0]['elements'][0]['elements'][1]['settings']['editor'] = str_replace( 'Welcome to', 'Welcom to', $data[0]['elements'][0]['elements'][1]['settings']['editor'] );
Fields::save_elementor_data( $gestalt, $data );
$token = Spellcheck::start( $gestalt );
check( 'starts as running', 'running' === Spellcheck::status( $gestalt, $token )['status'] );
( new Spellcheck() )->run( $gestalt, $token );
$state = Spellcheck::status( $gestalt, $token );
check( 'result stored', 'done' === $state['status'] && 1 === count( $state['findings'] ) && $state['texts'] > 1 && 1 === $state['total'], $state );
$token = Spellcheck::start( $gestalt );
Spellcheck::cancel( $gestalt, $token );
$mock_calls = static function () {
	clearstatcache();
	$log = sys_get_temp_dir() . '/wpllmml-mock-ai.log';
	return is_file( $log ) ? count( file( $log ) ) : 0;
};
$before     = $mock_calls();
( new Spellcheck() )->run( $gestalt, $token );
check( 'cancelled before it ran: no AI request', 'cancelled' === Spellcheck::status( $gestalt, $token )['status'] && $before === $mock_calls() );
check( 'unknown check', 'error' === Spellcheck::status( $gestalt, 'nope' )['status'] );

section( 'Abilities' );
$abilities = function_exists( 'wp_get_abilities' ) ? array_keys( wp_get_abilities() ) : array();
check( 'abilities registered', in_array( 'wp-llm-ml/list-languages', $abilities, true ) && in_array( 'wp-llm-ml/translate-text', $abilities, true ), $abilities );
if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'wp-llm-ml/translate-text' ) ) {
	$res = wp_get_ability( 'wp-llm-ml/translate-text' )->execute( array( 'text' => 'Good morning', 'from' => 'en', 'to' => 'nl' ) );
	check( 'translate-text ability', is_array( $res ) && 'NL:Good morning' === $res['text'], $res );
	$res = wp_get_ability( 'wp-llm-ml/get-translations' )->execute( array( 'post_id' => $gestalt ) );
	check( 'get-translations ability', is_array( $res ) && 'en' === $res['lang'] && isset( $res['translations']->nl ), $res );
	$langs = wp_get_ability( 'wp-llm-ml/list-languages' )->execute();
	check( 'list-languages ability', is_array( $langs ) && 3 === count( $langs ) );
}

section( 'A whole page goes to the AI in one request' );
$mock_log = sys_get_temp_dir() . '/wpllmml-mock-ai.log';
$calls    = static function () use ( $mock_log ) {
	clearstatcache();
	return is_file( $mock_log ) ? count( file( $mock_log ) ) : 0;
};
$long_src = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Long page',
		'post_name'   => 'long-page',
	)
);
$paras = '';
for ( $n = 1; $n <= 12; $n++ ) {
	$paras .= "<p>Paragraph {$n}: " . str_repeat( 'Gestalt therapy is about awareness. ', 6 ) . "</p>\n";
}
$long_data = Fields::elementor_data( $gestalt );
$long_data[0]['elements'][0]['elements'][1]['settings']['editor'] = $paras;
update_post_meta( $long_src, '_elementor_edit_mode', 'builder' );
update_post_meta( $long_src, '_elementor_template_type', 'wp-page' );
update_post_meta( $long_src, '_elementor_data', wp_slash( wp_json_encode( $long_data ) ) );
$long_ro = Duplicator::create( $long_src, 'ro' );
$before  = $calls();
$ticks   = array();
$result  = $tr->translate_post(
	$long_ro,
	'en',
	'ro',
	array(
		'on_progress' => static function ( $done, $total ) use ( &$ticks ) {
			$ticks[] = "$done/$total";
		},
	)
);
check( 'translated', true === $result, is_wp_error( $result ) ? $result->get_error_message() : $result );
check( 'one AI request for the whole page', 1 === $calls() - $before, $calls() - $before );
$editor = Fields::elementor_data( $long_ro )[0]['elements'][0]['elements'][1]['settings']['editor'];
check( 'long text translated as one piece, all 12 paragraphs kept', 1 === substr_count( $editor, 'RO:' ) && 0 === strpos( $editor, 'RO:<p>Paragraph 1:' ) && 12 === substr_count( $editor, '<p>' ), $editor );
check( 'progress reported', array( '13/13' ) === $ticks || ( 1 === count( $ticks ) && preg_match( '#^(\d+)/\1$#', $ticks[0] ) ), $ticks );

section( 'A stopped translation changes nothing' );
wp_update_post(
	array(
		'ID'         => $long_ro,
		'post_title' => 'Handmatig vertaald',
	)
);
$asked  = 0;
$result = $tr->translate_post(
	$long_ro,
	'en',
	'ro',
	array(
		'should_continue' => static function () use ( &$asked ) {
			return ++$asked < 2; // Yes before the request, no right after it.
		},
	)
);
check( 'reported as stopped', is_wp_error( $result ) && 'wpllmml_cancelled' === $result->get_error_code(), $result );
check( 'existing translation left untouched', 'Handmatig vertaald' === get_post( $long_ro )->post_title );

section( 'An unreadable AI reply is retried in halves' );
$out = $tr->translate_strings( array( 'One [[split]]', 'Two', 'Three', 'Four' ), 'en', 'nl' );
check( 'all texts translated after halving', array( 'NL:One [[split]]', 'NL:Two', 'NL:Three', 'NL:Four' ) === $out, $out );

section( 'Background job state' );
$job = Job::start( $long_ro, 'en', 'ro' );
check( 'job started and active', is_array( $job ) && Job::is_active( $long_ro ) );
check( 'second start refused while active', is_wp_error( Job::start( $long_ro, 'en', 'ro' ) ) );
update_post_meta( $long_ro, Job::META_BEAT, array( 'token' => $job['token'], 'at' => time() - 3600, 'cancel' => false ) );
check( 'no heartbeat from the browser: no longer active', ! Job::is_active( $long_ro ) );
check( 'a new start is then allowed', is_array( Job::start( $long_ro, 'en', 'ro' ) ) );
Job::clear( $long_ro );
wp_delete_post( $long_ro, true );
wp_delete_post( $long_src, true );

section( 'Texts tab: batched AI fill endpoint (HTTP)' );
$site = getenv( 'WPLLMML_SITE_URL' );
if ( $site ) {
	// Log in as user 1 the way the browser would: auth + logged-in cookies and a matching nonce.
	$expire                      = time() + HOUR_IN_SECONDS;
	$token                       = WP_Session_Tokens::get_instance( 1 )->create( $expire );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( 1, $expire, 'logged_in', $token );
	$nonce                       = wp_create_nonce( 'wpllmml_ajax' );
	$cookies                     = array(
		AUTH_COOKIE      => wp_generate_auth_cookie( 1, $expire, 'auth', $token ),
		LOGGED_IN_COOKIE => $_COOKIE[ LOGGED_IN_COOKIE ],
	);
	$post_batch = static function ( $lang, $keys, $with_cookies = true ) use ( $site, $nonce, $cookies ) {
		$body = 'action=wpllmml_ai_strings_batch&_ajax_nonce=' . rawurlencode( $nonce ) . '&lang=' . rawurlencode( $lang );
		foreach ( $keys as $key ) {
			$body .= '&keys%5B%5D=' . rawurlencode( $key );
		}
		$response = wp_remote_post(
			$site . '/wp-admin/admin-ajax.php',
			array(
				'timeout' => 60,
				'body'    => $body,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'cookies' => $with_cookies ? $cookies : array(),
			)
		);
		return json_decode( wp_remote_retrieve_body( $response ), true );
	};
	$discovered = WPLLMML\Strings::discover();
	$key_a      = md5( $discovered[0][0] );
	$key_b      = md5( $discovered[1][0] );
	$res        = $post_batch( 'ro', array( $key_a, $key_b, md5( 'not a text of this site' ) ) );
	check( 'batch translates the requested texts', ! empty( $res['success'] ) && 0 === strpos( $res['data']['translations'][ $key_a ], 'RO:' ) && isset( $res['data']['translations'][ $key_b ] ), $res );
	check( 'unknown text keys are ignored', ! empty( $res['success'] ) && 2 === count( $res['data']['translations'] ) );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( WPLLMML\Strings::OPTION, 'options' );
	$stored = get_option( WPLLMML\Strings::OPTION );
	check( 'batch is saved immediately', isset( $stored[ $key_a ]['t']['ro'] ) && $stored[ $key_a ]['t']['ro'] === $res['data']['translations'][ $key_a ] );
	$res = $post_batch( 'en', array( $key_a ) );
	check( 'default language refused', isset( $res['success'] ) && false === $res['success'] );
	$res = $post_batch( 'ro', array( $key_a ), false );
	check( 'logged-out request refused', empty( $res['success'] ) );

	section( 'Page translation in the background (HTTP, as the editor does it)' );
	$ajax  = static function ( $action, $fields, $timeout = 60 ) use ( $site, $nonce, $cookies ) {
		$response = wp_remote_post(
			$site . '/wp-admin/admin-ajax.php',
			array(
				'timeout' => $timeout,
				'body'    => array_merge(
					array(
						'action'      => $action,
						'_ajax_nonce' => $nonce,
					),
					$fields
				),
				'cookies' => $cookies,
			)
		);
		return is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : json_decode( wp_remote_retrieve_body( $response ), true );
	};
	$wait  = static function ( $post_id, $token, $want, $seconds ) use ( $ajax ) {
		$deadline = microtime( true ) + $seconds;
		do {
			$res = $ajax( 'wpllmml_ai_post_status', array( 'post' => $post_id, 'token' => $token ) );
			if ( ! empty( $res['success'] ) && in_array( $res['data']['status'], (array) $want, true ) ) {
				return $res['data'];
			}
			usleep( 500000 );
		} while ( microtime( true ) < $deadline );
		return isset( $res['data'] ) ? $res['data'] : $res;
	};
	$reiki = get_page_by_path( 'reiki' )->ID;
	$old   = Translations::get_translation( $reiki, 'ro' );
	if ( $old ) {
		wp_delete_post( $old, true );
	}
	$res = $ajax( 'wpllmml_ai_post_start', array( 'mode' => 'create', 'source' => $reiki, 'lang' => 'ro' ) );
	check( 'start creates the ro draft and a job', ! empty( $res['success'] ) && $res['data']['post'] > 0 && '' !== $res['data']['token'], $res );
	$ro_reiki = ! empty( $res['success'] ) ? (int) $res['data']['post'] : 0;
	$token    = ! empty( $res['success'] ) ? $res['data']['token'] : '';
	$t0       = microtime( true );
	$res      = $ajax( 'wpllmml_ai_post_run', array( 'post' => $ro_reiki, 'token' => $token ) );
	check( 'run answers at once and keeps working on the server', ! empty( $res['data']['started'] ) && microtime( true ) - $t0 < 3, array( $res, microtime( true ) - $t0 ) );
	$done = $wait( $ro_reiki, $token, array( 'done', 'error', 'cancelled' ), 30 );
	check( 'status reaches done with the edit screen to open', isset( $done['status'] ) && 'done' === $done['status'] && false !== strpos( $done['redirect'], 'post.php?post=' . $ro_reiki ), $done );
	clean_post_cache( $ro_reiki );
	Translations::flush();
	check( 'draft translated, linked and in Romanian', 0 === strpos( get_the_title( $ro_reiki ), 'RO:' ) && 'ro' === Translations::get_lang( $ro_reiki ) && Translations::get_translation( $reiki, 'ro' ) === $ro_reiki );
	check( 'job cleared after done', '' === get_post_meta( $ro_reiki, Job::META, true ) );

	section( 'One job per page; Cancel stops the server mid-request' );
	wp_update_post( array( 'ID' => $ro_reiki, 'post_title' => 'Eigen titel' ) );
	$slow_src = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Slow page [[slow]]', 'post_name' => 'slow-page' ) );
	$slow_ro  = Duplicator::create( $slow_src, 'ro' );
	$res      = $ajax( 'wpllmml_ai_post_start', array( 'mode' => 'retranslate', 'post' => $slow_ro ) );
	$token    = ! empty( $res['success'] ) ? $res['data']['token'] : '';
	$ajax( 'wpllmml_ai_post_run', array( 'post' => $slow_ro, 'token' => $token ) );
	$running = $wait( $slow_ro, $token, array( 'running' ), 10 );
	check( 'job is running', isset( $running['status'] ) && 'running' === $running['status'], $running );
	$second = $ajax( 'wpllmml_ai_post_start', array( 'mode' => 'retranslate', 'post' => $slow_ro ) );
	check( 'a second start on the same page is refused', isset( $second['success'] ) && false === $second['success'] && false !== stripos( (string) $second['data'], 'already being translated' ), $second );
	$t0 = microtime( true );
	$ajax( 'wpllmml_ai_post_cancel', array( 'post' => $slow_ro, 'token' => $token ) );
	// The runner notices within a few seconds, before the AI's 6-second answer arrives.
	$deadline = microtime( true ) + 15;
	do {
		usleep( 300000 );
		$state = get_post_meta( $slow_ro, Job::META_STATE, true );
		wp_cache_delete( $slow_ro, 'post_meta' );
		$state = get_post_meta( $slow_ro, Job::META_STATE, true );
	} while ( ( empty( $state['status'] ) || 'running' === $state['status'] ) && microtime( true ) < $deadline );
	check( 'server stopped after cancel', isset( $state['status'] ) && 'cancelled' === $state['status'], $state );
	check( 'stopped mid-request (before the AI answered)', microtime( true ) - $t0 < 5.5, microtime( true ) - $t0 );
	clean_post_cache( $slow_ro );
	check( 'cancelled translation saved nothing', 'Slow page [[slow]]' === get_post( $slow_ro )->post_title );
	Job::clear( $slow_ro );
	wp_delete_post( $slow_ro, true );
	wp_delete_post( $slow_src, true );
	wp_delete_post( $ro_reiki, true );

	section( 'Spell check dialog (HTTP, as the post list does it)' );
	$res = $ajax( 'wpllmml_spell_start', array( 'post' => $gestalt ) );
	check( 'answers at once with a token and the page', ! empty( $res['data']['token'] ) && 'Gestalt Therapy' === $res['data']['title'] && 'English' === $res['data']['language'], $res );
	$token    = isset( $res['data']['token'] ) ? $res['data']['token'] : '';
	$deadline = microtime( true ) + 30;
	do {
		usleep( 300000 );
		$res = $ajax( 'wpllmml_spell_status', array( 'post' => $gestalt, 'token' => $token ) );
	} while ( isset( $res['data']['status'] ) && 'running' === $res['data']['status'] && microtime( true ) < $deadline );
	$finding = isset( $res['data']['findings'][0] ) ? $res['data']['findings'][0] : array();
	check( 'findings with context', 'done' === $res['data']['status'] && 'Welcom to' === $finding['original'] && 'Text Editor' === $finding['where'], $res );
	$res = $ajax( 'wpllmml_apply_fix', array( 'post' => $gestalt, 'original' => $finding['original'], 'suggestion' => $finding['suggestion'], 'field' => $finding['field'] ) );
	check( 'applied', ! empty( $res['data']['applied'] ) );
	clean_post_cache( $gestalt );
	check( 'saved', false !== strpos( Fields::elementor_data( $gestalt )[0]['elements'][0]['elements'][1]['settings']['editor'], 'Welcome to "Gestalt' ) );
} else {
	check( 'WPLLMML_SITE_URL set for HTTP checks', false );
}

section( 'Bulk translation: what gets translated' );
$bulk_a  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bulk A' ) );
$bulk_b  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bulk B' ) );
$bulk_nl = Duplicator::create( $bulk_b, 'nl' );
$plan    = WPLLMML\Admin\Admin::bulk_plan( array( $gestalt, $bulk_a, $bulk_nl, $bulk_b ), 'all' );
$pairs   = array_map(
	static function ( $task ) {
		return $task['source'] . ':' . $task['lang'];
	},
	$plan['tasks']
);
check( 'missing languages only, from the default-language page, once per group', array( $bulk_a . ':nl', $bulk_a . ':ro', $bulk_b . ':ro' ) === $pairs, $pairs );
check( 'existing translations counted as skipped', 3 === $plan['skipped'], $plan['skipped'] );
check( 'titles for the progress panel', 'Bulk A' === $plan['tasks'][0]['title'] && 'Nederlands' === $plan['tasks'][0]['language'], $plan['tasks'][0] );
$plan = WPLLMML\Admin\Admin::bulk_plan( array( $bulk_a ), 'ro' );
check( 'one language', 1 === count( $plan['tasks'] ) && 'ro' === $plan['tasks'][0]['lang'] );
check( 'unknown language: nothing', array() === WPLLMML\Admin\Admin::bulk_plan( array( $bulk_a ), 'xx' )['tasks'] );

section( 'Bulk action "Create … translation (copy)"' );
$admin    = new WPLLMML\Admin\Admin( WPLLMML\Plugin::instance()->router );
$redirect = $admin->handle_bulk_action( 'edit.php?post_type=page', 'wpllmml_copy_all', array( $bulk_a, $bulk_nl ) );
Translations::flush();
check( 'drafts created', Translations::get_translation( $bulk_a, 'nl' ) && Translations::get_translation( $bulk_a, 'ro' ) && Translations::get_translation( $bulk_b, 'ro' ) );
check( 'as drafts', 'draft' === get_post_status( Translations::get_translation( $bulk_a, 'ro' ) ) );
$flash = get_transient( 'wpllmml_flash_' . get_current_user_id() );
check( 'result message', is_array( $flash ) && false !== strpos( $flash[0][1], '3 draft translations created; 1 already existed' ), $flash );
check( 'back to the list', 'edit.php?post_type=page' === $redirect );
$admin->handle_bulk_action( 'edit.php', 'wpllmml_ai_nl', array( $bulk_a ) );
$flash = get_transient( 'wpllmml_flash_' . get_current_user_id() );
check( 'AI without JavaScript: explained', is_array( $flash ) && 'error' === $flash[0][0] );
check( 'other bulk actions untouched', 'x' === $admin->handle_bulk_action( 'x', 'trash', array( $bulk_a ) ) );
delete_transient( 'wpllmml_flash_' . get_current_user_id() );
foreach ( array( $bulk_a, $bulk_b ) as $bulk_id ) {
	foreach ( Translations::get_translations( $bulk_id ) as $bulk_tid ) {
		wp_delete_post( $bulk_tid, true );
	}
}
Translations::flush();

// Publish the ro translation for a visual check.
wp_update_post( array( 'ID' => $ro, 'post_status' => 'publish' ) );
echo "\nRomanian page: " . get_permalink( $ro ) . "\n";

// Leave AI unconfigured afterwards.
$settings['ai']['api_key'] = '';
Settings::save( $settings );
finish();
