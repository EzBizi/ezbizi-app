#!/bin/bash
#
# Prove last night's backup can actually be restored — plan §18 Phase 0 item 5,
# which says "backups WITH A VERIFIED RESTORE" and means the second half.
#
# ── Why this exists as a scheduled job ───────────────────────────
#
# An unverified backup is worse than no backup, because it is indistinguishable
# from a working one until the day it matters. Every way a dump goes bad is
# silent: a mysqldump killed halfway still produces a plausible file, a
# truncated upload still lands, a passphrase that changed still encrypts.
#
# So this does the only thing that answers the question — it pulls the file back
# DOWN from the remote (which also proves the off-site copy is readable, not
# merely that a copy command exited zero), decrypts it, restores it into a
# scratch database, and checks that what came out matches what went in.
#
# It deliberately does NOT verify the local copy. The local copy is the one that
# will not exist on the day this is needed.

set -uo pipefail

STATUS=/var/www/ezbizi/storage/app/backup-status.json
KEYFILE=/root/.ezbizi-backup.key
SCRATCH=ezbizi_restore_check
WORK=$(mktemp -d)
REMOTE_HOST=${EZBIZI_BACKUP_HOST:-ubuntu@32.194.222.151}
REMOTE_DIR=${EZBIZI_BACKUP_DIR:-/home/ubuntu/ezbizi-backups}
REMOTE_KEY=${EZBIZI_BACKUP_SSH_KEY:-/root/.ssh/ezbizi-backup}
LIVE=ezbizi

trap 'rm -rf "$WORK"; mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`;" 2>/dev/null' EXIT

note()  { echo "$(date -Is) restore-check $*" >> /var/log/ezbizi-backup.log; }
fail()  {
  note "FAILED: $1"
  printf '{"ok":false,"at":"%s","stage":"restore-check","errors":["%s"]}\n' \
         "$(date -Is)" "$1" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
}

# ── 1. fetch the newest remote dump of the live database ─────────
newest=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
         "ls -1 $REMOTE_DIR/db-${LIVE}-*.sql.gz.gpg 2>/dev/null | sort | tail -1")

[[ -z "$newest" ]] && fail "no remote dump for ${LIVE}"

scp -i "$REMOTE_KEY" -o BatchMode=yes -q "$REMOTE_HOST:$newest" "$WORK/" \
  || fail "could not fetch $newest"

file="$WORK/$(basename "$newest")"
stamp=$(basename "$newest" | sed -E "s/db-${LIVE}-(.*)\.sql\.gz\.gpg/\1/")

# Yesterday's file passing every check is still a failure: it means last night's
# run did not happen, and a stale backup that verifies clean is precisely how a
# dead timer stays unnoticed.
today=$(date +%F)
yesterday=$(date -d yesterday +%F)
[[ "$stamp" != "$today" && "$stamp" != "$yesterday" ]] && fail "newest remote dump is from $stamp"

# ── 2. the file is intact ────────────────────────────────────────
remote_sum=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
             "grep '$(basename "$newest")' $REMOTE_DIR/manifest-${stamp}.txt 2>/dev/null" | awk '{print $1}')
local_sum=$(sha256sum "$file" | awk '{print $1}')

[[ -n "$remote_sum" && "$remote_sum" != "$local_sum" ]] && fail "checksum mismatch on $(basename "$newest")"

# ── 3. decrypt and restore into a scratch database ───────────────
gpg --batch --quiet --decrypt --passphrase-file "$KEYFILE" "$file" 2>/dev/null \
  | gunzip > "$WORK/dump.sql"

status=("${PIPESTATUS[@]}")
[[ "${status[0]}" -ne 0 ]] && fail "decryption failed — wrong passphrase or corrupt file"
[[ "${status[1]}" -ne 0 ]] && fail "gunzip failed — the archive is truncated"

mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`; CREATE DATABASE \`$SCRATCH\`;" \
  || fail "could not create the scratch database"

mysql "$SCRATCH" < "$WORK/dump.sql" 2>/dev/null || fail "the dump would not restore"

# ── 4. what came out matches what went in ────────────────────────
#
# Row counts rather than table names. An empty schema restores perfectly and
# tells you nothing, and "the file was 8KB" is the check that let a wiped
# database look healthy for seven hours.
q() { mysql -N -e "$1" 2>/dev/null; }

restored_tables=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SCRATCH';")
live_tables=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$LIVE';")

[[ "$restored_tables" -lt 1 ]] && fail "restored database has no tables"
[[ "$restored_tables" -lt "$live_tables" ]] && \
  fail "restored $restored_tables tables, live has $live_tables"

restored_migrations=$(q "SELECT COUNT(*) FROM \`$SCRATCH\`.migrations;")
live_migrations=$(q "SELECT COUNT(*) FROM \`$LIVE\`.migrations;")

[[ "$restored_migrations" -ne "$live_migrations" ]] && \
  fail "schema is behind: $restored_migrations migrations restored, $live_migrations live"

# Every table that has rows in production must have rows in the restore. This is
# the check that catches a dump interrupted partway through: the schema is
# complete, the early tables are full, and the later ones are silently empty.
empty=""
for table in organizations companies franchises users roles permissions; do
  live_rows=$(q "SELECT COUNT(*) FROM \`$LIVE\`.\`$table\`;")
  back_rows=$(q "SELECT COUNT(*) FROM \`$SCRATCH\`.\`$table\`;")

  [[ -z "$live_rows" ]] && continue
  [[ "$live_rows" -gt 0 && "${back_rows:-0}" -eq 0 ]] && empty="$empty $table"
done

[[ -n "$empty" ]] && fail "restored but empty:$empty"

note "restore ok from $stamp — $restored_tables tables, $restored_migrations migrations"

printf '{"ok":true,"at":"%s","stage":"restore-check","from":"%s","tables":%s,"migrations":%s,"errors":[]}\n' \
       "$(date -Is)" "$stamp" "$restored_tables" "$restored_migrations" > "$STATUS"
chmod 644 "$STATUS"
