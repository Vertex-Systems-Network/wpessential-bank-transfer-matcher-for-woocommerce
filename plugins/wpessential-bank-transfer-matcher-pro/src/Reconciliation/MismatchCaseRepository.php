<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Source-backed FINANCE REVIEW of a mismatched credit and order, never allocation.
 * Cases and their append-only decisions carry a hash of source/order financial facts.
 */
final class MismatchCaseRepository {
	private const VERSION = 'wpessential_wbm_pro_mismatch_case_schema';

	public function __construct( private readonly wpdb $db ) {}

	public function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_mismatch_cases';
				$events = $db->prefix . 'wbm_pro_mismatch_events';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					transaction_row_id BIGINT UNSIGNED NOT NULL,
					order_id BIGINT UNSIGNED NOT NULL,
					source_fingerprint CHAR(64) NOT NULL,
					source_bank_account_id BIGINT UNSIGNED NOT NULL,
					expected_bank_account_id BIGINT UNSIGNED NOT NULL,
					source_amount_minor BIGINT UNSIGNED NOT NULL,
					order_total_minor BIGINT UNSIGNED NOT NULL,
					order_outstanding_minor BIGINT UNSIGNED NOT NULL,
					source_currency CHAR(3) NOT NULL,
					order_currency CHAR(3) NOT NULL,
					source_exponent TINYINT UNSIGNED NOT NULL,
					order_exponent TINYINT UNSIGNED NOT NULL,
					reason_codes_json TEXT NOT NULL,
					facts_hash CHAR(64) NOT NULL,
					cleared_confirmed TINYINT(1) NOT NULL DEFAULT 0,
					status VARCHAR(16) NOT NULL,
					request_key CHAR(64) NOT NULL,
					created_by BIGINT UNSIGNED NOT NULL,
					reviewed_by BIGINT UNSIGNED NULL,
					review_decision VARCHAR(40) NULL,
					created_at DATETIME NOT NULL,
					reviewed_at DATETIME NULL,
					version BIGINT UNSIGNED NOT NULL DEFAULT 1,
					UNIQUE KEY source_order (transaction_row_id, order_id),
					UNIQUE KEY request_key (request_key),
					KEY review_queue (status, id)
				) ENGINE=InnoDB $collation" ) &&
				false !== $db->query( "CREATE TABLE IF NOT EXISTS $events (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					mismatch_case_id BIGINT UNSIGNED NOT NULL,
					actor_id BIGINT UNSIGNED NOT NULL,
					event_type VARCHAR(24) NOT NULL,
					event_json TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					KEY case_history (mismatch_case_id, id)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/**
	 * A true clearance flag is only the actor's explicit attestation, not a bank API
	 * verification or permission to mark WooCommerce paid.
	 * @return array{case_id:int,status:string,codes:list<string>,order_payment_changed:false}
	 */
	public function raise( int $source_id, int $order_id, int $actor_id,
		string $request_key, bool $cleared_confirmed, string $reason ): array {
		$this->actor( $actor_id, $reason );
		if ( min( $source_id, $order_id ) < 1 ||
			1 !== preg_match( '/\A[a-f0-9]{64}\z/', $request_key ) ) {
			throw new InvalidArgumentException( 'Bank and order identifiers with a stable request key are required.' );
		}
		$this->install();
		( new AllocationLedgerRepository( $this->db ) )->install();
		return $this->locked( $source_id, $order_id, function () use (
			$source_id, $order_id, $actor_id, $request_key, $cleared_confirmed, $reason
		): array {
			$facts = $this->facts( $source_id, $order_id, $cleared_confirmed );
			if ( [] === $facts['codes'] ) {
				throw new InvalidArgumentException( 'There is no mismatch to record; exact credit remains review-only.' );
			}
			$hash = $this->hash_facts( $facts );
			$table = $this->table();
			$existing = $this->db->get_row( $this->db->prepare(
				"SELECT id, transaction_row_id, order_id, created_by, status, facts_hash, reason_codes_json
				 FROM $table WHERE request_key = %s FOR UPDATE", $request_key
			), ARRAY_A );
			$this->check_db();
			if ( $existing ) {
				if ( $source_id !== (int) $existing['transaction_row_id'] ||
					$order_id !== (int) $existing['order_id'] || $actor_id !== (int) $existing['created_by'] ||
					$hash !== $existing['facts_hash'] ) {
					throw new InvalidArgumentException( 'Mismatch request was already used or source facts changed.' );
				}
				return [
					'case_id' => (int) $existing['id'], 'status' => 'already_' . $existing['status'],
					'codes' => json_decode( $existing['reason_codes_json'], true ),
					'order_payment_changed' => false,
				];
			}
			$prior = $this->db->get_var( $this->db->prepare(
				"SELECT id FROM $table WHERE transaction_row_id = %d AND order_id = %d FOR UPDATE",
				$source_id, $order_id
			) );
			$this->check_db();
			if ( null !== $prior ) throw new InvalidArgumentException( 'An unresolved mismatch review already exists for this source and order.' );
			$codes = wp_json_encode( $facts['codes'] );
			if ( ! is_string( $codes ) ) throw new RuntimeException( 'Could not encode mismatch reasons.' );
			$written = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (transaction_row_id, order_id, source_fingerprint,
				 source_bank_account_id, expected_bank_account_id, source_amount_minor,
				 order_total_minor, order_outstanding_minor, source_currency, order_currency,
				 source_exponent, order_exponent, reason_codes_json, facts_hash,
				 cleared_confirmed, status, request_key, created_by, created_at)
				 VALUES (%d, %d, %s, %d, %d, %d, %d, %d, %s, %s, %d, %d, %s, %s, %d, 'open', %s, %d, UTC_TIMESTAMP())",
				$source_id, $order_id, $facts['source_fingerprint'], $facts['source_bank_account_id'],
				$facts['expected_bank_account_id'], $facts['source_amount_minor'],
				$facts['order_total_minor'], $facts['order_outstanding_minor'],
				$facts['source_currency'], $facts['order_currency'],
				$facts['source_exponent'], $facts['order_exponent'],
				$codes, $hash, $cleared_confirmed ? 1 : 0, $request_key, $actor_id
			) );
			if ( 1 !== $written ) throw new RuntimeException( 'Could not persist mismatch source and order.' );
			$id = (int) $this->db->insert_id;
			$this->event( $id, $actor_id, 'raised', [
				'codes' => $facts['codes'], 'facts_hash' => $hash, 'reason' => trim( $reason ),
			] );
			return [ 'case_id' => $id, 'status' => 'open', 'codes' => $facts['codes'], 'order_payment_changed' => false ];
		} );
	}

	/** An annotation is not a match correction, allocation, or payment approval. */
	public function review( int $case_id, int $reviewer_id, string $decision, string $reason ): array {
		$this->actor( $reviewer_id, $reason );
		if ( $case_id < 1 || ! in_array( $decision,
			[ 'continue_hold', 'request_account_confirmation', 'request_source_evidence' ], true ) ) {
			throw new InvalidArgumentException( 'Unsupported audit-only mismatch decision.' );
		}
		$table = $this->table();
		$before = $this->db->get_row( $this->db->prepare(
			"SELECT transaction_row_id, order_id FROM $table WHERE id = %d", $case_id
		), ARRAY_A );
		if ( ! $before || '' !== $this->db->last_error ) throw new InvalidArgumentException( 'Mismatch case does not exist.' );
		$source_id = (int) $before['transaction_row_id'];
		$order_id = (int) $before['order_id'];
		return $this->locked( $source_id, $order_id, function () use (
			$case_id, $source_id, $order_id, $reviewer_id, $decision, $reason
		): array {
			$table = $this->table();
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM $table WHERE id = %d FOR UPDATE", $case_id
			), ARRAY_A );
			$this->check_db();
			if ( ! $row || $source_id !== (int) $row['transaction_row_id'] ||
				$order_id !== (int) $row['order_id'] ) {
				throw new InvalidArgumentException( 'Mismatch case changed during review.' );
			}
			$facts = $this->facts( $source_id, $order_id, 1 === (int) $row['cleared_confirmed'] );
			if ( $this->hash_facts( $facts ) !== $row['facts_hash'] ) {
				throw new InvalidArgumentException( 'Bank source, receiving account or order balance changed; new investigation required.' );
			}
			if ( 'reviewed' === $row['status'] && (int) $row['reviewed_by'] === $reviewer_id &&
				$row['review_decision'] === $decision ) {
				return [ 'case_id' => $case_id, 'status' => 'already_reviewed', 'order_payment_changed' => false ];
			}
			if ( 'open' !== $row['status'] || $reviewer_id === (int) $row['created_by'] ) {
				throw new InvalidArgumentException( 'Independent finance review is required for an open mismatch.' );
			}
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE $table SET status = 'reviewed', reviewed_by = %d, review_decision = %s,
				 reviewed_at = UTC_TIMESTAMP(), version = version + 1
				 WHERE id = %d AND status = 'open' AND created_by <> %d",
				$reviewer_id, $decision, $case_id, $reviewer_id
			) );
			if ( 1 !== $updated ) throw new RuntimeException( 'Mismatch review changed concurrently.' );
			$this->event( $case_id, $reviewer_id, 'reviewed', [
				'decision' => $decision, 'reason' => trim( $reason ), 'money_moved' => false,
			] );
			return [ 'case_id' => $case_id, 'status' => 'reviewed', 'order_payment_changed' => false ];
		} );
	}

	/** @return array<string,mixed> */
	private function facts( int $source_id, int $order_id, bool $clearance ): array {
		$import = $this->db->prefix . 'wbm_pro_import_rows';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$source = $this->db->get_row( $this->db->prepare(
			"SELECT r.canonical_json, r.error_code, r.warning_code, r.identity_fingerprint,
			 b.status AS batch_status, b.account_id, b.currency AS batch_currency,
			 a.currency AS account_currency, a.status AS account_status
			 FROM $import r JOIN $batches b ON b.id = r.batch_id
			 JOIN $accounts a ON a.id = b.account_id WHERE r.id = %d FOR UPDATE", $source_id
		), ARRAY_A );
		$this->check_db();
		if ( ! $source || 'completed' !== $source['batch_status'] || 'active' !== $source['account_status'] ||
			'' !== $source['error_code'] || ! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'Completed imported credit provenance is required for mismatch review.' );
		}
		$entry = json_decode( (string) $source['canonical_json'], true );
		if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) ||
			$entry['amount_minor'] < 1 || ! is_int( $entry['exponent'] ?? null ) ||
			$entry['exponent'] < 0 || $entry['exponent'] > 3 ||
			! is_string( $entry['currency'] ?? null ) ||
			! preg_match( '/\A[A-Z]{3}\z/', $entry['currency'] ) ||
			! is_string( $entry['reference'] ?? null ) || '' === trim( $entry['reference'] ) ||
			! is_string( $entry['posted_date'] ?? null ) ||
			! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $entry['posted_date'] ) ||
			$entry['posted_date'] > gmdate( 'Y-m-d' ) ) {
			throw new InvalidArgumentException( 'Malformed bank row cannot be used as monetary mismatch evidence.' );
		}
		$duplicates = (int) $this->db->get_var( $this->db->prepare(
			"SELECT COUNT(*) FROM $import r JOIN $batches b ON b.id = r.batch_id
			 WHERE b.account_id = %d AND b.status = 'completed' AND r.error_code = ''
			 AND r.identity_fingerprint = %s",
			(int) $source['account_id'], $source['identity_fingerprint']
		) );
		$this->check_db();
		$order = wc_get_order( $order_id );
		if ( ! $order ) throw new InvalidArgumentException( 'Order cannot be found for mismatch review.' );
		$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order_id ) );
		if ( ! $reference ) throw new InvalidArgumentException( 'Order has no immutable payment reference.' );
		$order_currency = (string) $order->get_currency();
		$exponent = wc_get_price_decimals();
		$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $order_currency, $exponent )->minor_units;
		$outstanding = $this->order_outstanding( $order_id, $total, $order_currency, $exponent );
		$expected_account = ( new OrderReceivingAccountRepository( $this->db ) )->expected_account_id( $order_id );
		$classification = MismatchReview::inspect( [
			'order_id' => $order_id, 'method' => (string) $order->get_payment_method(),
			'status' => (string) $order->get_status(), 'currency' => $order_currency,
			'exponent' => $exponent, 'outstanding_minor' => $outstanding,
			'reference' => $reference->value, 'expected_bank_account_id' => $expected_account,
		], [
			'source_id' => $source_id, 'bank_account_id' => (int) $source['account_id'],
			'currency' => $entry['currency'], 'exponent' => $entry['exponent'],
			'credit_minor' => $entry['amount_minor'], 'reference' => $entry['reference'],
			'unique' => $duplicates === 1 && '' === $source['warning_code'],
			'cleared_confirmed' => $clearance,
		] );
		$codes = $classification['codes'];
		if ( $entry['currency'] !== $source['batch_currency'] ||
			$entry['currency'] !== $source['account_currency'] ) {
			$codes[] = 'imported_account_currency_conflict';
		}
		return [
			'source_id' => $source_id, 'order_id' => $order_id,
			'source_fingerprint' => $source['identity_fingerprint'],
			'source_bank_account_id' => (int) $source['account_id'],
			'expected_bank_account_id' => $expected_account,
			'source_amount_minor' => $entry['amount_minor'],
			'order_total_minor' => $total, 'order_outstanding_minor' => $outstanding,
			'source_currency' => $entry['currency'], 'order_currency' => $order_currency,
			'source_exponent' => $entry['exponent'], 'order_exponent' => $exponent,
			'cleared_confirmed' => $clearance, 'codes' => $codes,
		];
	}

	private function order_outstanding( int $order_id, int $expected, string $currency, int $exponent ): int {
		$used = 0;
		$tables = [
			[ $this->db->prefix . 'wbm_manual_allocations', false ],
			[ $this->db->prefix . 'wbm_pro_allocations', true ],
		];
		foreach ( $tables as [ $table, $pro ] ) {
			$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->check_db();
			if ( $exists !== $table ) continue;
			$where = $pro ? "AND state IN ('approved', 'committed')" : '';
			$rows = $this->db->get_results( $this->db->prepare(
				"SELECT amount_minor, expected_minor, currency, exponent FROM $table
				 WHERE order_id = %d $where FOR UPDATE", $order_id
			), ARRAY_A );
			if ( ! is_array( $rows ) ) throw new RuntimeException( 'Existing order allocations cannot be checked.' );
			$this->check_db();
			foreach ( $rows as $row ) {
				$raw = (string) $row['amount_minor'];
				$max = (string) PHP_INT_MAX;
				if ( ! ctype_digit( $raw ) || strlen( $raw ) > strlen( $max ) ||
					( strlen( $raw ) === strlen( $max ) && strcmp( $raw, $max ) > 0 ) ||
					(string) $row['expected_minor'] !== (string) $expected ||
					$row['currency'] !== $currency || (int) $row['exponent'] !== $exponent ) {
					throw new InvalidArgumentException( 'Existing financial allocations have stale or invalid order snapshots.' );
				}
				$amount = (int) $raw;
				if ( $amount < 1 || $amount > $expected - $used ) {
					throw new InvalidArgumentException( 'Order allocation balances are inconsistent.' );
				}
				$used += $amount;
			}
		}
		return $expected - $used;
	}

	/** @param array<string,mixed> $facts */
	private function hash_facts( array $facts ): string {
		$json = wp_json_encode( $facts );
		if ( ! is_string( $json ) ) throw new RuntimeException( 'Could not hash financial mismatch evidence.' );
		return hash( 'sha256', $json );
	}

	private function actor( int $actor_id, string $reason ): void {
		$reason = trim( $reason );
		if ( $actor_id < 1 || $actor_id !== get_current_user_id() ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Mismatch review requires an authorized finance actor.' );
		}
		if ( strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'Mismatch investigation reason must be specific.' );
		}
	}

	private function event( int $case_id, int $actor_id, string $type, array $details ): void {
		$json = wp_json_encode( $details );
		$events = $this->db->prefix . 'wbm_pro_mismatch_events';
		if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
			"INSERT INTO $events (mismatch_case_id, actor_id, event_type, event_json, created_at)
			 VALUES (%d, %d, %s, %s, UTC_TIMESTAMP())",
			$case_id, $actor_id, $type, $json
		) ) ) throw new RuntimeException( 'Mismatch case audit append failed.' );
	}

	private function locked( int $source_id, int $order_id, callable $callback ): array {
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
					throw new RuntimeException( 'Bank source or order has an active reconciliation.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Mismatch cases require transactional storage.' );
			$active = true;
			$result = $callback();
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Mismatch case transaction failed.' );
			$active = false;
			return $result;
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Mismatch case source storage error.' );
	}
	private function table(): string { return $this->db->prefix . 'wbm_pro_mismatch_cases'; }
}
