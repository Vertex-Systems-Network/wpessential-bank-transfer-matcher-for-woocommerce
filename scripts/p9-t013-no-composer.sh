#!/usr/bin/env bash
# Real disposable WordPress must load both original installed plugin ZIPs without a Composer program.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
site="$1"
if [[ "$GITHUB_ACTIONS" != "true" || -z "$site" ]]; then echo "CI disposable only" >&2; exit 2; fi
if [[ "$(wp --path="$site" config get DB_NAME)" != "wbm_ci" ]]; then echo "Non-disposable DB denied" >&2; exit 2; fi
wpbin="$(command -v wp)"
phpbin="$(command -v php)"
d="$RUNNER_TEMP/p9-t013-php-wp-only"
mkdir -p "$d"
ln -s "$phpbin" "$d/php"
ln -s "$wpbin" "$d/wp"
echo "Testing actual Composer-free WordPress Free/Pro runtime with isolated PATH"
WBM_P9_NO_COMPOSER=1 PATH="$d" "$d/wp" --path="$site" eval-file "$root/tests/integration/p9-composerless.php"
echo "PASS actual plugin ZIP native runtime without Composer in PATH"
