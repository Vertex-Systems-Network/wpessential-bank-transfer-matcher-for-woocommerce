<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reporting\FinanceDashboardSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce-native finance dashboard. No payout, approve or ledger mutation.
 * Every displayed aggregate is only a bounded PAGE of source-backed rows.
 */
final class FinanceDashboardPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-dashboard';
	private const LABELS = [
		'outstanding_orders' => 'Outstanding BACS orders',
		'partial_funding' => 'Partially funded orders',
		'unmatched_credits' => 'Unmatched/ambiguous bank credits',
		'exception_aging' => 'Exception aging',
		'import_batches' => 'Statement import batches',
		'allocation_audit' => 'Allocation audit',
	];
	private const STATES = [
		'outstanding_orders' => [ 'all', 'pending', 'on-hold' ],
		'partial_funding' => [ 'all', 'pending', 'on-hold' ],
		'unmatched_credits' => [ 'all', 'unmatched', 'ambiguous', 'reviewed' ],
		'exception_aging' => [ 'all', 'open', 'reviewed' ],
		'import_batches' => [ 'all', 'pending', 'processing', 'completed', 'failed', 'cancelled' ],
		'allocation_audit' => [ 'all', 'proposed', 'approved', 'committed', 'reversed' ],
	];
	private const COLUMNS = [
		'outstanding_orders' => [ 'order_id', 'bank_account_id', 'currency', 'state', 'expected_minor', 'funded_minor', 'amount_minor' ],
		'partial_funding' => [ 'order_id', 'bank_account_id', 'currency', 'state', 'expected_minor', 'funded_minor', 'amount_minor' ],
		'unmatched_credits' => [ 'source_id', 'bank_account_id', 'currency', 'state', 'amount_minor', 'ambiguous_bank_source' ],
		'exception_aging' => [ 'case_id', 'case_type', 'bank_account_id', 'currency', 'state', 'age_band', 'financial_hold' ],
		'import_batches' => [ 'id', 'bank_account_id', 'currency', 'state', 'valid_count', 'error_count', 'warning_count' ],
		'allocation_audit' => [ 'id', 'order_id', 'bank_account_id', 'currency', 'state', 'amount_minor' ],
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 33 );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Bank finance dashboard', self::DOMAIN ),
			__( 'Bank finance dashboard', self::DOMAIN ), ProCaps::IMPORT,
			self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ) {
			wp_die( esc_html__( 'Finance reporting permission required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Bank finance dashboard', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only, source-backed finance report pages. Figures below are not full-ledger totals, bank-cleared balances or proof of payment. Exact amounts use minor units; currency/exponent and financial state remain separate. No imported claim can mark an order paid.', self::DOMAIN ) . '</p>';
		try {
			$filters = self::filters();
			$data = ( new FinanceDashboardSnapshot( $GLOBALS['wpdb'] ) )->read( $filters );
			self::render_filters( $data['definition'] );
			self::render_cards( $data['groups'], $data['definition'] );
			self::render_focus( $data['focused'], $data['definition'] );
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Dashboard filters are invalid or source reports are temporarily unavailable. No financial status was changed.', self::DOMAIN ) .
				'</p></div>';
		}
		echo '</div>';
	}

	private static function filters(): array {
		$input = wp_unslash( $_GET );
		if ( ! is_array( $input ) ) throw new InvalidArgumentException( 'Dashboard filters must be scalar.' );
		$from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$to = gmdate( 'Y-m-d' );
		$filters = [ 'from' => $from, 'to' => $to, 'currency' => 'ALL',
			'bank_account_id' => '0', 'focus_report' => 'outstanding_orders',
			'focus_state' => 'all', 'limit' => 20, 'before_id' => '0' ];
		foreach ( [ 'from', 'to', 'currency', 'bank_account_id', 'focus_report',
			'focus_state', 'before_id' ] as $field ) {
			if ( isset( $input[$field] ) ) {
				if ( ! is_string( $input[$field] ) ) throw new InvalidArgumentException( 'Invalid dashboard filter.' );
				$filters[$field] = $input[$field];
			}
		}
		if ( isset( $input['limit'] ) ) {
			if ( ! is_string( $input['limit'] ) ||
				! in_array( $input['limit'], [ '20', '50', '100' ], true ) ) {
				throw new InvalidArgumentException( 'Invalid dashboard page size.' );
			}
			$filters['limit'] = (int) $input['limit'];
		}
		return $filters;
	}

	private static function render_filters( array $d ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( [ 'from' => __( 'From (UTC)', self::DOMAIN ),
			'to' => __( 'To (UTC)', self::DOMAIN ) ] as $field => $label ) {
			echo '<tr><th scope="row"><label for="wbm-dash-' . esc_attr( $field ) . '">' .
				esc_html( $label ) . '</label></th><td><input type="date" required id="wbm-dash-' .
				esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' .
				esc_attr( $d[$field] ) . '"></td></tr>';
		}
		echo '<tr><th scope="row"><label for="wbm-dash-currency">' .
			esc_html__( 'ISO currency / ALL', self::DOMAIN ) .
			'</label></th><td><input required id="wbm-dash-currency" maxlength="3" pattern="ALL|[A-Z]{3}" name="currency" value="' .
			esc_attr( $d['currency'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-dash-account">' .
			esc_html__( 'Bank account ID (0 for all)', self::DOMAIN ) .
			'</label></th><td><input id="wbm-dash-account" type="number" min="0" step="1" name="bank_account_id" value="' .
			esc_attr( (string) $d['bank_account_id'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-dash-focus">' .
			esc_html__( 'Focus details', self::DOMAIN ) . '</label></th><td>';
		echo '<select id="wbm-dash-focus" name="focus_report">';
		foreach ( self::LABELS as $report => $label ) {
			echo '<option value="' . esc_attr( $report ) . '" ' .
				selected( $d['focus_report'], $report, false ) . '>' .
				esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-dash-state">' .
			esc_html__( 'Focused report state', self::DOMAIN ) .
			'</label></th><td><select id="wbm-dash-state" name="focus_state">';
		foreach ( self::STATES[$d['focus_report']] as $state ) {
			echo '<option value="' . esc_attr( $state ) . '" ' .
				selected( $d['focus_state'], $state, false ) . '>' .
				esc_html( $state ) . '</option>';
		}
		echo '</select> <span class="description">' .
			esc_html__( 'Switching report types? Select All before applying, or use a report card.', self::DOMAIN ) .
			'</span></td></tr>';
		echo '<tr><th scope="row"><label for="wbm-dash-limit">' .
			esc_html__( 'Source records per report page', self::DOMAIN ) .
			'</label></th><td><select id="wbm-dash-limit" name="limit">';
		foreach ( [ 20, 50, 100 ] as $size ) {
			echo '<option value="' . esc_attr( (string) $size ) . '" ' .
				selected( $d['limit'], $size, false ) . '>' . esc_html( (string) $size ) .
				'</option>';
		}
		echo '</select></td></tr></tbody></table>';
		submit_button( __( 'Apply read-only report filters', self::DOMAIN ) );
		echo '</form>';
	}

	private static function render_cards( array $groups, array $scope ): void {
		echo '<h2>' . esc_html__( 'Source-backed report pages', self::DOMAIN ) . '</h2>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px">';
		foreach ( self::LABELS as $type => $label ) {
			$group = $groups[$type];
			echo '<div class="postbox" style="padding:14px;margin:0">';
			echo '<h3 style="margin-top:0">' . esc_html( $label ) . '</h3>';
			if ( ! $group['available'] ) {
				echo '<p>' . esc_html__( 'Source unavailable — not zero.', self::DOMAIN ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html( number_format_i18n( $group['row_count'] ) ) .
					'</strong> ' . esc_html__( 'records on this page', self::DOMAIN ) . '</p>';
				if ( $group['has_more'] ) echo '<p>' .
					esc_html__( 'More source records exist — page totals are incomplete.', self::DOMAIN ) . '</p>';
				foreach ( $group['page_totals'] as $total ) {
					// Currency + exponent + state must always be shown, never added
					// across currency, precision or approval/paid distinctions.
					if ( 'exception_aging' === $type || 'import_batches' === $type ) {
						echo '<p>' . esc_html( sprintf( '%s / %s — %d cases/records',
							$total['currency'], $total['state'], $total['count'] ) ) . '</p>';
					} else {
						echo '<p>' . esc_html( sprintf(
							'%s (exp %d) / %s: %d minor units in %d records',
							$total['currency'], $total['exponent'], $total['state'],
							$total['amount_minor'], $total['count']
						) ) . '</p>';
					}
				}
			}
			$query = array_merge( $scope, [
				'page' => self::SLUG, 'focus_report' => $type,
				'focus_state' => 'all', 'before_id' => 0,
			] );
			echo '<p><a href="' . esc_url( add_query_arg( $query,
				admin_url( 'admin.php' ) ) ) . '">' .
				esc_html__( 'View filtered report page', self::DOMAIN ) . '</a></p>';
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_focus( ?array $result, array $scope ): void {
		echo '<h2>' . esc_html( self::LABELS[$scope['focus_report']] ) . ' — ' .
			esc_html__( 'bounded source details', self::DOMAIN ) . '</h2>';
		if ( null === $result ) {
			echo '<p>' . esc_html__( 'Focused source unavailable; financial totals cannot be inferred.', self::DOMAIN ) . '</p>';
			return;
		}
		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No matching records on this source page.', self::DOMAIN ) . '</p>';
			return;
		}
		$columns = self::COLUMNS[$scope['focus_report']];
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( $columns as $key ) echo '<th scope="col">' . esc_html( str_replace( '_', ' ', $key ) ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $result['rows'] as $record ) {
			echo '<tr>';
			foreach ( $columns as $key ) {
				$value = $record[$key] ?? '—';
				if ( is_bool( $value ) ) $value = $value ? 'yes' : 'no';
				echo '<td>' . esc_html( (string) $value ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' .
			esc_html__( 'These are exact source-page minor units and states, not verified bank balances. Non-additive exception cases cannot be totaled as money.', self::DOMAIN ) . '</p>';
		if ( $result['has_more'] && $result['next_before_id'] > 0 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg(
				array_merge( $scope, [ 'page' => self::SLUG,
					'before_id' => (int) $result['next_before_id'] ] ),
				admin_url( 'admin.php' ) ) ) . '">' .
				esc_html__( 'Next bounded source page', self::DOMAIN ) . '</a>';
		}
	}
}
