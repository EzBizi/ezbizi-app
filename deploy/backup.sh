#!/bin/bash
#
# EzBizi nightly backup — plan §18 Phase 0 item 5.
#
# ── What changed, and why ────────────────────────────────────────
#
# The first version of this wrote gzipped dumps to /var/backups/ezbizi and
# stopped. That is not a backup. It shares a disk, an instance and a filesystem
# with the thing it protects, so the failures it survives are exactly the ones
# nobody worries about — and none of the ones that lose a company.
#
# Three things were missing and are now here:
#
#   1. An off-instance copy. Nightly, over ssh, to the second box.
#   2. Encryption. The dumps carry customer records and the .env carries
#      APP_KEY; both now leave this machine as ciphertext.
#   3. A verdict the product can see. ezbizi-restore-check.sh actually restores
#      what was written and says whether it worked — see the note there about
#      why an unverified backup is worse than none.
#
# ── APP_KEY is part of the backup, deliberately ──────────────────
#
# TOTP secrets and recovery codes are encrypted with it. A database restored
# without the matching APP_KEY is a database where every second factor is
# permanently unreadable and every user is locked out. Losing the key is
# therefore identical to losing the data, which is why .env is in here.
#
# ── The ceiling this still has ───────────────────────────────────
#
# The remote is a second Lightsail instance in the same AWS account. That
# survives an instance loss, a bad deploy and a disk failure. It does NOT
# survive the account itself — a billing suspension or a compromised root login
# takes both boxes. Off-account storage (S3 with object lock, or a pull from
# somewhere else entirely) is the next step and needs a credential nobody has
# handed over yet. Stated here rather than left as an assumption.

set -uo pipefail

DEST=/var/backups/ezbizi
KEEP_LOCAL_DAYS=14
KEEP_REMOTE_DAYS=45
STAMP=$(date +%F)
STATUS=/var/www/ezbizi/storage/app/backup-status.json
KEYFILE=/root/.ezbizi-backup.key
REMOTE_HOST=${EZBIZI_BACKUP_HOST:-ubuntu@32.194.222.151}
REMOTE_DIR=${EZBIZI_BACKUP_DIR:-/home/ubuntu/ezbizi-backups}
REMOTE_KEY=${EZBIZI_BACKUP_SSH_KEY:-/root/.ssh/ezbizi-backup}

mkdir -p "$DEST"
errors=()

note() { echo "$(date -Is) $*" >> /var/log/ezbizi-backup.log; }

# Every artefact goes through here. Symmetric AES256 rather than a keypair
# because the only party who needs to decrypt is whoever is holding the
# passphrase during a restore, and a private key stored beside the backups
# protects nothing.
encrypt_to() {
  gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
      --passphrase-file "$KEYFILE" --output "$1"
}

if [[ ! -r "$KEYFILE" ]]; then
  note "FATAL no passphrase at $KEYFILE — refusing to write unencrypted backups"
  printf '{"ok":false,"at":"%s","stage":"setup","errors":["missing %s"]}\n' \
         "$(date -Is)" "$KEYFILE" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
fi

# ── 1. databases ─────────────────────────────────────────────────
for db in $(mysql -N -e "SELECT schema_name FROM information_schema.schemata
      WHERE schema_name NOT IN ('information_schema','performance_schema','mysql','sys');" 2>/dev/null); do

  out="$DEST/db-${db}-${STAMP}.sql.gz.gpg"

  # PIPESTATUS, not $?: a pipeline reports only its last command, so a
  # mysqldump that died halfway would still look like a success as long as gpg
  # was happy encrypting the truncated stream. That is the single most likely
  # way to end up with a backup that restores into a half-empty database.
  mysqldump --single-transaction --routines --triggers --events \
            --no-tablespaces "$db" 2>/dev/null | gzip | encrypt_to "$out"

  status=("${PIPESTATUS[@]}")
  if [[ "${status[0]}" -ne 0 || "${status[2]}" -ne 0 ]]; then
    errors+=("dump:$db")
    rm -f "$out"
  fi
done

# ── 2. the things that cannot be rebuilt ─────────────────────────
#
# The bare git repo IS the source of truth for the code — the release
# directories are derived from it and the working checkout is disposable. It
# was not in the previous version of this script, which meant a lost instance
# lost every commit that had not been pushed anywhere else.
tar -czf - \
    -C /var/repos ezbizi.git \
    -C /var/www/ezbizi .env storage \
    2>/dev/null | encrypt_to "$DEST/app-${STAMP}.tar.gz.gpg"

status=("${PIPESTATUS[@]}")
[[ "${status[0]}" -ne 0 || "${status[1]}" -ne 0 ]] && errors+=("app-archive")

# ── 3. server configuration ──────────────────────────────────────
tar -czf - /etc/nginx/sites-available /etc/nginx/conf.d /etc/bind /var/lib/bind \
           /etc/letsencrypt/renewal /etc/systemd/system/ezbizi-* /etc/cron.d \
    2>/dev/null | encrypt_to "$DEST/config-${STAMP}.tar.gz.gpg"

[[ "${PIPESTATUS[1]}" -ne 0 ]] && errors+=("config-archive")

# ── 4. a manifest, so a restore can prove it got the whole file ──
(cd "$DEST" && sha256sum ./*-"${STAMP}".*.gpg > "manifest-${STAMP}.txt") 2>/dev/null \
  || errors+=("manifest")

# ── 5. off this instance ─────────────────────────────────────────
if [[ -r "$REMOTE_KEY" ]]; then
  ssh -i "$REMOTE_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      "$REMOTE_HOST" "mkdir -p $REMOTE_DIR" 2>/dev/null \
    && scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
           "$DEST"/*-"${STAMP}".*.gpg "$DEST/manifest-${STAMP}.txt" \
           "$REMOTE_HOST:$REMOTE_DIR/" 2>/dev/null \
    || errors+=("offsite-copy")

  # Pruned on the remote too. A backup destination that only ever grows is one
  # that eventually fills a disk belonging to somebody else's website.
  ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
      "find $REMOTE_DIR -type f -mtime +$KEEP_REMOTE_DAYS ! -name '*-01.*' -delete" \
      2>/dev/null || errors+=("offsite-prune")
else
  errors+=("offsite-no-key")
fi

# ── 6. local retention ───────────────────────────────────────────
#
# Monthlies are kept: the failure this protects against is corruption nobody
# notices for a fortnight, which a fourteen-day window quietly discards.
find "$DEST" -type f -mtime +$KEEP_LOCAL_DAYS ! -name '*-01.*' -delete 2>/dev/null

# ── 7. the verdict ───────────────────────────────────────────────
if [[ ${#errors[@]} -eq 0 ]]; then
  printf '{"ok":true,"at":"%s","stage":"backup","size":"%s","files":%s,"errors":[]}\n' \
         "$(date -Is)" "$(du -sh "$DEST" | cut -f1)" "$(ls -1 "$DEST" | wc -l)" > "$STATUS"
  note "backup ok size=$(du -sh "$DEST" | cut -f1)"
else
  printf '{"ok":false,"at":"%s","stage":"backup","errors":["%s"]}\n' \
         "$(date -Is)" "$(IFS='","'; echo "${errors[*]}")" > "$STATUS"
  note "backup FAILED: ${errors[*]}"
fi

chmod 644 "$STATUS"
[[ ${#errors[@]} -eq 0 ]]
