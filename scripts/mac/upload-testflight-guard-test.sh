#!/bin/bash
# Controls for the altool guard in upload-testflight.sh. Run it anywhere:
#
#     bash scripts/mac/upload-testflight-guard-test.sh
#
# No Mac, no Xcode, no credentials -- it lifts run_altool() out of its sibling
# and feeds it recorded altool output with xcrun stubbed.
#
# WHY THIS FILE EXISTS. The guard was written to catch altool exiting 0 on a
# rejection, and it did. But it only ever asked whether altool COMPLAINED, so
# a run that said nothing at all -- no error, no success banner -- passed it.
# The pass and the silent no-op were the same outcome from outside, which is
# the one failure a release guard must not have. Caught 11 Sep 2026 on
# 0.0.0195, when the macOS leg printed both banners and nothing in the
# pipeline could tell me whether the iOS leg had.
#
# The three SILENT cases below are the point. Run this against the guard as it
# was before that date and they pass; that is what makes them worth keeping.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/upload-testflight.sh"
[ -f "$SRC" ] || { echo "cannot find $SRC" >&2; exit 1; }

eval "$(sed -n '/^run_altool() {/,/^}$/p' "$SRC")"
type run_altool >/dev/null 2>&1 || { echo "could not lift run_altool() out of $SRC" >&2; exit 1; }

FAKE=""
xcrun() { printf "%s\n" "$FAKE"; return 0; }

RC=0
check() { # name phase want payload
  local name="$1" phase="$2" want="$3" got
  FAKE="$4"
  if run_altool "$phase" >/dev/null 2>&1; then got=pass; else got=fail; fi
  if [ "$got" = "$want" ]; then
    echo "  ok   $name -> $got"
  else
    echo "  FAIL $name -> $got (wanted $want)"
    RC=1
  fi
}

echo "must PASS -- a good run is not allowed to be blocked:"
check "clean validate" validate pass "INFO: [ContentDelivery.Uploader]
==========================================
VERIFY SUCCEEDED with no errors
=========================================="
check "clean upload" upload pass "INFO: [ContentDelivery.Uploader]
UPLOAD SUCCEEDED with no errors
Delivery UUID: 805e1916-34c4-4784-be55-9b116412a79b
Transferred 63123044 bytes"
# Apple's own transient, seen 10 Sep 2026 on 0.0.0190: an ERROR line and then a
# completed delivery. Failing that one cost a release its iOS leg over a build
# that had in fact arrived, so it must keep passing.
check "Apple transient" upload pass "ERROR: CHANGE UPLOAD STATE TO COMPLETE ... status code 500
UPLOAD SUCCEEDED with no errors
Delivery UUID: 0b075687-1111-2222-3333-444455556666"

echo "must FAIL -- these are the reasons the guard exists:"
check "real rejection" upload fail "ERROR ITMS-4000: The bundle version must be higher than the previously uploaded version
Failed to upload package"
check "silent upload" upload fail ""
check "silent validate" validate fail ""
check "upload without a delivery uuid" upload fail "UPLOAD SUCCEEDED with no errors"

[ "$RC" -eq 0 ] && echo "all controls behaved" || echo "a control did not behave -- read it before shipping" >&2
exit "$RC"
