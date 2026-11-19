<?php

namespace WPEssential\BankTransferMatcherPro\Admin;

use RuntimeException;
use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;

defined( 'ABSPATH' ) || exit;

/** Read-only view of locally imported statement rows and provenance. */
final class BankTransactionsPage {
	private const SLUG = 'wpessential-wbm-pro-bank-transactions';

	public static function register(): void { add_action( 'admin_menu', array( self::class, 'menu' ), 33 ); }

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Bank transactions', 'wpessential-bank-transfer-matcher-pro' ), __( 'Bank transactions', 'wpessential-bank-transfer-matcher-pro' ), Capabilities::IMPORT, self::SLUG, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) wp_die( esc_html__( 'Statement import permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', array( 'response' => 403 ) );
		$batch_id = isset( $_GET['batch'] ) ? absint( $_GET['batch'] ) : 0;
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit = isset( $_GET['per_page'] ) && in_array( absint( $_GET['per_page'] ), array( 20, 50, 100 ), true ) ? absint( $_GET['per_page'] ) : 50;
		try {
			$history = ( new ImportBatchRepository( $GLOBALS['wpdb'] ) )->history( 1, 100 );
			if ( 0 === $batch_id && $history ) $batch_id = (int) $history[0]['id'];
			$selected = null;
			foreach ( $history as $batch ) if ( $batch_id === (int) $batch['id'] ) { $selected = $batch; break; }
			$rows = $selected ? ( new ImportJob( $GLOBALS['wpdb'] ) )->staged_rows( $batch_id, $page, $limit ) : array();
		} catch ( RuntimeException $error ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Bank transactions', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'Imported rows are unavailable. Check database health and retry.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Bank transactions', 'wpessential-bank-transfer-matcher-pro' ) . '</h1><p>' . esc_html__( 'These are unverified statement records. They are evidence for review and do not mark WooCommerce orders paid.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		if ( ! $history ) {
			echo '<p>' . esc_html__( 'No imported bank transactions are available.', 'wpessential-bank-transfer-matcher-pro' ) . ' <a class="button button-primary" href="' . esc_url( add_query_arg( 'page', 'wpessential-wbm-pro-import-statements', admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Import statement', 'wpessential-bank-transfer-matcher-pro' ) . '</a></p></div>';
			return;
		}
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="wbm-batch"><strong>' . esc_html__( 'Source batch', 'wpessential-bank-transfer-matcher-pro' ) . '</strong></label> <select id="wbm-batch" name="batch">';
		foreach ( $history as $batch ) printf( '<option value="%d"%s>#%d — %s — %s</option>', (int) $batch['id'], selected( $batch_id, (int) $batch['id'], false ), (int) $batch['id'], esc_html( $batch['currency'] ), esc_html( $batch['status'] ) );
		echo '</select> <select name="per_page">';
		foreach ( array( 20, 50, 100 ) as $size ) printf( '<option value="%d"%s>%d</option>', $size, selected( $limit, $size, false ), $size );
		echo '</select> '; submit_button( __( 'Apply', 'wpessential-bank-transfer-matcher-pro' ), 'secondary', '', false ); echo '</form>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'This batch has no staged rows on the selected page. It may still be queued, failed before parsing, or the page may be out of range.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Source row', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Date', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Debit / credit', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Amount', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Reference', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Bank ID', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Parse state', 'wpessential-bank-transfer-matcher-pro' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$data = is_string( $row['canonical_json'] ?? null ) ? json_decode( $row['canonical_json'], true ) : null;
			$amount = is_array( $data ) ? (int) $data['amount_minor'] : 0;
			printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', (int) $row['source_row'], esc_html( is_array( $data ) ? $data['posted_date'] : '—' ), esc_html( ! is_array( $data ) ? '—' : ( $amount > 0 ? __( 'Credit', 'wpessential-bank-transfer-matcher-pro' ) : __( 'Debit', 'wpessential-bank-transfer-matcher-pro' ) ) ), esc_html( is_array( $data ) ? abs( $amount ) . ' ' . $data['currency'] : '—' ), esc_html( is_array( $data ) ? self::mask( (string) $data['reference'] ) : '—' ), esc_html( is_array( $data ) ? self::mask( (string) $data['transaction_id'] ) : '—' ), esc_html( '' !== ( $row['error_code'] ?? '' ) ? $row['error_code'] : __( 'Parsed — unverified', 'wpessential-bank-transfer-matcher-pro' ) ) );
		}
		echo '</tbody></table><p>';
		if ( $page > 1 ) echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'batch' => $batch_id, 'paged' => $page - 1, 'per_page' => $limit ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Previous', 'wpessential-bank-transfer-matcher-pro' ) . '</a> ';
		if ( count( $rows ) === $limit ) echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'batch' => $batch_id, 'paged' => $page + 1, 'per_page' => $limit ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next', 'wpessential-bank-transfer-matcher-pro' ) . '</a>';
		echo '</p></div>';
	}

	private static function mask( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) return '—';
		return strlen( $value ) < 5 ? str_repeat( '•', strlen( $value ) ) : substr( $value, 0, 2 ) . '••••' . substr( $value, -2 );
	}
}
