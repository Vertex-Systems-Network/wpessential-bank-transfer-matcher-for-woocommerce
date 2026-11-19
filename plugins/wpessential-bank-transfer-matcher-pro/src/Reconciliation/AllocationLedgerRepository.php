<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use wpdb;

/** Pro-only ledger schema and informational balances. No allocation mutation API. */
final class AllocationLedgerRepository {
	private const VERSION = 'wpessential_wbm_pro_allocation_schema';

	public function __construct( private readonly wpdb $db ) {}

	public function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ static function ( wpdb $db ): bool {
			$table = $db->prefix . 'wbm_pro_allocations';
			$collation = $db->get_charset_collate();
			return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				transaction_row_id BIGINT UNSIGNED NOT NULL,
				source_fingerprint CHAR(64) NOT NULL,
				bank_account_id BIGINT UNSIGNED NOT NULL,
				order_id BIGINT UNSIGNED NOT NULL,
				amount_minor BIGINT UNSIGNED NOT NULL,
				credit_minor BIGINT UNSIGNED NOT NULL,
				expected_minor BIGINT UNSIGNED NOT NULL,
				currency CHAR(3) NOT NULL,
				exponent TINYINT UNSIGNED NOT NULL,
				state VARCHAR(16) NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				approved_by BIGINT UNSIGNED NULL,
				review_case_id BIGINT UNSIGNED NULL,
				reason TEXT NOT NULL,
				version BIGINT UNSIGNED NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				approved_at DATETIME NULL,
				committed_at DATETIME NULL,
				reversed_at DATETIME NULL,
				KEY source_state (transaction_row_id, state, id),
				KEY order_state (order_id, state, id),
				KEY account_state (bank_account_id, state, id),
				KEY fingerprint (source_fingerprint)
			) ENGINE=InnoDB $collation" );
		} ] );
	}

	/** Read-only snapshot; P5 write operations must revalidate inside source/order locks. */
	public function snapshot( int $order_id, int $source_id, int $expected, int $credit,
		string $currency, int $exponent, int $account_id ): array {
		$this->install();
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$rows = $this->db->get_results( $this->db->prepare(
			"SELECT order_id, transaction_row_id, bank_account_id, amount_minor, credit_minor, expected_minor, currency, exponent, state FROM $table WHERE order_id = %d OR transaction_row_id = %d ORDER BY id ASC",
			$order_id, $source_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) throw new RuntimeException( 'Ledger query failed.' );
		$manual = [];
		$free = $this->db->prefix . 'wbm_manual_allocations';
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $free ) );
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Free ledger query failed.' );
		if ( $exists === $free ) {
			$manual = $this->db->get_results( $this->db->prepare(
				"SELECT amount_minor, expected_minor, currency, exponent FROM $free WHERE order_id = %d ORDER BY id ASC",
				$order_id
			), ARRAY_A );
			if ( ! is_array( $manual ) || '' !== $this->db->last_error ) throw new RuntimeException( 'Free allocations unavailable.' );
		}
		return LedgerBalance::summarize( $order_id, $source_id, $expected, $credit, $currency, $exponent, $account_id, $rows, $manual );
	}
}
