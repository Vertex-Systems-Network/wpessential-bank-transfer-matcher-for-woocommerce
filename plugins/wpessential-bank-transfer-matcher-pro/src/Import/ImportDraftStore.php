<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;
use RuntimeException;

/** Short-lived private statement drafts used between dry-run preview and commit. */
final class ImportDraftStore {
	private const TTL = 1800;
	private const PREFIX = 'wpessential_wbm_pro_import_draft_';

	/** @return array{token:string,path:string,sha256:string,size:int} */
	public function create( string $source, int $actor_id ): array {
		if ( $actor_id < 1 || ! is_uploaded_file( $source ) ) {
			throw new InvalidArgumentException( 'A valid uploaded statement file is required.' );
		}
		$size = filesize( $source );
		if ( false === $size || $size < 1 || $size > 20 * 1024 * 1024 ) {
			throw new InvalidArgumentException( 'Statement files must be between 1 byte and 20 MB.' );
		}
		$hash = hash_file( 'sha256', $source );
		if ( false === $hash ) throw new RuntimeException( 'Could not hash the statement file.' );
		$directory = $this->private_directory();
		$path = $directory . '/' . bin2hex( random_bytes( 24 ) ) . '.csv';
		if ( ! move_uploaded_file( $source, $path ) ) throw new RuntimeException( 'Could not stage the statement preview.' );
		chmod( $path, 0600 );
		if ( $size !== filesize( $path ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ?: '' ) ) {
			@unlink( $path );
			throw new RuntimeException( 'The staged statement changed during upload.' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$record = array( 'path' => $path, 'sha256' => $hash, 'size' => $size, 'actor_id' => $actor_id );
		if ( ! set_transient( self::PREFIX . hash( 'sha256', $token ), $record, self::TTL ) ) {
			@unlink( $path );
			throw new RuntimeException( 'Could not save the statement preview.' );
		}
		return array( 'token' => $token, 'path' => $path, 'sha256' => $hash, 'size' => $size );
	}

	/** @return array{path:string,sha256:string,size:int,actor_id:int} */
	public function get( string $token, int $actor_id ): array {
		if ( 64 !== strlen( $token ) || 1 !== preg_match( '/\A[a-f0-9]+\z/', $token ) || $actor_id < 1 ) {
			throw new InvalidArgumentException( 'The statement preview token is invalid.' );
		}
		$key = self::PREFIX . hash( 'sha256', $token );
		$record = get_transient( $key );
		if ( ! is_array( $record ) || (int) ( $record['actor_id'] ?? 0 ) !== $actor_id ) {
			throw new InvalidArgumentException( 'The statement preview expired or is unavailable.' );
		}
		$path = $record['path'] ?? '';
		$hash = $record['sha256'] ?? '';
		$size = (int) ( $record['size'] ?? 0 );
		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) || ! is_string( $hash ) ||
			! hash_equals( $hash, hash_file( 'sha256', $path ) ?: '' ) || $size !== filesize( $path ) ) {
			$this->discard( $token, $actor_id );
			throw new RuntimeException( 'The staged statement is missing or changed.' );
		}
		return array( 'path' => $path, 'sha256' => $hash, 'size' => $size, 'actor_id' => $actor_id );
	}

	public function discard( string $token, int $actor_id ): void {
		if ( 64 !== strlen( $token ) || 1 !== preg_match( '/\A[a-f0-9]+\z/', $token ) ) return;
		$key = self::PREFIX . hash( 'sha256', $token );
		$record = get_transient( $key );
		if ( is_array( $record ) && (int) ( $record['actor_id'] ?? 0 ) === $actor_id ) {
			$path = $record['path'] ?? '';
			if ( is_string( $path ) && is_file( $path ) ) @unlink( $path );
			delete_transient( $key );
		}
	}

	private function private_directory(): string {
		$root = defined( 'WBM_PRIVATE_IMPORT_DIR' ) ? WBM_PRIVATE_IMPORT_DIR : dirname( rtrim( ABSPATH, '/\\' ) ) . '/wbm-private-imports';
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) throw new RuntimeException( 'Private import directory is unavailable.' );
		$real = realpath( $root );
		$web = realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH );
		$wp = realpath( ABSPATH );
		if ( false === $real || ( false !== $web && str_starts_with( $real . '/', rtrim( $web, '/' ) . '/' ) ) ||
			( false !== $wp && str_starts_with( $real . '/', rtrim( $wp, '/' ) . '/' ) ) ) {
			throw new RuntimeException( 'Private import directory must be outside the web root.' );
		}
		chmod( $real, 0700 );
		return $real;
	}
}
