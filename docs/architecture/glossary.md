# Domain glossary

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
