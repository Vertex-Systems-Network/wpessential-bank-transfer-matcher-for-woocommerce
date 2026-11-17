<?php

require_once '/wordpress/wp-load.php';

set_exception_handler( static function ( Throwable $error ): void {
	echo $error->getMessage() . PHP_EOL . $error->getTraceAsString();
	exit( 1 );
} );

if ( ! defined( 'WC_VERSION' ) ) {
	throw new RuntimeException( 'WooCommerce did not load.' );
}

$scenario = WBM_SCENARIO;
$free_loaded = 1 === did_action( 'wpessential_bank_transfer_matcher_ready' );
$pro_loaded  = 1 === did_action( 'wpessential_bank_transfer_matcher_pro_ready' );

if ( $free_loaded !== in_array( $scenario, array( 'free', 'pro' ), true ) || $pro_loaded !== ( 'pro' === $scenario ) ) {
	throw new RuntimeException( 'Unexpected plugin bootstrap state.' );
}

if ( $free_loaded ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	wp_set_current_user( 1 );
	do_action( 'admin_menu' );

	$style = 'wpessential-wbm-admin';
	set_current_screen( 'dashboard' );
	do_action( 'admin_enqueue_scripts', 'dashboard' );
	if ( wp_style_is( $style, 'enqueued' ) ) {
		throw new RuntimeException( 'Matcher stylesheet loaded on an unrelated admin page.' );
	}

	$hook = get_plugin_page_hookname( 'wpessential-bank-transfer-matcher', 'woocommerce' );
	set_current_screen( $hook );
	do_action( 'admin_enqueue_scripts', $hook );
	$asset_path = 'wpessential-bank-transfer-matcher/assets/admin.css';
	if ( ! wp_style_is( $style, 'enqueued' ) ||
		! str_ends_with( wp_styles()->registered[ $style ]->src, '/' . $asset_path ) ||
		! is_readable( WP_PLUGIN_DIR . '/' . $asset_path ) ) {
		throw new RuntimeException( 'Matcher stylesheet is missing or did not load on its admin page.' );
	}

	ob_start();
	\WPEssential\BankTransferMatcher\Bootstrap\Plugin::render_page();
	$html = ob_get_clean();

	if ( ! str_contains( $html, '<h1>Bank Transfer Matcher</h1>' ) ||
		! str_contains( $html, 'aria-labelledby="wbm-reconciliation-title"' ) ||
		! str_contains( $html, '<h2 id="wbm-reconciliation-title">Reconciliation</h2>' ) ) {
		throw new RuntimeException( 'Admin entry point did not render.' );
	}
}

printf( "PASS %s WP=%s WooCommerce=%s PHP=%s Free=%s Pro=%s\n",
	$scenario, get_bloginfo( 'version' ), WC_VERSION, PHP_VERSION,
	$free_loaded ? 'active' : 'inactive', $pro_loaded ? 'active' : 'inactive' );
