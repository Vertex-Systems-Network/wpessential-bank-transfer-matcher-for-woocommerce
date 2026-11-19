# ADR-0008 — P5-T008 explicit payment readiness and finalization boundary

Status: the pure readiness policy and internal, explicitly invoked Pro finalizer have both passed native and full CI regression. The new HPOS double-mode and strict-ledger recomputation changes are awaiting their own full CI verification; no public Pro finalization route or import automation exists.

\`PaymentReadiness::inspect\` accepts exact typed snapshots of total order amount, approved Free and Pro allocations, count of approved Pro rows, pending allocation reviews, unresolved exceptions, order payment gateway/status, currency precision and verified provenance/independent reviewer/bank-account binding facts. It rejects malformed snapshots and overdrawn balances; a partial order, open case, unverified source, missing receiving bank confirmation, missing maker/checker signoff or paid/invalid WooCommerce order is not ready.

A \`ready_for_explicit_finalization=true\` result only indicates that a separate finance authorization action **may** proceed to deeper live checks. The policy always returns \`review_only=true\` and \`auto_pay_allowed=false\`. It is not bank verification and must never be directly wired to an automated import job, customer claim or front-end status transition.

Remaining P5-T008: verify classic + HPOS payment completion and immutable audit recovery on the same code revision, including strict rejection of malformed database money. Mixed Free/Pro evidence intentionally remains blocked pending equivalent independent manual bank-source approval. No live payment processing or marketplace release before those gates.


## Explicit Pro payment completion with durable replay (CI pending)

A Pro-internal \`ProPaymentFinalizer::complete\` now takes the order ID, the authenticated finance reviewer identity, a stable SHA-256 request key, **fresh explicit bank-clearance confirmation**, and an auditable reason. The caller may only have both Free approval and Pro import capabilities; the same person who originally proposed any of the sources cannot finalize. The finalizer has **no REST route, scheduled task, importer callback, or customer-claim trigger**.

Under deterministic source and order named locks, it revalidates the complete exact-money Pro ledger; duplicate/flagged imported bank source identity, completed batch and active account; confirmed merchant receiving account; immutable reference or checked split reference anchor; order source snapshots; independent maker/checker state and audit events; and unresolved exception/mismatch holds. Other users cannot spend source/order funds while the payment is being finalized. A Pro-only policy deliberately blocks any Free manual evidence for now, because Free manual allocation does not have equivalent independent bank-source approval provenance. A Free-only order continues using its existing Free finalizer.

A Pro-owned \`wbm_pro_payment_finalizations\` intent stores the immutable approved-ledger SHA-256 hash, user, request key and pending/completed state. The pending intent is committed before WooCommerce hooks. \`payment_complete()\` executes **outside SQL transaction**, while the named locks remain held. Only after WooCommerce confirms its paid status are Pro rows marked \`committed\` with append-only commit events and the intent marked completed, transactionally.

If WooCommerce has become paid but an exception interrupted subsequent auditing, a repeat **explicit identical finance action** validates the same ledger and pending intent, writes missing audit, and returns \`audit_recovered\` WITHOUT repeating WooCommerce hooks. An arbitrary paid order without a matching pending intent is not treated as verified. Post-payment audit failure intentionally leaves a visible unresolved state requiring manual recovery; it is not silently swallowed.

A native disposable WooCommerce regression now challenges unauthorized actor/self-finalization, missing clearance, underpaid order, warned source, independent session lock contention, normal payment, duplicate submission and WooCommerce hook failure/recovery. These changes remain **unverified until CI passes**. Split group paid transition, fully funded multi-credit order, mixed Free/Pro policy and cross-order duplicate provenance require additional release-gate regression.
