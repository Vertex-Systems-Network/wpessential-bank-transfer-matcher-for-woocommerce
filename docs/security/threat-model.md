# WPEssential Bank Transfer Matcher — source-backed financial threat model

Version: P8-T001 security review (2026-11-20). **Engineering model**, not proof of compliance, penetration-test completion or merchant bank clearance. Source inspection and live CI evidence must be kept separate. Critical/high findings block release. This model covers Free and the separately activated Pro edition.

## 1. Assets, invariants, adversaries

| Asset / security property | Risk | Mandatory invariant |
| --- | --- | --- |
| WooCommerce order payment status and `date_paid` | Forged customer claim, import or privileged replay leads to false order-paid state | Receipt, claim and imported statement **never** mark paid by themselves; only explicit approved, fully funded reconciliation finalizes WooCommerce payment |
| Immutable reconciliation/allocation trail | Double allocation, partial credit counted as fully funded, mismatched currency, stale totals, race conditions | Exact minor units and stored currency/exponent; one credit cannot fund two orders; financial approvals need atomic/idempotent ledger checks and durable audit |
| Private receipt bytes, customer identity, order key | Unauthorized file access, order enumeration or guessing | Order-key plus billing email for guest claims; authenticated capability + order-specific nonce for receipt downloads; private bytes never served by public predictable path |
| Merchant-imported bank CSV/CAMT and account metadata | Untrusted content, XXE, formula injection, identifier leak and poisoned evidence | Bounded parser; private staging; explicit source identity, deduplication and masked outputs; merchant confirmation of cleared credits |
| Report exports, asynchronous jobs, operational notices | Financial metadata exfiltration and job ownership bypass | Finance role and nonce gates, actor-owned downloads, bounded redacted CSV, audit and retention |
| Administrator permission grants/Pro entitlement | Escalation to `wbm_approve` or manager-only powers | Independent Free/Pro capability gates, admin-only role edits, no implicit role escalation |
| Package/vendor supply chain and WordPress storage | Infected update or compatibility regression | Independently packaged GPL-compatible Free/Pro optimized Composer dependencies and verified native HPOS/classic semantics before release |

Adversaries include unauthenticated customers, malicious/compromised accounts with limited WP privileges, dishonest finance operators, malicious CSV/receipt providers, replay/race attackers and vulnerable extensions. Store owner and bank-clearing evidence are **not** assumed infallible; authorized actions still require documented evidence, explicit acknowledgement and data-integrity checks.

## 2. Concrete trust boundaries

1. **Internet / WooCommerce customer** → publicly reachable order-received page and guarded `admin_post_nopriv_wpessential_wbm_submit_claim`. The order key is bearer-like; pairing it with exact billing email, per-order nonce, rate limiting and file validation reduces—but does not eliminate—leakage risk. The form is not bank evidence.
2. **Authenticated WordPress REST** → `/wpessential-wbm/v1` capabilities, claim review, merchant bank evidence, allocations/payment-finalizer and Pro review-case actions. Methods and required fields are registered through `WP_REST_Server`; server-side `permission_callback` must deny unknown/unauthorized roles. REST browser nonces are required for authenticated cookie requests by WordPress; capability alone is insufficient to prove CSRF protection for admin-post forms.
3. **WooCommerce wp-admin menu and `admin-post.php`** → Free order reconciliation and Pro account/import/review/export/settings/operations. Displaying a menu is not authorization: each handler must enforce its own capability and nonce, before accepting untrusted parameters. P7-T006 fixed six Pro composite-capability menu mismatches.
4. **Merchant-supplied statement files / private import staging** → CSV/CAMT parser and canonical bank-source rows. Imported credits are merchant-provided records, not live cleared evidence; never update Woo paid status on import.
5. **Approval engine / MySQL-MariaDB allocation ledger** → atomic, unique, currency/amount-checked reconciliation with cross-process locking, maker/checker where applicable and audit. A partial credit, fee or reversal cannot silently clear a full order.
6. **Private report/email / filesystem and temporary exports** → bounded masked CSV, owned queued jobs and opt-in finance recipients; avoid customer details, raw bank references, credentials and long-lived publicly reachable URLs.
7. **Free + Pro activation / packaged ZIPs** → Pro must fail safely without compatible Free; Free does not embed Pro code or remote-executed updates. WooCommerce versions, HPOS and classic mode require real integration verification.

## 3. Role and route authorization matrix (source-derived)

Actual Free capabilities: `wbm_view` (read-only manager screens), `wbm_review_claim` (claim review, merchant evidence, Pro review case), `wbm_approve` (explicit ledger/allocation/payment approval). Pro caps: `wbm_import` (bank-import workspace) and `wbm_manage_bank_accounts` (sensitive settings/account metadata). WordPress `manage_options` gates Free settings changes.

| REST endpoint class | Real callback gate | Important extra restriction |
| --- | --- | --- |
| GET `/capabilities` | `wbm_view` | Returns current user's **actual** Free capability booleans; anonymous must be denied |
| POST order claim review, retry notifications, bank-evidence | `wbm_review_claim` | Claim transition to **verified** additionally checks `wbm_approve` inside callback; order must be eligible |
| POST order allocation, payment finalize | `wbm_approve` | Separate cleared-credit acknowledgement, amount/balance/evidence guard and WooCommerce order state; no customer authority |
| POST Pro review-case transition/assignment/priority/comment | `wbm_review_claim` | Case state/assignee must be valid; this cannot allocate funds or mark payment cleared |
| Pro statement import/mapping | `wbm_import` and admin-post nonce | No implicit pay-on-import |
| Pro allocations/finance reports | `wbm_import` AND `wbm_approve` and admin-post nonce | Reports redacted/bounded/actor-owned; approvals maker/checker |
| Pro manager-only notifications/retention/ops | `wbm_manage_bank_accounts` AND `wbm_import` AND `wbm_approve` | Restricted manager-only; explicit recovery/retention confirmations |
| Free settings write | WordPress `manage_options` and admin-post nonce | Shop manager can view but cannot modify role grants |

This is a **route-access model**, not a guarantee of object-level entitlement. Any actor with `wbm_approve` can reach an allocation permission callback; object eligibility and evidence correctness are separately enforced in ledger services. External application/password authentication must still obey WordPress account policy.

## 4. Current test evidence, exactly scoped

- **P7 source and registry tests:** `tests/p7-real-action-route-contracts.php`, `tests/integration/p7-real-action-routes.php`. Fourteen real screen IDs, 24 registered admin-post actions (only one guest-scoped claim action), ten registered typed REST methods; all anonymous REST permission callbacks deny with 401/403. Existing CI success for P7 at source `c5d9c6d8` before P8 changes.
- **P7-T006 role menu and P7-T008 real browser:** `tests/integration/p7-menu-role-matrix.php`, `tests/browser/p7-navigation.mjs`, `tests/browser/p7-usability.mjs`, `tests/browser/p7-usability-integrity.php`; seven distinct permission states in WP menus, three user personas LTR/real RTL, no finance POST, BACS orders still unpaid. P7 artifact 14618693822.
- **P8-T001 new test, pending exact CI:** `tests/integration/p8-route-capability-matrix.php` verifies nine synthetic identities across all ten actual WP REST registered callbacks (**90** callback probes), nine safe read-only capability route dispatches, an extra callback-level **verified-claim-without-approval** negative test, plus 24 admin-post hook public/private checks. No authenticated finance POST dispatch or balance mutation. Wired into the disposable MariaDB install CI (`scripts/test-native-install.sh`). Do not record P8-T001 as passed before green exact-head CI.
- **Financial integrity prior tests:** Pro allocation/concurrency/duplicate/correction and Free evidence/claims tests in `tests/integration/`, exercised against WC HPOS/classic and a disposable database. Their passing does not replace P8's adversarial replay, tampered private attachments or 1k/10k/50k import load tests.

## 5. Risk register / open acceptance

| ID | Threat / attack route | Implemented or in-flight control | Residual gate and owner |
| --- | --- | --- | --- |
| SEC-01 | Unprivileged REST finance mutation | WP registered permission callbacks and explicit approval | **P8-T001** live cross-role auth matrix; **P8-T003** negative CSRF/object-ID adversarial tests |
| SEC-02 | Public file/statement/receipt guessing | Private storage, admin-only guarded downloads (prior implementation) | **P8-T002** actual HTTP file isolation, path traversal, MIME/expiry and ownership |
| SEC-03 | Malicious CSV/XML/XSS/formula cells | Mapping validation, bounded import and redacted exports (prior implementation) | **P8-T003** adversarial encoding/CSV injection/XXE/escaping; no unvalidated public import |
| SEC-04 | Duplicate allocation, stale status, split overfunding | Source provenance and unique/checking ledger, approval locks | **P8-T004** adversarial simultaneous cross-process multi-order/rollback tests |
| SEC-05 | Unsupported Woo checkout/HPOS declaration | HPOS/classic/native E2E and synthetic BACS checkout already exercised | **P8-T005–006** authoritative compatibility/Block checkout version matrix |
| SEC-06 | Large file import resource exhaustion | Existing bounded parser/jobs | **P8-T007** actual measured 1k/10k/50k limits and safe failure behaviors |
| SEC-07 | Plugin coexistence/migration/Pro dependency | Separate Free/Pro ZIP, optimized Composer, safe missing-Free behavior | **P8-T008** migration, activation/deactivation and third-party plugin conflict test |
| SEC-08 | Consumer accessibility/localization gap | Native WP shell, real RTL direction, 375px claim form control geometry | **P8-T009/P9** validate contrast and screen reader; full Arabic plugin string translation **not certified**, do not claim WCAG 2.2 AA |
| SEC-09 | Release supply chain / sensitive artifact | Synthetic-only CI, private masks and Composer manifests | **P9-T004/T013** archive/vendor licenses, scanning, checksum, WordPress Plugin Check/QIT |

**Security gate:** Any unresolved critical/high authorization, payment correctness or private-data leak must block release. A green permission-matrix test alone does **not** mark these residual items passed; update findings with exact code SHA, CI URLs, reproducible commands and corrective tests as follow-up tasks. Never publish plugin ZIPs or execute external bank movement through this audit.
