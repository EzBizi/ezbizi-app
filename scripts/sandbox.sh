#!/bin/bash
#
# Build a throwaway copy of the app on the box that CANNOT reach production.
#
# Usage:  scripts/sandbox.sh <name>          # create, print the path
#         scripts/sandbox.sh <name> --sync   # overlay your working tree, safely
#         scripts/sandbox.sh <name> --verify # re-assert isolation, then check it
#         scripts/sandbox.sh <name> --rm     # destroy it and its database
#
# ── Why this exists, twice over ──────────────────────────────────
#
# On 2026-08-06 a `db:wipe --env=testing` reached the production database,
# because the test tree sat inside /var/www/ezbizi beside the production .env
# and fell back to it. The response was to move the tree to /opt/ezbizi-test.
#
# On 2026-08-10 it happened again, differently. A sandbox was built by copying a
# RELEASE directory — which carries the production .env, because deploy puts it
# there. The recipe of the day said to point `.env.testing` and `phpunit.xml` at
# a scratch database, which protects `php artisan test` and nothing else:
# `php artisan tinker`, `migrate`, `db:seed` and every other artisan command
# read `.env`. A `roles` table was emptied on the live database and had to be
# restored from the deploy's pre-migration dump.
#
# Both incidents have the same shape: the guard was a CONVENTION somebody had to
# follow, and the dangerous default was one forgotten flag away. So this script
# is the guard instead. It gives the sandbox its own .env with its own database
# name, and there is no production credential anywhere in the tree for anything
# to fall back to.
#
# ── And the third one, which had not caused an incident yet ──────
#
# The guard above ran ONCE, at build time, and the very next thing everybody did
# was overlay their working tree on top of the sandbox. `phpunit.xml` is TRACKED,
# so the overlay puts the repo's copy back — and the repo's copy says
# `<env name="DB_DATABASE" value="ezbizi_testing"/>`, the SHARED test database.
# Its <env> block overrides .env.testing, so from that moment `php artisan test`
# in the sandbox was aimed somewhere else entirely.
#
# What saved it, on the day this was found, was a MySQL grant: `ezbizi_sandbox`
# is granted `sb\_%\_testing`.* only, so the run died on
# `[1044] Access denied ... to database 'ezbizi_testing'` rather than sharing
# another sandbox's schema. That is containment nobody can see from here, that
# this script does not create, and that one convenience GRANT would remove. It
# is also a rotten thing to debug: a wall of access-denied reads as "the sandbox
# is broken", not "your phpunit.xml is pointing at the wrong database".
#
# So the guard stopped being a build step. It is re-asserted and re-checked
# every time, `--sync` owns the overlay that used to be a recipe in a comment,
# and `--verify` is there for an overlay done by hand.
#
# ── What it guarantees ───────────────────────────────────────────
#
#   - .env, .env.testing and phpunit.xml ALL name the same scratch database —
#     rewritten, not merely inspected, every time this script runs
#   - that name always ends in "testing", which tests/TestCase.php requires
#   - the scratch database is created and granted before anything runs
#   - no APP_KEY reuse: a fresh one, so a sandbox cannot decrypt production data
#     even if a dump were restored into it by mistake
#   - `storage` is a real directory, never the symlink to live customer files
#     that a release carries
#
# It does NOT try to be clever about what you then run. Anything you like is
# safe, because there is nothing dangerous within reach.

set -euo pipefail

NAME=${1:-}
MODE=${2:-}

usage() {
  echo "usage: scripts/sandbox.sh <name> [--sync|--verify|--rm]" >&2
  exit 2
}

[ -n "$NAME" ] || usage

case $MODE in
  ''|--sync|--verify|--rm) ;;
  *) echo "unknown option: $MODE" >&2; usage ;;
esac

# The name becomes a directory this script `rm -rf`s, a database name spliced
# into raw SQL, and a word inside a command string handed to a remote shell.
# Refused rather than quoted and hoped for.
case $NAME in
  *[!a-z0-9_-]*)
    echo "name: lowercase letters, digits, '-' and '_' only" >&2; exit 2 ;;
esac

REMOTE=${EZBIZI_REMOTE:-app}
DIR="/opt/sb-${NAME}"
DB="sb_${NAME}_testing"          # the marker TestCase.php insists on

# The repository this script lives in, not whatever directory it was invoked
# from: --sync reads the working tree, and `cd` somewhere else must not silently
# overlay nothing.
REPO=$(cd "$(dirname "$0")/.." && pwd)

if [ "$MODE" = "--rm" ]; then
  ssh "$REMOTE" "sudo rm -rf '$DIR'; sudo mysql -e \"DROP DATABASE IF EXISTS \\\`$DB\\\`;\"" \
    && echo "removed $DIR and $DB"
  exit 0
fi

# ── the guard, as a thing that can be run again ──────────────────
#
# Everything below used to sit at the bottom of the build, run once, and be
# invalidated by the first overlay. It is a function now because the answer to
# "when should isolation be checked" is "every time", and because there must be
# exactly one implementation of it rather than one in the build and a warning in
# a comment.
#
# It ASSERTS before it verifies. A check that only complains leaves the operator
# to fix three files by hand and get it right; rewriting them is idempotent,
# costs nothing when they are already correct, and closes the window between
# "overlay finished" and "somebody remembered".
ensure_isolated() {
  ssh "$REMOTE" "sudo bash -s -- '$DIR' '$DB'" <<'GUARD'
set -euo pipefail
DIR=$1; DB=$2

[ -d "$DIR" ] || { echo "   no sandbox at $DIR — build it first" >&2; exit 1; }

repaired=

# phpunit.xml, which is the one that catches people out twice over: it is
# TRACKED, so every overlay of the working tree brings the repo's copy back, and
# its <env> block OVERRIDES .env.testing, so it alone decides where a test run
# points no matter how correct the dotfiles are.
value=$(sed -n 's/.*<env name="DB_DATABASE" value="\([^"]*\)".*/\1/p' "$DIR/phpunit.xml" | head -1)

if [ -z "$value" ]; then
  # No such line at all. .env.testing would decide, which is the right answer
  # today — but it is the right answer by omission, and one added line in the
  # repo would take it away silently. Written in, so it is decided here.
  sed -i "s|<php>|<php>\n        <env name=\"DB_DATABASE\" value=\"$DB\"/>|" "$DIR/phpunit.xml"
  repaired="$repaired phpunit.xml(added)"
elif [ "$value" != "$DB" ]; then
  sed -i "s|<env name=\"DB_DATABASE\" value=\"[^\"]*\"|<env name=\"DB_DATABASE\" value=\"$DB\"|" "$DIR/phpunit.xml"
  repaired="$repaired phpunit.xml(was $value)"
fi

# The dotfiles are gitignored, so an overlay cannot reach them — but `--verify`
# exists for overlays this script did not perform, and a hand-edited .env is
# exactly the kind of thing somebody does at 2am.
for f in .env .env.testing; do
  [ -f "$DIR/$f" ] || { echo "   REFUSING: $DIR/$f is missing." >&2; exit 1; }

  value=$(sed -n 's/^DB_DATABASE=//p' "$DIR/$f" | head -1)

  if [ "$value" != "$DB" ]; then
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$DB|" "$DIR/$f"
    repaired="$repaired $f(was ${value:-empty})"
  fi
done

# A release symlinks storage/ at /var/www/ezbizi/storage — real customer files.
# The build replaces it with a directory; this re-checks, because a `cp -a` or a
# careless `php artisan storage:link` puts it back.
if [ -L "$DIR/storage" ]; then
  rm -f "$DIR/storage"
  mkdir -p \
    "$DIR/storage/app/private" "$DIR/storage/app/public" \
    "$DIR/storage/framework/cache/data" "$DIR/storage/framework/sessions" \
    "$DIR/storage/framework/testing" "$DIR/storage/framework/views" \
    "$DIR/storage/logs"
  chown -R ubuntu:ubuntu "$DIR/storage"
  repaired="$repaired storage(was a symlink)"
fi

# A compiled config outranks every file above — that is the trap the build
# comment two screens down describes — so it goes, and is rebuilt on demand.
rm -f "$DIR/bootstrap/cache/config.php"

[ -z "$repaired" ] || echo "   re-asserted:$repaired"

# ── now check it, rather than believing the sed ──────────────────
#
# Two questions, because the old guard asked only the first and the first is
# blind to the failure this whole section exists for.

# 1. What does a BARE artisan resolve to — `tinker`, `migrate`, `db:seed`, the
#    commands that read .env and have no idea phpunit.xml exists.
bare=$(cd "$DIR" && sudo -u ubuntu php artisan tinker \
  --execute='echo DB::connection()->getDatabaseName();' 2>/dev/null | tr -d "[:space:]")

# 2. What does a TEST RUN resolve to. PHPUnit exports its <env> block into the
#    environment before Laravel boots, and Laravel's dotenv loader does not
#    overwrite a variable that is already set — so handing the parsed value to
#    artisan the same way reproduces the real precedence chain end to end,
#    .env.testing included, instead of trusting the file we just wrote.
phpunit_db=$(sed -n 's/.*<env name="DB_DATABASE" value="\([^"]*\)".*/\1/p' "$DIR/phpunit.xml" | head -1)
under_test=$(cd "$DIR" && sudo -u ubuntu env APP_ENV=testing DB_DATABASE="$phpunit_db" \
  php artisan tinker --execute='echo DB::connection()->getDatabaseName();' 2>/dev/null | tr -d "[:space:]")

fail=
[ "$bare" = "$DB" ]       || fail="$fail\n   a bare artisan resolves to '$bare', not '$DB'"
[ "$under_test" = "$DB" ] || fail="$fail\n   a test run resolves to '$under_test', not '$DB'"

if [ -n "$fail" ]; then
  printf "   REFUSING:$fail\n" >&2
  exit 1
fi

echo "   isolated — artisan and phpunit both resolve to $DB"
GUARD
}

if [ "$MODE" = "--verify" ]; then
  ensure_isolated
  exit 0
fi

# ── --sync: the overlay, owned by the script that has to survive it ──
#
# This was eleven lines of shell printed at the end of a build, with a comment
# explaining which file to exclude and why. That is the same shape as both
# incidents above: a guard somebody has to remember. It is a subcommand now, and
# it re-asserts isolation the moment it finishes.
if [ "$MODE" = "--sync" ]; then
  echo "→ syncing $REPO into $DIR"

  # Tracked and untracked-but-not-ignored files, minus two.
  #
  # phpunit.xml is still excluded — re-asserting after the fact would work, but
  # not sending it at all means the wrong value never exists on the box even
  # momentarily. storage/ is excluded because those paths are not readable from
  # a Windows checkout.
  #
  # The `[ -f ]` filter is not decoration: `git ls-files` lists a tracked file
  # that has been DELETED in the working tree, tar exits non-zero on the missing
  # path, and under `set -o pipefail` that used to take the whole sync down
  # after it had already copied most of the tree.
  git -C "$REPO" ls-files -co --exclude-standard \
    | grep -vE '^(phpunit[.]xml|storage/)' \
    | while IFS= read -r f; do [ -f "$REPO/$f" ] && printf '%s\n' "$f"; done \
    | tar -C "$REPO" -czf - -T - \
    | ssh "$REMOTE" "tar -xzf - -C '$DIR'"

  # Deletions and renames do not travel in a tar of what exists.
  git -C "$REPO" status --porcelain \
    | awk '/^R/ {print $2} /^.?D/ {print $2}' \
    | ssh "$REMOTE" "cd '$DIR' && xargs -r rm -f"

  ssh "$REMOTE" "cd '$DIR' && composer dump-autoload -q"

  ensure_isolated
  exit 0
fi

echo "→ building $DIR against $DB"

# QUOTED heredoc, with the two values it needs passed as positional arguments.
#
# It used to be unquoted (`<<REMOTE_SCRIPT`), which meant bash expanded the body
# HERE, before a byte was sent — and the prose inside it contains backticks:
# `php artisan tinker`, `ezbizi`, `storage`, `cp -a`. To an unquoted heredoc
# those are command substitutions. Running this script printed
# `ezbizi: command not found`, `storage: command not found` and
# `cp: missing file operand`, spliced that output into the remote script, and
# died under `set -e` at `migrate` — so the isolation guard at the bottom, the
# entire point of the file, never ran and nobody was told.
#
# Quoting the delimiter is the structural fix rather than escaping the four
# backticks that happen to be here today: nothing in the body is ever
# interpreted locally again, whatever anybody writes in a comment later.
ssh "$REMOTE" "sudo bash -s -- '$DIR' '$DB'" <<'REMOTE_SCRIPT'
set -euo pipefail
DIR=$1; DB=$2

rm -rf "$DIR"

# From the newest release for its vendor/ — then every file that could point at
# production is replaced below. The release's own .env is removed outright
# rather than edited, so a missed sed cannot leave a live credential behind.
SRC=$(ls -d /var/www/ezbizi-releases/* | tail -1)
cp -a "$SRC" "$DIR"
rm -f "$DIR/.env" "$DIR/.env.testing" "$DIR/.env.backup"

# THE CACHED CONFIG, WHICH IS THE WHOLE TRAP ONE LAYER DOWN.
#
# A release carries bootstrap/cache/config.php, compiled at deploy time with the
# production database name baked into it. Laravel reads that file and never
# looks at .env at all — so the first version of this script wrote a perfectly
# correct .env, and `php artisan tinker` still answered `ezbizi` with the live
# organization in it. Removing the .env is not enough; the answer has to be
# removed too, or the sandbox is exactly as dangerous as before and now looks
# safe.
rm -f "$DIR/bootstrap/cache/config.php" "$DIR/bootstrap/cache/routes-v7.php" "$DIR/bootstrap/cache/events.php"

# THE STORAGE SYMLINK, WHICH POINTED AT LIVE CUSTOMER FILES.
#
# A release has `storage` symlinked to /var/www/ezbizi/storage — that is how
# deploy keeps uploads across releases — and `cp -a` preserves symlinks. So every
# sandbox this script has ever built was reading and WRITING production storage:
# branding logos, profile pictures, onboarding attachments, signed contracts.
# A test that writes a file, or any cleanup that empties a directory, reached
# real customer data.
#
# The guard below asks which DATABASE a bare artisan resolves to. It never asked
# about the filesystem, and the filesystem is where the files actually are.
rm -rf "$DIR/storage"
mkdir -p \
  "$DIR/storage/app/private" "$DIR/storage/app/public" \
  "$DIR/storage/framework/cache/data" "$DIR/storage/framework/sessions" \
  "$DIR/storage/framework/testing" "$DIR/storage/framework/views" \
  "$DIR/storage/logs"

# The tree in a release is installed --no-dev, so phpunit is absent. Borrow the
# dev dependencies from the long-lived test sandbox rather than downloading.
#
# An `if`, not an `&&` chain: under `set -e` a false `[ -d ... ]` is the exit
# status of the whole list, so a missing test tree used to abort the build here
# instead of skipping one optional step.
if [ -d /opt/ezbizi-test/vendor ]; then
  rm -rf "$DIR/vendor"
  cp -a /opt/ezbizi-test/vendor "$DIR/vendor"
fi

mysql -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\`;
          GRANT ALL ON \`$DB\`.* TO 'ezbizi_sandbox'@'127.0.0.1';"

# The .env the sandbox will live on. phpunit.xml and .env.testing are settled by
# ensure_isolated() after this script returns — one implementation of "these
# three files name the same database", run here and after every overlay, rather
# than a sed here that the next tar undoes.
cat > "$DIR/.env" <<ENV
APP_NAME=EzBizi
APP_ENV=testing
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=ezbizi_sandbox
DB_PASSWORD=$(cat /root/.ezbizi-sandbox.pw)
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=log
ENV

chown -R ubuntu:ubuntu "$DIR"

# Its own key, generated BEFORE .env.testing is copied from .env — key:generate
# writes only .env, so copying first left the testing environment with an empty
# APP_KEY and 75 tests died on MissingAppKeyException.
#
# A fresh key, not production's: a sandbox that cannot decrypt a production dump
# is one that cannot quietly become a copy of production.
sudo -u ubuntu php "$DIR/artisan" key:generate --force -q
cp "$DIR/.env" "$DIR/.env.testing"
sudo -u ubuntu php "$DIR/artisan" migrate --force -q
REMOTE_SCRIPT

# Refuse to hand over a sandbox that can see anything it should not. Checked
# rather than assumed, because all three holes this script exists to close were
# a guard somebody believed was in place — and checked HERE, by the same
# function `--sync` and `--verify` call, so a build and an overlay are held to
# one standard instead of two.
if ! ensure_isolated; then
  ssh "$REMOTE" "sudo rm -rf '$DIR'; sudo mysql -e \"DROP DATABASE IF EXISTS \\\`$DB\\\`;\""
  echo "   destroyed $DIR — it could not be made safe" >&2
  exit 1
fi

cat <<DONE

  $DIR   (database $DB)

  Push your working tree and run anything you like — there is no production
  credential in that directory:

    scripts/sandbox.sh $NAME --sync
    ssh $REMOTE 'cd $DIR && php artisan test'

  --sync excludes phpunit.xml and re-asserts the database name across all three
  files afterwards, because phpunit.xml is TRACKED: an overlay done by hand puts
  the repo's copy back, its <env> block outranks .env.testing, and the run is
  then aimed at the shared ezbizi_testing database. If you do overlay by hand,
  run this before believing any number:

    scripts/sandbox.sh $NAME --verify

  When you are done:  scripts/sandbox.sh $NAME --rm
DONE
