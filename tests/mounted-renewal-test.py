#!/usr/bin/env python3
"""Certificate ownership remains independent of unfinished peer checkpoints."""
import contextlib, copy, importlib.util, types, sys, unittest
from pathlib import Path
from unittest.mock import patch
if sys.platform == 'win32':
    sys.modules.setdefault('pwd', types.SimpleNamespace())
    sys.modules.setdefault('grp', types.SimpleNamespace())
    sys.modules.setdefault('fcntl', types.SimpleNamespace(LOCK_EX=2, LOCK_NB=4, flock=lambda *_: None))
spec=importlib.util.spec_from_file_location('mounted_owner_renewal',Path(__file__).resolve().parents[1]/'lib/provision.py')
p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
class MountedRenewal(unittest.TestCase):
    def public(self):
        return dict(host='8.8.8.8',public_ip='8.8.8.8',site_host='posts.example.org',path_prefix='/GitPosted',routing_schema=1,tls_ready=True,acme_hosts=['8.8.8.8','posts.example.org'])
    def registry(self):
        return dict(projects={'gitposted':dict(host='8.8.8.8',public_ip='8.8.8.8',path_prefix='/GitPosted')},authorities={
            '8.8.8.8':dict(owner='gitposted',tls_ready=True),
            'posts.example.org':dict(owner='gitposted',tls_ready=True),
            'pending.example.org':dict(owner='gitposted',tls_ready=False),
            'reposts.example.org':dict(owner='gitreposted',tls_ready=True)})
    def run_renew(self,registry=None,renew_exists=True):
        with contextlib.ExitStack() as stack:
            stack.enter_context(patch.object(p.os,'geteuid',return_value=0,create=True))
            stack.enter_context(patch.object(p,'shared_lock',contextlib.nullcontext))
            stack.enter_context(patch.object(p,'read_json',return_value=self.public()))
            stack.enter_context(patch.object(p.gateway,'read_registry',return_value=registry or self.registry()))
            stack.enter_context(patch.object(p,'ensure_hosts_available',side_effect=AssertionError('An unfinished peer must not block renewal')))
            stack.enter_context(patch.object(p,'peer_installation',side_effect=AssertionError('No peer credentials or state needed')))
            stack.enter_context(patch.object(p,'renewal_config_exists',return_value=renew_exists))
            commands=stack.enter_context(patch.object(p,'run',return_value=b''))
            p.renew_certificates()
            return [call.args[0] for call in commands.call_args_list]
    def test_owned_ready_certificates_renew_while_peer_checkpoint_is_broken(self):
        calls=self.run_renew();self.assertEqual(2,len(calls))
        names=[args[args.index('--cert-name')+1] for args in calls]
        self.assertEqual([p.cert_name('8.8.8.8'),p.cert_name('posts.example.org')],names)
        self.assertTrue(all(args[1]=='renew' and '--force-renewal' not in args for args in calls))
    def test_no_owned_certificates_does_not_call_certbot(self):
        value=self.registry()
        for authority in value['authorities'].values():authority['owner']='gitreposted'
        self.assertEqual([],self.run_renew(value))
    def test_missing_or_mismatched_own_immutable_route_is_rejected(self):
        for field,value in [('host','other.example.org'),('public_ip','1.1.1.1'),('path_prefix','/GitRePosted'),('missing',None)]:
            with self.subTest(field=field):
                registry=self.registry()
                if field=='missing':registry['projects'].pop('gitposted')
                else:registry['projects']['gitposted'][field]=value
                with self.assertRaises(p.Failure) as failure:self.run_renew(registry)
                self.assertEqual('TLS_STATE',failure.exception.code)
    def test_missing_ready_renewal_config_fails_even_for_retired_alias(self):
        with self.assertRaises(p.Failure)as failure:self.run_renew(renew_exists=False)
        self.assertEqual('TLS_STATE',failure.exception.code)
if __name__=='__main__':unittest.main(verbosity=2)
