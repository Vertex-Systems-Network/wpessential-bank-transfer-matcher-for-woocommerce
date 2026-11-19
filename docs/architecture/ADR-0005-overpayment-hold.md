# ADR-0005 — P5-T005 overpayments and unallocated source remainder

Status: preliminary exception **classification**, not a financial mutation or settlement workflow.

When a positive imported bank credit exceeds the currently outstanding order balance, the excess must be held as an **unallocated source remainder** and explicitly reviewed. A bank deposit is not automatically a refund, order overpayment, or approved order payment. A smaller bank credit leaves an open order balance; approval of that partial credit never marks the order paid.

The pure \`OverpaymentReview::assess\` compares immutable integer minor-unit amounts: order expected/allocated, source amount/allocated, and a proposed allocation. It returns the maximum safe review context, outstanding balance, bank remainder, overpayment amount, and exception codes: \`overpayment\`, \`unallocated_credit_remainder\`, \`partial_order_funding\`, \`no_order_balance\`. It rejects overdrafts, negative inputs, bad currency precision and invalid snapshots. Its result is explicitly \`review_only\` and \`order_payment_changed=false\`.

**Remaining P5-T005:** create persistent source-linked review case, store source/order/currency/snapshot and event provenance, support reviewed exception lifecycle and replay-safe resolution without automatic allocation or refunds, and test native WooCommerce/MariaDB recovery. No WooCommerce payment completion belongs in this task.
