#!/usr/bin/env bash
#
# Publish the marketing site — www.ezbizi.com.
#
#   ./scripts/deploy-www.sh
#
# Also called at the end of deploy.sh, because a publish step somebody has to
# remember is a publish step that stops happening. It stopped happening: on
# 2026-08-28 the live site was a day stale and still told visitors there was no
# iPhone app, while the commit that fixed that sat in git with nothing to carry
# it anywhere.
#
# ── where it goes ────────────────────────────────────────────────
#
# /var/www/www on the app box, owned www-data:www-data. The vhost is NOT in
# sites-enabled — it is a server block inside conf.d/wildcard_subdomains.conf,
# which is why grepping sites-enabled for "www.ezbizi.com" finds nothing and
# looks like the site is served from somewhere else entirely.
#
# ── why tar, and why that is the safety feature ──────────────────
#
# rsync is not on a stock Windows Git Bash, which is where this runs; deploy.sh
# learned that the hard way and ships its UI over tar for the same reason.
#
# It also happens to be the guardrail. `tar -xz` overwrites what is in the
# archive and touches nothing else, so "never delete" is the DEFAULT rather than
# a flag someone can leave off. That matters more here than anywhere:
#
#     /var/www/www/download/  holds EzBizi-setup.exe, four versioned installers
#     (~60 MB) and windows.json — NONE of which are in this repo. windows.json
#     is what every installed desktop client polls to learn a new version
#     exists. An rsync --delete here does not just lose files; it silently ends
#     updates for every Windows install in the field.
#
# So: no --delete, ever, by any route. The cost is that a file deleted from the
# repo lingers on the box (there are two such: index.html.bak-* from August).
# Removing one is a deliberate `sudo rm` on the box, not a deploy flag.
#
# ── the gate ─────────────────────────────────────────────────────
#
# www/ has no test suite and fails silently — a renamed page, a sitemap that
# forgot an entry, malformed JSON-LD. None of it 500s. check-site.py checks all
# of that offline, and it does not ship: it is a dev script, not a page.
set -euo pipefail
cd "$(dirname "$0")/.."

REMOTE=app
ROOT=/var/www/www
SITE=https://www.ezbizi.com

echo "→ checking the site"
python www/check-site.py

echo "→ publishing to $REMOTE:$ROOT"
tar -cz -C www --exclude=./check-site.py . | ssh "$REMOTE" "
  set -e
  sudo tar -xz -C '$ROOT' --no-same-owner
  sudo chown -R www-data:www-data '$ROOT'
"

# Verified against what the server actually returns, not against the exit status
# of the upload. A publish that extracted into the wrong directory, or left a
# file unreadable by www-data, exits 0 and serves the old page.
#
# Byte-for-byte, because "it returned 200" is what the stale site did all day.
echo "→ verifying what the server returns"
bad=0
for f in $(cd www && find . -name '*.html' | sed 's|^\./||' | sort); do
  url="$SITE/$(dirname "$f")/"
  url="${url//\/.\//\/}"                    # ./ -> /   for the root index.html
  if curl -sf "$url" | cmp -s - "www/$f"; then
    echo "   ok   $url"
  else
    echo "   ✗    $url does not match www/$f"
    bad=$((bad + 1))
  fi
done

[ "$bad" -eq 0 ] || { echo "✗ $bad page(s) wrong — the site is NOT what this repo says"; exit 1; }
echo "✓ site published"
