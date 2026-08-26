#!/usr/bin/env bash
#
# One release, every platform.
#
#   ./scripts/release.sh                    web + windows installer + android aab
#   ./scripts/release.sh --only windows     just that one
#   ./scripts/release.sh --only web,android
#   ./scripts/release.sh --dry-run          say what it would do, build nothing
#
# iOS is not here yet. It needs an Apple Developer team, a provisioning profile
# and an unlocked keychain on the Mac relay; build-app.sh can already produce an
# UNSIGNED ios build as a compile check, which is not a release. Add a target
# here when the account is approved — the Mac relay it needs already works.
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

while [ $# -gt 0 ]; do
  case "$1" in
    --only)    TARGETS="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2
       echo "usage: $0 [--only web,windows,android] [--dry-run]" >&2
       exit 2 ;;
  esac
done

wants() { printf '%s' ",$TARGETS," | grep -q ",$1,"; }

for t in $(printf '%s' "$TARGETS" | tr ',' ' '); do
  case "$t" in
    web|windows|android) ;;
    ios) echo "✗ ios is not wired up yet — see the header of this script" >&2; exit 2 ;;
    *) echo "✗ unknown target '$t'. Pick from: web windows android" >&2; exit 2 ;;
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
    "$(cygpath -w "$PWD/scripts/windows-installer.iss")"
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
