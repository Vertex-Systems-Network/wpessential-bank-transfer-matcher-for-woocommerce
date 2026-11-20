<?php
/**
 * Only an actual CI-only synthetic statement may be used to test HTTP isolation.
 * Export no private pathname, bank bytes, credentials or PII to the browser.
 */
if ( ! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wbm_ci' ||
     getenv('GITHUB_ACTIONS') !== 'true' ) {
    throw new RuntimeException('Disposable browser-only source fixture required.');
}
$fixture_path = getenv('P7_BROWSER_FIXTURE_FILE');
$raw = json_decode((string)file_get_contents($fixture_path), true, 512, JSON_THROW_ON_ERROR);
if (($raw['disposable'] ?? false) !== true || !is_int($raw['batch_id'] ?? null)) {
    throw new RuntimeException('Untrusted source fixture.');
}
global $wpdb;
$jobs = $wpdb->prefix . 'wbm_pro_import_jobs';
$path = $wpdb->get_var($wpdb->prepare(
    "SELECT source_path FROM $jobs WHERE batch_id=%d", $raw['batch_id']
));
$real = is_string($path) ? realpath($path) : false;
$webroot = realpath(ABSPATH);
if (false === $real || false === $webroot || !is_file($real) ||
    str_starts_with($real, rtrim($webroot, '/') . '/') ||
    (fileperms($real) & 0077) !== 0 ||
    !preg_match('/^[a-f0-9]{48}\.csv$/D', basename($real))) {
    throw new RuntimeException('Actual source was not private and opaque.');
}
file_put_contents(sys_get_temp_dir().'/p8-private-http.json', json_encode([
    'disposable' => true,
    'source_basename' => basename($real),
    'order_id' => $raw['order_id'],
], JSON_THROW_ON_ERROR));
echo "PASS P8-T002 source path outside webroot with restricted permissions; browser fixture contains basename only\n";
