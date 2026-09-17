#!/usr/bin/env bash
# The backend half of the release gate, without the release.
#
# 2026-09-17: 0.0.0238 was refused TWICE at the gate, 25 minutes in each time,
# on backend failures — 23 stale TimeClock fixtures, then 7 more across four
# modules' files. Both times the desk had gated the client (analyze + 2020
# tests, clean) and sent it to the gate without running the backend suite,
# because the backend suite lives in the sandbox on the box and there was no
# way to ask for it on its own. There is now.
#
#     bash scripts/preflight-backend.sh            # HEAD
#     bash scripts/preflight-backend.sh <sha>      # any commit
#     bash scripts/preflight-backend.sh <sha> Hr   # --filter Hr
#
# It publishes NOTHING. Same sandbox, same flock and the same 1G phpunit
# invocation deploy.sh uses, so a green run here is evidence about the tree a
# cut would gate — and it cannot collide with a real deploy, it waits for it.
set -euo pipefail

REMOTE=app
SHA=$(git rev-parse "${1:-HEAD}")
FILTER=${2:-}

# Derived, never hand-written: every top-level entry the archive is about to
# provide. A hand-kept list is how a deleted class goes on passing.
WIPE=$(git ls-tree --name-only "$SHA" | grep -vx storage | tr '\n' ' ')

echo "── backend pre-flight · ${SHA:0:8}${FILTER:+ · filter $FILTER} ──"

ARGS="--colors=never"
[ -n "$FILTER" ] && ARGS="$ARGS --filter $FILTER"

# The verdict is printed, not just returned. 2026-09-17: I ran this as
# `preflight-backend.sh main | tail -60` and read "[exited with code 0]" while
# the output above it said "FAILURES! Tests: 4077 ... Failures: 2" -- a pipeline
# exits with its LAST command's status, so tail's 0 replaced phpunit's 1. That
# is the same shape as release.sh reporting exit 0 over "nothing deployed",
# twice today. So the last line says which it was in words, and a piped
# invocation cannot hide it.
rc=0
git -c core.autocrlf=false archive "$SHA" | ssh "$REMOTE" "WIPE='$WIPE' ARGS='$ARGS'"'
  set -e
  mkdir -p /opt/ezbizi-test
  # The same lock the deploy gate takes. Two runs in this directory is how
  # bootstrap/app.php vanished mid-suite; wait rather than wipe.
  exec 9>/opt/ezbizi-test/.deploy-gate.lock
  flock -w 3600 9 || { echo "sandbox busy — a deploy holds the lock"; exit 1; }
  cd /opt/ezbizi-test
  rm -rf $WIPE
  rm -f .phpunit.result.cache
  tar -x
  composer install --no-interaction -q
  php8.4 -d memory_limit=1G vendor/bin/phpunit $ARGS
' || rc=$?

if [ "$rc" -eq 0 ]; then
  echo "== PRE-FLIGHT GREEN · ${SHA:0:8} · backend suite passed =="
else
  echo "== PRE-FLIGHT FAILED · ${SHA:0:8} · backend suite exit $rc · NOTHING SHOULD BE CUT =="
fi

exit "$rc"
