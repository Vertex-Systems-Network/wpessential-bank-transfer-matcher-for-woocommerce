<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Evidence\CrossEditionEvidenceGuard;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Matching\ExactMatchFacts;
use wpdb;

/**
 * Internal, explicitly invoked WooCommerce finalizer. No cron, REST or bank import
 * connection. A human finance actor confirms the bank credit AFTER an independent
 * maker/checker approval of every source. No payment_complete inside SQL transaction.
 *
 * P5 safety boundary: a mixed Free/Pro order cannot be finalized until equivalent
 * independent manual-evidence attestation is implemented. Existing Free-only orders
 * retain the Free payment finalizer.
 */
final class ProPaymentFinalizer {
	private const VERSION = 'wpessential_wbm_pro_finalization_schema';

	public function __construct( private readonly wpdb $db ) {}

	/**
	 * @return array{order_id:int,status:string,order_payment_changed:bool}
	 */
	public function complete( int $order_id, int $actor_id, string $request_key,
		bool $cleared_confirmed, string $reason ): array {
		if ( $order_id < 1 || $actor_id < 1 ||
			get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Explicit authorized finance finalization is required.' );
		}
		$reason = trim( $reason );
		if ( ! $cleared_confirmed || ! preg_match( '/\A[a-f0-9]{64}\z/', $request_key ) ||
			strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'Verify cleared credit, a stable request key and the finance decision.' );
		}
		( new AllocationLedgerRepository( $this->db ) )->install();
		$this->install();
		$preflight = $this->order_rows( $order_id, false );
		if ( [] === $preflight ) throw new InvalidArgumentException( 'There is no approved Pro evidence to finalize.' );
		$source_ids = array_values( array_unique( array_map(
			static fn ( array $row ): int => (int) $row['transaction_row_id'], $preflight
		) ) );
		$lock_keys = [ $this->lock_key( 'wbm-allocation', $order_id ) ];
		// A split source is shared by other recipient orders. Lock those orders
		// too: a concurrent Free manual writer only holds its recipient order
		// lock, and must never change split provenance during payment.
		$placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );
		$recipient_ids = $this->db->get_col( $this->db->prepare(
			"SELECT DISTINCT order_id FROM {$this->db->prefix}wbm_pro_allocations
			 WHERE transaction_row_id IN ($placeholders)", ...$source_ids
		) );
		$this->check_db();
		if ( ! is_array( $recipient_ids ) || [] === $recipient_ids ) {
			throw new RuntimeException( 'Split payment recipients cannot be locked.' );
		}
		foreach ( $recipient_ids as $recipient_id ) {
			if ( (int) $recipient_id < 1 ) throw new InvalidArgumentException( 'Invalid split recipient identity.' );
			$lock_keys[] = $this->lock_key( 'wbm-allocation', (int) $recipient_id );
		}
		foreach ( $source_ids as $source_id ) {
			if ( $source_id < 1 ) throw new InvalidArgumentException( 'Invalid imported source identity.' );
			$lock_keys[] = $this->lock_key( 'wbm-pro-source', $source_id );
		}
		$lock_keys = array_values( array_unique( $lock_keys ) );
		sort( $lock_keys, SORT_STRING );
		$held = [];
		$active = false;
		try {
			foreach ( $lock_keys as $lock_key ) {
				if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_key ) ) ) {
					throw new RuntimeException( 'Pro payment source/order under concurrent reconciliation.' );
				}
				$held[] = $lock_key;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Payment intent needs transactional storage.' );
			}
			$active = true;
			$validated = $this->validate( $order_id, $actor_id, $preflight, $source_ids );
			$order = $validated['order'];
			$digest = $validated['hash'];
			$table = $this->intent_table();
			$intent = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM $table WHERE order_id = %d FOR UPDATE", $order_id
			), ARRAY_A );
			$this->check_db();
			if ( $intent && ( $intent['request_key'] !== $request_key ||
				(int) $intent['actor_id'] !== $actor_id || $intent['ledger_sha256'] !== $digest ) ) {
				throw new InvalidArgumentException( 'Payment intent belongs to a different finance decision or ledger.' );
			}
			// WC hooks may have completed before the previous request failed to
			// persist its post-payment audit. Never pay twice on such a replay.
			if ( $order->is_paid() ) {
				if ( ! $intent || ! in_array( $intent['state'], [ 'pending', 'completed' ], true ) ) {
					throw new InvalidArgumentException( 'Paid order has no matching finalization intent.' );
				}
				$was_complete = 'completed' === $intent['state'];
				if ( ! $was_complete ) $this->commit_financial_audit( $order_id, $actor_id, $digest, $reason );
				if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Paid order audit recovery failed.' );
				$active = false;
				return [
					'order_id' => $order_id,
					'status' => $was_complete ? 'already_completed' : 'audit_recovered',
					'order_payment_changed' => false,
				];
			}
			if ( ! $order->has_status( [ 'on-hold', 'pending' ] ) ) {
				throw new InvalidArgumentException( 'Order status must remain unpaid until explicit finalization.' );
			}
			if ( $intent && 'pending' !== $intent['state'] ) {
				throw new InvalidArgumentException( 'Payment intent conflicts with WooCommerce order state.' );
			}
			if ( ! $intent ) {
				$inserted = $this->db->query( $this->db->prepare(
					"INSERT INTO $table (order_id, actor_id, request_key, ledger_sha256, state, reason, created_at)
					 VALUES (%d, %d, %s, %s, 'pending', %s, UTC_TIMESTAMP())",
					$order_id, $actor_id, $request_key, $digest, $reason
				) );
				if ( 1 !== $inserted ) throw new RuntimeException( 'Could not write durable finance payment intent.' );
			}
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Could not persist finalization intent.' );
			$active = false;

			// Recheck live WooCommerce order before calling hooks, while still
			// holding the source and order advisory locks.
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh || $fresh->is_paid() || ! $fresh->has_status( [ 'on-hold', 'pending' ] ) ||
				'bacs' !== $fresh->get_payment_method() ||
				$fresh->get_currency() !== $order->get_currency() ||
				$fresh->get_total( 'edit' ) !== $order->get_total( 'edit' ) ) {
				throw new RuntimeException( 'WooCommerce order changed after durable payment intent.' );
			}
			try {
				$completed = $fresh->payment_complete();
			} catch ( Throwable $error ) {
				// The intent remains pending, and a later EXPLICIT invocation
				// must inspect WC paid status before ever calling hooks again.
				throw new RuntimeException( 'WooCommerce payment hook failed; pending intent retained for recovery.', 0, $error );
			}
			$paid = wc_get_order( $order_id );
			if ( ! $completed ) {
				// WooCommerce can swallow a thrown payment hook and return false
				// AFTER it has already persisted the paid order. Keep the durable
				// intent pending so an explicit retry reconciles the audit and
				// never invokes payment_complete for an already-paid order.
				throw new RuntimeException( 'WooCommerce payment reported failure; pending intent retained for recovery.' );
			}
			if ( ! $paid || ! $paid->is_paid() ) {
				throw new RuntimeException( 'WooCommerce has not recorded a paid order; pending intent retained for recovery.' );
			}

			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Order is paid but audit requires manual recovery.' );
			}
			$active = true;
			$this->commit_financial_audit( $order_id, $actor_id, $digest, $reason );
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Paid order audit could not commit.' );
			$active = false;
			return [ 'order_id' => $order_id, 'status' => 'completed', 'order_payment_changed' => true ];
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $key ) {
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			}
		}
	}

	private function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_payment_finalizations';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
					actor_id BIGINT UNSIGNED NOT NULL,
					request_key CHAR(64) NOT NULL,
					ledger_sha256 CHAR(64) NOT NULL,
					state VARCHAR(16) NOT NULL,
					reason TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					completed_at DATETIME NULL,
					completed_by BIGINT UNSIGNED NULL,
					UNIQUE KEY request_key (request_key)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/** @return list<array<string,mixed>> */
	private function order_rows( int $order_id, bool $for_update ): array {
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$suffix = $for_update ? ' FOR UPDATE' : '';
		$rows = $this->db->get_results( $this->db->prepare(
			"SELECT * FROM $table WHERE order_id = %d ORDER BY transaction_row_id, id$suffix", $order_id
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $rows ) ) throw new RuntimeException( 'Pro allocation evidence cannot be loaded.' );
		return $rows;
	}

	/**
	 * Validate under ALL source/order advisory locks and within a database
	 * transaction; never trust an earlier readiness snapshot or UI assertion.
	 * @param list<array<string,mixed>> $preflight
	 * @param list<int> $source_ids
	 * @return array{order:\WC_Order,hash:string}
	 */
	private function validate( int $order_id, int $actor_id, array $preflight, array $source_ids ): array {
		$rows = $this->order_rows( $order_id, true );
		$snapshot = static fn ( array $row ): string =>
			$row['id'] . '/' . $row['transaction_row_id'] . '/' . $row['amount_minor'] . '/' .
			$row['expected_minor'] . '/' . $row['source_fingerprint'];
		if ( array_map( $snapshot, $preflight ) !== array_map( $snapshot, $rows ) ||
			[] === $rows ) {
			throw new InvalidArgumentException( 'Allocation set changed between preview and locking.' );
		}
		foreach ( $rows as $row ) {
			if ( ! in_array( (int) $row['transaction_row_id'], $source_ids, true ) ||
				! in_array( $row['state'], [ 'approved', 'committed' ], true ) ) {
				throw new InvalidArgumentException( 'All imported allocations must be independently approved.' );
			}
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || 'bacs' !== $order->get_payment_method() ||
			( ! $order->is_paid() && ! $order->has_status( [ 'pending', 'on-hold' ] ) ) ) {
			throw new InvalidArgumentException( 'Finalization requires a valid BACS order.' );
		}
		$currency = $order->get_currency();
		$exponent = wc_get_price_decimals();
		$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $currency, $exponent )->minor_units;
		if ( $total < 1 ) throw new InvalidArgumentException( 'Cannot finalize a zero or invalid order.' );
		$free_final = $this->db->prefix . 'wbm_payment_finalizations';
		if ( $this->table_exists( $free_final ) ) {
			$free_record = $this->db->get_var( $this->db->prepare(
				"SELECT state FROM $free_final WHERE order_id = %d FOR UPDATE", $order_id
			) );
			$this->check_db();
			if ( null !== $free_record ) {
				throw new InvalidArgumentException( 'Free edition has a payment finalization intent; resolve it first.' );
			}
		}
		$free_count = 0;
		$manual = $this->db->prefix . 'wbm_manual_allocations';
		if ( $this->table_exists( $manual ) ) {
			$free_count = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $manual WHERE order_id = %d FOR UPDATE", $order_id
			) );
			$this->check_db();
		}
		// Never treat a merchant-entered Free record as a second, independently
		// bank-confirmed payment. Mixed edition finalization remains gated.
		if ( $free_count > 0 ) {
			throw new InvalidArgumentException( 'Mixed Free/Pro payments need independent manual evidence review before finalization.' );
		}
		$pending = 0;
		$approved_sum = 0;
		$hash_rows = [];
		$by_source = [];
		foreach ( $rows as $row ) {
			$source_id = (int) $row['transaction_row_id'];
			$by_source[$source_id] = true;
			if ( (int) $row['expected_minor'] !== $total ||
				$row['currency'] !== $currency || (int) $row['exponent'] !== $exponent ||
				(int) $row['amount_minor'] < 1 ||
				(int) $row['amount_minor'] > $total - $approved_sum ||
				(int) $row['created_by'] < 1 || (int) $row['approved_by'] < 1 ||
				(int) $row['created_by'] === (int) $row['approved_by'] ||
				(int) $row['created_by'] === $actor_id ||
				empty( $row['approved_at'] ) ) {
				throw new InvalidArgumentException( 'Approved bank allocation has inconsistent maker/checker or amount facts.' );
			}
			$approved_sum += (int) $row['amount_minor'];
			$hash_rows[] = [
				'id' => (int) $row['id'], 'source' => $source_id,
				'amount' => (int) $row['amount_minor'], 'expected' => $total,
				'account' => (int) $row['bank_account_id'],
				'fingerprint' => $row['source_fingerprint'],
				'maker' => (int) $row['created_by'], 'checker' => (int) $row['approved_by'],
				'split' => $row['split_group_key'],
			];
			$this->verify_approval_audit( $row );
		}
		if ( count( $by_source ) !== count( $source_ids ) ||
			$approved_sum !== $total ) {
			throw new InvalidArgumentException( 'Order does not have a complete unique approved credit balance.' );
		}
		$source_table = $this->db->prefix . 'wbm_pro_import_rows';
		$batch_table = $this->db->prefix . 'wbm_pro_import_batches';
		$account_table = $this->db->prefix . 'wbm_pro_bank_accounts';
		foreach ( $source_ids as $source_id ) {
			$source = $this->db->get_row( $this->db->prepare(
				"SELECT r.canonical_json, r.identity_fingerprint, r.error_code, r.warning_code,
				 b.account_id, b.status AS batch_status, b.currency AS batch_currency,
				 a.status AS account_status, a.currency AS account_currency
				 FROM $source_table r JOIN $batch_table b ON b.id = r.batch_id
				 JOIN $account_table a ON a.id = b.account_id WHERE r.id = %d FOR UPDATE", $source_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $source ) || 'completed' !== $source['batch_status'] ||
				'active' !== $source['account_status'] || '' !== $source['error_code'] ||
				'' !== $source['warning_code'] ||
				! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
				throw new InvalidArgumentException( 'Imported source was removed, flagged or never fully processed.' );
			}
			$duplicates = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $source_table r JOIN $batch_table b ON b.id = r.batch_id
				 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
				 AND r.identity_fingerprint = %s",
				(int) $source['account_id'], $source['identity_fingerprint']
			) );
			$this->check_db();
			if ( $duplicates !== 1 ) throw new InvalidArgumentException( 'Bank source has ambiguous transaction identity.' );
			$entry = json_decode( (string) $source['canonical_json'], true );
			if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) ||
				$entry['amount_minor'] < 1 || ! is_int( $entry['exponent'] ?? null ) ||
				$entry['exponent'] < 0 || $entry['exponent'] > 3 ||
				! is_string( $entry['currency'] ?? null ) ||
				$entry['currency'] !== $currency ||
				$entry['currency'] !== $source['batch_currency'] ||
				$entry['currency'] !== $source['account_currency'] ||
				$entry['exponent'] !== $exponent ||
				! is_string( $entry['reference'] ?? null ) || '' === trim( $entry['reference'] ) ||
				! is_string( $entry['posted_date'] ?? null ) ||
				! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $entry['posted_date'] ) ||
				$entry['posted_date'] > gmdate( 'Y-m-d' ) ) {
				throw new InvalidArgumentException( 'Bank source monetary provenance no longer matches the order.' );
			}
			$source_allocations = $this->db->get_results( $this->db->prepare(
				"SELECT * FROM {$this->db->prefix}wbm_pro_allocations
				 WHERE transaction_row_id = %d ORDER BY order_id ASC, id ASC FOR UPDATE", $source_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $source_allocations ) || [] === $source_allocations ) {
				throw new InvalidArgumentException( 'Bank credit has no approved allocations.' );
			}
			$used = 0;
			$group = null;
			$seen_orders = [];
			$has_reference_anchor = false;
			foreach ( $source_allocations as $allocation ) {
				$recipient_id = (int) $allocation['order_id'];
				$amount = (int) $allocation['amount_minor'];
				if ( isset( $seen_orders[$recipient_id] ) ||
					! in_array( $allocation['state'], [ 'approved', 'committed' ], true ) ||
					$recipient_id < 1 || $amount < 1 || $amount > $entry['amount_minor'] - $used ||
					(int) $allocation['credit_minor'] !== $entry['amount_minor'] ||
					(int) $allocation['bank_account_id'] !== (int) $source['account_id'] ||
					$allocation['currency'] !== $currency ||
					(int) $allocation['exponent'] !== $exponent ||
					$allocation['source_fingerprint'] !== $source['identity_fingerprint'] ||
					(int) $allocation['created_by'] < 1 ||
					(int) $allocation['approved_by'] < 1 ||
					(int) $allocation['created_by'] === (int) $allocation['approved_by'] ) {
					throw new InvalidArgumentException( 'Bank source split is not fully approved or provenance changed.' );
				}
				$seen_orders[$recipient_id] = true;
				$used += $amount;
				$recipient = wc_get_order( $recipient_id );
				if ( ! $recipient || 'bacs' !== $recipient->get_payment_method() ||
					$recipient->get_currency() !== $currency ||
					$recipient->has_status( [ 'cancelled', 'failed', 'refunded' ] ) ||
					Money::from_decimal( (string) $recipient->get_total( 'edit' ), $currency, $exponent )->minor_units
						!== (int) $allocation['expected_minor'] ) {
					throw new InvalidArgumentException( 'Split recipient order is missing or its payment facts changed.' );
				}
				( new OrderReceivingAccountRepository( $this->db ) )->assert_matches( $recipient_id, (int) $source['account_id'] );
				$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $recipient_id ) );
				if ( ! $reference ) throw new InvalidArgumentException( 'Recipient lost immutable bank reference.' );
				if ( ExactMatchFacts::normalize_reference( $reference->value ) ===
					ExactMatchFacts::normalize_reference( $entry['reference'] ) ) {
					$has_reference_anchor = true;
				}
				CrossEditionEvidenceGuard::assert_no_manual_overlap( $this->db, $recipient_id,
					$entry['posted_date'], $entry['amount_minor'], $entry['currency'],
					$entry['exponent'], $entry['reference'] );
				$this->verify_approval_audit( $allocation );
				if ( $group === null ) $group = $allocation['split_group_key'];
				if ( $group !== $allocation['split_group_key'] ) {
					throw new InvalidArgumentException( 'Imported bank source has inconsistent split grouping.' );
				}
			}
			if ( $used !== $entry['amount_minor'] || ! $has_reference_anchor ||
				( null === $group && count( $source_allocations ) !== 1 ) ||
				( null !== $group && count( $source_allocations ) < 2 ) ) {
				throw new InvalidArgumentException( 'Bank credit is not fully and consistently assigned.' );
			}
		}
		// A reviewed exception is still not an actual completed bank refund or
		// allocation correction. Both open and reviewed cases block finalization.
		foreach ( [ 'wbm_pro_exceptions', 'wbm_pro_mismatch_cases' ] as $suffix ) {
			$table = $this->db->prefix . $suffix;
			if ( ! $this->table_exists( $table ) ) continue;
			$placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );
			$count = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $table
				 WHERE order_id = %d OR transaction_row_id IN ($placeholders) FOR UPDATE",
				$order_id, ...$source_ids
			) );
			$this->check_db();
			if ( $count > 0 ) throw new InvalidArgumentException( 'Unresolved bank exception or mismatch holds payment finalization.' );
		}
		$review = PaymentReadiness::inspect( [
			'expected_minor' => $total, 'free_allocated_minor' => 0,
			'pro_approved_minor' => $approved_sum, 'pro_approved_row_count' => count( $rows ),
			'pending_allocation_reviews' => $pending, 'unresolved_financial_exceptions' => 0,
			'currency' => $currency, 'exponent' => $exponent,
			'gateway' => $order->get_payment_method(),
			// A matching completed intent and paid WC order may be audited on retry.
			'order_status' => $order->is_paid() ? 'on-hold' : $order->get_status(),
			'order_is_paid' => false,
			'bank_sources_verified' => true, 'manual_evidence_verified' => true,
			'maker_checker_verified' => true, 'receiving_accounts_verified' => true,
		] );
		if ( ! $review['ready_for_explicit_finalization'] ) {
			throw new InvalidArgumentException( 'A source or order readiness gate blocks payment finalization.' );
		}
		$json = wp_json_encode( [
			'order' => $order_id, 'currency' => $currency, 'exponent' => $exponent,
			'total' => $total, 'allocations' => $hash_rows,
		] );
		if ( ! is_string( $json ) ) throw new RuntimeException( 'Cannot persist finance ledger hash.' );
		return [ 'order' => $order, 'hash' => hash( 'sha256', $json ) ];
	}

	/** For each credit, exactly one maker + checker event; committed is optional. */
	private function verify_approval_audit( array $row ): void {
		$table = $this->db->prefix . 'wbm_pro_allocation_events';
		$events = $this->db->get_results( $this->db->prepare(
			"SELECT event_type, actor_id FROM $table WHERE allocation_id = %d ORDER BY id ASC FOR UPDATE",
			(int) $row['id']
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $events ) || count( $events ) !== ( 'committed' === $row['state'] ? 3 : 2 ) ||
			$events[0]['event_type'] !== 'proposed' ||
			$events[1]['event_type'] !== 'approved' ||
			(int) $events[0]['actor_id'] !== (int) $row['created_by'] ||
			(int) $events[1]['actor_id'] !== (int) $row['approved_by'] ||
			( 'committed' === $row['state'] && $events[2]['event_type'] !== 'committed' ) ) {
			throw new InvalidArgumentException( 'Independent approval audit is incomplete or tampered.' );
		}
	}

	private function commit_financial_audit( int $order_id, int $actor_id, string $digest, string $reason ): void {
		$table = $this->intent_table();
		$row = $this->db->get_row( $this->db->prepare(
			"SELECT state, ledger_sha256 FROM $table WHERE order_id = %d FOR UPDATE", $order_id
		), ARRAY_A );
		$this->check_db();
		if ( ! $row || 'pending' !== $row['state'] || $digest !== $row['ledger_sha256'] ) {
			throw new InvalidArgumentException( 'Payment intent cannot be committed without a matching approved ledger.' );
		}
		$alloc = $this->db->prefix . 'wbm_pro_allocations';
		$events = $this->db->prefix . 'wbm_pro_allocation_events';
		$rows = $this->order_rows( $order_id, true );
		foreach ( $rows as $item ) {
			if ( 'committed' === $item['state'] ) continue;
			if ( 'approved' !== $item['state'] ) throw new RuntimeException( 'Allocation state changed during WooCommerce payment.' );
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE $alloc SET state = 'committed', committed_at = UTC_TIMESTAMP(), version = version + 1
				 WHERE id = %d AND state = 'approved'", (int) $item['id']
			) );
			if ( 1 !== $updated ) throw new RuntimeException( 'Could not commit reviewed bank allocation.' );
			$detail = wp_json_encode( [ 'order_id' => $order_id, 'ledger_sha256' => $digest,
				'reason' => $reason ] );
			if ( ! is_string( $detail ) || 1 !== $this->db->query( $this->db->prepare(
				"INSERT INTO $events (allocation_id, event_type, actor_id, event_json, created_at)
				 VALUES (%d, 'committed', %d, %s, UTC_TIMESTAMP())",
				(int) $item['id'], $actor_id, $detail
			) ) ) throw new RuntimeException( 'Commit event was not appended.' );
		}
		if ( 1 !== $this->db->query( $this->db->prepare(
			"UPDATE $table SET state = 'completed', completed_at = UTC_TIMESTAMP(), completed_by = %d
			 WHERE order_id = %d AND state = 'pending'", $actor_id, $order_id
		) ) ) throw new RuntimeException( 'Paid order finalization audit requires repair.' );
	}

	private function table_exists( string $table ): bool {
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->check_db();
		return $exists === $table;
	}

	private function lock_key( string $category, int $value ): string {
		return hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . $category . '/' . $value );
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Payment audit storage is unavailable.' );
	}

	private function intent_table(): string {
		return $this->db->prefix . 'wbm_pro_payment_finalizations';
	}
}
