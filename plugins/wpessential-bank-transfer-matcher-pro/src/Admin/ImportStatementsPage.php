<?php

namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\DryRunPreview;
use WPEssential\BankTransferMatcherPro\Import\DryRunResult;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportDraftStore;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Import\MappingProfileRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ImportStatementsPage {
	private const SLUG = 'wpessential-wbm-pro-import-statements';
	private static ?array $preview = null;
	private static ?WP_Error $request_error = null;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 32 );
		add_action( 'admin_post_wbm_pro_preview_statement', array( self::class, 'preview' ) );
		add_action( 'admin_post_wbm_pro_commit_statement', array( self::class, 'commit' ) );
		add_action( 'admin_post_wbm_pro_retry_import', array( self::class, 'retry' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Import statements', 'wpessential-bank-transfer-matcher-pro' ), __( 'Import statements', 'wpessential-bank-transfer-matcher-pro' ), Capabilities::IMPORT, self::SLUG, array( self::class, 'render' ) );
	}

	/** @return array{result:DryRunResult,token:string,account_id:int,profile:MappingProfile,file_name:string}|WP_Error */
	public static function process_preview( array $input, array $files ): array|WP_Error {
		if ( ! current_user_can( Capabilities::IMPORT ) ) return new WP_Error( 'wbm_pro_forbidden', __( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), 403 );
		$account_id = isset( $input['account_id'] ) ? absint( $input['account_id'] ) : 0;
		$profile_id = is_string( $input['profile_id'] ?? null ) ? sanitize_text_field( $input['profile_id'] ) : '';
		$file = $files['statement'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_string( $file['tmp_name'] ?? null ) ) {
			return new WP_Error( 'wbm_pro_upload_failed', __( 'Choose a CSV statement file and try again.', 'wpessential-bank-transfer-matcher-pro' ), 422 );
		}
		$name = is_string( $file['name'] ?? null ) ? sanitize_file_name( $file['name'] ) : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) return new WP_Error( 'wbm_pro_csv_required', __( 'Only CSV statement files are enabled for this release.', 'wpessential-bank-transfer-matcher-pro' ), 422 );
		try {
			$account = self::active_account( $account_id );
			$profile = self::profile( $profile_id );
			$drafts = new ImportDraftStore();
			$draft = $drafts->create( $file['tmp_name'], get_current_user_id() );
			try {
				$bytes = file_get_contents( $draft['path'] );
				if ( false === $bytes ) throw new RuntimeException( 'Could not read the staged statement.' );
				$result = ( new DryRunPreview() )->preview( $bytes, $profile );
				if ( $account['currency'] !== $profile->fixed_currency && '' === $profile->currency_header ) {
					throw new InvalidArgumentException( 'The fixed mapping currency does not match the selected bank account.' );
				}
				return array( 'result' => $result, 'token' => $draft['token'], 'account_id' => $account_id, 'profile' => $profile, 'file_name' => $name );
			} catch ( \Throwable $error ) {
				$drafts->discard( $draft['token'], get_current_user_id() );
				throw $error;
			}
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_pro_invalid_preview', $error->getMessage(), 422 );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_pro_preview_unavailable', __( 'The statement preview could not be prepared. Check private storage and try again.', 'wpessential-bank-transfer-matcher-pro' ), 503 );
		}
	}

	public static function preview(): void {
		check_admin_referer( 'wbm_pro_preview_statement' );
		$result = self::process_preview( wp_unslash( $_POST ), $_FILES );
		is_wp_error( $result ) ? self::$request_error = $result : self::$preview = $result;
		self::render_admin_response();
	}

	/** @return int|WP_Error */
	public static function process_commit( array $input ): int|WP_Error {
		if ( ! current_user_can( Capabilities::IMPORT ) ) return new WP_Error( 'wbm_pro_forbidden', __( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), 403 );
		$token = is_string( $input['draft_token'] ?? null ) ? sanitize_text_field( $input['draft_token'] ) : '';
		$account_id = isset( $input['account_id'] ) ? absint( $input['account_id'] ) : 0;
		$profile_id = is_string( $input['profile_id'] ?? null ) ? sanitize_text_field( $input['profile_id'] ) : '';
		$drafts = new ImportDraftStore();
		try {
			self::active_account( $account_id );
			$profile = self::profile( $profile_id );
			$draft = $drafts->get( $token, get_current_user_id() );
			$batch_id = ( new ImportJob( $GLOBALS['wpdb'] ) )->start( $draft['path'], $account_id, $profile, get_current_user_id() );
			$drafts->discard( $token, get_current_user_id() );
			return $batch_id;
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'wbm_pro_invalid_commit', $error->getMessage(), 422 );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'wbm_pro_import_unavailable', $error->getMessage(), 503 );
		}
	}

	public static function commit(): void {
		check_admin_referer( 'wbm_pro_commit_statement' );
		$result = self::process_commit( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { self::$request_error = $result; self::render_admin_response(); }
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'batch' => $result, 'started' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function retry(): void {
		check_admin_referer( 'wbm_pro_retry_import' );
		if ( ! current_user_can( Capabilities::IMPORT ) ) wp_die( esc_html__( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', array( 'response' => 403 ) );
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		try { ( new ImportJob( $GLOBALS['wpdb'] ) )->retry( $batch_id ); }
		catch ( \Throwable $error ) { self::$request_error = new WP_Error( 'wbm_pro_retry_failed', $error->getMessage(), 503 ); self::render_admin_response(); }
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'batch' => $batch_id, 'retried' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) wp_die( esc_html__( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', array( 'response' => 403 ) );
		try {
			$accounts = array_values( array_filter( ( new BankAccountRepository( $GLOBALS['wpdb'] ) )->all(), static fn ( array $account ): bool => 'active' === $account['status'] ) );
			$profiles = ( new MappingProfileRepository() )->all();
			$history = ( new ImportBatchRepository( $GLOBALS['wpdb'] ) )->history();
		} catch ( RuntimeException $error ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Import statements', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Import storage is unavailable. Check database health and retry.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Import statements', 'wpessential-bank-transfer-matcher-pro' ) . '</h1>';
		echo '<p>' . esc_html__( 'Preview a local CSV statement before committing it. Imported rows remain unverified and never confirm customer payments by themselves.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		if ( self::$request_error ) printf( '<div class="notice notice-error" role="alert"><p>%s</p></div>', esc_html( self::$request_error->get_error_message() ) );
		if ( isset( $_GET['started'] ) ) echo '<div class="notice notice-success"><p>' . esc_html__( 'Import queued. Refresh this page to read its persisted progress.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
		if ( self::$preview ) self::render_preview( self::$preview );
		else self::render_upload( $accounts, $profiles );
		self::render_history( $history );
		echo '</div>';
	}

	private static function render_upload( array $accounts, array $profiles ): void {
		if ( ! $accounts || ! $profiles ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Configure at least one active bank account and one statement mapping before importing.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html__( '1. Choose source', 'wpessential-bank-transfer-matcher-pro' ) . '</h2><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_preview_statement">';
		wp_nonce_field( 'wbm_pro_preview_statement' );
		echo '<table class="form-table"><tr><th><label for="wbm-account">' . esc_html__( 'Receiving account', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><select id="wbm-account" name="account_id" required><option value="">' . esc_html__( 'Select account', 'wpessential-bank-transfer-matcher-pro' ) . '</option>';
		foreach ( $accounts as $account ) printf( '<option value="%d">%s — %s •••• %s</option>', (int) $account['id'], esc_html( $account['nickname'] ), esc_html( $account['currency'] ), esc_html( $account['identifier_last4'] ) );
		echo '</select></td></tr><tr><th><label for="wbm-profile">' . esc_html__( 'Statement mapping', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><select id="wbm-profile" name="profile_id" required><option value="">' . esc_html__( 'Select mapping', 'wpessential-bank-transfer-matcher-pro' ) . '</option>';
		foreach ( $profiles as $profile ) printf( '<option value="%s">%s</option>', esc_attr( $profile->id ), esc_html( $profile->name ) );
		echo '</select></td></tr><tr><th><label for="wbm-statement">' . esc_html__( 'CSV statement', 'wpessential-bank-transfer-matcher-pro' ) . '</label></th><td><input id="wbm-statement" type="file" name="statement" accept=".csv,text/csv" required><p class="description">' . esc_html__( 'Maximum 20 MB and 10,000 data rows. Bank data stays in private local storage.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></td></tr></table>';
		submit_button( __( 'Validate and preview', 'wpessential-bank-transfer-matcher-pro' ) ); echo '</form>';
	}

	private static function render_preview( array $preview ): void {
		$result = $preview['result'];
		echo '<h2>' . esc_html__( '2. Dry-run preview', 'wpessential-bank-transfer-matcher-pro' ) . '</h2>';
		printf( '<p><strong>%s</strong> %d &nbsp; <strong>%s</strong> %d &nbsp; <strong>%s</strong> %d</p>', esc_html__( 'Valid:', 'wpessential-bank-transfer-matcher-pro' ), $result->valid_count, esc_html__( 'Invalid:', 'wpessential-bank-transfer-matcher-pro' ), $result->error_count, esc_html__( 'Warnings:', 'wpessential-bank-transfer-matcher-pro' ), $result->warning_count );
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Importing these records does not confirm customer payments. Review and allocation remain separate authorized actions.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Row', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Date', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Amount', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Reference', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Result', 'wpessential-bank-transfer-matcher-pro' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $result->rows, 0, 100 ) as $row ) {
			$canonical = $row->canonical;
			$reference = $canonical ? self::masked_reference( $canonical->reference ) : '—';
			$outcome = $row->error ?: ( $row->warnings ? implode( ', ', $row->warnings ) : __( 'Ready', 'wpessential-bank-transfer-matcher-pro' ) );
			printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $row->source_row, esc_html( $canonical?->posted_date ?? '—' ), esc_html( $canonical ? $canonical->amount_minor . ' ' . $canonical->currency : '—' ), esc_html( $reference ), esc_html( $outcome ) );
		}
		echo '</tbody></table>';
		if ( count( $result->rows ) > 100 ) echo '<p class="description">' . esc_html__( 'Showing the first 100 rows. All rows were validated.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_commit_statement"><input type="hidden" name="draft_token" value="' . esc_attr( $preview['token'] ) . '"><input type="hidden" name="account_id" value="' . (int) $preview['account_id'] . '"><input type="hidden" name="profile_id" value="' . esc_attr( $preview['profile']->id ) . '">';
		wp_nonce_field( 'wbm_pro_commit_statement' );
		submit_button( __( 'Commit import', 'wpessential-bank-transfer-matcher-pro' ), 'primary', 'submit', false, $result->valid_count < 1 ? array( 'disabled' => 'disabled' ) : array() );
		echo ' <a class="button" href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'wpessential-bank-transfer-matcher-pro' ) . '</a></form>';
	}

	private static function render_history( array $history ): void {
		echo '<h2>' . esc_html__( 'Import history and progress', 'wpessential-bank-transfer-matcher-pro' ) . '</h2>';
		if ( ! $history ) { echo '<p>' . esc_html__( 'No statements have been imported yet.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>'; return; }
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Batch', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Status', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Account / currency', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Processed', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Updated', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Action', 'wpessential-bank-transfer-matcher-pro' ) . '</th></tr></thead><tbody>';
		foreach ( $history as $batch ) {
			printf( '<tr><td>#%d</td><td>%s%s</td><td>#%d / %s</td><td>%d valid, %d errors, %d warnings</td><td>%s</td><td>', (int) $batch['id'], esc_html( ucfirst( $batch['status'] ) ), in_array( $batch['status'], array( 'pending', 'processing' ), true ) ? ' <progress aria-label="' . esc_attr__( 'Import in progress', 'wpessential-bank-transfer-matcher-pro' ) . '"></progress>' : '', (int) $batch['account_id'], esc_html( $batch['currency'] ), (int) $batch['valid_count'], (int) $batch['error_count'], (int) $batch['warning_count'], esc_html( $batch['updated_at'] ) );
			if ( 'failed' === $batch['status'] ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wbm_pro_retry_import"><input type="hidden" name="batch_id" value="' . (int) $batch['id'] . '">'; wp_nonce_field( 'wbm_pro_retry_import' ); submit_button( __( 'Retry', 'wpessential-bank-transfer-matcher-pro' ), 'small', 'submit', false ); echo '</form>'; }
			else echo '—';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function active_account( int $id ): array {
		foreach ( ( new BankAccountRepository( $GLOBALS['wpdb'] ) )->all() as $account ) if ( $id === (int) $account['id'] && 'active' === $account['status'] ) return $account;
		throw new InvalidArgumentException( 'Select an active bank account.' );
	}

	private static function profile( string $id ): MappingProfile {
		foreach ( ( new MappingProfileRepository() )->all() as $profile ) if ( hash_equals( $profile->id, $id ) ) return $profile;
		throw new InvalidArgumentException( 'Select a valid statement mapping.' );
	}

	private static function masked_reference( string $reference ): string {
		$reference = trim( $reference );
		if ( '' === $reference ) return '—';
		return strlen( $reference ) <= 4 ? str_repeat( '•', strlen( $reference ) ) : substr( $reference, 0, 2 ) . '••••' . substr( $reference, -2 );
	}

	private static function render_admin_response(): never {
		require_once ABSPATH . 'wp-admin/admin-header.php';
		self::render();
		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}
}
