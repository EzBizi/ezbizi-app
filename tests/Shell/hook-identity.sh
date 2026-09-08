#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/ezbizi-hook-test.XXXXXX")
[[ "$TMP" == */ezbizi-hook-test.* ]] || exit 2
trap 'rm -rf -- "$TMP"' EXIT
mkdir -p "$TMP/repo" "$TMP/releases" "$TMP/bin"
git -C "$TMP/repo" init -q -b main
git -C "$TMP/repo" config user.name Test
git -C "$TMP/repo" config user.email test@example.test
echo first > "$TMP/repo/identity"
git -C "$TMP/repo" add . && git -C "$TMP/repo" commit -qm first
PIN=$(git -C "$TMP/repo" rev-parse HEAD)
echo second > "$TMP/repo/identity"
git -C "$TMP/repo" commit -qam second
git clone -q --bare "$TMP/repo" "$TMP/bare.git"
# Run the actual hook through extraction, then stop before any production work.
sed '/echo "→ carrying over environment + storage"/,$d' "$ROOT/deploy/post-receive" \
  | sed -e 's/\r$//' -e "s|/var/repos/ezbizi.git|$TMP/bare.git|g" \
      -e "s|/var/repos/ezbizi-deploy.lock|$TMP/lock|g" \
      -e "s|RELEASES=/var/www/ezbizi-releases|RELEASES=$TMP/releases|" > "$TMP/hook"
printf '\ndone\n' >> "$TMP/hook"
printf '#!/usr/bin/env bash\n: >&9\n' > "$TMP/bin/flock"
chmod +x "$TMP/bin/flock"
for attempt in 1 2; do
  printf '%s %s refs/heads/main\n' "$PIN" "$PIN" | PATH="$TMP/bin:$PATH" bash "$TMP/hook"
done
count=0
for dir in "$TMP/releases/"*; do
  [[ "$(cat "$dir/identity")" == first ]]
  [[ "$(cat "$dir/.ezbizi-release-sha")" == "$PIN" ]]
  [[ "$(stat -c %a "$dir")" == 755 ]]
  count=$((count+1))
done
[[ "$count" == 2 ]]
[[ ! -e "$TMP/bare.git/index" ]]
# Real local Git transport: a receiver failure after protocol success must fail push.
cat > "$TMP/receiver-fails" <<'SH'
#!/usr/bin/env bash
git-receive-pack "$@"
exit 37
SH
chmod +x "$TMP/receiver-fails"
echo third > "$TMP/repo/identity"
git -C "$TMP/repo" commit -qam third
if git -C "$TMP/repo" push --receive-pack="bash $TMP/receiver-fails" "$TMP/bare.git" main >/dev/null 2>&1; then
  echo 'FAIL: receiver failure did not fail Git push'; exit 1
fi
echo 'PASS: hook archives received SHA despite advanced branch, unique targets, no shared index'
