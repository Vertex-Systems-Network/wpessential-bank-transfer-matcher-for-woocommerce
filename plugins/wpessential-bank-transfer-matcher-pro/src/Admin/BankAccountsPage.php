<?php

namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class BankAccountsPage {
	private const SLUG = 'wpessential-wbm-pro-bank-accounts';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 30 );
		add_action( 'admin_post_wbm_pro_save_bank_account', array( self::class, 'save' ) );
		add_action( 'admin_post_wbm_pro_archive_bank_account', array( self::class, 'archive' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Bank accounts', 'wpessential-bank-transfer-matcher-pro' ), __( 'Matcher bank accounts', 'wpessential-bank-transfer-matcher-pro' ), Capabilities::IMPORT, self::SLUG, array( self::class, 'render' ) );
	}

	public static function process_create( array $input ): int|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_ACCOUNTS ) ) return new WP_Error( 'wbm_pro_forbidden', __( 'Bank account management permission is required.', 'wpessential-bank-transfer-matcher-pro' ), 403 );
		global $wpdb;
		try {
			return ( new BankAccountRepository( $wpdb ) )->create( self::field( $input, 'nickname' ), self::field( $input, 'bank_name' ), self::field( $input, 'currency' ), self::field( $input, 'identifier_last4' ), get_current_user_id() );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_pro_invalid_account', $error->getMessage(), 422 );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_pro_account_unavailable', __( 'Bank account metadata could not be saved.', 'wpessential-bank-transfer-matcher-pro' ), 503 );
		}
	}

	public static function process_archive( int $id ): true|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_ACCOUNTS ) ) return new WP_Error( 'wbm_pro_forbidden', __( 'Bank account management permission is required.', 'wpessential-bank-transfer-matcher-pro' ), 403 );
		global $wpdb;
		try { ( new BankAccountRepository( $wpdb ) )->archive( $id ); return true; }
		catch ( InvalidArgumentException $error ) { return new WP_Error( 'wbm_pro_account_missing', $error->getMessage(), 404 ); }
		catch ( RuntimeException $error ) { return new WP_Error( 'wbm_pro_account_unavailable', __( 'Bank account metadata could not be archived.', 'wpessential-bank-transfer-matcher-pro' ), 503 ); }
	}

	public static function save(): void {
		check_admin_referer( 'wbm_pro_save_bank_account' );
		$result = self::process_create( wp_unslash( $_POST ) );
		self::finish( $result );
	}

	public static function archive(): void {
		check_admin_referer( 'wbm_pro_archive_bank_account' );
		$result = self::process_archive( isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0 );
		self::finish( $result );
	}

	private static function finish( int|true|WP_Error $result ): void {
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => $result->get_error_data() ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) wp_die( esc_html__( 'Import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', array( 'response' => 403 ) );
		global $wpdb;
		try { $accounts = ( new BankAccountRepository( $wpdb ) )->all(); }
		catch ( RuntimeException $error ) { echo '<div class="wrap"><h1>' . esc_html__( 'Matcher bank accounts', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Bank account metadata is unavailable. Check database health and retry.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div></div>'; return; }
		$manage = current_user_can( Capabilities::MANAGE_ACCOUNTS );
		echo '<div class="wrap"><h1>' . esc_html__( 'Matcher bank accounts', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><p>' . esc_html__( 'Safe labels identify the receiving account during statement import. Full account numbers and IBANs are not stored here.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		if ( isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bank account metadata updated.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
		if ( ! $accounts ) echo '<p>' . esc_html__( 'No import bank accounts have been configured.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		else {
			echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Bank account metadata', 'wpessential-bank-transfer-matcher-pro' ) . '"><table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Nickname', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Bank', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Currency', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Safe identifier', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Status', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Actions', 'wpessential-bank-transfer-matcher-pro' ) . '</th></tr></thead><tbody>';
			foreach ( $accounts as $account ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>•••• %s</td><td>%s</td><td>', esc_html( $account['nickname'] ), esc_html( $account['bank_name'] ), esc_html( $account['currency'] ), esc_html( $account['identifier_last4'] ), esc_html( $account['status'] ) );
				if ( $manage && 'active' === $account['status'] ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_archive_bank_account"><input type="hidden" name="account_id" value="' . (int) $account['id'] . '">'; wp_nonce_field( 'wbm_pro_archive_bank_account' ); submit_button( __( 'Archive', 'wpessential-bank-transfer-matcher-pro' ), 'small', 'submit', false ); echo '</form>'; }
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( $manage ) {
			echo '<h2>' . esc_html__( 'Add safe bank account metadata', 'wpessential-bank-transfer-matcher-pro' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_save_bank_account">'; wp_nonce_field( 'wbm_pro_save_bank_account' );
			echo '<table class="form-table"><tr><th><label for="wbm-nickname">' . esc_html__( 'Nickname', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><input id="wbm-nickname" name="nickname" maxlength="80" required></td></tr><tr><th><label for="wbm-bank">' . esc_html__( 'Bank name', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><input id="wbm-bank" name="bank_name" maxlength="120" required></td></tr><tr><th><label for="wbm-currency">' . esc_html__( 'Currency', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><input id="wbm-currency" name="currency" maxlength="3" pattern="[A-Za-z]{3}" required></td></tr><tr><th><label for="wbm-last4">' . esc_html__( 'Last 2–4 characters', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><input id="wbm-last4" name="identifier_last4" minlength="2" maxlength="4" pattern="[A-Za-z0-9]{2,4}" required><p class="description">' . esc_html__( 'Do not enter a full account number or IBAN.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></td></tr></table>';
			submit_button( __( 'Add bank account metadata', 'wpessential-bank-transfer-matcher-pro' ) ); echo '</form>';
		}
		echo '</div>';
	}

	private static function field( array $input, string $key ): string { return is_string( $input[ $key ] ?? null ) ? sanitize_text_field( $input[ $key ] ) : ''; }
}
