# ADR-0010 — P5-T010 native allocation summaries and finance audit workspace

Status: in progress.

A finance allocation workspace must remain WordPress/WooCommerce-native, using normal admin menu entries, scoped capabilities, real ledger/event/provenance data and exact per-currency minor units. It must show pending versus independently approved or committed allocations, split source links, mixed Free/Pro balance holds, and correction/dispute audit without treating a review case as a completed bank refund.

The first UI is explicitly **read-only**: no import action, payment completion, refunds, reversal, approval, or REST mutation route is exposed. Read-only filters, linked order snapshots and append-only audit history can be shipped once permission, escaping, malformed money, HPOS and native tests pass. Later P5-T010 maker/checker action modals require dedicated nonce/capability/CSRF/idempotency, dual-actor state checks and further integration tests. A UI button must never bypass the existing internal service contracts.
