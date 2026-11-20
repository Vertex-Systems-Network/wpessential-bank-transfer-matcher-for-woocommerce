<?php
use WPEssential\BankTransferMatcherPro\Admin\FinanceDashboardPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportBatchRepository;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
use WPEssential\BankTransferMatcherPro\Reporting\FinanceDashboardSnapshot;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Dashboard integration requires disposable wbm_ci.' );
}
global $wpdb;
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing finance test administrator.' );
$previous_actor = get_current_user_id();
$original_get = $_GET;
wp_set_current_user( $admin->ID );
$banks = new BankAccountRepository( $wpdb );
$usd = $banks->create( 'Dashboard USD source', 'Synthetic', 'USD', '1177', $admin->ID );
$eur = $banks->create( 'Dashboard EUR source', 'Synthetic', 'EUR', '8822', $admin->ID );
$profile = static fn ( string $currency ): MappingProfile => MappingProfile::from_array( [
	'id' => 'dashboard-' . strtolower( $currency ),
	'name' => 'Dashboard synthetic profile', 'delimiter' => ',',
	'date_header' => 'Date', 'date_format' => 'Y-m-d',
	'amount_header' => 'Amount', 'decimal_separator' => '.',
	'thousands_separator' => '', 'exponent' => 2, 'currency_header' => '',
	'fixed_currency' => $currency, 'reference_header' => 'Reference',
	'description_header' => '', 'transaction_id_header' => 'Bank ID',
] );
$batches = new ImportBatchRepository( $wpdb );
$ids = [];
$snapshot = new FinanceDashboardSnapshot( $wpdb );
$today = gmdate( 'Y-m-d' );
$base = [
	'from' => $today, 'to' => $today, 'currency' => 'USD',
	'bank_account_id' => $usd, 'focus_report' => 'import_batches',
	'focus_state' => 'pending', 'limit' => 20, 'before_id' => 0,
];
try {
	for ( $n = 0; $n < 22; ++$n ) {
		$account = $n === 21 ? $eur : $usd;
		$currency = $n === 21 ? 'EUR' : 'USD';
		$ids[] = $batches->create( $account, $profile( $currency ),
			hash( 'sha256', 'dashboard-batch-' . wp_generate_uuid4() ),
			100, $admin->ID
		);
	}
	if ( false === has_action( 'admin_menu', [ FinanceDashboardPage::class, 'menu' ] ) ) {
		throw new RuntimeException( 'Finance dashboard is missing from the native admin menu.' );
	}
	$first = $snapshot->read( $base );
	if ( ! $first['groups']['import_batches']['available'] ||
		$first['groups']['import_batches']['row_count'] !== 20 ||
		! $first['groups']['import_batches']['has_more'] ||
		$first['groups']['import_batches']['page_totals']['USD/0/pending']['count'] !== 20 ||
		! $first['groups']['import_batches']['page_scoped'] ||
		count( $first['focused']['rows'] ) !== 20 ) {
		throw new RuntimeException( 'Finance dashboard failed bounded import batch KPI scope.' );
	}
	$cursor = $first['groups']['import_batches']['next_before_id'];
	$next = $snapshot->read( array_replace( $base, [ 'before_id' => $cursor ] ) );
	if ( $cursor < 1 || $next['groups']['import_batches']['row_count'] !== 1 ||
		$next['groups']['import_batches']['has_more'] ||
		$next['focused']['rows'][0]['id'] === $first['focused']['rows'][19]['id'] ) {
		throw new RuntimeException( 'Dashboard record pages duplicated rows or incorrectly counted the ledger.' );
	}
	$all = $snapshot->read( array_replace( $base, [
		'currency' => 'ALL', 'bank_account_id' => 0,
		'limit' => 100, 'before_id' => 0, 'focus_state' => 'all',
	] ) );
	if ( ! $all['groups']['import_batches']['available'] ||
		$all['groups']['import_batches']['row_count'] !== 22 ||
		$all['groups']['import_batches']['page_totals']['USD/0/pending']['count'] !== 21 ||
		$all['groups']['import_batches']['page_totals']['EUR/0/pending']['count'] !== 1 ) {
		throw new RuntimeException( 'Multi-currency dashboard merged independent ISO currency totals.' );
	}
	foreach ( [ 'outstanding_orders', 'partial_funding', 'unmatched_credits',
		'exception_aging', 'allocation_audit' ] as $source ) {
		if ( ! array_key_exists( $source, $all['groups'] ) ||
			! array_key_exists( 'available', $all['groups'][$source] ) ) {
			throw new RuntimeException( 'Missing source availability card; missing schema must never become zero.' );
		}
	}
	$_GET = array_replace( $base, [
		'page' => 'wpessential-wbm-pro-dashboard', 'limit' => '20',
		'bank_account_id' => (string) $usd, 'before_id' => '0',
	] );
	ob_start();
	FinanceDashboardPage::render();
	$html = ob_get_clean();
	if ( ! str_contains( $html, 'Bank finance dashboard' ) ||
		! str_contains( $html, 'Source-backed report pages' ) ||
		! str_contains( $html, 'Next bounded source page' ) ||
		! str_contains( $html, 'minor units' ) ||
		str_contains( $html, 'customer_email' ) ) {
		throw new RuntimeException( 'Finance dashboard failed native screen/rendering safety checks.' );
	}
	foreach ( [
		array_replace( $base, [ 'focus_state' => 'approved' ] ),
		array_replace( $base, [ 'currency' => 'invalid' ] ),
		array_replace( $base, [ 'limit' => 999999 ] ),
		array_replace( $base, [ 'bank_account_id' => '-1' ] ),
		array_replace( $base, [ 'unknown' => 'ignored' ] ),
	] as $bad ) {
		try {
			$snapshot->read( $bad );
			throw new RuntimeException( 'Invalid dashboard financial report filter accepted.' );
		} catch ( InvalidArgumentException $expected ) {}
	}
	wp_set_current_user( 0 );
	try {
		$snapshot->read( $base );
		throw new RuntimeException( 'Anonymous viewer accessed finance dashboard.' );
	} catch ( RuntimeException $expected ) {
		if ( 'Anonymous viewer accessed finance dashboard.' === $expected->getMessage() ) throw $expected;
	}
	echo "PASS finance dashboard native WooCommerce card/role, exact USD/EUR pages, 21+1 synthetic batches, bounded cursor and safe read-only filters\n";
} finally {
	wp_set_current_user( $admin->ID );
	$_GET = $original_get;
	foreach ( $ids as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_import_batches', [ 'id' => $id ], [ '%d' ] );
	}
	foreach ( [ $usd, $eur ] as $id ) {
		$wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $id ], [ '%d' ] );
	}
	wp_set_current_user( $previous_actor );
}
