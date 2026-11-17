<?php

namespace WPEssential\BankTransferMatcher\Claims;

use InvalidArgumentException;

final readonly class ClaimAttachment {
	public const MAX_BYTES = 2 * 1024 * 1024;
	private const FORMATS = [
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'pdf' => 'application/pdf',
	];

	private function __construct( public string $mime, public string $bytes ) {}

	public static function enabled(): bool {
		return 'yes' === get_option( 'wpessential_wbm_claim_uploads', 'no' );
	}

	public static function from_upload( array $file ): ?self {
		if ( [] === $file || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? null ) ) {
			return null;
		}

		$path = $file['tmp_name'] ?? null;
		if ( ! self::enabled() || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) ||
			! is_string( $path ) || ! is_uploaded_file( $path ) || ! is_string( $file['name'] ?? null ) ) {
			throw new InvalidArgumentException( 'Invalid receipt upload.' );
		}
		$bytes = file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );
		if ( false === $bytes ) {
			throw new InvalidArgumentException( 'Could not read receipt upload.' );
		}

		return self::validate( $file['name'], $bytes );
	}

	public static function validate( string $filename, string $bytes ): self {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime = self::FORMATS[ $extension ] ?? null;
		if ( null === $mime || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ||
			! class_exists( \finfo::class ) ||
			$mime !== ( new \finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes ) ) {
			throw new InvalidArgumentException( 'Unsupported receipt format or size.' );
		}

		if ( 'application/pdf' === $mime ) {
			if ( ! str_starts_with( $bytes, '%PDF-' ) ) {
				throw new InvalidArgumentException( 'Invalid PDF receipt.' );
			}
		} elseif ( $mime !== ( @getimagesizefromstring( $bytes )['mime'] ?? null ) ) {
			throw new InvalidArgumentException( 'Invalid image receipt.' );
		}

		return new self( $mime, $bytes );
	}

	public static function extension( string $mime ): ?string {
		$extension = array_search( $mime, self::FORMATS, true );
		return false === $extension ? null : $extension;
	}
}
