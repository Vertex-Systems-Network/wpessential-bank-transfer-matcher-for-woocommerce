#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
edition="${1:-}"
slug=wpessential-bank-transfer-matcher
case "$edition" in
  free) ;;
  pro) slug="$slug-pro" ;;
  *) printf 'Usage: bash scripts/package-plugin.sh free|pro\n' >&2; exit 2 ;;
esac

plugin="$root/plugins/$slug"
composer_cmd=("${WBM_COMPOSER:-composer}" --working-dir="$plugin")
"${composer_cmd[@]}" validate --strict
"${composer_cmd[@]}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
if php -r 'exit(json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["packages"] === [] ? 0 : 1);' "$plugin/composer.lock"; then
  printf '%s has no locked production dependencies to audit.\n' "$slug"
else
  "${composer_cmd[@]}" audit --locked --no-dev --no-interaction
fi
python3 "$root/scripts/build-plugin-zips.py" --edition "$edition"
