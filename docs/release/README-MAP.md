# Release documentation map

Edit sources in `docs/source/`, then run `node scripts/generate-readmes.ts`. CI runs `node scripts/generate-readmes.ts --check` to reject generated drift and plugin header mismatches.

| Source | Output |
| --- | --- |
| Product metadata | Root and edition readmes, Free WordPress.org readme and sync report |
| Feature catalog | Verified preview scope and per-edition listing drafts |
| Installation, FAQ, support and privacy | Edition quick starts and support guidance |
| Versioned changes and upgrade notices | Edition changelogs and upgrade notes |

| Verified feature | Screen | Evidence |
| --- | --- | --- |
| F-FOUNDATION-ADMIN | WooCommerce Matcher admin entry point | `playground/evidence/admin-shell-2026-11-17.log` |
| F-FOUNDATION-DEPENDENCY | WordPress Plugins activation | `playground/evidence/version-lifecycle-2026-11-17.log` |

The generated listings are development drafts. The official readme validator, contributor usernames, tested-version metadata, and merchant screenshots are pending release verification.
