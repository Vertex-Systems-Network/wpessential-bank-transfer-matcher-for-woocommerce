<?php

namespace WPEssential\BankTransferMatcher\Admin;

use RuntimeException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/** Read-only order activity; never exposes private reviewer comments or bank bytes. */
final class AuditTimeline {
	public function __construct( private readonly wpdb $db ) {}

	/** @return list<array{kind: string, created_at: string, actor_id: int}> */
	public function recent( int $order_id ): array {
		$sources = [
			[ 'wpessential_wbm_claim_schema', 'wbm_payment_claims', "SELECT 'claim_submitted' AS kind, created_at, 0 AS actor_id FROM `%s` WHERE order_id = %%d" ],
			[ 'wpessential_wbm_evidence_schema', 'wbm_bank_evidence', "SELECT 'evidence_recorded' AS kind, created_at, actor_id FROM `%s` WHERE order_id = %%d" ],
			[ 'wpessential_wbm_allocation_schema', 'wbm_manual_allocations', "SELECT 'allocation_approved' AS kind, created_at, actor_id FROM `%s` WHERE order_id = %%d" ],
			[ 'wpessential_wbm_claim_review_schema', 'wbm_claim_review_events', "SELECT CONCAT('claim_', status) AS kind, created_at, actor_id FROM `%s` WHERE order_id = %%d" ],
			[ 'wpessential_wbm_finalization_schema', 'wbm_payment_finalizations', "SELECT 'payment_completed' AS kind, completed_at AS created_at, completed_by AS actor_id FROM `%s` WHERE order_id = %%d AND state = 'completed'" ],
		];
		$events = [];
		foreach ( $sources as [ $option, $suffix, $query ] ) {
			if ( ! $this->version( $option ) ) {
				continue;
			}
			$table = $this->db->prefix . $suffix;
			$sql = sprintf( $query, $table ) . ' ORDER BY created_at DESC LIMIT 10';
			$rows = $this->db->get_results( $this->db->prepare( $sql, $order_id ), ARRAY_A );
			$this->check_error();
			foreach ( $rows as $row ) {
				$events[] = [ 'kind' => $row['kind'], 'created_at' => $row['created_at'], 'actor_id' => (int) $row['actor_id'] ];
			}
		}
		usort( $events, static fn ( array $a, array $b ): int => strcmp( $b['created_at'], $a['created_at'] ) );
		return array_slice( $events, 0, 10 );
	}

	public function render( int $order_id ): void {
		$events = $this->recent( $order_id );
		echo '<section class="wbm-audit"><h4>' . esc_html__( 'Recent reconciliation activity', 'wpessential-bank-transfer-matcher' ) . '</h4>';
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No recorded activity yet.', 'wpessential-bank-transfer-matcher' ) . '</p></section>';
			return;
		}
		$labels = [
			'claim_submitted' => __( 'Customer claim submitted', 'wpessential-bank-transfer-matcher' ),
			'claim_under_review' => __( 'Claim moved to review', 'wpessential-bank-transfer-matcher' ),
			'claim_verified' => __( 'Customer claim verified', 'wpessential-bank-transfer-matcher' ),
			'claim_rejected' => __( 'Customer claim rejected', 'wpessential-bank-transfer-matcher' ),
			'evidence_recorded' => __( 'Manual bank evidence recorded', 'wpessential-bank-transfer-matcher' ),
			'allocation_approved' => __( 'Manual allocation approved', 'wpessential-bank-transfer-matcher' ),
			'payment_completed' => __( 'WooCommerce payment completed', 'wpessential-bank-transfer-matcher' ),
		];
		echo '<ol>';
		foreach ( $events as $event ) {
			$user = $event['actor_id'] ? get_userdata( $event['actor_id'] ) : false;
			$actor = $user ? $user->display_name : ( $event['actor_id'] ? sprintf( __( 'User #%d', 'wpessential-bank-transfer-matcher' ), $event['actor_id'] ) : __( 'Customer', 'wpessential-bank-transfer-matcher' ) );
			$time = strtotime( $event['created_at'] . ' UTC' );
			printf( '<li><strong>%s</strong> · %s · <time datetime="%s">%s</time></li>',
				esc_html( $labels[ $event['kind'] ] ?? $event['kind'] ), esc_html( $actor ),
				esc_attr( gmdate( 'c', $time ) ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) )
			);
		}
		echo '</ol></section>';
	}

	private function version( string $name ): ?string {
		$value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $name ) );
		$this->check_error();
		return null === $value ? null : (string) $value;
	}

	private function check_error(): void {
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Audit activity is unavailable.' );
		}
	}
}
