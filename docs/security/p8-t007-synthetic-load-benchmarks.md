# P8-T007 — Measured synthetic 1k / 10k / 50k CSV import and fail-closed safety

**VERIFIED** against exact source SHA `d0b5143ed64bb66eaf86779fa651358961b08481`, 2026-11-21. All **10/10 exact-SHA GitHub checks** are completed with conclusion `success`, including dedicated benchmark, native WP/WooCommerce/MariaDB, Foundation/Playground, 4-version matrix, and Chromium LTR/Arabic RTL.

## Primary quantitative evidence

- [Native MariaDB benchmark 44831039401](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44831039401): SUCCESS; JSON artifact **14675449713**, `p8-import-synthetic-benchmark`.
- Exact-head native Foundation/Playground/MariaDB [44831039373](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44831039373) and [44831053993](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44831053993): both SUCCESS.
- [Actual LTR/Arabic RTL Chromium and BACS no-paid/CSRF/privacy 44831039387](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44831039387): SUCCESS.
- [WC10.8.0/11.3.0 × PHP8.3/8.4 version matrix 44831039366](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44831039366): 4/4 SUCCESS.

| Synthetic CSV data rows | Correctness result | Actual total elapsed time | CSV bytes | PHP peak allocated memory |
| --- | --- | ---: | ---: | ---: |
| 1,000 | completed; 1,000 valid persisted before cleanup | **398.02 ms** | 46,925 | **133.81 MiB** |
| 10,000 | completed; 10,000 valid persisted before cleanup | **3,670.00 ms** | 488,926 | **133.81 MiB** |
| 50,000 | **rejected before staging** | **21.21 ms** | 2,488,926 | **133.81 MiB** |

**Scope:** One observed GitHub-hosted Ubuntu runner setup, real WordPress 7.0.8, WooCommerce 11.3.0, PHP 8.3, MariaDB 11.4, full Free+Pro ZIPs. `memory_get_peak_usage(true)` measures **PHP allocated memory**, **not process RSS**. Clock measured with PHP `hrtime()`. These are reproducible synthetic examples, **not an SLA** or a concurrency load forecast; CI runner speed varies. The JSON artifact includes per-file size, total/start/process durations, peak allocated memory, staged-row counts and import chunk counts.

## Source-backed correctness and defect prevented

- `CsvParser::MAX_ROWS = 10000`: 50k rows **must not** be represented as a supported import. `ImportJob::process()` already rejects rows after cap, but previously it could commit several 100-row chunks before eventually failing an over-cap file.
- `ImportJob::start()` now performs bounded **streaming `fgetcsv` row-count preflight** before private file copy, batch creation or Action Scheduler enqueue; follows existing 20 MiB source limit. It rejects source rows above 10,000 with `InvalidArgumentException` **before** any financial evidence is staged. This avoids misleading partial over-cap CSV imports.
- `tests/integration/p8-import-performance.php` creates entirely synthetic CSV rows with distinct source references, executes actual `ImportJob::start/process`, checks **completed 1,000 and 10,000 rows, no errors/warnings**, and cleans all synthetic private sources and database records. For 50,000 it verifies **no stage/queue** and confirms **before/after row, batch and job table counts are identical**, using actual MariaDB SQL.
- Workflow `.github/workflows/p8-import-benchmark.yml` creates artifact even when a gate fails and enforces generous CI-only regression budgets: **1k ≤12 seconds**, **10k ≤60 seconds**, **50k rejection ≤5 seconds**, and **peak PHP allocation ≤256 MiB**; exact-head passed.

## Residual limitations

- 10,000 row support **does not** imply a 50,000-row import mode; testing confirms deliberate rejection. These CSVs do not represent arbitrary real bank formats or production-grade parallel import workloads.
- No long-duration, distributed-runner, live merchant, slow remote-database, production resource limit, PHP process RSS, or multidimensional p99 benchmark performed. Streaming preflight is bounded by file size and row count and the later SHA-256 staged copy check remains mandatory. Separate P8-T008 upgrade/coexistence, P8-T009 hardening and P9 release gates remain open.
- No live bank statements, real customer data, payment completion, external merchant transactions or public package publication.
