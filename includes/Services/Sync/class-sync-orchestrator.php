<?php
/**
 * Sync Orchestrator — single merchant-facing entry for starting sync jobs.
 *
 * Maps intents to engine parameters; enriches responses for REST/JS.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Orchestrator', false ) ) :

class SheetSync_Sync_Orchestrator {

	/** @var string[] */
	private const VALID_INTENTS = array(
		'sheet_to_wc',
		'wc_to_sheet',
		'both',
		'first_publish',
		'apply_sheet',
		'import_all_sheet',
		'add_new_sheet',
		'update_sheet',
		'publish_wc',
		'both_ways',
	);

	/**
	 * @param array<string, mixed> $options confirm_full_export, pull_before_export, force_degraded_sync,
	 *                                      sync_strategy, sync_job_direction, sync_pull_mode (legacy overrides)
	 * @return array<string, mixed>
	 */
	public static function start( int $connection_id, string $intent = '', array $options = array() ): array {
		$connection_id = absint( $connection_id );
		if ( $connection_id < 1 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ),
			);
		}

		$intent = sanitize_key( $intent );
		$engine = self::resolve_engine_params( $connection_id, $intent, $options );

		if ( ! empty( $engine['error'] ) ) {
			return array(
				'success' => false,
				'message' => (string) $engine['error'],
			);
		}

		$strategy = (string) ( $engine['sync_strategy'] ?? 'smart' );
		add_filter(
			'sheetsync_sync_strategy_' . $connection_id,
			static fn() => $strategy
		);

		$pull_mode = (string) ( $engine['pull_mode'] ?? 'default' );
		$start_args = array(
			'triggered_by'        => (string) ( $options['triggered_by'] ?? 'manual' ),
			'confirm_full_export' => ! empty( $options['confirm_full_export'] ),
			'pull_before_export'  => ! empty( $options['pull_before_export'] ),
			'force_degraded_sync' => ! empty( $options['force_degraded_sync'] ),
		);

		$job_direction = (string) ( $engine['direction'] ?? '' );
		if ( $job_direction !== '' ) {
			$start_args['direction'] = $job_direction;
		}

		$engine_instance = new SheetSync_Sync_Engine();

		try {
			$result = array();
			SheetSync_Sync_Pull_Mode::run_with_mode(
				$connection_id,
				$pull_mode,
				static function () use ( $engine_instance, $connection_id, $start_args, &$result ) {
					$result = $engine_instance->run( $connection_id, $start_args );
				}
			);
		} catch ( \Throwable $e ) {
			if ( function_exists( 'sheetsync_debug_log' ) ) {
				sheetsync_debug_log( 'Orchestrator sync error: ' . $e->getMessage() );
			}
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		if ( ! empty( $result['success'] ) || ! empty( $result['job_id'] ) ) {
			sheetsync_update_setup_progress( 'first_sync_done', true );
		}

		return self::enrich_response( $connection_id, $intent, $result );
	}

	/**
	 * Poll + optional inline drain (same contract as ajax_sync_tick).
	 *
	 * @return array<string, mixed>
	 */
	public static function tick( int $connection_id, int $job_id = 0, bool $inline_drain = false ): array {
		if ( ! class_exists( 'SheetSync_Job_Repository', false ) ) {
			return array( 'job' => null, 'drained' => false );
		}

		$repo = new SheetSync_Job_Repository();
		if ( $job_id < 1 && $connection_id > 0 ) {
			$running = $repo->get_running_for_connection( $connection_id );
			$job_id  = $running ? (int) $running->id : 0;
		}

		$job     = $job_id > 0 ? $repo->get( $job_id ) : null;
		$drained = false;

		if ( $inline_drain && $job && in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			if ( function_exists( 'sheetsync_reschedule_stuck_job' ) ) {
				sheetsync_reschedule_stuck_job( $job_id, 0 );
			} elseif ( function_exists( 'sheetsync_schedule_job_action' ) ) {
				sheetsync_schedule_job_action( $job_id, 'sheetsync_run_job', 0 );
			}
			$seconds = function_exists( 'sheetsync_admin_request_budget_seconds' )
				? sheetsync_admin_request_budget_seconds()
				: (int) apply_filters( 'sheetsync_admin_drain_seconds', 4 );
			if ( function_exists( 'sheetsync_process_job_inline' ) ) {
				sheetsync_process_job_inline( $job_id, max( 3, $seconds ) );
			}
			$drained = true;
			$job     = $repo->get( $job_id );
		}

		if ( ! $job && $connection_id > 0 ) {
			$job = $repo->get_running_for_connection( $connection_id );
		}

		$payload = null;
		if ( $job ) {
			$payload = function_exists( 'sheetsync_job_rest_payload' )
				? sheetsync_job_rest_payload( $job )
				: null;
		}

		return array(
			'job'     => $payload,
			'drained' => $drained,
		);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private static function resolve_engine_params( int $connection_id, string $intent, array $options ): array {
		$legacy_strategy = sanitize_text_field( (string) ( $options['sync_strategy'] ?? '' ) );
		$legacy_direction = sanitize_text_field( (string) ( $options['sync_job_direction'] ?? '' ) );
		$legacy_pull      = sanitize_text_field( (string) ( $options['sync_pull_mode'] ?? 'default' ) );

		if ( $intent === '' || ! in_array( $intent, self::VALID_INTENTS, true ) ) {
			if ( in_array( $legacy_strategy, array( 'smart', 'full' ), true )
				|| in_array( $legacy_direction, array( 'pull', 'push', 'two_way' ), true ) ) {
				return array(
					'sync_strategy' => in_array( $legacy_strategy, array( 'smart', 'full' ), true ) ? $legacy_strategy : 'smart',
					'direction'     => in_array( $legacy_direction, array( 'pull', 'push', 'two_way' ), true ) ? $legacy_direction : '',
					'pull_mode'     => in_array( $legacy_pull, array( 'default', 'create_new', 'update_only' ), true ) ? $legacy_pull : 'default',
				);
			}
			$intent = self::default_intent_for_connection( $connection_id );
		}

		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn ) {
			return array( 'error' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) );
		}

		$sync_direction = (string) ( $conn->sync_direction ?? 'sheets_to_wc' );
		$first_link     = self::is_first_link( $connection_id );

		$params = array(
			'sync_strategy' => 'smart',
			'direction'     => '',
			'pull_mode'     => in_array( $legacy_pull, array( 'default', 'create_new', 'update_only' ), true ) ? $legacy_pull : 'default',
		);

		switch ( $intent ) {
			case 'import_all_sheet':
			case 'add_new_sheet':
			case 'update_sheet':
			case 'apply_sheet':
			case 'sheet_to_wc':
				$params['direction'] = 'pull';
				break;

			case 'publish_wc':
			case 'wc_to_sheet':
			case 'first_publish':
				$params['direction'] = 'push';
				break;

			case 'both':
			case 'both_ways':
				if ( $first_link && in_array( $sync_direction, array( 'wc_to_sheets', 'two_way' ), true ) ) {
					$params['direction'] = 'push';
				} else {
					$params['direction'] = 'two_way';
				}
				break;

			default:
				$params['direction'] = '';
		}

		if ( in_array( $legacy_strategy, array( 'smart', 'full' ), true ) ) {
			$params['sync_strategy'] = $legacy_strategy;
		}
		if ( in_array( $legacy_direction, array( 'pull', 'push', 'two_way' ), true ) ) {
			$params['direction'] = $legacy_direction;
		}

		return $params;
	}

	private static function default_intent_for_connection( int $connection_id ): string {
		$conn = SheetSync_Sync_Engine::get_connection( $connection_id );
		if ( ! $conn ) {
			return 'apply_sheet';
		}
		$dir = (string) ( $conn->sync_direction ?? 'sheets_to_wc' );
		if ( 'wc_to_sheets' === $dir ) {
			return self::is_first_link( $connection_id ) ? 'first_publish' : 'publish_wc';
		}
		if ( 'two_way' === $dir ) {
			return self::is_first_link( $connection_id ) ? 'first_publish' : 'both_ways';
		}
		return 'apply_sheet';
	}

	private static function is_first_link( int $connection_id ): bool {
		if ( ! class_exists( 'SheetSync_Product_Map_Repository', false ) ) {
			return true;
		}
		return ( new SheetSync_Product_Map_Repository() )->count_for_connection( $connection_id ) < 1;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function enrich_response( int $connection_id, string $intent, array $result ): array {
		$health = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'ok' => true, 'past_due' => 0 );

		$requires_tab = false;
		if ( ! ( $health['ok'] ?? true ) && ! empty( $result['job_id'] ) ) {
			$requires_tab = true;
		}
		if ( ! empty( $result['scheduler_degraded'] ) || ! empty( $result['force_degraded_sync'] ) ) {
			$requires_tab = true;
		}

		$phase = '';
		$eta   = null;
		if ( ! empty( $result['job_id'] ) && class_exists( 'SheetSync_Job_Repository', false ) ) {
			$job = ( new SheetSync_Job_Repository() )->get( (int) $result['job_id'] );
			if ( $job ) {
				$phase = (string) ( $job->phase ?? '' );
				$eta   = function_exists( 'sheetsync_estimate_job_eta_minutes' )
					? sheetsync_estimate_job_eta_minutes( $job )
					: null;
			}
		}

		$result['intent']             = $intent !== '' ? $intent : self::default_intent_for_connection( $connection_id );
		$result['phase']              = $phase;
		$result['estimated_minutes']  = $eta;
		$result['requires_tab_open']  = $requires_tab;
		$result['scheduler_healthy'] = (bool) ( $health['ok'] ?? true );

		if ( ! empty( $result['success'] ) || ! empty( $result['job_id'] ) ) {
			if ( ! empty( $result['async'] ) && empty( $result['job_id'] ) ) {
				$result['message'] = (string) ( $result['message'] ?? __( 'Sync queued. Processing in the background.', 'sheetsync-for-woocommerce' ) );
			}
			if ( empty( $result['success'] ) && (int) ( $result['processed'] ?? 0 ) > 0 ) {
				$result['partial'] = true;
				$result['message'] = (string) ( $result['message'] ?? __( 'Sync in progress or partially complete — see progress below.', 'sheetsync-for-woocommerce' ) );
			}
		}

		return $result;
	}
}

endif;
