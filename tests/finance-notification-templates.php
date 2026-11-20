<?php
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationTemplate;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationPreferences;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Notifications/FinanceNotificationTemplate.php';
require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/src/Notifications/FinanceNotificationPreferences.php';

$types = FinanceNotificationTemplate::types();
if ( count( $types ) !== 4 || count( array_unique( $types ) ) !== 4 ) {
	throw new RuntimeException( 'Finance notification types are not a fixed enumeration.' );
}
foreach ( $types as $type ) {
	$preview = FinanceNotificationTemplate::render(
		$type, '2026-11-20', 'https://example.test/wp-admin/admin.php?page=wpessential-wbm-pro-review-queue', true
	);
	$normal = FinanceNotificationTemplate::render(
		$type, '2026-11-20', 'https://example.test/wp-admin/admin.php?page=wpessential-wbm-pro-review-queue'
	);
	if ( ! str_starts_with( $preview['subject'], '[PREVIEW]' ) ||
		$normal['subject'] === $preview['subject'] ||
		! str_contains( $normal['body'], 'not confirmation of cleared funds' ) ||
		! str_contains( $normal['body'], 'No bank statement data' ) ||
		str_contains( $normal['body'], 'customer@example.test' ) ||
		! str_contains( $normal['body'], '2026-11-20' ) ) {
		throw new RuntimeException( 'Notification lost review-only, privacy or preview contract.' );
	}
}
foreach ( [
	[ 'unknown', '2026-11-20', 'https://example.test/' ],
	[ 'mismatch_digest', '2026-11-20', "https://example.test/\r\nBcc:intruder@example.test" ],
	[ 'exception_digest', '2026-11-20', 'javascript:alert(1)' ],
	[ 'reminder_digest', 'invalid-date', 'https://example.test/' ],
] as [ $type, $date, $url ] ) {
	try {
		FinanceNotificationTemplate::render( $type, $date, $url );
		throw new RuntimeException( 'Unsafe notification template accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
$opted_in = FinanceNotificationPreferences::normalize( [
	'enabled_types' => [ 'mismatch_digest', 'exception_digest' ],
	'recipient_ids' => [ '42', 7 ],
] );
if ( $opted_in['enabled_types'] !== [ 'exception_digest','mismatch_digest' ] ||
	$opted_in['recipient_ids'] !== [ 7, 42 ] ||
	FinanceNotificationPreferences::disabled()['enabled_types'] !== [] ) {
	throw new RuntimeException( 'Opt-in recipient policy did not canonicalize.' );
}
foreach ( [
	[ 'enabled_types' => [ 'mismatch_digest' ], 'recipient_ids' => [] ],
	[ 'enabled_types' => [ 'unsupported' ], 'recipient_ids' => [ 5 ] ],
	[ 'enabled_types' => [ 'exception_digest','exception_digest' ], 'recipient_ids' => [ 5 ] ],
	[ 'enabled_types' => [ 'reminder_digest' ], 'recipient_ids' => [ '1e3' ] ],
	[ 'enabled_types' => [], 'recipient_ids' => [ '0' ] ],
	[ 'enabled_types' => [], 'recipient_ids' => [ '7', 7 ] ],
	[ 'enabled_types' => [], 'recipient_ids' => [ 8 ], 'free_form_email' => 'attacker@example.test' ],
] as $bad ) {
	try {
		FinanceNotificationPreferences::normalize( $bad );
		throw new RuntimeException( 'Unsafe opt-in/recipient config accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
}
echo "PASS fixed four plain-text finance digests, safe previews, restricted context and no bank/payment leakage\n";
