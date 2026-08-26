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
from pathlib import Path
import stat
import sys
import tarfile

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

try:
    if not source.is_dir() or source.is_symlink():
        raise ValueError("source")

    files = []
    for root, directories, names in os.walk(source, topdown=True, followlinks=False):
        root_path = Path(root)
        for directory in directories:
            if (root_path / directory).is_symlink():
                raise ValueError("symlink")
        for name in names:
            path = root_path / name
            metadata = path.lstat()
            if not stat.S_ISREG(metadata.st_mode):
                raise ValueError("non-regular")
            relative = "/".join(path.relative_to(source).parts)
            digest = hashlib.sha256()
            size = 0
            flags = os.O_RDONLY | getattr(os, "O_BINARY", 0)
            if hasattr(os, "O_NOFOLLOW"):
                flags |= os.O_NOFOLLOW
            descriptor = os.open(path, flags)
            try:
                opened = os.fstat(descriptor)
                if not stat.S_ISREG(opened.st_mode):
                    raise ValueError("non-regular")
                with os.fdopen(descriptor, "rb", closefd=False) as stream:
                    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                        digest.update(chunk)
                        size += len(chunk)
            finally:
                os.close(descriptor)
            files.append({"path": relative, "bytes": size, "sha256": digest.hexdigest()})

    files.sort(key=lambda row: row["path"])
    manifest = json.dumps(
        {"version": 1, "files": files}, separators=(",", ":"), sort_keys=True
    ).encode("utf-8")

    with tarfile.open(output, "w:gz", format=tarfile.PAX_FORMAT) as archive:
        info = tarfile.TarInfo("manifest.v1.json")
        info.size = len(manifest)
        info.mode = 0o600
        archive.addfile(info, io.BytesIO(manifest))

        for row in files:
            path = source.joinpath(*row["path"].split("/"))
            flags = os.O_RDONLY | getattr(os, "O_BINARY", 0)
            if hasattr(os, "O_NOFOLLOW"):
                flags |= os.O_NOFOLLOW
            descriptor = os.open(path, flags)
            try:
                opened = os.fstat(descriptor)
                if not stat.S_ISREG(opened.st_mode) or opened.st_size != row["bytes"]:
                    raise ValueError("changed")
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
                        raise ValueError("changed")
            finally:
                os.close(descriptor)
except Exception:
    try:
        output.unlink(missing_ok=True)
    except Exception:
        pass
    print("private-file archive creation failed", file=sys.stderr)
    sys.exit(1)
PY
}

encrypt_private_files() (
  local source=$1
  local output=$2
  local keyfile=$3
  local work
  local complete=false

  work=$(mktemp -d) || exit 1
  cleanup_private_work() {
    rm -rf -- "$work"
    [[ "$complete" == true ]] || rm -f -- "$output"
  }
  trap cleanup_private_work EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  rm -f -- "$output"

  if ! build_private_archive "$source" "$work/private-files.tar.gz"; then
    exit 1
  fi

  gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
      --passphrase-file "$keyfile" --output "$output" "$work/private-files.tar.gz" \
    || exit 1
  complete=true
)

# The test entry point exercises the same creator and encryption path as the
# nightly job while avoiding databases, /var, ssh and production configuration.
if [[ "${1:-}" == "--private-files-fixture" ]]; then
  [[ $# -eq 4 && -d "$2" && -r "$4" ]] || {
    echo "private-file fixture setup failed" >&2
    exit 1
  }
  umask 077
  encrypt_private_files "$2" "$3" "$4"
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
KEEP_LOCAL_DAYS=14
KEEP_REMOTE_DAYS=45
STAMP=$(date +%F)
STATUS=/var/www/ezbizi/storage/app/backup-status.json
KEYFILE=/root/.ezbizi-backup.key
REMOTE_HOST=${EZBIZI_BACKUP_HOST:-ubuntu@32.194.222.151}
REMOTE_DIR=${EZBIZI_BACKUP_DIR:-/home/ubuntu/ezbizi-backups}
REMOTE_KEY=${EZBIZI_BACKUP_SSH_KEY:-/root/.ssh/ezbizi-backup}

errors=()

note() { echo "$(date -Is) $*" >> /var/log/ezbizi-backup.log; }

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
  printf '{"ok":false,"at":"%s","stage":"setup","errors":["missing %s"]}\n' \
         "$(date -Is)" "$KEYFILE" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
fi

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
  printf '{"ok":false,"at":"%s","stage":"backup","errors":["cannot list databases"]}
'          "$(date -Is)" > "$STATUS"
  chmod 644 "$STATUS"
  exit 1
fi

for db in $databases; do

  out="$DEST/db-${db}-${STAMP}.sql.gz.gpg"

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
    -C /var/www/ezbizi .env storage \
    2>/dev/null | encrypt_to "$DEST/app-${STAMP}.tar.gz.gpg"

all_ok "${PIPESTATUS[@]}" || errors+=("app-archive")

# `storage` remains in the application archive above to preserve the existing
# backup exactly. This second encrypted archive gives customer files the
# independently verifiable, versioned manifest that a restore can prove.
if ! encrypt_private_files \
    /var/www/ezbizi/storage/app/private \
    "$DEST/private-files-${STAMP}.tar.gz.gpg" \
    "$KEYFILE"; then
  errors+=("private-files-archive")
fi

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
    2>/dev/null | encrypt_to "$DEST/config-${STAMP}.tar.gz.gpg"

# Was PIPESTATUS[1] only, so tar's own exit status was discarded: a missing
# path or an unreadable file produced a partial archive that encrypted cleanly,
# checksummed cleanly and uploaded cleanly.
all_ok "${PIPESTATUS[@]}" || errors+=("config-archive")

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
# Only after a clean run. Deleting a fortnight-old backup on the strength of a
# night that produced nothing is how a bad week becomes an unrecoverable one.
if [[ ${#errors[@]} -eq 0 ]]; then
  find "$DEST" -type f -mtime +$KEEP_LOCAL_DAYS ! -name '*-01.*' -delete 2>/dev/null
else
  note "retention skipped — tonight's run had errors"
fi

# ── 7. the verdict ───────────────────────────────────────────────
if [[ ${#errors[@]} -eq 0 ]]; then
  printf '{"ok":true,"at":"%s","stage":"backup","size":"%s","files":%s,"errors":[]}\n' \
         "$(date -Is)" "$(du -sh "$DEST" | cut -f1)" "$(ls -1 "$DEST" | wc -l)" > "$STATUS"
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

  printf '{"ok":false,"at":"%s","stage":"backup","errors":[%s]}\n' \
         "$(date -Is)" "${joined%,}" > "$STATUS"
  note "backup FAILED: ${errors[*]}"
fi

chmod 644 "$STATUS"
[[ ${#errors[@]} -eq 0 ]]
