<?php
/**
 * A page translation that runs on the server in the background while the
 * editor's browser polls for its status.
 *
 * - One job per page: starting while a live job runs is refused, and only
 *   the job with the current token may save (a replaced job stops itself).
 * - The browser's polls are its heartbeat. When they stop (tab closed,
 *   navigated away) or the editor cancels, the server stops too, also in
 *   the middle of the AI request, and saves nothing.
 *
 * State lives in three post meta keys, each written by one side only, so the
 * runner and the browser never overwrite each other's updates:
 *   _wpllmml_ai_job        token, languages, start time (written at start)
 *   _wpllmml_ai_job_state  status, progress, message, runner pulse (runner)
 *   _wpllmml_ai_job_beat   browser heartbeat and cancel flag (browser)
 *
 * @package WPLLMML
 */

namespace WPLLMML\Ai;


defined( 'ABSPATH' ) || exit;

/**
 * Background page translation job.
 */
class Job {

	const META       = '_wpllmml_ai_job';
	const META_STATE = '_wpllmml_ai_job_state';
	const META_BEAT  = '_wpllmml_ai_job_beat';

	/**
	 * Seconds without a browser heartbeat before the runner gives up.
	 *
	 * @return int
	 */
	public static function heartbeat_ttl() {
		return (int) apply_filters( 'wpllmml_ai_heartbeat_ttl', defined( 'WPLLMML_AI_HEARTBEAT_TTL' ) ? WPLLMML_AI_HEARTBEAT_TTL : 20 );
	}

	/**
	 * Seconds without a sign of life from the runner before the job counts as dead.
	 *
	 * @return int
	 */
	public static function runner_ttl() {
		return (int) apply_filters( 'wpllmml_ai_runner_ttl', 60 );
	}

	/**
	 * Read a meta value straight from the database (the runner is a long
	 * request whose object cache would otherwise go stale).
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return array
	 */
	private static function read( $post_id, $key ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$value = get_post_meta( $post_id, $key, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Write a meta value.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param array  $value   Value.
	 */
	private static function write( $post_id, $key, array $value ) {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
	}

	/**
	 * Is a job for this post queued or running, with a live browser and runner?
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_active( $post_id ) {
		$job = self::read( $post_id, self::META );
		if ( ! $job ) {
			return false;
		}
		$state = self::read( $post_id, self::META_STATE );
		$beat  = self::read( $post_id, self::META_BEAT );
		if ( empty( $state['status'] ) || ! in_array( $state['status'], array( 'queued', 'running' ), true ) || ! empty( $beat['cancel'] ) ) {
			return false;
		}
		$now = time();
		if ( $now - (int) ( isset( $beat['at'] ) ? $beat['at'] : 0 ) > self::heartbeat_ttl() ) {
			return false;
		}
		$pulse = isset( $state['pulse'] ) ? (int) $state['pulse'] : (int) $job['created'];
		return $now - $pulse <= self::runner_ttl();
	}

	/**
	 * Register a new job (the browser then calls run() and polls status()).
	 *
	 * @param int    $post_id Translation to fill.
	 * @param string $from    Source language.
	 * @param string $to      Target language.
	 * @return array|\WP_Error Job.
	 */
	public static function start( $post_id, $from, $to ) {
		if ( self::is_active( $post_id ) ) {
			return new \WP_Error( 'wpllmml_busy', __( 'This page is already being translated (in another tab or by another editor). Wait until it is done, or cancel it there.', 'wp-llm-ml' ) );
		}
		$job = array(
			'token'   => wp_generate_password( 24, false ),
			'from'    => $from,
			'to'      => $to,
			'created' => time(),
			'user'    => get_current_user_id(),
		);
		self::write( $post_id, self::META, $job );
		self::write(
			$post_id,
			self::META_STATE,
			array(
				'token'  => $job['token'],
				'status' => 'queued',
				'done'   => 0,
				'total'  => 0,
				'pulse'  => time(),
			)
		);
		self::write(
			$post_id,
			self::META_BEAT,
			array(
				'token'  => $job['token'],
				'at'     => time(),
				'cancel' => false,
			)
		);
		return $job;
	}

	/**
	 * Run a queued job to the end (called in its own request).
	 *
	 * @param int    $post_id Translation.
	 * @param string $token   Job token.
	 * @return true|\WP_Error
	 */
	public static function run( $post_id, $token ) {
		$job   = self::read( $post_id, self::META );
		$state = self::read( $post_id, self::META_STATE );
		if ( ! $job || ! hash_equals( (string) $job['token'], (string) $token ) || empty( $state['status'] ) || 'queued' !== $state['status'] ) {
			return new \WP_Error( 'wpllmml_no_job', __( 'No translation is waiting for this page.', 'wp-llm-ml' ) );
		}
		$state = array(
			'token'  => $token,
			'status' => 'running',
			'done'   => 0,
			'total'  => 0,
			'pulse'  => time(),
		);
		self::write( $post_id, self::META_STATE, $state );

		$pulse_at = time();
		$proceed  = static function () use ( $post_id, $token, &$state, &$pulse_at ) {
			$job  = self::read( $post_id, self::META );
			$beat = self::read( $post_id, self::META_BEAT );
			$ok   = $job && hash_equals( (string) $job['token'], (string) $token )
				&& isset( $beat['token'] ) && $beat['token'] === $token && empty( $beat['cancel'] )
				&& time() - (int) $beat['at'] <= self::heartbeat_ttl();
			if ( $ok && time() - $pulse_at >= 5 ) {
				// Tell the browser the runner is still alive.
				$pulse_at       = time();
				$state['pulse'] = $pulse_at;
				self::write( $post_id, self::META_STATE, $state );
			}
			return $ok;
		};
		$progress = static function ( $done, $total ) use ( $post_id, &$state ) {
			$state['done']  = $done;
			$state['total'] = $total;
			$state['pulse'] = time();
			self::write( $post_id, self::META_STATE, $state );
		};

		$result = ( new Translator() )->translate_post(
			$post_id,
			$job['from'],
			$job['to'],
			array(
				'should_continue' => $proceed,
				'on_progress'     => $progress,
			)
		);

		// A newer job took over: leave its state alone.
		$current = self::read( $post_id, self::META );
		if ( ! $current || ! hash_equals( (string) $current['token'], (string) $token ) ) {
			return Translator::cancelled();
		}
		if ( is_wp_error( $result ) ) {
			$state['status']  = 'wpllmml_cancelled' === $result->get_error_code() ? 'cancelled' : 'error';
			$state['message'] = $result->get_error_message();
		} else {
			$state['status'] = 'done';
		}
		$state['pulse'] = time();
		self::write( $post_id, self::META_STATE, $state );
		return $result;
	}

	/**
	 * The browser's poll: records its heartbeat and returns the job's status.
	 *
	 * @param int    $post_id Translation.
	 * @param string $token   Job token.
	 * @return array status (queued|running|done|cancelled|error), done, total, message, elapsed.
	 */
	public static function status( $post_id, $token ) {
		$job = self::read( $post_id, self::META );
		if ( ! $job || ! hash_equals( (string) $job['token'], (string) $token ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'This translation was replaced by a newer one.', 'wp-llm-ml' ),
			);
		}
		$beat = self::read( $post_id, self::META_BEAT );
		if ( empty( $beat['cancel'] ) ) {
			self::write(
				$post_id,
				self::META_BEAT,
				array(
					'token'  => $token,
					'at'     => time(),
					'cancel' => false,
				)
			);
		}
		$state   = self::read( $post_id, self::META_STATE );
		$status  = isset( $state['status'] ) ? $state['status'] : 'error';
		$message = isset( $state['message'] ) ? $state['message'] : '';
		$pulse   = isset( $state['pulse'] ) ? (int) $state['pulse'] : (int) $job['created'];
		if ( in_array( $status, array( 'queued', 'running' ), true ) && time() - $pulse > self::runner_ttl() ) {
			$status  = 'error';
			$message = 'queued' === $state['status']
				? __( 'The translation could not be started on the server.', 'wp-llm-ml' )
				: __( 'The server stopped the translation, probably because of a time limit. Try again.', 'wp-llm-ml' );
		}
		return array(
			'status'  => $status,
			'done'    => isset( $state['done'] ) ? (int) $state['done'] : 0,
			'total'   => isset( $state['total'] ) ? (int) $state['total'] : 0,
			'message' => $message,
			'elapsed' => time() - (int) $job['created'],
		);
	}

	/**
	 * Ask the runner to stop (Cancel button, or the browser leaving the page).
	 *
	 * @param int    $post_id Translation.
	 * @param string $token   Job token.
	 * @return bool
	 */
	public static function cancel( $post_id, $token ) {
		$job = self::read( $post_id, self::META );
		if ( ! $job || ! hash_equals( (string) $job['token'], (string) $token ) ) {
			return false;
		}
		self::write(
			$post_id,
			self::META_BEAT,
			array(
				'token'  => $token,
				'at'     => time(),
				'cancel' => true,
			)
		);
		return true;
	}

	/**
	 * Forget a finished job.
	 *
	 * @param int $post_id Post id.
	 */
	public static function clear( $post_id ) {
		delete_post_meta( $post_id, self::META );
		delete_post_meta( $post_id, self::META_STATE );
		delete_post_meta( $post_id, self::META_BEAT );
	}
}
