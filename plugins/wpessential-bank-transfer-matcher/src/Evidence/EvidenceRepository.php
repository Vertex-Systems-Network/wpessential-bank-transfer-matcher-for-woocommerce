<?php

namespace WPEssential\BankTransferMatcher\Evidence;

use RuntimeException;
use WPEssential\BankTransferMatcher\Database\MigrationRunner;
use WPEssential\BankTransferMatcher\Domain\Money;
use wpdb;

defined( 'ABSPATH' ) || exit;

final class EvidenceRepository {
	private const VERSION = 'wpessential_wbm_evidence_schema';

	public function __construct( private readonly wpdb $db ) {}

	/** @return array{id: int, duplicate_warning: bool, order_payment_changed: false} */
	public function record( int $order_id, int $actor_id, string $date, Money $money, string $reference, string $note ): array {
		( new MigrationRunner( $this->db, self::VERSION ) )->upgrade( [ self::create_table(...) ] );
		$table = $this->db->prefix . 'wbm_bank_evidence';
		// A canonical exact tuple is a warning across orders, not an automatic match.
		$canonical = strtoupper( preg_replace( '/\s+/u', '', $reference ) ?? $reference );
		$fingerprint = hash( 'sha256', implode( '|', [ $date, $money->currency, $money->exponent, $money->minor_units, $canonical ] ) );
		$written = $this->db->query( $this->db->prepare(
			"INSERT INTO `$table` (order_id, actor_id, transfer_date, amount_minor, currency, exponent, reference, fingerprint, note, created_at)
			VALUES (%d, %d, %s, %d, %s, %d, %s, %s, %s, UTC_TIMESTAMP())",
			$order_id, $actor_id, $date, $money->minor_units, $money->currency, $money->exponent, $reference, $fingerprint, $note
		) );
		if ( false === $written || '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not save bank evidence.' );
		}
		$id = (int) $this->db->insert_id;
		$found = $this->db->get_var( $this->db->prepare( "SELECT id FROM `$table` WHERE fingerprint = %s AND id <> %d LIMIT 1", $fingerprint, $id ) );
		if ( '' !== $this->db->last_error ) {
			throw new RuntimeException( 'Could not inspect duplicate bank evidence.' );
		}
		return [ 'id' => $id, 'duplicate_warning' => null !== $found, 'order_payment_changed' => false ];
	}

	private static function create_table( wpdb $db ): bool {
		$table = $db->prefix . 'wbm_bank_evidence';
		$collation = $db->get_charset_collate();
		return false !== $db->query( "CREATE TABLE IF NOT EXISTS `$table` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
			transfer_date DATE NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL,
			currency CHAR(3) NOT NULL, exponent TINYINT UNSIGNED NOT NULL,
			reference VARCHAR(100) NOT NULL, fingerprint CHAR(64) NOT NULL,
			note TEXT NOT NULL, created_at DATETIME NOT NULL,
			KEY order_id (order_id), KEY fingerprint (fingerprint)
		) ENGINE=InnoDB $collation" );
	}
}
