<?php

namespace WPEssential\BankTransferMatcher\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

defined( 'ABSPATH' ) || exit;

final class OrdersPage {
	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view bank transfer orders.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 403 ] );
		}
		$status = isset( $_GET['wbm_status'] ) && is_string( $_GET['wbm_status'] ) ? sanitize_key( wp_unslash( $_GET['wbm_status'] ) ) : 'eligible';
		if ( ! in_array( $status, [ 'eligible', 'on-hold', 'pending' ], true ) ) {
			$status = 'eligible';
		}
		$page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, min( 10000, absint( $_GET['paged'] ) ) ) : 1;
		$search = isset( $_GET['wbm_search'] ) && is_string( $_GET['wbm_search'] ) ? trim( wp_unslash( $_GET['wbm_search'] ) ) : '';
		$statuses = 'eligible' === $status ? [ 'on-hold', 'pending' ] : [ $status ];
		global $wpdb;
		$from = isset( $_GET['wbm_from'] ) && is_string( $_GET['wbm_from'] ) ? wp_unslash( $_GET['wbm_from'] ) : '';
		$to = isset( $_GET['wbm_to'] ) && is_string( $_GET['wbm_to'] ) ? wp_unslash( $_GET['wbm_to'] ) : '';
		$currencies = get_woocommerce_currencies();
		$currency_input = isset( $_GET['wbm_currency'] ) && is_string( $_GET['wbm_currency'] ) ? wp_unslash( $_GET['wbm_currency'] ) : '';
		$currency = strtoupper( trim( $currency_input ) );
		$valid_currency = '' === $currency || ( 1 === preg_match( '/\A[A-Z]{3}\z/', $currency ) && isset( $currencies[ $currency ] ) );
		$orders = [];
		$pages = 1;
		$error_message = '';
		$eligible_count = null;
		try {
			if ( ! $valid_currency ) {
				throw new InvalidArgumentException( 'Unsupported order currency.' );
			}
			$start = self::date_bound( $from, false );
			$end = self::date_bound( $to, true );
			if ( null !== $start && null !== $end && $start > $end ) {
				throw new InvalidArgumentException( 'Order date range is reversed.' );
			}
			if ( '' === $search ) {
				$query = [
					'payment_method' => 'bacs', 'status' => $statuses, 'limit' => 20,
					'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC',
				];
				if ( null !== $start && null !== $end ) {
					$query['date_created'] = "$start...$end";
				} elseif ( null !== $start ) {
					$query['date_created'] = ">=$start";
				} elseif ( null !== $end ) {
					$query['date_created'] = "<=$end";
				}
				if ( '' !== $currency ) {
					$query['currency'] = $currency;
				}
				$results = wc_get_orders( $query );
				if ( ! is_object( $results ) || ! isset( $results->orders, $results->max_num_pages ) ) {
					throw new RuntimeException( 'WooCommerce order query is unavailable.' );
				}
				$orders = $results->orders;
				$pages = (int) $results->max_num_pages;
				if ( 'eligible' === $status && ! isset( $query['date_created'] ) && '' === $currency ) {
					$eligible_count = (int) $results->total;
				}
			} else {
				$orders = self::lookup( $search, $statuses, new ReferenceRepository( $wpdb ), $start, $end, $currency );
			}
		} catch ( InvalidArgumentException $error ) {
			$error_message = $valid_currency
				? __( 'Enter a valid creation date range. The start date must come before or on the end date.', 'wpessential-bank-transfer-matcher' )
				: __( 'Select a supported order currency.', 'wpessential-bank-transfer-matcher' );
		} catch ( RuntimeException $error ) {
			$error_message = __( 'Order lookup is temporarily unavailable. Please try again.', 'wpessential-bank-transfer-matcher' );
		}
		echo '<div class="wrap wbm-admin">';
		Components::heading( __( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ) );
		printf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( [ 'page' => 'wpessential-bank-transfer-matcher', 'wbm_view' => 'settings' ], admin_url( 'admin.php' ) ) ), esc_html__( 'Settings', 'wpessential-bank-transfer-matcher' ) );
		printf( '<p>%s</p>', esc_html__( 'Unpaid native bank transfer orders. A customer claim or manual record does not by itself confirm payment.', 'wpessential-bank-transfer-matcher' ) );
		try {
			$eligible_count ??= (int) wc_get_orders( [
				'payment_method' => 'bacs', 'status' => [ 'on-hold', 'pending' ], 'limit' => 1, 'paginate' => true, 'return' => 'ids',
			] )->total;
			( new Overview( $wpdb ) )->render( $eligible_count, $orders );
		} catch ( RuntimeException $error ) {
			echo '<p role="alert">' . esc_html__( 'Overview data is unavailable; the order list remains available.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		}
		echo '<form method="get" class="wbm-orders-filter"><input type="hidden" name="page" value="wpessential-bank-transfer-matcher">';
		printf( '<label for="wbm-search">%s</label> <input type="search" id="wbm-search" name="wbm_search" value="%s" maxlength="64"> ',
			esc_html__( 'Order ID or payment reference', 'wpessential-bank-transfer-matcher' ), esc_attr( substr( $search, 0, 64 ) ) );
		echo '<label for="wbm-status">' . esc_html__( 'Order status', 'wpessential-bank-transfer-matcher' ) . '</label> ';
		echo '<select id="wbm-status" name="wbm_status">';
		foreach ( [ 'eligible' => __( 'Eligible: on hold and pending', 'wpessential-bank-transfer-matcher' ), 'on-hold' => __( 'On hold', 'wpessential-bank-transfer-matcher' ), 'pending' => __( 'Pending payment', 'wpessential-bank-transfer-matcher' ) ] as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $status, $value, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label for="wbm-currency">' . esc_html__( 'Order currency', 'wpessential-bank-transfer-matcher' ) . '</label> ';
		echo '<select id="wbm-currency" name="wbm_currency"><option value="">' . esc_html__( 'All currencies', 'wpessential-bank-transfer-matcher' ) . '</option>';
		foreach ( $currencies as $code => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $currency, $code, false ), esc_html( $code . ' — ' . $label ) );
		}
		echo '</select> ';
		printf( '<label for="wbm-from">%s</label> <input type="date" id="wbm-from" name="wbm_from" value="%s"> ',
			esc_html__( 'Created from', 'wpessential-bank-transfer-matcher' ), esc_attr( substr( $from, 0, 10 ) ) );
		printf( '<label for="wbm-to">%s</label> <input type="date" id="wbm-to" name="wbm_to" value="%s"> ',
			esc_html__( 'Created through', 'wpessential-bank-transfer-matcher' ), esc_attr( substr( $to, 0, 10 ) ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'wpessential-bank-transfer-matcher' ) . '</button>';
		if ( '' !== $search || 'eligible' !== $status || '' !== $from || '' !== $to || '' !== $currency ) {
			printf( ' <a href="%s">%s</a>', esc_url( add_query_arg( 'page', 'wpessential-bank-transfer-matcher', admin_url( 'admin.php' ) ) ), esc_html__( 'Clear filters', 'wpessential-bank-transfer-matcher' ) );
		}
		echo '</form>';
		if ( '' !== $error_message ) {
			printf( '<p role="alert">%s</p>', esc_html( $error_message ) );
		} elseif ( ! $orders ) {
			printf( '<p role="status">%s</p>', '' === $search && '' === $from && '' === $to && '' === $currency
				? esc_html__( 'No bank transfer orders match this status.', 'wpessential-bank-transfer-matcher' )
				: esc_html__( 'No bank transfer orders match these filters.', 'wpessential-bank-transfer-matcher' ) );
		} else {
			echo '<div class="wbm-table-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'Bank transfer order rows', 'wpessential-bank-transfer-matcher' ) . '"><table class="wp-list-table widefat fixed striped"><thead><tr>';
			foreach ( [ __( 'Order', 'wpessential-bank-transfer-matcher' ), __( 'Date', 'wpessential-bank-transfer-matcher' ), __( 'Customer', 'wpessential-bank-transfer-matcher' ), __( 'Payment reference', 'wpessential-bank-transfer-matcher' ), __( 'Total', 'wpessential-bank-transfer-matcher' ), __( 'Allocated', 'wpessential-bank-transfer-matcher' ), __( 'Outstanding', 'wpessential-bank-transfer-matcher' ), __( 'Claim', 'wpessential-bank-transfer-matcher' ), __( 'Reconciliation', 'wpessential-bank-transfer-matcher' ), __( 'Actions', 'wpessential-bank-transfer-matcher' ) ] as $heading ) {
				printf( '<th scope="col">%s</th>', esc_html( $heading ) );
			}
			echo '</tr></thead><tbody>';
			$summary = new OrderSummary( $wpdb );
			foreach ( $orders as $order ) {
				self::row( $order, $summary );
			}
			echo '</tbody></table></div>';
		}
		if ( $pages > 1 ) {
			$base = add_query_arg( [ 'page' => 'wpessential-bank-transfer-matcher', 'wbm_status' => $status, 'wbm_from' => $from, 'wbm_to' => $to, 'wbm_currency' => $currency, 'paged' => '%#%' ], admin_url( 'admin.php' ) );
			echo '<nav class="wbm-pagination" aria-label="' . esc_attr__( 'Bank transfer order pages', 'wpessential-bank-transfer-matcher' ) . '">';
			echo wp_kses_post( paginate_links( [ 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'type' => 'plain' ] ) );
			echo '</nav>';
		}
		echo '</div>';
	}

	private static function date_bound( string $value, bool $end_of_day ): ?int {
		if ( '' === $value ) {
			return null;
		}
		if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid order date.' );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw new InvalidArgumentException( 'Invalid order date.' );
		}
		return ( $end_of_day ? $date->modify( '+1 day' )->setTime( 0, 0 )->getTimestamp() - 1 : $date->getTimestamp() );
	}

	/**
	 * @param list<string> $statuses
	 * @return list<WC_Order>
	 */
	private static function lookup( string $search, array $statuses, ReferenceRepository $references, ?int $start, ?int $end, string $currency ): array {
		if ( strlen( $search ) > 64 ) {
			return [];
		}
		if ( ctype_digit( $search ) ) {
			$order_id = filter_var( ltrim( $search, '0' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		} else {
			try {
				$order_id = $references->find_order_id( new PaymentReference( strtoupper( $search ) ) )?->value;
			} catch ( InvalidArgumentException $error ) {
				return [];
			}
		}
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method() || ! $order->has_status( $statuses ) ||
			( '' !== $currency && $currency !== $order->get_currency() ) ) {
			return [];
		}
		if ( null !== $start || null !== $end ) {
			$created = $order->get_date_created()?->getTimestamp();
			if ( null === $created || ( null !== $start && $created < $start ) || ( null !== $end && $created > $end ) ) {
				return [];
			}
		}
		return [ $order ];
	}

	private static function row( WC_Order $order, OrderSummary $summary ): void {
		try {
			$data = $summary->get( $order );
		} catch ( \InvalidArgumentException|\OverflowException|RuntimeException $error ) {
			printf( '<tr><td><a href="%s">#%s</a></td><td colspan="9">%s</td></tr>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ), esc_html__( 'Reconciliation summary unavailable; review this order.', 'wpessential-bank-transfer-matcher' ) );
			return;
		}
		$money = $data['total'];
		$customer = trim( $order->get_formatted_billing_full_name() );
		$customer = '' !== $customer ? $customer : __( 'Guest', 'wpessential-bank-transfer-matcher' );
		$state = $data['mismatch'] ? __( 'Balance exception', 'wpessential-bank-transfer-matcher' ) : ( 0 === $data['outstanding'] ? __( 'Fully allocated', 'wpessential-bank-transfer-matcher' ) : ( $data['allocated'] > 0 ? __( 'Partial', 'wpessential-bank-transfer-matcher' ) : __( 'Unallocated', 'wpessential-bank-transfer-matcher' ) ) );
		$reconcile_url = add_query_arg( [ 'page' => 'wpessential-bank-transfer-matcher', 'wbm_view' => 'order', 'order_id' => $order->get_id() ], admin_url( 'admin.php' ) );
		printf( '<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">%s</a></td></tr>',
			esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ),
			esc_html( $order->get_date_created()?->date_i18n( get_option( 'date_format' ) ) ?? '—' ), esc_html( $customer ),
			esc_html( $data['reference'] ?? '—' ), esc_html( self::money( $money->minor_units, $money->currency, $money->exponent ) ),
			esc_html( self::money( $data['allocated'], $money->currency, $money->exponent ) ),
			esc_html( self::money( $data['outstanding'], $money->currency, $money->exponent ) ),
			esc_html( $data['claim_status'] ?? '—' ), esc_html( $state ), esc_url( $reconcile_url ), esc_html__( 'Review', 'wpessential-bank-transfer-matcher' )
		);
	}

	public static function money( int $minor, string $currency, int $exponent ): string {
		$negative = $minor < 0 ? '-' : '';
		$digits = ltrim( (string) $minor, '-' );
		if ( 0 === $exponent ) {
			return $negative . $digits . ' ' . $currency;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $negative . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent ) . ' ' . $currency;
	}
}
