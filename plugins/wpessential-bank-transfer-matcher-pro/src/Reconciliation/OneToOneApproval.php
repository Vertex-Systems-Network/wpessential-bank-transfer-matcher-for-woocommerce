<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\CrossEditionEvidenceGuard;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Matching\ExactMatchFacts;
use wpdb;

/**
 * Explicit second-person finance review of a complete imported credit, including partial order funding.
 * Approval records an allocation; WooCommerce payment completion is a separate step.
 * No REST route, background trigger, or automatic import integration.
 */
final class OneToOneApproval {
	public function __construct( private readonly wpdb $db ) {}

	/** @return array{allocation_id:int,status:string,order_payment_changed:false} */
	public function approve( int $allocation_id, int $reviewer_id, bool $cleared_confirmed, string $reason ): array {
		$reason = trim( $reason );
		if ( $allocation_id < 1 || $reviewer_id < 1 || get_current_user_id() !== $reviewer_id ||
			! current_user_can( FreeCaps::APPROVE ) || ! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Authorized finance approval is required.' );
		}
		if ( ! $cleared_confirmed || strlen( $reason ) < 10 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) ) {
			throw new InvalidArgumentException( 'Confirm cleared bank evidence and record the review reason.' );
		}
		$ledger = new AllocationLedgerRepository( $this->db );
		$ledger->install();
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$preflight = $this->db->get_row( $this->db->prepare(
			"SELECT order_id, transaction_row_id FROM $table WHERE id = %d", $allocation_id
		), ARRAY_A );
		if ( ! $preflight || '' !== $this->db->last_error ) throw new InvalidArgumentException( 'Proposal is unavailable.' );
		$order_id = (int) $preflight['order_id'];
		$source_id = (int) $preflight['transaction_row_id'];
		$locks = [
			hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id ),
			hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-pro-source/' . $source_id ),
		];
		sort( $locks, SORT_STRING );
		$held = [];
		$in_transaction = false;
		try {
			foreach ( $locks as $lock ) {
				if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
					throw new RuntimeException( 'Credit or order is currently being reconciled.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Transaction unavailable.' );
			$in_transaction = true;
			$proposal = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM $table WHERE id = %d FOR UPDATE", $allocation_id
			), ARRAY_A );
			$this->check_db();
			if ( ! $proposal || (int) $proposal['order_id'] !== $order_id ||
				(int) $proposal['transaction_row_id'] !== $source_id ) {
				throw new InvalidArgumentException( 'Proposal changed during finance review.' );
			}
			// Split rows MUST be reviewed atomically as an entire distribution.
			if ( ! empty( $proposal['split_group_key'] ) ) {
				throw new InvalidArgumentException( 'Split allocations require group approval; individual approval is forbidden.' );
			}
			if ( 'approved' === $proposal['state'] && (int) $proposal['approved_by'] === $reviewer_id ) {
				if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Replay commit failed.' );
				$in_transaction = false;
				return [ 'allocation_id' => $allocation_id, 'status' => 'already_approved', 'order_payment_changed' => false ];
			}
			if ( 'proposed' !== $proposal['state'] || (int) $proposal['created_by'] === $reviewer_id ) {
				throw new InvalidArgumentException( 'A separate reviewer must approve an open proposal.' );
			}

			$rows = $this->db->prefix . 'wbm_pro_import_rows';
			$batches = $this->db->prefix . 'wbm_pro_import_batches';
			$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
			$source = $this->db->get_row( $this->db->prepare(
				"SELECT r.canonical_json, r.identity_fingerprint, r.error_code, r.warning_code,
				 b.account_id, b.currency AS batch_currency, b.status AS batch_status,
				 a.status AS account_status, a.currency AS account_currency
				 FROM $rows r JOIN $batches b ON b.id = r.batch_id JOIN $accounts a ON a.id = b.account_id
				 WHERE r.id = %d FOR UPDATE", $source_id
			), ARRAY_A );
			$this->check_db();
			if ( ! $source || 'completed' !== $source['batch_status'] || 'active' !== $source['account_status'] ||
				'' !== $source['error_code'] || '' !== $source['warning_code'] ||
				(int) $source['account_id'] !== (int) $proposal['bank_account_id'] ||
				$source['identity_fingerprint'] !== $proposal['source_fingerprint'] ) {
				throw new InvalidArgumentException( 'Imported source provenance changed or is unverified.' );
			}
			$duplicates = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $rows r JOIN $batches b ON b.id = r.batch_id
				 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
				 AND r.identity_fingerprint = %s",
				(int) $source['account_id'], $source['identity_fingerprint']
			) );
			$this->check_db();
			if ( 1 !== $duplicates ) throw new InvalidArgumentException( 'Ambiguous bank credit cannot be approved.' );
			$entry = json_decode( (string) $source['canonical_json'], true );
			if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) || $entry['amount_minor'] < 1 ||
				! is_int( $entry['exponent'] ?? null ) || ! is_string( $entry['currency'] ?? null ) ||
				! is_string( $entry['reference'] ?? null ) ||
				$entry['amount_minor'] !== (int) $proposal['amount_minor'] ||
				$entry['amount_minor'] !== (int) $proposal['credit_minor'] ||
				$entry['currency'] !== $proposal['currency'] ||
				$entry['currency'] !== $source['batch_currency'] ||
				$entry['currency'] !== $source['account_currency'] ||
				$entry['exponent'] !== (int) $proposal['exponent'] ) {
				throw new InvalidArgumentException( 'Bank credit no longer matches the proposal.' );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || 'bacs' !== $order->get_payment_method() || $order->is_paid() ||
				! $order->has_status( [ 'pending', 'on-hold' ] ) ||
				$order->get_currency() !== $entry['currency'] ||
				wc_get_price_decimals() !== $entry['exponent'] ) {
				throw new InvalidArgumentException( 'Order payment eligibility changed.' );
			}
			$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order_id ) );
			if ( ! $reference ||
				ExactMatchFacts::normalize_reference( $reference->value ) !==
				ExactMatchFacts::normalize_reference( $entry['reference'] ) ) {
				throw new InvalidArgumentException( 'The payment reference no longer matches.' );
			}
			CrossEditionEvidenceGuard::assert_no_manual_overlap( $this->db, $order_id, (string) ( $entry['posted_date'] ?? '' ),
				$entry['amount_minor'], $entry['currency'], $entry['exponent'], $entry['reference'] );
			$expected = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), $entry['exponent'] )->minor_units;
			if ( $expected < 1 || $expected !== (int) $proposal['expected_minor'] ||
				$entry['amount_minor'] > $expected ) {
				throw new InvalidArgumentException( 'Order amount changed since proposal.' );
			}
			$credit_minor = $entry['amount_minor'];
			$balance = $ledger->snapshot( $order_id, $source_id, $expected, $credit_minor,
				$entry['currency'], $entry['exponent'], (int) $source['account_id'] );
			if ( $balance['credit_unallocated_minor'] !== $credit_minor ) {
				throw new InvalidArgumentException( 'Bank credit was previously allocated.' );
			}
			PartialCreditPlanner::preview( $expected, $expected - $balance['order_outstanding_minor'],
				$entry['currency'], $entry['exponent'], [ [
					'source_id' => $source_id, 'available_minor' => $credit_minor,
					'allocate_minor' => $credit_minor, 'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
				] ] );
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE $table SET state = 'approved', approved_by = %d, approved_at = UTC_TIMESTAMP(),
				 version = version + 1 WHERE id = %d AND state = 'proposed' AND created_by <> %d",
				$reviewer_id, $allocation_id, $reviewer_id
			) );
			if ( 1 !== $updated ) throw new RuntimeException( 'Proposal state was modified.' );
			$json = wp_json_encode( [ 'from' => 'proposed', 'to' => 'approved', 'reason' => $reason ] );
			$events = $this->db->prefix . 'wbm_pro_allocation_events';
			if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
				"INSERT INTO $events (allocation_id, event_type, actor_id, event_json, created_at)
				 VALUES (%d, 'approved', %d, %s, UTC_TIMESTAMP())",
				$allocation_id, $reviewer_id, $json
			) ) || false === $this->db->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not persist finance approval and audit.' );
			}
			$in_transaction = false;
			return [ 'allocation_id' => $allocation_id, 'status' => 'approved', 'order_payment_changed' => false ];
		} finally {
			if ( $in_transaction ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Approval evidence unavailable.' );
	}
}
