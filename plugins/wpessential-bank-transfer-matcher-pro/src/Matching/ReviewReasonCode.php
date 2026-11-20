<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;

enum ReviewReasonCode: string {
	case EVIDENCE_REVIEWED = 'evidence_reviewed';
	case DUPLICATE_CONFIRMED = 'duplicate_confirmed';
	case NOT_ORDER_PAYMENT = 'not_order_payment';
	case MISMATCH_RESOLVED = 'mismatch_resolved';
	case SECOND_APPROVAL_REQUIRED = 'second_approval_required';
	case NEW_EVIDENCE = 'new_evidence';

	public static function parse( string $value ): self {
		return self::tryFrom( $value ) ?? throw new InvalidArgumentException( 'Unsupported review reason code.' );
	}
}
