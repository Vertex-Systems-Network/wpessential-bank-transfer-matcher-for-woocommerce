# ADR-0015 — Opt-in finance notifications (P6-T004)

Status: implemented on Phase P6 branch; exact-head CI verification pending.

## User impact and consent

All **Pro finance notifications default to OFF**. There is no implicit initial subscription, scheduled event, import-triggered send, bank-credit confirmation, customer-address recipient, marketplace list integration or raw recipient-email input. The WooCommerce-native `Finance notifications` screen is restricted to logged-in users who possess Free `wbm_approve`, Pro `wbm_import`, **and** Pro `wbm_manage_bank_accounts`. Settings and test sends require authenticated POST and separate purpose-specific nonces. The page provides local text-only previews and explicitly describes that messages never confirm bank clearance.

The operator selects which of four *fixed* digest categories to enable, and explicitly selects at most 10 currently eligible finance WordPress user IDs. The enabled list is rejected unless at least one actual eligible recipient is selected; unknown options, duplicates, arbitrary email addresses and malformed IDs fail closed. Recipient capabilities and email validity are rechecked at save and again at every send, ensuring role loss prevents future mail. A shop manager without manage-bank-accounts permission cannot change recipients or click test-send.

## Content minimization

`FinanceNotificationTemplate` emits four fixed, plain-text subjects/bodies: open exceptions, pending reviews, bank-statement mismatch and older-than-48-hour unresolved reviews. Every message contains only a UTC date, an internal authenticated review-workspace link, and the statement that an import or customer claim is not proof of cleared funds. It excludes bank references, full account numbers, amounts, customer/order identifiers, mapping records, attachments, claims and arbitrary bank-supplied text. Previews are deterministic, visually identified as preview and **never call `wp_mail`**.

## Delivery and rate safety

The daily digest is scheduled through Action Scheduler *only upon an authorized opt-in*; opt-out unschedules it. Worker execution checks that unresolved source-backed review, mismatch, exception or old-review cases actually exist before mailing; it never updates funds, orders or allocations. Explicit `Send test` requires opt-in and privileged POST, and counts toward the same recipient budget.

All delivery attempts use a per-recipient MySQL named lock. A successful first DB **reservation before sending** prevents racing callbacks from over-sending. Each finance recipient receives **at most 2 accepted sends in any rolling 24-hour period**, and **at most one per exact digest type** in that period, including manually requested previews sent as test emails. Source and recipient data are not written into the notification audit table; only recipient ID, fixed event key, UTC date, status and timestamps. An uncertain transport failure consumes its reservation instead of blind auto-retry (at-most-once semantics). Delivery acceptance by WordPress mail is not the same as confirmed inbox receipt.

## Verification and remaining QA

Foundation fixture `tests/finance-notification-templates.php` validates all four safe plain-text templates, normalization, preview label, dangerous URL/control rejection and finite options. Disposable native fixture `tests/integration/pro-finance-notifications.php` intercepts `pre_wp_mail` in-process (no real external emails), verifies default-off, opt-in, preview no-send, explicit tests, source-backed pending and aged review digests, 2/day and type deduplication, unexpected recipients and nonce denial, opt-out and append-only audit. Admin security fixture validates nonce forms and nopriv route absence. Exact current-SHA full CI (PHP/static/unit, native WooCommerce/MariaDB, Playground) is mandatory before P6-T004 can be marked done.

Follow-on Phase P6-T006 will cover redacted operational diagnostics and failed-delivery recovery; P6-T007 retention/privacy; P6-T008 full operational admin docs. Phase P6 and PR #12 cannot be merged until T001–T008 and the complete report-export/no-oversend safety gate pass, per `.ai/phase-merge-policy.md`.
