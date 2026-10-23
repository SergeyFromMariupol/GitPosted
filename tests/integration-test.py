#!/usr/bin/env python3
"""Opt-in Linux acceptance: real PHP/Unix broker/MySQL, captured platform APIs.

Run only on a dedicated GitPosted test server: python3 -B tests/integration-test.py --run
No production config/data is read. Temporary SQL names are generated and checked
before creation; all test data, processes and SQL users are removed afterwards.
The loopback-only PHP router supplies the HTTPS SAPI signal as a fixture adapter.
Real TLS/nginx/ACME acceptance is separate from this transport-isolated test.
"""
from __future__ import annotations
import argparse
import contextlib
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import pwd
import secrets
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import time

SOURCE = Path(__file__).resolve().parents[1]
HOST = 'installer.example'
BASE = 'https://' + HOST


def command(argv, data=None, timeout=60):
    result = subprocess.run([str(x) for x in argv], input=data, capture_output=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError('Fixture command failed: ' + str(argv[0]))
    return result.stdout


def sql(statement):
    return command(['mysql', '--protocol=socket', '--batch', '--skip-column-names'], statement.encode()).decode().strip()


def write(path, value, mode=0o644, owner=None):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(value, encoding='utf-8')
    path.chmod(mode)
    if owner:
        account = pwd.getpwnam(owner)
        os.chown(path, account.pw_uid, account.pw_gid)


TRANSPORT = r'''<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
require __DIR__.'/application-library.php';
$transport=static function(string $platform,string $token,string $method,string $path,array $body):array {
    $file=dirname(__DIR__,2).'/data/fake-api.json';
    $lock=fopen($file,'c+b');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Fixture lock');
    try {
        $raw=stream_get_contents($lock);$state=$raw!==''?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
        $state['calls'][]=['platform'=>$platform,'method'=>$method,'path'=>$path];
        if(($state['reject_token_hash']??'')===hash('sha256',$token))throw new RuntimeException('Fixture revoked token');
        if($platform==='telegram'){
            $reply=match($path){
                'getMe'=>['ok'=>true,'result'=>['id'=>(int)explode(':',$token,2)[0],'is_bot'=>true,'username'=>'FixtureTelegramBot','first_name'=>'Fixture']],
                'getWebhookInfo'=>['ok'=>true,'result'=>($state['telegram']??['url'=>'','allowed_updates'=>[]])],
                'setWebhook'=>['ok'=>true,'result'=>true],
                'setMyCommands','setChatMenuButton'=>['ok'=>true,'result'=>true],
                default=>throw new RuntimeException('Unexpected Telegram mutation'),
            };
            if($path==='setWebhook')$state['telegram']=['url'=>$body['url'],'allowed_updates'=>$body['allowed_updates'],'secret_hash'=>hash('sha256',$body['secret_token'])];
        }else{
            if($method==='GET'&&$path==='/me')$reply=['user_id'=>987654321,'username'=>'fixture_'.str_repeat('m',40),'name'=>'Fixture MAX'];
            elseif($method==='GET'&&$path==='/subscriptions')$reply=['subscriptions'=>$state['max']??[]];
            elseif($method==='POST'&&$path==='/subscriptions'){
                $state['max']=array_values(array_filter($state['max']??[],static fn($r)=>$r['url']!==$body['url']));
                $state['max'][]=['url'=>$body['url'],'update_types'=>$body['update_types'],'secret_hash'=>hash('sha256',$body['secret'])];$reply=['success'=>true];
            }elseif($method==='PATCH'&&$path==='/me/commands'){
                $reply=['commands'=>$body['commands']];
            }elseif($method==='DELETE'&&$path==='/subscriptions'){
                $state['max']=array_values(array_filter($state['max']??[],static fn($r)=>$r['url']!==$body['url']));$reply=['success'=>true];
            }else throw new RuntimeException('Unexpected MAX mutation');
        }
        rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($state,JSON_THROW_ON_ERROR));fflush($lock);return $reply;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
};
try{$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$response=(new InstallerApplication($transport))->run($input);}
catch(Throwable){$response=['ok'=>false,'error'=>['code'=>'FIXTURE_FAILED','message'=>'Fixture failed']];}
echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";exit($response['ok']?0:1);
'''


def stop(process):
    if process is not None and process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill();process.wait(timeout=5)


def clean_tree(root):
    assert root.resolve() == root and root.parent == Path('/var/tmp') and root.name.startswith('gitposted-integration.')
    entries = list(root.rglob('*'))
    assert all(not x.is_symlink() and (x.is_file() or x.is_dir()) and root in x.resolve().parents for x in entries)
    for path in sorted(entries, key=lambda x: len(x.parts), reverse=True):
        path.rmdir() if path.is_dir() else path.unlink()
    root.rmdir()


class Session:
    def __init__(self, port):
        self.port=port;self.cookies={}

    def call(self, action, value=None, csrf=None, origin=BASE):
        connection=http.client.HTTPConnection('127.0.0.1',self.port,timeout=45)
        headers={'Host':HOST,'Origin':origin,'Sec-Fetch-Site':'same-origin','Content-Type':'application/json'}
        if csrf:headers['X-CSRF-Token']=csrf
        if self.cookies:headers['Cookie']='; '.join(k+'='+v for k,v in self.cookies.items())
        connection.request('POST' if value is not None else 'GET','/install/api.php?action='+action,
                           body=json.dumps(value).encode() if value is not None else None,headers=headers)
        response=connection.getresponse()
        for key,value in response.getheaders():
            if key.lower()=='set-cookie':
                name,setting=value.split(';',1)[0].split('=',1);self.cookies[name]=setting
        raw=response.read();status=response.status;connection.close()
        try:body=json.loads(raw)
        except ValueError:raise AssertionError('Non-JSON fixture response') from None
        return status,body

    def successful(self, action, value=None, csrf=None):
        code,body=self.call(action,value,csrf)
        assert code==200 and body.get('ok') is True,(action,code,body.get('error',{}).get('code'))
        return body['result']

    def state(self):return self.successful('status')

    def wait(self, expected='complete'):
        deadline=time.monotonic()+60
        while time.monotonic()<deadline:
            state=self.state()
            if state.get('installed'):return state
            job=state.get('job') or {}
            if job.get('status') in ('failed','complete'):
                assert job['status']==expected,('Job status',job.get('error',{}).get('code'))
                return state
            time.sleep(1)
        raise AssertionError('Fixture job timed out')


def run_case(name, tg, mx, repair=False, foreign=False):
    root=Path(tempfile.mkdtemp(prefix='gitposted-integration.',dir='/var/tmp'));root.chmod(0o755)
    suffix=secrets.token_hex(6);dbname='GitPostedCheck_'+suffix;dbuser='gpcheck_'+suffix
    assert sql("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"+dbname+"'")=='0'
    assert sql("SELECT COUNT(*) FROM mysql.user WHERE User='"+dbuser+"'")=='0'
    broker_process=web_process=None
    try:
        bundle=root/'bundle';shutil.copytree(SOURCE,bundle)
        for path in [bundle,*bundle.rglob('*')]:
            assert not path.is_symlink();path.chmod(0o755 if path.is_dir() else 0o644)
        # Only literal SQL identifiers differ in the temporary broker source.
        path=bundle/'lib/broker.py';code=path.read_text()
        assert 'GitPosted' in code and 'gitposted_app' in code
        path.write_text(code.replace('GitPosted',dbname).replace('gitposted_app',dbuser))
        state=root/'state';state.mkdir(mode=0o750);os.chown(state,0,pwd.getpwnam('gitposted-setup').pw_gid)
        sessions=state/'sessions';sessions.mkdir(mode=0o700);os.chown(sessions,pwd.getpwnam('gitposted-setup').pw_uid,pwd.getpwnam('gitposted-setup').pw_gid)
        data=root/'data';data.mkdir(mode=0o750);os.chown(data,pwd.getpwnam('gitposted').pw_uid,pwd.getpwnam('gitposted').pw_gid)
        configdir=root/'config';configdir.mkdir(mode=0o770);os.chown(configdir,0,pwd.getpwnam('gitposted').pw_gid)
        config=configdir/'config.php'
        initial_api = {'telegram': {'url': BASE+'/webhook.php?key=fixture-private-url'},
                       'max': [{'url': BASE+'/max-webhook.php?key=fixture-private-url'}]} if foreign else {}
        write(data/'fake-api.json',json.dumps(initial_api),0o600,'gitposted')
        app=bundle/'bin/application.php';app.rename(bundle/'bin/application-library.php');write(app,TRANSPORT)
        api=bundle/'web/api.php';code=api.read_text()
        assert code.count('new WizardFiles()')==1 and code.count('new WizardBroker()')==1
        code=code.replace('new WizardFiles()',"new WizardFiles('"+str(state)+"')").replace('new WizardBroker()',"new WizardBroker('"+str(state/'broker.sock')+"')")
        write(api,code)
        write(root/'router.php',"<?php $_SERVER['HTTPS']='on';$_SERVER['SERVER_PORT']='443';require '"+str(api)+"';")
        loader="import sys\nfrom pathlib import Path\nsys.path.insert(0,"+repr(str(bundle/'lib'))+")\nimport provision as p\n"
        for key,value in {'STATE':state,'DATA':data,'CONFIG':config,'INSTALL':bundle,'APP':root/'app'}.items():loader+='p.'+key+'=Path('+repr(str(value))+')\n'
        loader+='p.configure_nginx=lambda public:None\noriginal_run=p.run\np.run=lambda argv,**kw:b"" if str(argv[0])=="systemctl" else original_run(argv,**kw)\n'
        write(root/'start-broker.py',loader+'import broker\nbroker.main()\n')
        write(root/'enroll.py',loader+"p.save_json(p.STATE/'public.json',{'host':'"+HOST+"','public_ip':'8.8.8.8','base_url':'"+BASE+"','site_url':'"+BASE+"','allowed_hosts':['"+HOST+"'],'tls_ready':True,'installed':False,'version':p.VERSION,'job':{'status':'idle','steps':[]}},True)\nprint(p.enrollment())\n")
        code=command(['python3','-B',root/'enroll.py']).decode().strip()
        logfile=open(root/'process.log','wb')
        broker_process=subprocess.Popen(['python3','-B',root/'start-broker.py'],stdout=logfile,stderr=logfile)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        web_process=subprocess.Popen(['runuser','-u','gitposted-setup','--','php','-d','display_errors=0','-d','log_errors=0','-S','127.0.0.1:'+str(port),root/'router.php'],cwd=bundle/'web',stdout=logfile,stderr=logfile,start_new_session=True)
        session=Session(port)
        for _ in range(100):
            try:
                status,body=session.call('status')
                if status==200 and body.get('ok') and (state/'broker.sock').exists():
                    initial=body['result'];break
            except (OSError,http.client.HTTPException):pass
            time.sleep(0.03)
        else:raise AssertionError('Fixture services did not start')
        assert initial['secure'] and not initial['authenticated']
        session.successful('login',{'code':code});current=session.state();csrf=current['csrf']
        password=secrets.token_urlsafe(24)
        value={'admin':{'username':'operator','password':password,'password_confirm':password},
               'telegram':{'enabled':tg,'token':'123456789:'+('t'*40) if tg else '', 'takeover':False},
               'max':{'enabled':mx,'token':'fixture-max-'+secrets.token_hex(16) if mx else '', 'takeover':False},
               'timezone':'UTC','support_username':'','hcaptcha':{'enabled':False},'mtproto':{'enabled':False}}
        for platform in ('telegram','max'):
            if value[platform]['enabled']:
                result=session.successful('check_bot',{'platform':platform,'token':value[platform]['token']},csrf)
                assert result['identity']['username']
                assert result['existing_urls']==([BASE+'/[configured-webhook]'] if foreign else [])
                assert 'fixture-private-url' not in json.dumps(result)
                value[platform]['takeover'] = foreign
        if repair:
            fake=json.loads((data/'fake-api.json').read_text());fake['reject_token_hash']=hashlib.sha256(value['telegram']['token'].encode()).hexdigest()
            write(data/'fake-api.json',json.dumps(fake),0o600,'gitposted')
        session.successful('start',{'settings':value},csrf)
        if repair:
            failed=session.wait('failed');assert not config.exists(),'Config should not precede successful identity check'
            value['telegram']['token']='123456789:'+('r'*40)
            session.successful('revise',{'job_id':failed['job']['id'],'settings':value},csrf)
        complete=session.wait();assert complete['installed']
        assert sql('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\''+dbname+'\'')=='33'
        assert sql('SELECT schema_version FROM `'+dbname+'`.posted_max_schema_meta WHERE id=1')=='5'
        validation=r'''$c=require getenv('POSTED_CONFIG_FILE');$i=json_decode(stream_get_contents(STDIN),true);echo json_encode(['password_ok'=>password_verify($i['password'],$c['admin_auth']['password_hash']),'telegram'=>$c['telegram_bot_enabled'],'max'=>$c['max_bot_enabled'],'name'=>$c['database']['name']]);'''
        flags=json.loads(command(['runuser','-u','gitposted','--','env','POSTED_CONFIG_FILE='+str(config),'php','-r',validation],json.dumps({'password':password}).encode()))
        assert flags=={'password_ok':True,'telegram':tg,'max':mx,'name':dbname}
        fake=json.loads((data/'fake-api.json').read_text())
        assert all(x['path'] in ('getMe','getWebhookInfo','setWebhook','setMyCommands','setChatMenuButton','/me','/subscriptions','/me/commands') for x in fake.get('calls',[]))
        if not tg:assert not any(x['platform']=='telegram' for x in fake.get('calls',[]))
        if not mx:assert not any(x['platform']=='max' for x in fake.get('calls',[]))
        status,body=session.call('start',{'settings':value},csrf);assert status==410 and body['error']['code']=='INSTALLED'
        final_private=json.loads((state/'private.json').read_text());assert not any(x in final_private for x in ('settings','config','enrollment','bot_bindings'))
        print('PASS real HTTP/Unix/MySQL:',name,flush=True)
    finally:
        # Kill our isolated process group, including runuser's PHP child.
        if web_process is not None:
            try:os.killpg(web_process.pid,signal.SIGTERM)
            except ProcessLookupError:pass
        stop(web_process);stop(broker_process)
        # Only fresh generated identifiers from this case may be dropped.
        assert dbname.startswith('GitPostedCheck_') and dbname.removeprefix('GitPostedCheck_')==suffix and len(suffix)==12
        sql('DROP DATABASE IF EXISTS `'+dbname+'`; DROP USER IF EXISTS \''+dbuser+'\'@\'localhost\';')
        path=state/'broker.sock' if 'state' in locals() else None
        if path and path.exists():assert stat.S_ISSOCK(path.lstat().st_mode);path.unlink()
        clean_tree(root)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run',action='store_true',help='Explicitly allow isolated real SQL/process acceptance on a dedicated test host')
    args=parser.parse_args()
    if not args.run:parser.print_help();return
    if os.geteuid()!=0:raise SystemExit('Run on a dedicated test host as root.')
    for case,tg,mx in [('none',False,False),('telegram',True,False),('max',False,True),('both',True,True)]:run_case(case,tg,mx)
    run_case('revoked-token-revise',True,False,repair=True)
    run_case('redacted-own-origin-consent',True,True,foreign=True)
    print('Integration acceptance passed: 6 isolated cases; platform transport captured; no publications.')


if __name__=='__main__':main()
