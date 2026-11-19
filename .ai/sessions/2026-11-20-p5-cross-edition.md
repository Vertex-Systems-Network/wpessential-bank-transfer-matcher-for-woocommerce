# Handoff — P5-T003 Free/Pro balance invariants

- Existing branch development/autonomous-release-track; previous commit 803ca001047d5cc2653f72e779bb5640f4b63cc9.
- P5-T003 native multi-credit integration has passed its native job (runs 44611205272 and 44611217703); complete Playground jobs not yet verified as of checkpoint.
- This commit closes a Free writer oversight: it now counts approved/committed Pro allocations under the same order lock so Free evidence cannot exceed an order's combined credit balance. Combined returned totals now reflect Free+Pro, and stale amount/currency raises a conflict.
- Added native `tests/integration/free-pro-allocation-balance.php` validating Pro 7000 + Free 3000, rejecting Free 6000 and late duplicate/overallocation, with WooCommerce remaining on hold.
- **New cross-edition code is not yet CI verified**. Pro/Free exact same real-world bank credit detection remains a source-identity blocker until explicit risk control and tests are in place.
- No release, merge, paid-status mutation or live financial data.
