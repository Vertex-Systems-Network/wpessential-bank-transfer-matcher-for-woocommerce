<?php

namespace WPEssential\BankTransferMatcher\Privacy;

defined( 'ABSPATH' ) || exit;

final class PersonalData {
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	public static function exporters( array $exporters ): array {
		$exporters['wbm-claims'] = array( 'exporter_friendly_name' => __( 'Bank transfer claims', 'wpessential-bank-transfer-matcher' ), 'callback' => array( self::class, 'export' ) );
		return $exporters;
	}

	public static function erasers( array $erasers ): array {
		$erasers['wbm-claims'] = array( 'eraser_friendly_name' => __( 'Bank transfer claims', 'wpessential-bank-transfer-matcher' ), 'callback' => array( self::class, 'erase' ) );
		return $erasers;
	}

	private static function orders( string $email, int $page ): array {
		if ( ! is_email( $email ) || ! in_array( (string) get_option( 'wpessential_wbm_claim_schema' ), array( '1', '2' ), true ) ) {
			return array();
		}
		return wc_get_orders( array( 'billing_email' => $email, 'limit' => 20, 'page' => max( 1, $page ), 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order' ) );
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$data = array();
		$orders = self::orders( $email, $page );
		$table = $wpdb->prefix . 'wbm_payment_claims';
		foreach ( $orders as $order ) {
			$claim = $wpdb->get_row( $wpdb->prepare( "SELECT status, transfer_date, amount_minor, currency, exponent, transfer_reference, sender_name, customer_note, created_at, attachment_mime FROM `$table` WHERE order_id = %d", $order->get_id() ), ARRAY_A );
			if ( ! $claim ) {
				continue;
			}
			$items = array();
			foreach ( $claim as $key => $value ) {
				$items[] = array( 'name' => ucwords( str_replace( '_', ' ', $key ) ), 'value' => (string) $value );
			}
			$data[] = array( 'group_id' => 'wbm-claims', 'group_label' => __( 'Bank transfer claims', 'wpessential-bank-transfer-matcher' ), 'item_id' => 'wbm-claim-' . $order->get_id(), 'data' => $items );
		}
		return array( 'data' => $data, 'done' => count( $orders ) < 20 );
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$orders = self::orders( $email, $page );
		$table = $wpdb->prefix . 'wbm_payment_claims';
		$removed = false;
		$retained = false;
		foreach ( $orders as $order ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM `$table` WHERE order_id = %d", $order->get_id() ) );
			if ( null === $exists ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE `$table` SET sender_name = '', customer_note = '', attachment_mime = NULL, attachment_bytes = NULL, attachment_expires = NULL WHERE order_id = %d", $order->get_id() ) );
			$removed = true;
			$retained = true;
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array( __( 'Financial claim details and audit records remain for reconciliation; review your store retention obligations.', 'wpessential-bank-transfer-matcher' ) ) : array(), 'done' => count( $orders ) < 20 );
	}
}
