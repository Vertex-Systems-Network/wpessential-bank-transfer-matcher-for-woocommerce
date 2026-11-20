<?php
/**
 * Plugin Name: WPEssential Bank Transfer Matcher for WooCommerce
 * Description: Local bank transfer reconciliation for WooCommerce.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Tested up to: 7.0
 * WC tested up to: 11.3.0
 * WC requires at least: 10.8
 * Requires Plugins: woocommerce
 * Author: WPEssential
 * License: GPL-2.0-or-later
 * Text Domain: wpessential-bank-transfer-matcher
 *
 * @package WPEssential\BankTransferMatcher
 */

defined( 'ABSPATH' ) || exit;

// Declare WooCommerce HPOS compatibility from the main plugin file in both
// admin and frontend requests. Actual Free+Pro classic/HPOS workflows are
// exercised by disposable WooCommerce/MariaDB integration tests.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

register_deactivation_hook( __FILE__, static function (): void {
	wp_clear_scheduled_hook( 'wpessential_wbm_purge_claim_receipts' );
} );

add_action(
	'plugins_loaded',
	static function () {
		$message = '';
		$autoload = __DIR__ . '/vendor/autoload.php';

		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '10.8', '<' ) ) {
			$message = __( 'Bank Transfer Matcher requires an active WooCommerce 10.8 or later.', 'wpessential-bank-transfer-matcher' );
		} elseif ( ! is_readable( $autoload ) ) {
			$message = __( 'Bank Transfer Matcher is missing its Composer autoloader. Install a complete plugin package.', 'wpessential-bank-transfer-matcher' );
		} else {
			require $autoload;
			\WPEssential\BankTransferMatcher\Bootstrap\Plugin::register();
		}

		if ( '' !== $message ) {
			add_action(
				'admin_notices',
				static function () use ( $message ) {
					if ( current_user_can( 'activate_plugins' ) ) {
						printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
					}
				}
			);
		}
	},
	20
);
