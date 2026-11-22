<?php

namespace WPEssential\BankTransferMatcher\Orders;

use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\EntityId;
use WPEssential\BankTransferMatcher\Domain\PaymentReference;
use wpdb;

final class ReferenceRepository {
	private const VERSION_OPTION = 'wpessential_wbm_reference_schema';
	private bool $ready = false;

	public function __construct( private readonly wpdb $db ) {}

	/**
	 * Reserve a candidate without replacing an existing reference.
	 * A null return means the candidate belongs to a different order.
	 */
	public function reserve( EntityId $order_id, PaymentReference $candidate ): ?PaymentReference {
		$this->ensure_schema();
		$table = $this->db->prefix . 'wbm_payment_references';
		$written = $this->db->query(
			$this->db->prepare(
				"INSERT INTO `$table` (order_id, reference, created_at)
				VALUES (%d, %s, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE order_id = order_id",
				$order_id->value,
				$candidate->value
			)
		);
		if ( false === $written ) {
			throw new RuntimeException( 'Could not store the payment reference.' );
		}

		return $this->find( $order_id );
	}

	public function find( EntityId $order_id ): ?PaymentReference {
		if ( ! $this->can_read() ) {
			return null;
		}
		$table = $this->db->prefix . 'wbm_payment_references';
		$value = $this->db->get_var( $this->db->prepare( "SELECT reference FROM `$table` WHERE order_id = %d", $order_id->value ) );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not read the payment reference.' );
		}

		return null === $value ? null : new PaymentReference( (string) $value );
	}

	public function find_order_id( PaymentReference $reference ): ?EntityId {
		if ( ! $this->can_read() ) {
			return null;
		}
		$table = $this->db->prefix . 'wbm_payment_references';
		$value = $this->db->get_var( $this->db->prepare( "SELECT order_id FROM `$table` WHERE reference = %s", $reference->value ) );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not look up the payment reference.' );
		}

		return null === $value ? null : new EntityId( (int) $value );
	}

	private function can_read(): bool {
		if ( ! $this->ready ) {
			$version = $this->schema_version();
			if ( null === $version ) {
				return false;
			}
			if ( '1' !== $version ) {
				throw new RuntimeException( 'Unsupported payment reference schema version.' );
			}
			$this->ready = true;
		}
		return true;
	}

	private function ensure_schema(): void {
		if ( $this->ready ) {
			return;
		}

		if ( '1' !== $this->schema_version() ) {
			( new MigrationRunner( $this->db, self::VERSION_OPTION ) )->upgrade( [ self::create_table(...) ] );
		}
		$this->ready = true;
	}

	private function schema_version(): ?string {
		$version = $this->db->get_var(
			$this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", self::VERSION_OPTION )
		);
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not read the payment reference schema version.' );
		}
		return null === $version ? null : (string) $version;
	}

	private static function create_table( wpdb $db ): bool {
		$table = $db->prefix . 'wbm_payment_references';
		$collation = $db->get_charset_collate();
		if ( false === $db->query(
			"CREATE TABLE IF NOT EXISTS `$table` (
				order_id BIGINT UNSIGNED NOT NULL,
				reference VARCHAR(64) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (order_id),
				UNIQUE KEY reference (reference)
			) ENGINE=InnoDB $collation"
		) ) {
			return false;
		}

		$unique = [];
		foreach ( $db->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ) as $index ) {
			if ( 0 === (int) $index['Non_unique'] ) {
				$unique[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
		}
		return [ 1 => 'order_id' ] === ( $unique['PRIMARY'] ?? [] ) &&
			[ 1 => 'reference' ] === ( $unique['reference'] ?? [] );
	}
}
