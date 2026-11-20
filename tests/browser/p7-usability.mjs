/**
 * P7-T008: genuine browser task walkthrough with three *different* users:
 * store owner, finance operator and unauthenticated BACS customer.
 *
 * Synthetic WordPress/WooCommerce/MariaDB CI only. Absolutely no POST to
 * financial REST actions, admin-post reconciliation endpoints, or claims.
 * The only POST in this script is WordPress user login.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const base = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const mode = process.env.P7_BROWSER_MODE || 'ltr';
const adminPassword = process.env.P7_BROWSER_ADMIN_PASSWORD;
const financePassword = process.env.P7_USABILITY_FINANCE_PASSWORD;
const fixturePath = process.env.P7_BROWSER_FIXTURE_FILE;
const dir = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
assert(process.env.GITHUB_ACTIONS === 'true' && fixturePath &&
  adminPassword && financePassword, 'Real role workflows require masked CI-only credentials');
assert(['ltr', 'rtl'].includes(mode), 'Unsupported role-workflow locale');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
assert(fixture.disposable === true && fixture.version === 1 &&
  Number.isInteger(fixture.unclaimed_order_id) && fixture.unclaimed_order_id > 0 &&
  /^https?:\/\//.test(fixture.unclaimed_checkout_received_url) &&
  fixture.finance_login === 'wbm_finance_ci',
  'Usability fixtures must be synthetic and contain a separate unclaimed BACS order');
mkdirSync(dir, { recursive: true });

const target = slug => base + '/wp-admin/admin.php?page=' + slug;
const report = {
  version: 1, disposable: true, mode, no_financial_mutations: true,
  personas: ['store_owner', 'finance_operator', 'guest_customer'],
  steps: [], issues: [], captures: [],
};
const browser = await chromium.launch({ headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'] });
const contexts = [];
const assertScreen = async (page, url, needle) => {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  assert.equal(response?.status(), 200, 'Real WordPress page returned non-200');
  const text = await page.locator('#wpbody-content').innerText();
  assert(text.includes(needle), 'Source-backed page copy missing: ' + needle);
  assert(!/There has been a critical error on this website|Sorry, you are not allowed to access this page/i.test(text),
    'Role-specific WordPress page is forbidden or crashed');
  return text;
};
const assertRTL = async page => {
  if (mode !== 'rtl') return;
  const actual = await page.evaluate(() => getComputedStyle(document.documentElement).direction);
  assert.equal(actual, 'rtl', 'Actual Arabic WordPress role session is not RTL');
};
const step = (persona, action, details) => report.steps.push({ persona, action, details });
const screenshot = async (page, persona, width) => {
  const path = 'p7-usability-' + mode + '-' + persona + '-' + width + '.png';
  await page.screenshot({ path: join(dir, path), fullPage: true,
    animations: 'disabled', timeout: 45000 });
  report.captures.push({ persona, width, path });
};
const trackForbiddenFinancialRequests = page => {
  page.on('request', request => {
    const method = request.method();
    const path = new URL(request.url()).pathname;
    if (method !== 'GET' && method !== 'HEAD' &&
      (/\/wp-admin\/admin-post\.php$/.test(path) ||
       /\/wp-json\/wpessential-wbm\//.test(path))) {
      report.issues.push({ persona: 'network', issue:
        'Financial or customer claim request was unexpectedly sent: ' + method + ' ' + path });
    }
  });
};
const loggedIn = async (username, password) => {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 },
    locale: mode === 'rtl' ? 'ar' : 'en-US', reducedMotion: 'reduce' });
  contexts.push(ctx);
  const page = await ctx.newPage();
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(u => u.pathname.startsWith('/wp-admin/'), { timeout: 45000 });
  trackForbiddenFinancialRequests(page);
  return page;
};

try {
  try {
    // STORE OWNER: discover BACS order, inspect existing unverified claim,
    // see outstanding balance, verify Settings/Finance/Operations in wp-admin.
    const page = await loggedIn('wbm_ci', adminPassword);
    const overview = await assertScreen(page, target('wpessential-bank-transfer-matcher'),
      'Bank Transfer Matcher');
    assert(overview.includes('Unpaid native bank transfer orders'),
      'Store owner cannot identify unpaid bank transfer orders');
    const reviewLink = page.locator(
      'a[href*="wbm_view=order"][href*="order_id=' + fixture.order_id + '"]').first();
    assert(await reviewLink.count() === 1, 'Source-backed Review link for seeded order is missing');
    await reviewLink.click();
    await page.waitForURL(u => u.searchParams.get('wbm_view') === 'order' &&
      u.searchParams.get('order_id') === String(fixture.order_id));
    const orderView = await page.locator('#wpbody-content').innerText();
    for (const label of ['Reconcile order #', 'Customer claim', 'Claimed amount',
      'Verified allocation', 'Outstanding', 'Merchant bank evidence']) {
      assert(orderView.includes(label), 'Store-owner order review missing ' + label);
    }
    assert(orderView.includes('does not mark order paid') ||
      orderView.includes('does not complete WooCommerce payment'),
      'Order reviewer has no explicit claim-versus-payment warning');
    await assertRTL(page);
    await screenshot(page, 'store-owner-order', 1440);
    step('store_owner', 'open_real_claimed_order',
      'Review link, unverified claim, outstanding/allocated balances and payment warning visible');
    const dashboard = await assertScreen(page, target('wpessential-wbm-pro-dashboard'),
      'Bank finance dashboard');
    assert(dashboard.includes('Read-only, source-backed'), 'Dashboard lacks limited-scope wording');
    await assertScreen(page, target('wpessential-wbm-pro-operations'),
      'Bank operations diagnostics');
    await assertScreen(page,
      target('wpessential-bank-transfer-matcher') + '&wbm_view=settings',
      'Bank Transfer Matcher settings');
    assert(await page.locator('input[name="action"][value="wbm_save_free_settings"]').count() === 1,
      'Owner should be able to access native Free settings form');
    await page.setViewportSize({ width: 375, height: 812 });
    await assertScreen(page, target('wpessential-bank-transfer-matcher') + '&wbm_status=on-hold',
      'Bank Transfer Matcher');
    assert((await page.locator('select[name="wbm_status"]').inputValue()) === 'on-hold',
      'Store owner cannot preserve actual order-state filter');
    await screenshot(page, 'store-owner-orders', 375);
    step('store_owner', 'navigate_finance_settings_and_mobile_orders',
      'Real dashboard/operations/settings, native status filter, mobile 375px navigation');

  } catch (error) {
    report.issues.push({ persona: 'store_owner', issue: String(error?.message || error) });
  }

  try {
    // FINANCE OPERATOR: a genuine non-admin WooCommerce shop manager.
    const page = await loggedIn(fixture.finance_login, financePassword);
    const menuPages = await page.locator('#adminmenu a[href*="page=wpessential-"]')
      .evaluateAll(links => links.map(link => new URL(link.href).searchParams.get('page')));
    for (const slug of ['wpessential-bank-transfer-matcher',
      'wpessential-wbm-pro-review-queue',
      'wpessential-wbm-pro-import-statements',
      'wpessential-wbm-pro-allocations',
      'wpessential-wbm-pro-finance-reports',
      'wpessential-wbm-pro-dashboard']) {
      assert(menuPages.includes(slug), 'Real finance operator missing permitted menu: ' + slug);
    }
    for (const slug of ['wpessential-wbm-pro-retention',
      'wpessential-wbm-pro-notifications', 'wpessential-wbm-pro-operations']) {
      assert(!menuPages.includes(slug), 'Unpermitted manager-only menu exposed to finance role: ' + slug);
    }
    const queue = await assertScreen(page, target('wpessential-wbm-pro-review-queue'),
      'Bank review queue');
    assert(queue.includes('cannot allocate funds or mark an order paid'),
      'Reviewer UI lacks unverified-evidence warning');
    await page.locator('form.wbm-pro-toolbar select[name="priority"]').selectOption('high');
    await page.locator('form.wbm-pro-toolbar input[type="submit"]').click();
    await page.waitForURL(u => u.searchParams.get('priority') === 'high');
    const caseLink = page.locator('a[href*="case_id=' + fixture.case_id + '"]').first();
    assert(await caseLink.count() > 0, 'Finance operator cannot locate seeded high-priority case');
    await caseLink.click();
    await page.waitForURL(u => u.searchParams.get('case_id') === String(fixture.case_id));
    const context = await page.locator('#wpbody-content').innerText();
    for (const word of ['Bank evidence', 'Order comparison', 'Decision context']) {
      assert(context.includes(word), 'Finance case workspace lacks ' + word);
    }
    await assertRTL(page);
    await screenshot(page, 'finance-case', 1440);
    step('finance_operator', 'filter_case_and_open_workspace',
      'Actual high priority GET filter, case details and unverified bank evidence visible');

    const bank = await assertScreen(page,
      target('wpessential-wbm-pro-bank-transactions') + '&batch=' + fixture.batch_id,
      'Bank transactions');
    assert(bank.includes('Credit') && !bank.includes('SYNTHETIC-CREDIT'),
      'Imported source must be available but raw synthetic bank reference must remain masked');
    await assertScreen(page, target('wpessential-wbm-pro-allocations'),
      'Bank allocation ledger');
    const settings = await assertScreen(page,
      target('wpessential-bank-transfer-matcher') + '&wbm_view=settings',
      'Bank Transfer Matcher settings');
    assert(settings.includes('Only administrators can change these grants'),
      'Non-admin finance actor lacks settings permission explanation');
    assert(await page.locator('input[name="action"][value="wbm_save_free_settings"]').count() === 0,
      'Finance operator unexpectedly sees privileged Free settings save form');
    await page.setViewportSize({ width: 375, height: 812 });
    await assertScreen(page, target('wpessential-wbm-pro-review-queue') +
      '&priority=high', 'Bank review queue');
    await screenshot(page, 'finance-review', 375);
    step('finance_operator', 'inspect_masked_source_ledger_readonly_settings_and_mobile_review',
      'Raw references masked, allocation page readable, manager-only menus absent and settings read-only');
  } catch (error) {
    report.issues.push({ persona: 'finance_operator', issue: String(error?.message || error) });
  }

  try {
    // CUSTOMER: real anonymous WooCommerce order-received URL with a distinct
    // unclaimed order. Validate form controls but NEVER click Submit.
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 },
      locale: mode === 'rtl' ? 'ar' : 'en-US', reducedMotion: 'reduce' });
    contexts.push(ctx);
    const page = await ctx.newPage();
    trackForbiddenFinancialRequests(page);
    const response = await page.goto(fixture.unclaimed_checkout_received_url,
      { waitUntil: 'domcontentloaded', timeout: 45000 });
    assert.equal(response?.status(), 200, 'Anonymous BACS received page unavailable');
    const text = await page.locator('body').innerText();
    assert(!page.url().includes('/wp-login.php') && text.includes('Payment reference'),
      'Guest cannot see the scoped BACS payment reference');
    assert(text.includes('does not verify payment'),
      'Customer does not see unverified-claim disclosure');
    const form = page.locator('form.wbm-payment-claim');
    assert(await form.count() === 1, 'An unclaimed guest BACS order has no real claim form');
    for (const input of ['order_id','order_key','billing_email','transfer_date',
      'transfer_amount','acknowledged','_wbm_claim_nonce']) {
      assert(await form.locator('[name="' + input + '"]').count() === 1,
        'Customer claim missing required form control ' + input);
    }
    assert(await form.evaluate(element => !element.checkValidity()),
      'Empty guest form must fail native browser required-field validation');
    await screenshot(page, 'guest-unclaimed-order', 1440);
    await page.setViewportSize({ width: 375, height: 812 });
    await screenshot(page, 'guest-claim-form', 375);
    step('guest_customer', 'inspect_unpaid_order_reference_and_form_without_submit',
      'Scoped order key and owner email fields required, form invalid if blank, no claim POST');
  } catch (error) {
    report.issues.push({ persona: 'guest_customer', issue: String(error?.message || error) });
  }
} finally {
  for (const context of contexts) await context.close();
  await browser.close();
  writeFileSync(join(dir, 'p7-usability-' + mode + '.json'),
    JSON.stringify(report, null, 2) + '\n');
}
const finished = new Set(report.steps.map(s => s.persona));
if (report.issues.length > 0 || finished.size !== 3 ||
  report.captures.length !== 6 || report.steps.length !== 5) {
  console.error(JSON.stringify({ problems: report.issues,
    roleSteps: report.steps.length, screenshots: report.captures.length }, null, 2));
  process.exitCode = 1;
} else {
  console.log('PASS P7-T008 actual ' + mode +
    ' store-owner, finance-operator and guest-customer usability: 5 safe tasks and 6 real screenshots');
}
