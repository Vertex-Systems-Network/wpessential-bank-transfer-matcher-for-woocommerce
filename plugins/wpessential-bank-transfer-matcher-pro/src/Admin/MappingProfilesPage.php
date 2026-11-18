<?php

namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Import\MappingProfileRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MappingProfilesPage {
	private const SLUG = 'wpessential-wbm-pro-mapping-profiles';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 31 );
		add_action( 'admin_post_wbm_pro_save_mapping_profile', array( self::class, 'save' ) );
	}

	public static function menu(): void { add_submenu_page( 'woocommerce', __( 'Statement mappings', 'wpessential-bank-transfer-matcher-pro' ), __( 'Statement mappings', 'wpessential-bank-transfer-matcher-pro' ), Capabilities::IMPORT, self::SLUG, array( self::class, 'render' ) ); }

	public static function process_create( array $input ): MappingProfile|WP_Error {
		if ( ! current_user_can( Capabilities::IMPORT ) ) return new WP_Error( 'wbm_pro_forbidden', __( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), 403 );
		$data = array();
		foreach ( array( 'name', 'delimiter', 'date_header', 'date_format', 'amount_header', 'decimal_separator', 'thousands_separator', 'currency_header', 'fixed_currency', 'reference_header', 'description_header' ) as $key ) $data[ $key ] = is_string( $input[ $key ] ?? null ) ? sanitize_text_field( $input[ $key ] ) : '';
		$data['delimiter'] = 'tab' === $data['delimiter'] ? "\t" : $data['delimiter'];
		$data['thousands_separator'] = 'none' === $data['thousands_separator'] ? '' : ( 'space' === $data['thousands_separator'] ? ' ' : $data['thousands_separator'] );
		$data['exponent'] = isset( $input['exponent'] ) ? (int) $input['exponent'] : -1;
		try { return ( new MappingProfileRepository() )->create( $data ); }
		catch ( InvalidArgumentException $error ) { return new WP_Error( 'wbm_pro_invalid_mapping', $error->getMessage(), 422 ); }
		catch ( RuntimeException $error ) { return new WP_Error( 'wbm_pro_mapping_unavailable', __( 'The mapping profile could not be saved.', 'wpessential-bank-transfer-matcher-pro' ), 503 ); }
	}

	public static function save(): void {
		check_admin_referer( 'wbm_pro_save_mapping_profile' ); $result = self::process_create( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => $result->get_error_data() ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) wp_die( esc_html__( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', array( 'response' => 403 ) );
		try { $profiles = ( new MappingProfileRepository() )->all(); }
		catch ( RuntimeException $error ) { echo '<div class="wrap"><h1>' . esc_html__( 'Statement mappings', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Mapping profiles are unavailable.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div></div>'; return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Statement mappings', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><p>' . esc_html__( 'Map one known bank CSV format at a time. Dates and amounts that do not match the selected format are rejected instead of guessed.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		if ( isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mapping profile saved.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
		if ( ! $profiles ) echo '<p>' . esc_html__( 'No statement mapping profiles have been configured.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		else { echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Name', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Date', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Amount locale', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Currency', 'wpessential-bank-transfer-matcher-pro' ) . '</th></tr></thead><tbody>'; foreach ( $profiles as $profile ) printf( '<tr><td>%s</td><td>%s → %s</td><td>%s / %s · %d</td><td>%s</td></tr>', esc_html( $profile->name ), esc_html( $profile->date_header ), esc_html( $profile->date_format ), esc_html( $profile->decimal_separator ), esc_html( '' === $profile->thousands_separator ? 'none' : $profile->thousands_separator ), $profile->exponent, esc_html( '' !== $profile->currency_header ? $profile->currency_header : $profile->fixed_currency ) ); echo '</tbody></table>'; }
		echo '<h2>' . esc_html__( 'Add mapping profile', 'wpessential-bank-transfer-matcher-pro' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_save_mapping_profile">'; wp_nonce_field( 'wbm_pro_save_mapping_profile' );
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Profile name', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input name="name" maxlength="80" required></td></tr><tr><th>' . esc_html__( 'CSV delimiter', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><select name="delimiter"><option value=",">' . esc_html__( 'Comma', 'wpessential-bank-transfer-matcher-pro' ) . '</option><option value=";">' . esc_html__( 'Semicolon', 'wpessential-bank-transfer-matcher-pro' ) . '</option><option value="tab">' . esc_html__( 'Tab', 'wpessential-bank-transfer-matcher-pro' ) . '</option></select></td></tr><tr><th>' . esc_html__( 'Date column / format', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input name="date_header" placeholder="Date" maxlength="100" required> <select name="date_format"><option>Y-m-d</option><option>d/m/Y</option><option>m/d/Y</option><option>d.m.Y</option></select></td></tr><tr><th>' . esc_html__( 'Amount column', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input name="amount_header" placeholder="Amount" maxlength="100" required></td></tr><tr><th>' . esc_html__( 'Decimal / thousands', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><select name="decimal_separator"><option value=".">.</option><option value=",">,</option></select> <select name="thousands_separator"><option value="none">' . esc_html__( 'None', 'wpessential-bank-transfer-matcher-pro' ) . '</option><option value=",">,</option><option value=".">.</option><option value="space">' . esc_html__( 'Space', 'wpessential-bank-transfer-matcher-pro' ) . '</option></select></td></tr><tr><th>' . esc_html__( 'Minor-unit decimals', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input type="number" name="exponent" min="0" max="6" value="2" required></td></tr><tr><th>' . esc_html__( 'Currency column or fixed ISO currency', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input name="currency_header" placeholder="Currency" maxlength="100"> <input name="fixed_currency" placeholder="USD" maxlength="3"></td></tr><tr><th>' . esc_html__( 'Reference / description columns', 'wpessential-bank-transfer-matcher-pro' ) . '</th><td><input name="reference_header" placeholder="Reference" maxlength="100"> <input name="description_header" placeholder="Description" maxlength="100"></td></tr></table>';
		submit_button( __( 'Save mapping profile', 'wpessential-bank-transfer-matcher-pro' ) ); echo '</form></div>';
	}
}
