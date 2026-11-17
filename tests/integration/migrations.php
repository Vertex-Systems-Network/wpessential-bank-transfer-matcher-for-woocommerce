<?php


use WPEssential\BankTransferMatcher\Database\MigrationRunner;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'wbm_ci' !== DB_NAME ) {
	throw new RuntimeException( 'Run this test against the disposable wbm_ci WordPress database.' );
}

require dirname( __DIR__, 2 ) . '/plugins/wpessential-bank-transfer-matcher/vendor/autoload.php';

global $wpdb;
$option = 'wbm_ci_migration_version';
$table  = $wpdb->prefix . 'wbm_ci_migration';
$lock   = hash( 'sha256', DB_NAME . '/' . $wpdb->prefix . '/' . $option );
$runner = new MigrationRunner( $wpdb, $option );
$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$create = static function ( wpdb $db ) use ( $table ): bool {
	return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$table` (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB" );
};
$version = static fn () => $wpdb->get_var(
	$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
);

function expect_migration_failure( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		if ( str_contains( $error->getMessage(), $message ) ) {
			return;
		}
		throw $error;
	}
	throw new LogicException( 'Missing expected migration failure.' );
}

if ( null !== $version() ||
	'1' !== (string) $second->get_var( $second->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
	throw new RuntimeException( 'Expected an unused fixture and an available advisory lock.' );
}
try {
	expect_migration_failure( fn () => $runner->upgrade( [ $create ] ), 'acquire the schema migration lock' );
	if ( null !== $version() ) {
		throw new RuntimeException( 'A contending connection changed the checkpoint.' );
	}
} finally {
	$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
}

expect_migration_failure( fn () => $runner->upgrade( [ $create, static fn () => false ] ), 'verification failed at version 2' );
if ( '1' !== $version() ||
	$table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
	throw new RuntimeException( 'The verified first step or checkpoint was lost after a later failure.' );
}

$runner->upgrade( [ $create, static fn () => true ] );
if ( '2' !== $version() ) {
	throw new RuntimeException( 'Retry did not advance the second checkpoint.' );
}
expect_migration_failure( fn () => $runner->upgrade( [ $create ] ), 'newer than the installed plugin' );

echo "PASS live MariaDB advisory locking, schema creation, checkpoint retry and downgrade guard\n";
