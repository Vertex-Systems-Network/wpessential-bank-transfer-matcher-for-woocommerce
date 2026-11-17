<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Read-only order summary. All monetary calculations use integer minor units. */
final class OrderSummary {
	public function __construct( private readonly wpdb $db ) {}

	/** @return array{reference: ?string, claim_status: ?string, total: Money, allocated: int, outstanding: int, evidence_count: int, mismatch: bool} */
	public function get( WC_Order $order ): array {
		$total = Money::from_decimal( (string) $order->get_total( 'edit' ), $order->get_currency(), wc_get_price_decimals() );
		$reference = ( new ReferenceRepository( $this->db ) )->find( new EntityId( $order->get_id() ) );
		$claim_status = null;
		$evidence_count = 0;
		$allocated = 0;
		if ( $this->version( 'wpessential_wbm_claim_schema' ) ) {
			$claim_status = $this->db->get_var( $this->db->prepare( "SELECT status FROM `{$this->db->prefix}wbm_payment_claims` WHERE order_id = %d", $order->get_id() ) );
			$this->check_error();
		}
		if ( '1' === $this->version( 'wpessential_wbm_evidence_schema' ) ) {
			$evidence_count = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->db->prefix}wbm_bank_evidence` WHERE order_id = %d", $order->get_id() ) );
			$this->check_error();
		}
		if ( '1' === $this->version( 'wpessential_wbm_allocation_schema' ) ) {
			$rows = $this->db->get_results( $this->db->prepare( "SELECT amount_minor, expected_minor, currency, exponent FROM `{$this->db->prefix}wbm_manual_allocations` WHERE order_id = %d", $order->get_id() ), ARRAY_A );
			$this->check_error();
			foreach ( $rows as $row ) {
				if ( $row['currency'] !== $total->currency || (int) $row['exponent'] !== $total->exponent ||
					(int) $row['expected_minor'] !== $total->minor_units || (int) $row['amount_minor'] <= 0 ||
					(int) $row['amount_minor'] > PHP_INT_MAX - $allocated ) {
					throw new RuntimeException( 'Order allocation snapshot requires review.' );
				}
				$allocated += (int) $row['amount_minor'];
			}
		}
		return [
			'reference' => $reference?->value,
			'claim_status' => $claim_status,
			'total' => $total,
			'allocated' => $allocated,
			'outstanding' => $total->minor_units - $allocated,
			'evidence_count' => $evidence_count,
			'mismatch' => $allocated > $total->minor_units || ( $order->is_paid() && $allocated !== $total->minor_units ),
		];
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
