# ADR-0002 — Pro allocation ledger foundation (P5-T001)

Status: P5-T001 verified in run 44602497110; P5-T002 proposal requires CI verification.

Free owns the existing `wbm_manual_allocations` table and payment approval APIs. Pro owns `wbm_pro_allocations` for imported transaction-row allocations, with source fingerprint, receiving account, exact integer minor units, expected/credit snapshots, states, actors, timestamps and indexed source/order/account lookups. These records have distinct provenance, not interchangeable bank credits. Read-only balance computation includes Free manual allocations for the same order and counts only Pro `approved`/`committed` rows; proposed and reversed rows are excluded. Never cast floats or unsigned SQL BIGINTs beyond PHP_INT_MAX.

P5-T002 introduces only an internal capability-protected PROPOSED record with exact source/order validations, named locks, unique request idempotency and an atomic append-only event. Proposals spend no credit, never approve allocation, never change WooCommerce payments and have no REST route. A later maker-checker gate must approve them. Before adding one, verify the source is a valid unique non-duplicate credit from a completed import batch; exclude manual and imported duplicate representations; lock source AND order in deterministic order, re-fetch current WooCommerce order status, currency, expected balance, and recheck all allocations inside the transaction. Require idempotency, approval authority, audit and recovery. The read-only snapshot is not authorization and cannot trigger `payment_complete()`.

No WC order foreign keys (HPOS compatibility). Native MariaDB schema, Free coexistence, concurrent approval, reversal and retention gates remain separate before release.
