#!/usr/bin/env bash
#
# Put the repo's WordPress plugin onto the hosted estate.
#
#   ./scripts/plugin-rollout.sh --survey          what is installed, where
#   ./scripts/plugin-rollout.sh --shipped         is the REPO's plugin live?
#   ./scripts/plugin-rollout.sh --canary <host>   one site, then stop
#   ./scripts/plugin-rollout.sh --all             every install
#   ./scripts/plugin-rollout.sh --rollback <dir>  put a backup set back
#
# ── Why this file exists ──
#
# "Roll the plugin out to the estate" was carried in ACTIVE-CONSTRAINTS §14 as
# something nobody here could do. That was measured without `sudo` and was
# wrong: `ubuntu` on the Plesk box has passwordless sudo and `plesk` is on its
# PATH. The real constraint is narrower and worth keeping in view — EzBizi's
# WORDPRESS credentials still carry no `update_plugins`, so nothing the product
# does at runtime can install code on a customer site. This is us, on our own
# hosting, with a shell.
#
# ── The two things that will break a site if you get them wrong ──
#
# 1. OWNERSHIP IS PER SITE. Every install is owned by that subscription's FTP
#    user (`cstar_ftp`, `a1chim_ftp`, …) with group `psacln`. Copying as root
#    and walking away leaves root-owned files inside a customer's tree: PHP
#    still reads them, so nothing breaks today, and the next time anybody edits
#    through Plesk, the File Manager or FTP it fails with a permission error
#    nobody can place. So the owner is read off the directory BEFORE the copy
#    and restored after it, per install, rather than assumed.
#
# 2. A BACKUP MUST NOT LIVE INSIDE `wp-content/plugins/`. WordPress treats every
#    directory in there as a plugin and reads its headers. `ezbizi.bak/` beside
#    `ezbizi/` therefore appears in the admin's plugin list as a second copy of
#    EzBizi, and a customer looking at two EzBizis will deactivate one of them.
#    Backups go to /var/backups/ezbizi-plugin/<timestamp>/ instead.
#
set -euo pipefail

REMOTE=websites
SRC="wordpress-plugin/ezbizi"
STAMP=$(date -u +%Y%m%d%H%M%S)
BACKUP_ROOT="/var/backups/ezbizi-plugin/${STAMP}"
LIST=/tmp/ezbizi-installs.txt

cd "$(dirname "$0")/.."

VERSION=$(grep -m1 "const EZBIZI_VERSION" "$SRC/ezbizi.php" | sed "s/.*= '//;s/'.*//")
[ -n "$VERSION" ] || { echo "cannot read EZBIZI_VERSION from $SRC/ezbizi.php" >&2; exit 1; }

COMMIT=$(git rev-parse --short HEAD)

# The plugin ships from a COMMIT, like every other artefact. A dirty plugin
# directory means the estate would carry code that is in no commit, on 110
# businesses' websites, and "which version is on the fleet" stops having an
# answer. docs/ is excluded for the reason build-app.sh excludes it.
#
# CALLED BY THE WRITING COMMANDS ONLY. It used to run at file scope, which
# refused `--survey` and `--shipped` too — and those are questions, asked most
# often by somebody who is mid-edit and wants to know what is out there. A
# read-only check that only answers from a clean tree is a check nobody runs at
# the moment they need it.
require_clean() {
  local dirty
  dirty=$(git status --porcelain --untracked-files=no -- "$SRC")

  [ -z "$dirty" ] && return 0

  echo "x $SRC has uncommitted changes; commit before rolling out:" >&2
  echo "$dirty" >&2
  exit 1
}

survey() {
  ssh "$REMOTE" "sudo -n find /var/www/vhosts -maxdepth 6 -type f \
      -path '*/wp-content/plugins/ezbizi/ezbizi.php' 2>/dev/null | sort > $LIST
    echo \"installs: \$(wc -l < $LIST)\"
    while read -r p; do
      sudo -n grep -m1 'const EZBIZI_VERSION' \"\$p\" 2>/dev/null | sed \"s/.*= '//;s/'.*//\"
    done < $LIST | sort | uniq -c | sort -rn"
}

# Ship the payload once and extract it once, rather than per site: 110 scp runs
# is 110 chances for one to half-finish.
stage() {
  echo "-> staging ${VERSION} (${COMMIT})"
  tar -czf - -C "$SRC" . | ssh "$REMOTE" "cat > /tmp/ezbizi-${VERSION}.tgz"
  ssh "$REMOTE" "sudo -n rm -rf /tmp/ezbizi-new && sudo -n mkdir -p /tmp/ezbizi-new \
    && sudo -n tar -xzf /tmp/ezbizi-${VERSION}.tgz -C /tmp/ezbizi-new \
    && sudo -n chmod -R u=rwX,go=rX /tmp/ezbizi-new
    echo '   staged:' \$(ls /tmp/ezbizi-new | tr '\n' ' ')"
}

# One install. Reads the owner first, backs up outside the plugins directory,
# copies, restores the owner, and re-reads the version off disk so the caller
# is told what is THERE rather than what was sent.
apply_one() {
  local target="$1"
  # `ssh -n`, and it is not cosmetic. Without it ssh reads the caller's stdin —
  # which in `--all` is the list of installs being looped over — so the first
  # site swallows the other 109 and the run reports success having touched one.
  # It did exactly that on the first attempt.
  ssh -n "$REMOTE" "set -e
    dir=\$(dirname '$target')
    own=\$(sudo -n stat -c '%U:%G' \"\$dir\")
    safe=\$(echo \"\$dir\" | sed 's|/var/www/vhosts/||; s|/|__|g')
    sudo -n mkdir -p '$BACKUP_ROOT'
    sudo -n tar -czf '$BACKUP_ROOT'/\"\$safe\".tgz -C \"\$dir\" .
    sudo -n rsync -a --delete /tmp/ezbizi-new/ \"\$dir\"/   # --delete: files removed from the plugin leave the installs too (audit 2026-11-27)
    sudo -n chown -R \"\$own\" \"\$dir\"
    sudo -n find \"\$dir\" -type f -exec chmod 644 {} \;
    sudo -n find \"\$dir\" -type d -exec chmod 755 {} \;
    now=\$(sudo -n grep -m1 'const EZBIZI_VERSION' \"\$dir\"/ezbizi.php | sed \"s/.*= '//;s/'.*//\")
    echo \"   \$now  \$own  \$dir\""
}

# Is what is in the repo what is on the estate?
#
# ── The question this exists to answer ──
#
# `wordpress-plugin/` has no ride in `release.sh` or `deploy.sh`, so an app
# release NEVER carries a plugin change however many go out. Two commits look
# identical in `git log` and are shipped by different routes entirely:
#
#     app/ or client/     an app release; the check is the BUILT sha on
#                         windows.json
#     wordpress-plugin/   a rollout; the check is this
#
# The deploy desk and I held different beliefs about the same SHA on
# 2026-09-23 for exactly this reason — they had it as unshipped, I had rolled
# it an hour earlier, and both of us were reading a true thing about a
# different question. `--survey` already prints the versions; this says whether
# they match the repo, which is the sentence somebody actually needs.
#
# It reports the estate, never the repo's opinion of itself: a plugin bumped to
# 1.14.0 and not rolled out is NOT shipped, and the whole point is that the
# version number in the file cannot tell you that.
shipped() {
  local want
  want=$(grep -m1 "const EZBIZI_VERSION" "$SRC/ezbizi.php" | sed "s/.*= '//;s/'.*//")

  echo "repo:   $want  ($COMMIT)"

  local counts
  counts=$(ssh "$REMOTE" "sudo -n find /var/www/vhosts -maxdepth 6 -type f \
      -path '*/wp-content/plugins/ezbizi/ezbizi.php' 2>/dev/null | sort > $LIST
    while read -r p; do
      sudo -n grep -m1 'const EZBIZI_VERSION' \"\$p\" 2>/dev/null | sed \"s/.*= '//;s/'.*//\"
    done < $LIST | sort | uniq -c | sort -rn")

  echo "estate:"
  echo "$counts" | sed 's/^/  /'

  local total on_want
  total=$(echo "$counts" | awk '{ n += $1 } END { print n + 0 }')
  on_want=$(echo "$counts" | awk -v w="$want" '$2 == w { print $1 + 0 }')
  on_want=${on_want:-0}

  echo
  if [ "$total" -gt 0 ] && [ "$on_want" -eq "$total" ]; then
    echo "SHIPPED: all $total installs are on $want"
    return 0
  fi

  echo "NOT SHIPPED: $on_want of $total installs are on $want"
  echo "             run --all to roll it out"
  return 1
}

case "${1:-}" in
  --survey)
    survey ;;

  --shipped)
    shipped ;;

  --canary)
    require_clean
    TARGET_HOST="${2:?--canary needs a host, e.g. chimneystar.com}"
    survey > /dev/null
    # httpdocs first: Plesk puts subdomains beside it under the same domain
    # folder, and `atl/` sorts before `httpdocs/`, so a plain first match sent
    # the a-1chimney.com canary to atl.a-1chimney.com (2026-09-24).
    T=$(ssh "$REMOTE" "grep -m1 '/$TARGET_HOST/httpdocs/' $LIST || grep -m1 '/$TARGET_HOST/' $LIST" || true)
    [ -n "$T" ] || { echo "no install under $TARGET_HOST" >&2; exit 1; }
    stage
    echo "-> canary: $TARGET_HOST"
    apply_one "$T"
    echo "   backups in $BACKUP_ROOT" ;;

  --all)
    require_clean
    survey
    stage
    echo "-> applying to every install"
    ssh "$REMOTE" "cat $LIST" | while read -r t; do apply_one "$t"; done
    echo "   backups in $BACKUP_ROOT" ;;

  --rollback)
    SET="${2:?--rollback needs a backup directory under /var/backups/ezbizi-plugin/}"
    ssh "$REMOTE" "set -e
      for f in '$SET'/*.tgz; do
        dir=/var/www/vhosts/\$(basename \"\$f\" .tgz | sed 's|__|/|g')
        own=\$(sudo -n stat -c '%U:%G' \"\$dir\")
        sudo -n rm -rf \"\$dir\"/* && sudo -n tar -xzf \"\$f\" -C \"\$dir\"
        sudo -n chown -R \"\$own\" \"\$dir\"
        echo \"   restored \$dir\"
      done" ;;

  *)
    sed -n '3,10p' "$0"; exit 2 ;;
esac
