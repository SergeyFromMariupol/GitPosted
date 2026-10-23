"""Clean-host bootstrap and bounded provisioning primitives. No production recovery."""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import pwd
import grp
import secrets
import shutil
import socket
import subprocess
import sys
import time
from urllib.parse import urlsplit

VERSION = '0.1.0-preview'
INSTALL = Path('/opt/gitposted-installer')
APP = Path('/opt/gitposted')
STATE = Path('/var/lib/gitposted-installer')
DATA = Path('/var/lib/gitposted')
CONFIG = Path('/etc/gitposted/config.php')
LOG = Path('/var/log/gitposted')
ACME = Path('/var/www/gitposted-acme')
SITE = Path('/etc/nginx/sites-available/gitposted-installer')
CERTBOT = STATE / 'certbot/bin/certbot'
APP_USER = 'gitposted'
SETUP_USER = 'gitposted-setup'


class Failure(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def run(argv, *, data=None, timeout=120, safe='Команда системы не выполнена.', capture=True):
    """Never include stdin, raw stderr or argv in raised errors."""
    try:
        p = subprocess.run([str(x) for x in argv], input=data, capture_output=capture,
                           timeout=timeout, check=False,
                           env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'LC_ALL': 'C.UTF-8'})
    except (OSError, subprocess.TimeoutExpired):
        raise Failure('SYSTEM_COMMAND_FAILED', safe) from None
    if p.returncode:
        raise Failure('SYSTEM_COMMAND_FAILED', safe)
    return p.stdout if capture else b''


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
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), uid, gid)
        os.replace(temporary, path)
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


def preflight(resume=False):
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
        return
    for path in (INSTALL, APP, STATE, DATA, CONFIG.parent, LOG, SITE):
        if path.exists() or path.is_symlink():
            raise Failure('EXISTING_INSTALLATION', 'Обнаружены существующие каталоги GitPosted. Нужен чистый сервер.')
    for name in (APP_USER, SETUP_USER):
        try:
            pwd.getpwnam(name)
        except KeyError:
            continue
        raise Failure('EXISTING_USER', 'Системный пользователь GitPosted уже существует.')
    for service in ('nginx', 'apache2', 'mysql', 'mariadb'):
        if shutil.which(service) or Path('/etc/' + service).exists():
            raise Failure('EXISTING_SERVICE', 'На сервере уже есть web-сервер или MySQL. Автоматическая установка остановлена.')
    for port in (80, 443, 3306):
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


def copy_tree(source, target):
    source, target = Path(source), Path(target)
    if source.resolve() != source or source.is_symlink() or source.stat().st_uid != 0 or source.stat().st_mode & 0o022:
        raise Failure('UNSAFE_PACKAGE', 'Исходный пакет должен принадлежать root и быть закрыт от чужой записи.')
    for path in sorted(source.rglob('*')):
        if path.is_symlink() or path.stat().st_uid != 0 or path.stat().st_mode & 0o022:
            raise Failure('UNSAFE_PACKAGE', 'Символические ссылки в пакете не разрешены.')
        relative = path.relative_to(source)
        destination = target / relative
        if path.is_dir():
            directory(destination, 0o755)
        elif path.is_file():
            directory(destination.parent, 0o755)
            atomic(destination, path.read_bytes(), 0o644)
        else:
            raise Failure('UNSAFE_PACKAGE', 'Пакет содержит необычный файл.')


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
    base_host = host_name(public['host'])
    site_host = host_name(public.get('site_host', base_host))
    installed = public.get('installed', False)
    tls_ready = public.get('tls_ready', False)
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
location ~ ^/(?:index|webhook|max-webhook|control/index|privacy/index|user-agreement/index)\\.php$ {{
 include fastcgi_params;
 fastcgi_param HTTPS on;
 fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
 fastcgi_pass unix:/run/php/gitposted.sock;
}}
location ~ \\.php(?:/|$) {{ return 404; }}
location /control/ {{ try_files $uri $uri/ /control/index.php?$query_string; }}
location / {{ try_files $uri $uri/ /index.php?$query_string; }}'''
    http = f'''server {{
listen 80 default_server;
listen [::]:80 default_server;
server_name _;
{acme}
location / {{ {'return 302 https://' + base_host + '/install/;' if tls_ready and not installed else 'return 301 https://' + site_host + '$request_uri;' if tls_ready else 'default_type text/plain; return 503 "GitPosted: HTTPS is being configured. Return to the SSH console.\\n";'} }}
}}
'''
    if not tls_ready:
        return http
    servers = []
    for host in dict.fromkeys([base_host, site_host]):
        body = app if host == site_host else f'location / {{ return 302 https://{site_host}$request_uri; }}'
        servers.append(f'''server {{
listen 443 ssl {'default_server' if host == base_host else ''};
listen [::]:443 ssl {'default_server' if host == base_host else ''};
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


def configure_nginx(public):
    old = SITE.read_bytes() if SITE.exists() else None
    atomic(SITE, nginx_content(public), 0o644)
    enabled = Path('/etc/nginx/sites-enabled/gitposted-installer')
    if not enabled.exists():
        if enabled.is_symlink():
            raise Failure('UNSAFE_PATH', 'Неожиданная конфигурация nginx.')
        enabled.symlink_to(SITE)
    try:
        run(['nginx', '-t'], safe='Новая конфигурация nginx не прошла проверку.')
        run(['systemctl', 'reload', 'nginx'])
    except Failure:
        if old is not None:
            atomic(SITE, old, 0o644)
        raise


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
ReadWritePaths=/etc/gitposted /etc/nginx /etc/cron.d /var/lib/gitposted /var/lib/gitposted-installer /var/log/gitposted /etc/letsencrypt /var/lib/letsencrypt /var/log/letsencrypt /run
[Install]
WantedBy=multi-user.target
''', 0o644)
    # Certbot is an isolated dependency of this installer, not the distribution's old version.
    atomic('/etc/systemd/system/gitposted-cert-renew.service', f'''[Unit]
Description=Renew GitPosted TLS certificates
[Service]
Type=oneshot
ExecStart={CERTBOT} renew --quiet --deploy-hook "systemctl reload nginx"
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
* * * * * gitposted POSTED_CONFIG_FILE={CONFIG} /usr/bin/php {APP}/current/src/cron.php >> {LOG}/cron.log 2>&1
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


def pools():
    for name, user, script_root, session_dir in (
        ('gitposted', APP_USER, f'{APP}:{CONFIG.parent}:{DATA}:{LOG}:/usr/share/zoneinfo:/tmp', DATA / 'sessions'),
        ('gitposted-installer', SETUP_USER, f'{INSTALL}:{STATE}:/tmp', STATE / 'sessions')):
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
request_terminate_timeout = 60s
clear_env = yes
env[POSTED_CONFIG_FILE] = {CONFIG}
env[POSTED_INSTALLER_STATE_DIR] = {STATE}
php_admin_value[open_basedir] = {script_root}
php_admin_value[session.save_path] = {session_dir}
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_value[error_log] = {LOG / 'php.log' if user == APP_USER else STATE / 'sessions/php.log'}
php_admin_value[upload_max_filesize] = 1M
php_admin_value[post_max_size] = 1M
php_admin_flag[expose_php] = off
''', 0o644)
    run(['php-fpm8.3', '-t'], safe='Конфигурация PHP-FPM не прошла проверку.')
    run(['systemctl', 'restart', 'php8.3-fpm'])


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


def show_link(host, code):
    print('\nOpen this personal one-time link:\nhttps://' + host + '/install/#setup=' + code
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
    if url.scheme != 'https' or url.netloc != host or url.path != '/control/' or url.query or url.fragment:
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
                   'site_url': 'https://' + host, 'site_host': host, 'job': job})
    for key in ('settings', 'config', 'enrollment'):
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
    # Reissue enrollment only. Never copy code, reset public metadata, restart
    # an active job or replace application/configuration during --resume.
    code = enrollment()
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'gitposted-installer.service', 'gitposted-cert-renew.timer', 'gitposted-rate-limit-cleanup.timer'])
    show_link(host_name(public['host']), code)
    return True


def prepare_worker(release):
    worker = DATA / 'runtime/post-links-venv'
    if worker.is_symlink() or worker.resolve() != worker:
        raise Failure('UNSAFE_PATH', 'Каталог Python загрузчика должен быть обычным каталогом данных.')
    if worker.exists() and worker.stat().st_uid != identity(APP_USER)[0]:
        raise Failure('UNSAFE_PATH', 'Python загрузчика должен принадлежать пользователю приложения.')
    if not worker.exists():
        run(['runuser', '-u', APP_USER, '--', 'python3', '-m', 'venv', '--copies', worker])
    # This tree is writable by the application. Never execute it as root and
    # never recursively follow it with privileged ownership changes on resume.
    run(['runuser', '-u', APP_USER, '--', worker / 'bin/python3', '-I', '-m', 'pip', 'install', '--disable-pip-version-check',
         '-r', release / 'src/tools/post_link_requirements.txt'], timeout=600,
        safe='Не удалось установить загрузчик Telegram.')


def install(source, ip, host, resume=False, checked=False):
    if not checked:
        preflight(resume)
    dns_matches(host, ip)
    print('Preflight passed. Installing GitPosted dependencies...', flush=True)
    directory(STATE, 0o700)
    save_json(STATE / 'bootstrap.json', {'installer': 'gitposted-clean-v1', 'public_ip': ip, 'host': host})
    run(['apt-get', 'update', '-qq'], timeout=600, safe='Не удалось обновить список пакетов Ubuntu.')
    run(['apt-get', 'install', '-y', '-qq', 'nginx', 'mysql-server', 'php8.3-fpm', 'php8.3-cli', 'php8.3-mysql',
         'php8.3-curl', 'php8.3-mbstring', 'python3-venv', 'ca-certificates', 'curl', 'cron', 'logrotate', 'unzip'],
        timeout=1200, safe='Установка системных пакетов не завершена. Используйте --resume.')
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
    for path in (DATA, LOG, DATA / 'sessions', DATA / 'runtime', DATA / 'storage', DATA / 'tmp'):
        directory(path, 0o700 if path.name == 'sessions' else 0o750, APP_USER)
    directory(STATE / 'sessions', 0o700, SETUP_USER)
    directory(CONFIG.parent, 0o770)
    os.chown(CONFIG.parent, 0, identity(APP_USER)[1])
    directory(INSTALL, 0o755)
    if source.resolve() != INSTALL:
        copy_tree(source, INSTALL)
    release = APP / 'releases' / VERSION
    directory(release, 0o755)
    copy_tree(INSTALL / 'payload/src', release / 'src')
    current = APP / 'current'
    if current.is_symlink():
        if current.resolve() != release:
            raise Failure('EXISTING_RELEASE', 'Найдена другая активная версия приложения.')
    elif current.exists():
        raise Failure('UNSAFE_PATH', 'Путь current уже занят.')
    else:
        current.symlink_to(release)
    for kind in ('assets', 'certs'):
        destination = DATA / kind
        copy_tree(INSTALL / 'payload/resources' / kind, destination)
        for path in [destination, *destination.rglob('*')]:
            os.chown(path, *identity(APP_USER))
    certbot_venv = STATE / 'certbot'
    directory(certbot_venv, 0o700)
    if not CERTBOT.exists():
        run(['python3', '-m', 'venv', certbot_venv], timeout=120)
    run([certbot_venv / 'bin/pip', 'install', '--disable-pip-version-check', 'certbot==5.8.0'], timeout=600,
        safe='Не удалось установить Certbot 5.8.0.')
    prepare_worker(release)
    pools()
    units()
    default = Path('/etc/nginx/sites-enabled/default')
    if default.is_symlink() and os.readlink(default) == '/etc/nginx/sites-available/default':
        default.unlink()  # Preflight guaranteed nginx did not exist before this installer.
    directory(ACME, 0o755)
    public = read_json(STATE / 'public.json', {})
    public.update({'version': VERSION, 'public_ip': ip, 'host': host, 'base_url': 'https://' + host,
                   'site_host': host, 'site_url': 'https://' + host, 'allowed_hosts': [host],
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
    show_link(host, code)


def main():
    parser = argparse.ArgumentParser(description='GitPosted clean Ubuntu 24.04 installer')
    parser.add_argument('--public-ip')
    parser.add_argument('--host', help='Optional domain already pointing to this public IP')
    parser.add_argument('--resume', action='store_true')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    try:
        if not args.dry_run and ((STATE / 'installed.json').exists() or (STATE / 'installed.json').is_symlink()):
            if os.geteuid() != 0:
                raise Failure('ROOT_REQUIRED', 'Запустите установщик через sudo.')
            with installer_lock():
                reconcile_installed()
                public = read_json(STATE / 'public.json', {})
                print('GitPosted is already installed: ' + public['admin_url'])
            return
        ip = public_ip(args.public_ip) if args.public_ip else detect_ip()
        host = host_name(args.host) if args.host else ip
        preflight(args.resume)
        dns_matches(host, ip)
        if args.dry_run:
            print(json.dumps({'ok': True, 'public_ip': ip, 'host': host, 'ubuntu': '24.04', 'writes': False}))
            return
        if not STATE.exists():
            directory(STATE, 0o700)
        with installer_lock():
            if args.resume and resume_ready():
                return
            install(Path(__file__).resolve().parents[1], ip, host, args.resume, checked=True)
    except Failure as error:
        print(error.code + ': ' + str(error), file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
