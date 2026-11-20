<?php
/**
 * P8-T007 synthetic 1k/10k import throughput + 50k fail-closed row limit.
 * Real disposable WordPress/WooCommerce/MariaDB only. No actual funds.
 */
use WPEssential\BankTransferMatcherPro\Import\BankAccountRepository;
use WPEssential\BankTransferMatcherPro\Import\ImportJob;
use WPEssential\BankTransferMatcherPro\Import\CsvParser;
use WPEssential\BankTransferMatcherPro\Import\MappingProfile;
if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
     getenv('GITHUB_ACTIONS') !== 'true' || ! class_exists('WooCommerce') ) {
    throw new RuntimeException('Performance tests only on disposable CI WooCommerce/MariaDB.');
}
$path = getenv('P8_PERF_REPORT_FILE');
if ( ! is_string($path) || ! str_starts_with($path, sys_get_temp_dir() . '/') ) {
    throw new RuntimeException('Performance report path must be runner temp.');
}
global $wpdb;
$actor = get_user_by('login', 'wbm_ci');
if (!$actor) throw new RuntimeException('No synthetic admin actor.');
$original_actor = get_current_user_id();
wp_set_current_user((int)$actor->ID);
$report = [
    'schema_version'=>1, 'synthetic'=>true,
    'code_sha'=>(string)(getenv('GITHUB_SHA') ?: ''),
    'wordpress'=>get_bloginfo('version'), 'woocommerce'=>WC_VERSION,
    'php'=>PHP_VERSION, 'max_supported_csv_rows'=>CsvParser::MAX_ROWS,
    'samples'=>[],
];
$account=0; $batches=[]; $files=[];
$profile=MappingProfile::from_array([
    'id'=>'p8-perf','name'=>'P8 synthetic performance','delimiter'=>',',
    'date_header'=>'Date','date_format'=>'Y-m-d','amount_header'=>'Amount',
    'decimal_separator'=>'.','thousands_separator'=>'','exponent'=>2,
    'currency_header'=>'','fixed_currency'=>'USD','reference_header'=>'Reference',
    'description_header'=>'','transaction_id_header'=>'Bank ID',
]);
$make_file=static function(int $size) use (&$files): string {
    $path=tempnam(sys_get_temp_dir(), 'wbm-p8-perf-');
    if (!is_string($path)) throw new RuntimeException('No temp CSV path.');
    $files[]=$path;
    $f=fopen($path,'wb');
    if (!$f) throw new RuntimeException('Synthetic CSV file unavailable.');
    try {
        fputcsv($f,['Date','Amount','Reference','Bank ID'],',','"','');
        for($i=1;$i<=$size;++$i) {
            fputcsv($f,[
                gmdate('Y-m-d'),'1.00',
                "P8PERF-$size-$i", sprintf('P8%05d-%06d',$size,$i),
            ],',','"','');
        }
    } finally { fclose($f); }
    return $path;
};
$cleanup=static function(int $id) use($wpdb):void {
    $jobs=$wpdb->prefix.'wbm_pro_import_jobs';
    $source=$wpdb->get_var($wpdb->prepare(
        "SELECT source_path FROM $jobs WHERE batch_id=%d",$id
    ));
    if (is_string($source)&&is_file($source)) @unlink($source);
    foreach(['wbm_pro_import_rows','wbm_pro_import_jobs'] as $suffix) {
        $wpdb->delete($wpdb->prefix.$suffix,['batch_id'=>$id],['%d']);
    }
    // Import batch table's primary key is id, not batch_id.
    $wpdb->delete($wpdb->prefix.'wbm_pro_import_batches',['id'=>$id],['%d']);
};
$ms=static fn():float => hrtime(true)/1000000;
$job=new ImportJob($wpdb);
try {
    $account=(new BankAccountRepository($wpdb))->create(
        'P8 throughput fixture','Synthetic Bank','USD','5001',(int)$actor->ID
    );
    foreach([1000,10000] as $count) {
        $source=$make_file($count);
        if(function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $t0=$ms();
        $batch=$job->start($source,$account,$profile,(int)$actor->ID);
        $batches[]=$batch;
        $t1=$ms();
        $chunks=0;
        do {
            $job->process($batch);
            ++$chunks;
            $status=(string)$wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}wbm_pro_import_batches WHERE id=%d",$batch
            ));
            if (!in_array($status,['pending','processing','completed'],true) ||
                $chunks > (int)ceil($count/100)+3) {
                throw new RuntimeException("Synthetic $count-row import stalled or failed.");
            }
        } while ($status!=='completed');
        $t2=$ms();
        $data=$wpdb->get_row($wpdb->prepare(
            "SELECT valid_count,error_count,warning_count FROM {$wpdb->prefix}wbm_pro_import_batches WHERE id=%d",$batch
        ),ARRAY_A);
        $stored=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wbm_pro_import_rows WHERE batch_id=%d",$batch
        ));
        if(!is_array($data) || (int)$data['valid_count']!==$count ||
            (int)$data['error_count']!==0 || (int)$data['warning_count']!==0 ||
            $stored!==$count) {
            throw new RuntimeException("P8 $count-row source lost rows or introduced warnings.");
        }
        $report['samples'][]=[
            'rows'=>$count,'outcome'=>'completed','csv_bytes'=>filesize($source),
            'staged_rows'=>$stored,'chunks'=>$chunks,
            'start_ms'=>round($t1-$t0,2),'process_ms'=>round($t2-$t1,2),
            'total_ms'=>round($t2-$t0,2),
            'php_peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),
        ];
        $cleanup($batch);
        $batches=array_values(array_diff($batches,[$batch]));
    }
    $count=50000;$source=$make_file($count);
    // A rejected file must not even create an import batch or any staged row.
    $rows_table=$wpdb->prefix.'wbm_pro_import_rows';
    $batches_table=$wpdb->prefix.'wbm_pro_import_batches';
    $jobs_table=$wpdb->prefix.'wbm_pro_import_jobs';
    $before_counts=[
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $rows_table"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $batches_table"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table"),
    ];
    if(function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $t0=$ms();$unexpected=0;$reject='';
    try {
        $unexpected=$job->start($source,$account,$profile,(int)$actor->ID);
    } catch(InvalidArgumentException $expected) {
        $reject=$expected->getMessage();
    }
    $t1=$ms();
    if($unexpected>0) {
        $batches[]=$unexpected;
        $job->cancel($unexpected);
        throw new RuntimeException('A 50k-row CSV was enqueued although the cap is 10k.');
    }
    if($reject==='') throw new RuntimeException('A 50k-row file was not rejected with a validation error.');
    $after_counts=[
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $rows_table"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $batches_table"),
        (int)$wpdb->get_var("SELECT COUNT(*) FROM $jobs_table"),
    ];
    if($before_counts!==$after_counts) {
        throw new RuntimeException('Rejected 50k CSV altered import batches, jobs, or staged row counts.');
    }
    $report['samples'][]=[
        'rows'=>50000,'outcome'=>'rejected_before_staging',
        'csv_bytes'=>filesize($source),'staged_rows'=>0,
        'total_ms'=>round($t1-$t0,2),
        'php_peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),
        'reason'=>'10k_row_cap',
    ];
    echo "PASS P8-T007 real 1k/10k synthetic import and 50k early rejection; time, memory, bytes, rows in JSON\n";
} finally {
    foreach($batches as $id)if($id>0)$cleanup((int)$id);
    if($account>0)$wpdb->delete($wpdb->prefix.'wbm_pro_bank_accounts',['id'=>$account],['%d']);
    foreach($files as $f)if(is_file($f))@unlink($f);
    wp_set_current_user($original_actor);
    file_put_contents($path,wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
