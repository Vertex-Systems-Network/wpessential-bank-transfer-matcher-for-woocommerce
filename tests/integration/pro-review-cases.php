<?php

use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;
use WPEssential\BankTransferMatcherPro\Matching\GeneratedCase;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;

global $wpdb;
$tables = [
	$wpdb->prefix . 'wbm_pro_review_events',
	$wpdb->prefix . 'wbm_pro_review_comments',
	$wpdb->prefix . 'wbm_pro_review_cases',
];
foreach ( $tables as $table ) $wpdb->query( "DROP TABLE IF EXISTS $table" );
delete_option( 'wpessential_wbm_pro_review_case_schema' );

$actor = get_current_user_id();
if ( $actor < 1 ) {
	$actor = 1;
	wp_set_current_user( $actor );
}
$assignee = wp_create_user( 'wbm-reviewer-' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'reviewer@example.test' );
if ( is_wp_error( $assignee ) ) throw new RuntimeException( $assignee->get_error_message() );

$repository = new ReviewCaseRepository( $wpdb );
$id = $repository->create( new GeneratedCase( 'conflict', 91, 44, 'high', [ ExplanationCode::AMOUNT_CONFLICT ] ), $actor );
$repository->assign( $id, (int) $assignee, $actor );
$repository->transition( $id, 'assigned', 'investigating', $actor, 'Review started.' );
$comment_id = $repository->add_comment( $id, $actor, 'Compared immutable bank evidence with the order.' );
$repository->set_priority( $id, 'critical', $actor );
$repository->transition( $id, 'investigating', 'awaiting_approval', $actor, 'Second approval required.' );
$repository->transition( $id, 'awaiting_approval', 'resolved', $actor, 'Mismatch documented.' );
$snapshot = $repository->snapshot( $id );
if ( 'resolved' !== $snapshot['status'] || 'critical' !== $snapshot['priority'] || (int) $assignee !== (int) $snapshot['assignee_id'] || 7 !== (int) $snapshot['version'] ) {
	throw new RuntimeException( 'Review case state, priority, assignment or version is incorrect.' );
}
try {
	$repository->transition( $id, 'investigating', 'resolved', $actor, 'Stale transition.' );
	throw new RuntimeException( 'Stale transition was accepted.' );
} catch ( RuntimeException $expected ) {
}
$repository->transition( $id, 'resolved', 'reopened', $actor, 'New evidence received.' );
$history = $repository->history( $id );
if ( 8 !== count( $history ) || 'created' !== $history[0]['event_type'] || 'transition' !== $history[7]['event_type'] ) {
	throw new RuntimeException( 'Append-only review history is incomplete.' );
}
$stored_comment = $wpdb->get_var( $wpdb->prepare( "SELECT comment_text FROM {$wpdb->prefix}wbm_pro_review_comments WHERE id = %d", $comment_id ) );
if ( 'Compared immutable bank evidence with the order.' !== $stored_comment ) throw new RuntimeException( 'Review comment was not preserved.' );
echo "PASS persisted review state, priority, assignment, comments and history\n";
