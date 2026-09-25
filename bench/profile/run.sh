#!/bin/bash
# Profiles a rebar site's pages with Excimer, on its server, with profiling
# enabled in the site's pool (see prepend.php). Writes
# <site>/rebar/profile/<mode>_<page>.folded.
# Usage: run.sh [requests-per-page]
#   REBAR_HOST  the site's hostname (its nginx server_name)
#   REBAR_ROOT  its root (default /var/www/<hostname>)
set -euo pipefail
N="${1:-200}"
HOST="${REBAR_HOST:?set REBAR_HOST}"
SITE="${REBAR_ROOT:-/var/www/$HOST}"
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
rm -f "$SITE"/rebar/profile/*.folded

# Settle cron first, as remote-bench.sh does, so it can't run mid-profile.
DRUSH="sudo -u www-data $SITE/bin/drush"
for i in $(seq 120); do
  [ "$(cd "$SITE" && $DRUSH php:eval 'echo (int) \Drupal::lock()->lockMayBeAvailable("cron");' 2>/dev/null)" = 1 ] && break
  sleep 5
done
(cd "$SITE" && $DRUSH cron -q 2>/dev/null)

url=$(cd "$SITE" && sudo -u www-data bin/drush uli --no-browser --uri="http://$HOST" 2>/dev/null)
curl -s -o /dev/null -c "$JAR" -H "Host: $HOST" -L "http://127.0.0.1${url#http://$HOST}"
COOKIE=$(awk '$6 ~ /^S?SESS/ {print $6 "=" $7}' "$JAR")

for mode in auth anon; do
  for path in / /node/51 /taxonomy/term/10; do
    args=(-q -l -c 4 -H "Host: $HOST")
    [ "$mode" = auth ] && args+=(-C "$COOKIE")
    label="${mode}_$(echo "$path" | tr -c 'a-z0-9\n' '_' | sed 's/^_*//; s/_*$//')"
    label="${label%_}"; [ "$path" = / ] && label="${mode}_front"
    ab "${args[@]}" -n 40 "http://127.0.0.1$path" >/dev/null
    ab "${args[@]}" -n "$N" -H "X-Rebar-Profile: $label" "http://127.0.0.1$path" | awk -v l="$label" '/^Requests per second/ {print l, $4 " req/s while profiling"}'
  done
done
ls -la "$SITE"/rebar/profile/
