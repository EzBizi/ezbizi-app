#!/usr/bin/env bash
# Isolated repositories and blocked network commands: never touches production.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/ezbizi-release-test.XXXXXX")
[[ "$TMP" == */ezbizi-release-test.* ]] || exit 2
trap 'rm -rf -- "$TMP"' EXIT
REAL_GIT=$(command -v git)
export REAL_GIT
mkdir -p "$TMP/bin" "$TMP/deploy/scripts" "$TMP/release/scripts" "$TMP/release/client"
cat > "$TMP/bin/git" <<'SH'
#!/usr/bin/env bash
if [[ "$1" == push ]]; then
  printf '%s\n' "$*" >> "$CAPTURE"
  exit 42
fi
exec "$REAL_GIT" "$@"
SH
cat > "$TMP/bin/ssh" <<'SH'
#!/usr/bin/env bash
if [[ "$*" == *'mktemp -d /tmp/ezbizi-deploy.'* ]]; then
  echo /tmp/ezbizi-deploy.TEST1234
  exit 0
fi
if [[ "$*" == *'tar -x --strip-components=1'* ]]; then
  cat >/dev/null
  exit 0
fi
printf 'UNEXPECTED NETWORK\n' >> "$CAPTURE"
exit 99
SH
cp "$TMP/bin/ssh" "$TMP/bin/scp"
chmod +x "$TMP/bin/"*
export PATH="$TMP/bin:$PATH"
for repo in deploy release; do
  git -C "$TMP/$repo" init -q -b main
  git -C "$TMP/$repo" config user.name Test
  git -C "$TMP/$repo" config user.email test@example.test
  git -C "$TMP/$repo" config core.autocrlf false
done
tr -d '\r' < "$ROOT/scripts/deploy.sh" > "$TMP/deploy/scripts/deploy.sh"
mkdir -p "$TMP/deploy/deploy"
cp "$ROOT/deploy/post-receive" "$ROOT/deploy/receive-release" "$TMP/deploy/deploy/"
cd "$TMP/deploy"
git add . && git commit -qm first
PIN=$(git rev-parse HEAD)
echo next > next.txt
git add next.txt && git commit -qm next
git checkout -q --detach "$PIN"
export CAPTURE="$TMP/deploy-calls"
bash scripts/deploy.sh --code --skip-gates --sha "$PIN" > "$TMP/deploy-output" 2>&1 && exit 1
failures=0
if ! grep -q "receive-release $PIN code production $PIN:main" "$CAPTURE"; then
  echo 'FAIL: deploy did not ship the explicitly pinned detached commit'
  failures=$((failures+1))
fi
if grep -q 'UNEXPECTED NETWORK' "$CAPTURE"; then exit 1; fi
export CAPTURE="$TMP/default-deploy-calls"
MAIN_SHA=$(git rev-parse main)
bash scripts/deploy.sh --code --skip-gates > "$TMP/default-output" 2>&1 && exit 1
grep -q "receive-release $MAIN_SHA code production $MAIN_SHA:main" "$CAPTURE" || { echo 'FAIL: standalone deploy default changed'; exit 1; }
export CAPTURE="$TMP/mismatch-calls"
EZBIZI_RELEASE_SHA="$MAIN_SHA" bash scripts/deploy.sh --code --sha "$PIN" > "$TMP/mismatch-output" 2>&1 && exit 1
[[ ! -e "$CAPTURE" ]] || { echo 'FAIL: mismatched identity reached deployment'; exit 1; }
cd "$TMP/release"
tr -d '\r' < "$ROOT/scripts/release.sh" > scripts/release.sh
cat > scripts/deploy.sh <<'SH'
#!/usr/bin/env bash
printf 'deploy %s\n' "$*" >> "$CAPTURE"
exit 1
SH
cat > scripts/build-app.sh <<'SH'
#!/usr/bin/env bash
printf 'native build\n' >> "$CAPTURE"
exit 1
SH
chmod +x scripts/*.sh
echo 0.0.0001 > client/VERSION
git add . && git commit -qm release
PIN=$(git rev-parse HEAD)
export CAPTURE="$TMP/release-calls"
EZBIZI_PINNED=1 EZBIZI_RELEASE_SHA="$PIN" bash scripts/release.sh --no-bump --only web,windows > "$TMP/release-output" 2>&1 && exit 1
if ! grep -q -- "--sha $PIN" "$CAPTURE"; then
  echo 'FAIL: release did not pass its commit to deploy'
  failures=$((failures+1))
fi
if grep -q 'native build' "$CAPTURE"; then
  echo 'FAIL: native build continued after web failed'
  failures=$((failures+1))
fi
export CAPTURE="$TMP/release-mismatch-calls"
EZBIZI_PINNED=1 EZBIZI_RELEASE_SHA=0000000000000000000000000000000000000000 bash scripts/release.sh --no-bump --only web > "$TMP/release-mismatch-output" 2>&1 && exit 1
[[ ! -e "$CAPTURE" ]] || { echo 'FAIL: mismatched pinned checkout reached deployment'; exit 1; }
[[ $failures -eq 0 ]] || exit 1
echo 'PASS: explicit deploy SHA, release forwarding, API-before-native ordering'
