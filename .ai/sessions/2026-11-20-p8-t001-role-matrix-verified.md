# P8-T001 verified native WordPress authorization — 2026-11-20

- P7 phase corrective PR #14 merged into `main` at **`4aa10da38e73ac5effc27280efbc05ccd719fb8d`** after all five exact-head checks SUCCESS and clean mergeability. No production/merchant financial action.
- Started dedicated P8 branch `development/p8-threat-model-access-matrix-20261120`, draft PR #15 (P8 phase remains incomplete).
- Replaced placeholder `docs/security/threat-model.md` with explicit assets, adversaries, trust boundaries, Free/Pro capability boundaries, financial invariants, security risk register and residual P8/P9 gates. This is engineering threat modelling, **not** formal certification.
- Implemented `tests/integration/p8-route-capability-matrix.php`, wired in `scripts/test-native-install.sh` to actual WordPress 7.0.8, WooCommerce 11.3.0 and disposable MariaDB `wbm_ci`.
- **Verified code SHA `cb6f22d77ec1df5a053f15b4815ab9e729086a13`**: GitHub Actions Foundation/Playground/native MariaDB **44717249254 SUCCESS** (both jobs). Native output: `PASS P8-T001 real WordPress role/REST matrix: 9 personas, 10 routes, 100 permission/read-only probes, 24 private/guest admin hooks, no authorized finance POST or order mutation`.
- Tests reject unregistered/ambiguous REST routes, unexpected guest admin actions, 401/403 failure to deny, incorrect capability GET response. Reviewer-only attempted claim status `verified` is denied by the actual callback without touching financial storage. The matrix never invokes any *authorized* financial POST.
- Further docs-only commit `8c38a5b5c4bbd871b80ca4b48a5236784f0e360b` has final-head CI 44717418840 still running at time of this checkpoint. Do not promote a phase PR without latest-head full green CI.
- **Next P8-T002:** private receipt and statement access audits with negative HTTP/nonce/ownership tests and exact actual private-source path verification; preserve every synthetic financial and customer record's privacy. P8-T003–T009 remain planned.
