<?php
use WPEssential\BankTransferMatcherPro\Admin\AllocationReviewPage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Finance UI check is allowed in disposable wbm_ci only.' );
}
$administrator = get_user_by( 'login', 'wbm_ci' );
if ( ! $administrator ) throw new RuntimeException( 'Finance UI administrator is missing.' );
wp_set_current_user( $administrator->ID );
$before = $_GET;
try {
	$_GET = [ 'page' => 'wpessential-wbm-pro-allocations', 'state' => 'reversed',
		'order_id' => '999999' ];
	ob_start();
	AllocationReviewPage::render();
	$html = ob_get_clean();
	foreach ( [ 'Bank allocation ledger', 'All allocation states', 'Filter allocations',
		'No allocations match these filters', 'Read-only finance evidence' ] as $phrase ) {
		if ( ! str_contains( $html, $phrase ) ) throw new RuntimeException( 'Allocation list missing: ' . $phrase );
	}
	if ( str_contains( $html, 'admin-post.php' ) ) {
		throw new RuntimeException( 'Read-only allocation queue must not expose mutation actions.' );
	}
	echo "PASS finance allocation UI filter empty state and read-only controls\n";
} finally {
	$_GET = $before;
}
