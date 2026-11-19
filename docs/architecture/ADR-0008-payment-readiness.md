# ADR-0008 — P5-T008 explicit payment readiness and finalization boundary

Status: preliminary **read-only** policy; no Pro WooCommerce paid transition implemented yet.

\`PaymentReadiness::inspect\` accepts exact typed snapshots of total order amount, approved Free and Pro allocations, count of approved Pro rows, pending allocation reviews, unresolved exceptions, order payment gateway/status, currency precision and verified provenance/independent reviewer/bank-account binding facts. It rejects malformed snapshots and overdrawn balances; a partial order, open case, unverified source, missing receiving bank confirmation, missing maker/checker signoff or paid/invalid WooCommerce order is not ready.

A \`ready_for_explicit_finalization=true\` result only indicates that a separate finance authorization action **may** proceed to deeper live checks. The policy always returns \`review_only=true\` and \`auto_pay_allowed=false\`. It is not bank verification and must never be directly wired to an automated import job, customer claim or front-end status transition.

Remaining P5-T008: implement a Pro-only, explicitly authorized and idempotent payment finalizer that revalidates every cleared source against immutable imported provenance, duplicate-source holds, active account/confirmed receiving bank, maker/checker event history, all Free manual evidence and Pro approved allocations, and the exact WooCommerce order total under an order lock. Persist pending/committed intents for crash recovery; call WooCommerce \`payment_complete()\` **outside any SQL transaction**, with clear recovery paths for WooCommerce hook failure or partial auditing. No live payment processing or release until CI verifies end-to-end.
