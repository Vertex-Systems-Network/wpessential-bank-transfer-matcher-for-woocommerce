<?php

namespace WPEssential\BankTransferMatcher\Evidence;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\Money;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Bank evidence may fund its recorded order only; all checks use exact minor units. */
final class AllocationRepository {
	private const VERSION = 'wpessential_wbm_allocation_schema';

	public function __construct( private readonly wpdb $db ) {}

	/** @return array{allocation_id: int, allocated_minor: int, outstanding_minor: int, currency: string, fully_allocated: bool, order_payment_changed: false} */
	public function allocate( int $order_id, int $evidence_id, int $actor_id, Money $expected, Money $amount, string $reason ): array {
		if ( $amount->minor_units <= 0 || '' === trim( $reason ) || $amount->currency !== $expected->currency || $amount->exponent !== $expected->exponent ) {
			throw new InvalidArgumentException( 'Invalid allocation amount or reason.' );
		}
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ self::create_table(...) ] );
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Order is being reconciled.' );
		}
		$transaction = false;
		try {
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Could not begin allocation transaction.' );
			}
			$transaction = true;
			$evidence_table = $this->db->prefix . 'wbm_bank_evidence';
			$table = $this->db->prefix . 'wbm_manual_allocations';
			$source = $this->db->get_row( $this->db->prepare( "SELECT * FROM `$evidence_table` WHERE id = %d FOR UPDATE", $evidence_id ), ARRAY_A );
			if ( '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Could not read bank evidence.' );
			}
			if ( ! $source || (int) $source['order_id'] !== $order_id || $source['currency'] !== $amount->currency || (int) $source['exponent'] !== $amount->exponent ) {
				throw new InvalidArgumentException( 'Bank evidence does not belong to this order and currency.' );
			}
			$duplicates = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `$evidence_table` WHERE fingerprint = %s", $source['fingerprint'] ) );
			if ( '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Could not check duplicate evidence.' );
			}
			if ( $duplicates !== 1 ) {
				throw new InvalidArgumentException( 'Duplicate bank evidence must be resolved before allocation.' );
			}
			$rows = $this->db->get_results( $this->db->prepare( "SELECT evidence_id, amount_minor, expected_minor, currency, exponent FROM `$table` WHERE order_id = %d FOR UPDATE", $order_id ), ARRAY_A );
			if ( '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Could not read existing allocations.' );
			}
			$order_used = 0;
			$source_used = 0;
			foreach ( $rows as $row ) {
				if ( (int) $row['expected_minor'] !== $expected->minor_units || $row['currency'] !== $expected->currency || (int) $row['exponent'] !== $expected->exponent ) {
					throw new InvalidArgumentException( 'Order total or currency changed after allocation. Review is required.' );
				}
				$value = (int) $row['amount_minor'];
				if ( $value <= 0 || $value > PHP_INT_MAX - $order_used ) {
					throw new RuntimeException( 'Invalid allocation balance.' );
				}
				$order_used += $value;
				if ( (int) $row['evidence_id'] === $evidence_id ) {
					$source_used += $value;
				}
			}
			if ( $amount->minor_units > $expected->minor_units - $order_used || $amount->minor_units > (int) $source['amount_minor'] - $source_used ) {
				throw new InvalidArgumentException( 'Allocation exceeds the outstanding order or bank credit balance.' );
			}
			$written = $this->db->query( $this->db->prepare(
				"INSERT INTO `$table` (order_id, evidence_id, actor_id, amount_minor, expected_minor, currency, exponent, reason, created_at)
				VALUES (%d, %d, %d, %d, %d, %s, %d, %s, UTC_TIMESTAMP())",
				$order_id, $evidence_id, $actor_id, $amount->minor_units, $expected->minor_units, $expected->currency, $expected->exponent, $reason
			) );
			if ( false === $written || '' !== $this->db->last_error ) {
				throw new RuntimeException( 'Could not record allocation.' );
			}
			$id = (int) $this->db->insert_id;
			if ( false === $this->db->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit allocation.' );
			}
			$transaction = false;
			$outstanding = $expected->minor_units - $order_used - $amount->minor_units;
			return [ 'allocation_id' => $id, 'allocated_minor' => $order_used + $amount->minor_units, 'outstanding_minor' => $outstanding,
				'currency' => $expected->currency, 'fully_allocated' => 0 === $outstanding, 'order_payment_changed' => false ];
		} finally {
			if ( $transaction ) {
				$this->db->query( 'ROLLBACK' );
			}
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function create_table( wpdb $db ): bool {
		$table = $db->prefix . 'wbm_manual_allocations';
		$collation = $db->get_charset_collate();
		return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$table` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL, evidence_id BIGINT UNSIGNED NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL,
			expected_minor BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
			exponent TINYINT UNSIGNED NOT NULL, reason TEXT NOT NULL, created_at DATETIME NOT NULL,
			KEY order_id (order_id), KEY evidence_id (evidence_id)
		) ENGINE=InnoDB $collation" );
	}
}
