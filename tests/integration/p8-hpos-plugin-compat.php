<?php
/**
 * P8-T005: verify actual paired installed WooCommerce Free/Pro editions
 * declare compatibility with HPOS; no direct wp_posts/postmeta order writes.
 * WordPress/WooCommerce/MariaDB disposable fixture only.
 */
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
     getenv('GITHUB_ACTIONS') !== 'true' ) {
    throw new RuntimeException('HPOS declaration audit requires isolated WC/MariaDB.');
}
$mode = $args[0] ?? '';
if (!in_array($mode, ['hpos', 'classic'], true) ||
    OrderUtil::custom_orders_table_usage_is_enabled() !== ($mode === 'hpos')) {
    throw new RuntimeException('WooCommerce did not activate requested primary order store.');
}
if (!did_action('woocommerce_init') ||
    !class_exists(FeaturesUtil::class) ||
    !did_action('wpessential_bank_transfer_matcher_ready') ||
    !did_action('wpessential_bank_transfer_matcher_pro_ready')) {
    throw new RuntimeException('Actual Free+Pro and WooCommerce initialization are required.');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// Only the explicitly labeled P9 exact-patch compatibility probe may test newer
// real WordPress/WooCommerce versions than the currently declared header matrix.
$latest_patch_probe = '1' === getenv( 'WBM_P9_LATEST_PATCH_PROBE' );
foreach (['wpessential-bank-transfer-matcher', 'wpessential-bank-transfer-matcher-pro'] as $slug) {
    $filename = $slug . '/' . $slug . '.php';
    $file = WP_PLUGIN_DIR . '/' . $filename;
    if (!is_plugin_active($filename) || !is_readable($file)) {
        throw new RuntimeException('Actual paired packaged edition missing: ' . $slug);
    }
    $features = FeaturesUtil::get_compatible_features_for_plugin($filename);
    if (!is_array($features) ||
        !in_array('custom_order_tables', $features['compatible'] ?? [], true) ||
        in_array('custom_order_tables', $features['incompatible'] ?? [], true)) {
        throw new RuntimeException('WooCommerce API does not confirm HPOS declaration: ' . $slug);
    }
    $headers = get_file_data($file, [
        'tested_wp' => 'Tested up to', 'tested_wc' => 'WC tested up to',
        'required_wp' => 'Requires at least', 'required_wc' => 'WC requires at least',
        'required_php' => 'Requires PHP',
    ]);
    if (($headers['tested_wp'] ?? '') !== '' ||
        ($headers['tested_wc'] ?? '') !== '11.3.0' ||
        ($headers['required_wp'] ?? '') !== '7.0' ||
        ($headers['required_wc'] ?? '') !== '10.8' ||
        ($headers['required_php'] ?? '') !== '8.3' ||
        version_compare(WC_VERSION, $headers['required_wc'], '<') ||
        ( ! $latest_patch_probe && version_compare(WC_VERSION, $headers['tested_wc'], '>') ) ||
        ( $latest_patch_probe && ( WC_VERSION !== '11.3.1' || get_bloginfo( 'version' ) !== '7.1.5' ) ) ) {
        throw new RuntimeException('Plugin version requirements are unsupported or diverged: ' . $slug);
    }
}
// Real WC CRUD round trip in active authoritative data store, with native
// order metadata and reference. No direct wp_postmeta manipulation.
$order = wc_create_order(['status'=>'on-hold']);
if (!$order instanceof WC_Order) throw new RuntimeException('Could not create actual WooCommerce order.');
try {
    $order->set_payment_method('bacs');
    $order->set_currency('USD');
    $order->set_total('18.00');
    $order->set_status('on-hold');
    $order->update_meta_data('_wbm_p8_storage_fixture', 'source-' . $mode);
    $order->save();
    $reloaded = wc_get_order($order->get_id());
    if (!$reloaded instanceof WC_Order ||
        !$reloaded->has_status('on-hold') ||
        $reloaded->get_payment_method() !== 'bacs' ||
        $reloaded->get_meta('_wbm_p8_storage_fixture') !== 'source-' . $mode ||
        $reloaded->get_total() !== '18.00' ||
        $reloaded->is_paid()) {
        throw new RuntimeException('Actual WooCommerce primary order store CRUD changed financial state.');
    }
    echo "PASS P8-T005 real Free+Pro Woo HPOS compatibility declarations and {$mode} WC CRUD; v". WC_VERSION ."; no direct order SQL/payment mutation\n";
} finally {
    $order->delete(true);
}
