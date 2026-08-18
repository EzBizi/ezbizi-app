#!/usr/bin/env bash
#
# Build and deploy EzBizi.
#
#   ./scripts/deploy.sh          code + UI
#   ./scripts/deploy.sh --code   code only (skip the Flutter build)
#
# Two halves, because they travel differently:
#
#   CODE  goes through git — versioned, atomic, rollback-able.
#   UI    is a 36 MB derived artifact, so it is built here and uploaded to a
#         shared directory on the server. Committing it would add a fresh
#         36 MB blob to history on every rebuild, permanently.
#
set -euo pipefail

REMOTE=app                       # ~/.ssh/config alias
SHARED=/var/www/ezbizi/public-app
CODE_ONLY=${1:-}

cd "$(dirname "$0")/.."

# ── tests before anything ships ──────────────────────────────────
# There is no CI yet (see DEPLOY.md), so this is the gate. Deploying a red
# build is the one thing the pipeline cannot undo for you.
#
# The working tree is synced to the box FIRST. Without that, the gate ran
# against whatever was last left in the sandbox — reporting green for code
# that was never tested, which is worse than no gate at all. Releases install
# --no-dev and have no phpunit, so this sandbox (full vendor, own .env, own
# test database) is where tests run.
#
# `git archive main` — the exact tree about to ship, not the working directory
# and not whatever was last left on the box. The old gate ran against a stale
# sandbox and reported green for code that was never tested, which is worse than
# no gate at all.
#
# Commit before deploying: this tests main, not your uncommitted edits.
echo "→ testing the tree about to ship"
# Captured, not just streamed. STATUS.md's test count was retyped by hand and was
# wrong three times in one day; the number is right here, in the run that gates
# the deploy, so it is taken from there rather than remembered.
BACKEND_OUT=$(git archive main | ssh "$REMOTE" '
  set -e
  # /opt, not /var/www. The sandbox holds dev dependencies and a full Laravel
  # tree; sitting it beside the production .env is what let `--env=testing` fall
  # back to production and wipe it. There is no .env here at all.
  cd /opt/ezbizi-test
  # Removed files must disappear too, or a deleted class keeps passing.
  #
  # `client` is on this list, and was not until a test went looking. The
  # reasoning above always applied to it — `tar -x` overwrites what it carries
  # and deletes nothing — but the list was written when nothing server-side read
  # the Dart source. Two tests do now (ClientModuleScreensTest and
  # ClientCallsRealRoutesTest), and the first run of the second one failed here
  # while passing everywhere else: it had found seven dead API calls in
  # `client/lib/screens/workflows_screen.dart`, a file deleted from the repo in
  # the workflows-to-automations rename and still sitting in this directory from
  # an extraction on 10 August.
  #
  # A ghost that only exists in the gate is the worst place for one. It fails
  # honest work and passes nothing useful, and the failure reads as "your change
  # is broken" rather than "this directory is stale".
  rm -rf app tests routes config database bootstrap resources client
  tar -x
  # Releases install --no-dev and have no phpunit; this sandbox keeps dev deps,
  # its own .env and its own test database.
  composer install --no-interaction -q
  php8.4 artisan test --compact
' 2>&1) || { echo "$BACKEND_OUT"; echo "✗ backend tests failed — nothing deployed"; exit 1; }
echo "$BACKEND_OUT" | tail -3
BACKEND_TESTS=$(echo "$BACKEND_OUT" | grep -oE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+')

if [ "$CODE_ONLY" != "--code" ]; then
  echo "→ client analyze + tests"
  CLIENT_OUT=$( (cd client && flutter analyze && flutter test) 2>&1 ) || {
    echo "$CLIENT_OUT"; echo "✗ client checks failed — nothing deployed"; exit 1;
  }
  echo "$CLIENT_OUT" | tail -2
  CLIENT_TESTS=$(echo "$CLIENT_OUT" | grep -oE '\+[0-9]+' | tail -1 | tr -d '+')

  echo "→ building web"
  # Same-origin by default, so the built app talks to whatever host serves it
  # and can never point at the wrong API.
  # No --base-href: the default already is "/", and the app IS the site
  # (app.ezbizi.com), not a subdirectory. Passing it explicitly is worse than
  # useless here — Git Bash on Windows rewrites a lone "/" into a drive path and
  # the build fails with a base-href of "C:/Program Files/Git/".
  # One script, so a build can never ship without its version stamped in.
  scripts/build-web.sh

fi

# Code BEFORE the UI, deliberately. The UI is uploaded straight into the live
# shared directory with no staging step, so shipping it first would put a new
# client in front of the old API for however long the push takes — and if the
# push then fails, permanently.
echo "→ pushing code"
git push production main

if [ "$CODE_ONLY" != "--code" ]; then
  echo "→ uploading UI to shared directory"

  # tar over ssh rather than rsync, for two reasons.
  #
  # It is there. rsync is not on a stock Windows Git Bash, and the deploy that
  # found that out had already pushed the backend — leaving a new API behind an
  # old UI, which is the exact state the code half is carefully ordered to avoid.
  # tar ships with every shell this runs in.
  #
  # And it swaps rather than syncs. rsync --delete edits the live directory in
  # place, so for the length of an upload the site is a mix of two builds — and
  # Flutter's assets are content-addressed, so half of them 404. Unpacking beside
  # it and renaming makes the change one operation, and leaves the previous build
  # next door to rename back to.
  tar -cz -C client/build/web . | ssh "$REMOTE" "
    set -e
    rm -rf '$SHARED.new' '$SHARED.old'
    mkdir -p '$SHARED.new'
    tar -xz -C '$SHARED.new'
    mv '$SHARED' '$SHARED.old'
    mv '$SHARED.new' '$SHARED'
  "
fi

# The four volatile rows of STATUS.md, from the things themselves. This is the
# one moment all of them are known at once — both suites have just run and the
# release is live — and the reason it is automated at all is that every one of
# them was hand-written, correct at the time, and wrong within hours.
echo "→ refreshing the STATUS numbers"
scripts/status-numbers.sh "${BACKEND_TESTS:-}" "${CLIENT_TESTS:-}" || true

# After both halves of the release, not between them. The watchdog is a
# snapshot in /usr/local/bin, not a symlink into the release — so a fix that
# only lives in deploy/ stays dead on the box until something copies it.
# First install is still the paste in DEPLOY.md (missing dest is a no-op, so
# a box that never had the units does not get them). After that, copy only
# when md5 differs.
#
# This step is housekeeping. A sudo prompt, a missing source, or a
# daemon-reload failure must not abort a deploy that already pushed the API
# — that would leave a new backend behind an old UI, the state the code-then-
# UI order exists to avoid. Keep set -e inside the remote shell so a bad
# step still stops *itself*; swallow the ssh exit so the deploy does not.
#
# This SSH is the app box. watch-peer-app.sh lives on 32.194.222.151 and is
# not updated here. The systemd drop-in that supplies EZBIZI_PEER_URL is
# local config and is never overwritten.
echo "→ refreshing the peer watchdog if the live copy drifted"
ssh "$REMOTE" 'set -euo pipefail
ROOT=$(readlink -f /var/www/ezbizi-current 2>/dev/null || readlink -f /var/www/ezbizi)
updated=0
refresh() {
  src=$1 dest=$2 mode=$3
  if [ ! -f "$dest" ]; then
    return 0
  fi
  if [ ! -f "$src" ]; then
    echo "missing $src" >&2
    return 1
  fi
  a=$(md5sum "$src"); a=${a%% *}
  b=$(md5sum "$dest"); b=${b%% *}
  if [ "$a" = "$b" ]; then
    return 0
  fi
  sudo install -m "$mode" "$src" "$dest"
  echo "updated $dest"
  updated=1
}
refresh "$ROOT/deploy/watch-peer-websites.sh" /usr/local/bin/ezbizi-watch-peer-websites.sh 0755
refresh "$ROOT/deploy/ezbizi-watch-peer.service" /etc/systemd/system/ezbizi-watch-peer.service 0644
refresh "$ROOT/deploy/ezbizi-watch-peer.timer" /etc/systemd/system/ezbizi-watch-peer.timer 0644
refresh "$ROOT/deploy/ezbizi-watch-peer-failed.service" /etc/systemd/system/ezbizi-watch-peer-failed.service 0644
if [ "$updated" -eq 1 ]; then
  sudo systemctl daemon-reload
else
  echo "peer watchdog unchanged"
fi
' || echo "warning: peer watchdog refresh failed; box may be on an older copy" >&2

echo "✓ done — https://app.ezbizi.com"
