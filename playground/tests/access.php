<?php

require_once '/wordpress/wp-load.php';

use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Bootstrap\Plugin;

$capability_map = array( 'wbm_view' => true, 'wbm_review_claim' => true, 'wbm_approve' => true );
$viewer_map     = array( 'wbm_view' => true, 'wbm_review_claim' => false, 'wbm_approve' => false );

add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title, $args ) {
		throw new RuntimeException( $message, $args['response'] );
	};
} );

add_role( 'wbm_test_viewer', 'Matcher test viewer', array( 'read' => true, 'wbm_view' => true ) );

foreach ( array( 'administrator' => 200, 'shop_manager' => 200, 'customer' => 403, 'wbm_test_viewer' => 200, 'anonymous' => 401 ) as $role => $status ) {
	$id = 'anonymous' === $role ? 0 : wp_insert_user(
		array( 'user_login' => 'wbm-test-' . $role, 'user_pass' => wp_generate_password(), 'role' => $role )
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	wp_set_current_user( $id );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/wpessential-wbm/v1/capabilities' ) );
	if ( $status !== $response->get_status() ) {
		throw new RuntimeException( 'Unexpected REST status for ' . $role );
	}
	if ( 200 === $status && $response->get_data() !== ( 'wbm_test_viewer' === $role ? $viewer_map : $capability_map ) ) {
		throw new RuntimeException( 'Unexpected capability map for ' . $role );
	}
	if ( 200 !== $status && 'wbm_forbidden' !== $response->get_data()['code'] ) {
		throw new RuntimeException( 'Unexpected REST denial for ' . $role );
	}

	ob_start();
	try {
		Plugin::render_page();
		if ( 200 !== $status || ! str_contains( ob_get_contents(), '<h1>Bank Transfer Matcher</h1>' ) ) {
			throw new RuntimeException( 'Unexpected admin page access for ' . $role );
		}
	} catch ( RuntimeException $error ) {
		if ( 200 === $status || 403 !== $error->getCode() ) {
			throw $error;
		}
	} finally {
		ob_end_clean();
	}
}

$manager = get_role( 'shop_manager' );
$manager->remove_cap( 'wbm_approve' );
Capabilities::install_defaults();
if ( $manager->has_cap( 'wbm_approve' ) ) {
	throw new RuntimeException( 'A removed grant was restored after provisioning.' );
}
