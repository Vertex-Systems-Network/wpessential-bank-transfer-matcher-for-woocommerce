<?php
/**
 * P8-T008: real same-store Woo 10.8 -> 11.3 upgrade and Free/Pro zip
 * reinstalls, while a benign third-party order-number plugin remains active.
 * All data synthetic. Never calls payment_complete().
 *
 * wp eval-file tests/integration/p8-upgrade-coexistence.php seed|free-only|verify|cleanup
 */
use Automattic\WooCommerce\Utilities\OrderUtil;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\ReferenceIssuer;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
    getenv('GITHUB_ACTIONS') !== 'true' || ! class_exists('WooCommerce') ) {
    throw new RuntimeException('P8 upgrade/coexistence fixture restricted to disposable WP/WC CI.');
}
$fixture_path = getenv('P8_UPGRADE_FIXTURE_FILE');
$report_path = getenv('P8_UPGRADE_REPORT_FILE');
$storage = getenv('P8_UPGRADE_STORAGE_MODE');
$step = $args[0] ?? '';
if ( ! in_array($step,['seed','free-only','verify','cleanup'],true) ||
    ! in_array($storage,['hpos','classic'],true) ||
    ! is_string($fixture_path) || ! str_starts_with($fixture_path,sys_get_temp_dir().'/') ||
    ! is_string($report_path) || ! str_starts_with($report_path,sys_get_temp_dir().'/') ) {
    throw new RuntimeException('Unknown P8 disposable fixture path/mode.');
}
if ( OrderUtil::custom_orders_table_usage_is_enabled() !== ('hpos' === $storage) ) {
    throw new RuntimeException('Real Woo primary order storage mode is not requested mode.');
}
$free='wpessential-bank-transfer-matcher/wpessential-bank-transfer-matcher.php';
$pro='wpessential-bank-transfer-matcher-pro/wpessential-bank-transfer-matcher-pro.php';
$companion='p8-ci-order-number/p8-ci-order-number.php';
if ( ! is_plugin_active($free) || ! is_plugin_active($companion) ||
    1 !== did_action('p8_ci_companion_ready') ||
    1 !== did_action('wpessential_bank_transfer_matcher_ready') ) {
    throw new RuntimeException('Free and synthetic third-party plugin did not bootstrap together.');
}
if ( ('free-only' === $step) === is_plugin_active($pro) ) {
    throw new RuntimeException('Pro active/missing state did not match the requested recovery step.');
}
if ( 'free-only' !== $step && 1 !== did_action('wpessential_bank_transfer_matcher_pro_ready') ) {
    throw new RuntimeException('Pro failed to bootstrap with its compatible Free edition.');
}
if ( ('seed' === $step && WC_VERSION !== '10.8.0') ||
    ('verify' === $step && WC_VERSION !== '11.3.0') ) {
    throw new RuntimeException('WC version not truly installed for intended migration phase.');
}
$loaders = \Composer\Autoload\ClassLoader::getRegisteredLoaders();
if ( ! isset($loaders[WP_PLUGIN_DIR.'/wpessential-bank-transfer-matcher/vendor']) ) {
    throw new RuntimeException('Free isolated Composer loader unavailable after plugin operation.');
}
if ( 'free-only' !== $step &&
    ! isset($loaders[WP_PLUGIN_DIR.'/wpessential-bank-transfer-matcher-pro/vendor']) ) {
    throw new RuntimeException('Pro isolated Composer loader unavailable after plugin operation.');
}
global $wpdb;
$refs = new ReferenceRepository($wpdb);
$claims = new ClaimRepository($wpdb);
$fixture = static function () use ($fixture_path): array {
    if (!is_readable($fixture_path)) throw new RuntimeException('No stored upgrade fixture.');
    $data=json_decode((string)file_get_contents($fixture_path),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['synthetic'] ?? false)!==true ||
        !is_int($data['order_id'] ?? null) || !is_int($data['bank_id'] ?? null)) {
        throw new RuntimeException('Untrusted upgrade fixture metadata.');
    }
    return $data;
};
$assert_persistent = static function (array $data) use ($wpdb,$refs,$claims,$storage): void {
    $order = wc_get_order($data['order_id']);
    if (!$order instanceof WC_Order || $order->get_customer_id()!==0 ||
        $order->get_currency()!=='USD' || $order->get_payment_method()!=='bacs' ||
        !$order->has_status('on-hold') || $order->is_paid() ||
        null!==$order->get_date_paid() || $order->get_total()!=='45.00' ||
        $order->get_billing_email()!=='p8-upgrade-fixture@example.test' ||
        !str_starts_with((string)$order->get_order_number(),'EXT-')) {
        throw new RuntimeException('Third-party order number or unpaid BACS order changed in '.$storage);
    }
    $reference=$refs->find(new EntityId($order->get_id()));
    if (!$reference || !hash_equals($data['reference'],$reference->value)) {
        throw new RuntimeException('Immutable payment reference was changed across upgrade.');
    }
    if (!$claims->exists($order->get_id())) {
        throw new RuntimeException('Existing unverified customer claim was lost.');
    }
    $table=$wpdb->prefix.'wbm_payment_claims';
    $claim=$wpdb->get_row($wpdb->prepare(
        "SELECT status, transfer_reference, amount_minor, sender_name, customer_note FROM $table WHERE order_id=%d",
        $order->get_id()
    ),ARRAY_A);
    if (!$claim || $claim['status']!=='submitted' ||
        $claim['transfer_reference']!=='P8-UPGRADE-UNVERIFIED' ||
        (int)$claim['amount_minor']!==4500 ||
        $claim['sender_name']!=='Synthetic CI sender' ||
        $claim['customer_note']!=='Not bank clearance, only synthetic statement.' ) {
        throw new RuntimeException('Persisted unverified claim was modified by reactivation/upgrade.');
    }
    if ((string)get_option('wpessential_wbm_claim_schema')!=='2') {
        throw new RuntimeException('Claim schema checkpoint failed to survive upgrade.');
    }
    $reference_rows=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wbm_payment_references WHERE order_id=%d",
        $order->get_id()
    ));
    $claim_rows=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE order_id=%d",$order->get_id()
    ));
    if ($reference_rows!==1 || $claim_rows!==1) {
        throw new RuntimeException('Plugin update duplicated immutable payment evidence.');
    }
};
if ('seed' === $step) {
    if (is_readable($fixture_path)) throw new RuntimeException('Seed already exists.');
    $admin=get_user_by('login','wbm_ci');
    if (!$admin) throw new RuntimeException('Synthetic administrator missing.');
    wp_set_current_user($admin->ID);
    $order=wc_create_order(['status'=>'on-hold','customer_id'=>0]);
    if (!$order instanceof WC_Order) throw new RuntimeException('Cannot seed Woo BACS order.');
    $order->set_payment_method('bacs');
    $order->set_currency('USD');
    $order->set_status('on-hold');
    $order->set_total('45.00');
    $order->set_billing_email('p8-upgrade-fixture@example.test');
    $order->save();
    $id=(int)$order->get_id();
    $reference=(new ReferenceIssuer($refs))->issue(new EntityId($id));
    $claims->save($id,[
        'date'=>gmdate('Y-m-d'), 'amount'=>Money::from_decimal('45.00','USD',2),
        'reference'=>'P8-UPGRADE-UNVERIFIED','sender'=>'Synthetic CI sender',
        'note'=>'Not bank clearance, only synthetic statement.',
    ]);
    $bank_id=(new BankAccountRepository($wpdb))->create(
        'P8 upgrade fixture','CI Test Bank','USD','0055',(int)$admin->ID
    );
    $data=['synthetic'=>true,'order_id'=>$id,'bank_id'=>$bank_id,
        'reference'=>$reference->value,'created_wc'=>WC_VERSION,'mode'=>$storage];
    if (false===file_put_contents($fixture_path,wp_json_encode($data))) {
        throw new RuntimeException('Cannot persist synthetic upgrade fixture.');
    }
    $assert_persistent($data);
    echo "PASS P8-T008 seeded same-store synthetic BACS order, immutable reference, unverified claim, bank account and third-party order-number filter in $storage\n";
    return;
}
$data=$fixture();
$assert_persistent($data);
if ('free-only' === $step) {
    // Independent Free must keep all claim/payment-reference semantics while
    // Pro is deliberately deactivated. This must NOT mark any order paid.
    echo "PASS P8-T008 Pro deactivation preserves Free reference and unverified claim with companion order-number plugin in $storage\n";
    return;
}
$banks=(new BankAccountRepository($wpdb))->all();
$matches=array_values(array_filter($banks,static fn(array $a):bool =>
    (int)$a['id']===$data['bank_id']));
if(count($matches)!==1 || $matches[0]['nickname']!=='P8 upgrade fixture' ||
    $matches[0]['identifier_last4']!=='0055' ||
    (string)get_option('wpessential_wbm_pro_bank_account_schema')!=='1') {
    throw new RuntimeException('Pro bank account safe labels or schema were changed.');
}
if ('verify' === $step) {
    $bank_count=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_bank_accounts WHERE id=%d",$data['bank_id']
    ));
    if($bank_count!==1) throw new RuntimeException('Bank account metadata duplicated on upgrade.');
    $report=[
        'schema_version'=>1,'synthetic'=>true,'storage_mode'=>$storage,
        'initial_woocommerce'=>$data['created_wc'],'upgraded_woocommerce'=>WC_VERSION,
        'php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),
        'companion_active'=>true,'free_active'=>true,'pro_active'=>true,
        'free_reference_unchanged'=>true,'unverified_claim_unchanged'=>true,
        'safe_pro_bank_account_unchanged'=>true,'paid_state_changed'=>false,
        'duplicate_evidence_rows'=>false,
    ];
    if(false===file_put_contents($report_path,wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n")) {
        throw new RuntimeException('Upgrade verification report not persisted.');
    }
    echo "PASS P8-T008 real Woo 10.8.0 to 11.3.0 upgrade, Free/Pro ZIP reinstall, companion coexistence, persisted unpaid BACS ref/claim/bank metadata and migration checkpoints in $storage\n";
    return;
}
if ('cleanup' === $step) {
    $wpdb->delete($wpdb->prefix.'wbm_payment_claims',['order_id'=>$data['order_id']],['%d']);
    $wpdb->delete($wpdb->prefix.'wbm_payment_references',['order_id'=>$data['order_id']],['%d']);
    $wpdb->delete($wpdb->prefix.'wbm_pro_bank_accounts',['id'=>$data['bank_id']],['%d']);
    $order=wc_get_order($data['order_id']);
    if($order instanceof WC_Order)$order->delete(true);
    if(is_file($fixture_path))unlink($fixture_path);
    echo "PASS P8-T008 synthetic upgrade fixture cleanup in $storage\n";
}
