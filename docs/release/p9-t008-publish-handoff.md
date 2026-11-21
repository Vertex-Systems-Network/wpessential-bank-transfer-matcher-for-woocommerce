# P9-T008 — Explicit technical vs permission-only blockers and safe publish handoff

**Development preparation; never call this a publisher approval or permission-only remaining issue.** P9-T007 still shows unresolved acceptance annotations: 168 traceability records are planned and one catalog feature remains planned. Some cases may already have runtime implementation and need evidence reconciliation, but that is not equivalent to accepted feature verification. The ledger records technical/business/permission work separately, so external account access cannot be misrepresented as the only missing gate.

The canonical machine-readable [release blocker ledger](p9-t008-publication-gates.json) has 15 individual unverified gates. The CI step `python3 scripts/p9-publication-preflight.py --negative-tests` reads the real source-backed P9-T007 acceptance artifact and ledger and emits `dist/p9-publication-preflight.json` with `release_ready=false` and `may_submit=false`. Negative tests reject any invented owner approval or premature verified status. This is a **successful documentation-preparation check**, not a successful submission.

### Free — WordPress.org directory handoff (NOT EXECUTED)

1. Resolve actual Free code, support scope, icon screenshots and latest supported WP/Woo compatibility. Run **official** Plugin Check and online readme validator against the **final** Free ZIP, attach real outputs/any errors and fix them. Keep no Pro source in the Free artifact.
2. A verified owner must use their actual WordPress.org developer account with 2FA and submit the Free ZIP for directory approval; directory acceptance is not assumed or bypassed. Do not place passwords/API keys into this repo or issue comments.
3. Only **after directory reviewers authorize the slug and committer access**, prepare SVN layout `trunk/`, `tags/<verified-version>/`, and `assets/` from the approved immutable Free package, with correct `Stable tag`; review `svn status` and `svn diff` locally. A dry-run file layout or `svn status` is not a publish action.
4. Actual `svn commit` to public WordPress.org is a separate owner-authorized external publication. No WordPress.org identity, SVN permissions or credentials are available/verified in this task.

### Pro — WooCommerce Marketplace handoff (NOT EXECUTED)

1. Confirm actual Marketplace vendor onboarding, seller identity, support contact, contract/monetization/license and update path, separate Free/Pro install docs, compatibility, icon, benefits and truthful screenshots.
2. Run the seven applicable **managed Woo QIT** checks in the actual Woo vendor dashboard or official CLI and attach genuine run IDs/report URLs. Local CI, offline PHP tests or a JSON matrix of `not_run` do **not** substitute for QIT.
3. Submit the Pro product for Woo review only through an authorized owner's vendor account after all technical/business gates, keeping Free/Pro separation and two-submission freemium rules. Do not publish, change live merchant orders, or represent this runbook as vendor approval.

### Release-stopping technical gaps

- Full per-ID acceptance traceability; exact final phase CI and latest WordPress/WooCommerce patch testing; final Free/Pro ZIP, pre-release archive version and dependency license scan; official WordPress.org Plugin Check and readme validator; Woo managed QIT; vendor image/product copy and actual commercial/license service readiness.
- **Credential-only blockers** exist (WordPress.org 2FA/submitter/SVN committer, Woo vendor account/dashboard/QIT access), but they are **not** the only blockers today.

Official sources as of 2026-11-21: <https://wordpress.org/plugins/developers/>, <https://make.wordpress.org/plugins/2024/10/01/plugin-check-and-2fa-now-mandatory-for-new-plugin-submissions/>, <https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/>, <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>, <https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product>, <https://developer.woocommerce.com/docs/woo-marketplace/support-guidelines>.

Do not copy credentials, attach bank files, send money, submit to marketplaces or push SVN as part of this engineering task. Gate reclassification needs actual source-linked evidence, followed by owner permission; updating this checklist alone grants no publication rights.
