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

The version and lifecycle checks can also run on their own.

```sh
python3 scripts/test-playground-foundation.py \
  --scenario woo-outdated --scenario free-outdated \
  --scenario free-new-major --scenario lifecycle
```

The version checks isolate the bootstrap guards. One fixture reports WooCommerce 10.7.0. Two transient copies of the Free archive report API 0.9.0 and 2.0.0. The normal packages are untouched. The lifecycle check uses the normal Free and Pro packages and WooCommerce, with a fresh WordPress request after each deactivation or reactivation.

Run `python3 scripts/test-playground-foundation.py --scenario access` to verify the capability map and admin page access for administrators, shop managers, customers, anonymous users and a custom read-only role. This scenario uses WordPress's in-process REST dispatcher. HTTP authentication and cookie nonce handling remain WordPress core responsibilities.
