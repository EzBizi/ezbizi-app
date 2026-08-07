#!/bin/bash
#
# Build the web client with its identity stamped in.
#
# ── Why this is a script and not a command I type ────────────────
#
# The version on screen exists to answer "am I looking at the newest build?".
# It can only answer that if it is impossible to ship without it — a flag I
# remember to pass most of the time produces a number that is right most of the
# time, which is worse than no number at all.
#
# Three values go in:
#
#   APP_VERSION  from client/VERSION — a human number, bumped deliberately
#   BUILD_ID     the git commit, with `+` when the tree is dirty
#   BUILT_AT     UTC, to the minute
#
# BUILD_ID is the one that cannot lie. It changes on every commit whether or not
# anybody remembered to touch VERSION, so comparing what the app shows against
# `git log -1` on the box settles the question exactly.
#
# The `+` on a dirty tree matters: that build is not reproducible from the hash
# it claims, and seeing the marker is the difference between "this is commit
# abc1234" and "this is roughly commit abc1234".

set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(tr -d '[:space:]' < client/VERSION)
COMMIT=$(git rev-parse --short HEAD)

git diff --quiet && git diff --cached --quiet || COMMIT="${COMMIT}+"

BUILT_AT=$(date -u +'%Y-%m-%d %H:%M')

echo "→ building v${VERSION} · ${COMMIT}"

(cd client && flutter build web --release \
  --pwa-strategy=none \
  --dart-define=API_BASE= \
  --dart-define=APP_VERSION="${VERSION}" \
  --dart-define=BUILD_ID="${COMMIT}" \
  --dart-define=BUILT_AT="${BUILT_AT}")

# The service-worker kill switch, over the empty file Flutter writes.
#
# `--pwa-strategy=none` does not omit flutter_service_worker.js — it writes it
# EMPTY. An empty worker installs and then WAITS, because nothing calls
# skipWaiting(), so it never takes over while a tab the old worker controls is
# open. Anybody who leaves the tab open keeps the old build indefinitely, which
# is exactly what happened. Ours skips waiting, clears every cache, unregisters
# itself and reloads the tabs.
cp client/web/sw-kill.js client/build/web/flutter_service_worker.js

echo "✓ built v${VERSION} · ${COMMIT}"
