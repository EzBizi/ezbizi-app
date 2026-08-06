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
# against whatever was last left in /var/www/ezbizi — reporting green for code
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
git archive main | ssh "$REMOTE" '
  set -e
  cd /var/www/ezbizi
  # Removed files must disappear too, or a deleted class keeps passing.
  rm -rf app tests routes config database bootstrap resources
  tar -x
  # Releases install --no-dev and have no phpunit; this sandbox keeps dev deps,
  # its own .env and its own test database.
  composer install --no-interaction -q
  php8.4 artisan test --compact
' || { echo "✗ backend tests failed — nothing deployed"; exit 1; }

if [ "$CODE_ONLY" != "--code" ]; then
  echo "→ client analyze + tests"
  (cd client && flutter analyze && flutter test) || {
    echo "✗ client checks failed — nothing deployed"; exit 1;
  }

  echo "→ building web"
  # Same-origin by default, so the built app talks to whatever host serves it
  # and can never point at the wrong API.
  # No --base-href: the default already is "/", and the app IS the site
  # (app.ezbizi.com), not a subdirectory. Passing it explicitly is worse than
  # useless here — Git Bash on Windows rewrites a lone "/" into a drive path and
  # the build fails with a base-href of "C:/Program Files/Git/".
  (cd client && flutter build web --release)

fi

# Code BEFORE the UI, deliberately. The UI is uploaded straight into the live
# shared directory with no staging step, so shipping it first would put a new
# client in front of the old API for however long the push takes — and if the
# push then fails, permanently.
echo "→ pushing code"
git push production main

if [ "$CODE_ONLY" != "--code" ]; then
  echo "→ uploading UI to shared directory"
  # --delete so removed assets don't linger; the shared dir holds only the build.
  rsync -az --delete client/build/web/ "$REMOTE:$SHARED/"
fi

echo "✓ done — https://app.ezbizi.com"
