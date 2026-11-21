# P9-T005 — Actual base, Free and Pro Playground bundle provenance

**Implementation draft; not release accepted.** Existing Foundation CI runs real `@wp-playground/cli@3.1.65` on the disposable Blueprint scenarios `base`, `free`, `pro`, missing Woo/Free, incompatible versions, lifecycle and permissions. Native MariaDB job separately installs the exact plugin ZIPs, exercises Free/Pro activation/reinstall and HPOS/classic order/ledger behavior. These are different trust boundaries: Playground SQLite bootstrap is not SQL concurrency proof.

The new `scripts/verify-playground-release-bundles.py` rejects unexpected ZIP members, missing/altered embedded Free/Pro ZIPs, incorrect WooCommerce and Free-before-Pro activation order, bad Blueprint source, and unsafe resource paths; it verifies exact-byte SHA256 parity with the edition-isolated production ZIPs from this same CI runner. A real tampered nested ZIP is rejected in the negative fixture, and output JSON makes clear that binary verification alone is **not** a live execution result.

After the live Playground tests and integrity verifier pass, Foundation CI uploads three real `playground-base/free/pro.zip` bundles plus `p9-playground-bundle-manifest.json` as named `p9-playground-bundles` GitHub Actions artifact. Requires the **exact new source SHA** and successful native MariaDB clean-install/HPOS/classic run before T005 is accepted. Archive provenance needs distinct execution evidence from successful Playground steps and SQL tests, not just the manifest.

No real statement, bank details, customer PII, live store, customer payment confirmation, submission or external Marketplace authorization is in scope. Compatibility patches, QIT and WordPress.org remain separate P9 gates.
