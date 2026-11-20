# P8-T002 — Private receipt and bank statement access audit

**Accepted: VERIFIED** on the exact branch HEAD `bafae85cff093bad371742654735ca77f2acfc4f` as of 2026-11-21. These are local synthetic security checks, **not** a penetration test or a public-site deployment.

## Exact CI evidence

- Two independent Foundation/WordPress Playground/native MariaDB runs: [44719809919](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44719809919) and [44719800103](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44719800103), both **SUCCESS** at exact HEAD.
- [Real PHP/Chromium browser run 44719800023](https://github.com/Vertex-Systems-Network/wpessential-bank-transfer-matcher-for-woocommerce/actions/runs/44719800023), **SUCCESS** at exact HEAD. Screenshot and JSON artifact **14621347905**. Steps `Verify synthetic statement outside public WordPress web root`, `Probe real unauthenticated and invalid-nonce receipt/statement HTTP endpoints`, LTR/Arabic RTL visual QA, real owner/finance/guest role tasks and no-claim/no-paid-state invariant all passed.
- Browser output: `PASS P8-T002 six real HTTP private statement/receipt negative access checks; no bank data leaked`. Native output for source SHA `464d0a3a` and latest native HEAD: scoped reviewer nonce/role check, anonymous/viewer denial, expired receipt byte purge, private random CSV path (outside WordPress root), 0600 file/0700 dir checks, staged SHA-256 integrity.

## Boundary checks and source files

| Entry point | Verified behavior |
| --- | --- |
| `admin_post_wpessential_wbm_download_receipt` | Registered only to authenticated actors; `AttachmentDownloads::authorized()` requires `wbm_review_claim` and the matching **order-specific** nonce |
| Anonymous receipt downloads | No valid public admin-post handler; real guest HTTP no/bad nonce attempts did not disclose receipt bytes |
| Read-only finance viewer | Cannot read another claim's customer receipt despite having `wbm_view`; an unrelated order ID and wrong nonce are denied |
| Expired receipt bytes | `ClaimRepository::attachment()` and `has_attachment()` fail for expired rows; `purge_expired_attachments()` removes stored bytes without deleting the claim |
| Pro CSV import source | Real `ImportJob::start()` stores a copied synthetic source with an opaque randomized filename and restricted permissions in a path **outside** WordPress docroot; SHA-256 verifies staged content |
| Direct private-source HTTP guesses | 3 guest URL guesses with 5 guest total probes, plus logged-in admin invalid nonce probe: no genuine CSV/receipt data exposed |

Some public `wp-content` URL guesses returned HTTP **200 with generic WordPress HTML fallback**, not CSV bytes. An initial overly strict test counted any 200 as a leak; it failed and was correctly changed to inspect the actual MIME type and known synthetic bank-data markers. This is **not** evidence that a private CSV became public. Neither private file bytes nor a CSV/octet-stream response was observed. A future proxy/static-server deployment must separately verify its document-root configuration.

## Reproduction

- Native integration: `wp eval-file tests/integration/p8-private-storage-access.php` after installing independently packaged Free + Pro on isolated WC/MariaDB `wbm_ci`.
- HTTP test in `.github/workflows/p7-browser-qa.yml`: `wp eval-file tests/browser/p8-private-http-fixture.php` and `node tests/browser/p8-private-http.mjs`; JSON record `p8-private-http-report.json` in artifact.
- No real bank evidence imported, no customer uploaded receipts or authorizing financial HTTP POST, and **no WooCommerce payment transition**. The CI artifact uses synthetic data only.

## Residual release risks

- This test is not a complete S3/object-storage reverse proxy audit; check the chosen merchant server/CDN's real static-file rules before release.
- HTTP multipart upload attacks, MIME confusion beyond current parser fixtures and penetration testing still require separate adversarial security tests in **P8-T003**.
- Review raw bank references, administrator report exports, financial retention and P9 production release access on their own gates. Do not claim an all-purpose security or privacy certification.
