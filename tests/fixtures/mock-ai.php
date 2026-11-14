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
file_put_contents( sys_get_temp_dir() . '/wpllmml-mock-ai.log', $body['model'] . "\n", FILE_APPEND );
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
