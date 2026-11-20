# WBM-P6-T003 — secure finance CSV slice (2026-11-20)

Branch: `development/p6-reports-operations-20261120`. Draft [PR #12](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/pull/12) targets `main` and carries post-PR#9 sub-branch fixes #10 and #11. **Do not merge until all of P6-T001–T008 and P6 phase gate pass.**

### Implemented in this session
- `ReportCsvEncoder.php`: six fixed report allowlists, exact canonical fingerprint check, 500-row / 1 MiB bounds, spreadsheet-formula neutralization including leading control/Unicode spacing, safe UTF-8/scalars and private source fields exclusion.
- `ReportCsvExportService.php`: existing finance capabilities + actor and nonce, no automatic/bank/payment hooks, append-only export metadata audit with actor/report/scope/CSV hash/counts, 3 successful exports per user per 60 seconds under MySQL named lock, fail-closed audit errors.
- `ReportExportsPage.php`: WooCommerce-native finance menu and authenticated POST-only audited CSV page downloads, no nopriv/REST/customer route, no-store headers, explicit has_more/cursor headers and limited-page guidance; registered from Pro bootstrap.
- Tests: `tests/report-csv.php` in Foundation CI, native `tests/integration/pro-report-csv-export.php` with synthetic imported batch, and extended native `pro-finance-admin-security.php` with forged-nonce denial. Native install script includes the export fixture.
- ADR: `docs/architecture/ADR-0014-finance-csv-export.md` documents boundaries and remaining async/security work.

### Verified evidence vs pending
P6-T002 done: exact cumulative dev head `5164ed3` passed full [GitHub Actions run 44647473663](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44647473663), including native WordPress/WooCommerce/MariaDB (classic and HPOS), Foundation and Playground. P6-T003 changes are **in progress** until the new exact-head full CI and asynchronous export/job safety are verified. Do not call them tested merely because workflow jobs started.

### Next execution
1. Inspect the two current workflow runs for exact PR #12 HEAD; read failing job logs and fix any PHP/unit, WordPress/MariaDB or Playground issue.
2. Implement bounded, retry-safe async export job contract (permission tied to actor, no public file exposure, no leaked bank refs, expiry/cancel/recovery, audit), or explicitly flag unavailable environmental gate. Add native concurrency/security tests.
3. Verify all T003 acceptance, record actual CI links and task state; continue T004 notifications without routine pause.
4. At P6-T008 completed and full phase gate pass, merge PR #12 to main using current expected head SHA and update Issue #7, which remains open until Section 23.
