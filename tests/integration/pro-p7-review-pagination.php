<?php
/**
 * P7-T005: native WordPress review queue page boundary, filter isolation and
 * textual status badges. Exactly-full final page must NOT offer phantom Next.
 * All generated records live in disposable CI-only wbm_ci and are cleaned up.
 */
use WPEssential\BankTransferMatcherPro\Admin\ReviewQueuePage;
use WPEssential\BankTransferMatcherPro\Matching\ReviewCaseRepository;
use WPEssential\BankTransferMatcherPro\Matching\GeneratedCase;
use WPEssential\BankTransferMatcherPro\Matching\ExplanationCode;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'P7 review pagination fixture is disposable wbm_ci only.' );
}
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
$admin = get_user_by( 'login', 'wbm_ci' );
if ( ! $admin ) throw new RuntimeException( 'Missing browser CI finance operator.' );
$prior_user = get_current_user_id();
$prior_get = $_GET;
$assignee = 0;
$ids = [];
wp_set_current_user( $admin->ID );
$repo = new ReviewCaseRepository( $wpdb );
$render = static function ( int $page ) use ( &$assignee ): string {
	$_GET = [
		'page' => 'wpessential-wbm-pro-review-queue',
		'status' => 'assigned', 'priority' => 'normal',
		'assignee' => (string) $assignee, 'per_page' => '20',
		'paged' => (string) $page,
	];
	ob_start();
	try {
		ReviewQueuePage::render();
		return (string) ob_get_contents();
	} finally {
		ob_end_clean();
	}
};
try {
	$login = 'wbm_p7_page_' . strtolower( wp_generate_password( 9, false, false ) );
	$user = wp_create_user( $login, wp_generate_password( 20 ),
		$login . '@example.test' );
	if ( is_wp_error( $user ) || (int) $user < 1 ) {
		throw new RuntimeException( 'Could not create isolated case assignee.' );
	}
	$assignee = (int) $user;
	for ( $i = 1; $i <= 20; ++$i ) {
		$id = $repo->create( new GeneratedCase(
			'conflict', 880000 + $i, null, 'normal',
			[ ExplanationCode::CURRENCY_CONFLICT ]
		), $admin->ID );
		$ids[] = $id;
		$repo->assign( $id, $assignee, $admin->ID );
	}
	$exact_final = $render( 1 );
	if ( 20 !== substr_count( $exact_final, 'data-state="assigned"' ) ||
		20 !== substr_count( $exact_final, 'data-priority="normal"' ) ||
		str_contains( $exact_final, '>Next</a>' ) ||
		! str_contains( $exact_final, 'id="wbm-review-page-size"' ) ||
		! str_contains( $exact_final, 'role="region"' ) ) {
		throw new RuntimeException( 'Exactly 20 filtered cases produced phantom Next, hidden state, or inaccessible controls.' );
	}
	$id = $repo->create( new GeneratedCase(
		'conflict', 880100, null, 'normal',
		[ ExplanationCode::CURRENCY_CONFLICT ]
	), $admin->ID );
	$ids[] = $id;
	$repo->assign( $id, $assignee, $admin->ID );
	$first = $render( 1 );
	$second = $render( 2 );
	$stale = $render( 3 );
	if ( 20 !== substr_count( $first, 'data-state="assigned"' ) ||
		! str_contains( $first, '>Next</a>' ) ||
		1 !== substr_count( $second, 'data-state="assigned"' ) ||
		str_contains( $second, '>Next</a>' ) ||
		! str_contains( $second, '>Previous</a>' ) ||
		! str_contains( $stale, 'No review cases match these filters.' ) ||
		! str_contains( $stale, 'Previous source page' ) ||
		str_contains( $stale, '>Next</a>' ) ) {
		throw new RuntimeException( 'Review case pagination did not preserve exact 20/21 row boundaries and stale-page recovery.' );
	}
	echo "PASS native review queue exact-20/no-phantom-Next, 21-row second page, stale-page Previous and textual status badges\n";
} finally {
	$_GET = $prior_get;
	wp_set_current_user( $admin->ID );
	if ( $ids ) {
		$list = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wbm_pro_review_events WHERE case_id IN ($list)",
			...$ids
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wbm_pro_review_comments WHERE case_id IN ($list)",
			...$ids
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wbm_pro_review_cases WHERE id IN ($list)",
			...$ids
		) );
	}
	if ( $assignee > 0 ) wp_delete_user( $assignee );
	wp_set_current_user( $prior_user );
}
