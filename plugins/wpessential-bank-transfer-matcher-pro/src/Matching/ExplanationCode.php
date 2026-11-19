<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

/** Stable, machine-readable reasons. Labels belong in the presentation layer. */
enum ExplanationCode: string {
	case ELIGIBLE = 'eligible';
	case CREDIT_REQUIRED = 'credit_required';
	case DUPLICATE_SUSPECT = 'duplicate_suspect';
	case SUPPORTED_CURRENCY_REQUIRED = 'supported_currency_required';
	case BANK_ACCOUNT_REQUIRED = 'bank_account_required';
	case BACS_ORDER_REQUIRED = 'bacs_order_required';
	case ORDER_OPEN_REQUIRED = 'order_open_required';
	case ORDER_UNPAID_REQUIRED = 'order_unpaid_required';
	case EXACT_REFERENCE = 'exact_reference';
	case EXACT_AMOUNT = 'exact_amount';
	case EXACT_CURRENCY = 'exact_currency';
	case EXACT_ACCOUNT = 'exact_account';
	case AMBIGUOUS = 'ambiguous';
	case DATE_PROXIMITY_HINT = 'date_proximity_hint';
	case ORDER_TOKEN_HINT = 'order_token_hint';
	case CUSTOMER_NAME_HINT = 'customer_name_hint';
}
