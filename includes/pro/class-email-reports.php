<?php
/**
 * PRO: Scheduled sync email reports (daily / weekly digests).
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class SheetSync_Email_Reports {

	const HOOK = 'sheetsync_email_report';

	const REPORT_HOUR = 8;

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( __CLASS__, 'maybe_bootstrap_schedule' ), 20 );
	}

	public static function maybe_bootstrap_schedule(): void {
		if ( self::interval() && ! wp_next_scheduled( self::HOOK ) ) {
			self::sync_schedule();
		}
	}

	public static function interval(): string {
		if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) {
			return '';
		}
		$settings = get_option( 'sheetsync_settings', array() );
		$interval = (string) ( $settings['email_report_interval'] ?? '' );
		return in_array( $interval, array( 'daily', 'weekly' ), true ) ? $interval : '';
	}

	public static function since_utc_for_interval( string $interval ): string {
		$seconds = ( 'weekly' === $interval ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		return gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}

	public static function period_label( string $interval ): string {
		return 'weekly' === $interval
			? __( 'Last 7 days', 'sheetsync-for-woocommerce' )
			: __( 'Last 24 hours', 'sheetsync-for-woocommerce' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function build_report( string $interval ): array {
		$since_utc = self::since_utc_for_interval( $interval );
		$stats     = class_exists( 'SheetSync_Logger', false )
			? SheetSync_Logger::get_period_stats( $since_utc )
			: array(
				'runs'           => 0,
				'success'        => 0,
				'partial'        => 0,
				'error'          => 0,
				'rows_processed' => 0,
				'rows_errored'   => 0,
			);

		$connections = class_exists( 'SheetSync_Logger', false )
			? SheetSync_Logger::get_period_by_connection( $since_utc, 12 )
			: array();

		$issues = class_exists( 'SheetSync_Logger', false )
			? SheetSync_Logger::get_period_issues( $since_utc, 5 )
			: array();

		$conflicts = function_exists( 'sheetsync_count_all_conflicts' )
			? sheetsync_count_all_conflicts()
			: 0;

		$health = function_exists( 'sheetsync_get_action_scheduler_health' )
			? sheetsync_get_action_scheduler_health()
			: array( 'ok' => true, 'message' => '', 'past_due' => 0 );

		global $wpdb;
		$active_connections = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}sheetsync_connections WHERE status = 'active'"
		);

		return array(
			'interval'           => $interval,
			'period_label'       => self::period_label( $interval ),
			'since_utc'          => $since_utc,
			'generated_at'       => current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'stats'              => $stats,
			'connections'        => $connections,
			'issues'             => $issues,
			'open_conflicts'     => $conflicts,
			'scheduler_ok'       => (bool) ( $health['ok'] ?? true ),
			'scheduler_message'  => (string) ( $health['message'] ?? '' ),
			'scheduler_past_due' => (int) ( $health['past_due'] ?? 0 ),
			'active_connections' => $active_connections,
			'reports_url'        => admin_url( 'admin.php?page=sheetsync-reports' ),
			'logs_url'           => admin_url( 'admin.php?page=sheetsync-logs' ),
			'conflicts_url'      => admin_url( 'admin.php?page=sheetsync-conflicts' ),
			'settings_url'       => admin_url( 'admin.php?page=sheetsync-settings' ),
		);
	}

	public static function send_report( string $interval, bool $force = false ): bool {
		if ( ! sheetsync_is_pro() ) {
			return false;
		}

		$configured = self::interval();
		if ( ! $force && ( '' === $configured || $configured !== $interval ) ) {
			return false;
		}

		$settings = get_option( 'sheetsync_settings', array() );
		$to       = sanitize_email( $settings['notification_email'] ?? get_option( 'admin_email' ) );
		if ( empty( $to ) ) {
			return false;
		}

		$report = self::build_report( $interval );
		$title  = 'weekly' === $interval
			? __( 'Weekly Sync Report', 'sheetsync-for-woocommerce' )
			: __( 'Daily Sync Report', 'sheetsync-for-woocommerce' );

		$subject = sprintf(
			/* translators: 1: report title, 2: site name */
			__( '[SheetSync] %1$s — %2$s', 'sheetsync-for-woocommerce' ),
			$title,
			get_bloginfo( 'name' )
		);

		$body    = self::build_email_html( $report, $title );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * @param array<string, mixed> $report
	 */
	public static function build_email_html( array $report, string $title ): string {
		$stats     = is_array( $report['stats'] ?? null ) ? $report['stats'] : array();
		$runs      = (int) ( $stats['runs'] ?? 0 );
		$success   = (int) ( $stats['success'] ?? 0 );
		$partial   = (int) ( $stats['partial'] ?? 0 );
		$errors    = (int) ( $stats['error'] ?? 0 );
		$processed = (int) ( $stats['rows_processed'] ?? 0 );
		$errored   = (int) ( $stats['rows_errored'] ?? 0 );
		$conflicts = (int) ( $report['open_conflicts'] ?? 0 );
		$sched_ok  = ! empty( $report['scheduler_ok'] );

		$esc_title   = esc_html( $title );
		$esc_period  = esc_html( (string) ( $report['period_label'] ?? '' ) );
		$esc_site    = esc_html( get_bloginfo( 'name' ) );
		$esc_time    = esc_html( (string) ( $report['generated_at'] ?? '' ) );
		$reports_url = esc_url( (string) ( $report['reports_url'] ?? admin_url( 'admin.php?page=sheetsync-reports' ) ) );
		$logs_url    = esc_url( (string) ( $report['logs_url'] ?? admin_url( 'admin.php?page=sheetsync-logs' ) ) );
		$conf_url    = esc_url( (string) ( $report['conflicts_url'] ?? admin_url( 'admin.php?page=sheetsync-conflicts' ) ) );

		$sched_icon = $sched_ok ? '&#9989;' : '&#9888;';
		$sched_text = $sched_ok
			? esc_html__( 'Background tasks healthy', 'sheetsync-for-woocommerce' )
			: esc_html( (string) ( $report['scheduler_message'] ?? __( 'Background tasks need attention', 'sheetsync-for-woocommerce' ) ) );

		$conn_rows = '';
		$connections = is_array( $report['connections'] ?? null ) ? $report['connections'] : array();
		foreach ( $connections as $conn ) {
			$name = esc_html( (string) ( $conn['connection_name'] ?? '' ) );
			$conn_rows .= '<tr><td style="padding:8px;border:1px solid #e5e7eb;">' . $name
				. '</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:center;">' . (int) ( $conn['runs'] ?? 0 )
				. '</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:center;">' . (int) ( $conn['rows_processed'] ?? 0 )
				. '</td><td style="padding:8px;border:1px solid #e5e7eb;text-align:center;color:#dc2626;">' . (int) ( $conn['errors'] ?? 0 )
				. '</td></tr>';
		}
		if ( '' === $conn_rows ) {
			$conn_rows = '<tr><td colspan="4" style="padding:12px;border:1px solid #e5e7eb;color:#6b7280;">'
				. esc_html__( 'No sync activity in this period.', 'sheetsync-for-woocommerce' ) . '</td></tr>';
		}

		$issue_list = '';
		$issues     = is_array( $report['issues'] ?? null ) ? $report['issues'] : array();
		foreach ( $issues as $issue ) {
			$msg = class_exists( 'SheetSync_Logger', false )
				? SheetSync_Logger::display_message( (string) ( $issue['message'] ?? '' ) )
				: (string) ( $issue['message'] ?? '' );
			$issue_list .= '<li style="margin-bottom:8px;"><strong>' . esc_html( (string) ( $issue['connection_name'] ?? '' ) ) . '</strong> — '
				. esc_html( $msg ) . '</li>';
		}
		$issues_block = '' !== $issue_list
			? '<h3 style="margin:20px 0 8px;font-size:15px;">' . esc_html__( 'Needs attention', 'sheetsync-for-woocommerce' ) . '</h3><ul style="padding-left:18px;margin:0;">' . $issue_list . '</ul>'
			: '<p style="color:#065f46;margin:16px 0 0;">' . esc_html__( 'No errors or partial syncs in this period.', 'sheetsync-for-woocommerce' ) . '</p>';

		$conflict_block = '';
		if ( $conflicts > 0 ) {
			$conflict_block = '<p style="margin:16px 0;padding:12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;">'
				. sprintf(
					/* translators: %d: conflict count */
					esc_html__( '%d sync conflicts are waiting for review.', 'sheetsync-for-woocommerce' ),
					$conflicts
				)
				. ' <a href="' . $conf_url . '">' . esc_html__( 'Open Sync Conflicts', 'sheetsync-for-woocommerce' ) . '</a></p>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
		return "
		<html><body style='font-family:sans-serif;color:#1f2937;'>
		<div style='max-width:560px;margin:0 auto;'>
			<div style='background:#1e6e42;padding:20px 24px;border-radius:8px 8px 0 0;'>
				<h1 style='color:#fff;margin:0;font-size:18px;'>SheetSync for WooCommerce</h1>
			</div>
			<div style='padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;'>
				<h2 style='margin-top:0;'>{$esc_title}</h2>
				<p style='margin:0 0 16px;color:#6b7280;'>{$esc_period} · {$esc_site} · {$esc_time}</p>
				<table style='width:100%;border-collapse:collapse;margin:0 0 16px;'>
					<tr style='background:#f0f7f3;'>
						<td style='padding:10px;border:1px solid #e5e7eb;font-weight:600;'>" . esc_html__( 'Sync runs', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;font-weight:700;'>{$runs}</td>
					</tr>
					<tr>
						<td style='padding:10px;border:1px solid #e5e7eb;'>" . esc_html__( 'Successful', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;color:#065f46;'>{$success}</td>
					</tr>
					<tr style='background:#f9fafb;'>
						<td style='padding:10px;border:1px solid #e5e7eb;'>" . esc_html__( 'Partial', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;'>{$partial}</td>
					</tr>
					<tr>
						<td style='padding:10px;border:1px solid #e5e7eb;'>" . esc_html__( 'Failed', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;color:#dc2626;'>{$errors}</td>
					</tr>
					<tr style='background:#f9fafb;'>
						<td style='padding:10px;border:1px solid #e5e7eb;'>" . esc_html__( 'Rows updated', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;'>{$processed}</td>
					</tr>
					<tr>
						<td style='padding:10px;border:1px solid #e5e7eb;'>" . esc_html__( 'Row errors', 'sheetsync-for-woocommerce' ) . "</td>
						<td style='padding:10px;border:1px solid #e5e7eb;color:#dc2626;'>{$errored}</td>
					</tr>
				</table>
				<p style='margin:0 0 8px;'>{$sched_icon} {$sched_text}</p>
				{$conflict_block}
				<h3 style='margin:20px 0 8px;font-size:15px;'>" . esc_html__( 'Activity by connection', 'sheetsync-for-woocommerce' ) . "</h3>
				<table style='width:100%;border-collapse:collapse;font-size:13px;'>
					<tr style='background:#f3f4f6;'>
						<th style='padding:8px;border:1px solid #e5e7eb;text-align:left;'>" . esc_html__( 'Connection', 'sheetsync-for-woocommerce' ) . "</th>
						<th style='padding:8px;border:1px solid #e5e7eb;'>" . esc_html__( 'Runs', 'sheetsync-for-woocommerce' ) . "</th>
						<th style='padding:8px;border:1px solid #e5e7eb;'>" . esc_html__( 'Rows', 'sheetsync-for-woocommerce' ) . "</th>
						<th style='padding:8px;border:1px solid #e5e7eb;'>" . esc_html__( 'Errors', 'sheetsync-for-woocommerce' ) . "</th>
					</tr>
					{$conn_rows}
				</table>
				{$issues_block}
				<p style='margin-top:24px;'>
					<a href='{$reports_url}' style='display:inline-block;background:#1e6e42;color:#fff;padding:10px 16px;text-decoration:none;border-radius:6px;margin-right:8px;'>" . esc_html__( 'Open Sync Reports', 'sheetsync-for-woocommerce' ) . "</a>
					<a href='{$logs_url}' style='display:inline-block;background:#fff;color:#1e6e42;padding:10px 16px;text-decoration:none;border-radius:6px;border:1px solid #1e6e42;'>" . esc_html__( 'View logs', 'sheetsync-for-woocommerce' ) . "</a>
				</p>
			</div>
			<p style='font-size:11px;color:#9ca3af;text-align:center;margin-top:16px;'>" . esc_html__( 'Manage report frequency in SheetSync → Settings → Email reports.', 'sheetsync-for-woocommerce' ) . "</p>
		</div>
		</body></html>";
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function compute_next_timestamp( string $interval ): int {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( self::REPORT_HOUR, 0, 0 );

		if ( 'weekly' === $interval ) {
			$current_dow = (int) $now->format( 'N' );
			$days_ahead    = 1 - $current_dow;
			if ( $days_ahead < 0 ) {
				$days_ahead += 7;
			}
			if ( 0 === $days_ahead && $next->getTimestamp() <= time() ) {
				$days_ahead = 7;
			}
			if ( $days_ahead > 0 ) {
				$next = $now->setTime( self::REPORT_HOUR, 0, 0 )->modify( '+' . $days_ahead . ' days' );
			}
		} elseif ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	public static function sync_schedule(): void {
		self::unschedule();
		$interval = self::interval();
		if ( '' === $interval ) {
			return;
		}
		wp_schedule_single_event( self::compute_next_timestamp( $interval ), self::HOOK, array( $interval ) );
	}

	public function run_scheduled( string $interval = 'daily' ): void {
		$interval = in_array( $interval, array( 'daily', 'weekly' ), true ) ? $interval : 'daily';
		self::send_report( $interval );
		self::sync_schedule();
	}
}
