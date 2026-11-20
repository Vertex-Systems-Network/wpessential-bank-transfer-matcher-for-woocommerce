<?php
/**
 * P8-T004: cancelled/stale order, duplicated bank provenance, tampered
 * source, independent SQL lock, idempotent second reviewer verification.
 * Disposable real WordPress/WooCommerce/MariaDB only. No Woo payment_complete.
 */
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reconciliation\OrderReceivingAccountRepository;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneProposal;
use WPEssential\BankTransferMatcherPro\Reconciliation\OneToOneApproval;
use WPEssential\BankTransferMatcherPro\Reconciliation\AllocationLedgerRepository;

if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
    getenv('GITHUB_ACTIONS') !== 'true' ) {
    throw new RuntimeException('P8 ledger invalidation requires disposable WP/WC/MariaDB.');
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$admin = get_user_by('login', 'wbm_ci');
if (!$admin) throw new RuntimeException('Synthetic admin missing.');
$original_user = get_current_user_id();
wp_set_current_user($admin->ID);
$order = null; $account = 0; $batch = 0; $credit = 0;
$duplicate_id = 0; $reviewer_id = 0; $held_lock = null;
$other = null;
try {
    $order = wc_create_order(['status'=>'on-hold']);
    if (!$order instanceof WC_Order) throw new RuntimeException('Synthetic BACS order failed.');
    $order->set_payment_method('bacs');
    $order->set_currency('USD');
    $order->set_total('50.00');
    $order->set_status('on-hold');
    $order->save();
    $order_id = (int)$order->get_id();
    $payment_ref = PaymentReference::issue();
    (new ReferenceRepository($wpdb))->reserve(new EntityId($order_id), $payment_ref);
    $account = (new BankAccountRepository($wpdb))->create(
        'P8 stale-reconciliation test', 'Synthetic bank only', 'USD', '4500', $admin->ID
    );
    (new OrderReceivingAccountRepository($wpdb))->confirm(
        $order_id, $account, $admin->ID,
        'Synthetic merchant confirmed the receiving account before review.'
    );
    $profile = MappingProfile::from_array([
        'id'=>'p8-stale','name'=>'P8 stale ledger','delimiter'=>',',
        'date_header'=>'Date','date_format'=>'Y-m-d','amount_header'=>'Amount',
        'decimal_separator'=>'.','thousands_separator'=>'','exponent'=>2,
        'currency_header'=>'','fixed_currency'=>'USD',
        'reference_header'=>'Reference','description_header'=>'','transaction_id_header'=>'Bank ID',
    ]);
    $imports = new ImportBatchRepository($wpdb);
    $batch = $imports->create(
        $account, $profile, hash('sha256', 'p8-stale-' . $order_id), 100, $admin->ID
    );
    $imports->transition($batch, 'pending', 'processing');
    $imports->transition($batch, 'processing', 'completed', 1);
    $rows = $wpdb->prefix . 'wbm_pro_import_rows';
    $allocations = $wpdb->prefix . 'wbm_pro_allocations';
    $events = $wpdb->prefix . 'wbm_pro_allocation_events';
    $fingerprint = hash('sha256', 'synthetic-credit-p8-' . $order_id);
    $canonical = [
        'source_row' => 2, 'posted_date' => gmdate('Y-m-d'),
        'amount_minor' => 5000, 'currency' => 'USD', 'exponent' => 2,
        'reference' => $payment_ref->value, 'description' => 'P8 synthetic bank credit',
        'transaction_id' => 'P8-STALE-' . $order_id,
    ];
    if (1 !== $wpdb->insert($rows, [
        'batch_id' => $batch, 'source_row' => 2,
        'canonical_json' => wp_json_encode($canonical), 'error_code' => '',
        'identity_fingerprint' => $fingerprint, 'warning_code' => '',
    ])) throw new RuntimeException('Synthetic bank source missing.');
    $credit = (int)$wpdb->insert_id;
    $proposal = new OneToOneProposal($wpdb);
    $approval = new OneToOneApproval($wpdb);
    $key = hash('sha256', 'p8-stale-request-' . $order_id);
    $reason = 'Synthetic exact credit is being proposed for finance verification.';
    $review_reason = 'Independent CI reviewer confirms only a synthetic cleared bank credit.';
    $created = $proposal->propose($order_id, $credit, $admin->ID, $key, $reason);
    $allocation = (int)$created['allocation_id'];
    if ('proposed' !== $created['state'] || $created['order_payment_changed']) {
        throw new RuntimeException('Unreviewed proposal changed payment state.');
    }
    $same = $proposal->propose($order_id, $credit, $admin->ID, $key, $reason);
    if ($same !== $created) throw new RuntimeException('Idempotent proposal replay changed ledger.');
    try {
        $proposal->propose($order_id, $credit, $admin->ID,
            hash('sha256', 'different-p8-request-' . $order_id), $reason);
        throw new RuntimeException('Duplicate bank-credit proposal was accepted.');
    } catch (InvalidArgumentException $expected) {}

    $reviewer_id = wp_create_user('wbm-p8-checker-' . wp_generate_password(8, false),
        wp_generate_password(32), 'p8-checker-' . wp_generate_password(8, false) . '@example.test');
    if (is_wp_error($reviewer_id)) throw new RuntimeException('Cannot create reviewer.');
    (new WP_User((int)$reviewer_id))->set_role('shop_manager');
    wp_set_current_user((int)$reviewer_id);
    $assert_rejected = static function (callable $operation, string $test): void {
        try {
            $operation();
        } catch (InvalidArgumentException $correct) {
            return;
        }
        throw new RuntimeException('Stale/invalid ledger proposal was accepted: ' . $test);
    };

    // WooCommerce order status can change after a source credit is proposed;
    // the checker must re-read current real order state, never trust snapshot.
    $order->update_status('cancelled');
    $assert_rejected(fn () => $approval->approve($allocation, $reviewer_id, true, $review_reason),
        'cancelled WooCommerce order');
    $order->update_status('on-hold');

    // Merchant import source can become disputed or tampered after proposal.
    $wpdb->update($rows, ['warning_code'=>'possible_duplicate'], ['id'=>$credit]);
    $assert_rejected(fn () => $approval->approve($allocation, $reviewer_id, true, $review_reason),
        'source marked as possibly duplicated');
    $wpdb->update($rows, ['warning_code'=>''], ['id'=>$credit]);
    $modified = $canonical;
    $modified['amount_minor'] = 4999;
    $wpdb->update($rows, ['canonical_json'=>wp_json_encode($modified)], ['id'=>$credit]);
    $assert_rejected(fn () => $approval->approve($allocation, $reviewer_id, true, $review_reason),
        'credit changed after proposal');
    $wpdb->update($rows, ['canonical_json'=>wp_json_encode($canonical)], ['id'=>$credit]);

    // Same bank fingerprint in a second imported row invalidates original.
    if (1 !== $wpdb->insert($rows, [
        'batch_id'=>$batch, 'source_row'=>3,
        'canonical_json'=>wp_json_encode(array_replace($canonical, ['source_row'=>3])),
        'error_code'=>'', 'identity_fingerprint'=>$fingerprint, 'warning_code'=>'',
    ])) throw new RuntimeException('Could not stage overlapping synthetic bank row.');
    $duplicate_id = (int)$wpdb->insert_id;
    $assert_rejected(fn () => $approval->approve($allocation, $reviewer_id, true, $review_reason),
        'duplicated identity fingerprint');
    $wpdb->delete($rows, ['id'=>$duplicate_id], ['%d']);
    $duplicate_id = 0;

    // A different SQL connection locking the same bank source must block
    // whole proposal approval. No partial writes or waiting for another user.
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $held_lock = hash('sha256', DB_NAME . '/' . $wpdb->prefix . '/wbm-pro-source/' . $credit);
    if ('1' !== (string)$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $held_lock))) {
        throw new RuntimeException('Cannot create independent source contention.');
    }
    try {
        $approval->approve($allocation, $reviewer_id, true, $review_reason);
        throw new RuntimeException('Source was approved while concurrently locked.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'currently being reconciled')) throw $expected;
    }
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $held_lock));
    $held_lock = null;

    if ((string)$wpdb->get_var($wpdb->prepare(
        "SELECT state FROM $allocations WHERE id=%d", $allocation)) !== 'proposed' ||
        (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $events WHERE allocation_id=%d", $allocation)) !== 1 ||
        wc_get_order($order_id)->is_paid()) {
        throw new RuntimeException('Negative stale/concurrent tests modified unpaid ledger.');
    }

    $verified = $approval->approve($allocation, $reviewer_id, true, $review_reason);
    $replayed = $approval->approve($allocation, $reviewer_id, true, $review_reason);
    $snapshot = (new AllocationLedgerRepository($wpdb))->snapshot(
        $order_id, $credit, 5000, 5000, 'USD', 2, $account
    );
    if ($verified['status'] !== 'approved' ||
        $replayed['status'] !== 'already_approved' ||
        $snapshot['order_outstanding_minor'] !== 0 ||
        $snapshot['credit_unallocated_minor'] !== 0 ||
        (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events WHERE allocation_id=%d", $allocation)) !== 2 ||
        wc_get_order($order_id)->is_paid()) {
        throw new RuntimeException('Approved allocation replay created double funding or paid order.');
    }
    echo "PASS P8-T004 real MariaDB cancelled-order/CSV provenance/credit tamper/fingerprint replay/source lock/idempotent maker-checker: no unintended Woo payment\n";
} finally {
    if ($held_lock !== null && $other) {
        $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $held_lock));
    }
    wp_set_current_user($admin->ID);
    if ($credit > 0) {
        $allocation_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wbm_pro_allocations WHERE transaction_row_id=%d", $credit
        ));
        foreach ($allocation_ids as $id) {
            $wpdb->delete($wpdb->prefix . 'wbm_pro_allocation_events',
                ['allocation_id'=>(int)$id], ['%d']);
        }
        $wpdb->delete($wpdb->prefix . 'wbm_pro_allocations',
            ['transaction_row_id'=>$credit], ['%d']);
    }
    if ($duplicate_id) $wpdb->delete($wpdb->prefix . 'wbm_pro_import_rows', ['id'=>$duplicate_id], ['%d']);
    if ($batch) {
        $wpdb->delete($wpdb->prefix . 'wbm_pro_import_rows', ['batch_id'=>$batch], ['%d']);
        $wpdb->delete($wpdb->prefix . 'wbm_pro_import_batches', ['id'=>$batch], ['%d']);
    }
    if ($order instanceof WC_Order) {
        $wpdb->delete($wpdb->prefix . 'wbm_pro_order_bank_events',
            ['order_id'=>$order->get_id()], ['%d']);
        $wpdb->delete($wpdb->prefix . 'wbm_pro_order_receiving_accounts',
            ['order_id'=>$order->get_id()], ['%d']);
        $order->delete(true);
    }
    if ($account) $wpdb->delete($wpdb->prefix . 'wbm_pro_bank_accounts', ['id'=>$account], ['%d']);
    if (is_int($reviewer_id) && $reviewer_id > 0) wp_delete_user($reviewer_id);
    wp_set_current_user($original_user);
}
