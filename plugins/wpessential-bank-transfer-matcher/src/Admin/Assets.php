<?php
/**
 * Assets scoped to a registered Matcher page.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {
	public static function register( string $page_hook ): void {
		add_action(
			'admin_enqueue_scripts',
			static function ( string $current_hook ) use ( $page_hook ): void {
				if ( $page_hook === $current_hook ) {
					$plugin = dirname( __DIR__, 2 ) . '/wpessential-bank-transfer-matcher.php';
					wp_enqueue_style( 'wpessential-wbm-admin', plugins_url( 'assets/admin.css', $plugin ), array(), '1.0.0' );
				}
			}
		);
	}
}
