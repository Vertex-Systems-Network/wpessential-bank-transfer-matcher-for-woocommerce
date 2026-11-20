# ADR-0011 — P5-T011 real cross-process finance races

Status: VERIFIED in GitHub Actions run 44642819046 (Foundation, Playground and real native WordPress/WooCommerce/MariaDB) and sibling run 44642809909 native.

Sequential SQL advisory lock tests are necessary but insufficient for a realistic WooCommerce deployment. The disposable native MariaDB harness now launches independent WordPress CLI processes against the same bank credit, BACS order and financing ledger. A filter on the **first START TRANSACTION after the repository has obtained the source/order named locks** creates a bounded test signal while the first process holds those locks. A second process makes the same or competing request while the first sleeps.

The P5-T011 fixture challenges simultaneous maker proposals, checker approvals, and explicit Pro WooCommerce payment finalization. It also launches a Free manual-evidence allocation against the same order during those Pro operations. A further split fixture uses one bank credit across two distinct BACS orders: it challenges atomic split-group approval and a Free allocation to recipient 2 while Pro finalizes recipient 1. This validates the finalizer's ALL-recipient source/order lock acquisition.

Each stage checks exactly one immutable source allocation, the expected proposed/approved/committed audit events, complete exact-money source/order conservation, independent maker/checker, no partial paid status and no duplicated WooCommerce payment hooks on an explicit replay. No live bank operation or unattended payment action is present; race fixtures run only in the disposable wbm_ci database.

Cross-host network partition simulation and adversarial resource exhaustion remain future release gates. Review-only correction and exception holds must continue to block funded allocations without refund or silent reversal.

## Verified outcome

The native CI log records each overlapping competing maker/checker/finalizer, the Free manual competitor, the atomic split-group reviewer race, both split recipients' independently audited WooCommerce payment completion, and exact conservation. No duplicate allocated source, financial event or payment hook was observed in these synthetic tests. Cross-host network partitions and operational failure injection remain release work.
