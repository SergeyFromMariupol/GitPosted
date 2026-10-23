#!/usr/bin/env python3
"""Independent bootstrap failure tests. No packages, sockets, services or server writes."""
from __future__ import annotations

import contextlib
import importlib.util
import io
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

if os.name != 'posix':
    for name in ('pwd', 'grp'):
        sys.modules.setdefault(name, types.SimpleNamespace())
    sys.modules.setdefault('fcntl', types.SimpleNamespace(LOCK_EX=2, LOCK_NB=4, flock=lambda *_: None))

spec = importlib.util.spec_from_file_location('installer_provision_audit', Path(__file__).resolve().parents[1] / 'lib/provision.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)
resource_spec = importlib.util.spec_from_file_location('installer_resources_audit', Path(__file__).resolve().parents[1] / 'bin/install_resources.py')
resources = importlib.util.module_from_spec(resource_spec)
resource_spec.loader.exec_module(resources)


class ProvisionAuditTests(unittest.TestCase):
    def test_dependency_failure_leaves_a_complete_durable_resume_bundle(self):
        events = []
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / 'source'
            source.mkdir()
            target = Path(temporary) / 'durable'
            with patch.object(p, 'INSTALL', target), patch.object(p, 'package_files', return_value={}), \
                 patch.object(p, 'dns_matches'), patch.object(p, 'directory'), patch.object(p, 'save_json'), \
                 patch.object(p, 'persist_bundle', side_effect=lambda *_: events.append('persisted')), \
                 patch.object(p, 'run', side_effect=lambda *_args, **_kwargs: events.append('dependency') or (_ for _ in ()).throw(p.Failure('APT_FAILED', 'Fixture dependency failure'))), \
                 contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(p.Failure):
                    p.install(source, '8.8.8.8', '8.8.8.8', checked=True)
            self.assertEqual(['persisted', 'dependency'], events,
                             'The curl loader deletes its temporary bundle on failure; resume must exist before apt runs.')

    def test_untrusted_source_is_rejected_before_the_first_install_write(self):
        with patch.object(p, 'validate_source', side_effect=p.Failure('UNSAFE_PACKAGE', 'Fixture source failure'), create=True) as validate, \
             patch.object(p, 'dns_matches'), patch.object(p, 'directory') as directory, patch.object(p, 'save_json') as save, \
             patch.object(p, 'run', side_effect=p.Failure('APT_FAILED', 'Fixture dependency failure')) as run, \
             patch.object(p, 'copy_tree') as copy, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(p.Failure):
                p.install(Path('/fixture'), '8.8.8.8', '8.8.8.8', checked=True)
        validate.assert_called_once()
        directory.assert_not_called()
        save.assert_not_called()
        copy.assert_not_called()
        run.assert_not_called()

    def test_main_checks_package_before_creating_state_or_lock(self):
        with tempfile.TemporaryDirectory() as temporary, patch.object(p, 'STATE', Path(temporary) / 'absent'), \
             patch.object(sys, 'argv', ['provision.py', '--public-ip', '8.8.8.8']), patch.object(p, 'preflight'), \
             patch.object(p, 'dns_matches'), patch.object(p, 'validate_source', side_effect=p.Failure('UNSAFE_PACKAGE', 'Fixture source failure'), create=True), \
             patch.object(p, 'directory') as directory, patch.object(p, 'installer_lock', contextlib.nullcontext), \
             patch.object(p, 'install') as install, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                p.main()
            self.assertEqual(1, error.exception.code)
        directory.assert_not_called()
        install.assert_not_called()

    def test_ipv4_requirement_does_not_depend_on_ipv6_kernel_support(self):
        text = p.nginx_content({'host': '8.8.8.8', 'site_host': 'example.org', 'tls_ready': True, 'installed': True})
        self.assertNotIn('listen [::]', text,
                         'An IPv4-only Ubuntu host must not fail nginx startup with EAFNOSUPPORT.')
        self.assertIn('listen 80 default_server', text)
        self.assertIn('listen 443 ssl', text)

    def test_app_data_resources_are_never_copied_or_chowned_by_root(self):
        with patch.object(p, 'run', return_value=b'') as run, patch.object(p, 'copy_tree') as copy, \
             patch.object(p.os, 'chown', create=True) as chown:
            helper = getattr(p, 'prepare_resources', None)
            self.assertIsNotNone(helper, 'Resources must be prepared by the application UID, not root recursion.')
            helper()
        copy.assert_not_called()
        chown.assert_not_called()
        self.assertEqual(1, run.call_count)
        self.assertEqual(['runuser', '-u', 'gitposted', '--'], run.call_args.args[0][:4])
        self.assertIn(p.INSTALL / 'bin/install_resources.py', run.call_args.args[0])

    def test_source_rejects_a_non_root_owned_file(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / 'bundle'
            source.mkdir()
            child = source / 'bootstrap.sh'
            child.write_text('fixture')
            original = Path.lstat
            def lstat(path, *args, **kwargs):
                info = original(path, *args, **kwargs)
                mode = stat.S_IFMT(info.st_mode) | (0o755 if stat.S_ISDIR(info.st_mode) else 0o644)
                return types.SimpleNamespace(st_mode=mode, st_uid=1001 if path == child else 0)
            with patch.object(Path, 'lstat', lstat), self.assertRaises(p.Failure) as error:
                p.validate_source(source)
            self.assertEqual('UNSAFE_PACKAGE', error.exception.code)

    def test_source_rejects_root_owned_leaf_inside_user_owned_parent(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary) / 'home'
            source = home / 'bundle'
            source.mkdir(parents=True)
            (source / 'bootstrap.sh').write_text('fixture')
            original = Path.lstat
            def lstat(path, *args, **kwargs):
                info = original(path, *args, **kwargs)
                mode = stat.S_IFMT(info.st_mode) | (0o755 if stat.S_ISDIR(info.st_mode) else 0o644)
                return types.SimpleNamespace(st_mode=mode, st_uid=1001 if path == home else 0)
            with patch.object(Path, 'lstat', lstat), self.assertRaises(p.Failure) as error:
                p.validate_source(source)
            self.assertEqual('UNSAFE_PACKAGE', error.exception.code)

    def test_direct_clone_skips_git_metadata_and_rejects_extra_private_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / 'bundle'
            (source / 'scripts').mkdir(parents=True)
            (source / 'scripts/build.py').write_bytes((Path(__file__).resolve().parents[1] / 'scripts/build.py').read_bytes())
            (source / 'bootstrap.sh').write_text('fixture public bootstrap')
            (source / '.git').mkdir()
            (source / '.git/config').write_text('fixture private Git credential')
            with patch.object(p, 'validate_source'):
                files = p.package_files(source)
                self.assertEqual({'bootstrap.sh', 'scripts/build.py'}, set(files))
                (source / 'private-credentials.txt').write_text('fixture private credential')
                with self.assertRaises(p.Failure) as error:
                    p.package_files(source)
                self.assertEqual('INVALID_PACKAGE', error.exception.code)

    def test_modified_manifest_fails_before_persisting_any_state(self):
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / 'bundle'
            (source / 'scripts').mkdir(parents=True)
            (source / 'scripts/build.py').write_bytes((Path(__file__).resolve().parents[1] / 'scripts/build.py').read_bytes())
            (source / 'bootstrap.sh').write_text('changed fixture')
            (source / 'MANIFEST.sha256').write_text('0' * 64 + '  bootstrap.sh\n')
            with patch.object(p, 'validate_source'), patch.object(p, 'dns_matches'), \
                 patch.object(p, 'directory') as directory, patch.object(p, 'save_json') as save, \
                 patch.object(p, 'run') as run, self.assertRaises(p.Failure) as error:
                p.install(source, '8.8.8.8', '8.8.8.8', checked=True)
            self.assertEqual('INVALID_PACKAGE', error.exception.code)
            directory.assert_not_called(); save.assert_not_called(); run.assert_not_called()

    def test_persisted_bundle_contains_only_validated_members(self):
        files = {'bootstrap.sh': b'bootstrap', 'scripts/build.py': b'fixture builder'}
        writes = {}
        with patch.object(p, 'directory'), patch.object(p, 'atomic', side_effect=lambda path, value, *_: writes.update({path:value})):
            p.persist_bundle(Path('/fixture/source'), files)
        self.assertEqual({p.INSTALL / name:value for name,value in files.items()}, writes)

    def test_resource_helper_refuses_root_before_any_directory_or_file_change(self):
        with patch.object(resources.os, 'getuid', return_value=0, create=True), \
             patch.object(resources.Path, 'mkdir') as mkdir, patch.object(resources.os, 'replace') as replace:
            with self.assertRaises(ValueError):
                resources.prepare()
        mkdir.assert_not_called()
        replace.assert_not_called()

    @unittest.skipUnless(os.name == 'posix', 'Linux app UID and no-follow fixture')
    def test_resource_copy_is_fixed_atomic_and_preserves_unrelated_runtime(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, source = Path(temporary) / 'data', Path(temporary) / 'resources'
            root.mkdir()
            for relative in resources.RESOURCES:
                path = source / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(relative.encode())
            (root / 'runtime').mkdir()
            private = root / 'runtime/bot.session'
            private.write_bytes(b'private-session-fixture')
            original = Path.lstat
            def lstat(path, *args, **kwargs):
                info = original(path, *args, **kwargs)
                return types.SimpleNamespace(st_mode=info.st_mode, st_uid=12345 if path == root or root in path.parents else 0)
            with patch.object(resources.os, 'getuid', return_value=12345), \
                 patch.object(resources.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=12345)), \
                 patch.object(Path, 'lstat', lstat):
                resources.prepare(root, source)
                for relative in resources.RESOURCES:
                    self.assertEqual(relative.encode(), (root / relative).read_bytes())
                self.assertEqual(0o700, stat.S_IMODE((root / 'sessions').stat().st_mode))
                self.assertEqual(b'private-session-fixture', private.read_bytes())
                (root / 'assets/schedule.jpg').write_bytes(b'old-complete-file')
                with patch.object(resources.os, 'replace', side_effect=OSError('fixture interrupted atomic replacement')):
                    with self.assertRaises(OSError):
                        resources.prepare(root, source)
                self.assertEqual(b'old-complete-file', (root / 'assets/schedule.jpg').read_bytes())
                self.assertEqual(['preview-links.png', 'schedule.jpg'], sorted(path.name for path in (root / 'assets').iterdir()))

    @unittest.skipUnless(os.name == 'posix', 'Linux app UID and symlink fixture')
    def test_resource_helper_rejects_linked_data_before_touching_outside_target(self):
        with tempfile.TemporaryDirectory() as temporary:
            root, source, outside = Path(temporary) / 'data', Path(temporary) / 'resources', Path(temporary) / 'outside'
            root.mkdir(); outside.mkdir()
            for relative in resources.RESOURCES:
                path = source / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(relative.encode())
            (root / 'assets').symlink_to(outside, target_is_directory=True)
            secret = outside / 'schedule.jpg'
            secret.write_bytes(b'preserve-outside')
            original = Path.lstat
            def lstat(path, *args, **kwargs):
                info = original(path, *args, **kwargs)
                return types.SimpleNamespace(st_mode=info.st_mode, st_uid=12345 if path == root or root in path.parents else 0)
            with patch.object(resources.os, 'getuid', return_value=12345), \
                 patch.object(resources.pwd, 'getpwnam', return_value=types.SimpleNamespace(pw_uid=12345)), \
                 patch.object(Path, 'lstat', lstat), self.assertRaises(ValueError):
                resources.prepare(root, source)
            self.assertEqual(b'preserve-outside', secret.read_bytes())


if __name__ == '__main__':
    unittest.main()
