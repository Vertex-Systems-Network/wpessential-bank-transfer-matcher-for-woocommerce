<?php
namespace WPEssential\BankTransferMatcherPro\Operations;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Redacted, finite-source operational diagnostics. No filenames, source paths,
 * account IDs, bank references, CSV content, mapping data, emails or payloads.
 * Reporting only: it never retries a job or changes financial state.
 */
final class FinanceOperationsSnapshot {
	private const MAX_ROWS = 20;
	private const SOURCES = [
		'import_batches' => [
			'table' => 'wbm_pro_import_batches',
			'states' => [ 'pending', 'processing', 'completed', 'failed', 'cancelled' ],
			'select' => 'id, status, valid_count, error_count, warning_count, created_at, updated_at',
		],
		'report_exports' => [
			'table' => 'wbm_pro_report_export_jobs',
			'states' => [ 'queued', 'running', 'ready', 'failed', 'cancelled', 'expired' ],
			'select' => 'id, status, pages_done, row_count, created_at, updated_at',
		],
		'mail_delivery' => [
			'table' => 'wbm_pro_notification_delivery',
			'states' => [ 'reserved', 'sent', 'failed' ],
			'select' => 'id, delivery_status AS status, created_at, completed_at',
		],
	];

	public function __construct( private readonly wpdb $db ) {}

	/** One bounded page per type, not a global count of outstanding tasks. */
	public function inspect( int $actor_id ): array {
		$this->authorize( $actor_id );
		$result = [];
		foreach ( self::SOURCES as $type => $spec ) {
			$table = $this->db->prefix . $spec['table'];
			$exists = $this->db->get_var( $this->db->prepare(
				'SHOW TABLES LIKE %s', $table
			) );
			$this->check_db();
			if ( $exists !== $table ) {
				$result[$type] = [ 'available' => false, 'rows' => [],
					'has_more' => null, 'page_scoped' => true ];
				continue;
			}
			$rows = $this->db->get_results(
				"SELECT {$spec['select']} FROM $table ORDER BY id DESC LIMIT " . ( self::MAX_ROWS + 1 ),
				ARRAY_A
			);
			$this->check_db();
			if ( ! is_array( $rows ) ) {
				throw new RuntimeException( 'Could not inspect finance job states.' );
			}
			$more = count( $rows ) > self::MAX_ROWS;
			$items = [];
			foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
				$id = self::number( $row['id'] ?? null );
				$state = $row['status'] ?? null;
				if ( $id < 1 || ! is_string( $state ) ||
					! in_array( $state, $spec['states'], true ) ) {
					throw new RuntimeException( 'Malformed finance job state; no diagnostic output.' );
				}
				$created = $row['created_at'] ?? null;
				if ( ! is_string( $created ) ||
					! preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $created ) ) {
					throw new RuntimeException( 'Malformed finance diagnostic timestamp.' );
				}
				$age = strtotime( $created . ' UTC' );
				if ( false === $age ) throw new RuntimeException( 'Unparseable finance job age.' );
				$days = max( 0, intdiv( time() - $age, DAY_IN_SECONDS ) );
				$band = $days === 0 ? 'under-1d' :
					( $days <= 3 ? '1-3d' : ( $days <= 7 ? '4-7d' : 'over-7d' ) );
				$entry = [
					'job_id' => $id, 'state' => $state, 'age_band' => $band,
					'created_at_utc' => $created,
					'processed_rows' => 0, 'error_rows' => 0,
					'warning_rows' => 0,
				];
				if ( 'import_batches' === $type ) {
					$entry['processed_rows'] = self::number( $row['valid_count'] ) +
						self::number( $row['error_count'] );
					$entry['error_rows'] = self::number( $row['error_count'] );
					$entry['warning_rows'] = self::number( $row['warning_count'] );
				}
				if ( 'report_exports' === $type ) {
					$entry['processed_rows'] = self::number( $row['row_count'] );
				}
				$items[] = $entry;
			}
			$result[$type] = [ 'available' => true,
				'rows' => $items, 'has_more' => $more, 'page_scoped' => true ];
		}
		return [
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'no_bank_clearance_claim' => true,
			'sources' => $result,
		];
	}

	/** Fixed, <=60-row redacted support CSV with no source IDs or recipient IDs. */
	public static function to_redacted_csv( array $snapshot ): string {
		if ( ( $snapshot['no_bank_clearance_claim'] ?? null ) !== true ||
			! is_array( $snapshot['sources'] ?? null ) ||
			array_keys( $snapshot['sources'] ) !== array_keys( self::SOURCES ) ) {
			throw new InvalidArgumentException( 'Invalid finance diagnostics source set.' );
		}
		$out = fopen( 'php://temp/maxmemory:131072', 'w+' );
		if ( false === $out ) throw new RuntimeException( 'Cannot encode redacted diagnostics.' );
		try {
			fputcsv( $out, [ 'source', 'state', 'age_band', 'processed_rows',
				'error_rows', 'warning_rows', 'page_scoped' ], ',', '"', '', "\n" );
			foreach ( $snapshot['sources'] as $type => $source ) {
				if ( ! is_array( $source ) ||
					( $source['page_scoped'] ?? null ) !== true ||
					! is_array( $source['rows'] ?? null ) ||
					! array_is_list( $source['rows'] ) ||
					count( $source['rows'] ) > self::MAX_ROWS ) {
					throw new InvalidArgumentException( 'Invalid bounded finance diagnostic rowset.' );
				}
				if ( $source['available'] === false ) {
					fputcsv( $out, [ $type, 'source_unavailable', '', 0, 0, 0, 'yes' ], ',', '"', '', "\n" );
					continue;
				}
				foreach ( $source['rows'] as $item ) {
					if ( ! is_array( $item ) ||
						! in_array( $item['state'] ?? null, self::SOURCES[$type]['states'], true ) ||
						! in_array( $item['age_band'] ?? null, [ 'under-1d', '1-3d','4-7d','over-7d' ], true ) ) {
						throw new InvalidArgumentException( 'Unsafe finance diagnostic row.' );
					}
					$values = [];
					foreach ( [ 'processed_rows', 'error_rows', 'warning_rows' ] as $key ) {
						$values[] = self::number( $item[$key] ?? null );
					}
					fputcsv( $out, array_merge( [ $type, $item['state'], $item['age_band'] ],
						$values, [ 'yes' ] ), ',', '"', '', "\n" );
				}
			}
			$length = ftell( $out );
			if ( false === $length || $length > 131072 || rewind( $out ) === false ) {
				throw new RuntimeException( 'Diagnostics CSV exceeds its strict bound.' );
			}
			$csv = stream_get_contents( $out );
			if ( ! is_string( $csv ) ) throw new RuntimeException( 'Could not read redacted diagnostics.' );
			return $csv;
		} finally {
			fclose( $out );
		}
	}

	private function authorize( int $actor_id ): void {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ) {
			throw new RuntimeException( 'Bank operations diagnostics require finance manager permissions.' );
		}
	}

	private static function number( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) ||
			! preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Unsafe finance diagnostic counter.' );
		}
		return (int) $value;
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Finance diagnostics storage unavailable.' );
		}
	}
}
