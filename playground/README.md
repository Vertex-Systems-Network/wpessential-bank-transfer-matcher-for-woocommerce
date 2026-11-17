# WordPress Playground

Run the disposable base environment with Node.js 24.18 or later.

```sh
npx --yes @wp-playground/cli@3.1.65 run-blueprint --blueprint=playground/blueprints/base/blueprint.json
```

The base Blueprint loads WooCommerce from WordPress.org. The 7.0 WordPress stream is selected because the current WooCommerce release rejected the 6.9 stream. The pinned minimum WordPress and WooCommerce combination needs a separate install check. Playground uses SQLite in this scenario. Verify transaction integrity separately on MySQL or MariaDB.

Build both development archives with the commands in [Start here](../START_HERE.md), then run all bootstrap scenarios.

```sh
python3 scripts/test-playground-foundation.py
```

Use `--scenario woo-missing --scenario free-missing` to exercise only the missing dependency scenarios.
