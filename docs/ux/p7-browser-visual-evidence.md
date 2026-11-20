# P7-T004 — Actual browser screenshot, RTL, forced-colors and keyboard evidence

**Status: VERIFIED** at exact source SHA `483a7e49caf80067361ae6a2619a0a8215213cb9`. Browser workflow [44672577045](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44672577045) SUCCESS; Foundation/Playground/native WordPress/WooCommerce/MariaDB 44672584525 and 44672576959 both SUCCESS. Stored GitHub Actions artifact `p7-real-wordpress-visual-evidence`, artifact ID **14600897788** (13 MB), contains 111 real screenshot PNGs and 2 machine-readable JSON reports. **No screenshot is simulated or Figma-generated.**

## Reproduction

The isolated workflow `.github/workflows/p7-browser-qa.yml` installs WP 7.0.8, WC 11.3.0, actual packaged Free and Pro and a private disposable MariaDB `wbm_ci`. CI-only `tests/browser/p7-seed.php` creates one synthetic unverified BACS order, a synthetic unverified customer claim, masked bank account metadata, one synthetic imported statement and a review case. A random masked administrator password is used; no real bank, merchant email, public production site or money transaction is contacted.

`tests/browser/p7-visual.mjs` logs in with Playwright/Chromium 1.65.1 via the actual WordPress PHP web server, then renders **18 real page variants covering all 14 UI-001–UI-014 IDs** at **1440×900, 1024×768 and 375×812**. It checks each HTTP status, real rendered source title, page horizontal overflow, Free/Pro stylesheet isolation and keyboard-focusable table scroll regions. The workflow switches the actual WordPress locale and administrator user locale to **Arabic** and verifies `is_rtl()`, then repeats the 54 screenshots. Three additional forced-colors/high-contrast captures at 375px cover Pro review, allocation and statement rows.

## Verifiable result

| Check | Actual evidence |
| --- | --- |
| LTR | `p7-report-ltr.json`: 54 real 1440/1024/375 screen PNGs, 14/14 unique UI IDs, 0 automated issues |
| Arabic RTL | `p7-report-rtl.json`: 54 real 1440/1024/375 PNGs, 14/14 unique UI IDs, computed direction `rtl`, 0 automated issues |
| High contrast | 3 Chromium forced-colors screenshots of the actual native WP finance/review screens |
| Keyboard | 8 LTR + 8 RTL successful 375px focus-to-Tab checks on actual scrollable data tables |
| Visual data safety | Synthetic BACS claim/import rows remain unverified, screenshots use only test-account data |
| Full code CI | Both exact SHA 483a7e49 Foundation and native WP/WC/MariaDB runs successful |

The first browser CI 44672117117 stored 53 LTR screenshots but failed 4 source-text assertions (audit title and capitalized native settings heading). The second run 44672479856 stored 54 LTR screenshots but still failed the exact case of the Free settings heading. These were **test assertion mismatches**, not hidden screenshots or passed QA. The final exact code SHA fixed assertions against actual Free `Recent reconciliation activity` and `Bank Transfer Matcher settings` headings; **both** browser languages now pass and preserve full artifacts.

## Explicit remaining gates

This automated capture verifies that the pages render, have no measured top-level overflow, and expose working keyboard-focusable table regions in normal and real Arabic RTL direction. It is **not** a substitute for complete manual WCAG 2.2 AA audit, screen reader reading order, contrast ratio inspection, exhaustive form/keyboard behavior, Figma mocks, screenshot diff approval or UX walkthroughs. **P7-T005** must investigate the actual captured pixel designs for misalignment/clipping, long translated text, row action positioning, statuses, and pagination. P7-T007 records before/after visual QA rubric; T008 performs role-based usability. PR #13 remains open/unmerged and Issue #7 remains open until phase/product gates respectively pass. No production release/deployment is approved.

## P7-T005 additional real screenshot/functional comparison (verified)

Exact code SHA `27e2c46bf7e26e983a5b908fdab4a57f91c29c85`: browser run [44682670860](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44682670860), screenshot artifact **14605361910** (115 actual screenshots: LTR 59 + Arabic RTL 56), **0 issues**; the exact-head Foundation/Playground/native WP/WC/MariaDB runs **44682670864 and 44682677458 both SUCCESS**.

Previous screenshot artifact **14601346876**, 111 real PNGs, exposed the 375px Free/Pro table presentation issues: bank-source selector and action button cramped together, review queue/filter buttons crowding page-size control and a source table that looked cut off without explicit horizontal-scroll guidance. Original stricter visual geometry runs failed the `buttonBelow` invariant; the actual root cause was WordPress native `submit_button(..., false)` producing a **direct unwrapped input**, not `p.submit`. The fix styles and checks the real input, not a fake wrapper. Latest after screenshots `ltr-UI-008-pro-bank-transactions-375.png`, `rtl-UI-008-pro-bank-transactions-375.png`, `rtl-UI-009-pro-review-queue-375.png` show vertically stacked full-width controls and readable table-scroll hints. No financial columns were removed; tables scroll within named keyboard regions.

Automated browser evidence includes **six** successful LTR/RTL 375px filter geometry checks (3 screens × 2 writing directions) for no overlapping/clipped labels, button below fields, accessible scroll hint and true table horizontal overflow or confirmed empty state. **Four** actual source-backed browser GET submissions (review-state filtered empty screen and imported-bank-source page-size selection × LTR/RTL) preserve submitted values, report real empty state and mask raw bank references. Review filter after-screenshot: `rtl-functional-review-filter-empty-375.png`; imported-source after: `ltr-functional-bank-source-page-size-375.png`. Native tests enforce 20/21 review/import pagination boundaries, no phantom Next, finance ledger page cap and no financial POST test calls.

**Scope limit:** Arabic WordPress RTL direction is genuine but plugin string translations and human screen-reader WCAG 2.2 AA audit are not certified; these remain P7-T007–T008. The full phase PR stays draft/unmerged until all P7 tasks pass; master Issue #7 remains open.
