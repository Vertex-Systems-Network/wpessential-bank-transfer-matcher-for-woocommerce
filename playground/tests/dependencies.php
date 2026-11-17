<?php

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$slug = WBM_DEPENDENCY_PLUGIN;
$file = "$slug/$slug.php";
$activation = activate_plugin( $file );

if ( ! is_wp_error( $activation ) || ! in_array( 'plugin_missing_dependencies', $activation->get_error_codes(), true ) ) {
	fwrite( STDERR, 'Unexpected activation result: ' . ( is_wp_error( $activation ) ? implode( ', ', $activation->get_error_codes() ) : get_debug_type( $activation ) ) . "\n" );
	exit( 1 );
}

if ( is_plugin_active( $file ) ) {
	throw new RuntimeException( 'The plugin became active with a missing dependency.' );
}

// Simulate loading an already-installed edition after a dependency disappeared.
require WP_PLUGIN_DIR . '/' . $file;
$priority = 'wpessential-bank-transfer-matcher' === $slug ? 20 : 30;
foreach ( $GLOBALS['wp_filter']['plugins_loaded']->callbacks[ $priority ] as $callback ) {
	$function = $callback['function'];
	if ( $function instanceof Closure && WP_PLUGIN_DIR . '/' . $file === ( new ReflectionFunction( $function ) )->getFileName() ) {
		$function();
	}
}

if ( did_action( 'wpessential_bank_transfer_matcher_ready' ) || did_action( 'wpessential_bank_transfer_matcher_pro_ready' ) ) {
	throw new RuntimeException( 'Plugin initialized without its dependency.' );
}

wp_set_current_user( 1 );
ob_start();
foreach ( $GLOBALS['wp_filter']['admin_notices']->callbacks[10] ?? [] as $callback ) {
	$function = $callback['function'];
	if ( $function instanceof Closure && WP_PLUGIN_DIR . '/' . $file === ( new ReflectionFunction( $function ) )->getFileName() ) {
		$function();
	}
}
$notice = ob_get_clean();
$expected = 'wpessential-bank-transfer-matcher' === $slug
	? 'requires an active WooCommerce'
	: 'requires a compatible and active Free edition';
if ( ! str_contains( $notice, $expected ) ) {
	throw new RuntimeException( 'Activation administrator did not receive the dependency notice.' );
}
