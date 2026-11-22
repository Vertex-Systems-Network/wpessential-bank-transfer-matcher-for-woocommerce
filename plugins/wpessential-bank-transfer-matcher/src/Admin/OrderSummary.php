<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use WC_Order;
use WeakMap;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Read-only order summary. All monetary calculations use integer minor units. */
final class OrderSummary {
	/** @var WeakMap<WC_Order, array{total: Money, allocated: int, outstanding: int, mismatch: bool}>|null */
	private readonly ?WeakMap $balances;

	public function __construct( private readonly wpdb $db, bool $reuse_balances = false ) {
		$this->balances = $reuse_balances ? new WeakMap() : null;
	}

	/** @return array{reference: ?string, claim_status: ?string, total: Money, allocated: int, outstanding: int, evidence_count: int, mismatch: bool} */
	public function get( WC_Order $order ): array {
		$balance = $this->balance( $order );
		$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order->get_id() ) );
		$claim_status = null;
		$evidence_count = 0;
		if ( $this->version( 'wpessential_wbm_claim_schema' ) ) {
			$claim_status = $this->db->get_var( $this->db->prepare( "SELECT status FROM `{$this->db->prefix}wbm_payment_claims` WHERE order_id = %d", $order->get_id() ) );
			$this->check_error();
		}
		if ( '1' === $this->version( 'wpessential_wbm_evidence_schema' ) ) {
			$evidence_count = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->prefix}wbm_bank_evidence` WHERE order_id = %d", $order->get_id() ) );
			$this->check_error();
		}
		return $balance + [
			'reference' => $reference?->value,
			'claim_status' => $claim_status,
			'evidence_count' => $evidence_count,
		];
	}

	/** @return array{total: Money, allocated: int, outstanding: int, mismatch: bool} */
	public function balance( WC_Order $order ): array {
		if ( isset( $this->balances[ $order ] ) ) {
			return $this->balances[ $order ];
		}
		$balance = $this->read_balance( $order );
		if ( null !== $this->balances ) {
			$this->balances[ $order ] = $balance;
		}
		return $balance;
	}

	/** @return array{total: Money, allocated: int, outstanding: int, mismatch: bool} */
	private function read_balance( WC_Order $order ): array {
		$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), wc_get_price_decimals() );
		if ( $total->minor_units < 0 ) {
			throw new OrderBalanceException( 'Order total requires review.' );
		}
		$allocated = 0;
		if ( '1' === $this->version( 'wpessential_wbm_allocation_schema' ) ) {
			$rows = $this->db->get_results( $this->db->prepare( "SELECT amount_minor, expected_minor, currency, exponent FROM `{$this->db->prefix}wbm_manual_allocations` WHERE order_id = %d", $order->get_id() ), ARRAY_A );
			$this->check_error();
			foreach ( $rows as $row ) {
				$amount = filter_var( $row['amount_minor'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
				$expected = filter_var( $row['expected_minor'], FILTER_VALIDATE_INT );
				if ( $row['currency'] !== $total->currency || (int) $row['exponent'] !== $total->exponent ||
					$expected !== $total->minor_units || false === $amount || $amount > PHP_INT_MAX - $allocated ) {
					throw new OrderBalanceException( 'Order allocation snapshot requires review.' );
				}
				$allocated += $amount;
			}
		}
		return [
			'total' => $total,
			'allocated' => $allocated,
			'outstanding' => $total->minor_units - $allocated,
			'mismatch' => $allocated > $total->minor_units || ( $order->is_paid() && $allocated !== $total->minor_units ),
		];
	}

	/** @param array{mismatch: bool, outstanding: int, allocated: int} $balance */
	public static function state( array $balance ): string {
		return match ( true ) {
			$balance['mismatch'] => 'exception',
			0 === $balance['outstanding'] => 'fully_allocated',
			$balance['allocated'] > 0 => 'partial',
			default => 'unallocated',
		};
	}

	private function version( string $name ): ?string {
		$value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $name ) );
		$this->check_error();
		return null === $value ? null : (string) $value;
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Order reconciliation summary is unavailable.' );
		}
	}
}
