<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Fixed existing-bot reader for typed technical discussion-source evidence. */
final class PostedTelegramCommentsReaderProviderV3
{
    private const WORKER='/tools/read_discussion_source_telegram_v3.py';
    private const MAX_OUTPUT=16384;
    private array $hints=[];
    public function __construct(private readonly PDO $pdo,private readonly array $config,array $verifiedGroupUsernames=[])
    {
        if(count($verifiedGroupUsernames)>5)throw new InvalidArgumentException('comments_reader_too_many_hints');
        foreach($verifiedGroupUsernames as$chat=>$username){
            $chat=(string)$chat;
            if(preg_match('/\A-1[0-9]{12}\z/D',$chat)!==1||!is_string($username)||preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/D',$username)!==1)throw new InvalidArgumentException('comments_reader_invalid_hint');
            $this->hints[$chat]=$username;
        }
    }
    /** Every ancestor is checked with lstat; open_basedir denial is a safe unavailable result. */
    private static function trustedFile(string $path,int $uid,bool $executable=false): bool
    {
        if($uid<=0||$path===''||!str_starts_with($path,'/')||str_contains($path,'\\')||str_contains($path,'//')||preg_match('/[\x00-\x1f\x7f]/',$path)===1||realpath($path)!==$path)return false;
        $parts=explode('/',substr($path,1));$current='';$paths=['/'];
        foreach($parts as$part){if($part===''||$part==='.'||$part==='..')return false;$current.='/'.$part;$paths[]=$current;}
        foreach($paths as$i=>$p){
            $st=@lstat($p);if(!is_array($st)||!in_array($st['uid'],[0,$uid],true)||($st['mode']&0022)!==0)return false;
            $kind=$st['mode']&0170000;if($kind!==($i===count($paths)-1?0100000:0040000))return false;
        }
        return is_readable($path)&&(!$executable||is_executable($path));
    }
    private static function restrictedWeb(?string $sapi=null,?string $basedir=null): bool
    {
        return($sapi??PHP_SAPI)!=='cli'&&trim($basedir??(string)ini_get('open_basedir'))!=='';
    }
    /** Fixed OS PHP only. Paths are internal fixed-layout values, never JSON options. */
    private static function cliCommand(string $python,string $worker,?string $nonce=null): array
    {
        if($nonce!==null&&preg_match('/\A[a-f0-9]{32}\z/D',$nonce)!==1)throw new InvalidArgumentException('invalid_cli_probe_nonce');
        $command=['/usr/bin/php','-r',self::cliBootstrap(),'--',$python,$worker,dirname($worker).'/import_post_link_telegram.py',dirname(dirname($python)).'/pyvenv.cfg'];
        if($nonce!==null)$command[]=$nonce;return$command;
    }
    /** The bootstrap never reads FD0. On success exec replaces this SAME process. */
    private static function cliBootstrap(): string
    {
        return <<<'GITPOSTED_COMMENTS_CLI'
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
function gpCommentsCliFail(): never{fwrite(STDOUT,'{"code":"READ_UNAVAILABLE","ok":false,"version":2}'."\n");exit(1);}
function gpCommentsCliTrusted(string $path,int $uid,bool $executable=false): bool
{
    if($uid<=0||$path===''||!str_starts_with($path,'/')||str_contains($path,'\\')||str_contains($path,'//')||preg_match('/[\x00-\x1f\x7f]/',$path)===1||realpath($path)!==$path)return false;
    $parts=explode('/',substr($path,1));$current='';$paths=['/'];
    foreach($parts as$part){if($part===''||$part==='.'||$part==='..')return false;$current.='/'.$part;$paths[]=$current;}
    foreach($paths as$i=>$p){
        $st=@lstat($p);if(!is_array($st)||!in_array($st['uid'],[0,$uid],true)||($st['mode']&0022)!==0)return false;
        $kind=$st['mode']&0170000;if($kind!==($i===count($paths)-1?0100000:0040000))return false;
    }
    return is_readable($path)&&(!$executable||is_executable($path));
}
try{
    if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||!function_exists('pcntl_exec')||($uid=posix_geteuid())<=0||!in_array($argc,[5,6],true))gpCommentsCliFail();
    $system=realpath('/usr/bin/php');if(!is_string($system)||PHP_BINARY!==$system||!gpCommentsCliTrusted($system,$uid,true)||(@lstat($system)['uid']??null)!==0)gpCommentsCliFail();
    [$unused,$python,$worker,$importer,$venvConfig]=array_slice($argv,0,5);
    $root=dirname(dirname($worker));$provider=$root.'/telegram-comments-reader.php';
    if($worker!==$root.'/tools/read_discussion_source_telegram_v3.py'||$importer!==$root.'/tools/import_post_link_telegram.py'||preg_match('~\A(.+)/runtime/post-links-venv/bin/python3\z~D',$python,$match)!==1||$venvConfig!==$match[1].'/runtime/post-links-venv/pyvenv.cfg')gpCommentsCliFail();
    foreach([[$python,true],[$worker,false],[$importer,false],[$venvConfig,false],[$provider,false]]as[$path,$execute])if(!gpCommentsCliTrusted($path,$uid,$execute))gpCommentsCliFail();
    if($argc===6){
        $nonce=$argv[5];if(preg_match('/\A[a-f0-9]{32}\z/D',$nonce)!==1)gpCommentsCliFail();
        $digest=hash('sha256',json_encode([$python,$worker,$importer,$venvConfig,$provider],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        fwrite(STDOUT,json_encode(['mode'=>'preflight','nonce'=>$nonce,'ok'=>true,'paths_sha256'=>$digest,'uid'=>$uid,'version'=>1],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");exit(0);
    }
    pcntl_exec($python,['-I','-S','-B',$worker],['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8']);
}catch(Throwable){}
gpCommentsCliFail();
GITPOSTED_COMMENTS_CLI;
    }
    private static function cliCapabilityProof(string $output,int $exit,int $stderrBytes,string $nonce,int $uid,string $digest): bool
    {
        if($exit!==0||$stderrBytes!==0||strlen($output)>1024||$uid<=0||preg_match('/\A[a-f0-9]{32}\z/D',$nonce)!==1||preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1)return false;
        $expected=['mode'=>'preflight','nonce'=>$nonce,'ok'=>true,'paths_sha256'=>$digest,'uid'=>$uid,'version'=>1];
        return trim($output)===json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    /** Capability validates paths only. Actual exec repeats every check; no auth/session/API. */
    private function cliPreflight(string $python,string $worker,string $importer,string $venvConfig,int $uid): bool
    {
        if($this->pdo->inTransaction())return false;
        $nonce=bin2hex(random_bytes(16));$digest=hash('sha256',json_encode([$python,$worker,$importer,$venvConfig,__FILE__],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $result=$this->process(self::cliCommand($python,$worker,$nonce),'',5.0);
        return$result!==null&&self::cliCapabilityProof($result['stdout'],$result['exit_code'],$result['stderr_bytes'],$nonce,$uid,$digest);
    }
    private function inputs(int $expectedBotId): ?array
    {
        if(!function_exists('posix_geteuid')||($uid=posix_geteuid())<=0)return null;
        $token=$this->config['bot_token']??null;$mtproto=$this->config['mtproto']??null;$data=$this->config['data_dir']??null;$apiId=is_array($mtproto)?($mtproto['api_id']??null):null;
        if(is_string($apiId)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$apiId)===1)$apiId=(int)$apiId;
        if(!is_string($token)||preg_match('/\A([1-9][0-9]{5,11}):[A-Za-z0-9_-]{30,200}\z/D',$token,$match)!==1||(string)$expectedBotId!==$match[1]
            ||!is_array($mtproto)||!is_int($apiId)||$apiId<1||$apiId>2147483647||!is_string($mtproto['api_hash']??null)||preg_match('/\A[a-fA-F0-9]{32}\z/D',$mtproto['api_hash'])!==1
            ||!is_string($data)||$data==='/'||!str_starts_with($data,'/')||realpath($data)!==$data||!is_dir($data)||is_link($data))return null;
        $python=$data.'/runtime/post-links-venv/bin/python3';$worker=__DIR__.self::WORKER;$importer=__DIR__.'/tools/import_post_link_telegram.py';$venvConfig=$data.'/runtime/post-links-venv/pyvenv.cfg';
        if(self::restrictedWeb()){if(!$this->cliPreflight($python,$worker,$importer,$venvConfig,$uid))return null;}
        else foreach([[$python,true],[$worker,false],[$importer,false],[$venvConfig,false],[__FILE__,false]]as[$path,$execute])if(!self::trustedFile($path,$uid,$execute))return null;
        return['api_id'=>$apiId,'api_hash'=>$mtproto['api_hash'],'bot_token'=>$token,'expected_bot_id'=>$expectedBotId,'data_dir'=>$data,'python'=>$python,'worker'=>$worker];
    }
    public function available(int $expectedBotId): bool{return $this->inputs($expectedBotId)!==null;}
    public function read(array $observation,int $expectedBotId,int $now): ?PostedTelegramDiscussionSourceProofPrototype05
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_reader_outside_sql_required');
        PostedTelegramCommentsPrototype05::validateObservation($observation);
        if($now<1||$now>=$observation['expires_at']||$observation['received_at']>$now+30)return null;
        $input=$this->inputs($expectedBotId);if($input===null)return null;
        $nonce=bin2hex(random_bytes(16));$started=time();$python=$input['python'];$worker=$input['worker'];unset($input['python'],$input['worker']);
        $job=['version'=>2,'nonce'=>$nonce]+$input+['observations'=>[['observation'=>$observation,'group_username'=>$this->hints[$observation['group_chat_id']]??null]],'timeout_seconds'=>20];
        $body=json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);unset($job,$input);
        if(strlen($body)>32768)return null;$result=$this->run($python,$worker,$body);unset($body);
        return$result===null?null:$this->proof($result['stdout'],$result['exit_code'],$observation,$expectedBotId,$nonce,$started,time());
    }
    private function run(string $python,string $worker,string $input): ?array
    {
        $command=self::restrictedWeb()?self::cliCommand($python,$worker):[$python,'-I','-S','-B',$worker];
        return$this->process($command,$input,30.0);
    }
    private function process(array $command,string $input,float $seconds): ?array
    {
        if(!function_exists('proc_open'))return null;$pipes=[];
        $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'],['bypass_shell'=>true]);
        if(!is_resource($process))return null;$stdout='';$stderrBytes=0;$offset=0;$deadline=microtime(true)+$seconds;$ok=true;$inputOpen=true;$exit=-1;
        try{
            foreach($pipes as$pipe)stream_set_blocking($pipe,false);
            while(true){
                if(microtime(true)>=$deadline){$ok=false;break;}
                if($inputOpen){$written=@fwrite($pipes[0],substr($input,$offset,4096));if($written===false){$ok=false;break;}$offset+=$written;if($offset===strlen($input)){fclose($pipes[0]);$inputOpen=false;unset($input);}}
                $data=stream_get_contents($pipes[1]);if($data!==false)$stdout.=$data;
                $errors=stream_get_contents($pipes[2]);if($errors!==false)$stderrBytes+=strlen($errors);unset($errors);
                if(strlen($stdout)>self::MAX_OUTPUT||$stderrBytes>self::MAX_OUTPUT){$ok=false;break;}
                $status=proc_get_status($process);if(!$status['running']){$exit=(int)($status['exitcode']??-1);break;}usleep(10000);
            }
            if(!$ok){
                @proc_terminate($process);$until=microtime(true)+1.0;
                do{$status=proc_get_status($process);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$until);
                if($status['running'])@proc_terminate($process,9);
            }
            $data=stream_get_contents($pipes[1]);if($data!==false)$stdout.=$data;
            $errors=stream_get_contents($pipes[2]);if($errors!==false)$stderrBytes+=strlen($errors);unset($errors);
            return$ok&&in_array($exit,[0,1],true)&&strlen($stdout)<=self::MAX_OUTPUT&&$stderrBytes<=self::MAX_OUTPUT?['stdout'=>$stdout,'exit_code'=>$exit,'stderr_bytes'=>$stderrBytes]:null;
        }finally{
            foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);@proc_close($process);
        }
    }
    private function proof(string $output,int $exitCode,array $observation,int $botId,string $nonce,int $started,int $finished): ?PostedTelegramDiscussionSourceProofPrototype05
    {
        try{
            if(strlen($output)>self::MAX_OUTPUT||$finished<$started||$finished-$started>30)return null;
            $r=json_decode($output,true,24,JSON_THROW_ON_ERROR);if(!is_array($r)||trim($output)!==json_encode(PostedTelegramCommentsPrototype05::canonical($r),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))return null;
            if(($r['version']??null)!==2||($r['nonce']??null)!==$nonce||($r['bot_id']??null)!==$botId)return null;
            if($exitCode===1&&($r['ok']??null)===false){
                $keys=array_keys($r);sort($keys);
                if(($r['code']??null)==='RATE_LIMITED'&&$keys===['bot_id','code','nonce','ok','retry_after','version']&&is_int($r['retry_after'])&&$r['retry_after']>=1&&$r['retry_after']<=3600)throw new PostedTelegramCommentsHoldoffPrototype05('discussion_rate_limited',$r['retry_after']);
                return null;
            }
            $keys=array_keys($r);sort($keys);if($exitCode!==0||$keys!==['bot_id','nonce','ok','results','version']||$r['ok']!==true||!is_array($r['results'])||!array_is_list($r['results'])||count($r['results'])!==1)return null;
            $item=$r['results'][0];if(!is_array($item)||($item['binding']??null)!==$observation['binding']||($item['status']??null)!=='verified'||($item['reason']??null)!=='verified'||!is_int($item['checked_at']??null)||$item['checked_at']<$started||$item['checked_at']>$finished||$finished-$item['checked_at']>=PostedTelegramCommentsPrototype05::EVIDENCE_TTL||!is_array($item['raw_message']??null))return null;
            $keys=array_keys($item);sort($keys);if($keys!==['binding','checked_at','raw_message','reason','status']||!self::technicalShape($item['raw_message']))return null;
            return PostedTelegramDiscussionSourceProofPrototype05::fromRawChannelMessage($observation,$botId,$item['raw_message'],$item['checked_at']);
        }catch(PostedTelegramCommentsHoldoffPrototype05 $e){throw$e;}catch(Throwable){return null;}
    }
    /** Reject even unused body/media/access-hash fields at this process boundary. */
    private static function technicalShape(array $raw): bool
    {
        $keys=array_keys($raw);sort($keys);if($keys!==['_','date','from_id','fwd_from','id','peer_id'])return false;
        $forward=$raw['fwd_from']??null;if(!is_array($forward))return false;
        $keys=array_keys($forward);sort($keys);if($keys!==['_','channel_post','date','from_id','imported','saved_from_msg_id','saved_from_peer']||$forward['imported']!==false)return false;
        foreach([$raw['peer_id'],$raw['from_id'],$forward['from_id'],$forward['saved_from_peer']]as$peer){
            if(!is_array($peer))return false;$keys=array_keys($peer);sort($keys);
            if($keys!==['_','channel_id']||$peer['_']!=='PeerChannel'||!is_int($peer['channel_id'])||$peer['channel_id']<1||$peer['channel_id']>1000000000000)return false;
        }
        return true;
    }
}

/** Integration stays explicit: no observer, cron or deletion starts on require. */
trait PostedTelegramCommentsReaderHooksV3
{
    private function telegramCommentsMaintenanceV3(int $limit=PostedTelegramCommentsPrototype05::MAX_BATCH): array
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_transport_outside_transaction_required');
        $store=$this->telegramCommentsStorePrototype05();$now=time();
        if($store->due($now,$limit)===[])return['observed'=>0,'deleted'=>0,'deferred'=>0];
        $botId=$this->telegramBotId();if($botId<=0)return['blocked'=>'bot_identity_unverified'];
        $reader=new PostedTelegramCommentsReaderProviderV3($this->pdo,$this->config);
        if(!$reader->available($botId))return['blocked'=>'discussion_reader_unavailable'];
        return(new PostedTelegramCommentsProcessorPrototype05($store,PostedTelegramCommentsProcessorPrototype05::botApiAdapter($this->telegram),$botId,static fn():int=>time(),
            static fn(array $observation,int $expectedBot,int $checkedAt):?PostedTelegramDiscussionSourceProofPrototype05=>$reader->read($observation,$expectedBot,$checkedAt)))->run($limit);
    }
}
