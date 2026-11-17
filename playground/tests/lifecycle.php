<?php

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$free_slug = 'wpessential-bank-transfer-matcher';
$pro_slug  = $free_slug . '-pro';
$free_file = "$free_slug/$free_slug.php";
$pro_file  = "$pro_slug/$pro_slug.php";

if ( ! defined( 'WC_VERSION' ) ) {
	throw new RuntimeException( 'WooCommerce must remain active throughout the lifecycle test.' );
}

$state = WBM_LIFECYCLE_STATE;
$expected_free = in_array( $state, array( 'deactivate-pro', 'deactivate-free', 'reactivate-pro', 'restored' ), true );
$expected_pro = in_array( $state, array( 'deactivate-pro', 'restored' ), true );

if ( is_plugin_active( $free_file ) !== $expected_free ||
	is_plugin_active( $pro_file ) !== $expected_pro ||
	did_action( 'wpessential_bank_transfer_matcher_ready' ) !== (int) $expected_free ||
	did_action( 'wpessential_bank_transfer_matcher_pro_ready' ) !== (int) $expected_pro ) {
	throw new RuntimeException( 'Unexpected activation or bootstrap state at ' . $state );
}

switch ( $state ) {
	case 'deactivate-pro':
		deactivate_plugins( $pro_file );
		break;
	case 'deactivate-free':
		deactivate_plugins( $free_file );
		break;
	case 'reactivate-free':
	case 'reactivate-pro':
		$result = activate_plugin( 'reactivate-free' === $state ? $free_file : $pro_file );
		if ( null !== $result ) {
			throw new RuntimeException( 'Unexpected activation result at ' . $state );
		}
		break;
}
