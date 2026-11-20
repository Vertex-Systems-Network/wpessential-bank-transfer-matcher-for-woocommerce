<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Permissioned, nonce-protected, audited ONE-PAGE report CSV generation.
 * No public endpoint; web adapter must enforce authenticated finance POST.
 * A larger multi-page/async export requires a separately reviewed job protocol.
 */
final class ReportCsvExportService {
	private const VERSION = 'wpessential_wbm_pro_export_audit_schema';
	private const MAX_PER_MINUTE = 3;

	public function __construct( private readonly wpdb $db ) {}

	/**
	 * @return array{filename:string,csv:string,sha256:string,row_count:int,
	 *   has_more:bool,next_before_id:int}
	 */
	public function export( ReportDefinition $scope, int $actor_id, string $nonce ): array {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! wp_verify_nonce( $nonce, 'wbm_pro_export_report' ) ) {
			throw new RuntimeException( 'Finance export requires verified finance access and request nonce.' );
		}

		// No public route or scheduled automation is attached to this service.
		$page = ( new ReportQueryRepository( $this->db ) )->run( $scope );
		$csv = ReportCsvEncoder::encode( $scope, $page );
		$rows = count( $page['rows'] );
		$digest = hash( 'sha256', $csv );
		$this->install();
		$this->audit( $scope, $actor_id, $rows, strlen( $csv ), $digest );
		return [
			'filename' => 'wbm-' . $scope->report . '-' . gmdate( 'Ymd' ) . '.csv',
			'csv' => $csv,
			'sha256' => $digest,
			'row_count' => $rows,
			'has_more' => $page['has_more'] === true,
			'next_before_id' => $page['next_before_id'],
		];
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_report_exports';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					actor_id BIGINT UNSIGNED NOT NULL,
					report_key VARCHAR(32) NOT NULL,
					scope_sha256 CHAR(64) NOT NULL,
					csv_sha256 CHAR(64) NOT NULL,
					row_count SMALLINT UNSIGNED NOT NULL,
					byte_count INT UNSIGNED NOT NULL,
					created_at DATETIME NOT NULL,
					KEY actor_window (actor_id, created_at),
					KEY report_window (report_key, created_at)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/** Serializes concurrent requests by actor so rate checks and audit are atomic. */
	private function audit( ReportDefinition $scope, int $actor_id,
		int $rows, int $bytes, string $sha256 ): void {
		$lock = 'wbm-report-' . substr(
			hash( 'sha256', $this->db->prefix . '/' . $actor_id ), 0, 32
		);
		$held = $this->db->get_var( $this->db->prepare(
			'SELECT GET_LOCK(%s, 3)', $lock
		) );
		$this->check_db();
		if ( '1' !== (string) $held ) {
			throw new RuntimeException( 'Finance report export is busy; retry safely.' );
		}
		try {
			$table = $this->db->prefix . 'wbm_pro_report_exports';
			$count = $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table
				 WHERE actor_id = %d AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE",
				$actor_id
			) );
			$this->check_db();
			if ( null === $count || ! ctype_digit( (string) $count ) ) {
				throw new RuntimeException( 'Finance export rate state unavailable.' );
			}
			if ( (int) $count >= self::MAX_PER_MINUTE ) {
				throw new RuntimeException( 'Finance export rate limit exceeded.' );
			}
			$inserted = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (actor_id, report_key, scope_sha256,
				 csv_sha256, row_count, byte_count, created_at)
				 VALUES (%d, %s, %s, %s, %d, %d, UTC_TIMESTAMP())",
				$actor_id, $scope->report, $scope->fingerprint(),
				$sha256, $rows, $bytes
			) );
			$this->check_db();
			if ( 1 !== $inserted ) {
				throw new RuntimeException( 'Finance export audit failed; export withheld.' );
			}
		} finally {
			$this->db->get_var( $this->db->prepare(
				'SELECT RELEASE_LOCK(%s)', $lock
			) );
			$this->check_db();
		}
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Finance export storage unavailable.' );
		}
	}
}
