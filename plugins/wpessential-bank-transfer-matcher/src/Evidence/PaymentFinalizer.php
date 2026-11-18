<?php

namespace WPEssential\BankTransferMatcher\Evidence;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\PaymentMethods;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Explicit, serialized WooCommerce transition after full manual reconciliation. */
final class PaymentFinalizer {
	private const VERSION = 'wpessential_wbm_finalization_schema';

	public function __construct( private readonly wpdb $db ) {}

	/** @return array{order_id: int, status: string, order_payment_changed: bool} */
	public function complete( int $order_id, int $actor_id ): array {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ self::create_table(...) ] );
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Order is being reconciled.' );
		}
		try {
			$order = wc_get_order( $order_id );
			$table = $this->db->prefix . 'wbm_payment_finalizations';
			$intent = $this->db->get_row( $this->db->prepare( "SELECT state FROM `$table` WHERE order_id = %d", $order_id ), ARRAY_A );
			$this->check_error();
			if ( $intent && 'completed' === $intent['state'] && $order && $order->is_paid() ) {
				return [ 'order_id' => $order_id, 'status' => 'already_completed', 'order_payment_changed' => false ];
			}
			if ( ! $order || PaymentMethods::BACS !== $order->get_payment_method() || $order->is_paid() || ! $order->has_status( [ 'on-hold', 'pending' ] ) ) {
				throw new InvalidArgumentException( 'Order payment state changed; review the order before retrying.' );
			}
			if ( $intent && 'pending' !== $intent['state'] ) {
				throw new InvalidArgumentException( 'Payment finalization history conflicts with the order.' );
			}
			try {
				$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), wc_get_price_decimals() );
			} catch ( \InvalidArgumentException|\OverflowException $error ) {
				throw new InvalidArgumentException( 'Order amount or currency is not supported for finalization.' );
			}
			$allocations = $this->db->get_results( $this->db->prepare(
				"SELECT a.amount_minor, a.expected_minor, a.currency, a.exponent, e.fingerprint
				FROM `{$this->db->prefix}wbm_manual_allocations` a
				JOIN `{$this->db->prefix}wbm_bank_evidence` e ON e.id = a.evidence_id AND e.order_id = a.order_id
				WHERE a.order_id = %d", $order_id
			), ARRAY_A );
			$this->check_error();
			$sum = 0;
			if ( $total->minor_units <= 0 || ! $allocations ) {
				throw new InvalidArgumentException( 'The order has no full verified allocation.' );
			}
			foreach ( $allocations as $row ) {
				if ( (int) $row['expected_minor'] !== $total->minor_units || $row['currency'] !== $total->currency || (int) $row['exponent'] !== $total->exponent ||
					(int) $row['amount_minor'] <= 0 || (int) $row['amount_minor'] > $total->minor_units - $sum ) {
					throw new InvalidArgumentException( 'Order balance or allocation history changed.' );
				}
				$duplicates = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->prefix}wbm_bank_evidence` WHERE fingerprint = %s", $row['fingerprint'] ) );
				$this->check_error();
				if ( 1 !== $duplicates ) {
					throw new InvalidArgumentException( 'Duplicate bank evidence requires manual review.' );
				}
				$sum += (int) $row['amount_minor'];
			}
			if ( $sum !== $total->minor_units ) {
				throw new InvalidArgumentException( 'The order still has an outstanding balance.' );
			}
			if ( ! $intent ) {
				$inserted = $this->db->query( $this->db->prepare(
					"INSERT INTO `$table` (order_id, actor_id, state, created_at) VALUES (%d, %d, 'pending', UTC_TIMESTAMP())",
					$order_id, $actor_id
				) );
				if ( false === $inserted ) {
					throw new RuntimeException( 'Could not record payment intent.' );
				}
			}
			// Never execute WooCommerce hooks inside a database transaction. A pending intent
			// remains available for an explicit retry if the WooCommerce transition fails.
			try {
				$completed = $order->payment_complete();
			} catch ( Throwable $error ) {
				throw new RuntimeException( 'WooCommerce payment completion failed.', 0, $error );
			}
			if ( ! $completed || ! wc_get_order( $order_id )->is_paid() ) {
				throw new RuntimeException( 'WooCommerce did not complete the payment.' );
			}
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE `$table` SET state = 'completed', completed_at = UTC_TIMESTAMP(), completed_by = %d WHERE order_id = %d AND state = 'pending'",
				$actor_id, $order_id
			) );
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Paid order requires finalization audit repair.' );
			}
			return [ 'order_id' => $order_id, 'status' => 'completed', 'order_payment_changed' => true ];
		} finally {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function create_table( wpdb $db ): bool {
		$table = $db->prefix . 'wbm_payment_finalizations';
		$collation = $db->get_charset_collate();
		return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$table` (
			order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			actor_id BIGINT UNSIGNED NOT NULL, state VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL, completed_at DATETIME NULL, completed_by BIGINT UNSIGNED NULL
		) ENGINE=InnoDB $collation" );
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Payment finalization storage is unavailable.' );
		}
	}
}
