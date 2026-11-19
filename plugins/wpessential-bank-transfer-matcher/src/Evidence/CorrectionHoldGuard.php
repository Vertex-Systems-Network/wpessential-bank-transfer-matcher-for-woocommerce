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
		if ( $source_id > 0 ) {
			$count = $db->get_var( $db->prepare(
				"SELECT COUNT(*) FROM $table WHERE order_id = %d OR transaction_row_id = %d",
				$order_id, $source_id
			) );
		} else {
			$count = $db->get_var( $db->prepare(
				"SELECT COUNT(*) FROM $table WHERE order_id = %d", $order_id
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
