#!/usr/bin/env python3
"""GitPosted mount routing with a private nginx and synthetic FastCGI peer.

No PHP application, configuration, database, bot or system service is started.
"""
from __future__ import annotations

import http.client
import importlib.util
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import ssl
import struct
import subprocess
import sys
import tempfile
import threading
import time
import types
import unittest

if os.name != 'posix':
    sys.modules.setdefault('pwd', types.SimpleNamespace())
    sys.modules.setdefault('grp', types.SimpleNamespace())
    sys.modules.setdefault('fcntl', types.SimpleNamespace(LOCK_EX=2, LOCK_NB=4, flock=lambda *_: None))

package = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('mounted_fixture_provision', package / 'lib/provision.py')
p = importlib.util.module_from_spec(spec); spec.loader.exec_module(p)
g = p.gateway


def public(*, separate=False, installed=True):
    return {'host': '8.8.8.8', 'site_host': 'posted.example.org' if separate else '8.8.8.8',
            'public_ip': '8.8.8.8', 'path_prefix': '/GitPosted', 'tls_ready': True,
            'installed': installed, 'shared_host': False,
            'acme_hosts': ['8.8.8.8', 'posted.example.org'] if separate else ['8.8.8.8']}


class MountedRenderTests(unittest.TestCase):
    def test_single_private_backend_with_two_hosts_and_before_or_after_install(self):
        for separate in (False, True):
            for installed in (False, True):
                with self.subTest(separate=separate, installed=installed):
                    content = p.nginx_content(public(separate=separate, installed=installed))
                    self.assertEqual(1, content.count('server {'))
                    self.assertIn('listen 127.0.0.1:8181;', content)
                    self.assertNotIn('listen 443', content); self.assertNotIn('listen 80', content)
                    self.assertNotIn('ssl_certificate', content)
                    self.assertIn('fastcgi_param REQUEST_URI $http_x_original_uri;', content)
                    self.assertIn('absolute_redirect off;', content)
                    if installed:
                        self.assertIn('fastcgi_param SCRIPT_NAME /GitPosted/control/passkeys.php;', content)
                        self.assertIn('fastcgi_param PATH_INFO $gitposted_passkey_path;', content)
                    else:
                        self.assertIn('location = /install {', content)
                        self.assertIn('location = /install/ {', content)
                        self.assertIn('fastcgi_param SCRIPT_FILENAME ' + str(p.INSTALL) + '/web/index.php;', content)
                        self.assertIn('location = /install/api.php {', content)
                    self.assertIn('routing schema 1', g._backend('gitposted', content))


class FastCGI:
    def __init__(self, path):
        self.socket = socket.socket(socket.AF_UNIX); self.socket.bind(str(path)); self.socket.listen(8)
        self.socket.settimeout(0.2); self.stopped = threading.Event()
        self.thread = threading.Thread(target=self.serve, daemon=True); self.thread.start()

    @staticmethod
    def exact(connection, size):
        result = b''
        while len(result) < size:
            value = connection.recv(size - len(result))
            if not value:
                raise EOFError()
            result += value
        return result

    @staticmethod
    def params(raw):
        result, offset = {}, 0
        def length():
            nonlocal offset
            value = raw[offset]; offset += 1
            if value & 0x80:
                value = ((value & 0x7f) << 24) | int.from_bytes(raw[offset:offset+3], 'big'); offset += 3
            return value
        while offset < len(raw):
            name_length, value_length = length(), length()
            name = raw[offset:offset+name_length].decode(); offset += name_length
            value = raw[offset:offset+value_length].decode(); offset += value_length
            result[name] = value
        return result

    @staticmethod
    def record(kind, request, content):
        return struct.pack('!BBHHBB', 1, kind, request, len(content), 0, 0) + content

    def serve(self):
        while not self.stopped.is_set():
            try:
                connection, _ = self.socket.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            with connection:
                connection.settimeout(5)
                parameters = b''
                try:
                    while True:
                        version, kind, request, size, padding, _ = struct.unpack('!BBHHBB', self.exact(connection, 8))
                        value = self.exact(connection, size)
                        if padding:
                            self.exact(connection, padding)
                        if kind == 4:
                            parameters += value
                        if kind == 5 and size == 0:
                            payload = json.dumps(self.params(parameters)).encode()
                            output = b'Content-Type: application/json\r\n\r\n' + payload
                            connection.sendall(self.record(6, request, output) + self.record(6, request, b'') +
                                               self.record(3, request, struct.pack('!IB3s', 0, 0, b'\0\0\0')))
                            break
                except (EOFError, OSError, ValueError):
                    continue

    def close(self):
        self.stopped.set(); self.socket.close(); self.thread.join(timeout=5)


def free_ports(count):
    result = set()
    while len(result) < count:
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0)); result.add(probe.getsockname()[1])
    return sorted(result)


@unittest.skipUnless(os.name == 'posix' and getattr(os, 'geteuid', lambda: -1)() == 0 and shutil.which('nginx') and shutil.which('openssl'),
                     'POSIX root private nginx/FastCGI fixture')
class FastCGIMountTests(unittest.TestCase):
    def test_gateway_backend_preserves_external_uri_passkey_path_and_safe_redirects(self):
        for separate, installed in ((False, False), (False, True), (True, False), (True, True)):
            with self.subTest(separate=separate, installed=installed), tempfile.TemporaryDirectory(prefix='posted-mount-fcgi-') as temporary:
                root = Path(temporary).resolve(); root.chmod(0o700)
                state = public(separate=separate, installed=installed)
                route = g._descriptor('gitposted', state)
                authorities = {host: {**g._authority('gitposted', host), 'tls_ready': True} for host in g._hosts(route)}
                registry = {'version': 1, 'projects': {'gitposted': route}, 'authorities': authorities, 'default_host': state['host']}
                source = root / 'src'; (source / 'control').mkdir(parents=True)
                for name in ('index.php', 'control/index.php', 'control/passkeys.php'):
                    (source / name).write_text('fixture, never executed')
                peer = FastCGI(root / 'php.sock'); nginx = None
                try:
                    key, cert = root / 'test.key', root / 'test.pem'
                    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                                    '-keyout', str(key), '-out', str(cert), '-subj', '/CN=mount-fixture'],
                                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
                    http_port, tls_port, backend_port = free_ports(3)
                    text = g.render_registry(registry) + '\n' + p.nginx_content(state)
                    text = text.replace('listen 80', 'listen 127.0.0.1:' + str(http_port)).replace('listen 443 ssl', 'listen 127.0.0.1:' + str(tls_port) + ' ssl')
                    text = text.replace('127.0.0.1:8181', '127.0.0.1:' + str(backend_port))
                    text = text.replace(str(p.APP) + '/current/src', str(source))
                    text = text.replace('unix:/run/php/gitposted.sock', 'unix:' + str(root / 'php.sock'))
                    # The installer handler remains captured too; no PHP-FPM runs.
                    text = text.replace('unix:/run/php/gitposted-installer.sock', 'unix:' + str(root / 'php.sock'))
                    text = text.replace('include fastcgi_params;', 'include /etc/nginx/fastcgi_params;')
                    for authority in authorities.values():
                        text = text.replace('/etc/letsencrypt/live/' + authority['cert_name'] + '/fullchain.pem', str(cert))
                        text = text.replace('/etc/letsencrypt/live/' + authority['cert_name'] + '/privkey.pem', str(key))
                    for name in ('client', 'proxy', 'fastcgi', 'scgi', 'uwsgi'):
                        (root / name).mkdir()
                    config = root / 'nginx.conf'
                    config.write_text('daemon off;\nmaster_process off;\npid ' + str(root / 'nginx.pid') + ';\nerror_log ' + str(root / 'nginx.log') + ' notice;\nevents {}\nhttp {\naccess_log off;\n' +
                                      '\n'.join(name + '_temp_path ' + str(root / directory) + ';' for name, directory in
                                                (('client_body', 'client'), ('proxy', 'proxy'), ('fastcgi', 'fastcgi'), ('scgi', 'scgi'), ('uwsgi', 'uwsgi'))) + '\n' + text + '\n}\n')
                    command = ['nginx', '-p', str(root), '-c', str(config), '-e', str(root / 'nginx.log')]
                    checked = subprocess.run(command + ['-t'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
                    self.assertEqual(0, checked.returncode, checked.stderr.decode(errors='replace'))
                    nginx = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
                    deadline = time.monotonic() + 5
                    while time.monotonic() < deadline:
                        try:
                            with socket.create_connection(('127.0.0.1', http_port), timeout=0.2):
                                break
                        except OSError:
                            self.assertIsNone(nginx.poll()); time.sleep(0.03)

                    def request(path, host=None):
                        connection = http.client.HTTPSConnection('127.0.0.1', tls_port, context=ssl._create_unverified_context(), timeout=5)
                        connection.request('GET', path, headers={'Host': host or state['site_host'], 'X-Original-URI': '/attacker-controlled', 'X-Forwarded-Proto': 'http'})
                        response = connection.getresponse(); body = response.read(); headers = dict(response.getheaders())
                        connection.close(); return response.status, headers, body

                    if not installed:
                        for host in set((state['host'], state['site_host'])):
                            for route, script in (('/install/', 'index.php'), ('/install/api.php?action=status', 'api.php')):
                                uri = '/GitPosted' + route
                                status, _, body = request(uri, host)
                                self.assertEqual(200, status, body.decode(errors='replace'))
                                parameters = json.loads(body)
                                self.assertEqual(str(p.INSTALL) + '/web/' + script, parameters['SCRIPT_FILENAME'])
                                self.assertEqual(uri, parameters['REQUEST_URI'])
                                self.assertEqual('on', parameters['HTTPS'])
                                self.assertEqual(host, parameters['HTTP_HOST'])
                            status, headers, _ = request('/GitPosted/install', host)
                            self.assertEqual(302, status)
                            self.assertIn(headers.get('Location'), ('/GitPosted/install/', 'https://' + host + '/GitPosted/install/'))
                        continue

                    for suffix in ('/auth/options', '/status', ''):
                        uri = '/GitPosted/control/passkeys.php' + suffix + '?fixture=keep'
                        status, _, body = request(uri)
                        self.assertEqual(200, status, body.decode(errors='replace'))
                        parameters = json.loads(body)
                        self.assertEqual(uri, parameters['REQUEST_URI'])
                        self.assertEqual('/GitPosted/control/passkeys.php', parameters['SCRIPT_NAME'])
                        self.assertEqual(suffix, parameters.get('PATH_INFO', ''))
                        self.assertEqual(str(source / 'control/passkeys.php'), parameters['SCRIPT_FILENAME'])
                        self.assertEqual('on', parameters['HTTPS'])
                    for path in ('/GitPosted/.env', '/GitPosted/bootstrap.php', '/GitPosted/cron.php',
                                 '/GitPosted/tools/verify_installation.php', '/GitPosted/config.json',
                                 '/GitPosted/control/passkeys.php/not-allowed'):
                        status, _, _ = request(path)
                        self.assertIn(status, (403, 404), path)
                    for name in ('control', 'privacy', 'user-agreement', 'assets'):
                        status, headers, _ = request('/GitPosted/' + name + '?fixture=keep')
                        self.assertEqual(308, status)
                        self.assertIn(headers.get('Location'), ('/GitPosted/' + name + '/?fixture=keep',
                                                              'https://' + state['site_host'] + '/GitPosted/' + name + '/?fixture=keep'))
                    if separate:
                        status, headers, _ = request('/GitPosted/control/?fixture=keep', state['host'])
                        self.assertEqual(308, status)
                        self.assertEqual('https://' + state['site_host'] + '/GitPosted/control/?fixture=keep', headers.get('Location'))
                finally:
                    if nginx is not None:
                        if nginx.poll() is None:
                            os.killpg(nginx.pid, signal.SIGTERM)
                        nginx.wait(timeout=5)
                    peer.close()


if __name__ == '__main__':
    unittest.main(verbosity=2)
