#!/usr/bin/env python3
"""Disposable proof for the encrypted private-file backup/restore contract."""

from __future__ import annotations

import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]
BACKUP = ROOT / "deploy" / "backup.sh"
RESTORE = ROOT / "deploy" / "restore-check.sh"
if os.name == "nt":
    BASH = os.environ.get("BASH_EXE", r"C:\Program Files\Git\bin\bash.exe")
    GPG = shutil.which("gpg") or r"C:\Program Files\Git\usr\bin\gpg.exe"
else:
    BASH = os.environ.get("BASH_EXE") or shutil.which("bash") or "/bin/bash"
    GPG = shutil.which("gpg") or "/usr/bin/gpg"


class PrivateFilesBackupRestoreFixtureTest(unittest.TestCase):
    SENSITIVE_FILENAME_MARKER = "fixture-sensitive-customer-filename.secret"
    SENSITIVE_VALUE_MARKER = "fixture-sensitive-customer-value"
    SECOND_PATH = "org-b/nested/other.bin"
    SECOND_CONTENTS = b"\x00\x01\x02"

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="ezbizi-private-files-")
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        (self.source / "org-a").mkdir()
        (self.source / "org-a" / self.SENSITIVE_FILENAME_MARKER).write_bytes(
            self.SENSITIVE_VALUE_MARKER.encode()
        )
        (self.source / "org-b" / "nested").mkdir(parents=True)
        (self.source / self.SECOND_PATH).write_bytes(self.SECOND_CONTENTS)
        self.key = self.root / "key"
        self.key.write_text("disposable-fixture-passphrase\n", encoding="utf-8")
        self.archive = self.root / "private-files-fixture.tar.gz.gpg"
        self.tmpdir = self.root / "tmp"
        self.tmpdir.mkdir()
        self.sentinel = self.tmpdir / "do-not-delete"
        self.sentinel.write_text("outside verifier work directory", encoding="utf-8")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def script_environment(self, extra: dict[str, str] | None = None) -> dict[str, str]:
        env = os.environ.copy()
        env["TMPDIR"] = str(self.tmpdir)
        if extra:
            env.update(extra)
        return env

    def run_script(
        self,
        script: Path,
        *args: Path | str,
        extra_env: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [BASH, str(script), *(str(value) for value in args)],
            cwd=ROOT,
            env=self.script_environment(extra_env),
            text=True,
            capture_output=True,
            timeout=30,
        )

    def create_with_backup(
        self,
        source: Path | None = None,
        archive: Path | None = None,
        extra_env: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            BACKUP,
            "--private-files-fixture",
            source or self.source,
            archive or self.archive,
            self.key,
            extra_env=extra_env,
        )

    def verify(
        self,
        archive: Path | None = None,
        key: Path | None = None,
        extra_env: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            RESTORE,
            "--private-files-fixture",
            archive or self.archive,
            key or self.key,
            extra_env=extra_env,
        )

    def select_source(
        self,
        status: Path,
        local: Path,
        remote: Path,
    ) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            RESTORE,
            "--source-selection-fixture",
            status,
            local,
            remote,
        )

    def create_outer_manifest(
        self,
        artifacts: Path,
        run: str,
        output: Path,
    ) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            BACKUP,
            "--outer-manifest-fixture",
            artifacts,
            run,
            output,
        )

    def encrypted_tar(
        self,
        name: str,
        manifest: dict,
        members: list[tuple[str, bytes, str]],
        archive_format: int = tarfile.USTAR_FORMAT,
    ) -> Path:
        plain = self.root / f"{name}.tar.gz"
        encrypted = self.root / f"{name}.tar.gz.gpg"
        with tarfile.open(plain, "w:gz", format=archive_format) as bundle:
            encoded = json.dumps(manifest, separators=(",", ":"), sort_keys=True).encode()
            info = tarfile.TarInfo("manifest.v1.json")
            info.size = len(encoded)
            info.mode = 0o600
            bundle.addfile(info, io.BytesIO(encoded))
            for path, contents, kind in members:
                info = tarfile.TarInfo("benign" if kind == "pax" else path)
                info.mode = 0o600
                if kind == "symlink":
                    info.type = tarfile.SYMTYPE
                    info.linkname = contents.decode("utf-8")
                    bundle.addfile(info)
                elif kind == "hardlink":
                    info.type = tarfile.LNKTYPE
                    info.linkname = contents.decode("utf-8")
                    bundle.addfile(info)
                elif kind == "fifo":
                    info.type = tarfile.FIFOTYPE
                    bundle.addfile(info)
                elif kind == "char":
                    info.type = tarfile.CHRTYPE
                    info.devmajor = 1
                    info.devminor = 3
                    bundle.addfile(info)
                elif kind == "pax":
                    info.size = len(contents)
                    info.pax_headers = {"path": path, "comment": self.SENSITIVE_VALUE_MARKER}
                    bundle.addfile(info, io.BytesIO(contents))
                else:
                    info.size = len(contents)
                    bundle.addfile(info, io.BytesIO(contents))
        subprocess.run(
            [
                GPG,
                "--batch",
                "--yes",
                "--quiet",
                "--symmetric",
                "--cipher-algo",
                "AES256",
                "--passphrase-file",
                str(self.key),
                "--output",
                str(encrypted),
                str(plain),
            ],
            check=True,
            capture_output=True,
        )
        plain.unlink()
        return encrypted

    @staticmethod
    def row(path: str, contents: bytes, *, size: int | None = None, digest: str | None = None) -> dict:
        return {
            "path": path,
            "bytes": len(contents) if size is None else size,
            "sha256": digest or hashlib.sha256(contents).hexdigest(),
        }

    def assert_generic_failure(self, result: subprocess.CompletedProcess[str]) -> None:
        self.assertNotEqual(0, result.returncode, result.stdout + result.stderr)
        combined = result.stdout + result.stderr
        self.assertNotIn(self.SENSITIVE_FILENAME_MARKER, combined)
        self.assertNotIn(self.SENSITIVE_VALUE_MARKER, combined)
        self.assertTrue(self.sentinel.exists(), "verifier deleted outside its own temporary directory")
        self.assertEqual(
            {self.sentinel},
            set(self.tmpdir.iterdir()),
            "failed operation left decrypted or plaintext temporary data behind",
        )

    def assert_archive_exactly_matches_source_without_using_the_verifier(self) -> None:
        plain = self.root / "independent-inspection.tar.gz"
        try:
            decrypted = subprocess.run(
                [
                    GPG,
                    "--batch",
                    "--yes",
                    "--quiet",
                    "--decrypt",
                    "--passphrase-file",
                    str(self.key),
                    "--output",
                    str(plain),
                    str(self.archive),
                ],
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertEqual(0, decrypted.returncode, decrypted.stdout + decrypted.stderr)

            sensitive_path = f"org-a/{self.SENSITIVE_FILENAME_MARKER}"
            expected_contents = {
                sensitive_path: self.SENSITIVE_VALUE_MARKER.encode(),
                self.SECOND_PATH: self.SECOND_CONTENTS,
            }
            expected_rows = [
                self.row(path, contents)
                for path, contents in sorted(expected_contents.items())
            ]
            expected_members = {
                "manifest.v1.json",
                *(f"files/{path}" for path in expected_contents),
            }

            with tarfile.open(plain, "r:gz") as bundle:
                members = bundle.getmembers()
                self.assertEqual(len(expected_members), len(members))
                self.assertEqual(expected_members, {member.name for member in members})
                self.assertTrue(all(member.isreg() for member in members))

                manifest_stream = bundle.extractfile("manifest.v1.json")
                self.assertIsNotNone(manifest_stream)
                manifest = json.loads(manifest_stream.read().decode("utf-8"))
                self.assertEqual({"version": 1, "files": expected_rows}, manifest)

                for path, expected in expected_contents.items():
                    payload = bundle.extractfile(f"files/{path}")
                    self.assertIsNotNone(payload)
                    self.assertEqual(expected, payload.read())
        finally:
            plain.unlink(missing_ok=True)

    def test_outer_manifest_uses_parser_compatible_basenames(self) -> None:
        run = "2026-08-27T230000Z-fixture"
        artifacts = self.root / "outer"
        artifacts.mkdir()
        names = {
            f"app-{run}.tar.gz.gpg",
            f"db-ezbizi-{run}.sql.gz.gpg",
        }
        for name in names:
            (artifacts / name).write_bytes(name.encode())
        output = self.root / "outer-manifest.txt"

        made = self.create_outer_manifest(artifacts, run, output)
        self.assertEqual(0, made.returncode, made.stdout + made.stderr)
        lines = output.read_text(encoding="utf-8").splitlines()
        self.assertEqual(f"# EZBIZI-BACKUP-MANIFEST\t1\t{run}", lines[0])
        parsed = []
        for line in lines[1:]:
            match = re.fullmatch(r"([0-9a-f]{64})  ([A-Za-z0-9._-]+)", line)
            self.assertIsNotNone(match, line)
            parsed.append(match.group(2))
        self.assertEqual(names, set(parsed))

    def test_valid_encrypted_archive_round_trips_and_cleans_its_temporary_directory(self) -> None:
        made = self.create_with_backup()
        self.assertEqual(0, made.returncode, made.stdout + made.stderr)
        self.assertTrue(self.archive.is_file())
        self.assert_archive_exactly_matches_source_without_using_the_verifier()

        checked = self.verify()
        self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)
        self.assertNotIn(self.SENSITIVE_FILENAME_MARKER, checked.stdout + checked.stderr)
        self.assertNotIn(self.SENSITIVE_VALUE_MARKER, checked.stdout + checked.stderr)
        self.assertTrue(self.sentinel.exists())
        self.assertEqual({self.sentinel}, set(self.tmpdir.iterdir()))

    def test_missing_archive_wrong_key_and_bad_encrypted_payload_fail(self) -> None:
        missing = self.verify(
            self.root
            / f"{self.SENSITIVE_FILENAME_MARKER}-{self.SENSITIVE_VALUE_MARKER}-missing.tar.gz.gpg"
        )
        self.assert_generic_failure(missing)

        self.assertEqual(0, self.create_with_backup().returncode)
        wrong_key = self.root / "wrong-key"
        wrong_key.write_text("wrong\n", encoding="utf-8")
        self.assert_generic_failure(self.verify(key=wrong_key))

        plain = self.root / "not-an-archive"
        plain.write_bytes(
            f"{self.SENSITIVE_FILENAME_MARKER}:{self.SENSITIVE_VALUE_MARKER}".encode()
        )
        bad = self.root / "bad-payload.gpg"
        subprocess.run(
            [GPG, "--batch", "--yes", "--quiet", "--symmetric", "--cipher-algo", "AES256",
             "--passphrase-file", str(self.key), "--output", str(bad), str(plain)],
            check=True,
            capture_output=True,
        )
        self.assert_generic_failure(self.verify(bad))

    def test_manifest_missing_extra_and_mismatched_rows_fail(self) -> None:
        sensitive_path = self.SENSITIVE_FILENAME_MARKER
        sensitive_value = self.SENSITIVE_VALUE_MARKER.encode()
        second_path = f"second-{self.SENSITIVE_FILENAME_MARKER}"
        second_value = f"second-{self.SENSITIVE_VALUE_MARKER}".encode()
        cases = {
            "missing-row": (
                {"version": 1, "files": [self.row(sensitive_path, sensitive_value)]},
                [
                    (f"files/{sensitive_path}", sensitive_value, "file"),
                    (f"files/{second_path}", second_value, "file"),
                ],
            ),
            "extra-row": (
                {
                    "version": 1,
                    "files": [
                        self.row(sensitive_path, sensitive_value),
                        self.row(second_path, second_value),
                    ],
                },
                [(f"files/{sensitive_path}", sensitive_value, "file")],
            ),
            "bad-size": (
                {"version": 1, "files": [self.row(sensitive_path, sensitive_value, size=99)]},
                [(f"files/{sensitive_path}", sensitive_value, "file")],
            ),
            "bad-sha": (
                {
                    "version": 1,
                    "files": [self.row(sensitive_path, sensitive_value, digest="0" * 64)],
                },
                [(f"files/{sensitive_path}", sensitive_value, "file")],
            ),
        }
        for name, (manifest, members) in cases.items():
            with self.subTest(name=name):
                self.assert_generic_failure(self.verify(self.encrypted_tar(name, manifest, members)))

    def test_traversal_symlink_and_extra_archive_entries_are_rejected_before_extraction(self) -> None:
        contents = self.SENSITIVE_VALUE_MARKER.encode()
        safe_path = self.SENSITIVE_FILENAME_MARKER
        valid = {"version": 1, "files": [self.row(safe_path, contents)]}
        cases = {
            "traversal": (
                valid,
                [(f"files/../{self.SENSITIVE_FILENAME_MARKER}", contents, "file")],
            ),
            "manifest-traversal": (
                {
                    "version": 1,
                    "files": [self.row(f"../{self.SENSITIVE_FILENAME_MARKER}", contents)],
                },
                [(f"files/../{self.SENSITIVE_FILENAME_MARKER}", contents, "file")],
            ),
            "symlink": (
                valid,
                [
                    (
                        f"files/{self.SENSITIVE_FILENAME_MARKER}",
                        self.SENSITIVE_VALUE_MARKER.encode(),
                        "symlink",
                    )
                ],
            ),
            "surprise": (
                valid,
                [
                    (f"files/{safe_path}", contents, "file"),
                    (
                        f"unexpected-{self.SENSITIVE_FILENAME_MARKER}",
                        contents,
                        "file",
                    ),
                ],
            ),
        }
        for name, (manifest, members) in cases.items():
            with self.subTest(name=name):
                self.assert_generic_failure(self.verify(self.encrypted_tar(name, manifest, members)))
                self.assertFalse((self.root / "escape").exists())

    def test_backup_rejects_a_sensitive_symlink_without_logging_or_leaving_plaintext(self) -> None:
        target = self.source / "org-a" / f"target-{self.SENSITIVE_FILENAME_MARKER}"
        target.write_text(self.SENSITIVE_VALUE_MARKER, encoding="utf-8")
        link = self.source / "org-a" / f"link-{self.SENSITIVE_FILENAME_MARKER}"
        link.symlink_to(target)
        failed_archive = self.root / "failed-private-files.tar.gz.gpg"

        result = self.create_with_backup(archive=failed_archive)

        self.assert_generic_failure(result)
        self.assertFalse(failed_archive.exists())

    def test_offsite_failure_forces_the_current_local_run_not_an_older_same_day_remote(self) -> None:
        current_run = "2026-08-26T235959Z-current"
        older_run = "2026-08-26T010000Z-older"
        local = self.root / "local"
        remote = self.root / "remote"
        local.mkdir()
        remote.mkdir()
        (local / f"manifest-{current_run}.txt").write_text("current", encoding="utf-8")
        (remote / f"manifest-{older_run}.txt").write_text("older", encoding="utf-8")
        status = self.root / "backup-status.json"
        status.write_text(
            json.dumps(
                {
                    "ok": False,
                    "stage": "backup",
                    "run": current_run,
                    "errors": ["offsite-copy"],
                }
            ),
            encoding="utf-8",
        )

        selected = self.select_source(status, local, remote)

        self.assertEqual(0, selected.returncode, selected.stdout + selected.stderr)
        self.assertEqual(f"local {current_run}", selected.stdout.strip())
        self.assertNotIn(older_run, selected.stdout + selected.stderr)

    def test_failed_same_target_retry_keeps_the_previous_verified_archive(self) -> None:
        first = self.create_with_backup()
        self.assertEqual(0, first.returncode, first.stdout + first.stderr)
        before = self.archive.read_bytes()

        link = self.source / "org-a" / f"retry-{self.SENSITIVE_FILENAME_MARKER}"
        link.symlink_to(self.source / "org-a" / self.SENSITIVE_FILENAME_MARKER)
        retry = self.create_with_backup()

        self.assert_generic_failure(retry)
        self.assertEqual(before, self.archive.read_bytes())
        checked = self.verify()
        self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)
        self.assert_archive_exactly_matches_source_without_using_the_verifier()

    def test_append_or_replace_after_archive_read_is_detected_before_publish(self) -> None:
        for action in ("append", "replace"):
            with self.subTest(action=action):
                source = self.root / f"race-source-{action}"
                shutil.copytree(self.source, source)
                archive = self.root / f"race-{action}.tar.gz.gpg"
                pause = self.root / f"pause-{action}"
                command = [
                    BASH,
                    str(BACKUP),
                    "--private-files-fixture",
                    str(source),
                    str(archive),
                    str(self.key),
                ]
                process = subprocess.Popen(
                    command,
                    cwd=ROOT,
                    env=self.script_environment(
                        {"EZBIZI_PRIVATE_FIXTURE_PAUSE": pause.as_posix()}
                    ),
                    text=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                )
                deadline = time.monotonic() + 10
                ready = Path(f"{pause}.ready")
                while not ready.exists() and process.poll() is None and time.monotonic() < deadline:
                    time.sleep(0.02)
                if not ready.exists():
                    stdout, stderr = process.communicate(timeout=30)
                    self.fail(
                        "archive builder did not reach deterministic race hook: "
                        + stdout
                        + stderr
                    )

                target = source / "org-a" / self.SENSITIVE_FILENAME_MARKER
                if action == "append":
                    with target.open("ab") as stream:
                        stream.write(self.SENSITIVE_VALUE_MARKER.encode())
                else:
                    replacement = target.with_suffix(".replacement")
                    replacement.write_text(
                        self.SENSITIVE_VALUE_MARKER * 2,
                        encoding="utf-8",
                    )
                    os.replace(replacement, target)
                Path(f"{pause}.continue").touch()
                stdout, stderr = process.communicate(timeout=30)
                result = subprocess.CompletedProcess(command, process.returncode, stdout, stderr)

                self.assert_generic_failure(result)
                self.assertFalse(archive.exists())

    def test_restore_rejects_duplicate_links_devices_fifo_pax_gnu_and_noncanonical_paths(self) -> None:
        contents = self.SENSITIVE_VALUE_MARKER.encode()
        safe_path = self.SENSITIVE_FILENAME_MARKER
        valid = {"version": 1, "files": [self.row(safe_path, contents)]}
        long_path = self.SENSITIVE_FILENAME_MARKER + ("x" * 160)
        cases = {
            "duplicate": (
                valid,
                [
                    (f"files/{safe_path}", contents, "file"),
                    (f"files/{safe_path}", contents, "file"),
                ],
                tarfile.USTAR_FORMAT,
            ),
            "hardlink": (
                valid,
                [(f"files/{safe_path}", self.SENSITIVE_VALUE_MARKER.encode(), "hardlink")],
                tarfile.USTAR_FORMAT,
            ),
            "fifo": (
                valid,
                [(f"files/{safe_path}", contents, "fifo")],
                tarfile.USTAR_FORMAT,
            ),
            "device": (
                valid,
                [(f"files/{safe_path}", contents, "char")],
                tarfile.USTAR_FORMAT,
            ),
            "pax-override": (
                valid,
                [(f"files/{safe_path}", contents, "pax")],
                tarfile.PAX_FORMAT,
            ),
            "gnu-longname": (
                {"version": 1, "files": [self.row(long_path, contents)]},
                [(f"files/{long_path}", contents, "file")],
                tarfile.GNU_FORMAT,
            ),
            "noncanonical": (
                {
                    "version": 1,
                    "files": [self.row(f"folder//{safe_path}", contents)],
                },
                [(f"files/folder//{safe_path}", contents, "file")],
                tarfile.USTAR_FORMAT,
            ),
        }
        for name, (manifest, members, archive_format) in cases.items():
            with self.subTest(name=name):
                archive = self.encrypted_tar(name, manifest, members, archive_format)
                self.assert_generic_failure(self.verify(archive))

    def test_restore_enforces_every_resource_policy_bound_before_exhaustion(self) -> None:
        self.assertEqual(0, self.create_with_backup().returncode)
        cases = {
            "encrypted-bytes": {"EZBIZI_PRIVATE_MAX_ENCRYPTED_BYTES": "1"},
            "members": {"EZBIZI_PRIVATE_MAX_MEMBERS": "2"},
            "manifest-rows": {"EZBIZI_PRIVATE_MAX_MANIFEST_ROWS": "1"},
            "manifest-bytes": {"EZBIZI_PRIVATE_MAX_MANIFEST_BYTES": "1"},
            "total-bytes": {"EZBIZI_PRIVATE_MAX_TOTAL_BYTES": "1"},
            "file-bytes": {"EZBIZI_PRIVATE_MAX_FILE_BYTES": "1"},
            "path-bytes": {"EZBIZI_PRIVATE_MAX_PATH_BYTES": "8"},
            "path-depth": {"EZBIZI_PRIVATE_MAX_PATH_DEPTH": "1"},
            "free-bytes": {"EZBIZI_PRIVATE_MIN_FREE_BYTES": str(2**63 - 1)},
            "free-inodes": {"EZBIZI_PRIVATE_MIN_FREE_INODES": str(2**63 - 1)},
        }
        for name, environment in cases.items():
            with self.subTest(name=name):
                self.assert_generic_failure(self.verify(extra_env=environment))

        compressed_contents = self.SENSITIVE_VALUE_MARKER.encode() * 100_000
        compressed_path = self.SENSITIVE_FILENAME_MARKER
        compressed = self.encrypted_tar(
            "compression-ratio",
            {"version": 1, "files": [self.row(compressed_path, compressed_contents)]},
            [(f"files/{compressed_path}", compressed_contents, "file")],
        )
        self.assert_generic_failure(
            self.verify(
                compressed,
                extra_env={"EZBIZI_PRIVATE_MAX_COMPRESSION_RATIO": "2"},
            )
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
