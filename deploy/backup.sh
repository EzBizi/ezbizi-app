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

# Build the customer-file archive without ever writing its manifest beside the
# encrypted backup. The manifest contains relative customer paths, so it belongs
# inside the ciphertext with the bytes it describes.
build_private_archive() {
  local source=$1
  local output=$2

  python3 - "$source" "$output" <<'PY'
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import stat
import sys
import tarfile
import time

source = Path(sys.argv[1])
output = Path(sys.argv[2])


class HashingReader:
    def __init__(self, stream):
        self.stream = stream
        self.digest = hashlib.sha256()
        self.bytes = 0

    def read(self, size=-1):
        chunk = self.stream.read(size)
        self.digest.update(chunk)
        self.bytes += len(chunk)
        return chunk


def identity(metadata):
    return (
        metadata.st_dev,
        metadata.st_ino,
        metadata.st_mode,
        metadata.st_size,
        metadata.st_mtime_ns,
    )


def canonical(path):
    rendered = "/".join(PurePosixPath(path).parts)
    if (
        not path
        or path != rendered
        or "\\" in path
        or "\x00" in path
        or PurePosixPath(path).is_absolute()
        or any(part in ("", ".", "..") for part in PurePosixPath(path).parts)
    ):
        raise ValueError("path")
    return rendered


def read_descriptor(descriptor):
    digest = hashlib.sha256()
    size = 0
    with os.fdopen(descriptor, "rb", closefd=False) as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
            size += len(chunk)
    return size, digest.hexdigest()


def posix_snapshot(root, hold_descriptors):
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    root_descriptor = os.open(root, flags)
    held = []
    try:
        root_metadata = os.fstat(root_descriptor)
        if not stat.S_ISDIR(root_metadata.st_mode):
            raise ValueError("source")
        rows = []
        directories_seen = []
        for walk_root, directories, names, walk_descriptor in os.fwalk(
            ".", topdown=True, follow_symlinks=False, dir_fd=root_descriptor
        ):
            relative_root = "" if walk_root == "." else "/".join(Path(walk_root).parts)
            stable_descriptor = os.dup(walk_descriptor) if hold_descriptors else walk_descriptor
            if hold_descriptors:
                held.append(stable_descriptor)
            directory_metadata = os.fstat(stable_descriptor)
            directories_seen.append((relative_root, identity(directory_metadata)))

            for directory in directories:
                metadata = os.stat(directory, dir_fd=stable_descriptor, follow_symlinks=False)
                if not stat.S_ISDIR(metadata.st_mode):
                    raise ValueError("non-directory")

            for name in names:
                relative = canonical(f"{relative_root}/{name}".lstrip("/"))
                metadata = os.stat(name, dir_fd=stable_descriptor, follow_symlinks=False)
                if not stat.S_ISREG(metadata.st_mode):
                    raise ValueError("non-regular")
                open_flags = os.O_RDONLY | os.O_NOFOLLOW
                descriptor = os.open(name, open_flags, dir_fd=stable_descriptor)
                try:
                    opened = os.fstat(descriptor)
                    if not stat.S_ISREG(opened.st_mode) or identity(opened) != identity(metadata):
                        raise ValueError("changed")
                    size, digest = read_descriptor(descriptor)
                    after = os.fstat(descriptor)
                    if identity(after) != identity(opened) or size != opened.st_size:
                        raise ValueError("changed")
                finally:
                    os.close(descriptor)
                rows.append(
                    {
                        "path": relative,
                        "bytes": size,
                        "sha256": digest,
                        "identity": identity(opened),
                        "dirfd": stable_descriptor if hold_descriptors else None,
                        "name": name,
                    }
                )
        rows.sort(key=lambda row: row["path"])
        directories_seen.sort()
        return identity(root_metadata), directories_seen, rows, held
    except Exception:
        for descriptor in held:
            os.close(descriptor)
        raise
    finally:
        os.close(root_descriptor)


def portable_snapshot(root):
    if not root.is_dir() or root.is_symlink():
        raise ValueError("source")
    rows = []
    directories_seen = []
    root_metadata = root.stat()
    for walk_root, directories, names in os.walk(root, topdown=True, followlinks=False):
        root_path = Path(walk_root)
        relative_root = "/".join(root_path.relative_to(root).parts)
        metadata = root_path.lstat()
        if not stat.S_ISDIR(metadata.st_mode):
            raise ValueError("non-directory")
        directories_seen.append((relative_root, identity(metadata)))
        for directory in directories:
            child = root_path / directory
            if not stat.S_ISDIR(child.lstat().st_mode):
                raise ValueError("non-directory")
        for name in names:
            path = root_path / name
            relative = canonical("/".join(path.relative_to(root).parts))
            before = path.lstat()
            if not stat.S_ISREG(before.st_mode):
                raise ValueError("non-regular")
            descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_BINARY", 0))
            try:
                opened = os.fstat(descriptor)
                if identity(opened) != identity(before):
                    raise ValueError("changed-portable-open")
                size, digest = read_descriptor(descriptor)
                after = os.fstat(descriptor)
                if identity(after) != identity(opened) or size != opened.st_size:
                    raise ValueError("changed-portable-read")
            finally:
                os.close(descriptor)
            rows.append(
                {
                    "path": relative,
                    "bytes": size,
                    "sha256": digest,
                    "identity": identity(opened),
                    "path_object": path,
                }
            )
    rows.sort(key=lambda row: row["path"])
    directories_seen.sort()
    return identity(root_metadata), directories_seen, rows, []


def public_snapshot(snapshot):
    root_identity, directories_seen, rows, _ = snapshot
    public_rows = [
        (row["path"], row["bytes"], row["sha256"], row["identity"])
        for row in rows
    ]
    return root_identity, directories_seen, public_rows


def take_snapshot(root, hold=False):
    if os.name == "posix" and hasattr(os, "fwalk") and hasattr(os, "O_NOFOLLOW"):
        return posix_snapshot(root, hold)
    return portable_snapshot(root)


def fixture_pause():
    pause = os.environ.get("EZBIZI_PRIVATE_FIXTURE_PAUSE")
    if os.environ.get("EZBIZI_PRIVATE_FIXTURE_MODE") != "1" or not pause:
        return
    Path(pause + ".ready").touch()
    deadline = time.monotonic() + 10
    continuation = Path(pause + ".continue")
    while not continuation.exists() and time.monotonic() < deadline:
        time.sleep(0.02)
    if not continuation.exists():
        raise ValueError("fixture-timeout")


try:
    first = take_snapshot(source, hold=True)
    files = first[2]
    manifest = json.dumps(
        {
            "version": 1,
            "files": [
                {"path": row["path"], "bytes": row["bytes"], "sha256": row["sha256"]}
                for row in files
            ],
        },
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")

    with tarfile.open(output, "w:gz", format=tarfile.USTAR_FORMAT) as archive:
        info = tarfile.TarInfo("manifest.v1.json")
        info.size = len(manifest)
        info.mode = 0o600
        archive.addfile(info, io.BytesIO(manifest))

        for row in files:
            if row.get("dirfd") is not None:
                descriptor = os.open(row["name"], os.O_RDONLY | os.O_NOFOLLOW, dir_fd=row["dirfd"])
            else:
                descriptor = os.open(
                    row["path_object"], os.O_RDONLY | getattr(os, "O_BINARY", 0)
                )
            try:
                opened = os.fstat(descriptor)
                if not stat.S_ISREG(opened.st_mode) or identity(opened) != row["identity"]:
                    raise ValueError("changed-archive-open")
                info = tarfile.TarInfo("files/" + row["path"])
                info.size = row["bytes"]
                info.mode = 0o600
                with os.fdopen(descriptor, "rb", closefd=False) as stream:
                    hashing_stream = HashingReader(stream)
                    archive.addfile(info, hashing_stream)
                    if (
                        hashing_stream.bytes != row["bytes"]
                        or hashing_stream.digest.hexdigest() != row["sha256"]
                    ):
                        raise ValueError("changed-archive-content")
                if identity(os.fstat(descriptor)) != row["identity"]:
                    raise ValueError("changed-archive-after")
            finally:
                os.close(descriptor)
    fixture_pause()
    final = take_snapshot(source, hold=False)
    if public_snapshot(final) != public_snapshot(first):
        raise ValueError("changed-final")
except Exception:
    try:
        output.unlink(missing_ok=True)
    except Exception:
        pass
    print("private-file archive creation failed", file=sys.stderr)
    sys.exit(1)
finally:
    if "first" in locals():
        for descriptor in first[3]:
            os.close(descriptor)
PY
}

encrypt_private_files() (
  local source=$1
  local output=$2
  local keyfile=$3
  local work
  local staged
  local complete=false

  work=$(mktemp -d) || exit 1
  staged=$(mktemp "$(dirname "$output")/.private-files.XXXXXX") || exit 1
  chmod 0600 "$staged" || exit 1
  cleanup_private_work() {
    rm -rf -- "$work"
    rm -f -- "$staged"
  }
  trap cleanup_private_work EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  if ! build_private_archive "$source" "$work/private-files.tar.gz"; then
    exit 1
  fi

  gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
      --passphrase-file "$keyfile" --output "$staged" "$work/private-files.tar.gz" \
    || exit 1
  python3 - "$staged" <<'PY' || exit 1
import os
import sys

descriptor = os.open(sys.argv[1], os.O_RDWR)
try:
    os.fsync(descriptor)
finally:
    os.close(descriptor)
PY
  mv -f -- "$staged" "$output" || exit 1
  python3 - "$(dirname "$output")" <<'PY' || exit 1
import os
import sys

if os.name == "posix":
    descriptor = os.open(sys.argv[1], os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
PY
  complete=true
)

# Write the run's public commit marker. Kept as one function so the disposable
# fixture exercises the identical filename/checksum grammar used in production.
write_run_manifest() {
  local work=$1
  local run=$2
  local output=$3

  {
    printf '# EZBIZI-BACKUP-MANIFEST\t1\t%s\n' "$run"
    (cd "$work" && sha256sum --text -- *.gpg | sort)
  } > "$output"
}

# The test entry point exercises the same creator and encryption path as the
# nightly job while avoiding databases, /var, ssh and production configuration.
if [[ "${1:-}" == "--private-files-fixture" ]]; then
  [[ $# -eq 4 && -d "$2" && -r "$4" ]] || {
    echo "private-file fixture setup failed" >&2
    exit 1
  }
  umask 077
  export EZBIZI_PRIVATE_FIXTURE_MODE=1
  encrypt_private_files "$2" "$3" "$4"
  exit $?
fi

if [[ "${1:-}" == "--outer-manifest-fixture" ]]; then
  [[ $# -eq 4 && -d "$2" ]] || {
    echo "outer-manifest fixture setup failed" >&2
    exit 1
  }
  write_run_manifest "$2" "$3" "$4"
  exit $?
fi

# Nothing this script writes is for anyone but root.
#
# The default umask is 022, which made every artefact -rw-r--r-- in a
# world-traversable directory. That is survivable for the encrypted files and
# was not for the ones written before encryption landed: an unencrypted
# app-*.tar.gz carries `.env`, which carries APP_KEY — the key that makes every
# TOTP secret in a dump readable. Any local account on the box could read it.
#
# The plaintext archives are gone and encryption is now the only path, so this
# is the belt to that braces: even a future artefact written before it reaches
# encrypt_to is unreadable to anyone else.
umask 077
install -d -m 0700 /var/backups/ezbizi

DEST=/var/backups/ezbizi
# Jose 2026-11-19: 7 nights on the app box, 3 off-site for the private files
# (40 GB a night by then), and ONE month-first copy kept beyond those.
# find -mtime +N matches files at least N+1 whole days old, so the prunes use
# N-1: KEEP_LOCAL_DAYS=7 keeps 7 nights, not 8 (desk 11-20: 11-13 survived).
KEEP_LOCAL_DAYS=7
KEEP_REMOTE_FILES_DAYS=3
KEEP_MONTHLY_DAYS=32
# 10, not 45 (2026-11-13): nightly archives reached 16-32 GB and 45 of them
# filled the websites box to 95%, starving its own Plesk backups. Month-firsts
# are still kept beyond it. storage/app/private travels only in the
# private-files archive (it was in the app archive too, so twice a night).
KEEP_REMOTE_DAYS=10
# Above this, the websites box (which hosts the offsite copy) is too full.
REMOTE_DISK_ALERT_PCT=85
STAMP=$(date +%F)
RUN_ID=${EZBIZI_BACKUP_RUN_ID:-$(date -u +%FT%H%M%SZ)-$$-${RANDOM}}
STATUS=/var/www/ezbizi/storage/app/backup-status.json
KEYFILE=/root/.ezbizi-backup.key
REMOTE_HOST=${EZBIZI_BACKUP_HOST:-ubuntu@32.194.222.151}
REMOTE_DIR=${EZBIZI_BACKUP_DIR:-/home/ubuntu/ezbizi-backups}
REMOTE_KEY=${EZBIZI_BACKUP_SSH_KEY:-/root/.ssh/ezbizi-backup}

errors=()

note() { echo "$(date -Is) $*" >> /var/log/ezbizi-backup.log; }

if [[ ! "$RUN_ID" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{6}Z-[A-Za-z0-9_-]+$ ]]; then
  note "FATAL invalid backup run identity"
  exit 1
fi

# Every stage of a pipeline, checked. Reading one hand-picked PIPESTATUS index
# is how a failing producer stays invisible behind a happy consumer.
all_ok() { for s in "$@"; do [[ "$s" -ne 0 ]] && return 1; done; return 0; }

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
  printf '{"ok":false,"at":"%s","stage":"setup","run":"%s","errors":["missing backup key"]}\n' \
         "$(date -Is)" "$RUN_ID" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
fi

RUN_WORK=$(mktemp -d "$DEST/.run-${RUN_ID}.XXXXXX") || exit 1
cleanup_run_work() { rm -rf -- "$RUN_WORK"; }
trap cleanup_run_work EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Mark the attempt before any artifact work. If the process is killed, the
# restore checker refuses this non-committed run instead of blessing an older
# same-date remote set.
printf '{"ok":false,"at":"%s","stage":"backup-running","run":"%s","errors":["incomplete-run"]}\n' \
       "$(date -Is)" "$RUN_ID" > "$STATUS"
chmod 644 "$STATUS"

# ── 1. databases ─────────────────────────────────────────────────
#
# Enumerated into a variable FIRST, and checked. As a `for ... in $(mysql ...)`
# this swallowed its own failure twice over: a `for` list discards the command
# substitution's exit status, and 2>/dev/null hid the reason. A refused
# connection therefore produced an empty list, zero dumps, and a backup that
# reported success with no databases in it.
databases=$(mysql -N -e "SELECT schema_name FROM information_schema.schemata
      WHERE schema_name NOT IN ('information_schema','performance_schema','mysql','sys');" 2>&1)

if [[ $? -ne 0 || -z "$databases" ]]; then
  note "FATAL cannot list databases: ${databases:-empty result}"
  printf '{"ok":false,"at":"%s","stage":"backup","run":"%s","errors":["cannot list databases"]}\n' \
         "$(date -Is)" "$RUN_ID" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
fi

for db in $databases; do

  out="$RUN_WORK/db-${db}-${RUN_ID}.sql.gz.gpg"

  # PIPESTATUS, not $?: a pipeline reports only its last command, so a
  # mysqldump that died halfway would still look like a success as long as gpg
  # was happy encrypting the truncated stream. That is the single most likely
  # way to end up with a backup that restores into a half-empty database.
  mysqldump --single-transaction --routines --triggers --events \
            --no-tablespaces "$db" 2>/dev/null | gzip | encrypt_to "$out"

  # Every stage, not a chosen index. mysqldump | gzip | gpg is three, and the
  # middle one was not being read at all — a gzip that ran out of disk produced
  # a truncated file that gpg encrypted quite happily.
  if ! all_ok "${PIPESTATUS[@]}"; then
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
    -C /var/www/ezbizi --exclude=storage/app/private .env storage \
    2>/dev/null | encrypt_to "$RUN_WORK/app-${RUN_ID}.tar.gz.gpg"
app_rc=("${PIPESTATUS[@]}")

# GNU tar exits 1 for "file changed as we read it" — live storage (monitoring
# screenshots, temp dirs) moves during the read, and that failed every other
# night from 11-06. The archive is still complete for everything that held
# still; only 2+ is a real tar failure.
(( app_rc[0] == 1 )) && app_rc[0]=0
all_ok "${app_rc[@]}" || errors+=("app-archive")

# `storage` remains in the application archive above to preserve the existing
# backup exactly. This second encrypted archive gives customer files the
# independently verifiable, versioned manifest that a restore can prove.
#
# The builder refuses a tree that changes under it, and monitoring screenshots
# land every few minutes, so it reads a hard-linked copy: files added or pruned
# meanwhile leave the copy alone, and an in-place write still fails the check.
PRIVATE_STAGE="$RUN_WORK/private-stage"
if ! cp -al /var/www/ezbizi/storage/app/private "$PRIVATE_STAGE" \
  || ! encrypt_private_files \
    "$PRIVATE_STAGE" \
    "$RUN_WORK/private-files-${RUN_ID}.tar.gz.gpg" \
    "$KEYFILE"; then
  errors+=("private-files-archive")
fi
rm -rf -- "$PRIVATE_STAGE"

# ── 3. server configuration ──────────────────────────────────────
#
# renewal-hooks as well as renewal. The renewal configs say when to renew; the
# hooks are what makes a renewal reach anything. Without
# renewal-hooks/deploy/nginx-reload.sh a rebuilt box renews its certificates
# perfectly and serves the one nginx loaded at boot until it expires — a silent
# failure with a date on it, and the exact thing this box was already doing.
#
# tar keeps the executable bit, which matters here more than it looks: certbot
# ignores a hook that is not executable, so a hook restored without +x is a hook
# that is present, readable, reviewed, and never runs.
tar -czf - /etc/nginx/sites-available /etc/nginx/conf.d /etc/bind /var/lib/bind \
           /etc/letsencrypt/renewal /etc/letsencrypt/renewal-hooks \
           /etc/systemd/system/ezbizi-* /etc/cron.d \
    2>/dev/null | encrypt_to "$RUN_WORK/config-${RUN_ID}.tar.gz.gpg"

# Was PIPESTATUS[1] only, so tar's own exit status was discarded: a missing
# path or an unreadable file produced a partial archive that encrypted cleanly,
# checksummed cleanly and uploaded cleanly.
all_ok "${PIPESTATUS[@]}" || errors+=("config-archive")

# ── 4. commit one run, with its manifest last ────────────────────
#
# Artifacts are built under a mode-0700 sibling directory and carry one unique
# run identity. The manifest is the commit marker: it is fsynced and renamed
# last, so a crash or retry can leave only an unreferenced partial run, never a
# mixed set presented as complete. A prior same-day run has different names and
# remains usable until ordinary retention removes it.
published=false
manifest="$RUN_WORK/manifest-${RUN_ID}.txt"
if [[ ${#errors[@]} -eq 0 ]]; then
  write_run_manifest "$RUN_WORK" "$RUN_ID" "$RUN_WORK/.manifest.tmp" 2>/dev/null \
    && chmod 0600 "$RUN_WORK/.manifest.tmp" \
    && mv "$RUN_WORK/.manifest.tmp" "$manifest" \
    || errors+=("manifest")
fi

if [[ ${#errors[@]} -eq 0 ]]; then
  shopt -s nullglob
  staged_artifacts=("$RUN_WORK"/*.gpg)
  shopt -u nullglob
  [[ ${#staged_artifacts[@]} -gt 0 ]] || errors+=("manifest")

  for staged in "${staged_artifacts[@]}" "$manifest"; do
    [[ -e "$DEST/$(basename "$staged")" ]] && errors+=("publish-collision")
  done
fi

if [[ ${#errors[@]} -eq 0 ]]; then
  python3 - "$RUN_WORK" "${staged_artifacts[@]}" "$manifest" <<'PY' || errors+=("publish-sync")
import os
import sys

directory = sys.argv[1]
for path in sys.argv[2:]:
    descriptor = os.open(path, os.O_RDWR)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
descriptor = os.open(directory, os.O_RDONLY)
try:
    os.fsync(descriptor)
finally:
    os.close(descriptor)
PY
fi

if [[ ${#errors[@]} -eq 0 ]]; then
  for staged in "${staged_artifacts[@]}"; do
    mv "$staged" "$DEST/$(basename "$staged")" || errors+=("publish")
  done
  if [[ ${#errors[@]} -eq 0 ]]; then
    mv "$manifest" "$DEST/$(basename "$manifest")" || errors+=("publish")
  fi
  if [[ ${#errors[@]} -eq 0 ]]; then
    python3 - "$DEST" <<'PY' || errors+=("publish-sync")
import os
import sys

descriptor = os.open(sys.argv[1], os.O_RDONLY)
try:
    os.fsync(descriptor)
finally:
    os.close(descriptor)
PY
  fi
  [[ ${#errors[@]} -eq 0 ]] && published=true
fi

# ── 5. off this instance ─────────────────────────────────────────
if [[ "$published" == true && -r "$REMOTE_KEY" ]]; then
  shopt -s nullglob
  local_artifacts=("$DEST"/*-"${RUN_ID}".*.gpg)
  shopt -u nullglob
  ssh -i "$REMOTE_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
      "$REMOTE_HOST" "mkdir -p $REMOTE_DIR" 2>/dev/null \
    && scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
           "${local_artifacts[@]}" "$REMOTE_HOST:$REMOTE_DIR/" 2>/dev/null \
    && scp -i "$REMOTE_KEY" -o BatchMode=yes -q \
           "$DEST/manifest-${RUN_ID}.txt" "$REMOTE_HOST:$REMOTE_DIR/" 2>/dev/null \
    || errors+=("offsite-copy")

  # Pruned on the remote too. A backup destination that only ever grows is one
  # that eventually fills a disk belonging to somebody else's website.
  ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
      "find $REMOTE_DIR -type f -mtime +$((KEEP_REMOTE_DAYS - 1)) ! -name '*-01.*' ! -name '*-01T*' -delete;
       find $REMOTE_DIR -type f -name 'private-files-*' -mtime +$((KEEP_REMOTE_FILES_DAYS - 1)) ! -name '*-01T*' -delete;
       find $REMOTE_DIR -type f -name '*-01T*' -mtime +$KEEP_MONTHLY_DAYS -delete" \
      2>/dev/null || errors+=("offsite-prune")
elif [[ "$published" == true ]]; then
  errors+=("offsite-no-key")
fi

# ── 6. local retention ───────────────────────────────────────────
#
# Monthlies are kept: the failure this protects against is corruption nobody
# notices for a fortnight, which a fourteen-day window quietly discards.
# Only after a clean run. Deleting a fortnight-old backup on the strength of a
# night that produced nothing is how a bad week becomes an unrecoverable one.
if [[ ${#errors[@]} -eq 0 ]]; then
  find "$DEST" -type f -mtime +$((KEEP_LOCAL_DAYS - 1)) \
      ! -name '*-01.*' ! -name '*-01T*' -delete 2>/dev/null
  # One month-first only (Jose 11-19): the older one goes once a newer exists.
  find "$DEST" -type f -name '*-01T*' -mtime +$KEEP_MONTHLY_DAYS -delete 2>/dev/null
else
  note "retention skipped — tonight's run had errors"
fi

# A full destination is a failing backup that has not failed yet. Checked
# after retention, so the warning never stops tonight's pruning.
if [[ -r "$REMOTE_KEY" ]]; then
  remote_pct=$(ssh -i "$REMOTE_KEY" -o BatchMode=yes "$REMOTE_HOST" \
      "df --output=pcent / | tail -1 | tr -dc 0-9" 2>/dev/null)
  if [[ -n "$remote_pct" && "$remote_pct" -ge $REMOTE_DISK_ALERT_PCT ]]; then
    errors+=("offsite-disk-${remote_pct}pct")
  fi
fi

# ── 7. the verdict ───────────────────────────────────────────────
if [[ ${#errors[@]} -eq 0 ]]; then
  printf '{"ok":true,"at":"%s","stage":"backup","run":"%s","size":"%s","files":%s,"errors":[]}\n' \
         "$(date -Is)" "$RUN_ID" "$(du -sh "$DEST" | cut -f1)" \
         "$(ls -1 "$DEST" | wc -l)" > "$STATUS"
  note "backup ok size=$(du -sh "$DEST" | cut -f1)"
else
  # Sanitised, not escaped.
  #
  # Two attempts at joining this array have now produced invalid JSON — first
  # `IFS='","'` (IFS uses only its first character), then a sed pipeline whose
  # own quoting was wrong. The status file is how a failure gets REPORTED, so a
  # bug in it means a real failure is invisible; it is the last place that
  # should be clever.
  #
  # Every value here is a token this script wrote itself — `dump:ezbizi`,
  # `offsite-copy`. Reducing them to a safe character set cannot produce
  # unparseable JSON no matter what a database happens to be called.
  joined=""
  for e in "${errors[@]}"; do
    safe=$(printf '%s' "$e" | tr -c 'A-Za-z0-9 :._-' '?')
    joined="${joined}\"${safe}\","
  done

  printf '{"ok":false,"at":"%s","stage":"backup","run":"%s","errors":[%s]}\n' \
         "$(date -Is)" "$RUN_ID" "${joined%,}" > "$STATUS"
  note "backup FAILED: ${errors[*]}"
fi

chmod 644 "$STATUS"
[[ ${#errors[@]} -eq 0 ]]
