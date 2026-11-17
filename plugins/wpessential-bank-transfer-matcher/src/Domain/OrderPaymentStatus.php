<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum OrderPaymentStatus: string {
	case UNCLAIMED = 'unclaimed';
	case CLAIMED_UNVERIFIED = 'claimed_unverified';
	case PARTIAL = 'partial';
	case FULLY_RECONCILED = 'fully_reconciled';
	case EXCEPTION = 'exception';
}
