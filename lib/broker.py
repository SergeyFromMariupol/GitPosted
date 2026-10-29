"""Temporary root broker: fixed verbs, peer UID authentication, durable bounded jobs."""
from __future__ import annotations

import fcntl
import copy
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import socketserver
import struct
import subprocess
import threading
import time
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import provision as p


def failure(code, message):
    raise p.Failure(code, message)


def boolean(value):
    if type(value) is not bool:
        failure('INVALID_SETTINGS', 'Некорректный переключатель.')
    return value


def text(value, minimum=0, maximum=4096):
    if not isinstance(value, str) or not minimum <= len(value.encode('utf-8')) <= maximum or re.search(r'[\x00-\x1f\x7f]', value):
        failure('INVALID_SETTINGS', 'Некорректное значение настройки.')
    return value


def token(platform, value):
    value = text(value, 16, 4096)
    if value != value.strip() or re.search(r'\s', value) or platform not in ('telegram', 'max'):
        failure('INVALID_TOKEN', 'Проверьте токен бота.')
    if platform == 'telegram' and not re.fullmatch(r'[1-9][0-9]{5,11}:[A-Za-z0-9_-]{30,200}', value):
        failure('INVALID_TOKEN', 'Проверьте формат токена Telegram.')
    return value


def host_aliases(public, extra=None):
    aliases = public.get('acme_hosts', [])
    if not isinstance(aliases, list):
        failure('INVALID_HOST', 'Не удалось проверить ранее подключённые домены.')
    values = [public['host'], public.get('site_host', public['host']), *aliases]
    if extra is not None:
        values.append(extra)
    result = list(dict.fromkeys(p.host_name(host) for host in values))
    if len(result) > 32:
        failure('HOST_LIMIT', 'Достигнут предел 32 адресов сертификатов этой установки.')
    return result


def settings(value):
    if not isinstance(value, dict) or set(value) - {'admin', 'telegram', 'max', 'timezone', 'support_username', 'hcaptcha', 'mtproto'}:
        failure('INVALID_SETTINGS', 'Некорректные настройки установки.')
    admin = value.get('admin', {})
    if not isinstance(admin, dict) or set(admin) - {'username', 'password', 'password_confirm'}:
        failure('INVALID_SETTINGS', 'Некорректные настройки администратора.')
    username = text(admin.get('username'), 3, 64)
    if not re.fullmatch(r'[A-Za-z0-9_.@-]+', username):
        failure('INVALID_SETTINGS', 'Некорректный логин администратора.')
    password = text(admin.get('password'), 12, 72)
    if len(password) < 12 or not hmac.compare_digest(password.encode(), text(admin.get('password_confirm'), 12, 72).encode()):
        failure('INVALID_SETTINGS', 'Пароли не совпадают или слишком короткие.')
    out = {'admin': {'username': username, 'password_hash': p.php_password(password)}}
    for platform in ('telegram', 'max'):
        item = value.get(platform)
        if not isinstance(item, dict) or set(item) - {'enabled', 'token', 'takeover'}:
            failure('INVALID_SETTINGS', 'Некорректные настройки бота.')
        enabled = boolean(item.get('enabled'))
        out[platform] = {'enabled': enabled, 'token': token(platform, item.get('token')) if enabled else '',
                         'takeover': boolean(item.get('takeover', False)) if enabled else False}
    timezone = text(value.get('timezone', 'Europe/Moscow'), 1, 64)
    try:
        ZoneInfo(timezone)
    except (ZoneInfoNotFoundError, ValueError):
        failure('INVALID_SETTINGS', 'Неизвестный часовой пояс.')
    support = text(value.get('support_username', ''), 0, 33).removeprefix('@')
    if support and not re.fullmatch(r'[A-Za-z0-9_]{5,32}', support):
        failure('INVALID_SETTINGS', 'Некорректный контакт поддержки.')
    out.update(timezone=timezone, support_username=support)
    captcha = value.get('hcaptcha', {'enabled': False})
    if not isinstance(captcha, dict) or set(captcha) - {'enabled', 'sitekey', 'secret'}:
        failure('INVALID_SETTINGS', 'Некорректные параметры hCaptcha.')
    enabled = boolean(captcha.get('enabled'))
    key, secret = text(captcha.get('sitekey'), 36, 36) if enabled else '', text(captcha.get('secret'), 23, 131) if enabled else ''
    if enabled and (not re.fullmatch(r'[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}', key) or not re.fullmatch(r'ES_[A-Za-z0-9_-]{20,128}', secret)):
        failure('INVALID_SETTINGS', 'Некорректные ключи hCaptcha.')
    out['hcaptcha'] = {'enabled': enabled, 'sitekey': key, 'secret': secret}
    mt = value.get('mtproto', {'enabled': False})
    if not isinstance(mt, dict) or set(mt) - {'enabled', 'api_id', 'api_hash'}:
        failure('INVALID_SETTINGS', 'Некорректные параметры Telegram API.')
    enabled = boolean(mt.get('enabled'))
    api_id, api_hash = mt.get('api_id', 0) if enabled else 0, text(mt.get('api_hash'), 32, 32) if enabled else ''
    if enabled and (not out['telegram']['enabled'] or type(api_id) is not int or not 1 <= api_id <= 2147483647 or not re.fullmatch(r'[a-fA-F0-9]{32}', api_hash)):
        failure('INVALID_SETTINGS', 'Для Telegram API нужны подключённый бот и API ID/hash.')
    out['mtproto'] = {'enabled': enabled, 'api_id': api_id, 'api_hash': api_hash}
    return out


def helper(request, timeout=45, *, native_clean=False):
    child = None
    try:
        child = subprocess.Popen(['runuser', '-u', p.APP_USER, '--', 'env', 'POSTED_CONFIG_FILE=' + str(p.CONFIG),
                 '/usr/bin/php', '-d', 'display_errors=0', '-d', 'log_errors=0', p.INSTALL / ('bin/native-content-enable.php' if native_clean else 'bin/application.php')],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        try:
            raw, _ = child.communicate(input=json.dumps(request).encode(), timeout=timeout)
        except subprocess.TimeoutExpired:
            # Killing only runuser leaves its PHP child alive. That child could
            # still register an obsolete webhook after a failed job was revised.
            # The private process group belongs only to this fixed helper call.
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.communicate(timeout=5)
            failure('APPLICATION_FAILED', 'Проверка приложения не завершилась.')
        if len(raw) > 65536:
            failure('APPLICATION_FAILED', 'Приложение не подтвердило действие.')
    except (OSError, subprocess.TimeoutExpired):
        failure('APPLICATION_FAILED', 'Проверка приложения не завершилась.')
    finally:
        if child is not None:
            if child.poll() is None:
                try:
                    os.killpg(child.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                try:
                    child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    pass  # SIGKILL is pending if the kernel is blocked in I/O.
            for stream in (child.stdin, child.stdout, child.stderr):
                if stream is not None:
                    stream.close()
    try:
        result = json.loads(raw)
    except (ValueError, TypeError):
        failure('APPLICATION_FAILED', 'Приложение не подтвердило действие.')
    if not isinstance(result, dict) or result.get('ok') is not True or child.returncode != 0:
        error = result.get('error') if isinstance(result, dict) else None
        code = error.get('code', '') if isinstance(error, dict) else ''
        if not isinstance(code, str):
            code = ''
        safe = {'TAKEOVER_REQUIRED': 'У бота уже есть другой webhook. Подтвердите переключение.',
                'PLATFORM_API_FAILED': 'Платформа не подтвердила запрос. Проверьте токен и доступ к API.',
                'WEBHOOK_VERIFY_FAILED': 'Платформа не подтвердила адрес webhook.',
                'COMMANDS_NOT_CONFIRMED': 'Платформа не подтвердила меню команд. Продолжите существующую установку.',
                'SCHEMA_INVALID': 'Структура базы не прошла проверку.'}
        raise p.Failure(code if code in safe else 'APPLICATION_FAILED', safe.get(code, 'Приложение не подтвердило действие.'))
    if not isinstance(result.get('result'), dict):
        failure('APPLICATION_FAILED', 'Приложение не подтвердило действие.')
    return result['result']


class Broker:
    def __init__(self):
        self.lock = threading.RLock()
        self.checks = threading.BoundedSemaphore(2)
        self.private = p.read_json(p.STATE / 'private.json', {})
        if not isinstance(self.private, dict):
            failure('STATE_UNAVAILABLE', 'Состояние установщика недоступно.')
        # This single private checkpoint commits settings and the matching job together.
        # public.json is only its replaceable, credential-free HTTP projection.
        checkpoint = self.private.get('public_state')
        durable_job = self.private.get('job')
        if isinstance(checkpoint, dict) and isinstance(durable_job, dict):
            self.public = {**copy.deepcopy(checkpoint), 'job': copy.deepcopy(durable_job)}
        else:
            self.public = p.read_json(p.STATE / 'public.json')
        if not isinstance(self.public, dict):
            failure('STATE_UNAVAILABLE', 'Состояние установщика недоступно.')
        # A crash can leave nginx's completed routes ahead of the terminal
        # marker. Reopen the pending wizard from the authoritative checkpoint
        # before accepting requests; never move the terminal marker forward.
        p.reconcile_pending_finish(self.public)
        job = self.public.get('job', {})
        if job.get('status') == 'running':
            job['status'] = 'failed'
            job['error'] = {'code': 'INTERRUPTED', 'message': 'Установка прервана. Продолжите существующую задачу.'}
            for step in job.get('steps', []):
                if step['status'] == 'running':
                    step['status'] = 'failed'
            self.save()
        elif isinstance(checkpoint, dict) and isinstance(durable_job, dict):
            p.save_json(p.STATE / 'public.json', self.public, True)

    def save(self):
        self.private['job'] = copy.deepcopy(self.public.get('job', {}))
        self.private['public_state'] = copy.deepcopy({key: value for key, value in self.public.items() if key != 'job'})
        p.save_json(p.STATE / 'private.json', self.private)
        p.save_json(p.STATE / 'public.json', self.public, True)

    def revisable(self):
        job = self.public.get('job', {})
        config = self.private.get('config', {})
        activation = [step for step in job.get('steps', []) if step.get('id') == 'activate']
        return (job.get('type') == 'install' and job.get('status') == 'failed'
                and bool(self.private.get('settings')) and not (p.STATE / 'installed.json').exists()
                and not config.get('telegram_bot_enabled') and not config.get('max_bot_enabled')
                and len(activation) == 1 and activation[0].get('status') == 'pending')

    def bot_binding(self, platform):
        bindings = self.private.get('bot_bindings', {})
        if not isinstance(bindings, dict):
            failure('STATE_UNAVAILABLE', 'Состояние личности бота недоступно.')
        binding = bindings.get(platform)
        if binding is not None:
            if (not isinstance(binding, dict) or set(binding) != {'id', 'started'}
                    or not isinstance(binding['id'], str) or type(binding['started']) is not bool
                    or (binding['id'] != '' and re.fullmatch(r'[1-9][0-9]{0,63}', binding['id']) is None)):
                failure('STATE_UNAVAILABLE', 'Состояние личности бота недоступно.')
            return copy.deepcopy(binding)
        # Older interrupted packages did not store API identities. Telegram's
        # authenticated token embeds its bot id; an opaque MAX token does not.
        steps = self.public.get('job', {}).get('steps', [])
        started = any(step.get('id') == platform and step.get('status') in ('running', 'failed', 'done') for step in steps)
        config = self.private.get('config', {})
        selected = self.private.get('settings', {}).get(platform, {})
        started = started and selected.get('enabled') is True
        identity = ''
        if started and platform == 'telegram':
            identity = str(config.get('bot_token', '')).split(':', 1)[0]
            if re.fullmatch(r'[1-9][0-9]{5,11}', identity) is None:
                failure('REGISTERED_BOT_CHANGE_DENIED', 'Не удалось подтвердить ранее подключённого бота. Продолжите прежнюю настройку.')
        return {'id': identity, 'started': started}

    def checked_bot_binding(self, platform, checked):
        identity = checked.get('identity', {}) if isinstance(checked, dict) else {}
        bot_id = identity.get('id') if isinstance(identity, dict) else None
        if not isinstance(bot_id, str) or re.fullmatch(r'[1-9][0-9]{0,63}', bot_id) is None:
            failure('APPLICATION_FAILED', 'API не подтвердил личность выбранного бота.')
        binding = self.bot_binding(platform)
        if binding['started'] and binding['id'] and not hmac.compare_digest(binding['id'], bot_id):
            failure('REGISTERED_BOT_CHANGE_DENIED', 'Этот шаг уже подключал другого бота. Используйте новый токен того же бота; сменить бота можно в админке после завершения.')
        binding['id'] = bot_id
        self.private.setdefault('bot_bindings', {})[platform] = binding
        return identity

    def dispatch(self, action, value):
        if not isinstance(value, dict):
            failure('INVALID_REQUEST', 'Некорректный запрос.')
        fields = {'status': set(), 'enroll': {'code'}, 'check_bot': {'platform', 'token'},
                  'start': {'settings'}, 'revise': {'job_id', 'settings'}, 'retry': {'job_id'}, 'host': {'host', 'email'}}
        if not isinstance(action, str) or action not in fields or set(value) - fields[action]:
            failure('INVALID_ACTION', 'Неизвестное действие или параметр.')
        with self.lock:
            if action == 'status':
                if (p.STATE / 'installed.json').exists() and not self.public.get('installed'):
                    # If the worker committed its marker but lost power before public save.
                    with p.installer_lock():
                        p.reconcile_installed()
                    self.public = p.read_json(p.STATE / 'public.json')
                    self.private = p.read_json(p.STATE / 'private.json', {})
                result = copy.deepcopy(self.public)
                if isinstance(result.get('job'), dict):
                    result['job']['can_revise'] = self.revisable()
                return result
            if self.public.get('installed') or (p.STATE / 'installed.json').exists():
                failure('INSTALLED', 'Установка завершена.')
            if action == 'enroll':
                with p.installer_lock():
                    code = text(value.get('code'), 32, 160)
                    record = p.read_json(p.STATE / 'private.json', {}).get('enrollment', {})
                    if record.get('consumed'):
                        failure('CODE_USED', 'Ссылка уже использована.')
                    if record.get('expires_at', 0) <= time.time():
                        failure('CODE_EXPIRED', 'Ссылка устарела. Получите новую через SSH.')
                    digest = hashlib.pbkdf2_hmac('sha256', code.encode(), record.get('salt', '').encode(), 250000).hex()
                    if not hmac.compare_digest(digest, record.get('hash', '')):
                        failure('INVALID_CODE', 'Ссылка недействительна.')
                    self.private = p.read_json(p.STATE / 'private.json', {})
                    self.private['enrollment']['consumed'] = True
                    self.save()
                    return {'enrolled': True}
            if action == 'check_bot':
                platform = value.get('platform')
                checked_token = token(platform, value.get('token'))
            elif action in ('start', 'host', 'retry', 'revise'):
                return self.prepare_job(action, value)
            else:
                failure('INVALID_ACTION', 'Неизвестное действие.')
        if not self.checks.acquire(blocking=False):
            failure('BUSY', 'Проверка ботов уже выполняется.')
        try:
            return helper({'action': 'check', 'platform': platform, 'token': checked_token}, timeout=35)
        finally:
            self.checks.release()

    def prepare_job(self, action, value):
        fd = os.open(p.STATE / 'installer.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        suite_lock = None
        suite_locked = False
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                failure('BUSY', 'Операция установщика уже выполняется.')
            suite_lock = p.shared_lock()
            suite_lock.__enter__()
            suite_locked = True
            p.ensure_hosts_available(self.public)
            # Load once under the same interprocess lock used by SSH link reissue.
            self.private = p.read_json(p.STATE / 'private.json', {})
            if action in ('start', 'host', 'retry', 'revise'):
                if self.public.get('job', {}).get('status') == 'running':
                    failure('BUSY', 'Установка уже выполняется.')
                if action in ('retry', 'revise'):
                    job = self.public.get('job', {})
                    if value.get('job_id') != job.get('id') or job.get('status') != 'failed':
                        failure('INVALID_JOB', 'Нет этой незавершённой задачи.')
                    if action == 'revise':
                        if not self.revisable():
                            failure('REVISE_DENIED', 'Боты уже включены. Продолжите завершение существующей установки.')
                        actual = helper({'action': 'installation_state'}, timeout=15)
                        if (not isinstance(actual, dict) or set(actual) != {'configured', 'telegram_enabled', 'max_enabled'}
                                or any(type(actual[key]) is not bool for key in actual)
                                or actual['telegram_enabled'] or actual['max_enabled']):
                            failure('REVISE_DENIED', 'Боты уже включены или состояние не подтверждено. Продолжите существующую установку.')
                        revised = settings(value.get('settings'))
                        config = self.private.get('config')
                        native_revision = self.native_reconfiguration(config) if config else None
                        bindings = {platform: self.bot_binding(platform) for platform in ('telegram', 'max')}
                        for platform, token_key in (('telegram', 'bot_token'), ('max', 'max_bot_token')):
                            binding = bindings[platform]
                            if binding['started'] and (not revised[platform]['enabled']
                                    or (platform == 'max' and not binding['id'] and config
                                        and config.get(token_key, '') != revised[platform]['token'])):
                                failure('REGISTERED_BOT_CHANGE_DENIED', 'Этот бот уже подключался. Сохраните платформу и токен того же бота; смена или отключение доступны после завершения в админке.')
                        if config:
                            for platform, token_key, username_key, secret_key in (
                                    ('telegram', 'bot_token', 'bot_username', 'webhook_secret'),
                                    ('max', 'max_bot_token', 'max_bot_username', 'max_webhook_secret')):
                                if config.get(token_key, '') != revised[platform]['token'] and not bindings[platform]['started']:
                                    config[secret_key] = secrets.token_hex(32)
                                config[token_key] = revised[platform]['token']
                                config[username_key] = ''
                            config.update(telegram_bot_enabled=False, max_bot_enabled=False,
                                          timezone=revised['timezone'], service_admin_username=revised['support_username'],
                                          service_admin_url='https://t.me/' + revised['support_username'] if revised['support_username'] else '',
                                          hcaptcha=revised['hcaptcha'], admin_auth=revised['admin'],
                                          mtproto={'api_id': revised['mtproto']['api_id'], 'api_hash': revised['mtproto']['api_hash'],
                                                   'phone': '', 'two_factor_password': '', 'source_bot': ''})
                        if native_revision is not None:
                            native_revision['config_sha256'] = self.native_clean_hash(config)
                            self.private['native_reconfiguration'] = native_revision
                        self.private['settings'] = revised
                        self.private['bot_bindings'] = bindings
                        for step in job['steps']:
                            if step['id'] in ('config', 'telegram', 'max', 'verify', 'activate', 'finish'):
                                step['status'] = 'pending'
                else:
                    if self.private.get('settings'):
                        failure('RESUME_REQUIRED', 'Продолжите уже созданную установку.')
                    if action == 'start':
                        self.private['settings'] = settings(value.get('settings'))
                        ids = [('database', 'Подготовка отдельной базы данных'), ('config', 'Сохранение защищённых настроек'),
                               ('schema', 'Создание структуры базы'), ('telegram', 'Подключение Telegram'),
                               ('max', 'Подключение МАКС'), ('verify', 'Проверка приложения'),
                               ('activate', 'Включение выбранных ботов'), ('finish', 'Завершение установки')]
                    else:
                        host = p.host_name(value.get('host'))
                        email = text(value.get('email', ''), 0, 254)
                        if email and not re.fullmatch(r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}', email):
                            failure('INVALID_SETTINGS', 'Некорректный email для сертификата.')
                        if host == self.public['public_ip']:
                            failure('INVALID_HOST', 'Укажите домен, направленный на сервер.')
                        p.ensure_hosts_available({**self.public, 'pending_host': host,
                                                  'acme_hosts': host_aliases(self.public, host)})
                        self.private['host_settings'] = {'host': host, 'email': email}
                        ids = [('dns', 'Проверка DNS домена'), ('certificate', 'Получение сертификата'), ('nginx', 'Подключение домена')]
                    self.public['job'] = {'id': secrets.token_hex(12), 'type': 'install' if action == 'start' else 'host',
                                          'status': 'idle', 'steps': [{'id': key, 'title': title, 'status': 'pending'} for key, title in ids]}
                self.public['job']['status'] = 'running'
                self.public['job']['error'] = None
                try:
                    self.save()
                    # The durable pending hostname is visible to the sibling before
                    # its worker starts. Do not hand a thread-owned lease to it.
                    suite_lock.__exit__(None, None, None)
                    suite_locked = False
                    threading.Thread(target=self.execute, args=(fd,), daemon=True).start()
                except BaseException:
                    # A failed projection write or worker launch must never leave a running
                    # job without a worker. The private checkpoint makes it retryable.
                    self.public['job']['status'] = 'failed'
                    self.public['job']['error'] = {'code': 'INTERRUPTED', 'message': 'Запуск прерван. Продолжите существующую задачу.'}
                    try:
                        self.save()
                    except BaseException:
                        durable = p.read_json(p.STATE / 'private.json', {})
                        self.private = durable
                        if isinstance(durable.get('public_state'), dict) and isinstance(durable.get('job'), dict):
                            self.public = {**copy.deepcopy(durable['public_state']), 'job': copy.deepcopy(durable['job'])}
                            if self.public['job'].get('status') == 'running':
                                self.public['job']['status'] = 'failed'
                                self.public['job']['error'] = {'code': 'INTERRUPTED', 'message': 'Запуск прерван. Продолжите существующую задачу.'}
                        else:
                            self.public = p.read_json(p.STATE / 'public.json')
                    raise
                fd = None  # Worker owns the open file description until job completion.
                return {'job': self.public['job']}
        finally:
            if suite_locked:
                suite_lock.__exit__(None, None, None)
            if fd is not None:
                os.close(fd)

    def execute(self, fd):
        suite_lock = None
        suite_locked = False
        try:
            suite_lock = p.shared_lock()
            suite_lock.__enter__()
            suite_locked = True
            p.ensure_hosts_available(self.public)
            job = self.public['job']
            for step in job['steps']:
                if step['status'] in ('done', 'skipped'):
                    continue
                with self.lock:
                    step['status'] = 'running'
                    self.save()
                result = self.install_step(step['id']) if job['type'] == 'install' else self.host_step(step['id'])
                with self.lock:
                    step['status'] = 'skipped' if result == 'skipped' else 'done'
                    self.save()
            with self.lock:
                job['status'] = 'complete'
                self.save()
            if job['type'] == 'install':
                # UI reads terminal metadata directly; the privileged socket is no longer needed.
                try:
                    p.run(['systemctl', 'disable', 'gitposted-installer.service'])
                    threading.Timer(3, lambda: p.run(['systemctl', 'stop', 'gitposted-installer.service'])).start()
                except p.Failure:
                    pass  # Installed marker and locked HTTP routes remain authoritative.
        except BaseException as error:
            with self.lock:
                if (p.STATE / 'installed.json').exists():
                    p.reconcile_installed()
                    self.public = p.read_json(p.STATE / 'public.json')
                    self.private = p.read_json(p.STATE / 'private.json', {})
                    return
                job = self.public['job']
                job['status'] = 'failed'
                code = error.code if isinstance(error, p.Failure) else 'JOB_FAILED'
                message = str(error) if isinstance(error, p.Failure) else 'Установка прервана. Проверьте состояние и повторите задачу.'
                job['error'] = {'code': code, 'message': message}
                for step in job['steps']:
                    if step['status'] == 'running':
                        step['status'] = 'failed'
                self.save()
        finally:
            try:
                if suite_locked:
                    suite_lock.__exit__(None, None, None)
            finally:
                fcntl.flock(fd, fcntl.LOCK_UN)
                os.close(fd)

    def write_config(self, config):
        encoded = p.run(['php', '-r', 'echo "<?php\\ndeclare(strict_types=1);\\nreturn ".var_export(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR),true).";\\n";'],
                        data=json.dumps(config).encode(), safe='Не удалось подготовить конфигурацию.')
        p.atomic(p.CONFIG, encoded, 0o600, *p.identity(p.APP_USER))

    @staticmethod
    def native_clean_hash(value):
        return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()).hexdigest()

    @staticmethod
    def native_database_identity(config):
        d = config.get('database', {})
        if (d.get('host') != 'localhost' or d.get('charset') != 'utf8mb4'
                or not isinstance(d.get('name'), str) or not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,63}', d['name'])
                or not isinstance(d.get('user'), str) or not re.fullmatch(r'[A-Za-z][A-Za-z0-9_]{0,31}', d['user'])
                or not isinstance(d.get('password'), str) or not re.fullmatch(r'[a-f0-9]{64}', d['password'])):
            failure('DATABASE_IDENTITY', 'Не подтверждены параметры отдельной базы установки.')
        return {k: d[k] for k in ('host', 'name', 'user', 'charset')}

    def create_database_owned(self, config):
        identity = self.native_database_identity(config)
        record = self.private.get('database_creation')
        if record is None:
            # Old optimistic database_owned checkpoints are not evidence of a fresh CREATE.
            if self.private.get('database_owned'):
                failure('DATABASE_PROVENANCE_REQUIRED', 'Происхождение базы не подтверждено. Автоматическое принятие существующей базы запрещено.')
            name, user = identity['name'], identity['user']
            db_count = p.run(['mysql', '--protocol=socket', '-NBe', "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" + name + "'"]).strip()
            user_count = p.run(['mysql', '--protocol=socket', '-NBe', "SELECT COUNT(*) FROM mysql.user WHERE User='" + user + "'"]).strip()
            if db_count != b'0' or user_count != b'0':
                failure('DATABASE_EXISTS', 'База или пользователь уже существуют. Автоматическая перезапись запрещена.')
            record = {'version': 1, 'nonce': secrets.token_hex(16), 'job_id': self.public['job']['id'],
                      'identity': identity, 'phase': 'database_create_intent', 'requested_at': int(time.time())}
            self.private['database_creation'] = record
            self.save()  # Persist BEFORE the only fresh CREATE; an unknown outcome is never adopted.
            p.run(['mysql', '--protocol=socket', '--binary-mode=1'], data=("CREATE DATABASE `" + name + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n").encode(), safe='Создание новой базы не подтверждено. Автоматический повтор запрещён.')
            record['phase'] = 'database_created_known'
            record['created_at'] = int(time.time())
            self.private['database_owned'] = True  # ONLY after known fresh CREATE, never before.
            self.save()
        if (not isinstance(record, dict) or record.get('version') != 1 or record.get('identity') != identity
                or record.get('job_id') != self.public['job']['id'] or not self.private.get('database_owned')):
            failure('DATABASE_PROVENANCE_REQUIRED', 'Происхождение базы не подтверждено.')
        if record.get('phase') in ('database_create_intent', 'user_create_intent'):
            failure('DATABASE_CREATE_UNKNOWN', 'Результат создания не определён. Автоматическое принятие или удаление запрещено.')
        if record['phase'] == 'database_created_known':
            record['phase'] = 'user_create_intent'; self.save()
            sql = "CREATE USER '" + identity['user'] + "'@'localhost' IDENTIFIED BY '" + config['database']['password'] + "';\n"
            p.run(['mysql', '--protocol=socket', '--binary-mode=1'], data=sql.encode(), safe='Создание пользователя не подтверждено. Автоматический повтор запрещён.')
            record['phase'] = 'user_created_known'; self.save()
        if record['phase'] == 'user_created_known':
            sql = "GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON `" + identity['name'] + "`.* TO '" + identity['user'] + "'@'localhost';\n"
            p.run(['mysql', '--protocol=socket', '--binary-mode=1'], data=sql.encode(), safe='Не удалось выдать права подтверждённому пользователю этой базы.')
            record['phase'] = 'database_ready'; self.save()
        if record['phase'] != 'database_ready':
            failure('DATABASE_PROVENANCE_REQUIRED', 'База не готова к созданию схемы.')

    def native_clean_sources(self, config, *, registration_reconcile=False, activation_recovery=False):
        flags = (config.get('telegram_bot_enabled'), config.get('max_bot_enabled'))
        selected_flags = tuple(self.private['settings'][platform]['enabled'] for platform in ('telegram', 'max'))
        permitted_flags = ((False, False), selected_flags) if activation_recovery and registration_reconcile else ((False, False),)
        if (self.public.get('installed') is not False or (p.STATE / 'installed.json').exists() or flags not in permitted_flags):
            failure('NATIVE_CLEAN_GATE', 'Активация допустима только до подключения и включения ботов.')
        registration = None
        if registration_reconcile:
            job = self.public.get('job', {})
            statuses = {step['id']: step.get('status') for step in job.get('steps', [])}
            if job.get('status') != 'running' or statuses.get('schema') != 'done' or statuses.get('verify') != 'done':
                failure('NATIVE_REGISTRATION_UNKNOWN', 'Регистрация и проверка ботов должны быть подтверждены до включения.')
            platforms = {}
            for platform in ('telegram', 'max'):
                selected = self.private['settings'][platform]['enabled']
                binding = self.bot_binding(platform)
                if selected:
                    if statuses.get(platform) != 'done' or binding.get('started') is not True or not binding.get('id'):
                        failure('NATIVE_REGISTRATION_UNKNOWN', 'Регистрация выбранного бота не подтверждена.')
                elif statuses.get(platform) != 'skipped' or binding.get('started') is not False:
                    failure('NATIVE_REGISTRATION_UNKNOWN', 'Состояние пропущенной платформы не подтверждено.')
                platforms[platform] = {'selected': selected, 'status': statuses[platform], 'binding': binding}
            registration = {'version': 1, 'job_id': job['id'], 'schema_status': 'done', 'verify_status': 'done', 'platforms': platforms}
        elif any(self.bot_binding(platform)['started'] for platform in ('telegram', 'max')):
            failure('NATIVE_CLEAN_GATE', 'Первая запись Native запрещена после начала регистрации.')
        files = p.package_files(p.INSTALL)
        readers = {name[len('payload/src/'):]: hashlib.sha256(value).hexdigest()
                   for name, value in sorted(files.items()) if name.startswith('payload/src/')}
        expected_root = p.APP / 'releases' / p.VERSION / 'src'
        current = p.APP / 'current'
        if not current.is_symlink() or current.resolve() / 'src' != expected_root or not expected_root.is_dir():
            failure('NATIVE_READERS', 'Текущий комплект приложения не подтверждён.')
        paths = p.validate_source(expected_root)
        actual = {str(path.relative_to(expected_root)).replace('\\', '/'): hashlib.sha256(path.read_bytes()).hexdigest()
                  for path in paths if path.is_file()}
        if not readers or actual != readers:
            failure('NATIVE_READERS', 'Файлы приложения отличаются от проверенного пакета.')
        expected_nginx = p.nginx_content(self.public)
        if p.path_prefix(self.public):
            expected_nginx = p.gateway._backend('gitposted', expected_nginx)
        if (p.SITE.is_symlink() or not p.SITE.is_file() or p.SITE.read_bytes() != expected_nginx.encode()
                or not p.ENABLED_SITE.is_symlink() or os.readlink(p.ENABLED_SITE) != str(p.SITE)
                or p.ENABLED_SITE.lstat().st_uid != 0):
            failure('NATIVE_CLEAN_GATE', 'Не подтверждён закрытый веб-вход незавершённой установки.')
        return {'readers': readers, 'reader_manifest_sha256': self.native_clean_hash(readers),
                'helper_sha256': hashlib.sha256(files['bin/native-content-enable.php']).hexdigest(),
                'nginx_sha256': hashlib.sha256(expected_nginx.encode()).hexdigest(),
                'installation_pending': True, 'bots_not_registered': registration is None,
                'registration_phase': 'before_registration' if registration is None else 'verified_registration',
                'registration_proof': registration}

    def native_clean_request(self, config, action, *, allow_insert=False, registration_reconcile=False, activation_recovery=False):
        record = self.private.get('database_creation')
        if (not isinstance(record, dict) or record.get('phase') != 'database_ready'
                or record.get('identity') != self.native_database_identity(config)
                or record.get('job_id') != self.public['job']['id'] or self.private.get('database_owned') is not True):
            failure('NATIVE_CLEAN_PROVENANCE', 'Подтверждение новой базы отсутствует; Native остаётся выключенным.')
        sources = self.native_clean_sources(config, registration_reconcile=registration_reconcile, activation_recovery=activation_recovery)
        state = self.private.get('native_clean', {})
        if state and (state.get('reader_manifest_sha256') != sources['reader_manifest_sha256']
                      or state.get('helper_sha256') != sources['helper_sha256']):
            failure('NATIVE_READERS', 'Комплект изменился после начала схемы. Автоматическое продолжение запрещено.')
        return {'action': action, 'creation_receipt': record, 'sources': sources,
                'phase': state.get('phase', 'before_schema'), 'plan': state.get('plan'),
                'plan_sha256': state.get('plan_sha256'), 'allow_insert': allow_insert,
                'activation_intent': self.private.get('native_activation') if activation_recovery else None}

    def initialize_native_clean_schema(self, config):
        state = self.private.get('native_clean')
        if state is None or state.get('phase') == 'schema_started':
            # Read-only partial-schema guard BEFORE ordinary initialization on resume.
            inspected_request = self.native_clean_request(config, 'inspect')
            helper(inspected_request, timeout=60, native_clean=True)
            if state is None:
                state = {'version': 1, 'phase': 'schema_started',
                         'reader_manifest_sha256': inspected_request['sources']['reader_manifest_sha256'],
                         'helper_sha256': inspected_request['sources']['helper_sha256']}
                self.private['native_clean'] = state; self.save()
            helper({'action': 'initialize', 'config': config}, timeout=180)
            state['phase'] = 'schema_seeded'; self.save()
        if state.get('phase') == 'schema_seeded':
            inspected = helper(self.native_clean_request(config, 'inspect'), timeout=60, native_clean=True)
            if inspected.get('complete') is not True or inspected.get('capability_state') != 'absent':
                failure('NATIVE_CLEAN_SCHEMA', 'Новая схема не готова к явной активации Native.')
            state.update(plan=inspected['plan'], plan_sha256=inspected['plan_sha256'], phase='enable_started')
            self.save()  # Unknown/lost child output never turns into another INSERT.
            result = helper(self.native_clean_request(config, 'enable_clean', allow_insert=True), timeout=60, native_clean=True)
        elif state.get('phase') in ('enable_started', 'enabled'):
            result = helper(self.native_clean_request(config, 'enable_clean'), timeout=60, native_clean=True)
        else:
            failure('NATIVE_CLEAN_PHASE', 'Не подтверждена стадия активации Native.')
        if result.get('enabled') is not True:
            failure('NATIVE_CLEAN_UNKNOWN', 'Активация Native не подтверждена. Автоматический повтор записи запрещён.')
        state.update(phase='enabled', certificate_sha256=result['certificate_sha256']); self.save()

    def native_reconfiguration(self, config):
        state = self.private.get('native_clean')
        if not state or state.get('phase') in ('schema_started', 'schema_seeded'):
            return None
        if state.get('phase') != 'enabled':
            failure('NATIVE_RECONFIGURATION_UNKNOWN', 'Сначала подтвердите исход включения Native без изменения настроек.')
        old = self.private.get('native_reconfiguration')
        current = self.native_clean_hash(config)
        if old is None:
            if state.get('plan', {}).get('disabled_config_sha256') != current:
                failure('NATIVE_RECONFIGURATION_UNKNOWN', 'Исходные настройки Native изменились без подтверждённой правки.')
        elif (not isinstance(old, dict) or set(old) != {'version', 'job_id', 'plan_sha256', 'config_sha256'}
              or old.get('version') != 1 or old.get('job_id') != self.public['job']['id']
              or old.get('plan_sha256') != state.get('plan_sha256') or old.get('config_sha256') != current):
            failure('NATIVE_RECONFIGURATION_UNKNOWN', 'Подтверждение изменения настроек не совпадает.')
        return {'version': 1, 'job_id': self.public['job']['id'],
                'plan_sha256': state['plan_sha256'], 'config_sha256': current}

    def verify_native_clean_before_activation(self, config):
        state = self.private.get('native_clean', {})
        if state.get('phase') != 'enabled':
            failure('NATIVE_CLEAN_NOT_ENABLED', 'Native не подтверждён; очереди остаются выключенными.')
        result = helper(self.native_clean_request(config, 'inspect', registration_reconcile=True), timeout=60, native_clean=True)
        if (result.get('complete') is not True or result.get('capability_state') != 'same'
                or result.get('certificate_sha256') != state.get('certificate_sha256')):
            failure('NATIVE_CLEAN_NOT_ENABLED', 'Native не подтверждён; очереди остаются выключенными.')
        if result.get('plan_sha256') != state.get('plan_sha256'):
            # Only an explicit durable wizard revision may change the config component.
            # The actual readonly inspection must preserve every schema/reader/certificate field.
            authorization = self.private.get('native_reconfiguration')
            if authorization is None or authorization != self.native_reconfiguration(config):
                failure('NATIVE_CLEAN_NOT_ENABLED', 'Изменение настроек Native не подтверждено.')
            plan = result.get('plan')
            if not isinstance(plan, dict) or result.get('plan_sha256') != self.native_clean_hash(plan):
                failure('NATIVE_CLEAN_NOT_ENABLED', 'План Native не подтверждён.')
            previous = state.get('plan', {})
            comparable = copy.deepcopy(plan)
            comparable['disabled_config_sha256'] = previous.get('disabled_config_sha256')
            if comparable != previous or plan.get('disabled_config_sha256') != self.native_clean_hash(config):
                failure('NATIVE_CLEAN_NOT_ENABLED', 'Схема или файлы изменились после создания Native.')
            state.update(plan=plan, plan_sha256=result['plan_sha256'])
            self.private.pop('native_reconfiguration', None)
            self.save()  # Metadata only; no capability insertion or schema mutation.

    def activate_native_clean(self, config):
        selected = {platform: self.private['settings'][platform]['enabled'] for platform in ('telegram', 'max')}
        actual = helper({'action': 'installation_state'}, timeout=15)
        if (not isinstance(actual, dict) or set(actual) != {'configured', 'telegram_enabled', 'max_enabled'}
                or any(type(v) is not bool for v in actual.values()) or actual['configured'] is not True):
            failure('NATIVE_ACTIVATION_UNKNOWN', 'Текущее состояние включения не подтверждено.')
        current = {'telegram': actual['telegram_enabled'], 'max': actual['max_enabled']}
        state = self.private.get('native_clean', {})
        if any(current.values()):
            intent = self.private.get('native_activation')
            if current != selected or not isinstance(intent, dict) or state.get('phase') != 'enabled':
                failure('NATIVE_ACTIVATION_UNKNOWN', 'Неожиданные флаги включения. Автоматическая перезапись запрещена.')
            result = helper(self.native_clean_request(config, 'inspect_activation', registration_reconcile=True, activation_recovery=True), timeout=60, native_clean=True)
            if (result.get('activation_reconciled') is not True or result.get('data_writes') != 0
                    or result.get('certificate_sha256') != state.get('certificate_sha256')
                    or result.get('plan_sha256') != state.get('plan_sha256')):
                failure('NATIVE_ACTIVATION_UNKNOWN', 'Исход включения не подтверждён; данные не изменены.')
            config['telegram_bot_enabled'] = selected['telegram']
            config['max_bot_enabled'] = selected['max']
            intent['phase'] = 'config_written_reconciled'
            self.save()  # Checkpoint only. Never rewrite active config or a capability.
            return
        self.verify_native_clean_before_activation(config)
        sources = self.native_clean_sources(config, registration_reconcile=True)
        target = copy.deepcopy(config)
        target['telegram_bot_enabled'] = selected['telegram']; target['max_bot_enabled'] = selected['max']
        self.private['native_activation'] = {
            'version': 1, 'phase': 'config_write_started', 'job_id': self.public['job']['id'],
            'creation_receipt_sha256': self.native_clean_hash(self.private['database_creation']),
            'plan_sha256': state['plan_sha256'], 'certificate_sha256': state['certificate_sha256'],
            'reader_manifest_sha256': sources['reader_manifest_sha256'],
            'registration_proof_sha256': self.native_clean_hash(sources['registration_proof']),
            'disabled_config_sha256': self.native_clean_hash(config),
            'target_config_sha256': self.native_clean_hash(target), 'target_flags': selected}
        self.save()  # Durable exact intent BEFORE the possible activation boundary.
        config.update(telegram_bot_enabled=selected['telegram'], max_bot_enabled=selected['max'])
        self.write_config(config)
        self.private['native_activation']['phase'] = 'config_written_known'
        self.save()

    def install_step(self, step):
        selected = self.private['settings']
        config = self.private.get('config')
        if step == 'database':
            if not config:
                config = {'bot_token': selected['telegram']['token'], 'bot_username': '', 'telegram_bot_enabled': False,
                          'max_bot_token': selected['max']['token'], 'max_bot_username': '', 'max_bot_enabled': False,
                          'telegram_web_window_enabled': True,  # User opt-in stays off; platform flags still gate access.
                          'notification_telegram_id': 0, 'notification_max_id': 0,
                          'timezone': selected['timezone'], 'base_url': self.public.get('site_url', self.public['base_url']),
                          'data_dir': str(p.DATA), 'service_admin_username': selected['support_username'],
                          'service_admin_url': 'https://t.me/' + selected['support_username'] if selected['support_username'] else '',
                          'webhook_secret': secrets.token_hex(32), 'max_webhook_secret': secrets.token_hex(32),
                          'max_api_base_url': 'https://platform-api2.max.ru', 'max_ca_file': str(p.DATA / 'certs/russiantrustedca.pem'),
                          'max_forbidden_reupload_limit_bytes': 536870912, 'max_seed_channels': [],
                          'hcaptcha': selected['hcaptcha'], 'admin_auth': selected['admin'],
                          'mtproto': {'api_id': selected['mtproto']['api_id'], 'api_hash': selected['mtproto']['api_hash'], 'phone': '', 'two_factor_password': '', 'source_bot': ''},
                          'database': {'host': 'localhost', 'name': 'GitPosted', 'user': 'gitposted_app', 'password': secrets.token_hex(32), 'charset': 'utf8mb4'}}
                self.private['config'] = config
                self.save()
            self.create_database_owned(config)
        elif step == 'config':
            revision = self.native_reconfiguration(config) if self.private.get('native_reconfiguration') is not None else None
            for platform in ('telegram', 'max'):
                if selected[platform]['enabled']:
                    checked = helper({'action': 'check', 'platform': platform, 'token': selected[platform]['token']}, timeout=35)
                    identity = self.checked_bot_binding(platform, checked)
                    config['bot_username' if platform == 'telegram' else 'max_bot_username'] = identity['username']
            if revision is not None:
                revision['config_sha256'] = self.native_clean_hash(config)
                self.private['native_reconfiguration'] = revision
            self.save()
            self.write_config(config)
        elif step == 'schema':
            self.initialize_native_clean_schema(config)
        elif step in ('telegram', 'max'):
            if not selected[step]['enabled']:
                return 'skipped'
            binding = self.bot_binding(step)
            if not binding['id']:
                # Anchor a legacy interrupted registration with its unchanged
                # configured token before retrying any mutation.
                checked = helper({'action': 'check', 'platform': step, 'token': selected[step]['token']}, timeout=35)
                self.checked_bot_binding(step, checked)
                binding = self.bot_binding(step)
            binding['started'] = True
            self.private.setdefault('bot_bindings', {})[step] = binding
            self.save()
            # A confirmed webhook plus localized command menus needs several
            # bounded API calls; retain a finite budget for the entire child.
            helper({'action': 'register', 'platform': step, 'takeover': selected[step]['takeover']}, timeout=300)
        elif step == 'verify':
            helper({'action': 'verify', 'platforms': [x for x in ('telegram', 'max') if selected[x]['enabled']]}, timeout=300)
        elif step == 'activate':
            self.activate_native_clean(config)
        elif step == 'finish':
            candidate = {**self.public, 'installed': True, 'admin_url': config['base_url'] + '/control/'}
            p.configure_nginx(candidate)
            p.save_json(p.STATE / 'installed.json', {'installed_at': int(time.time()), 'version': p.VERSION,
                                                   'admin_url': candidate['admin_url']})
            self.public.update(candidate)
            # Retain configuration only in /etc/gitposted; remove duplicate root-held credentials.
            self.private.pop('settings', None)
            self.private.pop('config', None)
            self.private.pop('enrollment', None)
            self.private.pop('bot_bindings', None)
            (p.STATE / 'access.json').unlink(missing_ok=True)
        return None

    def host_step(self, step):
        settings = self.private['host_settings']
        host, ip = settings['host'], self.public['public_ip']
        if step == 'dns':
            p.dns_matches(host, ip)
            # Retain previous HTTP-01 routes while their certificates exist.
            self.public['acme_hosts'] = host_aliases(self.public, host)
            self.save()
        elif step == 'certificate':
            self.public['acme_hosts'] = host_aliases(self.public, host)
            self.save()
            # Route HTTP-01 for the reserved domain to this project's ACME
            # directory before requesting its first certificate.
            p.configure_nginx({**self.public, 'pending_host': host})
            p.obtain_certificate(host, ip, settings['email'])
        elif step == 'nginx':
            candidate = {**self.public, 'site_host': host, 'site_url': 'https://' + host + p.path_prefix(self.public),
                         'acme_hosts': host_aliases(self.public, host),
                         'allowed_hosts': list(dict.fromkeys([self.public['host'], host]))}
            candidate.pop('pending_host', None)
            p.configure_nginx(candidate)
            self.public.update(candidate)


class Server(socketserver.ThreadingUnixStreamServer):
    daemon_threads = True
    request_queue_size = 16


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        try:
            _, uid, _ = struct.unpack('3i', self.connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i')))
            if uid != self.server.setup_uid:
                return
            self.connection.settimeout(50)
            raw = self.rfile.readline(32769)
            if not raw.endswith(b'\n') or len(raw) > 32768:
                return
            request = json.loads(raw)
            if not isinstance(request, dict) or set(request) != {'action', 'payload'}:
                return
            result = self.server.broker.dispatch(request['action'], request['payload'])
            envelope = {'ok': True, 'result': result}
        except p.Failure as error:
            envelope = {'ok': False, 'error': {'code': error.code, 'message': str(error)}}
        except BaseException:
            envelope = {'ok': False, 'error': {'code': 'REQUEST_FAILED', 'message': 'Действие не выполнено.'}}
        try:
            self.wfile.write(json.dumps(envelope, ensure_ascii=False).encode() + b'\n')
        except OSError:
            pass


def main():
    if os.geteuid() != 0:
        raise SystemExit('Root broker required.')
    if (p.STATE / 'installed.json').exists():
        with p.installer_lock():
            p.reconcile_installed()
        return
    path = p.STATE / 'broker.sock'
    if path.exists() or path.is_symlink():
        import stat
        info = path.lstat()
        if not stat.S_ISSOCK(info.st_mode) or info.st_uid != 0:
            raise SystemExit('Unsafe socket path.')
        path.unlink()
    server = Server(str(path), Handler)
    server.setup_uid = p.identity(p.SETUP_USER)[0]
    # State recovery includes read/modify/write and nginx reconciliation.
    # Serialize the entire startup with SSH enrollment reissue, not only
    # the individual file writes, so a fresh link cannot be overwritten.
    with p.installer_lock():
        server.broker = Broker()
    os.chown(path, 0, p.identity(p.SETUP_USER)[1])
    os.chmod(path, 0o660)
    try:
        server.serve_forever(poll_interval=0.25)
    finally:
        server.server_close()
        path.unlink(missing_ok=True)


if __name__ == '__main__':
    main()
