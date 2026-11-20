<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reporting\AsyncReportExportJob;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportCsvExportService;

defined( 'ABSPATH' ) || exit;

/**
 * Finance-only WooCommerce admin page and authenticated POST downloads.
 * No public/nopriv/REST or customer-facing financial export hooks.
 */
final class ReportExportsPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-finance-reports';
	private const ACTION = 'wbm_pro_export_report';
	private const QUEUE = 'wbm_pro_schedule_report_export';
	private const QUEUED_DOWNLOAD = 'wbm_pro_download_queued_export';
	private const QUEUED_CANCEL = 'wbm_pro_cancel_queued_export';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 36 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'download' ] );
		add_action( 'admin_post_' . self::QUEUE, [ self::class, 'schedule' ] );
		add_action( 'admin_post_' . self::QUEUED_DOWNLOAD, [ self::class, 'download_queued' ] );
		add_action( 'admin_post_' . self::QUEUED_CANCEL, [ self::class, 'cancel_queued' ] );
	}

	public static function menu(): void {
		if ( ! current_user_can( ProCaps::IMPORT ) || ! current_user_can( FreeCaps::APPROVE ) ) {
			return;
		}

		add_submenu_page( 'woocommerce', __( 'Finance reports', self::DOMAIN ),
			__( 'Finance reports', self::DOMAIN ), ProCaps::IMPORT,
			self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		self::authorize();
		echo '<div class="wrap"><h1>' . esc_html__( 'Finance reports', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Audited read-only finance CSV exports. Personal details, full bank account numbers, raw bank references, import filenames and credentials are not exported. Reports cannot mark any order paid or release a financial hold.', self::DOMAIN ) . '</p>';
		echo '<h2>' . esc_html__( 'Immediate one-page export', self::DOMAIN ) . '</h2>';
		self::filter_form( self::ACTION, 'sync', __( 'Download audited CSV page', self::DOMAIN ), false );
		echo '<p class="description">' . esc_html__( 'At most 500 rows or 1 MiB and 3 immediate exports per finance actor per minute. Each CSV is one page, not a full bank ledger.', self::DOMAIN ) . '</p>';

		echo '<h2>' . esc_html__( 'Queued, bounded export', self::DOMAIN ) . '</h2>';
		echo '<p>' . esc_html__( 'Action Scheduler processes at most ten pages of 100 rows (1,000 records / 5 MiB). Results remain private in the database for up to two hours; at most two active or downloadable jobs per finance user. A wider report requires narrower filters, never an unbounded export.', self::DOMAIN ) . '</p>';
		self::filter_form( self::QUEUE, 'async', __( 'Queue private CSV export', self::DOMAIN ), true );

		$job_id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		if ( $job_id > 0 ) {
			echo '<h2>' . esc_html__( 'Your private export job', self::DOMAIN ) . '</h2>';
			try {
				$job = ( new AsyncReportExportJob( $GLOBALS['wpdb'] ) )
					->status( $job_id, get_current_user_id() );
				echo '<p>' . esc_html( sprintf(
					/* translators: 1: job ID, 2: status, 3: pages, 4: rows */
					__( 'Job #%1$d: %2$s; %3$d pages processed; %4$d rows.', self::DOMAIN ),
					$job['id'], $job['status'], $job['pages_done'], $job['row_count']
				) ) . '</p>';
				if ( $job['can_download'] ) {
					self::job_form( self::QUEUED_DOWNLOAD, $job_id,
						__( 'Download private CSV', self::DOMAIN ) );
				}
				if ( in_array( $job['status'], [ 'queued','running','ready','failed' ], true ) ) {
					self::job_form( self::QUEUED_CANCEL, $job_id,
						__( 'Cancel and erase stored CSV', self::DOMAIN ) );
				}
				if ( in_array( $job['status'], [ 'queued', 'running' ], true ) ) {
					echo '<p><a href="' . esc_url( add_query_arg( [
						'page' => self::SLUG, 'job' => $job_id,
					], admin_url( 'admin.php' ) ) ) . '">' .
						esc_html__( 'Refresh export status', self::DOMAIN ) . '</a></p>';
				}
			} catch ( InvalidArgumentException|RuntimeException $error ) {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'This export job is unavailable or not yours.', self::DOMAIN ) .
					'</p></div>';
			}
		}
		echo '</div>';
	}

	private static function filter_form( string $action, string $prefix,
		string $submit, bool $queued ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		// Both pathways use the same scope nonce, checked again in the service.
		wp_nonce_field( self::ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wbm-' . esc_attr( $prefix ) . '-report">' .
			esc_html__( 'Report', self::DOMAIN ) . '</label></th><td>';
		echo '<select name="report" id="wbm-' . esc_attr( $prefix ) . '-report" required>';
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
			$id = 'wbm-' . $prefix . '-' . $field;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' .
				esc_html( $label ) . '</label></th><td><input required type="date" id="' .
				esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' .
				esc_attr( $default ) . '"></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wbm-' . esc_attr( $prefix ) . '-currency">' .
			esc_html__( 'ISO currency or ALL', self::DOMAIN ) .
			'</label></th><td><input id="wbm-' . esc_attr( $prefix ) .
			'-currency" name="currency" maxlength="3" value="ALL" pattern="ALL|[A-Z]{3}" required></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-' . esc_attr( $prefix ) . '-account">' .
			esc_html__( 'Bank account ID (0 for all)', self::DOMAIN ) .
			'</label></th><td><input type="number" id="wbm-' . esc_attr( $prefix ) .
			'-account" name="bank_account_id" min="0" step="1" value="0" required></td></tr>';
		if ( ! $queued ) {
			echo '<tr><th scope="row"><label for="wbm-sync-limit">' .
				esc_html__( 'Rows per export', self::DOMAIN ) .
				'</label></th><td><input type="number" id="wbm-sync-limit" name="limit" min="1" max="500" step="1" value="100" required></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wbm-' . esc_attr( $prefix ) . '-cursor">' .
			esc_html__( 'Before record ID (0 for first page)', self::DOMAIN ) .
			'</label></th><td><input type="number" id="wbm-' . esc_attr( $prefix ) .
			'-cursor" name="before_id" min="0" step="1" value="0" required></td></tr>';
		echo '</tbody></table>';
		submit_button( $submit );
		echo '</form>';
	}

	private static function job_form( string $action, int $job_id, string $label ): void {
		echo '<form style="display:inline-block;margin-right:12px" method="post" action="' .
			esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="job_id" value="' . esc_attr( (string) $job_id ) . '">';
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	private static function filters(): array {
		$input = wp_unslash( $_POST );
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'Invalid finance report request.' );
		}
		$data = [];
		foreach ( [ 'report', 'from', 'to', 'currency', 'bank_account_id',
			'state', 'limit', 'before_id' ] as $field ) {
			if ( array_key_exists( $field, $input ) ) $data[$field] = $input[$field];
		}
		return $data;
	}

	public static function download(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::ACTION );
		try {
			$scope = ReportDefinition::from_array( self::filters() );
			$download = ( new ReportCsvExportService( $GLOBALS['wpdb'] ) )->export(
				$scope, get_current_user_id(),
				self::posted_nonce()
			);
		} catch ( InvalidArgumentException $error ) {
			wp_die( esc_html__( 'Invalid finance report filters.', self::DOMAIN ), '',
				[ 'response' => 422 ] );
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Finance export is unavailable or rate-limited.', self::DOMAIN ), '',
				[ 'response' => 409 ] );
		}
		self::send_csv( $download['filename'], $download['csv'], [
			'X-WBM-Has-More: ' . ( $download['has_more'] ? 'yes' : 'no' ),
			'X-WBM-Next-Before-ID: ' . $download['next_before_id'],
		] );
	}

	public static function schedule(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::ACTION );
		try {
			$scope = ReportDefinition::from_array( self::filters() );
			$job_id = ( new AsyncReportExportJob( $GLOBALS['wpdb'] ) )
				->start( $scope, get_current_user_id(), self::posted_nonce() );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Export job cannot be queued; check filters or current job limit.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::redirect( $job_id );
	}

	public static function download_queued(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::QUEUED_DOWNLOAD );
		try {
			$file = ( new AsyncReportExportJob( $GLOBALS['wpdb'] ) )
				->download( self::posted_job_id(), get_current_user_id(), self::posted_nonce() );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Private export is unavailable or expired.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::send_csv( $file['filename'], $file['csv'] );
	}

	public static function cancel_queued(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::QUEUED_CANCEL );
		try {
			$id = self::posted_job_id();
			( new AsyncReportExportJob( $GLOBALS['wpdb'] ) )
				->cancel( $id, get_current_user_id(), self::posted_nonce() );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'This private export job cannot be cancelled.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::redirect( $id );
	}

	private static function redirect( int $job_id ): void {
		wp_safe_redirect( add_query_arg( [
			'page' => self::SLUG, 'job' => $job_id,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function send_csv( string $filename, string $csv,
		array $headers = [] ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		foreach ( $headers as $value ) header( $value );
		echo $csv; // Only whitelisted, bounded, audited CSV from the finance services.
		exit;
	}

	private static function posted_job_id(): int {
		$id = wp_unslash( $_POST['job_id'] ?? null );
		if ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $id ) ||
			strlen( $id ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $id ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $id, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid queued export job ID.' );
		}
		return (int) $id;
	}

	private static function posted_nonce(): string {
		$nonce = wp_unslash( $_POST['_wpnonce'] ?? null );
		return is_string( $nonce ) ? $nonce : '';
	}

	private static function require_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Finance exports require POST.', self::DOMAIN ),
				'', [ 'response' => 405 ] );
		}
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			wp_die( esc_html__( 'Finance reconciliation permission is required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
	}
}
