<?php
/**
 * Native WP Pro stylesheet only for actual WooCommerce submenu pages.
 * No storefront, Free edition, order editor or unrelated wp-admin styling.
 */
namespace WPEssential\BankTransferMatcherPro\Admin;

defined( 'ABSPATH' ) || exit;

final class NativeAdminAssets {
	private const SLUGS = [
		'wpessential-wbm-pro-bank-accounts',
		'wpessential-wbm-pro-mapping-profiles',
		'wpessential-wbm-pro-import-statements',
		'wpessential-wbm-pro-bank-transactions',
		'wpessential-wbm-pro-review-queue',
		'wpessential-wbm-pro-allocations',
		'wpessential-wbm-pro-finance-reports',
		'wpessential-wbm-pro-notifications',
		'wpessential-wbm-pro-dashboard',
		'wpessential-wbm-pro-operations',
		'wpessential-wbm-pro-retention',
	];

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function is_pro_page( string $hook ): bool {
		return str_starts_with( $hook, 'woocommerce_page_' ) &&
			in_array( substr( $hook, strlen( 'woocommerce_page_' ) ),
				self::SLUGS, true );
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::is_pro_page( $hook ) ) return;
		$plugin = dirname( __DIR__, 2 ) . '/wpessential-bank-transfer-matcher-pro.php';
		wp_enqueue_style( 'wpessential-wbm-pro-admin',
			plugins_url( 'assets/admin.css', $plugin ), [], '0.1.0' );
	}
}
