# P7-T003 — Real WordPress UI control/permission/REST audit

Status: **P7-T003 verified at exact SHA `8c71ca4fe5c5f1089012eacd0ebad558adc14e85`** by full Foundation/Playground/native WooCommerce/MariaDB GitHub Actions **44671126063** and **44671120192**, both SUCCESS. This is **not** browser screenshot or RTL evidence.

## Screen/source map

| UI | Actual PHP source | Supported mode | Constraint |
| --- | --- | --- | --- |
| UI-001 | `plugins/wpessential-bank-transfer-matcher/src/Admin/Overview.php` | read | Source-backed overview; not a bank ledger |
| UI-002 | `plugins/wpessential-bank-transfer-matcher/src/Admin/OrdersPage.php` | GET filter | Unpaid BACS status/pagination via WC CRUD, not all-gateway full search |
| UI-003 | `plugins/wpessential-bank-transfer-matcher/src/Admin/OrderPanel.php` | read | WC order context plus explicit reconciliation links |
| UI-004 | `plugins/wpessential-bank-transfer-matcher/src/Claims/CustomerClaims.php` | guest/owner POST and reviewer REST | Claim is unverified; reviewer does not mark WC paid |
| UI-005 | `plugins/wpessential-bank-transfer-matcher/src/Admin/ReconciliationPage.php` | REST guarded finance | Explicit independent evidence/allocation/finalization |
| UI-006 | `plugins/wpessential-bank-transfer-matcher/src/Orders/ReferencePresentation.php` | WC native hooks | Owner/guest protected reference and BACS checkout, no public ID scan |
| UI-007 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ImportStatementsPage.php` | manager POST | CSV preview+commit+failed retry+cancel; no bank clearance |
| UI-008 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/BankTransactionsPage.php` | GET filter | Source batch and page-size filter; masking, no bank-to-order approval |
| UI-009 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ReviewQueuePage.php` | GET and REST | Case queue filters + case REST; no money allocations |
| UI-010 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/AllocationReviewPage.php` | GET and finance POST | Maker/checker reason and nonce; no automatic paid/refund |
| UI-011 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ReportExportsPage.php` | finance POST | Bounded reports; no Saved Views or unbounded CSV |
| UI-012 | `plugins/wpessential-bank-transfer-matcher/src/Admin/AuditTimeline.php` | read | Order-scoped audit only; no global edit control |
| UI-013 | `plugins/wpessential-bank-transfer-matcher/src/Admin/SettingsPage.php` | settings + Pro metadata POST | Actual Free settings and separate real Pro settings submenus |
| UI-014 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/FinanceOperationsPage.php` | finance POST | Redacted diagnostic CSV and explicit audited failure recovery |

## Real nonce-guarded admin actions

Only `wpessential_wbm_submit_claim` is a public action, and it requires owner/order-key, billing email and order-specific nonce. Receipt GET requires a reviewer login and order nonce.

| UI | Registered admin action | Method | Permission | Nonce | Guest |
| --- | --- | --- | --- | --- | --- |
| UI-004 | `wpessential_wbm_submit_claim` | POST | order owner or order-key+email | `wpessential_wbm_submit_claim:<order_id>` | Order-scoped yes |
| UI-004 | `wpessential_wbm_download_receipt` | GET | wbm_review_claim | `wpessential_wbm_download_receipt:<order_id>` | No |
| UI-013 | `wbm_save_free_settings` | POST | manage_options | `wbm_save_free_settings` | No |
| UI-013 | `wbm_pro_save_bank_account` | POST | wbm_manage_bank_accounts | `wbm_pro_save_bank_account` | No |
| UI-013 | `wbm_pro_archive_bank_account` | POST | wbm_manage_bank_accounts | `wbm_pro_archive_bank_account` | No |
| UI-007 | `wbm_pro_save_mapping_profile` | POST | wbm_import | `wbm_pro_save_mapping_profile` | No |
| UI-007 | `wbm_pro_preview_statement` | POST | wbm_import | `wbm_pro_preview_statement` | No |
| UI-007 | `wbm_pro_commit_statement` | POST | wbm_import | `wbm_pro_commit_statement` | No |
| UI-007 | `wbm_pro_retry_import` | POST | wbm_import | `wbm_pro_retry_import` | No |
| UI-007 | `wbm_pro_cancel_import` | POST | wbm_import | `wbm_pro_cancel_import` | No |
| UI-010 | `wbm_pro_allocation_propose` | POST | wbm_import+wbm_approve | `wbm_pro_allocation_propose` | No |
| UI-010 | `wbm_pro_allocation_approve` | POST | wbm_import+wbm_approve | `wbm_pro_allocation_approve` | No |
| UI-010 | `wbm_pro_correction_raise` | POST | wbm_import+wbm_approve | `wbm_pro_correction_raise` | No |
| UI-010 | `wbm_pro_correction_review` | POST | wbm_import+wbm_approve | `wbm_pro_correction_review` | No |
| UI-011 | `wbm_pro_export_report` | POST | wbm_import+wbm_approve | `wbm_pro_export_report` | No |
| UI-011 | `wbm_pro_schedule_report_export` | POST | wbm_import+wbm_approve | `wbm_pro_export_report` | No |
| UI-011 | `wbm_pro_download_queued_export` | POST | wbm_import+wbm_approve | `wbm_pro_download_queued_export` | No |
| UI-011 | `wbm_pro_cancel_queued_export` | POST | wbm_import+wbm_approve | `wbm_pro_cancel_queued_export` | No |
| UI-013 | `wbm_pro_save_finance_notifications` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_save_finance_notifications` | No |
| UI-013 | `wbm_pro_test_finance_notification` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_test_finance_notification` | No |
| UI-014 | `wbm_pro_ops_diagnostics_export` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_ops_diagnostics_export` | No |
| UI-014 | `wbm_pro_ops_recovery` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_ops_recovery` | No |
| UI-013 | `wbm_pro_retention_save` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_retention_save` | No |
| UI-013 | `wbm_pro_retention_sweep` | POST | wbm_approve+wbm_import+wbm_manage_bank_accounts | `wbm_pro_retention_sweep` | No |

## Real REST server routes

Every route below must be registered by the WordPress REST server with a callable permission callback, expected request method and typed required fields. Actual registered REST permission callbacks are invoked with anonymous user context and must explicitly return 401/403 errors; only read-only `/capabilities` GET is separately tested through full REST dispatch. No authorized POST finance or bank mutation is dispatched. No anonymous route may approve money.

| UI | Route | Method | Required fields | Capability |
| --- | --- | --- | --- | --- |
| UI-001 | `/wpessential-wbm/v1/capabilities` | GET |  | wbm_view |
| UI-004 | `/wpessential-wbm/v1/orders/(?P<order_id>\d+)/claim/review` | POST | `order_id`, `status`, `comment` | wbm_review_claim, and wbm_approve for verified |
| UI-004 | `/wpessential-wbm/v1/orders/(?P<order_id>\d+)/claim/notifications/(?P<event_id>\d+)/retry` | POST | `order_id`, `event_id` | wbm_review_claim |
| UI-005 | `/wpessential-wbm/v1/orders/(?P<order_id>\d+)/bank-evidence` | POST | `order_id`, `date`, `amount`, `reference` | wbm_review_claim |
| UI-005 | `/wpessential-wbm/v1/orders/(?P<order_id>\d+)/allocations` | POST | `order_id`, `evidence_id`, `amount`, `acknowledged`, `reason` | wbm_approve |
| UI-005 | `/wpessential-wbm/v1/orders/(?P<order_id>\d+)/finalize-payment` | POST | `order_id`, `acknowledged` | wbm_approve |
| UI-009 | `/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/transition` | POST | `id`, `from`, `to`, `reason_code` | wbm_review_claim |
| UI-009 | `/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/assign` | POST | `id`, `assignee_id` | wbm_review_claim |
| UI-009 | `/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/priority` | POST | `id`, `priority` | wbm_review_claim |
| UI-009 | `/wpessential-wbm/v1/review-cases/(?P<id>[1-9][0-9]*)/comments` | POST | `id`, `comment` | wbm_review_claim |

## Real fields and filter limits

| UI | Renderer | Source-backed field names | Known scope |
| --- | --- | --- | --- |
| UI-002 | `plugins/wpessential-bank-transfer-matcher/src/Admin/OrdersPage.php` | `wbm_status` | Only eligible,on-hold,pending; 20 rows via real WC pagination |
| UI-004 | `plugins/wpessential-bank-transfer-matcher/src/Claims/CustomerClaims.php` | `transfer_date`, `transfer_amount`, `transfer_reference`, `sender_name`, `customer_note`, `acknowledged` | Guest email + order key scoped; no automatic payment |
| UI-005 | `plugins/wpessential-bank-transfer-matcher/src/Admin/ReconciliationPage.php` | `status`, `comment`, `date`, `amount`, `reference`, `note`, `evidence_id`, `acknowledged` | REST request fields for actual finance actions; not a fake GUI |
| UI-007 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/MappingProfilesPage.php` | `name`, `delimiter`, `date_header`, `date_format`, `amount_header`, `decimal_separator`, `thousands_separator`, `exponent`, `currency_header`, `fixed_currency`, `reference_header`, `description_header`, `transaction_id_header` | 13 real CSV mapping controls, no CAMT format control |
| UI-008 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/BankTransactionsPage.php` | `batch`, `per_page` | Only source batch and page-size; no broad search |
| UI-009 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ReviewQueuePage.php` | `status`, `priority`, `assignee`, `per_page` | Real queue filters, POST REST for case mutations |
| UI-010 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/AllocationReviewPage.php` | `state`, `order_id`, `per_page`, `source_id`, `request_key`, `reason`, `cleared_confirmed`, `allocation_id`, `split_group_key`, `case_type`, `review_decision` | State/order filters and guarded original maker/checker finance forms; Real permitted allocation/correction approval parameters |
| UI-011 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ReportExportsPage.php` | `report`, `from`, `to`, `currency`, `bank_account_id`, `limit`, `before_id`, `state` | Bounded reports, separate nonce for actor-owned private downloads |
| UI-013 | `plugins/wpessential-bank-transfer-matcher/src/Admin/SettingsPage.php` | `wbm_claim_uploads`, `wbm_shop_caps` | Free manager-option settings only, no imaginary all-in-one tab |
| UI-013 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/FinanceRetentionPage.php` | `automatic_cleanup`, `private_source_days`, `confirm`, `consent_auto`, `diagnostic_audit_days`, `notification_audit_days`, `recovery_audit_days`, `finance_export_audit_days` | Explicit consent and manual sweep confirmation; Explicit merchant consent for bounded operational metadata cleanup |
| UI-014 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/FinanceOperationsPage.php` | `operation`, `source_id`, `reason` | Failed-job recovery; redacted download uses server POST |
| UI-001 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/FinanceDashboardPage.php` | `from`, `to`, `currency`, `bank_account_id`, `focus_report`, `focus_state`, `limit`, `before_id` | Exact source-backed bounded report scopes; read-only |
| UI-007 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/ImportStatementsPage.php` | `account_id`, `profile_id`, `statement`, `draft_token`, `batch_id` | Actual private CSV preview/commit and failed state-specific retry/cancel |
| UI-013 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/BankAccountsPage.php` | `nickname`, `bank_name`, `currency`, `identifier_last4`, `account_id` | Masked safe account metadata only |
| UI-013 | `plugins/wpessential-bank-transfer-matcher-pro/src/Admin/FinanceNotificationsPage.php` | `enabled_types`, `recipient_ids`, `notification_type` | Opt-in authorized recipients and bounded tests |

## Evidence and explicit gaps

Machine-readable source: `tests/fixtures/p7-real-ui-routes.json`. PHP Foundation: `tests/p7-real-action-route-contracts.php`. Disposable native WP/WC/MariaDB: `tests/integration/p7-real-action-routes.php`. Global claim inbox, Saved Views, global audit search, unlimited CSV, automatic bank payment or unsupported form actions are not proven and must not be shown as functioning. Browser screenshots, 1440/1024/375 breakpoints, keyboard/RTL/high-contrast/screen-reader checks and usability still require P7-T004–T008 evidence. Phase PR #13 remains unmerged; Issue #7 remains open.
