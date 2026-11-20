<?php
$root = dirname( __DIR__ );
$spec = $root . '/docs/ux/p7-source-backed-screen-contracts.md';
$body = is_file( $spec ) ? file_get_contents( $spec ) : false;
if ( ! is_string( $body ) || strlen( $body ) < 9000 ) {
	throw new RuntimeException( 'P7 UI contract document is missing or incomplete.' );
}
preg_match_all( '/^## UI-(\d{3}) —/m', $body, $matches );
$expected = array_map( static fn ( int $i ): string => sprintf( '%03d', $i ), range( 1, 14 ) );
if ( $matches[1] !== $expected || count( array_unique( $matches[1] ) ) !== 14 ) {
	throw new RuntimeException( 'P7 screen contracts must cover UI-001 through UI-014 once, in order.' );
}
// Every real screen must point to an actual WP/Free/Pro source. Do not
// accept a Figma-only mockup or invented route as implementation evidence.
$free = 'plugins/wpessential-bank-transfer-matcher/src/';
$pro  = 'plugins/wpessential-bank-transfer-matcher-pro/src/';
$sources = [
	'001' => $free . 'Admin/Overview.php',
	'002' => $free . 'Admin/OrdersPage.php',
	'003' => $free . 'Admin/OrderPanel.php',
	'004' => $free . 'Claims/ClaimReviews.php',
	'005' => $free . 'Admin/ReconciliationPage.php',
	'006' => $free . 'Orders/ReferencePresentation.php',
	'007' => $pro . 'Admin/ImportStatementsPage.php',
	'008' => $pro . 'Admin/BankTransactionsPage.php',
	'009' => $pro . 'Admin/ReviewQueuePage.php',
	'010' => $pro . 'Admin/AllocationReviewPage.php',
	'011' => $pro . 'Admin/ReportExportsPage.php',
	'012' => $free . 'Admin/AuditTimeline.php',
	'013' => $free . 'Admin/SettingsPage.php',
	'014' => $pro . 'Admin/FinanceOperationsPage.php',
];
foreach ( $sources as $id => $source ) {
	if ( ! is_file( $root . '/' . $source ) ) {
		throw new RuntimeException( 'Invented or removed UI-' . $id . ' PHP source: ' . $source );
	}
	if ( stripos( $body, basename( $source ) ) === false ) {
		throw new RuntimeException( 'UI-' . $id . ' audit omits actual renderer name.' );
	}
}
foreach ( [
	'not yet', 'Gaps:', 'permission', 'nonce', 'ISO currency',
	'filtered empty', 'source unavailable', 'no automatic', 'RTL', '1440/1024/375',
] as $topic ) {
	if ( stripos( $body, $topic ) === false ) {
		throw new RuntimeException( 'P7 contract lacks gap, currency, auth or accessibility detail: ' . $topic );
	}
}
if ( preg_match( '/(all\s+14\s+screens\s+are\s+fully\s+implemented|RTL\s+screenshots\s+have\s+passed)/i', $body ) ) {
	throw new RuntimeException( 'Do not promote P7 visual QA before browser evidence exists.' );
}
echo "PASS 14-source WordPress Free/Pro UI screen contracts, explicit gap/audit states and future RTL/browser gates\n";
