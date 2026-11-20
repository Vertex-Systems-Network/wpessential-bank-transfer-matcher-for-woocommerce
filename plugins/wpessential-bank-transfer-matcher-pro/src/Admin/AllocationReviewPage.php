<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only WordPress admin finance queue and audit. No mutations, AJAX,
 * payment_complete, bank refund, allocation approval or public REST actions.
 */
final class AllocationReviewPage {
	private const SLUG = 'wpessential-wbm-pro-allocations';
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 35 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/** Enqueue only in this finance workspace, never the customer storefront. */
	public static function enqueue(): void {
		$slug = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $slug ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) return;
		$plugin_file = dirname( __DIR__, 2 ) . '/wpessential-bank-transfer-matcher-pro.php';
		wp_enqueue_script( 'wbm-pro-finance-dialogs',
			plugins_url( 'assets/js/finance-dialogs.js', $plugin_file ), [],
			'0.1.0', true );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce', __( 'Bank allocations', self::DOMAIN ),
			__( 'Bank allocations', self::DOMAIN ), ProCaps::IMPORT, self::SLUG, [ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( ProCaps::IMPORT ) || ! current_user_can( FreeCaps::APPROVE ) ) {
			wp_die( esc_html__( 'Finance reconciliation permission is required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
		$db = $GLOBALS['wpdb'];
		echo '<div class="wrap"><h1>' . esc_html__( 'Bank allocation ledger', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Ledger and audit views are read-only. Explicit finance forms can propose and independently approve allocations or record correction holds. Proposed credits are not funded; approved credits do not mark WooCommerce paid. This page cannot complete payments or issue refunds.', self::DOMAIN ) . '</p>';
		try {
			( new AllocationLedgerRepository( $db ) )->install();
			$id = isset( $_GET['allocation_id'] ) ? absint( $_GET['allocation_id'] ) : 0;
			if ( $id > 0 ) self::workspace( $db, $id );
			else self::queue( $db );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Financial records cannot be read or validated. Investigate the ledger before continuing.', self::DOMAIN ) . '</p></div>';
		}
		echo '</div>';
	}

	private static function queue( wpdb $db ): void {
		$status = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$status = in_array( $status, [ '', 'proposed', 'approved', 'committed', 'reversed' ], true ) ? $status : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit = isset( $_GET['per_page'] ) && in_array( absint( $_GET['per_page'] ), [ 20, 50 ], true )
			? absint( $_GET['per_page'] ) : 20;
		$table = $db->prefix . 'wbm_pro_allocations';
		$filters = [];
		$params = [];
		if ( '' !== $status ) { $filters[] = 'state = %s'; $params[] = $status; }
		if ( $order_id > 0 ) { $filters[] = 'order_id = %d'; $params[] = $order_id; }
		$where = $filters ? ' WHERE ' . implode( ' AND ', $filters ) : '';
		// Fetch one extra bounded row to avoid a phantom Next link on an
		// exact-full final page. Never display the lookahead financial row.
		$params[] = $limit + 1;
		$params[] = ( $page - 1 ) * $limit;
		$rows = $db->get_results( $db->prepare(
			"SELECT id, order_id, transaction_row_id, bank_account_id, amount_minor, credit_minor,
			 expected_minor, currency, exponent, state, created_by, approved_by, split_group_key,
			 created_at, approved_at
			 FROM $table$where ORDER BY id DESC LIMIT %d OFFSET %d", ...$params
		), ARRAY_A );
		self::check( $db );
		if ( ! is_array( $rows ) ) throw new RuntimeException( 'Financial allocation list missing.' );
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) array_pop( $rows );
		echo '<form class="wbm-pro-toolbar" method="get" role="search" aria-label="' . esc_attr__( 'Filter finance allocations', self::DOMAIN ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label class="wbm-pro-field" for="wbm-allocation-status"><span>' . esc_html__( 'Allocation state', self::DOMAIN ) . '</span>';
		echo '<select id="wbm-allocation-status" name="state"><option value="">' .
			esc_html__( 'All allocation states', self::DOMAIN ) . '</option>';
		foreach ( [ 'proposed', 'approved', 'committed', 'reversed' ] as $option ) {
			printf( '<option value="%s"%s>%s</option>',
				esc_attr( $option ), selected( $status, $option, false ), esc_html( $option ) );
		}
		echo '</select></label> <label class="wbm-pro-field" for="wbm-allocation-order"><span>' .
			esc_html__( 'Order ID', self::DOMAIN ) . '</span>';
		echo '<input id="wbm-allocation-order" type="number" min="1" name="order_id" value="' .
			esc_attr( $order_id ?: '' ) . '"></label> ';
		echo '<label class="wbm-pro-field" for="wbm-allocation-page-size"><span>' . esc_html__( 'Rows per page', self::DOMAIN ) . '</span><select id="wbm-allocation-page-size" name="per_page">';
		foreach ( [ 20, 50 ] as $size ) {
			printf( '<option value="%d"%s>%d</option>', $size, selected( $limit, $size, false ), $size );
		}
		echo '</select></label> ';
		submit_button( __( 'Filter allocations', self::DOMAIN ), 'secondary', '', false );
		echo '</form>';
		echo '<details data-wbm-finance-dialog style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0"><summary><strong>' .
			esc_html__( 'Propose a bank credit for independent review', self::DOMAIN ) .
			'</strong></summary><p>' .
			esc_html__( 'A proposal does not allocate or mark an order paid. The source, bank account, exact reference and outstanding order balance are checked again during independent approval.', self::DOMAIN ) .
			'</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wbm_pro_allocation_propose">';
		echo '<input type="hidden" name="request_key" value="' . esc_attr( hash( 'sha256', wp_generate_uuid4() ) ) . '">';
		wp_nonce_field( 'wbm_pro_allocation_propose' );
		echo '<p><label>' . esc_html__( 'Unpaid BACS order ID', self::DOMAIN ) .
			' <input name="order_id" type="number" min="1" required></label> ';
		echo '<label>' . esc_html__( 'Completed imported bank source ID', self::DOMAIN ) .
			' <input name="source_id" type="number" min="1" required></label></p>';
		echo '<p><label for="wbm-proposal-reason">' . esc_html__( 'Finance proposal reason', self::DOMAIN ) .
			'</label></p><textarea id="wbm-proposal-reason" name="reason" rows="3" cols="75" required minlength="20" maxlength="500"></textarea>';
		submit_button( __( 'Create proposal — does not pay the order', self::DOMAIN ), 'secondary' );
		echo '</form></details>';
		if ( ! $rows ) {
			echo '<p role="status" class="wbm-pro-empty">' . esc_html__( 'No allocations match these filters.', self::DOMAIN ) . '</p>';
			if ( $page > 1 ) {
				echo '<p><a class="button" href="' . esc_url( self::queue_url( $status, $order_id, $page - 1, $limit ) ) . '">' . esc_html__( 'Previous source page', self::DOMAIN ) . '</a></p>';
			}
			return;
		}
		// Totals are page-scoped and segregated by currency, precision and
		// state. Never sum proposed money as an approved or paid balance.
		$totals = [];
		foreach ( $rows as $row ) {
			$minor = self::unsigned( $row['amount_minor'] );
			$exponent = self::exponent( $row['exponent'] );
			$currency = self::currency( (string) $row['currency'] );
			$key = $currency . '/' . $exponent . '/' . $row['state'];
			$prev = $totals[$key] ?? 0;
			if ( $minor < 1 || $minor > PHP_INT_MAX - $prev ) {
				throw new RuntimeException( 'A ledger amount is malformed or exceeds the safe display range.' );
			}
			$totals[$key] = $prev + $minor;
		}
		echo '<h2>' . esc_html__( 'Visible page totals by currency and state', self::DOMAIN ) . '</h2><p>';
		foreach ( $totals as $key => $amount ) {
			[ $currency, $precision, $state ] = explode( '/', $key );
			echo '<span style="display:inline-block;margin:0 12px 8px 0"><strong>' .
				esc_html( self::money( $amount, (int) $precision ) . ' ' . $currency ) .
				'</strong> <small>' . esc_html( $state ) . '</small></span>';
		}
		echo '</p><div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Allocation ledger by currency and approval state', self::DOMAIN ) . '"><table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( [ 'Allocation', 'WooCommerce order', 'Imported source', 'Allocated amount',
			'Source credit', 'Order expected', 'State', 'Bank account', 'Split group', 'Reviewer', 'Evidence' ] as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg( [ 'page' => self::SLUG, 'allocation_id' => (int) $row['id'] ],
				admin_url( 'admin.php' ) );
			$amount = self::money( self::unsigned( $row['amount_minor'] ), self::exponent( $row['exponent'] ) );
			$expected = self::money( self::unsigned( $row['expected_minor'] ), self::exponent( $row['exponent'] ) );
			$source_credit = self::money( self::unsigned( $row['credit_minor'] ), self::exponent( $row['exponent'] ) );
			$split = is_string( $row['split_group_key'] ) && '' !== $row['split_group_key']
				? substr( $row['split_group_key'], 0, 12 ) . '…' : '—';
			printf( '<tr><td>#%d</td><td>#%d</td><td>#%d</td><td>%s %s</td><td>%s %s</td><td>%s %s</td><td>%s</td><td>#%d</td><td><code>%s</code></td><td>%s</td><td><a href="%s">%s</a></td></tr>',
				(int) $row['id'], (int) $row['order_id'], (int) $row['transaction_row_id'],
				esc_html( $amount ), esc_html( $row['currency'] ),
				esc_html( $source_credit ), esc_html( $row['currency'] ),
				esc_html( $expected ), esc_html( $row['currency'] ),
				esc_html( $row['state'] ), (int) $row['bank_account_id'],
				esc_html( $split ), $row['approved_by'] ? '#' . (int) $row['approved_by'] :
				esc_html__( 'Awaiting reviewer', self::DOMAIN ),
				esc_url( $url ), esc_html__( 'View audit', self::DOMAIN ) );
		}
		echo '</tbody></table></div><nav class="wbm-pro-pagination" aria-label="' . esc_attr__( 'Allocation ledger pages', self::DOMAIN ) . '">';
		if ( $page > 1 ) echo '<a class="button" href="' .
			esc_url( self::queue_url( $status, $order_id, $page - 1, $limit ) ) . '">' .
			esc_html__( 'Previous', self::DOMAIN ) . '</a> ';
		if ( $has_more ) echo '<a class="button" href="' .
			esc_url( self::queue_url( $status, $order_id, $page + 1, $limit ) ) . '">' .
			esc_html__( 'Next', self::DOMAIN ) . '</a>';
		echo '</nav>';
	}

	private static function workspace( wpdb $db, int $id ): void {
		$table = $db->prefix . 'wbm_pro_allocations';
		$row = $db->get_row( $db->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		self::check( $db );
		if ( ! $row ) throw new InvalidArgumentException( 'Unknown financial allocation.' );
		$order_id = (int) $row['order_id'];
		$source_id = (int) $row['transaction_row_id'];
		$precision = self::exponent( $row['exponent'] );
		$currency = self::currency( (string) $row['currency'] );
		$allocated = self::money( self::unsigned( $row['amount_minor'] ), $precision );
		$expected = self::money( self::unsigned( $row['expected_minor'] ), $precision );
		$credit = self::money( self::unsigned( $row['credit_minor'] ), $precision );
		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::SLUG,
			admin_url( 'admin.php' ) ) ) . '">&larr; ' .
			esc_html__( 'Back to allocation ledger', self::DOMAIN ) . '</a></p>';
		echo '<h2>' . sprintf( esc_html__( 'Allocation #%d — finance evidence', self::DOMAIN ), $id ) . '</h2>';
		echo '<p><strong>' . esc_html( $row['state'] ) . '</strong> · ' .
			esc_html__( 'Read-only; independent approval and explicit finalization are separate operations.', self::DOMAIN ) . '</p>';
		echo '<div class="wbm-finance-grid"><section class="wbm-finance-card"><h3>' .
			esc_html__( 'Allocation and provenance', self::DOMAIN ) . '</h3>';
		self::definition( 'Amount allocated', $allocated . ' ' . $currency );
		self::definition( 'Order total at proposal', $expected . ' ' . $currency );
		self::definition( 'Imported source amount', $credit . ' ' . $currency );
		self::definition( 'Bank account', '#' . $row['bank_account_id'] );
		self::definition( 'Imported source', '#' . $source_id );
		self::definition( 'Created by', '#' . $row['created_by'] );
		self::definition( 'Approved by', $row['approved_by'] ? '#' . $row['approved_by'] : 'Awaiting independent approval' );
		self::definition( 'Split group', $row['split_group_key'] ?: 'One source / one recipient' );
		echo '</section><section class="wbm-finance-card"><h3>' .
			esc_html__( 'Live WooCommerce order', self::DOMAIN ) . '</h3>';
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::definition( 'Order', '#' . $order_id );
			self::definition( 'Current status', $order->get_status() );
			self::definition( 'Current payment gateway', $order->get_payment_method() );
			self::definition( 'Current order total', (string) $order->get_total( 'edit' ) . ' ' . $order->get_currency() );
			self::definition( 'WooCommerce paid', $order->is_paid() ? 'Yes — WooCommerce state' : 'No — WooCommerce state' );
		} else {
			echo '<p>' . esc_html__( 'Linked order is unavailable; no allocation can be authorized from this view.', self::DOMAIN ) . '</p>';
		}
		echo '</section></div>';
		$import = $db->prefix . 'wbm_pro_import_rows';
		$source = $db->get_row( $db->prepare(
			"SELECT identity_fingerprint, canonical_json, error_code, warning_code
			 FROM $import WHERE id = %d", $source_id
		), ARRAY_A );
		self::check( $db );
		echo '<div class="wbm-finance-card"><h3>' . esc_html__( 'Imported bank source', self::DOMAIN ) . '</h3>';
		if ( $source ) {
			$bank = json_decode( (string) $source['canonical_json'], true );
			self::definition( 'Source identity', substr( (string) $source['identity_fingerprint'], 0, 16 ) . '…' );
			self::definition( 'Warning', '' !== (string) $source['warning_code'] ? $source['warning_code'] : 'No import warning recorded' );
			self::definition( 'Parse error', '' !== (string) $source['error_code'] ? $source['error_code'] : 'No import error recorded' );
			if ( is_array( $bank ) ) {
				self::definition( 'Bank posted date', (string) ( $bank['posted_date'] ?? 'Unavailable' ) );
				self::definition( 'Transfer reference (masked)', self::mask( (string) ( $bank['reference'] ?? '' ) ) );
			}
		} else echo '<p>' . esc_html__( 'Imported source is missing; financial provenance cannot be verified.', self::DOMAIN ) . '</p>';
		echo '</div>';
		$case_held = self::corrections( $db, $order_id, $source_id );
		self::action_panels( $row, $case_held );
		self::audit( $db, $id );
		echo '<p><em>' . esc_html__( 'No automatic payment or refund is available. Financial form actions require a separate authorized finance actor, a nonce, and an audited reason; a correction review never refunds or releases a hold automatically.', self::DOMAIN ) . '</em></p>';
	}

	private static function corrections( wpdb $db, int $order_id, int $source_id ): bool {
		$table = $db->prefix . 'wbm_pro_correction_cases';
		if ( ! self::table_exists( $db, $table ) ) return false;
		$rows = $db->get_results( $db->prepare(
			"SELECT id, case_type, status, allocation_id, created_by, reviewed_by,
			 review_decision, created_at FROM $table
			 WHERE order_id = %d OR transaction_row_id = %d ORDER BY id DESC LIMIT 30",
			$order_id, $source_id
		), ARRAY_A );
		self::check( $db );
		if ( ! $rows ) return false;
		echo '<div class="notice notice-warning"><p><strong>' .
			esc_html__( 'Financial hold: correction or reversal investigation exists. Reviewer annotation never releases this hold.', self::DOMAIN ) .
			'</strong></p></div>';
		echo '<h3>' . esc_html__( 'Correction and reversal investigations', self::DOMAIN ) . '</h3>';
		echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Financial correction and reversal case records', self::DOMAIN ) . '"><table class="wp-list-table widefat striped"><thead><tr><th>Case</th><th>Allocation</th><th>Issue</th><th>Review</th><th>Maker</th><th>Checker</th><th>Created</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>#%d</td><td>#%d</td><td>%s</td><td>%s%s</td><td>#%d</td><td>%s</td><td>%s</td></tr>',
				(int) $row['id'], (int) $row['allocation_id'],
				esc_html( $row['case_type'] ), esc_html( $row['status'] ),
				$row['review_decision'] ? ' / ' . esc_html( $row['review_decision'] ) : '',
				(int) $row['created_by'], $row['reviewed_by'] ? '#' . (int) $row['reviewed_by'] : '—',
				esc_html( $row['created_at'] ) );
		}
		echo '</tbody></table></div>';
		$case_ids = array_map( static fn ( array $item ): int => (int) $item['id'], $rows );
		$events_table = $db->prefix . 'wbm_pro_correction_events';
		$placeholders = implode( ', ', array_fill( 0, count( $case_ids ), '%d' ) );
		$events = $db->get_results( $db->prepare(
			"SELECT correction_case_id, actor_id, event_type, event_json, created_at
			 FROM $events_table WHERE correction_case_id IN ($placeholders)
			 ORDER BY id ASC LIMIT 100", ...$case_ids
		), ARRAY_A );
		self::check( $db );
		if ( ! is_array( $events ) ) throw new RuntimeException( 'Correction audit is unavailable.' );
		echo '<h3>' . esc_html__( 'Append-only correction audit history', self::DOMAIN ) . '</h3>';
		echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Append-only correction event history', self::DOMAIN ) . '"><table class="wp-list-table widefat striped"><thead><tr><th>Case</th><th>Event</th><th>Finance actor</th><th>Recorded (UTC)</th><th>Review note</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$payload = json_decode( (string) $event['event_json'], true );
			$reason = is_array( $payload ) && is_string( $payload['reason'] ?? null )
				? $payload['reason'] : '';
			printf( '<tr><td>#%d</td><td>%s</td><td>#%d</td><td>%s</td><td>%s</td></tr>',
				(int) $event['correction_case_id'], esc_html( $event['event_type'] ),
				(int) $event['actor_id'], esc_html( $event['created_at'] ), esc_html( $reason ) );
		}
		echo '</tbody></table></div>';
		self::correction_review_forms( $rows );
		return true;
	}

	private static function action_panels( array $row, bool $case_held ): void {
		$state = (string) $row['state'];
		$id = (int) $row['id'];
		if ( $case_held ) {
			echo '<p><strong>' . esc_html__( 'Actions held: this bank source or order has an unresolved correction. No approval or payment can proceed.', self::DOMAIN ) . '</strong></p>';
			return;
		}
		if ( 'proposed' === $state && (int) $row['created_by'] !== get_current_user_id() ) {
			echo '<details class="wbm-finance-card" data-wbm-finance-dialog><summary><strong>' .
				esc_html__( 'Independent finance allocation approval', self::DOMAIN ) .
				'</strong></summary><p>' .
				esc_html__( 'Confirm cleared bank funds only after verifying the original bank statement and all target orders. This approval records the allocation but does not mark WooCommerce paid.', self::DOMAIN ) .
				'</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wbm_pro_allocation_approve">';
			echo '<input type="hidden" name="allocation_id" value="' . $id . '">';
			echo '<input type="hidden" name="split_group_key" value="' .
				esc_attr( (string) ( $row['split_group_key'] ?? '' ) ) . '">';
			wp_nonce_field( 'wbm_pro_allocation_approve' );
			echo '<p><label><input type="checkbox" name="cleared_confirmed" value="1" required> ' .
				esc_html__( 'I independently verified the entire bank credit has cleared', self::DOMAIN ) .
				'</label></p><p><label for="wbm-approval-reason">' .
				esc_html__( 'Independent review reason', self::DOMAIN ) .
				'</label></p><textarea id="wbm-approval-reason" name="reason" rows="3" cols="75" required minlength="20" maxlength="500"></textarea>';
			submit_button( __( 'Record approval — do not mark order paid', self::DOMAIN ), 'primary' );
			echo '</form></details>';
		} elseif ( 'proposed' === $state ) {
			echo '<p>' . esc_html__( 'Awaiting a separate finance reviewer. The proposer cannot approve their own source.', self::DOMAIN ) . '</p>';
		}
		if ( in_array( $state, [ 'approved', 'committed' ], true ) ) {
			echo '<details class="wbm-finance-card" data-wbm-finance-dialog><summary><strong>' .
				esc_html__( 'Open a correction or reversal investigation (financial hold)', self::DOMAIN ) .
				'</strong></summary><p>' .
				esc_html__( 'Recording a suspected bank reversal or wrong attribution does not refund money or change the ledger; it places all related recipients on hold.', self::DOMAIN ) .
				'</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wbm_pro_correction_raise">';
			echo '<input type="hidden" name="allocation_id" value="' . $id . '">';
			echo '<input type="hidden" name="request_key" value="' . esc_attr( hash( 'sha256', wp_generate_uuid4() ) ) . '">';
			wp_nonce_field( 'wbm_pro_correction_raise' );
			echo '<p><label>' . esc_html__( 'Investigation type', self::DOMAIN ) . ' <select name="case_type" required>';
			foreach ( [ 'bank_reversal_notice', 'duplicate_credit', 'wrong_order', 'amount_dispute',
				'refund_investigation', 'source_correction' ] as $kind ) {
				echo '<option value="' . esc_attr( $kind ) . '">' . esc_html( $kind ) . '</option>';
			}
			echo '</select></label></p><p><label for="wbm-correction-reason">' .
				esc_html__( 'Evidence and case reason', self::DOMAIN ) .
				'</label></p><textarea id="wbm-correction-reason" name="reason" rows="3" cols="75" required minlength="20" maxlength="500"></textarea>';
			submit_button( __( 'Open investigation and hold the source', self::DOMAIN ), 'secondary' );
			echo '</form></details>';
		}
	}

	/** @param list<array<string,mixed>> $rows */
	private static function correction_review_forms( array $rows ): void {
		foreach ( $rows as $case ) {
			if ( 'open' !== $case['status'] ||
				(int) $case['created_by'] === get_current_user_id() ) continue;
			echo '<details class="wbm-finance-card" data-wbm-finance-dialog><summary><strong>' .
				sprintf( esc_html__( 'Independent correction case #%d review', self::DOMAIN ), (int) $case['id'] ) .
				'</strong></summary><p>' .
				esc_html__( 'Review notes are audit-only. Even a rejected or reviewed case still holds the bank source pending an authorized resolution.', self::DOMAIN ) .
				'</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wbm_pro_correction_review">';
			echo '<input type="hidden" name="case_id" value="' . (int) $case['id'] . '">';
			wp_nonce_field( 'wbm_pro_correction_review' );
			echo '<p><label>' . esc_html__( 'Review decision', self::DOMAIN ) .
				' <select name="review_decision" required>';
			foreach ( [ 'continue_hold', 'request_bank_restatement',
				'request_external_refund_review', 'reject_unsupported_claim' ] as $decision ) {
				echo '<option value="' . esc_attr( $decision ) . '">' . esc_html( $decision ) . '</option>';
			}
			echo '</select></label></p><p><label for="wbm-review-reason-' . (int) $case['id'] . '">' .
				esc_html__( 'Independent reviewer findings', self::DOMAIN ) .
				'</label></p><textarea id="wbm-review-reason-' . (int) $case['id'] .
				'" name="reason" rows="3" cols="75" required minlength="20" maxlength="500"></textarea>';
			submit_button( __( 'Record audit-only decision — hold remains', self::DOMAIN ), 'secondary' );
			echo '</form></details>';
		}
	}

	private static function audit( wpdb $db, int $allocation_id ): void {
		$table = $db->prefix . 'wbm_pro_allocation_events';
		$events = $db->get_results( $db->prepare(
			"SELECT event_type, actor_id, created_at, event_json FROM $table
			 WHERE allocation_id = %d ORDER BY id ASC LIMIT 100", $allocation_id
		), ARRAY_A );
		self::check( $db );
		if ( ! is_array( $events ) ) throw new RuntimeException( 'Allocation audit cannot be read.' );
		echo '<h3>' . esc_html__( 'Append-only allocation audit history', self::DOMAIN ) . '</h3>';
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No audit events were found; this allocation cannot be trusted without review.', self::DOMAIN ) . '</p>';
			return;
		}
		echo '<div class="wbm-pro-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Append-only allocation audit history', self::DOMAIN ) . '"><table class="wp-list-table widefat striped"><thead><tr><th>Event</th><th>Actor</th><th>Recorded (UTC)</th><th>Reason</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$payload = json_decode( (string) $event['event_json'], true );
			$reason = is_array( $payload ) && is_string( $payload['reason'] ?? null )
				? $payload['reason'] : '';
			printf( '<tr><td>%s</td><td>#%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( $event['event_type'] ), (int) $event['actor_id'],
				esc_html( $event['created_at'] ), esc_html( $reason ) );
		}
		echo '</tbody></table></div>';
	}

	private static function money( int $minor, int $exponent ): string {
		$exponent = self::exponent( $exponent );
		if ( $minor < 0 ) throw new InvalidArgumentException( 'Negative financial snapshot.' );
		$digits = str_pad( (string) $minor, $exponent + 1, '0', STR_PAD_LEFT );
		if ( 0 === $exponent ) return $digits;
		return substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}
	private static function unsigned( mixed $raw ): int {
		if ( is_int( $raw ) && $raw >= 0 ) return $raw;
		if ( ! is_string( $raw ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $raw ) ||
			strlen( $raw ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $raw ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $raw, (string) PHP_INT_MAX ) > 0 ) ) throw new InvalidArgumentException( 'Unsafe allocation amount.' );
		return (int) $raw;
	}
	private static function exponent( mixed $raw ): int {
		$exp = self::unsigned( $raw );
		if ( $exp > 3 ) throw new InvalidArgumentException( 'Unsafe currency precision.' );
		return $exp;
	}
	private static function currency( string $value ): string {
		if ( 1 !== preg_match( '/\A[A-Z]{3}\z/', $value ) ) throw new InvalidArgumentException( 'Unknown currency.' );
		return $value;
	}
	private static function mask( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) return '—';
		return strlen( $value ) < 5 ? str_repeat( '•', strlen( $value ) )
			: substr( $value, 0, 2 ) . '••••' . substr( $value, -2 );
	}
	private static function definition( string $label, string $value ): void {
		echo '<dl><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></dl>';
	}
	private static function check( wpdb $db ): void {
		if ( '' !== $db->last_error ) throw new RuntimeException( 'Finance audit storage error.' );
	}
	private static function table_exists( wpdb $db, string $table ): bool {
		$exists = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );
		self::check( $db );
		return $exists === $table;
	}
	private static function queue_url( string $status, int $order_id, int $page, int $limit ): string {
		return add_query_arg( [
			'page' => self::SLUG, 'state' => $status, 'order_id' => $order_id,
			'paged' => $page, 'per_page' => $limit,
		], admin_url( 'admin.php' ) );
	}
}
