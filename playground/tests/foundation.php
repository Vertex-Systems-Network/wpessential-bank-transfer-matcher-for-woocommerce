<?php

require_once '/wordpress/wp-load.php';

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
	wp_set_current_user( 1 );
	do_action( 'admin_menu' );

	ob_start();
	\WPEssential\BankTransferMatcher\Bootstrap\Plugin::render_page();
	$html = ob_get_clean();

	if ( ! str_contains( $html, '<h1>Bank Transfer Matcher</h1>' ) ) {
		throw new RuntimeException( 'Admin entry point did not render.' );
	}
}

printf( "PASS %s WP=%s WooCommerce=%s PHP=%s Free=%s Pro=%s\n",
	$scenario, get_bloginfo( 'version' ), WC_VERSION, PHP_VERSION,
	$free_loaded ? 'active' : 'inactive', $pro_loaded ? 'active' : 'inactive' );
