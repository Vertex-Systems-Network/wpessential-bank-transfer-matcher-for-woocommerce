# ADR-0003 — P5-T003 multi-credit preview boundary

The Pro read-only \`PartialCreditPlanner\` accepts merchant-selected proposed amounts from distinct bank credits, an existing approved allocation balance, and an explicit order currency and decimal exponent. It returns exact remaining balances in integer minor units without floating point arithmetic. Duplicate source IDs, overdrawn source balances, overdrawn orders, incorrect currencies/precision, zero amounts, and float values fail closed. Its result is \`review_only\`: it is not bank evidence, allocation approval or WooCommerce payment authorization.

**Remaining work for P5-T003:** connect the preview to real validated completed source rows, persist per-credit proposals with unique idempotency and reviewer events, revalidate inside source/order locks, support partial coverage and later additional credits, keep the order unpaid until explicit full-settlement finalization, and add native concurrency/error regression tests. Bank imports and customer claims never approve payments.


## Source-backed many-to-one approval extension (P5-T003)

A single imported credit continues to be consumed in full by at most one order in this phase (splitting a credit across orders remains P5-T004). The source-scoped conflict check now allows distinct imported credits to fund the same WooCommerce order. Each credit requires completed unique positive source evidence, exact immutable order reference, identical currency/precision, active bank account, finance maker proposal, and independent checker approval. The checker re-computes the combined Free + Pro outstanding amount under the order and source locks. Proposals do not reserve balance; an over-subscribed proposal fails at approval instead of silently over-allocating.

The pure \`PartialCreditPlanner\` is used as a fail-closed exact-money guard in both proposal and approval paths. The bank source balance must remain wholly unallocated at approval, with no cross-order splits until P5-T004. The WooCommerce order stays on-hold even when all Pro allocations cover it; \`payment_complete()\` stays behind P5-T008. API and UI remain unexposed pending review controls.

Regression test \`tests/integration/pro-many-credits.php\` provides three source rows covering a single USD order; checks duplicate source conflicts, newly ambiguous source identity, stale changed order totals, over-allocation, append-only audit, and remaining outstanding balances. Concurrent multi-request and Free/Pro mixed-write adversarial verification remain explicit later safety gates.


## Cross-edition allocation guard

Free's manual allocation writer shares the deterministic order lock with the Pro approval path. It must also count all Pro \`approved\` and \`committed\` allocations in the same order, even when the Pro plugin is inactive but historical Pro tables are still installed. It rejects mismatched order totals/currencies or a combined overdraw. Its returned \`allocated_minor\` and \`outstanding_minor\` represent the combined approved financial balance, not just Free allocations. P5 tests cover a Pro $70 credit plus Free $30 credit against a $100 order, both over-allocation directions and unchanged WooCommerce on-hold status. Existing Pro reader already includes Free allocations.

Exact duplicate provenance between a manually entered credit and an imported statement row still requires additional source identity investigation. Treat it as a release-blocking safeguard until explicitly tested; overlapping evidence must never be silently assumed to represent independent funds.
