<?php
// P9-T013 real installed WooCommerce Free and Pro, no Composer binary accessible.
if (!defined('WP_CLI') || !WP_CLI || !defined('DB_NAME') || DB_NAME !== 'wbm_ci' ||
    getenv('GITHUB_ACTIONS') !== 'true' || getenv('WBM_P9_NO_COMPOSER') !== '1') {
    throw new RuntimeException('Composerless test only on disposable native CI');
}
if (function_exists('shell_exec') && trim((string) shell_exec('command -v composer 2>/dev/null')) !== '') {
    throw new RuntimeException('Composer executable unexpectedly available to WP CLI');
}
if (!did_action('woocommerce_init') ||
    !did_action('wpessential_bank_transfer_matcher_ready') ||
    !did_action('wpessential_bank_transfer_matcher_pro_ready') ||
    !class_exists(\WPEssential\BankTransferMatcher\Bootstrap\Plugin::class) ||
    !class_exists(\WPEssential\BankTransferMatcherPro\Bootstrap\Plugin::class)) {
    throw new RuntimeException('Installed Woo/Free/Pro autoload/bootstrap failed without Composer');
}
foreach (['wpessential-bank-transfer-matcher','wpessential-bank-transfer-matcher-pro'] as $slug) {
    foreach (['vendor/autoload.php','vendor/composer/autoload_classmap.php','LICENSE'] as $member) {
        if (!is_readable(WP_PLUGIN_DIR.'/'.$slug.'/'.$member)) {
            throw new RuntimeException('Missing original production plugin runtime: '.$slug.'/'.$member);
        }
    }
}
$order=wc_create_order(['status'=>'on-hold']);
if (!$order instanceof WC_Order) throw new RuntimeException('Actual WooCommerce CRUD unavailable');
try {
    $order->set_payment_method('bacs');
    $order->set_currency('USD');
    $order->set_total('3.00');
    $order->save();
    $found=wc_get_order($order->get_id());
    if (!$found || $found->is_paid() || !$found->has_status('on-hold') ||
        $found->get_total()!=='3.00' || $found->get_payment_method()!=='bacs') {
        throw new RuntimeException('Composerless native activation changed unpaid BACS order state');
    }
    echo "PASS actual Woo+Free+Pro composerless boot and synthetic unpaid order round trip\n";
} finally {
    $order->delete(true);
}
