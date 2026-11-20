<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationPreferences;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationService;
use WPEssential\BankTransferMatcherPro\Notifications\FinanceNotificationTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in only. Finance recipient configuration and tests are restricted to
 * bank-account managers with matching Free+Pro finance capabilities.
 */
final class FinanceNotificationsPage {
	private const DOMAIN = 'wpessential-bank-transfer-matcher-pro';
	private const SLUG = 'wpessential-wbm-pro-notifications';
	private const SAVE = 'wbm_pro_save_finance_notifications';
	private const TEST = 'wbm_pro_test_finance_notification';

	private const LABELS = [
		'exception_digest' => 'Open exception digest',
		'pending_review_digest' => 'Pending finance review digest',
		'mismatch_digest' => 'Bank statement mismatch digest',
		'reminder_digest' => 'Reviews older than 48 hours',
	];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 37 );
		add_action( 'admin_post_' . self::SAVE, [ self::class, 'save' ] );
		add_action( 'admin_post_' . self::TEST, [ self::class, 'send_test' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Finance notifications', self::DOMAIN ),
			__( 'Finance notifications', self::DOMAIN ),
			ProCaps::MANAGE_ACCOUNTS, self::SLUG, [ self::class, 'render' ] );
	}

	public static function render(): void {
		self::authorize();
		$service = new FinanceNotificationService( $GLOBALS['wpdb'] );
		try {
			$config = $service->settings();
		} catch ( RuntimeException $error ) {
			wp_die( esc_html__( 'Finance notification settings must be repaired before use.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Finance notifications', self::DOMAIN ) . '</h1>';
		echo '<p>' . esc_html__( 'No email is sent by default. Only explicitly selected, currently authorized finance users may receive informational digests. Emails contain no account numbers, raw bank references, order/customer details or attachments. Sending a digest never authorizes payment.', self::DOMAIN ) . '</p>';
		echo '<p>' . esc_html__( 'At most two messages per finance recipient in any 24-hour period, including explicit tests; the same digest type cannot repeat within 24 hours. Digests only run after an administrator opts in and there is unresolved source-backed work.', self::DOMAIN ) . '</p>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'Finance notification preferences saved.', self::DOMAIN ) .
				'</p></div>';
		}
		if ( isset( $_GET['test_sent'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf(
				/* translators: 1: accepted mail deliveries, 2: skipped by rate/permissions */
				__( 'Test messages accepted: %1$d. Skipped: %2$d.', self::DOMAIN ),
				absint( $_GET['test_sent'] ), absint( $_GET['test_skipped'] ?? 0 )
			) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';
		wp_nonce_field( self::SAVE );
		echo '<h2>' . esc_html__( 'Opt-in digest types', self::DOMAIN ) . '</h2>';
		echo '<fieldset><legend class="screen-reader-text">' .
			esc_html__( 'Enabled finance digests', self::DOMAIN ) . '</legend>';
		foreach ( self::LABELS as $key => $label ) {
			echo '<p><label><input type="checkbox" name="enabled_types[]" value="' .
				esc_attr( $key ) . '" ' .
				checked( in_array( $key, $config['enabled_types'], true ), true, false ) .
				'> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<h2>' . esc_html__( 'Approved finance recipients', self::DOMAIN ) . '</h2>';
		echo '<p class="description">' .
			esc_html__( 'Only users with both bank approval and import permissions can receive finance digests. No arbitrary email addresses are accepted. The recipient list is always checked again at delivery.', self::DOMAIN ) .
			'</p><fieldset>';
		// Query eligible users before the 200-row UI cap, rather than
		// exhausting the page with ordinary customers. Always preserve saved
		// recipients even when a large store exceeds the visible selection cap.
		$candidates = get_users( [ 'capability' => [ FreeCaps::APPROVE, ProCaps::IMPORT ],
			'number' => 200, 'orderby' => 'display_name', 'order' => 'ASC' ] );
		$selected = $config['recipient_ids']
			? get_users( [ 'include' => $config['recipient_ids'] ] ) : [];
		$by_id = [];
		foreach ( array_merge( $candidates, $selected ) as $candidate ) {
			$by_id[ (int) $candidate->ID ] = $candidate;
		}
		$found = 0;
		foreach ( $by_id as $user ) {
			$id = (int) $user->ID;
			if ( ! user_can( $id, FreeCaps::APPROVE ) ||
				! user_can( $id, ProCaps::IMPORT ) || ! is_email( $user->user_email ) ) continue;
			++$found;
			echo '<p><label><input type="checkbox" name="recipient_ids[]" value="' .
				esc_attr( (string) $id ) . '" ' .
				checked( in_array( $id, $config['recipient_ids'], true ), true, false ) .
				'> ' . esc_html( $user->display_name ) . ' (' .
				esc_html( $user->user_email ) . ')</label></p>';
		}
		if ( 0 === $found ) echo '<p>' .
			esc_html__( 'No eligible finance recipients are available.', self::DOMAIN ) . '</p>';
		echo '</fieldset>';
		submit_button( __( 'Save opt-in notification settings', self::DOMAIN ) );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Privacy-safe template previews', self::DOMAIN ) . '</h2>';
		echo '<p>' . esc_html__( 'Previews are generated locally and do not send email or include live bank/claim data.', self::DOMAIN ) . '</p>';
		foreach ( FinanceNotificationTemplate::types() as $type ) {
			$preview = $service->preview( $type, get_current_user_id() );
			echo '<details><summary>' . esc_html( self::LABELS[$type] ) .
				'</summary><p><strong>' . esc_html( $preview['subject'] ) .
				'</strong></p><pre style="white-space:pre-wrap;max-width:680px">' .
				esc_html( $preview['body'] ) . '</pre></details>';
		}
		if ( $config['enabled_types'] ) {
			echo '<h2>' . esc_html__( 'Explicit test email', self::DOMAIN ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::TEST ) . '">';
			wp_nonce_field( self::TEST );
			echo '<label for="wbm-notification-test-type">' .
				esc_html__( 'Enabled template', self::DOMAIN ) . '</label> ';
			echo '<select id="wbm-notification-test-type" name="notification_type">';
			foreach ( $config['enabled_types'] as $type ) {
				echo '<option value="' . esc_attr( $type ) . '">' .
					esc_html( self::LABELS[$type] ) . '</option>';
			}
			echo '</select>';
			submit_button( __( 'Send test to opted-in recipients', self::DOMAIN ),
				'secondary' );
			echo '</form>';
		}
		echo '</div>';
	}

	public static function save(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::SAVE );
		$types = wp_unslash( $_POST['enabled_types'] ?? [] );
		$recipients = wp_unslash( $_POST['recipient_ids'] ?? [] );
		try {
			( new FinanceNotificationService( $GLOBALS['wpdb'] ) )->save(
				[ 'enabled_types' => $types, 'recipient_ids' => $recipients ],
				get_current_user_id(), self::posted_nonce()
			);
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Notification settings are invalid or could not be saved.', self::DOMAIN ),
				'', [ 'response' => 422 ] );
		}
		self::redirect( [ 'saved' => 1 ] );
	}

	public static function send_test(): void {
		self::require_post();
		self::authorize();
		check_admin_referer( self::TEST );
		$type = wp_unslash( $_POST['notification_type'] ?? null );
		if ( ! is_string( $type ) ) {
			wp_die( esc_html__( 'Choose a supported finance notification type.', self::DOMAIN ),
				'', [ 'response' => 422 ] );
		}
		try {
			$result = ( new FinanceNotificationService( $GLOBALS['wpdb'] ) )->send_test(
				$type, get_current_user_id(), self::posted_nonce()
			);
		} catch ( InvalidArgumentException|RuntimeException $error ) {
			wp_die( esc_html__( 'Finance test email was not sent; review permissions and settings.', self::DOMAIN ),
				'', [ 'response' => 409 ] );
		}
		self::redirect( [
			'test_sent' => (int) $result['sent'],
			'test_skipped' => (int) $result['skipped'],
		] );
	}

	private static function posted_nonce(): string {
		$value = wp_unslash( $_POST['_wpnonce'] ?? null );
		return is_string( $value ) ? $value : '';
	}

	private static function require_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Finance settings require POST.', self::DOMAIN ),
				'', [ 'response' => 405 ] );
		}
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 ||
			! current_user_can( FreeCaps::APPROVE ) ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( ProCaps::MANAGE_ACCOUNTS ) ) {
			wp_die( esc_html__( 'Bank-account manager authorization is required.', self::DOMAIN ),
				'', [ 'response' => 403 ] );
		}
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ),
			admin_url( 'admin.php' ) ) );
		exit;
	}
}
