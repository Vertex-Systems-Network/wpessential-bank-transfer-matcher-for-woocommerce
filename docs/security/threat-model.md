# Financial and security threat model

Create `docs/security/threat-model.md` covering assets, adversaries, trust boundaries, attack paths, mitigations, residual risks. Required reviews:

1. Malicious admin upload (CSV formula injection, oversized rows, nested quotes, resource exhaustion, encoding tricks, path traversal, XML XXE).
2. Guest claim abuse: enumeration, token replay, CSRF, attachment upload abuse, forged sender names/references, spam, leaking other orders.
3. WP privilege escalation via REST/AJAX action, insecure direct object references, missing capability checks on statement/receipt download.
4. Stored/reflected XSS through transaction descriptions, notes, CSV headers, claimant names, bank references.
5. SQL injection/unsafe table names or order query leakage.
6. Race condition/double-approval/replay, status transition duplication, DB transaction failure, stale amount allocation.
7. Licensing/update channel trust, supply-chain and dependency vulnerability scanning.
8. Logs/exports backups containing full bank or customer data.
9. Data retention, user consent, privacy exports/deletion; recordkeeping obligations by jurisdiction to be assessed by merchant, not falsely promised as universal compliance.
10. Multi-currency and malformed numeric overflow, rounding, zero-decimal units and changed currency metadata.
11. Reversal/cancellation after fulfillment; avoid changing accounting state without explicit case.
12. Known plugin conflicts, HPOS, checkout Blocks, different WC versions and unsupported DB setups.

Required automated tests: authorization matrix, nonces, object-level permissions, content validation, upload privacy, CSV formula injection, XML external entities disabled, replay of approval ID, concurrent allocation, tampered imported evidence, data redaction.

**Security gate:** no unresolved critical/high vulnerabilities before release. Moderate issues require explicit risk/mitigation record and release policy review.

---
