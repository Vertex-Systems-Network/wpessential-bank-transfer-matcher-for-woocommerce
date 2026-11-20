# P9-T004 — Independent Free/Pro archive and license integrity

**IMPLEMENTED BUT CI VERIFICATION PENDING.** Source baseline: P9 merge `017a1794536fedb0a4b9df03003e4ec13d6964af`. These checks are not release publication or final marketplace certification.

Build each plugin separately with `bash scripts/package-plugin.sh free` and `bash scripts/package-plugin.sh pro`: Composer 2 strict validate, locked `install --no-dev --prefer-dist --no-interaction --optimize-autoloader`, skip network vulnerability audit only when lockfile contains **zero** runtime vendor packages, then deterministic ZIP archive build.

- Both existing Composer lockfiles currently contain zero runtime and development packages. This is verified from actual source. **No Composer vulnerabilities have been externally scanned** when there is nothing to scan; the report records `not_applicable_no_locked_runtime_packages`. Future dependencies must undergo complete license/provenance and vulnerability review before ZIP publication, not silently pass.
- Add explicit `LICENSE` notices to each package and include them in exact required-member manifests; GPL-2.0-or-later matches the existing WordPress and Composer headers.
- `scripts/verify-release-archives.py` reads the **real built ZIP files** (not only source directories) and checks the single slug root, required members, forbidden hidden/traversal paths, deterministic timestamps, valid CRC, optimized Composer classmap and PSR-4 loader, locked production vendor manifest, dev dependencies absent and cross-edition path isolation.
- Foundation CI builds each ZIP, runs verifier, rebuilds both from the exact same commit and compares SHA256 of the two runs. This is a deterministic-repeat **CI verification** only once a real exact-head Actions job completes with success. The CI inventory report is written locally during the job, not published as a final public release artifact.
- Independent clean ZIP activation, missing Free handling, classic/HPOS and in-place WooCommerce upgrades are separately evidenced by P8 native tests. P9-T005/T013 still must recheck final release artifacts and vendor/license notices.

**Do not claim completion yet.** Run exact-source Foundation+native CI for this new branch. Review failures, do not promote packaging to released or Marketplace-approved, and preserve P9 phase order (T003 active before this task becomes ready).
