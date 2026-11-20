<?php

namespace WPEssential\BankTransferMatcherPro\Api;

use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;
use WPEssential\BankTransferMatcherPro\Matching\ReviewReasonCode;

defined( 'ABSPATH' ) || exit;

final class ReviewCaseController {
	private const NAMESPACE = 'wpessential-wbm/v1';
	private const CAPABILITY = 'wbm_review_claim';

	public static function register(): void { add_action( 'rest_api_init', [ self::class, 'routes' ] ); }

	public static function routes(): void {
		$base = '/review-cases/(?P<id>[1-9][0-9]*)';
		register_rest_route( self::NAMESPACE, $base . '/transition', [
			'methods' => 'POST',
			'callback' => [ self::class, 'transition' ],
			'permission_callback' => [ self::class, 'permission' ],
			'args' => [
				'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
				'from' => [ 'type' => 'string', 'required' => true ],
				'to' => [ 'type' => 'string', 'required' => true ],
				'reason_code' => [ 'type' => 'string', 'required' => true ],
			],
		] );
		register_rest_route( self::NAMESPACE, $base . '/assign', [
			'methods' => 'POST',
			'callback' => [ self::class, 'assign' ],
			'permission_callback' => [ self::class, 'permission' ],
			'args' => [
				'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
				'assignee_id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
			],
		] );
		register_rest_route( self::NAMESPACE, $base . '/priority', [
			'methods' => 'POST',
			'callback' => [ self::class, 'priority' ],
			'permission_callback' => [ self::class, 'permission' ],
			'args' => [
				'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
				'priority' => [ 'type' => 'string', 'enum' => [ 'normal', 'high', 'critical' ], 'required' => true ],
			],
		] );
		register_rest_route( self::NAMESPACE, $base . '/comments', [
			'methods' => 'POST',
			'callback' => [ self::class, 'comment' ],
			'permission_callback' => [ self::class, 'permission' ],
			'args' => [
				'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true ],
				'comment' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'required' => true ],
			],
		] );
	}

	public static function permission(): bool|WP_Error {
		if ( current_user_can( self::CAPABILITY ) ) return true;
		return new WP_Error( 'wbm_review_forbidden', __( 'Review permission is required.', 'wpessential-bank-transfer-matcher-pro' ), [ 'status' => 403 ] );
	}

	public static function transition( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::execute( static function ( ReviewCaseRepository $repository ) use ( $request ): array {
			$reason = ReviewReasonCode::parse( (string) $request['reason_code'] );
			$repository->transition( (int) $request['id'], sanitize_key( (string) $request['from'] ), sanitize_key( (string) $request['to'] ), get_current_user_id(), $reason->value );
			return $repository->snapshot( (int) $request['id'] );
		} );
	}

	public static function assign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::execute( static function ( ReviewCaseRepository $repository ) use ( $request ): array {
			$assignee = get_user_by( 'id', (int) $request['assignee_id'] );
			if ( ! $assignee || ! user_can( $assignee, self::CAPABILITY ) ) throw new InvalidArgumentException( 'Assignee must be an authorized reviewer.' );
			$repository->assign( (int) $request['id'], (int) $request['assignee_id'], get_current_user_id() );
			return $repository->snapshot( (int) $request['id'] );
		} );
	}

	public static function priority( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::execute( static function ( ReviewCaseRepository $repository ) use ( $request ): array {
			$repository->set_priority( (int) $request['id'], sanitize_key( (string) $request['priority'] ), get_current_user_id() );
			return $repository->snapshot( (int) $request['id'] );
		} );
	}

	public static function comment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::execute( static function ( ReviewCaseRepository $repository ) use ( $request ): array {
			$comment_id = $repository->add_comment( (int) $request['id'], get_current_user_id(), (string) $request['comment'] );
			return [ 'case_id' => (int) $request['id'], 'comment_id' => $comment_id ];
		} );
	}

	/** @param callable(ReviewCaseRepository): array<string, mixed> $operation */
	private static function execute( callable $operation ): WP_REST_Response|WP_Error {
		try {
			$result = $operation( new ReviewCaseRepository( $GLOBALS['wpdb'] ) );
			return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_review_invalid', $error->getMessage(), [ 'status' => 400 ] );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_review_conflict', $error->getMessage(), [ 'status' => 409 ] );
		}
	}
}
