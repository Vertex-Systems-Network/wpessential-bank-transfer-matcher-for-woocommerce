<?php
/**
 * P8-T002: actual private file and scoped receipt nonce checks in disposable
 * WooCommerce/MariaDB. Synthetic receipt and CSV only; no financial POST.
 */
use WPEssential\BankTransferMatcher\Claims\AttachmentDownloads;
use WPEssential\BankTransferMatcher\Claims\ClaimAttachment;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;

if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ) {
    throw new RuntimeException('Only disposable WP/WC/MariaDB wbm_ci permitted.');
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$original = get_current_user_id();
$admin = get_user_by('login', 'wbm_ci');
if ( ! $admin ) throw new RuntimeException('Missing synthetic admin.');
$order = null; $reviewer_id = 0; $viewer_id = 0;
$bank_account = 0; $batch = 0; $source = ''; $staged = '';
try {
    wp_set_current_user($admin->ID);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
    $receipt = ClaimAttachment::validate('synthetic.png', $png);
    $order = wc_create_order(['status' => 'on-hold']);
    if ( ! $order instanceof WC_Order ) throw new RuntimeException('No BACS fixture.');
    $order->set_payment_method('bacs');
    $order->set_status('on-hold');
    $order->set_customer_id(0);
    $order->set_currency('USD');
    $order->set_total('14.00');
    $order->save();
    $id = $order->get_id();
    $claims = new ClaimRepository($wpdb);
    $claims->save($id, [
        'date' => gmdate('Y-m-d'),
        'amount' => new Money(1400, 'USD', 2),
        'reference' => 'P8-SYNTHETIC', 'sender' => 'CI only', 'note' => 'No actual payment',
    ], $receipt);
    $retrieved = $claims->attachment($id);
    if (!is_array($retrieved) || $retrieved['attachment_mime'] !== 'image/png' ||
        $retrieved['attachment_bytes'] !== $png ) {
        throw new RuntimeException('Receipt bytes not stored privately and losslessly.');
    }
    if ( false !== has_action('admin_post_nopriv_wpessential_wbm_download_receipt') ||
        false === has_action('admin_post_wpessential_wbm_download_receipt') ) {
        throw new RuntimeException('Private receipt endpoint has public or missing handler.');
    }
    $reviewer_id = wp_create_user('p8_r_' . getmypid(), wp_generate_password(36), 'p8_r@example.test');
    $viewer_id = wp_create_user('p8_v_' . getmypid(), wp_generate_password(36), 'p8_v@example.test');
    if (is_wp_error($reviewer_id) || is_wp_error($viewer_id)) {
        throw new RuntimeException('Could not create isolated reviewer/viewer.');
    }
    $reviewer = new WP_User((int)$reviewer_id);
    $reviewer->set_role('subscriber');
    $reviewer->add_cap('wbm_review_claim', true);
    $viewer = new WP_User((int)$viewer_id);
    $viewer->set_role('subscriber');
    $viewer->add_cap('wbm_view', true);
    $action = 'wpessential_wbm_download_receipt:' . $id;
    wp_set_current_user((int)$reviewer_id);
    $nonce = wp_create_nonce($action);
    if (!AttachmentDownloads::authorized($id, $nonce) ||
        AttachmentDownloads::authorized($id + 1, $nonce) ||
        AttachmentDownloads::authorized($id, 'wrong-nonce')) {
        throw new RuntimeException('Receipt reviewer nonce is not order-scoped.');
    }
    wp_set_current_user((int)$viewer_id);
    if (AttachmentDownloads::authorized($id, $nonce) ||
        AttachmentDownloads::authorized($id, wp_create_nonce($action))) {
        throw new RuntimeException('Read-only viewer obtained a private receipt.');
    }
    wp_set_current_user(0);
    if (AttachmentDownloads::authorized($id, $nonce)) {
        throw new RuntimeException('Anonymous user obtained a private receipt.');
    }
    wp_set_current_user($admin->ID);
    if (!AttachmentDownloads::authorized($id, wp_create_nonce($action))) {
        throw new RuntimeException('Authorized administrator was denied.');
    }
    $table = $wpdb->prefix . 'wbm_payment_claims';
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET attachment_expires = '2000-01-01 00:00:00' WHERE order_id = %d", $id
    ));
    if ($claims->has_attachment($id) || null !== $claims->attachment($id)) {
        throw new RuntimeException('Expired receipt still served.');
    }
    $claims->purge_expired_attachments();
    if (null !== $wpdb->get_var($wpdb->prepare(
        "SELECT attachment_bytes FROM $table WHERE order_id = %d", $id
    ))) {
        throw new RuntimeException('Expired receipt bytes were not erased.');
    }

    $bank_account = (new BankAccountRepository($wpdb))->create(
        'P8 private fixture', 'CI bank', 'USD', '8000', $admin->ID
    );
    $profile = MappingProfile::from_array([
        'id'=>'p8-privacy','name'=>'P8 private',
        'delimiter'=>',','date_header'=>'Date','date_format'=>'Y-m-d',
        'amount_header'=>'Amount','decimal_separator'=>'.','thousands_separator'=>'',
        'exponent'=>2,'currency_header'=>'','fixed_currency'=>'USD',
        'reference_header'=>'Reference','description_header'=>'','transaction_id_header'=>'Bank ID',
    ]);
    $source = tempnam(sys_get_temp_dir(), 'p8-source-');
    if (false === $source) throw new RuntimeException('No source fixture.');
    file_put_contents($source, "Date,Amount,Reference,Bank ID\n2026-11-20,14.00,P8-FAKE,CI-0001\n");
    $batch = (new ImportJob($wpdb))->start($source, $bank_account, $profile, $admin->ID);
    $jobs = $wpdb->prefix . 'wbm_pro_import_jobs';
    $staged = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT source_path FROM $jobs WHERE batch_id = %d", $batch
    ));
    $real = realpath($staged);
    $webroot = realpath(ABSPATH);
    if (false === $real || false === $webroot ||
        str_starts_with($real, rtrim($webroot, '/') . '/') ||
        !preg_match('/^[a-f0-9]{48}\.csv$/D', basename($real)) ||
        is_link($real) || is_link(dirname($real)) ||
        (fileperms($real) & 0077) !== 0 ||
        (fileperms(dirname($real)) & 0077) !== 0 ||
        !hash_equals(hash_file('sha256', $source), hash_file('sha256', $real))) {
        throw new RuntimeException('Statement file is public, unmasked, symlinked, weakly permissioned or modified.');
    }
    if ($order->is_paid() || !$order->has_status('on-hold')) {
        throw new RuntimeException('Private access audit modified WooCommerce payment state.');
    }
    echo "PASS P8-T002 real order-scoped receipt reviewer/nonce, viewer/guest denial, expired byte purge and outside-webroot 0600/0700 opaque CSV\n";
} finally {
    wp_set_current_user($original);
    if ($batch) {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(ImportJob::HOOK, [(int)$batch], 'wpessential-wbm-import');
        }
        if (is_string($staged) && is_file($staged)) unlink($staged);
        foreach (['wbm_pro_import_rows', 'wbm_pro_import_jobs', 'wbm_pro_import_batches'] as $name) {
            $wpdb->delete($wpdb->prefix . $name, ['batch_id'=>$batch], ['%d']);
        }
    }
    if ($bank_account) $wpdb->delete($wpdb->prefix . 'wbm_pro_bank_accounts', ['id'=>$bank_account], ['%d']);
    if (is_string($source) && is_file($source)) unlink($source);
    if ($order instanceof WC_Order) {
        $wpdb->delete($wpdb->prefix . 'wbm_payment_claims', ['order_id'=>$order->get_id()], ['%d']);
        $order->delete(true);
    }
    foreach ([$reviewer_id, $viewer_id] as $actor) {
        if (is_int($actor) && $actor > 0) wp_delete_user($actor);
    }
}
