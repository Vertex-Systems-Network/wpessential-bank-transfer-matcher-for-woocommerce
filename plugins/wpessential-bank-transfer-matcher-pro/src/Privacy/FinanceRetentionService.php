<?php
namespace WPEssential\BankTransferMatcherPro\Privacy;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Strict, bounded merchant-approved cleanup; never touches allocation ledger,
 * transaction rows, order evidence, customer claims, settings or WC orders.
 */
final class FinanceRetentionService {
	public const HOOK = 'wpessential_wbm_pro_retention_cleanup';
	private const GROUP = 'wpessential-wbm-retention';
	private const VERSION = 'wpessential_wbm_pro_retention_runs_v1';
	private const CHUNK = 25;
	private const AUDITS = [
		'diagnostic_audit_days' => 'wbm_pro_diagnostics_exports',
		'notification_audit_days' => 'wbm_pro_notification_delivery',
		'recovery_audit_days' => 'wbm_pro_ops_recovery',
		'finance_export_audit_days' => 'wbm_pro_report_exports',
	];

	public function __construct( private readonly wpdb $db ) {}

	public static function register(): void {
		add_action( self::HOOK, static function (): void {
			global $wpdb;
			( new self( $wpdb ) )->sweep( 0, '', false );
		} );
	}

	public function settings(): array {
		$stored = get_option( FinanceRetentionPreferences::OPTION, false );
		if ( false === $stored ) return FinanceRetentionPreferences::defaults();
		if ( ! is_array( $stored ) ) {
			throw new RuntimeException( 'Retention settings corrupted: cleanup disabled.' );
		}
		try {
			return FinanceRetentionPreferences::normalize( $stored );
		} catch ( InvalidArgumentException $error ) {
			throw new RuntimeException( 'Retention settings corrupted: cleanup disabled.', 0, $error );
		}
	}

	public function save( array $input, int $actor_id, string $nonce ): array {
		$this->authorize( $actor_id, $nonce, 'wbm_pro_retention_save' );
		$policy = FinanceRetentionPreferences::normalize( $input );
		if ( $policy['automatic_cleanup'] && (
			! function_exists( 'as_has_scheduled_action' ) ||
			! function_exists( 'as_schedule_recurring_action' ) ) ) {
			throw new RuntimeException( 'Cannot enable automatic cleanup without Action Scheduler.' );
		}
		$prior = $this->settings();
		update_option( FinanceRetentionPreferences::OPTION, $policy, false );
		if ( $policy !== $this->settings() ) {
			throw new RuntimeException( 'Could not persist retention policy.' );
		}
		if ( $policy['automatic_cleanup'] ) {
			if ( ! as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				$scheduled = as_schedule_recurring_action( time() + DAY_IN_SECONDS,
					DAY_IN_SECONDS, self::HOOK, [], self::GROUP, true );
				if ( ! is_int( $scheduled ) || $scheduled < 1 ) {
					update_option( FinanceRetentionPreferences::OPTION, $prior, false );
					throw new RuntimeException( 'Could not schedule approved privacy retention policy.' );
				}
			}
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
		return $policy;
	}

	/** @return array<string,int> */
	public function sweep( int $actor_id, string $nonce, bool $manual = true ): array {
		if ( $manual ) {
			$this->authorize( $actor_id, $nonce, 'wbm_pro_retention_sweep' );
		}
		$policy = $this->settings(); // An invalid policy fails closed.
		if ( ! $manual && ! $policy['automatic_cleanup'] ) return [];
		$this->install();
		$lock = 'wbm-retention-' . substr( hash( 'sha256', $this->db->prefix ), 0, 32 );
		$held = $this->db->get_var( $this->db->prepare(
			'SELECT GET_LOCK(%s, 0)', $lock
		) );
		$this->check_db();
		if ( '1' !== (string) $held ) {
			throw new RuntimeException( 'Another finance retention sweep is running.' );
		}
		$audit_id = 0;
		try {
			$encoded = wp_json_encode( $policy );
			if ( ! is_string( $encoded ) ) throw new RuntimeException( 'Cannot hash retention policy.' );
			$table = $this->db->prefix . 'wbm_pro_retention_runs';
			$insert = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (actor_id,policy_sha256,result_json,status,created_at)
				 VALUES (%d,%s,'{}','started',UTC_TIMESTAMP())",
				$actor_id, hash( 'sha256', $encoded )
			) );
			$this->check_db();
			if ( 1 !== $insert ) throw new RuntimeException( 'Could not audit the requested cleanup.' );
			$audit_id = (int) $this->db->insert_id;
			$counts = [ 'expired_private_report_jobs' => $this->expire_private_exports() ];
			foreach ( self::AUDITS as $setting => $suffix ) {
				$counts[$setting . '_removed'] = $this->prune_audit(
					$suffix, $policy[$setting]
				);
			}
			$counts['private_source_files_removed'] = $policy['private_source_days'] > 0 ?
				$this->prune_sources( $policy['private_source_days'] ) : 0;
			$json = wp_json_encode( $counts );
			if ( ! is_string( $json ) ) throw new RuntimeException( 'Could not summarize retention run.' );
			$written = $this->db->query( $this->db->prepare(
				"UPDATE $table SET result_json=%s,status='completed',
				 finished_at=UTC_TIMESTAMP() WHERE id=%d AND status='started'",
				$json, $audit_id
			) );
			$this->check_db();
			if ( 1 !== $written ) throw new RuntimeException( 'Retention audit finalization failed.' );
			return $counts;
		} catch ( Throwable $error ) {
			if ( $audit_id ) {
				$this->db->query( $this->db->prepare(
					"UPDATE {$this->db->prefix}wbm_pro_retention_runs
					 SET status='failed',finished_at=UTC_TIMESTAMP()
					 WHERE id=%d AND status='started'", $audit_id
				) );
			}
			throw $error;
		} finally {
			$this->db->get_var( $this->db->prepare(
				'SELECT RELEASE_LOCK(%s)', $lock
			) );
			$this->check_db();
		}
	}

	/** Clear ONLY private CSV bytes past the original 2-hour TTL. */
	private function expire_private_exports(): int {
		$table = $this->db->prefix . 'wbm_pro_report_export_jobs';
		if ( ! $this->exists( $table ) ) return 0;
		$updated = $this->db->query(
			"UPDATE $table SET status='expired',csv_data='',csv_sha256='',
			 byte_count=0,updated_at=UTC_TIMESTAMP()
			 WHERE expires_at <= UTC_TIMESTAMP() AND status <> 'expired'
			 ORDER BY id ASC LIMIT " . self::CHUNK
		);
		$this->check_db();
		if ( false === $updated ) throw new RuntimeException( 'Private report cleanup failed.' );
		return (int) $updated;
	}

	/** Delete only explicitly listed OLD METADATA audits, not bank records. */
	private function prune_audit( string $suffix, int $days ): int {
		$table = $this->db->prefix . $suffix;
		if ( ! in_array( $suffix, self::AUDITS, true ) ||
			! $this->exists( $table ) ) return 0;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = $this->db->query( $this->db->prepare(
			"DELETE FROM $table WHERE created_at < %s
			 ORDER BY id ASC LIMIT " . self::CHUNK, $threshold
		) );
		$this->check_db();
		if ( false === $deleted ) throw new RuntimeException( 'Privacy metadata cleanup failed.' );
		return (int) $deleted;
	}

	/**
	 * Only completed/cancelled private import SOURCE files, and ONLY after
	 * the merchant explicitly selects nonzero retention days. Never delete
	 * failed/pending/processing imports, financial source rows or audit trail.
	 */
	private function prune_sources( int $days ): int {
		$jobs = $this->db->prefix . 'wbm_pro_import_jobs';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		if ( ! $this->exists( $jobs ) || ! $this->exists( $batches ) ) return 0;
		$directory = defined( 'WBM_PRIVATE_IMPORT_DIR' ) ?
			WBM_PRIVATE_IMPORT_DIR : dirname( rtrim( ABSPATH, '/\\' ) ) . '/wbm-private-imports';
		$root = realpath( $directory );
		$web = realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH );
		$wp = realpath( ABSPATH );
		if ( false === $root || ! is_dir( $root ) ||
			( false !== $web && str_starts_with( $root . '/', rtrim( $web, '/' ) . '/' ) ) ||
			( false !== $wp && str_starts_with( $root . '/', rtrim( $wp, '/' ) . '/' ) ) ) {
			throw new RuntimeException( 'Private staging directory cannot be safely validated.' );
		}
		$threshold = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows = $this->db->get_results( $this->db->prepare(
			"SELECT j.batch_id,j.source_path,b.file_sha256
			 FROM $jobs j JOIN $batches b ON b.id=j.batch_id
			 WHERE b.status IN ('completed','cancelled')
			 AND b.updated_at < %s AND j.source_path <> ''
			 ORDER BY j.batch_id ASC LIMIT " . self::CHUNK,
			$threshold
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $rows ) ) throw new RuntimeException( 'Could not inspect private source retention.' );
		$count = 0;
		foreach ( $rows as $row ) {
			$path = (string) $row['source_path'];
			if ( ! preg_match( '/\A[a-f0-9]{48}\.csv\z/', basename( $path ) ) ||
				is_link( $path ) ) {
				throw new RuntimeException( 'Unsafe or unexpected private import source path.' );
			}
			if ( is_file( $path ) ) {
				$real = realpath( $path );
				if ( false === $real || dirname( $real ) !== $root ||
					! hash_equals( (string) $row['file_sha256'], hash_file( 'sha256', $real ) ?: '' ) ||
					! unlink( $real ) ) {
					throw new RuntimeException( 'Original bank source changed; manual file review required.' );
				}
				++$count;
			} elseif ( file_exists( $path ) ) {
				throw new RuntimeException( 'Private source is not a regular file.' );
			}
			// Leave original import batch provenance, staged row identities and
			// approvals untouched; clear only the no-longer-valid private path.
			$cleared = $this->db->query( $this->db->prepare(
				"UPDATE $jobs SET source_path='' WHERE batch_id=%d AND source_path=%s",
				(int) $row['batch_id'], $path
			) );
			$this->check_db();
			if ( 1 !== $cleared ) {
				throw new RuntimeException( 'Could not record private source file cleanup.' );
			}
		}
		return $count;
	}

	private function exists( string $table ): bool {
		$found = $this->db->get_var( $this->db->prepare(
			'SHOW TABLES LIKE %s', $table
		) );
		$this->check_db();
		return $found === $table;
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_retention_runs';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					actor_id BIGINT UNSIGNED NOT NULL,
					policy_sha256 CHAR(64) NOT NULL,
					result_json VARCHAR(1024) NOT NULL,
					status VARCHAR(12) NOT NULL,
					created_at DATETIME NOT NULL,
					finished_at DATETIME NULL,
					KEY started_at (created_at)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	private function authorize( int $actor_id, string $nonce, string $action ): void {
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ||
			! wp_verify_nonce( $nonce, $action ) ) {
			throw new RuntimeException( 'A verified finance manager is required for retention controls.' );
		}
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Finance retention storage unavailable.' );
		}
	}
}
