<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportCsvExportService;

defined( 'ABSPATH' ) || exit;

/**
 * Finance-only WordPress POST download; no public REST, AJAX, cron or
 * customer endpoint and no handler that marks money cleared or orders paid.
 */
final class ReportExportsPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-finance-reports';
	private const ACTION = 'wbm_pro_export_report';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 36 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'download' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Finance reports', self::DOMAIN ),
			__( 'Finance reports', self::DOMAIN ), ProCaps::IMPORT,
			self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		self::authorize();
		echo '<div class="wrap"><h1>' . esc_html__( 'Finance reports', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only, independently audited CSV exports. Each download contains at most 500 rows (one page, not an entire bank ledger). All bank references, account numbers, customer information and statement filenames are excluded. Reports never authorize payment or release a financial hold.', self::DOMAIN ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wbm-report">' .
			esc_html__( 'Report', self::DOMAIN ) . '</label></th><td>';
		echo '<select name="report" id="wbm-report" required>';
		foreach ( [
			'outstanding_orders' => __( 'Outstanding BACS orders', self::DOMAIN ),
			'partial_funding' => __( 'Partial funding', self::DOMAIN ),
			'unmatched_credits' => __( 'Unmatched imported credits', self::DOMAIN ),
			'exception_aging' => __( 'Exception aging', self::DOMAIN ),
			'import_batches' => __( 'Import batches', self::DOMAIN ),
			'allocation_audit' => __( 'Allocation audit', self::DOMAIN ),
		] as $key => $name ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr>';
		$today = gmdate( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		foreach ( [
			'from' => [ __( 'From (UTC)', self::DOMAIN ), $from ],
			'to' => [ __( 'To (UTC)', self::DOMAIN ), $today ],
		] as $field => [ $label, $default ] ) {
			echo '<tr><th scope="row"><label for="wbm-' . esc_attr( $field ) . '">' .
				esc_html( $label ) . '</label></th><td><input required type="date" id="wbm-' .
				esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' .
				esc_attr( $default ) . '"></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wbm-currency">' .
			esc_html__( 'ISO currency or ALL', self::DOMAIN ) .
			'</label></th><td><input id="wbm-currency" name="currency" maxlength="3" value="ALL" pattern="ALL|[A-Z]{3}" required></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-account">' .
			esc_html__( 'Bank account ID (0 for all)', self::DOMAIN ) .
			'</label></th><td><input type="number" id="wbm-account" name="bank_account_id" min="0" step="1" value="0" required></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-limit">' .
			esc_html__( 'Rows per export', self::DOMAIN ) .
			'</label></th><td><input type="number" id="wbm-limit" name="limit" min="1" max="500" step="1" value="100" required></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-cursor">' .
			esc_html__( 'Before record ID (0 for first page)', self::DOMAIN ) .
			'</label></th><td><input type="number" id="wbm-cursor" name="before_id" min="0" step="1" value="0" required></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Download audited CSV page', self::DOMAIN ) );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'At most 3 downloads per finance user per minute; additional pages and larger asynchronous exports require explicit separate processing. Dates are UTC for WooCommerce and record creation; imported bank credits use their local posting-calendar dates.', self::DOMAIN ) . '</p>';
		echo '</div>';
	}

	public static function download(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Finance exports require POST.', self::DOMAIN ), '',
				[ 'response' => 405 ] );
		}
		self::authorize();
		// Validated both here and inside the audit service.
		check_admin_referer( self::ACTION );
		$input = wp_unslash( $_POST );
		if ( ! is_array( $input ) ) {
			wp_die( esc_html__( 'Invalid report request.', self::DOMAIN ), '',
				[ 'response' => 400 ] );
		}
		$data = [];
		foreach ( [ 'report', 'from', 'to', 'currency', 'bank_account_id',
			'state', 'limit', 'before_id' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) $data[$field] = $input[$field];
		}
		try {
			$scope = ReportDefinition::from_array( $data );
			$download = ( new ReportCsvExportService( $GLOBALS['wpdb'] ) )->export(
				$scope, get_current_user_id(),
				is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : ''
			);
		} catch ( InvalidArgumentException $error ) {
			wp_die( esc_html__( 'Invalid finance report filters.', self::DOMAIN ), '',
				[ 'response' => 422 ] );
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Finance export is unavailable or rate-limited.', self::DOMAIN ), '',
				[ 'response' => 409 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $download['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-WBM-Has-More: ' . ( $download['has_more'] ? 'yes' : 'no' ) );
		header( 'X-WBM-Next-Before-ID: ' . $download['next_before_id'] );
		echo $download['csv']; // Validated, bounded and encoded by ReportCsvEncoder.
		exit;
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			wp_die( esc_html__( 'Finance reconciliation permission is required.', self::DOMAIN ), '',
				[ 'response' => 403 ] );
		}
	}
}
