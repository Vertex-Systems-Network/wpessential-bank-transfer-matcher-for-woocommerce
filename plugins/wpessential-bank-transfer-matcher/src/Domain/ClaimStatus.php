<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum ClaimStatus: string {
	case SUBMITTED = 'submitted';
	case UNDER_REVIEW = 'under_review';
	case VERIFIED = 'verified';
	case REJECTED = 'rejected';
	case WITHDRAWN = 'withdrawn';
}
