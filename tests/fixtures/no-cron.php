<?php
/**
 * Must-use plugin for the browser tests: behave like a host where WP-Cron
 * never runs (its loopback request fails, or DISABLE_WP_CRON without a real
 * cron job). The layout sync must then be started by the screens themselves.
 *
 * @package WPLLMML
 */

define( 'WPLLMML_SYNC_NO_SPAWN', true );
add_filter( 'pre_get_ready_cron_jobs', '__return_empty_array' );
