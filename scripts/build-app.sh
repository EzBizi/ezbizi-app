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
# The main checkout's Distribution/, never the current directory — see the long
# note in release.sh. A build run from a release worktree used to leave its .ipa
# inside that worktree, which is both invisible and eventually deleted.
DIST="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/Distribution"
DIST_APPLE="$DIST/Apple"

API_BASE="https://app.ezbizi.com"
APP_FACE="customer"
RUN_GATES=1
UPLOAD=0
ALLOW_DIRTY=0
ANDROID_APK=0
TARGETS=()

while [ $# -gt 0 ]; do
  case "$1" in
    windows|linux|android|macos|ios) TARGETS+=("$1"); shift ;;
    --api-base) API_BASE="$2"; shift 2 ;;
    --face)     APP_FACE="$2"; shift 2 ;;
    --skip-gates) RUN_GATES=0; shift ;;
    --upload)     UPLOAD=1; shift ;;
    --allow-dirty) ALLOW_DIRTY=1; shift ;;
    --apk)         ANDROID_APK=1; shift ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [windows|linux|android|macos|ios]... [--api-base URL] [--face customer|staff] [--skip-gates] [--upload] [--allow-dirty] [--apk]" >&2
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
#
# docs/ is excluded, and that is not a loosening — it closes a hole this guard
# was opening. deploy.sh regenerates docs/EZBIZI-STATUS.md mid-release
# (status-numbers.sh, deploy.sh:268) and does not commit it. So a full
# release.sh run deployed web, dirtied its own tree doing so, and then this
# guard refused windows, android AND ios — every time, from a tree that was
# verifiably clean when the release started. That is what failed on 1 Sep
# (v0.0.0128) and, with the same three targets, on the v0.0.0127 nightly.
#
# Nothing under docs/ can reach an .exe, an .aab or an .ipa, so excluding it
# costs the reproducibility guarantee above nothing: the artefact is still
# pinned to a commit that fully determines it. A change under client/, app/ or
# any other shipping path still stops the build.
DIRTY=$(git status --porcelain --untracked-files=no -- . ':(exclude)docs')

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
# otherwise. 0.0.0103 -> 103.
#
# EVERY platform now, from the ONE read of client/VERSION above. Android used to
# do this arithmetic again for itself inside build.gradle.kts, off a second read
# of the file, and that is the bug: a build shipped declaring versionCode 9999
# while its filename said 0.0.0184, because the deploy desk held VERSION at
# 0.0.9999 for fifteen seconds as a sabotage control and the two reads landed
# either side of it. A wrong filename is cosmetic; a wrong versionCode reaching
# Play permanently burns every number beneath it.
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
    # thing you install on a phone to test comes from this artefact too.
    #
    # --apk is that day arriving early. The sentence above assumes the internal
    # testing track exists, and while Play holds a new listing for its first
    # review it does NOT: the .aab is the only artefact, an .aab cannot be
    # installed on a phone, and there is no track to distribute it through. So
    # the one thing left to prove — that an incoming call rings a real handset —
    # has no build anybody can install. --apk produces one, signed with the same
    # upload key and carrying the same --dart-defines, which is the point of
    # putting it HERE rather than telling somebody to run `flutter build apk`:
    # bare, it defaults API_BASE to nothing useful and the app installs fine and
    # then cannot log in.
    #
    # It is not part of a release. Play still gets the .aab.
    android)
      if [ "$ANDROID_APK" -eq 1 ]; then
        (cd client && flutter build apk --release           --build-name="$VERSION" --build-number="$BUILD_NUMBER" "${DEFINES[@]}")
      else
        (cd client && flutter build appbundle --release           --build-name="$VERSION" --build-number="$BUILD_NUMBER" "${DEFINES[@]}")
      fi ;;
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

# ── the version must not have moved under the build ─────────────
#
# VERSION is read TWICE per build and by two different things: this script
# reads it for the artefact's filename and its stamp, and
# android/app/build.gradle.kts reads it again for versionName/versionCode. On a
# tree with twenty sessions in it those two reads are not the same instant.
#
# It happened: 0.0.0184-cbb8aff1+-sideload.apk went out declaring versionCode
# 9999, versionName 0.0.9999, because a peer had VERSION at 0.0.9999 (what you
# set to test the desktop update offer) while Gradle read it. The filename said
# 0184 and the artefact said 9999, and nothing complained.
#
# The filename is cosmetic. versionCode is not: Play requires it to rise
# forever, so ONE upload at 9999 permanently burns every number below it and
# the listing can never ship 185 again. There is no undo for that.
#
# Cheaper to notice here than to explain to Play, so: re-read the file and
# refuse to hand back an artefact whose stamp is already a lie.
VERSION_AFTER=$(tr -d '[:space:]' < client/VERSION)
if [ "$VERSION_AFTER" != "$VERSION" ]; then
  echo "x client/VERSION moved during this build: ${VERSION} -> ${VERSION_AFTER}" >&2
  echo "  The artefacts are stamped from two different reads and at least one is" >&2
  echo "  wrong. Nothing is safe to upload. Find out who is holding VERSION" >&2
  echo "  (0.0.9999 is the desktop-update test value) and build again when it is" >&2
  echo "  settled." >&2
  exit 1
fi

# ── the sideload APK, if one was asked for ──────────────────────
#
# release.sh collects the .aab itself; this one is collected here because a
# release never produces it. Same name shape as every other artefact so the
# folder stays sorted by version, and -sideload so nobody ever mistakes it for
# something to upload to Play.
if wants android && [ "$ANDROID_APK" -eq 1 ]; then
  APK="client/build/app/outputs/flutter-apk/app-release.apk"
  if [ ! -f "$APK" ]; then
    echo "x expected $APK and it is not there" >&2
    exit 1
  fi
  mkdir -p "$DIST/Android"
  cp "$APK" "$DIST/Android/EzBizi-${VERSION}-${COMMIT}-sideload.apk"
  echo "  -> $DIST/Android/EzBizi-${VERSION}-${COMMIT}-sideload.apk"
fi

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
  # assets/ is synced for the same reason, added 2026-09-17 after it cost
  # 0.0.0234 both Apple halves. pubspec declares assets/social, assets/softphone
  # and assets/logo; the tar shipped lib, ios and macos and never them, so the
  # Mac built from whatever copy an older manual sync had left behind. It
  # worked for weeks and failed the moment that copy was gone, with
  # "unable to find directory entry in pubspec.yaml" - which reads like a
  # pubspec error and is a missing directory.
  #
  # The general form, and the reason this comment is longer than the fix: a
  # build that depends on a file nobody sends is GREEN until something tidies
  # up. The list below is the contract; anything pubspec names has to be in it.
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

  # THE UPLOAD SCRIPT IS NOT PART OF THE SYNC, so ship it explicitly.
  #
  # It lives beside the API key in ~/ezbizi-signing rather than in the
  # project, because it needs asc-key.env. The tar below sends only
  # client/{lib,ios,macos,...}, so scripts/ has NEVER reached this machine
  # and the copy the Mac executes was maintained by hand.
  #
  # Found 11 Sep 2026: the Mac was running a 10 Sep copy. Two guard fixes
  # committed that morning -- the one that made a SILENT altool run fail
  # instead of pass, and the line saying a delivery is not an acceptance --
  # were in the release pin and did not run. A guard improved in a file
  # nothing executes is worse than no guard, because it is believed.
  #
  # So the executed copy is overwritten from the committed copy on every
  # Apple build. It cannot drift again without this line being deleted.
  ssh "$MAC_REMOTE" "cat > ~/ezbizi-signing/upload-testflight.sh.new" \
    < scripts/mac/upload-testflight.sh
  ssh "$MAC_REMOTE" "chmod +x ~/ezbizi-signing/upload-testflight.sh.new \
    && mv ~/ezbizi-signing/upload-testflight.sh.new ~/ezbizi-signing/upload-testflight.sh"
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
    VERSION pubspec.yaml pubspec.lock analysis_options.yaml lib assets ios macos \
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
  if wants macos; then
    MAC_TEAM=2CMZQR34RL
    mkdir -p "$DIST_APPLE"

    # Same split as iOS below: a store-signed artefact when this Mac holds the
    # identities, a compile-check .app when it does not, and never one
    # mistaken for the other. macOS needs TWO identities where iOS needs one -
    # Apple Distribution signs the .app, 3rd Party Mac Developer Installer
    # signs the .pkg App Store Connect actually accepts - plus the Mac App
    # Store profile. All three arrived 4 Sep 2026.
    if ssh "$MAC_REMOTE" "security find-identity -v 2>/dev/null | grep -q 'Apple Distribution.*${MAC_TEAM}'" \
       && ssh "$MAC_REMOTE" "security find-identity -v 2>/dev/null | grep -qi 'Mac Developer Installer.*${MAC_TEAM}'"; then
      echo "→ building macos — signed for team ${MAC_TEAM}"
      MAC_SIGNED=1
      ssh "$MAC_REMOTE" "
        export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
        set -e
        ~/ezbizi-signing/unlock-keychain.sh
        cd ${MAC_PROJECT}
        ${MAC_FLUTTER} pub get
        # The .app, so Flutter's own build settings are what get archived.
        ${MAC_FLUTTER} build macos --release ${MAC_DEFINES}
        # Archive and export through Xcode, the way flutter build ipa does for
        # iOS under the hood. The export re-signs with the plist's manual
        # identities and, because installerSigningCertificate is set, emits
        # the signed .pkg rather than a bare .app.
        rm -rf build/macos.xcarchive build/macos-export
        xcodebuild -workspace macos/Runner.xcworkspace -scheme Runner \
          -configuration Release -destination 'generic/platform=macOS' \
          -archivePath build/macos.xcarchive archive -quiet
        xcodebuild -exportArchive -archivePath build/macos.xcarchive \
          -exportOptionsPlist macos/ExportOptions.plist \
          -exportPath build/macos-export -quiet
      "
      PKG_REMOTE=$(ssh "$MAC_REMOTE" "ls ${MAC_PROJECT}/build/macos-export/*.pkg 2>/dev/null | head -1")
      if [ -z "$PKG_REMOTE" ]; then
        echo "✗ xcodebuild -exportArchive reported success but left no .pkg on the Mac" >&2
        exit 1
      fi

    # ── exactly one profile per name, because the bad case is SILENT ──
    #
    # Apple session, 2026-09-10, found this by grepping scripts/ for every
    # profile-shaped string and getting zero hits: nothing anywhere checked
    # that a provisioning profile is installed, correctly named, or unique.
    # The knowledge lived in a comment in ExportOptions.plist -- "Name it
    # exactly as the portal does, or the export fails" -- which is a comment
    # naming a hazard and leaving it.
    #
    # Most of that hazard is loud, and loud is fine: a missing or misnamed
    # profile fails at export with a clear message, and a binary claiming an
    # entitlement its profile lacks is a hard signing failure. Neither can
    # ship a wrong artefact.
    #
    # DUPLICATE NAMES are the silent one. xcodebuild resolves by Name, so two
    # profiles both called "EzBizi Mac App Store" leave the choice to
    # directory order. If the stale one wins and the binary claims nothing
    # extra -- which is macOS today, no aps-environment in its entitlements --
    # it signs cleanly, uploads cleanly, and ships a package whose profile
    # lacks push. Nothing in the log would differ. The relay was in exactly
    # that state for the minutes between the new profiles being installed and
    # the old ones deleted.
    #
    # An unreachable probe WARNS rather than refuses: "no profile named X"
    # and "I could not ask" are two different facts, and only the first is a
    # reason to stop a release. The identity checks below still gate the build.
    MAC_PROFILE_NAMES=$(ssh -o BatchMode=yes "$MAC_REMOTE" \
      'for f in ~/Library/MobileDevice/Provisioning\ Profiles/*; do
         [ -f "$f" ] || continue
         security cms -D -i "$f" 2>/dev/null | plutil -extract Name raw - 2>/dev/null
       done' 2>/dev/null) || MAC_PROFILE_NAMES=""

    if [ -z "$MAC_PROFILE_NAMES" ]; then
      echo "! could not read the provisioning profiles on the relay - not blocking," >&2
      echo "  but nothing has confirmed which profile xcodebuild will pick." >&2
    else
      for want in "EzBizi App Store" "EzBizi Mac App Store"; do
        n=$(printf '%s\n' "$MAC_PROFILE_NAMES" | grep -cxF "$want")
        if [ "$n" != "1" ]; then
          echo "x expected exactly one profile named '${want}' on the relay, found ${n}." >&2
          if [ "$n" = "0" ]; then
            echo "  Install it from the portal, naming it EXACTLY as the portal does." >&2
          else
            echo "  Two profiles of one name: xcodebuild picks by directory order, so a" >&2
            echo "  stale one can sign and upload a package silently missing an" >&2
            echo "  entitlement. Delete the old one from" >&2
            echo "  ~/Library/MobileDevice/Provisioning Profiles/ before building." >&2
          fi
          exit 1
        fi
      done
      echo "  profiles ok - one each of 'EzBizi App Store' and 'EzBizi Mac App Store'"
    fi
      scp -q "${MAC_REMOTE}:${PKG_REMOTE}" "${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}-macos.pkg"
      echo "  → ${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}-macos.pkg"

      # Every released build is uploaded (Jose's ruling for iOS, 1 Sep; the
      # same reasoning). The Mac script validates first and treats altool's
      # OUTPUT as the verdict, because altool exits 0 when it fails.
      if [ "$UPLOAD" -eq 1 ]; then
        echo "-> macos - validate and upload to App Store Connect"
        ssh "$MAC_REMOTE" "~/ezbizi-signing/upload-testflight.sh ${PKG_REMOTE} macos"
        echo "  -> uploaded. App Store Connect processes it for a few minutes."
      fi
    else
      echo "→ building macos — UNSIGNED compile check (relay lacks a store identity for team ${MAC_TEAM})"
      ssh "$MAC_REMOTE" "
        export PATH=/opt/homebrew/bin:/usr/local/bin:\$PATH
        set -e
        cd ${MAC_PROJECT}
        ${MAC_FLUTTER} pub get
        ${MAC_FLUTTER} build macos --release ${MAC_DEFINES}
      "
      # Bring the ad-hoc .app home zipped, so a compile check still leaves
      # something runnable. ditto keeps the bundle intact where zip flattens
      # it and Gatekeeper then calls it damaged.
      APP_REMOTE=$(ssh "$MAC_REMOTE" "ls -d ${MAC_PROJECT}/build/macos/Build/Products/Release/*.app 2>/dev/null | head -1")
      if [ -z "$APP_REMOTE" ]; then
        echo "✗ flutter build macos reported success but left no .app on the Mac" >&2
        exit 1
      fi
      ssh "$MAC_REMOTE" "cd '$(dirname "$APP_REMOTE")' && ditto -c -k --keepParent '$(basename "$APP_REMOTE")' /tmp/ezbizi-macos.zip"
      scp -q "${MAC_REMOTE}:/tmp/ezbizi-macos.zip" "${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}-macos.zip"
      echo "  → ${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}-macos.zip  (ad-hoc, not for the store)"
    fi
  fi

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
        # iOS only: build with the App Store Xcode (27.1+ SDK fills the iPhone Duo's
        # inner screen; older SDKs letterbox it). macOS stays on the xcode-select pin
        # (26.0.1, flutter#188461 lipo bug). Desk, 2026-11-23.
        export DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer
        cd ${MAC_PROJECT}
        ${MAC_FLUTTER} pub get
        ${MAC_FLUTTER} build ipa --release ${MAC_DEFINES} --export-options-plist=ios/ExportOptions.plist
      "

      # Bring it home. An artefact that exists only on somebody else's Mac is one
      # power cut from not existing, and dist/ is where every other platform
      # leaves its release.
      mkdir -p "$DIST_APPLE"
      IPA_REMOTE=$(ssh "$MAC_REMOTE" "ls ${MAC_PROJECT}/build/ios/ipa/*.ipa 2>/dev/null | head -1")
      if [ -z "$IPA_REMOTE" ]; then
        echo "✗ flutter build ipa reported success but left no .ipa on the Mac" >&2
        exit 1
      fi
      scp -q "${MAC_REMOTE}:${IPA_REMOTE}" "${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}.ipa"
      echo "  → ${DIST_APPLE}/EzBizi-${VERSION}-${COMMIT}.ipa"

      # TestFlight, only when asked - but release.sh always asks.
      #
      # Opt-in HERE because a build that lands in TestFlight notifies testers
      # and cannot be unsent - it should not happen because somebody rebuilt to
      # check a compile error. The script on the Mac validates before it
      # uploads, so the cheap rejections are caught before ten minutes of wire.
      #
      # A release is not that case: every released iOS build goes to TestFlight
      # (Jose's ruling, 1 Sep), so release.sh passes --upload unconditionally.
      # This flag now separates a developer's scratch build from a release,
      # not a released build from an uploaded one.
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

  # Not "UNSIGNED" any more — each Apple target decides for itself above and
  # says which it did. The signed macOS path leaves a store-ready .pkg in
  # Distribution/Apple; only the fallback compile check is worth flagging.
  if wants macos && [ "${MAC_SIGNED:-0}" -eq 0 ]; then
    echo "→ the macOS .app is on the Mac under ${MAC_PROJECT}/build — UNSIGNED compile check, nothing to upload"
  fi
fi

# ── put the tree back the way the guard above requires ──────────
#
# `flutter pub get` regenerates the plugin registrants for linux, macos AND
# windows on every run, whatever platform you asked for, and all seven files
# are tracked. So a build leaves the tree dirty, and the cleanliness guard at
# the top of this script then refuses THE NEXT ONE.
#
# That is why `./scripts/release.sh` with no arguments — the documented
# default, all four platforms — could only ever build its first target. Every
# target after it died on dirt the previous target had created:
#
#     x refusing to build: the working tree has uncommitted changes.
#          M client/macos/Flutter/GeneratedPluginRegistrant.swift
#
# Traced on 2026-08-28 back from the Windows installer sitting thirteen
# versions stale on the download page while every desktop client polled its
# manifest and was told, truthfully, that there was nothing newer. Nobody had
# been ignoring the release script; it had been refusing itself.
#
# Restored AFTER the build, never before: a native build compiles these files,
# so handing it a committed copy that predates a plugin change would build the
# wrong plugin list. deploy.sh restores before its build instead and is right
# to — the web target does not read them at all.
#
# Only the registrants, by pathspec, so a real edit elsewhere survives —
# including under --allow-dirty, where the tree was dirty on purpose.
# Checked out unconditionally rather than after asking `git diff` what changed.
# The first version of this asked, got told NOTHING had changed, and left the
# tree dirty — because the dirt is LINE ENDINGS. pub get writes LF, the index
# holds CRLF, and `git status` says M while `git diff` correctly says there is
# no content difference. The guard reads status; the detection read diff; they
# disagreed and the fix silently did nothing. scripts/tree-blockers.sh exists
# because of exactly that disagreement, and this was written anyway.
#
# A checkout of an unchanged path is a no-op, so there is nothing to detect.
git checkout -- "client/*/flutter/generated_plugin_registrant.*" "client/*/flutter/generated_plugins.cmake" "client/*/Flutter/GeneratedPluginRegistrant.*" 2>/dev/null || true

echo "✓ built v${VERSION} · ${COMMIT}"
