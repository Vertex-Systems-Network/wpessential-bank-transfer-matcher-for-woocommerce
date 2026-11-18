<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use InvalidArgumentException;

/** Candidate identity; never authorizes an order payment or a row deletion. */
final class TransactionIdentity {
	/**
	 * Bank IDs are trustworthy only after the merchant confirms account-wide uniqueness.
	 * A fallback fingerprint is advisory, including when two rows are byte-for-byte equal.
	 *
	 * @return array{key:string, fingerprint:string, trusted:bool}
	 */
	public function identify( int $account_id, CanonicalStatementRow $row, bool $bank_id_unique_within_account = false ): array {
		if ( $account_id < 1 ) throw new InvalidArgumentException( 'A bank account is required for transaction identity.' );
		$bank_id = trim( $row->transaction_id );
		if ( $bank_id_unique_within_account && '' === $bank_id ) throw new InvalidArgumentException( 'A configured bank ID is missing.' );
		$account = (string) $account_id;
		$fingerprint = hash( 'sha256', implode( "\x1f", array( $account, $row->currency, (string) $row->amount_minor, (string) $row->exponent, $row->posted_date, $this->normalize( $row->reference ), $this->normalize( $row->description ) ) ) );
		$key = $bank_id_unique_within_account
			? hash( 'sha256', 'bank-id' . "\x1f" . $account . "\x1f" . $bank_id )
			: hash( 'sha256', 'candidate' . "\x1f" . $fingerprint );
		return array( 'key' => $key, 'fingerprint' => $fingerprint, 'trusted' => $bank_id_unique_within_account );
	}

	/** @return 'distinct'|'possible_duplicate'|'exact_repeat'|'id_conflict' */
	public function compare( array $first, array $second ): string {
		if ( ! isset( $first['key'], $first['fingerprint'], $first['trusted'], $second['key'], $second['fingerprint'], $second['trusted'] ) ) throw new InvalidArgumentException( 'Invalid identity evidence.' );
		if ( $first['key'] !== $second['key'] ) return 'distinct';
		if ( true === $first['trusted'] && true === $second['trusted'] ) return $first['fingerprint'] === $second['fingerprint'] ? 'exact_repeat' : 'id_conflict';
		return 'possible_duplicate';
	}

	private function normalize( string $value ): string { return strtoupper( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) ); }
}
