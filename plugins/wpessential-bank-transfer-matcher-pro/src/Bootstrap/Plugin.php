<?php
/**
 * Pro integration startup.
 *
 * @package WPEssential\BankTransferMatcherPro
 */

namespace WPEssential\BankTransferMatcherPro\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function register(): void {
		do_action( 'wpessential_bank_transfer_matcher_pro_ready' );
	}
}
