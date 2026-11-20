# P8-T009 — Source-backed security, financial correctness and reliability blocker closure

**Engineering verification accepted on 2026-11-21 for exact code SHA `11c19383bc2f61091be742939415c1437326514b`.** The full **12/12** configured GitHub checks at this source SHA completed successfully. This is **not** an independent penetration-test report, marketplace approval or blanket production-security certification.

## Exact-head CI evidence (all SUCCESS)

| Gate | Real evidence |
| --- | --- |
| Actual Free + Pro native WP/WooCommerce/MariaDB, PHP/static/unit and Playground | [Foundation run 44833813362](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833813362) and [44833824024](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833824024); **both Foundation and both native MariaDB jobs green** |
| Real LTR/Arabic RTL browser, guest BACS checkout, privacy/CSRF denial, unpaid invariant | [Chromium 44833813365](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833813365), artifact **14678195966** |
| PHP 8.3 and PHP 8.4 × WooCommerce 10.8.0 and 11.3.0 | [Four-cell matrix 44833813391](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833813391), **4/4 green**, HPOS and classic |
| WC 10.8→11.3 in-place upgrade, Pro dependency, separate harmless plugin, data persistence | [Real dual-store 44833813316](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833813316), both **HPOS** artifact **14677588383** and **Classic** artifact **14677927906** |
| 1k/10k CSV measured throughput, 50k safe reject before stage | [Native benchmark 44833813377](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44833813377), artifact **14678215212** |

## Confirmed defect: malformed CSV header could be staged before rejection

Prior to P8-T009, `ImportJob::start()` preflight counted rows but did **not** enforce all the actual CSV parser's header rules. An imported file with an invalid UTF-8, NUL, oversized, duplicate or blank header might be copied to a private bank-source file and assigned a pending import batch, with a subsequent processing failure. This was a **bounded reliability/correctness risk**, not evidence of a publicly reachable file or an actual double payment.

**Fix:** `CsvParser::validated_headers()` now enforces one shared policy for dry-run parsing, **before-stage streaming preflight** and hash-verified worker processing: nonblank unique normalized column names, UTF-8, NUL denial, max column count, max per-header bytes, first-column BOM normalization. The file size, permission, checksum and 10,000-row cap gates remain in place.

**Real negative regression:** `tests/integration/p8-invalid-csv-header.php`, wired into `scripts/test-native-install.sh`, rejects **six distinct synthetic invalid-header cases** (NUL, invalid UTF-8, oversized unmapped header, duplicate header, blank header, >100 columns) in both CsvParser and ImportJob **before any private staging, row/batch/checkpoint or async action changes**. Actual SQL before/after counts are identical. The positive **UTF-8 BOM one-row file** still parses and completes a real MariaDB import. Native CI emitted:
`PASS P8-T009 6 corrupt CSV header formats rejected before private staging/async queue; valid BOM 1-row import completed; native MariaDB counts unchanged`.

The source-code defect and regression acceptance are verified at the exact SHA above, including all 12 check runs.

## Closure by security/financial trust boundary

| Boundary / threat | P8 verified regression and accepted behavior |
| --- | --- |
| Roles, REST, admin-post | T001 actual WordPress roles/registered routes and denied method/role combinations; authenticated capability+nonce gates |
| Receipts and bank source privacy | T002 actual local private-file and HTTP guest/no/wrong nonce denials, expired byte purge; outside-docroot CSV staging and 0600/0700 file/dir |
| SQL/XSS/CSRF/XXE/CSV formula / receipt spoofing | T003 20 actual native adversarial checks and six real authenticated bad/missing-nonce HTTP probes |
| Payment approval concurrency, stale Woo cancellation, duplicated/tampered credit, replay | T004 independent MariaDB-session named source lock and on-hold/no-paid-state invariant; preexisting split/many-credit/finalizer regression |
| HPOS/legacy data store | T005 Free/Pro official Woo compatibility declarations, real order CRUD |
| Classic/Blocks checkout | T006 real Classic guest browser BACS and real Cart-Token Woo Store API checkout; separate references, claim form, no paid transition; Checkout Block frontend rendered, **Block Pay button itself not clicked** |
| Resource exhaustion and over-cap source | T007 true 1k/10k CSV import measured; 50k file deliberately rejected before queueing, with unchanged batch/job/row counts; CI timing/allocation budgets |
| Plugin lifecycle/data persistence | T008 Free/Pro dependency, Pro deactivation/reactivation, actual WC10.8→11.3 ZIP upgrade with synthetic third-party order number and unchanged unverified BACS evidence in HPOS and classic |
| Invalid CSV header before private staging | T009 shared header validator + six real negatives + valid BOM positive, 12/12 regression checks green |

## Risk disposition — important boundaries

**Within this explicitly tested P8 synthetic engineering scope, no known unresolved confirmed critical/high security or financial-correctness defect remains.** This conclusion is limited to the inspected source/fixtures and named CI tests, **not** a guarantee of no future vulnerabilities or proof of a comprehensive external penetration test.

Remaining mandatory P9 release-candidate/external checks:
- Actual merchant hosting/CDN/reverse-proxy private file routing and customer-data retention policies; not measured by isolated localhost WordPress.
- Independent malware scanning/receipt polyglot review and manual penetration testing if required by seller or marketplace; P8 tests cover stated MIME/XXE and access denial, not every attack class.
- WordPress Plugin Check, official readme validator, actual WooCommerce QIT suites and marketplace reviewer/business compliance; verify running results, do not invent unavailable services.
- Third-party extension matrix beyond a deliberately benign CI companion; production backups/rollbacks, CDN and simultaneous merchant traffic.
- PHP 8.5, every intermediate WC release, every Block Pay-button browser permutation, all translations/WCAG audits and 50,000-row **successful** import remain **unverified/not supported claims**.
- Artifact signature/license/security dependency audit and final release gates P9; no plugin ZIP publication or merchant financial authorization.

**Gate P8 engineering decision:** All nine P8 tasks T001–T009 are source-backed and verified. The phase PR may be merged to `main` **only** after its *final metadata/evidence HEAD SHA* passes all configured checks and GitHub review/protection rules. P9 readiness and public release remain pending. Master Issue #7 must stay open.
