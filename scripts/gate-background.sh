#!/usr/bin/env bash
#
# bash gate-background.sh <gate-runs dir> <run>
#
# The FULL backend suite for a release that shipped on a re-run of only what had
# failed (deploy.sh, "re-run only what failed"). The gate starts it, detached,
# once the re-run passed; it runs on the box against the same tree, which the
# gate left at <gate-runs>/<run>.tar.
#
# Its own sandbox (/opt/ezbizi-test/.background) and its own databases
# (ezbizi_testing_bg, ezbizi_testing_bg_test_<n>), so the next cut's gate never
# waits on it and the two never share a table. Lower priority than the gate and
# the live app (nice), and fewer workers.
#
#   <run>-full.log     the run
#   <run>-full.status  its exit status, written last
#
# A red result is a ✗ server_tests line on that release in the Releases screen
# (release-status.jsonl), and deploy.sh prints it at the start of the next cut.
# A failure is also recorded like any failed full run (scripts/gate-backend.sh),
# so the next cut can re-run just that if its change qualifies.
set -uo pipefail

RUNS=${1:?gate-runs dir} RUN=${2:?run}
SHA=${RUN%%-*}
GATE_SANDBOX=${EZBIZI_GATE_SANDBOX:-/opt/ezbizi-test}   # the gate's own sandbox: its .env.testing
SANDBOX=$GATE_SANDBOX/.background
RELEASE_STATUS_FILE=${RELEASE_STATUS_FILE:-/var/www/ezbizi/release-status.jsonl}
exec > "$RUNS/$RUN-full.log" 2>&1
trap 'rc=$?; echo "$rc" > "$RUNS/$RUN-full.status.tmp"; mv "$RUNS/$RUN-full.status.tmp" "$RUNS/$RUN-full.status"' EXIT

echo "full suite, in the background, for $SHA (it shipped on a re-run of what had failed)"
# One background run at a time; a newer one waits for the older.
exec 8>"$GATE_SANDBOX/.background.lock"
flock -w 14400 8 || { echo "✗ another background run held the lock for 4 hours"; exit 1; }

mkdir -p "$SANDBOX"
cd "$SANDBOX" || exit 1
# Everything but vendor (composer install below brings it up to date).
find . -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +
tar -x -f "$RUNS/$RUN.tar" || exit 1
rm -f "$RUNS/$RUN.tar"
cp "$GATE_SANDBOX/.env.testing" .env.testing
composer install --no-interaction -q || exit 1

# Laravel creates each worker's database while connected to this one, so it
# must exist; it is created from the gate's own base database.
APP_ENV=testing DB_DATABASE=ezbizi_testing php8.4 artisan tinker \
  --execute='DB::statement("CREATE DATABASE IF NOT EXISTS ezbizi_testing_bg");' < /dev/null || exit 1
export DB_DATABASE=ezbizi_testing_bg
APP_ENV=testing php8.4 -d memory_limit=1G scripts/gate-template-db.php || exit 1
EZBIZI_GATE_PROCESSES=${EZBIZI_GATE_BACKGROUND_PROCESSES:-6} \
  nice -n 10 bash scripts/gate-backend.sh full "$SANDBOX/backend-junit.xml" "$RUNS/$RUN-full"
RC=$?
[ "$RC" -eq 0 ] && { echo "✓ full suite green for $SHA"; exit 0; }

# The release already shipped: say so where the desk and the Releases screen look.
FAILED=$(tr '\n' ' ' < "$RUNS/$RUN-full.failed" 2>/dev/null)
MESSAGE="✗ background full suite FAILED for this release (it shipped on a re-run): ${FAILED:-see the log} — $RUNS/$RUN-full.log"
echo "$MESSAGE"
php8.4 -r 'echo json_encode(["version" => trim((string) @file_get_contents("client/VERSION")), "sha" => $argv[1],
  "stage" => "server_tests", "percent" => 0, "state" => "failed", "message" => $argv[2],
  "at" => gmdate("Y-m-d\TH:i:s\Z")], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;' \
  "$SHA" "$MESSAGE" >> "$RELEASE_STATUS_FILE" || true
exit "$RC"
