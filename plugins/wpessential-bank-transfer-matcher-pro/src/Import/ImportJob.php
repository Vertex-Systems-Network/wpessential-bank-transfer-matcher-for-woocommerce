<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;
use WPEssential\BankTransferMatcherPro\Access\Capabilities;
use wpdb;

/** Bounded, resumable CSV staging. Staged rows are unverified and never update orders. */
final class ImportJob {
	public const HOOK = 'wpessential_wbm_pro_import_chunk';
	private const CHUNK = 100;
	private const VERSION = 'wpessential_wbm_pro_import_job_schema';

	public function __construct( private readonly wpdb $db ) {}

	public static function register(): void {
		add_action( self::HOOK, static function ( int $batch_id ): void {
			global $wpdb;
			( new self( $wpdb ) )->process( $batch_id );
		}, 10, 1 );
	}

	/** Stage only from an authorized import action, with an already validated profile. */
	public function start( string $source, int $account_id, MappingProfile $profile, int $actor_id ): int {
		if ( ! current_user_can( Capabilities::IMPORT ) || get_current_user_id() !== $actor_id ) throw new RuntimeException( 'Import permission is required.' );
		if ( ! function_exists( 'as_enqueue_async_action' ) ) throw new RuntimeException( 'Action Scheduler is unavailable.' );
		$size = is_file( $source ) ? filesize( $source ) : false;
		if ( false === $size || $size < 1 || $size > 20 * 1024 * 1024 || ! is_readable( $source ) ) throw new InvalidArgumentException( 'Invalid statement file.' );
		$this->ensure_schema();
		$hash = hash_file( 'sha256', $source );
		if ( false === $hash ) throw new RuntimeException( 'Could not hash statement file.' );
		$dir = $this->private_directory();
		$destination = $dir . '/' . bin2hex( random_bytes( 24 ) ) . '.csv';
		if ( ! copy( $source, $destination ) ) throw new RuntimeException( 'Could not stage statement file.' );
		chmod( $destination, 0600 );
		try {
			if ( $size !== filesize( $destination ) || ! hash_equals( $hash, hash_file( 'sha256', $destination ) ?: '' ) ) throw new RuntimeException( 'Staged file changed during copy.' );
			$batch = ( new ImportBatchRepository( $this->db ) )->create( $account_id, $profile, $hash, $size, $actor_id );
			$result = $this->db->query( $this->db->prepare( "INSERT INTO `{$this->jobs_table()}` (batch_id, source_path, byte_offset, next_row, updated_at) VALUES (%d, %s, 0, 1, UTC_TIMESTAMP())", $batch, $destination ) );
			if ( 1 !== $result ) throw new RuntimeException( 'Could not create import checkpoint.' );
			$first_action = as_enqueue_async_action(
				self::HOOK, array( $batch ), 'wpessential-wbm-import', true
			);
			if ( ! is_int( $first_action ) || $first_action < 1 ) {
				throw new RuntimeException( 'Could not queue the private statement import.' );
			}
			return $batch;
		} catch ( \Throwable $error ) {
			if ( isset( $batch ) ) ( new ImportBatchRepository( $this->db ) )->transition( $batch, 'pending', 'failed' );
			unlink( $destination );
			throw $error;
		}
	}

	/** One database transaction per chunk holds the checkpoint and rows together. */
	public function process( int $batch_id ): void {
		if ( $batch_id < 1 ) throw new InvalidArgumentException( 'Invalid import batch.' );
		$this->ensure_schema();
		if ( false === $this->db->query( 'START TRANSACTION' ) ) throw new RuntimeException( 'Transactional import storage is required.' );
		$committed = false;
		try {
			$job = $this->db->get_row( $this->db->prepare( "SELECT j.*, b.status, b.file_sha256, b.profile_snapshot, b.account_id FROM `{$this->jobs_table()}` j JOIN `{$this->db->prefix}wbm_pro_import_batches` b ON b.id = j.batch_id WHERE j.batch_id = %d FOR UPDATE", $batch_id ), ARRAY_A );
			if ( ! is_array( $job ) ) throw new RuntimeException( 'Import checkpoint is missing.' );
			if ( 'completed' === $job['status'] || 'cancelled' === $job['status'] ) { $this->db->query( 'COMMIT' ); return; }
			if ( ! in_array( $job['status'], array( 'pending', 'processing' ), true ) ) throw new RuntimeException( 'Import requires explicit retry.' );
			$path = $job['source_path'];
			if ( ! is_file( $path ) || ! hash_equals( $job['file_sha256'], hash_file( 'sha256', $path ) ?: '' ) ) throw new RuntimeException( 'Staged statement is missing or changed.' );
			$profile_data = json_decode( $job['profile_snapshot'], true );
			if ( ! is_array( $profile_data ) ) throw new RuntimeException( 'Mapping snapshot is invalid.' );
			$profile = MappingProfile::from_array( $profile_data );
			$stream = fopen( $path, 'rb' );
			if ( false === $stream ) throw new RuntimeException( 'Could not open staged statement.' );
			try {
				$headers = fgetcsv( $stream, null, $profile->delimiter, '"', '' );
				if ( false === $headers || count( $headers ) > CsvParser::MAX_COLUMNS ) throw new InvalidArgumentException( 'CSV headers are invalid.' );
				$headers = array_map( static fn ( $value ): string => trim( (string) $value ), $headers );
				$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
				if ( in_array( '', $headers, true ) || count( array_unique( $headers ) ) !== count( $headers ) ) throw new InvalidArgumentException( 'CSV headers must be unique.' );
				if ( (int) $job['byte_offset'] > 0 && -1 === fseek( $stream, (int) $job['byte_offset'] ) ) throw new RuntimeException( 'Could not resume statement.' );
				$valid = (int) $job['valid_count']; $errors = (int) $job['error_count']; $warnings = (int) $job['warning_count']; $row_number = (int) $job['next_row']; $read = 0;
				while ( $read < self::CHUNK && false !== ( $values = fgetcsv( $stream, null, $profile->delimiter, '"', '' ) ) ) {
					++$row_number;
					if ( array( null ) === $values || ( 1 === count( $values ) && '' === $values[0] ) ) continue;
					if ( $row_number > CsvParser::MAX_ROWS + 1 ) throw new InvalidArgumentException( 'CSV row limit exceeded.' );
					++$read;
					try {
						if ( count( $values ) !== count( $headers ) ) throw new InvalidArgumentException( 'Column count differs from header.' );
						$fields = array_combine( $headers, $values );
						foreach ( $fields as $value ) if ( strlen( (string) $value ) > CsvParser::MAX_CELL_BYTES || str_contains( (string) $value, "\0" ) || 1 !== preg_match( '//u', (string) $value ) ) throw new InvalidArgumentException( 'Invalid or oversized CSV cell.' );
						$canonical = $profile->map( new RawStatementRow( $row_number, $fields ) );
						$payload = wp_json_encode( get_object_vars( $canonical ) ); $error = ''; $warning = ''; ++$valid;
						$identity = ( new TransactionIdentity() )->identify( (int) $job['account_id'], $canonical );
						$fingerprint = $identity['fingerprint'];
						$seen = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM `{$this->rows_table()}` r JOIN `{$this->db->prefix}wbm_pro_import_batches` b ON b.id = r.batch_id WHERE b.account_id = %d AND r.identity_fingerprint = %s", (int) $job['account_id'], $fingerprint ) );
						if ( $seen > 0 ) { $warning = 'possible_duplicate'; ++$warnings; }
					} catch ( InvalidArgumentException $invalid ) { $payload = null; $error = $invalid->getMessage(); $warning = ''; $fingerprint = ''; ++$errors; }
					$inserted = $this->db->query( $this->db->prepare( "INSERT INTO `{$this->rows_table()}` (batch_id, source_row, canonical_json, error_code, identity_fingerprint, warning_code) VALUES (%d, %d, %s, %s, %s, %s)", $batch_id, $row_number, $payload, $error, $fingerprint, $warning ) );
					if ( 1 !== $inserted ) throw new RuntimeException( 'Could not stage statement row.' );
				}
				$offset = ftell( $stream ); $finished = feof( $stream );
			} finally { fclose( $stream ); }
			$status = $finished ? 'completed' : 'processing';
			if ( false === $this->db->query( $this->db->prepare( "UPDATE `{$this->jobs_table()}` SET byte_offset = %d, next_row = %d, valid_count = %d, error_count = %d, warning_count = %d, updated_at = UTC_TIMESTAMP() WHERE batch_id = %d", $offset, $row_number, $valid, $errors, $warnings, $batch_id ) ) ||
				1 !== $this->db->query( $this->db->prepare( "UPDATE `{$this->db->prefix}wbm_pro_import_batches` SET status = %s, valid_count = %d, error_count = %d, warning_count = %d, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status IN ('pending', 'processing')", $status, $valid, $errors, $warnings, $batch_id ) ) ) throw new RuntimeException( 'Could not checkpoint import progress.' );
			if ( false === $this->db->query( 'COMMIT' ) ) throw new RuntimeException( 'Could not commit import chunk.' );
			$committed = true;
			if ( ! $finished ) {
				// Action Scheduler may still consider the current chunk pending;
				// unique=true silently returns 0, leaving a partial import stuck.
				// Row-checkpoint transactions, unique (batch,row) and status CAS
				// make a duplicate callback safe.
				$next_action = as_enqueue_async_action(
					self::HOOK, array( $batch_id ), 'wpessential-wbm-import', false
				);
				if ( ! is_int( $next_action ) || $next_action < 1 ) {
					throw new RuntimeException( 'Could not enqueue the next import chunk; manual recovery required.' );
				}
			}
		} catch ( \Throwable $error ) {
			if ( ! $committed ) $this->db->query( 'ROLLBACK' );
			$this->db->query( $this->db->prepare( "UPDATE `{$this->db->prefix}wbm_pro_import_batches` SET status = 'failed', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status IN ('pending', 'processing')", $batch_id ) );
			throw $error;
		} finally { if ( ! $committed ) $this->db->query( 'ROLLBACK' ); }
	}

	/** Failed Action Scheduler attempts may be retried after checking the source and checkpoint. */
	public function retry( int $batch_id ): void {
		if ( ! current_user_can( Capabilities::IMPORT ) || ! function_exists( 'as_enqueue_async_action' ) ) throw new RuntimeException( 'Import retry is unavailable.' );
		$this->ensure_schema();
		$job = $this->db->get_row( $this->db->prepare( "SELECT j.source_path, b.file_sha256, b.valid_count, b.error_count, b.warning_count FROM `{$this->jobs_table()}` j JOIN `{$this->db->prefix}wbm_pro_import_batches` b ON b.id = j.batch_id WHERE j.batch_id = %d AND b.status = 'failed'", $batch_id ), ARRAY_A );
		if ( ! is_array( $job ) || ! is_file( $job['source_path'] ) || ! hash_equals( $job['file_sha256'], hash_file( 'sha256', $job['source_path'] ) ?: '' ) ) throw new RuntimeException( 'The failed import source is missing or changed.' );
		( new ImportBatchRepository( $this->db ) )->transition( $batch_id, 'failed', 'processing', (int) $job['valid_count'], (int) $job['error_count'], (int) $job['warning_count'] );
		$retry_action = as_enqueue_async_action(
			self::HOOK, array( $batch_id ), 'wpessential-wbm-import', false
		);
		if ( ! is_int( $retry_action ) || $retry_action < 1 ) {
			( new ImportBatchRepository( $this->db ) )->transition(
				$batch_id, 'processing', 'failed',
				(int) $job['valid_count'], (int) $job['error_count'],
				(int) $job['warning_count']
			);
			throw new RuntimeException( 'Could not enqueue a verified import retry.' );
		}
	}

	/** Cancel a batch that has not completed and remove its private source file. */
	public function cancel( int $batch_id ): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) throw new RuntimeException( 'Import permission is required.' );
		$this->ensure_schema();
		$row = $this->db->get_row( $this->db->prepare( "SELECT j.source_path, b.status, b.valid_count, b.error_count, b.warning_count FROM `{$this->jobs_table()}` j JOIN `{$this->db->prefix}wbm_pro_import_batches` b ON b.id = j.batch_id WHERE j.batch_id = %d", $batch_id ), ARRAY_A );
		if ( ! is_array( $row ) || ! in_array( $row['status'], array( 'pending', 'processing', 'failed' ), true ) ) throw new RuntimeException( 'This import can no longer be cancelled.' );
		( new ImportBatchRepository( $this->db ) )->transition( $batch_id, $row['status'], 'cancelled', (int) $row['valid_count'], (int) $row['error_count'], (int) $row['warning_count'] );
		if ( is_file( $row['source_path'] ) ) @unlink( $row['source_path'] );
	}

	/** @return list<array<string, mixed>> */
	public function staged_rows( int $batch_id, int $page = 1, int $limit = 50 ): array {
		if ( $batch_id < 1 || $page < 1 || ! in_array( $limit, array( 20, 50, 100 ), true ) ) throw new InvalidArgumentException( 'Invalid staged-row query.' );
		$this->ensure_schema();
		$offset = ( $page - 1 ) * $limit;
		$rows = $this->db->get_results( $this->db->prepare( "SELECT source_row, canonical_json, error_code, warning_code FROM `{$this->rows_table()}` WHERE batch_id = %d ORDER BY source_row ASC LIMIT %d OFFSET %d", $batch_id, $limit, $offset ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) throw new RuntimeException( 'Could not load imported rows.' );
		return $rows;
	}

	private function jobs_table(): string { return $this->db->prefix . 'wbm_pro_import_jobs'; }
	private function rows_table(): string { return $this->db->prefix . 'wbm_pro_import_rows'; }

	private function private_directory(): string {
		$root = defined( 'WBM_PRIVATE_IMPORT_DIR' ) ? WBM_PRIVATE_IMPORT_DIR : dirname( rtrim( ABSPATH, '/\\' ) ) . '/wbm-private-imports';
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) throw new RuntimeException( 'Private import directory is unavailable.' );
		$real = realpath( $root ); $web = realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH ); $wp = realpath( ABSPATH );
		if ( false === $real || ( false !== $web && str_starts_with( $real . '/', rtrim( $web, '/' ) . '/' ) ) || ( false !== $wp && str_starts_with( $real . '/', rtrim( $wp, '/' ) . '/' ) ) ) throw new RuntimeException( 'Private import directory must be outside the web root.' );
		chmod( $real, 0700 );
		return $real;
	}

	private function ensure_schema(): void {
		$lock = hash( 'sha256', DB_NAME . '/' . $this->db->prefix . '/' . self::VERSION );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) throw new RuntimeException( 'Could not lock import job schema.' );
		try {
			$version = get_option( self::VERSION, '0' );
			if ( ! in_array( $version, array( '0', '1', '2' ), true ) ) throw new RuntimeException( 'Unsupported import job schema.' );
			$collation = $this->db->get_charset_collate(); $jobs = $this->jobs_table(); $rows = $this->rows_table();
			if ( '0' === $version ) {
				if ( false === $this->db->query( "CREATE TABLE IF NOT EXISTS `$jobs` (batch_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, source_path VARCHAR(255) NOT NULL, byte_offset BIGINT UNSIGNED NOT NULL DEFAULT 0, next_row BIGINT UNSIGNED NOT NULL DEFAULT 1, valid_count BIGINT UNSIGNED NOT NULL DEFAULT 0, error_count BIGINT UNSIGNED NOT NULL DEFAULT 0, warning_count BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL) ENGINE=InnoDB $collation" ) ||
				false === $this->db->query( "CREATE TABLE IF NOT EXISTS `$rows` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT UNSIGNED NOT NULL, source_row BIGINT UNSIGNED NOT NULL, canonical_json TEXT NULL, error_code VARCHAR(255) NOT NULL, identity_fingerprint CHAR(64) NOT NULL DEFAULT '', warning_code VARCHAR(32) NOT NULL DEFAULT '', UNIQUE KEY batch_row (batch_id, source_row), KEY batch_id (batch_id), KEY identity_fingerprint (identity_fingerprint)) ENGINE=InnoDB $collation" ) ) throw new RuntimeException( 'Could not create import job tables: ' . $this->db->last_error );
			} elseif ( '1' === $version ) {
				if ( false === $this->db->query( "ALTER TABLE `$jobs` ADD COLUMN warning_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER error_count" ) ||
					false === $this->db->query( "ALTER TABLE `$rows` ADD COLUMN identity_fingerprint CHAR(64) NOT NULL DEFAULT '' AFTER error_code, ADD COLUMN warning_code VARCHAR(32) NOT NULL DEFAULT '' AFTER identity_fingerprint, ADD KEY identity_fingerprint (identity_fingerprint)" ) ) throw new RuntimeException( 'Could not migrate import job tables: ' . $this->db->last_error );
			}
			if ( '2' !== $version ) update_option( self::VERSION, '2', false );
		} finally { $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
}
