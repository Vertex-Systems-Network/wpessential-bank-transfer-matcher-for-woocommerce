<?php

namespace WPEssential\BankTransferMatcherPro\Admin;

use RuntimeException;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;

defined( 'ABSPATH' ) || exit;

/** Read-only review queue and evidence workspace. Mutating actions are added by the secured action task. */
final class ReviewQueuePage {
	private const SLUG = 'wpessential-wbm-pro-review-queue';
	private const CAPABILITY = 'wbm_view';

	public static function register(): void { add_action( 'admin_menu', [ self::class, 'menu' ], 34 ); }

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Bank review queue', 'wpessential-bank-transfer-matcher-pro' ), __( 'Bank review queue', 'wpessential-bank-transfer-matcher-pro' ), self::CAPABILITY, self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) wp_die( esc_html__( 'Review queue permission is required.', 'wpessential-bank-transfer-matcher-pro' ), '', [ 'response' => 403 ] );
		$id = isset( $_GET['case_id'] ) ? absint( $_GET['case_id'] ) : 0;
		echo '<div class="wrap wbm-pro-screen"><h1>' . esc_html__( 'Bank review queue', 'wpessential-bank-transfer-matcher-pro' ) . '</h1>';
		echo '<p>' . esc_html__( 'Suggestions and review cases are unverified. This workspace cannot allocate funds or mark an order paid.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		try {
			if ( $id > 0 ) self::workspace( $id );
			else self::queue();
		} catch ( RuntimeException $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Review data is unavailable. Check database health and retry.', 'wpessential-bank-transfer-matcher-pro' ) . '</p></div>';
		}
		echo '</div>';
	}

	private static function queue(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$priority = isset( $_GET['priority'] ) ? sanitize_key( wp_unslash( $_GET['priority'] ) ) : '';
		$assignee = isset( $_GET['assignee'] ) ? absint( $_GET['assignee'] ) : 0;
		$page = isset( $_GET['paged'] ) ? max( 1, min( 10000, absint( $_GET['paged'] ) ) ) : 1;
		$limit = isset( $_GET['per_page'] ) && in_array( absint( $_GET['per_page'] ), [ 20, 50, 100 ], true ) ? absint( $_GET['per_page'] ) : 20;
		$repository = new ReviewCaseRepository( $GLOBALS['wpdb'] );
		$rows = $repository->queue( $status, $priority, $assignee, $page, $limit );
		// A full last page is not proof that another page exists. Request the
		// next bounded filtered page only when necessary, avoiding phantom Next.
		$has_more = count( $rows ) === $limit && $page < 10000 &&
			[] !== $repository->queue( $status, $priority, $assignee, $page + 1, $limit );
		echo '<form class="wbm-pro-toolbar" method="get" role="search" aria-label="' . esc_attr__( 'Bank review case filters', 'wpessential-bank-transfer-matcher-pro' ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		self::select( 'status', __( 'All states', 'wpessential-bank-transfer-matcher-pro' ), [ 'open', 'assigned', 'investigating', 'awaiting_approval', 'resolved', 'dismissed', 'reopened' ], $status );
		self::select( 'priority', __( 'All priorities', 'wpessential-bank-transfer-matcher-pro' ), [ 'critical', 'high', 'normal' ], $priority );
		echo '<label class="wbm-pro-field" for="wbm-filter-assignee"><span>' . esc_html__( 'Assignee user ID', 'wpessential-bank-transfer-matcher-pro' ) . '</span><input id="wbm-filter-assignee" type="number" min="1" name="assignee" value="' . esc_attr( $assignee ?: '' ) . '" placeholder="' . esc_attr__( 'Assignee ID', 'wpessential-bank-transfer-matcher-pro' ) . '"></label> ';
		echo '<label class="wbm-pro-field" for="wbm-review-page-size"><span>' . esc_html__( 'Rows per page', 'wpessential-bank-transfer-matcher-pro' ) . '</span><select id="wbm-review-page-size" name="per_page">';
		foreach ( [ 20, 50, 100 ] as $size ) printf( '<option value="%d"%s>%d</option>', $size, selected( $limit, $size, false ), $size );
		echo '</select></label> '; submit_button( __( 'Filter', 'wpessential-bank-transfer-matcher-pro' ), 'secondary', '', false ); echo '</form>';
		if ( [] === $rows ) {
			echo '<p role="status" class="wbm-pro-empty">' . esc_html__( 'No review cases match these filters.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
			if ( $page > 1 ) {
				echo '<p><a class="button" href="' . esc_url( self::page_url( $status, $priority, $assignee, $page - 1, $limit ) ) . '">' . esc_html__( 'Previous source page', 'wpessential-bank-transfer-matcher-pro' ) . '</a></p>';
			}
			return;
		}
		echo '<p class="description wbm-pro-scroll-hint">' . esc_html__( 'Scroll sideways to view all review-case columns. Case priorities and states are labels, not proof of payment.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Bank review case results', 'wpessential-bank-transfer-matcher-pro' ) . '"><table class="wp-list-table widefat fixed striped"><thead><tr><th>' . esc_html__( 'Case', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Type', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Priority', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'State', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Transaction', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Order', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th>' . esc_html__( 'Assignee', 'wpessential-bank-transfer-matcher-pro' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg( [ 'page' => self::SLUG, 'case_id' => (int) $row['id'] ], admin_url( 'admin.php' ) );
			$priority_tag = sprintf( '<span class="wbm-pro-state" data-priority="%s">%s</span>',
				esc_attr( (string) $row['priority'] ), esc_html( ucfirst( (string) $row['priority'] ) ) );
			$case_state = sprintf( '<span class="wbm-pro-state" data-state="%s">%s</span>',
				esc_attr( (string) $row['status'] ), esc_html( ucwords( str_replace( '_', ' ', (string) $row['status'] ) ) ) );
			printf( '<tr><td>#%d</td><td>%s</td><td>%s</td><td>%s</td><td>#%d</td><td>%s</td><td>%s</td><td><a class="button" href="%s">%s</a></td></tr>', (int) $row['id'], esc_html( $row['case_type'] ), $priority_tag, $case_state, (int) $row['transaction_id'], $row['order_id'] ? '#' . (int) $row['order_id'] : '—', $row['assignee_id'] ? '#' . (int) $row['assignee_id'] : esc_html__( 'Unassigned', 'wpessential-bank-transfer-matcher-pro' ), esc_url( $url ), esc_html__( 'Open workspace', 'wpessential-bank-transfer-matcher-pro' ) );
		}
		echo '</tbody></table></div><nav class="wbm-pro-pagination" aria-label="' . esc_attr__( 'Review case result pages', 'wpessential-bank-transfer-matcher-pro' ) . '">';
		if ( $page > 1 ) echo '<a class="button" href="' . esc_url( self::page_url( $status, $priority, $assignee, $page - 1, $limit ) ) . '">' . esc_html__( 'Previous', 'wpessential-bank-transfer-matcher-pro' ) . '</a> ';
		if ( $has_more ) echo '<a class="button" href="' . esc_url( self::page_url( $status, $priority, $assignee, $page + 1, $limit ) ) . '">' . esc_html__( 'Next', 'wpessential-bank-transfer-matcher-pro' ) . '</a>';
		echo '</nav>';
	}

	private static function workspace( int $id ): void {
		$repository = new ReviewCaseRepository( $GLOBALS['wpdb'] );
		$case = $repository->snapshot( $id );
		$comments = $repository->comments( $id );
		$history = $repository->history( $id );
		$transaction = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT canonical_json, error_code, warning_code FROM {$GLOBALS['wpdb']->prefix}wbm_pro_import_rows WHERE id = %d", (int) $case['transaction_id'] ), ARRAY_A );
		$bank = is_array( $transaction ) && is_string( $transaction['canonical_json'] ) ? json_decode( $transaction['canonical_json'], true ) : null;
		$order = $case['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $case['order_id'] ) : false;
		$reasons = json_decode( $case['reasons_json'], true );
		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Back to queue', 'wpessential-bank-transfer-matcher-pro' ) . '</a></p>';
		echo '<h2>' . sprintf( esc_html__( 'Review case #%d', 'wpessential-bank-transfer-matcher-pro' ), $id ) . '</h2>';
		echo '<p><strong>' . esc_html( $case['priority'] ) . '</strong> · ' . esc_html( $case['status'] ) . ' · ' . esc_html( $case['case_type'] ) . '</p>';
		echo '<div class="wbm-review-grid"><section class="wbm-review-card"><h3>' . esc_html__( 'Bank evidence', 'wpessential-bank-transfer-matcher-pro' ) . '</h3>';
		if ( is_array( $bank ) ) {
			self::definition( __( 'Date', 'wpessential-bank-transfer-matcher-pro' ), $bank['posted_date'] ?? '—' );
			self::definition( __( 'Amount', 'wpessential-bank-transfer-matcher-pro' ), self::amount( (int) ( $bank['amount_minor'] ?? 0 ), (int) ( $bank['exponent'] ?? 2 ) ) . ' ' . ( $bank['currency'] ?? '' ) );
			self::definition( __( 'Reference', 'wpessential-bank-transfer-matcher-pro' ), self::mask( (string) ( $bank['reference'] ?? '' ) ) );
			self::definition( __( 'Description', 'wpessential-bank-transfer-matcher-pro' ), (string) ( $bank['description'] ?? '—' ) );
		} else echo '<p>' . esc_html__( 'Imported transaction evidence is unavailable.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		echo '</section><section class="wbm-review-card"><h3>' . esc_html__( 'Order comparison', 'wpessential-bank-transfer-matcher-pro' ) . '</h3>';
		if ( $order ) {
			self::definition( __( 'Order', 'wpessential-bank-transfer-matcher-pro' ), '#' . $order->get_id() );
			self::definition( __( 'Status', 'wpessential-bank-transfer-matcher-pro' ), $order->get_status() );
			self::definition( __( 'Total', 'wpessential-bank-transfer-matcher-pro' ), $order->get_total() . ' ' . $order->get_currency() );
			self::definition( __( 'Payment method', 'wpessential-bank-transfer-matcher-pro' ), $order->get_payment_method() );
		} else echo '<p>' . esc_html__( 'Linked WooCommerce order is unavailable or this case has no order candidate.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		echo '</section><section class="wbm-review-card"><h3>' . esc_html__( 'Decision context', 'wpessential-bank-transfer-matcher-pro' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Why this case exists', 'wpessential-bank-transfer-matcher-pro' ) . '</strong></p><ul>';
		foreach ( is_array( $reasons ) ? $reasons : [] as $reason ) echo '<li><code>' . esc_html( $reason ) . '</code></li>';
		echo '</ul><p>' . esc_html__( 'Reviewer actions update the case audit trail only. No suggestion confirms payment.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		if ( current_user_can( 'wbm_review_claim' ) ) self::actions( $case );
		else echo '<p>' . esc_html__( 'You have read-only access to this case.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		echo '</section></div>';
		echo '<h3>' . esc_html__( 'Comments', 'wpessential-bank-transfer-matcher-pro' ) . '</h3>';
		if ( [] === $comments ) echo '<p>' . esc_html__( 'No comments yet.', 'wpessential-bank-transfer-matcher-pro' ) . '</p>';
		else foreach ( $comments as $comment ) echo '<blockquote><p>' . esc_html( $comment['comment_text'] ) . '</p><cite>#' . (int) $comment['actor_id'] . ' · ' . esc_html( $comment['created_at'] ) . '</cite></blockquote>';
		echo '<h3>' . esc_html__( 'Audit history', 'wpessential-bank-transfer-matcher-pro' ) . '</h3><ol>';
		foreach ( $history as $event ) echo '<li><strong>' . esc_html( $event['event_type'] ) . '</strong> · #' . (int) $event['actor_id'] . ' · ' . esc_html( $event['created_at'] ) . '</li>';
		echo '</ol>';
	}

	/** @param array<string, mixed> $case */
	private static function actions( array $case ): void {
		$id = (int) $case['id'];
		$next = [
			'open' => [ 'assigned', 'dismissed' ],
			'assigned' => [ 'investigating', 'dismissed' ],
			'investigating' => [ 'awaiting_approval', 'resolved', 'dismissed' ],
			'awaiting_approval' => [ 'investigating', 'resolved', 'dismissed' ],
			'resolved' => [ 'reopened' ],
			'dismissed' => [ 'reopened' ],
			'reopened' => [ 'assigned', 'investigating', 'dismissed' ],
		][ $case['status'] ] ?? [];
		echo '<hr><h4>' . esc_html__( 'Reviewer actions', 'wpessential-bank-transfer-matcher-pro' ) . '</h4><div id="wbm-review-action-status" role="status"></div>';
		if ( [] !== $next ) {
			echo '<form class="wbm-review-action" data-path="/transition"><input type="hidden" name="from" value="' . esc_attr( $case['status'] ) . '"><label>' . esc_html__( 'Change state', 'wpessential-bank-transfer-matcher-pro' ) . ' <select name="to">';
			foreach ( $next as $status ) echo '<option value="' . esc_attr( $status ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</option>';
			echo '</select></label> <label>' . esc_html__( 'Reason', 'wpessential-bank-transfer-matcher-pro' ) . ' <select name="reason_code">';
			foreach ( [ 'evidence_reviewed', 'duplicate_confirmed', 'not_order_payment', 'mismatch_resolved', 'second_approval_required', 'new_evidence' ] as $reason ) echo '<option value="' . esc_attr( $reason ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $reason ) ) ) . '</option>';
			echo '</select></label> <button class="button">' . esc_html__( 'Update state', 'wpessential-bank-transfer-matcher-pro' ) . '</button></form>';
		}
		echo '<form class="wbm-review-action" data-path="/assign"><label>' . esc_html__( 'Assignee user ID', 'wpessential-bank-transfer-matcher-pro' ) . ' <input required type="number" min="1" name="assignee_id"></label> <button class="button">' . esc_html__( 'Assign', 'wpessential-bank-transfer-matcher-pro' ) . '</button></form>';
		echo '<form class="wbm-review-action" data-path="/priority"><label>' . esc_html__( 'Priority', 'wpessential-bank-transfer-matcher-pro' ) . ' <select name="priority">';
		foreach ( [ 'normal', 'high', 'critical' ] as $priority ) echo '<option value="' . esc_attr( $priority ) . '"' . selected( $case['priority'], $priority, false ) . '>' . esc_html( ucfirst( $priority ) ) . '</option>';
		echo '</select></label> <button class="button">' . esc_html__( 'Set priority', 'wpessential-bank-transfer-matcher-pro' ) . '</button></form>';
		echo '<form class="wbm-review-action" data-path="/comments"><label>' . esc_html__( 'Add comment', 'wpessential-bank-transfer-matcher-pro' ) . ' <textarea required maxlength="2000" name="comment"></textarea></label> <button class="button">' . esc_html__( 'Add comment', 'wpessential-bank-transfer-matcher-pro' ) . '</button></form>';
		$config = wp_json_encode( [ 'base' => rest_url( 'wpessential-wbm/v1/review-cases/' . $id ), 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
		if ( is_string( $config ) ) echo '<script>(function(c){document.querySelectorAll(".wbm-review-action").forEach(function(f){f.addEventListener("submit",async function(e){e.preventDefault();var s=document.getElementById("wbm-review-action-status");s.textContent="Processing…";var data=Object.fromEntries(new FormData(f).entries());try{var r=await fetch(c.base+f.dataset.path,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":c.nonce},body:JSON.stringify(data)});var b=await r.json();if(!r.ok){throw new Error(b.message||"Action failed.");}s.textContent="Saved.";window.location.reload();}catch(x){s.textContent=x.message;}});});})(' . $config . ');</script>';
	}

	private static function select( string $name, string $all, array $values, string $selected_value ): void {
		echo '<label class="wbm-pro-field" for="wbm-filter-' . esc_attr( $name ) . '"><span>' . esc_html( 'status' === $name ? __( 'Case state', 'wpessential-bank-transfer-matcher-pro' ) : __( 'Priority', 'wpessential-bank-transfer-matcher-pro' ) ) . '</span><select id="wbm-filter-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all ) . '</option>';
		foreach ( $values as $value ) printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $selected_value, $value, false ), esc_html( ucwords( str_replace( '_', ' ', $value ) ) ) );
		echo '</select></label> ';
	}

	private static function page_url( string $status, string $priority, int $assignee, int $page, int $limit ): string {
		return add_query_arg( [ 'page' => self::SLUG, 'status' => $status, 'priority' => $priority, 'assignee' => $assignee, 'paged' => $page, 'per_page' => $limit ], admin_url( 'admin.php' ) );
	}

	private static function definition( string $term, string $value ): void { echo '<dl><dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $value ) . '</dd></dl>'; }
	private static function amount( int $minor, int $exponent ): string { $digits = str_pad( (string) abs( $minor ), $exponent + 1, '0', STR_PAD_LEFT ); return ( $minor < 0 ? '-' : '' ) . ( 0 === $exponent ? $digits : substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent ) ); }
	private static function mask( string $value ): string { $value = trim( $value ); return '' === $value ? '—' : ( strlen( $value ) < 5 ? str_repeat( '•', strlen( $value ) ) : substr( $value, 0, 2 ) . '••••' . substr( $value, -2 ) ); }
}
