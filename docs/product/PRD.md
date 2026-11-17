# Product requirements

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

## Journeys

- Customer receives a reference on an eligible order and can submit a claim.
- Finance operator records merchant-observed evidence and proposes an allocation.
- Authorized reviewer confirms the allocation and reviews the audit event. Eligible orders reach WooCommerce payment completion only at the verified balance threshold.
- Pro operator maps and previews a statement, imports selected rows and routes exceptions to review.

## Principal risks

False confirmation, double allocation, stale balances, private evidence disclosure, and recovery after an interrupted WooCommerce transition are release gates.
