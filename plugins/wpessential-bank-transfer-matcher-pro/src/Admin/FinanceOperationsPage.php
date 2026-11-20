<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Operations\FinanceJobRecovery;
use WPEssential\BankTransferMatcherPro\Operations\FinanceOperationsSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce-native operational triage. Explicit POST/nonce for every action;
 * no customer endpoint, raw bank payload export or automatic retry.
 */
final class FinanceOperationsPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-operations';
	private const EXPORT = 'wbm_pro_ops_diagnostics_export';
	private const RECOVER = 'wbm_pro_ops_recovery';

	private const LABELS = [
		'import_batches' => 'Statement import batches',
		'report_exports' => 'Private report export jobs',
		'mail_delivery' => 'Notification delivery audit',
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 39 );
		add_action( 'admin_post_' . self::EXPORT, [ self::class, 'download_diagnostics' ] );
		add_action( 'admin_post_' . self::RECOVER, [ self::class, 'recover' ] );
	}

	public static function menu(): void {
		if ( ! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ||
			! current_user_can( ProCaps::IMPORT ) || ! current_user_can( FreeCaps::APPROVE ) ) {
			return;
		}

		add_submenu_page( 'woocommerce', __( 'Bank operations diagnostics', self::DOMAIN ),
			__( 'Bank operations', self::DOMAIN ), ProCaps::MANAGE_ACCOUNTS,
			self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		self::authorize();
		echo '<div class="wrap"><h1>' .
			esc_html__( 'Bank operations diagnostics', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Private, read-only diagnostics for finance managers only. Each source shows up to 20 latest job records, not a full history or proof of cleared funds. Missing sources are unavailable, not zero. No account numbers, uploaded filenames, paths, bank references, customer identities, email addresses or import file contents are displayed or exported.', self::DOMAIN ) . '</p>';
		if ( isset( $_GET['recovered'] ) ) {
			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'A verified, explicitly authorized recovery was queued. Recheck source job status; this does not approve a payment.', self::DOMAIN ) .
				'</p></div>';
		}
		try {
			$data = ( new FinanceOperationsSnapshot( $GLOBALS['wpdb'] ) )
				->inspect( get_current_user_id() );
			self::results( $data['sources'] );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Finance diagnostics are unavailable. Nothing has been retried or paid.', self::DOMAIN ) .
				'</p></div>';
		}
		echo '<h2>' . esc_html__( 'Redacted support diagnostics', self::DOMAIN ) . '</h2>';
		echo '<p>' . esc_html__( 'The audited CSV contains only fixed source/state, age band and job count fields. Job IDs, user and recipient IDs, file names and financial amounts are excluded. At most three exports per manager per hour.', self::DOMAIN ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '">';
		wp_nonce_field( self::EXPORT );
		submit_button( __( 'Download redacted diagnostics CSV', self::DOMAIN ), 'secondary' );
		echo '</form>';
		echo '<p class="description">' .
			esc_html__( 'Recovery is explicit, manager-authorized, audited and rate-limited. Statement import retry verifies the original private staged file SHA-256; an expired or altered file is never replayed. Failed private exports create a new, bounded job only for their original owner.', self::DOMAIN ) .
			'</p></div>';
	}

	private static function results( array $groups ): void {
		foreach ( self::LABELS as $kind => $title ) {
			$group = $groups[$kind] ?? null;
			echo '<h2>' . esc_html( $title ) . '</h2>';
			if ( ! is_array( $group ) || $group['available'] !== true ) {
				echo '<p>' . esc_html__( 'Source unavailable — not zero.', self::DOMAIN ) . '</p>';
				continue;
			}
			if ( $group['has_more'] ) echo '<p class="description">' .
				esc_html__( 'More records exist; only the latest 20 are shown.', self::DOMAIN ) . '</p>';
			if ( ! $group['rows'] ) {
				echo '<p>' . esc_html__( 'No recorded jobs in this source.', self::DOMAIN ) . '</p>';
				continue;
			}
			echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr( $title ) . '"><table class="wp-list-table widefat fixed striped"><thead><tr>';
			foreach ( [ 'Job', 'Status', 'Age', 'Processed rows',
				'Invalid rows', 'Warnings', 'Recovery' ] as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $group['rows'] as $row ) {
				echo '<tr><td>#' . esc_html( (string) $row['job_id'] ) .
					'</td><td>' . esc_html( $row['state'] ) .
					'</td><td>' . esc_html( $row['age_band'] ) .
					'</td><td>' . esc_html( (string) $row['processed_rows'] ) .
					'</td><td>' . esc_html( (string) $row['error_rows'] ) .
					'</td><td>' . esc_html( (string) $row['warning_rows'] ) .
					'</td><td>';
				if ( $row['state'] === 'failed' &&
					in_array( $kind, [ 'import_batches', 'report_exports' ], true ) ) {
					self::recovery_form( $kind, $row['job_id'] );
				} else {
					echo esc_html__( 'Read-only', self::DOMAIN );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	private static function recovery_form( string $kind, int $id ): void {
		$operation = $kind === 'import_batches' ? 'retry_import' : 'requeue_export';
		echo '<form method="post" action="' .
			esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RECOVER ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		echo '<input type="hidden" name="source_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( self::RECOVER );
		echo '<label class="screen-reader-text" for="wbm-ops-reason-' . esc_attr( $kind ) .
			'-' . esc_attr( (string) $id ) . '">' .
			esc_html__( 'Why retry this failed job?', self::DOMAIN ) . '</label>';
		echo '<input required id="wbm-ops-reason-' . esc_attr( $kind ) . '-' .
			esc_attr( (string) $id ) .
			'" type="text" name="reason" minlength="12" maxlength="500" size="22" placeholder="' .
			esc_attr__( 'Reason (12–500 characters)', self::DOMAIN ) . '">';
		submit_button( $operation === 'retry_import' ?
			__( 'Verify file & retry', self::DOMAIN ) :
			__( 'Requeue own failed export', self::DOMAIN ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function download_diagnostics(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::EXPORT );
		try {
			$file = ( new FinanceOperationsSnapshot( $GLOBALS['wpdb'] ) )
				->export_redacted( get_current_user_id(), self::posted_nonce() );
		} catch ( RuntimeException|InvalidArgumentException $error ) {
			wp_die( esc_html__( 'Redacted diagnostics export is unavailable or rate-limited.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $file['csv'];
		exit;
	}

	public static function recover(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::RECOVER );
		$operation = wp_unslash( $_POST['operation'] ?? null );
		$raw_id = wp_unslash( $_POST['source_id'] ?? null );
		$reason = wp_unslash( $_POST['reason'] ?? null );
		if ( ! is_string( $operation ) || ! is_string( $raw_id ) ||
			! preg_match( '/\A[1-9][0-9]*\z/', $raw_id ) ||
			strlen( $raw_id ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $raw_id ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $raw_id, (string) PHP_INT_MAX ) > 0 ) ||
			! is_string( $reason ) ) {
			wp_die( esc_html__( 'Invalid recovery request.', self::DOMAIN ),
				'', [ 'response' => 422 ] );
		}
		try {
			( new FinanceJobRecovery( $GLOBALS['wpdb'] ) )->recover(
				$operation, (int) $raw_id, get_current_user_id(),
				self::posted_nonce(), $reason
			);
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Recovery was denied, unsafe, duplicate or source integrity failed.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		wp_safe_redirect( add_query_arg( [
			'page' => self::SLUG, 'recovered' => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function posted_nonce(): string {
		$value = wp_unslash( $_POST['_wpnonce'] ?? null );
		return is_string( $value ) ? $value : '';
	}

	private static function require_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Finance operations require POST.', self::DOMAIN ),
				'', [ 'response' => 405 ] );
		}
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ) {
			wp_die( esc_html__( 'Bank account manager permission required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
	}
}
