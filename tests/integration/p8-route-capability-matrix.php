<?php
/**
 * P8-T001: real registered WordPress REST role/capability matrix, with no
 * authenticated financial POST, approval, payment or account mutation.
 * Run only through disposable WP-CLI + WooCommerce + MariaDB wbm_ci.
 */
use WPEssential\BankTransferMatcher\Claims\ClaimReviews;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'P8 authorization requires disposable WordPress/MariaDB wbm_ci.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$root = dirname( __DIR__, 2 );
$manifest = json_decode(
	(string) file_get_contents( $root . '/tests/fixtures/p7-real-ui-routes.json' ),
	true, 512, JSON_THROW_ON_ERROR
);
$rest_specs = $manifest['rest'] ?? null;
$admin_specs = $manifest['admin_post'] ?? null;
if ( ! is_array( $rest_specs ) || count( $rest_specs ) !== 10 ||
	! is_array( $admin_specs ) || count( $admin_specs ) !== 24 ) {
	throw new RuntimeException( 'The real P7 endpoint inventory changed without P8 review.' );
}

// This map is intentionally independent from the generated UI manifest.
$route_gates = [
	'/wpessential-wbm/v1/capabilities' => [ 'wbm_view' ],
	'/wpessential-wbm/v1/orders/(?P<order_id>\d+)/claim/review' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/orders/(?P<order_id>\d+)/claim/notifications/(?P<event_id>\d+)/retry' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/orders/(?P<order_id>\d+)/bank-evidence' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/orders/(?P<order_id>\d+)/allocations' => [ 'wbm_approve' ],
	'/wpessential-wbm/v1/orders/(?P<order_id>\d+)/finalize-payment' => [ 'wbm_approve' ],
	'/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/transition' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/assign' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/priority' => [ 'wbm_review_claim' ],
	'/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/comments' => [ 'wbm_review_claim' ],
];
$manifest_routes = array_column( $rest_specs, 'route' );
if ( count( $manifest_routes ) !== count( array_unique( $manifest_routes ) ) ||
	array_diff( $manifest_routes, array_keys( $route_gates ) ) ||
	array_diff( array_keys( $route_gates ), $manifest_routes ) ) {
	throw new RuntimeException( 'REST routes deviate from independently enumerated P8 permission requirements.' );
}
$routes = rest_get_server()->get_routes();
$get_handler = static function ( array $spec ) use ( $routes ): array {
	$registered = $routes[$spec['route']] ?? null;
	if ( ! is_array( $registered ) ) {
		throw new RuntimeException( 'Missing real registered route: ' . $spec['route'] );
	}
	$found = [];
	foreach ( $registered as $handler ) {
		if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) continue;
		$methods = $handler['methods'];
		$allows = is_array( $methods ) ? ! empty( $methods[$spec['method']] )
			: ( is_string( $methods ) && in_array(
				$spec['method'], array_map( 'trim', explode( ',', $methods ) ), true
			) );
		if ( $allows && is_callable( $handler['permission_callback'] ?? null ) &&
			is_callable( $handler['callback'] ?? null ) ) {
			$found[] = $handler;
		}
	}
	if ( count( $found ) !== 1 ) {
		throw new RuntimeException( 'Ambiguous or missing authorized method on: ' . $spec['route'] );
	}
	return $found[0];
};
foreach ( $admin_specs as $spec ) {
	$action = $spec['name'] ?? '';
	$public = has_action( 'admin_post_nopriv_' . $action );
	if ( false === has_action( 'admin_post_' . $action ) ) {
		throw new RuntimeException( 'Unregistered native admin finance action: ' . $action );
	}
	$expected_guest = 'wpessential_wbm_submit_claim' === $action;
	if ( $expected_guest !== ( false !== $public ) ||
		$expected_guest !== $spec['guest'] ) {
		throw new RuntimeException( 'Unexpected public admin-post financial route: ' . $action );
	}
}

// Exercise each permission_callback exactly as registered in WP_REST_Server.
// Never invoke any finance POST callback with an authorized identity.
$role_sets = [
	'anonymous' => [],
	'customer' => [],
	'viewer' => [ 'wbm_view' ],
	'importer' => [ 'wbm_view', 'wbm_import' ],
	'account_metadata_only' => [ 'wbm_view', 'wbm_manage_bank_accounts' ],
	'reviewer' => [ 'wbm_view', 'wbm_review_claim' ],
	'approver' => [ 'wbm_view', 'wbm_approve' ],
	'finance' => [ 'wbm_view', 'wbm_review_claim', 'wbm_approve', 'wbm_import' ],
	'full_manager' => [ 'wbm_view', 'wbm_review_claim', 'wbm_approve', 'wbm_import', 'wbm_manage_bank_accounts' ],
];
$capabilities = [ 'wbm_view', 'wbm_review_claim', 'wbm_approve', 'wbm_import', 'wbm_manage_bank_accounts' ];
$original_user = get_current_user_id();
$created = [];
$probes = 0;
try {
	foreach ( $role_sets as $persona => $granted ) {
		if ( 'anonymous' === $persona ) {
			wp_set_current_user( 0 );
		} else {
			$name = 'wbm_p8_' . $persona . '_' . getmypid();
			$user_id = wp_create_user( $name, wp_generate_password( 40 ), $name . '@example.test' );
			if ( is_wp_error( $user_id ) ) {
				throw new RuntimeException( 'Could not create synthetic role: ' . $persona );
			}
			$created[] = (int) $user_id;
			$actor = new WP_User( (int) $user_id );
			$actor->set_role( 'subscriber' );
			wp_set_current_user( (int) $user_id );
			$active = wp_get_current_user();
			foreach ( $capabilities as $capability ) {
				$active->add_cap( $capability, in_array( $capability, $granted, true ) );
				if ( current_user_can( $capability ) !== in_array( $capability, $granted, true ) ) {
					throw new RuntimeException( 'Synthetic WordPress effective role mismatch: ' . $persona . '/' . $capability );
				}
			}
		}
		foreach ( $rest_specs as $spec ) {
			$handler = $get_handler( $spec );
			$required = $route_gates[$spec['route']];
			$allowed = [] === array_diff( $required, $granted );
			$req = new WP_REST_Request( $spec['method'], $spec['anonymous_probe'] );
			$permission = call_user_func( $handler['permission_callback'], $req );
			if ( $allowed ) {
				if ( true !== $permission ) {
					throw new RuntimeException( 'Authorized role was denied: ' . $persona . '/' . $spec['route'] );
				}
			} else {
				if ( ! is_wp_error( $permission ) ) {
					throw new RuntimeException( 'Unauthorized role bypassed REST gate: ' . $persona . '/' . $spec['route'] );
				}
				$meta = $permission->get_error_data();
				$status = is_array( $meta ) ? (int) ( $meta['status'] ?? 0 ) : (int) $meta;
				if ( ! in_array( $status, [ 401, 403 ], true ) ) {
					throw new RuntimeException( 'REST denial has no 401/403: ' . $persona . '/' . $spec['route'] );
				}
			}
			++$probes;
		}
		// The only REST dispatch in this suite is read-only. Also test its
		// returned role flags rather than trusting the permission callback alone.
		$response = rest_do_request( new WP_REST_Request(
			'GET', '/wpessential-wbm/v1/capabilities'
		) );
		$view = in_array( 'wbm_view', $granted, true );
		if ( $view ) {
			if ( 200 !== $response->get_status() ) {
				throw new RuntimeException( 'Authorized capability GET failed: ' . $persona );
			}
			$data = $response->get_data();
			foreach ( [ 'wbm_view', 'wbm_review_claim', 'wbm_approve' ] as $capability ) {
				if ( ! isset( $data[$capability] ) ||
					$data[$capability] !== in_array( $capability, $granted, true ) ) {
					throw new RuntimeException( 'Returned capability differs from actual actor: ' . $persona . '/' . $capability );
				}
			}
		} elseif ( ! in_array( $response->get_status(), [ 401, 403 ], true ) ) {
			throw new RuntimeException( 'Restricted capability GET leaked to ' . $persona );
		}
		++$probes;

		// Defense in depth: for claim state "verified" the registered route
		// permits a reviewer, but the *callback* requires wbm_approve too.
		// Reviewer-only denial occurs before any database reads or writes.
		if ( 'reviewer' === $persona ) {
			$req = new WP_REST_Request(
				'POST', '/wpessential-wbm/v1/orders/999999/claim/review'
			);
			$req->set_param( 'order_id', 999999 );
			$req->set_param( 'status', 'verified' );
			$req->set_param( 'comment', 'P8 negative authorization only' );
			$denial = ClaimReviews::review( $req );
			if ( ! is_wp_error( $denial ) || 'wbm_forbidden' !== $denial->get_error_code() ) {
				throw new RuntimeException( 'Reviewer-only role could verify a customer claim.' );
			}
			++$probes;
		}
	}
	echo 'PASS P8-T001 real WordPress role/REST matrix: ' . count( $role_sets ) .
		' personas, ' . count( $rest_specs ) . ' routes, ' . $probes .
		' permission/read-only probes, 24 private/guest admin hooks, no authorized finance POST or order mutation' . "\n";
} finally {
	wp_set_current_user( $original_user );
	foreach ( $created as $id ) {
		wp_delete_user( $id );
	}
}
