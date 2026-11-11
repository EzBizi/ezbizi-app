#!/usr/bin/env bash
# Upload a signed AAB to Google Play and roll it out on a track.
#
#   scripts/play-upload.sh <path/to/app.aab> [track] [release-name]
#
# track defaults to "production"; release-name defaults to the AAB's version.
# Play still reviews every production release before it reaches phones.
#
# Auth: no key file. The caller's gcloud account (development@ezbizi.com)
# impersonates play-publisher@ezbizi-506117 — it holds TokenCreator on it, and
# the service account holds release rights in Play Console. Jose, 2026-11-02:
# "can you take care of it?" — Play was 11 versions behind because the AAB was
# built every release and never uploaded.
set -euo pipefail

AAB="${1:?usage: play-upload.sh <aab> [track] [release-name]}"
TRACK="${2:-production}"
NAME="${3:-}"
PKG="com.ezbizi.app"
SA="play-publisher@ezbizi-506117.iam.gserviceaccount.com"
API="https://androidpublisher.googleapis.com/androidpublisher/v3/applications/$PKG"
UPLOAD="https://androidpublisher.googleapis.com/upload/androidpublisher/v3/applications/$PKG"

[ -f "$AAB" ] || { echo "✗ $AAB not found" >&2; exit 1; }

TOKEN="$(gcloud auth print-access-token --impersonate-service-account="$SA" \
  --scopes=https://www.googleapis.com/auth/androidpublisher 2>/dev/null)"
[ -n "$TOKEN" ] || { echo "✗ could not get a Play token as $SA" >&2; exit 1; }

# Extract a JSON string/number field without jq.
field() { sed -n "s/.*\"$1\": *\"\{0,1\}\([^\",}]*\).*/\1/p" | head -1; }

call() { # method url [curl args...] — fails loudly on any non-2xx
  local method="$1" url="$2"; shift 2
  local out code
  out="$(curl -sS -X "$method" -H "Authorization: Bearer $TOKEN" -w '\n%{http_code}' "$@" "$url")"
  code="${out##*$'\n'}"; out="${out%$'\n'*}"
  if [ "${code:0:1}" != 2 ]; then echo "✗ $method $url → $code" >&2; echo "$out" >&2; exit 1; fi
  printf '%s' "$out"
}

EDIT="$(call POST "$API/edits" -H 'Content-Type: application/json' -d '{}' | tr -d '\n' | field id)"
[ -n "$EDIT" ] || { echo "✗ no edit id" >&2; exit 1; }
echo "→ edit $EDIT"

CODE="$(call POST "$UPLOAD/edits/$EDIT/bundles?uploadType=media" \
  -H 'Content-Type: application/octet-stream' --data-binary "@$AAB" | tr -d '\n' | field versionCode)"
[ -n "$CODE" ] || { echo "✗ upload returned no versionCode" >&2; exit 1; }
echo "→ uploaded versionCode $CODE"

[ -n "$NAME" ] || NAME="$(basename "$AAB" | sed -n 's/^EzBizi-\([0-9.]*\)-.*/\1/p')"
[ -n "$NAME" ] || NAME="$CODE"

call PUT "$API/edits/$EDIT/tracks/$TRACK" -H 'Content-Type: application/json' \
  -d "{\"track\":\"$TRACK\",\"releases\":[{\"name\":\"$NAME\",\"versionCodes\":[\"$CODE\"],\"status\":\"completed\"}]}" >/dev/null
echo "→ $TRACK set to $NAME ($CODE)"

call POST "$API/edits/$EDIT:commit" -H 'Content-Length: 0' >/dev/null
echo "✓ committed: $NAME ($CODE) on $TRACK — Play reviews it before phones get it"
