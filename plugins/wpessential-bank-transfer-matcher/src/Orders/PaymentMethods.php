<?php

namespace WPEssential\BankTransferMatcher\Orders;

use WC_Payment_Gateway;

final class PaymentMethods {
	public const BACS = 'bacs';

	/** @return list<string> */
	public static function enabled_ids(): array {
		$enabled = [];
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$enabled[] = (string) $id;
			}
		}
		return $enabled;
	}

	public static function bacs_instructions(): string {
		$gateway = WC()->payment_gateways()->payment_gateways()[ self::BACS ] ?? null;
		if ( ! $gateway instanceof WC_Payment_Gateway ) {
			return '';
		}

		$instructions = $gateway->get_option( 'instructions', '' );
		return is_string( $instructions ) ? $instructions : '';
	}
}
