<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Bounded, private, actor-owned Action Scheduler CSV export job.
 *
 * At most 10 x 100-row source pages and 5 MiB result are kept in DB, never a
 * public uploads URL. The worker impersonates ONLY the original still-
 * authorized actor for read-only ReportQueryRepository calls, restores the
 * previous actor, and never invokes payment/allocation transitions.
 */
final class AsyncReportExportJob {
	public const HOOK = 'wpessential_wbm_pro_report_export_page';
	public const EXPIRY_HOOK = 'wpessential_wbm_pro_report_export_expire';
	private const VERSION = 'wpessential_wbm_pro_async_export_v1';
	private const PAGES = 10;
	private const PAGE_SIZE = 100;
	private const MAX_BYTES = 5242880;
	private const MAX_LIVE_JOBS = 2;
	private const RETAIN_SECONDS = 7200;
	private const GROUP = 'wpessential-wbm-report-export';

	public function __construct( private readonly wpdb $db ) {}

	public static function register(): void {
		add_action( self::HOOK, static function ( int $job_id ): void {
			global $wpdb;
			( new self( $wpdb ) )->process( $job_id );
		}, 10, 1 );
		add_action( self::EXPIRY_HOOK, static function ( int $job_id ): void {
			global $wpdb;
			( new self( $wpdb ) )->expire( $job_id );
		}, 10, 1 );
	}

	public function start( ReportDefinition $scope, int $actor_id, string $nonce ): int {
		$this->require_actor( $actor_id, $nonce, 'wbm_pro_export_report' );
		if ( ! function_exists( 'as_enqueue_async_action' ) ||
			! function_exists( 'as_schedule_single_action' ) ) {
			throw new RuntimeException( 'Action Scheduler is required for queued exports.' );
		}
		$this->install();
		$filters = $scope->canonical();
		unset( $filters['version'] );
		$filters['limit'] = self::PAGE_SIZE;
		$filters = ReportDefinition::from_array( $filters )->canonical();
		$json = wp_json_encode( $filters );
		if ( ! is_string( $json ) ) throw new RuntimeException( 'Could not encode export scope.' );
		$lock = 'wbm-export-user-' . substr( hash( 'sha256', $this->db->prefix . '/' . $actor_id ), 0, 32 );
		$this->take_lock( $lock, 3 );
		try {
			$table = $this->table();
			$active = $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE actor_id = %d
				 AND expires_at > UTC_TIMESTAMP() AND status IN ('queued','running','ready')",
				$actor_id
			) );
			$this->check_db();
			if ( null === $active || ! ctype_digit( (string) $active ) ||
				(int) $active >= self::MAX_LIVE_JOBS ) {
				throw new RuntimeException( 'Only two active or downloadable export jobs per finance actor.' );
			}
			$created = $this->db->query( $this->db->prepare(
				"INSERT INTO $table
				(actor_id, scope_json, status, pages_done, row_count, next_before_id,
				 csv_data, csv_sha256, byte_count, created_at, updated_at, expires_at)
				VALUES (%d,%s,'queued',0,0,%d,'','',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),
				 DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND))",
				$actor_id, $json, $scope->before_id, self::RETAIN_SECONDS
			) );
			$this->check_db();
			if ( 1 !== $created ) throw new RuntimeException( 'Could not persist export job.' );
			$job_id = (int) $this->db->insert_id;
			// An enqueue failure is explicit and reversible; no silent queue.
			$id = as_enqueue_async_action( self::HOOK, [ $job_id ], self::GROUP, true );
			if ( ! is_int( $id ) || $id < 1 ) {
				$this->db->query( $this->db->prepare(
					"UPDATE $table SET status='failed',csv_data='',updated_at=UTC_TIMESTAMP()
					 WHERE id=%d AND actor_id=%d", $job_id, $actor_id
				) );
				throw new RuntimeException( 'Could not enqueue report export.' );
			}
			as_schedule_single_action( time() + self::RETAIN_SECONDS,
				self::EXPIRY_HOOK, [ $job_id ], self::GROUP, true );
			return $job_id;
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** Run at most one 100-row page; no web-request or actor nonce at cron time. */
	public function process( int $job_id ): void {
		if ( $job_id < 1 ) throw new InvalidArgumentException( 'Invalid report export job.' );
		$this->install();
		$lock = $this->job_lock( $job_id );
		$this->take_lock( $lock, 0 );
		$next = false;
		try {
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM {$this->table()} WHERE id=%d", $job_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $row ) ) throw new RuntimeException( 'Missing export job.' );
			if ( in_array( $row['status'], [ 'ready','failed','cancelled','expired' ], true ) ) return;
			if ( ! in_array( $row['status'], [ 'queued','running' ], true ) ) {
				throw new RuntimeException( 'Invalid export job state.' );
			}
			if ( $this->expired( $row ) ) {
				$this->expire_row( $job_id );
				return;
			}
			$actor_id = (int) $row['actor_id'];
			if ( ! $this->has_capabilities( $actor_id ) ) {
				$this->fail_row( $job_id );
				return;
			}
			$scope = json_decode( (string) $row['scope_json'], true );
			if ( ! is_array( $scope ) || ( $scope['version'] ?? null ) !== 1 ) {
				$this->fail_row( $job_id );
				return;
			}
			unset( $scope['version'] );
			$scope['before_id'] = (int) $row['next_before_id'];
			$scope['limit'] = self::PAGE_SIZE;
			$definition = ReportDefinition::from_array( $scope );
			if ( (int) $row['pages_done'] >= self::PAGES ) {
				$this->fail_row( $job_id );
				return;
			}
			$previous = get_current_user_id();
			try {
				wp_set_current_user( $actor_id );
				$result = ( new ReportQueryRepository( $this->db ) )->run( $definition );
				$page_csv = ReportCsvEncoder::encode( $definition, $result );
			} finally {
				wp_set_current_user( $previous );
			}
			$next_cursor = (int) $result['next_before_id'];
			if ( $result['has_more'] === true && ( $next_cursor < 1 ||
				( (int) $row['next_before_id'] > 0 &&
					$next_cursor >= (int) $row['next_before_id'] ) ) ) {
				throw new RuntimeException( 'Non-progressing report export cursor.' );
			}
			// All pages share one encoder header; append only subsequent rows.
			if ( (int) $row['pages_done'] > 0 ) {
				$first_newline = strpos( $page_csv, "\n" );
				if ( false === $first_newline ) throw new RuntimeException( 'Missing CSV page header.' );
				$page_csv = substr( $page_csv, $first_newline + 1 );
			}
			$combined = (string) $row['csv_data'] . $page_csv;
			$bytes = strlen( $combined );
			if ( $bytes > self::MAX_BYTES ) {
				throw new RuntimeException( 'Async report exceeds private 5 MiB export bound.' );
			}
			$pages = (int) $row['pages_done'] + 1;
			$rows = (int) $row['row_count'] + count( $result['rows'] );
			$next = $result['has_more'] === true;
			if ( $next && $pages >= self::PAGES ) {
				throw new RuntimeException( 'More than ten pages; narrow report date/account filters.' );
			}
			$status = $next ? 'running' : 'ready';
			$written = $this->db->query( $this->db->prepare(
				"UPDATE {$this->table()} SET
				status=%s, pages_done=%d, row_count=%d, next_before_id=%d,
				csv_data=%s, csv_sha256=%s, byte_count=%d,
				updated_at=UTC_TIMESTAMP() WHERE id=%d AND status IN ('queued','running')",
				$status, $pages, $rows, $next_cursor, $combined,
				hash( 'sha256', $combined ), $bytes, $job_id
			) );
			$this->check_db();
			if ( 1 !== $written ) throw new RuntimeException( 'Could not persist export page checkpoint.' );
		} catch ( Throwable $error ) {
			// A failed job does not expose partial financial CSV data.
			$this->fail_row( $job_id );
			throw $error;
		} finally {
			$this->release_lock( $lock );
		}
		if ( $next ) {
			$id = as_enqueue_async_action( self::HOOK, [ $job_id ], self::GROUP, true );
			if ( ! is_int( $id ) || $id < 1 ) {
				$this->fail_row( $job_id );
				throw new RuntimeException( 'Could not queue the next export page.' );
			}
		}
	}

	/** Owner-only status, never returns CSV, private DB paths or source IDs. */
	public function status( int $job_id, int $actor_id ): array {
		$this->require_actor( $actor_id );
		$this->install();
		$row = $this->owned( $job_id, $actor_id );
		if ( $this->expired( $row ) ) {
			$this->expire( $job_id );
			$row['status'] = 'expired';
			$row['row_count'] = 0;
			$row['byte_count'] = 0;
		}
		return [
			'id' => $job_id, 'status' => (string) $row['status'],
			'pages_done' => (int) $row['pages_done'],
			'row_count' => (int) $row['row_count'],
			'byte_count' => (int) $row['byte_count'],
			'can_download' => 'ready' === $row['status'],
		];
	}

	/** POST nonce required, source/user permission is rechecked at retrieval. */
	public function download( int $job_id, int $actor_id, string $nonce ): array {
		$this->require_actor( $actor_id, $nonce, 'wbm_pro_download_queued_export' );
		$this->install();
		$row = $this->owned( $job_id, $actor_id );
		if ( $this->expired( $row ) || 'ready' !== $row['status'] ||
			! hash_equals( (string) $row['csv_sha256'],
				hash( 'sha256', (string) $row['csv_data'] ) ) ) {
			throw new RuntimeException( 'Export not ready, expired, or changed.' );
		}
		$this->db->query( $this->db->prepare(
			"INSERT INTO {$this->db->prefix}wbm_pro_report_exports
			(actor_id, report_key, scope_sha256, csv_sha256, row_count, byte_count, created_at)
			 VALUES (%d,%s,%s,%s,%d,%d,UTC_TIMESTAMP())",
			$actor_id, (string) ( json_decode( $row['scope_json'], true )['report'] ?? '' ),
			hash( 'sha256', (string) $row['scope_json'] ),
			(string) $row['csv_sha256'], (int) $row['row_count'], (int) $row['byte_count']
		) );
		$this->check_db();
		return [
			'filename' => 'wbm-private-export-' . $job_id . '.csv',
			'csv' => (string) $row['csv_data'],
		];
	}

	public function cancel( int $job_id, int $actor_id, string $nonce ): void {
		$this->require_actor( $actor_id, $nonce, 'wbm_pro_cancel_queued_export' );
		$this->install();
		$lock = $this->job_lock( $job_id );
		$this->take_lock( $lock, 3 );
		try {
			$row = $this->owned( $job_id, $actor_id );
			if ( ! in_array( $row['status'], [ 'queued','running','ready','failed' ], true ) ) {
				throw new RuntimeException( 'Export can no longer be cancelled.' );
			}
			$this->db->query( $this->db->prepare(
				"UPDATE {$this->table()} SET status='cancelled',csv_data='',byte_count=0,
				 csv_sha256='',updated_at=UTC_TIMESTAMP() WHERE id=%d AND actor_id=%d",
				$job_id, $actor_id
			) );
			$this->check_db();
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** Expires even if a background queue is delayed or disabled. */
	public function expire( int $job_id ): void {
		$this->install();
		$lock = $this->job_lock( $job_id );
		$this->take_lock( $lock, 3 );
		try {
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT expires_at FROM {$this->table()} WHERE id=%d", $job_id
			), ARRAY_A );
			$this->check_db();
			if ( is_array( $row ) && $this->expired( $row ) ) {
				$this->expire_row( $job_id );
			}
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function owned( int $job_id, int $actor_id ): array {
		if ( $job_id < 1 ) throw new InvalidArgumentException( 'Invalid export job identifier.' );
		$row = $this->db->get_row( $this->db->prepare(
			"SELECT * FROM {$this->table()} WHERE id=%d AND actor_id=%d",
			$job_id, $actor_id
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $row ) ) throw new RuntimeException( 'This export job is unavailable.' );
		return $row;
	}

	private function expired( array $row ): bool {
		return (string) $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' );
	}

	private function fail_row( int $job_id ): void {
		$this->db->query( $this->db->prepare(
			"UPDATE {$this->table()} SET status='failed',csv_data='',csv_sha256='',
			 byte_count=0,updated_at=UTC_TIMESTAMP() WHERE id=%d
			 AND status IN ('queued','running')", $job_id
		) );
		$this->check_db();
	}

	private function expire_row( int $job_id ): void {
		$this->db->query( $this->db->prepare(
			"UPDATE {$this->table()} SET status='expired',csv_data='',csv_sha256='',
			 byte_count=0,updated_at=UTC_TIMESTAMP() WHERE id=%d", $job_id
		) );
		$this->check_db();
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_report_export_jobs';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					actor_id BIGINT UNSIGNED NOT NULL,
					scope_json TEXT NOT NULL,
					status VARCHAR(16) NOT NULL,
					pages_done TINYINT UNSIGNED NOT NULL,
					row_count SMALLINT UNSIGNED NOT NULL,
					next_before_id BIGINT UNSIGNED NOT NULL,
					csv_data MEDIUMTEXT NOT NULL,
					csv_sha256 CHAR(64) NOT NULL,
					byte_count INT UNSIGNED NOT NULL,
					created_at DATETIME NOT NULL,
					updated_at DATETIME NOT NULL,
					expires_at DATETIME NOT NULL,
					KEY actor_status (actor_id, status, expires_at),
					KEY expires_at (expires_at)
				) ENGINE=InnoDB $collation" );
			},
		] );
		// The ready-download audit table is shared with page CSV exports.
		( new ReportCsvExportService( $this->db ) )->install_audit_schema();
	}

	private function require_actor( int $actor_id, string $nonce = '', string $nonce_action = '' ): void {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! $this->has_capabilities( $actor_id ) ||
			( '' !== $nonce_action && ! wp_verify_nonce( $nonce, $nonce_action ) ) ) {
			throw new RuntimeException( 'Authorized finance actor and verified nonce are required.' );
		}
	}

	private function has_capabilities( int $actor_id ): bool {
		return $actor_id > 0 && user_can( $actor_id, FreeCaps::APPROVE ) &&
			user_can( $actor_id, ProCaps::IMPORT );
	}

	private function table(): string {
		return $this->db->prefix . 'wbm_pro_report_export_jobs';
	}

	private function job_lock( int $id ): string {
		return 'wbm-export-job-' . substr( hash( 'sha256', $this->db->prefix . '/' . $id ), 0, 32 );
	}

	private function take_lock( string $lock, int $seconds ): void {
		$held = $this->db->get_var( $this->db->prepare(
			'SELECT GET_LOCK(%s, %d)', $lock, $seconds
		) );
		$this->check_db();
		if ( '1' !== (string) $held ) {
			throw new RuntimeException( 'Export job is being processed by another worker.' );
		}
	}

	private function release_lock( string $lock ): void {
		$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		$this->check_db();
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Private finance export storage unavailable.' );
		}
	}
}
