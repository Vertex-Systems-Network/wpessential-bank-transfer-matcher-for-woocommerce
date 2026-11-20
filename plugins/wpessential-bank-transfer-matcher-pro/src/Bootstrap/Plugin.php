<?php
/**
 * Pro integration startup.
 *
 * @package WPEssential\BankTransferMatcherPro
 */

namespace WPEssential\BankTransferMatcherPro\Bootstrap;

use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Admin\BankAccountsPage;
use WPEssential\BankTransferMatcherPro\Admin\BankTransactionsPage;
use WPEssential\BankTransferMatcherPro\Admin\ImportStatementsPage;
use WPEssential\BankTransferMatcherPro\Admin\MappingProfilesPage;
use WPEssential\BankTransferMatcherPro\Admin\ReviewQueuePage;
use WPEssential\BankTransferMatcherPro\Admin\AllocationReviewPage;
use WPEssential\BankTransferMatcherPro\Admin\AllocationAdminActions;
use WPEssential\BankTransferMatcherPro\Admin\ReportExportsPage;
use WPEssential\BankTransferMatcherPro\Api\ReviewCaseController;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationService;
use WPEssential\BankTransferMatcherPro\Admin\FinanceNotificationsPage;
use WPEssential\BankTransferMatcherPro\Admin\FinanceDashboardPage;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function register(): void {
		add_action( 'init', array( Capabilities::class, 'install_defaults' ), 30 );
		BankAccountsPage::register();
		MappingProfilesPage::register();
		ImportStatementsPage::register();
		BankTransactionsPage::register();
		ReviewQueuePage::register();
		AllocationReviewPage::register();
		AllocationAdminActions::register();
		ReportExportsPage::register();
		ReviewCaseController::register();
		ImportJob::register();
		AsyncReportExportJob::register();
		FinanceNotificationService::register();
		FinanceNotificationsPage::register();
		FinanceDashboardPage::register();
		do_action( 'wpessential_bank_transfer_matcher_pro_ready' );
	}
}
