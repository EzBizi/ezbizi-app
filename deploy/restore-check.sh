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
# So this does the only thing that answers the question — it pulls the files
# back DOWN from the remote (which also proves the off-site copy is readable,
# not merely that a copy command exited zero), decrypts the database into a
# scratch schema, restores private customer files into a fresh temporary tree,
# and checks that both results exactly match what went in.
#
# If the remote is unreachable it verifies the local copy instead and says so —
# reporting `offsite:false`, which the health screen shows as a problem rather
# than a tick. Proving a dump restores is worth having on its own; proving it
# about the copy that shares a disk with the database is not the thing that
# matters on the day the disk is gone.

set -uo pipefail
umask 077

verify_private_encrypted_archive() {
  local encrypted=$1
  local keyfile=$2
  local work=$3

  [[ -r "$encrypted" && -r "$keyfile" ]] || {
    echo "private-file restore verification failed" >&2
    return 1
  }

  mkdir "$work" || return 1
  if ! gpg --batch --quiet --decrypt --passphrase-file "$keyfile" \
      --output "$work/archive.tar.gz" "$encrypted" 2>/dev/null; then
    echo "private-file restore verification failed" >&2
    return 1
  fi

  python3 - "$work/archive.tar.gz" "$work/restored" <<'PY'
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import tarfile

archive_path = Path(sys.argv[1])
restore_root = Path(sys.argv[2])


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate-key")
        result[key] = value
    return result


def valid_relative_path(value):
    if not isinstance(value, str) or not value or "\\" in value or "\x00" in value:
        return False
    path = PurePosixPath(value)
    return not path.is_absolute() and all(part not in ("", ".", "..") for part in path.parts)


try:
    with tarfile.open(archive_path, "r:gz") as archive:
        members = archive.getmembers()
        names = [member.name for member in members]
        if len(names) != len(set(names)):
            raise ValueError("duplicate-entry")
        if any(not member.isreg() for member in members):
            raise ValueError("non-regular-entry")
        if names.count("manifest.v1.json") != 1:
            raise ValueError("manifest")

        manifest_member = archive.getmember("manifest.v1.json")
        if manifest_member.size > 64 * 1024 * 1024:
            raise ValueError("manifest-size")
        manifest_stream = archive.extractfile(manifest_member)
        if manifest_stream is None:
            raise ValueError("manifest")
        manifest = json.loads(
            manifest_stream.read().decode("utf-8"), object_pairs_hook=unique_object
        )
        if not isinstance(manifest, dict) or set(manifest) != {"version", "files"}:
            raise ValueError("manifest-shape")
        if manifest["version"] != 1 or not isinstance(manifest["files"], list):
            raise ValueError("manifest-version")

        rows = []
        paths = set()
        for row in manifest["files"]:
            if not isinstance(row, dict) or set(row) != {"path", "bytes", "sha256"}:
                raise ValueError("row-shape")
            path = row["path"]
            byte_count = row["bytes"]
            digest = row["sha256"]
            if not valid_relative_path(path) or path in paths:
                raise ValueError("row-path")
            if isinstance(byte_count, bool) or not isinstance(byte_count, int) or byte_count < 0:
                raise ValueError("row-size")
            if not isinstance(digest, str) or re.fullmatch(r"[0-9a-f]{64}", digest) is None:
                raise ValueError("row-hash")
            paths.add(path)
            rows.append((path, byte_count, digest))

        expected = {"manifest.v1.json", *("files/" + path for path in paths)}
        if set(names) != expected:
            raise ValueError("entry-set")

        by_name = {member.name: member for member in members}
        for path, byte_count, _ in rows:
            if by_name["files/" + path].size != byte_count:
                raise ValueError("entry-size")

        restore_root.mkdir(mode=0o700)
        resolved_root = restore_root.resolve()
        total_bytes = 0
        for path, byte_count, expected_digest in rows:
            target = restore_root.joinpath(*PurePosixPath(path).parts)
            target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            if not target.resolve(strict=False).is_relative_to(resolved_root):
                raise ValueError("target")
            source = archive.extractfile(by_name["files/" + path])
            if source is None:
                raise ValueError("entry")
            digest = hashlib.sha256()
            written = 0
            descriptor = os.open(
                target,
                os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0),
                0o600,
            )
            try:
                with os.fdopen(descriptor, "wb", closefd=False) as output:
                    for chunk in iter(lambda: source.read(1024 * 1024), b""):
                        output.write(chunk)
                        digest.update(chunk)
                        written += len(chunk)
            finally:
                os.close(descriptor)
                source.close()
            if written != byte_count or digest.hexdigest() != expected_digest:
                raise ValueError("content")
            total_bytes += written

        print(f"{len(rows)} {total_bytes}")
except Exception:
    print("private-file restore verification failed", file=sys.stderr)
    sys.exit(1)
PY
}

# Disposable entry point: it runs the production decryption, archive validation,
# extraction and manifest comparison in a fresh directory, then removes exactly
# that directory. It performs no database, network, status-file or /var writes.
if [[ "${1:-}" == "--private-files-fixture" ]]; then
  [[ $# -eq 3 ]] || {
    echo "private-file fixture setup failed" >&2
    exit 1
  }
  FIXTURE_WORK=$(mktemp -d) || exit 1
  cleanup_fixture() { rm -rf -- "$FIXTURE_WORK"; }
  trap cleanup_fixture EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  verify_private_encrypted_archive "$2" "$3" "$FIXTURE_WORK/verification" >/dev/null
  exit $?
fi

STATUS=/var/www/ezbizi/storage/app/backup-status.json
KEYFILE=/root/.ezbizi-backup.key
SCRATCH=ezbizi_restore_check
WORK=$(mktemp -d) || { echo "mktemp failed" >&2; exit 1; }
REMOTE_HOST=${EZBIZI_BACKUP_HOST:-ubuntu@32.194.222.151}
REMOTE_DIR=${EZBIZI_BACKUP_DIR:-/home/ubuntu/ezbizi-backups}
REMOTE_KEY=${EZBIZI_BACKUP_SSH_KEY:-/root/.ssh/ezbizi-backup}
LIVE=ezbizi
LOCAL_DIR=/var/backups/ezbizi

# INT and TERM as well as EXIT: systemd kills this unit with SIGTERM when
# TimeoutStartSec expires, and a bash script killed mid-run does not otherwise
# run its EXIT trap — leaving a scratch copy of the production database behind.
cleanup() { rm -rf -- "$WORK"; mysql -e "DROP DATABASE IF EXISTS \`$SCRATCH\`;" 2>/dev/null; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

note()  { echo "$(date -Is) restore-check $*" >> /var/log/ezbizi-backup.log; }
fail()  {
  note "FAILED: $1"

  # Sanitised for the same reason as backup.sh: these messages interpolate file
  # names, database names and shell output, and a status file that cannot be
  # parsed means a real failure is reported as no failure at all. The full text
  # goes to the log, which has no such constraint.
  safe=$(printf '%s' "$1" | tr -c "A-Za-z0-9 :;,.()_/='-" '?')

  printf '{"ok":false,"at":"%s","stage":"restore-check","errors":["%s"]}\n' \
         "$(date -Is)" "$safe" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
}

# ── 1. fetch the newest dump of the live database ────────────────
#
# The remote copy is preferred, and fetching it is the point: it proves the
# off-site file is readable rather than merely that a copy command exited zero.
#
# Falling back to the local copy is deliberate and is NOT treated as success.
# Verifying that a dump restores is most of the value and is worth having on its
# own — but a restore that only proves the copy sharing a disk with the database
# has not proved the thing that matters on the day the disk is gone. The
# distinction is carried through to the status file, so the health screen shows
# it rather than a green tick.
offsite=true
newest=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes -o ConnectTimeout=15 "$REMOTE_HOST" \
         "ls -1 $REMOTE_DIR/db-${LIVE}-*.sql.gz.gpg 2>/dev/null | sort | tail -1" 2>/dev/null)

if [[ -n "$newest" ]] && scp -i "$REMOTE_KEY" -o BatchMode=yes -q "$REMOTE_HOST:$newest" "$WORK/" 2>/dev/null; then
  file="$WORK/$(basename "$newest")"
else
  offsite=false
  newest=$(ls -1 "$LOCAL_DIR"/db-"${LIVE}"-*.sql.gz.gpg 2>/dev/null | sort | tail -1)
  [[ -z "$newest" ]] && fail "no dump for ${LIVE} anywhere"
  cp "$newest" "$WORK/" || fail "could not read $newest"
  file="$WORK/$(basename "$newest")"
fi

stamp=$(basename "$newest" | sed -E "s/db-${LIVE}-(.*)\.sql\.gz\.gpg/\1/")

# Yesterday's file passing every check is still a failure: it means last night's
# run did not happen, and a stale backup that verifies clean is precisely how a
# dead timer stays unnoticed.
#
# Yesterday's stamp is accepted ONLY in the small hours, for a run that started
# before midnight and finished after it. Accepting it at any hour is what let a
# failed backup pass its own verification against the previous night's file.
today=$(date +%F)
yesterday=$(date -d yesterday +%F)
hour=$(date +%-H)

if [[ "$stamp" != "$today" ]]; then
  if [[ "$stamp" == "$yesterday" && "$hour" -lt 6 ]]; then
    note "verifying yesterday's dump — this run straddled midnight"
  else
    fail "the newest dump anywhere is from $stamp, not today"
  fi
fi

# A failure recorded by backup.sh is NOT erased by a passing restore. Both
# scripts write the same status file and this one runs second, so a dump that
# never got written could be reported green on the strength of an older copy.
#
# Which failure it was decides what happens next, and the distinction matters:
#
#   - a dump or archive that did not get written means there is nothing
#     tonight to verify, and verifying an older file would be answering a
#     different question than the one anybody is asking. Stop.
#   - an off-site copy that did not happen still leaves a good local dump.
#     Verifying it is worth doing and is reported honestly as offsite:false —
#     refusing to check at all would throw away the half that still works.
earlier=""

if [[ -r "$STATUS" ]] && grep -q '"stage":"backup"' "$STATUS" && grep -q '"ok":false' "$STATUS"; then
  earlier=$(tr -d '[]"' < "$STATUS" | sed -n 's/.*errors:\(.*\)}/\1/p')

  if [[ "$earlier" != *offsite* || "$earlier" == *dump* || "$earlier" == *archive* ]]; then
    fail "tonight's backup failed (${earlier:-no reason recorded}) - there is nothing from tonight to verify"
  fi

  note "backup reported ${earlier} - verifying the local dump anyway"
fi

# ── 2. the file is intact ────────────────────────────────────────
#
# Against the manifest written at backup time, so a file that arrived truncated
# is caught here rather than at 3am during an actual restore.
if [[ "$offsite" == true ]]; then
  recorded=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
             "grep '$(basename "$newest")' $REMOTE_DIR/manifest-${stamp}.txt 2>/dev/null" | awk '{print $1}')
else
  recorded=$(grep "$(basename "$newest")" "$LOCAL_DIR/manifest-${stamp}.txt" 2>/dev/null | awk '{print $1}')
fi

actual=$(sha256sum "$file" | awk '{print $1}')

# A MISSING manifest entry is a failure, not a pass. Skipping the comparison
# when it cannot be read means the one case the checksum exists for — a file
# that did not arrive intact — is also the case where it is silently not checked.
[[ -z "$recorded" ]] && fail "no checksum recorded for $(basename "$newest")"
[[ "$recorded" != "$actual" ]] && fail "checksum mismatch on $(basename "$newest")"

# The private-file archive must come from the same backup run and the same
# source as the database dump. Falling back independently could report a green
# restore assembled from two different nights or from one off-site and one
# instance-local component.
private_name="private-files-${stamp}.tar.gz.gpg"
private_file="$WORK/$private_name"

if [[ "$offsite" == true ]]; then
  scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
      "$REMOTE_HOST:$REMOTE_DIR/$private_name" "$private_file" 2>/dev/null \
    || fail "private-file archive missing from off-site backup"
  private_recorded=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
      "grep '$private_name' $REMOTE_DIR/manifest-${stamp}.txt 2>/dev/null" | awk '{print $1}')
else
  [[ -r "$LOCAL_DIR/$private_name" ]] || fail "private-file archive missing from local backup"
  cp "$LOCAL_DIR/$private_name" "$private_file" 2>/dev/null \
    || fail "private-file archive could not be read"
  private_recorded=$(grep "$private_name" "$LOCAL_DIR/manifest-${stamp}.txt" 2>/dev/null | awk '{print $1}')
fi

[[ -z "$private_recorded" ]] && fail "private-file archive has no recorded checksum"
private_actual=$(sha256sum "$private_file" | awk '{print $1}')
[[ "$private_recorded" != "$private_actual" ]] && fail "private-file archive checksum mismatch"

private_stats=$(verify_private_encrypted_archive \
    "$private_file" "$KEYFILE" "$WORK/private-file-verification" 2>/dev/null) \
  || fail "private-file archive would not restore exactly"

read -r private_files private_bytes <<< "$private_stats"
[[ "$private_files" =~ ^[0-9]+$ && "$private_bytes" =~ ^[0-9]+$ ]] \
  || fail "private-file restore returned no verifiable totals"

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

# Empty is not zero and not equal. Both queries returning nothing — a missing
# table, a refused connection — made this comparison pass and then interpolated
# an empty string into the JSON below, producing a status file nothing could
# parse. The check meant to catch a bad restore instead broke the reporting that
# would have told anybody about it.
[[ ! "$restored_migrations" =~ ^[0-9]+$ || ! "$live_migrations" =~ ^[0-9]+$ ]] && \
  fail "could not count migrations (restored='$restored_migrations' live='$live_migrations')"

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

# The restore worked. Whether that is ENOUGH depends on where the file came
# from, and saying so is the difference between a health screen that reports and
# one that reassures.
if [[ "$offsite" == true ]]; then
  note "restore ok from $stamp (off-site) — $restored_tables tables, $restored_migrations migrations"

  printf '{"ok":true,"at":"%s","stage":"restore-check","from":"%s","offsite":true,"tables":%s,"migrations":%s,"private_files":{"manifest_version":1,"files":%s,"bytes":%s},"errors":[]}\n' \
         "$(date -Is)" "$stamp" "$restored_tables" "$restored_migrations" \
         "$private_files" "$private_bytes" > "$STATUS"
else
  note "restore ok from $stamp (LOCAL ONLY) — no off-site copy"

  printf '{"ok":false,"at":"%s","stage":"restore-check","from":"%s","offsite":false,"tables":%s,"migrations":%s,"private_files":{"manifest_version":1,"files":%s,"bytes":%s},"errors":["no off-site copy: the verified dump shares a disk with the database it protects"]}\n' \
         "$(date -Is)" "$stamp" "$restored_tables" "$restored_migrations" \
         "$private_files" "$private_bytes" > "$STATUS"
fi

chmod 644 "$STATUS"
