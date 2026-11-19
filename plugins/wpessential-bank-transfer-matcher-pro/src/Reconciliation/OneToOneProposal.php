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

/** Proposes ONE whole imported credit for ONE order; multiple credits may fund the same order. No payment_complete or REST route. */
final class OneToOneProposal {
	public function __construct( private readonly wpdb $db ) {}

	/** @return array{allocation_id:int,state:string,order_payment_changed:false} */
	public function propose( int $order_id, int $source_id, int $actor_id, string $request_key, string $reason ): array {
		$reason = trim( $reason );
		if ( min( $order_id, $source_id, $actor_id ) < 1 || $actor_id !== get_current_user_id() ||
			! current_user_can( FreeCaps::APPROVE ) || ! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Finance proposal permission is required.' );
		}
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $request_key ) ||
			strlen( $reason ) < 10 || strlen( $reason ) > 500 || str_contains( $reason, "\0" ) ) {
			throw new InvalidArgumentException( 'Valid request identity and review reason are required.' );
		}
		$ledger = new AllocationLedgerRepository( $this->db );
		$ledger->install();
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$locks = [
			hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id ),
			hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-pro-source/' . $source_id ),
		];
		sort( $locks, SORT_STRING );
		$held = [];
		$active = false;
		try {
			foreach ( $locks as $lock ) {
				if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
					throw new RuntimeException( 'Order or bank source is locked for review.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Transactions required.' );
			$active = true;
			$replay = $this->db->get_row( $this->db->prepare( "SELECT id, order_id, transaction_row_id, state FROM $table WHERE idempotency_key = %s FOR UPDATE", $request_key ), ARRAY_A );
			$this->check_db();
			if ( $replay ) {
				if ( (int) $replay['order_id'] !== $order_id || (int) $replay['transaction_row_id'] !== $source_id || 'proposed' !== $replay['state'] ) {
					throw new InvalidArgumentException( 'Request key was already used for another allocation.' );
				}
				if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Replay commit failed.' );
				$active = false;
				return [ 'allocation_id' => (int) $replay['id'], 'state' => 'proposed', 'order_payment_changed' => false ];
			}
			$rows = $this->db->prefix . 'wbm_pro_import_rows';
			$batches = $this->db->prefix . 'wbm_pro_import_batches';
			$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
			$source = $this->db->get_row( $this->db->prepare(
				"SELECT r.canonical_json, r.error_code, r.warning_code, r.identity_fingerprint,
				 b.account_id, b.currency AS batch_currency, b.status AS batch_status,
				 a.currency AS account_currency, a.status AS account_status
				 FROM $rows r JOIN $batches b ON b.id = r.batch_id JOIN $accounts a ON a.id = b.account_id
				 WHERE r.id = %d FOR UPDATE", $source_id
			), ARRAY_A );
			$this->check_db();
			if ( ! $source || 'completed' !== $source['batch_status'] || 'active' !== $source['account_status'] ||
				'' !== $source['error_code'] || '' !== $source['warning_code'] ||
				! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
				throw new InvalidArgumentException( 'A valid, completed, non-duplicate credit is required.' );
			}
			$duplicates = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $rows r JOIN $batches b ON b.id = r.batch_id
				 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
				 AND r.identity_fingerprint = %s",
				(int) $source['account_id'], $source['identity_fingerprint']
			) );
			$this->check_db();
			if ( 1 !== $duplicates ) throw new InvalidArgumentException( 'Duplicate or overlapping source evidence requires review.' );
			$entry = json_decode( (string) $source['canonical_json'], true );
			if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) ||
				$entry['amount_minor'] < 1 || ! is_int( $entry['exponent'] ?? null ) ||
				! is_string( $entry['currency'] ?? null ) || ! is_string( $entry['reference'] ?? null ) ||
				$entry['currency'] !== $source['batch_currency'] || $entry['currency'] !== $source['account_currency'] ) {
				throw new InvalidArgumentException( 'Source credit amount or currency is invalid.' );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || 'bacs' !== $order->get_payment_method() || $order->is_paid() ||
				! $order->has_status( [ 'pending', 'on-hold' ] ) ||
				$order->get_currency() !== $entry['currency'] || wc_get_price_decimals() !== $entry['exponent'] ) {
				throw new InvalidArgumentException( 'The order is not eligible for this credit.' );
			}
			$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order_id ) );
			if ( ! $reference ||
				ExactMatchFacts::normalize_reference( $reference->value ) !==
				ExactMatchFacts::normalize_reference( $entry['reference'] ) ) {
				throw new InvalidArgumentException( 'Exact order payment reference is required.' );
			}
			CrossEditionEvidenceGuard::assert_no_manual_overlap( $this->db, $order_id, (string) ( $entry['posted_date'] ?? '' ),
				$entry['amount_minor'], $entry['currency'], $entry['exponent'], $entry['reference'] );
			$expected = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), $entry['exponent'] )->minor_units;
			if ( $expected < 1 || $entry['amount_minor'] > $expected ) {
				throw new InvalidArgumentException( 'A credit cannot exceed the order total.' );
			}
			$conflicts = $this->db->get_results( $this->db->prepare(
				"SELECT id FROM $table WHERE transaction_row_id = %d AND state <> 'reversed' FOR UPDATE",
				$source_id
			), ARRAY_A );
			$this->check_db();
			if ( $conflicts ) throw new InvalidArgumentException( 'This bank credit already has an active proposal or allocation.' );
			$credit_minor = $entry['amount_minor'];
			$balance = $ledger->snapshot( $order_id, $source_id, $expected, $credit_minor, $entry['currency'], $entry['exponent'], (int) $source['account_id'] );
			if ( $balance['credit_unallocated_minor'] !== $credit_minor ) {
				throw new InvalidArgumentException( 'Bank credit was already allocated.' );
			}
			// Source must be spent in full by one order; split sources are a separate P5-T004 gate.
			PartialCreditPlanner::preview( $expected, $expected - $balance['order_outstanding_minor'],
				$entry['currency'], $entry['exponent'], [ [
					'source_id' => $source_id, 'available_minor' => $credit_minor,
					'allocate_minor' => $credit_minor, 'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
				] ] );
			$insert = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (transaction_row_id, source_fingerprint, bank_account_id, order_id,
				amount_minor, credit_minor, expected_minor, currency, exponent, state, created_by, reason, idempotency_key, created_at)
				VALUES (%d, %s, %d, %d, %d, %d, %d, %s, %d, 'proposed', %d, %s, %s, UTC_TIMESTAMP())",
				$source_id, $source['identity_fingerprint'], (int) $source['account_id'],
				$order_id, $credit_minor, $credit_minor, $expected, $entry['currency'], $entry['exponent'], $actor_id, $reason, $request_key
			) );
			if ( 1 !== $insert ) throw new RuntimeException( 'Could not insert proposal.' );
			$id = (int) $this->db->insert_id;
			$events = $this->db->prefix . 'wbm_pro_allocation_events';
			$json = wp_json_encode( [ 'order_id' => $order_id, 'transaction_row_id' => $source_id, 'status' => 'proposed' ] );
			if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
				"INSERT INTO $events (allocation_id, event_type, actor_id, event_json, created_at)
				VALUES (%d, 'proposed', %d, %s, UTC_TIMESTAMP())", $id, $actor_id, $json
			) ) || false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Proposal audit commit failed.' );
			$active = false;
			return [ 'allocation_id' => $id, 'state' => 'proposed', 'order_payment_changed' => false ];
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Allocation storage is unavailable.' );
	}
}
