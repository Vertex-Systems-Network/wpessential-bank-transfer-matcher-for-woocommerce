<?php
$base = dirname( __DIR__ ) . '/docs/operations/';
$required = [
	'PRO-FINANCE-ADMIN-GUIDE.md' => [
		'WooCommerce', 'BACS', 'USD 125.00', 'Partial', 'Bank data retention',
		'2-hour', '500 rows', '1,000 records', 'finance notifications',
		'Failed', 'backup', 'HPOS',
	],
	'PRO-INCIDENT-RUNBOOKS.md' => [
		'failed', 'SHA256', 'Requeue own failed export', 'manual', 'retention',
		'Action Scheduler', 'restore', 'P6 safety release gate',
	],
	'PRO-P6-ACCEPTANCE.md' => [
		'Source provenance', 'Private CSV', 'Notifications', 'Retention',
		'Admin and docs', 'Merge boundary',
	],
];
foreach ( $required as $file => $phrases ) {
	$path = $base . $file;
	$body = is_file( $path ) ? file_get_contents( $path ) : false;
	if ( ! is_string( $body ) || strlen( $body ) < 1500 ) {
		throw new RuntimeException( 'Missing or unhelpfully short Pro finance runbook: ' . $file );
	}
	foreach ( $phrases as $phrase ) {
		if ( stripos( $body, $phrase ) === false ) {
			throw new RuntimeException( 'Finance operations guide omits required safety topic: ' . $file . '/' . $phrase );
		}
	}
}
echo "PASS full Pro finance admin examples, incident/restore runbooks and P6 phase acceptance checklist are present\n";
