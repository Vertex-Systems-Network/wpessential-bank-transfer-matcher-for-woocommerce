<?php

require_once '/wordpress/wp-load.php';

$scenario = WBM_VERSION_SCENARIO;
$woo_incompatible = 'woo-outdated' === $scenario;
$slug = 'wpessential-bank-transfer-matcher' . ( $woo_incompatible ? '' : '-pro' );
$file = WP_PLUGIN_DIR . "/$slug/$slug.php";

if ( ! defined( 'WC_VERSION' ) ||
	did_action( 'wpessential_bank_transfer_matcher_ready' ) !== (int) ! $woo_incompatible ||
	did_action( 'wpessential_bank_transfer_matcher_pro_ready' ) ) {
	throw new RuntimeException( 'An incompatible dependency initialized the dependent plugin.' );
}

if ( $woo_incompatible && '10.7.0' !== WC_VERSION ) {
	throw new RuntimeException( 'The outdated WooCommerce version fixture did not load.' );
}
if ( ! $woo_incompatible ) {
	$version = \WPEssential\BankTransferMatcher\Bootstrap\Plugin::API_VERSION;
	if ( $version !== ( 'free-outdated' === $scenario ? '0.9.0' : '2.0.0' ) ) {
		throw new RuntimeException( 'The incompatible Free API fixture did not load.' );
	}
}

wp_set_current_user( 1 );
ob_start();
foreach ( $GLOBALS['wp_filter']['admin_notices']->callbacks[10] ?? array() as $callback ) {
	$function = $callback['function'];
	if ( $function instanceof Closure && $file === ( new ReflectionFunction( $function ) )->getFileName() ) {
		$function();
	}
}
$notice = ob_get_clean();
$expected = $woo_incompatible ? 'requires an active WooCommerce 10.8 or later' : 'requires a compatible and active Free edition';
if ( ! str_contains( $notice, $expected ) ) {
	throw new RuntimeException( 'The incompatible version did not produce an actionable notice.' );
}
