#!/bin/bash
#
# Build a throwaway copy of the app on the box that CANNOT reach production.
#
# Usage:  scripts/sandbox.sh <name>        # create, print the path
#         scripts/sandbox.sh <name> --rm   # destroy it and its database
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
# ── What it guarantees ───────────────────────────────────────────
#
#   - .env, .env.testing and phpunit.xml ALL name the same scratch database
#   - that name always ends in "testing", which tests/TestCase.php requires
#   - the scratch database is created and granted before anything runs
#   - no APP_KEY reuse: a fresh one, so a sandbox cannot decrypt production data
#     even if a dump were restored into it by mistake
#
# It does NOT try to be clever about what you then run. Anything you like is
# safe, because there is nothing dangerous within reach.

set -euo pipefail

NAME=${1:-}
[ -n "$NAME" ] || { echo "usage: scripts/sandbox.sh <name> [--rm]" >&2; exit 2; }

REMOTE=${EZBIZI_REMOTE:-app}
DIR="/opt/sb-${NAME}"
DB="sb_${NAME}_testing"          # the marker TestCase.php insists on

if [ "${2:-}" = "--rm" ]; then
  ssh "$REMOTE" "sudo rm -rf '$DIR'; sudo mysql -e \"DROP DATABASE IF EXISTS \\\`$DB\\\`;\"" \
    && echo "removed $DIR and $DB"
  exit 0
fi

echo "→ building $DIR against $DB"

ssh "$REMOTE" "sudo bash -s" <<REMOTE_SCRIPT
set -euo pipefail
DIR='$DIR'; DB='$DB'

rm -rf "\$DIR"

# From the newest release for its vendor/ — then every file that could point at
# production is replaced below. The release's own .env is removed outright
# rather than edited, so a missed sed cannot leave a live credential behind.
SRC=\$(ls -d /var/www/ezbizi-releases/* | tail -1)
cp -a "\$SRC" "\$DIR"
rm -f "\$DIR/.env" "\$DIR/.env.testing" "\$DIR/.env.backup"

# THE CACHED CONFIG, WHICH IS THE WHOLE TRAP ONE LAYER DOWN.
#
# A release carries bootstrap/cache/config.php, compiled at deploy time with the
# production database name baked into it. Laravel reads that file and never
# looks at .env at all — so the first version of this script wrote a perfectly
# correct .env, and `php artisan tinker` still answered `ezbizi` with the live
# organization in it. Removing the .env is not enough; the answer has to be
# removed too, or the sandbox is exactly as dangerous as before and now looks
# safe.
rm -f "\$DIR/bootstrap/cache/config.php" "\$DIR/bootstrap/cache/routes-v7.php" "\$DIR/bootstrap/cache/events.php"

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
rm -rf "\$DIR/storage"
mkdir -p \n  "\$DIR/storage/app/private" "\$DIR/storage/app/public" \n  "\$DIR/storage/framework/cache/data" "\$DIR/storage/framework/sessions" \n  "\$DIR/storage/framework/testing" "\$DIR/storage/framework/views" \n  "\$DIR/storage/logs"

# The tree in a release is installed --no-dev, so phpunit is absent. Borrow the
# dev dependencies from the long-lived test sandbox rather than downloading.
[ -d /opt/ezbizi-test/vendor ] && rm -rf "\$DIR/vendor" && cp -a /opt/ezbizi-test/vendor "\$DIR/vendor"

mysql -e "DROP DATABASE IF EXISTS \\\`\$DB\\\`; CREATE DATABASE \\\`\$DB\\\`;
          GRANT ALL ON \\\`\$DB\\\`.* TO 'ezbizi_sandbox'@'127.0.0.1';"

# One database name, written into all three places that decide it. phpunit.xml
# is the one that catches people out: its <env> block OVERRIDES .env.testing,
# so sedding only the dotfile isolates nothing.
cat > "\$DIR/.env" <<ENV
APP_NAME=EzBizi
APP_ENV=testing
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=\$DB
DB_USERNAME=ezbizi_sandbox
DB_PASSWORD=\$(cat /root/.ezbizi-sandbox.pw)
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
MAIL_MAILER=log
ENV
sed -i "s|<env name=\"DB_DATABASE\" value=\"[^\"]*\"|<env name=\"DB_DATABASE\" value=\"\$DB\"|" "\$DIR/phpunit.xml"

chown -R ubuntu:ubuntu "\$DIR"

# Its own key, generated BEFORE .env.testing is copied from .env — key:generate
# writes only .env, so copying first left the testing environment with an empty
# APP_KEY and 75 tests died on MissingAppKeyException.
#
# A fresh key, not production's: a sandbox that cannot decrypt a production dump
# is one that cannot quietly become a copy of production.
sudo -u ubuntu php "\$DIR/artisan" key:generate --force -q
cp "\$DIR/.env" "\$DIR/.env.testing"
sudo -u ubuntu php "\$DIR/artisan" migrate --force -q

# Refuse to hand over a sandbox that can see production. Checked rather than
# assumed, because both incidents this script exists to prevent were caused by a
# guard somebody believed was in place.
SEES=\$(cd "\$DIR" && sudo -u ubuntu php artisan tinker --execute='echo DB::connection()->getDatabaseName();' 2>/dev/null | tr -d "[:space:]")

if [ "\$SEES" != "\$DB" ]; then
  echo "   REFUSING: a bare artisan here resolves to '\$SEES', not '\$DB'." >&2
  rm -rf "\$DIR"
  mysql -e "DROP DATABASE IF EXISTS \\\`\$DB\\\`;"
  exit 1
fi

echo "   ready — artisan resolves to \$SEES"
REMOTE_SCRIPT

cat <<DONE

  $DIR   (database $DB)

  Overlay your working tree, then run anything you like — there is no
  production credential in that directory:

    # phpunit.xml is EXCLUDED on purpose: it is tracked, so an overlay replaces
    # the sed'd copy with yours, and its <env> block overrides .env.testing —
    # silently putting the run back on the shared database with nothing said.
    # storage/ is excluded because tar cannot read those files from here.
    cd /h/EzBizi && git ls-files -co --exclude-standard \
      | grep -vE '^(phpunit[.]xml|storage/)' | tar -czf - -T - \\
      | ssh $REMOTE 'tar -xzf - -C $DIR'
    cd /h/EzBizi && git status --porcelain | awk '/^R/ {print \$2} /^.?D/ {print \$2}' \\
      | ssh $REMOTE "cd $DIR && xargs -r rm -f"
    ssh $REMOTE 'cd $DIR && composer dump-autoload -q && php artisan test'

  When you are done:  scripts/sandbox.sh $NAME --rm
DONE
