#!/usr/bin/env python3
"""Disposable proof for the encrypted private-file backup/restore contract."""

from __future__ import annotations

import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
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
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="ezbizi-private-files-")
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        (self.source / "org-a").mkdir()
        (self.source / "org-a" / "customer-secret-name.pdf").write_bytes(
            b"customer-value-that-must-never-be-logged"
        )
        (self.source / "org-b").mkdir()
        (self.source / "org-b" / "other.bin").write_bytes(b"\x00\x01\x02")
        self.key = self.root / "key"
        self.key.write_text("disposable-fixture-passphrase\n", encoding="utf-8")
        self.archive = self.root / "private-files-fixture.tar.gz.gpg"
        self.tmpdir = self.root / "tmp"
        self.tmpdir.mkdir()
        self.sentinel = self.tmpdir / "do-not-delete"
        self.sentinel.write_text("outside verifier work directory", encoding="utf-8")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def run_script(self, script: Path, *args: Path | str) -> subprocess.CompletedProcess[str]:
        env = os.environ.copy()
        env["TMPDIR"] = str(self.tmpdir)
        return subprocess.run(
            [BASH, str(script), *(str(value) for value in args)],
            cwd=ROOT,
            env=env,
            text=True,
            capture_output=True,
            timeout=30,
        )

    def create_with_backup(self) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            BACKUP,
            "--private-files-fixture",
            self.source,
            self.archive,
            self.key,
        )

    def verify(self, archive: Path | None = None, key: Path | None = None) -> subprocess.CompletedProcess[str]:
        return self.run_script(
            RESTORE,
            "--private-files-fixture",
            archive or self.archive,
            key or self.key,
        )

    def encrypted_tar(self, name: str, manifest: dict, members: list[tuple[str, bytes, str]]) -> Path:
        plain = self.root / f"{name}.tar.gz"
        encrypted = self.root / f"{name}.tar.gz.gpg"
        with tarfile.open(plain, "w:gz") as bundle:
            encoded = json.dumps(manifest, separators=(",", ":"), sort_keys=True).encode()
            info = tarfile.TarInfo("manifest.v1.json")
            info.size = len(encoded)
            info.mode = 0o600
            bundle.addfile(info, io.BytesIO(encoded))
            for path, contents, kind in members:
                info = tarfile.TarInfo(path)
                info.mode = 0o600
                if kind == "symlink":
                    info.type = tarfile.SYMTYPE
                    info.linkname = "../../escape"
                    bundle.addfile(info)
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
        self.assertNotIn("customer-secret-name", combined)
        self.assertNotIn("customer-value", combined)
        self.assertTrue(self.sentinel.exists(), "verifier deleted outside its own temporary directory")

    def test_valid_encrypted_archive_round_trips_and_cleans_its_temporary_directory(self) -> None:
        made = self.create_with_backup()
        self.assertEqual(0, made.returncode, made.stdout + made.stderr)
        self.assertTrue(self.archive.is_file())

        checked = self.verify()
        self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)
        self.assertNotIn("customer-secret-name", checked.stdout + checked.stderr)
        self.assertNotIn("customer-value", checked.stdout + checked.stderr)
        self.assertTrue(self.sentinel.exists())
        self.assertEqual({self.sentinel}, set(self.tmpdir.iterdir()))

    def test_missing_archive_wrong_key_and_bad_encrypted_payload_fail(self) -> None:
        missing = self.verify(self.root / "missing.tar.gz.gpg")
        self.assert_generic_failure(missing)

        self.assertEqual(0, self.create_with_backup().returncode)
        wrong_key = self.root / "wrong-key"
        wrong_key.write_text("wrong\n", encoding="utf-8")
        self.assert_generic_failure(self.verify(key=wrong_key))

        plain = self.root / "not-an-archive"
        plain.write_bytes(b"not a gzip or tar archive")
        bad = self.root / "bad-payload.gpg"
        subprocess.run(
            [GPG, "--batch", "--yes", "--quiet", "--symmetric", "--cipher-algo", "AES256",
             "--passphrase-file", str(self.key), "--output", str(bad), str(plain)],
            check=True,
            capture_output=True,
        )
        self.assert_generic_failure(self.verify(bad))

    def test_manifest_missing_extra_and_mismatched_rows_fail(self) -> None:
        alpha = b"alpha"
        beta = b"beta"
        cases = {
            "missing-row": (
                {"version": 1, "files": [self.row("alpha.txt", alpha)]},
                [("files/alpha.txt", alpha, "file"), ("files/beta.txt", beta, "file")],
            ),
            "extra-row": (
                {"version": 1, "files": [self.row("alpha.txt", alpha), self.row("beta.txt", beta)]},
                [("files/alpha.txt", alpha, "file")],
            ),
            "bad-size": (
                {"version": 1, "files": [self.row("alpha.txt", alpha, size=99)]},
                [("files/alpha.txt", alpha, "file")],
            ),
            "bad-sha": (
                {"version": 1, "files": [self.row("alpha.txt", alpha, digest="0" * 64)]},
                [("files/alpha.txt", alpha, "file")],
            ),
        }
        for name, (manifest, members) in cases.items():
            with self.subTest(name=name):
                self.assert_generic_failure(self.verify(self.encrypted_tar(name, manifest, members)))

    def test_traversal_symlink_and_extra_archive_entries_are_rejected_before_extraction(self) -> None:
        contents = b"safe"
        valid = {"version": 1, "files": [self.row("safe.txt", contents)]}
        cases = {
            "traversal": (valid, [("files/../escape", contents, "file")]),
            "manifest-traversal": (
                {"version": 1, "files": [self.row("../escape", contents)]},
                [("files/../escape", contents, "file")],
            ),
            "symlink": (valid, [("files/safe.txt", b"", "symlink")]),
            "surprise": (valid, [("files/safe.txt", contents, "file"), ("unexpected", b"x", "file")]),
        }
        for name, (manifest, members) in cases.items():
            with self.subTest(name=name):
                self.assert_generic_failure(self.verify(self.encrypted_tar(name, manifest, members)))
                self.assertFalse((self.root / "escape").exists())


if __name__ == "__main__":
    unittest.main(verbosity=2)
