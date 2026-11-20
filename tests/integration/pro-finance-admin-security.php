<?php
use WPEssential\BankTransferMatcherPro\Admin\AllocationAdminActions;
use WPEssential\BankTransferMatcherPro\Admin\AllocationReviewPage;
use WPEssential\BankTransferMatcherPro\Admin\ReportExportsPage;
use WPEssential\BankTransferMatcherPro\Admin\FinanceNotificationsPage;
use WPEssential\BankTransferMatcherPro\Admin\FinanceOperationsPage;
use WPEssential\BankTransferMatcherPro\Admin\FinanceRetentionPage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Use disposable wbm_ci for finance admin security tests only.' );
}
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing finance administrator fixture.' );
foreach ( [
	'wbm_pro_allocation_propose', 'wbm_pro_allocation_approve',
	'wbm_pro_correction_raise', 'wbm_pro_correction_review',
] as $action ) {
	if ( false === has_action( 'admin_post_' . $action ) ) {
		throw new RuntimeException( 'Missing explicit WordPress finance action: ' . $action );
	}
}
if ( false === has_action( 'admin_post_wbm_pro_export_report' ) ||
	false === has_action( 'admin_post_wbm_pro_schedule_report_export' ) ||
	false === has_action( 'admin_post_wbm_pro_download_queued_export' ) ||
	false === has_action( 'admin_post_wbm_pro_cancel_queued_export' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_export_report' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_schedule_report_export' ) ||
	false === has_action( 'admin_menu', [ ReportExportsPage::class, 'menu' ] ) ) {
	throw new RuntimeException( 'Finance CSV export must be a registered authenticated admin POST only.' );
}
if ( false === has_action( 'admin_post_wbm_pro_save_finance_notifications' ) ||
	false === has_action( 'admin_post_wbm_pro_test_finance_notification' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_save_finance_notifications' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_test_finance_notification' ) ||
	false === has_action( 'admin_menu', [ FinanceNotificationsPage::class, 'menu' ] ) ) {
	throw new RuntimeException( 'Opt-in finance notifications need authenticated admin settings and explicit tests.' );
}
if ( false === has_action( 'admin_post_wbm_pro_ops_diagnostics_export' ) ||
	false === has_action( 'admin_post_wbm_pro_ops_recovery' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_ops_diagnostics_export' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_ops_recovery' ) ||
	false === has_action( 'admin_menu', [ FinanceOperationsPage::class, 'menu' ] ) ) {
	throw new RuntimeException( 'Operational support diagnostics and recovery must require authenticated admin POST.' );
}
if ( false === has_action( 'admin_post_wbm_pro_retention_save' ) ||
	false === has_action( 'admin_post_wbm_pro_retention_sweep' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_retention_save' ) ||
	false !== has_action( 'admin_post_nopriv_wbm_pro_retention_sweep' ) ||
	false === has_action( 'admin_menu', [ FinanceRetentionPage::class, 'menu' ] ) ) {
	throw new RuntimeException( 'Privacy retention controls must be private authenticated admin-only actions.' );
}
if ( false === has_action( 'admin_menu', [ AllocationReviewPage::class, 'menu' ] ) ) {
	throw new RuntimeException( 'Finance review queue was not registered as WordPress menu.' );
}
$key = hash( 'sha256', 'security-fixture-only' );
$reason = 'Finance security fixture reason must never cause a financial mutation.';
$unauth = [
	'order_id' => '1', 'source_id' => '1', 'request_key' => $key, 'reason' => $reason,
];
$previous_user = get_current_user_id();
$old_post = $_POST;
$method = $_SERVER['REQUEST_METHOD'] ?? null;
try {
	wp_set_current_user( 0 );
	try {
		AllocationAdminActions::process_proposal( $unauth );
		throw new RuntimeException( 'Anonymous actor invoked finance proposal.' );
	} catch ( RuntimeException $expected ) {
		if ( str_contains( $expected->getMessage(), 'Anonymous actor invoked' ) ) throw $expected;
	}
	wp_set_current_user( $admin->ID );
	ob_start();
	ReportExportsPage::render();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'name="action" value="wbm_pro_export_report"' ) ||
		! str_contains( $html, 'name="action" value="wbm_pro_schedule_report_export"' ) ||
		! str_contains( $html, 'name="_wpnonce"' ) ||
		! str_contains( $html, 'name="report"' ) ||
		! str_contains( $html, 'name="before_id"' ) ||
		str_contains( $html, 'bank_account_number' ) ) {
		throw new RuntimeException( 'Native finance export UI lost guarded form fields or leaked account data.' );
	}
	ob_start();
	FinanceNotificationsPage::render();
	$notifications_html = ob_get_clean();
	if ( ! str_contains( $notifications_html, 'name="action" value="wbm_pro_save_finance_notifications"' ) ||
		! str_contains( $notifications_html, 'name="_wpnonce"' ) ||
		! str_contains( $notifications_html, 'name="recipient_ids[]"' ) ||
		! str_contains( $notifications_html, '[PREVIEW]' ) ||
		str_contains( $notifications_html, 'bank_account_number' ) ) {
		throw new RuntimeException( 'Finance notification settings lost nonce, eligible recipient choices or preview safety.' );
	}
	foreach ( [ '0', '1.5', '99999999999999999999999999999' ] as $invalid_id ) {
		try {
			AllocationAdminActions::process_proposal( array_replace( $unauth, [ 'order_id' => $invalid_id ] ) );
			throw new RuntimeException( 'Non-integer or out-of-range order ID accepted.' );
		} catch ( InvalidArgumentException $expected ) {}
	}
	try {
		AllocationAdminActions::process_proposal( array_replace( $unauth, [ 'request_key' => 'not-an-id' ] ) );
		throw new RuntimeException( 'Malformed proposal replay key accepted.' );
	} catch ( InvalidArgumentException $expected ) {}
	try {
		AllocationAdminActions::process_approval( [
			'allocation_id' => '1', 'split_group_key' => '', 'reason' => $reason,
		] );
		throw new RuntimeException( 'Allocation approval accepted without explicit cleared confirmation.' );
	} catch ( InvalidArgumentException $expected ) {}

	// Drive the actual HTTP adapter with an invalid request nonce, intercept
	// wp_die to prove rejection before any finance service is invoked.
	$deny = static function () {
		return static function ( $message ) {
			throw new RuntimeException( 'Finance nonce rejected before business operation.' );
		};
	};
	add_filter( 'wp_die_handler', $deny );
	try {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array_replace( $unauth, [
			'action' => 'wbm_pro_allocation_propose', '_wpnonce' => 'invalid-finance-nonce',
		] );
		try {
			AllocationAdminActions::handle_proposal();
			throw new RuntimeException( 'Invalid nonce executed a financial proposal.' );
		} catch ( RuntimeException $expected ) {
			if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
				throw $expected;
			}
		}
		$_POST = [
			'action' => 'wbm_pro_export_report', '_wpnonce' => 'invalid-finance-nonce',
			'report' => 'import_batches', 'from' => gmdate( 'Y-m-d' ),
			'to' => gmdate( 'Y-m-d' ),
		];
		try {
			ReportExportsPage::download();
			throw new RuntimeException( 'Invalid nonce returned a CSV download.' );
		} catch ( RuntimeException $expected ) {
			if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
				throw $expected;
			}
		}
		foreach ( [
			'wbm_pro_save_finance_notifications' => 'save',
			'wbm_pro_test_finance_notification' => 'send_test',
		] as $action => $handler ) {
			$_POST = [ 'action' => $action, '_wpnonce' => 'invalid-finance-nonce',
				'enabled_types' => [], 'recipient_ids' => [],
				'notification_type' => 'pending_review_digest' ];
			try {
				FinanceNotificationsPage::$handler();
				throw new RuntimeException( 'Invalid nonce executed notification action.' );
			} catch ( RuntimeException $expected ) {
				if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
					throw $expected;
				}
			}
		}
		foreach ( [
			'wbm_pro_ops_diagnostics_export' => 'download_diagnostics',
			'wbm_pro_ops_recovery' => 'recover',
		] as $action => $handler ) {
			$_POST = [ 'action' => $action, '_wpnonce' => 'invalid-finance-nonce',
				'operation' => 'retry_import', 'source_id' => '1',
				'reason' => 'Explicit synthetic guarded operation.' ];
			try {
				FinanceOperationsPage::$handler();
				throw new RuntimeException( 'Forged nonce executed a finance operations action.' );
			} catch ( RuntimeException $expected ) {
				if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
					throw $expected;
				}
			}
		}
		foreach ( [
			'wbm_pro_retention_save' => 'save',
			'wbm_pro_retention_sweep' => 'sweep',
		] as $action => $handler ) {
			$_POST = [ 'action' => $action, '_wpnonce' => 'invalid-finance-nonce',
				'confirm' => '1', 'automatic_cleanup' => '0' ];
			try {
				FinanceRetentionPage::$handler();
				throw new RuntimeException( 'Forged nonce executed a privacy cleanup action.' );
			} catch ( RuntimeException $expected ) {
				if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
					throw $expected;
				}
			}
		}
		foreach ( [
			'wbm_pro_schedule_report_export' => 'schedule',
			'wbm_pro_download_queued_export' => 'download_queued',
			'wbm_pro_cancel_queued_export' => 'cancel_queued',
		] as $action => $handler ) {
			$_POST = [ 'action' => $action, '_wpnonce' => 'invalid-finance-nonce',
				'job_id' => '1', 'report' => 'import_batches',
				'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ) ];
			try {
				ReportExportsPage::$handler();
				throw new RuntimeException( 'Invalid nonce executed queued export action.' );
			} catch ( RuntimeException $expected ) {
				if ( 'Finance nonce rejected before business operation.' !== $expected->getMessage() ) {
					throw $expected;
				}
			}
		}
	} finally {
		remove_filter( 'wp_die_handler', $deny );
	}
	echo "PASS native finance admin/CSV POST registry, accessible export form, anonymous denial, strict IDs and nonce rejection\n";
} finally {
	wp_set_current_user( $previous_user );
	$_POST = $old_post;
	if ( null === $method ) unset( $_SERVER['REQUEST_METHOD'] );
	else $_SERVER['REQUEST_METHOD'] = $method;
}
