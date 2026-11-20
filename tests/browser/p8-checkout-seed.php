<?php
/**
 * Disposable P8-T006 guest checkout fixture. Run AFTER P7 seed so the real
 * checkout order-received page is configured; never on a merchant site.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
	getenv( 'GITHUB_ACTIONS' ) !== 'true' || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'P8 checkout fixture requires disposable WP/WC CI.' );
}
$path = getenv( 'P8_CHECKOUT_FIXTURE_FILE' );
if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . '/' ) ) {
	throw new RuntimeException( 'Cannot write a checkout fixture outside the CI temp folder.' );
}
$checkout_id = wc_get_page_id( 'checkout' );
if ( $checkout_id < 1 || ! has_shortcode( (string) get_post_field( 'post_content', $checkout_id ), 'woocommerce_checkout' ) ) {
	throw new RuntimeException( 'P7 created shortcode checkout page not available.' );
}
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_coupons', 'no' );
$gateway = WC()->payment_gateways()->payment_gateways()['bacs'] ?? null;
if ( ! $gateway || 'yes' !== $gateway->enabled ) {
	throw new RuntimeException( 'Real WooCommerce BACS gateway is disabled.' );
}
$product = new WC_Product_Simple();
$product->set_name( 'P8 synthetic virtual checkout item' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product->set_virtual( true );
$product->set_regular_price( '12.50' );
$product->set_tax_status( 'none' );
$product->set_sold_individually( false );
$product->set_manage_stock( false );
$product->set_stock_status( 'instock' );
$product_id = $product->save();
if ( ! is_int( $product_id ) || $product_id < 1 ) {
	throw new RuntimeException( 'Could not publish disposable virtual WooCommerce product.' );
}
$block_id = wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_title' => 'P8 synthetic Checkout Blocks',
	'post_content' => '<!-- wp:woocommerce/checkout /-->',
	'post_name' => 'p8-disposable-block-checkout',
], true );
if ( is_wp_error( $block_id ) || ! $block_id ) {
	throw new RuntimeException( 'Could not seed real WooCommerce Checkout Block page.' );
}
$block_html = do_blocks( (string) get_post_field( 'post_content', $block_id ) );
if ( ! str_contains( $block_html, 'wc-block-checkout' ) &&
	! str_contains( $block_html, 'wp-block-woocommerce-checkout' ) ) {
	throw new RuntimeException( 'Installed WooCommerce did not register Checkout Blocks.' );
}
$fixture = [
	'version' => 1,
	'disposable' => true,
	'product_id' => $product_id,
	'checkout_page_id' => (int) $checkout_id,
	'blocks_page_id' => (int) $block_id,
	'classic_checkout_url' => get_permalink( $checkout_id ),
	'blocks_checkout_url' => get_permalink( $block_id ),
	'woocommerce_version' => WC_VERSION,
	'wordpress_version' => get_bloginfo( 'version' ),
	'php_version' => PHP_VERSION,
];
$json = wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( ! is_string( $json ) || file_put_contents( $path, $json ) === false ) {
	throw new RuntimeException( 'Checkout fixture JSON was not persisted.' );
}
echo 'PASS P8-T006 real shortcode checkout + registered Woo Checkout Block fixtures seeded; product is synthetic, BACS enabled' . "\n";
