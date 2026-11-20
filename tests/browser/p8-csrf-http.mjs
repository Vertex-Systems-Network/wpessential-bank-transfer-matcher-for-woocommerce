/**
 * P8-T003: real WordPress HTTP CSRF-negative checks on disposable CI site.
 * ONLY intentionally invalid nonce / absent WordPress REST nonce requests;
 * never send an authenticated *authorized* financial POST.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const origin = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const password = process.env.P7_BROWSER_ADMIN_PASSWORD;
const evidence = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
const fixturePath = process.env.P7_BROWSER_FIXTURE_FILE;
assert(process.env.GITHUB_ACTIONS === 'true' && password && fixturePath,
  'CSRF probes require masked credentials and disposable CI-only fixture.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
assert(fixture.disposable === true && Number.isInteger(fixture.order_id),
  'CSRF requests may target only a synthetic, unpaid WooCommerce order.');
mkdirSync(evidence, { recursive: true });
const report = {
  version: 1, disposable: true, no_authorized_finance_post: true,
  attempts: [], issues: [],
};
const browser = await chromium.launch({
  headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  await page.goto(origin + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('wbm_ci');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(u => u.pathname.startsWith('/wp-admin/'), { timeout: 45000 });
  const bankUrl = origin + '/wp-admin/admin.php?page=wpessential-wbm-pro-bank-accounts';
  const first = await page.goto(bankUrl, { waitUntil: 'load' });
  assert.equal(first?.status(), 200, 'Real authorized bank metadata screen missing.');

  // A malicious offsite form could post with the admin's cookie but cannot
  // produce matching per-action WordPress nonce. Every handler MUST deny
  // before validation, metadata writes, exports or file staging.
  const adminActions = [
    { action: 'wbm_pro_save_bank_account', form: {
      nickname: 'P8-CSRF-MUST-NOT-APPEAR', bank_name: 'Untrusted synthetic',
      currency: 'USD', identifier_last4: '8000',
    } },
    { action: 'wbm_pro_archive_bank_account', form: { account_id: '1' } },
    { action: 'wbm_pro_preview_statement', form: { account_id: '1', profile_id: '1' } },
    { action: 'wbm_pro_export_report', form: { report: 'import_batches' } },
    { action: 'wbm_save_free_settings', form: { wbm_claim_uploads: 'yes' } },
  ];
  for (const test of adminActions) {
    const response = await context.request.post(
      origin + '/wp-admin/admin-post.php',
      { form: { action: test.action, _wpnonce: 'p8-deliberately-invalid', ...test.form },
        failOnStatusCode: false, maxRedirects: 0 },
    );
    const status = response.status();
    if (status !== 403) {
      report.issues.push({ endpoint: 'admin-post', action: test.action, status });
    }
    report.attempts.push({ type: 'admin_post_wrong_nonce', action: test.action, status });
  }
  // WordPress REST cookie authentication rejects a sensitive POST without
  // X-WP-Nonce, even though the browser carries a valid admin login cookie.
  const endpoint = origin + '/wp-json/wpessential-wbm/v1/orders/' +
    fixture.order_id + '/finalize-payment';
  const request = await context.request.post(endpoint, {
    headers: { 'content-type': 'application/json' },
    data: JSON.stringify({ acknowledged: true }),
    failOnStatusCode: false,
    maxRedirects: 0,
  });
  const status = request.status();
  if (status !== 401 && status !== 403) {
    report.issues.push({ endpoint: 'rest_financial_no_cookie_nonce', status });
  }
  report.attempts.push({ type: 'rest_financial_missing_wp_nonce', status });

  const after = await page.goto(bankUrl, { waitUntil: 'load' });
  assert.equal(after?.status(), 200, 'Bank metadata page unavailable after denied CSRF probes.');
  const html = await page.locator('#wpbody-content').innerText();
  if (html.includes('P8-CSRF-MUST-NOT-APPEAR')) {
    report.issues.push({ type: 'cross_site_request_created_bank_metadata' });
  }
  await context.close();
} finally {
  await browser.close();
  writeFileSync(join(evidence, 'p8-csrf-http-report.json'),
    JSON.stringify(report, null, 2) + '\n');
}
if (report.issues.length || report.attempts.length !== 6) {
  console.error(JSON.stringify({ issues: report.issues, count: report.attempts.length }));
  process.exitCode = 1;
} else {
  console.log('PASS P8-T003 six real HTTP invalid/missing nonce CSRF denials on actual WP admin-post / REST; no authorized finance POST');
}
