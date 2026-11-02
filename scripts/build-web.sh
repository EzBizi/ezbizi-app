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

# ── refuse to stamp a build nobody can reproduce ─────────────────
#
# The trailing "+" below means the tree was dirty. It was a marker, not a
# guard, and production shipped as `v0.0.0112 · 288a795+` — a bundle that
# corresponds to no commit. release.sh already refuses a dirty tree, but
# deploy.sh calls THIS script directly and walked around that check.
#
# Thirteen sessions share this working tree; it is routinely dirty with
# somebody else's work in progress. So the refusal names the files, because
# on a shared tree "the tree is dirty" sends you hunting through dozens of
# them at the exact moment you are trying to ship.
#
# --allow-dirty is the deliberate escape, and it still stamps the "+" so the
# artefact says of itself that it is not reproducible.
DIRTY=$(git status --porcelain --untracked-files=no)

if [ -n "$DIRTY" ]; then
  if [ "${1:-}" = "--allow-dirty" ]; then
    COMMIT="${COMMIT}+"
    echo "⚠ building from a dirty tree (--allow-dirty). This artefact cannot be"
    echo "  reproduced from ${COMMIT%+}; it is stamped + to say so."
  else
    echo "✗ refusing to build: the working tree has uncommitted changes." >&2
    echo "  A build from here cannot be reproduced from any commit, and the" >&2
    echo "  version it stamps would name one it does not match." >&2
    echo >&2
    echo "$DIRTY" | sed 's/^/    /' >&2
    echo >&2
    echo "  Commit or stash these, or pass --allow-dirty if you mean it." >&2
    exit 1
  fi
fi

BUILT_AT=$(date -u +'%Y-%m-%d %H:%M')

echo "→ building v${VERSION} · ${COMMIT}"

# --wasm (Jose, 2026-11-02): Chrome/Edge run skwasm from main.dart.wasm; every
# other browser falls back to main.dart.js + CanvasKit, whose wasm aborted 164
# times 10-16..11-02. Rollback: drop the flag in the next release.
(cd client && flutter build web --release --wasm \
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

# MaterialIcons is NOT content-hashed. Flutter writes the same filename every
# build and tree-shakes a different subset into it. A browser that kept the
# previous subset draws empty boxes for any icon this build added.
#
# BUILD_ID in the filename, not VERSION: VERSION is bumped by hand and two
# deploys of the same number would reuse the URL with different glyphs.
# FontManifest.json itself must not be cached (nginx exact-match no-cache);
# renaming the font is what makes a leftover cached manifest miss instead of
# silently drawing the old subset.
# PYTHONIOENCODING, because this script is run from Git Bash on Windows where
# Python's stdout defaults to cp1252 and the arrow below is not in it. The
# manifest rewrite had already succeeded; it was the log line that killed the
# deploy, with `set -e` taking the rest of the release down with it.
PYTHONIOENCODING=utf-8 python - "$COMMIT" <<'PY'
import json, sys
from pathlib import Path

build_id = sys.argv[1]
root = Path('client/build/web/assets')
src = root / 'fonts' / 'MaterialIcons-Regular.otf'
dst = root / 'fonts' / f'MaterialIcons-Regular.{build_id}.otf'
if not src.is_file():
    raise SystemExit(f'missing {src}')
src.replace(dst)
manifest_path = root / 'FontManifest.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
old = 'fonts/MaterialIcons-Regular.otf'
new = f'fonts/MaterialIcons-Regular.{build_id}.otf'
changed = 0
for family in manifest:
    for font in family.get('fonts', []):
        if font.get('asset') == old:
            font['asset'] = new
            changed += 1
if changed != 1:
    raise SystemExit(f'expected to rewrite 1 font path, rewrote {changed}')
manifest_path.write_text(json.dumps(manifest), encoding='utf-8')
print(f'  icon font → {new}')
PY

echo "✓ built v${VERSION} · ${COMMIT}"
