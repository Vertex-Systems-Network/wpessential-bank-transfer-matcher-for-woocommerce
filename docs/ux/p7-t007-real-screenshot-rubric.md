# P7-T007 — Source-backed visual QA rubric and actual screenshot inspection

Status: **IN PROGRESS**; real before images inspected, after-scroll-reset screenshot and exact-head CI remain pending. Not an assertion of complete WCAG 2.2 AA compliance.

## Reproducible evidence, not mockups

- Source: real Chromium / disposable WordPress 7.0.8 + WooCommerce 11.3.0 + packaged Free/Pro + synthetic BACS/import/review case, run [44706953731](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44706953731), exact tested SHA `3854e7acbbaf1b6958538233919412560fc14d39`.
- Artifact: `p7-real-wordpress-visual-evidence`, GitHub artifact **14614998226**. Local inspection included `ltr-UI-008-pro-bank-transactions-375.png`, `ltr-UI-009-pro-review-queue-375.png`, `rtl-UI-009-pro-review-queue-375.png`, `rtl-UI-009-pro-case-workspace-375.png`, `ltr-UI-007-pro-statement-import-375.png`, both `p7-nav-*-375.png`, and a 4×4 comparative contact sheet of Free/Pro, review/ledger/reports/import screen variants. Original artifact contains LTR 59 + Arabic RTL 56 visual screenshots (including 14 real UI IDs), 4 additional menu screenshots, 2 browser reports and 2 navigation reports. Both LTR/RTL reports and both navigation reports have **0 automated issues**; the artifact is synthetic, not merchant evidence.
- Source-backed checks: HTML headings and HTTP 200, 1440/1024/375, no page-wide overflow over 10px on 375, Pro-vs-Free scoped assets, 6 real mobile filter geometry checks, 4 real GET form submissions and persistent URL/filtered states, 16 keyboard table-focus/Tab checks, forced-high-contrast Chromium captures, real `is_rtl()` locale, all 12 menu links, mobile navigation and Enter.

## Visual rubric, findings, disposition

| Dimension | Actual observed evidence | Finding / next action |
| --- | --- | --- |
| Native WordPress appearance | 375px bank importer, review queue, finance reports and dashboard screenshots | Familiar WooCommerce wp-admin forms, headings, tables, notices, native buttons. No detached neon or SaaS shell observed on inspected screens |
| 375px form layout | LTR/Arabic RTL import, source-batch selector, case priority/state, finance allocation filters | Main labels and inputs vertically stacked; no visible filter overlapping in inspected screenshots. Automated geometric 3-screen×2-locale checks passed |
| Responsive data tables | `ltr-UI-008-pro-bank-transactions-375.png` and LTR/RTL review queue | Whole table intentionally scrolls within accessible titled region, not over document. Visible scroll guidance indicates additional columns; amounts remain unverified |
| Keyboard-focus screenshot artifact | `ltr-UI-009-pro-review-queue-375.png` starts with *Order / Assignee* rather than *Case / Type*. RTL begins with *Assignee*; the nearest first-column label looks clipped | **Capture-sequence issue identified:** automation focuses the scrollable region then Tabs to far-right row action before screenshot, shifting horizontally to the end. This cannot be counted as a confirmed CSS clipping defect. In `tests/browser/p7-visual.mjs` at `0e105a59`, explicitly restore `scrollLeft=0` after keyboard probe and before screenshot. Re-capture actual LTR/RTL and inspect new first-column images before closing T007 |
| Case details / money semantics | `rtl-UI-009-pro-case-workspace-375.png` shows three stacked evidence/order/decision cards | Reference masked, USD amounts separate from order total, conflict and on-hold text visible, no claim/import proof of paid state |
| Navigation | `p7-nav-ltr-375.png` / `p7-nav-rtl-375.png` | Native WooCommerce sidebar opens on 375px, all 12 links available. Menu role visibility/native tests in T006 passed |
| Arabic RTL | RTL screenshots of imports, reports, queue, case workspace, menu | Actual WordPress Arabic locale/direction correctly flips layout. **Plugin content remains English in these screenshots** (translations not certified). Log localization gap; do not claim complete Arabic language coverage |
| Focus and contrast | 16 mobile keyboard region checks; 3 forced-colors images | Automated focus functionality passed and forced-colors screenshots were actually captured; contrast ratios and screen-reader reading order **not measured** by these checks |
| Long reports / usability | `ltr-UI-011-pro-finance-reports-375.png` and RTL counterpart | Finance export page becomes long vertically on 375px, but sections and bounded export controls remain individually reachable. T008 must test task completion/focus order, not infer ease-of-use from capture |
| Security and provenance | All screenshots use disposable synthetic import and claim fixtures | No real bank/customer data; no financial POST or automatic paid action in visual pipeline |

## Before/after source-backed screenshots

**Before (real) [44706953731](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44706953731), artifact 14614998226**: `ltr-UI-009-pro-review-queue-375.png` and `rtl-UI-009-pro-review-queue-375.png` show the *last* horizontal table columns after keyboard focus. The existing UI scroll region itself remains usable.

**After source change**: `tests/browser/p7-visual.mjs` restores `scrollLeft=0` before screenshot while preserving successful keyboard-to-Tab exercise. The resulting live browser artifact, image comparison, and exact HEAD full green CI are **PENDING**. Do not claim the after artifact is verified until fetched and visually inspected.

## Residual acceptance requirements

1. Complete after-screenshot comparison in 375px LTR and RTL, plus sample 1440 and 1024 layouts; confirm first-column screenshot baseline restored without hiding table features.
2. Obtain exact-final-head successful browser and Foundation/native/Playground CI.
3. Record any remaining visible issues as concrete scoped P8/P9 release gates. Plugin Arabic content translation, assistive screen-reader reading order, and formal per-color WCAG 2.2 AA contrast tests remain **unverified**; a 0-issue screenshot script does not certify accessibility.
4. Run P7-T008 store-owner, finance-operator and customer task-based usability on synthetic flows before phase completion.

**Safety invariant:** No receipt/claim/statement alone marks paid; no real financial mutation was executed during visual QA.
