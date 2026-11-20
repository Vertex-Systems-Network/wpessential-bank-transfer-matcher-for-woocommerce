# 2026-11-20 P7-T006 navigation correction (append-only session)

- Inspected main HEAD `772016945838faf60635b816c48343d23e355240`, GitHub Issue #7, P7 PR #13, START_HERE, task graph and real Free/Pro admin menus.
- Found P7 PR #13 merged while `WBM-P7-T006` remains in progress and T007/T008 remain planned. Main Foundation CI 44684986238 was green, but did not test the absent navigation matrix.
- Created `development/p7-navigation-role-gates-20261120` from that HEAD.
- Corrected six Pro WooCommerce submenu registrations to require their existing full composite finance caps; no finance flow/money state changed.
- Added `tests/integration/p7-menu-role-matrix.php` and wired three independent modes into native CI.
- **Verification**: committed to GitHub only; CI has been triggered but is NOT considered passed until exact latest source SHA is verified. No local WordPress/MariaDB runtime or manual browser evidence in this session.
- Remaining: inspect CI; repair failures; browser RTL/keyboard/nav role walkthrough; P7-T007 visual QA rubric and T008 role usability; update all generated state/docs and phase gate before any merge. Issue #7 stays open.
