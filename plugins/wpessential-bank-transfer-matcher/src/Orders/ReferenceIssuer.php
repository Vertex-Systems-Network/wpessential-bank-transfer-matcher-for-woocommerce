<?php

namespace WPEssential\BankTransferMatcher\Orders;

use RuntimeException;
use WC_Order;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;

final class ReferenceIssuer {
	public function __construct( private readonly ReferenceRepository $repository ) {}

	/** @param null|callable(): PaymentReference $generate */
	public function issue( EntityId $order_id, ?callable $generate = null ): PaymentReference {
		$existing = $this->repository->find( $order_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$generate ??= static fn () => PaymentReference::issue();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$reference = $this->repository->reserve( $order_id, $generate() );
			if ( null !== $reference ) {
				return $reference;
			}
		}
		throw new RuntimeException( 'Could not allocate a unique payment reference.' );
	}

	public static function issue_at_checkout( WC_Order $order ): void {
		if ( PaymentMethods::BACS !== $order->get_payment_method() ||
			! $order->get_id() ||
			! in_array( PaymentMethods::BACS, PaymentMethods::enabled_ids(), true ) ) {
			return;
		}

		global $wpdb;
		try {
			( new self( new ReferenceRepository( $wpdb ) ) )->issue( new EntityId( $order->get_id() ) );
		} catch ( RuntimeException $error ) {
			wc_get_logger()->error(
				'Payment reference could not be issued.',
				[ 'source' => 'wpessential-bank-transfer-matcher' ]
			);
		}
	}
}
