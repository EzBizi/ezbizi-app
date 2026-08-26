#!/usr/bin/env bash
#
# Create the Google Play upload key, interactively.
#
#   bash scripts/make-upload-key.sh
#
# Exists because the keytool invocation is 200 characters long and pasting it
# into Git Bash put a bracketed-paste escape (^[[200~) at the front of the
# executable's name — bash then reported "No such file or directory" for a path
# that was plainly correct on screen, because the escape does not print. A short
# command nobody has to paste avoids the whole class of problem.
#
# ── passwords ────────────────────────────────────────────────────
#
# This script never sees them. keytool prompts for them itself, reads them from
# the terminal, and puts them in the keystore. Nothing here holds a password in
# a variable, an argument, or the shell history — which is also why the
# key.properties at the end is printed as a template for you to fill in rather
# than written for you.
set -euo pipefail

cd "$(dirname "$0")/.."

KEYSTORE="/h/Development/Credentials/ezbizi-upload.jks"
ALIAS="upload"

# keytool ships inside a JDK and is essentially never on PATH on Windows.
# Android Studio's bundled JBR is the one Flutter already builds with, so it is
# the right one to use — a key made by a different JDK is fine, but looking in
# the place Flutter points at means one less thing to be wrong.
find_keytool() {
  if command -v keytool >/dev/null 2>&1; then
    command -v keytool
    return 0
  fi
  local candidates=(
    "/c/Program Files/Android/Android Studio/jbr/bin/keytool.exe"
    "/c/Program Files/Android/Android Studio/jre/bin/keytool.exe"
    "${LOCALAPPDATA:-}/Programs/Android Studio/jbr/bin/keytool.exe"
    "${JAVA_HOME:-}/bin/keytool"
    "${JAVA_HOME:-}/bin/keytool.exe"
  )
  local c
  for c in "${candidates[@]}"; do
    [ -n "$c" ] && [ -f "$c" ] && { printf '%s' "$c"; return 0; }
  done
  return 1
}

if ! KEYTOOL=$(find_keytool); then
  echo "✗ could not find keytool." >&2
  echo "  It ships with a JDK. Android Studio bundles one at" >&2
  echo "  C:\\Program Files\\Android\\Android Studio\\jbr\\bin — if yours is" >&2
  echo "  elsewhere, set JAVA_HOME and run this again." >&2
  exit 1
fi

# Never clobber. If a keystore is already here it may be the one Play has
# already registered, and overwriting it costs a key reset request to Google.
if [ -f "$KEYSTORE" ]; then
  echo "✗ a keystore already exists at:" >&2
  echo "    $KEYSTORE" >&2
  echo >&2
  echo "  Not overwriting it. If Play has seen it, replacing it means asking" >&2
  echo "  Google for a key reset. Delete it by hand first if you are certain" >&2
  echo "  it was never uploaded." >&2
  exit 1
fi

mkdir -p "$(dirname "$KEYSTORE")"

cat <<'PROMPTS'
keytool will now ask you a series of questions.

  Keystore password    you choose it, typed twice. Nothing echoes as you
                       type — that is normal, not a frozen terminal.
  First and last name  CN. "EzBizi" is fine; this is an organization key.
  Organizational unit  EZBIZI LLC
  Organization         EZBIZI LLC
  City / State         Hallandale Beach / Florida
  Country code         US  ← two letters, the COUNTRY. Not the state.
  Correct?             yes
  Key password         press RETURN to reuse the keystore password.

PROMPTS

"$KEYTOOL" -genkey -v \
  -keystore "$KEYSTORE" \
  -storetype JKS \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000 \
  -alias "$ALIAS"

if [ ! -f "$KEYSTORE" ]; then
  echo "✗ keytool finished but no keystore appeared at $KEYSTORE" >&2
  exit 1
fi

cat <<EOF

✓ keystore written to $KEYSTORE

Now write client/android/key.properties with the passwords you just chose:

  nano client/android/key.properties

    storePassword=<the keystore password>
    keyPassword=<the same one, if you pressed RETURN>
    keyAlias=${ALIAS}
    storeFile=H:/Development/Credentials/ezbizi-upload.jks

Forward slashes in storeFile — it is a Java .properties file, where a
backslash escapes the next character.

Then check it took, which builds nothing:

  ./scripts/release.sh --only android --dry-run

Back the .jks and its password up somewhere off this drive. It is the one
artefact in the release pipeline that cannot be regenerated.
EOF
