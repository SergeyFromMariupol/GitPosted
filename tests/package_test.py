#!/usr/bin/env python3
"""Offline release packaging tests; fixtures contain no real credentials."""
from __future__ import annotations

import hashlib
import importlib.util
import os
from pathlib import Path
import tarfile
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("installer_build", Path(__file__).resolve().parents[1] / "scripts" / "build.py")
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)


class PackageTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="gp-package-fixture-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "installer"
        self.output = self.root / "release"
        self.source.mkdir()
        self.write("README.md", b"Self-hosted installer\n")
        self.write("bootstrap.sh", b"#!/bin/sh\nexit 0\n")
        self.write("bin/application.php", b"<?php declare(strict_types=1);\n")
        self.write("payload/src/.htaccess", b"Options -Indexes\n")
        self.write("payload/src/assets/picture (1).svg", b"<svg/>\n")
        self.write("payload/resources/certs/russiantrustedca.pem", b"public CA fixture\n")

    def tearDown(self):
        self.temporary.cleanup()

    def write(self, name, value):
        path = self.source / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(value)
        return path

    def extract(self):
        with tarfile.open(self.output / builder.ARCHIVE, "r:gz") as archive:
            # This archive was just built from these fixtures, never an external archive.
            archive.extractall(self.root / "extracted", filter="data")
        return self.root / "extracted" / builder.PREFIX

    def test_deterministic_tar_gzip_hash_manifest_and_portable_permissions(self):
        before = {str(path.relative_to(self.source)): path.read_bytes() for path in self.source.rglob("*") if path.is_file()}
        first = builder.build(self.source, self.output)
        archive_bytes = (self.output / builder.ARCHIVE).read_bytes()
        (self.source / "README.md").touch()
        second = builder.build(self.source, self.output)
        self.assertEqual(first["sha256"], second["sha256"])
        self.assertEqual(archive_bytes, (self.output / builder.ARCHIVE).read_bytes())
        self.assertEqual(first["sha256"], hashlib.sha256(archive_bytes).hexdigest())
        self.assertEqual((self.output / (builder.ARCHIVE + ".sha256")).read_text(), first["sha256"] + "  " + builder.ARCHIVE + "\n")
        self.assertEqual(builder.verify_manifest(self.extract()), len(before))
        with tarfile.open(self.output / builder.ARCHIVE) as archive:
            for entry in archive:
                self.assertEqual((entry.uid, entry.gid, entry.mtime), (0, 0, 0))
                self.assertFalse(entry.issym() or entry.islnk())
            self.assertEqual(archive.getmember(builder.PREFIX + "/bootstrap.sh").mode, 0o755)
            self.assertEqual(archive.getmember(builder.PREFIX + "/bin/application.php").mode, 0o644)
        self.assertEqual(before, {str(path.relative_to(self.source)): path.read_bytes() for path in self.source.rglob("*") if path.is_file()})

    def test_output_inside_source_is_rejected_without_creating_artifacts(self):
        with self.assertRaises(builder.PackageError): builder.build(self.source, self.source / "dist")
        self.assertFalse((self.source / "dist").exists())

    def test_git_metadata_is_never_read_or_packaged_from_a_checkout(self):
        self.write(".git/config", b"SQLite format 3\0private Git metadata fixture")
        built = builder.build(self.source, self.output)
        self.assertEqual(built["file_count"], 6)
        extracted = self.extract()
        self.assertFalse((extracted / ".git").exists())
        self.assertEqual(builder.verify_manifest(extracted), 6)

    def test_rebuilding_replaces_output_links_without_modifying_other_files(self):
        self.output.mkdir()
        unrelated = self.root / "unrelated.txt"
        unrelated.write_bytes(b"unrelated fixture must stay unchanged")
        try:
            os.link(unrelated, self.output / builder.ARCHIVE)
            os.link(unrelated, self.output / (builder.ARCHIVE + ".sha256"))
        except OSError:
            self.skipTest("hard links not available")
        builder.build(self.source, self.output)
        self.assertEqual(unrelated.read_bytes(), b"unrelated fixture must stay unchanged")
        self.assertEqual(builder.verify_manifest(self.extract()), 6)

    def test_secret_cache_database_and_unknown_files_are_rejected(self):
        for name in ("payload/src/config.php", "payload/src/tools/__pycache__/worker.pyc", "payload/src/runtime/bot.session", "docs/private.key", "web/config.json", "unexpected.txt"):
            with self.subTest(name=name):
                path = self.write(name, b"fixture")
                with self.assertRaises(builder.PackageError): builder.build(self.source, self.output)
                path.unlink()
                # Remove only empty fixture directories inside this exact test source.
                parent = path.parent
                while parent != self.source and not any(parent.iterdir()):
                    parent.rmdir()
                    parent = parent.parent

    def test_renamed_session_key_and_literal_tokens_are_rejected(self):
        values = [b"SQLite format 3\0" + b"not a real session", b"-----BEGIN " + b"PRIVATE KEY-----\nnot a key\n", b"token=" + b"123456:" + b"a" * 32]
        for value in values:
            with self.subTest(value_type=value[:10]):
                self.write("docs/innocent.txt", value)
                with self.assertRaises(builder.PackageError): builder.build(self.source, self.output)

    def test_symlink_is_rejected_even_when_file_extension_is_allowed(self):
        target = self.root / "outside.php"
        target.write_bytes(b"private fixture")
        link = self.source / "bin" / "linked.php"
        try: link.symlink_to(target)
        except OSError: self.skipTest("symlink creation not available")
        with self.assertRaises(builder.PackageError): builder.build(self.source, self.output)

    def test_manifest_detects_tamper_missing_and_extra_files(self):
        builder.build(self.source, self.output)
        extracted = self.extract()
        readme = extracted / "README.md"
        original = readme.read_bytes()
        readme.write_bytes(b"tampered")
        with self.assertRaises(builder.PackageError): builder.verify_manifest(extracted)
        readme.write_bytes(original)
        readme.unlink()
        with self.assertRaises(builder.PackageError): builder.verify_manifest(extracted)
        readme.write_bytes(original)
        (extracted / "docs").mkdir()
        extra = extracted / "docs" / "extra.md"
        extra.write_bytes(b"not in manifest")
        with self.assertRaises(builder.PackageError): builder.verify_manifest(extracted)
        extra.unlink()
        self.assertEqual(builder.verify_manifest(extracted), 6)

    def test_manifest_cannot_reference_parent_or_duplicate_a_file(self):
        builder.build(self.source, self.output)
        extracted = self.extract()
        manifest = extracted / builder.MANIFEST
        original = manifest.read_text()
        for line in ("a" * 64 + "  ../outside\n", original.splitlines()[0] + "\n"):
            manifest.write_text(original + line)
            with self.assertRaises(builder.PackageError): builder.verify_manifest(extracted)

    def test_case_collisions_and_noncanonical_names_are_not_portable(self):
        for name in ("../private", "/private", "docs/../private", "docs/line\nbreak.md", "docs\\file.md"):
            with self.subTest(name=name):
                with self.assertRaises(builder.PackageError): builder.safe_relative(name)


if __name__ == "__main__":
    unittest.main(verbosity=2)
