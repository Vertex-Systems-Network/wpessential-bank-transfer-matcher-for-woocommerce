<?php
namespace WPEssential\BankTransferMatcher\Evidence;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * A Pro correction/reversal investigation is a financial HOLD, not a reversal.
 * Free must honor it even when Pro is deactivated but its tables are retained.
 * Called under existing source/order advisory locks by allocation writers.
 */
final class CorrectionHoldGuard {
	public static function assert_no_hold( wpdb $db, int $order_id, int $source_id = 0 ): void {
		if ( $order_id < 1 || $source_id < 0 ) {
			throw new InvalidArgumentException( 'Invalid correction-hold subject.' );
		}
		$table = $db->prefix . 'wbm_pro_correction_cases';
		$exists = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( '' !== $db->last_error ) throw new RuntimeException( 'Financial correction hold storage is unavailable.' );
		if ( $exists !== $table ) return;
		$pro = $db->prefix . 'wbm_pro_allocations';
		$pro_exists = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $pro ) );
		if ( '' !== $db->last_error || $pro_exists !== $pro ) {
			throw new RuntimeException( 'Pro source ledger unavailable while correction holds exist.' );
		}
		// The shared bank source may fund several orders. A Free writer has
		// no imported source ID, so resolve all Pro bank sources of its order
		// and block if ANY of those sources has an active correction case.
		if ( $source_id > 0 ) {
			$count = $db->get_var( $db->prepare(
				"SELECT COUNT(*) FROM $table c WHERE c.order_id = %d
				 OR c.transaction_row_id = %d
				 OR EXISTS (SELECT 1 FROM $pro p WHERE p.order_id = %d
				 AND p.transaction_row_id = c.transaction_row_id)",
				$order_id, $source_id, $order_id
			) );
		} else {
			$count = $db->get_var( $db->prepare(
				"SELECT COUNT(*) FROM $table c WHERE c.order_id = %d
				 OR EXISTS (SELECT 1 FROM $pro p WHERE p.order_id = %d
				 AND p.transaction_row_id = c.transaction_row_id)",
				$order_id, $order_id
			) );
		}
		if ( '' !== $db->last_error || null === $count ) {
			throw new RuntimeException( 'Cannot verify correction or reversal holds.' );
		}
		// A second reviewer acknowledging a dispute does NOT release funds.
		if ( (int) $count !== 0 ) {
			throw new InvalidArgumentException( 'Correction/reversal investigation holds this order or bank credit; resolve outside the allocator.' );
		}
	}
}
