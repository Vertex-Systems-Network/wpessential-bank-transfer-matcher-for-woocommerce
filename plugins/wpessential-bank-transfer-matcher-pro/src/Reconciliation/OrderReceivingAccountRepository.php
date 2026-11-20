<?php
namespace WPEssential\BankTransferMatcherPro\Reconciliation;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use wpdb;

/**
 * Immutable merchant confirmation of the expected receiving bank for a BACS order.
 * An imported source account is NEVER an inferred order-to-bank binding.
 */
final class OrderReceivingAccountRepository {
	private const VERSION = 'wpessential_wbm_pro_receiving_account_schema';

	public function __construct( private readonly wpdb $db ) {}

	public function install(): void {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [
			static function ( wpdb $db ): bool {
				$table = $db->prefix . 'wbm_pro_order_receiving_accounts';
				$events = $db->prefix . 'wbm_pro_order_bank_events';
				$collation = $db->get_charset_collate();
				return false !== $db->query( "CREATE TABLE IF NOT EXISTS $table (
					order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
					bank_account_id BIGINT UNSIGNED NOT NULL,
					currency CHAR(3) NOT NULL,
					confirmed_by BIGINT UNSIGNED NOT NULL,
					reason TEXT NOT NULL,
					confirmed_at DATETIME NOT NULL,
					KEY bank_account_id (bank_account_id, order_id)
				) ENGINE=InnoDB $collation" ) &&
				false !== $db->query( "CREATE TABLE IF NOT EXISTS $events (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					order_id BIGINT UNSIGNED NOT NULL,
					bank_account_id BIGINT UNSIGNED NOT NULL,
					actor_id BIGINT UNSIGNED NOT NULL,
					event_type VARCHAR(24) NOT NULL,
					reason TEXT NOT NULL,
					created_at DATETIME NOT NULL,
					KEY order_events (order_id, id)
				) ENGINE=InnoDB $collation" );
			},
		] );
	}

	/** Confirm once; changes require a future audited rebind workflow. */
	public function confirm( int $order_id, int $account_id, int $actor_id, string $reason ): array {
		$reason = trim( $reason );
		if ( min( $order_id, $account_id, $actor_id ) < 1 ||
			get_current_user_id() !== $actor_id ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ) {
			throw new RuntimeException( 'Receiving bank confirmation requires an authorized account manager.' );
		}
		if ( strlen( $reason ) < 20 || strlen( $reason ) > 500 ||
			str_contains( $reason, "\0" ) || 1 !== preg_match( '//u', $reason ) ) {
			throw new InvalidArgumentException( 'Record a clear receiving-bank confirmation reason.' );
		}
		$this->install();
		( new AllocationLedgerRepository( $this->db ) )->install();
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/wbm-allocation/' . $order_id );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Order is under concurrent reconciliation.' );
		}
		$active = false;
		try {
			if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Transactional bank binding required.' );
			$active = true;
			$order = wc_get_order( $order_id );
			if ( ! $order || 'bacs' !== $order->get_payment_method() || $order->is_paid() ||
				! $order->has_status( [ 'pending', 'on-hold' ] ) ) {
				throw new InvalidArgumentException( 'Only an unpaid BACS order may confirm its receiving bank.' );
			}
			$accounts = $this->db->prefix . 'wbm_pro_bank_accounts';
			$account = $this->db->get_row( $this->db->prepare(
				"SELECT id, currency, status FROM $accounts WHERE id = %d FOR UPDATE", $account_id
			), ARRAY_A );
			if ( ! is_array( $account ) || '' !== $this->db->last_error ||
				'active' !== $account['status'] || $account['currency'] !== $order->get_currency() ) {
				throw new InvalidArgumentException( 'Expected receiving bank must exist, be active, and match order currency.' );
			}
			$table = $this->db->prefix . 'wbm_pro_order_receiving_accounts';
			$bound = $this->db->get_row( $this->db->prepare(
				"SELECT bank_account_id FROM $table WHERE order_id = %d FOR UPDATE", $order_id
			), ARRAY_A );
			if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Receiving bank binding cannot be read.' );
			if ( $bound ) {
				if ( (int) $bound['bank_account_id'] !== $account_id ) {
					throw new InvalidArgumentException( 'Order has an immutable receiving bank; an audited rebind is required.' );
				}
				if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Binding replay commit failed.' );
				$active = false;
				return [ 'order_id' => $order_id, 'bank_account_id' => $account_id, 'status' => 'already_confirmed' ];
			}
			$pro = $this->db->prefix . 'wbm_pro_allocations';
			$used = (int) $this->db->get_var( $this->db->prepare(
				"SELECT COUNT(*) FROM $pro WHERE order_id = %d AND state <> 'reversed' FOR UPDATE", $order_id
			) );
			if ( '' !== $this->db->last_error || $used !== 0 ) {
				throw new InvalidArgumentException( 'Existing Pro allocations need reviewed bank binding before changes.' );
			}
			if ( 1 !== $this->db->query( $this->db->prepare(
				"INSERT INTO $table (order_id, bank_account_id, currency, confirmed_by, reason, confirmed_at)
				 VALUES (%d, %d, %s, %d, %s, UTC_TIMESTAMP())",
				$order_id, $account_id, $account['currency'], $actor_id, $reason
			) ) ) throw new RuntimeException( 'Receiving bank binding insert failed.' );
			$events = $this->db->prefix . 'wbm_pro_order_bank_events';
			if ( 1 !== $this->db->query( $this->db->prepare(
				"INSERT INTO $events (order_id, bank_account_id, actor_id, event_type, reason, created_at)
				 VALUES (%d, %d, %d, 'confirmed', %s, UTC_TIMESTAMP())",
				$order_id, $account_id, $actor_id, $reason
			) ) || false === $this->db->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Receiving bank confirmation audit failed.' );
			}
			$active = false;
			return [ 'order_id' => $order_id, 'bank_account_id' => $account_id, 'status' => 'confirmed' ];
		} finally {
			if ( $active ) $this->db->query( 'ROLLBACK' );
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** A missing binding returns 0; it must never default to the imported account. */
	public function expected_account_id( int $order_id ): int {
		if ( $order_id < 1 ) throw new InvalidArgumentException( 'Invalid bank-binding order.' );
		$table = $this->db->prefix . 'wbm_pro_order_receiving_accounts';
		$exists = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Receiving bank bindings are unavailable.' );
		if ( $exists !== $table ) return 0;
		$value = $this->db->get_var( $this->db->prepare(
			"SELECT bank_account_id FROM $table WHERE order_id = %d", $order_id
		) );
		if ( '' !== $this->db->last_error ) throw new RuntimeException( 'Receiving bank binding read failed.' );
		return null === $value ? 0 : (int) $value;
	}

	/** Every imported allocation must have a previously confirmed matching order bank. */
	public function assert_matches( int $order_id, int $source_account_id ): void {
		if ( $source_account_id < 1 || $this->expected_account_id( $order_id ) !== $source_account_id ) {
			throw new InvalidArgumentException( 'Order has no confirmed matching receiving bank account; finance review required.' );
		}
	}
}
