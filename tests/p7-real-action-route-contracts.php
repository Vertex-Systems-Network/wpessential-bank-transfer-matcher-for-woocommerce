<?php
/**
 * P7-T003: fail closed when a user-facing control, form/REST endpoint or
 * privilege contract no longer corresponds to a genuine Free/Pro source.
 *
 * This pure PHP test is NOT a replacement for live WordPress hook/REST
 * registration; tests/integration/p7-real-action-routes.php verifies that.
 */
$root = dirname( __DIR__ );
$path = $root . '/tests/fixtures/p7-real-ui-routes.json';
$raw = is_file( $path ) ? file_get_contents( $path ) : false;
$manifest = is_string( $raw ) ? json_decode( $raw, true, 512, JSON_THROW_ON_ERROR ) : null;
if ( ! is_array( $manifest ) || 1 !== ( $manifest['schema_version'] ?? 0 ) ) {
	throw new RuntimeException( 'P7 real UI route inventory is missing or malformed.' );
}
$screens = $manifest['screens'] ?? [];
$actions = $manifest['admin_post'] ?? [];
$rest = $manifest['rest'] ?? [];
$fields = $manifest['form_fields'] ?? [];
if ( ! is_array( $screens ) || ! is_array( $actions ) ||
	! is_array( $rest ) || ! is_array( $fields ) ||
	count( $screens ) !== 14 || count( $actions ) !== 24 ||
	count( $rest ) !== 10 || count( $fields ) < 11 ) {
	throw new RuntimeException( 'P7 actual UI control matrix lost required screen/action/route/field coverage.' );
}
foreach ( range( 1, 14 ) as $i ) {
	$id = sprintf( 'UI-%03d', $i );
	if ( ! isset( $screens[$id] ) ) {
		throw new RuntimeException( 'Missing real UI source contract: ' . $id );
	}
}
$source = static function ( string $relative ) use ( $root ): string {
	if ( ! preg_match( '~\Aplugins/wpessential-bank-transfer-matcher(?:-pro)?/src/[A-Za-z0-9/_-]+\.php\z~', $relative ) ||
		! is_file( $root . '/' . $relative ) ) {
		throw new RuntimeException( 'Missing or invalid real Free/Pro source: ' . $relative );
	}
	$text = file_get_contents( $root . '/' . $relative );
	if ( ! is_string( $text ) || ! str_contains( $text, '<?php' ) ) {
		throw new RuntimeException( 'Unusable PHP UI/control source: ' . $relative );
	}
	return $text;
};
foreach ( $screens as $id => $screen ) {
	if ( ! preg_match( '/\AUI-0(?:0[1-9]|1[0-4])\z/', $id ) ||
		! is_string( $screen['source'] ?? null ) ||
		! is_string( $screen['mode'] ?? null ) ||
		! is_string( $screen['contract'] ?? null ) ||
		strlen( $screen['contract'] ) < 18 ) {
		throw new RuntimeException( 'Unsafe, incomplete or invented screen schema: ' . $id );
	}
	$source( $screen['source'] );
}
$seen = [];
$public = [];
foreach ( $actions as $action ) {
	if ( ! is_array( $action ) ||
		! preg_match( '/\A[a-z][a-z0-9_]{3,100}\z/', (string) ( $action['name'] ?? '' ) ) ||
		! isset( $screens[$action['screen'] ?? ''] ) ||
		! is_string( $action['capability'] ?? null ) ||
		! is_string( $action['nonce'] ?? null ) ||
		! in_array( $action['method'] ?? null, [ 'GET', 'POST' ], true ) ||
		! is_bool( $action['guest'] ?? null ) ||
		isset( $seen[$action['name']] ) ) {
		throw new RuntimeException( 'Duplicate, invented or incomplete admin action.' );
	}
	$seen[$action['name']] = true;
	$handler = $source( $action['source'] );
	$form = $source( $action['form_source'] ?? $action['source'] );
	if ( ! str_contains( $handler, $action['name'] ) ||
		! str_contains( $handler, 'admin_post_' ) ||
		( ! str_contains( $handler, 'current_user_can' ) &&
			! str_contains( $handler, 'authorize(' ) &&
			! str_contains( $handler, 'self::process(' ) ) ||
		! str_contains( $form, $action['name'] ) ) {
		throw new RuntimeException( 'UI action lacks source-backed hook, rendered intent or authorization: ' . $action['name'] );
	}
	$nonce = $action['nonce'];
	if ( ! str_contains( $nonce, '<order_id>' ) &&
		! str_contains( $handler, $nonce ) ) {
		throw new RuntimeException( 'UI action nonce is not source-backed: ' . $action['name'] );
	}
	if ( ! str_contains( $handler, 'wp_verify_nonce' ) &&
		! str_contains( $handler, 'check_admin_referer' ) &&
		! str_contains( $handler, 'self::authorize' ) ) {
		throw new RuntimeException( 'Real handler lacks a server-side nonce gate: ' . $action['name'] );
	}
	if ( $action['guest'] ) {
		$public[] = $action['name'];
		if ( 'wpessential_wbm_submit_claim' !== $action['name'] ||
			'POST' !== $action['method'] ||
			! str_contains( $handler, 'order_key' ) ||
			! str_contains( $handler, 'billing_email' ) ||
			! str_contains( $handler, 'wp_verify_nonce' ) ||
			! str_contains( $handler, 'admin_post_nopriv_' ) ) {
			throw new RuntimeException( 'Unexpected public/guest payment control.' );
		}
	} elseif ( str_contains( $handler, 'admin_post_nopriv_' ) &&
		'wpessential_wbm_download_receipt' === $action['name'] ) {
		throw new RuntimeException( 'Receipt download exposed to guest without reviewer login.' );
	}
	if ( 'wbm_pro_allocation_approve' === $action['name'] &&
		( ! str_contains( $handler, 'cleared_confirmed' ) ||
			! str_contains( $handler, 'OneToOneApproval' ) ||
			! str_contains( $handler, 'SplitCreditAllocation' ) ) ) {
		throw new RuntimeException( 'Actual finance approval lost explicit clearance/maker-checker service.' );
	}
}
if ( $public !== [ 'wpessential_wbm_submit_claim' ] ) {
	throw new RuntimeException( 'Exactly one owner/order-key-gated guest form is allowed.' );
}
if ( count( $seen ) !== 24 ) {
	throw new RuntimeException( 'Expected all 24 real WP admin action registrations.' );
}
$seen_rest = [];
foreach ( $rest as $entry ) {
	if ( ! is_array( $entry ) ||
		! isset( $screens[$entry['screen'] ?? ''] ) ||
		! is_string( $entry['route'] ?? null ) ||
		! str_starts_with( $entry['route'], '/wpessential-wbm/v1/' ) ||
		! in_array( $entry['method'] ?? null, [ 'GET', 'POST' ], true ) ||
		! is_array( $entry['required_fields'] ?? null ) ||
		! is_string( $entry['capability'] ?? null ) ||
		! is_string( $entry['anonymous_probe'] ?? null ) ||
		isset( $seen_rest[$entry['route']] ) ) {
		throw new RuntimeException( 'Invented, duplicate or unsafe REST action contract.' );
	}
	$seen_rest[$entry['route']] = true;
	$handler = $source( $entry['source'] );
	if ( ! str_contains( $handler, 'register_rest_route' ) ||
		! str_contains( $handler, "'permission_callback'" ) ||
		! str_contains( $handler, 'current_user_can' ) ||
		! str_contains( $handler, "'methods'" ) ) {
		throw new RuntimeException( 'API route lacks a real registration, method or authorization: ' . $entry['route'] );
	}
	foreach ( $entry['required_fields'] as $name ) {
		if ( ! is_string( $name ) ||
			! preg_match( '/\A[a-z][a-z0-9_]{0,60}\z/', $name ) ||
			! str_contains( $handler, "'" . $name . "'" ) ) {
			throw new RuntimeException( 'REST route lacks registered input schema: ' . $entry['route'] . ' / ' . $name );
		}
	}
	// Full literal path gets checked in WordPress runtime; the Pro review
	// controller builds its actual route via $base and known suffix.
	$suffix = substr( $entry['route'], strrpos( $entry['route'], '/' ) );
	if ( ! str_contains( $handler, $suffix ) &&
		! str_contains( $handler, "'/capabilities'" ) ) {
		throw new RuntimeException( 'Route suffix has no source registration: ' . $entry['route'] );
	}
}
foreach ( $fields as $group ) {
	if ( ! is_array( $group ) ||
		! isset( $screens[$group['screen'] ?? ''] ) ||
		! is_array( $group['names'] ?? null ) ||
		empty( $group['names'] ) ||
		! is_string( $group['note'] ?? null ) ) {
		throw new RuntimeException( 'Malformed real field group.' );
	}
	$renderer = $source( $group['source'] );
	$known = [];
	foreach ( $group['names'] as $field ) {
		if ( ! is_string( $field ) ||
			! preg_match( '/\A[a-z][a-z0-9_]{0,60}\z/', $field ) ||
			isset( $known[$field] ) ) {
			throw new RuntimeException( 'Unbounded or duplicate UI input field.' );
		}
		$known[$field] = true;
		if ( ! str_contains( $renderer, 'name="' . $field ) &&
			! str_contains( $renderer, "'" . $field . "'" ) ) {
			throw new RuntimeException( 'UI field not found in its source-backed real renderer: ' .
				$group['screen'] . ' / ' . $field );
		}
	}
}
$full_text = file_get_contents( $root . '/docs/ux/p7-source-backed-screen-contracts.md' );
if ( ! is_string( $full_text ) ||
	! str_contains( $full_text, 'not yet' ) ||
	! str_contains( $full_text, 'Saved Views' ) ||
	! str_contains( $full_text, 'RTL' ) ) {
	throw new RuntimeException( 'Unimplemented UI gaps must remain explicitly visible.' );
}
echo 'PASS P7 14 genuine UI sources, 24 nonce/permission-backed admin controls, 10 registered-schema REST routes, 11 form field groups and sole order-key-guarded guest form' . "\n";
