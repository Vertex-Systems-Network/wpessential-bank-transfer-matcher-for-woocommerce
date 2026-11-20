<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use InvalidArgumentException;
use RuntimeException;

/**
 * Page-scoped, read-only finance CSV encoder. Not an export authorization,
 * HTTP endpoint, bank-clearing decision or full-ledger snapshot.
 *
 * Only an explicit safe column allowlist is serialized: raw bank references,
 * descriptions, customer details, uploaded filenames and credentials cannot
 * be exported by adding arbitrary keys to a query result.
 */
final class ReportCsvEncoder {
	private const MAX_BYTES = 1048576;
	private const COLUMNS = [
		'outstanding_orders' => [
			'order_id', 'bank_account_id', 'currency', 'exponent', 'state',
			'expected_minor', 'funded_minor', 'amount_minor', 'created_at_utc',
			'receiving_account_confirmed', 'woocommerce_paid',
		],
		'partial_funding' => [
			'order_id', 'bank_account_id', 'currency', 'exponent', 'state',
			'expected_minor', 'funded_minor', 'amount_minor', 'created_at_utc',
			'receiving_account_confirmed', 'woocommerce_paid',
		],
		'unmatched_credits' => [
			'source_id', 'bank_account_id', 'currency', 'exponent', 'state',
			'amount_minor', 'posted_date', 'ambiguous_bank_source',
			'pending_review_not_paid', 'unconfirmed_clearance',
		],
		'exception_aging' => [
			'case_id', 'case_type', 'order_id', 'bank_account_id', 'currency',
			'exponent', 'state', 'age_days', 'age_band',
			'disputed_minor_non_additive', 'financial_hold',
		],
		'import_batches' => [
			'id', 'bank_account_id', 'currency', 'state', 'valid_count',
			'error_count', 'warning_count', 'created_at_utc',
		],
		'allocation_audit' => [
			'id', 'order_id', 'source_id', 'bank_account_id', 'currency',
			'exponent', 'state', 'amount_minor', 'created_at_utc',
		],
	];

	public static function encode( ReportDefinition $scope, array $result ): string {
		$columns = self::COLUMNS[$scope->report] ?? null;
		if ( null === $columns ||
			( $result['definition'] ?? null ) !== $scope->canonical() ||
			( $result['fingerprint'] ?? null ) !== $scope->fingerprint() ||
			( $result['payment_authorized'] ?? null ) !== false ||
			( $result['ledger_mutated'] ?? null ) !== false ) {
			throw new InvalidArgumentException( 'Export data is not the requested read-only finance report.' );
		}
		$rows = $result['rows'] ?? null;
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) ||
			count( $rows ) > $scope->limit || count( $rows ) > 500 ) {
			throw new InvalidArgumentException( 'Finance CSV export must contain only one bounded report page.' );
		}
		$handle = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Cannot open bounded export buffer.' );
		}
		try {
			if ( false === fputcsv( $handle, $columns, ',', '"', '', "\n" ) ) {
				throw new RuntimeException( 'Cannot write finance CSV header.' );
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					throw new InvalidArgumentException( 'Malformed finance export row.' );
				}
				$cells = [];
				foreach ( $columns as $column ) {
					if ( ! array_key_exists( $column, $row ) ) {
						throw new InvalidArgumentException( 'Report row is missing an authorized export column.' );
					}
					$cells[] = self::safe_cell( $row[$column] );
				}
				if ( false === fputcsv( $handle, $cells, ',', '"', '', "\n" ) ) {
					throw new RuntimeException( 'Cannot write finance export row.' );
				}
				if ( ftell( $handle ) > self::MAX_BYTES ) {
					throw new InvalidArgumentException( 'Finance CSV page exceeds 1 MiB export limit.' );
				}
			}
			rewind( $handle );
			$csv = stream_get_contents( $handle, self::MAX_BYTES + 1 );
			if ( false === $csv || strlen( $csv ) > self::MAX_BYTES ) {
				throw new InvalidArgumentException( 'Finance CSV page exceeds 1 MiB export limit.' );
			}
			return $csv;
		} finally {
			fclose( $handle );
		}
	}

	/** Convert exact scalars only, neutralizing spreadsheet formula execution. */
	private static function safe_cell( mixed $value ): string {
		if ( is_int( $value ) ) {
			if ( $value < 0 ) throw new InvalidArgumentException( 'Export numeric values must be nonnegative.' );
			return (string) $value;
		}
		if ( is_bool( $value ) ) return $value ? '1' : '0';
		if ( ! is_string( $value ) || strlen( $value ) > 4096 ||
			1 !== preg_match( '//u', $value ) || str_contains( $value, "\0" ) ) {
			throw new InvalidArgumentException( 'Invalid text in finance CSV row.' );
		}
		// Excel and other spreadsheets may treat leading whitespace/control
		// followed by =, +, -, or @ as an executable formula.
		if ( 1 === preg_match( '/\A[\p{Z}\p{C}]*[=+\-@]/u', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
