# Handoff — P5-T006 explicit receiving bank confirmation

- Branch development/autonomous-release-track; parent fddf93d833a3483127ed8c6974e39af0a7dac61f; draft PR #9 retained.
- Prior P5-T006 classifier PHP and native CI passed; both prior full runs failed only because WordPress Playground couldn't download WooCommerce from downloads.wordpress.org. Code-related failure not observed.
- Added `OrderReceivingAccountRepository` (Pro-owned SQL table and immutable audit); requires both account-management and Free approval capabilities, active account and matching order currency. No binding is inferred from bank source.
- Pro OneToOneProposal, OneToOneApproval and SplitCreditAllocation now reject absent/mismatched confirmed account binding.
- Existing one-to-one, many-to-one and two-order split native fixtures were updated to confirm bank accounts first. **New code unverified until CI.**
- Next: add source-backed mismatch case lifecycle and tests; ensure Free/Pro balance, maker-checker, and no payment_complete are preserved. No merge or release.
