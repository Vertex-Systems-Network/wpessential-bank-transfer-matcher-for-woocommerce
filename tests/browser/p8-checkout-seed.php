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
// WooCommerce 11.x installs the Checkout Block by default. Reuse the
// existing real shortcode page only if one exists; otherwise publish a
// distinct synthetic classic page WITHOUT changing global checkout settings.
// Keeping the actual configured checkout page preserves P7 guest receipts.
$checkout_id = wc_get_page_id( 'checkout' );
if ( $checkout_id < 1 ||
	! has_shortcode( (string) get_post_field( 'post_content', $checkout_id ), 'woocommerce_checkout' ) ) {
	$checkout_id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => 'p8-disposable-classic-checkout',
		'post_title' => 'P8 synthetic classic shortcode checkout',
		'post_content' => '[woocommerce_checkout]',
	], true );
	if ( is_wp_error( $checkout_id ) || ! $checkout_id ) {
		throw new RuntimeException( 'Cannot create separate classic shortcode checkout page.' );
	}
}
if ( ! has_shortcode( (string) get_post_field( 'post_content', $checkout_id ), 'woocommerce_checkout' ) ) {
	throw new RuntimeException( 'Classic shortcode did not persist in synthetic page.' );
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
// WP-CLI lacks the frontend request context required by dynamic Woo Blocks;
// a CLI do_blocks() HTML check would give false negatives. Verify the genuine
// serialized block here; Chromium separately asserts browser-rendered markup.
$parsed = parse_blocks( (string) get_post_field( 'post_content', $block_id ) );
if ( count( $parsed ) !== 1 ||
	( $parsed[0]['blockName'] ?? '' ) !== 'woocommerce/checkout' ) {
	throw new RuntimeException( 'Could not persist a genuine WooCommerce Checkout Block.' );
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
