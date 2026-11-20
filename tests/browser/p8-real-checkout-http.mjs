/**
 * P8-T006 real guest WooCommerce checkout against disposable localhost:
 * classic WooCommerce checkout browser submission and actual Store API cart
 * + checkout (same backend used by Checkout Blocks). No simulated gateway
 * webhook, no fake checkout hooks, no external transfer/paid state.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const base = process.env.P7_BROWSER_BASE || 'http://127.0.0.1:8893';
const fixtureFile = process.env.P8_CHECKOUT_FIXTURE_FILE;
const outputFile = process.env.P8_CHECKOUT_REPORT_FILE;
const dir = process.env.P7_BROWSER_EVIDENCE_DIR || './evidence';
assert(process.env.GITHUB_ACTIONS === 'true' &&
  /^http:\/\/127\.0\.0\.1:\d+$/.test(base) &&
  fixtureFile?.startsWith('/tmp/') && outputFile?.startsWith('/tmp/'),
  'Actual WooCommerce checkout requires isolated CI localhost and temp artifacts.');
const fixture = JSON.parse(readFileSync(fixtureFile, 'utf8'));
assert(fixture.disposable === true && fixture.version === 1 &&
  Number.isInteger(fixture.product_id) && fixture.product_id > 0 &&
  fixture.classic_checkout_url.startsWith(base + '/') &&
  fixture.blocks_checkout_url.startsWith(base + '/'),
  'Untrusted checkout fixture.');
mkdirSync(dir, { recursive: true });
const report = {
  version: 1, disposable: true, gateway: 'bacs',
  php_version: fixture.php_version,
  wordpress_version: fixture.wordpress_version,
  woocommerce_version: fixture.woocommerce_version,
  source: 'actual_localhost_wc', orders: [], steps: [], issues: [],
};
const short = (s) => String(s ?? '').slice(0, 200);
const recorded = (name, detail) => report.steps.push({ name, detail });
const visitOrderReceived = async (page, url, channel) => {
  const received = new URL(url, base);
  assert.equal(received.origin, base, 'Order confirmation redirected off localhost.');
  const response = await page.goto(received.href,
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  assert.equal(response?.status(), 200, channel + ' order receipt HTTP not 200');
  // The actual WC order-received action must include the plugin reference.
  const reference = page.locator('.wbm-payment-reference code');
  await reference.waitFor({ state: 'visible', timeout: 25000 });
  assert.match((await reference.innerText()).trim(), /^[A-Z0-9-]{6,64}$/,
    channel + ' source-generated payment reference missing/invalid');
  assert.equal(await page.locator('form.wbm-payment-claim').count(), 1,
    channel + ' actual anonymous customer claim form missing');
  recorded(channel + '_order_received', { http_status: response.status(),
    reference_visible: true, claim_form_visible: true });
};

const browser = await chromium.launch({ headless: true,
  args: ['--no-sandbox', '--disable-dev-shm-usage'] });
try {
  // First checkout actually submits WooCommerce's classic browser checkout.
  const classicContext = await browser.newContext({ locale: 'en-US' });
  const classicPage = await classicContext.newPage();
  await classicPage.goto(base + '/?add-to-cart=' + fixture.product_id,
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  const checkoutResponse = await classicPage.goto(fixture.classic_checkout_url,
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  assert.equal(checkoutResponse?.status(), 200);
  const form = classicPage.locator('form.checkout.woocommerce-checkout');
  await form.waitFor({ state: 'visible', timeout: 30000 });
  assert(await classicPage.locator('#order_review').count() === 1,
    'Real shortcode checkout order review missing.');
  const inputs = {
    billing_first_name: 'Synthetic',
    billing_last_name: 'Classic',
    billing_address_1: '1 CI Fixture Lane',
    billing_city: 'Test City',
    billing_postcode: '90210',
    billing_phone: '5555550100',
    billing_email: 'p8-classic-ci@example.test',
  };
  for (const [id, value] of Object.entries(inputs)) {
    const input = classicPage.locator('#' + id);
    await input.waitFor({ state: 'attached', timeout: 20000 });
    await input.fill(value);
  }
  if (await classicPage.locator('#billing_country').count()) {
    await classicPage.locator('#billing_country').selectOption('US');
  }
  if (await classicPage.locator('#billing_state').count()) {
    const state = classicPage.locator('#billing_state');
    const tag = await state.evaluate(e => e.tagName.toLowerCase());
    if (tag === 'select') await state.selectOption('CA');
    else await state.fill('CA');
  }
  const bacs = classicPage.locator('input[name="payment_method"][value="bacs"]');
  assert(await bacs.count() === 1, 'Actual BACS payment gateway not listed for classic checkout.');
  if (!(await bacs.isChecked())) await bacs.check({ force: true });
  if (await classicPage.locator('#terms').count()) {
    await classicPage.locator('#terms').check({ force: true });
  }
  await classicPage.screenshot({ path: join(dir, 'p8-t006-classic-pre-submit.png'),
    fullPage: true, animations: 'disabled', timeout: 45000 });
  recorded('classic_real_checkout_form', { payment_method: 'bacs', synthetic_cart: true });
  await classicPage.locator('#place_order').click({ timeout: 30000 });
  try {
    await classicPage.waitForURL(/\/order-received\/\d+\//, { timeout: 55000 });
  } catch (e) {
    const hints = await classicPage.locator('.woocommerce-error, .woocommerce-NoticeGroup-checkout').allTextContents();
    throw new Error('Real classic checkout did not reach order-received; errors: ' +
      hints.map(short).join('; ') + '; path: ' + new URL(classicPage.url()).pathname);
  }
  const classicPath = new URL(classicPage.url());
  const classicId = Number(classicPath.pathname.match(/\/order-received\/(\d+)\//)?.[1] ?? 0);
  assert(Number.isInteger(classicId) && classicId > 0, 'Missing actual classic order ID.');
  await visitOrderReceived(classicPage, classicPage.url(), 'classic');
  report.orders.push({ channel: 'classic_shortcode_browser', id: classicId });
  await classicContext.close();

  // Separate anonymous browser demonstrates installed Checkout Block markup.
  const blockContext = await browser.newContext({ locale: 'en-US' });
  const blockPage = await blockContext.newPage();
  await blockPage.goto(base + '/?add-to-cart=' + fixture.product_id,
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  const blockResponse = await blockPage.goto(fixture.blocks_checkout_url,
    { waitUntil: 'domcontentloaded', timeout: 45000 });
  assert.equal(blockResponse?.status(), 200);
  const blockRoot = blockPage.locator('.wp-block-woocommerce-checkout, .wc-block-checkout').first();
  const blockRendered = await blockRoot.waitFor({ state: 'attached', timeout: 15000 })
    .then(() => true, () => false);
  const blockMarkup = await blockPage.evaluate(() => ({
    title: document.title.slice(0, 120),
    body_class: document.body.className.slice(0, 160),
    checkout_block_candidates: document.querySelectorAll('[class*="wc-block-checkout"], [class*="woocommerce-checkout"]').length,
  }));
  await blockPage.screenshot({ path: join(dir, 'p8-t006-blocks-render.png'),
    fullPage: true, animations: 'disabled', timeout: 45000 });
  recorded('real_checkout_block_page_probe', { http_status: blockResponse.status(),
    wc_checkout_block: blockRendered, dom: blockMarkup });
  if (!blockRendered) report.issues.push({ kind: 'checkout_block_not_rendered',
    path: new URL(blockPage.url()).pathname, dom: blockMarkup });
  await blockContext.close();

  // Actual Store API HTTP represents the Checkout Blocks transaction path,
  // not a manual do_action hook. The Cart-Token binds a separate guest session.
  const cartUrl = base + '/wp-json/wc/store/v1/cart';
  const cartResponse = await fetch(cartUrl);
  assert.equal(cartResponse.status, 200, 'Store API cart GET failed.');
  const cartToken = cartResponse.headers.get('Cart-Token');
  assert(cartToken && cartToken.length > 24, 'Woo Store API did not return Cart-Token.');
  const apiHeaders = { 'Content-Type': 'application/json', 'Cart-Token': cartToken };
  const add = await fetch(cartUrl + '/add-item', {
    method: 'POST', headers: apiHeaders,
    body: JSON.stringify({ id: fixture.product_id, quantity: 1 }),
  });
  const cartBody = await add.json();
  assert.equal(add.status, 200, 'Actual Store API add-item rejected: ' +
    short(cartBody?.message || JSON.stringify(cartBody)));
  assert(Array.isArray(cartBody.items) &&
    cartBody.items.some(x => x.id === fixture.product_id),
    'Woo Store API cart failed to retain the exact synthetic item.');
  recorded('store_api_real_guest_cart', { http_status: add.status, cart_token: true,
    contains_synthetic_product: true });
  const billing = {
    first_name: 'Synthetic', last_name: 'Blocks', company: '',
    address_1: '2 CI Fixture Lane', address_2: '', city: 'Test City',
    state: 'CA', postcode: '90210', country: 'US',
    email: 'p8-blocks-ci@example.test', phone: '5555550101',
  };
  const checkout = await fetch(base + '/wp-json/wc/store/v1/checkout', {
    method: 'POST', headers: apiHeaders,
    body: JSON.stringify({
      billing_address: billing, shipping_address: billing,
      payment_method: 'bacs', payment_data: [], customer_note: '',
      create_account: false,
    }),
  });
  const checked = await checkout.json();
  assert.equal(checkout.status, 200, 'Real Store API BACS checkout failed: ' +
    short(checked?.message || JSON.stringify(checked)));
  const blockId = Number(checked?.order_id ?? 0);
  assert(Number.isInteger(blockId) && blockId > 0,
    'Real Store API checkout did not create an order.');
  assert(checked?.payment_result?.redirect_url,
    'Store API BACS checkout did not return order-received redirect.');
  const guest = await browser.newContext({ locale: 'en-US' });
  const guestPage = await guest.newPage();
  await visitOrderReceived(guestPage, checked.payment_result.redirect_url, 'blocks_store_api');
  report.orders.push({ channel: 'blocks_store_api_http', id: blockId });
  recorded('store_api_real_checkout', {
    http_status: checkout.status, payment_method: 'bacs',
    payment_status: short(checked.payment_result?.payment_status),
    order_key_validated_by_wc: true,
  });
  await guest.close();
} catch (error) {
  report.issues.push({ kind: 'real_checkout_failure', detail: short(error?.message || error) });
} finally {
  await browser.close();
  writeFileSync(outputFile, JSON.stringify(report, null, 2) + '\n');
  writeFileSync(join(dir, 'p8-t006-real-checkout-report.json'),
    JSON.stringify(report, null, 2) + '\n');
}
if (report.issues.length || report.orders.length !== 2) {
  console.error('FAIL P8-T006 actual BACS classic vs Store API checkout: ' +
    JSON.stringify(report.issues));
  process.exitCode = 1;
} else {
  console.log('PASS P8-T006 actual classic browser checkout + Checkout Block render + Store API BACS guest checkout; two synthetic order-received reference/claim paths, no deliberate financial completion');
}
