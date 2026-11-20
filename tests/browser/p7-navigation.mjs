/**
 * P7-T006: actual WooCommerce wp-admin navigation links on a disposable
 * installed Free+Pro site. No REST POST, financial action or mock markup.
 * Run in both real WordPress LTR and Arabic RTL browser jobs.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const base = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const mode = process.env.P7_BROWSER_MODE || 'ltr';
const password = process.env.P7_BROWSER_ADMIN_PASSWORD;
const dir = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
assert(process.env.GITHUB_ACTIONS === 'true' && password,
  'Navigation browser checks require synthetic CI credentials');
assert(['ltr', 'rtl'].includes(mode), 'Unsupported test locale');
mkdirSync(dir, { recursive: true });

const slugs = [
  'wpessential-bank-transfer-matcher',
  'wpessential-wbm-pro-bank-accounts',
  'wpessential-wbm-pro-mapping-profiles',
  'wpessential-wbm-pro-import-statements',
  'wpessential-wbm-pro-bank-transactions',
  'wpessential-wbm-pro-review-queue',
  'wpessential-wbm-pro-allocations',
  'wpessential-wbm-pro-finance-reports',
  'wpessential-wbm-pro-notifications',
  'wpessential-wbm-pro-dashboard',
  'wpessential-wbm-pro-operations',
  'wpessential-wbm-pro-retention',
];
const target = slug => new URL('/wp-admin/admin.php?page=' + slug, base).href;
const results = { mode, disposable: true, views: [], issues: [],
  no_financial_mutations: true };
const browser = await chromium.launch({ headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: mode === 'rtl' ? 'ar' : 'en-US',
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('wbm_ci');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(u => u.pathname.startsWith('/wp-admin/'), { timeout: 45000 });

  for (const width of [1440, 375]) {
    await page.setViewportSize({ width, height: width === 375 ? 812 : 900 });
    try {
      const landing = await page.goto(target(slugs[0]), { waitUntil: 'domcontentloaded' });
      assert.equal(landing?.status(), 200, 'Free matcher page not reachable');
      const actual = await page.locator('#adminmenu a[href*="page=wpessential-"]')
        .evaluateAll(links => links.map(link => {
          try { return new URL(link.href).searchParams.get('page'); }
          catch { return ''; }
        }).filter(Boolean));
      assert.equal(actual.length, slugs.length, 'Unexpected menu link count or duplicate slugs');
      assert.deepEqual([...actual].sort(), [...slugs].sort(),
        'WooCommerce matcher sidebar has missing, stale or unexpected destinations');
      const direction = await page.evaluate(() =>
        document.documentElement.getAttribute('dir') ||
        getComputedStyle(document.documentElement).direction);
      if (mode === 'rtl') {
        assert.equal(direction, 'rtl', 'WordPress Arabic sidebar is not RTL');
      }
      if (width === 1440) {
        // Exercise genuine keyboard navigation of a WordPress submenu anchor.
        const anchor = page.locator(
          '#adminmenu a[href*="page=wpessential-wbm-pro-review-queue"]').first();
        await anchor.focus();
        assert(await anchor.evaluate(node => document.activeElement === node),
          'WooCommerce review submenu cannot receive keyboard focus');
        await page.keyboard.press('Enter');
        await page.waitForURL(u => u.searchParams.get('page') ===
          'wpessential-wbm-pro-review-queue', { timeout: 30000 });
      } else {
        // WordPress's built-in responsive menu toggle, not a simulated UI.
        const toggle = page.locator('#wp-admin-bar-menu-toggle a').first();
        assert(await toggle.isVisible(), 'WordPress mobile navigation toggle is missing');
        await toggle.click();
        assert(await page.locator('#adminmenuwrap').isVisible(),
          'Actual WordPress responsive menu does not open at 375px');
      }
      const shot = 'p7-nav-' + mode + '-' + width + '.png';
      await page.screenshot({ path: join(dir, shot), fullPage: true });
      for (const slug of slugs) {
        const response = await page.goto(target(slug), { waitUntil: 'domcontentloaded' });
        const body = await page.locator('#wpbody-content').innerText();
        assert.equal(response?.status(), 200, 'Menu destination returned non-200: ' + slug);
        assert(!page.url().includes('/wp-login.php') &&
          !/There has been a critical error on this website|Sorry, you are not allowed to access this page/i.test(body),
          'Accessible admin menu destination renders login/403/error: ' + slug);
        assert(await page.locator('#wpbody-content h1').count() > 0,
          'Menu page missing native heading: ' + slug);
      }
      results.views.push({ width, mode, menu_count: actual.length,
        targets_visited: slugs.length, keyboard_or_mobile_tested: true,
        screenshot: shot, direction });
    } catch (error) {
      results.issues.push({ width, mode, error: String(error?.message || error).slice(0, 350) });
    }
  }
} finally {
  await browser.close();
  writeFileSync(join(dir, 'p7-navigation-' + mode + '.json'),
    JSON.stringify(results, null, 2) + '\n');
}
if (results.issues.length || results.views.length !== 2) {
  console.error(JSON.stringify(results.issues, null, 2));
  process.exitCode = 1;
} else {
  console.log('PASS real WordPress ' + mode +
    ' admin submenu links, native keyboard/mobile menu and 24 safe route visits');
}
