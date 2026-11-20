# Phase P6-T004 — Notification opt-in, privacy and anti-spam checkpoint (2026-11-20)

## Current status

**In progress.** The task graph has 67 done tasks. P6-T003 was genuinely verified against exact source SHA `13bbbee` by full GitHub Actions [44653642849](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44653642849). The next action is to wait for *current-head* full CI on [draft Phase PR #12](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/pull/12), correct actual failures, mark T004 done with evidence and advance to WBM-P6-T005. **Do not merge P6** before all T001–T008 and P6 financial/notification safety gate complete.

## Implemented

- Fixed plain-text, no customer/bank data template choices: exception, pending review, mismatch, older review reminder, with safe preview label.
- Opt-in settings default **off**, allowed types and max-10 recipient IDs strictly normalized, no arbitrary email input. Save/test limited to logged-in Free APPROVE + Pro IMPORT + Pro MANAGE_ACCOUNTS actor, purpose-specific nonce.
- Daily Action Scheduler digest created only after an authorized opt-in, source-backed unresolved review/exception/mismatch checks, explicit opt-out removes schedule.
- Both real scheduler and explicit test mail recheck live finance recipient capabilities and email validity. Per-recipient database named lock + reserved-before-send audit provide at-most-once behavior, maximum two accepted sends in any rolling 24h and one same template/type in that window. No attachments or financial state changes.
- Native WooCommerce Finance Notifications settings page: privileged recipient selection, local previews (no mail), explicit opt-in and send test buttons. Current actor and previously selected eligible WordPress users are kept visible alongside role-granted administrator/shop-manager candidates (server-side live user_can eligibility still filters every candidate).
- Foundation test `tests/finance-notification-templates.php` and native disposable `tests/integration/pro-finance-notifications.php` wired to CI. The native fixture intercepts `pre_wp_mail` and does NOT send real emails. Extended admin POST/nopriv/nonce security checks.
- Design/safety ADR `docs/architecture/ADR-0015-opt-in-finance-notifications.md`.

## CI and concrete fix evidence

- [44655756661](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44655756661) and [44655762607](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44655762607) were full SUCCESS at earlier code SHA `060bfa5` after correcting an unsupported default 25-row review queue fixture call to explicit 20.
- [44656259727](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44656259727) found a real *admin recipient picker regression*: `WP_User_Query` filtering on both custom role-granted caps returned no eligible administrator in CI. Corrected picker to known administrator/shop-manager roles plus saved recipients and current manager; each displayed user is still checked with both actual caps. Fix at `d976d94`.
- [44656618485](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44656618485) and [44656627658](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44656627658) verify the corrected code head; they were running at the time of this handoff, **not claimed green**.
- Exact expected end-to-end native output includes `PASS opt-in finance digests... zero real email` and `PASS native finance admin/CSV POST registry...` plus full Playground/foundation. If errors appear, read only relevant lines of job logs; do not disclose generated CI MariaDB passwords.

## Merge and publication boundaries

Issue #7 remains open until Section 23 complete. Owner authorized automatic phase-PR **merge to main only after entire phase and current-head full CI green**, not a partial task, release, store deployment, public marketplace publication, or actual bank/payment action. Read `.ai/phase-merge-policy.md`. Do not claim to continue asynchronously after this execution context ends.
