<?php

declare(strict_types=1);

use WPEssential\BankTransferMatcher\Domain\AllocationStatus;
use WPEssential\BankTransferMatcher\Domain\BatchStatus;
use WPEssential\BankTransferMatcher\Domain\ClaimStatus;
use WPEssential\BankTransferMatcher\Domain\Currency;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\OrderPaymentStatus;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Domain\ReviewCaseStatus;
use WPEssential\BankTransferMatcher\Domain\TransactionStatus;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher/vendor/autoload.php';

$reference = PaymentReference::issue( str_repeat( 'A', 39 ) );
if ( 64 !== strlen( $reference->value ) ||
	$reference->value !== ( new PaymentReference( $reference->value ) )->value ||
	1 !== preg_match( '/\AWBM-[A-F0-9]{24}\z/', PaymentReference::issue()->value ) ||
	0 !== ( new Currency( 'JPY', 0 ) )->exponent ||
	4 !== ( new Currency( 'CLF', 4 ) )->exponent ||
	PHP_INT_MAX !== ( new EntityId( PHP_INT_MAX ) )->value ) {
	throw new RuntimeException( 'Valid domain value did not round-trip.' );
}

foreach ( [
	fn () => new Currency( 'usd', 2 ),
	fn () => new Currency( 'USD', -1 ),
	fn () => new Currency( 'USD', 5 ),
	fn () => new EntityId( 0 ),
	fn () => new EntityId( -1 ),
	fn () => new PaymentReference( "WBM-123\n" ),
	fn () => new PaymentReference( '' ),
	fn () => new PaymentReference( str_repeat( 'A', 65 ) ),
	fn () => PaymentReference::issue( 'ABC--DEF' ),
	fn () => PaymentReference::issue( str_repeat( 'A', 40 ) ),
] as $construct ) {
	try {
		$construct();
	} catch ( InvalidArgumentException ) {
		continue;
	}
	throw new RuntimeException( 'Invalid domain value was accepted.' );
}

foreach ( [
	AllocationStatus::class   => 'proposed approved committed reversed',
	BatchStatus::class        => 'uploaded parsing preview_ready validating committing imported partially_imported failed cancelled',
	ClaimStatus::class        => 'submitted under_review verified rejected withdrawn',
	OrderPaymentStatus::class => 'unclaimed claimed_unverified partial fully_reconciled exception',
	ReviewCaseStatus::class   => 'open assigned investigating awaiting_approval resolved dismissed reopened',
	TransactionStatus::class  => 'new suggested needs_review allocated partially_allocated unallocated duplicate_suspect reversed',
] as $enum => $expected ) {
	if ( explode( ' ', $expected ) !== array_column( $enum::cases(), 'value' ) ||
		null !== $enum::tryFrom( 'unknown_status' ) ) {
		throw new RuntimeException( 'Status values diverged from the storage contract.' );
	}
}

echo "PASS domain value boundaries and status contracts\n";
