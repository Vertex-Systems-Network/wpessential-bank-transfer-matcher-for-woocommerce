/**
 * Real WP/WooCommerce Chromium screenshots, no mock pages or financial POSTs.
 * CI-only synthetic BACS fixture, 14 UI IDs at 1440, 1024 and 375 widths.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const base = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const fixturePath = process.env.P7_BROWSER_FIXTURE_FILE;
const mode = process.env.P7_BROWSER_MODE || 'ltr';
const output = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
const password = process.env.P7_BROWSER_ADMIN_PASSWORD;
assert(fixturePath && password && process.env.GITHUB_ACTIONS === 'true',
  'Browser evidence requires isolated synthetic CI context');
assert(['ltr', 'rtl'].includes(mode), 'Unsupported screenshot direction');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
assert(fixture.disposable === true && fixture.version === 1,
  'Never screenshot real bank records from a production database');
const id = fixture.order_id;
const caseId = fixture.case_id;
const url = query => '/wp-admin/admin.php?page=' + query;
const reconcile = url('wpessential-bank-transfer-matcher') +
  '&wbm_view=order&order_id=' + id;
const specs = [
  { ui: 'UI-001', key: 'free-overview', route: url('wpessential-bank-transfer-matcher'), text: 'Bank Transfer Matcher', admin: true },
  { ui: 'UI-001', key: 'pro-finance-dashboard', route: url('wpessential-wbm-pro-dashboard'), text: 'Bank finance dashboard', admin: true },
  { ui: 'UI-002', key: 'free-orders-on-hold', route: url('wpessential-bank-transfer-matcher') + '&wbm_status=on-hold', text: 'Bank Transfer Matcher', admin: true },
  { ui: 'UI-003', key: 'wc-order-meta-panel', route: '/wp-admin/post.php?post=' + id + '&action=edit', text: 'Order', admin: true },
  { ui: 'UI-004', key: 'merchant-claim-review', route: reconcile, text: 'Customer claim', admin: true },
  { ui: 'UI-005', key: 'manual-reconciliation', route: reconcile, text: 'Reconcile order', admin: true },
  { ui: 'UI-006', key: 'bacs-customer-received', route: fixture.checkout_received_url, text: 'Payment reference', admin: false },
  { ui: 'UI-007', key: 'pro-statement-import', route: url('wpessential-wbm-pro-import-statements'), text: 'Import statements', admin: true },
  { ui: 'UI-008', key: 'pro-bank-transactions', route: url('wpessential-wbm-pro-bank-transactions') + '&batch=' + fixture.batch_id, text: 'Bank transactions', admin: true },
  { ui: 'UI-009', key: 'pro-review-queue', route: url('wpessential-wbm-pro-review-queue'), text: 'Bank review queue', admin: true },
  { ui: 'UI-009', key: 'pro-case-workspace', route: url('wpessential-wbm-pro-review-queue') + '&case_id=' + caseId, text: 'Bank evidence', admin: true },
  { ui: 'UI-010', key: 'pro-finance-allocations', route: url('wpessential-wbm-pro-allocations'), text: 'Bank allocation ledger', admin: true },
  { ui: 'UI-011', key: 'pro-finance-reports', route: url('wpessential-wbm-pro-finance-reports'), text: 'Finance reports', admin: true },
  { ui: 'UI-012', key: 'free-audit-timeline', route: reconcile, text: 'Audit', admin: true },
  { ui: 'UI-013', key: 'free-settings', route: url('wpessential-bank-transfer-matcher') + '&wbm_view=settings', text: 'Settings', admin: true },
  { ui: 'UI-013', key: 'pro-mapping-profiles', route: url('wpessential-wbm-pro-mapping-profiles'), text: 'Statement mappings', admin: true },
  { ui: 'UI-013', key: 'pro-retention-settings', route: url('wpessential-wbm-pro-retention'), text: 'Bank data retention', admin: true },
  { ui: 'UI-014', key: 'pro-ops-diagnostics', route: url('wpessential-wbm-pro-operations'), text: 'Bank operations diagnostics', admin: true },
];
const sizes = [{ width: 1440, height: 900 }, { width: 1024, height: 768 }, { width: 375, height: 812 }];
const report = { version: 1, mode, disposable: true, at_utc: new Date().toISOString(),
  playwright_version: '1.65.1', screens: [], issues: [], no_financial_mutations: true };
mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
  const context = await browser.newContext({ viewport: sizes[0], deviceScaleFactor: 1,
    locale: mode === 'rtl' ? 'ar' : 'en-US', reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('#user_login').fill('wbm_ci');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(u => u.pathname.startsWith('/wp-admin/'), { timeout: 45000 });
  for (const size of sizes) {
    await page.setViewportSize(size);
    for (const spec of specs) {
      const relative = /^https?:\/\//.test(spec.route) ? spec.route : base + spec.route;
      const entry = { ui: spec.ui, name: spec.key, width: size.width, mode };
      try {
        const response = await page.goto(relative, { waitUntil: 'domcontentloaded', timeout: 45000 });
        if (!response || response.status() !== 200) {
          throw new Error('WordPress returned HTTP ' + (response ? response.status() : 'none'));
        }
        const observed = await page.evaluate(() => ({
          body: document.body?.innerText?.slice(0, 18000) ?? '',
          direction: getComputedStyle(document.documentElement).direction,
          bodyRTL: document.body?.classList.contains('rtl') ?? false,
          htmlDir: document.documentElement.getAttribute('dir') || '',
          overflowPx: Math.max(
            document.documentElement.scrollWidth - document.documentElement.clientWidth, 0),
          forms: document.querySelectorAll('form').length,
          regions: document.querySelectorAll('[role="region"][tabindex="0"]').length,
          styles: [...document.querySelectorAll('link[rel="stylesheet"]')]
            .map(x => x.getAttribute('href') || '')
            .filter(x => x.includes('wpessential-bank-transfer-matcher')),
        }));
        if (page.url().includes('/wp-login.php') ||
          /There has been a critical error on this website|Sorry, you are not allowed to access this page/i.test(observed.body)) {
          throw new Error('PHP error or WordPress permissions prevented real screen rendering');
        }
        if (!observed.body.includes(spec.text)) {
          throw new Error('Expected actual screen text not rendered: ' + spec.text);
        }
        if (mode === 'rtl' && !observed.bodyRTL &&
          observed.htmlDir !== 'rtl' && observed.direction !== 'rtl') {
          throw new Error('Actual Arabic WP RTL locale not applied');
        }
        if (size.width === 375 && observed.overflowPx > 10) {
          throw new Error('Unbounded horizontal document overflow: ' + observed.overflowPx + 'px');
        }
        if (spec.key.startsWith('pro-') && spec.admin &&
          !observed.styles.some(s => s.includes('wpessential-bank-transfer-matcher-pro/assets/admin.css'))) {
          throw new Error('Real native Pro stylesheet not enqueued on actual WC screen');
        }
        if (spec.key.startsWith('free-') && spec.admin &&
          !observed.styles.some(s => s.includes('wpessential-bank-transfer-matcher/assets/admin.css'))) {
          throw new Error('Real native Free stylesheet not enqueued on actual WC screen');
        }
        if (size.width === 375 && observed.regions > 0) {
          const region = page.locator('[role="region"][tabindex="0"]').first();
          await region.focus();
          if (!(await region.evaluate(node => document.activeElement === node))) {
            throw new Error('Scrollable finance table cannot receive keyboard focus');
          }
          await page.keyboard.press('Tab');
          if (!(await region.evaluate(node => document.activeElement !== node))) {
            throw new Error('Tab focus trapped in scrollable finance table');
          }
          entry.keyboard_region_tested = true;
        }
        const filename = mode + '-' + spec.ui + '-' + spec.key + '-' + size.width + '.png';
        await page.screenshot({ path: join(output, filename), fullPage: true,
          animations: 'disabled', timeout: 45000 });
        entry.screenshot = filename;
        entry.direction = observed.direction;
        entry.overflow_px = observed.overflowPx;
        entry.form_count = observed.forms;
        entry.table_regions = observed.regions;
        report.screens.push(entry);
      } catch (error) {
        report.issues.push({ ui: spec.ui, page: spec.key, width: size.width, mode,
          issue: String(error?.message || error).slice(0, 280) });
      }
    }
  }
  if (mode === 'ltr') {
    for (const key of ['pro-review-queue', 'pro-finance-allocations', 'pro-bank-transactions']) {
      const spec = specs.find(x => x.key === key);
      await page.setViewportSize({ width: 375, height: 812 });
      await page.goto(base + spec.route, { waitUntil: 'domcontentloaded' });
      await page.emulateMedia({ forcedColors: 'active', contrast: 'more' });
      if (!(await page.evaluate(() => matchMedia('(forced-colors: active)').matches))) {
        report.issues.push({ page: key, issue: 'Chromium forced-color mode unavailable' });
      } else {
        const filename = 'high-contrast-' + key + '-375.png';
        await page.screenshot({ path: join(output, filename), fullPage: true });
        report.screens.push({ ui: spec.ui, name: key, width: 375,
          mode: 'high-contrast', screenshot: filename });
      }
      await page.emulateMedia({ forcedColors: 'none', contrast: 'no-preference' });
    }
  }
} finally {
  await browser.close();
  writeFileSync(join(output, 'p7-report-' + mode + '.json'),
    JSON.stringify(report, null, 2) + '\n');
}
const ids = new Set(report.screens.filter(x => x.mode === mode).map(x => x.ui));
if (ids.size !== 14 || report.issues.length) {
  console.error(JSON.stringify({ passedUI: ids.size,
    captured: report.screens.length, failures: report.issues.length,
    details: report.issues.slice(0, 16) }, null, 2));
  process.exitCode = 1;
} else {
  console.log('PASS real ' + mode + ' WordPress/WooCommerce Chromium screens: ' +
    ids.size + ' unique UI IDs and ' + report.screens.length + ' screenshots');
}
