<?php
/**
 * Pro integration startup.
 *
 * @package WPEssential\BankTransferMatcherPro
 */

namespace WPEssential\BankTransferMatcherPro\Bootstrap;

use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Admin\BankAccountsPage;
use WPEssential\BankTransferMatcherPro\Admin\MappingProfilesPage;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function register(): void {
		add_action( 'init', array( Capabilities::class, 'install_defaults' ), 30 );
		BankAccountsPage::register();
		MappingProfilesPage::register();
		ImportStatementsPage::register();
		BankTransactionsPage::register();
		ImportJob::register();
		do_action( 'wpessential_bank_transfer_matcher_pro_ready' );
	}
}
