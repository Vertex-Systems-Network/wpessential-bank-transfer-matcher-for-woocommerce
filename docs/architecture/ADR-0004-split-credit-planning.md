# ADR-0004 — P5-T004 source-to-many-orders preview

Status: preliminary read-only implementation; all mutation, approval and payment gates remain open.

A single bank credit can be explicitly divided among more than one eligible WooCommerce BACS order, subject to live exact minor-unit financial limits. The pure \`SplitCreditPlanner::preview\` validates the available bank credit, previously approved allocations, distinct target order IDs, per-order outstanding amounts, currency/exponent equality, and all order and source caps. No floats or implicit FX are supported. The result reports both bank-unallocated and order-outstanding remainders and is marked \`review_only\`.

**Not yet implemented:** source-backed validation of an approved unique bank credit, multi-order named locks acquired in deterministic order, maker-created split proposals, independent checker approval, all-order idempotency, append-only events, partial refunds and reversals, or a UI for choosing allocation amounts. None of these may be inferred from the preview. Only after all order and credit checks inside a single SQL transaction can a split ever be approved. A split must not call \`payment_complete()\`; that is guarded separately by P5-T008.

**Release gates:** cross-order Free/Pro duplicate-deposit provenance, concurrency stress and recovery, corrected historical allocations, and capability/API review.


## Internal atomic split write path (verification pending)

A Pro-internal \`SplitCreditAllocation\` service now proposes and approves one **complete** uniquely imported positive credit divided between 2–25 different unpaid BACS orders. One imported bank reference must match an immutable reference of at least one target order; any nonmatching recipient requires an explicit merchant distribution decision. The maker specifies exact per-order minor units and a unique request key. Proposal rows share \`split_group_key\` (migration v3) and each generates an append-only event. A separate approver confirms bank clearance, rechecks the bank source/account/identity, every target order/amount, and Free + Pro balances before one SQL transaction updates every allocation and writes its audit events.

The workflow locks the imported source and **all** target orders via deterministic sorted advisory locks. It rejects duplicate sources, changed order totals, over-allocations, stale source warnings and partial group approval. Legacy individual one-to-one approval explicitly refuses split-group rows. The source cannot be spent elsewhere while this group exists. The credit is allocated in full or not at all; future partial-source top-ups and reversals remain separate gates. No REST routes, automatic matching approval, or WooCommerce paid transitions have been introduced.

Native disposable WooCommerce regression fixture now checks two-order split proposal/approval, conflicting attempts, maker-checker separation, stale source warning/changed order, idempotent replay, exact approved ledger and unpaid orders. These new changes must pass CI before P5-T004 can be marked done.
