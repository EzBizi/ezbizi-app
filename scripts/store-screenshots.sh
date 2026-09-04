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
set -euo pipefail

# ── remote half ─────────────────────────────────────────────────────
# Runs on the Mac via `bash -s`, so this file needs no nested quoting.
if [ "${1:-}" = "--remote" ]; then
  export PATH=/opt/homebrew/bin:/usr/local/bin:$PATH
  cd ~/ezbizi-build
  if [ "${PROBE:-0}" = "1" ]; then
    SHOT_EMAIL=apple@ezbizi.com; SHOT_PASSWORD=placeholder
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
    --dart-define=SHOT_EMAIL="$SHOT_EMAIL" \
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
PROBE=0; [ "${1:-}" = "--probe" ] && PROBE=1
DIST="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/Distribution/Apple/screenshots/macos"
mkdir -p "$DIST"

# The release sync in build-app.sh carries lib/, ios/ and macos/ — not the two
# test halves — so send what this run needs on top of whatever it last synced.
scp -q -r client/integration_test client/test_driver "$MAC:ezbizi-build/"
scp -q client/macos/Runner/MainFlutterWindow.swift client/macos/Runner/DebugProfile.entitlements "$MAC:ezbizi-build/macos/Runner/"

echo "→ macos — capturing on the Mac relay$([ "$PROBE" -eq 1 ] && echo ' (probe: placeholder password)')"
ssh "$MAC" "PROBE=$PROBE bash -s -- --remote" < "$0"

scp -q "$MAC:Library/Containers/com.ezbizi.app/Data/store-screenshots/macos/*.png" "$DIST/"
echo "✓ $(ls "$DIST"/*.png | wc -l | tr -d ' ') screenshot(s) → $DIST"
