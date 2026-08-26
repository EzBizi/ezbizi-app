#!/usr/bin/env bash
#
# Build the EzBizi client for the platforms the web build does not cover.
#
#   ./scripts/build-app.sh windows
#   ./scripts/build-app.sh android
#   ./scripts/build-app.sh macos ios                 # both, one relay session
#   ./scripts/build-app.sh windows --face staff --api-base https://dev.ezbizi.com
#
# Web is not a target here. It has its own script because it is deployed rather
# than installed, and because it is the only build that needs the service-worker
# kill switch. See scripts/build-web.sh, which deploy.sh calls.
#
# ── Why an installed app needs flags the web build does not ──────
#
# The web build passes `--dart-define=API_BASE=` on purpose: empty means
# same-origin, so the bundle talks to whatever host served it and can never
# point at the wrong API. There is no host off the web. An installed app that
# inherits that default builds cleanly, launches cleanly, and fails every single
# request — the worst failure shape there is, because nothing is wrong until a
# customer tries to sign in. So API_BASE is REQUIRED here and defaulted
# explicitly rather than left empty.
#
# APP_FACE is the same argument. Deployment.face() reads the hostname to decide
# customer app or control plane, and Uri.base off the web is a file path with no
# host — so an unflagged desktop build is silently always the customer app. That
# is right for almost every build and wrong for the one that matters, so it is
# stated rather than inherited.
set -euo pipefail

cd "$(dirname "$0")/.."

# ── the relay ────────────────────────────────────────────────────
#
# An ~/.ssh/config alias, exactly like deploy.sh's `app`. The Mac's address is
# somebody's machine on somebody's LAN and does not belong in this repo: it has
# already drifted once (10.1.10.74 to .103), which is why the alias resolves a
# Bonjour name rather than an IP. Anyone who cannot reach it gets told which
# alias is missing, which is a fixable message.
MAC_REMOTE=mac
MAC_PROJECT='~/ezbizi-build'
MAC_FLUTTER=/opt/homebrew/bin/flutter

API_BASE="https://app.ezbizi.com"
APP_FACE="customer"
RUN_GATES=1
TARGETS=()

while [ $# -gt 0 ]; do
  case "$1" in
    windows|linux|android|macos|ios) TARGETS+=("$1"); shift ;;
    --api-base) API_BASE="$2"; shift 2 ;;
    --face)     APP_FACE="$2"; shift 2 ;;
    --skip-gates) RUN_GATES=0; shift ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [windows|linux|android|macos|ios]... [--api-base URL] [--face customer|staff] [--skip-gates]" >&2
       exit 2 ;;
  esac
done

if [ ${#TARGETS[@]} -eq 0 ]; then
  echo "nothing to build. Pick at least one of: windows linux android macos ios" >&2
  exit 2
fi

case "$APP_FACE" in
  customer|staff) ;;
  *) echo "--face must be customer or staff, not '$APP_FACE'" >&2; exit 2 ;;
esac

# The same stamp the web build uses, read from the same file, so an installed
# app and the website report one identity. The `+` means the tree was dirty when
# this was built, which is the difference between "v0.0.0030" and "something
# that was nearly v0.0.0030".
VERSION=$(tr -d '[:space:]' < client/VERSION)
COMMIT=$(git rev-parse --short HEAD)
git diff --quiet && git diff --cached --quiet || COMMIT="${COMMIT}+"
BUILT_AT=$(date -u +'%Y-%m-%d %H:%M')

DEFINES=(
  --dart-define=API_BASE="${API_BASE}"
  --dart-define=APP_FACE="${APP_FACE}"
  --dart-define=APP_VERSION="${VERSION}"
  --dart-define=BUILD_ID="${COMMIT}"
  --dart-define=BUILT_AT="${BUILT_AT}"
)

echo "→ v${VERSION} · ${COMMIT} · ${APP_FACE} · ${API_BASE}"
echo "→ targets: ${TARGETS[*]}"

# ── gates ────────────────────────────────────────────────────────
#
# deploy.sh gates on the same two before anything ships, and the reasoning
# carries: a binary somebody installs is harder to take back than a web build,
# because taking it back means asking them to install something else. Skippable
# for iteration, deliberately not skippable by accident.
if [ "$RUN_GATES" -eq 1 ]; then
  echo "→ analyze + tests"
  (cd client && flutter analyze && flutter test)
else
  echo "→ gates SKIPPED (--skip-gates) — do not ship this build"
fi

wants() { printf '%s\n' "${TARGETS[@]}" | grep -qx "$1"; }

# ── the targets this machine can build itself ────────────────────
build_locally() {
  case "$1" in
    # appbundle, not apk. Play has not accepted an APK for a new app since 2021,
    # and the AAB is what the internal testing track distributes from — so the
    # thing you install on a phone to test comes from this artefact too. An APK
    # is only worth building to sideload without Play, which is not the path
    # here; `flutter build apk` is still there by hand if that day comes.
    android) (cd client && flutter build appbundle --release "${DEFINES[@]}") ;;
    *)       (cd client && flutter build "$1" --release "${DEFINES[@]}") ;;
  esac
}

for target in windows linux android; do
  wants "$target" || continue
  echo "→ building ${target}"

  # Once, then once more against a cleared cache.
  #
  # The desktop targets are CMake projects, and CMake caches the project and
  # target NAMES in build/<target>. Rename either — as happened moving off the
  # `flutter create` default of ezbizi_client — and every subsequent build dies
  # on "No target ezbizi_client" from a cache describing an app that no longer
  # exists. The source is correct and the error names something absent from it,
  # which is a genuinely confusing half-hour if you have not seen it before.
  #
  # Retrying blind would be wrong, so the cache is cleared BETWEEN the attempts:
  # a real compile error fails identically twice and is reported, while a stale
  # cache is fixed by the only thing that fixes it. The clean is not the default
  # because it costs the whole incremental build, and most builds are fine.
  if ! build_locally "$target"; then
    echo "  ${target} failed — clearing its build cache and trying once more"
    rm -rf "client/build/${target}"
    build_locally "$target"
  fi
done

# ── the two that need the Mac ────────────────────────────────────
#
# One session for both, because the expensive parts — the source sync and
# `flutter pub get` — are shared, and running them twice doubles the slowest
# thing in this script for no benefit.
if wants macos || wants ios; then
  echo "→ reaching the Mac relay"

  if ! ssh -o ConnectTimeout=10 -o BatchMode=yes "$MAC_REMOTE" 'true' 2>/dev/null; then
    echo "✗ cannot reach the Mac at ssh alias '${MAC_REMOTE}'." >&2
    echo "  Is the Mac mini awake and on the network? Is there a '${MAC_REMOTE}' entry" >&2
    echo "  in ~/.ssh/config with its key? Nothing Apple-shaped can be built without it." >&2
    exit 1
  fi

  # ── why a plain pipe here, when A1 Tools stages a temp tarball ──
  #
  # A1 Tools builds from PowerShell, and Windows PowerShell 5.1 re-encodes bytes
  # piped between two native executables — so `tar -cf - | ssh 'tar -xf -'` there
  # hangs with "Damaged tar archive" and it has to stage a file and scp it. This
  # script is bash, the pipe is binary-clean, and deploy.sh already ships the
  # whole web build over one. Copying the workaround would be carrying a scar
  # from an injury this script cannot suffer.
  #
  # ios/ and macos/ are synced, not just lib/. Skipping them is how a native
  # change — an Info.plist key, an entitlement, an AppDelegate fix — silently
  # never reaches the builder and the artefact is signed against stale code. It
  # cost A1 Tools two separate incidents; there is no reason to learn it twice.
  #
  # The excludes are all Flutter-generated files holding THIS machine's Windows
  # paths. Ship Flutter/ephemeral and the Mac's Podfile tries to require
  # "H:\flutter\...\podhelper" and pod install dies before a build starts. The
  # Mac regenerates every one of them during its own pub get.
  echo "→ syncing source to the Mac"
  ssh "$MAC_REMOTE" "rm -rf ${MAC_PROJECT} && mkdir -p ${MAC_PROJECT}"

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
    VERSION pubspec.yaml pubspec.lock analysis_options.yaml lib ios macos \
    | ssh "$MAC_REMOTE" "tar -xz -C ${MAC_PROJECT}"

  MAC_DEFINES="--dart-define=API_BASE=${API_BASE} --dart-define=APP_FACE=${APP_FACE}"
  MAC_DEFINES="${MAC_DEFINES} --dart-define=APP_VERSION=${VERSION}"
  MAC_DEFINES="${MAC_DEFINES} --dart-define=BUILD_ID=${COMMIT}"
  MAC_DEFINES="${MAC_DEFINES} --dart-define=BUILT_AT=${BUILT_AT}"

  # Built unsigned, and said out loud rather than left to be discovered.
  #
  # Signing needs an Apple Developer team, a provisioning profile and an
  # unlocked keychain, and EzBizi has none of the three yet. An unsigned macOS
  # .app runs locally after the Gatekeeper prompt, and an unsigned iOS build
  # produces a .app that cannot be installed on a phone at all — it is a
  # compile check, not a deliverable. Both are worth having now; neither is
  # worth mistaking for a release.
  wants macos && ssh "$MAC_REMOTE" "
    export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
    set -e
    cd ${MAC_PROJECT}
    ${MAC_FLUTTER} pub get
    ${MAC_FLUTTER} build macos --release ${MAC_DEFINES}
  "

  wants ios && ssh "$MAC_REMOTE" "
    export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
    set -e
    cd ${MAC_PROJECT}
    ${MAC_FLUTTER} pub get
    ${MAC_FLUTTER} build ios --release --no-codesign ${MAC_DEFINES}
  "

  echo "→ Apple artefacts are on the Mac under ${MAC_PROJECT}/build — UNSIGNED"
fi

echo "✓ built v${VERSION} · ${COMMIT}"
