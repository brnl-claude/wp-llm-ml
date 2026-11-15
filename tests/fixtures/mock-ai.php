<?php
/**
 * Mock OpenAI-compatible API (stand-in for HostYourAI in tests).
 * Run: php -S localhost:8891 tests/fixtures/mock-ai.php
 *
 * - GET  /v1/models            → {"data":[{"id":"mock-model"}]}
 * - POST /v1/chat/completions  → translation: every string prefixed with
 *   "<CODE>:" (code taken from the system prompt); spell check: one finding
 *   for the word "Welcom" when present.
 * Requires "Authorization: Bearer test-key".
 * A text containing [[slow]] makes the reply take 6 seconds; a request with
 * several texts of which one contains [[split]] gets an unreadable reply.
 * Each translation request is logged to <tmp>/wpllmml-mock-ai.log.
 * Also fakes GitHub's "latest release" API (/github/releases/latest) and
 * serves the zip named in WPLLMML_MOCK_ZIP, for the updater test.
 *
 * @package WPLLMML
 */

$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$auth   = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
$reply  = static function ( $code, $data ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
};
// Fake GitHub release API for the updater test (no auth, like GitHub's public API).
if ( '/github/releases/latest' === $path ) {
	$version = getenv( 'WPLLMML_MOCK_VERSION' ) ? getenv( 'WPLLMML_MOCK_VERSION' ) : '9.9.9';
	$reply(
		200,
		array(
			'tag_name'     => 'v' . $version,
			'html_url'     => 'https://github.com/brnl-claude/wp-llm-ml/releases/tag/v' . $version,
			'body'         => "Test release\n- something new",
			'published_at' => '2026-11-14T12:00:00Z',
			'assets'       => array(
				array( 'name' => 'notes.txt', 'browser_download_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/github/notes.txt' ),
				array( 'name' => 'wp-llm-ml.zip', 'browser_download_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/github/wp-llm-ml.zip' ),
			),
		)
	);
	return;
}
if ( '/github/wp-llm-ml.zip' === $path && getenv( 'WPLLMML_MOCK_ZIP' ) && is_file( getenv( 'WPLLMML_MOCK_ZIP' ) ) ) {
	header( 'Content-Type: application/zip' );
	readfile( getenv( 'WPLLMML_MOCK_ZIP' ) );
	return;
}
if ( 'Bearer test-key' !== $auth ) {
	$reply( 401, array( 'error' => array( 'message' => 'Invalid API key' ) ) );
	return;
}
if ( '/v1/models' === $path ) {
	$reply( 200, array( 'data' => array( array( 'id' => 'mock-model' ), array( 'id' => 'mock-large' ) ) ) );
	return;
}
if ( '/v1/chat/completions' !== $path ) {
	$reply( 404, array( 'error' => array( 'message' => 'not found' ) ) );
	return;
}
$body     = json_decode( file_get_contents( 'php://input' ), true );
$system   = $body['messages'][0]['content'];
$user     = json_decode( $body['messages'][1]['content'], true );
$content  = '';
if ( false !== strpos( $system, 'professional translator' ) ) {
	preg_match( '/ to [A-Za-z]+ \(([a-z_-]+)\)/', $system, $m );
	$code    = strtoupper( $m[1] );
	$joined  = implode( "\n", $user );
	if ( false !== strpos( $joined, '[[slow]]' ) ) {
		sleep( 6 ); // Lets tests stop a translation while the AI is still working.
	}
	if ( count( $user ) > 1 && false !== strpos( $joined, '[[split]]' ) ) {
		// An answer with the wrong number of items, like a reply that was cut off.
		$reply( 200, array( 'choices' => array( array( 'index' => 0, 'message' => array( 'role' => 'assistant', 'content' => '["only one"]' ) ) ) ) );
		file_put_contents( sys_get_temp_dir() . '/wpllmml-mock-ai.log', 'translate ' . count( $user ) . "\n", FILE_APPEND );
		return;
	}
	$out     = array_map(
		static function ( $s ) use ( $code ) {
			return $code . ':' . $s;
		},
		$user
	);
	// Reply wrapped in a code fence, as some models do.
	$content = "```json\n" . json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n```";
} elseif ( false !== strpos( $system, 'proofreader' ) ) {
	$found = array();
	foreach ( $user as $item ) {
		if ( false !== strpos( $item['text'], 'Welcom ' ) ) {
			$found[] = array(
				'id'          => $item['id'],
				'original'    => 'Welcom to',
				'suggestion'  => 'Welcome to',
				'explanation' => 'Spelling: "Welcome".',
			);
		}
		// A hallucinated finding that does not occur in the text must be dropped.
		$found[] = array(
			'id'          => $item['id'],
			'original'    => 'zzzz-not-in-text',
			'suggestion'  => 'x',
			'explanation' => 'bogus',
		);
	}
	$content = '<think>checking…</think>' . json_encode( $found );
}
file_put_contents( sys_get_temp_dir() . '/wpllmml-mock-ai.log', ( false !== strpos( $system, 'professional translator' ) ? 'translate ' . count( $user ) : 'other' ) . "\n", FILE_APPEND );
$reply(
	200,
	array(
		'id'      => 'mock',
		'object'  => 'chat.completion',
		'choices' => array(
			array(
				'index'   => 0,
				'message' => array(
					'role'    => 'assistant',
					'content' => $content,
				),
			),
		),
	)
);
