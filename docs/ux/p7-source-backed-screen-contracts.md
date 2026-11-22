# P7-T001 — UI-001–UI-014 implementation-backed screen contracts

Baseline: merged P6 main 51c34bd75f3936bddfc86911c99722c834465e1a, 2026-11-20.
Source of desired functionality: docs/ux/screen-inventory.md, Master Directive Section 8.4–8.17.
Status: P7-T001 14-screen source/schema/gap contract fully verified at SHA `a396837e`, GitHub Actions 44665899783 and 44665892900 SUCCESS. This is **schema verification only**, not production screen parity, browser screenshots, visual QA or accessibility/RTL evidence.

## Universal screen design/financial controls

Use WooCommerce native admin headings/forms/tables, explicit server-role capabilities, labels, inline validation, no fake interactive controls, desktop-first 1440/1024/375 responsive and high-contrast/keyboard/RTL verification in later P7 tasks. Financial rows must preserve exact integer minor units, ISO currency, exponent, receiving account and allocation state separately. No automatic payment approval, order paid-state transition, bank clearance, refund or settlement is allowed from a preview or imported source. Never automatically pay orders from imported statements, claim submissions, suggestions, reports, notifications or diagnostics. Do not sum different currencies or proposed vs approved/committed amounts. Every aggregate must say whether it covers a bounded page or a fully reconciled source. Missing source means unavailable, never zero.

Universal states: initial; loading (only for actual async requests); empty; filtered-empty; source unavailable; validation error with preserved safe input; unauthenticated/permission denied; nonce/session expired; duplicate/stale version conflict; partial background processing; success with safe audit reference; failed/expired/cancelled task; active hold or incomplete independent bank clearance. Every real action needs its real route, method, capabilities, nonce, validation, safe error, success feedback and audit effect.

## UI-001 — OVERVIEW (Free, Pro enhancement)

Current sources: plugins/wpessential-bank-transfer-matcher/src/Admin/Overview.php and OrdersPage.php; Pro src/Admin/FinanceDashboardPage.php.
Actor: Free wbm_view for base overview; Pro finance dashboard requires Free wbm_approve plus Pro wbm_import.
Fields: outstanding native-BACS count, per-currency and exponent unpaid values, pending/unverified claim counts, recent verified cases, job health; Pro six distinct bounded source report cards and page totals.
Controls: navigate to eligible order lists, claims, import history; Pro date/ISO currency/account/report/state and 20/50/100 page size; safe refresh and help.
States: initial, no eligible records, source unavailable, filter empty, claim pending, import partial/failed and permission denial.
Current evidence: Free overview and Pro read-only six-report dashboard exist.
Implementation gaps: integrated cross-edition KPI hierarchy and explicit data-scope labels need parity review; no global multi-currency balance may be inferred; richer help/refresh and responsive screenshot/RTL gates pending.

## UI-002 — BANK TRANSFER ORDERS (Free)

Current source: Free src/Admin/OrdersPage.php, backed by WC order CRUD and BACS status.
Actor: wbm_view; actual payment actions demand separate approval role.
Fields: immutable order reference and WC order number, status, gateway, creation date, claim state, independent verified sum, exact remaining amount and ISO currency.
Controls: exact WooCommerce order ID or immutable payment-reference lookup within eligible status and native BACS, status and inclusive creation-date filters, clear filters and bounded pagination. Payment-state, currency and amount filtering remain acceptance gaps.
States: BACS default view, filtered empty, missing reference, unrelated gateway, no match, permission denied.
Gap: customer-field and custom order-number search, additional filter predicates and full row-action parity.

## UI-003 — ORDER DETAIL / WC META PANEL (Free, Pro sources)

Current source: Free src/Admin/OrderPanel.php, OrderSummary.php, ReconciliationPage.php, WC order CRUD, immutable ReferenceRepository.
Actor: authorized order viewer; separate wbm_review_claim and wbm_approve, with Pro source permissions as needed.
Fields: order ID/state, payment reference, BACS eligibility, currency/exponent, manual bank evidence, allocation and outstanding minor units, claim statuses, audit timeline, receiving account masked by role.
Controls: view native order, visit actual reconciliation, present/copy reference where implemented, reasoned bank verification via guarded finance service.
States: no legacy reference, wrong gateway, partial, claimed-unverified, no bank clearance, duplicate evidence, already finalized, permission denied, HPOS/classic.
Gaps: full drawer UX, all field/help labels, keyboard copy feedback, WC classic/HPOS placement and narrow-screen clipping are not yet visual test evidence.

## UI-004 — PAYMENT CLAIMS (Free)

Current sources: src/Claims/CustomerClaims.php, ClaimReviews.php, ClaimRepository.php, ClaimAttachment.php, AttachmentDownloads.php; Admin/ReconciliationPage.php.
Actor: rightful customer/order-key holder for submission, reviewer wbm_review_claim for internal decision, private-file access only after object authorization.
Fields: order reference, declared transfer date, amount minor/currency, optional sender/reference/note/attachment; review status and reason.
Controls: validated rate-limited owner/guest claim submit, real reviewer decision and secured attachment download.
States: no claim, submitted, under review, verified, rejected, duplicate claim, guest key expiry, malformed amount, missing attachment, non-owner denial.
Gap: a standalone globally searchable claim inbox and advanced reviewer/bulk filters are NOT established by these order-context handlers. Do not ship decorative claims table buttons.

## UI-005 — MANUAL VERIFICATION (Free)

Sources: Admin/ReconciliationPage.php, Evidence/BankEvidence.php, ManualAllocations.php, PaymentFinalizer.php and documented secured order REST routes.
Actor: wbm_approve, order eligibility, maker/checker and independently verified bank source.
Fields: selected eligible order, verified evidence type, bank posted/cleared date, exact positive amount and ISO exponent, external transaction identity, receiving bank account, merchant reason, expected and remaining balance.
Controls: actual evidence record, eligible partial allocation, explicit guarded finalization, correction or hold when mismatched.
States: missing evidence, duplicate fingerprint, wrong currency, overpay, partial, stale status, already paid, approval conflict, rollback.
Gaps: source provenance help, read-only impact preview, confirmation/audit UX and native label focus/contrast check. Preview can never mark an order paid.

## UI-006 — CUSTOMER CHECKOUT, THANK YOU, MY ACCOUNT, EMAIL (Free)

Sources: Orders/ReferenceIssuer.php, ReferencePresentation.php, Claims/CustomerClaims.php. Hooks include woocommerce_thankyou_bacs, woocommerce_view_order, woocommerce_email_before_order_table, and WC checkout/Store API order hooks.
Actor: rightful order owner, or guest carrying valid order-key/token; no order enumeration.
Fields: merchant BACS instructions, immutable payment reference, order amount/currency, permitted bank details, declared transfer/claim form.
Controls: actual customer BACS payment flow, owner-scoped claim, optional safe attachment, HTML/plain email.
States: classic and Blocks checkout, BACS vs non-BACS, valid owner/guest, bad key, paid/unpaid, duplicate claim, file invalid, nonce failure.
Gaps: real frontend browser screenshot, consistent mobile/RTL behavior and copy reference accessible confirmation not yet independently evidenced.

## UI-007 — IMPORT STATEMENTS (Pro)

Sources: Pro Admin/ImportStatementsPage.php, MappingProfilesPage.php, BankAccountsPage.php, Import/ImportJob.php.
Actor: wbm_import for preview/commit, wbm_manage_bank_accounts for bank metadata, manager+Free approval for audited recovery.
Fields: source account/currency, CSV file <=20MiB, mapping profile date/amount/exponent/delimiter/reference/transaction-ID, raw/invalid/duplicate warning counts, bounded worker status.
Controls: real two-step Validate and preview -> Commit import; authenticated retry failed only if private staged source SHA256 unchanged; cancel eligible jobs; never directly allocate funds.
States: empty source, invalid mapping, duplicate source, invalid bank posting/amount, warning, preview, queued/processing/chunk, complete, failed, cancelled, tampered source.
Gaps: richer multi-step mapping UI and CAMT indication should only appear for genuinely shipped adapters; verify keyboard forms, progress and privacy masked rows.

## UI-008 — BANK TRANSACTIONS (Pro)

Source: Pro Admin/BankTransactionsPage.php, imported rows and associated allocation/review sources.
Actor: Pro wbm_import for source inspection; approvals remain separate.
Fields: imported row ID, date, bank account, credit/debit direction, ISO currency, exact amount, duplicate/ambiguous state, proposal/review state, source batch.
Controls: existing native source filters and table actions; desired date, state, batch, min/max and reference queries ONLY if actual bounded SQL predicates support them.
States: no imported rows, filtered empty, valid credit, outgoing debit, duplicate fingerprint, missing reference, unverified import, permission denial.
Gaps: complete bounded search/filter and pagination parity, accessible direction labels and masked reference check. No direct approved-payment toggle.

## UI-009 — MATCHING & REVIEW QUEUE (Pro)

Source: Pro Admin/ReviewQueuePage.php, Api/ReviewCaseController.php, Matching/ReviewCaseRepository.php.
Actor: wbm_view for read-only case views, separate actual REST authorization for assign/transition/comment/priority.
Fields: case type, priority, status, source row, candidate order if any, explanations, assignee, comments and immutable event timeline.
Controls: implemented status/priority/assignee filters, bounded page, authenticated case transition/assignment/priority/comment; all require real permission and source case version.
States: open, assigned, investigating, awaiting approval, resolved, dismissed, reopened, duplicate, stale CAS, forbidden.
Gaps: master spec tabs Suggested/Needs review/Partial/Overpaid/etc and Run matcher on unreviewed are desired, NOT proof of existing routes. Check JS progressive no-JS fallback and accessible dynamic status.

## UI-010 — ALLOCATIONS / RECONCILIATION LEDGER (Pro)

Sources: Pro Admin/AllocationReviewPage.php, AllocationAdminActions.php, Reconciliation/AllocationLedgerRepository.php and Free payment finalizer.
Actor: maker for proposal, checker for approval with independent source and enforced capabilities, strict financial hold guard.
Fields: allocation ID, bank source ID, receiving account, exact minor amount + exponent/currency, eligible order, proposal/approval state, reviewer and reason, independent ledger source.
Controls: actual nonce-protected admin POST propose, approve, correction raise/review; separate permitted paid finalization if exact independent cleared amount.
States: proposed, approved, committed, reversed, duplicate source, partial, overpaid, stale-version conflict, financial hold.
Gaps: By order / By transaction views, safe multi-order split drawer and advanced filter layout require P7-T002 implementation checks. Never turn proposal into paid state.

## UI-011 — REPORTS (Pro)

Sources: Pro Admin/FinanceDashboardPage.php, ReportExportsPage.php, Reporting/ReportQueryRepository.php, AsyncReportExportJob.php.
Actor: Free approve + Pro import, plus manager for support diagnostics only.
Fields: six report keys, UTC dates except source bank posting-calendar, account ID, ISO currency or ALL, valid state, limit, keyset cursor; rows labeled with exact minor units and state.
Controls: bounded 20/50/100 admin report pages, immediate safe CSV <=500 rows/1MiB and 3 downloads/minute; queued actor-owned 100 rows/page and <=1000 rows/5MiB, status/download/cancel/expiry under private audit.
States: source unavailable vs empty, has_more, queued, running, ready, failed, cancelled, expired, rate limited, wrong actor, changed source.
Gaps: Saved Views and Print are NOT implemented; never add fake buttons. Chart/full-ledger global aggregates require independently reconciled source, not guessing from pages.

## UI-012 — AUDIT LOG (Free base, Pro enhanced)

Sources: Free Admin/AuditTimeline.php, Pro review/ledger audit stores, Pro Admin/FinanceOperationsPage.php for job audit summaries.
Actor: authorized Free view for order-scoped history, Pro finance roles for bank reviews, manager for support audit export.
Fields: safe event type, timestamp, actor role/system, entity/order, before/after review state and reason hash where permitted; no raw private bank notes.
Controls: real read-only timeline; no editing/deletion of financial audit.
States: empty, filter empty, missing schema, corrupted history, user forbidden.
Gap: dedicated cross-source global audit explorer/search/filters is NOT proven by existing per-order timeline + Pro job audit summaries.

## UI-013 — SETTINGS (Free + Pro)

Sources: Free Admin/SettingsPage.php; Pro MappingProfilesPage.php, BankAccountsPage.php, FinanceNotificationsPage.php, FinanceRetentionPage.php.
Actors: Free settings/admin appropriate cap, Pro import for mappings, Pro bank-account manager plus Free approve for notification recipient/retention controls.
Fields: immutable ref defaults/claim limits/accepted offline gateway/instruction preferences where currently supported; mapping profiles, manager-picked eligible recipient IDs, fixed opt-in digest types; retention enabled OFF and private source never-delete default.
Controls: real admin POST save + nonce, account/mapping creation, explicit notification preview/test only after opt-in, separate consented retention save/sweep.
States: unsaved, validation failure, success, role denied, scheduler unavailable, opt-in toggled/disabled, changed privacy policy.
Gaps: planned combined settings tabs and unsaved warning are NOT guaranteed present; no fake 100-property form or editable control without server route.

## UI-014 — TOOLS & DIAGNOSTICS (Free + Pro)

Sources: Pro Admin/FinanceOperationsPage.php, Operations/FinanceOperationsSnapshot.php, FinanceJobRecovery.php, Privacy/FinanceRetentionService.php; Free admin/settings basics.
Actor: Pro manager requires Free approve + Pro import + Pro manage bank accounts; no public support exports.
Fields: up to 20 latest import/export/mail operational rows per source with state, coarse age and counters; desired WP/WC/PHP/DB/HPOS/Blocks matrix not yet full UI proof.
Controls: read-only diagnostics, manager-only redacted CSV <=60 records/128KiB and 3/hour, source SHA256 verified failed import retry, original-owner fresh bounded failed export requeue, confirmed retention sweep.
States: source unavailable, filtered empty, failed, expired, tampered SHA, rate-limited, role denied, queue unavailable, successful audited recovery, privacy partial failure.
Gaps: full environment/plugin version/cron/schema matrix, accessibility, screenshots and RTL remain downstream. No one-click unsupported DB repair.

## Next task implementation contract and signoff boundaries

For every UI ID the next P7 task must bind each visible control to the exact existing WP admin POST/REST action, required nonce/capability, data source, validation, loading/empty/conflict response, and evidence test. If missing, explicitly implement/test backend or leave out the interactive control. Use native WordPress tables, labels, responsive widths, accessible status indicators and safe queries; preserve all P6 money invariants and user privacy.

Priority gaps: order list search/multi-filter; dedicated claim inbox vs order-context claim review; real import mapping wizard/format availability; case tab/action parity; Saved Views intentionally absent; global audit explorer absent; environment compatibility cards incomplete. Do not claim these have already shipped.

P7-T004 must separately prove 1440/1024/375 visual screenshots, keyboard navigation, high contrast, actual RTL and frontend/WordPress browser flows; THIS SCHEMA DOCUMENT DOES NOT CONSTITUTE THAT EVIDENCE. Do not merge the P7 phase until all its tasks, exact-head CI, and required manual/browser/human gates are verified. Master Issue #7 remains open.
