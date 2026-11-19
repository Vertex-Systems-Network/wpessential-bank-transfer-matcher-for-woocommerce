<?php

use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;
use WPEssential\BankTransferMatcherPro\Matching\GeneratedCase;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;

global $wpdb;
$administrator = get_role( 'administrator' );
if ( ! $administrator ) throw new RuntimeException( 'Administrator role is missing.' );
$administrator->add_cap( 'wbm_review_claim' );
wp_set_current_user( 1 );

$reviewer_id = wp_create_user( 'wbm-api-reviewer-' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'api-reviewer@example.test' );
if ( is_wp_error( $reviewer_id ) ) throw new RuntimeException( $reviewer_id->get_error_message() );
$reviewer = get_user_by( 'id', (int) $reviewer_id );
$reviewer->add_cap( 'wbm_review_claim' );

$repository = new ReviewCaseRepository( $wpdb );
$id = $repository->create( new GeneratedCase( 'conflict', 501, 601, 'high', [ ExplanationCode::CURRENCY_CONFLICT ] ), 1 );

$call = static function ( string $path, array $body, int $user_id = 1 ): WP_REST_Response|WP_Error {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'POST', $path );
	$request->set_body_params( $body );
	return rest_do_request( $request );
};

$response = $call( "/wpessential-wbm/v1/review-cases/$id/assign", [ 'assignee_id' => (int) $reviewer_id ] );
if ( 200 !== $response->get_status() || 'assigned' !== $response->get_data()['data']['status'] ) throw new RuntimeException( 'Secure assignment failed.' );

$response = $call( "/wpessential-wbm/v1/review-cases/$id/transition", [ 'from' => 'assigned', 'to' => 'investigating', 'reason_code' => 'evidence_reviewed' ] );
if ( 200 !== $response->get_status() || 'investigating' !== $response->get_data()['data']['status'] ) throw new RuntimeException( 'Secure transition failed.' );

$response = $call( "/wpessential-wbm/v1/review-cases/$id/priority", [ 'priority' => 'critical' ] );
if ( 200 !== $response->get_status() || 'critical' !== $response->get_data()['data']['priority'] ) throw new RuntimeException( 'Secure priority update failed.' );

$response = $call( "/wpessential-wbm/v1/review-cases/$id/comments", [ 'comment' => 'Reviewer API comment.' ] );
if ( 200 !== $response->get_status() || (int) $response->get_data()['data']['comment_id'] < 1 ) throw new RuntimeException( 'Secure comment failed.' );

$response = $call( "/wpessential-wbm/v1/review-cases/$id/transition", [ 'from' => 'investigating', 'to' => 'awaiting_approval', 'reason_code' => 'invented_reason' ] );
if ( 400 !== $response->get_status() || 'investigating' !== $repository->snapshot( $id )['status'] ) throw new RuntimeException( 'Invalid reason code changed state.' );

$subscriber_id = wp_create_user( 'wbm-api-subscriber-' . wp_generate_password( 6, false ), wp_generate_password( 20 ), 'api-subscriber@example.test' );
if ( is_wp_error( $subscriber_id ) ) throw new RuntimeException( $subscriber_id->get_error_message() );
$response = $call( "/wpessential-wbm/v1/review-cases/$id/comments", [ 'comment' => 'Forbidden.' ], (int) $subscriber_id );
if ( 403 !== $response->get_status() ) throw new RuntimeException( 'Unauthorized reviewer action was accepted.' );

echo "PASS secure reviewer API permissions, schemas, reasons and CAS actions\n";
