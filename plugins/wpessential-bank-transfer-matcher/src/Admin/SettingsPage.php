<?php

namespace WPEssential\BankTransferMatcher\Admin;

use WPEssential\BankTransferMatcher\Access\Capabilities;
use WPEssential\BankTransferMatcher\Claims\ClaimAttachment;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private const SLUG = 'wpessential-bank-transfer-matcher';

	public static function register(): void {
		add_action( 'admin_post_wbm_save_free_settings', array( self::class, 'save' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wbm_save_free_settings' );
		$uploads = isset( $_POST['wbm_claim_uploads'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['wbm_claim_uploads'] ) );
		update_option( 'wpessential_wbm_claim_uploads', $uploads ? 'yes' : 'no' );
		$role = get_role( 'shop_manager' );
		if ( $role ) {
			foreach ( array( Capabilities::VIEW, Capabilities::REVIEW_CLAIM, Capabilities::APPROVE ) as $cap ) {
				if ( isset( $_POST['wbm_shop_caps'] ) && is_array( $_POST['wbm_shop_caps'] ) && in_array( $cap, array_map( 'sanitize_key', wp_unslash( $_POST['wbm_shop_caps'] ) ), true ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wbm_view' => 'settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view these settings.', 'wpessential-bank-transfer-matcher' ), '', array( 'response' => 403 ) );
		}
		$role = get_role( 'shop_manager' );
		$editable = current_user_can( 'manage_options' );
		echo '<div class="wrap wbm-admin"><h1>' . esc_html__( 'Bank Transfer Matcher settings', 'wpessential-bank-transfer-matcher' ) . '</h1>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ), esc_html__( 'Back to orders', 'wpessential-bank-transfer-matcher' ) );
		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wpessential-bank-transfer-matcher' ) . '</p></div>';
		}
		if ( $editable ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_save_free_settings">';
			wp_nonce_field( 'wbm_save_free_settings' );
		}
		echo '<h2>' . esc_html__( 'Customer claims', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		self::checkbox( 'wbm_claim_uploads', 'yes', ClaimAttachment::enabled(), __( 'Allow private receipt uploads', 'wpessential-bank-transfer-matcher' ), $editable );
		echo '<p>' . esc_html__( 'PDF, JPG and PNG receipts are limited to 2 MB, stored privately, and removed after 30 days. A customer claim never confirms payment automatically.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		echo '<h2>' . esc_html__( 'Shop manager access', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		foreach ( array( Capabilities::VIEW => __( 'View orders and settings', 'wpessential-bank-transfer-matcher' ), Capabilities::REVIEW_CLAIM => __( 'Review customer claims', 'wpessential-bank-transfer-matcher' ), Capabilities::APPROVE => __( 'Record and approve bank evidence', 'wpessential-bank-transfer-matcher' ) ) as $cap => $label ) {
			self::checkbox( 'wbm_shop_caps[]', $cap, $role && $role->has_cap( $cap ), $label, $editable );
		}
		echo '<p>' . esc_html__( 'Only administrators can change these grants. Administrator access is retained.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		echo '<h2>' . esc_html__( 'Data and privacy', 'wpessential-bank-transfer-matcher' ) . '</h2>';
		echo '<p>' . esc_html__( 'Customer claim data, bank evidence, allocations and review history are stored with the order for reconciliation. Receipt bytes expire after 30 days. Follow your store retention policy for financial records and handle customer data requests alongside the WooCommerce order.', 'wpessential-bank-transfer-matcher' ) . '</p>';
		if ( $editable ) {
			submit_button( __( 'Save settings', 'wpessential-bank-transfer-matcher' ) );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function checkbox( string $name, string $value, bool $checked, string $label, bool $editable ): void {
		printf( '<p><label><input type="checkbox" name="%s" value="%s" %s %s> %s</label></p>', esc_attr( $name ), esc_attr( $value ), checked( $checked, true, false ), disabled( ! $editable, true, false ), esc_html( $label ) );
	}
}
