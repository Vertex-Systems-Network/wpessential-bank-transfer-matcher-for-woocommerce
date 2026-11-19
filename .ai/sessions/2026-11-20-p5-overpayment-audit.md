# Handoff — P5-T005 persisted exception workflow

- Branch development/autonomous-release-track; previous parent 6e280fb1e782522f5c91b387d0d4a1f4e737d779. Draft PR #9 remains unmerged.
- Pure exact-money `OverpaymentReview` unit policy and test added earlier. Pro `OverpaymentExceptionRepository` now stores source-linked exceptions and independent review-only annotations; no allocation, refund or WooCommerce paid transitions.
- Added `tests/integration/pro-overpayment-exceptions.php` and native CI invocation. It validates a $120 source against $100 order, stale order/ambiguous source, reviewer separation, idempotent replay, append-only events and unchanged ledger.
- New code unverified until CI. Review-only decisions never imply financial settlement; actual return/reallocation remains a separate authorized operation.
- No merge, release, real bank data or marketplace submissions.
