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
DIST=dist

API_BASE="https://app.ezbizi.com"
APP_FACE="customer"
RUN_GATES=1
UPLOAD=0
ALLOW_DIRTY=0
TARGETS=()

while [ $# -gt 0 ]; do
  case "$1" in
    windows|linux|android|macos|ios) TARGETS+=("$1"); shift ;;
    --api-base) API_BASE="$2"; shift 2 ;;
    --face)     APP_FACE="$2"; shift 2 ;;
    --skip-gates) RUN_GATES=0; shift ;;
    --upload)     UPLOAD=1; shift ;;
    --allow-dirty) ALLOW_DIRTY=1; shift ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [windows|linux|android|macos|ios]... [--api-base URL] [--face customer|staff] [--skip-gates] [--upload] [--allow-dirty]" >&2
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
# ── refuse to stamp a build nobody can reproduce ─────────────────
#
# Same guard, same reason, as build-web.sh. The "+" below marks a dirty tree;
# it was a marker rather than a check, and production once shipped as
# `v0.0.0112 · 288a795+` — a bundle matching no commit.
#
# It matters MORE here than on the web. A web build is replaced by the next
# deploy; an .ipa goes to Apple and an .aab goes to Play, where a build that
# cannot be reproduced from a commit is one you cannot rebuild to diagnose a
# review rejection weeks later. Neither store lets you quietly replace it.
#
# --allow-dirty still stamps the + so the artefact says so of itself.
DIRTY=$(git status --porcelain --untracked-files=no)

if [ -n "$DIRTY" ]; then
  if [ "$ALLOW_DIRTY" -eq 1 ]; then
    COMMIT="${COMMIT}+"
    echo "⚠ building from a dirty tree (--allow-dirty). This artefact cannot be"
    echo "  reproduced from ${COMMIT%+}; it is stamped + to say so."
  else
    echo "✗ refusing to build: the working tree has uncommitted changes." >&2
    echo "  A store artefact that matches no commit cannot be rebuilt to" >&2
    echo "  diagnose a review rejection weeks from now." >&2
    echo >&2
    echo "$DIRTY" | sed 's/^/    /' >&2
    echo >&2
    echo "  Commit or stash these, or pass --allow-dirty if you mean it." >&2
    exit 1
  fi
fi
BUILT_AT=$(date -u +'%Y-%m-%d %H:%M')

# Apple wants a monotonic CFBundleVersion, exactly as Play wants a rising
# versionCode, and Flutter defaults both to pubspec's `1.0.0+1` unless told
# otherwise. Android already derives its number from client/VERSION inside
# build.gradle.kts; this is the same arithmetic for the platforms that cannot
# read that file themselves. 0.0.0103 -> 103.
BUILD_NUMBER=$(printf '%s' "$VERSION" | tr -d '.' | sed 's/^0*//')
BUILD_NUMBER=${BUILD_NUMBER:-1}

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
  # Quoted, because BUILT_AT holds a space ("2026-08-27 20:04"). The local builds
# pass their defines as an ARRAY, which keeps the value whole; this path is a
# flat string interpolated into an ssh command, so an unquoted space split the
# value and Flutter read the time as a second argument — "Target file 20:04 not
# found". Every Apple build hit this; none had been run since BUILT_AT was added.
MAC_DEFINES="${MAC_DEFINES} --dart-define=BUILT_AT='${BUILT_AT}'"
MAC_DEFINES="${MAC_DEFINES} --build-name=${VERSION} --build-number=${BUILD_NUMBER}"

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

  # ── iOS ────────────────────────────────────────────────────────
  #
  # Signed if this Mac can sign for EZBIZI LLC, a compile check if it cannot.
  # The difference matters and is never guessed at: an unsigned iOS build leaves
  # a .app that will not install on any phone, so shipping one by accident means
  # discovering at upload time that there was never an artefact.
  #
  # The identity is matched on TEAM, not on name. This Mac also holds a personal
  # Developer ID and an Apple Development certificate under two other teams, and
  # "is there an Apple Distribution identity" is true for all three — only the
  # 2CMZQR34RL one can sign com.ezbizi.app.
  if wants ios; then
    IOS_TEAM=2CMZQR34RL

    if ssh "$MAC_REMOTE" "security find-identity -v -p codesigning 2>/dev/null | grep -q 'Apple Distribution.*${IOS_TEAM}'"; then
      echo "→ building ios — signed for team ${IOS_TEAM}"
      ssh "$MAC_REMOTE" "
        export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
        set -e
        # The build keychain has to be open before codesign asks it for the key.
        # A script on the Mac rather than a line here: the command needs nested
        # quoting through ssh, which is how a password gets mangled.
        ~/ezbizi-signing/unlock-keychain.sh
        cd ${MAC_PROJECT}
        ${MAC_FLUTTER} pub get
        ${MAC_FLUTTER} build ipa --release ${MAC_DEFINES} --export-options-plist=ios/ExportOptions.plist
      "

      # Bring it home. An artefact that exists only on somebody else's Mac is one
      # power cut from not existing, and dist/ is where every other platform
      # leaves its release.
      mkdir -p "$DIST"
      IPA_REMOTE=$(ssh "$MAC_REMOTE" "ls ${MAC_PROJECT}/build/ios/ipa/*.ipa 2>/dev/null | head -1")
      if [ -z "$IPA_REMOTE" ]; then
        echo "✗ flutter build ipa reported success but left no .ipa on the Mac" >&2
        exit 1
      fi
      scp -q "${MAC_REMOTE}:${IPA_REMOTE}" "${DIST}/EzBizi-${VERSION}-${COMMIT}.ipa"
      echo "  → ${DIST}/EzBizi-${VERSION}-${COMMIT}.ipa"

      # TestFlight, only when asked.
      #
      # Opt-in because a build that lands in TestFlight notifies testers and
      # cannot be unsent - it should not happen because somebody rebuilt to
      # check a compile error. The script on the Mac validates before it
      # uploads, so the cheap rejections are caught before ten minutes of wire.
      if [ "$UPLOAD" -eq 1 ]; then
        echo "-> ios - validate and upload to TestFlight"
        ssh "$MAC_REMOTE" "~/ezbizi-signing/upload-testflight.sh ${IPA_REMOTE}"
        echo "  -> uploaded. App Store Connect processes it for a few minutes"
        echo "     before it appears in TestFlight."
      fi
    else
      echo "→ building ios — UNSIGNED (no Apple Distribution identity for team ${IOS_TEAM} on the Mac)"
      ssh "$MAC_REMOTE" "
        export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
        set -e
        cd ${MAC_PROJECT}
        ${MAC_FLUTTER} pub get
        ${MAC_FLUTTER} build ios --release --no-codesign ${MAC_DEFINES}
      "
      echo "  This is a compile check. It cannot be installed on a phone or"
      echo "  uploaded to App Store Connect. Run scripts/release.sh --only ios"
      echo "  to be told exactly what the Mac still needs."
    fi
  fi

  # Not "UNSIGNED" any more — iOS decides for itself above and says which it did.
  # macOS is still genuinely unsigned: it needs a Developer ID certificate, which
  # is a different certificate from the Apple Distribution one iOS uses.
  wants macos && echo "→ the macOS .app is on the Mac under ${MAC_PROJECT}/build — UNSIGNED"
fi

echo "✓ built v${VERSION} · ${COMMIT}"
