#!/usr/bin/env bash
#
# Release progress for the Development tab's Releases screen.
#
#   source scripts/lib/release-status.sh
#   release_status <stage> <percent> <message>
#   release_status_gate <remote gate log>      # server_tests % from phpunit
#
# or, from a wrapper that is not bash-sourcing this file:
#
#   EZBIZI_RELEASE_SHA=<sha> bash scripts/lib/release-status.sh precheck 40 "flutter test"
#
# Each call appends one JSON line to $RELEASE_STATUS_FILE on the app box over
# the ssh alias the scripts already use. GET /api/v1/staff/release-status reads
# that file.
#
# Stages, in order: precheck server_tests web windows android apple, plus
# `release` for the run as a whole (0 = started, 100 = finished).
# A message starting with ✗ marks the stage failed; percent 100 marks it done.
#
# IT MUST NEVER FAIL A RELEASE. Every call swallows its own errors, has a short
# timeout, and returns 0. A box that cannot be reached costs the progress bar,
# not the deploy. EZBIZI_RELEASE_STATUS=off turns it off.

RELEASE_STATUS_REMOTE="${RELEASE_STATUS_REMOTE:-app}"
# Not under public-app (served) and not storage/ (www-data's): the deploy user
# owns /var/www/ezbizi, so the file needs no setup, and 0644 lets php-fpm read it.
RELEASE_STATUS_FILE="${RELEASE_STATUS_FILE:-/var/www/ezbizi/release-status.jsonl}"

_release_status_json_escape() {
  local s=${1//\\/\\\\}
  s=${s//\"/\\\"}
  printf '%s' "$s" | tr -d '\000-\037'
}

# Prints the JSON line, with the literal token __PCT__ when $2 is __PCT__.
_release_status_line() {
  local sha="${RELEASE_STATUS_SHA:-${EZBIZI_RELEASE_SHA:-}}" version="${EZBIZI_RELEASE_VERSION:-}" state=running
  [ -n "$sha" ] || sha=$(git rev-parse HEAD 2>/dev/null || true)
  [ -n "$version" ] || version=$(git show "$sha:client/VERSION" 2>/dev/null | tr -d '[:space:]' || true)
  case "$3" in ✗*) state=failed ;; *) [ "$2" = 100 ] && state=done ;; esac
  local extra=""
  [ -n "${RELEASE_STATUS_TARGETS:-}" ] && extra=",\"targets\":\"$(_release_status_json_escape "$RELEASE_STATUS_TARGETS")\""
  printf '{"version":"%s","sha":"%s","stage":"%s","percent":%s,"state":"%s","message":"%s","at":"%s"%s}\n' \
    "$(_release_status_json_escape "$version")" "$sha" "$1" "$2" "$state" \
    "$(_release_status_json_escape "$3")" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$extra"
}

# Appends stdin's one line on the box; trims the file so it never grows past 5000.
_release_status_send() {
  [ "${EZBIZI_RELEASE_STATUS:-on}" = off ] && return 0
  local cap=()
  command -v timeout >/dev/null 2>&1 && cap=(timeout 20)
  ${cap[@]+"${cap[@]}"} ssh -o ConnectTimeout=8 -o BatchMode=yes "$RELEASE_STATUS_REMOTE" "$1" >/dev/null 2>&1 || true
}

release_status() {  # release_status <stage> <percent 0-100> <message>
  {
    local line
    line=$(_release_status_line "$1" "$2" "$3") || return 0
    printf '%s\n' "$line" | _release_status_send \
      "f='$RELEASE_STATUS_FILE'; cat >> \"\$f\"; [ \$(wc -l < \"\$f\") -le 5000 ] || { tail -n 2000 \"\$f\" > \"\$f.tmp\" && mv \"\$f.tmp\" \"\$f\"; }"
  } 2>/dev/null || true
  return 0
}

# Reads the last "( NN%)" phpunit printed into the gate log ON the box and
# records it as server_tests' percent — one ssh call, nothing written if
# phpunit has not started printing yet.
release_status_gate() {  # release_status_gate <remote log path>
  {
    local line
    line=$(_release_status_line server_tests __PCT__ "phpunit running on the box") || return 0
    printf '%s\n' "$line" | _release_status_send \
      "p=\$(tail -c 4000 '$1' 2>/dev/null | grep -oE '[0-9]+%\\)' | tail -1 | tr -dc 0-9); [ -n \"\$p\" ] || exit 0; [ \"\$p\" -lt 100 ] || p=99; IFS= read -r l; printf '%s\\n' \"\${l/__PCT__/\$p}\" >> '$RELEASE_STATUS_FILE'"
  } 2>/dev/null || true
  return 0
}

# Run as a command rather than sourced.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  release_status "${1:?stage}" "${2:?percent}" "${3:-}"
fi
