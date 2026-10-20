#!/usr/bin/env bash
# Records the Cacheer Monitor walkthrough end to end:
#   1. starts the dashboard on 127.0.0.1:9966 with a fresh events file,
#   2. runs 75 seconds of real traffic (traffic.php) in the background,
#   3. drives the dashboard with Playwright (record.mjs),
#   4. stops everything.
# Requires: composer install (in evidence/), Node with npm, redis-server.
set -euo pipefail
cd "$(dirname "$0")"

export CACHEER_MONITOR_EVENTS="$PWD/var/events.jsonl"
mkdir -p var media
: > "$CACHEER_MONITOR_EVENTS"
[ -d node_modules/playwright ] || npm ci --no-audit --no-fund --silent

../vendor/bin/cacheer-monitor serve --port=9966 > var/serve.log 2>&1 &
server=$!
# The dashboard runs PHP's built-in server with worker processes, so stop every
# process listening on the port, not just the one started here.
trap 'kill "$server" 2>/dev/null || true; lsof -ti tcp:9966 -sTCP:LISTEN | xargs kill 2>/dev/null || true' EXIT

for _ in $(seq 50); do curl -fs http://127.0.0.1:9966/api/health >/dev/null && break; sleep 0.1; done

php traffic.php --seconds=75 > var/traffic.log 2>&1 &
traffic=$!
node record.mjs
wait "$traffic"
cat var/traffic.log
