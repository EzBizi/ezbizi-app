#!/usr/bin/env bash
#
# One release, every platform.
#
#   ./scripts/release.sh                    all four: web, windows, android, ios
#   ./scripts/release.sh --only windows     just that one
#   ./scripts/release.sh --only web,android
#   ./scripts/release.sh --dry-run          say what it would do, build nothing
#   ./scripts/release.sh --no-bump          release client/VERSION as it stands
#   ./scripts/release.sh --gates            run the test suites first
#
# The gates are OFF by default (Jose, 1 Sep). See the note at the top of
# deploy.sh for what decided it. Nothing verifies a release unless --gates.
#
# ── KILLING A RUN DOES NOT STOP IT ─────────────────────────────
#
# Stopping the shell that launched this script kills the PARENT. The
# children keep going, and they are the half that publishes.
#
# 11 Sep 2026: a cut was stopped mid-gate. windows.json and the box marker
# were read within the minute and both still showed the PREVIOUS release,
# so the version number was treated as unused and re-pinned onto a new
# commit. The orphans then deployed the web app, published windows.json and
# the installer, and uploaded the iOS build to App Store Connect. That
# version shipped from an abandoned pin, carrying a release note amended for
# a cut that was already dead, and it spent the Apple build number so the
# replacement would have been refused as a duplicate.
#
# The readings were correct. They were evidence about the second they were
# taken and not a property of the release, because the thing writing them
# had not stopped. So before reusing a version number, the question is not
# what is live -- it is whether the publisher has actually stopped:
#
#   taskkill //F //IM flutter_tester.exe    # and dart.exe
#   ssh app "pgrep -fa 'vendor/bin/phpunit'"          # the remote gate
#   ssh app "flock -n /opt/ezbizi-test/.deploy-gate.lock -c true"
#         ^ a dead run holds this for an hour and the next gate waits on it
#
# Then re-read the marker. And the cheaper lesson: killing a cut is not free.
# Three were killed that afternoon over release-note wording. Pin later, let
# cuts finish, and amend the entry in place afterwards -- a person who read
# the wrong sentence never sees the corrected one either way.
#
# The version is bumped and committed automatically on every run. Two releases
# shipped twice under one number before this existed; the updater compares
# versions, so the second was never offered.
#
#   ./scripts/release.sh --only ios        signed .ipa via the Mac relay,
#                                          uploaded to TestFlight
#
# iOS IS a default target as of 28 Aug (Jose's ruling: every release builds all
# four). It was excluded before because its signing identity lives on another
# machine and an asleep Mac would have failed the whole release. That is no
# longer true: a failing target is recorded and skipped, so a sleeping relay now
# costs the .ipa and nothing else.
#
# This comment said the opposite until the defaults changed under it. Worth
# noticing: it was accurate when written, and became misleading without anyone
# editing it.
#
# ── what this is, and what it is not ─────────────────────────────
#
# An orchestrator. Every actual build already had a script:
#
#   web      scripts/deploy.sh    tests, builds, pushes code, swaps the UI
#   windows  scripts/build-app.sh flutter build windows
#   android  scripts/build-app.sh flutter build appbundle
#
# The only genuinely new work is wrapping the Windows folder into an installer
# and putting the artefacts somewhere findable. Everything else is a call.
#
# ── ordering ─────────────────────────────────────────────────────
#
# Web goes FIRST and the rest only run if it succeeded. deploy.sh ships the API,
# and an installed client that is newer than the API is a client making calls to
# routes that do not exist yet — on a desktop or a phone, where "just refresh"
# is not the fix it is on the web. Server first, then the things that talk to it.
set -euo pipefail

cd "$(dirname "$0")/.."

# Artefacts go to the MAIN checkout, always — never to whatever directory this
# script happens to be running in.
#
# Releases are run from a throwaway worktree (release.sh refuses a dirty tree
# and the shared tree is almost never clean), so a relative "dist" put every
# artefact inside that worktree. On 1 Sep v0.0.0128 built all three installers
# into H:\Development\wt\release-df7cca0\dist while Jose was looking at
# H:\Development\EzBizi\dist, which still held 0.0.0126. The build had worked;
# the output was just somewhere nobody would think to look, and a worktree is
# deleted eventually.
#
# --git-common-dir resolves to the main .git from inside a linked worktree, so
# its parent is the main checkout no matter where this runs.
DIST="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/Distribution"
DIST_WINDOWS="$DIST/Windows"
DIST_ANDROID="$DIST/Android"
DIST_APPLE="$DIST/Apple"

# Targets that failed. A failing target no longer aborts the release: it is
# recorded, its own packaging steps are skipped, the remaining targets still
# build, and every failure is reported together at the end with a non-zero
# exit. One asleep machine or one broken toolchain should cost that platform,
# not the other three.
FAILED=()
TARGETS="web,windows,android,ios,macos"
DRY_RUN=0
SKIP_GATES=--skip-gates   # gates are OFF by default; --gates turns them on
BUMP=1

# The marketing site's box, which is NOT the app box — see AGENTS/DEPLOY. The
# installer is served from www.ezbizi.com because that is where a person who
# has not installed anything yet goes looking, and because the update manifest
# has to be reachable by a client the app server may already be refusing.
WEB_REMOTE="app"
WEB_DOWNLOADS="/var/www/www/download"

# Kept whole, so the re-exec below can pass the release through to a pinned
# worktree exactly as it was asked for.
ORIG_ARGS=("$@")

while [ $# -gt 0 ]; do
  case "$1" in
    --only)    TARGETS="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    # Both are forwarded to deploy.sh and concern the WEB gate only — the app
    # builds already run with --skip-gates whenever web is in the same release.
    --skip-gates) SKIP_GATES=--skip-gates; shift ;;
    --gates)      SKIP_GATES=--gates; shift ;;
    --no-bump) BUMP=0; shift ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [--only web,windows,android,ios,macos] [--dry-run] [--gates] [--no-bump]" >&2
       exit 2 ;;
  esac
done

wants() { printf '%s' ",$TARGETS," | grep -q ",$1,"; }

for t in $(printf '%s' "$TARGETS" | tr ',' ' '); do
  case "$t" in
    web|windows|android|ios|macos) ;;
    *) echo "✗ unknown target '$t'. Pick from: web windows android ios" >&2; exit 2 ;;
  esac
done

# ── bump the version, so nobody has to decide it ─────────────────
#
# A1 Tools' build_release.ps1 bumps on every build and this did not, which is
# why 0.0.0127 and 0.0.0128 each shipped twice under one number: the second
# build was invisible to the in-app updater, because it compares versions and
# saw no change. Deciding the number by hand is a step that gets skipped
# exactly when a release is rushed.
#
# The bump is COMMITTED, not left on disk. deploy.sh ships `git archive main`,
# so an uncommitted bump would put the new number in the installers and the old
# tree on the server — two different programs wearing one version, which is the
# thing the dirty-tree check below exists to prevent.
if [ "$BUMP" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
  # A detached HEAD means a release worktree, where a commit is reachable from
  # no branch: the bump would ship nowhere and vanish with the worktree.
  if ! git symbolic-ref -q HEAD >/dev/null 2>&1; then
    echo "✗ cannot bump from a detached worktree — the commit would belong to no branch." >&2
    echo "  Run this from the main checkout, or pass --no-bump to release the" >&2
    echo "  version already in client/VERSION." >&2
    exit 1
  fi

  OLD=$(tr -d '[:space:]' < client/VERSION)
  case "$OLD" in
    *.*[0-9]) ;;
    *) echo "✗ cannot parse client/VERSION ('$OLD') — expected trailing .N" >&2; exit 1 ;;
  esac
  _last=${OLD##*.}
  # 10# forces base 10. Without it $((0129 + 1)) is an invalid octal literal and
  # the release dies on the version number, which is a silly way to lose a deploy.
  NEW="${OLD%.*}.$(printf "%0${#_last}d" "$((10#$_last + 1))")"

  printf '%s\n' "$NEW" > client/VERSION
  # --only commits this path and nothing else. Several sessions share this
  # working tree and its git index; a bare `git commit` takes whatever they have
  # staged, which on 1 Sep silently reverted another session's release notes.
  git commit --only client/VERSION -q -m "v${NEW}" || {
    echo "✗ could not commit the version bump" >&2; exit 1; }
  echo "→ version ${OLD} → ${NEW}"

  # A warning, not a stop. The note matters (version_history.dart is what the
  # version stamp in the app opens), but a missing one is not a reason to refuse
  # to ship a release somebody is waiting on.
  grep -q "version: '${NEW}'" client/lib/core/version_history.dart 2>/dev/null || {
    echo "   ⚠ no release note for ${NEW} in client/lib/core/version_history.dart."
    echo "     The app will show this version with nothing under it."; }
fi

RELEASE_SHA=$(git rev-parse HEAD)
VERSION=$(git show "$RELEASE_SHA:client/VERSION" | tr -d '[:space:]')
if [ -n "${EZBIZI_RELEASE_SHA:-}" ] && [ "$RELEASE_SHA" != "$EZBIZI_RELEASE_SHA" ]; then
  echo "✗ pinned checkout does not match the requested release SHA" >&2
  exit 1
fi
COMMIT=$(git rev-parse --short "$RELEASE_SHA")
DIRTY=0
# docs/ is excluded, as build-app.sh already excludes it. Nothing under docs/
# reaches an installer or the API, and the pinned worktree below builds from
# HEAD regardless of the working tree — so the only thing a modified doc could
# do here is refuse a release. It did that twice on 3-4 Sep: another session
# had four docs open from the previous afternoon, and this line stopped a
# five-target release for files that were never going to ship.
git diff --quiet -- . ':(exclude)docs' && git diff --cached --quiet -- . ':(exclude)docs' || DIRTY=1

echo "── EzBizi release v${VERSION} · ${COMMIT} ──"
echo "   targets: ${TARGETS}"

# ── refuse to release a tree that is not committed ───────────────
#
# deploy.sh tests `git archive main` — the committed tree, not the working
# directory. So a dirty tree ships the API from the last commit while the
# installers are built from the edits on disk. Two different programs, one
# version number, and nothing on either machine says so. Cheap to catch here,
# genuinely confusing to debug later.
if [ "$DIRTY" -eq 1 ] && [ "$DRY_RUN" -eq 1 ]; then
  echo "   (tree is dirty — a real run would stop here)"
elif [ "$DIRTY" -eq 1 ]; then
  echo "✗ the working tree has uncommitted changes." >&2
  echo "  deploy.sh ships the committed tree; the installers would be built from" >&2
  echo "  your edits. Those would be two different builds wearing one version." >&2
  echo "  Commit (or stash) first." >&2
  exit 1
fi

# ── build from a worktree pinned to this commit ──────────────────
#
# Up to thirteen sessions share this working tree, and a release takes minutes.
# Every target after the first therefore builds from whatever the tree happens
# to be by the time it starts, not from what was gated and deployed.
#
# That is not theoretical. On 2-3 Sep it cost four releases: three where a
# session edited mid-run and windows/android/ios all refused with "the working
# tree has uncommitted changes" after web had already shipped, and one where the
# .ipa recorded a different SHA from the .exe because main moved between them.
# The recovery each time was to build the failed targets from a worktree pinned
# to the SHA web shipped — so this does that up front instead of after the loss.
#
# The bump above has already been committed, so HEAD here is the release. The
# worktree is detached at it and cannot move: another session's commit lands on
# main and leaves this build untouched.
#
# EZBIZI_PINNED stops the re-exec recursing. --no-bump is forced because the
# bump is done; without it the pinned run would bump again, and a detached HEAD
# cannot carry that commit anywhere.
if [ -z "${EZBIZI_PINNED:-}" ] && [ "$DRY_RUN" -eq 0 ]; then
  PIN_SHA="$RELEASE_SHA"
  PIN_DIR="$(dirname "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")")/wt/release-${PIN_SHA:0:12}"

  if [ -d "$PIN_DIR" ]; then
    [ "$(git -C "$PIN_DIR" rev-parse HEAD)" = "$PIN_SHA" ] || {
      echo "✗ existing release worktree has a different SHA" >&2; exit 1;
    }
    echo "→ reusing pinned worktree $PIN_DIR"
  else
    echo "→ pinning the build to ${PIN_SHA:0:7} — $PIN_DIR"
    git worktree add --detach "$PIN_DIR" "$PIN_SHA" >/dev/null
  fi

  # Gitignored, so a fresh worktree has no signing key and Android would fail
  # its preflight for a reason that has nothing to do with the release.
  if [ -f client/android/key.properties ]; then
    # -ef: the same inode. Running this from INSIDE the pinned worktree (which
    # is what the re-exec below does, and what a person retrying a failed
    # release does) makes source and destination the same file, and cp refuses.
    # That aborted 0.0.0147's first attempt before it built anything.
    [ client/android/key.properties -ef "$PIN_DIR/client/android/key.properties" ]       || cp client/android/key.properties "$PIN_DIR/client/android/key.properties"
  fi

  MAIN_DIR="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"
  cd "$PIN_DIR"
  # Not exec: this shell stays alive to remove the worktree afterwards. A
  # finished release keeps nothing there — artefacts went to main's
  # Distribution/ — and by 4 Sep 2026 seventeen dead checkouts had piled
  # up under wt/, each dirty only with the docs/EZBIZI-STATUS.md that
  # deploy.sh regenerates, hence --force. A failed release keeps its
  # worktree and says where it is.
  set +e
  EZBIZI_PINNED=1 EZBIZI_RELEASE_SHA="$PIN_SHA" bash scripts/release.sh --no-bump "${ORIG_ARGS[@]}"
  STATUS=$?
  set -e
  cd "$MAIN_DIR"
  if [ "$STATUS" -eq 0 ]; then
    # On Windows the Gradle/Kotlin daemons keep files open under the pin's
    # client/build for minutes after the Android build, and the removal fails
    # with "Invalid argument", leaving a full checkout behind every release
    # (four of them on 2026-09-08). Stop the daemons first; they restart on
    # the next build.
    (cd "$PIN_DIR/client/android" 2>/dev/null && ./gradlew --stop >/dev/null 2>&1) || true
    git worktree remove --force "$PIN_DIR" 2>/dev/null && echo "→ removed pinned worktree $PIN_DIR"       || echo "→ pinned worktree still held by a build daemon, remove it later: git worktree remove --force $PIN_DIR"
  else
    echo "→ pinned worktree kept for inspection: $PIN_DIR"
  fi
  exit "$STATUS"
fi

# ── android needs a real key before it needs anything else ───────
#
# Checked BEFORE the web deploy, not when the AAB is built. Failing at the end
# would mean the API and the Windows installer have already shipped for a
# release that cannot be completed — and the fix, generating a key, is a
# decision with a password in it that nobody should be making in a hurry.
if wants android && [ ! -f client/android/key.properties ]; then
  cat >&2 <<'MISSING'
✗ no Android upload key on this machine (client/android/key.properties).

  Without it the release build falls back to the debug key and Google Play
  rejects the upload. Create one — you choose the passwords, they are never
  stored anywhere but the two files below, and both are already gitignored:

    "/c/Program Files/Android/Android Studio/jbr/bin/keytool.exe" -genkey -v \
      -keystore "/h/Development/Credentials/ezbizi-upload.jks" \
      -storetype JKS -keyalg RSA -keysize 2048 -validity 10000 -alias upload

  keytool ships with Android Studio's JDK and is not on PATH, hence the full
  path. H:\Development\Credentials is where this project's other credentials
  live and is not a git repo.

  Then write client/android/key.properties:

    storePassword=<the store password you just chose>
    keyPassword=<the key password you just chose>
    keyAlias=upload
    storeFile=H:/Development/Credentials/ezbizi-upload.jks

  Forward slashes in storeFile. That is a Java .properties file, where a
  backslash escapes the next character, so a Windows path written the Windows
  way is silently mangled into a keystore that cannot be found.

  Back that .jks up somewhere you will still have in five years. Losing it
  means asking Play for a key reset; leaking it means the same. It is not in
  the repo and it must not be — a keystore in git history is burned for good.
MISSING
  exit 1
fi

# ── ios needs a certificate that lives on somebody else's machine ─
#
# Same reasoning as the Android key above, one step further away: the signing
# identity is not on this machine at all, it is in a keychain on the Mac relay.
# Checked here, before the web deploy, so a release cannot get half-shipped and
# then discover it has no way to produce the artefact it promised.
#
# Matched on TEAM. The Mac holds three codesigning identities and two of them
# belong to other teams entirely — a personal Developer ID and an Apple
# Development certificate. Only 2CMZQR34RL is EZBIZI LLC, and only it can sign
# com.ezbizi.app.
if wants ios; then
  if ! ssh -o ConnectTimeout=10 -o BatchMode=yes mac 'true' 2>/dev/null; then
    echo "✗ cannot reach the Mac relay (ssh alias 'mac')." >&2
    echo "  Nothing Apple-shaped can be built without it. Is the Mac mini awake?" >&2
    exit 1
  fi

  if ! ssh -o BatchMode=yes mac "security find-identity -v -p codesigning 2>/dev/null | grep -q 'Apple Distribution.*2CMZQR34RL'"; then
    cat >&2 <<'MISSING'
✗ the Mac relay cannot sign for EZBIZI LLC (team 2CMZQR34RL).

  It has codesigning identities, but they belong to other teams — a personal
  Developer ID and an Apple Development certificate. Neither can sign
  com.ezbizi.app, and Apple rejects an archive signed by the wrong team.

  Three things are needed, in this order, all under development@ezbizi.com:

  1. An Apple Distribution certificate for team 2CMZQR34RL.
     On the Mac: Keychain Access > Certificate Assistant > Request a Certificate
     From a Certificate Authority. Save to disk. Upload the .certSigningRequest
     at developer.apple.com > Certificates > + > Apple Distribution. Download
     the .cer and double-click it to install into the login keychain.

     The private key never leaves that Mac. That is the point of a CSR, and it
     is why this cannot be done from here.

  2. An App Store provisioning profile for com.ezbizi.app, tied to that
     certificate. developer.apple.com > Profiles > + > App Store Connect.

  3. An App Store Connect API key, so uploads do not need a password or a 2FA
     prompt on a headless machine. App Store Connect > Users and Access >
     Integrations > App Store Connect API. Download the .p8 ONCE — Apple does
     not offer it again — and put it in ~/.appstoreconnect/private_keys/ on the
     Mac. Keep a copy in H:\Development\Credentials.

  Then run this again. build-app.sh detects the identity by team and switches
  from an unsigned compile check to a real signed .ipa on its own.
MISSING
    exit 1
  fi
fi

# ── untracked files in the directories that ship ─────────────────
#
# A warning, not a stop: plenty of untracked files are meant to be untracked.
# But `git archive main` carries only what is committed, so an untracked
# migration deploys as "Nothing to migrate" and an untracked screen is simply
# absent — both of which read as a broken feature rather than a missing commit.
# Narrow on purpose: only the directories whose contents actually ship.
UNTRACKED=$(git ls-files --others --exclude-standard -- app database routes config client/lib 2>/dev/null || true)
if [ -n "$UNTRACKED" ]; then
  echo "   ⚠ untracked files in shipping directories — these will NOT be released:"
  printf '%s\n' "$UNTRACKED" | sed 's/^/     /'
fi

run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "   would run: $*"
    return 0
  fi
  "$@"
}

# ── Windows code signing ─────────────────────────────────────────
#
# The EzBizi LLC certificate (DigiCert, valid to 25 Oct 2027) lives on the
# SafeNet eToken, the USB key, next to a dead A1 Chimney one. So the
# certificate is picked by NAME. /a would let signtool choose, and it can choose
# A1.
#
# SafeNet asks for the token password in a popup. If nobody is there to type it,
# or the token is not plugged in, this gives up after 5 minutes and the build
# ships UNSIGNED with a warning. Jose, 26 Oct: an update that never ships is
# worse than one with a SmartScreen warning. Turning on "single logon" in
# SafeNet Authentication Client makes it ask once per Windows session rather
# than once per file.
SIGNTOOL=$(ls "/c/Program Files (x86)/Windows Kits/10/bin/"*/x64/signtool.exe 2>/dev/null | sort -V | tail -1 || true)
UNSIGNED=()
sign() {
  if [ "$DRY_RUN" -eq 1 ]; then
    echo "   would sign $1"
    return 0
  fi
  # MSYS_NO_PATHCONV for the same reason as the ISCC call below: /n, /fd and /tr
  # would otherwise be rewritten into Windows paths.
  if [ -n "$SIGNTOOL" ] && timeout 300 env MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' \
      "$SIGNTOOL" sign /n "EzBizi LLC" /fd sha256 /tr http://timestamp.digicert.com /td sha256 \
      "$(cygpath -w "$1")"; then
    return 0
  fi
  echo "   ⚠ $(basename "$1") NOT signed: token not plugged in, password not entered, or no signtool" >&2
  UNSIGNED+=("$(basename "$1")")
}

mkdir -p "$DIST_WINDOWS" "$DIST_ANDROID" "$DIST_APPLE"

# ── 1. web ───────────────────────────────────────────────────────
#
# deploy.sh is the whole web release and gates on both suites before it ships
# anything. It is also the only gate the other targets get: they are built with
# --skip-gates below precisely because this already ran them, against this same
# commit, minutes ago. Drop web from --only and the gates come back (see below).
GATES=--skip-gates
if wants web; then
  echo
  echo "→ web — deploy.sh (tests, build, push, UI swap)"
  if ! run ./scripts/deploy.sh ${SKIP_GATES} --sha "$RELEASE_SHA"; then
    echo "✗ web/API deployment failed; native builds and publication stopped" >&2
    exit 1
  fi
else
  # Nothing has tested this commit in this run, so let build-app.sh do it.
  GATES=""
  echo
  echo "   (web skipped — the app builds will run the client gates themselves)"
fi

# ── 2. windows ───────────────────────────────────────────────────
if wants windows; then
  echo
  echo "→ windows — building"
  if run ./scripts/build-app.sh windows ${GATES}; then
  GATES=--skip-gates   # whatever ran above covered this commit

  WIN_BUILD="client/build/windows/x64/runner/Release"
  if [ "$DRY_RUN" -eq 0 ] && [ ! -f "$WIN_BUILD/ezbizi.exe" ]; then
    echo "✗ expected $WIN_BUILD/ezbizi.exe and it is not there." >&2
    echo "  Flutter moved its output, or the build silently produced nothing." >&2
    exit 1
  fi

  # Inno Setup. Installed at a Program Files path with a space and a bracket in
  # it, so it is quoted and called through its absolute path rather than assumed
  # to be on PATH — it is not on PATH by default.
  ISCC="/c/Program Files (x86)/Inno Setup 6/ISCC.exe"
  if [ ! -f "$ISCC" ]; then
    ISCC="/c/Program Files/Inno Setup 6/ISCC.exe"
  fi
  if [ "$DRY_RUN" -eq 0 ] && [ ! -f "$ISCC" ]; then
    echo "✗ Inno Setup 6 not found — the folder built, but nothing wrapped it." >&2
    echo "  Install it from https://jrsoftware.org/isdl.php, or ship the folder" >&2
    echo "  at $WIN_BUILD by hand." >&2
    exit 1
  fi

  # The app itself first, so the copy the installer drops on disk is signed too,
  # not only the installer that carries it.
  echo "→ windows — signing ezbizi.exe"
  sign "$WIN_BUILD/ezbizi.exe"

  echo "→ windows — wrapping into an installer"
  # Two separate Windows/POSIX boundaries here, and both bite.
  #
  # cygpath, because ISCC is a native Windows exe and cannot read /c/... paths.
  #
  # MSYS_NO_PATHCONV, because Git Bash rewrites any argument that starts with a
  # slash into a Windows path — so /DAppVersion=0.0.0097 arrived as something
  # ISCC read as a *filename*, and it refused with "You may not specify more
  # than one script filename". Both spellings are set: Git Bash reads the first,
  # MSYS2 the second.
  run env MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' "$ISCC" \
    "/DAppVersion=${VERSION}" \
    "/DSourceDir=$(cygpath -w "$PWD/$WIN_BUILD")" \
    "/DOutputDir=$(cygpath -w "$DIST_WINDOWS")" \
    "/DAssetDir=$(cygpath -w "$PWD/assets/installer")" \
    "$(cygpath -w "$PWD/scripts/windows-installer.iss")"

  # ── publish it, so installed copies can find it ──────────────────
  #
  # An installer nobody can download updates nobody. The app asks
  # https://www.ezbizi.com/download/windows.json on launch and offers what it
  # finds; see client/lib/core/desktop_update.dart.
  #
  # Two names for one file, deliberately. The versioned copy is the archive —
  # it never changes, so a specific build stays fetchable for as long as anyone
  # needs to reproduce a bug report. `EzBizi-setup.exe` is the moving target
  # the manifest points at, so the URL in the manifest is stable and the
  # download is always the newest.
  #
  # The manifest is written AFTER both uploads. A manifest announcing a version
  # whose installer has not landed yet sends every client to a 404 for as long
  # as the upload takes, and they would all try at once.
  WIN_EXE="$DIST_WINDOWS/EzBizi-${VERSION}-windows-x64.exe"

  if [ "$DRY_RUN" -eq 0 ] && [ ! -f "$WIN_EXE" ]; then
    echo "✗ Inno reported success but $WIN_EXE is not there." >&2
    exit 1
  fi

  # Before the upload below, so the installer that gets published is the signed one.
  echo "→ windows — signing the installer"
  sign "$WIN_EXE"

  # ── do not advertise a client the API cannot serve ───────────────
  #
  # BUILDING after a failed web deploy is harmless. PUBLISHING is not, and this
  # step publishes: it writes the installer AND windows.json to
  # www.ezbizi.com/download, and windows.json is what the in-app updater polls.
  # So a failed web deploy plus a successful Windows build would push a newer
  # client onto every installed desktop, automatically, against an API still
  # running the old code. Nobody asked for that update and nobody can easily
  # take it back.
  #
  # Same rule as the ordering comment at the top of this file, but it has to be
  # enforced HERE now that a failed target no longer aborts the release.
  #
  # Only when web was part of this release. `--only windows` publishes freely:
  # the API is whatever it already was, and shipping an installer fix against an
  # unchanged API is the normal case.
  PUBLISH_WINDOWS=1
  if wants web; then
    for f in ${FAILED[@]+"${FAILED[@]}"}; do
      [ "$f" = "web" ] && PUBLISH_WINDOWS=0
    done
  fi

  if [ "$PUBLISH_WINDOWS" -eq 0 ]; then
    echo "⚠ windows — built, but NOT published: the web deploy failed." >&2
    echo "  windows.json drives the in-app updater, so publishing it now would" >&2
    echo "  push this client onto every installed desktop against an API still" >&2
    echo "  on the previous version. The installer is in ${DIST}/ and can be" >&2
    echo "  published by hand once web lands." >&2
    FAILED+=("windows-publish")
  fi

  if [ "$PUBLISH_WINDOWS" -eq 1 ]; then
  echo "→ windows — publishing the installer and its manifest"
  run ssh "$WEB_REMOTE" "sudo mkdir -p '$WEB_DOWNLOADS' && sudo chown www-data:www-data '$WEB_DOWNLOADS'"

  if [ "$DRY_RUN" -eq 0 ]; then
    gzip -c "$WIN_EXE" | ssh "$WEB_REMOTE" "
      set -e
      gunzip -c | sudo tee '$WEB_DOWNLOADS/EzBizi-${VERSION}-windows-x64.exe' >/dev/null
      sudo cp '$WEB_DOWNLOADS/EzBizi-${VERSION}-windows-x64.exe' '$WEB_DOWNLOADS/EzBizi-setup.exe'
      printf '%s' '{\"version\":\"${VERSION}\",\"url\":\"https://www.ezbizi.com/download/EzBizi-setup.exe\",\"built\":\"${COMMIT}\"}' \
        | sudo tee '$WEB_DOWNLOADS/windows.json' >/dev/null
      sudo chown -R www-data:www-data '$WEB_DOWNLOADS'
    "
  else
    echo "   would upload $WIN_EXE and write windows.json"
  fi
  fi
else
  FAILED+=("windows")
  echo "   ✗ windows failed — skipped its packaging, continuing"
fi
fi

# ── 3. android ───────────────────────────────────────────────────
if wants android; then
  echo
  echo "→ android — building signed app bundle"
  if run ./scripts/build-app.sh android ${GATES}; then

  AAB="client/build/app/outputs/bundle/release/app-release.aab"
  if [ "$DRY_RUN" -eq 0 ]; then
    if [ ! -f "$AAB" ]; then
      echo "✗ expected $AAB and it is not there." >&2
      exit 1
    fi
    cp "$AAB" "$DIST_ANDROID/EzBizi-${VERSION}-${COMMIT}.aab"
    # Upload to Play, like iOS goes to TestFlight. Play still reviews it.
    # A failure warns and does not fail the release: the AAB is in dist/.
    ./scripts/play-upload.sh "$DIST_ANDROID/EzBizi-${VERSION}-${COMMIT}.aab" production "$VERSION" \
      || echo "   ⚠ Play upload failed — the AAB is in $DIST_ANDROID; upload it by hand"
  fi
else
  FAILED+=("android")
  echo "   ✗ android failed — skipped its packaging, continuing"
fi
fi

# ── 4. ios ───────────────────────────────────────────────────────
#
# Last, and after Android on purpose. It is the slowest target by a wide margin
# — a source sync over the LAN, a pub get, an archive and an export on a machine
# that is not this one — so putting it last means a failure in a cheaper target
# surfaces in seconds rather than after the Mac has finished.
#
# build-app.sh fetches the .ipa into dist/ itself, because only it knows where
# the Mac left it. Nothing to copy here.
#
# --upload is passed on every release: an .ipa sitting in dist/ is a build
# nobody can try, and being tryable is the whole reason TestFlight exists
# (Jose's ruling, 1 Sep). Upload is not submission — the build goes to
# internal testers, and nothing reaches App Store review without someone
# attaching it there.
if wants ios; then
  echo
  echo "→ ios — building signed archive on the Mac relay"
  if run ./scripts/build-app.sh ios ${GATES} --upload; then

  # EzBizi-${VERSION}-*.ipa, not EzBizi-*.ipa. dist/ keeps every past release,
  # so a bare glob matches a months-old artefact and reports a build that
  # produced nothing as fine — the one failure this check exists to catch.
  # Windows and Android were already version-specific; this was not.
  if [ "$DRY_RUN" -eq 0 ] && ! ls "$DIST_APPLE"/EzBizi-"${VERSION}"-*.ipa >/dev/null 2>&1; then
    echo "✗ build-app.sh finished but left no .ipa in ${DIST_APPLE}/." >&2
    exit 1
  fi
else
  FAILED+=("ios")
  echo "   ✗ ios failed — skipped its packaging, continuing"
fi
fi

# ── 5. macos ─────────────────────────────────────────────────────
#
# Added 4 Sep, the day Apple approved the app. Same relay, same source sync
# as iOS, so it goes last with it. build-app.sh compiles on the Mac and brings
# the .app home zipped; nothing is published from here yet.
#
# Signed for the Mac App Store when the relay holds the two identities and
# the profile (all three created 4 Sep 2026), and uploaded like iOS is. When
# it cannot sign, build-app.sh falls back to an ad-hoc .app zip and says so,
# rather than failing the target — a compile check is still worth having.
if wants macos; then
  echo
  echo "→ macos — building on the Mac relay"
  if run ./scripts/build-app.sh macos ${GATES} --upload; then

  # A signed .pkg when the relay can sign for the store, an ad-hoc .zip when it
  # cannot; build-app.sh says which. Either is a build; neither is missing.
  # One ls per glob: ls given two globs fails when EITHER has no match, so
  # a single ls of both declared the first real .pkg (0.0.0150) missing.
  if [ "$DRY_RUN" -eq 0 ] \
     && ! ls "$DIST_APPLE"/EzBizi-"${VERSION}"-*-macos.pkg >/dev/null 2>&1 \
     && ! ls "$DIST_APPLE"/EzBizi-"${VERSION}"-*-macos.zip >/dev/null 2>&1; then
    echo "✗ build-app.sh finished but left no macOS .pkg or .zip in ${DIST_APPLE}/." >&2
    exit 1
  fi
else
  FAILED+=("macos")
  echo "   ✗ macos failed — skipped its packaging, continuing"
fi
fi

echo
if [ "$DRY_RUN" -eq 1 ]; then
  echo "✓ dry run — nothing was built"
  exit 0
fi

if [ ${#UNSIGNED[@]} -gt 0 ]; then
  echo "⚠ shipped UNSIGNED (Windows will show a SmartScreen warning): ${UNSIGNED[*]}"
  echo "  Plug in the EzBizi token and run --only windows to replace it with a signed build."
fi

if [ ${#FAILED[@]} -gt 0 ]; then
  echo
  echo "✗ release v${VERSION} · ${COMMIT} — ${#FAILED[@]} target(s) failed:"
  printf '     %s
' "${FAILED[@]}"
  echo "  The rest were built; their artefacts are in ${DIST}/."
  case " ${FAILED[*]} " in *" web "*)
    echo "  WEB FAILED, so the API did NOT ship. Anything built in this run is"
    echo "  newer than what is live — do not distribute it." ;;
  esac
  exit 1
fi

echo "✓ release v${VERSION} · ${COMMIT}"
wants web && echo "   web      https://app.ezbizi.com"
if [ -d "$DIST" ]; then
  echo "   artefacts in ${DIST}/:"
  for d in "$DIST_WINDOWS" "$DIST_ANDROID" "$DIST_APPLE"; do
    # This release's files only. Each folder keeps every past build, so listing
    # the whole thing reports months-old artefacts as if they were today's —
    # the same trap the .ipa check above already guards against.
    found=$(ls -1sh "$d"/EzBizi-"${VERSION}"-* 2>/dev/null) || continue
    [ -n "$found" ] || continue
    echo "     $(basename "$d")/"
    printf '%s\n' "$found" | sed 's/^/       /'
  done
fi
