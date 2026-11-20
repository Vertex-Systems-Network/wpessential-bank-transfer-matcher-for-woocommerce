# P9-T003 — Source-backed release screenshots

Engineering work in review. This is not an approved marketplace asset or a release claim.

New `scripts/p9-release-screenshots.py` takes the real WordPress/WooCommerce/Playwright browser test output (directory or downloaded ZIP), verifies all six disposable LTR/Arabic RTL/role/navigation reports have zero issues, validates each capture against actual reported PNG width/direction and produces exact-byte Free and Pro screenshots with SHA256 in `evidence-manifest.json`.

Expected contents: 5 Free screenshots, 6 Pro screenshots, 2 RTL/mobile QA captures. No Free screenshot displays paid-only Pro screens. No source image is post-edited, no synthetic payment claim authorizes a paid-state transition, and there is no mock UI.

On `development/p9-t003-source-browser-screenshots-20261121`, real browser CI is configured to run this generator **after** all financial-state and usability steps and upload `p9-source-verified-release-screenshots`. Record the final run ID, original WordPress evidence artifact ID, generated release screenshot artifact ID and manual content review only when this pipeline actually passes. Browser workflow screenshot artifacts expire; archive approved assets separately before distribution.

Historical reference only (not proof of this new generator): native source SHA a515d8f6ac98a2fe94ebedb35b3f2e70b9164501, browser run https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44837254602, real screenshot artifact 14679601545. Existing test reports and PNGs were downloaded and visually inspected as synthetic staging outputs.

Outstanding release checks: screenshot clarity, truthful captions, incidental PII, accessibility, vendor design/brand approval, official Woo QIT results, WordPress.org listing requirements and publication permission. Do not mark P9-T003 complete until exact-head screenshot artifact and manifest have been inspected.
