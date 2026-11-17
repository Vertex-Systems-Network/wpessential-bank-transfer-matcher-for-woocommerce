<?php
/**
 * Permission-protected REST description of the current user's capabilities.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Rest;

use WPEssential\BankTransferMatcher\Access\Capabilities;
use WP_Error;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class AccessController {
	public static function register_routes(): void {
		register_rest_route(
			'wpessential-wbm/v1',
			'/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( self::class, 'can_view' ),
				'callback'            => array( self::class, 'get_capabilities' ),
			)
		);
	}

	public static function can_view(): true|WP_Error {
		if ( current_user_can( Capabilities::VIEW ) ) {
			return true;
		}

		return new WP_Error(
			'wbm_forbidden',
			__( 'You do not have permission to access Bank Transfer Matcher.', 'wpessential-bank-transfer-matcher' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public static function get_capabilities(): array {
		return array(
			Capabilities::VIEW         => current_user_can( Capabilities::VIEW ),
			Capabilities::REVIEW_CLAIM => current_user_can( Capabilities::REVIEW_CLAIM ),
			Capabilities::APPROVE      => current_user_can( Capabilities::APPROVE ),
		);
	}
}
