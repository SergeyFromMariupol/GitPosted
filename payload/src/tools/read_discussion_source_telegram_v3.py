"""Existing-bot exact discussion source reader.

Import is stdlib-only and never opens a session or network connection.
Production main requires -I -S -B, trusted files, existing shared lock and auth.
No persistent SDK session, login, update state, send/delete/history or fallback.
"""
from __future__ import annotations

from dataclasses import dataclass, field
import hashlib
import json
from pathlib import PurePosixPath
import re
from typing import Any

MAX_MID=2147483647
MAX_AGE=172500
MAX_BATCH=5
SDK_VERSION='1.45.0'
SDK_WHEEL_SHA='339e24c83aedc9f12cbe389264627edc49779e4289c535aa90aafc4ce62f7972'


class ReadDenied(RuntimeError):
    def __init__(self,code='READ_DENIED'):
        self.code=code
        super().__init__(code)


def deny(code='READ_DENIED'):raise ReadDenied(code)
def exact_int(value,minimum=1,maximum=(1<<63)-1):return type(value) is int and minimum<=value<=maximum
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def absent_false(value):return value is None or value is False
def chat(value):
    if type(value) is not str or not re.fullmatch(r'-1[0-9]{12}',value) or not -1999999999999<=int(value)<-1000000000000:deny('INVALID_OBSERVATION')
    return value
def peer_id(chat_id):return -int(chat(chat_id))-1000000000000


@dataclass(frozen=True)
class GenericObservation:
    value:dict[str,Any]
    @classmethod
    def parse(cls,value,now):
        names={'version','sender_channel_id','original','group_chat_id','group_message_id','group_date','received_at','expires_at','binding'}
        if type(value) is not dict or set(value)!=names or type(value['version']) is not int or value['version']!=2 or not exact_int(now):deny('INVALID_OBSERVATION')
        sender,group=chat(value['sender_channel_id']),chat(value['group_chat_id']);original=value['original']
        if type(original) is not dict or set(original)!={'chat_id','message_id','date'}:deny('INVALID_OBSERVATION')
        origin=chat(original['chat_id'])
        if group in (sender,origin) or not exact_int(original['message_id'],1,MAX_MID) or not exact_int(value['group_message_id'],1,MAX_MID):deny('INVALID_OBSERVATION')
        for number in (original['date'],value['group_date'],value['received_at'],value['expires_at']):
            if not exact_int(number):deny('INVALID_OBSERVATION')
        if value['group_date']>now+30 or value['received_at']>now+30 or value['group_date']>value['received_at']+30 or original['date']>value['group_date']+5 or value['received_at']-value['group_date']>=MAX_AGE or value['expires_at']!=value['group_date']+MAX_AGE or now>=value['expires_at']:deny('INVALID_OBSERVATION')
        # Original date is provenance and may be old; NEVER a300s freshness gate.
        binding={k:value[k] for k in ('sender_channel_id','original','group_chat_id','group_message_id','group_date')}
        if type(value['binding']) is not str or value['binding']!=hashlib.sha256(canonical(binding)).hexdigest():deny('INVALID_OBSERVATION')
        return cls(json.loads(canonical(value)))


@dataclass(frozen=True)
class GroupScope:
    chat_id:str
    message_ids:tuple[int,...]
    username:str|None=None
    def validate(self):
        chat(self.chat_id)
        if type(self.message_ids) is not tuple or not 1<=len(self.message_ids)<=MAX_BATCH or len(set(self.message_ids))!=len(self.message_ids) or not all(exact_int(x,1,MAX_MID) for x in self.message_ids):deny('INVALID_SCOPE')
        if self.username is not None and (type(self.username) is not str or re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}',self.username) is None):deny('INVALID_SCOPE')


@dataclass(frozen=True)
class PathFact:
    path:str
    kind:str
    owner:int
    mode:int


def trusted_chain(path:str,facts:list[PathFact],app_uid:int,*,executable=False,directory=False):
    """Strict lstat policy; the provider gathers facts WITHOUT following symlinks.

    Every ancestor including / must be present, trusted owner0/appUID and no0022.
    No symlink exception is made for an interpreter installed with --copies.
    """
    if not exact_int(app_uid) or type(path) is not str or not path.startswith('/') or str(PurePosixPath(path))!=path or '//' in path or '\\' in path or any(p in ('.','..') for p in path.split('/')):deny('UNTRUSTED_EXECUTABLE')
    parts=PurePosixPath(path).parts
    expected=['/']+[str(PurePosixPath(*parts[:i])) for i in range(2,len(parts)+1)]
    if type(facts) is not list or [x.path for x in facts]!=expected:deny('UNTRUSTED_EXECUTABLE')
    for index,fact in enumerate(facts):
        if type(fact) is not PathFact or fact.owner not in (0,app_uid) or type(fact.owner) is not int or type(fact.mode) is not int or not 0<=fact.mode<=0o7777 or fact.mode&0o022:deny('UNTRUSTED_EXECUTABLE')
        if fact.kind!=('dir' if directory or index<len(facts)-1 else 'file'):deny('UNTRUSTED_EXECUTABLE')
    if directory and executable:deny('UNTRUSTED_EXECUTABLE')
    if executable and not facts[-1].mode&0o111:deny('UNTRUSTED_EXECUTABLE')
    return True


@dataclass(frozen=True)
class ExistingAuth:
    api_id:int=field(repr=False)
    api_hash:str=field(repr=False)
    dc_id:int=field(repr=False)
    server_address:str=field(repr=False)
    port:int=field(repr=False)
    auth_key:bytes=field(repr=False)
    def validate(self):
        import ipaddress
        if not exact_int(self.api_id,1,MAX_MID) or type(self.api_hash) is not str or re.fullmatch('[a-fA-F0-9]{32}',self.api_hash) is None or not exact_int(self.dc_id,1,5) or type(self.port) is not int or self.port!=443 or type(self.auth_key) is not bytes or len(self.auth_key)!=256:deny('INVALID_EXISTING_AUTH')
        try:ipaddress.ip_address(self.server_address)
        except (ValueError,TypeError):deny('INVALID_EXISTING_AUTH')
        return True


class BotReadGate:
    """Per-job typed RPC allowlist. No personal actor/menu/history/webview policy.

    Actual SDK TL requests and responses are validated at both sender and queue
    boundaries before the existing-auth connection is started.
    """
    def __init__(self,sdk,api_id,expected_bot_id,scopes):
        if getattr(sdk,'__version__',None)!=SDK_VERSION or not exact_int(api_id,1,MAX_MID) or not exact_int(expected_bot_id,1,999999999999) or type(scopes) is not tuple or not 1<=len(scopes)<=MAX_BATCH:deny('INVALID_SCOPE')
        for s in scopes:s.validate()
        if sum(len(s.message_ids) for s in scopes)>MAX_BATCH or len({s.chat_id for s in scopes})!=len(scopes):deny('INVALID_SCOPE')
        aliases=[s.username.lower() for s in scopes if s.username is not None]
        if len(set(aliases))!=len(aliases):deny('INVALID_SCOPE')
        self.sdk=sdk;self.api_id=api_id;self.bot_id=expected_bot_id;self.scopes={peer_id(s.chat_id):s for s in scopes}
        self.identity=False;self.bindings={};self.zero_refresh=set();self.validated_groups=set();self.counts={};self.certified={};self.completed=set();self.closed=False

    def unwrap(self,request):
        f=self.sdk.functions;seen=set()
        for _ in range(4):
            cls=type(request)
            if cls not in (f.InvokeWithLayerRequest,f.InitConnectionRequest,f.InvokeWithoutUpdatesRequest):return request
            if cls in seen:deny('WRAPPER_DENIED')
            seen.add(cls)
            if cls is f.InvokeWithLayerRequest:
                from telethon.tl.alltlobjects import LAYER
                if type(request.layer) is not int or request.layer!=LAYER:deny('WRAPPER_DENIED')
            if cls is f.InitConnectionRequest and (type(request.api_id) is not int or request.api_id!=self.api_id or request.proxy is not None or request.params is not None or any(type(getattr(request,k,None)) is not str or len(getattr(request,k))>128 for k in ('device_model','system_version','app_version','system_lang_code','lang_pack','lang_code'))):deny('WRAPPER_DENIED')
            request=request.query
        deny('WRAPPER_DENIED')

    def cache_group(self,channel_id,access_hash):
        # The SQLiteRO loader selects ONLY these exact requested peer IDs.
        if not self.identity or not exact_int(channel_id,1,999999999999) or channel_id not in self.scopes or not exact_int(access_hash,-(1<<63),(1<<63)-1) or access_hash==0:deny('GROUP_SCOPE_DENIED')
        existing=self.bindings.get(channel_id)
        if existing is not None and existing!=access_hash:deny('GROUP_SCOPE_DENIED')
        self.bindings[channel_id]=access_hash

    def input_group(self,value):
        t=self.sdk.types
        return type(value) is t.InputChannel and type(value.channel_id) is int and value.channel_id in self.scopes and type(value.access_hash) is int and self.bindings.get(value.channel_id)==value.access_hash

    def permit_bot_zero_refresh(self,channel_id):
        # Official bot-only peer bootstrap, not a guessed message/history query:
        # https://core.telegram.org/api/peers#access-hash
        if not self.identity or not exact_int(channel_id,1,999999999999) or channel_id not in self.scopes or channel_id in self.bindings or channel_id in self.validated_groups:deny('GROUP_SCOPE_DENIED')
        self.zero_refresh.add(channel_id)

    def zero_group(self,value):
        t=self.sdk.types
        return type(value)is t.InputChannel and type(value.channel_id)is int and value.channel_id in self.zero_refresh and value.channel_id in self.scopes and type(value.access_hash)is int and value.access_hash==0

    def inspect(self,request):
        if self.closed:deny('GATE_CLOSED')
        f,t=self.sdk.functions,self.sdk.types;leaf=self.unwrap(request);cls=type(leaf)
        if cls is f.help.GetConfigRequest:return ('config',1)
        if cls is f.PingRequest and exact_int(leaf.ping_id,-(1<<63),(1<<63)-1):return ('ping',2)
        if cls is f.users.GetUsersRequest and type(leaf.id) is list and len(leaf.id)==1 and type(leaf.id[0]) is t.InputUserSelf:return ('self',1)
        if not self.identity:deny('BOT_IDENTITY_REQUIRED')
        if cls is f.contacts.ResolveUsernameRequest:
            matches=[s for s in self.scopes.values() if s.username is not None and leaf.username==s.username]
            if len(matches)==1:return ('resolve:'+matches[0].chat_id,1)
        if cls is f.channels.GetChannelsRequest and type(leaf.id) is list and len(leaf.id)==1 and (self.input_group(leaf.id[0])or self.zero_group(leaf.id[0])):return ('group:'+str(leaf.id[0].channel_id),1)
        if cls is f.channels.GetMessagesRequest and self.input_group(leaf.channel) and leaf.channel.channel_id in self.validated_groups and type(leaf.id) is list and len(leaf.id)==1 and type(leaf.id[0]) is t.InputMessageID:
            mid=leaf.id[0].id;scope=self.scopes[leaf.channel.channel_id]
            if type(mid) is int and mid in scope.message_ids:return ('read:'+str(leaf.channel.channel_id)+':'+str(mid),1)
        deny('RPC_SCOPE_DENIED')

    def before_send(self,request):
        # No application request batching; at most5 separately observed reads.
        if type(request) in (list,tuple):deny('BATCH_DENIED')
        name,limit=self.inspect(request)
        if self.counts.get(name,0)>=limit:deny('RPC_LIMIT')
        self.counts[name]=self.counts.get(name,0)+1
        self.certified[id(request)]=(request,hashlib.sha256(bytes(request)).digest())

    def before_queue(self,state):
        # Transport ack/state replies may be queued internally by the sender;
        # they are NOT grants for an application RPC or a new channel read.
        if self.closed:deny('GATE_CLOSED')
        request=state.request;t=self.sdk.types
        if type(request) is t.MsgsAck:
            if type(request.msg_ids) is list and 1<=len(request.msg_ids)<=64 and all(exact_int(x) for x in request.msg_ids):return
            deny('TRANSPORT_FRAME_DENIED')
        if type(request) is t.MsgsStateInfo:
            if exact_int(request.req_msg_id) and type(request.info) is str and 1<=len(request.info)<=64 and set(request.info)<={'\x01'}:return
            deny('TRANSPORT_FRAME_DENIED')
        certificate=self.certified.get(id(request))
        if certificate is None or certificate[0] is not request or hashlib.sha256(bytes(request)).digest()!=certificate[1]:deny('UNCERTIFIED_DISPATCH')
        self.inspect(request)

    def after_response(self,request,result):
        certificate=self.certified.get(id(request))
        if self.closed or certificate is None or certificate[0] is not request or id(request) in self.completed or hashlib.sha256(bytes(request)).digest()!=certificate[1]:deny('UNCERTIFIED_RESPONSE')
        f,t=self.sdk.functions,self.sdk.types;leaf=self.unwrap(request)
        if type(leaf) is f.users.GetUsersRequest:
            if type(result) is not list or len(result)!=1 or type(result[0]) is not t.User or type(result[0].id) is not int or result[0].id!=self.bot_id or result[0].bot is not True:deny('BOT_IDENTITY_MISMATCH')
            self.identity=True
        elif type(leaf) is f.contacts.ResolveUsernameRequest:
            scope=next(s for s in self.scopes.values() if s.username==leaf.username);expected=peer_id(scope.chat_id)
            if type(result) is not t.contacts.ResolvedPeer or type(result.peer) is not t.PeerChannel or type(result.peer.channel_id) is not int or result.peer.channel_id!=expected or result.users or type(result.chats) is not list or len(result.chats)!=1:deny('GROUP_SCOPE_DENIED')
            self.validate_group(result.chats[0],expected)
        elif type(leaf) is f.channels.GetChannelsRequest:
            if type(result) is not t.messages.Chats or type(result.chats) is not list or len(result.chats)!=1:deny('GROUP_SCOPE_DENIED')
            self.validate_group(result.chats[0],leaf.id[0].channel_id)
        elif type(leaf) is f.channels.GetMessagesRequest:
            if type(result) is not t.messages.ChannelMessages or type(result.messages) is not list or len(result.messages)!=1 or getattr(result.messages[0],'id',None)!=leaf.id[0].id:deny('MESSAGE_SCOPE_DENIED')
        self.completed.add(id(request));return result

    def validate_group(self,group,expected):
        t=self.sdk.types
        if type(group) is not t.Channel or type(group.id) is not int or group.id!=expected or group.megagroup is not True or not absent_false(group.broadcast) or not absent_false(group.min)or not absent_false(group.left)or type(group.access_hash) is not int or group.access_hash==0:deny('GROUP_SCOPE_DENIED')
        self.bindings[group.id]=group.access_hash;self.zero_refresh.discard(group.id);self.validated_groups.add(group.id)

    def close(self):
        self.closed=True;self.bindings.clear();self.zero_refresh.clear();self.certified.clear()


def technical_message(sdk,raw,observation):
    """No publication lookup. Output only fields consumed by the typed parser."""
    t=sdk.types;obs=observation.value;origin=obs['original']
    def exact_peer(value,wanted):return type(value) is t.PeerChannel and type(value.channel_id) is int and value.channel_id==peer_id(wanted)
    def unix(value):
        from datetime import datetime
        if type(value) is datetime and value.tzinfo is not None and value.utcoffset() is not None:
            stamp=value.timestamp();return int(stamp) if stamp.is_integer() else None
        return value if type(value) is int else None
    if type(raw) is not t.Message or type(raw.id) is not int or raw.id!=obs['group_message_id'] or not exact_peer(raw.peer_id,obs['group_chat_id']) or not exact_peer(raw.from_id,obs['sender_channel_id']) or unix(raw.date)!=obs['group_date'] or not absent_false(raw.post):deny('MESSAGE_SCOPE_DENIED')
    fwd=raw.fwd_from
    if type(fwd) is not t.MessageFwdHeader or not absent_false(fwd.imported) or not exact_peer(fwd.from_id,origin['chat_id']) or type(fwd.channel_post) is not int or fwd.channel_post!=origin['message_id'] or unix(fwd.date)!=origin['date']:deny('ORIGINAL_SCOPE_DENIED')
    if not exact_peer(fwd.saved_from_peer,obs['sender_channel_id']) or not exact_int(fwd.saved_from_msg_id,1,MAX_MID):deny('IMMEDIATE_SOURCE_UNSUPPORTED')
    def normalized(value):return {'_':'PeerChannel','channel_id':value.channel_id}
    return {'_':'Message','id':raw.id,'date':obs['group_date'],'peer_id':normalized(raw.peer_id),'from_id':normalized(raw.from_id),
        'fwd_from':{'_':'MessageFwdHeader','from_id':normalized(fwd.from_id),'channel_post':fwd.channel_post,'date':origin['date'],'imported':False,'saved_from_peer':normalized(fwd.saved_from_peer),'saved_from_msg_id':fwd.saved_from_msg_id}}


# Concrete existing-auth adapter. None of these functions run on module import.
import asyncio
import importlib.util
import logging
import os
from pathlib import Path
import sqlite3
import stat
import sys
import time
from types import MethodType

MAX_INPUT=32768
MAX_OUTPUT=16384
sys.dont_write_bytecode=True

def unique_object(pairs):
    result={}
    for name,value in pairs:
        if name in result:deny('INVALID_JOB')
        result[name]=value
    return result

@dataclass(frozen=True)
class ReadJob:
    nonce:str
    api_id:int
    api_hash:str=field(repr=False)
    bot_token:str=field(repr=False)
    bot_id:int
    data_dir:Any
    observations:tuple
    timeout:int=20
    @property
    def session_path(self):return self.data_dir/'runtime'/'post-links-bot'/f'bot-{self.bot_id}.session'
    @classmethod
    def parse(cls,value,now=None):
        now=int(time.time())if now is None else now
        keys={'version','nonce','api_id','api_hash','bot_token','expected_bot_id','data_dir','observations','timeout_seconds'}
        if type(value)is not dict or set(value)!=keys or type(value['version'])is not int or value['version']!=2 or not exact_int(now):deny('INVALID_JOB')
        if type(value['nonce'])is not str or re.fullmatch('[a-f0-9]{32}',value['nonce'])is None or not exact_int(value['api_id'],1,MAX_MID)or type(value['api_hash'])is not str or re.fullmatch('[a-fA-F0-9]{32}',value['api_hash'])is None:deny('INVALID_JOB')
        token=value['bot_token'];match=re.fullmatch(r'([1-9][0-9]{5,11}):[A-Za-z0-9_-]{30,200}',token)if type(token)is str else None
        if match is None or not exact_int(value['expected_bot_id'],1,999999999999)or int(match[1])!=value['expected_bot_id']:deny('INVALID_JOB')
        path=value['data_dir']
        if type(path)is not str or len(path)>4096 or not path.startswith('/')or path=='/'or str(PurePosixPath(path))!=path or '//'in path or '\\'in path or any(x in ('.','..')for x in path.split('/'))or any(ord(x)<32 for x in path):deny('UNSAFE_PATH')
        items=value['observations']
        if type(items)is not list or not 1<=len(items)<=MAX_BATCH:deny('INVALID_JOB')
        parsed=[]
        for item in items:
            if type(item)is not dict or set(item)!={'observation','group_username'}:deny('INVALID_JOB')
            observation=GenericObservation.parse(item['observation'],now);username=item['group_username']
            GroupScope(observation.value['group_chat_id'],(observation.value['group_message_id'],),username).validate();parsed.append((observation,username))
        identities=[(o.value['group_chat_id'],o.value['group_message_id'])for o,_ in parsed]
        hints={}
        for o,hint in parsed:
            group=o.value['group_chat_id']
            if group in hints and hints[group]!=hint:deny('INVALID_JOB')
            hints[group]=hint
        if len(set(identities))!=len(identities)or not exact_int(value['timeout_seconds'],5,20):deny('INVALID_JOB')
        return cls(value['nonce'],value['api_id'],value['api_hash'],token,value['expected_bot_id'],Path(path)if os.name=='posix'else PurePosixPath(path),tuple(parsed),value['timeout_seconds'])

def trusted_path(path,uid,*,executable=False):
    path=Path(path);text=str(path)
    if os.name!='posix' or not path.is_absolute()or path.resolve(strict=True)!=path:deny('UNTRUSTED_EXECUTABLE')
    facts=[]
    for p in [Path('/')]+list(reversed(path.parents))[1:]+[path]:
        st=p.lstat();kind='dir'if stat.S_ISDIR(st.st_mode)else('file'if stat.S_ISREG(st.st_mode)else'other')
        facts.append(PathFact(str(p),kind,st.st_uid,stat.S_IMODE(st.st_mode)))
    trusted_chain(text,facts,uid,executable=executable,directory=stat.S_ISDIR(path.lstat().st_mode))
    if not os.access(path,os.R_OK)or(executable and not os.access(path,os.X_OK)):deny('UNTRUSTED_EXECUTABLE')
    return path.lstat()

class SecureSession:
    def __init__(self,job):
        if os.name!='posix' or os.geteuid()==0:deny('WRONG_WORKER_USER')
        self.job=job;self.uid=os.geteuid();self.gid=os.getegid();self.original=None
        self.validate();self.original=self.snapshot()
    def validate(self):
        for p in (self.job.data_dir,self.job.data_dir/'runtime',self.job.session_path.parent):
            trusted_path(p,self.uid);st=p.lstat()
            if not stat.S_ISDIR(st.st_mode)or st.st_uid!=self.uid or st.st_gid!=self.gid:deny('UNSAFE_PATH')
        if stat.S_IMODE(self.job.session_path.parent.lstat().st_mode)!=0o700:deny('UNSAFE_PATH')
        for p in (self.job.session_path,Path(str(self.job.session_path)+'.lock')):
            trusted_path(p,self.uid);st=p.lstat()
            if not stat.S_ISREG(st.st_mode)or st.st_uid!=self.uid or st.st_gid!=self.gid or stat.S_IMODE(st.st_mode)!=0o600:deny('UNSAFE_PATH')
        if self.sidecars():deny('SESSION_SIDECAR_UNSUPPORTED')
    def sidecars(self):
        p=self.job.session_path
        return tuple(str(x)for x in p.parent.iterdir()if x.name.startswith(p.name)and x.name not in (p.name,p.name+'.lock'))
    def snapshot(self):
        self.validate();return session_file_state(self.job.session_path,self.uid,self.gid)
    def assert_unchanged(self):
        if self.original!=self.snapshot():deny('SESSION_PATH_CHANGED')
    def load(self,current_version):
        self.assert_unchanged();result=load_existing_auth(self.job,current_version);self.assert_unchanged();return result

def session_file_state(path,uid,gid):
    """Actual filesystem component, separately testable on synthetic POSIX files."""
    path=Path(path);parent=path.parent;lock_path=Path(str(path)+'.lock')
    if parent.resolve(strict=True)!=parent or parent.is_symlink():deny('UNSAFE_PATH')
    directory=parent.lstat()
    if not stat.S_ISDIR(directory.st_mode)or directory.st_uid!=uid or directory.st_gid!=gid or stat.S_IMODE(directory.st_mode)!=0o700:deny('UNSAFE_PATH')
    states=[]
    for item in (path,lock_path):
        info=item.lstat()
        if item.resolve(strict=True)!=item or not stat.S_ISREG(info.st_mode)or info.st_uid!=uid or info.st_gid!=gid or stat.S_IMODE(info.st_mode)!=0o600:deny('UNSAFE_PATH')
        states.append(info)
    extras=tuple(sorted(x.name for x in parent.iterdir()if x.name.startswith(path.name)and x.name not in (path.name,lock_path.name)))
    if extras:deny('SESSION_SIDECAR_UNSUPPORTED')
    st,lock=states;digest=hashlib.sha256()
    if st.st_size<=0 or st.st_size>256*1024*1024:deny('SESSION_FORMAT_UNSUPPORTED')
    with path.open('rb')as source:
        for data in iter(lambda:source.read(1024*1024),b''):digest.update(data)
    return((st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,stat.S_IMODE(st.st_mode),digest.digest()),(lock.st_dev,lock.st_ino,stat.S_IMODE(lock.st_mode)),extras)

def load_existing_auth(job,current_version):
    """Existing synthetic/real metadata only, opened mode=ro. No SQLiteSession."""
    path=Path(job.session_path)
    if not exact_int(current_version)or not path.is_file()or path.is_symlink():deny('SESSION_FORMAT_UNSUPPORTED')
    if any(Path(str(path)+suffix).exists()or Path(str(path)+suffix).is_symlink()for suffix in ('-wal','-shm','-journal')):deny('SESSION_SIDECAR_UNSUPPORTED')
    connection=None
    try:
        connection=sqlite3.connect(path.as_uri()+'?mode=ro',uri=True,timeout=1)
        connection.execute('PRAGMA query_only=ON');connection.execute('PRAGMA trusted_schema=OFF')
        if connection.execute('PRAGMA journal_mode').fetchone()not in (('delete',),('truncate',),('persist',)):deny('SESSION_SIDECAR_UNSUPPORTED')
        schema=connection.execute("SELECT name,type FROM sqlite_master WHERE name IN ('entities','sessions','version') ORDER BY name").fetchall()
        if schema!=[('entities','table'),('sessions','table'),('version','table')]or connection.execute('SELECT version FROM version LIMIT 2').fetchall()!=[(current_version,)]:deny('SESSION_FORMAT_UNSUPPORTED')
        rows=connection.execute('SELECT dc_id,server_address,port,auth_key FROM sessions LIMIT 2').fetchall()
        if len(rows)!=1:deny('AUTH_REQUIRED')
        auth=ExistingAuth(job.api_id,job.api_hash,*rows[0]);auth.validate()
        groups=sorted({o.value['group_chat_id']for o,_ in job.observations});marks=','.join('?'for _ in groups)
        cached=connection.execute(f'SELECT id,hash FROM entities WHERE id IN ({marks})',tuple(int(x)for x in groups)).fetchall();peers={}
        for identity,access in cached:
            if type(identity)is not int or str(identity)not in groups or not exact_int(access,-(1<<63),(1<<63)-1):deny('SESSION_FORMAT_UNSUPPORTED')
            if access!=0:peers[peer_id(str(identity))]=access
        return auth,peers
    except sqlite3.Error:deny('SESSION_FORMAT_UNSUPPORTED')
    finally:
        if connection is not None:connection.close()

def load_shared_importer():
    path=Path(__file__).with_name('import_post_link_telegram.py');trusted_path(path,os.geteuid())
    spec=importlib.util.spec_from_file_location('gitposted_comments_shared_importer',path);module=importlib.util.module_from_spec(spec);sys.modules[spec.name]=module;spec.loader.exec_module(module)
    if not callable(getattr(module,'session_lock',None)):deny('DEPENDENCY_UNAVAILABLE')
    return module

def load_sdk(job):
    """-S prevents sitecustomize/.pth execution; add only checked fixed venv code."""
    if not sys.flags.isolated or not sys.flags.no_site or not sys.dont_write_bytecode:deny('ISOLATED_INTERPRETER_REQUIRED')
    uid=os.geteuid();venv=job.data_dir/'runtime'/'post-links-venv';python=venv/'bin'/'python3'
    if Path(sys.executable)!=python:deny('INTERPRETER_MISMATCH')
    for p,execute in ((python,True),(Path(__file__),False),(venv/'pyvenv.cfg',False)):trusted_path(p,uid,executable=execute)
    site=venv/'lib'/f'python{sys.version_info.major}.{sys.version_info.minor}'/'site-packages';trusted_path(site,uid)
    for package in ('telethon','pyaes','rsa','pyasn1'):
        root=site/package;trusted_path(root,uid);count=0
        for folder,dirs,files in os.walk(root,followlinks=False):
            for leaf in dirs+files:
                p=Path(folder)/leaf;st=p.lstat();count+=1
                if count>2500 or stat.S_ISLNK(st.st_mode)or st.st_uid not in (0,uid)or stat.S_IMODE(st.st_mode)&0o022 or not(stat.S_ISREG(st.st_mode)or stat.S_ISDIR(st.st_mode)):deny('UNTRUSTED_EXECUTABLE')
    if any(x=='telethon'or x.startswith('telethon.')for x in sys.modules):deny('SDK_ALREADY_IMPORTED')
    sys.path.insert(0,str(site));sys.modules['cryptg']=None;sys.modules['socks']=None;sys.modules['python_socks']=None
    # Telethon's optional libssl discovery invokes ldconfig/compiler subprocesses
    # on Linux. Use its existing reviewed pyaes fallback, not external discovery.
    import ctypes.util
    find_library=ctypes.util.find_library
    try:
        ctypes.util.find_library=lambda name:None
        import telethon as sdk
    finally:ctypes.util.find_library=find_library
    if sdk.__version__!=SDK_VERSION or Path(sdk.__file__).parent!=site/'telethon':deny('DEPENDENCY_UNAVAILABLE')
    return sdk

def make_bot_client(auth,sdk,gate):
    """Concrete no-update lifecycle, guarded before connect and internal queueing."""
    from telethon.sessions import MemorySession
    from telethon.crypto import AuthKey
    auth.validate();memory=MemorySession();memory.set_dc(auth.dc_id,auth.server_address,auth.port);memory.auth_key=AuthKey(auth.auth_key);expected_key=hashlib.sha256(auth.auth_key).digest()
    class ExistingBotClient(sdk.TelegramClient):
        async def get_me(self,input_peer=False):
            user=getattr(self,'_comments_verified_self',None)
            if user is None:
                users=await self._comments_rpc(sdk.functions.users.GetUsersRequest([sdk.types.InputUserSelf()]))
                user=users[0];self._comments_verified_self=user
            return sdk.types.InputPeerSelf()if input_peer else user
        async def _on_login(self,user):
            if user is not getattr(self,'_comments_verified_self',None)or not gate.identity or user.bot is not True or user.id!=gate.bot_id:deny('BOT_IDENTITY_MISMATCH')
            self._mb_entity_cache.set_self_user(user.id,True,user.access_hash);self._authorized=True;return user
        async def is_user_authorized(self):return gate.identity and self._authorized is True
        async def _update_loop(self):
            while self.is_connected():await asyncio.sleep(.2)
        async def _keepalive_loop(self):
            while self.is_connected():await asyncio.sleep(.2)
        async def _save_states_and_entities(self):return None
        async def _switch_dc(self,*a,**k):deny('DC_CHANGE_DENIED')
        async def _create_exported_sender(self,*a,**k):deny('EXPORTED_SENDER_DENIED')
        async def _borrow_exported_sender(self,*a,**k):deny('EXPORTED_SENDER_DENIED')
        async def sign_in(self,*a,**k):deny('AUTH_REQUIRED')
        async def start(self,*a,**k):deny('AUTH_REQUIRED')
        async def send_code_request(self,*a,**k):deny('AUTH_REQUIRED')
        async def log_out(self,*a,**k):deny('AUTH_REQUIRED')
        async def qr_login(self,*a,**k):deny('AUTH_REQUIRED')
        def set_proxy(self,*a,**k):deny('PROXY_DENIED')
        async def _comments_rpc(self,request):return await self._sender.send(sdk.functions.InvokeWithoutUpdatesRequest(request))
    client=ExistingBotClient(memory,auth.api_id,auth.api_hash,receive_updates=False,catch_up=False,auto_reconnect=False,connection_retries=0,request_retries=0,retry_delay=0,flood_sleep_threshold=0,raise_last_call_error=True,timeout=5,use_ipv6=':'in auth.server_address)
    client._sender._updates_queue=None;original_send=client._sender.send
    def checked_send(sender,request,ordered=False):
        key=sender.auth_key.key if sender.auth_key else None
        if type(key)is not bytes or hashlib.sha256(key).digest()!=expected_key:deny('AUTH_KEY_CHANGED')
        gate.before_send(request);future=original_send(request,ordered=ordered)
        async def checked_result():return gate.after_response(request,await future)
        return asyncio.ensure_future(checked_result())
    async def no_new_key(sender,*a,**k):deny('AUTH_REQUIRED')
    client._sender.send=MethodType(checked_send,client._sender);client._sender._try_gen_auth_key=MethodType(no_new_key,client._sender)
    queue=client._sender._send_queue;original_append,original_extend=queue.append,queue.extend
    def append(state):gate.before_queue(state);return original_append(state)
    def extend(states):
        states=list(states)
        for state in states:gate.before_queue(state)
        return original_extend(states)
    queue.append,queue.extend=append,extend
    client._comments_gate=gate;client._comments_expected_key=expected_key
    return client

async def read_job(job,sdk,auth,cached,client_factory=make_bot_client):
    grouped={}
    for observation,hint in job.observations:
        group=observation.value['group_chat_id'];grouped.setdefault(group,{'mids':[],'hint':hint})['mids'].append(observation.value['group_message_id'])
    scopes=tuple(GroupScope(group,tuple(v['mids']),v['hint'])for group,v in grouped.items());gate=BotReadGate(sdk,job.api_id,job.bot_id,scopes);client=client_factory(auth,sdk,gate)
    try:
        if client.session.dc_id!=auth.dc_id or client.session.server_address!=auth.server_address or client.session.port!=auth.port:deny('AUTH_KEY_CHANGED')
        await client.connect()
        if not await client.is_user_authorized():deny('BOT_IDENTITY_MISMATCH')
        me=await client.get_me()
        if type(me)is not sdk.types.User or me.bot is not True or me.id!=job.bot_id:deny('BOT_IDENTITY_MISMATCH')
        for group,details in grouped.items():
            raw=peer_id(group)
            if details['hint']is not None:await client._comments_rpc(sdk.functions.contacts.ResolveUsernameRequest(details['hint']))
            else:
                if raw in cached:gate.cache_group(raw,cached[raw]);access=cached[raw]
                else:gate.permit_bot_zero_refresh(raw);access=0
                await client._comments_rpc(sdk.functions.channels.GetChannelsRequest([sdk.types.InputChannel(raw,access)]))
        results=[]
        for observation,_ in job.observations:
            obs=observation.value
            if int(time.time())>=obs['expires_at']:deny('OBSERVATION_EXPIRED')
            raw=peer_id(obs['group_chat_id']);response=await client._comments_rpc(sdk.functions.channels.GetMessagesRequest(sdk.types.InputChannel(raw,gate.bindings[raw]),[sdk.types.InputMessageID(obs['group_message_id'])]));checked=int(time.time())
            item={'binding':obs['binding'],'status':'unsupported','reason':'discussion_origin_unproven','checked_at':checked}
            try:item['raw_message']=technical_message(sdk,response.messages[0],observation);item['status']=item['reason']='verified'
            except ReadDenied as error:
                if error.code not in ('MESSAGE_SCOPE_DENIED','ORIGINAL_SCOPE_DENIED','IMMEDIATE_SOURCE_UNSUPPORTED'):raise
            results.append(item)
        return{'version':2,'ok':True,'nonce':job.nonce,'bot_id':job.bot_id,'results':results}
    finally:
        try:await asyncio.wait_for(client.disconnect(),2)
        except Exception:pass
        finally:
            gate.close()
            key=getattr(getattr(client,'_sender',None),'auth_key',None)
            if key is not None:key.key=None
            client.session.auth_key=None

def execute(job,loader,*,context_factory=SecureSession,sdk_loader=load_sdk,client_factory=make_bot_client):
    context=context_factory(job)
    # Exactly the existing session lock/inode. No second lock or persistent Session is created.
    with loader.session_lock(job):
        context.assert_unchanged();sdk=sdk_loader(job)
        from telethon.sessions.sqlite import CURRENT_VERSION
        auth,cached=context.load(CURRENT_VERSION)
        try:return asyncio.run(asyncio.wait_for(read_job(job,sdk,auth,cached,client_factory),job.timeout))
        finally:context.assert_unchanged()

def safe_error(error,job=None):
    allowed={'INVALID_JOB','INVALID_OBSERVATION','UNSAFE_PATH','UNTRUSTED_EXECUTABLE','WRONG_WORKER_USER','SESSION_PATH_CHANGED','SESSION_FORMAT_UNSUPPORTED','SESSION_SIDECAR_UNSUPPORTED','AUTH_REQUIRED','BOT_IDENTITY_MISMATCH','PRIVATE_PEER_UNCACHED','DEPENDENCY_UNAVAILABLE','OBSERVATION_EXPIRED','SESSION_BUSY','ISOLATED_INTERPRETER_REQUIRED','INTERPRETER_MISMATCH'}
    code=getattr(error,'code',None);result={'version':2,'ok':False,'code':code if code in allowed else'READ_UNAVAILABLE'}
    if type(error).__name__=='FloodWaitError':
        retry=getattr(error,'seconds',None);result.update(code='RATE_LIMITED',retry_after=retry if exact_int(retry,1,3600) else 300)
    if job is not None:result.update(nonce=job.nonce,bot_id=job.bot_id)
    return result

def main():
    logging.disable(logging.CRITICAL);job=None
    try:
        if len(sys.argv)!=1 or os.name!='posix'or os.geteuid()==0:deny('WRONG_WORKER_USER')
        if not sys.flags.isolated or not sys.flags.no_site or not sys.dont_write_bytecode:deny('ISOLATED_INTERPRETER_REQUIRED')
        trusted_path(Path(__file__),os.geteuid());trusted_path(Path(sys.executable),os.geteuid(),executable=True)
        raw=sys.stdin.buffer.read(MAX_INPUT+1)
        if not raw or len(raw)>MAX_INPUT:deny('INVALID_JOB')
        job=ReadJob.parse(json.loads(raw,object_pairs_hook=unique_object));result=execute(job,load_shared_importer())
    except BaseException as error:result=safe_error(error,job)
    output=canonical(result)
    if len(output)>MAX_OUTPUT:result={'version':2,'ok':False,'code':'READ_UNAVAILABLE'};output=canonical(result)
    sys.stdout.buffer.write(output+b'\n');return 0 if result.get('ok') is True else 1

if __name__=='__main__':raise SystemExit(main())
