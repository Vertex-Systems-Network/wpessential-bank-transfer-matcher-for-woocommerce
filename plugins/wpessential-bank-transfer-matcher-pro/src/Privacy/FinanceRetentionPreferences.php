<?php
namespace WPEssential\BankTransferMatcherPro\Privacy;

use InvalidArgumentException;

/**
 * Safe, explicit merchant data-retention choices. Automatic cleanup is OFF,
 * private imported statement sources are retained by default, and financial
 * allocation/bank-ledger records are never touched by this policy.
 */
final class FinanceRetentionPreferences {
	public const OPTION = 'wpessential_wbm_pro_retention_policy';
	private const WINDOWS = [
		'diagnostic_audit_days' => [ 30, 90, 180, 365 ],
		'notification_audit_days' => [ 90, 180, 365 ],
		'recovery_audit_days' => [ 180, 365, 730 ],
		'finance_export_audit_days' => [ 180, 365, 730 ],
		'private_source_days' => [ 0, 30, 90, 180, 365 ],
	];

	public static function defaults(): array {
		return [
			'automatic_cleanup' => false,
			'diagnostic_audit_days' => 90,
			'notification_audit_days' => 180,
			'recovery_audit_days' => 365,
			'finance_export_audit_days' => 365,
			'private_source_days' => 0,
		];
	}

	public static function windows(): array {
		return self::WINDOWS;
	}

	public static function normalize( array $input ): array {
		$expected = array_keys( self::defaults() );
		if ( array_diff( array_keys( $input ), $expected ) ||
			array_diff( $expected, array_keys( $input ) ) ) {
			throw new InvalidArgumentException( 'Retention choices must be a complete fixed schema.' );
		}
		$enabled = $input['automatic_cleanup'];
		if ( true === $enabled || '1' === $enabled ) {
			$enabled = true;
		} elseif ( false === $enabled || '0' === $enabled ) {
			$enabled = false;
		} else {
			throw new InvalidArgumentException( 'Unsafe automatic retention toggle.' );
		}
		$choices = [ 'automatic_cleanup' => $enabled ];
		foreach ( self::WINDOWS as $key => $allowed ) {
			$value = $input[$key];
			if ( is_string( $value ) &&
				1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) &&
				strlen( $value ) <= 3 ) $value = (int) $value;
			if ( ! is_int( $value ) || ! in_array( $value, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Unsupported retention window for ' . $key . '.' );
			}
			$choices[$key] = $value;
		}
		return $choices;
	}
}
