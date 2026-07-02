<?php
/**
 * WP-CLI commands for SheetSync background queue.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Run SheetSync background maintenance from the command line.
 */
class SheetSync_CLI_Queue_Command {

	/**
	 * Run Action Scheduler, Smart Poll, stuck jobs, and media queue.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Max seconds to spend. Default: 25.
	 *
	 * [--ping]
	 * : Health check only (no queue processing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp sheetsync run-queue
	 *     wp sheetsync run-queue --seconds=45
	 *     wp sheetsync run-queue --ping
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'SheetSync_External_Cron', false ) ) {
			WP_CLI::error( 'SheetSync external cron is not loaded.' );
		}

		if ( isset( $assoc_args['ping'] ) ) {
			$payload = SheetSync_External_Cron::ping();
			WP_CLI::log( wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
			WP_CLI::success( 'Ping OK.' );
			return;
		}

		$seconds = isset( $assoc_args['seconds'] ) ? (int) $assoc_args['seconds'] : 25;
		$result  = SheetSync_External_Cron::run( $seconds );

		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );

		$past_due = (int) ( $result['scheduler_after']['past_due'] ?? 0 );
		if ( $past_due > 5 ) {
			WP_CLI::warning( sprintf( '%d Action Scheduler tasks still past due.', $past_due ) );
		} else {
			WP_CLI::success( 'Queue run finished.' );
		}
	}
}

WP_CLI::add_command( 'sheetsync run-queue', 'SheetSync_CLI_Queue_Command' );
