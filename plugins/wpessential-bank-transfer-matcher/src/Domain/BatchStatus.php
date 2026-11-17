<?php

namespace WPEssential\BankTransferMatcher\Domain;

enum BatchStatus: string {
	case UPLOADED = 'uploaded';
	case PARSING = 'parsing';
	case PREVIEW_READY = 'preview_ready';
	case VALIDATING = 'validating';
	case COMMITTING = 'committing';
	case IMPORTED = 'imported';
	case PARTIALLY_IMPORTED = 'partially_imported';
	case FAILED = 'failed';
	case CANCELLED = 'cancelled';
}
