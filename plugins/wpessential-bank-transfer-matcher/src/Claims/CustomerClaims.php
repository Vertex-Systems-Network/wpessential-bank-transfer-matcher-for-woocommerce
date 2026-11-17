<?php

namespace WPEssential\BankTransferMatcher\Claims;

use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WP_Error;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\PaymentMethods;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;

final class CustomerClaims {
	private const ACTION = 'wpessential_wbm_submit_claim';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'submit' ) );
	}

	public static function submit(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This form accepts POST requests only.', 'wpessential-bank-transfer-matcher' ), '', [ 'response' => 405 ] );
		}
		$result = self::process( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => $result->get_error_data() ] );
		}
		[ $order, $status ] = $result;
		$url = $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
		wp_safe_redirect( add_query_arg( 'wbm_claim', $status, $url ), 303 );
		exit;
	}

	/** @return array{WC_Order, string}|WP_Error */
	public static function process( array $input ): array|WP_Error {
		$forbidden = new WP_Error( 'wbm_claim_forbidden', __( 'Unable to verify access to this order.', 'wpessential-bank-transfer-matcher' ), 403 );
		$order_id = filter_var( self::field( $input, 'order_id' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		if ( ! $order_id || ! wp_verify_nonce( self::field( $input, '_wbm_claim_nonce' ), self::ACTION . ':' . $order_id ) ) {
			return $forbidden;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $forbidden;
		}
		$guest = ! $order->get_customer_id();
		if ( $guest
			? ! hash_equals( $order->get_order_key(), self::field( $input, 'order_key' ) ) || '' === self::field( $input, 'order_key' )
			: $order->get_customer_id() !== get_current_user_id() ) {
			return $forbidden;
		}
		if ( ! self::eligible( $order ) ) {
			return $forbidden;
		}

		global $wpdb;
		$repository = new ClaimRepository( $wpdb );
		try {
			if ( ! $repository->allow_attempt( $order_id ) ) {
				return new WP_Error( 'wbm_claim_limited', __( 'Too many attempts. Please wait before trying again.', 'wpessential-bank-transfer-matcher' ), 429 );
			}
			if ( $guest ) {
				$email = self::field( $input, 'billing_email' );
				if ( ! is_email( $email ) || ! hash_equals( strtolower( $order->get_billing_email() ), strtolower( $email ) ) ) {
					return $forbidden;
				}
			}
			if ( ! ( new ReferenceRepository( $wpdb ) )->find( new EntityId( $order_id ) ) ) {
				return $forbidden;
			}
			if ( $repository->exists( $order_id ) ) {
				return [ $order, 'submitted' ];
			}
			$repository->save( $order_id, self::validate( $input, $order ) );
			return [ $order, 'submitted' ];
		} catch ( InvalidArgumentException | \OverflowException ) {
			return [ $order, 'invalid' ];
		} catch ( RuntimeException ) {
			return [ $order, 'unavailable' ];
		}
	}

	public static function render( WC_Order $order ): void {
		if ( ! self::eligible( $order ) ) {
			return;
		}

		global $wpdb;
		try {
			if ( ! ( new ReferenceRepository( $wpdb ) )->find( new EntityId( $order->get_id() ) ) ) {
				return;
			}
			if ( ( new ClaimRepository( $wpdb ) )->exists( $order->get_id() ) ) {
				printf( '<p role="status">%s</p>', esc_html__( 'Claim received. Payment has not yet been verified.', 'wpessential-bank-transfer-matcher' ) );
				return;
			}
		} catch ( RuntimeException ) {
			return;
		}

		$status = isset( $_GET['wbm_claim'] ) && is_string( $_GET['wbm_claim'] ) ? wp_unslash( $_GET['wbm_claim'] ) : '';
		if ( 'invalid' === $status || 'unavailable' === $status ) {
			printf( '<p role="alert">%s</p>', esc_html( 'invalid' === $status
				? __( 'Check the transfer date, amount and acknowledgment, then try again.', 'wpessential-bank-transfer-matcher' )
				: __( 'Payment claims are temporarily unavailable.', 'wpessential-bank-transfer-matcher' ) ) );
		}

		?>
		<form class="wbm-payment-claim" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<h2><?php esc_html_e( 'I have sent the payment', 'wpessential-bank-transfer-matcher' ); ?></h2>
			<p><?php esc_html_e( 'The store uses these details to review your claim. Submitting a claim does not verify payment. Do not include bank credentials or card details.', 'wpessential-bank-transfer-matcher' ); ?></p>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
			<?php wp_nonce_field( self::ACTION . ':' . $order->get_id(), '_wbm_claim_nonce', false ); ?>
			<?php if ( ! $order->get_customer_id() ) : ?>
				<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
				<p><label><?php esc_html_e( 'Billing email', 'wpessential-bank-transfer-matcher' ); ?> <input type="email" name="billing_email" required></label></p>
			<?php endif; ?>
			<p><label><?php esc_html_e( 'Transfer date', 'wpessential-bank-transfer-matcher' ); ?> <input type="date" name="transfer_date" required></label></p>
			<p><label><?php printf( esc_html__( 'Transfer amount (%s)', 'wpessential-bank-transfer-matcher' ), esc_html( $order->get_currency() ) ); ?> <input type="text" inputmode="decimal" name="transfer_amount" maxlength="20" required></label></p>
			<p><label><?php esc_html_e( 'Bank transfer reference (optional)', 'wpessential-bank-transfer-matcher' ); ?> <input type="text" name="transfer_reference" maxlength="100"></label></p>
			<p><label><?php esc_html_e( 'Sender name (optional)', 'wpessential-bank-transfer-matcher' ); ?> <input type="text" name="sender_name" maxlength="100"></label></p>
			<p><label><?php esc_html_e( 'Note for the store (optional)', 'wpessential-bank-transfer-matcher' ); ?> <textarea name="customer_note" maxlength="1000"></textarea></label></p>
			<p><label><input type="checkbox" name="acknowledged" value="yes" required> <?php esc_html_e( 'I understand that a claim does not verify payment.', 'wpessential-bank-transfer-matcher' ); ?></label></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Submit payment claim', 'wpessential-bank-transfer-matcher' ); ?></button></p>
		</form>
		<?php
	}

	private static function eligible( WC_Order $order ): bool {
		return $order->get_id() > 0 && PaymentMethods::BACS === $order->get_payment_method() &&
			$order->has_status( [ 'pending', 'on-hold' ] );
	}

	private static function field( array $input, string $key ): string {
		return is_string( $input[ $key ] ?? null ) ? trim( $input[ $key ] ) : '';
	}

	private static function validate( array $input, WC_Order $order ): array {
		$date = self::field( $input, 'transfer_date' );
		if ( 'yes' !== self::field( $input, 'acknowledged' ) ||
			! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $date_parts ) ||
			(int) $date_parts[1] < 1000 || ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] ) ||
			$date > wp_date( 'Y-m-d' ) ) {
			throw new InvalidArgumentException( 'Invalid transfer date or acknowledgment.' );
		}
		$raw_amount = self::field( $input, 'transfer_amount' );
		if ( strlen( $raw_amount ) > 20 ) {
			throw new InvalidArgumentException( 'Invalid transfer amount length.' );
		}
		$amount = Money::from_decimal( $raw_amount, $order->get_currency(), wc_get_price_decimals() );
		if ( $amount->minor_units <= 0 || $amount->minor_units > 1_000_000_000_000 ) {
			throw new InvalidArgumentException( 'Invalid claimed amount.' );
		}
		$reference = sanitize_text_field( self::field( $input, 'transfer_reference' ) );
		$sender = sanitize_text_field( self::field( $input, 'sender_name' ) );
		$note = sanitize_textarea_field( self::field( $input, 'customer_note' ) );
		if ( strlen( $reference ) > 100 || strlen( $sender ) > 100 || strlen( $note ) > 1000 ) {
			throw new InvalidArgumentException( 'Claim text exceeds the allowed length.' );
		}
		return compact( 'date', 'amount', 'reference', 'sender', 'note' );
	}
}
