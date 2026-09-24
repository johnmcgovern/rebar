#!/bin/bash
# Benchmarks drupal01 (stock) against drupal02 (drust) on server, from the
# server itself over loopback. Sites are measured alternately, per path, so
# drift over time affects both equally.
#
# Usage: remote-bench.sh [requests] [concurrency] [rounds]
# Output: one line per (round, mode, site, path) with ab's figures.
set -euo pipefail
N="${1:-300}"
C="${2:-4}"
ROUNDS="${3:-3}"
SITES=(drupal01 drupal02)
PATHS=(/ /node/51 /taxonomy/term/10)
JAR_DIR="$(mktemp -d)"
trap 'rm -rf "$JAR_DIR"' EXIT

drush() {
  local site=$1; shift
  local root=/var/www/$site.example.com
  if [ -x "$root/bin/drush" ]; then
    (cd "$root" && sudo -u www-data bin/drush "$@")
  else
    (cd "$root" && sudo -u www-data vendor/bin/drush "$@")
  fi
}

# Runs cron to completion (waiting for any run in progress), so automated
# cron (every 3 hours) can't fire mid-benchmark. A cron run in progress makes
# every request that ends try to start cron too and log a warning: with a
# slow cron (search indexing) that costs ~200ms per request, on both sites.
settle_cron() {
  local site=$1 i
  for i in $(seq 120); do
    [ "$(drush "$site" php:eval 'echo (int) \Drupal::lock()->lockMayBeAvailable("cron");' 2>/dev/null)" = 1 ] && break
    sleep 5
  done
  drush "$site" cron -q 2>/dev/null
}

# Logs in as user 1 via a one-time link and prints the session cookie.
login_cookie() {
  local site=$1 url
  url=$(drush "$site" uli --no-browser --uri="http://$site.example.com" 2>/dev/null)
  url="http://127.0.0.1${url#http://$site.example.com}"
  curl -s -o /dev/null -c "$JAR_DIR/$site" -H "Host: $site.example.com" -L "$url"
  awk '$6 ~ /^S?SESS/ {print $6 "=" $7}' "$JAR_DIR/$site"
}

run_ab() {
  local site=$1 path=$2 cookie=$3
  local args=(-q -l -n "$N" -c "$C" -H "Host: $site.example.com")
  [ -n "$cookie" ] && args+=(-C "$cookie")
  # Warm up: fill caches and opcache, and let every worker see the page.
  ab -q -n 40 -c "$C" "${args[@]:6}" "http://127.0.0.1$path" >/dev/null 2>&1 || true
  ab "${args[@]}" "http://127.0.0.1$path" 2>/dev/null | awk -v s="$site" -v p="$path" '
    /^Failed requests/ {fail=$3}
    /^Non-2xx responses/ {non2xx=$3}
    /^Requests per second/ {rps=$4}
    /^  50%/ {p50=$2} /^  95%/ {p95=$2} /^  99%/ {p99=$2}
    END {printf "%s %s rps=%s p50=%sms p95=%sms p99=%sms failed=%s non2xx=%s\n", s, p, rps, p50, p95, p99, fail+0, non2xx+0}'
}

declare -A COOKIE
for site in "${SITES[@]}"; do
  settle_cron "$site"
  drush "$site" cr -q
  COOKIE[$site]=$(login_cookie "$site")
  [ -n "${COOKIE[$site]}" ] || { echo "login failed for $site" >&2; exit 1; }
done

echo "# $(date -Is) n=$N c=$C rounds=$ROUNDS"
for round in $(seq "$ROUNDS"); do
  for mode in anon auth; do
    for path in "${PATHS[@]}"; do
      for site in "${SITES[@]}"; do
        cookie=""
        [ "$mode" = auth ] && cookie="${COOKIE[$site]}"
        echo "round=$round mode=$mode $(run_ab "$site" "$path" "$cookie")"
      done
    done
  done
done
