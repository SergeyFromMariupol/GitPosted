#!/usr/bin/env python3
"""Crash after nginx closes the wizard, before the terminal marker commits."""
from __future__ import annotations
import copy
import importlib.util
import os
from pathlib import Path
import unittest
from unittest import mock
import types

spec = importlib.util.spec_from_file_location('deep_wizard_fixtures', Path(__file__).with_name('broker_test.py'))
fixtures = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixtures)
broker, p = fixtures.broker, fixtures.p


class DeepWizardRecovery(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.BrokerTests(methodName='runTest');self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)

    def interrupted_finish(self, status='running'):
        app = self.fixture.make()
        app.public.update(tls_ready=True, installed=False)
        app.public['job'] = {'id': 'durable-job', 'type': 'install', 'status': 'running',
                             'steps': [{'id': 'activate', 'status': 'done'}, {'id': 'finish', 'status': status}]}
        app.private.update(settings={'telegram': {'enabled': True}}, config={'telegram_bot_enabled': True, 'max_bot_enabled': False})
        app.save();return copy.deepcopy(app.private['config'])

    def test_service_restart_reopens_wizard_after_completed_nginx_precedes_terminal_marker(self):
        before = self.interrupted_finish()
        with mock.patch.object(p, 'configure_nginx') as configure:
            recovered = broker.Broker()
        configure.assert_called_once()
        self.assertIs(configure.call_args.args[0]['installed'], False)
        self.assertEqual(recovered.public['job']['status'], 'failed')
        self.assertEqual(recovered.public['job']['id'], 'durable-job')
        self.assertEqual(recovered.private['config'], before)
        self.assertEqual(recovered.public['job']['steps'][0]['status'], 'done')
        self.assertFalse(recovered.revisable())

    def test_restart_uses_private_checkpoint_even_if_public_projection_is_stale(self):
        self.interrupted_finish()
        public=p.read_json(p.STATE/'public.json');public['installed']=True
        self.fixture.save_json(p.STATE/'public.json',public)
        with mock.patch.object(p, 'configure_nginx') as configure:
            recovered=broker.Broker()
        self.assertIs(recovered.public['installed'],False)
        self.assertIs(configure.call_args.args[0]['installed'],False)

    def test_failed_finish_recovery_is_idempotent_without_reactivating_platforms(self):
        before=self.interrupted_finish('failed')
        for _ in range(2):
            with mock.patch.object(p,'configure_nginx') as configure:
                recovered=broker.Broker()
            configure.assert_called_once()
            self.assertEqual(recovered.private['config'],before)
            self.assertEqual(recovered.public['job']['steps'][0]['status'],'done')
        calls=[]
        recovered.install_step=lambda step:calls.append(step)
        with mock.patch.object(p,'run'),mock.patch.object(broker.threading,'Timer'):
            self.fixture.execute(recovered)
        self.assertEqual(calls,['finish'])

    def test_normal_incomplete_job_does_not_reconfigure_nginx_on_restart(self):
        app=self.fixture.make();app.public['tls_ready']=True
        app.public['job']={'id':'before-finish','type':'install','status':'running','steps':[{'id':'schema','status':'running'},{'id':'finish','status':'pending'}]}
        app.save()
        with mock.patch.object(p,'configure_nginx') as configure:
            broker.Broker()
        configure.assert_not_called()

    def test_late_duplicate_start_never_replaces_existing_settings_or_restarts_worker(self):
        app=self.fixture.make()
        def worker(**kwargs):
            return types.SimpleNamespace(start=lambda:os.close(kwargs['args'][0]))
        with mock.patch.object(broker.threading,'Thread',side_effect=worker) as launch:
            original=app.dispatch('start',{'settings':fixtures.valid_settings()})['job']['id']
            changed=fixtures.valid_settings();changed['admin']['username']='different_operator'
            with self.assertRaises(p.Failure):app.dispatch('start',{'settings':changed})
            app.public['job']['status']='failed';app.save()
            with self.assertRaises(p.Failure):app.dispatch('start',{'settings':changed})
            self.assertEqual(launch.call_count,1)
        self.assertEqual(app.public['job']['id'],original)
        self.assertEqual(app.private['settings']['admin']['username'],'operator')


if __name__=='__main__':unittest.main()
