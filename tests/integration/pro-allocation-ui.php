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
		'No allocations match these filters', 'Ledger and audit views are read-only',
		'wbm-pro-toolbar', 'role="search"', 'id="wbm-allocation-page-size"', 'for="wbm-allocation-order"' ] as $phrase ) {
		if ( ! str_contains( $html, $phrase ) ) throw new RuntimeException( 'Allocation list missing: ' . $phrase );
	}
	// Even malformed/max-int query page numbers must not create an unbounded
	// SQL offset or fabricate a Next page for this empty synthetic result.
	$_GET['paged'] = (string) PHP_INT_MAX;
	ob_start();
	AllocationReviewPage::render();
	$bounded = ob_get_clean();
	if ( ! str_contains( $bounded, 'No allocations match these filters' ) ||
		str_contains( $bounded, '>Next</a>' ) ) {
		throw new RuntimeException( 'Allocation pagination accepted unbounded synthetic page or phantom Next.' );
	}
	if ( str_contains( $html, '<style>.wbm-finance-dialog' ) ) throw new RuntimeException( 'Finance detail dialog still emits inline layout CSS instead of scoped native stylesheet.' );
	if ( ! str_contains( $html, 'data-wbm-finance-dialog' ) ) {
		throw new RuntimeException( 'Finance proposal must support progressive dialog enhancement.' );
	}
	AllocationReviewPage::enqueue();
	$handle = 'wbm-pro-finance-dialogs';
	if ( ! wp_script_is( $handle, 'enqueued' ) ||
		! str_contains( (string) ( wp_scripts()->registered[$handle]->src ?? '' ),
			'assets/js/finance-dialogs.js' ) ) {
		throw new RuntimeException( 'Finance dialog script was not enqueued or has the wrong plugin asset path.' );
	}
	if ( ! str_contains( $html, 'wbm_pro_allocation_propose' ) ||
		! str_contains( $html, 'name="_wpnonce"' ) ) {
		throw new RuntimeException( 'Finance proposal form must be explicit and nonce protected.' );
	}
	if ( str_contains( $html, 'payment_complete' ) ||
		str_contains( $html, 'wbm_pro_complete_payment' ) ||
		str_contains( $html, 'automatic_refund' ) ) {
		throw new RuntimeException( 'Finance allocation queue must not expose payment/refund triggers.' );
	}
	echo "PASS finance allocation UI filters, nonce-protected manual proposal and no payment/refund trigger\n";
} finally {
	$_GET = $before;
}
