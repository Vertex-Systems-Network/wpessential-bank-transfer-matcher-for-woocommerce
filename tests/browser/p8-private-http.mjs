/**
 * P8-T002: real PHP/WordPress HTTP negative private-file checks.
 * Disposed localhost and synthetic bank reference only. No financial POST.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const base = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const password = process.env.P7_BROWSER_ADMIN_PASSWORD;
const output = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
assert(process.env.GITHUB_ACTIONS === 'true' && password,
  'Public HTTP negative checks require CI-only synthetic credentials');
const source = JSON.parse(readFileSync('/tmp/p8-private-http.json', 'utf8'));
assert(source.disposable === true && Number.isInteger(source.order_id) &&
  /^[a-f0-9]{48}\.csv$/.test(source.source_basename),
  'Source fixture cannot include full private paths or customer details');
mkdirSync(output, { recursive: true });
const report = { version: 1, disposable: true, source_only_basename: true,
  probes: [], issues: [], no_financial_mutation: true };
const browser = await chromium.launch({ headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
  const publicContext = await browser.newContext();
  const anon = await publicContext.newPage();
  for (const path of [
    '/' + source.source_basename,
    '/wp-content/uploads/' + source.source_basename,
    '/wp-content/plugins/wpessential-bank-transfer-matcher-pro/' + source.source_basename,
    '/wp-admin/admin-post.php?action=wpessential_wbm_download_receipt&order_id=' + source.order_id,
    '/wp-admin/admin-post.php?action=wpessential_wbm_download_receipt&order_id=' +
      source.order_id + '&_wpnonce=incorrect',
  ]) {
    const response = await anon.goto(base + path, { waitUntil: 'domcontentloaded' });
    const status = response?.status() ?? 0;
    const contentType = (response?.headers()['content-type'] ?? '').toLowerCase();
    const body = await anon.locator('body').innerText().catch(() => '');
    const kind = path.includes('admin-post') ? 'private_receipt' : 'private_statement';
    // The disposable WordPress router sometimes returns a generic HTML
    // theme/onboarding page with HTTP 200 for nonexistent wp-content URLs.
    // A 200 HTML fallback is not a private CSV leak. Verify *actual bytes*
    // and MIME type; never accept a CSV/XML/octet-stream response.
    const privateBytesExposed = body.includes('SYNTHETIC-CREDIT') ||
      body.includes('CI-P7-001') ||
      (kind === 'private_statement' && (
        contentType.includes('text/csv') ||
        contentType.includes('application/octet-stream') ||
        contentType.includes('application/xml'))) ||
      (kind === 'private_receipt' && status === 200);
    if (privateBytesExposed) {
      report.issues.push({ type: 'anonymous_public_file', status,
        path_kind: kind, content_type: contentType });
    }
    report.probes.push({ actor: 'guest', path_kind: kind, status,
      generic_html_fallback: status === 200 && contentType.includes('text/html') });
  }
  await publicContext.close();

  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const admin = await adminContext.newPage();
  await admin.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await admin.locator('#user_login').fill('wbm_ci');
  await admin.locator('#user_pass').fill(password);
  await admin.locator('#wp-submit').click();
  await admin.waitForURL(u => u.pathname.startsWith('/wp-admin/'), { timeout: 45000 });
  const denied = await admin.goto(base + '/wp-admin/admin-post.php?action=wpessential_wbm_download_receipt' +
    '&order_id=' + source.order_id + '&_wpnonce=incorrect', { waitUntil: 'domcontentloaded' });
  const adminStatus = denied?.status() ?? 0;
  if (adminStatus !== 403) {
    report.issues.push({ type: 'authorized_admin_bad_nonce', status: adminStatus });
  }
  report.probes.push({ actor: 'authenticated_admin', path_kind: 'private_receipt_bad_nonce',
    status: adminStatus });
  await adminContext.close();
} finally {
  await browser.close();
  writeFileSync(join(output, 'p8-private-http-report.json'), JSON.stringify(report, null, 2) + '\n');
}
if (report.probes.length !== 6 || report.issues.length) {
  console.error(JSON.stringify(report, null, 2));
  process.exitCode = 1;
} else {
  console.log('PASS P8-T002 six real HTTP private statement/receipt negative access checks; no bank data leaked');
}
