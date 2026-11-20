<?php
namespace WPEssential\BankTransferMatcherPro\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/** Read-only imported credits with explicit ambiguity; NEVER bank clearance. */
final class UnmatchedCreditReportRepository {
	private const MAX_SCAN = 3000;

	public function __construct( private readonly wpdb $db ) {}

	/** @return array<string,mixed> */
	public function run( ReportDefinition $scope ): array {
		if ( 'unmatched_credits' !== $scope->report ) {
			throw new InvalidArgumentException( 'Not an imported-credit report.' );
		}
		if ( get_current_user_id() < 1 || ! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Bank reporting requires finance access.' );
		}
		$rows = $this->db->prefix . 'wbm_pro_import_rows';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$alloc = $this->db->prefix . 'wbm_pro_allocations';
		foreach ( [ $rows, $batches, $accounts, $alloc ] as $table ) {
			$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->check_db();
			if ( $exists !== $table ) throw new RuntimeException( 'Required bank report source is missing.' );
		}
		$conditions = [ "b.status = 'completed'" ];
		$inputs = [];
		if ( $scope->before_id > 0 ) {
			$conditions[] = 'r.id < %d';
			$inputs[] = $scope->before_id;
		}
		if ( $scope->bank_account_id > 0 ) {
			$conditions[] = 'b.account_id = %d';
			$inputs[] = $scope->bank_account_id;
		}
		$inputs[] = self::MAX_SCAN + 1;
		$candidates = $this->db->get_results( $this->db->prepare(
			"SELECT r.id, r.canonical_json, r.error_code, r.warning_code,
			 r.identity_fingerprint, b.account_id, b.currency AS batch_currency,
			 a.currency AS account_currency, a.status AS account_status
			 FROM $rows r JOIN $batches b ON b.id = r.batch_id
			 JOIN $accounts a ON a.id = b.account_id
			 WHERE " . implode( ' AND ', $conditions ) .
			" ORDER BY r.id DESC LIMIT %d", ...$inputs
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $candidates ) ) throw new RuntimeException( 'Bank report query failed.' );
		if ( count( $candidates ) > self::MAX_SCAN ) {
			throw new RuntimeException( 'Too many bank rows to scan; narrow the bank account or cursor.' );
		}
		$output = [];
		$has_more = false;
		foreach ( $candidates as $row ) {
			if ( '' !== $row['error_code'] ) continue;
			$entry = json_decode( (string) $row['canonical_json'], true );
			if ( ! is_array( $entry ) ||
				! is_int( $entry['amount_minor'] ?? null ) || 0 === $entry['amount_minor'] ||
				! is_int( $entry['exponent'] ?? null ) ||
				$entry['exponent'] < 0 || $entry['exponent'] > 3 ||
				! is_string( $entry['currency'] ?? null ) ||
				1 !== preg_match( '/\A[A-Z]{3}\z/', $entry['currency'] ) ||
				! is_string( $entry['posted_date'] ?? null ) ||
				1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $entry['posted_date'] ) ||
				! is_string( $entry['reference'] ?? null ) ||
				1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) $row['identity_fingerprint'] ) ) {
				throw new InvalidArgumentException( 'Unparseable imported money is not reportable as credit.' );
			}
			// Imported bank statements can contain valid outgoing debits. They
			// are not incoming credits and must not abort this report.
			if ( $entry['amount_minor'] < 0 ) continue;
			$date = DateTimeImmutable::createFromFormat(
				'!Y-m-d', $entry['posted_date'], new DateTimeZone( 'UTC' )
			);
			if ( ! $date || $date->format( 'Y-m-d' ) !== $entry['posted_date'] ) {
				throw new InvalidArgumentException( 'Invalid bank posting date.' );
			}
			if ( $entry['posted_date'] < $scope->from || $entry['posted_date'] > $scope->to ||
				( 'ALL' !== $scope->currency && $entry['currency'] !== $scope->currency ) ) continue;
			$id = self::positive( $row['id'] );
			$account = self::positive( $row['account_id'] );
			$existing = $this->db->get_results( $this->db->prepare(
				"SELECT state FROM $alloc WHERE transaction_row_id = %d AND state <> 'reversed'", $id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $existing ) ) throw new RuntimeException( 'Bank allocation rows unavailable.' );
			$allocated = false;
			$review_pending = false;
			foreach ( $existing as $allocation ) {
				if ( in_array( $allocation['state'], [ 'approved', 'committed' ], true ) ) {
					$allocated = true;
				} elseif ( 'proposed' === $allocation['state'] ) {
					$review_pending = true;
				} else {
					throw new InvalidArgumentException( 'Unknown bank allocation state.' );
				}
			}
			if ( $allocated ) continue;
			$duplicates = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $rows r JOIN $batches b ON b.id = r.batch_id
				 WHERE b.account_id = %d AND b.status = 'completed'
				 AND r.error_code = '' AND r.identity_fingerprint = %s",
				$account, $row['identity_fingerprint']
			) );
			$this->check_db();
			$ambiguous = '' === trim( $entry['reference'] ) ||
				1 !== $duplicates || '' !== $row['warning_code'] ||
				'active' !== $row['account_status'] ||
				$entry['currency'] !== $row['account_currency'] ||
				$entry['currency'] !== $row['batch_currency'];
			// "reviewed" means the credit has a PROPOSAL awaiting a checker;
			// it is emphatically NOT approved or evidence of bank clearance.
			$state = $ambiguous ? 'ambiguous' : ( $review_pending ? 'reviewed' : 'unmatched' );
			if ( 'all' !== $scope->state && $state !== $scope->state ) continue;
			$output[] = [
				'id' => $id, 'source_id' => $id, 'bank_account_id' => $account,
				'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
				'state' => $state, 'amount_minor' => $entry['amount_minor'],
				'posted_date' => $entry['posted_date'],
				'ambiguous_bank_source' => $ambiguous,
				'pending_review_not_paid' => $review_pending,
				'unconfirmed_clearance' => true,
			];
			if ( count( $output ) > $scope->limit ) $has_more = true;
		}
		$output = array_slice( $output, 0, $scope->limit );
		return [
			'definition' => $scope->canonical(),
			'fingerprint' => $scope->fingerprint(),
			'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			'rows' => $output, 'page_totals' => PerCurrencyTotals::summarize( $output ),
			'has_more' => $has_more,
			'next_before_id' => $has_more && $output ? $output[count( $output ) - 1]['id'] : 0,
			'payment_authorized' => false, 'ledger_mutated' => false,
		];
	}

	private static function positive( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) return $value;
		if ( ! is_string( $value ) ||
			1 !== preg_match( '/\A[1-9]\d*\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid report source ID.' );
		}
		return (int) $value;
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Bank report storage error.' );
	}
}
