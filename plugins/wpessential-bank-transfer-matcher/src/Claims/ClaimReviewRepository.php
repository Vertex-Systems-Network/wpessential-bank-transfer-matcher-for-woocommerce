<?php

namespace WPEssential\BankTransferMatcher\Claims;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Append-only reviewer decisions, with a separate pending notification state. */
final class ClaimReviewRepository {
	private const VERSION = 'wpessential_wbm_claim_review_schema';

	public function __construct( private readonly wpdb $db ) {}

	/** @return array{event_id: int, status: string, notification: string} */
	public function transition( int $order_id, int $actor_id, string $next, string $comment, bool $acknowledged ): array {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ self::create_table(...) ] );
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Order is being reviewed.' );
		}
		$transaction = false;
		try {
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Could not begin claim review.' );
			}
			$transaction = true;
			$claims = $this->db->prefix . 'wbm_payment_claims';
			$events = $this->db->prefix . 'wbm_claim_review_events';
			$claim = $this->db->get_row( $this->db->prepare( "SELECT status, amount_minor, currency, exponent FROM `$claims` WHERE order_id = %d FOR UPDATE", $order_id ), ARRAY_A );
			$this->check_error();
			if ( ! $claim || ! in_array( $claim['status'], [ 'submitted', 'under_review' ], true ) ||
				! in_array( $next, 'submitted' === $claim['status'] ? [ 'under_review', 'verified', 'rejected' ] : [ 'verified', 'rejected' ], true ) ) {
				throw new InvalidArgumentException( 'Claim review transition is unavailable.' );
			}
			if ( 'verified' === $next ) {
				if ( ! $acknowledged ) {
					throw new InvalidArgumentException( 'Bank clearance acknowledgement is required.' );
				}
				$version = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", 'wpessential_wbm_allocation_schema' ) );
				$this->check_error();
				if ( '1' !== $version ) {
					throw new InvalidArgumentException( 'A verified bank allocation is required before verifying a claim.' );
				}
				$rows = $this->db->get_results( $this->db->prepare(
					"SELECT amount_minor, currency, exponent FROM `{$this->db->prefix}wbm_manual_allocations` WHERE order_id = %d", $order_id
				), ARRAY_A );
				$this->check_error();
				$allocated = 0;
				foreach ( $rows as $row ) {
					if ( $row['currency'] !== $claim['currency'] || (int) $row['exponent'] !== (int) $claim['exponent'] ||
						(int) $row['amount_minor'] <= 0 || (int) $row['amount_minor'] > PHP_INT_MAX - $allocated ) {
						throw new InvalidArgumentException( 'Allocation currency or amount requires review.' );
					}
					$allocated += (int) $row['amount_minor'];
				}
				if ( $allocated < (int) $claim['amount_minor'] ) {
					throw new InvalidArgumentException( 'The claimed amount is not covered by verified allocations.' );
				}
			}
			$updated = $this->db->query( $this->db->prepare( "UPDATE `$claims` SET status = %s WHERE order_id = %d AND status = %s", $next, $order_id, $claim['status'] ) );
			if ( 1 !== $updated ) {
				throw new RuntimeException( 'Could not transition payment claim.' );
			}
			$written = $this->db->query( $this->db->prepare(
				"INSERT INTO `$events` (order_id, actor_id, previous_status, status, reviewer_comment, notification, created_at)
				VALUES (%d, %d, %s, %s, %s, 'pending', UTC_TIMESTAMP())",
				$order_id, $actor_id, $claim['status'], $next, $comment
			) );
			if ( false === $written ) {
				throw new RuntimeException( 'Could not audit claim review.' );
			}
			$id = (int) $this->db->insert_id;
			if ( false === $this->db->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit claim review.' );
			}
			$transaction = false;
			return [ 'event_id' => $id, 'status' => $next, 'notification' => 'pending' ];
		} finally {
			if ( $transaction ) {
				$this->db->query( 'ROLLBACK' );
			}
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public function pending_event( int $order_id, int $event_id ): ?array {
		$table = $this->db->prefix . 'wbm_claim_review_events';
		$event = $this->db->get_row( $this->db->prepare( "SELECT id, status, notification FROM `$table` WHERE id = %d AND order_id = %d", $event_id, $order_id ), ARRAY_A );
		$this->check_error();
		return is_array( $event ) ? $event : null;
	}

	public function mark_notified( int $event_id ): void {
		$table = $this->db->prefix . 'wbm_claim_review_events';
		if ( false === $this->db->query( $this->db->prepare( "UPDATE `$table` SET notification = 'sent', notified_at = UTC_TIMESTAMP() WHERE id = %d AND notification = 'pending'", $event_id ) ) ) {
			throw new RuntimeException( 'Could not mark claim notification sent.' );
		}
	}

	private static function create_table( wpdb $db ): bool {
		$table = $db->prefix . 'wbm_claim_review_events';
		$collation = $db->get_charset_collate();
		return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$table` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
			previous_status VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL,
			reviewer_comment TEXT NOT NULL, notification VARCHAR(12) NOT NULL,
			created_at DATETIME NOT NULL, notified_at DATETIME NULL,
			KEY order_id (order_id), KEY notification (notification)
		) ENGINE=InnoDB $collation" );
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Claim review storage is unavailable.' );
		}
	}
}
