<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Scoped Free KPIs; the outstanding sum is explicitly limited to the visible page. */
final class Overview {
	public function __construct( private readonly wpdb $db ) {}

	/** @param list<\WC_Order> $orders */
	public function render( int $eligible_count, array $orders ): void {
		$claims = 0;
		$verified = 0;
		if ( $this->version( 'wpessential_wbm_claim_schema' ) ) {
			$claims = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->db->prefix}wbm_payment_claims` WHERE status IN ('submitted', 'under_review')" );
			$this->check_error();
		}
		if ( $this->version( 'wpessential_wbm_claim_review_schema' ) ) {
			$verified = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->db->prefix}wbm_claim_review_events` WHERE status = 'verified' AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY" );
			$this->check_error();
		}
		$currencies = [];
		$incomplete = false;
		$summary = new OrderSummary( $this->db );
		foreach ( $orders as $order ) {
			try {
				$data = $summary->get( $order );
				$money = $data['total'];
				$key = $money->currency . ':' . $money->exponent;
				$previous = $currencies[ $key ]['minor'] ?? 0;
				if ( $data['outstanding'] < 0 || $data['outstanding'] > PHP_INT_MAX - $previous ) {
					throw new RuntimeException( 'Invalid outstanding balance.' );
				}
				$currencies[ $key ] = [ 'minor' => $previous + $data['outstanding'], 'currency' => $money->currency, 'exponent' => $money->exponent ];
			} catch ( \InvalidArgumentException|\OverflowException|RuntimeException $error ) {
				$incomplete = true;
			}
		}
		ksort( $currencies );
		echo '<section class="wbm-overview" aria-labelledby="wbm-overview-title"><h2 id="wbm-overview-title">' . esc_html__( 'Overview', 'wpessential-bank-transfer-matcher' ) . '</h2><div class="wbm-kpis">';
		self::card( __( 'Eligible BACS orders', 'wpessential-bank-transfer-matcher' ), (string) $eligible_count );
		self::card( __( 'Claims awaiting review', 'wpessential-bank-transfer-matcher' ), (string) $claims );
		self::card( __( 'Claims verified in 30 days', 'wpessential-bank-transfer-matcher' ), (string) $verified );
		$balance = [];
		foreach ( $currencies as $entry ) {
			$balance[] = OrdersPage::money( $entry['minor'], $entry['currency'], $entry['exponent'] );
		}
		self::card( __( 'Outstanding on this page', 'wpessential-bank-transfer-matcher' ), $balance ? implode( ' · ', $balance ) : '—' );
		echo '</div>';
		if ( $incomplete ) {
			echo '<p role="alert">' . esc_html__( 'One or more order balances need review; the page total excludes them.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		}
		echo '</section>';
	}

	private static function card( string $label, string $value ): void {
		printf( '<div class="wbm-kpi"><h3>%s</h3><p>%s</p></div>', esc_html( $label ), esc_html( $value ) );
	}

	private function version( string $name ): ?string {
		$value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $name ) );
		$this->check_error();
		return null === $value ? null : (string) $value;
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Overview counters are unavailable.' );
		}
	}
}
