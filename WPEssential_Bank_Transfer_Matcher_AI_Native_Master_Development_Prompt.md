# MASTER DEVELOPMENT DIRECTIVE — WPEssential Bank Transfer Matcher for WooCommerce

**Prompt version:** 1.1 — Composer, directory submission, documentation generator, Playground testing extension  
**Product:** WPEssential Bank Transfer Matcher for WooCommerce  
**Free plugin slug:** `wpessential-bank-transfer-matcher`  
**Pro plugin slug:** `wpessential-bank-transfer-matcher-pro`  
**Working name / text domain:** `wpessential-bank-transfer-matcher`; Pro `wpessential-bank-transfer-matcher-pro`  
**Commercial model:** Free + Pro (proposed $79/year, one-site license; multi-site tiers configurable)  
**Target:** Stable WordPress/WooCommerce environments with High-Performance Order Storage (HPOS) and supported Cart/Checkout Blocks  
**Product constraint:** Locally processed bank-statement reconciliation without requiring bank API connectivity  
**Working language for code, documentation, UI:** English, fully internationalizable and accessible  
**Mandatory delivery extensions:** Composer 2/PSR-4 for both products; WordPress.org Free submission candidate; WooCommerce Marketplace Pro submission candidate; generated `readme.txt` + `README.md`; WordPress Playground Blueprints and test bundles; evidence-backed release gates.  
**Directive type:** Execution-ready specification, architecture contract, UX contract, autonomous-agent playbook, test plan, release plan, and persistent-memory contract.

> **EXECUTE, DO NOT REDESIGN THE BRIEF.** This is the canonical product and execution specification. Treat every section marked MUST as an acceptance criterion. Do not ask a human to choose between ordinary implementation alternatives. Make a documented, reasoned decision and continue. Do not invent tool results, tests, deployment success, access permissions, banking evidence, or payment confirmations. An autonomous AI needs an actual orchestrator/runner and tool permissions; this prompt alone does not cause work to continue when an agent session is inactive.

---

## 0. FIRST MESSAGE / EXACT STARTING POINT

When assigned this prompt, **begin immediately**; do not respond with a design proposal asking for approval. Perform the following in order and record what actually happened:

1. Inspect the working directory, Git status, branch, repository structure, existing issues, dependency manifests, existing tests, automation workflows, and README. Do not overwrite a prior project without first inspecting it.
2. Check whether `START_HERE.md` and `.ai/state.json` exist. If they do, resume from their authoritative recorded state and verify it against Git and tests; never reset completed work. If not, create them from this specification.
3. Verify latest relevant official technical documentation using available browsing/tools: WordPress Plugin Handbook and plugin directory guidelines; WooCommerce BACS behavior; HPOS CRUD/compatibility; Cart/Checkout Blocks; Action Scheduler; PHP/WordPress requirements; WordPress coding standards and internationalization; Composer packaging; WordPress.org Plugin Check/readme validator; Woo Marketplace latest QIT and partner requirements; WordPress Playground Blueprint schema/bundle instructions. Capture URL and verification date in `docs/research/sources.md`. If web access is not available, label version facts "verification pending", use a conservative compatible baseline, and do not fabricate confirmation.
4. Create `/docs/product/PRD.md`, `/docs/product/feature-matrix.md`, `/docs/architecture/ADR-0001-separate-free-pro.md`, `/docs/ux/screen-inventory.md`, `/docs/testing/acceptance-matrix.md`, `/BACKLOG.md`, `.ai/state.json`, and `START_HERE.md`. Seed the full epic/task graph in Section 23 without treating documentation alone as feature completion.
5. Build the runnable local development/test environment with **WordPress Playground first** (Blueprint base → Free → Free+Pro), plus real MySQL/MariaDB tests for financial correctness and concurrency. Verify Composer autoloading and vendor package completeness. Record failures and environmental blockers with exact commands/logs.
6. Implement **Phase 0 → Phase 1** in the first available work cycle, including an installable minimal Free plugin shell, dependency checks, hooks, **Composer-generated PSR-4 production autoloading**, health/activation behavior, a native admin navigation shell, and a working `playground/blueprints/base/blueprint.json`. Begin a separate Pro extension shell, disabled gracefully when Free is missing.
7. Run lint/static analysis/tests/build. Fix problems until passing or record precise blockers. Commit coherent, tested increments when Git permissions allow, with useful commit messages.
8. Update `.ai/state.json`, `/BACKLOG.md`, `START_HERE.md`, and an append-only work log **before every session/context boundary**. Select and start the next unblocked task rather than merely saying "continue".

**Mandatory initial user-facing output:** concise factual startup report: inspected state, first tasks selected, files changed, verification performed, tests run with results, blockers, and next automatic task. Never report completed implementation unless files exist and supporting evidence is available. If the platform cannot autonomously continue without another invocation, do not falsely claim unattended execution; leave an exact resume instruction in `START_HERE.md`.

**One-line permanent mission:** Build two production-grade, independently distributable WooCommerce plugins that enable merchants to reconcile offline bank transfers against local statement imports safely, explainably, auditably, and without a required third-party bank API.

---

## 1. HARD RULES — NON-NEGOTIABLE

### 1.1 Autonomous engineering behavior

- **No routine human consultancy:** Do not ask the user which table to create, what field to put on a screen, how an admin page should look, which validation to use, or whether to proceed to the next phase. This prompt is the delegated authority for routine engineering choices.
- **No confirmation theater:** Do not stop after drafts, wireframes, skeletons, or partial implementations to request aesthetic or workflow approval. Build, inspect, test, correct, and move to the next acceptance criterion.
- **No endless planning:** After initial research, every normal cycle must produce an implementation, test, fixture, documentation, or verified defect fix. Plans are not the deliverable.
- **Self-correction:** Detect errors, trace root causes, write regression tests, fix, rerun relevant tests, and document why the error happened.
- **Progress persistence:** All work survives context/session loss through repository-backed state. Never rely solely on conversational memory.
- **No fabricated success:** "Passed", "working", "released", "screenshot reviewed", "compatible" and "fully tested" require evidence. Mark untested claims explicitly.
- **Scope ownership:** All features, UI screens, states, business rules, security controls, automated tests, and release artifacts specified here are in scope unless explicitly deferred to a named version with a written rationale.
- **No hidden scope cuts:** If a requirement conflicts with a platform/security constraint, document the conflict in an ADR and implement the closest secure behavior without asking a routine question.
- **No AI runtime dependency:** The merchant-facing plugin must function normally without AI, cloud LLMs, paid AI API subscriptions, or network-based matching services. "AI-native" describes **how the plugin is developed**, not a hidden cloud service.
- **Tool honesty:** An AI agent can only execute actions available through its actual tools, credentials, permissions, and runtime. Do not claim to work in the background after the runner stops.

### 1.2 Financial correctness and safety

- A **customer-submitted payment claim or receipt is not bank evidence**. Never mark an order paid based solely on a receipt, screenshot, customer-entered transaction ID, name match, fuzzy score, or unverified bank-feed entry.
- An imported statement is **merchant-supplied evidence**, not live bank confirmation. Show its source, account, import batch, transaction uniqueness, and verification state. Import alone does not guarantee a cleared deposit.
- Default workflow: **suggest → human merchant/authorized finance-staff review → approve → reconcile → update WooCommerce order**. No auto-confirmation in the default Free or Pro experience.
- Never submit or initiate a bank transfer, payout, refund, chargeback, or external money movement. This product tracks and reconciles historical financial records; it is not a payment gateway or bank transfer initiator.
- Never mark partially paid orders fully paid; never over-allocate a deposit; never assign the same bank credit twice; never silently convert currencies; never silently offset a bank reversal or fee against a credit.
- Use one explicit **reconciliation approval** with permissions, transaction integrity, immutable/auditable event log, balance checks, duplicate checks, and correct WooCommerce payment lifecycle semantics.
- When evidence is insufficient, classify as **review required**, preserve the order's correct existing payment state, and explain the reason.

### 1.3 Security, privacy, policy and operational constraints

- Follow WordPress.org plugin guidelines, GPL-compatible licensing, WC integration practices, data privacy requirements, accessibility, and dependency security. Do not embed remotely executed code or install Pro from the Free plugin through prohibited mechanisms.
- Never upload real bank statements, receipts, order data, customer PII, secrets, license credentials, or bank details to AI services or external telemetry without explicit authorization. Use synthetic fixtures in tests and examples.
- Never hardcode account credentials, API keys, license keys, or customer identifiers.
- Customer files must be private, access-controlled, validated, bounded, malware-aware where feasible, and inaccessible by public URL guessing.
- Use nonce and capability checks for administrative mutations; nonces alone are not authorization. Authenticate customer-facing claims using order permissions or secure purpose-scoped tokens with expiry and rate limits.
- Production deployments, destructive changes, public releases, and submissions requiring credentials remain **external authorization boundaries**. Do not invent consent. Prepare artifacts and continue all other safe tasks; document only the specific blocker.
- **Preserve existing work:** do not delete existing repository history or overwrite untracked data; create branches and backups as appropriate. No `git reset --hard`, secret exposure, or database destructive resets outside explicitly disposable test environments.

---

## 2. PRODUCT DEFINITION, USERS AND SCOPE

### 2.1 Problem

WooCommerce's Direct Bank Transfer (BACS) orders usually remain **On hold** while the merchant manually checks deposits. Busy stores need a safe way to locate their deposits, distinguish duplicate and partial payments, reconcile bank credits to orders, and preserve an approval trail. Current plugins may handle receipt uploads or CSV matching separately; this product combines the full workflow.

### 2.2 Audiences and personas

1. **Store owner:** checks what is unpaid, sees high-level KPIs, approves single payments, controls defaults, and receives actionable alerts.
2. **Finance manager:** imports statements, investigates bank differences, approves staff reconciliations, closes periods, and exports reports.
3. **Finance operator:** handles initial review and matching suggestions, records claims, proposes allocations, and resolves routine issues within granted permissions.
4. **Customer:** sees correct bank instructions/reference, optionally submits a payment claim/receipt, and checks claim progress without obtaining access to bank data.
5. **Agency/admin:** configures multiple client sites, compatibility, backups, migrations, licensing, and diagnostics.
6. **Auditor/read-only reviewer:** views allocations, source/import history and decision trail without the ability to change ledger state.

### 2.3 Scope boundaries

**In scope:** WooCommerce native BACS, explicitly configured additional offline payment method IDs, reference generation, customer claim capture, manual ledger records, CSV/XML statement ingestion in Pro, deterministic match recommendations, exceptions, controlled allocations, reporting, audits, and exports.

**Out of scope for v1:** bank APIs, real-time bank connectivity, open banking, automatically initiating payments/refunds, posting to general ledgers, automatic tax accounting, AI-based payment verification, OCR-dependent matching, arbitrary PDF statement parsing, universal gateway reconciliation, SaaS infrastructure, and unsupported FX assumptions. Build extensibility contracts for some of these future possibilities without pretending to ship them.

### 2.4 Success criteria

- An ordinary BACS customer can complete checkout, see/copy a unique reference, and submit an optional proof claim.
- A merchant can process and reconcile hundreds of deposit rows without leaving the WooCommerce admin area.
- A valid full payment can be allocated to one order and marked paid **only after authorized approval**.
- Partial, split, overpaid, duplicate, reversed, ambiguous, stale and orphan payments are clearly tracked and never silently treated as complete payments.
- Free runs independently. Pro extends rather than replaces Free and gracefully handles dependency/license conditions.
- Re-importing identical or overlapping statements never produces duplicate ledger credits or duplicate paid-order transitions.
- UI coverage and state coverage are complete: every table, form, menu, field, modal, alert and empty state has defined meaning and a tested action.
- Functional packaged releases have sufficient automated and manual evidence; no fake green testing.

---

## 3. COMPETITOR RESEARCH REQUIREMENTS (PHASE 0)

Create `docs/research/competitors.md` and refresh actual public findings before finalizing product UX. Research at least these categories:

- Direct bank-transfer reconciliation (e.g. WooCommerce Payments BACS Extended and similar CSV/CAMT products).
- Bank-transfer reference/manual matching plugins (e.g. Kairos Labs-style solutions).
- Receipt uploads/approval plugins (e.g. Rusa Payment Verification, PeproDev, Kubelio, Direct Payments, local-market bank/wallet plugins).
- Broader payment settlement/reconciliation platforms, including WooPayments reconciliation reporting; differentiate payouts from offline BACS deposits.

For each verified competitor record: product name; URL; last verified date; active availability; pricing model if publicly confirmed; compatible WP/WC versions if claimed; actual source-supported features; supported file formats; onboarding steps; screenshot/UX findings if accessible; duplicate/partial/split behavior (mark **unknown** when not documented); security/dependency model; strengths; differentiators; gaps proven by evidence vs assumptions. Never mark a feature as absent merely because a marketing page omits it. No copying proprietary code, UI assets, names, text, or styling. Use feature parity to inform an original implementation.

Research deliverables: `competitor-matrix.md`, `workflow-benchmark.md`, `positioning.md`, `sources.md`, `risks.md`, each with links, date, verified/unknown status. Keep competitor research separate from product requirements so unknown competitor details do not change mandatory specifications.

---

## 4. FREE AND PRO — TWO SEPARATE REAL PLUGINS

### 4.1 Distribution architecture

**Free:** `/plugins/wpessential-bank-transfer-matcher/` — complete, usable WordPress.org-targeted plugin. It contains shared public domain types, manual verification/claims services, settings, basic dashboards, BACS presentation, permissions, REST endpoints, uninstall/migration base, and Pro extension interfaces. It must load and work with Pro absent.

**Pro:** `/plugins/wpessential-bank-transfer-matcher-pro/` — separate installable ZIP, bootstrap, version, plugin headers, namespaced classes, assets, license/update settings, and Pro-only features. Its runtime requires Free at a compatible version. Pro is **not** just a license flag unlocking buried premium functionality inside the Free package.

**Dependency rules:** Pro detects Free activation/version through stable public API; no fatal errors on activation order; no duplicate class bootstraps; no conflicting autoloaders; show a scoped actionable admin notice if Free is missing/outdated; avoid running Pro jobs until valid Free dependency is present. Make data read-only/safe when Pro is deactivated; never delete Pro data simply because a license expires. Free uninstall must not silently erase installed Pro records. Provide deliberate data-retention and full removal options with permissions and confirmation.

**Licensing rules:** Keep license validation isolated behind a `LicenseService` interface. No licensing network calls are required for Free. Pro continues to present existing financial records and allow secure access/export if license lapses; define and clearly display which premium processing actions are restricted, without holding customers' financial data hostage. No fake license validator or hardcoded "always valid" in production. Tests use mocks, not real customer keys. Pro update server integration is opt-in/configurable and documented; do not ship remote executable code through Free.

### 4.2 FREE — complete included feature list

1. BACS payment method detector (native `bacs`), support multiple configured offline methods as opt-in manual providers where compatibility is verified.
2. Configurable unique order payment reference: safe prefix/suffix, collision-resistant opaque segment, immutable once issued unless controlled rotation; support legacy orders and order-number plugins without using visible order numbers as unique identifier.
3. Bank transfer instructions integrated with Thank You/Order Received, My Account/View Order, and supported transactional email contexts. Respect native WooCommerce bank details; never replace/modify merchant BACS account configuration unexpectedly.
4. Copy reference action and accessible confirmation feedback; safe localization and RTL.
5. Customer payment claim: declared bank transfer date, transfer amount/currency, transaction reference, optional sender name, customer note, optional permitted attachment; duplicate/rate-limit validation. Claims explicitly say "not yet verified".
6. Guest claim flow with verified order access token/order key or emailed secure purpose-scoped link; no public order enumeration.
7. Manual verification workspace: search by order/reference/status; record merchant-verified bank evidence source, transfer ID/reference, amount, date, account identifier, notes, attachments/reference (as permitted), reviewer and timestamp.
8. Basic manual ledger: distinct evidence records, allocation to order, full-payment checks, manual approval, and immutable activity/audit entry.
9. Pending and on-hold order overview, overdue filter, exact/order reference lookup, basic stats, basic filtering, activity timeline.
10. WooCommerce order admin meta-panel/integration; view internal balance/payment claim states, route to manual verification; HPOS-safe CRUD.
11. Email templates for claim received and verified/declined (merchant setting controls customer notifications; respect WooCommerce email conventions).
12. Basic settings: reference format, instruction placement, retention, supported offline methods, customer claim toggle, upload limits, manual roles, notifications, privacy/uninstall behavior.
13. Core hooks/API, safe admin diagnostics and export of user-associated personal data where applicable.
14. Accessible responsive **desktop-first** admin UI: native WP style and clear table bulk controls; no unsupported mobile-specific redesign requirement for v1, but must remain usable on narrow screens.

### 4.3 PRO — complete extension feature list

1. Bank statement import wizard: CSV first, CAMT.053 XML later **before release if verified test coverage is achieved**; bank-mapping profiles; tolerant locale date/number handling without heuristic silent acceptance.
2. File and row preflight, dry-run, visible data preview, account mapping, errors/warnings, idempotent commit, import history and replay-safe processing.
3. Deterministic, explainable bank transaction to order candidate matching (unique reference, currency, receiving account, amount, date/date range, customer claim as secondary hint).
4. Duplicates: file duplicate warning, row transaction-ID duplicate, overlapping exports, collision review, ambiguous legitimate repeats, account-specific canonical fingerprints.
5. Review queue: unrecognized credit, ambiguous order, partial payment, overpayment, duplicate suspect, debit/reversal, currency mismatch, old/cancelled/refunded order, reference errors, bank-fee variance, unsupported statement fields, claim conflicts.
6. Reconciliation allocation ledger: one credit → one order; many credits → one order; one credit → multiple orders; split amounts; corrections through reversal and new audit events, not silent edits.
7. Partial payments, outstanding balances, overpayment/unallocated credits, exception aging, custom resolutions, saved review notes, assignments, review statuses.
8. Bank account and import-source management (bank name, nickname, masked account/IBAN last digits, currency, import profile, enabled state; never display full secrets when not necessary).
9. Team permissions (viewer/operator/approver/admin), optional maker-checker two-person approval, assignment, audit reasons and approval timestamps.
10. Advanced reconciliation dashboard, period filters, bank-account summaries, remaining balances, aging, unmatched credits, claim discrepancies, performance metrics.
11. Report exports (CSV with formula injection guard), searchable report history, stored filters, printable summary (optional PDF only when appropriate and tested), audit exports.
12. Batch job management via Action Scheduler: progress, pause/resume where safe, retry, cancel uncommitted jobs, row failure list; bounded memory and runtime.
13. Template CSV mappings and optional CAMT.053 support; add MT940/OFX/CAMT.054 to future work unless fully specified and verified.
14. Notifications: exception notifications, pending review digests, claim/statement mismatch and reminders with opt-in controls and rate-limited delivery.
15. Pro settings, license status/help, data retention, advanced logs, diagnostics, backup/restore considerations and compatibility warnings.

### 4.4 Feature governance

`docs/product/feature-matrix.md` must have fields: `feature_id`, `name`, `tier`, `owner_package`, `UI_screen`, `data_model`, `API_endpoint`, `permissions`, `business_rules`, `tests`, `documentation`, `phase`, `status`, `evidence`. Every UI control and service must trace to at least one feature ID. Free must not become unusable when Pro is absent. Do not move an announced Free feature to Pro without an ADR.

---

## 5. FINANCIAL DOMAIN MODEL — CANONICAL DEFINITIONS

Define unambiguous vocabulary in `docs/architecture/glossary.md`:

- **WooCommerce Order:** commercial request with order total, refunds, currency, gateway, order statuses.
- **Expected Balance:** remaining amount owed according to explicit supported policy; do not recalculate blindly from current WooCommerce order total after finance approvals without reconciliation safeguards.
- **Payment Reference:** unique opaque identifier assigned to an order, displayed to the customer; preserve the original raw bank reference separately from normalized tokens.
- **Bank Account:** merchant's receiving destination, never automatically assumed from customer sender details.
- **Import Batch:** statement source, upload metadata, hash, bank account, mapping, period, parse stats, validation outcome and state.
- **Bank Transaction:** immutable source transaction row (credit/debit), amount, currency, booking/value date, reference, bank transaction ID if present, canonical fingerprint, source batch, dedup/reversal state.
- **Customer Claim:** unverified customer assertion plus optional proof and its approval/rejection lifecycle.
- **Manual Evidence Record:** finance reviewer-entered evidence of a bank transfer, with provenance clearly distinguished from imported bank statement rows.
- **Match Candidate:** non-binding suggested relationship between transaction and order, with explicit reasons and confidence CATEGORY, not financial probability.
- **Allocation:** controlled monetary link between an eligible credit and an order, with amount in minor units and source provenance; reversal also audited.
- **Review Case:** exception record with type, severity, assignee, resolution, comments and history.
- **Reconciliation Approval:** authorized event authorizing allocation and, when balance is fully covered, an eligible WooCommerce payment completion transition.
- **Audit Event:** append-only meaningful record of who/what/when/change/reason/before-after references. Never edit a historic audit event silently.

**Money rules:** Store currency code and integer minor units or an equally safe exact decimal implementation with explicit currency exponent. Never use floats for financial arithmetic. Validate zero-decimal and multi-decimal currencies and negative entries. Currency conversion is prohibited by default; a future FX module would require explicit rate/date/source/audit. Avoid double counting refunds and reversals.

**State machines:**

- Claim: `submitted → under_review → verified | rejected | withdrawn`; claims alone cannot mark WooCommerce order paid.
- Batch: `uploaded → parsing → preview_ready → validating → committing → imported | partially_imported | failed | cancelled`; an immutable committed batch must not be silently reprocessed as new money.
- Transaction: `new → suggested | needs_review → allocated | partially_allocated | unallocated | duplicate_suspect | reversed`; track immutable source event separately from mutable derived status.
- Review case: `open → assigned → investigating → awaiting_approval → resolved | dismissed | reopened` with permission checks.
- Allocation: `proposed → approved → committed → reversed` where reversal creates linked audit event and balance recomputation.
- Order internal payment state: `unclaimed | claimed_unverified | partial | fully_reconciled | exception`; WooCommerce actual order status remains authoritative for fulfillment status; do not invent custom WooCommerce order statuses unless demonstrably required.

**Invariants:**

1. Sum of active allocations from one credit ≤ eligible credit amount.
2. Sum of active approved allocations to an order ≤ expected balance unless separately tracked overpayment exception.
3. No two active approvals may claim the same unique bank transaction twice.
4. Paid transition only after full verified reconciliation and authorized approval, with idempotency guard and lock/transaction strategy.
5. Reversing a confirmed allocation must never silently overwrite a shipped/completed order; create an exception and require a deliberate merchant resolution for downstream WooCommerce status/refund/accounting impacts.
6. A bank debit is not automatically a customer refund, and a refund in WooCommerce is not automatically a bank debit; keep distinct until reconciled.
7. Normalization must not destroy raw evidence: retain raw row plus canonical parsed representation subject to retention rules.

---

## 6. TECHNICAL ARCHITECTURE — FREE CORE VS PRO EXTENSIONS

### 6.1 Repository layout (required target)

```text
/README.md
/START_HERE.md
/BACKLOG.md
/CHANGELOG.md
/.ai/
  state.json
  next-task.json
  decisions.jsonl
  sessions/
  handoffs/
  agent-prompts/
  runbooks/
/docs/
  product/  research/  architecture/  ux/  security/
  testing/  operations/  api/  release/  user/
/fixtures/
  statements/  orders/  expected-outcomes/
/tools/
  scripts/  release/  checks/
/plugins/
  wpessential-bank-transfer-matcher/
    wpessential-bank-transfer-matcher.php
    composer.json
    composer.lock
    vendor/                    # generated production autoload + runtime deps in ZIP
    README.md                  # generated
    src/
      Bootstrap/  Domain/  Application/  Infrastructure/
      WooCommerce/  Admin/  Customer/  REST/  Privacy/
    assets/  templates/  languages/  tests/  uninstall.php
    readme.txt
  wpessential-bank-transfer-matcher-pro/
    wpessential-bank-transfer-matcher-pro.php
    composer.json
    composer.lock
    vendor/                    # generated production autoload + runtime deps in ZIP
    README.md                  # generated
    src/
      Bootstrap/  Import/  Matching/  Reconciliation/
      Exceptions/  Reports/  Licensing/  Admin/  REST/
    assets/  templates/  languages/  tests/  uninstall.php
/playground/
  blueprints/base/blueprint.json
  blueprints/free/blueprint.json
  blueprints/pro/blueprint.json
  README.md
/scripts/
  build_playground_bundles.py
  generate-readmes.ts
/docs/source/
  product-metadata.json
  feature-catalog.json
  changelog/
/.github/workflows/
  php-ci.yml  frontend-ci.yml  integration.yml  e2e.yml  release.yml
```

Adapt to an existing repository's established conventions if a strong reason exists; document deviations. Do not introduce unnecessary frameworks.

### 6.2 Backend and boundaries

- PHP with **required independent Composer 2/PSR-4 autoloading in Free and Pro**, strict interface boundaries and dependency injection where practical. The shipped ZIPs MUST include each plugin's compiled optimized `vendor/autoload.php` and runtime dependencies; merchants MUST NOT need Composer on the server. See Section 16A.
- **Free owns** stable domain contracts, permissions, claim tracking, manual evidence services, reference service, WooCommerce integration, minimal data migration service and extensibility event/API contracts.
- **Pro owns** importer, matching, allocation extensions, exception service, reporting, bank mapping and Pro-specific tables/migrations. Avoid circular dependencies and reaching into private Free internals.
- WooCommerce orders must use WooCommerce CRUD APIs (`wc_get_order`, `WC_Order_Query`, setters, `save()`, etc.) rather than direct `wp_posts` order manipulation. Declare HPOS compatibility only after verification.
- Reconciliation tables use WordPress database prefix and deliberate indexed schema design, migrations and tested upgrade/rollback paths. Order IDs are references, not WordPress post foreign key constraints.
- Financial allocation writes use a transaction-safe approach compatible with supported MySQL/MariaDB engines plus idempotency keys and locks; if transactional guarantees are unavailable, fail closed with actionable diagnostics rather than risk double allocation.
- Import processing uses stream parsing, chunking, resumable checkpoints, retention-aware quarantine, and Action Scheduler. Support failure recovery without duplicate writes.
- Expose versioned service interfaces/hooks and permission-protected REST APIs; sanitize/validate **inputs**, escape **outputs**, parameterize SQL, avoid unbounded pagination.
- Use WP native settings, notices, components and enqueueing; frontend bundles are local. Avoid global CSS selectors/JS polluting other admin pages.

### 6.3 Suggested services/interfaces

```text
PaymentReferenceGeneratorInterface
OrderEligibilityPolicyInterface
BankEvidenceRepositoryInterface
CustomerClaimServiceInterface
BankStatementParserInterface
ColumnMappingServiceInterface
ImportBatchServiceInterface
TransactionFingerprintServiceInterface
MatchCandidateProviderInterface
MatchExplanationServiceInterface
AllocationLedgerInterface
ReconciliationPolicyInterface
ApprovalServiceInterface
ExceptionCaseServiceInterface
AuditTrailInterface
ReportQueryInterface
CapabilityPolicyInterface
LicenseServiceInterface
```

Every interface needs unit tests, error contracts and ownership. Integrations with unsupported offline gateways must be adapters behind an explicit compatibility registry, disabled by default.

### 6.4 Custom tables (logical; exact names/migrations may be refined)

```text
{prefix}wbm_payment_references          [Free]
{prefix}wbm_payment_claims              [Free]
{prefix}wbm_manual_evidence             [Free]
{prefix}wbm_audit_events                [Free, shared API]
{prefix}wbm_bank_accounts               [Pro]
{prefix}wbm_import_profiles             [Pro]
{prefix}wbm_import_batches              [Pro]
{prefix}wbm_bank_transactions           [Pro]
{prefix}wbm_match_candidates            [Pro]
{prefix}wbm_order_allocations           [Pro]
{prefix}wbm_review_cases                [Pro]
{prefix}wbm_report_exports              [Pro]
{prefix}wbm_license_metadata            [Pro; minimal secrets]
```

Schema specification must include per-table columns, types, indexes/unique constraints, foreign references, soft-deletion/reversal behaviors, PII classification, retention period, migration strategy, and example queries. Never store plaintext secrets in ordinary options. Consider Free manual allocations/ledger schema carefully so that Pro can reuse rather than duplicate it; a shared Free-owned allocation API/table is preferable if Free is capable of true verified partial/manual allocations. Record final decision in ADR. For large stores, index order lookup, batch lookup, transaction identity, account/date, status/assigned owner and amount currency.

---

## 7. IMPORTING AND MATCHING — DETAILED PROCESS

### 7.1 Supported file validation

CSV: upload via authorized admin; validate extension *and content*, size, MIME where meaningful, encoding, delimiter, quotation, line endings, BOM, header presence, line count, malformed quotes, required date/amount fields, supported decimal/grouping separators, debit/credit columns, signed amount columns, transaction ID, reference, account/currency, leading zero preservation, value/booking date, date timezone policy. All errors must identify row and column without exposing private full statement data in general logs.

CAMT.053 (Pro): implement through a secured XML parser with external entities disabled and strict structure/namespace/account reconciliation; only mark supported after verified real-world synthetic fixture coverage. No uncontrolled XML expansions. MT940/OFX/CAMT.054 are named future adapters; do not claim current support unless implemented and verified.

### 7.2 Import wizard (non-destructive)

1. Choose receiving bank account and source file.
2. Detect format, show sample parsed rows, choose/suggest bank profile.
3. Map required fields with examples and validation; preserve raw values.
4. Choose locale/timezone/currency with safe explicit defaults; show ambiguity warnings.
5. Run **dry run** producing counts: new, exact duplicates, suspected duplicates, invalid, credits, debits, ambiguous account, unmatched and potential orders. No real ledger mutations yet.
6. Confirm **import** (not payment approval), commit idempotently in bounded background jobs.
7. Show job progress, accepted/rejected rows, collisions, source hash, import account, dedup state, retry and batch history.
8. Prompt to open Match Review; importing never itself marks orders paid.

### 7.3 Duplicate strategy

Use bank-provided transaction IDs where unique **within bank account and documented scope**. If unavailable, compute normalized canonical fingerprints using account, currency, signed amount, booking date, reference and additional stable fields, while preserving raw row. Distinguish:
- exact repeat of same source transaction (block duplicate import);
- same file reimport (no new money; show existing import);
- overlapping file (accept unseen entries; skip confirmed repeats);
- same amount/date/sender but two legitimate payments (collision review, not automatic deletion);
- contradictory transaction IDs or changed postings (exception);
- bank-side reversals/corrections (separate events linked to originals).

All fingerprints are advisory unless their uniqueness scope is trustworthy. Concurrency tests must verify idempotency and lock correctness.

### 7.4 Matching policy (explainable and conservative)

Candidate generation order:
1. Valid exact unique payment reference + correct receiving account and currency.
2. Check eligible order state and outstanding balance.
3. Compare amount (exact, partial, over, combined potential), dates, and transaction uniqueness.
4. Secondary hints: customer claim's stated transfer reference/date/name, normalized payer text, order identifiers. These **never independently authorize approval**.
5. Confidence categories: `exact_candidate`, `strong_candidate`, `weak_candidate`, `ambiguous`, `blocked`, each carrying machine-readable reasons and human-readable explanations. No unexplained black-box percentage scores.
6. All candidates are reviewed/approved by authorized staff. A later opt-in automated confirmation feature is out of scope and requires distinct security specification, tests, settings and disclosures.

### 7.5 Allocation rules

- Single credit/single order: approve exact/partial amount.
- Multiple credits/one order: maintain transaction-level ledger and running balance, never mark paid before full settlement.
- Single credit/multiple orders: explicitly choose orders and amounts with live remaining balance preview; check sum ≤ credit.
- Overpaid: allocate only valid owed amount, keep unallocated remainder and review case; never generate fictitious refunds.
- Underpaid: record partial allocation and pending amount; keep order on hold.
- Refund/debit/reversal: separate linked investigations; do not mark bank debits as WooCommerce refunds automatically.
- Bank fee differences: allow recorded variance classification, but never silently change the order amount to fit an unexplained credit.
- Currency mismatch: prevent direct allocation without explicitly supported verified currency conversion policy (not included v1).
- Already paid/cancelled/refunded/shipped orders: block automatic status changes and route to finance review.
- Authorized full approval: call appropriate WooCommerce payment completion lifecycle only once when eligible and consistent; verify resulting status based on order type and WC behavior. Handle downstream failures with safe retry/compensation, not blind second completion.

### 7.6 Example scenarios to ship as fixtures

- Exact payment and unique reference, standard USD (or store currency).
- Reference wrong by one character; no auto approval.
- Two orders of identical amount; blank bank reference.
- Three payments totaling one order.
- One payment split across three orders.
- Overpayment $525 on $500 order.
- Valid payment referencing cancelled/refunded order.
- Duplicate import of same statement five times.
- Overlapping statements where source IDs are missing.
- Same-day same-amount legitimate distinct credits.
- Negative transaction and reversal.
- Decimal comma, thousands separator, UTF-8 BOM, leading-zero IDs, non-ASCII payer names.
- Zero-decimal currency and mismatched currency.
- Manual payment claim with no statement evidence.
- Claim forged with same reference as another order.
- Two reviewers concurrently approve same credit.
- Invoice total changes after partial payment.
- Order cancelled after partial allocation.
- One bad row among 50,000 rows.
- Remote user attempts to access receipt/private bank statement URL.

---
## 8. UI/UX DESIGN CONTRACT — DO NOT LEAVE ANY SCREEN TO GUESSWORK

### 8.1 Design principles

- The plugin must visually belong in **WordPress/WooCommerce wp-admin**. Use WordPress admin page patterns and `@wordpress/components` (when justified), a Gutenberg-compatible component language, system/native styling, restrained color palette, contextual notices, familiar tables, WP-like tabs, filters and screen hierarchy.
- No full-screen generic SaaS dashboard, neon gradients, random cards, excessive shadows, detached branding, complicated charts or ornamental UI that reduces readability.
- Use clear type hierarchy; 24–28px page title if aligned to WP environment; 14px standard UI body; 12–13px metadata; 32–40px only for high-value KPI numerals. Exact values may adjust for WP admin consistency.
- Neutral backgrounds (`#F6F7F7`-like admin gray), white surfaces, subtle `#DCDCDE` borders, restrained WordPress accent blue for navigation/actions, WC-appropriate state colors; color must never be sole status indicator.
- Compact but readable: tables should handle 100+ entries, sticky headers where appropriate, consistent sorting/filtering/pagination, row-level actions, accessible action menus, descriptive status pills, safe destructive confirmations.
- **Desktop-first** layouts at 1440px, 1280px and 1024px; at 768px controls stack and tables use accessible horizontal scroll; at 375px basic tasks remain possible. Do not allow critical actions offscreen/inaccessible.
- Reuse components across Free and Pro: `PageHeader`, `StatusBadge`, `SummaryStat`, `FilterToolbar`, `DataTable`, `EmptyState`, `DetailDrawer`, `ActivityTimeline`, `FormField`, `Notice`, `ConfirmDialog`, `PermissionGate`, `ImportProgress`, `CurrencyAmount`, `ReferenceCopy`, `BankAccountBadge`.
- RTL support, keyboard focus order, semantically labeled form controls, WCAG 2.2 AA target, minimum contrast, screen-reader announcements, no color-only meanings, 44px comfortable click target where possible.
- **Content before decoration:** every field needs purpose; every CTA has implemented behavior; every table row corresponds to real or clearly labeled fixture data, never a static dummy screenshot in production.

### 8.2 Mandatory deliverables per screen

For **every** screen listed below, UI Designer Agent must produce all of:

1. Unique screen ID (`UI-xxx`), name, tier, role/capability, exact route/menu parent.
2. Primary user job and success condition.
3. Desktop layout hierarchy with header, description, toolbar, filters, data area, secondary content, footer if relevant.
4. Exact form schema: field key, label, type, required/default, help text, placeholder, validation, permission, storage key, action.
5. Exact table schema: columns, data types, sortable/filterable flags, default sort, format rules, empty values, row actions, bulk actions, pagination.
6. Actual button/CTA behavior, API/service endpoint, confirmation requirement, success/failure feedback.
7. All screen states: first-run/no-data, loading, success, error, restricted permissions, read-only/dependency missing, long-running job, partial success, network retry; additional `not_supported` states where relevant.
8. Keyboard/ARIA design, responsive behavior and localization/RTL notes.
9. High-fidelity editable mockup using **available** design tools (e.g. Figma when connected), plus production implementation; if Figma access is missing, provide developer-ready HTML/React component mockups inside the repo and continue. Do not claim a Figma file was created if it was not.
10. Screenshot-based visual QA after real implementation: visual diff, legibility, overflow, clickability, consistency, tab navigation and error messages.
11. At least one integration/E2E test linking the visible action to a real endpoint and persisted state.

### 8.3 WordPress menu hierarchy

Top-level optional menu label: **Bank Transfer Matcher** (WP-style icon); prefer WooCommerce submenu integration if it improves discoverability and does not overload the admin. Do not duplicate pages in two places. Final deterministic plan:

```text
WooCommerce
└─ Bank Transfer Matcher
   ├─ Overview                     [Free]
   ├─ Bank Transfer Orders         [Free]
   ├─ Payment Claims               [Free]
   ├─ Manual Verification          [Free]
   ├─ Bank Transactions            [Pro]
   ├─ Import Statements            [Pro]
   ├─ Matching & Review Queue      [Pro]
   ├─ Allocations                  [Pro]
   ├─ Reports                      [Pro]
   ├─ Audit Log                    [Free base, Pro enhanced]
   ├─ Settings                     [Free tabs + Pro tabs]
   └─ Tools & Diagnostics          [Free + Pro]
```

When Pro is missing, Free shows only Free navigation; one restrained optional upgrade card in an appropriate Free context is allowed, no invasive site-wide notices. When Pro installed but Free missing, show just a dependency alert and disable Pro routes safely.

### 8.4 Screen UI-001 — OVERVIEW (Free base; Pro enhancements)

**Purpose:** Answer: What needs attention? How much outstanding? Which reviews can be processed now?

**Top header:** title `Bank Transfer Matcher`, subtitle `Verify offline transfers and keep payment records organized`; right toolbar `Refresh`, `Help`, Free button `Manual verification`, Pro button `Import statement`.

**KPI cards:** `On-hold bank-transfer orders` (count), `Outstanding balance` (sum by currency; DO NOT sum mismatched currencies), `Unverified customer claims` (count), `Recently verified` (count, chosen period); Pro cards `Imported unmatched credits`, `Ready-to-review matches`, `Open exceptions`, `Partial payments`. Each card has info tooltip, date period, relevant filter navigation and meaningful empty values. Do not display fictitious savings metrics.

**Controls:** period segmented filter `7 days / 30 days / 90 days / Custom`; bank-account filter (Pro), order gateway filter, currency filter, role-specific date range. Query params kept in URL. **Main layout:** two-column primary `Attention required` list (claim, age, order, amount, status, action) and secondary `Recent activity` (actor, time, event, link); a full-width `Orders requiring action` table below. Pro `Import health` module (last successful import, warnings, failed row count) and `Reconciliation summary` by currency.

**Empty state:** hero `No transfers need review` with a sentence and primary action `View bank-transfer orders` or `Import statement` (Pro). No fake data.

**Permissions:** store admins see all; finance viewer sees only allowed information; only authorized reviewers see approval CTA.

### 8.5 Screen UI-002 — BANK TRANSFER ORDERS (Free)

**Purpose:** locate all eligible orders without switching between WooCommerce screens.

**Toolbar:** search (order number, immutable payment reference, permitted customer fields); status filter (`On hold`, `Processing`, `Completed`, `Cancelled`, `Refunded`, `All`); payment-state filter (`Unclaimed`, `Claimed—unverified`, `Partial`, `Fully reconciled`, `Exception`); gateway; currency; order date range; outstanding minimum/maximum; clear filters; `Export visible` only with appropriate permission. Default filter: eligible on-hold orders, descending order date.

**Table columns:** selection checkbox (where bulk actions allowed), Order # (link), Date, Customer (privacy-filtered), Gateway, Reference (copy), Order Total, Verified Allocations, Outstanding, Claim Status, Reconciliation State, Woo Status, Actions. Monetary displays always carry currency. Sort by date, balance, number; paginate 20/50/100 (cap safely) and use server-side search. `—` for unknown fields, distinguish zero from missing.

**Row actions:** `View order`, `Copy reference`, `View claims`, `Verify manually` (authorized), `View activity`. Bulk: `Export selection` and `Assign reviewer` only if implemented and authorized; **no bulk mark-paid in Free**. Clicking row expands an order detail drawer with summary, payment reference, financial balance breakdown, claims, manual records, activity, and warnings; action buttons link to real forms.

**Empty:** `No orders match these filters`, clear filters; first-run: explain native BACS setup with link to WooCommerce payment settings. **Error:** show retry, no swallowed failures. **Acceptance:** filters actually affect query, reference copy works, clicking verify opens pre-filled correct order, HPOS safe.

### 8.6 Screen UI-003 — ORDER DETAIL / WC ORDER META PANEL (Free)

**Purpose:** complete payment context from native WC order edit and matcher order drawer.

**Sections:** header (Order ID, customer, WC status, payment state), balance strip (Total, Verified amount, Outstanding), immutable reference with copy, BACS receiving account display (masked per permission), chronological claim list, manual evidence list, Pro imported transaction allocations, audit timeline.

**Actions:** `View full reconciliation`, `Add manual verification` (permission + eligible order), `View claim`, `Copy payment instructions`, `Open WooCommerce order`. Show content instead of dead upgrade buttons when Pro absent. **Guardrail:** WC payment state differs from internal ledger → persistent warning and exception escalation, not silent auto-fix.

### 8.7 Screen UI-004 — PAYMENT CLAIMS (Free)

**Toolbar:** `All / Submitted / Under review / Verified / Rejected`, text search by claim ID, order reference, date range, attachments yes/no, currency, assigned reviewer if enabled.

**Table:** Claim ID, Order, Customer (masked), Claimed Amount, Currency, Declared Date, Customer Reference, Evidence Attachment (safe icon), Claim Status, Submitted At, Reviewer, Actions. Row `Review`, `View order`, `Download private attachment` (permission), `Reject` (with reason), `Link to manual verification` (does not itself verify). Bulk assigning allowed only if implemented, no bulk payment confirmation.

**Detail drawer:** display original claim, uploaded file rendered through authorized streaming endpoint, linked order, candidate bank records if Pro, prior attempts/duplicates, comments, status history. `Approve claim as verified` MUST be labeled as customer claim status and **must not mark WooCommerce order paid**; actual payment approval is a different action.

**Messages:** `Claim received. Payment has not yet been confirmed.`; `Claim reviewed, but no matching bank credit yet.`; `Declined—reason visible to customer only if configured.`

### 8.8 Screen UI-005 — MANUAL VERIFICATION (Free)

**Form full schema:**
- `order_id`: searchable order selector; required; prefilled from order context; server validates gateway/eligibility.
- `evidence_source`: select `Bank portal checked`, `Bank statement reviewed manually`, `Other documented bank evidence`; required; no `Customer screenshot` as sufficient bank evidence.
- `receiving_account`: select permitted configured account or safe manual account label; required as applicable.
- `payment_date`: date (bank posted/cleared date); required; sensible timezone display.
- `amount`: exact money input with localized display and canonical minor units; required >0; cannot silently exceed allocatable/order balance.
- `currency`: readonly from evidence/store where applicable; mismatch blocks and explains.
- `bank_transaction_reference`: text ≤ configurable safe length; optional if evidence source legitimately lacks one, but requires stronger explanatory note.
- `reviewer_notes`: textarea required if non-exact/override situation; max length and audit.
- `evidence_attachment`: optional secure documentation upload only; never public URL.
- `review_acknowledgement`: explicit checkbox: `I checked the merchant bank account and confirmed a cleared incoming credit.`

**Inline summary:** order amount, allocated total, outstanding, proposed allocation, remaining, reference, potential duplicate warnings. **Actions:** `Save draft`, `Submit for approval` (maker-checker enabled), `Verify & apply allocation` (only authorized; explicit confirm modal showing amount/currency/order/evidence and consequences), `Cancel`. If partial, do not transition WC to paid. If full, safe idempotent paid transition. Show success ID and audit link.

**Errors:** duplicate evidence, invalid amount, stale order balance, concurrent approval, permission, failed WC status update, unsupported currency. Never leave a half-applied ledger without recoverable state.

### 8.9 Screen UI-006 — CUSTOMER CHECKOUT, THANK YOU, MY ACCOUNT, EMAIL (Free)

**Checkout BACS context:** keep native WooCommerce gateway selector and payment semantics. If block checkout, register compatible extension point and test both Classic and Blocks as appropriate; do not modify other payment methods.

**Thank You:** payment instructions card: `Bank transfer instructions`, account nickname/bank name, masked or native displayed account details controlled by merchant settings, `Payment reference` in copyable mono text, order amount/currency, `Use this exact reference when transferring`, `I have sent the payment` action → secure claim form. Status message: `Your order is on hold until the bank transfer is confirmed.`

**My Account / Order:** order number/status, payment reference, current claim status, safe upload form if enabled, instructions and privacy disclosure. Show order-linked information only to rightful user/order-key holder. No UI that promises instant confirmation.

**Claim form fields:** `transfer_date` (required), `transfer_amount` (required, bounded), `currency` (order currency/read-only unless evidence explicitly differs, then no automatic match), `transfer_reference` (optional text, warning when absent), `sender_name` (optional), `receipt_file` (optional accepted types configured), `customer_note` (optional), acknowledgment `Submitting a receipt does not confirm payment`. Submit has CSRF protections, anti-spam rate limits and accessible status confirmation. Duplicate submits show existing claim rather than creating infinite new claims unless corrections are intentionally supported.

**Email templates:** order transfer reference/instructions for appropriate WooCommerce notifications, claim received, verified/declined, optional follow-up; avoid sending private statements or reviewer notes to customers. `Preview email` in admin settings uses synthetic data.

### 8.10 Screen UI-007 — IMPORT STATEMENTS (Pro)

**Step 1 Choose source:** bank-account selector (required), `Add bank account` modal if permission, file upload browse/drag-drop, `CSV / CAMT.053` format indicator, file size limits, last-import summary. Actions `Continue`, `Cancel`. Header displays `Bank details stay on this WordPress site` if true.

**Step 2 Map columns:** detected delimiter, character encoding, header row, first 10 sample rows (masked customer details in nonprivileged contexts), mapping table with selectors for `Transaction ID`, `Booking date` required, `Value date` optional, `Reference/Description` required where available, `Amount signed` OR `Credit amount / Debit amount`, `Currency` (column or fixed value), `Sender / Counterparty`, `Account`. Required fields flagged. Date format select (`YYYY-MM-DD`, `DD/MM/YYYY`, `MM/DD/YYYY`, custom validated), decimal style, timezone, amount sign convention, default transaction type. Buttons `Save mapping profile`, `Load profile`, `Back`, `Validate mapping`.

**Step 3 Dry-run:** summary cards `Rows detected`, `New credits`, `Debits`, `Exact duplicates`, `Possible duplicates`, `Invalid`, `Potential matches`; a virtualized preview table: row #, raw amount, parsed amount, date, masked reference, classification, reason, mapping errors. Tabs All/New/Duplicates/Invalid; `Download error rows` (protected), `Edit mapping`, `Commit import`. Warning: `Importing records does not confirm customer payments.`

**Step 4 Import progress:** per-job chunk counts, progress bar, elapsed/estimated only if measured, processed/new/skipped/failed, retry failed, cancel uncommitted work, leave page safely. Refresh persists actual status. Avoid endless spinner.

**Step 5 Result:** batch ID/source hash, account/currency, imported totals **per currency**, duplicates, errors, `View bank transactions`, `Review proposed matches`, `View import log`; downloadable redacted receipt/report. Permission gating across steps and URLs.

### 8.11 Screen UI-008 — BANK TRANSACTIONS (Pro)

**Toolbar:** source account, credit/debit, currency, date range, batch, allocation status, duplicate status, keyword/reference, amount min/max, transaction ID. `Import statement` primary. Default: recent credits, newest first.

**Table columns:** Transaction ID / Source ID, Date, Account, Debit/Credit, Amount + Currency, Bank Reference (masked preview), Allocated, Unallocated, Candidate Order(s), Reconciliation Status, Source Batch, Actions. Credit positive/debit distinct, accessible symbols and labels. Render 20/50/100 rows paginated server-side.

**Row detail:** immutable raw source fields, canonical normalized fields, provenance, original batch, duplicate/collision evidence, potential matches with reasons, current allocations and history. Actions `Review match`, `Allocate` (permission; eligible credit only), `Open exception`, `Link reversal`, `View batch`. Never offer delete of an imported committed transaction; use verified correction/reversal flow.

**Empty states:** no imports (CTA), filter no match (clear), all reconciled (positive but not gaudy). Errors: backend unavailable, partial import, locked transaction and unauthorized action.

### 8.12 Screen UI-009 — MATCHING & REVIEW QUEUE (Pro)

**Top tabs:** `All`, `Suggested`, `Needs review`, `Partial`, `Overpaid`, `Duplicate`, `Unmatched`, `Resolved`. **Controls:** risk/priority, assignee, bank account, currency, statement batch, age, search, sort by oldest/severity/amount. `Run matcher on unreviewed` is an authorized background analysis job only; never marks paid.

**List columns:** Case ID, Type, Severity, Transaction, Transaction Amount, Best Candidate Order, Amount Difference, Confidence Category, Explanation, Assignee, Age, Status, Actions. `Suggested` is not `Paid`. Bulk actions: assign, label, export; bulk payment approvals allowed **only** if product gets a separate audited specification and robust maker-checker safety review; default no bulk approval.

**Case workspace (main signature UI):** 3-column desktop layout—left `Bank evidence` (raw/normalized description, bank credit, account, date, source, duplicates), center `Order comparison` (candidate orders, order total, claimed paid, outstanding, reference, WC order status, claim proof), right `Decision` (why suggested, mismatches, required checks, editable proposed allocations, approval history). On smaller widths, sections stack and remain visible via tabs. Buttons `Allocate`, `Request second approval`, `Mark as non-order credit`, `Hold for investigation`, `Add note`, `Assign`, `Resolve`, `Reject suggestion`. Each requires appropriate capability and reasons where applicable.

**Required reason codes:** `reference_exact`, `reference_missing`, `amount_exact`, `amount_partial`, `amount_over`, `currency_mismatch`, `bank_account_mismatch`, `already_paid`, `order_cancelled`, `order_refunded`, `duplicate_exact`, `duplicate_possible`, `reversal`, `claim_only`, `no_candidate`, `multiple_candidates`, `manual_override`. UI translates codes into plain English; never show only cryptic status codes.

### 8.13 Screen UI-010 — ALLOCATIONS / RECONCILIATION LEDGER (Pro)

**Two views:** `By transaction` and `By order`. Filter: date, account, currency, allocation status, approver, order, batch, reversal. Table: Allocation ID, Bank Transaction, Order, Allocated Amount/Currency, Verified Source, Proposed By, Approved By, Approved At, Status, Remaining Balance, Actions.

**Create allocation drawer:** choose eligible credit, choose one or multiple orders, per-order allocation input, running totals, unallocated credit and order outstanding previews, conflict warnings, `Propose`, `Submit for approval`, `Approve and commit` (only permitted). Show reason/provenance, make stale data revalidation mandatory before commit.

**Reverse allocation:** privileged action with reason, confirmation summarizing downstream effects; create linked correction record; do not quietly change shipped/refunded WC statuses. Display immutable timeline and resulting exception.

### 8.14 Screen UI-011 — REPORTS (Pro)

**Subtabs:** `Reconciliation summary`, `Outstanding orders`, `Unmatched credits`, `Partial payments`, `Exception aging`, `Import performance`, `Audit exports`.

**Global toolbar:** date period (posting date vs approval date explicitly selectable), bank account, currency, order gateway, operator, source batch; `Apply filters`, `Save view`, `Export CSV`, `Print` where implemented. Never add balances in distinct currencies into one meaningless total.

**Report-specific content:**
- Summary: payments verified, full/partial allocations, outstanding, unmatched credits, exception count; per-currency grouping; reconciliation rate definition documented.
- Outstanding: Order, date, customer (permission-dependent), total, allocated, remaining, age, claim, action.
- Unmatched: transaction, account, currency, amount, date, reference, reason, review link.
- Partial: order, total, allocations count, verified amount, remaining, due age.
- Exceptions: cases by type/age/assignee/status with drilldowns.
- Import performance: batch, rows, failures, duplicates, duration, mapping profile, operator.
- Audit: event types, actor, timestamp, entity ID, reason, export constraints.

**Exports:** exactly filtered data, export timestamp, selection summary, safe filename, CSV formula injection protection, strict permission; asynchronous for large reports; email no attachments by default.

### 8.15 Screen UI-012 — AUDIT LOG (Free basic / Pro enhanced)

**Toolbar:** actor, event type, entity type, order/transaction ID, date, success/failure, source module. **Columns:** event ID, timestamp with timezone, actor (user or system job), action, entity, before/after summary (redacted), reason, linked order/batch, view detail.

**Detail:** immutable event ID, actor/capability, timestamp, affected entity, recorded changes, correlation ID/import job, source of evidence, security/privacy redactions. If log integrity check detects abnormality, visible admin warning with diagnostic guidance. No edit/delete event action in normal UI. Retention/legal privacy policies must be disclosed, with safe archive/delete rules where allowed.

### 8.16 Screen UI-013 — SETTINGS (Free tabs + Pro tabs)

Use WordPress-style tabs, field labels, help text, validation and success notices. Group related options; never dump 100 controls into one page.

**Tab A General [Free]:** `Enable reference generation` (default on for native BACS), `Supported gateways` (BACS checked by default; additional recognized offline gateway IDs opt-in), `Reference prefix` (default `WBM`, A–Z/0–9/- safe), `Reference pattern` (immutable opaque suffix; format preview), `Show reference on order received` (on), `Show in My Account` (on), `Show in emails` (on), `Allow legacy order reference generation` (off until intentional backfill), `Reference regeneration` restricted tool with reason/preview.

**Tab B Customer claims [Free]:** `Enable claims` (on), `Allow guest claims` (on only with secure order access), `Require transfer date` (on), `Require amount` (on), `Allow receipt upload` (off initially until storage configured), `Allowed formats` (e.g. PDF/JPG/PNG subject to server and security validation), `Max upload MB` conservative default, `Rate limit`, `Allow resubmission` (off; controlled corrections), `Display claim notice` (on), `Customer claim privacy notice` editable sanitized text.

**Tab C Bank instructions [Free]:** `Use WooCommerce BACS account details` (on), `Additional instructional note` (sanitized rich text), `Reference explanation`, `Confirmation button label`, `Email instruction inclusion`, `Hide/Show account details based on WC settings`; WC settings link. No duplicate editable secret bank-account storage if native WC is authoritative.

**Tab D Manual verification [Free]:** `Require evidence type` (on), `Require reviewer notes for mismatches` (on), `Require approval acknowledgment` (on), `Partial manual allocations` (on if implemented in core), `Allowed verifier roles` (administrator/shop_manager plus custom finance capability), `Maker-checker workflow` visible as Pro feature (not a fake Free toggle), `Duplicate evidence warning` (on and non-disableable for safety).

**Tab E Notifications [Free + Pro]:** claim received email (on), customer confirmed email (follows WC convention), rejected claim email (merchant opt-in), exception digest (Pro), reminder cadence (Pro; bounded, no spam), recipient roles, template previews, test email action only when explicitly clicked.

**Tab F Data & Privacy [Free + Pro]:** `Data retention for customer proof` (default documented), `Statement retention` (Pro), `Audit retention` (disclose legal relevance), `Remove plugin data on uninstall` (off by default; separate deliberate confirmation), private attachment cleanup, export/delete user data integration, redaction mode.

**Tab G Bank accounts [Pro]:** table `Nickname`, `Bank`, `Masked number`, `Currency`, `Status`, `Mapping profile`, `Last import`, Actions. Add/edit form `Nickname`, `Bank name`, `Currency`, `Last 4 / safe identifier`, `Bank account identifier` (store minimum necessary, masked), `Default import mapping`, `Active`, `Notes`; prevent account deletion with linked transactions, allow archive.

**Tab H Matching rules [Pro]:** enable/disable safe secondary hints (default strict), permitted date-window for candidate display (not approval), max candidate count, matching priority references over names, duplicate warning strict (non-disableable), `Auto-approve payments` **not present in v1**, warning threshold, partial candidate support, account consistency mandatory, currency consistency mandatory.

**Tab I Import & performance [Pro]:** batch size adjustable within safe tested range, maximum upload, encoding defaults, default date format/locale, CSV retention, background processing health, mapping templates, failed job retry policy, parse timeout; show environment advisory and effective server limits.

**Tab J Team & approvals [Pro]:** finance viewer/operator/approver capabilities, maker-checker toggle, mandatory reason codes for overrides, second approval threshold (if supported, default none but configurable), review assignment defaults, lock conflict policy. Changes themselves audited.

**Tab K License & updates [Pro]:** license input (masked), status, expiry/renewal, site activation count if service provides it, `Activate`, `Deactivate`, `Check status`, update channel info, privacy disclosure, network failure status; do not treat network error as invalidity without thoughtful grace behavior.

Each setting must have documented default, persistence key, validation schema, access capability, side effects, migration behavior, help text and at least one test.

### 8.17 Screen UI-014 — TOOLS & DIAGNOSTICS (Free + Pro)

**Cards:** environment version matrix (PHP/WP/WC, DB engine, HPOS, Blocks), Action Scheduler status, database migrations/schema version, plugin packages/versions, server upload limits, cron health, permissions, private file storage health, REST route reachability. `Copy diagnostics` must redact PII/secrets/account details. **Actions:** `Run read-only health check`, `Rebuild safe derived counts`, `Validate schema`, `Check compatibility`, `Preview reference backfill`, `Export redacted debug report`, Pro `Retry failed import job`. Every repair with side effects requires clear confirmation/permissions/backups, even though the AI development process doesn't need routine human signoffs.

### 8.18 UI state and interaction specification template (apply to EVERY control)

```yaml
screen_id: UI-XXX
route: wp-admin/...  # exact route
tier: free|pro|shared
capability: wbm_view|wbm_review_claim|wbm_import|wbm_approve|...
user_goal: precise outcome
layout: header -> filters -> list/detail -> action area
controls:
  - id: stable_id
    label: visible label
    type: select|text|date|currency|checkbox|button|table|modal|...
    default: exact default
    required: true|false
    validation: rules and messages
    permissions: capability
    action: service/API invoked
    success_feedback: exact behavior
    error_feedback: exact behavior
states: [initial, loading, empty, filtered_empty, success, validation_error,
         service_error, partial_success, permission_denied, dependency_missing]
responsive: desktop/tablet/narrow rules
accessibility: keyboard labels focus order announcements
telemetry: local audit only if warranted; no unsolicited external analytics
tests: [unit/integration/e2e references]
```

**UI implementation rule:** A mockup is accepted only when every visible control matches one defined action/state and the React implementation follows the approved schema. Do not ship decorative non-functional buttons, mock data left in production, broken search controls, missing pagination, or labels that promise unavailable behavior.

---

## 9. UX DESIGN WORKSTREAM AND VISUAL QA PIPELINE

The UI Designer Agent must work in parallel with domain/backend implementation when a multi-agent runner is available; use separate source/branches or change boundaries to avoid simultaneous edits to the same file. If only a single agent exists, execute these roles sequentially.

**Required workflow per screen:** requirements → schema → information hierarchy → desktop wireframe → high-fidelity WP-native view → reusable component inventory → implemented page → screenshot at 1440 and 1024 → interaction/E2E test → accessibility check → defect cleanup → UX acceptance evidence.

**Visual QA rubric** (score each 0–4, with reasons and screenshots):
1. WordPress/WooCommerce native visual consistency.
2. Clear hierarchy and primary task discoverability.
3. Complete feature/field coverage.
4. Accurate financial status meaning.
5. Forms/tables responsive and no overflow.
6. Accessibility/keyboard/navigation.
7. Real functionality and comprehensible error states.
8. Empty/first-run/onboarding completeness.

Release gate: no screen scores below 3, no broken critical actions, no missing required fields. Fix before marking UI complete. Use recorded visual review artifacts; do not say "looks great" as evidence.

**When Figma is available:** create editable components using native frames, auto-layout, typography styles, variants (default/hover/focus/disabled/error), and all screens above; store Figma file URL and frames in `docs/ux/figma-index.md`. Avoid replacing coding with only Figma deliverables. **When unavailable:** use Storybook or local React component preview/screenshots; track Figma-specific output as environment-blocked rather than preventing product implementation.

---
## 10. AI-NATIVE DEVELOPMENT SYSTEM — ROLES, RULES AND AUTONOMOUS LOOP

### 10.1 Role-based agent architecture

Use specialized agents **if the actual runner supports delegation**; otherwise a single capable agent executes each role as an explicit sequential stage and records handoffs. Never pretend parallel agents are running if they are not.

**Agent A — Autonomous Product Director / Orchestrator**
- Owns task graph, sprintless continuous execution, scope traceability, blockers, priority, handoffs and evidence review.
- MUST use `START_HERE.md`, `.ai/state.json` and `BACKLOG.md` as authority; pick highest-value unblocked task rather than selecting attractive/easy tasks randomly.
- MUST not mark a task done based only on a subordinate's claim. Validate tests/changed files/acceptance evidence.
- MUST not ask routine decisions of humans. When ambiguity arises, compare product invariants/official standards; choose safe simplest approach, record ADR and proceed.
- Cannot self-authorize live merchant money movement, secrets, irreversible deployment or publication.

**Agent B — Research & Competitor Intelligence**
- Owns official source verification, competitor matrix, current standards and evidence grades (`verified`, `likely`, `unknown`).
- Separates public claims from tests and never asserts competitor feature absence without evidence.
- Provides actionable feature/design implications, source URLs and timestamps, never plagiarizes.
- Does not block engineering on missing current pricing; mark uncertain and proceed.

**Agent C — Solutions Architect / Database Designer**
- Owns ADRs, module interfaces, Free/Pro boundaries, data model, money precision, lifecycle, migration and WooCommerce CRUD correctness.
- Reviews domain invariants and schema consistency before implementations modify ledger state.
- Rejects architecture violating idempotency, provenance, or separation of Free and Pro.
- Produces file/class/interface maps and executable schema migration tests.

**Agent D — WordPress/WooCommerce Free Backend Engineer**
- Implements independent Free bootstrap, references, BACS integration, customer claims, private files, manual verification, basic audit, native order UI and settings.
- Does not call Pro-only classes from Free; uses stable optional public extension points.
- Uses WC CRUD, activation/dependency guards, WP security conventions, escaping, WP i18n and privacy hooks.
- Tests no-Pro setup, HPOS and legacy supported storage.

**Agent E — Pro Import & Data Engineer**
- Owns bank profiles, CSV/CAMT parser, mappings, bank transactions, import batch jobs, validation, dedup, retry and derived import status.
- Must supply deterministic fixtures and re-import/concurrency coverage.
- Prohibited from auto-marking orders paid during import.
- Never lets arbitrary file content reach unserialization, unguarded XXE or unsafe storage.

**Agent F — Reconciliation & Financial Logic Engineer**
- Owns candidate matching, allocation ledger, review case/approval state, partial/overpayment, reversal handling and WC paid transition orchestration.
- Must prove invariants with property/edge-case tests and DB transactional/idempotent behavior.
- Must fail closed on ambiguity, currency mismatches, stale state and duplicate claims.
- Never equates customer proof, confidence scores or an import row with cleared money.

**Agent G — UI/UX Designer and Frontend Architect**
- Owns wp-admin design system, all UI-001–UI-014 screens, design tokens, exact field/action contracts, accessible forms/tables, responsive behavior and visual QA.
- Cannot close design tickets with a screenshot alone or a beautiful page that lacks features; must map interactions to actual backend contracts.
- Must use consistent WP/WC layout and components; review real 1440/1024/narrow screenshots.
- Keeps Free and Pro UI consistent without duplicating component logic.

**Agent H — QA / Test Automation**
- Converts requirements into executable tests, fixtures, coverage and reproducible evidence.
- Tests negative cases, race conditions, security, import idempotency, WC payment transitions, real admin interactions and packaging.
- MUST reject fake/mocked success paths when the requirement demands integration behavior.
- Files defects with clear reproduction steps and regression tests before resolving.

**Agent I — Security, Privacy & Compliance Reviewer**
- Reviews file uploads, authorization, capability mapping, CSRF, SQL, XSS, SSRF/XXE, CSV injection, private files, retention, bank data exposure, dependencies, updates and plugins directory rules.
- Can block a release for critical/high security issues; document exact issue and remediation, then let agents proceed with unrelated work.
- Never asks merchant data to be sent to external AI for testing; tests use synthetic fixtures.
- Ensures no unreviewed automatic funds/payment confirmation flows appear.

**Agent J — Technical Writer & Developer Experience**
- Maintains accurate installation guides, user/admin walkthroughs, API contracts, changelog, upgrade/migration, troubleshooting, privacy, FAQ, contributor guide and support runbooks.
- Documents actual working behavior, not plans masquerading as released features.
- Updates docs in same work cycle as behavior changes and records screenshots accurately.

**Agent K — Release Engineer / CI Owner**
- Owns deterministic builds, composer/frontend assets, dependencies, CI workflows, versioning, package checksums, ZIP test installs, WordPress.org readme, SVN-ready Free release, separate Pro distribution, rollback and release notes.
- Cannot publish without actual authorization/credentials; must produce artifacts/checklists and move on to next permitted work.
- Validates no Pro-only code is bundled in Free and no test/dev dependencies shipped unnecessarily.

**Agent L — Memory / Backlog Custodian**
- Owns `.ai/state.json`, `BACKLOG.md`, task handoffs, append-only decisions, blockers and resume reproducibility.
- Reconciles docs against Git/test evidence at every boundary.
- Prevents forgetting previously fixed bugs, redoing tasks, losing context or silently changing priorities.
- Never marks a pending task done because its plan exists.

### 10.2 Common rules that apply to EVERY agent

1. Read `START_HERE.md` and `.ai/state.json` before editing.
2. Never override a higher-priority product invariant or security rule.
3. Work on clearly owned task IDs; avoid parallel editing conflicts; record file ownership and handoff.
4. Every task has measurable acceptance criteria and is complete only with evidence.
5. Keep source/fixture commits small enough for review, with relevant test changes.
6. No secret exfiltration, real-customer data in prompts, unsolicited network telemetry, or destructive commands.
7. No fake unit tests or mocks that only test hardcoded expected responses.
8. No infinite refactor loops: prioritize user outcomes and remaining acceptance criteria.
9. If external access unavailable, complete all offline work and mark that one integration blocked, not the entire project.
10. Produce a structured handoff with decisions, changed files, tests, defects, and next task; no empty "done" messages.
11. If uncertain about a third-party API, check authoritative docs/installed SDK before claiming compatibility.
12. Never present future phase ideas as already implemented features.
13. Every UI action must call a real permitted backend path and visibly report result.
14. Every financial mutation must be idempotent, permissioned, logged, and recoverable.
15. Each agent must obey the current runner's tool permissions and organizational policy. Agents cannot grant themselves elevated privileges.

### 10.3 Task contract (universal)

```yaml
id: WBM-P4-T012
name: Implement overlapping-statement duplicate detection
phase: 4
owner: pro-import-agent
tier: pro
priority: P0
dependencies: [WBM-P3-T006, WBM-P2-T011]
status: ready # planned|ready|in_progress|blocked|review|done
scope:
  files: ["plugins/wpessential-bank-transfer-matcher-pro/src/Import/...", "fixtures/statements/..."]
acceptance:
  - Same CSV imported 5 times creates one bank transaction per actual credit
  - Overlapping files import only genuinely new transactions
  - Ambiguous identical records are flagged rather than deleted
  - All financial amounts retain exact currency precision
security:
  - No public statement file exposure
  - No order paid transitions on import
evidence:
  tests: []
  commands: []
  logs: []
  screenshots: []
  commits: []
blocking_reason: null
next_action: "Write fixture + failing test, then implement parser/fingerprint decisions"
```

### 10.4 Autonomous development loop (run under an actual orchestrator)

```text
BOOT:
  load START_HERE.md and .ai/state.json
  inspect Git and verify latest actual test state
  verify environment, capabilities and current phase
  reconcile stale task states, preserving evidence

LOOP while execution time/tools/budget/permissions remain:
  task = highest_priority_unblocked_task(BACKLOG, dependency_graph, critical_path)
  if no task:
      run phase/release acceptance scan
      create tasks for verified unmet criteria or stop with an honest status report
  acquire scoped file ownership/branch for task
  gather relevant product rules + source + tests
  implement one verifiable vertical slice
  run relevant lint, static, unit, integration, UI/security tests
  if failures:
      triage root cause, write reproducer, repair and retest
      if environment blocked: mark scoped blocker + choose next unblocked task
      if design choice ambiguous: choose safest simplest valid option, record ADR
  if acceptance true:
      mark done with file/test/commit evidence
  else:
      keep in progress/review with exact next action
  persist .ai/state.json, BACKLOG.md, handoff and work log atomically
  checkpoint Git when permitted
  immediately select next unblocked task

AT CONTEXT/TIME/TOOL BOUNDARY:
  persist exact resume state and blockers
  do not claim unseen background work or an automatic future result
```

### 10.5 "No Human Consultation" decision matrix

| Decision | Agent action |
|---|---|
| Field label, placeholder, UI spacing | Use UI contract and WP patterns; implement without asking |
| SQL table/index/name | Design + ADR + tests; proceed |
| Free/Pro ownership ambiguity | Resolve from Feature Matrix and core independence rule; ADR |
| Unknown bank file dialect | Treat as unsupported/mapping-required; produce fixture and safe error |
| Missing Figma access | Implement local screen specification and testable UI; log design export blocker |
| Missing cloud license server | Build interface/mock integration; do not fabricate live activation |
| Competing implementation strategies | Pick safest, maintainable, simplest with documented rationale |
| Unit/integration test fails | Fix autonomously and rerun |
| Production credentials required | Do not request secret in public issue/log; prepare all possible offline work and describe blocked operation |
| Real payment approval | Must be performed by authenticated authorized **merchant end-user** in plugin, never by development AI |
| Public release/publish with account credentials | Produce release candidates; require externally authorized action/tool permissions |
| Destructive changes to real data | Do not execute without explicit authorization; operate on disposable fixtures instead |

**Clarification:** Eliminating human consultation from **development workflow** does not eliminate legally/financially necessary merchant verification or authorization inside the finished product.

---

## 11. MEMORY, BACKLOG AND CONTEXT-LOSS RECOVERY SYSTEM

### 11.1 Required persistent files

- `START_HERE.md`: dynamic 1–2 page entrypoint for any new agent or session; includes exact current phase, current task, command to run next, environment setup, relevant files, blockers and test status.
- `.ai/state.json`: machine-readable current execution status; last verified SHA/commit, active task, task queue pointer, tests, artifacts, paused blockers, next precise action.
- `BACKLOG.md`: human-readable P0/P1/P2 backlog organized by phases, owners, dependencies, acceptance, status and evidence.
- `.ai/next-task.json`: single next autonomous task for deterministic resume; generated from graph, not stale prose.
- `.ai/decisions.jsonl`: append-only decisions with `decision_id`, date, issue, options, selected, rationale, evidence, consequence, supersedes.
- `.ai/sessions/YYYY-MM-DD-HHMM.md`: context handoff, changes, tests, failures, next action, important warnings.
- `.ai/runbooks/recovery.md`: exact recovery when tests fail, environment missing, import corruption suspected, Pro missing Free, Git conflict.
- `docs/architecture/ADRs/`: architecture decision records.
- `docs/testing/traceability.csv`: requirement-to-test-to-UI-to-code evidence map.
- `docs/release/readiness.md`: releases and gates; all truthfully checked.

### 11.2 Example `.ai/state.json` (template, not proof of completed work)

```json
{
  "project": "WPEssential Bank Transfer Matcher for WooCommerce",
  "schema_version": 1,
  "phase": "P0-PREDEVELOPMENT",
  "active_task_id": "WBM-P0-T001",
  "current_branch": null,
  "last_verified_commit": null,
  "last_checkpoint_utc": null,
  "execution_status": "ready",
  "next_action": "Inspect repository and inventory existing assets; then initialize product/architecture docs.",
  "completed_tasks": [],
  "blocked_tasks": [],
  "tests": {
    "php_unit": "not_run",
    "php_static": "not_run",
    "frontend": "not_run",
    "integration": "not_run",
    "e2e": "not_run",
    "security": "not_run",
    "package_install": "not_run"
  },
  "release_artifacts": {"free_zip": null, "pro_zip": null},
  "critical_invariants": [
    "no_customer_claim_as_payment_proof",
    "no_auto_paid_on_csv_import",
    "idempotent_allocation",
    "free_independent_of_pro"
  ]
}
```

### 11.3 Backlog states and prioritization

Statuses: `planned`, `ready`, `in_progress`, `in_review`, `blocked`, `done`, `verified`, `deferred`. `done` means implementation and acceptance completed; `verified` means an independent agent/test gate independently confirmed it. Do not count deferred as completed.

**Priority:** P0 (security/financial invariants/build/test/usable MVP), P1 (production-quality UX and full Pro feature parity), P2 (advanced convenience), P3 (future). Use a dependency graph to avoid implementing UI controls before domain/API contracts are known, yet deliver thin vertical slices rather than a backend-only multi-month project.

**Backlog rehydration on restart:** read state → inspect Git HEAD → compare files and tests → reconcile out-of-sync statuses → preserve existing completed tasks → prioritize defects blocking core workflows → continue from `next_action`. If a task is marked complete but code/tests are missing, reopen it with reason.

**Memory discipline:** store concise verified facts rather than entire raw conversation. Do not persist secrets, financial customer data, unredacted logs, or raw files outside test environment. Tasks reference artifact paths and evidence instead of copying giant code dumps into context.

### 11.4 Session handoff template

```markdown
# Handoff — <timestamp UTC>
## Verified environment
## Current branch and commit
## Completed task IDs / implementation files
## Test commands executed and exact results
## Screens implemented and screenshot paths
## Security/financial invariants checked
## Open defects and reproducible symptoms
## Blockers (scope, why, permitted workaround)
## Decisions/ADRs
## Next ONE unblocked task and first executable command
## Items that MUST NOT be assumed complete
```

### 11.5 Autonomous resume prompt to store in `START_HERE.md`

> Resume WPEssential Bank Transfer Matcher for WooCommerce. Read this file, `.ai/state.json`, `BACKLOG.md`, the active task's acceptance criteria and the latest handoff. Verify Git state/tests, select the exact `next_action`, execute it, test it, record evidence, and automatically move to the next unblocked task for as long as this runner is active. Never replan completed work, ask routine design questions, or claim unexecuted work. Continue observing financial correctness, privacy, Free/Pro separation and external authorization boundaries.

---

## 12. DEVELOPMENT QUALITY AND CODING RULES

### PHP/WordPress

- Follow WordPress Coding Standards and PSR-4 class autoload strategy; use static analysis (PHPStan/Psalm level appropriate to project), PHP_CodeSniffer/WPCS, and proper escaping/sanitization.
- Version requirement headers dynamically based on tested WP/WC/PHP versions, not guessed latest versions. Detect missing WooCommerce and unsupported environments gracefully.
- No direct order post-table SQL; use WooCommerce CRUD for order data and status. Define HPOS compatibility only after tests; test Cart/Checkout Blocks separately and declare accurately.
- Use hooks/filters only with stable namespaced prefixes (`wbm_` or `wpessential_wbm_`), document extension points, avoid global symbol conflicts.
- Idempotent activation/migrations; schema versioned; uninstall opt-in for data deletion, with cross-package dependencies respected.
- Localized strings through WordPress i18n, no string concatenation that breaks translation, correct text domain, no hardcoded visible English in code outside translations.
- Accessible admin notices and WP settings patterns; code loaded only on matching plugin routes where possible.
- Respect multisite support policy: either fully test network activation/site tables or explicitly support only per-site installations without unsafe assumptions.

### TypeScript/React

- Strict TypeScript, lint/format, typed REST clients, no `any` for key financial models, safe loading/error states, SSR/DOM assumptions constrained to WP admin.
- Use shared Free admin components and Pro extension routes; no duplicate UI frameworks.
- Controlled forms, schema validation mirrored server-side, safe money input, locale date awareness; avoid client-side authority for financial decisions.
- React error boundaries and explicit data fetching/cache invalidation, preferably stable WordPress packages where suitable.
- No unsafe `dangerouslySetInnerHTML` on customer/statement content. Use escaping and limited sanitized markdown where necessary.

### Database and jobs

- Make import batches and allocations idempotent under concurrent requests. Keep stable correlation IDs, lock keys, DB transactions where available and compensating repair handling.
- Avoid unbounded loops and N+1 queries; index key lookups. Target realistic large statements with bounded memory; stream, chunk and process background jobs.
- Provide clear operational errors when WP-Cron is disabled/delayed; never label pending jobs successful.
- Never erase source provenance when a transaction is corrected. Archive/redact only according to explicit retention rules.

### API conventions

- REST namespace e.g. `wpessential-wbm/v1`. Validate user permissions per route and object, apply nonce/auth as appropriate, sanitize all fields and use consistent error codes.
- Specify each endpoint: method, request schema, response schema, capabilities, idempotency key, pagination, errors, rate limits and audit side effects.
- All writes have audit events and conflict handling. Keep server authoritative for order eligibility/money.

---

## 13. SECURITY TESTING AND THREAT MODEL

Create `docs/security/threat-model.md` covering assets, adversaries, trust boundaries, attack paths, mitigations, residual risks. Required reviews:

1. Malicious admin upload (CSV formula injection, oversized rows, nested quotes, resource exhaustion, encoding tricks, path traversal, XML XXE).
2. Guest claim abuse: enumeration, token replay, CSRF, attachment upload abuse, forged sender names/references, spam, leaking other orders.
3. WP privilege escalation via REST/AJAX action, insecure direct object references, missing capability checks on statement/receipt download.
4. Stored/reflected XSS through transaction descriptions, notes, CSV headers, claimant names, bank references.
5. SQL injection/unsafe table names or order query leakage.
6. Race condition/double-approval/replay, status transition duplication, DB transaction failure, stale amount allocation.
7. Licensing/update channel trust, supply-chain and dependency vulnerability scanning.
8. Logs/exports backups containing full bank or customer data.
9. Data retention, user consent, privacy exports/deletion; recordkeeping obligations by jurisdiction to be assessed by merchant, not falsely promised as universal compliance.
10. Multi-currency and malformed numeric overflow, rounding, zero-decimal units and changed currency metadata.
11. Reversal/cancellation after fulfillment; avoid changing accounting state without explicit case.
12. Known plugin conflicts, HPOS, checkout Blocks, different WC versions and unsupported DB setups.

Required automated tests: authorization matrix, nonces, object-level permissions, content validation, upload privacy, CSV formula injection, XML external entities disabled, replay of approval ID, concurrent allocation, tampered imported evidence, data redaction.

**Security gate:** no unresolved critical/high vulnerabilities before release. Moderate issues require explicit risk/mitigation record and release policy review.

---

## 14. TEST PLAN — PROOF BEFORE COMPLETION

### 14.1 Test layers

- **Unit:** references, validation, money arithmetic, state machines, match ranking/explanations, fingerprints, allocation invariants, capability policies.
- **Property-based/edge:** random order amounts, currency exponents, allocation lists; assert sums/replay invariants.
- **Database integration:** migrations, unique indexes, transaction locking/idempotency, journal consistency, rollback/retry.
- **WooCommerce integration:** BACS On hold behavior, HPOS enabled/disabled, paid lifecycle/virtual products, cancelled/refunded orders, native emails, legacy/classic checkout and Blocks where supported.
- **WP-admin E2E:** every UI-001 to UI-014 critical path; filters, search, sort, pagination, buttons, modal validation, permission error, settings saved/reloaded.
- **Customer E2E:** checkout to reference display, order page, guest claim, secure file access, claim review and notifications.
- **Import fixture suite:** every scenario in Section 7.6; CSV profiles; large inputs and invalid rows.
- **Security:** WP-style auth/capability, XSS/CSRF/XXE/CSV injection, upload privacy, data leakage, arbitrary file requests.
- **Compatibility:** minimum and current tested WP/WC/PHP matrix, multiple popular themes, multisite policy, other plugins if available.
- **Performance:** 1k/10k/50k statement rows under bounded memory; exact thresholds measured and recorded on representative local environment, not invented guarantees.
- **Installation/release:** fresh install Free alone, Free+Pro, Pro missing Free, deactivate/re-activate each, upgrade/downgrade safe behavior, no data loss, separate ZIP validation.
- **Visual:** capture screenshots and test 1440px/1024px/375px, dark admin color schemes, RTL sample, keyboard navigation.

### 14.2 Mandatory failure tests

- Same transaction approved in two concurrent browser sessions; only one succeeds, other sees conflict.
- Partial allocated total never triggers payment_complete.
- Duplicate statement reimport produces zero new credits and zero additional paid transitions.
- Bank credit allocated to one order cannot be allocated beyond remainder to another.
- Payment proof claim cannot bypass bank verification.
- User with viewer capability cannot download customer receipt unless explicitly permitted.
- Pro deactivation does not break Free admin or destroy ledger.
- Missing Free disables Pro operations safely.
- 500 response during import shows resumable failed state, not successful status.
- New order total after approval generates appropriate exception; never silently rewrites ledger.
- WC payment transition failure reports partial workflow safely with recoverable job/event; never duplicates payment completion.

### 14.3 Definition of Done per task

A task becomes `verified` only when: functional code exists; all acceptance clauses met; relevant automated tests pass; UI relevant state/screens tested; accessibility, security and performance impacts checked; documentation updated; no newly introduced critical regressions; evidence logs/screenshots/commit recorded; Free/Pro boundary intact. A green build alone is not enough when acceptance requires actual WP/WC integration.

### 14.4 Overall release gates

Release candidate must pass: security gate, financial invariants gate, functional workflows (Free and Pro), **independent Composer/autoload gate C1**, **WordPress.org Free gate WORG**, **Woo Marketplace Pro gate WOO**, **documentation generator gate DOC**, **Playground + MySQL gate PG**, packaging/install gate, UX coverage gate, source compliance, privacy documentation, rollback/migration gate and honest change log. If any gate fails, label package **release candidate — not production ready** and continue fixing where possible. Do not ship known financial-correctness blockers.

---

## 15. DOCUMENTATION — COMPLETE AND CONTINUOUS

Create and keep current:

**For merchant users:** `getting-started.md`, `first-bank-transfer.md`, `reference-settings.md`, `payment-claims.md`, `manual-verification.md`, `CSV-import-guide.md`, `bank-mapping.md`, `match-review.md`, `partial-payments.md`, `duplicate-payments.md`, `overpayments.md`, `reversals.md`, `team-approval.md`, `reports.md`, `troubleshooting.md`, `privacy-and-data-retention.md`, `faq.md`. Every relevant screen includes a `Help` link to the applicable guide.

**For developers:** product PRD, requirements matrix, architecture overview and diagrams, Free/Pro boundary ADR, schema definitions/indexes, state machine diagrams, public hooks/API reference, REST schemas, data import plugin interface/format contracts, security model, permission matrix, migration/dependency compatibility, frontend component library, local environment setup, testing/fixtures, contributing guide, changelog/versioning, licensing/update service adapter.

**For releases:** WordPress.org `readme.txt` with honest feature descriptions and requirements; `README.md`, upgrade note, license notice, security disclosure process, privacy data description, sample CSV mapping templates, sample synthetic files, reproducible ZIP build commands, checksums, support matrix and rollback runbook.

**Per documentation item:** claim only tested behavior; mark plan/future items clearly. Screenshots must come from actual UI or labeled design concepts. Update docs in same task that changes behavior.

---

## 16. RELEASE, DISTRIBUTION AND BUSINESS MODEL

### Free distribution

- Separate Free ZIP cleanly installable by WordPress; WordPress.org guidelines compliant; GPL-compatible; contains meaningful complete functionality and no hidden remote code install/execute logic.
- Validate headers, text domain, assets included locally, no unneeded development dependencies, translation-ready readme, uninstall behavior, safe admin notices, optional restrained contextual Pro link.
- Prepare WordPress.org SVN-compatible assets and submission checklist. Submission requires authorized account; **do not impersonate acceptance by WordPress.org**.

### Pro distribution

- Separate `wpessential-bank-transfer-matcher-pro.zip`, compatible installed Free plugin version, separate Pro main file/text domain, normal WordPress plugin lifecycle, separately versioned and changelogged.
- Proposed price $79/year for one site, additional multi-site tiers configurable later. **Do not implement billing checkout or public license activation service unless access and requirements exist**; deliver `LicenseService` contract and local test double for integration, with honest staging behavior.
- Do not block merchants from accessing historical bank records and exports solely because subscription renewal fails. Define safe expiry/read-only effects.
- Pro cannot copy Free plugin into itself or replace Free files during install/update. Keep class/package ownership explicit.

### Build artifacts

```text
/dist/wpessential-bank-transfer-matcher-<version>.zip
/dist/wpessential-bank-transfer-matcher-pro-<version>.zip
/dist/SHA256SUMS
/dist/release-manifest.json
/docs/release/validation-report.md
/docs/release/screenshots/
/docs/release/compatibility-matrix.md
```

Build scripts install production dependencies using Composer with locked versions, compile assets, strip dev/cache/log files, validate ZIP entries/security, install both into a clean **Playground Blueprint bundle and a MySQL/MariaDB test environment**, verify activation/dependency controls, capture test results, compute checksums and record actual paths. See Sections 16A–16D.

**Never claim publicly published when only packaged.** Publish/commit to public repositories, WordPress.org SVN and licensed storefronts only through authorized accounts/permissions; otherwise save final-ready release candidate artifacts and exact follow-up instructions.

---
## 16A. REQUIRED COMPOSER 2 AUTOLOADING, PSR-4 PACKAGING, DEPENDENCY ISOLATION

**This is not an optional preference. Both installable plugins MUST use Composer-generated autoloading.** This requirement applies to initial bootstraps, CI, release candidates, Playground builds, and WordPress.org/Woo Marketplace distribution.

### 16A.1 Separate Composer ownership

- Free: `plugins/wpessential-bank-transfer-matcher/composer.json` with its own `composer.lock`, namespace `WPEssential\\BankTransferMatcher\\` mapped to `src/`, package type `wordpress-plugin`, and `vendor/autoload.php` generated locally.
- Pro: `plugins/wpessential-bank-transfer-matcher-pro/composer.json` with independent `composer.lock`, namespace `WPEssential\\BankTransferMatcherPro\\` mapped to `src/`, independent `vendor/autoload.php`, and only an explicit API/version contract with Free.
- Every PHP production class must be PSR-4 compatible. WordPress hooks may use thin procedural bootstraps. Do not introduce duplicate classes, generic namespaces, global composer-loader mutations, or ambiguous class aliases. Avoid overlapping vendor symbols; evaluate dependency prefixing/scoping ONLY when needed and test compatibility.
- Shared runtime services/interfaces belong in **Free**, including interfaces for Pro extensions. Pro registers implementations using documented hooks/registries. Pro must gracefully deactivate or show a restrained, actionable dependency notice when Free/WooCommerce is unavailable; never fatal on boot.
- Do not use Composer as a runtime network service; no `composer install`, `composer update`, `npm install`, or downloads on merchant sites. Never require merchant CLI access.
- Do not bundle PHPUnit, PHPStan, PHPCS, test fixtures, CI secrets, Node dependencies, source maps with secrets, or author development tools in marketplace ZIPs. Include **all required production autoload files**, dependency notices/licenses, compiled JS/CSS, translations, and declared resources.

### 16A.2 Minimum Composer manifest examples (adapt version constraints only after verification)

FREE illustrative `composer.json` (place real, validated package data in project):

```json
{
  "name": "wpessential/bank-transfer-matcher",
  "description": "Manual offline bank transfer verification for WooCommerce.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": { "php": "^8.1" },
  "autoload": {
    "psr-4": { "WPEssential\\BankTransferMatcher\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "WPEssential\\BankTransferMatcher\\Tests\\": "tests/" }
  },
  "config": { "sort-packages": true, "optimize-autoloader": true }
}
```

PRO illustrative `composer.json`:

```json
{
  "name": "wpessential/bank-transfer-matcher-pro",
  "description": "CSV reconciliation add-on for WPEssential Bank Transfer Matcher.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": { "php": "^8.1" },
  "autoload": {
    "psr-4": { "WPEssential\\BankTransferMatcherPro\\": "src/" }
  },
  "autoload-dev": {
    "psr-4": { "WPEssential\\BankTransferMatcherPro\\Tests\\": "tests/" }
  },
  "config": { "sort-packages": true, "optimize-autoloader": true }
}
```

Notes: The example minimum `php: ^8.1` is a proposed product support decision, not a statement that Woo requires PHP 8.1. Resolve product requirements at P0 using supported vendor guidelines, plugin headers and CI. Test the exact declared minimum. For plugin-to-plugin dependencies, do **not** pretend that Composer package requirements alone install/activate the Free WordPress plugin. Enforce required Free slug/version at WordPress plugin boot. Explicitly map `Requires Plugins: woocommerce` as appropriate in the WordPress plugin header; the Pro dependency on Free requires an additional runtime/version guard and cannot be assumed to be installed by Composer.

### 16A.3 Bootstrapping and verification

Each plugin main PHP file MUST have valid WordPress plugin headers and `defined( 'ABSPATH' ) || exit;`. Perform environment/dependency checks; then include **its own** `__DIR__ . '/vendor/autoload.php'` using safe existence checks with admin diagnostics instead of fatal error on missing vendor. Instantiate a small bootstrap/container; register hooks only after dependencies are verified. Never pull Pro classes from Free.

Required build/test commands (adapt to actual repo path; fail on non-zero exit):

```bash
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer dump-autoload --no-dev --optimize
composer audit --no-interaction
```

Run separately inside Free and Pro directories. Lockfile must be committed and used; do not blindly update dependencies while creating an immutable release. A CI build should also run `composer validate`, `composer install` with dev deps in a build environment, PHPCS/WPCS, PHPStan or Psalm, PHP unit/integration tests, and license/SBOM scans. Add a **clean install from packaged ZIP** test to ensure Composer classes load without `composer` installed on the merchant WordPress instance. If `composer audit` cannot execute (e.g. network outage), classify it as `NOT VERIFIED`, not `PASS`.

### 16A.4 Version compatibility and conflicts

- PHP minimum and WP/WC minimum must be supported by **every production package**, not only the top-level plugin code.
- Maintain a Composer dependency license inventory, package provenance, and vulnerability review. Reject unreviewed dependencies and incompatible licenses.
- Test installing Free alone, Pro after Free, installing Pro without Free, Free deactivated with Pro active, WooCommerce disabled, incompatible Free/Pro versions, simultaneous upgrades, and rollback.
- Composer must **not** autoload Free-only types from the Pro vendor tree or duplicate Free source. Only documented stable Free services may be referenced by Pro.
- Include `build/package-manifest.json` per product enumerating required files and forbidden release paths. Zip QA MUST inspect actual ZIP member paths, not just repository folders.

**Gate C1:** Two independent Composer autoloaders load correct test classes from production ZIPs; no dev tool/runtime dependency leaks; no fatal errors for missing/Woo dependencies; supported PHP compatibility verified.

---

## 16B. WORDPRESS.ORG FREE DIRECTORY — ACTUAL SUBMISSION-READY PRODUCT

Free is a complete, independently usable plugin with manual references/claims/verification, and must follow the **current** WordPress.org detailed plugin guidelines. Do not ship inactive, encrypted, time-limited, hidden or remotely unlocked Pro classes in the Free ZIP. Pro-only reconciliation engine belongs only in the separate Pro plugin. Avoid prohibited remote code downloads, admin spam, unexpected telemetry, public asset calls without consent, and abusive upsells. Any Free-to-Pro promotional text must be minimal, contextual, honest and policy-compliant.

### 16B.1 WordPress.org submission deliverables

```text
plugins/wpessential-bank-transfer-matcher/
  wpessential-bank-transfer-matcher.php
  composer.json
  composer.lock
  vendor/autoload.php                 # build output included in installable ZIP
  src/
  assets/                              # compiled assets
  languages/                           # text domain and translations as appropriate
  readme.txt                           # GENERATED in official WP.org format
  README.md                            # GENERATED human/developer docs
  LICENSE
  uninstall.php                        # only if necessary; explicit safe policy
  ...

docs/submission/wordpress-org/
  checklist.md
  plugin-check-results.md
  readme-validator-results.md
  svn-publish-plan.md
  privacy-and-third-party-services.md
  release-screenshot-evidence.md
  asset-manifest.md
  plugin-directory-copy.md
  final-review-report.md
```

`readme.txt` MUST use the **WordPress.org plugin directory format**, NOT a renamed GitHub Markdown README. Use verified headers such as plugin title, Contributors (actual WP.org usernames only; omit if unknown pending release), Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License and License URI, and honest short description (at most 150 characters). Use `== Description ==`, `== Installation ==`, `== Frequently Asked Questions ==`, `== Screenshots ==`, `== Changelog ==`, and `== Upgrade Notice ==` when relevant. No invented ratings, reviews, adoption statistics, support URLs, external dependency claims or features. Use version-consistent screenshots/captions. Keep plugin PHP headers, `readme.txt`, package names and documentation aligned. **Since WordPress 5.8, main PHP plugin headers drive platform requirements**, so a matching `readme.txt` alone cannot fix mismatches.

### 16B.2 Submission eligibility and review stages

1. Validate unique plugin slug, product name/trademark, `Plugin Name`, `Version`, `Text Domain`, `Domain Path` (if used), `Requires at least`, `Requires PHP`, `Requires Plugins` (as applicable), and tested WP/WC versions.
2. Validate Free active functionality, admin screens, i18n, accessibility, settings/capabilities, uninstall data choice, data privacy/exporter/eraser where relevant, safe external links, and GPL/compatible dependency licensing.
3. Run current **Plugin Check (PCP)** tooling and resolve WordPress.org-relevant errors; evaluate warnings with written disposition; run WordPress.org official readme validator and store exact results or `NOT RUN` reason.
4. Build `wpessential-bank-transfer-matcher-<version>.zip` containing only one correctly named root plugin folder. Test install/activate, WooCommerce missing, WooCommerce enabled, fresh site, migration, and no Composer/Node needed on server.
5. Generate WordPress.org screenshots and graphical directory assets according to **verified current** specs (icon/banner sizes and image names; do not invent). Store assets in distribution-ready `svn/assets`, not confusingly as runtime UI files.
6. Produce SVN staging structure (`trunk`, `tags/<version>`, `assets`) **only after** the directory grants access; prepare a dry-run structure before then. Do not perform a remote SVN push or submission without authorized account/access.
7. Document reviewers' setup steps, known limitations, data handling, exact local file processing, and rationale for the separate Pro plugin. No false claims of WordPress.org approval.

### 16B.3 Free rejection-prevention tests

- Zero Pro runtime implementation in ZIP; no useless trialware; no plugin crash if WooCommerce absent.
- Free operates fully without any license key or remote account; a genuine, independent merchant workflow works end-to-end.
- Only documented local processing; bank data/receipt privacy protected; WordPress.org plugin guidelines compatible.
- Valid GPL-compatible licensing for all bundled deps; all files published are appropriate for distribution.
- Translation strings and WordPress UI meet current code/security/accessibility practices.
- `readme.txt` is generated, valid, truthful, and references only verified Free features.

**Gate WORG:** candidate has evidence-backed pass for plugin checks, readme validator, packaging, Free workflows, privacy/legal/dependency inventory, and reproducible ZIP; actual directory review/acceptance is externally controlled.

---

## 16C. WOOCOMMERCE MARKETPLACE — PRO PRODUCT SUBMISSION-READY

**Important distribution nuance:** Woo Marketplace's current guidelines describe *freemium products* as requiring **separate Free and Paid submissions** for Marketplace listing, later merged into a single product page. This is distinct from **WordPress.org Free submission**. Plan these as separately documented submission tracks. Pro remains a separate, installed add-on depending on Free unless Woo Marketplace explicitly requires an alternative and the architecture is re-evaluated with an ADR. Do not silently combine/remove Free to appease a marketplace.

### 16C.1 Technical admission criteria

- Check the Woo **freemium monetization track**, which explicitly supports a separate upgrade add-on plugin. If a Marketplace listing of the Free component is considered, ensure it has a valid paid-upgrade monetization path; listing only the Free plugin there may be ineligible. Keep WordPress.org Free submission as a different, independent application.
- Main plugin header must include truthful `Author` and `Developer` fields plus approved public links. The Woo `Woo:` Marketplace header is provisioned by Woo after onboarding; **never invent or manually add a Woo product ID/header** to the Free/Pro builds.

- Meets **current Woo Marketplace submission requirements at release time**. Reverify latest WordPress/WooCommerce major versions, PHP requirements, HPOS, Cart/Checkout Blocks, editor compatibility, accessibility and UX guidelines before setting release metadata.
- Must pass/resolve Woo Quality Insights Toolkit (**QIT**) checks: API, end-to-end (E2E), activation, security, PHP compatibility, malware, and validation (as presently documented); store exact logs/reports. If Marketplace access or QIT execution is unavailable, mark `EXTERNALLY BLOCKED: NOT VERIFIED`, do not pretend tests passed.
- Critical flows: activating Free+Pro; missing Free dependency; BACS checkout; statement CSV format validation; duplicates; matching review; partial/split allocations; manual approvals; no double credit; cancellations/refunds; upgrades/migrations; account roles; export/privacy; checkout Blocks; WC HPOS.
- Test with two latest WP/WC major releases **where applicable and verified**; declared product PHP minimum plus current supported runtime; no unsupported compatibility claims.
- Ensure Pro is useful on its own license terms but depends correctly on the independently installed Free plugin; no accidental payment/bank movement; no hidden API requirement.
- WC custom product names/trademark conformity; pricing, third-party link/upsell and monetization rules checked. The proposed `$79/year` is a hypothesis, **not** an approved Marketplace selling price. If listing pricing or update/licensing differs, document decision and provide applicable adapters instead of undocumented external billing.
- Do not build a fake Marketplace API/credentials or license service. Integrate approved provisioning/updater/billing mechanism only when provided and permitted.

### 16C.2 Pro submission evidence pack

```text
docs/submission/woocommerce-marketplace/
  business-rationale-and-competition.md
  submission-readiness.md
  partner-account-prerequisites.md
  technology-and-dependency-disclosure.md
  qit-api-results.md
  qit-e2e-results.md
  qit-activation-results.md
  qit-security-results.md
  qit-php-compatibility-results.md
  qit-malware-results.md
  qit-validation-results.md
  ui-accessibility-and-critical-flows.md
  hpos-checkout-blocks-test-evidence.md
  listing-copy-and-price-proposal.md
  screenshots-and-product-assets.md
  support-sla-and-update-policy.md
  reviewer-test-guide.md
  known-limitations.md
  final-submission-report.md
```

Product listing assets should use verified current dimensions and rules. As of checked Woo guidance: product icon **160×160px**, product screenshots/gallery ideally **896×550px or larger**; verify again at packaging time. Include actual merchant UI screenshots, not only Figma/mockup concepts. Reviewer test guide must be accessible without real bank data, use synthetic CSV cases with expected results, and document exact login and navigation. Do not embed real credentials in the repository or publicly shared docs.

### 16C.3 Pro verification matrix

- Pro absent/active/disabled; Free uninstalled/disabled/version incompatible; Woo unavailable.
- CRUD and HPOS compatibility declaration must reflect **actual** exercised test results, not merely a hook declaration.
- Checkout block support proven with active checkout UI as needed by Free, including Woo's BACS flow.
- WP-admin architecture feels native: no unstyled React controls, inaccessible dialogs, broken tables or dead buttons. Validate 14 screens and all listed states under both products.
- Security audits, Composer dependency scans, no remote bank data transmission, support and privacy docs complete.
- QIT and Marketplace feedback loops produce backlog tasks with reproduction evidence; fix then rerun tests autonomously when access permits.

**Gate WOO:** Pro release candidate and test evidence complete; Woo Marketplace partner application/approval and actual submission remain authorized external actions; never report accepted or listed without evidence.

---

## 16D. DOCUMENTATION GENERATOR — `README.md` + `readme.txt` WITHOUT MANUAL DRIFT

**Build one canonical source; generate all release-facing documentation.** `README.md` and WordPress.org `readme.txt` are **different output formats** and must not be blindly renamed/copied. Do not hand-maintain version numbers/features in many disconnected documents. Build a deterministic docs generator early in P1 and execute in CI and P9.

### 16D.1 Canonical inputs and generated outputs

Inputs (version-controlled):

```text
/docs/source/product-metadata.json      # product name, slugs, tested/min versions, license,
                                        # genuine links, contact info, release version
/docs/source/feature-catalog.json       # feature IDs, Free/Pro ownership, test IDs,
                                        # verified status, policy-safe summary
/docs/source/faq.md                    # reviewed FAQ source content
/docs/source/installation.md           # verified user journey
/docs/source/changelog/*.md            # versioned release changes tied to commits
/docs/source/upgrade-notices.json       # upgrade details and migration disclosures
/docs/source/support-and-privacy.md     # support, data privacy, optional services
/scripts/generate_readmes.py            # Python starter supplied; expand to production contract
```

Outputs (do NOT manually edit):

```text
/plugins/wpessential-bank-transfer-matcher/README.md
/plugins/wpessential-bank-transfer-matcher/readme.txt
/plugins/wpessential-bank-transfer-matcher-pro/README.md
/plugins/wpessential-bank-transfer-matcher-pro/readme.txt    # optional internal reference;
                                                             # do not submit Pro readme to WP.org
/README.md                                                    # repository overview
/docs/submission/wordpress-org/listing-copy.generated.md
/docs/submission/woocommerce-marketplace/listing-copy.generated.md
/docs/release/metadata-sync-report.json
```

Product metadata must explicitly distinguish `status: planned | implemented | verified | released`; generator **MUST NOT market planned/untested features as shipped**. Free copy must never describe Pro implementation as a Free feature. Pro copy must state the installed Free dependency and version constraints. Support/author URLs and WP.org contributor usernames must be real; unresolved metadata blocks release docs but does not block functional coding.

### 16D.2 Generation, validation, and CI contract

A tested Python starter generator, an intentionally incomplete metadata example, and a generator contract are supplied in this prompt package under `scripts/generate_readmes.py`, `templates/release/product-docs.example.json`, and `docs/README_GENERATION.md`. The implementation agent MUST integrate/extend the starter, enforce canonical release metadata and feature test IDs, produce all specified outputs, and connect CI version/header/readme validators. Do not treat the starter as a full release process.

Required project scripts or equivalent:

```bash
npm run docs:generate
npm run docs:check
npm run docs:readme-validate
npm run release:package-free
npm run release:package-pro
npm run playground:bundle-free
npm run playground:bundle-pro
npm run playground:test
```

Implement scripts with actual exit codes and readable output; **no inert npm placeholders**. Generator must:

1. Validate schema and required metadata before output (names, versions, PHP/WP/WC, slugs, GPL, URLs, owner, verified features).
2. Render official WP.org `readme.txt` format with proper headings, short description <=150 chars, actual Free-only descriptions, screenshots/FAQ/changelog, Stable tag matching built package version, tested platform versions reflecting a real test matrix.
3. Render Markdown `README.md` for Free and Pro separately with quick start, activation/requirements, screenshots, limitations, feature matrix, privacy, troubleshooting, support and changelog. Render root repo README for contributors.
4. Synchronize `Version`/requirements/text-domain in main PHP headers with release metadata (or fail instead of silently mutating code), Composer versions where used, CHANGELOG, package filenames, and stable tag.
5. Verify all generated files are fresh in CI via deterministic regeneration + no-diff check (`git diff --exit-code` on generated files), with a readable metadata drift report.
6. Validate WordPress.org readme using official validator where accessible, plus offline structural assertions. Preserve actual validator URL/report when run; otherwise mark `NOT VERIFIED`.
7. Never output sensitive bank/customer data, fictional customer testimonials, fabricated compatibility claims, future-only features, unverified test passes, or unapproved marketing promises.

**UI for project/docs agent:** Maintain a `docs/release/README-MAP.md` which maps each screen ID/feature ID to source documentation, screenshot, generated listing copy and test evidence. Add a docs validation task automatically whenever a feature changes. Include code-fenced example of the complete generated `readme.txt` in source guide, NOT a duplicate authoritative file that drifts.

**Gate DOC:** changing version or feature ownership in canonical metadata changes expected outputs deterministically; all docs remain in sync, and `docs:check` fails on drift or false advertised feature status.

---

## 16E. WORDPRESS PLAYGROUND FIRST — BLUEPRINT TESTING AND REPRODUCIBLE BUNDLES

**Mandatory:** Use real WordPress Playground during development, not a screenshot/mock simulation, and ship importable Blueprint JSON files. Use `https://playground.wordpress.net/blueprint-schema.json` as schema and prefer current `installPlugin.pluginData` syntax rather than deprecated `pluginZipFile`. Store `blueprint.json` at bundle root and local plugin ZIPs as bundled resources. Avoid fake remote paths or unversioned example URLs.

### 16E.1 Required Blueprints and artifacts

```text
/playground/blueprints/base/blueprint.json          # WP + WooCommerce, runnable without our ZIPs
/playground/blueprints/free/blueprint.json          # WP + WooCommerce + Free ZIP
/playground/blueprints/pro/blueprint.json           # WP + WooCommerce + Free ZIP + Pro ZIP
/playground/README.md                              # setup, CLI/browser usage, limitations
/playground/fixtures/                            # synthetic sample CSV/claims only
/playground/evidence/                            # logs/screenshots from actually run sessions
/scripts/build_playground_bundles.py               # validated, deterministic packaging
/dist/playground/free-playground-bundle.zip         # only after Free ZIP exists
/dist/playground/pro-playground-bundle.zip          # only after both ZIPs exist
```

**Base Blueprint** runs and installs official WooCommerce from WordPress.org without our unpublished plugin. **Free/Pro Blueprints** are ready-to-populate templates until `scripts/build_playground_bundles.py` copies actual Free/Pro release candidate ZIPs into their resource paths and produces self-contained bundles. Do not claim those templates are immediately runnable before release candidate files exist. Do not put placeholder empty ZIPs in a release bundle.

### 16E.2 Mandatory blueprint behavior

- `preferredVersions.php` and `.wp` explicitly set supported values; use a pinned CI matrix and retest `latest` separately. Do not use `preferredVersions` for WooCommerce settings.
- `steps`: `login` → `installPlugin` for WooCommerce with `pluginData: {"resource":"wordpress.org/plugins","slug":"woocommerce"}` and `options.activate: true` → `installPlugin` Free using `pluginData: {"resource":"bundled","path":"/wpessential-bank-transfer-matcher.zip"}` → Pro equivalent as last dependency. Paths must match the ZIP contents of the generated Blueprint bundle.
- Configure ordinary WP admin login only for disposable demos; credentials must be synthetic, never production credentials. Use deterministic landing page `/wp-admin/` or an actual implemented plugin page.
- Do not pretend WordPress Playground browser persistence provides durable QA evidence. Capture screenshots, console errors, network errors where supported, PHP logs, results and environmental versions in `playground/evidence/`; give every run a run ID.
- Test **Free only**, **Free + Pro**, **Pro missing Free**, **Woo missing**, **upgrade/migration**, user roles, and HPOS/checkout settings where the environment supports them. Some negative dependency tests require separate scenario Blueprints or fixtures beyond the happy-path three.
- Use Playwright or a comparable automated browser harness against running Playground for navigation, field validation, table sorting/filtering, dialogs, admin notices, empty/loading/error states, and a11y checks. Store screenshots against screen IDs UI-001–UI-014.
- Seed synthetic products/orders/bank deposits in fixtures or a documented isolated importer; never distribute fixtures containing bank account details or PII from a real merchant.

### 16E.3 Local execution and bundle assembly

Use current official CLI syntax and pin/test a version for CI. Example:

```bash
npx @wp-playground/cli --blueprint=./playground/blueprints/base server
# After actual plugin ZIPs have been built and bundles assembled:
npx @wp-playground/cli --blueprint=./dist/playground/free-playground-bundle.zip server
npx @wp-playground/cli --blueprint=./dist/playground/pro-playground-bundle.zip server
```

For browser sharing, host the self-contained bundle behind an intentionally published, CORS-accessible HTTPS URL, then use `https://playground.wordpress.net/?blueprint-url=<public-bundle-url>`; do not claim a local path works in another browser or send secret Pro sources in a public demo accidentally. The developer runner should prefer local CLI bundles for confidential pre-release code. Validate generated Blueprint JSON against current Blueprint schema when access exists; JSON parse alone only verifies syntax.

### 16E.4 Playground is NOT the only financial correctness test platform

Playground generally uses SQLite through its WordPress integration, not normal WooCommerce production MySQL/MariaDB. Therefore it is excellent for **UI/UX, activation, plugin lifecycle, integration smoke tests**, but it cannot substantiate MySQL row locks/transactions, deadlock handling, database indexing/performance, multi-worker races, or production cron scheduling. Complement Playground with ephemeral WP/WC backed by **MySQL/MariaDB** for allocation concurrency, idempotency, isolation, rollback, imports, transaction correctness and load tests. Both tracks are release gates; never call a PHP/DB compatibility issue "Playground-only" without investigating the real DB impact.

**Gate PG:** Base Blueprint actually runs; Free/Pro Blueprint bundles are built from real installable ZIPs and run in WP Playground, with captured evidence; realistic MySQL/MariaDB finance tests separately pass. If the current runner cannot launch the browser/CLI, mark runtime execution `BLOCKED` and leave exact runnable commands and evidence gaps, while continuing safe implementation.

---

## 17. FULL DEVELOPMENT PHASES — WORK WITHOUT ROUTINE APPROVAL STOPS

Phase transitions occur when acceptance criteria are met; do not wait for user to say "continue". The AI runner selects the next unblocked task automatically while actively executing. If only one session is available, persist a resumption checkpoint.

### PHASE P0 — RECONNAISSANCE, RESEARCH, PRODUCT CONTRACT

**Tasks:**
- P0-T001 inventory repository/environment/access; do not overwrite existing work.
- P0-T002 verify official WP/WC standards and BACS baseline; capture sources.
- P0-T003 research competitor capabilities/prices with evidence labels.
- P0-T004 write PRD, personas, user journeys, scope/non-scope, risks.
- P0-T005 write Free/Pro complete feature inventory and screen traceability.
- P0-T006 write entity glossary and domain invariants.
- P0-T007 seed full backlog, memory contracts, ADR templates and next-task system.
- P0-T008 list synthetic fixture matrix and financial threat model.

**Gate P0:** official docs/research links stored, complete acceptance matrix, separate package ownership clear, no undefined high-risk payment semantics, first runnable tasks assigned. Not allowed to spend all remaining time generating only plans.

### PHASE P1 — ENVIRONMENT, BOOTSTRAPS, FOUNDATION

**Tasks:**
- P1-T001 build disposable WP/WC local environment (Docker or equivalent), reproducible commands.
- P1-T002 create separate Free and Pro plugin directory/bootstrap/**independent Composer 2 PSR-4 manifests + lockfiles + autoload runtime** (Section 16A).
- P1-T003 create activation/deactivation/dependency/version checks, clean error states.
- P1-T004 create Free-owned capabilities and role mapping; REST API auth foundation.
- P1-T005 create shared domain value objects (Money, Currency, References, IDs, statuses) and exact arithmetic tests.
- P1-T006 create DB migration scaffolding with schema version tracking and rollback strategy.
- P1-T007 create WP-native admin routing shell, common UI components and assets build.
- P1-T008 create CI lint/static/unit/integration skeleton with no artificial green statuses.
- P1-T009 verify fresh Free install alone, Pro missing Free handling, HPOS declaration only when testable.

**Gate P1:** both independent plugin packages installable; Free shell works and Pro fails gracefully without Free; tests can run; no security warning or activation fatal.

- P1-T010 build `playground/blueprints/base/blueprint.json`, validate syntax/schema, run WP + WooCommerce smoke test and capture evidence (Section 16E).
- P1-T011 implement canonical release metadata and docs generator scaffolding, verify `README.md`/`readme.txt` outputs and metadata drift checks (Section 16D).
- P1-T012 implement independent production Composer package scripts and clean ZIP smoke-install tests for Free/Pro (Section 16A).

### PHASE P2 — FREE CORE PRODUCT, COMPLETE END-TO-END

**Tasks:**
- P2-T001 generate unique immutable payment reference; test collisions and order-number plugins.
- P2-T002 BACS native settings integration and enabled method detection.
- P2-T003 Thank You, My Account, order emails, classic checkout/Blocks support as actually verified.
- P2-T004 secure customer claim form, guest access, rate limits and privacy copy.
- P2-T005 private attachment storage and access, retention, file validation.
- P2-T006 manual bank evidence record and duplicate warnings.
- P2-T007 manual allocations and full/partial verified balance tracking with safe WC lifecycle.
- P2-T008 payment claim review with states, comments, customer notifications.
- P2-T009 order admin panel and consolidated eligible orders table.
- P2-T010 Free overview KPIs and basic audit timeline.
- P2-T011 Free settings tabs, roles/capabilities, privacy tools.
- P2-T012 build all Free UI screen states and real API interactions.
- P2-T013 Free end-to-end fixture and tests from checkout to customer claim to manual approval.
- P2-T014 complete Free user docs, activation and clean ZIP packaging smoke test.

**Gate P2:** merchant can complete real manual verification without Pro, and customer claims alone cannot mark payments received. No fake/inert UI buttons.

### PHASE P3 — PRO FILE INGESTION AND BANK ACCOUNTS

**Tasks:**
- P3-T001 Pro-owned bank account metadata schema + UI and permission rules.
- P3-T002 CSV parser and raw/canonical row representation.
- P3-T003 configurable mapping profile editor, locale/date/amount/currency validation.
- P3-T004 dry-run preview with row-level errors and collision warnings.
- P3-T005 import batch schema/provenance/states and import history.
- P3-T006 robust transaction fingerprint and unique-ID handling.
- P3-T007 streaming/chunked Action Scheduler jobs, progress/retries.
- P3-T008 CAMT.053 secure XML parser (only release when coverage confirmed).
- P3-T009 import wizard/transactions UI and all empty/error/progress states.
- P3-T010 test re-imports, overlapping files, large fixtures and permission restrictions.

**Gate P3:** import creates trusted provenance records but zero automatic WC payment transitions. Duplicate prevention proven, bank data remains private, large file processing bounded.

### PHASE P4 — PRO MATCHING AND EXCEPTIONS

**Tasks:**
- P4-T001 matching candidate model/explanation codes and eligibility policy.
- P4-T002 unique reference/amount/account/currency matching with exact deterministic rules.
- P4-T003 secondary hints with ambiguity prevention.
- P4-T004 duplicate-suspect and reversal/conflict case generation.
- P4-T005 review case state machine, priority/assignee/comments.
- P4-T006 review queue table, case workspace and evidence comparison UI.
- P4-T007 reviewer actions, reason codes and secure API.
- P4-T008 test false matches, missing ref, cancelled/refunded/already-paid states.

**Gate P4:** every suggestion is explainable; uncertain matches stay in review; no candidate by itself triggers paid transition.

### PHASE P5 — ALLOCATIONS, PARTIALS, SPLITS, APPROVALS

**Tasks:**
- P5-T001 allocations schema/invariant constraints and balance recomputation.
- P5-T002 one-to-one allocation.
- P5-T003 many credits to one order (partial payments).
- P5-T004 one credit to multiple orders (explicit split and limits).
- P5-T005 overpayments/unallocated remainder and exceptions.
- P5-T006 amount/currency/account mismatch handling.
- P5-T007 maker-checker approvals and review locks.
- P5-T008 WC payment_complete orchestration; idempotency, failure recovery.
- P5-T009 reversal/correction event model without silent ledger mutation.
- P5-T010 allocation UI, summaries, review/action modals and audit.
- P5-T011 simultaneous approval/race condition and complex fixture tests.

**Gate P5:** all financial invariants pass including concurrency, reversal, exact money and customer-claim isolation. No partial order is marked fully paid.

### PHASE P6 — REPORTS, NOTIFICATIONS, OPERATIONS

**Tasks:**
- P6-T001 report definitions with per-currency totals and reproducible filters.
- P6-T002 outstanding, unmatched, partial, exception-aging and import summaries.
- P6-T003 safe CSV export, async job limits, audit export permissions.
- P6-T004 notification templates, previews, rate limits and correct recipient controls.
- P6-T005 Pro dashboard KPIs, Saved Views if included, advanced filtering.
- P6-T006 tools diagnostics, failed-job recovery, redacted diagnostics export.
- P6-T007 policies for retention, privacy, backup migration and cleanup.
- P6-T008 full operational admin docs and examples.

**Gate P6:** reports reconcile to ledger source records; exports cannot leak sensitive data to unauthorized roles; notifications do not over-send.

### PHASE P7 — COMPLETE UI/UX, VISUAL SYSTEM, RESPONSIVENESS

**Tasks:**
- P7-T001 finalize all screen schemas UI-001–UI-014 and component states.
- P7-T002 implement native WP spacing, typography, tables, filters, forms, detail drawers.
- P7-T003 verify each field, action, permission and API route is real.
- P7-T004 screenshot 1440/1024/375, high-contrast/RTL/keyboard tests.
- P7-T005 fix misalignment, clipping, overflow, unreadable status/badges, broken search/pagination.
- P7-T006 ensure Free/Pro integration works without duplicated/stale navigation.
- P7-T007 run visual QA rubric, log before/after evidence.
- P7-T008 usability walkthrough for store owner, finance operator and customer with synthetic tasks.

**Gate P7:** no screen has missing fields or fake actions; critical workflows usable with keyboard; visual consistency score meets rubric.

### PHASE P8 — SECURITY, COMPATIBILITY, PERFORMANCE, RELIABILITY

**Tasks:**
- P8-T001 threat model verification and auth/capability matrix tests.
- P8-T002 private attachment and statement access audits.
- P8-T003 SQL/XSS/CSRF/XXE/CSV-injection adversarial tests.
- P8-T004 allocation concurrency/duplicates/stale order/invalidation tests.
- P8-T005 WC HPOS + classic storage support and honest compatibility declarations.
- P8-T006 checkout Blocks/classic behavior and supported WC/PHP versions matrix.
- P8-T007 1k/10k/50k file performance/load test with measured memory/time.
- P8-T008 plugin coexistence, activation, upgrade, schema migration, Pro dependency tests.
- P8-T009 fix security, correctness, reliability and performance defects to gate.

**Gate P8:** financial correctness and security release blockers all cleared with evidence, not only a clean UI smoke test.

### PHASE P9 — DOCUMENTATION, PACKAGING, RELEASE CANDIDATES

**Tasks:**
- P9-T001 generate WordPress.org `readme.txt` + Free/Pro `README.md` from canonical metadata; run documentation drift and readme validator checks (Section 16D).
- P9-T002 complete WooCommerce Marketplace Pro submission evidence, QIT test matrix, reviewer instructions, business/UX compliance, install/license/API docs and fallback behavior (Section 16C).
- P9-T003 generate screenshots from verified production code, guides and examples.
- P9-T004 build deterministic Free ZIP and Pro ZIP with **Composer optimized production autoloaders** and scanned/vendor dependency licenses (Section 16A).
- P9-T005 build/execute actual Free and Pro WordPress Playground Blueprint bundles; also test clean install/deactivate/reactivate/upgrade in MySQL-backed disposable WP (Section 16E).
- P9-T006 generate SHA256SUMS, changelog, validation report, compatibility matrix.
- P9-T007 final acceptance audit vs every feature ID, screen ID and test ID.
- P9-T008 document exact permission/credential-only release blockers and safe publish steps.
- P9-T009 run WordPress Plugin Check and official readme validator with results, prepare WordPress.org SVN dry-run structure and Free submission checklist (Section 16B).
- P9-T010 run available Woo QIT suites and prepare Marketplace Pro evidence pack, screenshots, business case, two-submission freemium plan and reviewer walkthrough (Section 16C).
- P9-T011 validate Blueprint schemas, run Free/Pro Playground tests with screenshots and logs, then cross-check MySQL/MariaDB concurrency evidence (Section 16E).
- P9-T012 lock versions and regenerate readmes/changelogs/release manifests from canonical sources, enforce `docs:check` no-drift CI gate (Section 16D).
- P9-T013 audit actual archive contents: Free includes no Pro code; both include optimized Composer autoloaders, runtime deps, notices and licenses; all install cleanly without Composer on host (Section 16A).

**Gate P9:** independently installable ZIPs, Composer autoload completeness, generated docs, official submission preparation for Free and Pro, functioning Playground bundles + MySQL financial tests, passing release verification, honest compatibility claims; publication only when authorized.

### PHASE P10 — POST-RELEASE QUALITY AND FUTURE BACKLOG

Only start after P9 release candidate passes (or when fixing production-adjacent issues on staging). Maintain safe issue triage, performance optimizations, translated strings, bank CSV profile library, integration hooks, potential MT940/OFX and accessible UX improvements. Do not expand feature scope in a way that postpones core release indefinitely. Prioritize regressions and customer-facing bugs.

---

## 18. BACKLOG STRUCTURE, CAPACITY AND AUTOMATIC TASK SELECTION

Generate one backlog row for **every individual task in P0–P10** with the full task schema. Also create subtasks for each required UI field/action state, high-risk financial invariant, fixture and report. Each task needs: specific file locations, ownership, dependencies, test IDs, feature ID, screen ID if UI, and evidence.

**Scheduler algorithm:**
1. Filter tasks not done/deferred, dependencies verified, safe permitted scope and prerequisites available.
2. Pick critical-path P0 items first, then vertical-slice tasks: functional Free checkout → claim → manual verify → Pro import → match → approval → reports.
3. Prefer P0 financial/security and blocker fixes above polish.
4. Allow safe parallel tasks only when no file overlap/domain inconsistency.
5. After each success, auto-unblock dependents, execute next. Do not spend whole execution budget re-ranking backlog.
6. On external blocker, mark exact reason and select another task; never mark unrelated tasks blocked.
7. On repeated failure, capture repro/test/log; attempt diagnosis/fix; if genuinely environment-constrained, document and move to next unblocked task. Do not retry endlessly without changing approach.
8. When everything feasible is verified, run release matrix and report only remaining genuine blockers/known limitations.

**Reporting per work cycle (short and substantive):** `Completed:` IDs and file/test evidence; `Verified:` exact commands/results; `Blocked:` precise reason; `Next:` one task and first action. No generic "working on it" messages.

---

## 19. REQUIRED FINANCIAL UX COPY AND STATUS LANGUAGE

Use plain, unambiguous language; never imply a deposit has cleared before verified evidence and approval.

**Customer-facing:**
- `Order placed. Awaiting bank transfer confirmation.`
- `Your payment reference: {reference}`.
- `Use this reference in your bank transfer description.`
- `Payment information submitted. We have not confirmed receipt of funds yet.`
- `Your transfer has been verified.` only after authorized approval.

**Admin-facing:**
- `Potential match — not approved`.
- `Imported bank entry — confirmation pending`.
- `Unverified customer claim`.
- `Partial payment — {remaining_amount} still outstanding`.
- `Possible duplicate — review before allocation`.
- `Different currencies — cannot allocate automatically`.
- `Already paid order — investigate before changing records`.
- `This bank credit has an existing allocation`.
- `Approval will record {amount} against order #{id} and may update its WooCommerce payment status.`
- `Reversal will create a correction event; it will not silently undo fulfillment or initiate a refund.`

Distinguish `imported`, `suggested`, `allocated`, `approved`, `paid`, `reversed`, and `settled` in product copy. Define `settled` carefully and avoid using it if the source evidence does not establish bank settlement.

---

## 20. DEVELOPER-TO-MERCHANT APPROVAL BOUNDARY

This project has **two different kinds of approvals** and they must not be confused:

1. **Development decisions:** delegated to AI agents. No repeated human sign-off for UI fields, architecture, backlog, code, QA, documentation or normal next steps.
2. **Financial verification and merchant operations:** remain a secure product behavior. Real merchant/admin reviewers verify bank evidence and approve financial allocations/order-paid status within the plugin. The AI development runner does not simulate merchant approval in production and cannot remove safety gates merely to satisfy "no human consultation".

A third category, public account/credential publication or destructive action, is an external authorization boundary. Agents may prepare release artifacts without publishing and must not falsely represent that publishing occurred.

---

## 21. REQUIRED SOURCE REFERENCES AND VERIFICATION

Use official sources first; verify what actually applies to tested versions:

- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/
- WordPress.org detailed guidelines/trialware/add-on policy: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress.org readme format / header behavior: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- WordPress.org official readme validator: https://wordpress.org/plugins/developers/readme-validator/
- Woo Marketplace submission details, QIT tests and freemium track: https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product
- Woo Marketplace freemium monetization options: https://developer.woocommerce.com/docs/woo-marketplace/monetization-expectations
- Woo extension required plugin headers and Woo-managed field: https://developer.woocommerce.com/docs/extensions/getting-started-extensions/how-to-design-a-simple-extension/
- Woo Marketplace listing image specifications: https://developer.woocommerce.com/docs/woo-marketplace/product-page-content-and-assets/
- WordPress Playground Blueprint data format: https://developer.wordpress.org/playground/blueprints/data-format/
- WordPress Playground bundle resources: https://developer.wordpress.org/playground/blueprints/bundles/
- WordPress Playground Blueprint step reference: https://developer.wordpress.org/playground/blueprints/steps/
- WordPress Playground limitations: https://developer.wordpress.org/playground/developers/limitations/

- WordPress.org Detailed Plugin Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress.org Plugin Directory: https://developer.wordpress.org/plugins/wordpress-org/
- WooCommerce Direct Bank Transfer (BACS): https://woocommerce.com/document/bacs/
- WooCommerce extension compatibility: https://developer.woocommerce.com/docs/best-practices/compatibility
- WooCommerce HPOS extension recipe: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- WooCommerce Cart/Checkout Blocks: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/
- WooCommerce Scheduled Actions: https://woocommerce.com/document/understanding-the-woocommerce-system-status-report/scheduled-actions/
- Action Scheduler source/docs: https://github.com/woocommerce/action-scheduler

Research at actual time of implementation, retain retrieval dates, and adapt to changed guidance. Refer to official API and WC source when uncertain. Do not assume a URL in this list proves compatibility without integration tests.

---

## 22. ACCEPTANCE TRACEABILITY — NOTHING MAY DISAPPEAR

Maintain `docs/testing/traceability.csv` with this schema:

```csv
Requirement ID,Feature ID,Tier,Screen ID,User Role,API/Service,Data Model,Unit Test,Integration Test,E2E Test,Documentation,Status,Evidence
```

**Required audit procedure:**
1. Enumerate all text marked MUST and all detailed screen controls in Sections 4–21.
2. Link each to a feature ID/task ID/test ID/documentation section.
3. Create a backlog item for any missing implementation or test.
4. Refuse to label a phase "complete" if unimplemented requirements are hidden under broad tickets.
5. When a requirement cannot be executed due to missing external access, mark `blocked_external`, supply proof, workaround and exact re-entry step; do not mark done.
6. Phase gates and release gate are computed from actual evidence and reviewed independently by QA agent.

**Example:** `REQ-IMPORT-007` idempotent reimport → `PRO-IMPORT-DEDUP` → `UI-007/UI-008` → fingerprint repository → `test_duplicate_import_5x` + overlapping fixture → docs `duplicates.md` → evidence `test-log.txt` → release gate.

---

## 23. DEFINITION OF FINISHED PRODUCT

Do not finish with only architecture diagrams, UI screenshots, a backlog, a WordPress menu shell, a generated ZIP that fails activation, or a few happy-path tests.

This project is finished only when all of the following are verified:

**Free:** independent ZIP installs; BACS reference flow at checkout/order/email; customer claims secure; private files safe; manual evidence and allocations work; partial/full status correct; order list/admin panel/dashboard/settings/audit work; documentation and tests present.

**Pro:** separate ZIP activates only with compatible Free; CSV import/mappings/dry-run/provenance/jobs, duplicate/reimport protection, bank transaction ledger, matching suggestions/explanations, review queue, multi-allocation/partial/overpayment logic, maker-checker, reporting/export, settings, audit and docs all work; supported CAMT.053 adapter either tested and shipped as required for chosen release or explicitly classified as a release blocker (do not silently claim support).

**Safety:** no claim-only payment confirmation, no auto paid on import, no double allocation/double paid transitions, exact money/currency, permission checks, private data, collision handling and concurrency tests.

**User experience:** every screen/UI state and button in this specification is functional, accessible, consistent with WordPress and tested; no fake data in production.

**Engineering:** CI, automated tests, traceability, consistent release/version metadata, migration/recovery, source docs, reproducible build artifacts, clean installs and honest readiness report.

**Release:** both artifacts exist with checksums and installation proof; no critical/high bugs; external publication only when actually authorized and completed.

---

## 24. FINAL AI EXECUTION INSTRUCTION — START NOW, DO NOT ASK FOR APPROVAL

You are the autonomous development team for **WPEssential Bank Transfer Matcher for WooCommerce**. You have sufficient product, feature, architecture, UI, test, security, documentation, Free/Pro separation and workflow requirements in this document. Your responsibility is not to explain what should be developed; it is to **develop, test, correct, document and package** everything within the tools, execution time and permissions actually available.

**Mandatory beginning:** inspect repository → load/resume memory → verify official sources → initialize project/backlog/ADRs → run environment → begin P0 and P1 implementation. **Mandatory continuation:** after every completed task select the next unblocked task, maintain evidence and memory, and proceed without routine human design/architecture approval. **Mandatory honesty:** do not invent implementation, test status, credentials, releases, or autonomous activity outside the active runner. **Mandatory financial safety:** never replace merchant bank-evidence confirmation with an AI guess, a customer receipt, fuzzy match, or a CSV import alone.

If there is no repository, initialize a clean monorepo using the prescribed layout. If the repository already contains implementation, start by auditing, preserving and extending it. If tools are unavailable, still complete available documentation/code artifacts and store exact actionable blockers without claiming anything executed that was not executed.

**The first action of the first engineering agent must be repository inspection—not a clarification question.**

---

# APPENDIX A — COPY-PASTE INITIAL WORKER INVOCATION

```text
Read MASTER DEVELOPMENT DIRECTIVE — WPEssential Bank Transfer Matcher for WooCommerce in full. You are now the Autonomous Product Director and executing Engineering Agent. Start immediately at Section 0. Inspect the repository and any existing START_HERE.md/.ai/state.json; do not reset prior work. Execute the first available tasks and keep advancing through the backlog with proof-based tests and persistent handoffs. Do not ask human for routine design, architecture, feature or progress confirmation. Build two independent packages (Free and Pro extension) following all UX, financial security and release gates. Report only actual inspected/changed/tested evidence and concrete external blockers. Continue while your runner is active, and persist a precise next action when its session ends.
```

# APPENDIX B — MINIMUM START_HERE.md CONTENT

```markdown
# START HERE — WPEssential Bank Transfer Matcher

## Mission
Build and test Free + Pro WordPress plugins per the Master Development Directive.

## Repository state
- Branch: [inspect dynamically]
- Commit: [inspect dynamically]
- Current phase: [load from .ai/state.json]
- Current task: [load task ID]

## Verified status
- Last actual tests: [commands and results; never assumptions]
- Last checked official docs: [date and sources]
- Last packaged artifacts: [actual paths or none]

## First executable command / next action
[the single actionable next step; e.g. inspect WC plugin APIs and implement reference service]

## Blocked items
[exact issue, logs, workaround, external permission needed]

## Development rules
No routine approval interruptions. Respect separate Free/Pro code, WC CRUD, exact money, approval safeguards, idempotency, safe uploads and truthful evidence. Execute next unblocked task, persist context, repeat while runner is active.
```

# APPENDIX C — MINIMUM DEVELOPER WORK LOG TEMPLATE

```markdown
## YYYY-MM-DD HH:MM UTC — Task WBM-Px-Tyyy
- Objective:
- Before state:
- Actual files changed:
- Database/REST/UI changes:
- Commands run:
- Test output/path:
- Screenshots/path (if UI):
- Financial-security checks:
- Accepted / failed acceptance criteria:
- Decisions/ADRs:
- Blockers:
- Next task / first action:
```

# APPENDIX D — IMPLEMENTATION ANTI-PATTERNS TO REJECT

- Pro features implemented as hidden classes/assets inside the Free WordPress.org ZIP.
- Single giant React screen with fake menus and static dummy table data.
- Inert buttons, non-persisting settings, unimplemented filters or links to future features presented as working.
- Matching two payments solely because the amounts are equal.
- "99% confidence, mark as paid" logic.
- Creating a WooCommerce paid status immediately after receipt upload or CSV import.
- Using floating-point money or silently mixing currencies.
- Treating same-value same-day transfers as necessarily duplicates.
- Importing the same money more than once across overlapping statement files.
- Modifying WooCommerce order storage directly rather than CRUD/HPOS-safe APIs.
- Publicly accessible receipts, raw statement files or unredacted logs.
- Skipping concurrency, cancelled/refunded order cases and data migration tests.
- Calling a UI visually correct without screenshots and real flow tests.
- Claiming WordPress.org/Woo Marketplace approval, production release, Figma creation or tests without actual evidence.
- Claiming Composer PSR-4 is configured when `vendor/autoload.php` is missing from distributable ZIP.
- Treating `README.md` as WordPress.org `readme.txt` without format conversion and validator tests.
- Calling an unbuilt Playground template a working Free/Pro demo.
- Using a Playground SQLite-only pass to certify MySQL/MariaDB financial concurrency safety.
- Manually inventing a Woo Marketplace `Woo:` header/product ID or marking QIT checks passed without real evidence.
- Asking human to pick ordinary labels, table columns, theme colors, API routes or which module to implement next.
- Continuously rewriting plans and never building working code.
- Losing backlog/context between sessions and re-implementing completed work.
- Shipping a plugin dependent on paid AI inference, despite local-first product scope.
- Continuing financial/deployment operations outside permissions or fabricating work after the runner stops.

**END OF MASTER DEVELOPMENT DIRECTIVE.**
