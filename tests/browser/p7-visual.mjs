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
  { ui: 'UI-012', key: 'free-audit-timeline', route: reconcile, text: 'Recent reconciliation activity', admin: true },
  { ui: 'UI-013', key: 'free-settings', route: url('wpessential-bank-transfer-matcher') + '&wbm_view=settings', text: 'Bank Transfer Matcher settings', admin: true },
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
  await page.waitForLoadState('load');
  // WordPress/WooCommerce may initially land on a dashboard or onboarding
  // interstitial. Enter the real matcher wp-admin route before inspecting
  // navigation, rather than treating the transient post-login page as UI.
  await page.goto(base + '/wp-admin/admin.php?page=wpessential-bank-transfer-matcher',
    { waitUntil: 'load', timeout: 45000 });
  await page.locator('#adminmenu').waitFor({ state: 'attached', timeout: 15000 });
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
          body: (document.querySelector('#wpbody-content')?.innerText ?? document.body?.innerText ?? '').slice(0, 35000),
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
        // Real visual layout assertions: the mobile finance filters must not
        // overlap or truncate their actual controls in LTR or Arabic RTL.
        // These are DOM geometry assertions on genuine WP-rendered pages,
        // not assumptions about a simulated screenshot.
        if (size.width === 375 && ['pro-review-queue','pro-bank-transactions',
          'pro-finance-allocations'].includes(spec.key)) {
          const geometry = await page.evaluate(() => {
            const form = document.querySelector('form.wbm-pro-toolbar');
            const region = document.querySelector('.wbm-pro-table-scroll');
            const hint = document.querySelector('.wbm-pro-scroll-hint');
            const empty = document.querySelector('.wbm-pro-empty');
            if (!form || (!region && !empty) || (region && !hint)) return { missing: true };
            const bounds = form.getBoundingClientRect();
            const labels = [...form.querySelectorAll(':scope > label.wbm-pro-field')];
            const submit = form.querySelector(':scope > .submit');
            const control = submit?.querySelector('input[type="submit"],button') ||
              form.querySelector(':scope > input[type="submit"], :scope > button[type="submit"]');
            const boxes = labels.map(n => n.getBoundingClientRect());
            return {
              missing: false, fields: labels.length,
              narrowFields: boxes.filter(box => box.width < bounds.width * 0.8).length,
              outside: boxes.some(box => box.left < bounds.left - 1 || box.right > bounds.right + 1),
              overlapping: boxes.some((box, i) => i && box.top < boxes[i - 1].bottom - 1),
              buttonBelow: !!(control && boxes.length &&
                control.getBoundingClientRect().top > boxes[boxes.length - 1].bottom - 2),
              tooWideButton: !!(control &&
                control.getBoundingClientRect().width > bounds.width + 1),
              scrollable: !region || region.scrollWidth > region.clientWidth + 10,
              overflow: region ? getComputedStyle(region).overflowX : 'auto',
              hintVisible: !region || hint.getBoundingClientRect().height > 0,
              hintText: !region ? 100 : (hint.innerText || '').trim().length,
              emptyIsReal: !region && !!empty,
            };
          });
          if (geometry.missing || geometry.fields < 2 ||
              geometry.narrowFields || geometry.outside ||
              geometry.overlapping || !geometry.buttonBelow ||
              geometry.tooWideButton || !geometry.scrollable ||
              geometry.overflow !== 'auto' ||
              !geometry.hintVisible || geometry.hintText < 25) {
            throw new Error('Mobile financial filters/scroll guidance not accessible: ' +
              JSON.stringify(geometry));
          }
          entry.native_filter_geometry = 'passed';
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
          // Tab intentionally moves focus to the row action at the far end
          // of a wide ledger. Restore the logical start before taking the
          // visual baseline; otherwise the screenshot appears to clip the
          // first columns even though the real table is horizontally scrollable.
          const restored = await region.evaluate(node => {
            // Scroll coordinates differ between RTL browser engines. Rather
            // than assume scrollLeft===0, expose the actual first table
            // column after the focus-to-Tab probe and measure visibility.
            if (document.activeElement instanceof HTMLElement) {
              document.activeElement.blur();
            }
            const first = node.querySelector('thead th:first-child');
            if (!first) return { visible: false, reason: 'missing-first-header' };
            first.scrollIntoView({ behavior: 'instant', block: 'nearest', inline: 'start' });
            const viewport = node.getBoundingClientRect();
            const box = first.getBoundingClientRect();
            const overlap = Math.max(0,
              Math.min(viewport.right, box.right) - Math.max(viewport.left, box.left));
            const fraction = box.width > 0 ? overlap / box.width : 0;
            return { visible: fraction >= 0.85, fraction,
              scrollLeft: node.scrollLeft, direction: getComputedStyle(node).direction };
          });
          if (!restored.visible) {
            throw new Error('First ledger column still clipped after keyboard QA: ' +
              JSON.stringify(restored));
          }
          entry.table_first_column_visible_for_screenshot = true;
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

  // Exercise actual GET forms, not decorative filters or mocked API handlers.
  // Never submit a financial POST, allocation, claim or approval in visual QA.
  await page.setViewportSize({ width: 375, height: 812 });
  const checkFilter = async (ui, name, route, apply, verify) => {
    try {
      const response = await page.goto(base + route, { waitUntil: 'domcontentloaded' });
      if (!response || response.status() !== 200) {
        throw new Error('Actual WordPress screen unavailable for filter test');
      }
      await apply();
      await page.waitForLoadState('domcontentloaded');
      await verify();
      const filename = mode + '-functional-' + name + '-375.png';
      await page.screenshot({ path: join(output, filename), fullPage: true });
      report.screens.push({ ui,
        name, width: 375, mode, screenshot: filename, live_get_filter: true });
    } catch (error) {
      report.issues.push({ mode, page: name, width: 375, issue:
        'Native filter or persistence failed: ' + String(error?.message || error).slice(0, 220) });
    }
  };
  await checkFilter('UI-002', 'order-reference-copy',
    url('wpessential-bank-transfer-matcher') + '&wbm_search=' + encodeURIComponent(id),
    async () => {
      const control = page.locator('.wbm-copy-reference');
      const reference = await control.locator('.wbm-copy-value').innerText();
      const button = control.getByRole('button', { name: 'Copy reference' });
      await button.focus();
      await button.press('Enter');
      await control.getByRole('status').filter({ hasText: /payment reference (copied|selected)/i }).waitFor();
      const message = await control.getByRole('status').innerText();
      if (message.includes('selected') && await page.evaluate(() => String(window.getSelection())) !== reference) {
        throw new Error('Clipboard fallback did not select the exact payment reference');
      }
    },
    async () => {
      if (new URL(page.url()).searchParams.get('wbm_search') !== String(id)) {
        throw new Error('Copy reference changed the active order search');
      }
    });
  await checkFilter('UI-009', 'review-filter-empty',
    url('wpessential-wbm-pro-review-queue'),
    async () => {
      await page.locator('form.wbm-pro-toolbar select[name="status"]').selectOption('resolved');
      await page.locator('form.wbm-pro-toolbar input[type="submit"]').click();
    },
    async () => {
      const query = new URL(page.url()).searchParams;
      if (query.get('status') !== 'resolved' ||
          query.get('page') !== 'wpessential-wbm-pro-review-queue' ||
          !(await page.locator('.wbm-pro-empty').innerText()).includes('No review cases match')) {
        throw new Error('Case-state GET filter did not persist or return source-backed empty state');
      }
    });
  await checkFilter('UI-008', 'bank-source-page-size',
    url('wpessential-wbm-pro-bank-transactions') + '&batch=' + fixture.batch_id,
    async () => {
      await page.locator('form.wbm-pro-toolbar select[name="per_page"]').selectOption('20');
      await page.locator('form.wbm-pro-toolbar input[type="submit"]').click();
    },
    async () => {
      const query = new URL(page.url()).searchParams;
      const view = await page.locator('#wpbody-content').innerText();
      if (query.get('batch') !== String(fixture.batch_id) ||
          query.get('per_page') !== '20' ||
          query.get('page') !== 'wpessential-wbm-pro-bank-transactions' ||
          !view.includes('Credit') || view.includes('SYNTHETIC-CREDIT') ||
          await page.locator('.wbm-pro-table-scroll').count() !== 1) {
        throw new Error('Imported-source filter lost batch selection, masking, or bounded page size');
      }
    });

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
