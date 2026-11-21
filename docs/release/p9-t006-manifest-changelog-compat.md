# P9-T006 — Candidate SHA256SUMS, canonical changelog and compatibility evidence

**Implementation in review; not release-accepted.** Source for ZIPs: the actual Free/Pro and base/Free/Pro Playground artifacts built and exercised in the same disposable GitHub Foundation run. This is deliberately a candidate-level report, not a promise that QIT, vendor review, WordPress.org Plugin Check or all compatibility tests were performed.

The `scripts/generate-p9-release-evidence.py` script must run **after** Playground real execution, P9-T004 deterministic ZIP verification and P9-T005 bundle source verification. It derives:
- `dist/SHA256SUMS`: SHA-256 for exactly the real five non-production ZIP files, sorted deterministically.
- `dist/CHANGELOG-0.1.0.md`: byte-for-byte copy from the canonical `docs/source/changelog/0.1.0.md`. **No invented Pro capabilities.**
- `dist/p9-validation-report.json`: source GitHub commit/run, true ZIP hashes/sizes, checked Composer license inventory, correct hash-parity of all nested Playground resources, and explicitly NOT RUN external checks. A hash manifest **does not prove live Playground or native MariaDB execution by itself**. The Foundation CI ordering plus separate native job conclusion must be inspected.
- `dist/p9-compatibility-matrix.json`: version cells parsed from actual repository WooCommerce compatibility workflow: installed WordPress 7.0.8, PHP 8.3/8.4, WooCommerce 10.8.0/11.3.0, MariaDB 11.4, HPOS and classic; historical P8 evidence is linked. It deliberately marks newer patch support **NOT VERIFIED**.
- The script rejects missing/damaged ZIPs, edition mixups, wrong production ZIP hashes in bundle manifest and changed lock/license scope. Negative tests reject hash tampering and false execution claims.

CI should upload `p9-sha256-changelog-compat-validation` only after all earlier source-ordered validation steps succeed. A successful upload and verified source artifacts are necessary but not sufficient for release. Actual Marketplace vendor submission, pricing/license integration, Woo managed QIT, WordPress.org validator/Plugin Check, and production WordPress/WooCommerce patch-version support are separate gates.

**Status:** draft stacked on P9-T005, do not merge or mark P9-T006 complete until P9-T005 accepted at exact CI and P9-T006 native/Playground checks and artifact integrity independently verified.

## CI pull-request SHA provenance

For a GitHub Actions `pull_request` event, `github.sha` / `GITHUB_SHA` is often GitHub's *synthetic PR merge checkout SHA*, rather than the original branch head commit. The P9-T006 generator now records **both** the source PR head (from `github.event.pull_request.head.sha`, falling back to `github.sha` on push) and the separate actual CI checkout SHA. Both are useful: the source head ties task acceptance to code reviewed; the checkout SHA ties the test result to its exact merged base. A release report must never equate the two without verification. Official release builds must regenerate checksums on the final merged source, not merely re-use a PR test artifact.
