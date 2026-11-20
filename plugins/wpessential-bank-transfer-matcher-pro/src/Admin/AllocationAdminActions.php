<?php
namespace WPEssential\BankTransferMatcherPro\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcher\Access\Capabilities as FreeCaps;
use WPEssential\BankTransferMatcherPro\Access\Capabilities as ProCaps;
use WPEssential\BankTransferMatcherPro\Reconciliation\CorrectionCaseRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\SplitCreditAllocation;

/**
 * Explicit WordPress-native POST adapters to already audited maker/checker
 * services. No automatic payment, reversal or refund handler exists here.
 *
 * Process methods are separately testable; every public HTTP handler requires
 * POST, capability checks and a context-specific WordPress nonce.
 */
final class AllocationAdminActions {
	public static function register(): void {
		foreach ( [
			'wbm_pro_allocation_propose' => 'handle_proposal',
			'wbm_pro_allocation_approve' => 'handle_approval',
			'wbm_pro_correction_raise' => 'handle_correction',
			'wbm_pro_correction_review' => 'handle_correction_review',
		] as $action => $callback ) {
			add_action( 'admin_post_' . $action, [ self::class, $callback ] );
		}
	}

	/** @param array<string,mixed> $input */
	public static function process_proposal( array $input ): array {
		self::authorize();
		return ( new OneToOneProposal( $GLOBALS['wpdb'] ) )->propose(
			self::positive_id( $input, 'order_id' ),
			self::positive_id( $input, 'source_id' ),
			get_current_user_id(),
			self::key( $input, 'request_key' ),
			self::reason( $input )
		);
	}

	/** @param array<string,mixed> $input */
	public static function process_approval( array $input ): array {
		self::authorize();
		$allocation_id = self::positive_id( $input, 'allocation_id' );
		$reason = self::reason( $input );
		$confirmed = isset( $input['cleared_confirmed'] ) &&
			is_string( $input['cleared_confirmed'] ) && '1' === $input['cleared_confirmed'];
		if ( ! $confirmed ) throw new InvalidArgumentException( 'Clearance confirmation must be checked explicitly.' );
		$group = $input['split_group_key'] ?? '';
		if ( ! is_string( $group ) ) throw new InvalidArgumentException( 'Invalid split group key.' );
		if ( '' !== $group ) {
			if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $group ) ) {
				throw new InvalidArgumentException( 'Split review requires the original immutable group key.' );
			}
			// Validate the selected recipient BEFORE the mutating group call.
			// Never throw a client-input error after approval has committed.
			$table = $GLOBALS['wpdb']->prefix . 'wbm_pro_allocations';
			$belongs = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT split_group_key FROM $table WHERE id = %d", $allocation_id
			) );
			if ( '' !== $GLOBALS['wpdb']->last_error || $belongs !== $group ) {
				throw new InvalidArgumentException( 'Selected allocation does not belong to this split group.' );
			}
			$group_result = ( new SplitCreditAllocation( $GLOBALS['wpdb'] ) )->approve(
				$group, get_current_user_id(), $confirmed, $reason
			);
			// Only use returned, service-validated recipients to form the
			// redirect; the group service enforces all source/order locks.
			return [ 'allocation_id' => (int) $group_result['allocation_ids'][0],
				'status' => $group_result['status'], 'order_payment_changed' => false ];
		}
		return ( new OneToOneApproval( $GLOBALS['wpdb'] ) )->approve(
			$allocation_id, get_current_user_id(), $confirmed, $reason
		);
	}

	/** @param array<string,mixed> $input */
	public static function process_correction( array $input ): array {
		self::authorize();
		$type = $input['case_type'] ?? '';
		if ( ! is_string( $type ) ) throw new InvalidArgumentException( 'Invalid correction investigation kind.' );
		return ( new CorrectionCaseRepository( $GLOBALS['wpdb'] ) )->raise(
			self::positive_id( $input, 'allocation_id' ), get_current_user_id(),
			self::key( $input, 'request_key' ), $type, self::reason( $input )
		);
	}

	/** @param array<string,mixed> $input */
	public static function process_correction_review( array $input ): array {
		self::authorize();
		$decision = $input['review_decision'] ?? '';
		if ( ! is_string( $decision ) ) throw new InvalidArgumentException( 'Invalid correction review decision.' );
		return ( new CorrectionCaseRepository( $GLOBALS['wpdb'] ) )->review(
			self::positive_id( $input, 'case_id' ), get_current_user_id(),
			$decision, self::reason( $input )
		);
	}

	public static function handle_proposal(): void {
		self::handle( 'wbm_pro_allocation_propose', 'process_proposal' );
	}
	public static function handle_approval(): void {
		self::handle( 'wbm_pro_allocation_approve', 'process_approval' );
	}
	public static function handle_correction(): void {
		self::handle( 'wbm_pro_correction_raise', 'process_correction' );
	}
	public static function handle_correction_review(): void {
		self::handle( 'wbm_pro_correction_review', 'process_correction_review' );
	}

	private static function handle( string $nonce_action, string $processor ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Finance actions require a verified POST request.', 'wpessential-bank-transfer-matcher-pro' ),
				'', [ 'response' => 405 ] );
		}
		check_admin_referer( $nonce_action );
		if ( get_current_user_id() < 1 ||
			! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			wp_die( esc_html__( 'Finance allocation access is restricted.', 'wpessential-bank-transfer-matcher-pro' ),
				'', [ 'response' => 403 ] );
		}
		$input = wp_unslash( $_POST );
		if ( ! is_array( $input ) ) throw new RuntimeException( 'Invalid finance request.' );
		try {
			$result = self::$processor( $input );
		} catch ( InvalidArgumentException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', [ 'response' => 422 ] );
		} catch ( RuntimeException $error ) {
			wp_die( esc_html( $error->getMessage() ), '', [ 'response' => 409 ] );
		}
		$id = (int) ( $result['allocation_id'] ?? 0 );
		wp_safe_redirect( add_query_arg( [
			'page' => 'wpessential-wbm-pro-allocations',
			'allocation_id' => $id, 'finance_updated' => sanitize_key( (string) ( $result['status'] ?? $result['state'] ?? 'updated' ) ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function authorize(): void {
		if ( get_current_user_id() < 1 || ! current_user_can( ProCaps::IMPORT ) ||
			! current_user_can( FreeCaps::APPROVE ) ) {
			throw new RuntimeException( 'Finance allocation access is restricted.' );
		}
	}
	/** @param array<string,mixed> $input */
	private static function positive_id( array $input, string $field ): int {
		$raw = $input[$field] ?? null;
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			throw new InvalidArgumentException( 'Missing finance identifier.' );
		}
		$str = (string) $raw;
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $str ) ||
			strlen( $str ) > strlen( (string) PHP_INT_MAX ) ||
			( strlen( $str ) === strlen( (string) PHP_INT_MAX ) &&
				strcmp( $str, (string) PHP_INT_MAX ) > 0 ) ) {
			throw new InvalidArgumentException( 'Invalid finance identifier.' );
		}
		return (int) $str;
	}
	/** @param array<string,mixed> $input */
	private static function key( array $input, string $field ): string {
		$value = $input[$field] ?? '';
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $value ) ) {
			throw new InvalidArgumentException( 'Invalid idempotent finance request key.' );
		}
		return $value;
	}
	/** @param array<string,mixed> $input */
	private static function reason( array $input ): string {
		$value = $input['reason'] ?? '';
		if ( ! is_string( $value ) || str_contains( $value, "\0" ) ) {
			throw new InvalidArgumentException( 'Financial review requires an auditable reason.' );
		}
		$value = trim( sanitize_textarea_field( $value ) );
		if ( strlen( $value ) < 20 || strlen( $value ) > 500 ||
			1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'Financial review reason must contain 20–500 characters.' );
		}
		return $value;
	}
}
