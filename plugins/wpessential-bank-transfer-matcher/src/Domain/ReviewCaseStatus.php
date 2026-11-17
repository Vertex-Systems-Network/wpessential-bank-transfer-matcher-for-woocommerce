<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum ReviewCaseStatus: string {
	case OPEN = 'open';
	case ASSIGNED = 'assigned';
	case INVESTIGATING = 'investigating';
	case AWAITING_APPROVAL = 'awaiting_approval';
	case RESOLVED = 'resolved';
	case DISMISSED = 'dismissed';
	case REOPENED = 'reopened';
}
