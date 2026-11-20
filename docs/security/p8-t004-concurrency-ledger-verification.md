# P8-T004 — Real MariaDB ledger contention, replay and stale-state security gate

**VERIFIED** at exact code SHA `258ef616263400999c74dd7ba424432e921ccfcf` on 2026-11-21. GitHub [Foundation/Playground/native 44819128262](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44819128262), [second Foundation/native 44819139195](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44819139195), and [Chromium actual LTR+Arabic RTL 44819128256](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44819128256) are **SUCCESS**, five checks green. Real browser artifact ID **14671287140**. No external merchant transaction/cleared bank funds or live order was touched.

## Native verification
- New `tests/integration/p8-stale-ledger-invalidation.php`, wired in `scripts/test-native-install.sh`, runs against packaged actual Free+Pro, WooCommerce and disposable MariaDB `wbm_ci`.
- A real WooCommerce BACS order receives synthetic reference and a confirmed synthetic receiving-bank account; one completed import batch contains an unverified, nonduplicate 5000-minor-unit credit.
- Proposal idempotency key replay preserves the same single proposed ledger row. A different request key targeting the same source **fails** before a second proposal is persisted.
- Order cancelled **after proposal** is ineligible for reviewer approval. Restore on-hold only within this synthetic test.
- Marking source `possible_duplicate`, mutating its canonical amount or inserting another source with the same identity fingerprint each blocks review. No review audit event or outstanding-balance change is allowed on those failures.
- A genuinely separate MariaDB connection holds the named bank-source lock; attempt to approve from another session is rejected. Lock release restores legitimate review.
- Explicit second finance user approval succeeds only in clean state, then same-reviewer replay returns `already_approved`; exactly 2 audit events (proposal and approval), outstanding/order credit balances reflect one allocation, and **the WC order remains unpaid**. No `payment_complete()` called.
- Expected native output: `PASS P8-T004 real MariaDB cancelled-order/CSV provenance/credit tamper/fingerprint replay/source lock/idempotent maker-checker: no unintended Woo payment`.

## Existing independent regression evidence
- `tests/integration/pro-approval-locks.php`: independent SQL order and source advisory locks, conflict rejection, maker/checker, stale retry and no extra audit rows.
- `pro-one-to-one.php`: one source/one order, duplicate proposal and request replay, correction holds and source warning drift.
- `pro-many-credits.php`: multiple imported credits, duplicate provenance and order amount change between proposal and approval, no over-allocation.
- `pro-split-credit.php`: two-recipient split group atomicity, recipient/source locks and no partial approvals.
- `pro-payment-finalizer.php`: explicit separate completion, durable pending payment intent, payment hook replay and source concurrency; only synthetic orders.
- `pro-overpayment-exceptions.php`: manual exception holds, order drift and no automatic refunds.

## Coverage boundary
Tests prove real named-lock contention across two MariaDB connections, transactions/replays and synthetic drift, **not** statistical load across many simultaneous OS processes or all database failure/replica configurations. No production bank clearance was simulated. Further performance/compatibility/activation/release tasks P8-T005–T009 and P9 must remain separate gates.
