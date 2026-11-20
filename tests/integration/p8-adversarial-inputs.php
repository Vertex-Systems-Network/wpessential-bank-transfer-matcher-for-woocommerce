<?php
/**
 * P8-T003: SQL / stored XSS / CSV injection / XXE negative regression.
 * WordPress + WooCommerce + MariaDB disposable wbm_ci only.
 * No financial approval, settlement or authorized financial mutation.
 */
use WPEssential\BankTransferMatcherPro\Admin\BankAccountsPage;
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\Camt053Parser;
use WPEssential\BankTransferMatcherPro\Import\CsvParser;
use WPEssential\BankTransferMatcherPro\Reporting\ReportDefinition;
use WPEssential\BankTransferMatcherPro\Reporting\ReportCsvEncoder;
use WPEssential\BankTransferMatcher\Claims\ClaimAttachment;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
    getenv( 'GITHUB_ACTIONS' ) !== 'true' ) {
    throw new RuntimeException( 'Adversarial inputs require isolated WP/WC/MariaDB CI.' );
}
global $wpdb;
$actor = get_user_by( 'login', 'wbm_ci' );
if ( ! $actor ) throw new RuntimeException( 'No synthetic admin actor.' );
$original_user = get_current_user_id();
wp_set_current_user( $actor->ID );
$accounts = new BankAccountRepository( $wpdb );
$bank_id = 0;
$passes = 0;
try {
    // SQL injection-like text must remain a single literal value, never SQL.
    // We use the legitimate repository API; do not execute raw injected SQL.
    $sql_literal = "P8' OR 1=1 --";
    $html_literal = '<img src=x onerror=alert(1)>';
    $before = $accounts->all();
    $bank_id = $accounts->create( $sql_literal, $html_literal, 'USD', '0008', $actor->ID );
    $after = $accounts->all();
    $created = array_values( array_filter( $after, static fn (array $row): bool =>
        (int) $row['id'] === $bank_id ) );
    if ( count($created) !== 1 || $created[0]['nickname'] !== $sql_literal ||
        $created[0]['bank_name'] !== $html_literal ||
        count($after) !== count($before) + 1 ||
        $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wbm_pro_bank_accounts'" ) === null ) {
        throw new RuntimeException('Literal SQL-like bank metadata changed schema or other bank rows.');
    }
    ++$passes;

    // Test actual installed WordPress admin page HTML, not a mock escape().
    ob_start();
    BankAccountsPage::render();
    $rendered = ob_get_clean();
    if ( ! str_contains( $rendered, esc_html( $html_literal ) ) ||
        str_contains( $rendered, $html_literal ) ||
        ! str_contains( $rendered, esc_html( $sql_literal ) ) ) {
        throw new RuntimeException('Stored malicious bank metadata was not HTML-escaped.');
    }
    ++$passes;

    // Spreadsheet formula injection: any leading Unicode Z/C whitespace
    // followed by = + - @ must be escaped AFTER the authorised column
    // allowlist is applied. ReportCsvEncoder is the *real* export encoder.
    $scope = ReportDefinition::from_array([
        'report' => 'unmatched_credits',
        'from' => '2026-11-20',
        'to' => '2026-11-20',
        'limit' => 1,
    ]);
    foreach ( [
        '=HYPERLINK("https://invalid.example.test","x")',
        '+SUM(1,1)',
        '-1+1',
        '@SUM(2,2)',
        " \t=1+1",
        "\u{00A0}@now",
        "\x00=bad",
    ] as $malicious ) {
        $row = [
            'source_id' => 18, 'bank_account_id' => 12,
            'currency' => 'USD', 'exponent' => 2,
            'state' => $malicious, 'amount_minor' => 100,
            'posted_date' => '2026-11-20',
            'ambiguous_bank_source' => true,
            'pending_review_not_paid' => true,
            'unconfirmed_clearance' => true,
            'raw_bank_reference' => 'P8-SENSITIVE-NEVER-EXPORT',
        ];
        $result = [
            'definition' => $scope->canonical(),
            'fingerprint' => $scope->fingerprint(),
            'rows' => [$row],
            'payment_authorized' => false,
            'ledger_mutated' => false,
        ];
        if (str_contains($malicious, "\x00")) {
            try {
                ReportCsvEncoder::encode($scope, $result);
                throw new RuntimeException('NUL-containing CSV value was accepted.');
            } catch (InvalidArgumentException $expected) { ++$passes; }
            continue;
        }
        $csv = ReportCsvEncoder::encode($scope, $result);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv); rewind($stream);
        $columns = fgetcsv($stream, null, ',', '"', '');
        $values = fgetcsv($stream, null, ',', '"', '');
        fclose($stream);
        if (str_contains($csv, 'P8-SENSITIVE-NEVER-EXPORT') ||
            !is_array($columns) || !is_array($values)) {
            throw new RuntimeException('Export included private data or malformed CSV.');
        }
        $field = $values[array_search('state', $columns, true)] ?? null;
        if ($field !== "'" . $malicious) {
            throw new RuntimeException('Spreadsheet formula cell escaped incorrectly.');
        }
        ++$passes;
    }

    // Imported CSV data is treated only as untrusted text, never as a
    // spreadsheet to execute. Reject malformed UTF-8, NUL and oversized
    // cell input; quoted formula text remains inert raw field.
    $parser = new CsvParser();
    $raw = $parser->parse("Date,Reference\n2026-11-20,\"=1+1\"\n");
    if (count($raw) !== 1 || $raw[0]->value('Reference') !== '=1+1') {
        throw new RuntimeException('CSV import altered original source provenance.');
    }
    ++$passes;
    foreach ( [
        "Date,Reference\n2026-11-20,a\0b\n",
        "Date,Reference\n2026-11-20,\xC3\x28\n",
        "Date,Reference\n2026-11-20," . str_repeat('Z', CsvParser::MAX_CELL_BYTES + 1) . "\n",
    ] as $malformed ) {
        try {
            $parser->parse($malformed);
            throw new RuntimeException('Malformatted adversarial CSV was accepted.');
        } catch (InvalidArgumentException $expected) { ++$passes; }
    }

    // XML external entities and doctypes are forbidden regardless of
    // libxml's process-wide configuration. Never dereference these URIs.
    $entry = '<Ntry><Amt Ccy="USD">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-11-20</Dt></BookgDt></Ntry>';
    $xml_body = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt><Acct><Id><IBAN>GB0000001234</IBAN></Id><Ccy>USD</Ccy></Acct>' . $entry . '</Stmt></BkToCstmrStmt></Document>';
    $camt = new Camt053Parser();
    $positive = $camt->parse('<?xml version="1.0"?>' . $xml_body, 'USD', '1234', 2);
    if (count($positive) !== 1 || $positive[0]->amount_minor !== 100) {
        throw new RuntimeException('Safe strict CAMT.053 fixture was denied.');
    }
    ++$passes;
    foreach ([
        '<!DOCTYPE Document [<!ENTITY p8 SYSTEM "file:///tmp/not-a-real-asset">]>' . $xml_body,
        '<?xml version="1.0"?><!DoCtYpE Document [<!ENTITY p8 SYSTEM "https://invalid.example.test/xxe">]>' . $xml_body,
        '<!ENTITY p8 "internal">' . $xml_body,
    ] as $xxe ) {
        try {
            $camt->parse($xxe, 'USD', '1234', 2);
            throw new RuntimeException('Unsafe XML DTD/entity syntax was accepted.');
        } catch (InvalidArgumentException $expected) { ++$passes; }
    }

    // Receipt upload content must not be accepted based merely on the
    // claimed extension: MIME and actual PDF/image structure are checked.
    foreach ([
        ['receipt.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'],
        ['receipt.pdf', '<html>synthetic not-a-pdf</html>'],
        ['receipt.png', '<script>alert(1)</script>'],
    ] as [$filename, $bytes]) {
        try {
            ClaimAttachment::validate($filename, $bytes);
            throw new RuntimeException('Malicious-looking receipt bytes accepted.');
        } catch (InvalidArgumentException $expected) { ++$passes; }
    }
    echo "PASS P8-T003 actual WordPress/MariaDB SQL-literal, stored HTML escaping, finance CSV formula neutralization/redaction, malformed CSV, no-XXE and receipt MIME: $passes assertions; no paid-order mutation\n";
} finally {
    if ($bank_id > 0) $wpdb->delete( $wpdb->prefix . 'wbm_pro_bank_accounts', [ 'id' => $bank_id ], [ '%d' ] );
    wp_set_current_user( $original_user );
}
