# Handoff — reviewer lock contention regression staged

- Branch development/autonomous-release-track; parent dcc1f4496af9e9cadb244d1fe97b4c744eb0fadc; P5-T006 remains active until source-backed mismatch CI fully green.
- Added preparatory P5-T007 native fixture to test real MariaDB advisory locks held by a **separate wpdb connection**, blocking both maker proposal (order lock) and independent checker approval (source lock) without ledger or audit mutation. It tests self-approval refusal, missing clearance and idempotent final approval; WooCommerce remains unpaid.
- This preliminary P5-T007 test has not been verified. It does not change production approval code. Inspect CI and correct any failure.
- No release, merge or live financial data.
