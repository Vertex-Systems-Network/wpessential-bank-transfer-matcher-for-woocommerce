<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Privacy\FinanceRetentionPreferences;
use WPEssential\BankTransferMatcherPro\Privacy\FinanceRetentionService;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit opt-in merchant retention controls; never automatically delete
 * financial ledger/evidence or order/customer/claim/review/audit decisions.
 */
final class FinanceRetentionPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-retention';
	private const SAVE = 'wbm_pro_retention_save';
	private const SWEEP = 'wbm_pro_retention_sweep';

	private const LABELS = [
		'diagnostic_audit_days' => 'Redacted support-export audit metadata',
		'notification_audit_days' => 'Email delivery audit metadata',
		'recovery_audit_days' => 'Failed-job recovery audit metadata',
		'finance_export_audit_days' => 'Financial CSV download audit metadata',
		'private_source_days' => 'Completed/cancelled source CSV files',
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 40 );
		add_action( 'admin_post_' . self::SAVE, [ self::class, 'save' ] );
		add_action( 'admin_post_' . self::SWEEP, [ self::class, 'sweep' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Bank data retention', self::DOMAIN ),
			__( 'Bank data retention', self::DOMAIN ),
			ProCaps::MANAGE_ACCOUNTS, self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		self::authorize();
		echo '<div class="wrap"><h1>' .
			esc_html__( 'Bank data retention', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Automatic cleanup is OFF by default. Money movements, imported transaction rows, financial allocations, customer claims and WooCommerce order records are never deleted by this tool. Keep your accounting audit evidence under your local legal retention requirements.', self::DOMAIN ) . '</p>';
		echo '<p>' . esc_html__( 'Private report download bytes have their own strict 2-hour expiry. This cleanup sweeps expired buffers and, only with explicit merchant consent, old operational audit metadata and completed/cancelled private statement source files. Failed/unfinished imports remain intact for manual recovery. Backups outside WordPress are not modified.', self::DOMAIN ) . '</p>';
		$service = new FinanceRetentionService( $GLOBALS['wpdb'] );
		try {
			$policy = $service->settings();
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Retention policy storage is inconsistent. Cleanup is disabled.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		if ( isset( $_GET['saved'] ) || isset( $_GET['swept'] ) ) {
			echo '<div class="notice notice-success"><p>' .
				esc_html__( isset( $_GET['swept'] ) ?
					'An audited, bounded privacy cleanup pass completed. Review retention audit history.' :
					'The finance retention policy was saved.', self::DOMAIN ) .
				'</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';
		wp_nonce_field( self::SAVE );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::LABELS as $name => $label ) {
			$allowed = FinanceRetentionPreferences::windows()[$name];
			echo '<tr><th scope="row"><label for="wbm-retain-' . esc_attr( $name ) .
				'">' . esc_html( $label ) . '</label></th><td>';
			echo '<select name="' . esc_attr( $name ) . '" id="wbm-retain-' .
				esc_attr( $name ) . '">';
			foreach ( $allowed as $days ) {
				$text = 0 === $days ? __( 'Never delete automatically', self::DOMAIN ) :
					sprintf( __( '%d days', self::DOMAIN ), $days );
				echo '<option value="' . esc_attr( (string) $days ) . '" ' .
					selected( $policy[$name], $days, false ) . '>' .
					esc_html( $text ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="automatic_cleanup" value="1" ' .
			checked( $policy['automatic_cleanup'], true, false ) .
			'> ' . esc_html__( 'Enable an explicitly approved daily bounded cleanup', self::DOMAIN ) .
			'</label></p>';
		echo '<p><label><input type="checkbox" name="consent_auto" value="1"> ' .
			esc_html__( 'I understand enabling automatic cleanup permanently removes old selected operational metadata and eligible private source files.', self::DOMAIN ) .
			'</label></p>';
		submit_button( __( 'Save retention policy', self::DOMAIN ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Run one bounded cleanup pass', self::DOMAIN ) . '</h2>';
		echo '<p>' . esc_html__( 'Manual cleanup can run while recurring cleanup is OFF. It uses the currently saved policy, processes at most 25 records per source and records counts in a private audit. Only previously expired private CSV buffers and explicitly selected dated metadata/source files are eligible.', self::DOMAIN ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SWEEP ) . '">';
		wp_nonce_field( self::SWEEP );
		echo '<label><input required type="checkbox" name="confirm" value="1"> ' .
			esc_html__( 'I approve this one cleanup pass and understand it cannot restore deleted source files or metadata.', self::DOMAIN ) .
			'</label>';
		submit_button( __( 'Run audited cleanup now', self::DOMAIN ), 'secondary' );
		echo '</form>';
		echo '<p class="description">' .
			esc_html__( 'Financial statements and claim-related evidence are subject to merchant accounting retention, backup and privacy obligations. This plugin does not manage external cloud backups.', self::DOMAIN ) .
			'</p></div>';
	}

	public static function save(): void {
		self::post_and_authorize( self::SAVE );
		$input = wp_unslash( $_POST );
		if ( ! is_array( $input ) ) {
			wp_die( esc_html__( 'Invalid retention settings.', self::DOMAIN ), '', [ 'response' => 422 ] );
		}
		$preferences = [ 'automatic_cleanup' =>
			isset( $input['automatic_cleanup'] ) ? $input['automatic_cleanup'] : '0' ];
		foreach ( FinanceRetentionPreferences::windows() as $field => $allowed ) {
			$preferences[$field] = $input[$field] ?? null;
		}
		if ( $preferences['automatic_cleanup'] === '1' &&
			( $input['consent_auto'] ?? null ) !== '1' ) {
			wp_die( esc_html__( 'Explicit automatic retention consent is required.', self::DOMAIN ),
				'', [ 'response' => 422 ] );
		}
		try {
			( new FinanceRetentionService( $GLOBALS['wpdb'] ) )->save(
				$preferences, get_current_user_id(), self::posted_nonce()
			);
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Retention options could not be validated or saved.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::redirect( 'saved' );
	}

	public static function sweep(): void {
		self::post_and_authorize( self::SWEEP );
		$confirm = wp_unslash( $_POST['confirm'] ?? null );
		if ( '1' !== $confirm ) {
			wp_die( esc_html__( 'An explicit cleanup acknowledgment is required.', self::DOMAIN ),
				'', [ 'response' => 422 ] );
		}
		try {
			( new FinanceRetentionService( $GLOBALS['wpdb'] ) )->sweep(
				get_current_user_id(), self::posted_nonce(), true
			);
		} catch ( RuntimeException|InvalidArgumentException $error ) {
			wp_die( esc_html__( 'Retention cleanup failed closed; inspect private operation logs.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::redirect( 'swept' );
	}

	private static function posted_nonce(): string {
		$value = wp_unslash( $_POST['_wpnonce'] ?? null );
		return is_string( $value ) ? $value : '';
	}

	private static function post_and_authorize( string $action ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( esc_html__( 'Retention controls require POST.', self::DOMAIN ),
				'', [ 'response' => 405 ] );
		}
		self::authorize();
		check_admin_referer( $action );
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ) {
			wp_die( esc_html__( 'Bank-account manager permission is required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
	}

	private static function redirect( string $event ): void {
		wp_safe_redirect( add_query_arg( [
			'page' => self::SLUG, $event => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}
