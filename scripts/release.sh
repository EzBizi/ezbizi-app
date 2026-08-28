#!/usr/bin/env bash
#
# One release, every platform.
#
#   ./scripts/release.sh                    web + windows installer + android aab
#   ./scripts/release.sh --only windows     just that one
#   ./scripts/release.sh --only web,android
#   ./scripts/release.sh --dry-run          say what it would do, build nothing
#
#   ./scripts/release.sh --only ios        signed .ipa via the Mac relay
#
# iOS is a target but not a default. It is the only platform whose signing
# identity lives on another machine, so a release that included it by default
# would fail for anyone whose Mac was asleep. Ask for it explicitly until that
# stops being true.
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

DIST="dist"
TARGETS="web,windows,android"
DRY_RUN=0

# The marketing site's box, which is NOT the app box — see AGENTS/DEPLOY. The
# installer is served from www.ezbizi.com because that is where a person who
# has not installed anything yet goes looking, and because the update manifest
# has to be reachable by a client the app server may already be refusing.
WEB_REMOTE="app"
WEB_DOWNLOADS="/var/www/www/download"

while [ $# -gt 0 ]; do
  case "$1" in
    --only)    TARGETS="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [--only web,windows,android,ios] [--dry-run]" >&2
       exit 2 ;;
  esac
done

wants() { printf '%s' ",$TARGETS," | grep -q ",$1,"; }

for t in $(printf '%s' "$TARGETS" | tr ',' ' '); do
  case "$t" in
    web|windows|android|ios) ;;
    *) echo "✗ unknown target '$t'. Pick from: web windows android ios" >&2; exit 2 ;;
  esac
done

VERSION=$(tr -d '[:space:]' < client/VERSION)
COMMIT=$(git rev-parse --short HEAD)
DIRTY=0
git diff --quiet && git diff --cached --quiet || DIRTY=1

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

mkdir -p "$DIST"

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
  run ./scripts/deploy.sh
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
  run ./scripts/build-app.sh windows ${GATES}
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
    "/DOutputDir=$(cygpath -w "$PWD/$DIST")" \
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
  WIN_EXE="$DIST/EzBizi-${VERSION}-windows-x64.exe"

  if [ "$DRY_RUN" -eq 0 ] && [ ! -f "$WIN_EXE" ]; then
    echo "✗ Inno reported success but $WIN_EXE is not there." >&2
    exit 1
  fi

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

# ── 3. android ───────────────────────────────────────────────────
if wants android; then
  echo
  echo "→ android — building signed app bundle"
  run ./scripts/build-app.sh android ${GATES}

  AAB="client/build/app/outputs/bundle/release/app-release.aab"
  if [ "$DRY_RUN" -eq 0 ]; then
    if [ ! -f "$AAB" ]; then
      echo "✗ expected $AAB and it is not there." >&2
      exit 1
    fi
    cp "$AAB" "$DIST/EzBizi-${VERSION}-${COMMIT}.aab"
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
if wants ios; then
  echo
  echo "→ ios — building signed archive on the Mac relay"
  run ./scripts/build-app.sh ios ${GATES}

  if [ "$DRY_RUN" -eq 0 ] && ! ls "$DIST"/EzBizi-*.ipa >/dev/null 2>&1; then
    echo "✗ build-app.sh finished but left no .ipa in ${DIST}/." >&2
    exit 1
  fi
fi

echo
if [ "$DRY_RUN" -eq 1 ]; then
  echo "✓ dry run — nothing was built"
  exit 0
fi

echo "✓ release v${VERSION} · ${COMMIT}"
wants web && echo "   web      https://app.ezbizi.com"
if [ -d "$DIST" ] && [ -n "$(ls -A "$DIST" 2>/dev/null)" ]; then
  echo "   artefacts in ${DIST}/:"
  ls -1sh "$DIST" | sed 's/^/     /'
fi
