# Handoff — P5-T003, many imported credits fund one WooCommerce order

- Development branch: development/autonomous-release-track (draft PR #9), parent f8ee0b750649507e6e05ade8d1d267509ba7aa65.
- Read-only multi-credit planner passed all CI jobs in 44608365274 and 44608355773.
- Extended original one-to-one maker/checker classes to allow a whole credit less than the remaining order balance while retaining exclusive source ownership (no splits). Proposals keep order balances unchanged. Approvals recheck live source, exact reference, permissions, order/currency/amount snapshot, and combined Free/Pro ledger balance inside deterministic order and source locks.
- New native test `tests/integration/pro-many-credits.php` includes three credits completing a $100 internal balance; duplicate, drift and over-amount rejection; order remains on-hold even if internal balance is zero.
- **This new implementation is not verified until a new CI run is green.** If CI passes, consider P5-T003 acceptance and move to P5-T004 split-credit work.
- No paid-order transition, no bank transfer APIs, no automatic imported-credit approval, no release or merge.
