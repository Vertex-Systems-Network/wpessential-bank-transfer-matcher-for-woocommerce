<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum TransactionStatus: string {
	case NEW = 'new';
	case SUGGESTED = 'suggested';
	case NEEDS_REVIEW = 'needs_review';
	case ALLOCATED = 'allocated';
	case PARTIALLY_ALLOCATED = 'partially_allocated';
	case UNALLOCATED = 'unallocated';
	case DUPLICATE_SUSPECT = 'duplicate_suspect';
	case REVERSED = 'reversed';
}
