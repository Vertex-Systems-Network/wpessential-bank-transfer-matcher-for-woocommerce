<?php
/**
 * Shared components for Matcher admin screens.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Admin;

defined( 'ABSPATH' ) || exit;

final class Components {
	public static function heading( string $title ): void {
		printf( '<h1>%s</h1>', esc_html( $title ) );
	}

	public static function panel( string $id, string $title, string $description ): void {
		printf(
			'<section class="wbm-panel" aria-labelledby="%1$s"><h2 id="%1$s">%2$s</h2><p>%3$s</p></section>',
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $description )
		);
	}
}
