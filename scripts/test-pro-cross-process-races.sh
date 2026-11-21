#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
if [[ $# -lt 1 ]]; then echo "Provide disposable WordPress path" >&2; exit 1; fi
site="$1"
wp_cli="wp"
if [[ $# -ge 2 ]]; then wp_cli="$2"; fi
fixture="$root/tests/integration/pro-cross-process-races.php"
wp_race() { "$wp_cli" --path="$site" --no-color eval-file "$fixture" "$@"; }
if [[ "$("$wp_cli" --path="$site" --no-color config get DB_NAME)" != wbm_ci ]]; then
  echo "Refusing test outside disposable wbm_ci" >&2
  exit 1
fi
scratch="$(mktemp -d /tmp/wbm-pro-race-XXXXXX)"
primary_pid=""
free_pid=""
cleanup() {
  if [[ -n "$primary_pid" ]] && kill -0 "$primary_pid" 2>/dev/null; then
    kill "$primary_pid" 2>/dev/null || true
    wait "$primary_pid" 2>/dev/null || true
  fi
  if [[ -n "$free_pid" ]] && kill -0 "$free_pid" 2>/dev/null; then
    kill "$free_pid" 2>/dev/null || true
    wait "$free_pid" 2>/dev/null || true
  fi
  wp_race cleanup >/dev/null 2>&1 || true
  rm -rf "$scratch"
}
trap cleanup EXIT
wp_race setup
race_phase() {
  local stage="$1" signal="$scratch/$1.ready" primary="$scratch/$1.primary.log" contender="$scratch/$1.contender.log"
  wp_race "$stage"_hold "$signal" >"$primary" 2>&1 &
  primary_pid=$!
  local signaled=no
  for _ in $(seq 1 100); do
    if [[ -f "$signal" ]]; then signaled=yes; break; fi
    if ! kill -0 "$primary_pid" 2>/dev/null; then
      echo "Primary worker died before taking named locks" >&2; cat "$primary" >&2; exit 1
    fi
    sleep 0.1
  done
  if [[ "$signaled" != yes ]]; then
    echo "Primary worker never signaled held named locks" >&2; cat "$primary" >&2; exit 1
  fi
  local free_log="$scratch/$1.free.log"
  if [[ "$stage" == propose || "$stage" == approve || "$stage" == complete ]]; then
    wp_race free_contender >"$free_log" 2>&1 &
    free_pid=$!
  fi
  if ! wp_race "$stage"_contender >"$contender" 2>&1; then cat "$contender" >&2; exit 1; fi
  if [[ -n "$free_pid" ]]; then
    if ! wait "$free_pid"; then cat "$free_log" >&2; exit 1; fi
    free_pid=""
    cat "$free_log"
  fi
  cat "$contender"
  # Keep the primary DB advisory locks held until both independent contenders
  # have made their real WP-CLI requests and received lock denials.
  touch "$scratch/$stage.release"
  if ! wait "$primary_pid"; then cat "$primary" >&2; exit 1; fi
  primary_pid=""
  cat "$primary"
  wp_race "verify_$stage"
}
race_phase propose
race_phase approve
race_phase complete
wp_race split_setup
race_phase split_approve
race_phase split_finalize
wp_race split_finish
echo "PASS real simultaneous WooCommerce maker, checker, split recipient and paid-intent races"
