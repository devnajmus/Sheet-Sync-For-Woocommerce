<?php
/**
 * Groups and summarizes sheet validation issues for merchant-facing reports.
 *
 * @package SheetSync_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheet_Validation_Report', false ) ) :

class SheetSync_Sheet_Validation_Report {

	/**
	 * @param list<array<string, mixed>> $issues
	 * @param array{simple?: int, variable_parents?: int, variations?: int} $stats
	 * @return array{
	 *   ok: bool,
	 *   rows_checked: int,
	 *   stats: array,
	 *   summary: array,
	 *   issues: list<array>,
	 *   issues_detail: list<array>
	 * }
	 */
	public static function build( array $issues, array $stats, int $rows_checked ): array {
		$stats['total_rows'] = self::stats_total( $stats );

		$errors   = 0;
		$warnings = 0;
		$buckets  = array();

		foreach ( $issues as $issue ) {
			$level = (string) ( $issue['level'] ?? 'warn' );
			if ( 'error' === $level ) {
				++$errors;
			} elseif ( 'warn' === $level ) {
				++$warnings;
			} elseif ( 'ok' === $level ) {
				continue;
			}

			$group_key = (string) ( $issue['group_key'] ?? '' );
			if ( $group_key === '' ) {
				$group_key = (string) ( $issue['code'] ?? 'general' ) . '|' . md5( (string) ( $issue['message'] ?? '' ) );
			}

			if ( ! isset( $buckets[ $group_key ] ) ) {
				$buckets[ $group_key ] = array(
					'level'       => $level,
					'code'        => (string) ( $issue['code'] ?? 'general' ),
					'message'     => (string) ( $issue['message'] ?? '' ),
					'field'       => (string) ( $issue['field'] ?? '' ),
					'column'      => (string) ( $issue['column'] ?? '' ),
					'value'       => (string) ( $issue['value'] ?? '' ),
					'expected'    => (string) ( $issue['expected'] ?? '' ),
					'rows'        => array(),
					'edit_url'    => (string) ( $issue['edit_url'] ?? '' ),
					'group_key'   => $group_key,
				);
			}

			$row = (int) ( $issue['row'] ?? 0 );
			if ( $row > 0 && count( $buckets[ $group_key ]['rows'] ) < 12 ) {
				$buckets[ $group_key ]['rows'][] = $row;
			}
			if ( empty( $buckets[ $group_key ]['edit_url'] ) && ! empty( $issue['edit_url'] ) ) {
				$buckets[ $group_key ]['edit_url'] = (string) $issue['edit_url'];
			}
		}

		$grouped  = array();
		$detailed = array();
		$counts   = array(
			'invalid_prices'        => 0,
			'duplicate_sku'         => 0,
			'missing_required'      => 0,
			'missing_attributes'    => 0,
			'missing_attribute_terms' => 0,
			'other_errors'          => 0,
			'other_warnings'        => 0,
		);

		foreach ( $buckets as $bucket ) {
			$row_count = self::count_rows_for_bucket( $bucket, $issues );
			$display   = self::format_grouped_issue( $bucket, $row_count );
			$grouped[] = $display;

			if ( 'error' === $bucket['level'] ) {
				self::increment_summary_bucket( $counts, (string) $bucket['code'], $row_count, true );
			} else {
				self::increment_summary_bucket( $counts, (string) $bucket['code'], $row_count, false );
			}

			if ( $row_count <= 3 ) {
				foreach ( $bucket['rows'] as $row_num ) {
					$detailed[] = self::find_issue_for_row( $issues, (string) $bucket['group_key'], (int) $row_num );
				}
			}
		}

		usort(
			$grouped,
			static function ( array $a, array $b ): int {
				$order = array( 'error' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3 );
				$la    = $order[ $a['level'] ?? 'warn' ] ?? 9;
				$lb    = $order[ $b['level'] ?? 'warn' ] ?? 9;
				if ( $la !== $lb ) {
					return $la <=> $lb;
				}
				return ( (int) ( $b['row_count'] ?? 0 ) ) <=> ( (int) ( $a['row_count'] ?? 0 ) );
			}
		);

		$has_errors = $errors > 0;
		$summary    = array(
			'ready'    => ! $has_errors,
			'errors'   => $errors,
			'warnings' => $warnings,
			'counts'   => $counts,
			'products' => array(
				'simple'           => (int) ( $stats['simple'] ?? 0 ),
				'variable_parents' => (int) ( $stats['variable_parents'] ?? 0 ),
				'variations'       => (int) ( $stats['variations'] ?? 0 ),
				'total_rows'       => (int) ( $stats['total_rows'] ?? 0 ),
			),
		);

		if ( function_exists( 'sheetsync_cache_sheet_row_stats' ) && (int) ( $stats['total_rows'] ?? 0 ) > 0 ) {
			sheetsync_cache_sheet_row_stats(
				(int) ( $stats['connection_id'] ?? 0 ),
				array(
					'simple'            => (int) ( $stats['simple'] ?? 0 ),
					'variable_parents'  => (int) ( $stats['variable_parents'] ?? 0 ),
					'variations'        => (int) ( $stats['variations'] ?? 0 ),
					'total_rows'        => (int) ( $stats['total_rows'] ?? 0 ),
					'rows_checked'      => $rows_checked,
				)
			);
		}

		if ( ! $has_errors ) {
			array_unshift(
				$grouped,
				array(
					'level'     => 'ok',
					'code'      => 'ready',
					'message'   => sprintf(
						/* translators: 1: simple, 2: parents, 3: variations */
						__( 'Ready to import: %1$d simple, %2$d variable parents, %3$d variations (%4$d rows).', 'sheetsync-for-woocommerce' ),
						(int) ( $stats['simple'] ?? 0 ),
						(int) ( $stats['variable_parents'] ?? 0 ),
						(int) ( $stats['variations'] ?? 0 ),
						(int) ( $stats['total_rows'] ?? 0 )
					),
					'row'       => 0,
					'row_count' => 0,
					'rows'      => array(),
				)
			);
		}

		return array(
			'ok'            => ! $has_errors,
			'rows_checked'  => $rows_checked,
			'stats'         => $stats,
			'summary'       => $summary,
			'issues'        => $grouped,
			'issues_detail' => array_values( array_filter( $detailed ) ),
		);
	}

	/**
	 * @param array<string, mixed> $stats
	 */
	public static function stats_total( array $stats ): int {
		return (int) ( $stats['simple'] ?? 0 )
			+ (int) ( $stats['variable_parents'] ?? 0 )
			+ (int) ( $stats['variations'] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $bucket
	 * @param list<array<string, mixed>> $issues
	 */
	private static function count_rows_for_bucket( array $bucket, array $issues ): int {
		$group_key = (string) ( $bucket['group_key'] ?? '' );
		if ( $group_key === '' ) {
			return max( 1, count( $bucket['rows'] ?? array() ) );
		}
		$count = 0;
		foreach ( $issues as $issue ) {
			if ( (string) ( $issue['group_key'] ?? '' ) === $group_key && (int) ( $issue['row'] ?? 0 ) > 0 ) {
				++$count;
			}
		}
		return max( $count, count( $bucket['rows'] ?? array() ), 1 );
	}

	/**
	 * @param array<string, mixed> $bucket
	 * @return array<string, mixed>
	 */
	private static function format_grouped_issue( array $bucket, int $row_count ): array {
		$rows = array_values( array_unique( array_map( 'intval', (array) ( $bucket['rows'] ?? array() ) ) ) );
		sort( $rows, SORT_NUMERIC );

		$message = (string) ( $bucket['message'] ?? '' );
		if ( $row_count > 1 ) {
			$row_hint = self::format_row_sample( $rows, $row_count );
			if ( $row_hint !== '' ) {
				$message .= ' ' . $row_hint;
			}
		} elseif ( ! empty( $rows[0] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: sheet row number */
				__( '(Row %d)', 'sheetsync-for-woocommerce' ),
				(int) $rows[0]
			);
		}

		return array(
			'level'     => (string) ( $bucket['level'] ?? 'warn' ),
			'code'      => (string) ( $bucket['code'] ?? 'general' ),
			'message'   => $message,
			'row'       => ! empty( $rows[0] ) ? (int) $rows[0] : 0,
			'row_count' => $row_count,
			'rows'      => $rows,
			'field'     => (string) ( $bucket['field'] ?? '' ),
			'column'    => (string) ( $bucket['column'] ?? '' ),
			'value'     => (string) ( $bucket['value'] ?? '' ),
			'expected'  => (string) ( $bucket['expected'] ?? '' ),
			'edit_url'  => (string) ( $bucket['edit_url'] ?? '' ),
		);
	}

	/**
	 * @param int[] $rows
	 */
	private static function format_row_sample( array $rows, int $total ): string {
		if ( empty( $rows ) ) {
			return sprintf(
				/* translators: %d: number of rows */
				__( '(Referenced by %d rows)', 'sheetsync-for-woocommerce' ),
				$total
			);
		}
		$sample = implode(
			', ',
			array_map(
				static fn( int $r ): string => (string) $r,
				array_slice( $rows, 0, 5 )
			)
		);
		if ( $total > count( $rows ) ) {
			return sprintf(
				/* translators: 1: sample row list, 2: total row count */
				__( '(Rows %1$s — %2$d total)', 'sheetsync-for-woocommerce' ),
				$sample,
				$total
			);
		}
		return sprintf(
			/* translators: 1: row list, 2: row count */
			__( '(Rows %1$s — %2$d rows)', 'sheetsync-for-woocommerce' ),
			$sample,
			$total
		);
	}

	/**
	 * @param list<array<string, mixed>> $issues
	 * @return array<string, mixed>|null
	 */
	private static function find_issue_for_row( array $issues, string $group_key, int $row_num ): ?array {
		foreach ( $issues as $issue ) {
			if ( (string) ( $issue['group_key'] ?? '' ) === $group_key && (int) ( $issue['row'] ?? 0 ) === $row_num ) {
				return $issue;
			}
		}
		return null;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function increment_summary_bucket( array &$counts, string $code, int $row_count, bool $is_error ): void {
		switch ( $code ) {
			case 'invalid_price':
				$counts['invalid_prices'] += $row_count;
				break;
			case 'duplicate_sku':
				$counts['duplicate_sku'] += $row_count;
				break;
			case 'missing_attribute':
				$counts['missing_attributes'] += $row_count;
				break;
			case 'missing_attribute_term':
				$counts['missing_attribute_terms'] += $row_count;
				break;
			case 'missing_required':
				$counts['missing_required'] += $row_count;
				break;
			default:
				if ( $is_error ) {
					$counts['other_errors'] += $row_count;
				} else {
					$counts['other_warnings'] += $row_count;
				}
		}
	}
}

endif;
