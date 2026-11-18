<?php

namespace WPEssential\BankTransferMatcherPro\Import;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/** Conservative single-account CAMT.053 adapter; no payment approval side effects. */
final class Camt053Parser {
	private const NAMESPACES = array(
		'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02',
		'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08',
	);

	/** @return list<CanonicalStatementRow> */
	public function parse( string $xml, string $expected_currency, string $account_last4, int $exponent ): array {
		if ( '' === $xml || strlen( $xml ) > 20 * 1024 * 1024 || str_contains( $xml, "\0" ) || 1 !== preg_match( '//u', $xml ) ||
			preg_match( '/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml ) ) throw new InvalidArgumentException( 'Unsafe or oversized CAMT.053 document.' );
		if ( ! class_exists( DOMDocument::class ) ) throw new InvalidArgumentException( 'The XML extension is required.' );
		if ( 1 !== preg_match( '/\A[A-Z]{3}\z/', $expected_currency ) || 1 !== preg_match( '/\A[A-Z0-9]{2,4}\z/', $account_last4 ) || $exponent < 0 || $exponent > 6 ) throw new InvalidArgumentException( 'Invalid expected account settings.' );
		$previous = libxml_use_internal_errors( true );
		try {
			$document = new DOMDocument();
			$document->resolveExternals = false;
			$document->substituteEntities = false;
			if ( ! $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS ) || null !== $document->doctype ) throw new InvalidArgumentException( 'Invalid or unsafe CAMT.053 XML.' );
		} finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$root = $document->documentElement;
		if ( ! $root || 'Document' !== $root->localName || ! in_array( $root->namespaceURI, self::NAMESPACES, true ) ) throw new InvalidArgumentException( 'Unsupported CAMT.053 namespace.' );
		$xpath = new DOMXPath( $document ); $xpath->registerNamespace( 'c', $root->namespaceURI );
		$statements = $xpath->query( '/c:Document/c:BkToCstmrStmt/c:Stmt' );
		if ( ! $statements || 1 !== $statements->length ) throw new InvalidArgumentException( 'Exactly one CAMT.053 statement is required.' );
		$statement = $statements->item( 0 );
		$account = $this->one( $xpath, './c:Acct/c:Id/c:IBAN | ./c:Acct/c:Id/c:Othr/c:Id', $statement, 'statement account' );
		$account = strtoupper( preg_replace( '/\s+/', '', $account ) ?? $account );
		if ( ! str_ends_with( $account, $account_last4 ) ) throw new InvalidArgumentException( 'Statement account does not match the selected account.' );
		if ( $expected_currency !== $this->one( $xpath, './c:Acct/c:Ccy', $statement, 'account currency' ) ) throw new InvalidArgumentException( 'Statement currency does not match the selected account.' );
		$entries = $xpath->query( './c:Ntry', $statement );
		if ( ! $entries || $entries->length < 1 || $entries->length > CsvParser::MAX_ROWS ) throw new InvalidArgumentException( 'Invalid CAMT.053 entry count.' );
		$rows = array();
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof DOMElement ) throw new InvalidArgumentException( 'Invalid statement entry.' );
			$index = count( $rows ) + 2;
			$details = $xpath->query( './c:NtryDtls/c:TxDtls', $entry );
			if ( $details && $details->length > 1 ) throw new InvalidArgumentException( "Entry $index contains multiple transactions and needs a separate adapter." );
			$amount_nodes = $xpath->query( './c:Amt', $entry );
			if ( ! $amount_nodes || 1 !== $amount_nodes->length || ! $amount_nodes->item( 0 ) instanceof DOMElement ) throw new InvalidArgumentException( "Entry $index has no single amount." );
			$amount_node = $amount_nodes->item( 0 );
			if ( $expected_currency !== $amount_node->getAttribute( 'Ccy' ) ) throw new InvalidArgumentException( "Entry $index has a currency mismatch." );
			$minor = $this->minor_units( trim( $amount_node->textContent ), $exponent, $index );
			$direction = $this->one( $xpath, './c:CdtDbtInd', $entry, 'credit/debit direction' );
			if ( 'DBIT' === $direction ) $minor = -$minor;
			elseif ( 'CRDT' !== $direction ) throw new InvalidArgumentException( "Entry $index has an unsupported direction." );
			$date = $this->one( $xpath, './c:BookgDt/c:Dt', $entry, 'booking date' );
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) throw new InvalidArgumentException( "Entry $index has an invalid booking date." );
			$transaction_id = $this->optional( $xpath, './c:NtryDtls/c:TxDtls/c:Refs/c:AcctSvcrRef', $entry );
			if ( 'NOTPROVIDED' === strtoupper( $transaction_id ) ) $transaction_id = '';
			$reference = $this->optional( $xpath, './c:NtryDtls/c:TxDtls/c:Refs/c:EndToEndId', $entry );
			if ( 'NOTPROVIDED' === strtoupper( $reference ) ) $reference = '';
			$description = $this->optional( $xpath, './c:AddtlNtryInf', $entry );
			$rows[] = new CanonicalStatementRow( $index, $date, $minor, $expected_currency, $exponent, $reference, $description, $transaction_id );
		}
		return $rows;
	}

	private function one( DOMXPath $xpath, string $query, \DOMNode $context, string $label ): string {
		$nodes = $xpath->query( $query, $context );
		if ( ! $nodes || 1 !== $nodes->length || '' === trim( $nodes->item( 0 )->textContent ) ) throw new InvalidArgumentException( "Missing or ambiguous $label." );
		return trim( $nodes->item( 0 )->textContent );
	}

	private function optional( DOMXPath $xpath, string $query, \DOMNode $context ): string {
		$nodes = $xpath->query( $query, $context );
		if ( ! $nodes || $nodes->length > 1 ) throw new InvalidArgumentException( 'Ambiguous CAMT.053 reference.' );
		return 1 === $nodes->length ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	private function minor_units( string $amount, int $exponent, int $index ): int {
		if ( 1 !== preg_match( '/\A(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/', $amount, $parts ) || strlen( $parts[2] ?? '' ) > $exponent ) throw new InvalidArgumentException( "Entry $index has an invalid amount precision." );
		$digits = ltrim( $parts[1] . str_pad( $parts[2] ?? '', $exponent, '0' ), '0' );
		$digits = '' === $digits ? '0' : $digits;
		$max = (string) PHP_INT_MAX;
		if ( '0' === $digits || strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) > 0 ) ) throw new InvalidArgumentException( "Entry $index has an invalid amount." );
		return (int) $digits;
	}
}
