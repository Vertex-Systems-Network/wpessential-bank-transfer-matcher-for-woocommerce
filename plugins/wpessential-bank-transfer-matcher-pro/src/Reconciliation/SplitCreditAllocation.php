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
 * Explicit group proposal and atomic second-person finance approval for a single
 * bank credit allocated to distinct orders. Internal service, NO REST route,
 * NO automatic importer action, NO WooCommerce payment transition.
 */
final class SplitCreditAllocation {
	private const MAX_ORDERS = 25;

	public function __construct( private readonly wpdb $db ) {}

	/**
	 * This P5 gate distributes a WHOLE uniquely imported credit among 2–25 orders.
	 * Unallocated source remainders require a later approved workflow, never auto-spend.
	 * @param list<array{order_id:int,amount_minor:int}> $allocations
	 * @return array{split_group_key:string,allocation_ids:list<int>,status:string,order_payment_changed:false}
	 */
	public function propose( int $source_id, array $allocations, int $maker_id,
		string $request_key, string $reason ): array {
		$this->assert_actor( $maker_id, $reason );
		if ( $source_id < 1 || ! preg_match( '/\A[a-f0-9]{64}\z/', $request_key ) ||
			count( $allocations ) < 2 || count( $allocations ) > self::MAX_ORDERS ) {
			throw new InvalidArgumentException( 'Two or more distinct orders and a stable request key are required.' );
		}
		$lines = [];
		foreach ( $allocations as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['order_id'] ?? null ) ||
				! is_int( $item['amount_minor'] ?? null ) || $item['order_id'] < 1 || $item['amount_minor'] < 1 ||
				isset( $lines[ $item['order_id'] ] ) ) {
				throw new InvalidArgumentException( 'Split amounts must be positive integers for distinct orders.' );
			}
			$lines[ $item['order_id'] ] = $item['amount_minor'];
		}
		ksort( $lines, SORT_NUMERIC );
		$this->ledger()->install();
		return $this->locked( $source_id, array_keys( $lines ), function () use ( $source_id, $lines, $maker_id, $request_key, $reason ): array {
			$table = $this->table();
			$existing = $this->group_rows( $request_key, true );
			if ( $existing ) {
				$this->assert_group( $existing, $source_id, $lines, $maker_id, 'proposed' );
				return $this->result( $request_key, $existing, 'already_proposed' );
			}
			$source = $this->source( $source_id );
			$other = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE transaction_row_id = %d AND state <> 'reversed' FOR UPDATE", $source_id
			) );
			$this->check_db();
			if ( $other !== 0 ) throw new InvalidArgumentException( 'Bank credit already has an active proposal or allocation.' );

			$plan = [];
			$reference_anchor = false;
			foreach ( $lines as $order_id => $amount ) {
				$details = $this->eligible_order( $order_id, $source, true );
				$balance = $this->ledger()->snapshot( $order_id, $source_id, $details['expected'],
					$source['amount'], $source['currency'], $source['exponent'], $source['account_id'] );
				$plan[] = [
					'order_id' => $order_id, 'outstanding_minor' => $balance['order_outstanding_minor'],
					'allocate_minor' => $amount, 'currency' => $source['currency'], 'exponent' => $source['exponent'],
				];
				if ( $details['reference_matches'] ) $reference_anchor = true;
			}
			// Do not silently accept a deposit with no reference tied to ANY target order.
			if ( ! $reference_anchor ) {
				throw new InvalidArgumentException( 'A split requires a bank reference matching at least one target order.' );
			}
			$preview = SplitCreditPlanner::preview( $source['amount'], 0,
				$source['currency'], $source['exponent'], $plan );
			if ( ! $preview['fully_allocated'] ) {
				throw new InvalidArgumentException( 'This split workflow requires the entire credit to be assigned.' );
			}
			$ids = [];
			foreach ( $plan as $line ) {
				$order_id = $line['order_id'];
				$item_key = hash( 'sha256', "split-item\x1f" . $request_key . "\x1f" . $order_id );
				$expected = $line['outstanding_minor'];
				// Store the actual order TOTAL, not merely its outstanding balance.
				$order = wc_get_order( $order_id );
				$full = Money::from_decimal( (string) $order->get_total( 'edit' ),
					$source['currency'], $source['exponent'] )->minor_units;
				if ( $full < $expected ) throw new RuntimeException( 'Order balance changed during split review.' );
				$insert = $this->db->query( $this->db->prepare(
					"INSERT INTO $table (transaction_row_id, source_fingerprint, bank_account_id, order_id,
					 amount_minor, credit_minor, expected_minor, currency, exponent, state, created_by,
					 reason, idempotency_key, split_group_key, created_at)
					 VALUES (%d, %s, %d, %d, %d, %d, %d, %s, %d, 'proposed', %d, %s, %s, %s, UTC_TIMESTAMP())",
					$source_id, $source['fingerprint'], $source['account_id'], $order_id,
					$line['allocate_minor'], $source['amount'], $full,
					$source['currency'], $source['exponent'], $maker_id, $reason, $item_key, $request_key
				) );
				if ( 1 !== $insert ) throw new RuntimeException( 'Split proposal write failed.' );
				$id = (int) $this->db->insert_id;
				$this->event( $id, 'proposed', $maker_id, [
					'group' => $request_key, 'order_id' => $order_id,
					'source_id' => $source_id, 'amount_minor' => $line['allocate_minor'],
				] );
				$ids[] = $id;
			}
			return [
				'split_group_key' => $request_key, 'allocation_ids' => $ids,
				'status' => 'proposed', 'order_payment_changed' => false,
			];
		} );
	}

	/** Explicit independent reviewer confirmation; all group rows CAS together. */
	public function approve( string $group_key, int $reviewer_id,
		bool $cleared_confirmed, string $reason ): array {
		$this->assert_actor( $reviewer_id, $reason );
		if ( ! $cleared_confirmed || ! preg_match( '/\A[a-f0-9]{64}\z/', $group_key ) ) {
			throw new InvalidArgumentException( 'Bank clearance confirmation and valid split group are required.' );
		}
		$this->ledger()->install();
		$preflight = $this->group_rows( $group_key );
		if ( count( $preflight ) < 2 ) throw new InvalidArgumentException( 'Split group was not found or is incomplete.' );
		$source_id = (int) $preflight[0]['transaction_row_id'];
		$order_ids = array_map( static fn ( array $row ): int => (int) $row['order_id'], $preflight );
		return $this->locked( $source_id, $order_ids, function () use ( $group_key, $preflight,
			$source_id, $reviewer_id, $reason ): array {
			$rows = $this->group_rows( $group_key, true );
			// Protect against modifications or added rows between preflight and locking.
			$before = array_map( static fn ( array $row ): string =>
				$row['id'] . '/' . $row['order_id'] . '/' . $row['transaction_row_id'] . '/' . $row['amount_minor'], $preflight );
			$after = array_map( static fn ( array $row ): string =>
				$row['id'] . '/' . $row['order_id'] . '/' . $row['transaction_row_id'] . '/' . $row['amount_minor'], $rows );
			if ( $before !== $after ) throw new InvalidArgumentException( 'Split distribution changed during review.' );
			$maker_id = (int) $rows[0]['created_by'];
			$lines = [];
			$all_approved = true;
			foreach ( $rows as $row ) {
				$id = (int) $row['order_id'];
				if ( (int) $row['transaction_row_id'] !== $source_id || isset( $lines[$id] ) ||
					(int) $row['created_by'] !== $maker_id || (int) $row['amount_minor'] < 1 ) {
					throw new InvalidArgumentException( 'Split group has inconsistent provenance.' );
				}
				$lines[$id] = (int) $row['amount_minor'];
				if ( 'approved' !== $row['state'] || (int) $row['approved_by'] !== $reviewer_id ) $all_approved = false;
			}
			if ( $all_approved ) return $this->result( $group_key, $rows, 'already_approved' );
			if ( $maker_id === $reviewer_id ) throw new InvalidArgumentException( 'A different finance reviewer must approve the split.' );
			foreach ( $rows as $row ) if ( 'proposed' !== $row['state'] ) {
				throw new InvalidArgumentException( 'Split cannot be partly approved or modified.' );
			}
			$source = $this->source( $source_id );
			$table = $this->table();
			$other = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table WHERE transaction_row_id = %d AND (split_group_key IS NULL OR split_group_key <> %s)
				 AND state <> 'reversed' FOR UPDATE", $source_id, $group_key
			) );
			$this->check_db();
			if ( $other !== 0 ) throw new InvalidArgumentException( 'Bank credit has a competing allocation.' );
			$plan = [];
			$reference_anchor = false;
			foreach ( $rows as $row ) {
				$order_id = (int) $row['order_id'];
				$details = $this->eligible_order( $order_id, $source, true );
				if ( $details['expected'] !== (int) $row['expected_minor'] ||
					$source['fingerprint'] !== $row['source_fingerprint'] ||
					$source['account_id'] !== (int) $row['bank_account_id'] ||
					$source['amount'] !== (int) $row['credit_minor'] ||
					$source['currency'] !== $row['currency'] || $source['exponent'] !== (int) $row['exponent'] ) {
					throw new InvalidArgumentException( 'Order, source or account changed since split proposal.' );
				}
				$balance = $this->ledger()->snapshot( $order_id, $source_id, $details['expected'],
					$source['amount'], $source['currency'], $source['exponent'], $source['account_id'] );
				$plan[] = [
					'order_id' => $order_id, 'outstanding_minor' => $balance['order_outstanding_minor'],
					'allocate_minor' => $lines[$order_id], 'currency' => $source['currency'],
					'exponent' => $source['exponent'],
				];
				if ( $details['reference_matches'] ) $reference_anchor = true;
			}
			if ( ! $reference_anchor ) throw new InvalidArgumentException( 'Bank reference does not match any target order.' );
			$preview = SplitCreditPlanner::preview( $source['amount'], 0,
				$source['currency'], $source['exponent'], $plan );
			if ( ! $preview['fully_allocated'] ) throw new InvalidArgumentException( 'Incomplete bank credit split cannot be approved.' );
			$events = $this->db->prefix . 'wbm_pro_allocation_events';
			foreach ( $rows as $row ) {
				$updated = $this->db->query( $this->db->prepare(
					"UPDATE $table SET state = 'approved', approved_by = %d,
					 approved_at = UTC_TIMESTAMP(), version = version + 1
					 WHERE id = %d AND state = 'proposed' AND created_by <> %d",
					$reviewer_id, (int) $row['id'], $reviewer_id
				) );
				if ( 1 !== $updated ) throw new RuntimeException( 'Split state changed while approving.' );
				$this->event( (int) $row['id'], 'approved', $reviewer_id, [
					'group' => $group_key, 'reason' => $reason,
					'amount_minor' => (int) $row['amount_minor'],
				] );
			}
			return $this->result( $group_key, $rows, 'approved' );
		} );
	}

	/** @return array{account_id:int,amount:int,currency:string,exponent:int,reference:string,date:string,fingerprint:string} */
	private function source( int $source_id ): array {
		$rows = $this->db->prefix . 'wbm_pro_import_rows';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$source = $this->db->get_row( $this->db->prepare(
			"SELECT r.canonical_json, r.error_code, r.warning_code, r.identity_fingerprint,
			 b.status AS batch_status, b.account_id, b.currency AS batch_currency,
			 a.currency AS account_currency, a.status AS account_status
			 FROM $rows r JOIN $batches b ON b.id = r.batch_id JOIN $accounts a ON a.id = b.account_id
			 WHERE r.id = %d FOR UPDATE", $source_id
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $source ) || 'completed' !== $source['batch_status'] ||
			'active' !== $source['account_status'] || '' !== $source['error_code'] ||
			'' !== $source['warning_code'] ||
			! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'A unique completed imported credit is required.' );
		}
		$count = (int) $this->db->get_var( $this->db->prepare(
			"SELECT COUNT(*) FROM $rows r JOIN $batches b ON b.id = r.batch_id
			 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
			 AND r.identity_fingerprint = %s",
			(int) $source['account_id'], $source['identity_fingerprint']
		) );
		$this->check_db();
		if ( 1 !== $count ) throw new InvalidArgumentException( 'Imported bank credit is duplicated or ambiguous.' );
		$entry = json_decode( (string) $source['canonical_json'], true );
		if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) ||
			$entry['amount_minor'] < 1 || ! is_int( $entry['exponent'] ?? null ) ||
			! is_string( $entry['reference'] ?? null ) || '' === trim( $entry['reference'] ) ||
			! is_string( $entry['posted_date'] ?? null ) ||
			! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $entry['posted_date'] ) ||
			$entry['posted_date'] > gmdate( 'Y-m-d' ) ||
			! is_string( $entry['currency'] ?? null ) ||
			$entry['currency'] !== $source['batch_currency'] ||
			$entry['currency'] !== $source['account_currency'] ||
			$entry['exponent'] < 0 || $entry['exponent'] > 3 ) {
			throw new InvalidArgumentException( 'Invalid imported credit amount, account or posting date.' );
		}
		return [
			'account_id' => (int) $source['account_id'], 'amount' => $entry['amount_minor'],
			'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
			'reference' => $entry['reference'], 'date' => $entry['posted_date'],
			'fingerprint' => $source['identity_fingerprint'],
		];
	}

	/** @return array{expected:int,reference_matches:bool} */
	private function eligible_order( int $order_id, array $source, bool $cross_edition ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'bacs' !== $order->get_payment_method() || $order->is_paid() ||
			! $order->has_status( [ 'pending', 'on-hold' ] ) ||
			$order->get_currency() !== $source['currency'] || wc_get_price_decimals() !== $source['exponent'] ) {
			throw new InvalidArgumentException( 'A currently unpaid eligible BACS order is required.' );
		}
		$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order_id ) );
		if ( ! $reference ) throw new InvalidArgumentException( 'Target order has no immutable payment reference.' );
		if ( $cross_edition ) {
			CrossEditionEvidenceGuard::assert_no_manual_overlap( $this->db, $order_id,
				$source['date'], $source['amount'], $source['currency'], $source['exponent'], $source['reference'] );
		}
		$total = Money::from_decimal( (string) $order->get_total( 'edit' ),
			$source['currency'], $source['exponent'] )->minor_units;
		if ( $total < 1 ) throw new InvalidArgumentException( 'Target order has no positive amount outstanding.' );
		return [
			'expected' => $total,
			'reference_matches' => ExactMatchFacts::normalize_reference( $reference->value ) ===
				ExactMatchFacts::normalize_reference( $source['reference'] ),
		];
	}

	/** @return list<array<string,mixed>> */
	private function group_rows( string $group, bool $lock = false ): array {
		$table = $this->table();
		$suffix = $lock ? ' FOR UPDATE' : '';
		$rows = $this->db->get_results( $this->db->prepare(
			"SELECT * FROM $table WHERE split_group_key = %s ORDER BY order_id ASC, id ASC$suffix", $group
		), ARRAY_A );
		if ( ! is_array( $rows ) ) throw new RuntimeException( 'Could not read split allocation group.' );
		$this->check_db();
		return $rows;
	}

	/** @param list<array<string,mixed>> $rows
	 * @param array<int,int> $expected */
	private function assert_group( array $rows, int $source_id, array $expected, int $maker_id, string $status ): void {
		if ( count( $rows ) !== count( $expected ) ) throw new InvalidArgumentException( 'Request key belongs to a different split.' );
		foreach ( $rows as $row ) {
			$order_id = (int) $row['order_id'];
			if ( ! isset( $expected[$order_id] ) || $expected[$order_id] !== (int) $row['amount_minor'] ||
				$source_id !== (int) $row['transaction_row_id'] ||
				$maker_id !== (int) $row['created_by'] || $row['state'] !== $status ) {
				throw new InvalidArgumentException( 'Request key cannot be reused with another split.' );
			}
		}
	}

	/** @param list<array<string,mixed>> $rows
	 * @return array{split_group_key:string,allocation_ids:list<int>,status:string,order_payment_changed:false} */
	private function result( string $key, array $rows, string $status ): array {
		return [
			'split_group_key' => $key,
			'allocation_ids' => array_map( static fn ( array $row ): int => (int) $row['id'], $rows ),
			'status' => $status, 'order_payment_changed' => false,
		];
	}

	/**
	 * Named locks serialize with Free and one-to-one Pro allocation writers.
	 * A single transaction commits all split rows or none.
	 * @param list<int> $order_ids
	 */
	private function locked( int $source_id, array $order_ids, callable $callback ): array {
		$locks = [ hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-pro-source/' . $source_id ) ];
		foreach ( $order_ids as $id ) {
			$locks[] = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $id );
		}
		$locks = array_unique( $locks );
		sort( $locks, SORT_STRING );
		$held = [];
		$active = false;
		try {
			foreach ( $locks as $lock ) {
				if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
					throw new RuntimeException( 'Split source or target order is under concurrent reconciliation.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Split needs transactional storage.' );
			$active = true;
			$output = $callback();
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Split allocation transaction could not commit.' );
			$active = false;
			return $output;
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) {
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private function assert_actor( int $actor_id, string $reason ): void {
		$reason = trim( $reason );
		if ( $actor_id < 1 || get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) || ! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Authorized finance reviewer is required.' );
		}
		if ( strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'Explain the explicit split decision in a finance audit reason.' );
		}
	}

	/** @param array<string,mixed> $payload */
	private function event( int $allocation_id, string $type, int $actor_id, array $payload ): void {
		$json = wp_json_encode( $payload );
		$table = $this->db->prefix . 'wbm_pro_allocation_events';
		if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
			"INSERT INTO $table (allocation_id, event_type, actor_id, event_json, created_at)
			 VALUES (%d, %s, %d, %s, UTC_TIMESTAMP())",
			$allocation_id, $type, $actor_id, $json
		) ) ) throw new RuntimeException( 'Could not append split financial audit.' );
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Split bank reconciliation storage error.' );
	}
	private function ledger(): AllocationLedgerRepository { return new AllocationLedgerRepository( $this->db ); }
	private function table(): string { return $this->db->prefix . 'wbm_pro_allocations'; }
}
