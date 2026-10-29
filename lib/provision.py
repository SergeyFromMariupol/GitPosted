"""Clean-host bootstrap and bounded provisioning primitives. No production recovery."""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import ctypes
import errno
import fcntl
import hashlib
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import grp
import secrets
import shutil
import signal
import socket
import stat
import subprocess
import sys
import time
import tempfile
from urllib.parse import urlsplit

VERSION = '0.1.7'
INSTALL = Path('/opt/gitposted-installer')
APP = Path('/opt/gitposted')
STATE = Path('/var/lib/gitposted-installer')
DATA = Path('/var/lib/gitposted')
CONFIG = Path('/etc/gitposted/config.php')
LOG = Path('/var/log/gitposted')
ACME = Path('/var/www/gitposted-acme')
SITE = Path('/etc/nginx/sites-available/gitposted-installer')
ENABLED_SITE = Path('/etc/nginx/sites-enabled/gitposted-installer')
RENEWAL_DIR = Path('/etc/letsencrypt/renewal')
CERTBOT = STATE / 'certbot/bin/certbot'
APP_USER = 'gitposted'
SETUP_USER = 'gitposted-setup'
PACKAGE_LOCKS = (Path('/var/lib/dpkg/lock-frontend'), Path('/var/lib/dpkg/lock'))
SYSTEM_PACKAGES = ('nginx', 'mysql-server', 'php8.3-fpm', 'php8.3-cli', 'php8.3-mysql',
                   'php8.3-curl', 'php8.3-mbstring', 'python3-venv', 'ca-certificates',
                   'curl', 'cron', 'logrotate', 'unzip')


class Failure(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class BootstrapInterrupted(BaseException):
    """CLI cancellation must bypass normal validation, retries and remapping."""
    code = 'BOOTSTRAP_INTERRUPTED'


_coinstall_spec = importlib.util.spec_from_file_location('gitposted_coinstall', Path(__file__).with_name('coinstall.py'))
coinstall = importlib.util.module_from_spec(_coinstall_spec)
_coinstall_spec.loader.exec_module(coinstall)
_gateway_spec = importlib.util.spec_from_file_location('gitposted_gateway', Path(__file__).with_name('gateway.py'))
gateway = importlib.util.module_from_spec(_gateway_spec)
_gateway_spec.loader.exec_module(gateway)


def path_prefix(public):
    if not isinstance(public, dict):
        raise Failure('STATE_CONFLICT', 'Некорректное состояние адреса GitPosted.')
    value = public.get('path_prefix', '')
    if value not in ('', '/GitPosted') or ('routing_schema' in public and (type(public['routing_schema']) is not int or public['routing_schema'] != 1)):
        raise Failure('STATE_CONFLICT', 'Некорректный постоянный адрес GitPosted.')
    return value


def routing_call(method, *args):
    try:
        return method(*args)
    except gateway.GatewayError as error:
        raise Failure(error.code, str(error)) from None


def installation_prefix(ip, resume=False):
    if resume:
        return path_prefix(read_json(STATE / 'bootstrap.json', {}))
    peer = peer_installation(ip)
    return '/GitPosted' if peer is None or peer.get('path_prefix') else ''


@contextmanager
def shared_lock(*, create=True):
    try:
        with coinstall.shared_lock(create=create):
            yield
    except coinstall.CoInstallError as error:
        raise Failure(error.code, str(error)) from None


def peer_installation(ip=None, *, require_complete=True):
    try:
        return coinstall.inspect_peer('gitposted', ip, require_complete=require_complete)
    except coinstall.CoInstallError as error:
        raise Failure(error.code, str(error)) from None


def ensure_hosts_available(public):
    aliases = public.get('acme_hosts', [])
    if not isinstance(aliases, list) or len(aliases) > 32:
        raise Failure('INVALID_HOST', 'Слишком много дополнительных адресов сертификатов.')
    hosts = [public.get('host'), public.get('site_host', public.get('host')), public.get('pending_host')] + aliases
    try:
        return coinstall.validate_hosts('gitposted', hosts, public.get('public_ip'), path_prefix=path_prefix(public))
    except coinstall.CoInstallError as error:
        raise Failure(error.code, str(error)) from None


def verify_passive_apache_stub():
    if shutil.which('apache2'):
        raise Failure('EXISTING_SERVICE', 'На сервере уже есть Apache. Автоматическая установка остановлена.')
    try:
        present = coinstall.passive_apache_stub()
    except coinstall.CoInstallError as error:
        raise Failure(error.code, str(error)) from None
    if present:
        owner = run(['dpkg-query', '--search', '/etc/apache2/conf-available/php8.3-fpm.conf'],
                    safe='Не удалось подтвердить происхождение неактивного PHP-FPM-файла Apache.')
        if re.fullmatch(rb'php8\.3-fpm(?::[a-z0-9-]+)?: /etc/apache2/conf-available/php8\.3-fpm\.conf\n?', owner) is None:
            raise Failure('UNMANAGED_WEB_SERVER', 'Неактивный файл Apache не принадлежит штатному пакету PHP-FPM. Установка остановлена.')


def run(argv, *, data=None, timeout=120, safe='Команда системы не выполнена.', capture=True):
    """Never include stdin, raw stderr or argv in raised errors."""
    child = None
    communicated = False
    try:
        child = subprocess.Popen([str(x) for x in argv], stdin=subprocess.PIPE,
                                 stdout=subprocess.PIPE if capture else None,
                                 stderr=subprocess.PIPE if capture else None,
                                 start_new_session=True,
                                 env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive',
                                      'LC_ALL': 'C.UTF-8', 'NEEDRESTART_MODE': 'l'})
        try:
            output, _ = child.communicate(input=data, timeout=timeout)
            communicated = True
        except subprocess.TimeoutExpired:
            # apt/runuser can leave dpkg or helpers behind. End this private
            # process group before releasing the operation's shared lock.
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.communicate(timeout=5)
            raise Failure('SYSTEM_COMMAND_FAILED', safe) from None
    except (OSError, subprocess.TimeoutExpired):
        raise Failure('SYSTEM_COMMAND_FAILED', safe) from None
    finally:
        if child is not None:
            if not communicated or child.returncode:
                try:
                    os.killpg(child.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            if child.poll() is None:
                try:
                    child.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    pass
            for stream in (child.stdin, child.stdout, child.stderr):
                if stream is not None:
                    stream.close()
    if child.returncode:
        error = Failure('SYSTEM_COMMAND_FAILED', safe)
        error.command_returncode = child.returncode  # Bounded classification, never raw output.
        raise error
    return output if capture else b''


def identity(name):
    value = pwd.getpwnam(name)
    return value.pw_uid, value.pw_gid


def directory(path, mode=0o750, owner=None):
    path = Path(path)
    if not path.is_absolute() or path.resolve() != path or path.is_symlink():
        raise Failure('UNSAFE_PATH', 'Обнаружена неожиданная символическая ссылка.')
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, mode)
    if owner:
        os.chown(path, *identity(owner))


def atomic(path, content, mode=0o600, uid=0, gid=0):
    path = Path(path)
    if not path.is_absolute() or path.parent.resolve() != path.parent or path.is_symlink() or path.parent.is_symlink():
        raise Failure('UNSAFE_PATH', 'Обнаружен неподходящий путь конфигурации.')
    temporary = path.parent / ('.' + path.name + '.' + secrets.token_hex(8))
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content.encode() if isinstance(content, str) else content)
            stream.flush()
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), uid, gid)
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        sync_directory(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def save_json(path, value, public=False):
    atomic(path, json.dumps(value, ensure_ascii=False, separators=(',', ':')) + '\n',
           0o640 if public else 0o600, 0, identity(SETUP_USER)[1] if public else 0)


def read_json(path, default=None):
    try:
        if Path(path).is_symlink():
            raise Failure('UNSAFE_PATH', 'Небезопасный файл состояния.')
        return json.loads(Path(path).read_text())
    except FileNotFoundError:
        return default


def public_ip(value):
    try:
        address = ipaddress.ip_address(value)
        if address.version != 4 or not address.is_global:
            raise ValueError()
        return str(address)
    except ValueError:
        raise Failure('INVALID_IP', 'Нужен публичный IPv4 сервера.') from None


def host_name(value):
    if not isinstance(value, str) or value != value.strip() or len(value) > 253:
        raise Failure('INVALID_HOST', 'Укажите домен без протокола, пути и порта.')
    import re
    value = value.lower()
    try:
        return public_ip(value)
    except Failure:
        pass
    labels = value.split('.')
    if len(labels) < 2 or any(not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', x) for x in labels):
        raise Failure('INVALID_HOST', 'Некорректный домен. Для IDN используйте punycode.')
    if labels[-1].isdigit() or value.endswith(('.local', '.localhost', '.invalid', '.test')):
        raise Failure('INVALID_HOST', 'Нужен публичный домен.')
    return value


def dns_matches(host, ip):
    host = host_name(host)
    if host == ip:
        return
    try:
        addresses = {x[4][0] for x in socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)}
    except OSError:
        raise Failure('DNS_MISMATCH', 'Домен ещё не разрешается. Настройте A-запись и повторите.') from None
    if not addresses or addresses != {ip}:
        raise Failure('DNS_MISMATCH', 'Все A/AAAA-записи домена должны указывать на этот сервер; проверьте также AAAA.')


def detect_ip():
    value = json.loads(run(['ip', '-4', '-j', 'address', 'show', 'scope', 'global']))
    found = set()
    for interface in value:
        for address in interface.get('addr_info', []):
            try:
                found.add(public_ip(address.get('local', '')))
            except Failure:
                pass
    if len(found) != 1:
        raise Failure('PUBLIC_IP_REQUIRED', 'Передайте --public-ip с публичным IPv4 этого сервера.')
    return found.pop()


def preflight(resume=False, *, host=None, ip=None, explicit_host=True):
    if os.geteuid() != 0:
        raise Failure('ROOT_REQUIRED', 'Запустите установщик через sudo.')
    values = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
    if values.get('ID', '').strip('"') != 'ubuntu' or values.get('VERSION_ID', '').strip('"') != '24.04':
        raise Failure('UNSUPPORTED_OS', 'Эта версия установщика поддерживает чистую Ubuntu Server 24.04 LTS.')
    if shutil.disk_usage('/').free < 4 * 1024**3:
        raise Failure('DISK_SPACE', 'Нужно не менее 4 ГиБ свободного места.')
    mem = int(next(x.split()[1] for x in Path('/proc/meminfo').read_text().splitlines() if x.startswith('MemTotal:')))
    if mem < 900_000:
        raise Failure('MEMORY', 'Нужно не менее 1 ГиБ RAM; рекомендуется 2 ГиБ.')
    marker = read_json(STATE / 'bootstrap.json')
    if resume:
        if not marker or marker.get('installer') != 'gitposted-clean-v1' or (STATE / 'installed.json').exists():
            raise Failure('RESUME_UNAVAILABLE', 'Нет незавершённой установки для продолжения.')
        shared = marker.get('shared_host', False)
        if type(shared) is not bool:
            raise Failure('STATE_CONFLICT', 'Некорректный режим совместной установки.')
        if shared:
            if peer_installation(ip) is None:
                raise Failure('PEER_MISSING', 'Соседняя установка больше не найдена. Общие службы автоматически не переустанавливаются.')
        if host:
            ensure_hosts_available({'host': host, 'public_ip': ip, 'path_prefix': path_prefix(marker)})
        return shared
    for path in (INSTALL, APP, STATE, DATA, CONFIG.parent, LOG, SITE):
        if path.exists() or path.is_symlink():
            raise Failure('EXISTING_INSTALLATION', 'Обнаружены существующие каталоги GitPosted. Нужен чистый сервер.')
    for name in (APP_USER, SETUP_USER):
        try:
            pwd.getpwnam(name)
        except KeyError:
            continue
        raise Failure('EXISTING_USER', 'Системный пользователь GitPosted уже существует.')
    peer = peer_installation(ip)
    if peer and not peer.get('path_prefix') and (not explicit_host or not host or host == ip):
        raise Failure('SECOND_HOST_REQUIRED', 'Другой проект уже установлен. Укажите отдельный домен или поддомен: --host repost.example.org.')
    if peer:
        ensure_hosts_available({'host': host, 'public_ip': ip, 'path_prefix': '/GitPosted' if peer.get('path_prefix') else ''})
        verify_passive_apache_stub()
    for service in (('mariadb',) if peer else ('nginx', 'apache2', 'mysql', 'mariadb')):
        if shutil.which(service) or Path('/etc/' + service).exists():
            raise Failure('EXISTING_SERVICE', 'На сервере уже есть web-сервер или MySQL. Автоматическая установка остановлена.')
    for port in (() if peer else (80, 443, 3306)):
        for af, addr in ((socket.AF_INET, '0.0.0.0'), (socket.AF_INET6, '::')):
            try:
                with socket.socket(af, socket.SOCK_STREAM) as sock:
                    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                    if af == socket.AF_INET6:
                        sock.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
                    sock.bind((addr, port))
            except OSError as error:
                if error.errno not in (97, 99):
                    raise Failure('PORT_BUSY', 'Порты 80, 443 или 3306 заняты. Нужен чистый сервер.') from None
    return peer is not None


def validate_source(source):
    """Audit the complete source before any persistent bootstrap write.

    A root-owned leaf inside somebody else's writable home is not trusted:
    that ancestor could be renamed while root reads the package. Root-owned
    sticky temporary directories are safe for the loader's private root tree.
    """
    source = Path(source)
    try:
        if not source.is_absolute() or source.resolve(strict=True) != source or not source.is_dir():
            raise ValueError()
        for parent in source.parents:
            info = parent.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 \
                    or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                raise ValueError()
        paths = [source, *sorted(source.rglob('*'))]
        for path in paths:
            info = path.lstat()
            if info.st_uid != 0 or info.st_mode & 0o022 \
                    or not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
                raise ValueError()
        return paths[1:]
    except (OSError, RuntimeError, ValueError):
        raise Failure('UNSAFE_PACKAGE', 'Пакет и его каталоги должны принадлежать root и быть закрыты от чужой записи; ссылки не разрешены.') from None


def copy_tree(source, target):
    source, target = Path(source), Path(target)
    paths = validate_source(source)
    for path in paths:
        relative = path.relative_to(source)
        destination = target / relative
        if path.is_dir():
            directory(destination, 0o755)
        elif path.is_file():
            directory(destination.parent, 0o755)
            atomic(destination, path.read_bytes(), 0o644)
        else:
            raise Failure('UNSAFE_PACKAGE', 'Пакет содержит необычный файл.')


def package_files(source):
    """Return only the builder's public allowlist, after read-only validation.

    A developer checkout can contain .git credentials; copy_tree must never
    expose that metadata (or an accidentally added private file) under /opt.
    The source has already been proved immutable to non-root before its
    trusted builder module is loaded. No PHP/dependency is needed here.
    """
    source = Path(source)
    validate_source(source)
    try:
        spec = importlib.util.spec_from_file_location('gitposted_trusted_package_builder', source / 'scripts/build.py')
        if spec is None or spec.loader is None:
            raise ValueError()
        builder = importlib.util.module_from_spec(spec)
        old_bytecode = sys.dont_write_bytecode
        try:
            sys.dont_write_bytecode = True
            spec.loader.exec_module(builder)
        finally:
            sys.dont_write_bytecode = old_bytecode
        has_manifest = (source / 'MANIFEST.sha256').exists()
        if has_manifest:
            builder.verify_manifest(source)
        files = builder.collect(source, verify=has_manifest)
        if has_manifest:
            files['MANIFEST.sha256'] = (source / 'MANIFEST.sha256').read_bytes()
        return files
    except (OSError, ValueError, UnicodeError, SyntaxError):
        raise Failure('INVALID_PACKAGE', 'Пакет неполон или содержит файлы вне публичного списка. Используйте полный проверенный release.') from None


def persist_bundle(source, files):
    destinations = []
    for name, content in sorted(files.items()):
        if (not isinstance(name, str) or not name or '\\' in name or '\x00' in name
                or any(part in ('', '.', '..') for part in name.split('/'))):
            raise Failure('UNSAFE_PACKAGE', 'Пакет содержит неподходящий относительный путь.')
        relative = Path(*name.split('/'))
        destination = INSTALL / relative
        if relative.is_absolute() or relative.drive or not destination.is_relative_to(INSTALL):
            raise Failure('UNSAFE_PACKAGE', 'Файл пакета находится вне каталога установщика.')
        destinations.append((relative, destination, content))
    directory(INSTALL, 0o755)
    same_source = Path(source).resolve() == INSTALL
    for relative, destination, content in destinations:
        # mkdir(parents=True) applies umask to implicit intermediate folders.
        # Every public bundle ancestor must be traversable by the app UID;
        # create/check each one explicitly, never chmod above INSTALL.
        parent = INSTALL
        for part in relative.parts[:-1]:
            parent = parent / part
            directory(parent, 0o755)
        if destination.is_symlink():
            raise Failure('UNSAFE_PATH', 'Файл установщика не должен быть символической ссылкой.')
        if not same_source:
            atomic(destination, content, 0o644)


def sync_directory(path):
    """Persist directory entries, not only the bytes of their regular files."""
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def publish_initial_state(source, target):
    """Linux no-replace directory commit; an existing path is never adopted."""
    libc = ctypes.CDLL(None, use_errno=True)
    rename = libc.renameat2
    rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    rename.restype = ctypes.c_int
    if rename(-100, os.fsencode(source), -100, os.fsencode(target), 1) != 0:  # AT_FDCWD, RENAME_NOREPLACE
        code = ctypes.get_errno()
        if code in (errno.EEXIST, errno.ENOTEMPTY):
            raise Failure('EXISTING_INSTALLATION', 'Каталог состояния уже появился. Не перезаписывайте его; проверьте существующую установку.')
        raise Failure('STATE_UNAVAILABLE', 'Не удалось сохранить начальное состояние установки.')


def initialize_state(ip, host, shared_host=False, prefix=''):
    """Publish marker and lock together, so every visible state can resume.

    A killed process before publication may leave an unpublished private
    sibling. It is never adopted or searched for by later installations.
    """
    if STATE.exists() or STATE.is_symlink() or not STATE.is_absolute() or STATE.parent.resolve() != STATE.parent:
        raise Failure('EXISTING_INSTALLATION', 'Каталог состояния уже существует или имеет неожиданный путь.')
    prefix = path_prefix({'path_prefix': prefix})
    temporary = STATE.parent / ('.' + STATE.name + '-initial.' + secrets.token_hex(8))
    temporary.mkdir(mode=0o700)
    try:
        save_json(temporary / 'bootstrap.json', {'installer': 'gitposted-clean-v1', 'public_ip': ip, 'host': host, 'shared_host': shared_host, 'path_prefix': path_prefix({'path_prefix': prefix}), **({'routing_schema': 1} if prefix else {})})
        descriptor = os.open(temporary / 'installer.lock', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
        sync_directory(temporary)
        publish_initial_state(temporary, STATE)
        sync_directory(STATE.parent)
    finally:
        if temporary.exists() and not temporary.is_symlink() and temporary.resolve() == temporary:
            # Only the exact two files this initializer creates; no recursion.
            members = list(temporary.iterdir())
            if any(path.name not in ('bootstrap.json', 'installer.lock') or path.is_symlink() or not path.is_file() for path in members):
                raise Failure('UNSAFE_PATH', 'Временное начальное состояние содержит неожиданный файл.')
            for path in members:
                path.unlink()
            temporary.rmdir()


def php_password(password):
    return run(['php', '-r', '$v=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR); echo password_hash($v["password"], defined("PASSWORD_ARGON2ID")?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);'],
               data=json.dumps({'password': password}).encode(), safe='Не удалось создать хеш пароля.').decode()


def enrollment():
    private = read_json(STATE / 'private.json', {})
    code = secrets.token_urlsafe(24)
    salt = secrets.token_hex(16)
    private['enrollment'] = {'salt': salt, 'hash': hashlib.pbkdf2_hmac('sha256', code.encode(), salt.encode(), 250000).hex(),
                             'expires_at': int(time.time()) + 86400, 'consumed': False}
    save_json(STATE / 'private.json', private)
    save_json(STATE / 'access.json', {'code_hash': php_password(code), 'expires_at': private['enrollment']['expires_at']}, True)
    return code


def cert_name(host):
    return 'gitposted-' + hashlib.sha256(host.encode()).hexdigest()[:12]


def obtain_certificate(host, ip, email=''):
    with shared_lock():
        public = read_json(STATE / 'public.json', {})
        ensure_hosts_available({**public, 'host': host, 'public_ip': ip})
        if path_prefix(public):
            # Register HTTP-01 before issuance. On a shared authority its first
            # owner remains responsible for issuance and renewal.
            candidate = {**public}
            if host not in {public.get('host'), public.get('site_host'), *public.get('acme_hosts', [])}:
                candidate['pending_host'] = host
            configure_nginx(candidate)
            authority = routing_call(gateway.get_authority, host)
            if not authority:
                raise Failure('TLS_STATE', 'Не найдена маршрутизация сертификата.')
            if authority['tls_ready']:
                return
            if authority['owner'] != 'gitposted':
                raise Failure('TLS_NOT_READY', 'Сначала завершите HTTPS соседнего проекта на этом адресе.')
            _obtain_certificate(host, ip, email)
            routing_call(gateway.mark_tls_ready, 'gitposted', host, run)
            return
        if public and host not in {public.get('host'), public.get('site_host'), *public.get('acme_hosts', [])}:
            configure_nginx({**public, 'pending_host': host})
        _obtain_certificate(host, ip, email)


def _obtain_certificate(host, ip, email=''):
    dns_matches(host, ip)
    args = [CERTBOT, 'certonly', '--webroot', '--webroot-path', ACME, '--cert-name', cert_name(host),
            '--non-interactive', '--agree-tos', '--keep-until-expiring', '--preferred-challenges', 'http',
            '--email', email] if email else [CERTBOT, 'certonly', '--webroot', '--webroot-path', ACME,
            '--cert-name', cert_name(host), '--non-interactive', '--agree-tos', '--keep-until-expiring',
            '--preferred-challenges', 'http', '--register-unsafely-without-email']
    if host == ip:
        args += ['--required-profile', 'shortlived', '--ip-address', ip]
    else:
        args += ['-d', host]
    run(args, timeout=300, safe='Не удалось получить TLS-сертификат. Проверьте DNS, доступность 80/443 и ограничения ACME; затем продолжите через --resume.')


def nginx_content(public):
    if path_prefix(public):
        return mounted_nginx_content(public)
    base_host = host_name(public['host'])
    site_host = host_name(public.get('site_host', base_host))
    installed = public.get('installed', False)
    tls_ready = public.get('tls_ready', False)
    shared_host = public.get('shared_host', False)
    if type(shared_host) is not bool:
        raise Failure('STATE_CONFLICT', 'Некорректный режим совместного размещения.')
    aliases = public.get('acme_hosts', [])
    if not isinstance(aliases, list) or len(aliases) > 32:
        raise Failure('INVALID_HOST', 'Слишком много дополнительных адресов сертификатов.')
    http_hosts = list(dict.fromkeys([base_host, site_host, *[host_name(host) for host in aliases]]))
    if public.get('pending_host'):
        http_hosts = list(dict.fromkeys([*http_hosts, host_name(public['pending_host'])]))
    acme = f'location ^~ /.well-known/acme-challenge/ {{ root {ACME}; default_type text/plain; }}'
    status_location = f'location = /install/api.php {{ include fastcgi_params; fastcgi_param HTTPS on; fastcgi_param SCRIPT_FILENAME {INSTALL}/web/api.php; fastcgi_read_timeout 50s; fastcgi_pass unix:/run/php/gitposted-installer.sock; }}'
    wizard = (status_location + '\nlocation = /install { return 404; }\nlocation /install/ { return 404; }' if installed else f'''
location = /install {{ return 302 /install/; }}
location = /install/ {{ include fastcgi_params; fastcgi_param HTTPS on; fastcgi_param SCRIPT_FILENAME {INSTALL}/web/index.php; fastcgi_pass unix:/run/php/gitposted-installer.sock; }}
{status_location}
location ^~ /install/assets/ {{ alias {INSTALL}/web/assets/; add_header X-Content-Type-Options nosniff always; }}
location /install/ {{ return 404; }}''')
    app = f'''
root {APP}/current/src;
index index.php;
location ~ /\\. {{ deny all; }}
location ^~ /tools/ {{ return 404; }}
location ~ \\.(?:py|sql|gz|ini|log|json|sh|md|pem|lock)$ {{ return 404; }}
location ~ "^/control/passkeys\\.php(?<gitposted_passkey_path>/(?:status|auth/(?:options|verify)|register/(?:options|verify)|[0-9]{{1,20}}))?/?$" {{
 include fastcgi_params;
 fastcgi_param HTTPS on;
 fastcgi_param SCRIPT_FILENAME {APP}/current/src/control/passkeys.php;
 fastcgi_param SCRIPT_NAME /control/passkeys.php;
 fastcgi_param PATH_INFO $gitposted_passkey_path;
 fastcgi_read_timeout 60s;
 fastcgi_pass unix:/run/php/gitposted.sock;
}}
location ~ ^/(?:index|webhook|max-webhook|control/index|privacy/index|user-agreement/index)\\.php$ {{
 include fastcgi_params;
 fastcgi_param HTTPS on;
 fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 fastcgi_read_timeout 1300s;
 fastcgi_pass unix:/run/php/gitposted.sock;
}}
location ~ \\.php(?:/|$) {{ return 404; }}
location /control/ {{ try_files $uri $uri/ /control/index.php?$query_string; }}
location / {{ try_files $uri $uri/ /index.php?$query_string; }}'''
    pending_app = f'''
root {APP}/current/src;
location ~ ^/(?:webhook|max-webhook)\\.php$ {{
 include fastcgi_params;
 fastcgi_param HTTPS on;
 fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 fastcgi_read_timeout 1300s;
 fastcgi_pass unix:/run/php/gitposted.sock;
}}
location / {{ return 302 /install/; }}'''
    http = f'''server {{
 listen 80{'' if shared_host else ' default_server'};
server_name {' '.join(http_hosts)};
{acme}
location / {{ {'return 302 https://' + base_host + '/install/;' if tls_ready and not installed else 'return 301 https://' + site_host + '$request_uri;' if tls_ready else 'default_type text/plain; return 503 "GitPosted: HTTPS is being configured. Return to the SSH console.\\n";'} }}
}}
'''
    if not tls_ready:
        return http
    servers = []
    for host in dict.fromkeys([base_host, site_host]):
        body = pending_app if not installed else app if host == site_host else f'location / {{ return 302 https://{site_host}$request_uri; }}'
        servers.append(f'''server {{
listen 443 ssl{' default_server' if host == base_host and not shared_host else ''};
server_name {host};
ssl_certificate /etc/letsencrypt/live/{cert_name(host)}/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/{cert_name(host)}/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;
client_max_body_size 1m;
add_header X-Content-Type-Options nosniff always;
{wizard}
{body}
}}
''')
    return http + '\n'.join(servers)


def mounted_nginx_content(public):
    prefix = path_prefix(public)
    legacy = nginx_content({**public, 'path_prefix': '', 'tls_ready': True})
    marker = 'add_header X-Content-Type-Options nosniff always;\n'
    start = legacy.index(marker) + len(marker)
    end = legacy.rfind('\n}')
    body = legacy[start:end]
    # The first TLS block corresponds to the base host. Before completion it is
    # the same app; after completion use the canonical host so it renders app.
    canonical = host_name(public.get('site_host', public['host']))
    if public.get('installed') and public['host'] != canonical:
        legacy = nginx_content({**public, 'host': canonical, 'path_prefix': '', 'tls_ready': True})
        body = legacy[legacy.index(marker) + len(marker):legacy.rfind('\n}')]
    # There is normally one TLS block; aliases may produce a second. Keep only
    # location/body text belonging to the first canonical block.
    if '\n}\n\nserver {' in body:
        body = body.split('\n}\n\nserver {', 1)[0]
    body = body.replace('include fastcgi_params;',
                        'include fastcgi_params; fastcgi_param REQUEST_URI $http_x_original_uri;')
    body = body.replace('fastcgi_param SCRIPT_NAME /control/passkeys.php;',
                        'fastcgi_param SCRIPT_NAME ' + prefix + '/control/passkeys.php;')
    body = body.replace('return 302 /install/;', 'return 302 ' + prefix + '/install/;')
    redirects = ''.join('location = /' + name + ' { return 308 ' + prefix + '/' + name + '/$is_args$args; }\n'
                        for name in ('control', 'privacy', 'user-agreement', 'assets'))
    return ('server {\nlisten 127.0.0.1:8181;\nserver_name _;\nabsolute_redirect off; port_in_redirect off;\n'
            'set_real_ip_from 127.0.0.1; real_ip_header X-Real-IP; real_ip_recursive off;\n'
            'client_max_body_size 1m;\n' + redirects + body + '\n}\n')


def configure_nginx(public):
    with shared_lock():
        ensure_hosts_available(public)
        if path_prefix(public):
            routing_call(gateway.configure, 'gitposted', public, nginx_content(public), run)
        else:
            _configure_nginx(public)


def _configure_nginx(public):
    if SITE.is_symlink() or (SITE.exists() and not SITE.is_file()):
        raise Failure('UNSAFE_PATH', 'Неожиданная конфигурация nginx.')
    old = SITE.read_bytes() if SITE.exists() else None
    enabled = ENABLED_SITE
    existed = enabled.exists() or enabled.is_symlink()
    if existed and (not enabled.is_symlink() or os.readlink(enabled) != str(SITE) or enabled.lstat().st_uid != 0):
        raise Failure('UNSAFE_PATH', 'Неожиданная ссылка сайта nginx.')
    reload_attempted = False
    try:
        atomic(SITE, nginx_content(public), 0o644)
        if not existed:
            enabled.symlink_to(SITE)
        run(['nginx', '-t'], safe='Новая конфигурация nginx не прошла проверку.')
        reload_attempted = True
        run(['systemctl', 'reload', 'nginx'])
    except (Failure, OSError) as error:
        try:
            if old is not None:
                atomic(SITE, old, 0o644)
            else:
                SITE.unlink(missing_ok=True)
            if not existed and enabled.is_symlink() and os.readlink(enabled) == str(SITE):
                enabled.unlink()
            if reload_attempted:
                run(['nginx', '-t'], safe='Прежняя конфигурация nginx не прошла проверку.')
                run(['systemctl', 'reload', 'nginx'], safe='Не удалось вернуть прежнюю конфигурацию nginx.')
        except (Failure, OSError):
            raise Failure('NGINX_ROLLBACK_FAILED', 'Не удалось подтвердить возврат прежней конфигурации nginx. Проверьте nginx -t и состояние службы.') from None
        if isinstance(error, Failure):
            raise
        raise Failure('NGINX_CONFIGURATION_FAILED', 'Не удалось сохранить конфигурацию nginx; прежние настройки возвращены.') from None



def units():
    atomic('/etc/systemd/system/gitposted-installer.service', f'''[Unit]
Description=GitPosted temporary installation broker
After=network.target mysql.service php8.3-fpm.service
[Service]
Type=simple
ExecStart=/usr/bin/python3 -B {INSTALL}/lib/broker.py
Restart=on-failure
RestartSec=3
UMask=0077
PrivateTmp=true
ProtectHome=true
ProtectSystem=full
ReadWritePaths=-/var/lib/gitposted-suite /var/www/gitposted-acme /etc/gitposted /etc/nginx /etc/cron.d /var/lib/gitposted /var/lib/gitposted-installer /var/log/gitposted /etc/letsencrypt /var/lib/letsencrypt /var/log/letsencrypt /run
[Install]
WantedBy=multi-user.target
''', 0o644)
    # Certbot is an isolated dependency of this installer, not the distribution's old version.
    atomic('/etc/systemd/system/gitposted-cert-renew.service', f'''[Unit]
Description=Renew GitPosted TLS certificates
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 -B {INSTALL}/lib/provision.py --renew-certificates
''', 0o644)
    atomic('/etc/systemd/system/gitposted-cert-renew.timer', '''[Unit]
Description=Check GitPosted certificate renewal twice daily
[Timer]
OnBootSec=10min
OnUnitActiveSec=12h
RandomizedDelaySec=20min
Persistent=true
[Install]
WantedBy=timers.target
''', 0o644)
    atomic('/etc/cron.d/gitposted', f'''SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * gitposted /usr/bin/test -r {CONFIG} && POSTED_CONFIG_FILE={CONFIG} /usr/bin/php {APP}/current/src/cron.php >> {LOG}/cron.log 2>&1
''', 0o644)
    atomic('/etc/logrotate.d/gitposted', f'''{LOG}/*.log {{
 daily
 rotate 14
 compress
 missingok
 notifempty
 copytruncate
 su gitposted gitposted
}}
''', 0o644)
    atomic('/etc/systemd/system/gitposted-rate-limit-cleanup.service', f'''[Unit]
Description=Remove expired GitPosted request rate limits
[Service]
Type=oneshot
User=gitposted
Group=gitposted
ExecStart=/usr/bin/python3 -B {INSTALL}/bin/cleanup.py
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths={DATA}
''', 0o644)
    atomic('/etc/systemd/system/gitposted-rate-limit-cleanup.timer', '''[Unit]
Description=GitPosted request rate limit cleanup
[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
[Install]
WantedBy=timers.target
''', 0o644)


def pools(shared_host=False):
    for name, user, script_root, session_dir, timeout in (
        ('gitposted', APP_USER, f'{APP}:{CONFIG.parent}:{DATA}:{LOG}:/usr/share/zoneinfo:/tmp', DATA / 'sessions', 1300),
        ('gitposted-installer', SETUP_USER, f'{INSTALL}:{STATE}:/tmp', STATE / 'sessions', 60)):
        atomic(f'/etc/php/8.3/fpm/pool.d/{name}.conf', f'''[{name}]
user = {user}
group = {user}
listen = /run/php/{name}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0600
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 15s
request_terminate_timeout = {timeout}s
clear_env = yes
env[POSTED_CONFIG_FILE] = {CONFIG}
env[POSTED_INSTALLER_STATE_DIR] = {STATE}
php_admin_value[open_basedir] = {script_root}
php_admin_value[session.save_path] = {session_dir}
php_admin_value[session.gc_probability] = 1
php_admin_value[session.gc_divisor] = 100
php_admin_value[session.gc_maxlifetime] = 3600
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {LOG / 'php.log' if user == APP_USER else STATE / 'sessions/php.log'}
php_admin_value[upload_max_filesize] = 1M
php_admin_value[post_max_size] = 1M
php_admin_value[max_execution_time] = {timeout}
php_admin_flag[opcache.validate_timestamps] = on
php_admin_value[opcache.revalidate_freq] = 0
php_admin_flag[expose_php] = off
''', 0o644)
    run(['php-fpm8.3', '-t'], safe='Конфигурация PHP-FPM не прошла проверку.')
    run(['systemctl', 'reload' if shared_host else 'restart', 'php8.3-fpm'])


def renewal_config_exists(host):
    path = RENEWAL_DIR / (cert_name(host) + '.conf')
    if not path.exists() and not path.is_symlink():
        return False
    try:
        coinstall._parents(path.parent)
    except coinstall.CoInstallError as error:
        raise Failure(error.code, str(error)) from None
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
        raise Failure('UNSAFE_PATH', 'Небезопасный файл настроек продления сертификата.')
    return True


def renew_certificates():
    """Renew only this installation's certificates under the common lease."""
    if os.geteuid() != 0:
        raise Failure('ROOT_REQUIRED', 'Продление сертификатов требует root.')
    with shared_lock():
        public = read_json(STATE / 'public.json', {})
        if not isinstance(public, dict) or public.get('tls_ready') is not True:
            raise Failure('TLS_NOT_READY', 'HTTPS этой установки ещё не настроен.')
        if path_prefix(public):
            # Renewal changes no route claims. A stopped neighbour's partial
            # checkpoint must never starve this owner's short-lived certificate.
            registry = routing_call(gateway.read_registry)
            own = registry['projects'].get('gitposted')
            expected = {'host': host_name(public['host']), 'public_ip': public_ip(public['public_ip']),
                        'path_prefix': path_prefix(public)}
            if not isinstance(own, dict) or any(own.get(key) != value for key, value in expected.items()):
                raise Failure('TLS_STATE', 'Не подтверждена принадлежность HTTPS-маршрута этому установщику.')
            hosts = [host for host, authority in registry['authorities'].items()
                     if authority['owner'] == 'gitposted' and authority['tls_ready']]
        else:
            ensure_hosts_available(public)
            hosts = list(dict.fromkeys([host_name(public['host']),
                                       host_name(public.get('site_host', public['host'])),
                                       *[host_name(host) for host in public.get('acme_hosts', [])]]))
        failed = []
        for host in hosts:
            try:
                if not renewal_config_exists(host):
                    if path_prefix(public) or host in {public['host'], public.get('site_host', public['host'])}:
                        raise Failure('TLS_STATE', 'Отсутствует настройка продления действующего HTTPS-адреса.')
                    continue  # A reserved candidate may never have passed issuance.
                run([CERTBOT, 'renew', '--quiet', '--no-random-sleep-on-renew', '--cert-name', cert_name(host),
                     '--deploy-hook', 'systemctl reload nginx'], timeout=600,
                    safe='Не удалось продлить сертификат этой установки.')
            except Failure as error:
                # One retired/broken hostname must not starve the current site.
                # Never execute Certbot for a config whose metadata was rejected.
                if error.code == 'BOOTSTRAP_INTERRUPTED':
                    raise  # Explicit CLI cancellation must stop subsequent work.
                failed.append(error.code)
        if failed:
            code = 'UNSAFE_PATH' if any(value in {'UNSAFE_PATH', 'UNTRUSTED_PEER', 'STATE_CONFLICT'} for value in failed) \
                else 'TLS_STATE' if all(value == 'TLS_STATE' for value in failed) else 'TLS_RENEW_FAILED'
            raise Failure(code, 'Не все сертификаты удалось проверить или продлить. Остальные допустимые сертификаты обработаны; проверьте настройки HTTPS, DNS и доступность HTTP.')



@contextmanager
def installer_lock():
    path = STATE / 'installer.lock'
    if STATE.resolve() != STATE or STATE.is_symlink() or path.is_symlink():
        raise Failure('UNSAFE_PATH', 'Небезопасный путь блокировки установщика.')
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if info.st_uid != 0 or info.st_mode & 0o077:
            raise Failure('UNSAFE_PATH', 'Блокировка установщика должна принадлежать root с режимом 0600.')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Failure('INSTALL_BUSY', 'Установка уже выполняется. Дождитесь завершения задания.') from None
        yield
    finally:
        os.close(fd)


def show_link(host, code, prefix=''):
    prefix = path_prefix({'path_prefix': prefix})
    print('\nOpen this personal one-time link:\nhttps://' + host + prefix + '/install/#setup=' + code
          + '\nThe browser signs in automatically. The fragment is not sent in the HTTP URL. Keep this link private.', flush=True)


def reconcile_installed():
    """Repair the terminal state after a crash following the durable marker.

    The caller holds installer.lock (or is the broker's current job owner).
    This never starts services, changes application config or reopens enrollment.
    """
    marker_path = STATE / 'installed.json'
    if not marker_path.exists() and not marker_path.is_symlink():
        return False
    if STATE.resolve() != STATE or STATE.is_symlink():
        raise Failure('UNSAFE_PATH', 'Небезопасный каталог состояния установщика.')
    marker = read_json(marker_path)
    if not isinstance(marker, dict) or type(marker.get('installed_at')) is not int or marker['installed_at'] <= 0 \
            or not isinstance(marker.get('version'), str) or not 1 <= len(marker['version']) <= 100 \
            or not isinstance(marker.get('admin_url'), str):
        raise Failure('STATE_CONFLICT', 'Некорректная отметка завершённой установки; автоматическое продолжение запрещено.')
    try:
        url = urlsplit(marker['admin_url'])
        host = host_name(url.netloc)
    except (Failure, ValueError):
        raise Failure('STATE_CONFLICT', 'Некорректный адрес завершённой установки.') from None
    prefix = path_prefix(read_json(STATE / 'bootstrap.json', {}))
    if url.scheme != 'https' or url.netloc != host or url.path != prefix + '/control/' or url.query or url.fragment:
        raise Failure('STATE_CONFLICT', 'Некорректный адрес завершённой установки.')
    public = read_json(STATE / 'public.json', {})
    private = read_json(STATE / 'private.json', {})
    if not isinstance(public, dict) or not isinstance(private, dict):
        raise Failure('STATE_CONFLICT', 'Состояние завершённой установки недоступно.')
    job = public.get('job', {})
    if not isinstance(job, dict):
        job = {}
    job = {**job, 'type': 'install', 'status': 'complete', 'error': None}
    steps = job.get('steps', [])
    job['steps'] = [{**step, 'status': 'skipped' if step.get('status') == 'skipped' else 'done'}
                    for step in steps if isinstance(step, dict)] if isinstance(steps, list) else []
    public.update({'installed': True, 'admin_url': marker['admin_url'], 'version': marker['version'],
                   'site_url': 'https://' + host + prefix, 'site_host': host, 'job': job, 'path_prefix': prefix})
    for key in ('settings', 'config', 'enrollment', 'job', 'public_state', 'bot_bindings'):
        private.pop(key, None)
    # Remove duplicate credentials before publishing terminal public metadata.
    # Every step is idempotent if another crash interrupts this reconciliation.
    save_json(STATE / 'private.json', private)
    access = STATE / 'access.json'
    if access.is_symlink():
        raise Failure('UNSAFE_PATH', 'Небезопасный файл доступа установщика.')
    access.unlink(missing_ok=True)
    save_json(STATE / 'public.json', public, True)
    return True


def reconcile_pending_finish(public):
    """Reopen the wizard if nginx closed before the final marker was committed.

    The final nginx reload and installed.json are separate external effects.
    Until the marker exists, a killed finish step must remain retryable through
    the browser. Never reopen a committed installation or touch app config.
    """
    marker = STATE / 'installed.json'
    if not isinstance(public, dict) or public.get('installed') is True or public.get('tls_ready') is not True \
            or marker.exists() or marker.is_symlink():
        return False
    job = public.get('job', {})
    if not isinstance(job, dict) or job.get('type') != 'install':
        return False
    steps = job.get('steps', [])
    finish = [step for step in steps if isinstance(step, dict) and step.get('id') == 'finish'] if isinstance(steps, list) else []
    if len(finish) != 1 or finish[0].get('status') not in ('running', 'failed', 'done'):
        return False
    configure_nginx({**public, 'installed': False})
    return True


def resume_ready():
    if reconcile_installed():
        return True
    public = read_json(STATE / 'public.json', {})
    if public.get('installed') or (STATE / 'installed.json').exists():
        raise Failure('RESUME_UNAVAILABLE', 'Установка завершена, мастер закрыт.')
    if public.get('tls_ready') is not True:
        private = read_json(STATE / 'private.json', {})
        if any(private.get(key) for key in ('settings', 'config', 'job')):
            raise Failure('STATE_CONFLICT', 'Установка приложения уже начата. Не переустанавливайте инфраструктуру; проверьте состояние задания.')
        return False
    reconcile_pending_finish(public)
    # Reissue enrollment only. Never copy code, reset public metadata, restart
    # an active job or replace application/configuration during --resume.
    code = enrollment()
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'gitposted-installer.service', 'gitposted-cert-renew.timer', 'gitposted-rate-limit-cleanup.timer'])
    show_link(host_name(public['host']), code, path_prefix(public))
    return True


def prepare_worker(release):
    worker = DATA / 'runtime/post-links-venv'
    if worker.is_symlink() or worker.resolve() != worker:
        raise Failure('UNSAFE_PATH', 'Каталог Python загрузчика должен быть обычным каталогом данных.')
    if worker.exists() and worker.stat().st_uid != identity(APP_USER)[0]:
        raise Failure('UNSAFE_PATH', 'Python загрузчика должен принадлежать пользователю приложения.')
    # venv creates its directory before its interpreter and bundled pip. A
    # killed first attempt must be repairable; existence alone is no checkpoint.
    # Re-running stdlib venv preserves installed packages and stays under the
    # application UID. Ready web installs never enter this provisioning path.
    run(['runuser', '-u', APP_USER, '--', 'python3', '-m', 'venv', '--copies', worker])
    # This tree is writable by the application. Never execute it as root and
    # never recursively follow it with privileged ownership changes on resume.
    run(['runuser', '-u', APP_USER, '--', worker / 'bin/python3', '-I', '-m', 'pip', 'install', '--disable-pip-version-check',
         '-r', release / 'src/tools/post_link_requirements.txt'], timeout=600,
        safe='Не удалось установить загрузчик Telegram.')


def prepare_resources():
    # DATA is app-writable. All descendant creation and resource replacement
    # must happen as that UID, including on resume after an interrupted setup.
    run(['runuser', '-u', APP_USER, '--', '/usr/bin/python3', '-I', '-B', INSTALL / 'bin/install_resources.py'],
        safe='Не удалось подготовить каталоги и ресурсы приложения.')


def release_directory():
    # Bootstrap uses umask 077. Every traversed code ancestor needs an explicit
    # public execute bit before the separate application UID can read scripts.
    directory(APP, 0o755)
    directory(APP / 'releases', 0o755)
    release = APP / 'releases' / VERSION
    directory(release, 0o755)
    return release


def wait_package_locks(timeout=120):
    """Wait for the existing POSIX package locks without deleting/replacing them.

    The locks are released before dpkg starts. A new owner can win that small
    race; dpkg must still acquire its own locks and will fail safely in that case.
    """
    deadline = time.monotonic() + timeout
    while True:
        descriptors, busy = [], False
        try:
            for path in PACKAGE_LOCKS:
                try:
                    fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK)
                except FileNotFoundError:
                    continue
                except OSError:
                    raise Failure('PACKAGE_MANAGER_UNAVAILABLE', 'Не удалось проверить блокировку пакетов. Проверьте состояние APT/dpkg и повторите --resume.') from None
                descriptors.append(fd)
                info = os.fstat(fd)
                if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1:
                    raise Failure('UNSAFE_PATH', 'Обнаружен неподходящий файл блокировки пакетов; автоматическое продолжение запрещено.')
                try:
                    fcntl.lockf(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except OSError as error:
                    if error.errno not in (errno.EACCES, errno.EAGAIN):
                        raise Failure('PACKAGE_MANAGER_UNAVAILABLE', 'Не удалось проверить блокировку пакетов. Проверьте APT/dpkg и повторите --resume.') from None
                    busy = True
                    break
            if not busy:
                return
        finally:
            for fd in reversed(descriptors):
                os.close(fd)
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise Failure('PACKAGE_MANAGER_BUSY', 'APT/dpkg занят другой операцией. Дождитесь её завершения и повторите --resume; не удаляйте файлы блокировки.')
        time.sleep(min(1, remaining))


def prepare_system_packages(resume=False, shared_host=False):
    packages = list(SYSTEM_PACKAGES)
    if shared_host:
        # A completed sibling owns the working stack. Do not upgrade it, run
        # broad dpkg recovery, or ask apt to restart already-installed services.
        versions = shared_package_inventory()
        installed = {name.split(':', 1)[0] for name in versions}
        if not {'nginx', 'mysql-server', 'php8.3-fpm'} <= installed:
            raise Failure('SHARED_STACK_CHANGED', 'Общая установка nginx/MySQL/PHP повреждена. Восстановите первый проект до продолжения второго.')
        missing = [name for name in packages if name not in installed]
        if not missing:
            return
        add_shared_packages(missing, versions)
        return
    if resume:
        wait_package_locks()
        # A killed apt/dpkg can leave a dirty journal. apt refuses a new install
        # until dpkg merges it and finishes pending package configuration.
        try:
            run(['dpkg', '--force-confdef', '--force-confold', '--configure', '--pending'], timeout=1200,
                safe='Не удалось завершить настройку пакетов. Проверьте APT/dpkg и повторите --resume; файлы блокировки удалять нельзя.')
        except Failure as error:
            # An incomplete unpack can also leave missing dependencies. The
            # explicit install below may repair these, but may never remove
            # packages. A remaining journal/lock/configuration error still
            # causes that bounded apt command to stop safely.
            if getattr(error, 'command_returncode', None) != 1:
                raise Failure('PACKAGE_CONFIGURATION_FAILED', 'Не удалось завершить настройку пакетов. Проверьте APT/dpkg и повторите --resume; не удаляйте файлы блокировки.') from None
            repair = True
        else:
            repair = False
    else:
        repair = False
    apt = ['apt-get', '-o', 'DPkg::Lock::Timeout=120',
           '-o', 'Dpkg::Options::=--force-confdef', '-o', 'Dpkg::Options::=--force-confold']
    run(apt + ['update', '-qq'], timeout=600, safe='Не удалось обновить список пакетов Ubuntu. Проверьте сеть и APT; затем повторите --resume.')
    options = ['--fix-broken', '--no-remove'] if repair else ['--no-remove']
    run(apt + ['install', '-y', '-qq', *options, *packages],
        timeout=1200, safe='Настройка пакетов не завершена. Дождитесь других операций APT/dpkg; при необходимости выполните dpkg --configure --pending и повторите --resume. Не удаляйте файлы блокировки.')


def shared_package_inventory():
    """Read exact installed versions; a partial dpkg transaction is not adopted."""
    records = run(['dpkg-query', '--show', '--showformat=${binary:Package}\t${Version}\t${db:Status-Abbrev}\n'])
    if len(records) > 8 * 1024 * 1024:
        raise Failure('SHARED_STACK_CHANGED', 'Не удалось проверить установленные пакеты первого проекта.')
    installed = {}
    for line in records.decode('utf-8').splitlines():
        fields = line.split('\t')
        if len(fields) != 3:
            raise Failure('SHARED_STACK_CHANGED', 'Некорректное состояние менеджера пакетов; первый проект не изменён.')
        name, version, status = fields
        if not re.fullmatch(r'[a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?', name) or len(status) != 3:
            raise Failure('SHARED_STACK_CHANGED', 'Некорректное состояние менеджера пакетов; первый проект не изменён.')
        if status[1] in ('c', 'n'):
            continue
        if not re.fullmatch(r'[0-9A-Za-z.+:~_-]+', version):
            raise Failure('SHARED_STACK_CHANGED', 'Некорректная версия установленного пакета.')
        if status[1] == 'i' and status[2] == ' ' and status[0] in ('i', 'h'):
            if name in installed:
                raise Failure('SHARED_STACK_CHANGED', 'Неоднозначный список установленных пакетов.')
            installed[name] = version
        elif status[1] not in ('c', 'n'):
            raise Failure('SHARED_PACKAGE_CHANGE_REQUIRED', 'На сервере есть незавершённая настройка пакетов. Завершите её отдельно, затем повторите установку второго проекта.')
    return installed


def frozen_package_preferences(installed):
    records = []
    for name, version in sorted(installed.items()):
        if not re.fullmatch(r'[a-z0-9][a-z0-9+.-]*(?::[a-z0-9][a-z0-9-]*)?', name) \
                or not re.fullmatch(r'[0-9A-Za-z.+:~_-]+', version):
            raise Failure('SHARED_STACK_CHANGED', 'Невозможно закрепить версии общих пакетов.')
        records.append(f'Package: {name}\nPin: version {version}\nPin-Priority: 1001\n\n'
                       f'Package: {name}\nPin: version ?*\nPin-Priority: -1\n\n')
    return ''.join(records).encode('ascii')


@contextmanager
def shared_package_pins(installed):
    """Freeze existing packages for these commands without changing APT config."""
    if STATE.resolve() != STATE:
        raise Failure('UNSAFE_PATH', 'Небезопасный каталог состояния установщика.')
    info = STATE.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise Failure('UNSAFE_PATH', 'Каталог состояния должен принадлежать root без права посторонней записи.')
    descriptor, name = tempfile.mkstemp(prefix='.shared-apt-', dir=STATE)
    path = Path(name)
    fragments = path.with_name(path.name + '.parts')
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), 0o600)
            os.fchown(stream.fileno(), 0, 0)
            stream.write(frozen_package_preferences(installed))
            stream.flush(); os.fsync(stream.fileno())
        fragments.mkdir(mode=0o700)
        yield ['-o', 'Dir::Etc::preferences=' + str(path),
               '-o', 'Dir::Etc::preferencesparts=' + str(fragments),
               '-o', 'APT::Get::allow-downgrades=false',
               '-o', 'APT::Get::allow-remove-essential=false',
               '-o', 'APT::Get::allow-change-held-packages=false']
    finally:
        # These exact names were created above; no shared preferences are touched.
        if fragments.exists() and not fragments.is_symlink():
            fragments.rmdir()
        path.unlink(missing_ok=True)


def validate_shared_package_plan(plan, installed):
    existing = set(installed) | {name.split(':', 1)[0] for name in installed}
    for line in plan.decode('utf-8').splitlines():
        if line.startswith(('Remv ', 'Purg ')):
            raise Failure('SHARED_PACKAGE_CHANGE_REQUIRED', 'Зависимости второго проекта требуют изменения пакетов первого. Автоматическая установка остановлена; выполните обслуживание отдельно.')
        if line.startswith(('Inst ', 'Conf ')):
            fields = line.split()
            if len(fields) < 3 or fields[1] in existing or fields[1].split(':', 1)[0] in existing or '[' in fields[2] \
                    or fields[1].split(':', 1)[0].startswith(('php', 'nginx', 'mysql', 'mariadb', 'apache2')):
                raise Failure('SHARED_PACKAGE_CHANGE_REQUIRED', 'Зависимости второго проекта требуют изменения пакетов первого. Автоматическая установка остановлена; выполните обслуживание отдельно.')


def add_shared_packages(missing, installed):
    # Installing a previously absent PHP module can itself restart FPM from
    # postinst. A completed older installation needs a separate maintenance
    # step for these; new installers prepare the same complete package set.
    protected = {'nginx', 'mysql-server', 'php8.3-fpm', 'php8.3-cli', 'php8.3-mysql',
                 'php8.3-curl', 'php8.3-mbstring'}
    if protected.intersection(missing):
        raise Failure('SHARED_PACKAGE_CHANGE_REQUIRED', 'Первой установке не хватает общих web/PHP/MySQL-пакетов. Их настройка может перезапустить службы; выполните обслуживание отдельно либо используйте оба установщика rc.6 или новее.')
    wait_package_locks()
    apt = ['apt-get', '-o', 'DPkg::Lock::Timeout=120',
           '-o', 'Dpkg::Options::=--force-confdef', '-o', 'Dpkg::Options::=--force-confold']
    run(apt + ['update', '-qq'], timeout=600, safe='Не удалось обновить список пакетов Ubuntu. Повторите --resume.')
    with shared_package_pins(installed) as pins:
        command = apt + pins + ['install', '--no-upgrade', '--no-remove', *missing]
        plan = run(command + ['--simulate'], timeout=120,
                   safe='Зависимости требуют отдельного обслуживания сервера. Пакеты первого проекта не изменены.')
        validate_shared_package_plan(plan, installed)
        if shared_package_inventory() != installed:
            raise Failure('SHARED_STACK_CHANGED', 'Список пакетов изменился во время проверки. Повторите установку после завершения других операций APT.')
        run(command + ['-y', '-qq'], timeout=1200,
            safe='Не удалось добавить зависимости второго проекта. Повторите --resume для проверки; незавершённую настройку APT/dpkg потребуется завершить отдельным обслуживанием.')



def install(source, ip, host, resume=False, checked=False, package=None, shared_host=None):
    if not checked:
        shared_host = preflight(resume, host=host, ip=ip)
    if shared_host is None:
        shared_host = read_json(STATE / 'bootstrap.json', {}).get('shared_host', False)
    if type(shared_host) is not bool:
        raise Failure('STATE_CONFLICT', 'Некорректный режим совместной установки.')
    prefix = installation_prefix(ip, resume) if not (STATE / 'bootstrap.json').exists() else path_prefix(read_json(STATE / 'bootstrap.json', {}))
    dns_matches(host, ip)
    files = package_files(source) if package is None else package
    print('Preflight passed. Installing GitPosted dependencies...', flush=True)
    directory(STATE, 0o700)
    save_json(STATE / 'bootstrap.json', {'installer': 'gitposted-clean-v1', 'public_ip': ip, 'host': host, 'shared_host': shared_host, 'path_prefix': prefix, **({'routing_schema': 1} if prefix else {})})
    # The curl loader removes its temporary archive on every exit. Persist a
    # complete root-owned resume entry point before apt/network operations.
    persist_bundle(source, files)
    prepare_system_packages(resume=resume, shared_host=shared_host)
    mysql_version = run(['mysql', '-NBe', 'SELECT VERSION()']).decode().strip()
    if not mysql_version.startswith('8.0.') or 'MariaDB' in mysql_version:
        raise Failure('MYSQL_VERSION', 'Нужен MySQL 8.0. Установка остановлена.')
    for user in (APP_USER, SETUP_USER):
        try:
            pwd.getpwnam(user)
        except KeyError:
            run(['useradd', '--system', '--user-group', '--home-dir', str(DATA if user == APP_USER else STATE), '--no-create-home', '--shell', '/usr/sbin/nologin', user])
    setup_gid = identity(SETUP_USER)[1]
    os.chown(STATE, 0, setup_gid)
    os.chmod(STATE, 0o750)
    for path in (DATA, LOG):
        directory(path, 0o750, APP_USER)
    directory(STATE / 'sessions', 0o700, SETUP_USER)
    directory(CONFIG.parent, 0o770)
    os.chown(CONFIG.parent, 0, identity(APP_USER)[1])
    release = release_directory()
    copy_tree(INSTALL / 'payload/src', release / 'src')
    current = APP / 'current'
    if current.is_symlink():
        if current.resolve() != release:
            raise Failure('EXISTING_RELEASE', 'Найдена другая активная версия приложения.')
    elif current.exists():
        raise Failure('UNSAFE_PATH', 'Путь current уже занят.')
    else:
        current.symlink_to(release)
    prepare_resources()
    certbot_venv = STATE / 'certbot'
    directory(certbot_venv, 0o700)
    if not CERTBOT.exists():
        run(['python3', '-m', 'venv', certbot_venv], timeout=120)
    run([certbot_venv / 'bin/pip', 'install', '--disable-pip-version-check', 'certbot==5.8.0'], timeout=600,
        safe='Не удалось установить Certbot 5.8.0.')
    prepare_worker(release)
    pools(shared_host)
    if prefix:
        directory('/var/lib/gitposted-suite', 0o700)
    units()
    default = Path('/etc/nginx/sites-enabled/default')
    if not shared_host and default.is_symlink() and os.readlink(default) == '/etc/nginx/sites-available/default':
        default.unlink()  # Preflight guaranteed nginx did not exist before this installer.
    directory(ACME, 0o755)
    public = read_json(STATE / 'public.json', {})
    public.update({'version': VERSION, 'public_ip': ip, 'host': host, 'base_url': 'https://' + host + prefix,
                   'site_host': host, 'site_url': 'https://' + host + prefix, 'allowed_hosts': [host], 'path_prefix': prefix, **({'routing_schema': 1} if prefix else {}),
                   'shared_host': shared_host, 'acme_hosts': [host],
                   'tls_ready': False, 'installed': False, 'job': {'status': 'idle', 'steps': []}})
    save_json(STATE / 'public.json', public, True)
    configure_nginx(public)
    print('Obtaining trusted HTTPS certificate...', flush=True)
    obtain_certificate(host, ip)
    public['tls_ready'] = True
    configure_nginx(public)
    save_json(STATE / 'public.json', public, True)
    code = enrollment()
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'nginx', 'mysql', 'php8.3-fpm', 'cron', 'gitposted-installer.service',
         'gitposted-cert-renew.timer', 'gitposted-rate-limit-cleanup.timer'])
    show_link(host, code, prefix)


@contextmanager
def interruption_signals():
    """Unwind CLI package children before releasing a shared host lease."""
    import threading
    if threading.current_thread() is not threading.main_thread():
        yield
        return
    watched = [signal.SIGTERM]
    if hasattr(signal, 'SIGHUP'):
        watched.append(signal.SIGHUP)
    previous = {number: signal.getsignal(number) for number in watched}
    interrupted = False

    def interrupted_cli(_number, _frame):
        nonlocal interrupted
        if interrupted:
            return  # Repeated signals must not interrupt process-group cleanup.
        interrupted = True
        raise BootstrapInterrupted('Подготовка прервана. После проверки состояния пакетов продолжите установку с --resume.')

    try:
        for number in watched:
            signal.signal(number, interrupted_cli)
        yield
    finally:
        for number, handler in previous.items():
            signal.signal(number, handler)


def main():
    parser = argparse.ArgumentParser(description='GitPosted clean Ubuntu 24.04 installer')
    parser.add_argument('--public-ip')
    parser.add_argument('--host', help='Optional domain already pointing to this public IP')
    parser.add_argument('--resume', action='store_true')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--renew-certificates', action='store_true', help=argparse.SUPPRESS)
    args = parser.parse_args()
    try:
        with interruption_signals():
            if os.geteuid() != 0:
                raise Failure('ROOT_REQUIRED', 'Запустите установщик через sudo.')
            if args.renew_certificates:
                if args.host or args.public_ip or args.resume or args.dry_run:
                    raise Failure('INVALID_ARGUMENTS', 'Продление сертификатов запускается без параметров установки.')
                renew_certificates()
                return
            with shared_lock(create=not args.dry_run):
                bootstrap_main(args)
    except (Failure, BootstrapInterrupted) as error:
        print(error.code + ': ' + str(error), file=sys.stderr)
        sys.exit(1)


def bootstrap_main(args):
    if not args.dry_run and ((STATE / 'installed.json').exists() or (STATE / 'installed.json').is_symlink()):
        with installer_lock():
            reconcile_installed()
            public = read_json(STATE / 'public.json', {})
            print('GitPosted is already installed: ' + public['admin_url'])
        return
    saved = read_json(STATE / 'public.json', {}) if args.resume else {}
    if args.resume and not saved:
        saved = read_json(STATE / 'bootstrap.json', {})
    ip = public_ip(args.public_ip) if args.public_ip else public_ip(saved['public_ip']) if saved.get('public_ip') else detect_ip()
    host = host_name(args.host) if args.host else host_name(saved['host']) if saved.get('host') else ip
    if not args.host and not args.resume:
        peer = peer_installation(ip)
        if peer and peer.get('path_prefix'):
            host = host_name(peer['site_host'])
    prefix = installation_prefix(ip, args.resume)
    shared_host = preflight(args.resume, host=host, ip=ip, explicit_host=bool(args.host))
    dns_matches(host, ip)
    if args.dry_run:
        print(json.dumps({'ok': True, 'public_ip': ip, 'host': host, 'ubuntu': '24.04', 'path_prefix': prefix, 'writes': False}))
        return
    source = Path(__file__).resolve().parents[1]
    files = package_files(source)
    if not STATE.exists():
        initialize_state(ip, host, shared_host, prefix)
    with installer_lock():
        if args.resume and resume_ready():
            return
        install(source, ip, host, args.resume, checked=True, package=files, shared_host=shared_host)


if __name__ == '__main__':
    main()
