<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
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
 * Immutable source-linked exception evidence and append-only reviewer annotations.
 * Never allocates, refunds, cancels, releases or marks a WooCommerce order paid.
 */
final class OverpaymentExceptionRepository {
	private const VERSION = 'wpessential_wbm_pro_exception_schema';

	public function __construct( private readonly wpdb $db ) {}

	public function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_exceptions';
				$events = $db->prefix . 'wbm_pro_exception_events';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					transaction_row_id BIGINT UNSIGNED NOT NULL,
					bank_account_id BIGINT UNSIGNED NOT NULL,
					source_fingerprint CHAR(64) NOT NULL,
					order_id BIGINT UNSIGNED NOT NULL,
					credit_minor BIGINT UNSIGNED NOT NULL,
					expected_minor BIGINT UNSIGNED NOT NULL,
					credit_unallocated_minor BIGINT UNSIGNED NOT NULL,
					order_outstanding_minor BIGINT UNSIGNED NOT NULL,
					overpayment_minor BIGINT UNSIGNED NOT NULL,
					currency CHAR(3) NOT NULL,
					exponent TINYINT UNSIGNED NOT NULL,
					exception_code VARCHAR(40) NOT NULL,
					status VARCHAR(12) NOT NULL,
					request_key CHAR(64) NOT NULL,
					created_by BIGINT UNSIGNED NOT NULL,
					reviewed_by BIGINT UNSIGNED NULL,
					review_decision VARCHAR(40) NULL,
					reason TEXT NOT NULL,
					version BIGINT UNSIGNED NOT NULL DEFAULT 1,
					created_at DATETIME NOT NULL,
					reviewed_at DATETIME NULL,
					UNIQUE KEY source_order (transaction_row_id, order_id),
					UNIQUE KEY request_key (request_key),
					KEY queue (status, id)
				) ENGINE=InnoDB $collation" ) &&
				false !== $db->query( "CREATE TABLE IF NOT EXISTS $events (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					exception_id BIGINT UNSIGNED NOT NULL,
					actor_id BIGINT UNSIGNED NOT NULL,
					event_type VARCHAR(24) NOT NULL,
					event_json TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					KEY exception_history (exception_id, id)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/** @return array{exception_id:int,status:string,credit_unallocated_minor:int,order_payment_changed:false} */
	public function raise( int $source_id, int $order_id, int $actor_id,
		string $request_key, string $reason ): array {
		$this->actor( $actor_id, $reason );
		if ( min( $source_id, $order_id ) < 1 || ! preg_match( '/\A[a-f0-9]{64}\z/', $request_key ) ) {
			throw new InvalidArgumentException( 'A source, order and stable exception request key are required.' );
		}
		$this->install();
		return $this->locked( $source_id, $order_id, function () use (
			$source_id, $order_id, $actor_id, $request_key, $reason
		): array {
			$table = $this->table();
			$existing = $this->db->get_row( $this->db->prepare(
				"SELECT id, transaction_row_id, order_id, status, created_by, credit_unallocated_minor
				 FROM $table WHERE request_key = %s FOR UPDATE", $request_key
			), ARRAY_A );
			$this->check_db();
			if ( is_array( $existing ) ) {
				if ( $source_id !== (int) $existing['transaction_row_id'] ||
					$order_id !== (int) $existing['order_id'] || $actor_id !== (int) $existing['created_by'] ) {
					throw new InvalidArgumentException( 'An exception request key cannot be reused.' );
				}
				return [
					'exception_id' => (int) $existing['id'], 'status' => 'already_' . $existing['status'],
					'credit_unallocated_minor' => (int) $existing['credit_unallocated_minor'],
					'order_payment_changed' => false,
				];
			}
			$prior = $this->db->get_var( $this->db->prepare(
				"SELECT id FROM $table WHERE transaction_row_id = %d AND order_id = %d FOR UPDATE",
				$source_id, $order_id
			) );
			$this->check_db();
			if ( null !== $prior ) throw new InvalidArgumentException( 'This source/order already has an exception review case.' );
			$context = $this->context( $source_id, $order_id );
			$policy = $context['policy'];
			if ( 0 === $policy['credit_unallocated_after_minor'] ) {
				throw new InvalidArgumentException( 'A source with no unallocated amount has no outstanding credit exception.' );
			}
			$code = $policy['overpayment_minor'] > 0 ? 'overpayment' : 'unallocated_credit_remainder';
			$insert = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (transaction_row_id, bank_account_id, source_fingerprint, order_id,
				 credit_minor, expected_minor, credit_unallocated_minor, order_outstanding_minor,
				 overpayment_minor, currency, exponent, exception_code, status, request_key,
				 created_by, reason, created_at)
				 VALUES (%d, %d, %s, %d, %d, %d, %d, %d, %d, %s, %d, %s, 'open', %s, %d, %s, UTC_TIMESTAMP())",
				$source_id, $context['account_id'], $context['fingerprint'], $order_id,
				$context['credit'], $context['expected'], $policy['credit_unallocated_after_minor'],
				$policy['order_outstanding_after_minor'], $policy['overpayment_minor'],
				$context['currency'], $context['exponent'], $code, $request_key, $actor_id, trim( $reason )
			) );
			if ( 1 !== $insert ) throw new RuntimeException( 'Could not persist bank credit exception.' );
			$id = (int) $this->db->insert_id;
			$this->event( $id, $actor_id, 'raised', [
				'code' => $code, 'source_id' => $source_id, 'order_id' => $order_id,
				'bank_unallocated_minor' => $policy['credit_unallocated_after_minor'],
				'overpayment_minor' => $policy['overpayment_minor'],
			] );
			return [
				'exception_id' => $id, 'status' => 'open',
				'credit_unallocated_minor' => $policy['credit_unallocated_after_minor'],
				'order_payment_changed' => false,
			];
		} );
	}

	/**
	 * Independent review is an audit annotation, NOT a financial resolution.
	 * Decisions describe a follow-up action still needed; money remains held.
	 */
	public function review( int $exception_id, int $reviewer_id,
		string $decision, string $reason ): array {
		$this->actor( $reviewer_id, $reason );
		if ( $exception_id < 1 || ! in_array( $decision,
			[ 'continue_hold', 'bank_return_requested', 'reallocate_pending_review' ], true ) ) {
			throw new InvalidArgumentException( 'Unsupported credit exception review decision.' );
		}
		$this->install();
		$table = $this->table();
		$prefetch = $this->db->get_row( $this->db->prepare(
			"SELECT transaction_row_id, order_id FROM $table WHERE id = %d", $exception_id
		), ARRAY_A );
		if ( ! $prefetch || '' !== $this->db->last_error ) {
			throw new InvalidArgumentException( 'Credit exception was not found.' );
		}
		$source_id = (int) $prefetch['transaction_row_id'];
		$order_id = (int) $prefetch['order_id'];
		return $this->locked( $source_id, $order_id, function () use (
			$exception_id, $source_id, $order_id, $reviewer_id, $decision, $reason
		): array {
			$table = $this->table();
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM $table WHERE id = %d FOR UPDATE", $exception_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $row ) || (int) $row['transaction_row_id'] !== $source_id ||
				(int) $row['order_id'] !== $order_id ) {
				throw new InvalidArgumentException( 'Credit exception changed during review.' );
			}
			if ( 'reviewed' === $row['status'] && (int) $row['reviewed_by'] === $reviewer_id &&
				$row['review_decision'] === $decision ) {
				return [ 'exception_id' => $exception_id, 'status' => 'already_reviewed', 'order_payment_changed' => false ];
			}
			if ( 'open' !== $row['status'] || $reviewer_id === (int) $row['created_by'] ) {
				throw new InvalidArgumentException( 'An independent reviewer is required for an open credit exception.' );
			}
			$context = $this->context( $source_id, $order_id );
			$policy = $context['policy'];
			if ( $context['fingerprint'] !== $row['source_fingerprint'] ||
				$context['account_id'] !== (int) $row['bank_account_id'] ||
				$context['expected'] !== (int) $row['expected_minor'] ||
				$context['credit'] !== (int) $row['credit_minor'] ||
				$policy['credit_unallocated_after_minor'] !== (int) $row['credit_unallocated_minor'] ||
				$policy['order_outstanding_after_minor'] !== (int) $row['order_outstanding_minor'] ||
				$policy['overpayment_minor'] !== (int) $row['overpayment_minor'] ) {
				throw new InvalidArgumentException( 'Exception source or order balance changed; fresh investigation is required.' );
			}
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE $table SET status = 'reviewed', reviewed_by = %d,
				 review_decision = %s, reviewed_at = UTC_TIMESTAMP(), version = version + 1
				 WHERE id = %d AND status = 'open' AND created_by <> %d",
				$reviewer_id, $decision, $exception_id, $reviewer_id
			) );
			if ( 1 !== $updated ) throw new RuntimeException( 'Credit exception approval was modified.' );
			$this->event( $exception_id, $reviewer_id, 'reviewed', [
				'decision' => $decision, 'reason' => trim( $reason ), 'money_moved' => false,
			] );
			return [ 'exception_id' => $exception_id, 'status' => 'reviewed', 'order_payment_changed' => false ];
		} );
	}

	/** @return array{policy:array<string,mixed>,account_id:int,fingerprint:string,credit:int,expected:int,currency:string,exponent:int} */
	private function context( int $source_id, int $order_id ): array {
		$rows = $this->db->prefix . 'wbm_pro_import_rows';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$source = $this->db->get_row( $this->db->prepare(
			"SELECT r.canonical_json, r.error_code, r.warning_code, r.identity_fingerprint,
			 b.status AS batch_status, b.currency AS batch_currency, b.account_id,
			 a.status AS account_status, a.currency AS account_currency
			 FROM $rows r JOIN $batches b ON b.id = r.batch_id JOIN $accounts a ON a.id = b.account_id
			 WHERE r.id = %d FOR UPDATE", $source_id
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $source ) || 'completed' !== $source['batch_status'] ||
			'active' !== $source['account_status'] || '' !== $source['warning_code'] ||
			'' !== $source['error_code'] ||
			! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'A valid unique imported source is required.' );
		}
		$duplicates = (int) $this->db->get_var( $this->db->prepare(
			"SELECT COUNT(*) FROM $rows r JOIN $batches b ON b.id = r.batch_id
			 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
			 AND r.identity_fingerprint = %s",
			(int) $source['account_id'], $source['identity_fingerprint']
		) );
		$this->check_db();
		if ( 1 !== $duplicates ) throw new InvalidArgumentException( 'Duplicated imported credit requires investigation.' );
		$entry = json_decode( (string) $source['canonical_json'], true );
		if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) || $entry['amount_minor'] < 1 ||
			! is_int( $entry['exponent'] ?? null ) || $entry['exponent'] < 0 || $entry['exponent'] > 3 ||
			! is_string( $entry['currency'] ?? null ) || ! is_string( $entry['reference'] ?? null ) ||
			! is_string( $entry['posted_date'] ?? null ) || $entry['posted_date'] > gmdate( 'Y-m-d' ) ||
			$entry['currency'] !== $source['batch_currency'] || $entry['currency'] !== $source['account_currency'] ) {
			throw new InvalidArgumentException( 'Bank credit does not have complete valid monetary provenance.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || 'bacs' !== $order->get_payment_method() || $order->is_paid() ||
			! $order->has_status( [ 'pending', 'on-hold' ] ) ||
			$order->get_currency() !== $entry['currency'] ||
			wc_get_price_decimals() !== $entry['exponent'] ) {
			throw new InvalidArgumentException( 'Only an eligible unpaid BACS order may be associated.' );
		}
		$ref = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order_id ) );
		if ( ! $ref || ExactMatchFacts::normalize_reference( $ref->value ) !==
			ExactMatchFacts::normalize_reference( $entry['reference'] ) ) {
			throw new InvalidArgumentException( 'Exact unmodified order payment reference is required.' );
		}
		CrossEditionEvidenceGuard::assert_no_manual_overlap( $this->db, $order_id,
			$entry['posted_date'], $entry['amount_minor'], $entry['currency'],
			$entry['exponent'], $entry['reference'] );
		$expected = Money::from_decimal( (string) $order->get_total( 'edit' ), $entry['currency'], $entry['exponent'] )->minor_units;
		$ledger = ( new AllocationLedgerRepository( $this->db ) )->snapshot( $order_id,
			$source_id, $expected, $entry['amount_minor'], $entry['currency'],
			$entry['exponent'], (int) $source['account_id'] );
		$policy = OverpaymentReview::assess(
			$expected, $expected - $ledger['order_outstanding_minor'],
			$entry['amount_minor'], $entry['amount_minor'] - $ledger['credit_unallocated_minor'],
			0, $entry['currency'], $entry['exponent']
		);
		return [
			'policy' => $policy, 'account_id' => (int) $source['account_id'],
			'fingerprint' => $source['identity_fingerprint'],
			'credit' => $entry['amount_minor'], 'expected' => $expected,
			'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
		];
	}

	/** Serializes case review with existing Free/Pro allocation writers for source/order. */
	private function locked( int $source_id, int $order_id, callable $action ): array {
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
					throw new RuntimeException( 'Bank source or order is under active financial review.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Transactional exception audit is unavailable.' );
			}
			$in_transaction = true;
			$result = $action();
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Financial exception transaction could not commit.' );
			$in_transaction = false;
			return $result;
		} finally {
			if ( $in_transaction ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) {
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private function actor( int $actor_id, string $reason ): void {
		$reason = trim( $reason );
		if ( $actor_id < 1 || $actor_id !== get_current_user_id() ||
			! current_user_can( FreeCaps::APPROVE ) || ! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Only an authorized finance user may record a credit exception.' );
		}
		if ( strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'Finance exception reason is required.' );
		}
	}

	/** @param array<string,mixed> $payload */
	private function event( int $exception_id, int $actor_id, string $type, array $payload ): void {
		$json = wp_json_encode( $payload );
		$events = $this->db->prefix . 'wbm_pro_exception_events';
		if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
			"INSERT INTO $events (exception_id, actor_id, event_type, event_json, created_at)
			 VALUES (%d, %d, %s, %s, UTC_TIMESTAMP())", $exception_id, $actor_id, $type, $json
		) ) ) throw new RuntimeException( 'Could not record immutable bank exception audit.' );
	}

	private function table(): string { return $this->db->prefix . 'wbm_pro_exceptions'; }
	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Credit exception provenance storage failed.' );
	}
}
