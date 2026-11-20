<?php
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationPreferences;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationService;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Notification fixture requires disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing finance administrator.' );
$previous = get_current_user_id();
wp_set_current_user( $admin->ID );
$prior_setting = get_option( FinanceNotificationPreferences::OPTION, false );
$service = new FinanceNotificationService( $wpdb );
$nonce = wp_create_nonce( 'wbm_pro_save_finance_notifications' );
$test_nonce = wp_create_nonce( 'wbm_pro_test_finance_notification' );
$review_id = 0;
$captured = [];
$spy = static function ( $return, array $mail ) use ( &$captured ) {
	$captured[] = $mail;
	return true; // NO real SMTP delivery in disposable CI.
};
add_filter( 'pre_wp_mail', $spy, 10, 2 );
$reject = static function ( callable $action, string $reason ): void {
	try {
		$action();
		throw new RuntimeException( 'Unsafe finance mail action accepted: ' . $reason );
	} catch ( InvalidArgumentException|RuntimeException $expected ) {
		if ( str_starts_with( $expected->getMessage(), 'Unsafe finance mail action accepted:' ) ) {
			throw $expected;
		}
	}
};
try {
	if ( false === has_action( FinanceNotificationService::HOOK ) ) {
		throw new RuntimeException( 'Registered daily notification scheduler callback missing.' );
	}
	if ( $prior_setting === false && [] !== $service->deliver_due() ) {
		throw new RuntimeException( 'Default-off finance notifications sent without opt-in.' );
	}
	$reject( static fn () => $service->save( [
		'enabled_types' => [ 'pending_review_digest' ],
		'recipient_ids' => [ $admin->ID ],
	], $admin->ID, 'forged-nonce' ), 'forged settings request' );
	$reject( static fn () => $service->save( [
		'enabled_types' => [ 'pending_review_digest' ],
		'recipient_ids' => [ 999999999 ],
	], $admin->ID, $nonce ), 'nonexistent and non-finance recipient ID' );
	$preferences = $service->save( [
		'enabled_types' => [ 'pending_review_digest', 'reminder_digest' ],
		'recipient_ids' => [ $admin->ID ],
	], $admin->ID, $nonce );
	if ( $preferences['recipient_ids'] !== [ $admin->ID ] ||
		$service->settings() !== $preferences ) {
		throw new RuntimeException( 'Finance recipient settings lost canonical opt-in.' );
	}
	$preview = $service->preview( 'pending_review_digest', $admin->ID );
	if ( ! str_starts_with( $preview['subject'], '[PREVIEW]' ) ||
		count( $captured ) !== 0 ) {
		throw new RuntimeException( 'Preview unexpectedly sent a finance message.' );
	}
	$reject( static fn () => $service->send_test( 'exception_digest', $admin->ID, $test_nonce ),
		'send disabled notification template' );
	$first = $service->send_test( 'pending_review_digest', $admin->ID, $test_nonce );
	if ( $first['sent'] !== 1 || count( $captured ) !== 1 ) {
		throw new RuntimeException( 'Explicit, authorized test email was not delivered to the mail intercept.' );
	}
	$reject( static fn () => $service->send_test(
		'pending_review_digest', $admin->ID, 'invalid-nonce'
	), 'forged explicit test mail' );

	// Native unresolved case, aged 72 hours, exercises real schedule source
	// gating. It has no real bank or customer data; no financial mutation.
	( new ReviewCaseRepository( $wpdb ) )->queue( '', '', 0, 1, 20 );
	$table = $wpdb->prefix . 'wbm_pro_review_cases';
	$insert = $wpdb->insert( $table, [
		'case_type' => 'synthetic_manual_review', 'transaction_id' => 987654321,
		'order_id' => null, 'status' => 'open', 'priority' => 'normal',
		'assignee_id' => null, 'reasons_json' => '[]', 'version' => 1,
		'created_by' => $admin->ID,
		'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
		'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	] );
	if ( 1 !== $insert ) throw new RuntimeException( 'Cannot stage synthetic review digest fixture.' );
	$review_id = (int) $wpdb->insert_id;
	$automatic = $service->deliver_due();
	$submitted = array_sum( array_column( $automatic, 'sent' ) );
	if ( $submitted !== 1 || count( $captured ) !== 2 ||
		$service->deliver_due()['pending_review_digest']['sent'] !== 0 ) {
		throw new RuntimeException( 'Daily finance digest violated recipient budget or idempotency.' );
	}
	$more = $service->send_test( 'reminder_digest', $admin->ID, $test_nonce );
	if ( $more['sent'] !== 0 || $more['skipped'] !== 1 ||
		count( $captured ) !== 2 ) {
		throw new RuntimeException( 'Finance reminder bypassed cumulative two-per-day recipient cap.' );
	}
	foreach ( $captured as $mail ) {
		if ( $mail['to'] !== $admin->user_email ||
			! str_contains( (string) $mail['message'], 'not confirmation of cleared funds' ) ||
			! empty( $mail['attachments'] ) ||
			str_contains( (string) $mail['message'], '987654321' ) ||
			! str_contains( (string) $mail['subject'], 'Bank Transfer Matcher' ) ) {
			throw new RuntimeException( 'Finance notification leaked source evidence or targeted an unapproved user.' );
		}
	}
	wp_set_current_user( 0 );
	$reject( static fn () => $service->save(
		[ 'enabled_types' => [], 'recipient_ids' => [] ], $admin->ID, $nonce
	), 'anonymous attempted to reconfigure finance delivery' );
	wp_set_current_user( $admin->ID );
	$service->save( [ 'enabled_types' => [], 'recipient_ids' => [] ], $admin->ID, $nonce );
	if ( $service->deliver_due() !== [] || count( $captured ) !== 2 ) {
		throw new RuntimeException( 'Disabled notifications continued to send messages.' );
	}
	$recorded = $wpdb->get_results( $wpdb->prepare(
		"SELECT event_key,delivery_status FROM {$wpdb->prefix}wbm_pro_notification_delivery
		 WHERE recipient_id=%d AND event_day=%s",
		$admin->ID, gmdate( 'Y-m-d' )
	), ARRAY_A );
	if ( count( $recorded ) !== 2 ||
		array_unique( array_column( $recorded, 'delivery_status' ) ) !== [ 'sent' ] ) {
		throw new RuntimeException( 'Notification reservation/audit did not preserve exact accepted deliveries.' );
	}
	echo "PASS opt-in finance digests, preview-only, trusted recipients, nonces, 24h per-type / 2-total quotas, source-backed scheduler, zero real email\n";
} finally {
	wp_set_current_user( $admin->ID );
	remove_filter( 'pre_wp_mail', $spy, 10 );
	if ( $review_id > 0 ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_review_cases', [ 'id' => $review_id ], [ '%d' ] );
	}
	$wpdb->delete( $wpdb->prefix . 'wbm_pro_notification_delivery',
		[ 'recipient_id' => $admin->ID, 'event_day' => gmdate( 'Y-m-d' ) ], [ '%d','%s' ] );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( FinanceNotificationService::HOOK, [],
			'wpessential-wbm-pro-finance-digest' );
	}
	if ( false === $prior_setting ) delete_option( FinanceNotificationPreferences::OPTION );
	else update_option( FinanceNotificationPreferences::OPTION, $prior_setting, false );
	wp_set_current_user( $previous );
}
