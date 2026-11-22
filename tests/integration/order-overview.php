<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WPEssential\BankTransferMatcher\Admin\OrderPanel;
use WPEssential\BankTransferMatcher\Bootstrap\Plugin;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Claims\ClaimReviewRepository;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Evidence\AllocationRepository;
use WPEssential\BankTransferMatcher\Evidence\EvidenceRepository;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run against the disposable wbm_ci database.' );
}
$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'classic', 'hpos' ], true ) || OrderUtil::custom_orders_table_usage_is_enabled() !== ( 'hpos' === $mode ) ) {
	throw new RuntimeException( 'Overview test requires the requested storage mode.' );
}
global $wpdb;
wp_set_current_user( get_user_by( 'login', 'wbm_ci' )->ID );
$orders = [];
$make = static function ( string $currency, string $total, string $claim_amount ) use ( &$orders, $wpdb ): WC_Order {
	$order = wc_create_order( [ 'status' => 'on-hold' ] );
	$order->set_payment_method( 'bacs' );
	$order->set_currency( $currency );
	$order->set_total( $total );
	$order->save();
	( new ClaimRepository( $wpdb ) )->save( $order->get_id(), [
		'date' => gmdate( 'Y-m-d' ), 'amount' => Money::from_decimal( $claim_amount, $currency, wc_get_price_decimals() ),
		'reference' => 'OVERVIEW-' . $order->get_id(), 'sender' => 'Fixture sender', 'note' => 'Private customer note',
	] );
	$orders[] = $order;
	return $order;
};
$previous_get = $_GET;
try {
	$usd = $make( 'USD', '20.00', '5.00' );
	$eur = $make( 'EUR', '30.00', '30.00' );
	$evidence = new EvidenceRepository( $wpdb );
	$allocation = new AllocationRepository( $wpdb );
	foreach ( [ [ $usd, '5.00' ], [ $eur, '30.00' ] ] as [ $order, $amount ] ) {
		$bank = $evidence->record( $order->get_id(), get_current_user_id(), gmdate( 'Y-m-d' ), Money::from_decimal( $amount, $order->get_currency(), wc_get_price_decimals() ), 'OVERVIEW-BANK-' . $order->get_id(), 'Private bank note' );
		$allocation->allocate( $order->get_id(), $bank['id'], get_current_user_id(), Money::from_decimal( (string) $order->get_total(), $order->get_currency(), wc_get_price_decimals() ), Money::from_decimal( $amount, $order->get_currency(), wc_get_price_decimals() ), 'Private allocation reason' );
	}
	$reviews = new ClaimReviewRepository( $wpdb );
	$reviews->transition( $usd->get_id(), get_current_user_id(), 'under_review', 'Private reviewer comment', false );
	$reviews->transition( $eur->get_id(), get_current_user_id(), 'verified', 'Private verified comment', true );
	$_GET = [ 'page' => 'wpessential-bank-transfer-matcher' ];
	ob_start();
	Plugin::render_page();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'Claims awaiting review</h3><p>1</p>' ) ||
		! str_contains( $html, 'Claims verified in 30 days</h3><p>1</p>' ) ||
		! str_contains( $html, '15.00 USD' ) || ! str_contains( $html, '0.00 EUR' ) ||
		! str_contains( $html, 'Outstanding on this page' ) ) {
		preg_match( '/<section class="wbm-overview".*?<\/section>/s', $html, $overview_markup );
		throw new RuntimeException( 'Overview counts or currency-specific balance are incorrect: ' . wp_strip_all_tags( $overview_markup[0] ?? substr( $html, 0, 1000 ) ) );
	}
	$references = new ReferenceRepository( $wpdb );
	$reference = $references->reserve( new EntityId( $usd->get_id() ), PaymentReference::issue() );
	if ( null === $reference ) {
		throw new RuntimeException( 'Could not reserve the order search fixture reference.' );
	}
	$find_orders = static function ( string $search, string $status, array $filters = [] ): string {
		$_GET = [ 'page' => 'wpessential-bank-transfer-matcher', 'wbm_search' => $search, 'wbm_status' => $status ] + $filters;
		ob_start();
		try {
			Plugin::render_page();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	};
	foreach ( [ (string) $usd->get_id(), strtolower( $reference->value ) ] as $search ) {
		$match = $find_orders( $search, 'on-hold' );
		if ( ! str_contains( $match, '>' . '#' . $usd->get_order_number() . '</a>' ) ||
			str_contains( $match, '>' . '#' . $eur->get_order_number() . '</a>' ) ||
			! str_contains( $match, 'Clear filters' ) ||
			str_contains( $match, 'No bank transfer orders match these filters.' ) ) {
			throw new RuntimeException( 'Exact order search returned the wrong rows or lost its clear action.' );
		}
	}
	foreach ( [
		[ $reference->value, 'pending' ],
		[ PaymentReference::issue()->value, 'eligible' ],
		[ str_repeat( '9', 65 ), 'eligible' ],
	] as [ $search, $status ] ) {
		$miss = $find_orders( $search, $status );
		if ( ! str_contains( $miss, 'No bank transfer orders match these filters.' ) ||
			str_contains( $miss, '>' . '#' . $usd->get_order_number() . '</a>' ) ) {
			throw new RuntimeException( 'Unmatched or filtered lookup opened an unrelated order.' );
		}
	}
	$usd->set_date_created( ( new DateTimeImmutable( '2026-11-18 23:59:59', wp_timezone() ) )->getTimestamp() );
	$usd->save();
	$eur->set_date_created( ( new DateTimeImmutable( '2026-11-19 00:00:00', wp_timezone() ) )->getTimestamp() );
	$eur->save();
	foreach ( [
		[ '', [ 'wbm_from' => '2026-11-18', 'wbm_to' => '2026-11-18' ], $usd ],
		[ '', [ 'wbm_to' => '2026-11-18' ], $usd ],
		[ '', [ 'wbm_from' => '2026-11-19' ], $eur ],
		[ $reference->value, [ 'wbm_from' => '2026-11-18', 'wbm_to' => '2026-11-18' ], $usd ],
		[ $reference->value, [ 'wbm_from' => '2026-11-19' ], null ],
	] as [ $search, $dates, $expected ] ) {
		$filtered = $find_orders( $search, 'on-hold', $dates );
		foreach ( [ $usd, $eur ] as $candidate ) {
			if ( ( $candidate === $expected ) !== str_contains( $filtered, '>#' . $candidate->get_order_number() . '</a>' ) ) {
				throw new RuntimeException( 'Order creation dates did not scope search or the list.' );
			}
		}
	}
	foreach ( [
		[ 'wbm_from' => '2026-02-30' ],
		[ 'wbm_from' => '2026-11-19', 'wbm_to' => '2026-11-18' ],
	] as $invalid_dates ) {
		$invalid = $find_orders( '', 'eligible', $invalid_dates );
		if ( ! str_contains( $invalid, 'Enter a valid creation date range.' ) || str_contains( $invalid, 'wbm-table-scroll' ) ) {
			throw new RuntimeException( 'Invalid order dates fell back to the unfiltered list.' );
		}
	}
	echo "PASS $mode inclusive order creation dates, partial bounds and invalid date rejection\n";

	foreach ( [
		[ '', [ 'wbm_currency' => 'USD' ], $usd ],
		[ '', [ 'wbm_currency' => 'eur', 'wbm_from' => '2026-11-19' ], $eur ],
		[ '', [ 'wbm_currency' => 'USD', 'wbm_from' => '2026-11-19' ], null ],
		[ $reference->value, [ 'wbm_currency' => 'USD', 'wbm_to' => '2026-11-18' ], $usd ],
		[ $reference->value, [ 'wbm_currency' => 'EUR', 'wbm_to' => '2026-11-18' ], null ],
	] as [ $search, $filters, $expected ] ) {
		$filtered = $find_orders( $search, 'on-hold', $filters );
		foreach ( [ $usd, $eur ] as $candidate ) {
			if ( ( $candidate === $expected ) !== str_contains( $filtered, '>#' . $candidate->get_order_number() . '</a>' ) ) {
				throw new RuntimeException( 'Currency did not scope the WooCommerce list or exact search.' );
			}
		}
	}
	$invalid_currency = $find_orders( '', 'eligible', [ 'wbm_currency' => 'USD-EUR' ] );
	if ( ! str_contains( $invalid_currency, 'Select a supported order currency.' ) ||
		str_contains( $invalid_currency, 'wbm-table-scroll' ) ) {
		throw new RuntimeException( 'Unsupported currency fell back to an unfiltered list.' );
	}
	echo "PASS $mode currency filter combines with date, status and exact lookup\n";

	$eur->set_payment_method( 'cod' );
	$eur->save();
	if ( ! str_contains( $find_orders( (string) $eur->get_id(), 'eligible' ), 'No bank transfer orders match these filters.' ) ) {
		throw new RuntimeException( 'Order lookup returned an ineligible payment method.' );
	}
	echo "PASS $mode exact reference/order ID search, status, no results and gateway scoping\n";

	ob_start();
	OrderPanel::render( $usd );
	$panel = ob_get_clean();
	if ( ! str_contains( $panel, 'Recent reconciliation activity' ) ||
		! str_contains( $panel, 'Manual allocation approved' ) ||
		! str_contains( $panel, 'Claim moved to review' ) ||
		str_contains( $panel, 'Private reviewer comment' ) || str_contains( $panel, 'Private bank note' ) ) {
		throw new RuntimeException( 'Audit timeline is incomplete or leaks private notes.' );
	}
	echo "PASS $mode exact per-currency visible balance, claim KPIs and private-safe audit timeline\n";
	// Fill the first unfiltered page with newer orders to guard against post-pagination filtering.
	for ( $index = 0; $index < 20; ++$index ) {
		$unclaimed = wc_create_order( [ 'status' => 'on-hold' ] );
		if ( ! $unclaimed instanceof WC_Order ) {
			throw new RuntimeException( 'Could not create an unclaimed fixture order.' );
		}
		$orders[] = $unclaimed;
		$unclaimed->set_payment_method( 'bacs' );
		$unclaimed->set_currency( 'USD' );
		$unclaimed->set_total( '10.00' );
		$unclaimed->set_date_created( ( new DateTimeImmutable( '2026-11-20 12:00:00', wp_timezone() ) )->getTimestamp() );
		$unclaimed->save();
	}
	$usd_label = '>#' . $usd->get_order_number() . '</a>';
	$unclaimed_label = '>#' . $unclaimed->get_order_number() . '</a>';
	$under_review = [ 'wbm_claim' => 'under_review', 'wbm_currency' => 'USD', 'wbm_to' => '2026-11-20' ];
	if ( str_contains( $find_orders( '', 'on-hold' ), $usd_label ) ) {
		throw new RuntimeException( 'Pagination fixture did not move the reviewed claim beyond page one.' );
	}
	$filtered = $find_orders( '', 'on-hold', $under_review );
	if ( ! str_contains( $filtered, $usd_label ) || str_contains( $filtered, $unclaimed_label ) ) {
		throw new RuntimeException( 'Claim status did not apply before order pagination.' );
	}
	if ( ! str_contains( $find_orders( (string) $unclaimed->get_id(), 'on-hold', [ 'wbm_claim' => 'none' ] ), $unclaimed_label ) ||
		str_contains( $find_orders( (string) $unclaimed->get_id(), 'on-hold', $under_review ), $unclaimed_label ) ||
		! str_contains( $find_orders( $reference->value, 'on-hold', $under_review ), $usd_label ) ) {
		throw new RuntimeException( 'Exact lookup did not respect the claim filter.' );
	}
	$reviews->transition( $usd->get_id(), get_current_user_id(), 'rejected', 'Synthetic claim status change', false );
	if ( str_contains( $find_orders( '', 'on-hold', $under_review ), $usd_label ) ||
		! str_contains( $find_orders( '', 'on-hold', [ 'wbm_claim' => 'rejected' ] ), $usd_label ) ) {
		throw new RuntimeException( 'Claim filter remained stale after a claim transition.' );
	}
	$invalid_claim = $find_orders( '', 'on-hold', [ 'wbm_claim' => 'invalid' ] );
	if ( ! str_contains( $invalid_claim, 'Select a supported claim status.' ) || str_contains( $invalid_claim, 'wbm-table-scroll' ) ) {
		throw new RuntimeException( 'Invalid claim filter showed unfiltered orders.' );
	}
	echo "PASS $mode claim-state filter before pagination, combined filters, no claim and live transitions\n";

} finally {
	$_GET = $previous_get;
	foreach ( $orders as $order ) {
		foreach ( [ 'wbm_payment_references', 'wbm_claim_review_events', 'wbm_manual_allocations', 'wbm_bank_evidence', 'wbm_payment_claims' ] as $suffix ) {
			$wpdb->delete( $wpdb->prefix . $suffix, [ 'order_id' => $order->get_id() ], [ '%d' ] );
		}
		$order->delete( true );
	}
}
