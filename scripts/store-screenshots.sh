#!/usr/bin/env bash
# Mac App Store screenshots, taken from the real app in a real window on the
# relay Mac, by client/integration_test/store_screenshots_test.dart.
#
#   ./scripts/store-screenshots.sh            the real set, signed in as the demo tenant
#   ./scripts/store-screenshots.sh --probe    mechanics only: placeholder password,
#                                             proves window size and capture, lands
#                                             on the sign-in screen with an error
#   ./scripts/store-screenshots.sh --device <simulator udid> <set>
#                                             an iOS simulator instead of the Mac
#                                             window, into screenshots/<set>, e.g.
#                                             iphone69 or ipad13 (`xcrun simctl
#                                             list devices` on the relay for udids)
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

  # integration_test is deliberately absent from pubspec.yaml — declared, it
  # breaks EVERY Android release build on Flutter 3.41.6, and the whole reason
  # is written out beside dev_dependencies in client/pubspec.yaml.
  #
  # The harness cannot run without it, so the run adds it here and takes it out
  # again on the way through. It was two edits in a comment before this, which
  # is a bad place to keep a step that breaks the Play build when it is
  # forgotten — the failure lands on a release days later, not on the person
  # who took the screenshots.
  #
  # Nothing here can reach the real repo. This half runs on the Mac, against a
  # tree that both this script and build-app.sh `rm -rf` before every sync.
  cp pubspec.yaml pubspec.yaml.harness-off
  trap 'mv -f pubspec.yaml.harness-off pubspec.yaml 2>/dev/null || true' EXIT
  awk '{print} /^dev_dependencies:[[:space:]]*$/{print "  integration_test:"; print "    sdk: flutter"}'     pubspec.yaml.harness-off > pubspec.yaml
  # awk prints the file unchanged if its anchor ever moves, and the run would
  # then die inside `flutter drive` with an error about the target, nowhere near
  # the cause. Fail here instead, naming the anchor.
  #
  # The anchor tolerates trailing whitespace because this tree is synced from
  # Windows and arrives with CRLF: BSD awk on the Mac does not strip the , so
  # a bare $ matches nothing there while matching fine on the box that wrote it.
  if ! grep -q '^  integration_test:' pubspec.yaml; then
    echo "x could not add integration_test to pubspec.yaml - the 'dev_dependencies:' line it anchors on has moved" >&2
    exit 5
  fi
  flutter pub get > /dev/null
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
  if [ -n "${DEVICE:-}" ]; then
    # A simulator frame goes through binding.takeScreenshot to the driver on
    # this side, which writes store-screenshots/$SHOT_DIR/ under the project.
    OUT="$HOME/ezbizi-build/store-screenshots/$SET"
    xcrun simctl boot "$DEVICE" 2>/dev/null || true   # already booted is fine
    # Signed out every run: "Keep me signed in" puts the token in the
    # keychain, and the next run opened signed in with no form to fill.
    #
    # NOT an uninstall. That also forgets the notification answer, and the
    # system prompt AppDelegate raises at launch then sits over the app:
    # every frame came back blank white while the test passed.
    # ponytail: a new or erased simulator needs that prompt answered once.
    # Over ssh nothing can tap it (no assistive access); the 2026-10-06 fix
    # was authorizationStatus=2 for com.ezbizi.app in the shut-down sim's
    # data/Library/BulletinBoard/VersionedSectionInfo.plist.
    xcrun simctl keychain "$DEVICE" reset
    export SHOT_DIR="$SET"
  else
    # The debug app is sandboxed, so the test writes into its container.
    OUT="$HOME/Library/Containers/com.ezbizi.app/Data/store-screenshots/macos"
    pkill -x EzBizi 2>/dev/null || true
  fi
  rm -rf "$OUT"
  LOG=/tmp/store-screenshots.log
  EZBIZI_WINDOW=1440x900 flutter drive -d "${DEVICE:-macos}" \
    --driver=test_driver/screenshots.dart \
    --target="${SHOT_TARGET:-integration_test/store_screenshots_test.dart}" ${SHOT_EXTRA:-} \
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
DEVICE=""; SET=macos; SHOT_TARGET=""; SHOT_EXTRA=""
if [ "${1:-}" = "--ezchat" ]; then
  # EzChat, the chat sister app: its own harness and app flag.
  SHOT_TARGET=integration_test/ezchat_screenshots_test.dart; SHOT_EXTRA=--dart-define=EZBIZI_APP=ezchat; shift
fi
if [ "${1:-}" = "--device" ]; then
  DEVICE="${2:?--device needs a simulator udid}"; SET="${3:?--device needs a set name, e.g. iphone69}"
fi
DIST="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/Distribution/Apple/screenshots/$SET"
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
if ssh "$MAC" 'pgrep -f "xcodebuild|ezbizi-build" >/dev/null 2>&1'; then
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
  VERSION pubspec.yaml pubspec.lock analysis_options.yaml lib assets ios macos integration_test test_driver \
  | ssh "$MAC" "tar -xz -C ${MAC_PROJECT}"

echo "→ $SET — capturing on the Mac relay as v${APP_VERSION} · ${BUILD_ID}$([ "$PROBE" -eq 1 ] && echo ' (probe: placeholder password)')"
ssh "$MAC" "PROBE=$PROBE DEVICE=$DEVICE SET=$SET SHOT_TARGET=$SHOT_TARGET SHOT_EXTRA=$SHOT_EXTRA APP_VERSION=$APP_VERSION BUILD_ID=$BUILD_ID bash -s -- --remote" < "$0"

if [ -n "$DEVICE" ]; then
  scp -q "$MAC:ezbizi-build/store-screenshots/$SET/*.png" "$DIST/"
else
  scp -q "$MAC:Library/Containers/com.ezbizi.app/Data/store-screenshots/macos/*.png" "$DIST/"
fi
# Different screens never make byte-identical PNGs. Identical ones mean the
# capture saw one stale surface (a system prompt over the app gave ten blank
# frames and a passing test).
DUPES=$(md5sum "$DIST"/[0-9]*.png | awk '{print $1}' | sort | uniq -d | wc -l | tr -d ' ')
if [ "$DUPES" -gt 0 ]; then
  echo "✗ identical frames in $DIST - the capture did not see the app; look at them" >&2
  exit 6
fi
echo "✓ $(ls "$DIST"/*.png | wc -l | tr -d ' ') screenshot(s) → $DIST"
