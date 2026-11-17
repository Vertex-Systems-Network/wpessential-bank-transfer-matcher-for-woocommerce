<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum AllocationStatus: string {
	case PROPOSED = 'proposed';
	case APPROVED = 'approved';
	case COMMITTED = 'committed';
	case REVERSED = 'reversed';
}
