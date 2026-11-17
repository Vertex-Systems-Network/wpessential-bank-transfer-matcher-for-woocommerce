# Free and Pro feature inventory

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
