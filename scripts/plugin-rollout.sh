#!/usr/bin/env bash
#
# Put the repo's WordPress plugin onto the hosted estate.
#
#   ./scripts/plugin-rollout.sh --survey          what is installed, where
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

# The plugin ships from a COMMIT, like every other artefact. A dirty plugin
# directory means the estate would carry code that is in no commit, on 110
# businesses' websites, and "which version is on the fleet" stops having an
# answer. docs/ is excluded for the reason build-app.sh excludes it.
DIRTY=$(git status --porcelain --untracked-files=no -- "$SRC")
if [ -n "$DIRTY" ]; then
  echo "x $SRC has uncommitted changes; commit before rolling out:" >&2
  echo "$DIRTY" >&2
  exit 1
fi
COMMIT=$(git rev-parse --short HEAD)

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
    sudo -n cp -a /tmp/ezbizi-new/. \"\$dir\"/
    sudo -n chown -R \"\$own\" \"\$dir\"
    sudo -n find \"\$dir\" -type f -exec chmod 644 {} \;
    sudo -n find \"\$dir\" -type d -exec chmod 755 {} \;
    now=\$(sudo -n grep -m1 'const EZBIZI_VERSION' \"\$dir\"/ezbizi.php | sed \"s/.*= '//;s/'.*//\")
    echo \"   \$now  \$own  \$dir\""
}

case "${1:-}" in
  --survey)
    survey ;;

  --canary)
    TARGET_HOST="${2:?--canary needs a host, e.g. chimneystar.com}"
    survey > /dev/null
    T=$(ssh "$REMOTE" "grep -m1 '/$TARGET_HOST/' $LIST" || true)
    [ -n "$T" ] || { echo "no install under $TARGET_HOST" >&2; exit 1; }
    stage
    echo "-> canary: $TARGET_HOST"
    apply_one "$T"
    echo "   backups in $BACKUP_ROOT" ;;

  --all)
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
