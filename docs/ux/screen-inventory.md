# Screen inventory and interaction contracts

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
