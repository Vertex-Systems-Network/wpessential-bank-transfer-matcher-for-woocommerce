<?php
namespace WPEssential\BankTransferMatcherPro\Operations;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use wpdb;

/**
 * Explicit, manager-authorized recovery for FAILED import and private export
 * jobs only. Never approves imported rows, commits ledger allocations, marks
 * orders paid, sends email or skips source-file integrity checks.
 *
 * Original failed export is NEVER mutated/restarted: its validated scope is
 * copied into a newly bounded, independently audited Action Scheduler job.
 */
final class FinanceJobRecovery {
	private const VERSION = 'wpessential_wbm_pro_job_recovery_v1';

	public function __construct( private readonly wpdb $db ) {}

	/** @return array{operation:string,source_id:int,new_job_id:int,audit_id:int} */
	public function recover( string $operation, int $source_id, int $actor_id,
		string $nonce, string $reason ): array {
		if ( ! in_array( $operation, [ 'retry_import', 'requeue_export' ], true ) ||
			$source_id < 1 ) {
			throw new InvalidArgumentException( 'Unsupported finance recovery operation or source ID.' );
		}
		$this->authorize( $actor_id, $nonce );
		$reason = trim( $reason );
		if ( strlen( $reason ) < 12 || strlen( $reason ) > 500 ||
			! preg_match( '//u', $reason ) ||
			preg_match( '/[\x00-\x1F\x7F]/', $reason ) ) {
			throw new InvalidArgumentException( 'Recovery requires a valid 12–500 character reason.' );
		}
		$this->install();
		$lock = 'wbm-ops-recovery-' . substr(
			hash( 'sha256', $this->db->prefix . '/' . $actor_id ), 0, 32
		);
		$held = $this->db->get_var( $this->db->prepare(
			'SELECT GET_LOCK(%s, 3)', $lock
		) );
		$this->check_db();
		if ( '1' !== (string) $held ) throw new RuntimeException( 'Another finance recovery is in progress.' );
		try {
			$table = $this->db->prefix . 'wbm_pro_ops_recovery';
			$recent = $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE actor_id = %d
				 AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR",
				$actor_id
			) );
			$duplicate = $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE source_id = %d AND operation = %s
				 AND outcome IN ('requested','queued')",
				$source_id, $operation
			) );
			$this->check_db();
			if ( null === $recent || (int) $recent >= 3 ||
				null === $duplicate || (int) $duplicate > 0 ) {
				throw new RuntimeException( 'Recovery rate limit or previous recovery already exists.' );
			}
			// Perform all authorization / source eligibility checks before
			// recording a request, to avoid a bad form permanently blocking a
			// valid recovery operation for another finance manager.
			$scope = null;
			if ( 'requeue_export' === $operation ) {
				$original = $this->db->get_row( $this->db->prepare(
					"SELECT actor_id,scope_json,status,expires_at
					 FROM {$this->db->prefix}wbm_pro_report_export_jobs WHERE id = %d",
					$source_id
				), ARRAY_A );
				$this->check_db();
				if ( ! is_array( $original ) ||
					(int) $original['actor_id'] !== $actor_id ||
					'failed' !== $original['status'] ||
					(string) $original['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) {
					throw new RuntimeException( 'Only your own unexpired failed private export can be requeued.' );
				}
				$raw = json_decode( (string) $original['scope_json'], true );
				if ( ! is_array( $raw ) || ( $raw['version'] ?? null ) !== 1 ) {
					throw new RuntimeException( 'Original private export scope is invalid.' );
				}
				unset( $raw['version'] );
				$scope = ReportDefinition::from_array( $raw );
			} else {
				$state = $this->db->get_row( $this->db->prepare(
					"SELECT status FROM {$this->db->prefix}wbm_pro_import_batches
					 WHERE id = %d", $source_id
				), ARRAY_A );
				$this->check_db();
				if ( ! is_array( $state ) || 'failed' !== $state['status'] ) {
					throw new RuntimeException( 'Only failed statement import batches may be retried.' );
				}
			}
			$insert = $this->db->query( $this->db->prepare(
				"INSERT INTO $table
				 (actor_id,source_id,operation,reason_sha256,outcome,new_job_id,created_at)
				 VALUES (%d,%d,%s,%s,'requested',0,UTC_TIMESTAMP())",
				$actor_id, $source_id, $operation, hash( 'sha256', $reason )
			) );
			$this->check_db();
			if ( 1 !== $insert ) throw new RuntimeException( 'Could not reserve an audited recovery.' );
			$audit_id = (int) $this->db->insert_id;
			$new_id = 0;
			try {
				if ( 'retry_import' === $operation ) {
					// Existing import retry rechecks private source existence
					// and the original SHA256 before scheduling.
					( new ImportJob( $this->db ) )->retry( $source_id );
				} else {
					$new_id = ( new AsyncReportExportJob( $this->db ) )->start(
						$scope, $actor_id, wp_create_nonce( 'wbm_pro_export_report' )
					);
				}
			} catch ( Throwable $error ) {
				$this->db->query( $this->db->prepare(
					"UPDATE $table SET outcome='blocked',completed_at=UTC_TIMESTAMP()
					 WHERE id=%d AND outcome='requested'", $audit_id
				) );
				$this->check_db();
				throw $error;
			}
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE $table SET outcome='queued',new_job_id=%d,
				 completed_at=UTC_TIMESTAMP()
				 WHERE id=%d AND outcome='requested'",
				$new_id, $audit_id
			) );
			$this->check_db();
			if ( 1 !== $updated ) throw new RuntimeException( 'Recovery was queued but audit finalization failed.' );
			return [ 'operation' => $operation, 'source_id' => $source_id,
				'new_job_id' => $new_id, 'audit_id' => $audit_id ];
		} finally {
			$this->db->get_var( $this->db->prepare(
				'SELECT RELEASE_LOCK(%s)', $lock
			) );
			$this->check_db();
		}
	}

	private function authorize( int $actor_id, string $nonce ): void {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ||
			! wp_verify_nonce( $nonce, 'wbm_pro_ops_recovery' ) ) {
			throw new RuntimeException( 'Verified finance manager nonce and capabilities required.' );
		}
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_ops_recovery';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					actor_id BIGINT UNSIGNED NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					operation VARCHAR(20) NOT NULL,
					reason_sha256 CHAR(64) NOT NULL,
					outcome VARCHAR(12) NOT NULL,
					new_job_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
					created_at DATETIME NOT NULL,
					completed_at DATETIME NULL,
					KEY actor_recent (actor_id,created_at),
					KEY source_recovery (operation,source_id,outcome)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Finance recovery audit storage unavailable.' );
		}
	}
}
