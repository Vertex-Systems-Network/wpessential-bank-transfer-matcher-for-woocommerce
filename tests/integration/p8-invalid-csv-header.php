<?php
/**
 * P8-T009: malicious CSV header must not create private source/batch/jobs.
 * Actual WP/WC/MariaDB, isolated GitHub Actions synthetic fixture only.
 */
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\CsvParser;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;

if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
     getenv('GITHUB_ACTIONS') !== 'true' || ! class_exists('WooCommerce') ) {
    throw new RuntimeException('CSV header rejection needs disposable CI WP/WC/MariaDB.');
}
global $wpdb;
$actor=get_user_by('login','wbm_ci');
if (!$actor) throw new RuntimeException('No CI synthetic merchant actor.');
$old=get_current_user_id();
wp_set_current_user((int)$actor->ID);
$bank=0;$created=[];$accepted=0;
$profile=MappingProfile::from_array([
    'id'=>'p8-header', 'name'=>'P8 invalid header',
    'delimiter'=>',', 'date_header'=>'Date',
    'date_format'=>'Y-m-d', 'amount_header'=>'Amount',
    'decimal_separator'=>'.', 'thousands_separator'=>'',
    'exponent'=>2, 'currency_header'=>'', 'fixed_currency'=>'USD',
    'reference_header'=>'Reference','description_header'=>'',
    'transaction_id_header'=>'Bank ID',
]);
$jobs=$wpdb->prefix.'wbm_pro_import_jobs';
$batches=$wpdb->prefix.'wbm_pro_import_batches';
$rows=$wpdb->prefix.'wbm_pro_import_rows';
$actions=$wpdb->prefix.'actionscheduler_actions';
$snapshot=static function () use($wpdb,$jobs,$batches,$rows,$actions): array {
    return [
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $batches"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $rows"),
        (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $actions WHERE hook=%s", ImportJob::HOOK
        )),
    ];
};
$cleanup=static function (int $id) use($wpdb,$jobs,$batches,$rows):void {
    $file=$wpdb->get_var($wpdb->prepare("SELECT source_path FROM $jobs WHERE batch_id=%d",$id));
    if(is_string($file)&&is_file($file))@unlink($file);
    $wpdb->delete($rows,['batch_id'=>$id],['%d']);
    $wpdb->delete($jobs,['batch_id'=>$id],['%d']);
    $wpdb->delete($batches,['id'=>$id],['%d']);
};
$parser=new CsvParser();
$import=new ImportJob($wpdb);
$goodData="2026-11-20,1.00,P8SAFE,SYNTH-BANK-01\n";
$invalid=[
    ['nul', "Da\0te,Amount,Reference,Bank ID\n".$goodData],
    ['invalid-utf8', "Da\xC3\x28te,Amount,Reference,Bank ID\n".$goodData],
    ['oversized-unmapped', "Date,Amount,Reference,Bank ID,".
        str_repeat('Z', CsvParser::MAX_CELL_BYTES+1)."\n".
        "2026-11-20,1.00,P8SAFE,SYNTH-BANK-01,1\n"],
    ['duplicate', "Date,Amount,Date,Bank ID\n".$goodData],
    ['blank', "Date,,Reference,Bank ID\n".$goodData],
    ['over-100-columns',
        implode(',',array_map(static fn(int $i):string=>"C$i",range(1,CsvParser::MAX_COLUMNS+1)))."\n".
        implode(',',array_fill(0,CsvParser::MAX_COLUMNS+1,'X'))."\n"],
];
try {
    $bank=(new BankAccountRepository($wpdb))->create(
        'P8 CSV safety', 'CI only', 'USD', '0024', (int)$actor->ID
    );
    $count=0;
    foreach($invalid as [$label,$bytes]) {
        $path=tempnam(sys_get_temp_dir(),'wbm-p8-bad-header-');
        if(!is_string($path))throw new RuntimeException('No disposable CSV fixture.');
        $created[]=$path;
        file_put_contents($path,$bytes);
        // Independent dry run and the asynchronous import must fail the
        // same malformed header before creating any bank/source row.
        try {
            $parser->parse($bytes);
            throw new RuntimeException("CSV parser accepted corrupt header: $label");
        } catch(InvalidArgumentException $expected) {}
        $before=$snapshot();
        try {
            $accepted=$import->start($path,$bank,$profile,(int)$actor->ID);
            throw new RuntimeException("CSV import staged corrupt header: $label");
        } catch(InvalidArgumentException $expected) {}
        if($before!==$snapshot()) {
            throw new RuntimeException("Header $label created source, job, batch or queued action.");
        }
        ++$count;
    }
    // Positive BOM fixture: ordinary bank CSV must still stage and parse.
    $valid="\xEF\xBB\xBFDate,Amount,Reference,Bank ID\n".
        "2026-11-20,1.00,P8GOOD-BOM,BOM-01\n";
    $parsed=$parser->parse($valid);
    if(count($parsed)!==1 || $parsed[0]->value('Date')!=='2026-11-20') {
        throw new RuntimeException('Valid UTF-8 BOM header no longer parses.');
    }
    $path=tempnam(sys_get_temp_dir(),'wbm-p8-valid-header-');
    $created[]=$path; file_put_contents($path,$valid);
    $accepted=$import->start($path,$bank,$profile,(int)$actor->ID);
    $import->process($accepted);
    $state=$wpdb->get_row($wpdb->prepare(
        "SELECT status,valid_count,error_count FROM $batches WHERE id=%d",$accepted
    ),ARRAY_A);
    $row_count=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $rows WHERE batch_id=%d",$accepted
    ));
    if(!is_array($state) || $state['status']!=='completed' ||
        (int)$state['valid_count']!==1 ||
        (int)$state['error_count']!==0 || $row_count!==1) {
        throw new RuntimeException('Valid BOM CSV was not completed without errors.');
    }
    echo "PASS P8-T009 $count corrupt CSV header formats rejected before private staging/async queue; valid BOM 1-row import completed; native MariaDB counts unchanged\n";
} finally {
    if($accepted>0) $cleanup($accepted);
    if($bank>0)$wpdb->delete($wpdb->prefix.'wbm_pro_bank_accounts',['id'=>$bank],['%d']);
    foreach($created as $file)if(is_file($file))@unlink($file);
    wp_set_current_user($old);
}
