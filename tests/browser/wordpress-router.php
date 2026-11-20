<?php
/**
 * CI-only PHP built-in webserver router. Serve static files and real
 * wp-admin scripts directly, let WordPress resolve pretty permalinks.
 */
if ( PHP_SAPI !== 'cli-server' ||
	getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {
	http_response_code( 403 );
	exit( 'Browser fixture web router is CI-only.' );
}
$root = $_SERVER['DOCUMENT_ROOT'];
$relative = rawurldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ?? '/' );
$file = realpath( $root . $relative );
$docroot = realpath( $root );
if ( false !== $file && false !== $docroot &&
	str_starts_with( $file, rtrim( $docroot, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) &&
	is_file( $file ) ) {
	return false;
}
require $root . '/index.php';
