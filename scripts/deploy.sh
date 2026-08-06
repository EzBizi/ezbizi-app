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
echo "→ backend tests"
ssh "$REMOTE" 'cd /var/www/ezbizi && php8.4 artisan test --compact' || {
  echo "✗ backend tests failed — nothing deployed"; exit 1;
}

if [ "$CODE_ONLY" != "--code" ]; then
  echo "→ client analyze + tests"
  (cd client && flutter analyze && flutter test) || {
    echo "✗ client checks failed — nothing deployed"; exit 1;
  }

  echo "→ building web"
  # Same-origin by default, so the built app talks to whatever host serves it
  # and can never point at the wrong API.
  (cd client && flutter build web --release --base-href /app/)

  echo "→ uploading UI to shared directory"
  # --delete so removed assets don't linger; the shared dir holds only the build.
  rsync -az --delete client/build/web/ "$REMOTE:$SHARED/"
fi

echo "→ pushing code"
git push production main

echo "✓ done — https://app.ezbizi.com/app/"
