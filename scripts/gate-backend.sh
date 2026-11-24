#!/usr/bin/env bash
#
# The backend half of the release gate. Runs ON THE BOX, from the root of a
# sandbox tree: deploy.sh's gate calls it, and so does the background full run
# (scripts/gate-background.sh).
#
#   scripts/gate-backend.sh full  <junit> <record>             every test, in parallel
#   scripts/gate-backend.sh tests <junit> <record> <file>...   only these test files
#
# A passing run prints EZBIZI_BACKEND_TESTS=<n> (scripts/phpunit-count.php).
#
# <record> is a path prefix in the gate-runs directory. A FAILED full run leaves
#   <record>.failed     the test files with a failure or an error
#   <record>.exercised  every PHP file those files load when they run alone
# and points <gate-runs>/full-failed at <record>. That is what lets the next cut
# re-run only what failed (deploy.sh, "re-run only what failed"). A passing full
# run removes the pointer, so the next cut runs everything again.
#
# Parallel, since 2026-11-24. The serial suite took 50-60 minutes on this box
# (1h40 when it was busy), and every one-test failure cost another hour. Laravel's
# ParallelRunner gives each worker its own database, <DB_DATABASE>_test_<n>, which
# the worker creates (the gate's DB user holds ALL on `ezbizi\_testing%`) and fills
# from database/schema/mysql-schema.sql (scripts/gate-template-db.php, run first).
#
# EZBIZI_GATE_PROCESSES, default 8, measured on the box 2026-11-24 with the live
# app running: 8 workers ran 7,476 tests in 5m28s; 12 took 5m18s while the summed
# test time rose from 41 to 58 minutes. The disk and MySQL are the limit, not the
# cores, and the live app shares both, so more workers only cost it.
set -uo pipefail

MODE=${1:?full or tests} JUNIT=${2:?junit path} RECORD=${3:?record prefix}
shift 3
PROCESSES=${EZBIZI_GATE_PROCESSES:-8}
ROOT=$PWD
POINTER="$(dirname "$RECORD")/full-failed"

rm -f "$JUNIT"
# What the run must account for: the report is held to this count below, so a
# worker that dies, or a file the runner never reached, cannot pass as green.
LISTED=$(php8.4 -d memory_limit=1G vendor/bin/phpunit --list-tests "$@" | grep -c '^ - ')
[ "${LISTED:-0}" -gt 0 ] || { echo "✗ phpunit --list-tests listed no tests"; exit 1; }
case "$MODE" in
  full)
    LARAVEL_PARALLEL_TESTING=1 php8.4 -d memory_limit=1G vendor/bin/paratest \
      --runner='\Illuminate\Testing\ParallelRunner' --processes="$PROCESSES" \
      --passthru-php="'-d' 'memory_limit=1G'" --colors=never --log-junit "$JUNIT"
    ;;
  tests)
    [ $# -gt 0 ] || { echo "gate-backend.sh tests: no test files given" >&2; exit 2; }
    php8.4 -d memory_limit=1G vendor/bin/phpunit --colors=never --log-junit "$JUNIT" "$@"
    ;;
  *) echo "gate-backend.sh: unknown mode $MODE" >&2; exit 2 ;;
esac
RC=$?

if [ "$RC" -eq 0 ]; then
  # phpunit-count.php refuses a report with any failure, error, no tests, or a
  # total other than what --list-tests listed.
  php8.4 scripts/phpunit-count.php "$JUNIT" "$LISTED" || { echo "✗ the JUnit report does not show a clean, complete run"; exit 1; }
  [ "$MODE" = full ] && rm -f "$POINTER"
  exit 0
fi

[ "$MODE" = full ] || exit "$RC"   # a re-run's failure keeps the full run it was based on

# ── a failed full run: record what failed, and what that code is ──
rm -f "$POINTER" "$RECORD.failed" "$RECORD.exercised" "$RECORD.exercised.raw"
if ! php8.4 scripts/gate-failed-tests.php "$JUNIT" "$ROOT" > "$RECORD.failed"; then
  rm -f "$RECORD.failed"
  echo "(the failures could not all be placed in test files: the next cut runs the full suite)"
  exit "$RC"
fi
echo
echo "→ failed test files, re-run alone to record the code they exercise:"
sed 's/^/     /' "$RECORD.failed"
# Alone, in one process, on the base database: none of the parallel workers use it.
# shellcheck disable=SC2046  # one test file per line, no spaces
if EZBIZI_INCLUDED_FILES="$RECORD.exercised.raw" php8.4 -d memory_limit=1G \
    -d auto_prepend_file="$ROOT/scripts/gate-included-files.php" \
    vendor/bin/phpunit --colors=never $(cat "$RECORD.failed") > "$RECORD.alone.txt" 2>&1; then
  echo "   they PASS alone: the failure depends on what ran beside them (order, shared state), not only on the code"
else
  echo "   they fail alone too ($RECORD.alone.txt)"
fi
if [ -s "$RECORD.exercised.raw" ]; then
  sed -n "s|^$ROOT/||p" "$RECORD.exercised.raw" | sort -u > "$RECORD.exercised"
  basename "$RECORD" > "$POINTER"
else
  echo "(nothing recorded what they load: the next cut runs the full suite)"
fi
rm -f "$RECORD.exercised.raw"
exit "$RC"
