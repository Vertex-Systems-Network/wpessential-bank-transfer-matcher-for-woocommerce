<?php
/**
 * Synthetic P7 browser-only screenshot fixture, never a production migration.
 * Creates no real customer identity, bank account number or cleared payment.
 * Executable only in disposable GitHub Actions WordPress/MariaDB.
 */
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\Money;
use WPEssential\BankTransferMatcher\Orders\ReferenceIssuer;
use WPEssential\BankTransferMatcher\Orders\ReferenceRepository;
use WPEssential\BankTransferMatcher\Claims\ClaimRepository;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfileRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;
use WPEssential\BankTransferMatcherPro\Matching\GeneratedCase;
use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ||
	getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {
	throw new RuntimeException( 'P7 browser seed requires an isolated CI-only wbm_ci WordPress database.' );
}
global $wpdb;
$user = get_user_by( 'login', 'wbm_ci' );
if ( ! $user || ! class_exists( 'WooCommerce' ) ||
	! class_exists( ReferenceIssuer::class ) ||
	! class_exists( ImportJob::class ) ) {
	throw new RuntimeException( 'Both real WooCommerce Free/Pro editions must be active for browser screenshots.' );
}
wp_set_current_user( $user->ID );

$checkout_id = wc_get_page_id( 'checkout' );
if ( $checkout_id < 1 || 'publish' !== get_post_status( $checkout_id ) ) {
	$checkout_id = wp_insert_post( [
		'post_type' => 'page', 'post_title' => 'Synthetic CI checkout',
		'post_status' => 'publish', 'post_content' => '[woocommerce_checkout]',
	], true );
	if ( is_wp_error( $checkout_id ) ) {
		throw new RuntimeException( 'Could not create disposable checkout page.' );
	}
	update_option( 'woocommerce_checkout_page_id', (int) $checkout_id );
}

$order = wc_create_order( [ 'status' => 'on-hold' ] );
if ( ! $order || is_wp_error( $order ) ) {
	throw new RuntimeException( 'Synthetic BACS order cannot be created.' );
}
$order->set_payment_method( 'bacs' );
$order->set_status( 'on-hold' );
$order->set_billing_first_name( 'Synthetic' );
$order->set_billing_last_name( 'Screenshot' );
$order->set_billing_email( 'p7-ci-fixture@example.test' );
$order->set_total( '249.00' );
$order->save();
$order_id = (int) $order->get_id();

( new ReferenceIssuer( new ReferenceRepository( $wpdb ) ) )
	->issue( new EntityId( $order_id ) );
( new ClaimRepository( $wpdb ) )->save( $order_id, [
	'date' => gmdate( 'Y-m-d' ),
	'amount' => Money::from_decimal( '49.00', $order->get_currency(),
		wc_get_price_decimals() ),
	'reference' => 'SYNTHETIC-P7-CLAIM',
	'sender' => 'Synthetic Source',
	'note' => 'CI-only customer claim; NOT bank clearance',
] );
$bank_id = ( new BankAccountRepository( $wpdb ) )->create(
	'Synthetic USD account', 'CI Fixture Bank', 'USD', 'P700', $user->ID
);
$profile = ( new MappingProfileRepository() )->create( [
	'name' => 'CI synthetic statement CSV',
	'delimiter' => ',', 'date_header' => 'Date',
	'date_format' => 'Y-m-d', 'amount_header' => 'Amount',
	'decimal_separator' => '.', 'thousands_separator' => '',
	'exponent' => 2, 'currency_header' => '', 'fixed_currency' => 'USD',
	'reference_header' => 'Reference', 'description_header' => 'Description',
	'transaction_id_header' => 'Bank ID',
] );
$temporary = tempnam( sys_get_temp_dir(), 'wbm-p7-browser-' );
if ( ! is_string( $temporary ) ) throw new RuntimeException( 'No synthetic CSV temp file.' );
file_put_contents( $temporary,
	"Date,Amount,Reference,Description,Bank ID\n" .
	gmdate( 'Y-m-d' ) .
	",49.00,SYNTHETIC-CREDIT,CI-only source,CI-P7-001\n"
);
try {
	$job = new ImportJob( $wpdb );
	$batch_id = $job->start( $temporary, $bank_id, $profile, $user->ID );
	$job->process( $batch_id );
} finally {
	unlink( $temporary );
}
$source_row = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}wbm_pro_import_rows
	 WHERE batch_id=%d AND error_code='' ORDER BY id ASC LIMIT 1",
	$batch_id
) );
if ( $source_row < 1 ) throw new RuntimeException( 'Synthetic source row was not staged.' );
$case_id = ( new ReviewCaseRepository( $wpdb ) )->create(
	new GeneratedCase(
		'conflict', $source_row, $order_id, 'high',
		[ ExplanationCode::CURRENCY_CONFLICT ]
	),
	$user->ID
);
$fixture = [
	'version' => 1,
	'disposable' => true,
	'order_id' => $order_id,
	'bank_account_id' => $bank_id,
	'batch_id' => $batch_id,
	'case_id' => $case_id,
	'checkout_received_url' => $order->get_checkout_order_received_url(),
	'site_url' => site_url(),
];
$json = wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$dest = getenv( 'P7_BROWSER_FIXTURE_FILE' );
if ( ! is_string( $json ) || ! is_string( $dest ) ||
	! str_starts_with( $dest, sys_get_temp_dir() . '/' ) ||
	file_put_contents( $dest, $json ) === false ) {
	throw new RuntimeException( 'Could not write disposable screenshot metadata to runner temp.' );
}
echo "PASS CI-only browser seed: synthetic unverified claim, one BACS order, private CSV batch, masked account and review case\n";
