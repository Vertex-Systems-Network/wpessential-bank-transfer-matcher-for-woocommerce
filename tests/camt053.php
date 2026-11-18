<?php

use WPEssential\BankTransferMatcherPro\Import\Camt053Parser;

require dirname( __DIR__ ) . '/plugins/wpessential-bank-transfer-matcher-pro/vendor/autoload.php';

$parser = new Camt053Parser();
$fixture = static function ( string $version, string $entries, string $account = 'GB00TEST00001234', string $currency = 'USD' ): string {
	return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.' . $version . '"><BkToCstmrStmt><Stmt><Acct><Id><IBAN>' . $account . '</IBAN></Id><Ccy>' . $currency . '</Ccy></Acct>' . $entries . '</Stmt></BkToCstmrStmt></Document>';
};
$credit = '<Ntry><Amt Ccy="USD">125.50</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-11-18</Dt></BookgDt><NtryDtls><TxDtls><Refs><AcctSvcrRef>000123</AcctSvcrRef><EndToEndId>ORDER-5</EndToEndId></Refs></TxDtls></NtryDtls><AddtlNtryInf>Incoming</AddtlNtryInf></Ntry>';
$debit = '<Ntry><Amt Ccy="USD">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-11-19</Dt></BookgDt><NtryDtls><TxDtls><Refs><AcctSvcrRef>NOTPROVIDED</AcctSvcrRef></Refs></TxDtls></NtryDtls></Ntry>';
foreach ( [ '02', '08' ] as $version ) {
	$rows = $parser->parse( $fixture( $version, $credit . $debit ), 'USD', '1234', 2 );
	if ( 2 !== count( $rows ) || 12550 !== $rows[0]->amount_minor || '000123' !== $rows[0]->transaction_id || 'ORDER-5' !== $rows[0]->reference || -500 !== $rows[1]->amount_minor || '' !== $rows[1]->transaction_id ) throw new RuntimeException( 'CAMT.053 canonical data changed.' );
}
$reject = static function ( string $xml, string $reason ) use ( $parser ): void {
	try { $parser->parse( $xml, 'USD', '1234', 2 ); }
	catch ( InvalidArgumentException $expected ) { return; }
	throw new RuntimeException( $reason );
};
$reject( $fixture( '04', $credit ), 'Unsupported namespace accepted.' );
$reject( $fixture( '08', $credit, 'GB00TEST00009999' ), 'Wrong bank account accepted.' );
$reject( $fixture( '08', $credit, 'GB00TEST00001234', 'EUR' ), 'Wrong currency accepted.' );
$reject( $fixture( '08', str_replace('125.50', '125.501', $credit) ), 'Excess precision accepted.' );
$reject( $fixture( '08', str_replace('2026-11-18', '2026-02-30', $credit) ), 'Impossible booking date accepted.' );
$reject( $fixture( '08', str_replace('<CdtDbtInd>CRDT</CdtDbtInd>', '<CdtDbtInd>UNKNOWN</CdtDbtInd>', $credit) ), 'Unknown direction accepted.' );
$reject( $fixture( '08', str_replace('</NtryDtls>', '<TxDtls><Refs><AcctSvcrRef>other</AcctSvcrRef></Refs></TxDtls></NtryDtls>', $credit) ), 'Aggregated entry accepted.' );
$reject( $fixture( '08', $credit ) . $fixture( '08', $debit ), 'Multiple documents accepted.' );
$reject( '<!DOCTYPE Document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . $fixture( '08', $credit ), 'External entity accepted.' );
$reject( $fixture( '08', $credit . $credit ) . str_repeat( ' ', 21 * 1024 * 1024 ), 'Oversized XML accepted.' );
echo "PASS CAMT.053 .02/.08 strict account, currency, amount, entry and XML safety cases\n";
