<?php
/**
 * Minimal assertion helpers for the integration scripts.
 *
 * @package WPLLMML
 */

$GLOBALS['wpllmml_test'] = array(
	'pass' => 0,
	'fail' => 0,
);

/**
 * Print a section header.
 *
 * @param string $name Name.
 */
function section( $name ) {
	echo "\n## {$name}\n";
}

/**
 * Record an assertion.
 *
 * @param string $label Description.
 * @param bool   $ok    Result.
 * @param mixed  $info  Shown on failure.
 */
function check( $label, $ok, $info = null ) {
	if ( $ok ) {
		++$GLOBALS['wpllmml_test']['pass'];
		echo "  ok   {$label}\n";
	} else {
		++$GLOBALS['wpllmml_test']['fail'];
		echo "  FAIL {$label}" . ( null !== $info ? ' — ' . ( is_scalar( $info ) ? $info : json_encode( $info ) ) : '' ) . "\n";
	}
}

/**
 * Print totals and exit non-zero on failures.
 */
function finish() {
	$t = $GLOBALS['wpllmml_test'];
	echo "\n{$t['pass']} passed, {$t['fail']} failed\n";
	exit( $t['fail'] ? 1 : 0 );
}
