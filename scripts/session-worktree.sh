#!/usr/bin/env bash
#
# Give this session its own working tree and its own index.
#
#   ./scripts/session-worktree.sh phone
#   ./scripts/session-worktree.sh phone --with-vendor      # also composer install
#
# ── why ──────────────────────────────────────────────────────────
#
# On 2026-08-28 eighteen sessions shared one working directory and one
# `.git/index`. Four separate pieces of finished work were silently reverted —
# one of them three times — and every one was caught by a person noticing, not
# by anything failing. Nothing goes red when a stale blob overwrites a good one:
# there is no conflict, because there was no merge.
#
# A worktree gives you your own index and your own files. Nobody can sweep your
# staging into their commit, nobody's stale blob can revert your work, and when
# two sessions genuinely change the same thing it surfaces as a CONFLICT at
# merge time instead of one side quietly winning.
#
# The objects and refs stay shared, so this costs no network and no history
# duplication. It is not a clone.
#
# ── the workflow ─────────────────────────────────────────────────
#
#   1. Run this once. You get H:/Development/wt/<module> on branch
#      session/<module>.
#   2. Work and commit THERE. Your index is yours; a bare `git commit` is safe
#      again, though the post-commit check is still worth keeping.
#   3. Pull main in when you want it:  git merge main
#   4. Hand the deploy session your branch name and SHAs. They merge to main
#      and deploy.
#
# You cannot push into main yourself and that is deliberate — git refuses to
# push into a branch checked out elsewhere, and main is checked out in the
# primary tree. The merge happens in one place, by the session that owns
# deploys, which is where a conflict should be seen.
#
# ── what a worktree does not give you ────────────────────────────
#
# Everything gitignored is absent: vendor/ (184M), client/.dart_tool, build
# outputs. Pass --with-vendor for a composer install, or run it yourself later.
# You do NOT need vendor unless you run PHP tests.
#
# NEVER symlink or junction vendor from another tree. `git worktree remove`
# follows a junction and deletes through it — that is how this repo lost its
# vendor/ this morning, taking every session's tests down with it.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO="$(pwd)"

MODULE="${1:-}"
[ -z "$MODULE" ] && { echo "usage: $0 <module> [--with-vendor]" >&2; exit 1; }
MODULE="$(echo "$MODULE" | tr '[:upper:] ' '[:lower:]-')"

ROOT="$(cd .. && pwd)/wt"
DEST="$ROOT/$MODULE"
BRANCH="session/$MODULE"

if [ -e "$DEST" ]; then
  echo "✗ $DEST already exists. Use it, or remove it with:" >&2
  echo "    git worktree remove '$DEST'" >&2
  exit 1
fi

mkdir -p "$ROOT"

# Branch from main as it is NOW. Resolved once, here, so the worktree starts
# from a commit rather than from whatever main becomes mid-setup.
BASE="$(git rev-parse main)"
echo "→ branching $BRANCH from $(git rev-parse --short "$BASE")"

if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
  git worktree add "$DEST" "$BRANCH"
else
  git worktree add -b "$BRANCH" "$DEST" "$BASE"
fi

# .env is gitignored, so a fresh worktree has none. The symptom is "No
# application encryption key has been specified", which reads like a broken
# application rather than a missing file.
#
# THE AXIS IS ENCRYPTION, NOT THE DATABASE, and getting that wrong sends people
# hunting the wrong thing. Measured: 19 DB-backed feature tests — provisioning
# orgs, companies, franchises, POSTing through the API — pass with no .env at
# all, because the connection is satisfied by config/database.php defaults plus
# whatever DB_DATABASE you pass in front of the command. What fails is anything
# that actually encrypts: sessions, cookies, Crypt, an encrypted cast.
#
# So a green run in an unprovisioned worktree does NOT prove the worktree is
# correctly set up. It proves that suite does not encrypt anything.
#
# Copied rather than linked, for the same reason vendor is: nothing in a
# worktree should point back into another tree.
if [ -f "$REPO/.env" ]; then
  cp "$REPO/.env" "$DEST/.env"
  echo "→ copied .env"
fi
[ -f "$DEST/.env" ] || echo "  ⚠ no .env here — anything that encrypts will fail; DB tests may still pass, so a green run does not prove this tree is provisioned" >&2

if [ "${2:-}" = "--with-vendor" ]; then
  echo "→ composer install (its own vendor, never a link to another tree)"
  # `composer` is not on PATH on this machine; the repo ships composer.phar and
  # every documented invocation is `php composer.phar`. Prefer a real composer
  # where one exists, so this keeps working on a machine that has one.
  #
  # An ABSOLUTE path, because $DEST is outside the repo and a relative
  # composer.phar will not resolve from there.
  #
  # And `|| true`: the worktree is already created by this point, so a
  # dependency failure must not abort and leave a half-made tree that the
  # existence guard above then refuses to recreate. That is three confusing
  # signals from one missing package. Vendor is recoverable; the worktree
  # being un-creatable is not, without a manual `git worktree remove`.
  if command -v composer >/dev/null 2>&1; then
    (cd "$DEST" && composer install --no-interaction -q) || VENDOR_FAILED=1
  elif [ -f "$REPO/composer.phar" ]; then
    (cd "$DEST" && php "$REPO/composer.phar" install --no-interaction -q) || VENDOR_FAILED=1
  else
    echo "  ⚠ no composer and no composer.phar — skipping vendor" >&2
    VENDOR_FAILED=1
  fi
  # The artefact, not the exit status. `composer install` returning 0 and
  # vendor/autoload.php existing are DIFFERENT CLAIMS, and this script exists
  # because of a day spent on exactly that distinction. The first version of it
  # reported success while --with-vendor had done nothing at all.
  [ -f "$DEST/vendor/autoload.php" ] || VENDOR_FAILED=1

  if [ "${VENDOR_FAILED:-0}" = "1" ]; then
    echo "  ⚠ vendor install FAILED. The worktree is fine and yours; only vendor" >&2
    echo "    is missing. Fix it in the worktree with:  php \"$REPO/composer.phar\" install" >&2
    echo "    (you only need vendor to run PHP tests — a client-only session can ignore this)" >&2
  fi
fi

cat <<EOF

✓ your tree:   $DEST
  your branch: $BRANCH

  cd "$DEST"

  Work and commit there. Your index is your own.
  Pull main in with:  git merge main
  Hand the deploy session your branch and SHAs; they merge and deploy.

  THE DATABASE IS NOT ISOLATED BY THIS. A worktree gives you your own files
  and your own index; it does NOT give you your own schema. phpunit.xml:27
  pins DB_DATABASE=ezbizi_testing, and a .env cannot beat it — only a real
  shell variable can, because that <env> has no force="true". So a perfectly
  provisioned worktree still runs against the SHARED schema the moment anyone
  types \`php artisan test\` without the prefix, and RefreshDatabase drops it
  under whoever else is mid-run.

  THE PREFIX IS NOT OPTIONAL:

    DB_DATABASE=ezbizi_testing_$MODULE php artisan test
    DB_DATABASE=ezbizi_testing_$MODULE vendor/bin/phpunit

  AND IT ONLY WORKS FOR THOSE TWO. Anything else needs DB_CONNECTION as well:

    DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_$MODULE php artisan migrate

  Because the prefix above is not doing what it looks like it is doing. It
  works only because phpunit.xml:26 pins DB_CONNECTION=mysql, and phpunit sets
  that before Laravel loads .env — which is immutable, so .env cannot win.
  Outside the test runner nothing pins it, .env says DB_CONNECTION=sqlite, and
  Laravel then reads DB_DATABASE as a FILE PATH:

    php artisan test                  -> mysql,  schema ezbizi_testing_$MODULE
    php artisan migrate               -> sqlite, FILE ./ezbizi_testing_$MODULE
    DB_CONNECTION=mysql ... migrate   -> mysql,  schema ezbizi_testing_$MODULE

  The middle one exits 0 and prints a full list of migrations that ran. It
  created an empty SQLite database in the repo root and touched no MySQL at
  all. Not hypothetical: ezbizi_testing_twilio, 925 KB, is sitting in the repo
  root right now from exactly that command.
EOF
