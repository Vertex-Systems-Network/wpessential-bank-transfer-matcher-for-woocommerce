<?php
/**
 * Free plugin startup and public integration version.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Bootstrap;

use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Claims\CustomerClaims;
use WPEssential\BankTransferMatcher\Claims\AttachmentDownloads;
use WPEssential\BankTransferMatcher\Evidence\BankEvidence;
use WPEssential\BankTransferMatcher\Admin\Assets;
use WPEssential\BankTransferMatcher\Admin\Components;
use WPEssential\BankTransferMatcher\Orders\ReferenceIssuer;
use WPEssential\BankTransferMatcher\Orders\ReferencePresentation;
use WPEssential\BankTransferMatcher\Rest\AccessController;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public const API_VERSION = '1.0.0';

	public static function register(): void {
		add_action( 'init', array( Capabilities::class, 'install_defaults' ), 20 );
		add_action( 'woocommerce_checkout_order_created', array( ReferenceIssuer::class, 'issue_at_checkout' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( ReferenceIssuer::class, 'issue_at_checkout' ) );
		ReferencePresentation::register();
		CustomerClaims::register();
		AttachmentDownloads::register();
		BankEvidence::register();
		add_action( 'rest_api_init', array( AccessController::class, 'register_routes' ) );
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		do_action( 'wpessential_bank_transfer_matcher_ready', self::API_VERSION );
	}

	public static function register_menu(): void {
		$page_hook = add_submenu_page(
			'woocommerce',
			__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ),
			__( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ),
			Capabilities::VIEW,
			'wpessential-bank-transfer-matcher',
			array( self::class, 'render_page' )
		);

		if ( false !== $page_hook ) {
			Assets::register( $page_hook );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap wbm-admin">';
		Components::heading( __( 'Bank Transfer Matcher', 'wpessential-bank-transfer-matcher' ) );
		Components::panel(
			'wbm-reconciliation-title',
			__( 'Reconciliation', 'wpessential-bank-transfer-matcher' ),
			__( 'Reconciliation workflows are under development.', 'wpessential-bank-transfer-matcher' )
		);
		echo '</div>';
	}
}
