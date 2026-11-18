<?php

namespace WPEssential\BankTransferMatcherPro\Access;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const IMPORT = 'wbm_import';
	public const MANAGE_ACCOUNTS = 'wbm_manage_bank_accounts';
	private const VERSION = 'wpessential_wbm_pro_capability_version';

	public static function install_defaults(): void {
		if ( 1 === (int) get_option( self::VERSION ) ) return;
		$administrator = get_role( 'administrator' );
		$shop_manager = get_role( 'shop_manager' );
		if ( ! $administrator || ! $shop_manager ) return;
		foreach ( array( self::IMPORT, self::MANAGE_ACCOUNTS ) as $capability ) {
			if ( ! array_key_exists( $capability, $administrator->capabilities ) ) $administrator->add_cap( $capability );
		}
		if ( ! array_key_exists( self::IMPORT, $shop_manager->capabilities ) ) $shop_manager->add_cap( self::IMPORT );
		update_option( self::VERSION, 1 );
	}
}
