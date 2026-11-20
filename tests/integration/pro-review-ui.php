<?php

use WPEssential\BankTransferMatcherPro\Admin\ReviewQueuePage;

global $wpdb;
$administrator = get_role( 'administrator' );
if ( ! $administrator ) throw new RuntimeException( 'Administrator role is missing.' );
$administrator->add_cap( 'wbm_view' );
$administrator->add_cap( 'wbm_review_claim' );
wp_set_current_user( 1 );
if ( ! current_user_can( 'wbm_view' ) ) throw new RuntimeException( 'Authorized review fixture is missing wbm_view.' );
$case_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wbm_pro_review_cases ORDER BY id DESC LIMIT 1" );
if ( $case_id < 1 ) throw new RuntimeException( 'Review UI fixture is missing.' );

$_GET = [ 'page' => 'wpessential-wbm-pro-review-queue' ];
ob_start();
ReviewQueuePage::render();
$queue = ob_get_clean();
foreach ( [ 'Bank review queue', 'unverified', 'Open workspace', 'reopened', 'critical' ] as $needle ) {
	if ( ! str_contains( $queue, $needle ) ) throw new RuntimeException( "Review queue is missing $needle." );
}

$_GET = [ 'page' => 'wpessential-wbm-pro-review-queue', 'case_id' => (string) $case_id ];
ob_start();
ReviewQueuePage::render();
$workspace = ob_get_clean();
foreach ( [ 'Bank evidence', 'Order comparison', 'Decision context', 'Comments', 'Audit history', 'Reviewer actions', 'Update state', 'Add comment' ] as $needle ) {
	if ( ! str_contains( $workspace, $needle ) ) throw new RuntimeException( "Review workspace is missing $needle." );
}

$_GET = [ 'page' => 'wpessential-wbm-pro-review-queue', 'status' => 'resolved' ];
ob_start();
ReviewQueuePage::render();
$empty = ob_get_clean();
if ( ! str_contains( $empty, 'No review cases match these filters.' ) ) throw new RuntimeException( 'Review queue empty state is missing.' );

echo "PASS review queue, filters, empty state and evidence workspace UI\n";
