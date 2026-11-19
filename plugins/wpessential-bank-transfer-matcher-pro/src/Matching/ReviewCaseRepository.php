<?php

namespace WPEssential\BankTransferMatcherPro\Matching;

use InvalidArgumentException;
use RuntimeException;
use wpdb;

/** Persistent review workflow. Every mutation appends an immutable event. */
final class ReviewCaseRepository {
	private const VERSION = 'wpessential_wbm_pro_review_case_schema';
	private bool $ready = false;

	public function __construct( private readonly wpdb $db ) {}

	public function create( GeneratedCase $generated, int $actor_id ): int {
		if ( $actor_id < 1 ) throw new InvalidArgumentException( 'A review case actor is required.' );
		$this->ensure_schema();
		$reasons = wp_json_encode( array_map( static fn ( ExplanationCode $code ): string => $code->value, $generated->explanations ) );
		if ( ! is_string( $reasons ) ) throw new RuntimeException( 'Could not encode review reasons.' );
		$table = $this->cases_table();
		$this->begin();
		try {
			$result = $this->db->query( $this->db->prepare(
				"INSERT INTO $table (case_type, transaction_id, order_id, status, priority, assignee_id, reasons_json, version, created_by, created_at, updated_at) VALUES (%s, %d, %s, 'open', %s, NULL, %s, 1, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
				$generated->type, $generated->transaction_id, $generated->order_id, $generated->priority, $reasons, $actor_id
			) );
			if ( 1 !== $result ) throw new RuntimeException( 'Could not create review case.' );
			$id = (int) $this->db->insert_id;
			$this->event( $id, 'created', $actor_id, [ 'status' => 'open', 'priority' => $generated->priority ] );
			$this->commit();
			return $id;
		} catch ( \Throwable $error ) { $this->rollback(); throw $error; }
	}

	public function transition( int $id, string $from, string $to, int $actor_id, string $reason ): void {
		$this->assert_mutation( $id, $actor_id, $reason );
		ReviewCaseStateMachine::assert_transition( $from, $to );
		$this->ensure_schema();
		$table = $this->cases_table();
		$this->begin();
		try {
			$result = $this->db->query( $this->db->prepare( "UPDATE $table SET status = %s, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = %s", $to, $id, $from ) );
			if ( 1 !== $result ) throw new RuntimeException( 'Review case changed or was not found.' );
			$this->event( $id, 'transition', $actor_id, [ 'from' => $from, 'to' => $to, 'reason' => trim( $reason ) ] );
			$this->commit();
		} catch ( \Throwable $error ) { $this->rollback(); throw $error; }
	}

	public function assign( int $id, int $assignee_id, int $actor_id ): void {
		if ( $id < 1 || $assignee_id < 1 || $actor_id < 1 ) throw new InvalidArgumentException( 'Invalid review assignment.' );
		$this->ensure_schema();
		$table = $this->cases_table();
		$this->begin();
		try {
			$row = $this->db->get_row( $this->db->prepare( "SELECT status FROM $table WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
			if ( ! is_array( $row ) || in_array( $row['status'], [ 'resolved', 'dismissed' ], true ) ) throw new RuntimeException( 'Closed review case cannot be assigned.' );
			$status = 'open' === $row['status'] ? 'assigned' : $row['status'];
			$result = $this->db->query( $this->db->prepare( "UPDATE $table SET assignee_id = %d, status = %s, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = %s", $assignee_id, $status, $id, $row['status'] ) );
			if ( 1 !== $result ) throw new RuntimeException( 'Review assignment conflicted.' );
			$this->event( $id, 'assigned', $actor_id, [ 'assignee_id' => $assignee_id, 'status' => $status ] );
			$this->commit();
		} catch ( \Throwable $error ) { $this->rollback(); throw $error; }
	}

	public function set_priority( int $id, string $priority, int $actor_id ): void {
		if ( $id < 1 || $actor_id < 1 ) throw new InvalidArgumentException( 'Invalid priority actor.' );
		ReviewCaseStateMachine::assert_priority( $priority );
		$this->ensure_schema();
		$table = $this->cases_table();
		$result = $this->db->query( $this->db->prepare( "UPDATE $table SET priority = %s, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status NOT IN ('resolved', 'dismissed')", $priority, $id ) );
		if ( 1 !== $result ) throw new RuntimeException( 'Review priority changed or case is closed.' );
		$this->event( $id, 'priority_changed', $actor_id, [ 'priority' => $priority ] );
	}

	public function add_comment( int $id, int $actor_id, string $comment ): int {
		$comment = trim( $comment );
		if ( $id < 1 || $actor_id < 1 || '' === $comment || strlen( $comment ) > 2000 || str_contains( $comment, "\0" ) || 1 !== preg_match( '//u', $comment ) ) throw new InvalidArgumentException( 'Invalid review comment.' );
		$this->ensure_schema();
		$comments = $this->comments_table(); $cases = $this->cases_table();
		$result = $this->db->query( $this->db->prepare( "INSERT INTO $comments (case_id, actor_id, comment_text, created_at) SELECT id, %d, %s, UTC_TIMESTAMP() FROM $cases WHERE id = %d", $actor_id, $comment, $id ) );
		if ( 1 !== $result ) throw new RuntimeException( 'Could not append review comment.' );
		$comment_id = (int) $this->db->insert_id;
		$this->event( $id, 'comment_added', $actor_id, [ 'comment_id' => $comment_id ] );
		return $comment_id;
	}

	/** @return array<string, mixed> */
	public function snapshot( int $id ): array {
		if ( $id < 1 ) throw new InvalidArgumentException( 'Invalid review case.' );
		$this->ensure_schema(); $table = $this->cases_table();
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) throw new InvalidArgumentException( 'Review case was not found.' );
		return $row;
	}

	/** @return list<array<string, mixed>> */
	public function history( int $id ): array {
		if ( $id < 1 ) throw new InvalidArgumentException( 'Invalid review case.' );
		$this->ensure_schema(); $table = $this->events_table();
		$rows = $this->db->get_results( $this->db->prepare( "SELECT event_type, actor_id, event_json, created_at FROM $table WHERE case_id = %d ORDER BY id ASC", $id ), ARRAY_A );
		if ( ! is_array( $rows ) ) throw new RuntimeException( 'Could not load review history.' );
		return $rows;
	}

	private function assert_mutation( int $id, int $actor_id, string $reason ): void {
		if ( $id < 1 || $actor_id < 1 || '' === trim( $reason ) || strlen( $reason ) > 500 ) throw new InvalidArgumentException( 'Review transition requires actor and reason.' );
	}

	/** @param array<string, mixed> $payload */
	private function event( int $id, string $type, int $actor_id, array $payload ): void {
		$json = wp_json_encode( $payload ); $table = $this->events_table();
		if ( ! is_string( $json ) || 1 !== $this->db->query( $this->db->prepare( "INSERT INTO $table (case_id, event_type, actor_id, event_json, created_at) VALUES (%d, %s, %d, %s, UTC_TIMESTAMP())", $id, $type, $actor_id, $json ) ) ) throw new RuntimeException( 'Could not append review event.' );
	}

	private function begin(): void { if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Review storage requires transactions.' ); }
	private function commit(): void { if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Could not commit review change.' ); }
	private function rollback(): void { $this->db->query( 'ROLLBACK' ); }
	private function cases_table(): string { return $this->db->prefix . 'wbm_pro_review_cases'; }
	private function comments_table(): string { return $this->db->prefix . 'wbm_pro_review_comments'; }
	private function events_table(): string { return $this->db->prefix . 'wbm_pro_review_events'; }

	private function ensure_schema(): void {
		if ( $this->ready ) return;
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . self::VERSION );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) throw new RuntimeException( 'Could not lock review schema.' );
		try {
			$stored = get_option( self::VERSION, '0' );
			if ( ! in_array( $stored, [ '0', '1' ], true ) ) throw new RuntimeException( 'Unsupported review schema version.' );
			if ( '0' === $stored ) {
				$collation = $this->db->get_charset_collate(); $cases = $this->cases_table(); $comments = $this->comments_table(); $events = $this->events_table();
				if ( false === $this->db->query( "CREATE TABLE IF NOT EXISTS $cases (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, case_type VARCHAR(32) NOT NULL, transaction_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(12) NOT NULL, assignee_id BIGINT UNSIGNED NULL, reasons_json TEXT NOT NULL, version BIGINT UNSIGNED NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY queue (status, priority, id), KEY assignee_queue (assignee_id, status, id), KEY transaction_id (transaction_id)) ENGINE=InnoDB $collation" ) ||
					false === $this->db->query( "CREATE TABLE IF NOT EXISTS $comments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, comment_text TEXT NOT NULL, created_at DATETIME NOT NULL, KEY case_history (case_id, id)) ENGINE=InnoDB $collation" ) ||
					false === $this->db->query( "CREATE TABLE IF NOT EXISTS $events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, case_id BIGINT UNSIGNED NOT NULL, event_type VARCHAR(32) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, event_json TEXT NOT NULL, created_at DATETIME NOT NULL, KEY case_history (case_id, id)) ENGINE=InnoDB $collation" ) ) throw new RuntimeException( 'Could not create review case tables: ' . $this->db->last_error );
				update_option( self::VERSION, '1', false );
			}
		} finally { $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		$this->ready = true;
	}
}
