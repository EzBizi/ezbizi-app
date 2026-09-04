#!/usr/bin/env bash
# Mac App Store screenshots, taken from the real app in a real window on the
# relay Mac, by client/integration_test/store_screenshots_test.dart.
#
#   ./scripts/store-screenshots.sh            the real set, signed in as the demo tenant
#   ./scripts/store-screenshots.sh --probe    mechanics only: placeholder password,
#                                             proves window size and capture, lands
#                                             on the sign-in screen with an error
#
# Credentials live ONLY on the Mac, in ~/ezbizi-signing/screenshot.env:
#
#   SHOT_EMAIL=apple@ezbizi.com
#   SHOT_PASSWORD=<the demo tenant's password>
#
# That file is sourced on the Mac inside the remote half below and never
# printed, copied or read by anything on this side. The demo tenant, never
# A1-Group: these images go on a public product page.
#
# Output: Distribution/Apple/screenshots/macos/*.png at 1440x900 (or 2880x1800
# on a Retina relay), which are two of the four sizes Apple accepts for Mac.
#
# The footer of every frame carries APP_VERSION and BUILD_ID. Those describe
# the tree this run SYNCS to the Mac, not merely the caller's HEAD: the first
# version of this script stamped the caller's SHA onto whatever build-app.sh
# had last left in ~/ezbizi-build, and one run stamped b8bd4286 onto a tree
# that differed from it by 17 .dart files (the Apple session hashed all 272
# to prove it). So the run syncs first, the same way the release does, and
# the stamp is true because the tree is the one compiled. A dirty caller
# tree gets the same "+" build-app.sh puts on its artefacts.
set -euo pipefail

# ── remote half ─────────────────────────────────────────────────────
# Runs on the Mac via `bash -s`, so this file needs no nested quoting.
if [ "${1:-}" = "--remote" ]; then
  # A non-interactive ssh shell has no flutter on PATH, and the Mac holds two
  # installs: ~/dev/flutter (3.41.5, Dart 3.11.3, FAILS pubspec's ^3.11.4) and
  # /opt/homebrew/bin/flutter (3.41.6, Dart 3.11.4, the working one). Homebrew
  # first, or the run dies at dependency resolution with an SDK-version
  # message nowhere near the real cause.
  export PATH=/opt/homebrew/bin:/usr/local/bin:$PATH
  cd ~/ezbizi-build
  SHOT_PROBE=false
  if [ "${PROBE:-0}" = "1" ]; then
    SHOT_EMAIL=apple@ezbizi.com; SHOT_PASSWORD=placeholder; SHOT_PROBE=true
  else
    if [ ! -f ~/ezbizi-signing/screenshot.env ]; then
      echo "✗ ~/ezbizi-signing/screenshot.env is missing on the Mac (SHOT_EMAIL=, SHOT_PASSWORD=)" >&2
      exit 2
    fi
    set -a; . ~/ezbizi-signing/screenshot.env; set +a
  fi
  # The debug app is sandboxed, so the test writes into its container.
  OUT="$HOME/Library/Containers/com.ezbizi.app/Data/store-screenshots/macos"
  rm -rf "$OUT"
  pkill -x EzBizi 2>/dev/null || true
  LOG=/tmp/store-screenshots.log
  EZBIZI_WINDOW=1440x900 flutter drive -d macos \
    --driver=test_driver/screenshots.dart \
    --target=integration_test/store_screenshots_test.dart \
    --dart-define=API_BASE=https://app.ezbizi.com \
    --dart-define=APP_FACE=customer \
    --dart-define=APP_VERSION="${APP_VERSION:-dev}" \
    --dart-define=BUILD_ID="${BUILD_ID:-local}" \
    --dart-define=SHOT_PROBE="$SHOT_PROBE"     --dart-define=SHOT_EMAIL="$SHOT_EMAIL" \
    --dart-define=SHOT_PASSWORD="$SHOT_PASSWORD" > "$LOG" 2>&1 || true
  pkill -x EzBizi 2>/dev/null || true
  grep -E "wrote |skipped |All tests passed|Some tests failed" "$LOG" || true
  if ! ls "$OUT"/*.png >/dev/null 2>&1; then
    echo "✗ no screenshots were written — last lines of $LOG:" >&2
    tail -40 "$LOG" >&2
    exit 3
  fi
  for f in "$OUT"/*.png; do
    echo "  $(basename "$f"): $(sips -g pixelWidth -g pixelHeight "$f" | awk '/pixel/{printf "%s ", $2}')px"
  done
  exit 0
fi

# ── local half ──────────────────────────────────────────────────────
cd "$(dirname "$0")/.."
MAC=mac
MAC_PROJECT='~/ezbizi-build'
PROBE=0; [ "${1:-}" = "--probe" ] && PROBE=1
DIST="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/Distribution/Apple/screenshots/macos"
# A probe's frames are sign-in screens with an error banner. Written into the
# real set they overwrite 01-sign-in and 02-dashboard with wrong pictures under
# right names, which happened once on 2026-09-04. Probes get their own folder.
[ "$PROBE" -eq 1 ] && DIST="${DIST}-probe"
mkdir -p "$DIST"
APP_VERSION=$(tr -d '[:space:]' < client/VERSION)
BUILD_ID=$(git rev-parse --short HEAD)
# Same marker, same exclusion, as build-app.sh: another session's open doc is
# not this build's dirt. Screenshots are not a release artefact, so a dirty
# tree is allowed here — but the frame says so of itself.
if [ -n "$(git status --porcelain --untracked-files=no -- . ':(exclude)docs')" ]; then
  BUILD_ID="${BUILD_ID}+"
fi

# The sync below wipes the relay's project directory. A release mid-build
# there would lose its tree under xcodebuild's feet.
if ssh "$MAC" 'pgrep -f "xcodebuild|flutter_tools" >/dev/null 2>&1'; then
  echo "✗ the relay is compiling something right now (xcodebuild or flutter running). Try again when it is quiet." >&2
  exit 4
fi

# Sync the caller's client tree, the way build-app.sh does and with its
# exclude list (keep the two in step; each exclude there has a reason written
# beside it), plus the two test halves the release sync deliberately omits.
echo "→ syncing client tree ${APP_VERSION} · ${BUILD_ID} to the Mac"
ssh "$MAC" "rm -rf ${MAC_PROJECT} && mkdir -p ${MAC_PROJECT}"
tar -cz \
  --exclude='.symlinks' \
  --exclude='Pods' \
  --exclude='build' \
  --exclude='.dart_tool' \
  --exclude='Flutter/Generated.xcconfig' \
  --exclude='Flutter/flutter_export_environment.sh' \
  --exclude='Flutter/ephemeral' \
  --exclude='.last_build_id' \
  --exclude='Podfile.lock' \
  --exclude='DerivedData' \
  --exclude='.DS_Store' \
  -C client \
  VERSION pubspec.yaml pubspec.lock analysis_options.yaml lib ios macos integration_test test_driver \
  | ssh "$MAC" "tar -xz -C ${MAC_PROJECT}"

echo "→ macos — capturing on the Mac relay as v${APP_VERSION} · ${BUILD_ID}$([ "$PROBE" -eq 1 ] && echo ' (probe: placeholder password)')"
ssh "$MAC" "PROBE=$PROBE APP_VERSION=$APP_VERSION BUILD_ID=$BUILD_ID bash -s -- --remote" < "$0"

scp -q "$MAC:Library/Containers/com.ezbizi.app/Data/store-screenshots/macos/*.png" "$DIST/"
echo "✓ $(ls "$DIST"/*.png | wc -l | tr -d ' ') screenshot(s) → $DIST"
