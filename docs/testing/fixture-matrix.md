# Synthetic fixture inventory

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

All entries are planned. Create only synthetic order and statement records.
