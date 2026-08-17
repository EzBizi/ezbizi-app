#!/bin/bash
#
# Rewrite the volatile rows of EZBIZI-STATUS.md from the things themselves.
#
# ── Why this exists ──────────────────────────────────────────────
#
# STATUS.md declares itself the tie-breaker: "if something contradicts another
# document, this one is right." It drifted three times in one day.
#
#   - It said "Zero organizations. Nothing real has been created yet" while
#     production held an organization, four companies and thirteen franchises.
#     Every "do it now while it is free" argument in a review rested on that
#     sentence, including the ones that turned into work.
#   - It said 317 tests, then 401, while the number was 482.
#   - It named a client version two releases behind, on the row whose entire
#     purpose is telling you whether you are looking at a stale build.
#
# Each time it was written by hand, correctly, and was wrong within hours. The
# answer is not more care. A number that has to be retyped to stay true will
# stop being true, so these four rows are now GENERATED — from the test runner,
# from client/VERSION, and from a count against the live database.
#
# Everything else in that file is prose and stays hand-written. This only owns
# what is between the markers.
#
# ── Where it runs from ───────────────────────────────────────────
#
# The end of scripts/deploy.sh, which is the moment all four can change and the
# only moment they are all known at once. Deploy already runs both suites; this
# takes the counts it was throwing away rather than running anything twice.
#
# Safe to run by hand: pass the counts, or omit them to leave those rows alone.

set -uo pipefail

# In the repo since 2026-08-17. It used to live in H:/AWS/Application, which is
# the folder for the SERVER — its credentials and its key — and is not a git
# repository, so the architecture brief every § in the code points at had no
# history at all. The override is kept for anyone running this by hand.
STATUS=${EZBIZI_STATUS:-"$(dirname "$0")/../docs/EZBIZI-STATUS.md"}
REMOTE=${EZBIZI_REMOTE:-app}

BACKEND_TESTS=${1:-}
CLIENT_TESTS=${2:-}

if [ ! -f "$STATUS" ]; then
  echo "  (no STATUS.md at $STATUS — skipping)"
  exit 0
fi

VERSION=$(tr -d '[:space:]' < "$(dirname "$0")/../client/VERSION" 2>/dev/null)

# One round trip, one row of counts. Anything unreachable leaves the row saying
# so rather than saying zero — "0 organizations" is exactly the false statement
# this script exists to stop, and it is what a failed query looks like.
COUNTS=$(ssh -o BatchMode=yes "$REMOTE" '
  R=$(ls -d /var/www/ezbizi-releases/* 2>/dev/null | tail -1) || exit 1
  cd "$R" || exit 1
  sudo -u ubuntu php artisan tinker --execute="
    \$n = fn (\$t) => DB::table(\$t)->count();
    printf(\"%d|%d|%d|%d|%d|%d|%d\n\",
      \$n(\"organizations\"), \$n(\"companies\"), \$n(\"franchises\"),
      \$n(\"users\"), \$n(\"customers\"), \$n(\"jobs\"), \$n(\"permissions\"));
  " 2>/dev/null | tr -d "\r" | grep -E "^[0-9]+\|"
' 2>/dev/null)

if [ -n "$COUNTS" ]; then
  IFS='|' read -r ORGS COS FRS USERS CUSTS JOBS PERMS <<< "$COUNTS"
  DATA_ROW="| Production data | **${ORGS} organization(s) · ${COS} companies · ${FRS} franchises · ${USERS} users · ${CUSTS} customers · ${JOBS} jobs.** Counted $(date +%F) against the live database by \`scripts/status-numbers.sh\`, not typed. |"
  PERM_ROW="| Permissions in production | **${PERMS} rows.** \`ezbizi:sync-permissions\` runs on every deploy, between the migration and the caches. |"
else
  DATA_ROW="| Production data | **Could not be counted** on $(date +%F) — the box did not answer. Treat the previous figure as unverified rather than current. |"
  PERM_ROW="| Permissions in production | **Unknown** — see above. |"
fi

TESTS_ROW="| Tests | ${BACKEND_TESTS:-?} backend · ${CLIENT_TESTS:-?} client. Both gated on deploy: nothing ships if either is red. |"
VERSION_ROW="| Client version | \`v${VERSION}\` — shown bottom-left in the app and on sign-in, so a stale cache is visible rather than guessed at. |"

python3 - "$STATUS" "$TESTS_ROW" "$VERSION_ROW" "$DATA_ROW" "$PERM_ROW" "$(date +%F)" <<'PY'
import re, sys

path, tests, version, data, perms, today = sys.argv[1:7]
s = open(path, encoding='utf-8').read()

block = '\n'.join([tests, version, data, perms])

# Only what is between the markers. Everything else in the file is prose.
new, n = re.subn(
    r'(<!-- numbers:start -->\n).*?(\n<!-- numbers:end -->)',
    lambda m: m.group(1) + block + m.group(2),
    s, flags=re.S)

if n == 0:
    print('  (no numbers:start/end markers in STATUS.md — nothing rewritten)')
    sys.exit(0)

new = re.sub(r'^\*\*\d{4}-\d{2}-\d{2}\.\*\* One page\.',
             f'**{today}.** One page.', new, count=1, flags=re.M)

open(path, 'w', encoding='utf-8', newline='\n').write(new)
print(f'  STATUS.md numbers refreshed ({today})')
PY
