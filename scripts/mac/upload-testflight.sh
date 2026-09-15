#!/bin/bash
# Validate, then upload an .ipa or .pkg to App Store Connect.
#
# Usage: upload-testflight.sh /path/to/EzBizi.ipa            (ios, the default)
#        upload-testflight.sh /path/to/EzBizi.pkg macos
#
# An API key, not an Apple ID: a password needs a 2FA code, and there is nobody
# at the keyboard of a relay Mac to type one. The key is App Manager only - it
# can ship builds, and cannot manage users or accept agreements.
#
# A script here rather than lines in build-app.sh because the command needs
# nested quoting through ssh, which is how credentials get mangled.
set -e
FILE="$1"
TYPE="${2:-ios}"
[ -f "$FILE" ] || { echo "no such file: $FILE" >&2; exit 1; }
case "$TYPE" in ios|macos) ;; *) echo "type must be ios or macos, not $TYPE" >&2; exit 1;; esac
. "$HOME/ezbizi-signing/asc-key.env"
export API_PRIVATE_KEYS_DIR="$HOME/.appstoreconnect/private_keys"

# altool EXITS 0 WHEN IT FAILS. Verified 1 Sep 2026: a duplicate bundle version
# printed "ERROR ... must be higher than the previously uploaded version" and
# "Failed to upload package", then returned 0 - so `set -e` above did nothing,
# build-app.sh printed "-> uploaded", and release.sh reported the whole release
# as successful over an upload App Store Connect had rejected.
#
# So the output is the verdict, not the exit status: run altool, show everything
# it said, and fail if it said ERROR.
run_altool() {
  local phase="$1"; shift
  local out
  if ! out=$(xcrun altool "$@" 2>&1); then
    printf "%s\n" "$out"
    echo "x altool $phase failed (exit status)" >&2
    return 1
  fi
  printf "%s\n" "$out"
  if printf "%s" "$out" | grep -q "ERROR"; then
    # ...but an ERROR line FOLLOWED BY A COMPLETED DELIVERY is Apple's transient,
    # not a rejection. Verified 10 Sep 2026 on 0.0.0190: altool printed
    #   ERROR: CHANGE UPLOAD STATE TO COMPLETE ... status code 500
    # and then
    #   UPLOAD SUCCEEDED ... Delivery UUID: 0b075687-... Transferred 29655142 bytes
    # The byte count matched the .ipa exactly and App Store Connect showed build
    # 190 under TestFlight > Build Uploads as Processing. Failing that run was
    # wrong, and it cost a release its iOS leg over a build that had arrived.
    #
    # So: fatal ONLY when the delivery did not complete. Both signals are
    # required, because either alone is weaker than the pair -- and on the
    # validate phase neither appears, so a real validation ERROR still fails.
    if printf "%s" "$out" | grep -q "UPLOAD SUCCEEDED" &&
       printf "%s" "$out" | grep -q "Delivery UUID:"; then
      echo "! altool $phase printed an ERROR and the delivery COMPLETED anyway." >&2
      echo "! Apple may have SKIPPED VALIDATION (look for a 409 STATE_ERROR.UPDATING" >&2
      echo "! above). A build can still be failed during processing afterwards." >&2
      echo "! A PERSON must confirm this build in App Store Connect >" >&2
      echo "! TestFlight > Build Uploads. Nothing in this pipeline can do it." >&2
    else
      echo "x altool $phase reported an ERROR and still exited 0 - treating as failed" >&2
      return 1
    fi
  fi

  # AND NOW THE POSITIVE HALF, which is the one that was missing.
  #
  # Everything above asks whether altool COMPLAINED. Nothing asked whether it
  # SUCCEEDED, so the pass and a silent no-op were the same outcome: exit 0,
  # no ERROR in the output, guard satisfied, release green. Output that is
  # neither a success banner nor an error -- a truncated stream, a stub, a
  # future altool that changes its wording -- sailed straight through.
  #
  # Found 11 Sep 2026 on 0.0.0195: the macOS leg printed both banners, and I
  # could not confirm the iOS leg had because the guard never required one.
  # A release is allowed to fail; it is not allowed to be unverifiable.
  #
  # Phase-specific, because the banners differ and requiring the wrong one
  # would fail every good run: validate says VERIFY SUCCEEDED and never says
  # UPLOAD SUCCEEDED, which is exactly why the transient branch above pairs
  # its two signals rather than trusting either alone.
  case "$phase" in
    validate)
      printf "%s" "$out" | grep -q "VERIFY SUCCEEDED" && return 0
      echo "x altool validate never said VERIFY SUCCEEDED. It did not report an" >&2
      echo "  error either, so this is not a rejection - it is an unreadable run," >&2
      echo "  which is worse. Read the output above before shipping anything." >&2
      return 1
      ;;
    upload)
      if printf "%s" "$out" | grep -q "UPLOAD SUCCEEDED" &&
         printf "%s" "$out" | grep -q "Delivery UUID:"; then
        # DELIVERED IS NOT ACCEPTED, and this is the last place anybody
        # reads before calling the leg shipped. A Delivery UUID means
        # Apple took the bytes. Processing runs afterwards, can still
        # fail, and tells nobody but Jose by email. Build 190 sat
        # unresolved on his list for a day because a green upload was
        # read as a shipped build and nothing here said otherwise.
        echo "  delivered (Delivery UUID present). NOT yet accepted -- App" >&2
        echo "  Store Connect processes it afterwards and processing can" >&2
        echo "  still fail. Only TestFlight > Build Uploads can say. Do not" >&2
        echo "  call this leg shipped until somebody has read it there." >&2
        return 0
      fi
      echo "x altool upload never said UPLOAD SUCCEEDED with a Delivery UUID." >&2
      echo "  No error was reported, so nothing was rejected - but nothing is" >&2
      echo "  known to have ARRIVED either. Do not call this leg shipped." >&2
      return 1
      ;;
  esac
}

echo "validating ($TYPE)..."
run_altool validate --validate-app --type "$TYPE" -f "$FILE" --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
echo "uploading ($TYPE)..."
run_altool upload --upload-app --type "$TYPE" -f "$FILE" --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
