#!/usr/bin/env python3
"""Interrupted Linux bootstrap fixtures; no package, API or root operations."""
from __future__ import annotations

import importlib.util
import json
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

spec=importlib.util.spec_from_file_location('deep_provision',Path(__file__).resolve().parents[1]/'lib/provision.py')
p=importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class DeepProvisionTests(unittest.TestCase):
    @unittest.skipUnless(os.name=='posix' and getattr(os,'geteuid',lambda:-1)()==0,'Linux durable file and directory fixture')
    def test_atomic_commit_flushes_final_file_metadata_before_rename_and_parent_afterwards(self):
        original_fsync=os.fsync;original_replace=os.replace;events=[]
        with tempfile.TemporaryDirectory()as temporary:
            target=Path(temporary)/'state.json';target.write_bytes(b'old-complete-value')
            def fsync(descriptor):
                info=os.fstat(descriptor)
                if stat.S_ISDIR(info.st_mode):
                    self.assertEqual(b'new-complete-value',target.read_bytes());events.append('directory')
                else:
                    self.assertEqual(0o640,info.st_mode&0o777);self.assertEqual(0,info.st_uid);events.append('file')
                return original_fsync(descriptor)
            def replace(source,destination):
                self.assertEqual(b'old-complete-value',target.read_bytes());events.append('rename');return original_replace(source,destination)
            with patch.object(p.os,'fsync',side_effect=fsync),patch.object(p.os,'replace',side_effect=replace):
                p.atomic(target,b'new-complete-value',0o640)
            self.assertEqual(['file','rename','directory'],events)
            self.assertEqual([target],list(Path(temporary).iterdir()))

    @unittest.skipUnless(os.name=='posix' and getattr(os,'geteuid',lambda:-1)()==0,'Linux root-owned directory publication fixture')
    def test_initial_state_is_resume_ready_as_soon_as_it_becomes_visible(self):
        with tempfile.TemporaryDirectory()as temporary,patch.object(p,'STATE',Path(temporary)/'state'):
            p.initialize_state('8.8.8.8','example.org')
            self.assertEqual(['bootstrap.json','installer.lock'],sorted(x.name for x in p.STATE.iterdir()))
            self.assertEqual({'installer':'gitposted-clean-v1','public_ip':'8.8.8.8','host':'example.org'},p.read_json(p.STATE/'bootstrap.json'))
            self.assertEqual(0o700,p.STATE.stat().st_mode&0o777)
            self.assertEqual(0o600,(p.STATE/'installer.lock').stat().st_mode&0o777)
            self.assertFalse(p.resume_ready(),'Infrastructure resume must continue without adopting any other state.')

    @unittest.skipUnless(os.name=='posix' and getattr(os,'geteuid',lambda:-1)()==0,'Linux initial-state interruption fixture')
    def test_failed_initial_commit_leaves_no_visible_half_state(self):
        with tempfile.TemporaryDirectory()as temporary,patch.object(p,'STATE',Path(temporary)/'state'),patch.object(p,'publish_initial_state',side_effect=p.Failure('INTERRUPTED','Fixture interruption')):
            with self.assertRaises(p.Failure):p.initialize_state('8.8.8.8','example.org')
            self.assertFalse(p.STATE.exists());self.assertEqual([],list(Path(temporary).iterdir()))

    @unittest.skipUnless(os.name=='posix' and getattr(os,'geteuid',lambda:-1)()==0,'Linux RENAME_NOREPLACE collision fixture')
    def test_initial_commit_cannot_replace_even_an_empty_path_that_appeared_concurrently(self):
        original=p.publish_initial_state
        with tempfile.TemporaryDirectory()as temporary,patch.object(p,'STATE',Path(temporary)/'state'):
            inode=[]
            def collision(source,target):
                target.mkdir();inode.append(target.stat().st_ino);return original(source,target)
            with patch.object(p,'publish_initial_state',side_effect=collision),self.assertRaises(p.Failure)as error:
                p.initialize_state('8.8.8.8','example.org')
            self.assertEqual('EXISTING_INSTALLATION',error.exception.code)
            self.assertEqual(inode[0],p.STATE.stat().st_ino);self.assertEqual([],list(p.STATE.iterdir()))
            self.assertEqual([p.STATE],list(Path(temporary).iterdir()))

    def test_custom_session_pools_enable_native_gc_with_a_consistent_lifetime(self):
        pools={}
        with patch.object(p,'atomic',side_effect=lambda path,value,*_:pools.update({str(path):value})),patch.object(p,'run',return_value=b''):
            p.pools()
        for name in ('gitposted','gitposted-installer'):
            text=pools['/etc/php/8.3/fpm/pool.d/'+name+'.conf']
            self.assertIn('php_admin_value[session.gc_probability] = 1\n',text)
            self.assertIn('php_admin_value[session.gc_divisor] = 100\n',text)
            self.assertIn('php_admin_value[session.gc_maxlifetime] = 3600\n',text)

    def test_uncommitted_finish_reopens_only_the_pending_wizard(self):
        for status in ('running','failed','done'):
            with self.subTest(status=status),tempfile.TemporaryDirectory()as temporary,patch.object(p,'STATE',Path(temporary)),patch.object(p,'configure_nginx')as configure:
                public={'host':'8.8.8.8','tls_ready':True,'installed':False,
                        'job':{'type':'install','steps':[{'id':'finish','status':status}]}}
                self.assertTrue(p.reconcile_pending_finish(public));configure.assert_called_once_with(public)

    def test_committed_or_unrelated_jobs_never_reopen_the_wizard(self):
        cases=[{'installed':True}, {'tls_ready':False}, {'job':{'type':'host','steps':[{'id':'finish','status':'running'}]}},
               {'job':{'type':'install','steps':[{'id':'finish','status':'pending'}]}},
               {'job':{'type':'install','steps':[]}}, {'job':None}]
        with tempfile.TemporaryDirectory()as temporary,patch.object(p,'STATE',Path(temporary)),patch.object(p,'configure_nginx')as configure:
            for override in cases:
                public={'host':'8.8.8.8','tls_ready':True,'installed':False,
                        'job':{'type':'install','steps':[{'id':'finish','status':'failed'}]},**override}
                self.assertFalse(p.reconcile_pending_finish(public))
            (Path(temporary)/'installed.json').write_text('fixture authoritative marker')
            self.assertFalse(p.reconcile_pending_finish({'tls_ready':True,'installed':False,'job':{'type':'install','steps':[{'id':'finish','status':'failed'}]}}))
            configure.assert_not_called()

    def test_resume_repairs_interrupted_worker_venv_before_executing_its_python(self):
        # python -m venv creates its directory before the interpreter/pip.
        # A killed first attempt may therefore leave either of these states.
        for has_python in (False,True):
            with self.subTest(has_python=has_python),tempfile.TemporaryDirectory()as temporary:
                data=Path(temporary)/'data';worker=data/'runtime/post-links-venv'
                worker.mkdir(parents=True)
                (worker/'pyvenv.cfg').write_text('fixture interrupted venv')
                if has_python:
                    (worker/'bin').mkdir();(worker/'bin/python3').write_text('fixture interpreter without pip')
                operations=[]
                def run(argv,**_):
                    operations.append([str(x)for x in argv]);return b''
                owner=worker.stat().st_uid
                with patch.object(p,'DATA',data),patch.object(p,'identity',return_value=(owner,owner)),patch.object(p,'run',side_effect=run):
                    p.prepare_worker(Path('/release'))
                self.assertEqual(2,len(operations),'An interrupted venv must be reconstructed before dependency installation.')
                self.assertEqual(['runuser','-u','gitposted','--','python3','-m','venv','--copies',str(worker)],operations[0])
                self.assertEqual(['runuser','-u','gitposted','--',str(worker/'bin/python3'),'-I','-m','pip'],operations[1][:8])


if __name__=='__main__':unittest.main()
