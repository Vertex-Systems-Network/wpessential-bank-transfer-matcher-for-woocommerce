<?php
/**
 * Ordered database schema upgrades for a single package.
 *
 * @package WPEssential\BankTransferMatcher
 */

namespace WPEssential\BankTransferMatcher\Database;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

final class MigrationRunner {
	public function __construct(
		private readonly wpdb $db,
		private readonly string $version_option
	) {
	}

	/**
	 * Run or resume ordered migrations. Each step returns true only after
	 * verifying its schema change and must tolerate re-execution.
	 *
	 * @param list<callable(wpdb): bool> $steps Schema steps starting at version 1.
	 */
	public function upgrade( array $steps ): void {
		if ( ! array_is_list( $steps ) ) {
			throw new InvalidArgumentException( 'Schema migrations must be an ordered list.' );
		}

		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . $this->version_option );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Could not acquire the schema migration lock.' );
		}

		try {
			$this->apply( $steps, $lock );
		} finally {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function apply( array $steps, string $lock ): void {
		// Read from the database inside the lock, independently of any options cache.
		$stored = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $this->version_option )
		);

		if ( '' !== $this->db->last_error || ( null !== $stored && 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', (string) $stored ) ) ) {
			throw new RuntimeException( 'Could not read a valid schema version.' );
		}

		$target = count( $steps );
		$version = (int) $stored;
		if ( $version > $target ) {
			throw new RuntimeException( 'Database schema is newer than the installed plugin.' );
		}

		for ( ; $version < $target; ++$version ) {
			if ( true !== $steps[ $version ]( $this->db ) ) {
				throw new RuntimeException( 'Schema migration verification failed at version ' . ( $version + 1 ) . '.' );
			}

			$written = $this->db->query(
				$this->db->prepare(
					"INSERT INTO {$this->db->options} (option_name, option_value, autoload)
					SELECT %s, %s, 'no' WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
					ON DUPLICATE KEY UPDATE option_value = %s",
					$this->version_option,
					(string) ( $version + 1 ),
					$lock,
					(string) ( $version + 1 )
				)
			);

			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'Could not record the completed schema version.' );
			}
		}
	}
}
