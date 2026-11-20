# Handoff — P5-T010 initial WordPress-native read-only allocation UI

- Branch development/autonomous-release-track, parent 5f7d56585cbc80956ea1917a197f750dc9e61bba; P5-T009 complete/verified, P5-T010 active, draft PR #9 unmerged.
- Added Pro `Admin/AllocationReviewPage.php` registered under WooCommerce, gated by Free approval + Pro import. Read-only GET-filtered ledger, currency/state-specific exact page totals, split group provenance, bank reference masking, current WC order evidence, append-only allocation audit, correction case hold and event timeline.
- Native `pro-split-credit.php` fixture now renders the queue and workspace with a paid two-order split, independently reviewed correction case and source-wide financial hold; asserts no mutation form. Separate `pro-allocation-ui.php` checks empty state/filters. Both invoked through `scripts/test-native-install.sh`.
- **All new P5-T010 UI code pending CI.** Do not implement mutation controls without independent nonce/capability/dual-review request idempotency and native tests.
- No merge, release, bank action or marketplace submission.
