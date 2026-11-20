<?php
/**
 * P7-T003: actual WordPress hook + REST registry truth, no invented actions.
 * Uses disposable WP/WooCommerce/MariaDB fixture and probes NO financial
 * mutation endpoints with an authorized identity.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run P7 route security audit in disposable wbm_ci only.' );
}
$root = dirname( __DIR__, 2 );
$manifest = json_decode(
	(string) file_get_contents( $root . '/tests/fixtures/p7-real-ui-routes.json' ),
	true, 512, JSON_THROW_ON_ERROR
);
if ( ! is_array( $manifest ) || count( $manifest['screens'] ?? [] ) !== 14 ||
	count( $manifest['admin_post'] ?? [] ) !== 24 ||
	count( $manifest['rest'] ?? [] ) !== 10 ) {
	throw new RuntimeException( 'Incomplete P7 source-backed WordPress route registry audit.' );
}
$owner = get_user_by( 'login', 'wbm_ci' );
if ( ! $owner ) throw new RuntimeException( 'Synthetic finance admin missing.' );
$original_user = get_current_user_id();
try {
	wp_set_current_user( $owner->ID );
	foreach ( [ 'wbm_view', 'wbm_review_claim', 'wbm_approve',
		'wbm_import', 'wbm_manage_bank_accounts' ] as $permission ) {
		if ( ! current_user_can( $permission ) ) {
			throw new RuntimeException( 'Test fixture lacks the exact actual finance capability: ' . $permission );
		}
	}
	$guest_actions = [];
	foreach ( $manifest['admin_post'] as $a ) {
		$name = $a['name'];
		$registered = has_action( 'admin_post_' . $name );
		if ( false === $registered ) {
			throw new RuntimeException( 'Visible financial control has no real admin-post hook: ' . $name );
		}
		$guest = has_action( 'admin_post_nopriv_' . $name );
		if ( $a['guest'] === true ) {
			$guest_actions[] = $name;
			if ( 'wpessential_wbm_submit_claim' !== $name ||
				'POST' !== $a['method'] || false === $guest ) {
				throw new RuntimeException( 'Only order-key+email+nonce-scoped claim submission may permit guests.' );
			}
		} elseif ( false !== $guest ) {
			throw new RuntimeException( 'Finance/admin/customer private operation exposed to anonymous user: ' . $name );
		}
	}
	if ( $guest_actions !== [ 'wpessential_wbm_submit_claim' ] ) {
		throw new RuntimeException( 'Registered public forms do not match the intentional customer claim.' );
	}
	// This is the authoritative, registered WP_REST_Server routes collection.
	// It is distinct from the PHP source audit and does not guess endpoints.
	$server = rest_get_server();
	$routes = $server->get_routes();
	foreach ( $manifest['rest'] as $spec ) {
		$route = $spec['route'];
		if ( ! array_key_exists( $route, $routes ) ) {
			throw new RuntimeException( 'Unregistered REST route advertised by real UI: ' . $route );
		}
		$handlers = array_values( array_filter(
			$routes[$route],
			static fn ( mixed $entry ): bool => is_array( $entry ) &&
				isset( $entry['methods'] ) &&
				isset( $entry['permission_callback'] )
		) );
		$found = false;
		foreach ( $handlers as $handler ) {
			$methods = $handler['methods'];
			if ( is_array( $methods ) ) {
				$allows = ! empty( $methods[$spec['method']] );
			} else {
				$allows = is_string( $methods ) &&
					in_array( $spec['method'], explode( ',', $methods ), true );
			}
			if ( ! $allows || ! is_callable( $handler['permission_callback'] ) ||
				! is_callable( $handler['callback'] ?? null ) ) continue;
			$fields = $handler['args'] ?? [];
			foreach ( $spec['required_fields'] as $field ) {
				if ( ! isset( $fields[$field] ) ||
					( $fields[$field]['required'] ?? null ) !== true ||
					! in_array( $fields[$field]['type'] ?? null, [ 'integer', 'string', 'boolean' ], true ) ) {
					throw new RuntimeException( 'REST route lacks declared required field: ' .
						$route . ' / ' . $field );
				}
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			throw new RuntimeException( 'REST route has no valid method, role callback or matching parser: ' . $route );
		}
	}
	// WordPress may validate a missing required POST field before running
	// permission callbacks and return 400. Test the *actual registered*
	// permission callbacks directly, without ever dispatching a money
	// operation or confusing invalid input with legitimate authorization.
	wp_set_current_user( 0 );
	foreach ( $manifest['rest'] as $spec ) {
		$registered = $routes[$spec['route']];
		$checked = false;
		foreach ( $registered as $entry ) {
			if ( ! is_array( $entry ) ||
				! is_callable( $entry['permission_callback'] ?? null ) ||
				! isset( $entry['methods'] ) ) continue;
			$methods = $entry['methods'];
			$allows = is_array( $methods )
				? ! empty( $methods[$spec['method']] )
				: ( is_string( $methods ) &&
					in_array( $spec['method'], explode( ',', $methods ), true ) );
			if ( ! $allows ) continue;
			$request = new WP_REST_Request(
				$spec['method'], $spec['anonymous_probe']
			);
			$permission = call_user_func(
				$entry['permission_callback'], $request
			);
			if ( ! is_wp_error( $permission ) ) {
				throw new RuntimeException(
					'Registered REST permission callback did not explicitly reject anonymous user: ' .
					$spec['route']
				);
			}
			$data = $permission->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : (int) $data;
			if ( ! in_array( $status, [ 401, 403 ], true ) ) {
				throw new RuntimeException(
					'Anonymous registered REST denial lacks 401/403 status: ' .
					$spec['route']
				);
			}
			$checked = true;
		}
		if ( ! $checked ) {
			throw new RuntimeException( 'No registered REST anonymous role test executed for: ' . $spec['route'] );
		}
	}
	// For a GET route with no required body fields, also exercise real
	// WordPress REST dispatch to prove end-to-end anonymous denial.
	$public_read = rest_do_request( new WP_REST_Request(
		'GET', '/wpessential-wbm/v1/capabilities'
	) );
	if ( ! in_array( $public_read->get_status(), [ 401, 403 ], true ) ) {
		throw new RuntimeException( 'Capabilities endpoint bypassed WordPress REST anonymous denial.' );
	}
	echo "PASS live WordPress 24 admin hooks (only 1 guarded customer guest claim), 10 exact REST methods/required fields, authenticated permission callbacks and all registered anonymous permission denials\n";
} finally {
	wp_set_current_user( $original_user );
}
