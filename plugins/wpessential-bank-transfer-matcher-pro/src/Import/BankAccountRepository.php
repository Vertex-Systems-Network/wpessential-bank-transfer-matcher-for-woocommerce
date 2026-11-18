<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

final class BankAccountRepository {
	private const VERSION = 'wpessential_wbm_pro_bank_account_schema';
	private bool $ready = false;

	public function __construct( private readonly wpdb $db ) {}

	public function create( string $nickname, string $bank, string $currency, string $last4, int $actor_id ): int {
		$this->ensure_schema();
		$nickname = trim( $nickname ); $bank = trim( $bank ); $currency = strtoupper( trim( $currency ) ); $last4 = strtoupper( trim( $last4 ) );
		if ( '' === $nickname || strlen( $nickname ) > 80 || '' === $bank || strlen( $bank ) > 120 ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) || 1 !== preg_match( '/\A[A-Z0-9]{2,4}\z/', $last4 ) || $actor_id < 1 ) {
			throw new InvalidArgumentException( 'Enter a nickname, bank, ISO currency and 2–4 safe identifier characters.' );
		}
		$table = $this->db->prefix . 'wbm_pro_bank_accounts';
		$result = $this->db->query( $this->db->prepare( "INSERT INTO `$table` (nickname, bank_name, currency, identifier_last4, status, created_by, created_at, updated_at) VALUES (%s, %s, %s, %s, 'active', %d, UTC_TIMESTAMP(), UTC_TIMESTAMP())", $nickname, $bank, $currency, $last4, $actor_id ) );
		if ( false === $result || '' !== $this->db->last_error ) throw new RuntimeException( 'Could not save bank account metadata.' );
		return (int) $this->db->insert_id;
	}

	public function archive( int $id ): void {
		$this->ensure_schema();
		$result = $this->db->query( $this->db->prepare( "UPDATE `{$this->db->prefix}wbm_pro_bank_accounts` SET status = 'archived', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'active'", $id ) );
		if ( 1 !== $result ) throw new InvalidArgumentException( 'Active bank account metadata was not found.' );
	}

	public function all(): array {
		$this->ensure_schema();
		$rows = $this->db->get_results( "SELECT id, nickname, bank_name, currency, identifier_last4, status, created_at, updated_at FROM `{$this->db->prefix}wbm_pro_bank_accounts` ORDER BY status ASC, nickname ASC, id ASC", ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) throw new RuntimeException( 'Could not load bank account metadata.' );
		return $rows;
	}

	private function ensure_schema(): void {
		if ( $this->ready ) return;
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . self::VERSION );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) throw new RuntimeException( 'Could not acquire bank account schema lock.' );
		try {
			$stored = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", self::VERSION ) );
			if ( null !== $stored && '1' !== (string) $stored ) throw new RuntimeException( 'Unsupported bank account schema version.' );
			if ( null === $stored ) {
				$table = $this->db->prefix . 'wbm_pro_bank_accounts'; $collation = $this->db->get_charset_collate();
				$created = $this->db->query( "CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, nickname VARCHAR(80) NOT NULL, bank_name VARCHAR(120) NOT NULL, currency CHAR(3) NOT NULL, identifier_last4 VARCHAR(4) NOT NULL, status VARCHAR(12) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY status (status), KEY currency (currency)) ENGINE=InnoDB $collation" );
				if ( false === $created ) throw new RuntimeException( 'Could not create bank account metadata table.' );
				$written = $this->db->query( $this->db->prepare( "INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = '1'", self::VERSION ) );
				if ( false === $written ) throw new RuntimeException( 'Could not record bank account schema version.' );
			}
		} finally { $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		$this->ready = true;
	}
}
