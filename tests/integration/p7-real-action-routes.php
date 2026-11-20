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
	// Denial-only anonymous dispatch, with nonexistent synthetic IDs. If a
	// route accidentally becomes public, reject before any apparent success.
	// NO authorized money mutation is dispatched by this audit.
	wp_set_current_user( 0 );
	foreach ( $manifest['rest'] as $spec ) {
		$request = new WP_REST_Request( $spec['method'], $spec['anonymous_probe'] );
		$result = rest_do_request( $request );
		$status = $result->get_status();
		if ( ! in_array( $status, [ 401, 403 ], true ) ) {
			throw new RuntimeException( 'REST operation is public or lacks permission enforcement: ' .
				$spec['route'] . ' (HTTP ' . $status . ')' );
		}
	}
	echo "PASS live WordPress 24 admin hooks (only 1 guarded customer guest claim), 10 exact REST methods/required fields, authenticated permission callbacks and all anonymous REST denials\n";
} finally {
	wp_set_current_user( $original_user );
}
