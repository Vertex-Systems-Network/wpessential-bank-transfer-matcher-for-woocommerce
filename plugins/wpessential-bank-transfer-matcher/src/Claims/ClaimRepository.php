<?php

namespace WPEssential\BankTransferMatcher\Claims;

use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\ClaimStatus;
use wpdb;

final class ClaimRepository {
	private const VERSION = 'wpessential_wbm_claim_schema';
	private bool $ready = false;

	public function __construct( private readonly wpdb $db ) {}

	public function exists( int $order_id ): bool {
		if ( ! $this->ready && null === $this->version() ) {
			return false;
		}
		$this->ensure_schema();
		$table = $this->db->prefix . 'wbm_payment_claims';
		$found = $this->db->get_var( $this->db->prepare( "SELECT order_id FROM `$table` WHERE order_id = %d", $order_id ) );
		$this->check_error();
		return null !== $found;
	}

	public function allow_attempt( int $order_id ): bool {
		$this->ensure_schema();
		$table = $this->db->prefix . 'wbm_claim_attempts';
		$window = intdiv( time(), 900 );
		$this->db->query( $this->db->prepare( "DELETE FROM `$table` WHERE window_id < %d LIMIT 100", $window - 1 ) );
		$this->check_error();
		$result = $this->db->query( $this->db->prepare(
			"INSERT INTO `$table` (order_id, window_id, attempts) VALUES (%d, %d, 1)
			ON DUPLICATE KEY UPDATE attempts = IF(attempts < 8, attempts + 1, attempts)",
			$order_id,
			$window
		) );
		$this->check_error();
		return 0 < $result;
	}

	public function save( int $order_id, array $claim ): void {
		$this->ensure_schema();
		$table = $this->db->prefix . 'wbm_payment_claims';
		$this->db->query( $this->db->prepare(
			"INSERT INTO `$table` (order_id, status, transfer_date, amount_minor, currency, exponent, transfer_reference, sender_name, customer_note, created_at)
			VALUES (%d, %s, %s, %d, %s, %d, %s, %s, %s, UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE order_id = order_id",
			$order_id, ClaimStatus::SUBMITTED->value, $claim['date'], $claim['amount']->minor_units,
			$claim['amount']->currency, $claim['amount']->exponent, $claim['reference'], $claim['sender'], $claim['note']
		) );
		$this->check_error();
	}

	private function version(): ?string {
		$value = $this->db->get_var( $this->db->prepare(
			"SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
			self::VERSION
		) );
		$this->check_error();
		return null === $value ? null : (string) $value;
	}

	private function ensure_schema(): void {
		if ( $this->ready ) {
			return;
		}
		if ( '1' !== $this->version() ) {
			( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ self::create_tables(...) ] );
		}
		$this->ready = true;
	}

	private static function create_tables( wpdb $db ): bool {
		$claims = $db->prefix . 'wbm_payment_claims';
		$attempts = $db->prefix . 'wbm_claim_attempts';
		$collation = $db->get_charset_collate();
		return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$claims` (
			order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(20) NOT NULL,
			transfer_date DATE NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL,
			currency CHAR(3) NOT NULL, exponent TINYINT UNSIGNED NOT NULL,
			transfer_reference VARCHAR(100) NOT NULL, sender_name VARCHAR(100) NOT NULL,
			customer_note TEXT NOT NULL, created_at DATETIME NOT NULL
		) ENGINE=InnoDB $collation" ) && false !== $db->query( "CREATE TABLE IF NOT EXISTS `$attempts` (
			order_id BIGINT UNSIGNED NOT NULL, window_id BIGINT UNSIGNED NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL, PRIMARY KEY (order_id, window_id), KEY window_id (window_id)
		) ENGINE=InnoDB $collation" );
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Payment claim storage is unavailable.' );
		}
	}
}
