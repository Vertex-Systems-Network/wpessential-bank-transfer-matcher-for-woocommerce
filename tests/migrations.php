<?php

declare(strict_types=1);

use WPEssential\BankTransferMatcher\Database\MigrationRunner;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher/vendor/autoload.php';

define( 'DB_NAME', 'test' );

final class wpdb {
	public string $prefix = 'wp_2_';
	public string $options = 'wp_2_options';
	public string $last_error = '';
	public ?string $version = null;
	public bool $busy = false;
	public bool $fail_write = false;
	public array $events = [];

	public function prepare( string $sql, string ...$args ): array {
		return [ $sql, $args ];
	}

	public function get_var( array $prepared ): ?string {
		$sql = $prepared[0];
		$this->events[] = $sql;
		return match ( true ) {
			str_contains( $sql, 'GET_LOCK' ) => $this->busy ? '0' : '1',
			str_contains( $sql, 'RELEASE_LOCK' ) => '1',
			str_contains( $sql, 'FROM wp_2_options' ) => $this->version,
			default => throw new LogicException( 'Unknown query.' ),
		};
	}

	public function query( array $prepared ): int|false {
		if ( $this->fail_write ) {
			return false;
		}
		$this->version = $prepared[1][1];
		return 1;
	}
}

function expect_failure( callable $run, string $expected ): void {
	try {
		$run();
	} catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), $expected ) ) {
			throw $error;
		}
		return;
	}

	throw new LogicException( 'Expected failure: ' . $expected );
}

$db = new wpdb();
$runner = new MigrationRunner( $db, 'wbm_schema' );
$calls = 0;
$first = static function () use ( &$calls ): bool {
	++$calls;
	return true;
};

expect_failure( fn () => $runner->upgrade( [ $first, fn () => false ] ), 'verification failed' );
if ( '1' !== $db->version || ! str_contains( end( $db->events ), 'RELEASE_LOCK' ) ) {
	throw new LogicException( 'Failed migration advanced the version or retained its lock.' );
}

$db->fail_write = true;
expect_failure( fn () => $runner->upgrade( [ $first, fn () => true ] ), 'record the completed' );
if ( '1' !== $db->version ) {
	throw new LogicException( 'Failed checkpoint advanced the version.' );
}

$db->fail_write = false;
$runner->upgrade( [ $first, fn () => true ] );
$runner->upgrade( [ $first, static function () { throw new LogicException( 'Replayed completed step.' ); } ] );
if ( '2' !== $db->version || 1 !== $calls ) {
	throw new LogicException( 'Upgrade failed to resume from its checkpoint.' );
}

expect_failure( fn () => $runner->upgrade( [ $first ] ), 'newer' );
$db->busy = true;
expect_failure( fn () => $runner->upgrade( [ $first, fn () => true ] ), 'migration lock' );
if ( ! str_contains( end( $db->events ), 'GET_LOCK' ) ) {
	throw new LogicException( 'Contended upgrade proceeded past lock acquisition.' );
}

echo "PASS migration ordering, failure, retry, downgrade and lock contention control flow\n";
