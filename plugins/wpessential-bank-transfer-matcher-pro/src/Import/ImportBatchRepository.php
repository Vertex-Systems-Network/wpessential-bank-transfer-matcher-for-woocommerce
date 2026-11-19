<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

/** Persistent provenance and progress for statement imports; no payment side effects. */
final class ImportBatchRepository {
	private const VERSION = 'wpessential_wbm_pro_import_batch_schema';
	private const TRANSITIONS = array(
		'pending' => array( 'processing', 'cancelled', 'failed' ),
		'processing' => array( 'completed', 'failed', 'cancelled' ),
		'failed' => array( 'processing', 'cancelled' ),
		'completed' => array(),
		'cancelled' => array(),
	);
	private bool $ready = false;

	public function __construct( private readonly wpdb $db ) {}

	/** The profile snapshot is retained even when the editable profile is later removed. */
	public function create( int $account_id, MappingProfile $profile, string $file_sha256, int $file_size, int $actor_id ): int {
		if ( $account_id < 1 || $actor_id < 1 || $file_size < 1 || $file_size > 20 * 1024 * 1024 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $file_sha256 ) ) {
			throw new InvalidArgumentException( 'Invalid import provenance.' );
		}
		$snapshot = wp_json_encode( $profile->to_array() );
		if ( ! is_string( $snapshot ) || strlen( $snapshot ) > 8192 ) throw new InvalidArgumentException( 'Mapping snapshot is too large.' );
		$this->ensure_schema();
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$account = $this->db->get_row( $this->db->prepare( "SELECT id, currency, status FROM `$accounts` WHERE id = %d", $account_id ), ARRAY_A );
		if ( ! is_array( $account ) || 'active' !== $account['status'] ) throw new InvalidArgumentException( 'An active bank account is required.' );
		$table = $this->table();
		$existing = $this->db->get_var( $this->db->prepare( "SELECT id FROM `$table` WHERE account_id = %d AND file_sha256 = %s AND status <> 'cancelled' LIMIT 1", $account_id, $file_sha256 ) );
		if ( null !== $existing ) throw new InvalidArgumentException( 'This exact statement file was already imported for the selected account.' );
		$result = $this->db->query( $this->db->prepare(
			"INSERT INTO `$table` (account_id, currency, profile_id, profile_snapshot, file_sha256, file_size, status, created_by, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %d, 'pending', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
			$account_id, $account['currency'], $profile->id, $snapshot, $file_sha256, $file_size, $actor_id
		) );
		if ( 1 !== $result ) throw new RuntimeException( 'Could not create import batch.' );
		return (int) $this->db->insert_id;
	}

	/** Compare-and-swap state change prevents stale workers from completing a cancelled batch. */
	public function transition( int $id, string $from, string $to, int $valid = 0, int $errors = 0, int $warnings = 0 ): void {
		if ( $id < 1 || ! in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true ) || min( $valid, $errors, $warnings ) < 0 ) throw new InvalidArgumentException( 'Invalid import batch transition.' );
		$this->ensure_schema();
		$table = $this->table();
		$result = $this->db->query( $this->db->prepare(
			"UPDATE `$table` SET status = %s, valid_count = %d, error_count = %d, warning_count = %d, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = %s",
			$to, $valid, $errors, $warnings, $id, $from
		) );
		if ( 1 !== $result ) throw new RuntimeException( 'Import batch state changed or batch was not found.' );
	}

	/** @return list<array<string, mixed>> */
	public function history( int $page = 1, int $limit = 25 ): array {
		if ( $page < 1 || $limit < 1 || $limit > 100 || $page > intdiv( PHP_INT_MAX, $limit ) ) throw new InvalidArgumentException( 'Invalid history page.' );
		$this->ensure_schema();
		$table = $this->table();
		$rows = $this->db->get_results( $this->db->prepare(
			"SELECT id, account_id, currency, profile_id, file_sha256, file_size, status, valid_count, error_count, warning_count, created_by, created_at, updated_at FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit, ( $page - 1 ) * $limit
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) throw new RuntimeException( 'Could not load import history.' );
		return $rows;
	}

	public function snapshot( int $id ): array {
		$this->ensure_schema();
		$row = $this->db->get_row( $this->db->prepare( "SELECT profile_snapshot FROM `{$this->table()}` WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) throw new InvalidArgumentException( 'Import batch was not found.' );
		$data = json_decode( $row['profile_snapshot'], true );
		if ( ! is_array( $data ) ) throw new RuntimeException( 'Import batch mapping snapshot is invalid.' );
		return $data;
	}

	private function table(): string { return $this->db->prefix . 'wbm_pro_import_batches'; }

	private function ensure_schema(): void {
		if ( $this->ready ) return;
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . self::VERSION );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) throw new RuntimeException( 'Could not acquire import schema lock.' );
		try {
			$stored = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", self::VERSION ) );
			if ( null !== $stored && '1' !== (string) $stored ) throw new RuntimeException( 'Unsupported import schema version.' );
			if ( null === $stored ) {
				$table = $this->table(); $collation = $this->db->get_charset_collate();
				$created = $this->db->query( "CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, profile_id VARCHAR(64) NOT NULL, profile_snapshot TEXT NOT NULL, file_sha256 CHAR(64) NOT NULL, file_size BIGINT UNSIGNED NOT NULL, status VARCHAR(12) NOT NULL, valid_count BIGINT UNSIGNED NOT NULL DEFAULT 0, error_count BIGINT UNSIGNED NOT NULL DEFAULT 0, warning_count BIGINT UNSIGNED NOT NULL DEFAULT 0, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY account_history (account_id, id), KEY status_history (status, id), KEY file_hash (file_sha256)) ENGINE=InnoDB $collation" );
				if ( false === $created ) throw new RuntimeException( 'Could not create import batch table.' );
				$written = $this->db->query( $this->db->prepare( "INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = '1'", self::VERSION ) );
				if ( false === $written ) throw new RuntimeException( 'Could not record import schema version.' );
			}
		} finally { $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		$this->ready = true;
	}
}
