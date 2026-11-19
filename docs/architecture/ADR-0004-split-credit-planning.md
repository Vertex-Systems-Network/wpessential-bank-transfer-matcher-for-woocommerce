# ADR-0004 — P5-T004 source-to-many-orders preview

Status: preliminary read-only implementation; all mutation, approval and payment gates remain open.

A single bank credit can be explicitly divided among more than one eligible WooCommerce BACS order, subject to live exact minor-unit financial limits. The pure \`SplitCreditPlanner::preview\` validates the available bank credit, previously approved allocations, distinct target order IDs, per-order outstanding amounts, currency/exponent equality, and all order and source caps. No floats or implicit FX are supported. The result reports both bank-unallocated and order-outstanding remainders and is marked \`review_only\`.

**Not yet implemented:** source-backed validation of an approved unique bank credit, multi-order named locks acquired in deterministic order, maker-created split proposals, independent checker approval, all-order idempotency, append-only events, partial refunds and reversals, or a UI for choosing allocation amounts. None of these may be inferred from the preview. Only after all order and credit checks inside a single SQL transaction can a split ever be approved. A split must not call \`payment_complete()\`; that is guarded separately by P5-T008.

**Release gates:** cross-order Free/Pro duplicate-deposit provenance, concurrency stress and recovery, corrected historical allocations, and capability/API review.
