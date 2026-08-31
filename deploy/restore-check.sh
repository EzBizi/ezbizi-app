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

backup_source_decision() {
  python3 - "$1" <<'PY'
import json
from pathlib import Path
import re
import sys

try:
    status = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    run = status.get("run")
    errors = status.get("errors")
    if (
        status.get("stage") != "backup"
        or not isinstance(run, str)
        or re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z-[A-Za-z0-9_-]+", run) is None
        or not isinstance(errors, list)
        or any(not isinstance(error, str) for error in errors)
    ):
        raise ValueError("status")
    if status.get("ok") is True and not errors:
        source = "offsite"
    elif status.get("ok") is False and errors and set(errors) <= {"offsite-copy", "offsite-no-key"}:
        source = "local"
    else:
        raise ValueError("failed-backup")
    print(f"{source} {run}")
except Exception:
    print("backup source decision failed", file=sys.stderr)
    sys.exit(1)
PY
}

verify_private_encrypted_archive() {
  local encrypted=$1
  local keyfile=$2
  local work=$3

  # Operational ceilings, not tenant settings. They can be raised by the
  # deployment environment as storage grows, but malformed input never gets an
  # unbounded member list, manifest, expansion, path or disk allocation.
  local max_encrypted=${EZBIZI_PRIVATE_MAX_ENCRYPTED_BYTES:-68719476736}
  local max_members=${EZBIZI_PRIVATE_MAX_MEMBERS:-250001}
  local max_rows=${EZBIZI_PRIVATE_MAX_MANIFEST_ROWS:-250000}
  local max_manifest=${EZBIZI_PRIVATE_MAX_MANIFEST_BYTES:-67108864}
  local max_total=${EZBIZI_PRIVATE_MAX_TOTAL_BYTES:-274877906944}
  local max_file=${EZBIZI_PRIVATE_MAX_FILE_BYTES:-17179869184}
  local max_path=${EZBIZI_PRIVATE_MAX_PATH_BYTES:-100}
  local max_depth=${EZBIZI_PRIVATE_MAX_PATH_DEPTH:-16}
  local max_ratio=${EZBIZI_PRIVATE_MAX_COMPRESSION_RATIO:-1000}
  local min_free=${EZBIZI_PRIVATE_MIN_FREE_BYTES:-1073741824}
  local min_inodes=${EZBIZI_PRIVATE_MIN_FREE_INODES:-1024}
  local value

  for value in "$max_encrypted" "$max_members" "$max_rows" "$max_manifest" \
      "$max_total" "$max_file" "$max_path" "$max_depth" "$max_ratio" \
      "$min_free" "$min_inodes"; do
    [[ "$value" =~ ^[0-9]+$ ]] || {
      echo "private-file restore verification failed" >&2
      return 1
    }
  done

  [[ -r "$encrypted" && -r "$keyfile" ]] || {
    echo "private-file restore verification failed" >&2
    return 1
  }

  mkdir "$work" || return 1
  if ! python3 - "$encrypted" "$work" "$max_encrypted" "$min_free" "$min_inodes" <<'PY'
import os
from pathlib import Path
import shutil
import sys

try:
    encrypted = Path(sys.argv[1])
    destination = Path(sys.argv[2])
    maximum = int(sys.argv[3])
    reserve_bytes = int(sys.argv[4])
    reserve_inodes = int(sys.argv[5])
    size = encrypted.stat().st_size
    if size <= 0 or size > maximum:
        raise ValueError("encrypted-size")
    if shutil.disk_usage(destination).free < size + reserve_bytes:
        raise ValueError("disk")
    if hasattr(os, "statvfs"):
        if os.statvfs(destination).f_favail < 1 + reserve_inodes:
            raise ValueError("inodes")
    elif "EZBIZI_PRIVATE_MIN_FREE_INODES" in os.environ:
        raise ValueError("inodes-unavailable")
except Exception:
    print("private-file restore verification failed", file=sys.stderr)
    sys.exit(1)
PY
  then
    return 1
  fi

  if ! gpg --batch --quiet --decrypt --passphrase-file "$keyfile" \
      --output "$work/archive.tar.gz" "$encrypted" 2>/dev/null; then
    echo "private-file restore verification failed" >&2
    return 1
  fi

  python3 - "$work/archive.tar.gz" "$work/restored" \
      "$max_encrypted" "$max_members" "$max_rows" "$max_manifest" \
      "$max_total" "$max_file" "$max_path" "$max_depth" "$max_ratio" \
      "$min_free" "$min_inodes" <<'PY'
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import sys
import tarfile

archive_path = Path(sys.argv[1])
restore_root = Path(sys.argv[2])
(
    max_compressed,
    max_members,
    max_rows,
    max_manifest,
    max_total,
    max_file,
    max_path,
    max_depth,
    max_ratio,
    min_free,
    min_inodes,
) = (int(value) for value in sys.argv[3:])


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate-key")
        result[key] = value
    return result


def canonical_relative_path(value):
    if not isinstance(value, str) or not value or "\\" in value or "\x00" in value:
        raise ValueError("path")
    path = PurePosixPath(value)
    rendered = "/".join(path.parts)
    if (
        path.is_absolute()
        or value != rendered
        or any(part in ("", ".", "..") for part in path.parts)
        or len(value.encode("utf-8")) > max_path
        or len(path.parts) > max_depth
    ):
        raise ValueError("path")
    return rendered


def safe_member(member, expected_name):
    if member is None or not member.isreg() or member.pax_headers:
        raise ValueError("member")
    if member.name != expected_name or canonical_relative_path(member.name) != member.name:
        raise ValueError("member-name")


def available_for(total_bytes, file_count):
    if shutil.disk_usage(restore_root.parent).free < total_bytes + min_free:
        raise ValueError("disk")
    if hasattr(os, "statvfs"):
        if os.statvfs(restore_root.parent).f_favail < file_count + min_inodes:
            raise ValueError("inodes")
    elif "EZBIZI_PRIVATE_MIN_FREE_INODES" in os.environ:
        raise ValueError("inodes-unavailable")


try:
    compressed_size = archive_path.stat().st_size
    if compressed_size <= 0 or compressed_size > max_compressed:
        raise ValueError("compressed-size")

    with tarfile.open(archive_path, "r|gz") as archive:
        members = iter(archive)
        member_count = 1
        manifest_member = next(members, None)
        if member_count > max_members:
            raise ValueError("members")
        safe_member(manifest_member, "manifest.v1.json")
        if manifest_member.size > max_manifest:
            raise ValueError("manifest-size")
        manifest_stream = archive.extractfile(manifest_member)
        if manifest_stream is None:
            raise ValueError("manifest")
        manifest_bytes = manifest_stream.read(max_manifest + 1)
        manifest_stream.close()
        if len(manifest_bytes) != manifest_member.size or len(manifest_bytes) > max_manifest:
            raise ValueError("manifest-size")
        manifest = json.loads(
            manifest_bytes.decode("utf-8"), object_pairs_hook=unique_object
        )
        if not isinstance(manifest, dict) or set(manifest) != {"version", "files"}:
            raise ValueError("manifest-shape")
        if manifest["version"] != 1 or not isinstance(manifest["files"], list):
            raise ValueError("manifest-version")

        if len(manifest["files"]) > max_rows:
            raise ValueError("rows")

        rows = []
        paths = set()
        total_expected = 0
        for row in manifest["files"]:
            if not isinstance(row, dict) or set(row) != {"path", "bytes", "sha256"}:
                raise ValueError("row-shape")
            path = row["path"]
            byte_count = row["bytes"]
            digest = row["sha256"]
            canonical_relative_path(path)
            if path in paths:
                raise ValueError("row-path")
            if (
                isinstance(byte_count, bool)
                or not isinstance(byte_count, int)
                or byte_count < 0
                or byte_count > max_file
            ):
                raise ValueError("row-size")
            if not isinstance(digest, str) or re.fullmatch(r"[0-9a-f]{64}", digest) is None:
                raise ValueError("row-hash")
            paths.add(path)
            rows.append((path, byte_count, digest))
            total_expected += byte_count
            if total_expected > max_total:
                raise ValueError("total-size")

        if [row[0] for row in rows] != sorted(paths):
            raise ValueError("row-order")
        if total_expected > compressed_size * max_ratio:
            raise ValueError("compression-ratio")
        available_for(total_expected, len(rows))

        restore_root.mkdir(mode=0o700)
        resolved_root = restore_root.resolve()
        total_bytes = 0
        for path, byte_count, expected_digest in rows:
            member_count += 1
            if member_count > max_members:
                raise ValueError("members")
            member = next(members, None)
            safe_member(member, "files/" + path)
            if member.size != byte_count or member.size > max_file:
                raise ValueError("entry-size")
            target = restore_root.joinpath(*PurePosixPath(path).parts)
            target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            if not target.resolve(strict=False).is_relative_to(resolved_root):
                raise ValueError("target")
            source = archive.extractfile(member)
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
                        if written + len(chunk) > byte_count or total_bytes + written + len(chunk) > max_total:
                            raise ValueError("content-size")
                        output.write(chunk)
                        digest.update(chunk)
                        written += len(chunk)
            finally:
                os.close(descriptor)
                source.close()
            if written != byte_count or digest.hexdigest() != expected_digest:
                raise ValueError("content")
            total_bytes += written

        if next(members, None) is not None:
            raise ValueError("extra-member")

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

if [[ "${1:-}" == "--source-selection-fixture" ]]; then
  [[ $# -eq 4 ]] || {
    echo "backup source fixture setup failed" >&2
    exit 1
  }
  decision=$(backup_source_decision "$2") || exit 1
  read -r source run <<< "$decision"
  chosen=$3
  [[ "$source" == "offsite" ]] && chosen=$4
  [[ -r "$chosen/manifest-${run}.txt" ]] || {
    echo "backup source fixture selection failed" >&2
    exit 1
  }
  printf '%s %s\n' "$source" "$run"
  exit 0
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

  printf '{"ok":false,"at":"%s","stage":"restore-check","run":"%s","errors":["%s"]}\n' \
         "$(date -Is)" "${run:-unknown}" "$safe" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
}

# ── 1. select this exact committed run ───────────────────────────
#
# Date-only "newest" selection can bless an older remote success after a newer
# same-day run failed to copy. backup.sh names and commits every artifact with a
# unique run identity; its status decides whether this checker may use that
# exact remote set or must verify the exact local set and report local-only.
decision=$(backup_source_decision "$STATUS" 2>/dev/null) \
  || fail "the current backup run is incomplete or cannot be selected"
read -r source run <<< "$decision"
offsite=true
[[ "$source" == "local" ]] && offsite=false
stamp=${run:0:10}

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

# ── 2. fetch and verify the committed set ────────────────────────
manifest_name="manifest-${run}.txt"
db_name="db-${LIVE}-${run}.sql.gz.gpg"
private_name="private-files-${run}.tar.gz.gpg"
manifest_file="$WORK/$manifest_name"
file="$WORK/$db_name"
private_file="$WORK/$private_name"

if [[ "$offsite" == true ]]; then
  if ! scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
      "$REMOTE_HOST:$REMOTE_DIR/$manifest_name" "$manifest_file" 2>/dev/null \
    || ! ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
      "cd $REMOTE_DIR && sha256sum -c '$manifest_name' --quiet" 2>/dev/null \
    || ! scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
      "$REMOTE_HOST:$REMOTE_DIR/$db_name" "$file" 2>/dev/null \
    || ! scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
      "$REMOTE_HOST:$REMOTE_DIR/$private_name" "$private_file" 2>/dev/null; then
    # A temporarily unreachable remote still leaves a useful local restore, but
    # it is explicitly unhealthy and cannot become offsite:true.
    offsite=false
    rm -f -- "$manifest_file" "$file" "$private_file"
  fi
fi

if [[ "$offsite" == false ]]; then
  [[ -r "$LOCAL_DIR/$manifest_name" ]] \
    || fail "the current local backup manifest is missing"
  (cd "$LOCAL_DIR" && sha256sum -c "$manifest_name" --quiet) 2>/dev/null \
    || fail "the current local backup set is incomplete"
  cp "$LOCAL_DIR/$manifest_name" "$manifest_file" 2>/dev/null \
    || fail "the current local backup manifest could not be read"
  cp "$LOCAL_DIR/$db_name" "$file" 2>/dev/null \
    || fail "the current local database archive could not be read"
  cp "$LOCAL_DIR/$private_name" "$private_file" 2>/dev/null \
    || fail "the current local private-file archive could not be read"
fi

recorded=$(python3 - "$manifest_file" "$run" "$db_name" "$private_name" <<'PY'
from pathlib import Path
import re
import sys

try:
    lines = Path(sys.argv[1]).read_text(encoding="utf-8").splitlines()
    run, database, private = sys.argv[2:]
    if not lines or lines[0] != f"# EZBIZI-BACKUP-MANIFEST\t1\t{run}":
        raise ValueError("header")
    entries = {}
    for line in lines[1:]:
        match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9._-]+)", line)
        if match is None or match.group(2) in entries or f"-{run}." not in match.group(2):
            raise ValueError("entry")
        entries[match.group(2)] = match.group(1)
    required = {
        database,
        private,
        f"app-{run}.tar.gz.gpg",
        f"config-{run}.tar.gz.gpg",
    }
    if not required <= set(entries):
        raise ValueError("missing")
    print(entries[database], entries[private])
except Exception:
    sys.exit(1)
PY
) || fail "the current backup manifest is invalid"

read -r db_recorded private_recorded <<< "$recorded"
db_actual=$(sha256sum "$file" | awk '{print $1}')
private_actual=$(sha256sum "$private_file" | awk '{print $1}')
[[ "$db_recorded" == "$db_actual" ]] || fail "database archive checksum mismatch"
[[ "$private_recorded" == "$private_actual" ]] || fail "private-file archive checksum mismatch"

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
#
# THE LIST IS DERIVED, and it has to be. It used to name six tables —
# organizations, companies, franchises, users, roles, permissions — and those
# six are the FOUNDATION set, the first thing any dump writes. A dump
# interrupted partway through leaves exactly those full and everything after
# them empty, so this check passed on the single failure mode it exists to
# catch. It could not go red. The schema has 139 created tables across 89
# migrations; six of them were being asked to speak for all of it.
#
# Found by the audit session on 2026-08-28 — the same shape as the deploy
# gate's hand-written wipe list found the same day, and the same direction of
# failure: a maintained list standing in for a fact the system already knows,
# green when it should be red.
#
# COUNT(*) per table rather than information_schema.TABLE_ROWS, which is an
# estimate for InnoDB and cheerfully reports 0 for a small non-empty table —
# the one reading this check cannot afford to get wrong.
empty=""
for table in $(q "SELECT table_name FROM information_schema.tables \
                  WHERE table_schema='$LIVE' AND table_type='BASE TABLE' \
                  ORDER BY table_name;"); do
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

  printf '{"ok":true,"at":"%s","stage":"restore-check","run":"%s","from":"%s","offsite":true,"tables":%s,"migrations":%s,"private_files":{"manifest_version":1,"files":%s,"bytes":%s},"errors":[]}\n' \
         "$(date -Is)" "$run" "$stamp" "$restored_tables" "$restored_migrations" \
         "$private_files" "$private_bytes" > "$STATUS"
else
  note "restore ok from $stamp (LOCAL ONLY) — no off-site copy"

  printf '{"ok":false,"at":"%s","stage":"restore-check","run":"%s","from":"%s","offsite":false,"tables":%s,"migrations":%s,"private_files":{"manifest_version":1,"files":%s,"bytes":%s},"errors":["no off-site copy: the verified dump shares a disk with the database it protects"]}\n' \
         "$(date -Is)" "$run" "$stamp" "$restored_tables" "$restored_migrations" \
         "$private_files" "$private_bytes" > "$STATUS"
fi

chmod 644 "$STATUS"
