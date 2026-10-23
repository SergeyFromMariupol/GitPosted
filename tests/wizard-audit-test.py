#!/usr/bin/env python3
"""New crash-boundary regressions. Only temporary fixtures; no services or API."""
from __future__ import annotations

import importlib.util
import copy
import os
import types
from pathlib import Path
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location('broker_audit_fixtures', Path(__file__).with_name('broker_test.py'))
fixtures = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixtures)
broker, p = fixtures.broker, fixtures.p


class WizardCrashAudit(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.BrokerTests(methodName='runTest')
        self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)

    def test_start_failure_between_private_and_public_commit_does_not_strand_settings(self):
        app = self.fixture.make()
        wrote_private = False

        def fail_public(path, value, public=False):
            nonlocal wrote_private
            if Path(path).name == 'private.json':
                wrote_private = True
                self.fixture.save_json(path, value, public)
            elif wrote_private:
                raise OSError('Simulated public projection write failure')
            else:
                self.fixture.save_json(path, value, public)

        with mock.patch.object(p, 'save_json', side_effect=fail_public), mock.patch.object(broker.threading, 'Thread'):
            with self.assertRaises((OSError, p.Failure)):
                app.dispatch('start', {'settings': fixtures.valid_settings()})
        recovered = broker.Broker()
        if recovered.private.get('settings'):
            self.assertEqual(recovered.public.get('job', {}).get('status'), 'failed',
                             'Durable settings have no recoverable failed job after restart')
            self.assertTrue(recovered.public['job'].get('id'), 'Retry needs the original durable job id')
        else:
            self.assertNotEqual(recovered.public.get('job', {}).get('status'), 'running')

    def test_worker_spawn_failure_records_failed_job_instead_of_permanent_running(self):
        app = self.fixture.make()
        with mock.patch.object(broker.threading, 'Thread') as thread:
            thread.return_value.start.side_effect = RuntimeError('Simulated inability to spawn worker')
            with self.assertRaises((RuntimeError, p.Failure)):
                app.dispatch('start', {'settings': fixtures.valid_settings()})
        self.assertEqual(app.public.get('job', {}).get('status'), 'failed',
                         'No worker exists but public status remains running until an operator restarts broker')

    def failed_install(self):
        app = self.fixture.make()
        app.private['settings'] = broker.settings(fixtures.valid_settings(True, True))
        with mock.patch.object(p, 'run', return_value=b'0'):
            app.install_step('database')
        app.private['database_owned'] = True
        app.public['job'] = {'id': 'original-job', 'type': 'install', 'status': 'failed',
                            'steps': [{'id': step, 'status': 'done' if step in ('database', 'config', 'schema', 'telegram')
                                       else ('failed' if step == 'max' else 'pending')}
                                      for step in ('database', 'config', 'schema', 'telegram', 'max', 'verify', 'activate', 'finish')]}
        app.save()
        return app

    def revise(self, app, value=None, actual=None):
        with mock.patch.object(broker, 'helper', return_value=actual or {'configured': True, 'telegram_enabled': False, 'max_enabled': False}), mock.patch.object(broker.threading, 'Thread', side_effect=lambda **kwargs: types.SimpleNamespace(start=lambda: os.close(kwargs['args'][0]))):
            return app.dispatch('revise', {'job_id': app.public['job']['id'], 'settings': value or fixtures.valid_settings()})

    def test_public_projection_loss_recovers_job_and_site_from_one_private_commit(self):
        app = self.failed_install()
        app.public.update(site_url='https://committed.example', site_host='committed.example')
        with mock.patch.object(p, 'save_json', side_effect=lambda path, value, public=False: self.fixture.save_json(path, value, public) if not public else (_ for _ in ()).throw(OSError('projection crash'))):
            with self.assertRaises(OSError): app.save()
        recovered = broker.Broker()
        self.assertEqual(recovered.public['job']['id'], 'original-job')
        self.assertEqual(recovered.public['site_url'], 'https://committed.example')
        self.assertEqual(p.read_json(self.fixture.state / 'public.json')['site_host'], 'committed.example')

    def test_private_checkpoint_can_recreate_a_missing_public_projection(self):
        app = self.failed_install()
        (self.fixture.state / 'public.json').unlink()
        recovered = broker.Broker()
        self.assertEqual(recovered.public['job']['id'], app.public['job']['id'])
        self.assertTrue((self.fixture.state / 'public.json').is_file())

    def test_private_commit_failure_rolls_back_unstarted_job_and_allows_new_start(self):
        app = self.fixture.make()
        with mock.patch.object(p, 'save_json', side_effect=OSError('private unavailable')), mock.patch.object(broker.threading, 'Thread'):
            with self.assertRaises(OSError): app.dispatch('start', {'settings': fixtures.valid_settings()})
        self.assertFalse(app.private.get('settings'))
        self.assertNotEqual(app.public['job']['status'], 'running')
        with mock.patch.object(broker.threading, 'Thread', side_effect=lambda **kwargs: types.SimpleNamespace(start=lambda: os.close(kwargs['args'][0]))):
            self.assertTrue(app.dispatch('start', {'settings': fixtures.valid_settings()})['job']['id'])

    def test_revise_keeps_database_credentials_schema_and_job_id_but_rotates_changed_tokens(self):
        app = self.failed_install()
        # This case changes candidates before their first registration. Once
        # registration starts, separate binding regressions enforce identity.
        next(step for step in app.public['job']['steps'] if step['id'] == 'telegram')['status'] = 'pending'
        next(step for step in app.public['job']['steps'] if step['id'] == 'max')['status'] = 'pending'
        app.save()
        before = copy.deepcopy(app.private['config'])
        changed = fixtures.valid_settings(True, False)
        changed['telegram']['token'] = '987654:' + 'b' * 32
        changed['admin']['username'] = 'new_operator'
        result = self.revise(app, changed)
        config = app.private['config']
        self.assertEqual(result['job']['id'], 'original-job')
        self.assertEqual(config['database'], before['database'])
        self.assertTrue(app.private['database_owned'])
        self.assertEqual(config['admin_auth']['username'], 'new_operator')
        self.assertNotEqual(config['webhook_secret'], before['webhook_secret'])
        self.assertNotEqual(config['max_webhook_secret'], before['max_webhook_secret'])
        self.assertEqual(config['max_bot_token'], '')
        self.assertFalse(config['telegram_bot_enabled']); self.assertFalse(config['max_bot_enabled'])
        steps = {step['id']: step['status'] for step in result['job']['steps']}
        self.assertEqual(steps['database'], 'done'); self.assertEqual(steps['schema'], 'done')
        self.assertTrue(all(steps[key] == 'pending' for key in ('config', 'telegram', 'max', 'verify', 'activate', 'finish')))

    def test_revise_unchanged_token_keeps_own_webhook_secret_for_idempotent_retry(self):
        app = self.failed_install()
        before = app.private['config']['webhook_secret']
        self.revise(app, fixtures.valid_settings(True, True))
        self.assertEqual(app.private['config']['webhook_secret'], before)

    def test_revise_private_commit_failure_restores_original_durable_settings(self):
        app = self.failed_install()
        before = copy.deepcopy(app.private['settings'])
        with mock.patch.object(p, 'save_json', side_effect=OSError('private unavailable')):
            with self.assertRaises(OSError): self.revise(app, fixtures.valid_settings(True, True))
        self.assertEqual(app.private['settings'], before)
        self.assertEqual(app.public['job']['status'], 'failed')

    def test_revise_before_first_config_write_accepts_confirmed_absent_config(self):
        app = self.failed_install()
        for step in app.public['job']['steps']:
            if step['id'] in ('telegram', 'max'): step['status'] = 'pending'
        app.save()
        self.assertFalse(p.CONFIG.exists())
        self.revise(app, actual={'configured': False, 'telegram_enabled': False, 'max_enabled': False})
        self.assertEqual(app.public['job']['status'], 'running')

    def test_bot_identity_and_registration_intent_are_durable_before_api_mutation(self):
        app = self.failed_install()
        for step in app.public['job']['steps']:
            if step['id'] in ('telegram', 'max'): step['status'] = 'pending'
        app.save()
        calls = []
        def application(request, **kwargs):
            calls.append(request['action'])
            if request['action'] == 'check':
                return {'identity': {'id': '123456' if request['platform'] == 'telegram' else '987654', 'username': 'LocalFixtureBot'}}
            durable = p.read_json(self.fixture.state / 'private.json')
            self.assertEqual(durable['bot_bindings'][request['platform']], {'id': '123456', 'started': True})
            self.assertEqual(durable['bot_bindings']['max'], {'id': '987654', 'started': False})
            raise p.Failure('WEBHOOK_VERIFY_FAILED', 'Simulated unknown registration result')
        with mock.patch.object(broker, 'helper', side_effect=application), mock.patch.object(app, 'write_config') as write:
            app.install_step('config')
            self.assertEqual(p.read_json(self.fixture.state / 'private.json')['bot_bindings'],
                             {'telegram': {'id': '123456', 'started': False}, 'max': {'id': '987654', 'started': False}})
            write.assert_called_once()
            with self.assertRaises(p.Failure): app.install_step('telegram')
        self.assertEqual(calls, ['check', 'check', 'register'])
        recovered = broker.Broker()
        self.assertTrue(recovered.private['bot_bindings']['telegram']['started'])
        self.assertNotIn('bot_bindings', recovered.dispatch('status', {}))

    def test_revoked_token_can_be_replaced_by_same_bot_without_old_token_or_secret_rotation(self):
        for platform in ('telegram', 'max'):
            with self.subTest(platform=platform):
                app = self.failed_install()
                app.private['bot_bindings'] = {'telegram': {'id': '123456', 'started': True}, 'max': {'id': '987654', 'started': True}}
                app.save()
                secrets_before = {key: app.private['config'][key] for key in ('webhook_secret', 'max_webhook_secret')}
                revised = fixtures.valid_settings(True, True)
                revised[platform]['token'] = '123456:' + 'b' * 32 if platform == 'telegram' else 'replacement-max-token'
                self.revise(app, revised)
                checks = []
                def application(request, **kwargs):
                    self.assertEqual(request['action'], 'check')
                    checks.append(request['platform'])
                    self.assertEqual(request['token'], revised[request['platform']]['token'])
                    return {'identity': {'id': '123456' if request['platform'] == 'telegram' else '987654', 'username': 'LocalFixtureBot'}}
                with mock.patch.object(broker, 'helper', side_effect=application), mock.patch.object(app, 'write_config') as write:
                    app.install_step('config')
                self.assertEqual(checks, ['telegram', 'max'])
                write.assert_called_once()
                for key, value in secrets_before.items(): self.assertEqual(app.private['config'][key], value)
                self.assertTrue(app.private['bot_bindings'][platform]['started'])

    def test_changed_bot_id_fails_before_config_write_or_registration_and_preserves_binding(self):
        for platform in ('telegram', 'max'):
            with self.subTest(platform=platform):
                app = self.failed_install()
                bindings = {'telegram': {'id': '123456', 'started': True}, 'max': {'id': '987654', 'started': True}}
                app.private['bot_bindings'] = copy.deepcopy(bindings)
                app.save()
                revised = fixtures.valid_settings(True, True)
                revised[platform]['token'] = '654321:' + 'b' * 32 if platform == 'telegram' else 'other-max-bot-token'
                self.revise(app, revised)
                calls = []
                def application(request, **kwargs):
                    calls.append(request['action'])
                    self.assertEqual(request['action'], 'check')
                    identity = '654321' if request['platform'] == platform else ('123456' if request['platform'] == 'telegram' else '987654')
                    return {'identity': {'id': identity, 'username': 'LocalFixtureBot'}}
                with mock.patch.object(broker, 'helper', side_effect=application), mock.patch.object(app, 'write_config') as write:
                    with self.assertRaises(p.Failure) as error: app.install_step('config')
                    self.assertEqual(error.exception.code, 'REGISTERED_BOT_CHANGE_DENIED')
                    write.assert_not_called()
                self.assertNotIn('register', calls)
                self.assertEqual(app.private['bot_bindings'], bindings)
                self.assertEqual(p.read_json(self.fixture.state / 'private.json')['bot_bindings'], bindings)

    def test_started_platform_cannot_be_skipped_and_rejected_revision_changes_no_checkpoint(self):
        for platform in ('telegram', 'max'):
            with self.subTest(platform=platform):
                app = self.failed_install()
                app.private['bot_bindings'] = {'telegram': {'id': '123456', 'started': True}, 'max': {'id': '987654', 'started': True}}
                app.save()
                before = copy.deepcopy(app.private)
                revised = fixtures.valid_settings(True, True); revised[platform]['enabled'] = False
                with self.assertRaises(p.Failure) as error: self.revise(app, revised)
                self.assertEqual(error.exception.code, 'REGISTERED_BOT_CHANGE_DENIED')
                self.assertEqual(app.private, before)
                self.assertEqual(p.read_json(self.fixture.state / 'private.json'), before)

    def test_legacy_telegram_binding_recovers_from_token_prefix_and_preserves_secret(self):
        app = self.failed_install()
        revised = fixtures.valid_settings(True, True)
        revised['telegram']['token'] = '123456:' + 'b' * 32
        before = app.private['config']['webhook_secret']
        self.revise(app, revised)
        self.assertEqual(app.private['bot_bindings']['telegram'], {'id': '123456', 'started': True})
        self.assertEqual(app.private['config']['webhook_secret'], before)

    def test_legacy_max_changed_token_is_denied_until_unchanged_identity_is_anchored(self):
        app = self.failed_install()
        changed = fixtures.valid_settings(True, True); changed['max']['token'] = 'replacement-max-token'
        before = copy.deepcopy(app.private)
        with self.assertRaises(p.Failure) as error: self.revise(app, changed)
        self.assertEqual(error.exception.code, 'REGISTERED_BOT_CHANGE_DENIED')
        self.assertEqual(app.private, before)
        self.revise(app, fixtures.valid_settings(True, True))
        with mock.patch.object(broker, 'helper', side_effect=lambda request, **kwargs:
                {'identity': {'id': '123456' if request['platform'] == 'telegram' else '987654', 'username': 'LocalFixtureBot'}}), mock.patch.object(app, 'write_config'):
            app.install_step('config')
        self.assertEqual(p.read_json(self.fixture.state / 'private.json')['bot_bindings']['max'], {'id': '987654', 'started': True})

    def test_final_commit_and_terminal_recovery_remove_private_bot_bindings(self):
        for recovery in (False, True):
            with self.subTest(recovery=recovery):
                app = self.fixture.finish_fixture()
                app.private['bot_bindings'] = {'telegram': {'id': '123456', 'started': True}}
                app.save()
                with mock.patch.object(p, 'configure_nginx'), mock.patch.object(p, 'run', return_value=b''), mock.patch.object(broker.threading, 'Timer'):
                    if recovery:
                        self.fixture.save_json(self.fixture.state / 'installed.json', {'installed_at': 123, 'version': 'fixture', 'admin_url': 'https://local.example/control/'})
                        p.reconcile_installed()
                    else:
                        self.fixture.execute(app)
                self.assertNotIn('bot_bindings', p.read_json(self.fixture.state / 'private.json'))
                (self.fixture.state / 'installed.json').unlink(missing_ok=True)

    def test_revise_denied_after_activation_ever_started_or_actual_flag_is_active(self):
        for cause in ('running', 'failed', 'done', 'private-active', 'actual-active', 'malformed-state', 'marker'):
            with self.subTest(cause=cause):
                app = self.failed_install()
                actual = {'configured': True, 'telegram_enabled': False, 'max_enabled': False}
                if cause in ('running', 'failed', 'done'):
                    next(step for step in app.public['job']['steps'] if step['id'] == 'activate')['status'] = cause
                elif cause == 'private-active': app.private['config']['max_bot_enabled'] = True
                elif cause == 'actual-active': actual['max_enabled'] = True
                elif cause == 'malformed-state': actual = {'configured': False}
                elif cause == 'marker': self.fixture.save_json(self.fixture.state / 'installed.json', {'installed_at': 123})
                app.save()
                original = copy.deepcopy(app.private['settings'])
                with self.assertRaises(p.Failure): self.revise(app, actual=actual)
                self.assertEqual(app.private['settings'], original)
                (self.fixture.state / 'installed.json').unlink(missing_ok=True)

    def test_revise_rejects_wrong_job_and_never_calls_helper_or_spawns_worker(self):
        app = self.failed_install()
        with mock.patch.object(broker, 'helper') as helper, mock.patch.object(broker.threading, 'Thread') as worker:
            with self.assertRaises(p.Failure): app.dispatch('revise', {'job_id': 'other-job', 'settings': fixtures.valid_settings()})
            helper.assert_not_called(); worker.assert_not_called()


if __name__ == '__main__':
    unittest.main()
