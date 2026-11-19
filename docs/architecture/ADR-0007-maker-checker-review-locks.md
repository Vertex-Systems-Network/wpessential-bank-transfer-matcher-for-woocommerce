# ADR-0007 — P5-T007 maker/checker identity and source/order serialization

Status: verified in GitHub Actions run 44626679846.

A separate authorized WordPress finance actor must attest cleared funds for a proposed Pro allocation. One-to-one and split-group approval paths reject the original proposer as reviewer, use compare-and-swap state changes and append-only approval audit, and never mark a WooCommerce order paid during allocation. Source and order MySQL named locks are acquired deterministically; Free's manual allocator shares the order lock.

Native WordPress/WooCommerce/MariaDB tests now use an **independent database connection** to hold order and source locks while a second reconciliation attempts to proceed. They assert that blocked maker proposals and reviewer approvals leave the ledger and audit unchanged. They also challenge a split group while a different recipient order or the shared imported credit is locked, ensuring no partial group approval.

Separate P5-T008 finalization must verify the full approved ledger and human authorization before any WooCommerce side effect. Release-wide multi-process cross-account/cross-order provenance and failure-injection stress are still gates in P5-T011/P8.
