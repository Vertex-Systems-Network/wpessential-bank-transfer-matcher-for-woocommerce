<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Immutable, source-backed correction and reversal INVESTIGATION records.
 *
 * No allocation state changes, refunds, bank returns, WC order mutations or
 * payment_complete calls. A reviewed investigation remains a financial hold.
 */
final class CorrectionCaseRepository {
	private const VERSION = 'wpessential_wbm_pro_correction_case_schema';

	public function __construct( private readonly wpdb $db ) {}

	public function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_correction_cases';
				$events = $db->prefix . 'wbm_pro_correction_events';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					allocation_id BIGINT UNSIGNED NOT NULL,
					order_id BIGINT UNSIGNED NOT NULL,
					transaction_row_id BIGINT UNSIGNED NOT NULL,
					bank_account_id BIGINT UNSIGNED NOT NULL,
					source_fingerprint CHAR(64) NOT NULL,
					amount_minor BIGINT UNSIGNED NOT NULL,
					credit_minor BIGINT UNSIGNED NOT NULL,
					expected_minor BIGINT UNSIGNED NOT NULL,
					currency CHAR(3) NOT NULL,
					exponent TINYINT UNSIGNED NOT NULL,
					allocation_state VARCHAR(16) NOT NULL,
					facts_sha256 CHAR(64) NOT NULL,
					case_type VARCHAR(40) NOT NULL,
					status VARCHAR(12) NOT NULL,
					request_key CHAR(64) NOT NULL,
					created_by BIGINT UNSIGNED NOT NULL,
					reviewed_by BIGINT UNSIGNED NULL,
					review_decision VARCHAR(40) NULL,
					reason TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					reviewed_at DATETIME NULL,
					version BIGINT UNSIGNED NOT NULL DEFAULT 1,
					UNIQUE KEY allocation_id (allocation_id),
					UNIQUE KEY request_key (request_key),
					KEY order_hold (order_id, status, id),
					KEY source_hold (transaction_row_id, status, id)
				) ENGINE=InnoDB $collation" ) &&
				false !== $db->query( "CREATE TABLE IF NOT EXISTS $events (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					correction_case_id BIGINT UNSIGNED NOT NULL,
					actor_id BIGINT UNSIGNED NOT NULL,
					event_type VARCHAR(24) NOT NULL,
					event_json TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					KEY correction_history (correction_case_id, id)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/**
	 * One immutable case per allocation; no financial mutation.
	 * @return array{case_id:int,status:string,allocation_id:int,order_payment_changed:false,ledger_changed:false}
	 */
	public function raise( int $allocation_id, int $actor_id, string $request_key,
		string $case_type, string $reason ): array {
		$this->actor( $actor_id, $reason );
		if ( $allocation_id < 1 || ! self::valid_key( $request_key ) ||
			! in_array( $case_type, [
				'bank_reversal_notice', 'duplicate_credit', 'wrong_order',
				'amount_dispute', 'refund_investigation', 'source_correction',
			], true ) ) {
			throw new InvalidArgumentException( 'An approved allocation, case kind and stable request key are required.' );
		}
		( new AllocationLedgerRepository( $this->db ) )->install();
		$this->install();
		$locator = $this->locate( $allocation_id );
		return $this->locked( $locator['order_id'], $locator['source_id'],
			function () use ( $locator, $allocation_id, $actor_id, $request_key, $case_type, $reason ): array {
				$facts = $this->facts( $allocation_id, $locator['order_id'], $locator['source_id'] );
				$hash = self::digest( $facts );
				$table = $this->case_table();
				$replay = $this->db->get_row( $this->db->prepare(
					"SELECT * FROM $table WHERE request_key = %s FOR UPDATE", $request_key
				), ARRAY_A );
				$this->check_db();
				if ( $replay ) {
					if ( (int) $replay['allocation_id'] !== $allocation_id ||
						(int) $replay['created_by'] !== $actor_id ||
						$replay['case_type'] !== $case_type ||
						$replay['facts_sha256'] !== $hash ) {
						throw new InvalidArgumentException( 'Correction request key cannot be reused with different facts.' );
					}
					return self::result( (int) $replay['id'], $allocation_id, 'already_' . $replay['status'] );
				}
				$prior = $this->db->get_var( $this->db->prepare(
					"SELECT id FROM $table WHERE allocation_id = %d FOR UPDATE", $allocation_id
				) );
				$this->check_db();
				if ( null !== $prior ) {
					throw new InvalidArgumentException( 'This allocation already has a correction/reversal investigation.' );
				}
				if ( 1 !== $this->db->query( $this->db->prepare(
					"INSERT INTO $table (allocation_id, order_id, transaction_row_id, bank_account_id,
					 source_fingerprint, amount_minor, credit_minor, expected_minor, currency, exponent,
					 allocation_state, facts_sha256, case_type, status, request_key, created_by,
					 reason, created_at)
					 VALUES (%d, %d, %d, %d, %s, %d, %d, %d, %s, %d, %s, %s, %s, 'open', %s, %d, %s, UTC_TIMESTAMP())",
					$allocation_id, $facts['order_id'], $facts['transaction_row_id'],
					$facts['bank_account_id'], $facts['source_fingerprint'], $facts['amount_minor'],
					$facts['credit_minor'], $facts['expected_minor'], $facts['currency'],
					$facts['exponent'], $facts['allocation_state'], $hash,
					$case_type, $request_key, $actor_id, trim( $reason )
				) ) ) {
					throw new RuntimeException( 'Could not persist correction/reversal hold.' );
				}
				$id = (int) $this->db->insert_id;
				$this->event( $id, $actor_id, 'raised', [
					'case_type' => $case_type, 'facts_sha256' => $hash,
					'order_id' => $facts['order_id'], 'source_id' => $facts['transaction_row_id'],
					'amount_minor' => $facts['amount_minor'], 'state' => $facts['allocation_state'],
					'reason' => trim( $reason ), 'money_moved' => false,
				] );
				return self::result( $id, $allocation_id, 'open' );
			}
		);
	}

	/** Review outcome is only an append-only investigation annotation. */
	public function review( int $case_id, int $reviewer_id, string $decision, string $reason ): array {
		$this->actor( $reviewer_id, $reason );
		if ( $case_id < 1 || ! in_array( $decision, [
			'continue_hold', 'request_bank_restatement',
			'request_external_refund_review', 'reject_unsupported_claim',
		], true ) ) {
			throw new InvalidArgumentException( 'Only non-financial correction review decisions are supported.' );
		}
		$table = $this->case_table();
		$prefetch = $this->db->get_row( $this->db->prepare(
			"SELECT allocation_id, order_id, transaction_row_id FROM $table WHERE id = %d", $case_id
		), ARRAY_A );
		if ( ! $prefetch || '' !== $this->db->last_error ) {
			throw new InvalidArgumentException( 'Correction case does not exist.' );
		}
		$order_id = (int) $prefetch['order_id'];
		$source_id = (int) $prefetch['transaction_row_id'];
		$allocation_id = (int) $prefetch['allocation_id'];
		return $this->locked( $order_id, $source_id, function () use (
			$table, $case_id, $order_id, $source_id, $allocation_id, $reviewer_id, $decision, $reason
		): array {
			$row = $this->db->get_row( $this->db->prepare(
				"SELECT * FROM $table WHERE id = %d FOR UPDATE", $case_id
			), ARRAY_A );
			$this->check_db();
			if ( ! $row || $order_id !== (int) $row['order_id'] ||
				$source_id !== (int) $row['transaction_row_id'] ||
				$allocation_id !== (int) $row['allocation_id'] ) {
				throw new InvalidArgumentException( 'Correction source changed during review.' );
			}
			$facts = $this->facts( $allocation_id, $order_id, $source_id );
			if ( self::digest( $facts ) !== $row['facts_sha256'] ) {
				throw new InvalidArgumentException( 'Bank source, payment or ledger changed; investigate with a fresh snapshot.' );
			}
			if ( 'reviewed' === $row['status'] && (int) $row['reviewed_by'] === $reviewer_id &&
				$row['review_decision'] === $decision ) {
				return self::result( $case_id, $allocation_id, 'already_reviewed' );
			}
			if ( 'open' !== $row['status'] || (int) $row['created_by'] === $reviewer_id ) {
				throw new InvalidArgumentException( 'A distinct finance reviewer must inspect an open correction.' );
			}
			if ( 1 !== $this->db->query( $this->db->prepare(
				"UPDATE $table SET status = 'reviewed', reviewed_by = %d,
				 review_decision = %s, reviewed_at = UTC_TIMESTAMP(),
				 version = version + 1 WHERE id = %d AND status = 'open' AND created_by <> %d",
				$reviewer_id, $decision, $case_id, $reviewer_id
			) ) ) {
				throw new RuntimeException( 'Correction review changed concurrently.' );
			}
			$this->event( $case_id, $reviewer_id, 'reviewed', [
				'decision' => $decision, 'reason' => trim( $reason ),
				'money_moved' => false, 'hold_released' => false,
			] );
			return self::result( $case_id, $allocation_id, 'reviewed' );
		} );
	}

	/** @return array{order_id:int,source_id:int} */
	private function locate( int $allocation_id ): array {
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$row = $this->db->get_row( $this->db->prepare(
			"SELECT order_id, transaction_row_id FROM $table WHERE id = %d", $allocation_id
		), ARRAY_A );
		if ( ! $row || '' !== $this->db->last_error ) {
			throw new InvalidArgumentException( 'Correction requires an existing Pro allocation.' );
		}
		$order_id = self::positive( $row['order_id'] );
		$source_id = self::positive( $row['transaction_row_id'] );
		return [ 'order_id' => $order_id, 'source_id' => $source_id ];
	}

	/** Current, exact source, ledger and WooCommerce snapshot, sampled under locks. */
	private function facts( int $allocation_id, int $order_id, int $source_id ): array {
		$table = $this->db->prefix . 'wbm_pro_allocations';
		$alloc = $this->db->get_row( $this->db->prepare(
			"SELECT * FROM $table WHERE id = %d FOR UPDATE", $allocation_id
		), ARRAY_A );
		$this->check_db();
		if ( ! $alloc || (int) $alloc['order_id'] !== $order_id ||
			(int) $alloc['transaction_row_id'] !== $source_id ||
			! in_array( $alloc['state'], [ 'approved', 'committed' ], true ) ||
			(int) $alloc['created_by'] < 1 || (int) $alloc['approved_by'] < 1 ||
			(int) $alloc['created_by'] === (int) $alloc['approved_by'] ) {
			throw new InvalidArgumentException( 'Correction requires a source-backed independently approved allocation.' );
		}
		foreach ( [ 'amount_minor', 'credit_minor', 'expected_minor', 'bank_account_id' ] as $field ) {
			self::positive( $alloc[$field] );
		}
		$currency = (string) $alloc['currency'];
		$exponent = self::unsigned( $alloc['exponent'] );
		if ( ! preg_match( '/\A[A-Z]{3}\z/', $currency ) || $exponent > 3 ||
			! preg_match( '/\A[a-f0-9]{64}\z/', (string) $alloc['source_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'Allocation currency or source identity is malformed.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_currency() !== $currency ||
			$exponent !== wc_get_price_decimals() ) {
			throw new InvalidArgumentException( 'Order currency or precision no longer supports review.' );
		}
		$current_total = Money::from_decimal( (string) $order->get_total( 'edit' ),
			$currency, $exponent )->minor_units;
		$rows = $this->db->prefix . 'wbm_pro_import_rows';
		$batches = $this->db->prefix . 'wbm_pro_import_batches';
		$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
		$source = $this->db->get_row( $this->db->prepare(
			"SELECT r.canonical_json, r.error_code, r.warning_code, r.identity_fingerprint,
			 b.account_id, b.status AS batch_status, b.currency AS batch_currency,
			 a.status AS account_status, a.currency AS account_currency
			 FROM $rows r JOIN $batches b ON b.id = r.batch_id
			 JOIN $accounts a ON a.id = b.account_id WHERE r.id = %d FOR UPDATE",
			$source_id
		), ARRAY_A );
		$this->check_db();
		if ( ! $source || ! is_string( $source['canonical_json'] ) ||
			! preg_match( '/\A[a-f0-9]{64}\z/', (string) $source['identity_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'Imported transaction provenance is missing.' );
		}
		$entry = json_decode( $source['canonical_json'], true );
		if ( ! is_array( $entry ) || ! is_int( $entry['amount_minor'] ?? null ) ||
			$entry['amount_minor'] < 1 || ! is_string( $entry['currency'] ?? null ) ||
			! is_int( $entry['exponent'] ?? null ) || ! is_string( $entry['reference'] ?? null ) ||
			! is_string( $entry['posted_date'] ?? null ) ) {
			throw new InvalidArgumentException( 'Source transaction is not valid for a correction review.' );
		}
		// Changes and warnings may be the reason for investigating the credit;
		// capture them as immutable evidence rather than claiming it is clean.
		// Snapshot the WHOLE affected order and the shared imported source,
		// not just the disputed allocation. A different credit, split
		// recipient or Free manual allocation changing later invalidates review.
		$related = $this->db->get_results( $this->db->prepare(
			"SELECT id, order_id, transaction_row_id, bank_account_id, amount_minor,
			 credit_minor, expected_minor, currency, exponent, state, created_by,
			 approved_by, source_fingerprint, split_group_key, version
			 FROM $table WHERE order_id = %d OR transaction_row_id = %d
			 ORDER BY id ASC FOR UPDATE", $order_id, $source_id
		), ARRAY_A );
		$this->check_db();
		if ( ! is_array( $related ) || [] === $related ) {
			throw new RuntimeException( 'Complete order/source ledger cannot be reviewed.' );
		}
		$manual = [];
		$manual_table = $this->db->prefix . 'wbm_manual_allocations';
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $manual_table ) );
		$this->check_db();
		if ( $exists === $manual_table ) {
			$manual = $this->db->get_results( $this->db->prepare(
				"SELECT id, evidence_id, amount_minor, expected_minor, currency, exponent
				 FROM $manual_table WHERE order_id = %d ORDER BY id ASC FOR UPDATE", $order_id
			), ARRAY_A );
			$this->check_db();
			if ( ! is_array( $manual ) ) {
				throw new RuntimeException( 'Free manual credit history cannot be reviewed.' );
			}
		}
		$ledger_json = wp_json_encode( [ 'pro' => $related, 'manual' => $manual ] );
		if ( ! is_string( $ledger_json ) ) {
			throw new RuntimeException( 'Could not fingerprint related approved allocations.' );
		}
		$payload = [
			'affected_ledger_sha256' => hash( 'sha256', $ledger_json ),
			'allocation_id' => $allocation_id,
			'order_id' => $order_id, 'transaction_row_id' => $source_id,
			'bank_account_id' => self::positive( $alloc['bank_account_id'] ),
			'source_fingerprint' => (string) $alloc['source_fingerprint'],
			'amount_minor' => self::positive( $alloc['amount_minor'] ),
			'credit_minor' => self::positive( $alloc['credit_minor'] ),
			'expected_minor' => self::positive( $alloc['expected_minor'] ),
			'currency' => $currency, 'exponent' => $exponent,
			'allocation_state' => $alloc['state'],
			'split_group_key' => $alloc['split_group_key'] ?? null,
			'created_by' => self::positive( $alloc['created_by'] ),
			'approved_by' => self::positive( $alloc['approved_by'] ),
			'order_total_minor_now' => $current_total, 'order_is_paid_now' => (bool) $order->is_paid(),
			'order_gateway_now' => (string) $order->get_payment_method(),
			'bank_canonical_sha256' => hash( 'sha256', $source['canonical_json'] ),
			'bank_identity_now' => (string) $source['identity_fingerprint'],
			'bank_account_now' => self::positive( $source['account_id'] ),
			'bank_status_now' => $source['account_status'],
			'batch_status_now' => $source['batch_status'],
			'bank_currency_now' => $source['account_currency'],
			'batch_currency_now' => $source['batch_currency'],
			'bank_error_now' => (string) $source['error_code'],
			'bank_warning_now' => (string) $source['warning_code'],
		];
		return $payload;
	}

	private function locked( int $order_id, int $source_id, callable $callback ): array {
		$pro = $this->db->prefix . 'wbm_pro_allocations';
		$recipients = $this->db->get_col( $this->db->prepare(
			"SELECT DISTINCT order_id FROM $pro WHERE transaction_row_id = %d", $source_id
		) );
		$this->check_db();
		if ( ! is_array( $recipients ) || [] === $recipients ) {
			throw new InvalidArgumentException( 'Correction source has no linked order recipients.' );
		}
		$recipient_set = array_map( static fn ( mixed $id ): int => self::positive( $id ), $recipients );
		sort( $recipient_set, SORT_NUMERIC );
		$locks = [ hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-pro-source/' . $source_id ) ];
		foreach ( $recipients as $recipient ) {
			$id = self::positive( $recipient );
			$locks[] = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $id );
		}
		$locks[] = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id );
		$locks = array_values( array_unique( $locks ) );
		sort( $locks, SORT_STRING );
		$held = [];
		$active = false;
		try {
			foreach ( $locks as $lock ) {
				if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
					throw new RuntimeException( 'Source or order is under concurrent financial reconciliation.' );
				}
				$held[] = $lock;
			}
			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Correction review requires transactional storage.' );
			}
			$active = true;
			// Preflight recipient collection precedes named locking. Confirm
			// the set has not changed while we acquired the locks; otherwise
			// a newly added split recipient would be left unprotected.
			$locked_recipients = $this->db->get_col( $this->db->prepare(
				"SELECT DISTINCT order_id FROM $pro WHERE transaction_row_id = %d FOR UPDATE", $source_id
			) );
			$this->check_db();
			if ( ! is_array( $locked_recipients ) ) {
				throw new RuntimeException( 'Could not lock correction recipient group.' );
			}
			$locked_set = array_map( static fn ( mixed $id ): int => self::positive( $id ), $locked_recipients );
			sort( $locked_set, SORT_NUMERIC );
			if ( $locked_set !== $recipient_set ) {
				throw new InvalidArgumentException( 'Split recipient set changed during correction review.' );
			}
			$result = $callback();
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Could not commit correction investigation.' );
			$active = false;
			return $result;
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			foreach ( array_reverse( $held ) as $lock ) {
				$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private function actor( int $actor_id, string $reason ): void {
		$reason = trim( $reason );
		if ( $actor_id < 1 || $actor_id !== get_current_user_id() ||
			! current_user_can( FreeCaps::APPROVE ) || ! current_user_can( ProCaps::IMPORT ) ) {
			throw new RuntimeException( 'Correction investigation requires an authorized finance actor.' );
		}
		if ( strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'An auditable correction investigation reason is required.' );
		}
	}

	private function event( int $case_id, int $actor_id, string $type, array $payload ): void {
		$json = wp_json_encode( $payload );
		$table = $this->db->prefix . 'wbm_pro_correction_events';
		if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare(
			"INSERT INTO $table (correction_case_id, actor_id, event_type, event_json, created_at)
			 VALUES (%d, %d, %s, %s, UTC_TIMESTAMP())",
			$case_id, $actor_id, $type, $json
		) ) ) throw new RuntimeException( 'Correction event append failed.' );
	}

	private static function result( int $case_id, int $allocation_id, string $status ): array {
		return [
			'case_id' => $case_id, 'allocation_id' => $allocation_id, 'status' => $status,
			'order_payment_changed' => false, 'ledger_changed' => false,
		];
	}

	private static function valid_key( string $key ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/', $key );
	}

	/** Fail closed rather than truncating unsigned MariaDB integer money. */
	private static function positive( mixed $value ): int {
		$number = self::unsigned( $value );
		if ( $number < 1 ) throw new InvalidArgumentException( 'Positive financial identifier or amount required.' );
		return $number;
	}
	private static function unsigned( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) return $value;
		if ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ||
			strlen( $value ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $value ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid unsigned financial row value.' );
		}
		return (int) $value;
	}
	private static function digest( array $facts ): string {
		$json = wp_json_encode( $facts );
		if ( ! is_string( $json ) ) throw new RuntimeException( 'Correction snapshot could not be fingerprinted.' );
		return hash( 'sha256', $json );
	}

	private function case_table(): string { return $this->db->prefix . 'wbm_pro_correction_cases'; }
	private function check_db(): void {
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Correction evidence cannot be loaded safely.' );
	}
}
