#!/usr/bin/env bash
# All fixed server paths are rewritten into a disposable local fixture.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/ezbizi-receiver-test.XXXXXX")
[[ "$TMP" == */ezbizi-receiver-test.* ]] || exit 2
trap 'rm -rf -- "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/repos/ezbizi.git/hooks" "$TMP/www/ezbizi-current" "$TMP/www/ezbizi"
export TEST_ROOT="$TMP"
export TEST_SHA=1111111111111111111111111111111111111111
REAL_MV=$(command -v mv)
export REAL_MV
cat > "$TMP/bin/flock" <<'SH'
#!/usr/bin/env bash
: >&9 || exit 1
echo lock >> "$TEST_ROOT/events"
SH
cat > "$TMP/bin/git" <<'SH'
#!/usr/bin/env bash
echo "${REF_SHA:-$TEST_SHA}"
SH
cat > "$TMP/bin/git-receive-pack" <<'SH'
#!/usr/bin/env bash
[[ "$EZBIZI_DEPLOY_LOCKED" == 1 ]] && : >&9 || exit 1
echo receive >> "$TEST_ROOT/events"
if [[ "${ACTIVATE:-yes}" == yes ]]; then
  echo "$TEST_SHA" > "$TEST_ROOT/www/ezbizi-current/.ezbizi-release-sha"
fi
SH
cat > "$TMP/bin/mv" <<'SH'
#!/usr/bin/env bash
if [[ "${FAIL_SWAP:-no}" == yes && "$1" == *.stage.* && "$2" == */public-app ]]; then
  [[ "$1" == *.previous ]] || exit 1
fi
exec "$REAL_MV" "$@"
SH
chmod +x "$TMP/bin/"*
export PATH="$TMP/bin:$PATH"
prepare() {
  STAGE="$TMP/ezbizi-deploy.TEST"
  mkdir -p "$STAGE" "$TMP/www/ezbizi/public-app" "$TMP/www/ezbizi/public-app.stage.TEST"
  echo old > "$TMP/www/ezbizi/public-app/identity"
  echo new > "$TMP/www/ezbizi/public-app.stage.TEST/identity"
  echo "$TEST_SHA" > "$TMP/www/ezbizi/public-app.stage.TEST/.ezbizi-release-sha"
  echo old > "$TMP/www/ezbizi-current/.ezbizi-release-sha"
  sed -e 's/\r$//' -e "s|/tmp/ezbizi-deploy|$TMP/ezbizi-deploy|g" \
      -e "s|/var/repos|$TMP/repos|g" -e "s|/var/www|$TMP/www|g" \
      "$ROOT/deploy/receive-release" > "$STAGE/receive-release"
  cat > "$STAGE/post-receive" <<'SH'
#!/usr/bin/env bash
[[ "$EZBIZI_DEPLOY_LOCKED" == 1 ]] && : >&9 || exit 1
echo replay >> "$TEST_ROOT/events"
[[ "${HOOK_FAIL:-no}" != yes ]] || exit 1
echo "$TEST_SHA" > "$TEST_ROOT/www/ezbizi-current/.ezbizi-release-sha"
SH
}
run() { bash "$STAGE/receive-release" "$TEST_SHA" "$TMP/www/ezbizi/public-app.stage.TEST" "$TMP/repos/ezbizi.git"; }
prepare
run
[[ "$(cat "$TMP/www/ezbizi/public-app/identity")" == new ]]
[[ "$(stat -c %a "$TMP/www/ezbizi/public-app")" == 755 ]]
[[ "$(head -1 "$TMP/events")" == lock ]]
prepare
ACTIVATE=no run
grep -q replay "$TMP/events"
prepare
if ACTIVATE=no HOOK_FAIL=yes run; then echo 'FAIL: accepted hook failure'; exit 1; fi
[[ "$(cat "$TMP/www/ezbizi/public-app/identity")" == old ]]
prepare
if ACTIVATE=no REF_SHA=2222222222222222222222222222222222222222 run; then echo 'FAIL: accepted another ref'; exit 1; fi
[[ "$(cat "$TMP/www/ezbizi/public-app/identity")" == old ]]
prepare
if FAIL_SWAP=yes run; then echo 'FAIL: accepted failed UI swap'; exit 1; fi
[[ "$(cat "$TMP/www/ezbizi/public-app/identity")" == old ]]
prepare
echo wrong > "$TMP/www/ezbizi/public-app.stage.TEST/.ezbizi-release-sha"
if run; then echo 'FAIL: accepted mismatched UI manifest'; exit 1; fi
echo 'PASS: receiver lock, manifest, API failure, same-SHA recovery, newer-ref refusal, UI rollback'
