<?php
/**
 * Free-owned WordPress capability defaults.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Access;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const VIEW         = 'wbm_view';
	public const REVIEW_CLAIM = 'wbm_review_claim';
	public const APPROVE      = 'wbm_approve';

	private const VERSION_OPTION = 'wpessential_wbm_capability_version';

	public static function install_defaults(): void {
		if ( 1 === (int) get_option( self::VERSION_OPTION ) ) {
			return;
		}

		$administrator = get_role( 'administrator' );
		$shop_manager  = get_role( 'shop_manager' );
		if ( ! $administrator || ! $shop_manager ) {
			return;
		}

		foreach ( array( $administrator, $shop_manager ) as $role ) {
			foreach ( array( self::VIEW, self::REVIEW_CLAIM, self::APPROVE ) as $capability ) {
				if ( ! array_key_exists( $capability, $role->capabilities ) ) {
					$role->add_cap( $capability );
				}
			}
		}

		update_option( self::VERSION_OPTION, 1 );
	}
}
