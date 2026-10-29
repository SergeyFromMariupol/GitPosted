<?php
declare(strict_types=1);

/** Public offline fixtures: no config, native PDO connection or network. */
if (PHP_SAPI!=='cli'||function_exists('curl_exec')) {
    throw new RuntimeException('Use php -d disable_functions=curl_exec tests/telegram-runtime-test.php');
}
require_once dirname(__DIR__).'/payload/src/telegram-account-transfer.php';
require_once dirname(__DIR__).'/payload/src/telegram-policy.php';

function releaseTelegramAssert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

// Extract actual packaged methods. Their logic is unchanged; only their
// surrounding dependencies are replaced with bounded, in-memory fixtures.
function releaseTelegramMethod(string $source,string $name): string
{
    $tokens=token_get_all($source);$found=[];
    for($i=0,$count=count($tokens);$i<$count;$i++) {
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $next=$i+1;
        while(isset($tokens[$next])&&is_array($tokens[$next])&&$tokens[$next][0]===T_WHITESPACE)$next++;
        if(!is_array($tokens[$next]??null)||$tokens[$next][0]!==T_STRING||$tokens[$next][1]!==$name)continue;
        $code='';$depth=0;$opened=false;
        for($j=$i;$j<$count;$j++) {
            $token=$tokens[$j];$code.=is_array($token)?$token[1]:$token;
            if($token==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))) {$depth++;$opened=true;}
            elseif($token==='}')$depth--;
            if($opened&&$depth===0)break;
        }
        $found[]=$code;
    }
    releaseTelegramAssert(count($found)===1,'Expected one packaged method: '.$name);
    return $found[0];
}

final class ReleaseTelegramStore extends PDO
{
    public const ACTOR=20001;
    public bool $active=true;
    public ?string $state='await_channel_ref';
    public array $prompt=['input_token'=>'aaaaaaaaaaaaaaaaaaaaaaaa'];
    public ?array $channel=null;
    public array $grants=[];
    public bool $graphLease=false;
    private bool $transaction=false;
    private ?array $before=null;

    public function __construct() {} // Native PDO constructor is intentionally never called.
    public function prepare(string $sql,array $options=[]): PDOStatement|false {return new ReleaseTelegramStatement($this,$sql);}
    public function beginTransaction(): bool {
        releaseTelegramAssert(!$this->transaction,'Nested fixture transaction.');
        $this->before=[$this->state,$this->prompt,$this->channel,$this->grants];$this->transaction=true;return true;
    }
    public function commit(): bool {$this->transaction=false;$this->before=null;return true;}
    public function rollBack(): bool {
        if($this->before!==null)[$this->state,$this->prompt,$this->channel,$this->grants]=$this->before;
        $this->transaction=false;$this->before=null;return true;
    }
    public function inTransaction(): bool {return $this->transaction;}
    public function snapshot(): array {return [$this->active,$this->state,$this->prompt,$this->channel,$this->grants,$this->transaction,$this->graphLease];}
    public function current(array $params): bool {
        return ($params['user']??null)===self::ACTOR&&$this->active&&$this->state==='await_channel_ref'
            &&json_decode((string)($params['data']??''),true)===$this->prompt;
    }
}

final class ReleaseTelegramStatement extends PDOStatement
{
    private mixed $answer=false;
    private int $affected=0;
    public function __construct(private ReleaseTelegramStore $store,private string $sql) {}
    public function execute(?array $params=null): bool {
        $params??=[];
        $query=preg_replace('/\s+/',' ',trim($this->sql));$this->affected=0;
        if($query==='SELECT GET_LOCK(?,0)') {
            releaseTelegramAssert($params===['gitposted_telegram_rooted_graph']&&!$this->store->inTransaction()&&!$this->store->graphLease,'Unexpected graph lease acquisition.');$this->store->graphLease=true;$this->answer=1;
        } elseif($query==='SELECT RELEASE_LOCK(?)') {
            releaseTelegramAssert($params===['gitposted_telegram_rooted_graph']&&!$this->store->inTransaction()&&$this->store->graphLease,'Graph lease released during transaction.');$this->store->graphLease=false;$this->answer=1;
        } elseif(str_starts_with($query,'SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1')) {
            $this->answer=$this->store->current($params)?ReleaseTelegramStore::ACTOR:false;
        } elseif(str_starts_with($query,'INSERT INTO posted_channels ')) {
            releaseTelegramAssert($this->store->inTransaction()&&$this->store->channel===null,'Unexpected registration write.');
            $this->store->channel=['id'=>7,'chat_id'=>$params['chat_id'],'added_by'=>$params['added_by'],'is_active'=>1,
                'title'=>$params['title'],'username'=>$params['username'],'bot_status'=>$params['bot_status'],
                'bot_can_post'=>$params['can_post'],'bot_can_edit'=>$params['can_edit'],'bot_can_delete'=>$params['can_delete']];
            $this->affected=1;
        } elseif(str_starts_with($query,'UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user')) {
            releaseTelegramAssert($this->store->inTransaction(),'Prompt consumption escaped its transaction.');
            if($this->store->current($params)) {$this->store->state=null;$this->affected=1;}
        } else throw new RuntimeException('Unexpected offline SQL operation.');
        return true;
    }
    public function fetchColumn(int $column=0): mixed {return $this->answer;}
    public function rowCount(): int {return $this->affected;}
}

$bootstrap=(string)file_get_contents(dirname(__DIR__).'/payload/src/bootstrap.php');
$methods='';
foreach(['telegramChannelRegistrationId','telegramChannelRegistrationReference','telegramChannelRegistrationUsernameMatches',
    'forwardedChannelOrigin','normalizeTelegramDeleteTimerMinutes','telegramDeleteTimerLabel','telegramDeleteTimerHint',
    'telegramDeleteTimerKeyboard','minutesLabel','registerChannelFromMessage'] as $method) {
    $methods.=releaseTelegramMethod($bootstrap,$method)."\n";
}
$sendMessage=releaseTelegramMethod($bootstrap,'sendMessage');
$methods.=releaseTelegramMethod((string)file_get_contents(dirname(__DIR__).'/payload/src/telegram-subscription-ui.php'),'telegramSubscriptionPaid')."\n";
eval(<<<'PHP'
namespace GitPostedReleaseFixture;
use \RuntimeException;
use \Throwable;
use \PDO;
use \InvalidArgumentException;
use \PostedTelegramPolicy;
use \PostedTelegramRootedAccess;
final class Transport
{
    public array $calls=[];
    public string $mode='';
    public ?\Closure $beforeActorRead=null;
    public array $chat=['id'=>-1001234567890,'type'=>'channel','title'=>'Example channel','username'=>'example_channel'];
    public function __construct(private \ReleaseTelegramStore $store) {}
    public function call(string $method,array $body=[]): array {
        \releaseTelegramAssert(!$this->store->inTransaction(),'Platform API reached a fixture transaction.');
        $this->calls[]=[$method,$body];
        if($method==='getChat')return ['ok'=>true,'result'=>$this->chat];
        if($method==='getMe')return ['ok'=>true,'result'=>['id'=>30003,'is_bot'=>true]];
        if($method==='getChatMember') {
            $id=$body['user_id'];$bot=$id===30003;
            if(!$bot&&$this->beforeActorRead!==null)($this->beforeActorRead)();
            $result=['user'=>['id'=>$id,'is_bot'=>$bot],'status'=>'administrator',
                'can_post_messages'=>true,'can_edit_messages'=>true,'can_delete_messages'=>true];
            if($bot&&$this->mode==='bot_member')$result['status']='member';
            if($bot&&$this->mode==='bot_post_string')$result['can_post_messages']='true';
            if($bot&&$this->mode==='bot_wrong_identity')$result['user']['id']=30004;
            if(!$bot&&$this->mode==='actor_member')$result['status']='member';
            if(!$bot&&$this->mode==='actor_bot')$result['user']['is_bot']=true;
            return ['ok'=>true,'result'=>$result];
        }
        \releaseTelegramAssert($method==='sendMessage'&&($body['chat_id']??'')==='20001','Unexpected offline transport write.');
        return ['ok'=>true,'result'=>['message_id'=>1]];
    }
PHP
.$sendMessage."\n}\n".<<<'PHP'
final class Bot
{
    private const TELEGRAM_MAX_AUTO_DELETE_MINUTES=2875;
    private const TELEGRAM_MAX_DELETE_TIMER_MINUTES=525600;
    public function __construct(private \ReleaseTelegramStore $pdo,private Transport $telegram,private array $config=[]) {}
    private function encodeJson(mixed $value): string {return json_encode($value,JSON_THROW_ON_ERROR);}
    private function channelByChatId(string $id): ?array {return ($this->pdo->channel['chat_id']??null)===$id?$this->pdo->channel:null;}
    private function lockActiveTelegramActor(int $id): bool {
        \releaseTelegramAssert($this->pdo->inTransaction()&&$id===20001,'Unexpected actor lock.');return $this->pdo->active;
    }
    private function clearTelegramChannelTransferReceipt(int $id): void {\releaseTelegramAssert($id===7,'Unexpected binding clear.');}
    private function grant(int $actor,int $channel,bool $post,bool $schedule,bool $manage,bool $admins,int $grantor): void {
        \releaseTelegramAssert($this->pdo->inTransaction()&&$actor===20001&&$channel===7&&$grantor===$actor,'Unexpected grant target.');
        $this->pdo->grants[]=[$actor,$channel,$post,$schedule,$manage,$admins];
    }
    private function telegramTransferredChannelOwnerReceipt(array $channel): ?array {throw new RuntimeException('Existing ownership is outside this new-channel fixture.');}
    private function telegramChannelAdministratorStatus(string $chat,int $actor): ?bool {throw new RuntimeException('Unexpected owner lookup.');}
    private function freezeTelegramChannelOwnership(int $channel,int $actor): bool {throw new RuntimeException('New registration tried to freeze another owner.');}
PHP
.$methods."\n}\n");

function releaseTelegramFixture(): array
{
    $store=new ReleaseTelegramStore();$transport=new GitPostedReleaseFixture\Transport($store);
    return [new GitPostedReleaseFixture\Bot($store,$transport),$store,$transport];
}

$tests=[];
$tests['packaged reference parser keeps exact channel identities and rejects message or invite links']=static function():void {
    [$bot]=releaseTelegramFixture();
    foreach(['@example_channel','t.me/example_channel','https://t.me/example_channel','http://t.me/example_channel/'] as $text) {
        releaseTelegramAssert($bot->telegramChannelRegistrationReference(['text'=>$text])==='@example_channel','Public reference was not canonicalized.');
    }
    releaseTelegramAssert($bot->telegramChannelRegistrationReference(['text'=>'-1001234567890'])==='-1001234567890','Numeric channel ID changed.');
    foreach(['@example_channel/1','https://t.me/example_channel/1','https://t.me/+exampleInvite','https://t.me/joinchat/exampleInvite',
        'https://t.me/example_channel?start=test','-0001','1','-9223372036854775809'] as $text) {
        releaseTelegramAssert($bot->telegramChannelRegistrationReference(['text'=>$text])===null,'Unsupported reference was accepted.');
    }
    $forward=['text'=>'@another_channel','forward_origin'=>['type'=>'channel','chat'=>['id'=>-1001234567890],'message_id'=>1]];
    releaseTelegramAssert($bot->telegramChannelRegistrationReference($forward)==='-1001234567890','Forward identity lost precedence over its text.');
};
$tests['packaged active aliases accept only current string usernames']=static function():void {
    [$bot]=releaseTelegramFixture();
    foreach([['username'=>'primary_name','active_usernames'=>['Example_Channel']],['active_usernames'=>['example_channel']]] as $chat) {
        releaseTelegramAssert($bot->telegramChannelRegistrationUsernameMatches($chat,'example_channel'),'An active public alias was rejected.');
    }
    foreach([['active_usernames'=>'example_channel'],['active_usernames'=>[true,7,['example_channel']]],['username'=>'different_name']] as $chat) {
        releaseTelegramAssert(!$bot->telegramChannelRegistrationUsernameMatches($chat,'example_channel'),'Malformed or unrelated alias granted authority.');
    }
};
$tests['packaged deletion labels preserve safe legacy preset manual periods and exact repeat interval']=static function():void {
    [$bot]=releaseTelegramFixture();
    foreach([2875=>'≈48 ч',2880=>'≈48 ч',4320=>'3 д · вручную',14400=>'10 д · вручную'] as $minutes=>$label) {
        releaseTelegramAssert($bot->telegramDeleteTimerLabel($minutes)===$label,'Deletion mode label is misleading.');
    }
    releaseTelegramAssert($bot->minutesLabel(2880)==='2 д','Deletion presentation altered the repeat formatter.');
    $keyboard=$bot->telegramDeleteTimerKeyboard('timer:',[0,2875,4320],'back',2880);
    releaseTelegramAssert($keyboard[0][1]===['text'=>'≈48 ч','callback_data'=>'timer:2875','style'=>'primary'],'Legacy selected preset changed its stored callback.');
    $hint=$bot->telegramDeleteTimerHint();
    releaseTelegramAssert(str_contains($hint,'47 ч 55 мин')&&str_contains($hint,'5 минут')&&str_contains($hint,'вручную'),'Deletion limits are not explained.');
};
$tests['packaged transfer binding is valid only for its exact owner channel and completed confirmation']=static function():void {
    $channel=['id'=>7,'chat_id'=>'-1001234567890','added_by'=>20002];
    $marker=['version'=>1,'channel_id'=>7,'chat_id'=>'-1001234567890','owner_id'=>20002,'source_id'=>20001,
        'token'=>str_repeat('a',24),'fingerprint'=>str_repeat('b',64),'completed_at'=>time()];
    releaseTelegramAssert(PostedTelegramAccountTransfer::channelTransferMarker($channel,json_encode($marker,JSON_THROW_ON_ERROR))===$marker,'Exact completed binding was rejected.');
    foreach(['owner_id'=>20003,'channel_id'=>8,'chat_id'=>'-1001234567891','source_id'=>20002,'token'=>'old','completed_at'=>time()+120] as $key=>$value) {
        $invalid=$marker;$invalid[$key]=$value;
        releaseTelegramAssert(PostedTelegramAccountTransfer::channelTransferMarker($channel,json_encode($invalid,JSON_THROW_ON_ERROR))===null,'Mismatched binding retained authority.');
    }
};
$tests['packaged registration checks identities and commits once before its private celebration effect']=static function():void {
    [$bot,$store,$transport]=releaseTelegramFixture();$prompt=$store->prompt;
    $bot->registerChannelFromMessage(20001,['text'=>'https://t.me/example_channel'],$prompt);
    releaseTelegramAssert($store->state===null&&$store->channel['chat_id']==='-1001234567890'&&$store->channel['added_by']===20001,'Registration did not persist the verified channel.');
    releaseTelegramAssert(count($store->grants)===1&&$store->grants[0]===[20001,7,true,true,true,true],'Registration did not grant the exact owner rights.');
    releaseTelegramAssert(!$store->inTransaction()&&!$store->graphLease,'Successful registration leaked its graph lease.');
    $messages=array_values(array_filter($transport->calls,static fn(array $call):bool=>$call[0]==='sendMessage'));
    releaseTelegramAssert(count($messages)===1&&$messages[0][1]['chat_id']==='20001'
        &&($messages[0][1]['message_effect_id']??null)==='5046509860389126442','Success celebration was dropped or sent outside the private reply.');
    $before=count($transport->calls);$bot->registerChannelFromMessage(20001,['text'=>'@example_channel'],$prompt);
    releaseTelegramAssert(count($transport->calls)===$before&&count($store->grants)===1,'A consumed input replay registered twice.');
};
$tests['packaged registration rejects insufficient or spoofed rights without a celebration or state mutation']=static function():void {
    foreach(['bot_member','bot_post_string','bot_wrong_identity','actor_member','actor_bot'] as $mode) {
        [$bot,$store,$transport]=releaseTelegramFixture();$transport->mode=$mode;$before=$store->snapshot();
        $bot->registerChannelFromMessage(20001,['text'=>'@example_channel'],$store->prompt);
        releaseTelegramAssert($store->snapshot()===$before,'Rejected identity changed channel registration state.');
        foreach($transport->calls as [$method,$body])releaseTelegramAssert(!isset($body['message_effect_id']),'Failed registration sent a success effect.');
    }
};
$tests['packaged registration preserves a newer input created during its API preflight']=static function():void {
    [$bot,$store,$transport]=releaseTelegramFixture();$prompt=$store->prompt;
    $transport->beforeActorRead=static function()use($store):void {$store->state='await_other_input';$store->prompt=['input_token'=>'bbbbbbbbbbbbbbbbbbbbbbbb'];};
    $bot->registerChannelFromMessage(20001,['text'=>'@example_channel'],$prompt);
    releaseTelegramAssert($store->state==='await_other_input'&&$store->prompt['input_token']==='bbbbbbbbbbbbbbbbbbbbbbbb'
        &&$store->channel===null&&$store->grants===[],'Stale registration replaced a newer workflow.');
    foreach($transport->calls as [$method,$body])releaseTelegramAssert(!isset($body['message_effect_id']),'Stale registration sent a success effect.');
};

$passed=0;
foreach($tests as $name=>$test) {
    try {$test();$passed++;echo 'PASS ',$name,PHP_EOL;}
    catch(Throwable $error) {fwrite(STDERR,'FAIL '.$name.': '.$error->getMessage().PHP_EOL);exit(1);}
}
echo 'telegram runtime package tests passed: ',$passed,PHP_EOL;
