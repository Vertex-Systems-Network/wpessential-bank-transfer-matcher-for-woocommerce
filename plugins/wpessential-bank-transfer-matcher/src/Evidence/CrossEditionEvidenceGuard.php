<?php
namespace WPEssential\BankTransferMatcher\Evidence;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Conservative duplicate-provenance guard across Free manual and Pro imported
 * credits. Equal date/currency/amount/reference is ambiguous, never two payments.
 *
 * Both callers must already hold the shared named order lock and an SQL transaction.
 * This is an additional safety gate, not proof that any bank deposit is genuine.
 */
final class CrossEditionEvidenceGuard {
	public static function assert_no_manual_overlap( wpdb $db, int $order_id, string $date,
		int $amount_minor, string $currency, int $exponent, string $reference ): void {
		$normalized = self::validate( $order_id, $date, $amount_minor, $currency, $exponent, $reference );
		$evidence = $db->prefix . 'wbm_bank_evidence';
		$allocations = $db->prefix . 'wbm_manual_allocations';
		if ( ! self::table_exists( $db, $evidence ) || ! self::table_exists( $db, $allocations ) ) return;
		$rows = $db->get_results( $db->prepare(
			"SELECT e.reference FROM $evidence e
			 INNER JOIN $allocations a ON a.evidence_id = e.id AND a.order_id = e.order_id
			 WHERE e.order_id = %d AND e.transfer_date = %s AND e.amount_minor = %d
			 AND e.currency = %s AND e.exponent = %d FOR UPDATE",
			$order_id, $date, $amount_minor, $currency, $exponent
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $db->last_error ) throw new RuntimeException( 'Manual bank evidence cannot be reviewed safely.' );
		foreach ( $rows as $row ) {
			if ( self::normalize( (string) $row['reference'] ) === $normalized ) {
				throw new InvalidArgumentException( 'This credit may already be allocated as Free manual evidence. Resolve duplicate provenance.' );
			}
		}
	}

	public static function assert_no_import_overlap( wpdb $db, int $order_id, string $date,
		int $amount_minor, string $currency, int $exponent, string $reference ): void {
		$normalized = self::validate( $order_id, $date, $amount_minor, $currency, $exponent, $reference );
		$pro = $db->prefix . 'wbm_pro_allocations';
		if ( ! self::table_exists( $db, $pro ) ) return;
		$imported = $db->prefix . 'wbm_pro_import_rows';
		if ( ! self::table_exists( $db, $imported ) ) {
			$count = $db->get_var( $db->prepare(
				"SELECT COUNT(*) FROM $pro WHERE order_id = %d AND state IN ('approved', 'committed')", $order_id
			) );
			if ( '' !== $db->last_error ) throw new RuntimeException( 'Cannot inspect imported financial records.' );
			if ( (int) $count > 0 ) throw new RuntimeException( 'Imported source records are missing; manual reconciliation requires review.' );
			return;
		}
		$rows = $db->get_results( $db->prepare(
			"SELECT p.transaction_row_id, r.canonical_json FROM $pro p
			 LEFT JOIN $imported r ON r.id = p.transaction_row_id
			 WHERE p.order_id = %d AND p.state IN ('approved', 'committed') FOR UPDATE",
			$order_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $db->last_error ) throw new RuntimeException( 'Cannot inspect approved imported credits.' );
		foreach ( $rows as $row ) {
			$entry = json_decode( (string) ( $row['canonical_json'] ?? '' ), true );
			if ( ! is_array( $entry ) || ! is_string( $entry['reference'] ?? null ) ||
				! is_string( $entry['posted_date'] ?? null ) ||
				! is_int( $entry['amount_minor'] ?? null ) || ! is_int( $entry['exponent'] ?? null ) ||
				! is_string( $entry['currency'] ?? null ) ) {
				throw new RuntimeException( 'An approved imported credit has missing or invalid provenance.' );
			}
			if ( $date === $entry['posted_date'] && $amount_minor === $entry['amount_minor'] &&
				$currency === $entry['currency'] && $exponent === $entry['exponent'] &&
				$normalized === self::normalize( $entry['reference'] ) ) {
				throw new InvalidArgumentException( 'This deposit may already be allocated through Pro. Resolve duplicate provenance.' );
			}
		}
	}

	private static function validate( int $order_id, string $date, int $amount_minor, string $currency,
		int $exponent, string $reference ): string {
		$normalized = self::normalize( $reference );
		if ( $order_id < 1 || $amount_minor < 1 ||
			1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) ||
			1 !== preg_match( '/\A[A-Z]{3}\z/', $currency ) ||
			$exponent < 0 || $exponent > 3 || '' === $normalized ) {
			throw new InvalidArgumentException( 'A valid bank source identity is required.' );
		}
		return $normalized;
	}

	private static function normalize( string $reference ): string {
		return strtoupper( preg_replace( '/[^A-Z0-9]+/i', '', $reference ) ?? '' );
	}

	private static function table_exists( wpdb $db, string $table ): bool {
		$result = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( '' !== $db->last_error ) throw new RuntimeException( 'Financial evidence storage is unavailable.' );
		return $table === $result;
	}
}
