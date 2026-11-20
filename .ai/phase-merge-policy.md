# Phase-gated pull request merge policy

Status: owner-authorized on 2026-11-20. Applies to **development phase PRs**, not release distribution.

## Authoritative acceptance

The canonical task graph is `.ai/tasks.json` and the individual phase gates are in the Master Development Directive (e.g. P6 requires ledger-reconciled reporting, authorized redacted exports and non-spamming notifications). The live issue is [#7](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/issues/7). An issue is not resolved merely because its PRs merged.

## Automatic merge decision — while an authorized execution runner is active

1. Work on a dedicated `development/<phase>-...` branch; open the phase PR against `main` early, preferably as a draft. Each coherent task may use short-lived subordinate PRs; merging a subordinate PR into a phase branch **does not** merge that feature to `main`.
2. Before merging the *phase PR*, ensure **every** phase task has `done` or `verified` status with truthful, specific evidence; dependencies, actual code and traceability must match. Review all tasks and the master phase gate; no skipped or inferred feature.
3. Require the exact current PR head SHA to pass all configured required GitHub Actions checks, including native WooCommerce + MariaDB (classic and HPOS where applicable), PHP/static/unit, packaged Playground and relevant fixture/security checks. Do not interpret an older green SHA as proof for changed code. Human/manual/external gates stay pending until independently verified.
4. Reject merge if code review finds unresolved critical/security/financial bugs, branch conflicts, missing mandatory checks or failed jobs. Never bypass branch protection, force-push protected branches, or override failing checks. A QA sign-off must be represented by evidence; it cannot be invented.
5. When all criteria pass, **merge the phase PR automatically without asking for another routine approval**, using the exact expected head SHA. Re-read GitHub merge status and the resulting `main` HEAD; record the merge SHA, CI run URLs and the next unblocked task in state/backlog/issue.
6. If a phase is not complete, leave its PR open and document blockers. Never claim a background process will continue after this active runner stops. On resumption, pick up the persisted checkpoint and retry the gate.
7. Close Issue #7 only when **all** work in Section 23, including release-candidate readiness and required external evidence, is genuinely verified. Do not close for an intermediate merged phase.

## Publication and financial boundaries

Owner approval authorizes merging **verified development phase PRs to `main`**. It does **not** authorize deployment to a merchant store, merchant payment confirmation, actual bank/refund transactions, marketplace submission, SVN publication or a public release. Synthetic finance fixtures only. Imports and customer claims never authorize paid status.

## Phase P6 current status

P6-T001 reporting contracts complete. P6-T002 providers implemented; cumulative dev-branch CI/merge verification required before status promotion. P6-T003–T008 remain outstanding. The P6 PR is **not** merge-eligible before all eight tasks and the full P6 gate pass.
