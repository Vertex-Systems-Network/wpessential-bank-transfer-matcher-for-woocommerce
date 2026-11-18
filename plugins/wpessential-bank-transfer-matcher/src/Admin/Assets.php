<?php
/**
 * Assets scoped to a registered Matcher page.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {
	public static function register( string $page_hook ): void {
		add_action(
			'admin_enqueue_scripts',
			static function ( string $current_hook ) use ( $page_hook ): void {
				if ( $page_hook === $current_hook ) {
					$plugin = dirname( __DIR__, 2 ) . '/wpessential-bank-transfer-matcher.php';
					wp_enqueue_style( 'wpessential-wbm-admin', plugins_url( 'assets/admin.css', $plugin ), array(), '1.0.0' );
					wp_enqueue_script( 'wpessential-wbm-admin', plugins_url( 'assets/admin.js', $plugin ), array(), '1.0.0', true );
					wp_localize_script( 'wpessential-wbm-admin', 'wpessentialWbmAdmin', array(
						'root' => esc_url_raw( rest_url() ), 'nonce' => wp_create_nonce( 'wp_rest' ),
						'processing' => __( 'Processing…', 'wpessential-bank-transfer-matcher' ),
						'saved' => __( 'Saved. Refreshing the reconciliation state…', 'wpessential-bank-transfer-matcher' ),
						'failed' => __( 'The action could not be completed. Review the fields and retry.', 'wpessential-bank-transfer-matcher' ),
					) );
				}
			}
		);
	}
}
