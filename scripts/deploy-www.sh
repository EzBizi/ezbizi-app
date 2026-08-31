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
#
# ── which www/ ───────────────────────────────────────────────────
#
#   ./scripts/deploy-www.sh              publishes the WORKING TREE
#   ./scripts/deploy-www.sh <sha>        publishes that commit
#
# deploy.sh passes the gated SHA, for the same reason it builds the client in a
# worktree at that SHA rather than in place: fifteen sessions share this tree,
# and a site published from it is a claim about whatever anybody happened to
# have saved. Publishing half of somebody edit to the live marketing site is a
# worse outcome than any it would save.
set -euo pipefail
cd "$(dirname "$0")/.."

REMOTE=app
ROOT=/var/www/www
SITE=https://www.ezbizi.com
SHA=${1:-}

# The source of truth for this run. Working tree by default; a temp extraction
# of the commit when one is named. git archive rather than a worktree because
# only www/ is wanted and a worktree would cost a full checkout.
SRC=www
if [ -n "$SHA" ]; then
  TMP=$(mktemp -d)
  trap 'rm -rf "$TMP"' EXIT
  SRC=$TMP/www
  mkdir -p "$SRC"
  # autocrlf=false: see the note in deploy.sh. The archive is built on Windows,
  # so without this the published page bytes depend on which machine deployed.
  git -c core.autocrlf=false archive "$SHA" www | tar -x --strip-components=1 -C "$SRC"
  echo "→ publishing www/ at $(git rev-parse --short "$SHA")"
else
  echo "→ publishing www/ from the WORKING TREE (uncommitted edits included)"
fi

echo "→ checking the site"
python "$SRC/check-site.py"

echo "→ publishing to $REMOTE:$ROOT"
tar -cz -C "$SRC" --exclude=./check-site.py . | ssh "$REMOTE" "
  set -e
  sudo tar -xz -C '$ROOT' --no-same-owner
  sudo chown -R www-data:www-data '$ROOT'
"

# Verified against what the server actually returns, not against the exit status
# of the upload. A publish that extracted into the wrong directory, or left a
# file unreadable by www-data, exits 0 and serves the old page.
#
# Byte-for-byte, because "it returned 200" is what the stale site did all day.
# DO NOT VERIFY A PUBLISHED PAGE WITH `git show`. It is the obvious way to check
# and it reports a false mismatch on every file:
#
#     git show HEAD:www/google-ads-api/index.html   12357 bytes
#     git archive HEAD -- (same path)               12543 bytes
#     the live page                                 12543 bytes
#     difference 186 = exactly one CR per line, 186 lines
#
# `git archive` applies the CRLF policy of whoever runs it -- core.autocrlf in
# LOCAL config, not something the repo pins; `git show` does not. This loop
# compares against $SRC — the extracted ARCHIVE — which is the right comparison
# and the one the deploy actually shipped.
#
# The reason this warning is here rather than in a doc: the failure mode is
# somebody deciding a good publish is broken and correcting it. On www/ that
# means editing the marketing site by hand, which is what this script exists to
# stop, and it would happen during whatever review made them check in the first
# place. Found on 2026-08-31 by a session verifying a page a Google reviewer was
# about to load.
echo "→ verifying what the server returns"
bad=0
for f in $(cd "$SRC" && find . -name '*.html' | sed 's|^\./||' | sort); do
  url="$SITE/$(dirname "$f")/"
  url="${url//\/.\//\/}"                    # ./ -> /   for the root index.html
  if curl -sf "$url" | cmp -s - "$SRC/$f"; then
    echo "   ok   $url"
  else
    echo "   ✗    $url does not match $SRC/$f"
    bad=$((bad + 1))
  fi
done

[ "$bad" -eq 0 ] || { echo "✗ $bad page(s) wrong — the site is NOT what this repo says"; exit 1; }
# ── is what we just published still the newest thing? ───────────
#
# Everything above proves the UPLOAD worked: the bytes on the server are the
# bytes of the SHA we shipped. It cannot prove that SHA was CURRENT, and those
# are different claims.
#
# The gap is real and it bit on 2026-08-31. deploy-www.sh runs at the end of
# deploy.sh, which pins GATED_SHA before its gate — so a www-only commit landing
# during that ~19 minute window has nothing to carry it. The site was correct in
# git and stale in public, every check green, while a Google compliance reviewer
# was reading the page. It was found by a session diffing the live page against
# every historical commit on a hunch, which is not a control.
#
# A www-only commit has no deploy of its own. This is the thing that tells you.
AHEAD=$(git rev-list --count "${SHA:-HEAD}"..main -- www/ 2>/dev/null || echo 0)
if [ "${AHEAD:-0}" -gt 0 ]; then
  echo "⚠ PUBLISHED, BUT NOT CURRENT — main is $AHEAD commit(s) ahead on www/:" >&2
  git log --oneline "${SHA:-HEAD}"..main -- www/ 2>/dev/null | sed 's/^/     /' >&2
  echo "  Re-run: scripts/deploy-www.sh \$(git rev-parse main)" >&2
fi

echo "✓ site published"
