<?php
/**
 * P8-T006 post-HTTP financial truth check. Real WooCommerce CRUD + real
 * plugin reference repository. Never call payment_complete or fake hooks.
 */
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;

if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
	getenv('GITHUB_ACTIONS') !== 'true' ) {
	throw new RuntimeException('Guest checkout integrity only on disposable CI DB.');
}
$path = getenv('P8_CHECKOUT_REPORT_FILE');
if ( ! is_string($path) || ! str_starts_with($path, sys_get_temp_dir() . '/') ||
	! is_readable($path) ) {
	throw new RuntimeException('Missing real HTTP checkout report.');
}
$report = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if ( ! is_array($report) || ($report['disposable'] ?? false) !== true ||
	! empty($report['issues']) || count($report['orders'] ?? []) !== 2 ) {
	throw new RuntimeException('Checkout HTTP report was not accepted.');
}
global $wpdb;
$repo = new ReferenceRepository($wpdb);
$claims = new ClaimRepository($wpdb);
$references = [];
foreach (['classic_shortcode_browser','blocks_store_api_http'] as $index => $channel) {
	$actual = $report['orders'][$index] ?? null;
	$id = $actual['id'] ?? 0;
	if ( ! is_array($actual) || ($actual['channel'] ?? null) !== $channel ||
		! is_int($id) || $id < 1 ) {
		throw new RuntimeException('Checkout channel not produced by real HTTP: ' . $channel);
	}
	$order = wc_get_order($id);
	if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'bacs' ||
		$order->get_customer_id() !== 0 ||
		! $order->has_status('on-hold') || $order->is_paid() ||
		null !== $order->get_date_paid() || $order->get_total() !== '12.50' ||
		$claims->exists($id) ) {
		throw new RuntimeException('Checkout channel mutated payment or customer claim state: ' . $channel);
	}
	$ref = $repo->find(new EntityId($id));
	if ( ! $ref || ! preg_match('/\A[A-Z0-9-]{6,64}\z/', $ref->value) ) {
		throw new RuntimeException('No immutable BACS reference for actual checkout: ' . $channel);
	}
	$items = $order->get_items('line_item');
	if (count($items) !== 1) {
		throw new RuntimeException('Actual WC checkout cart contents missing: ' . $channel);
	}
	$references[] = $ref->value;
}
if ($references[0] === $references[1]) {
	throw new RuntimeException('Classic and Checkout Blocks shared a BACS payment reference.');
}
echo "PASS P8-T006 real classic and Store API guest BACS orders: two distinct immutable references, on-hold/unpaid, no claim, no customer ID, genuine WC CRUD\n";
