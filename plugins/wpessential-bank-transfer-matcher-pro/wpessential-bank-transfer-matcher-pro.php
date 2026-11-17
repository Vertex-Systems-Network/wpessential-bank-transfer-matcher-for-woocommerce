<?php
/**
 * Plugin Name: WPEssential Bank Transfer Matcher Pro for WooCommerce
 * Description: Pro extension for WPEssential Bank Transfer Matcher.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce, wpessential-bank-transfer-matcher
 * Author: WPEssential
 * License: GPL-2.0-or-later
 * Text Domain: wpessential-bank-transfer-matcher-pro
 *
 * @package WPEssential\BankTransferMatcherPro
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function () {
		$message = '';
		$free = \WPEssential\BankTransferMatcher\Bootstrap\Plugin::class;
		$autoload = __DIR__ . '/vendor/autoload.php';

		if ( ! did_action( 'wpessential_bank_transfer_matcher_ready' ) ||
			! defined( $free . '::API_VERSION' ) ||
			! version_compare( $free::API_VERSION, '1.0.0', '>=' ) ||
			version_compare( $free::API_VERSION, '2.0.0', '>=' ) ) {
			$message = __( 'Bank Transfer Matcher Pro requires a compatible and active Free edition.', 'wpessential-bank-transfer-matcher-pro' );
		} elseif ( ! is_readable( $autoload ) ) {
			$message = __( 'Bank Transfer Matcher Pro is missing its Composer autoloader. Install a complete plugin package.', 'wpessential-bank-transfer-matcher-pro' );
		} else {
			require $autoload;
			\WPEssential\BankTransferMatcherPro\Bootstrap\Plugin::register();
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
	30
);
