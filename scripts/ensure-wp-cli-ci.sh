#!/usr/bin/env bash
# Fallback for setup-php@v2 sometimes reporting success while its optional
# WP-CLI tool download fails. CI-only: never installed inside either plugin.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != "true" || -z "${RUNNER_TEMP:-}" || -z "${GITHUB_PATH:-}" ]]; then
  echo 'This bootstrap is restricted to ephemeral GitHub Actions runners.' >&2
  exit 2
fi
if command -v wp >/dev/null 2>&1 && wp --info >/dev/null 2>&1; then
  echo 'PASS existing WP-CLI executable is ready.'
  exit 0
fi
version='2.12.0'
sha256='ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c'
url="https://github.com/wp-cli/wp-cli/releases/download/v${version}/wp-cli-${version}.phar"
tmp="$(mktemp "${RUNNER_TEMP}/wbm-wp-cli.XXXXXX")"
trap 'rm -f "$tmp"' EXIT
curl --fail --location --silent --show-error --retry 3 --retry-all-errors \
  --retry-delay 2 --connect-timeout 15 --max-time 120 --output "$tmp" "$url"
echo "$sha256  $tmp" | sha256sum --check --status || {
  echo 'Official WP-CLI release SHA-256 mismatch; refusing to execute.' >&2
  exit 1
}
dir="${RUNNER_TEMP}/wbm-ci-pinned-tools"
mkdir -p "$dir"
install -m 0755 "$tmp" "$dir/wp"
php "$dir/wp" --info >/dev/null
echo "$dir" >> "$GITHUB_PATH"
echo 'PASS official WP-CLI 2.12.0 SHA256-verified fallback installed for later CI steps.'
