<?php
/**
 * Free plugin startup and public integration version.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Bootstrap;

use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Rest\AccessController;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const API_VERSION = '1.0.0';

	public static function register(): void {
		add_action( 'init', array( Capabilities::class, 'install_defaults' ), 20 );
		add_action( 'rest_api_init', array( AccessController::class, 'register_routes' ) );
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		do_action( 'wpessential_bank_transfer_matcher_ready', self::API_VERSION );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ),
			__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ),
			Capabilities::VIEW,
			'wpessential-bank-transfer-matcher',
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ) . '</h1>';
		echo '<p>' . esc_html__( 'This development build provides the admin entry point. Reconciliation workflows are under development.', 'wpessential-bank-transfer-matcher' ) . '</p></div>';
	}
}
