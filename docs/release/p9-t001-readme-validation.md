# P9-T001 — Canonical Free/Pro readme and WordPress.org format validation

Sources are `docs/source/product-metadata.json`, `docs/source/feature-catalog.json`, the reviewed installation, FAQ, support/privacy, changelog and upgrade-notice files. Generated readmes are not authoritative sources.

## Reproducible commands

- `npm run docs:generate`: real deterministic generator; updates Free WordPress.org `readme.txt`, Free/Pro `README.md`, root README, submission drafts and sync report.
- `npm run docs:check`: compares all generated files to canonical sources; fails on drift.
- `npm run docs:readme-validate`: runs `scripts/validate-wporg-readme.py`, a real *offline* structural validator checking official section layout, short description length, stable tag/main PHP version, tested and required platform metadata, genuine tags/dependencies, Free-only verified features and Pro Free API range.
- `python3 tests/docs_generation.py`: deliberate mutation tests; confirms invalid generated readme, PHP version drift, planned-feature promotion, duplicate tags, stable-tag mismatch, missing section and Pro-only feature injection fail.
- The Foundation CI in `.github/workflows/php-ci.yml` runs all these, plus packaged/plugin/Playground tests; the P9 branch retains native MariaDB, real browser RTL/privacy, version-matrix, upgrade and import-performance gates.

## What has not been verified

**WordPress.org official online readme validator: NOT VERIFIED.** This offline validator is not the official tool. Use https://wordpress.org/plugins/developers/readme-validator/ for the actual separately evidenced P9-T009 submission preflight.

**WordPress.org contributor usernames: NOT VERIFIED / empty.** No contributor account identifiers were invented. No screenshots, tags suggesting endorsement, unverified Pro-only features, public release claims, SVN upload or marketplace submission were fabricated.

Version 0.1.0 is a development preview. Tested WC 11.3.0 and WP 7.0 metadata are backed by actual P8-T005/T006 WordPress 7.0.8 / WooCommerce 11.3.0 native checkout/HPOS matrix; this is not certification of every intermediate version.

Free plugin version PHP headers remain authoritative in modern WordPress; WordPress.org readme format guidance: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/ .
