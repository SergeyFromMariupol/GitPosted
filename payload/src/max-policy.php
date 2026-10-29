<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

final class GitPostedMaxPolicyException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly ?int $channelId=null)
    {parent::__construct($reason==='operator_denied'?'Доступ к боту ограничен.':'Для этого канала требуется подписка.');}
}

/** MAX operator checks and ephemeral preflight; callers keep existing publication journals authoritative. */
trait GitPostedMaxPolicyTrait
{
    private function maxPolicyOperatorAllowed(string $userId): bool
    {return GitPostedMaxPolicy::operatorAllowed($this->config,$userId);}

    /** Private operator only. Call before upsert; public/interactive events are routed separately. */
    private function maxPolicyAllowOperator(string $userId,?string $callbackId=null): bool
    {
        if($this->maxPolicyOperatorAllowed($userId))return true;
        $notice='Доступ к боту ограничен. Ваш ID МАКС: '.$userId;
        if($callbackId!==null)$this->api->answerCallback($callbackId,$notice,[],'Доступ ограничен');
        else $this->api->sendToUser($userId,$notice);
        return false;
    }

    /** The API-derived count is exact, bound to this channel and never fabricated on read failure. */
    private function maxPolicyPreflightChannel(array $channel,string $feature='basic'): array
    {
        $state=GitPostedMaxPolicy::channelState($this->pdo,$this->config,$channel,$feature);
        if($state['state']!=='member_count_unknown')return$state;
        if($this->pdo->inTransaction())throw new LogicException('MAX subscriber preflight must precede SQL locks.');
        try{
            $chat=$this->api->getChat(GitPostedMaxPolicy::chatId($channel['chat_id']??null));
            $id=GitPostedMaxPolicy::chatId($chat['chat_id']??null);$count=$chat['participants_count']??null;
            if($id!==GitPostedMaxPolicy::chatId($channel['chat_id'])||($chat['type']??null)!=='channel'||!is_int($count)||$count<0||$count>2147483647)return$state;
            return GitPostedMaxPolicy::channelState($this->pdo,$this->config,$channel,$feature,$count);
        }catch(Throwable){return$state;}
    }

    private function maxPolicyPublishPreflight(array $post,?string $actorId): array
    {
        GitPostedMaxPolicy::requireOperator($this->config,GitPostedMaxPolicy::actorId($post['author_max_user_id']??null));
        if($actorId!==null)GitPostedMaxPolicy::requireOperator($this->config,$actorId);
        $channel=['id'=>(int)GitPostedMaxPolicy::positiveId($post['channel_id']??null),'chat_id'=>GitPostedMaxPolicy::chatId($post['chat_id']??null)];
        $state=$this->maxPolicyPreflightChannel($channel);
        if(!$state['allowed'])throw new GitPostedMaxPolicyException($state['state'],$channel['id']);
        if($state['state']!=='free')$post['_max_policy_preflight']=['channel_id'=>$channel['id'],'chat_id'=>$channel['chat_id'],'checked_at'=>time(),'member_count'=>$state['member_count']];
        return$post;
    }

    /** SQL-only last gate before quota debit/attempt insertion; never replaces current MAX ACL checks. */
    private function maxPolicyReserveLocked(array $post,array $channel,string $authorId,?string $actorId): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('MAX policy gate requires the locked channel/post transaction.');
        GitPostedMaxPolicy::requireOperator($this->config,$authorId);
        if($actorId!==null)GitPostedMaxPolicy::requireOperator($this->config,$actorId);
        if((int)GitPostedMaxPolicy::positiveId($post['channel_id']??null)!==(int)$channel['id']||GitPostedMaxPolicy::chatId($post['chat_id']??null)!==GitPostedMaxPolicy::chatId($channel['chat_id']??null))throw new GitPostedMaxPolicyException('channel_changed',(int)$channel['id']);
        $proof=$post['_max_policy_preflight']??null;$count=null;$now=time();
        if(is_array($proof)&&($proof['channel_id']??null)===(int)$channel['id']&&($proof['chat_id']??null)===(string)$channel['chat_id']
            &&is_int($proof['checked_at']??null)&&$proof['checked_at']>=$now-60&&$proof['checked_at']<=$now+2&&is_int($proof['member_count']??null))$count=$proof['member_count'];
        GitPostedMaxPolicy::requireChannel($this->pdo,$this->config,$channel,'basic',$count);
    }

    private function maxPolicyRequirePremiumLocked(array $channel): void
    {if(!$this->pdo->inTransaction())throw new LogicException('MAX repeat policy requires its existing locked mutation.');GitPostedMaxPolicy::requireChannel($this->pdo,$this->config,$channel,'premium');}
}

/** Independent operator access and per-channel licensing. No payments or permission mutations. */
final class GitPostedMaxPolicy
{
    public const BASIC_TRIAL_MEMBER_LIMIT=500;
    public const MAX_ALLOWLIST=1000;

    public static function defaults(): array
    {return ['version'=>1,'pricing_mode'=>'free','access_mode'=>'open','allowed_max_ids'=>[]];}

    public static function fromConfig(array $config): array
    {return array_key_exists('max_policy',$config)?self::normalize($config['max_policy']):self::defaults();}

    public static function normalize(mixed $value): array
    {
        if(!is_array($value))throw new InvalidArgumentException('Настройки доступа МАКС повреждены.');
        $keys=array_keys($value);sort($keys);$expected=array_keys(self::defaults());sort($expected);
        if($keys!==$expected||($value['version']??null)!==1||!in_array($value['pricing_mode']??null,['free','paid'],true)
            ||!in_array($value['access_mode']??null,['open','allowlist'],true)||!is_array($value['allowed_max_ids']??null)
            ||!array_is_list($value['allowed_max_ids'])||count($value['allowed_max_ids'])>self::MAX_ALLOWLIST) {
            throw new InvalidArgumentException('Некорректные настройки доступа МАКС.');
        }
        $ids=[];foreach($value['allowed_max_ids'] as$id)$ids[self::actorId($id)]=true;
        $ids=array_map('strval',array_keys($ids));usort($ids,static fn(string$a,string$b):int=>strlen($a)<=>strlen($b)?:strcmp($a,$b));
        return ['version'=>1,'pricing_mode'=>$value['pricing_mode'],'access_mode'=>$value['access_mode'],'allowed_max_ids'=>$ids];
    }

    public static function fromAdmin(array $input): array
    {
        foreach(['max_pricing_mode','max_access_mode','max_allowed_ids'] as$key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('Заполните режимы и список МАКС ID.');
        $raw=trim($input['max_allowed_ids']);if(strlen($raw)>24000)throw new InvalidArgumentException('Список МАКС ID слишком длинный.');
        $parts=$raw===''?[]:preg_split('/[\s,;]+/u',$raw,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($parts))throw new InvalidArgumentException('Некорректный список МАКС ID.');
        return self::normalize(['version'=>1,'pricing_mode'=>$input['max_pricing_mode'],'access_mode'=>$input['max_access_mode'],'allowed_max_ids'=>$parts]);
    }

    public static function operatorAllowed(array $config,string $maxId): bool
    {
        try{self::actorId($maxId);}catch(InvalidArgumentException){return false;}
        try{$policy=self::fromConfig($config);}catch(InvalidArgumentException){return false;}
        return $policy['access_mode']==='open'||in_array($maxId,$policy['allowed_max_ids'],true);
    }

    public static function requireOperator(array $config,string $maxId): void
    {if(!self::operatorAllowed($config,$maxId))throw new GitPostedMaxPolicyException('operator_denied');}

    public static function licenseKey(int $channelId): string
    {self::positiveId($channelId);return 'max_channel_license_'.$channelId;}

    public static function normalizeLicense(mixed $license,array $channel): array
    {
        if(!is_array($license))throw new InvalidArgumentException('Лицензия канала повреждена.');
        $keys=array_keys($license);sort($keys);$expected=['channel_id','chat_id','expires_at','status','version'];sort($expected);
        if($keys!==$expected||($license['version']??null)!==1||($license['channel_id']??null)!==(int)self::positiveId($channel['id']??null)
            ||($license['chat_id']??null)!==self::chatId($channel['chat_id']??null)||!in_array($license['status']??null,['active','revoked'],true)
            ||!is_string($license['expires_at']??null))throw new InvalidArgumentException('Лицензия относится к другому каналу.');
        self::utcTimestamp($license['expires_at']);return $license;
    }

    public static function readLicense(PDO $pdo,array $channel,bool $lock=false): ?array
    {
        if($lock&&!$pdo->inTransaction())throw new LogicException('License row lock requires a transaction.');
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1'.($lock?' FOR UPDATE':''));
        $query->execute(['key'=>self::licenseKey((int)self::positiveId($channel['id']??null))]);$raw=$query->fetchColumn();
        if($raw===false)return null;
        try{$license=json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException$error){throw new InvalidArgumentException('Лицензия канала повреждена.',0,$error);}
        return self::normalizeLicense($license,$channel);
    }

    /** Basic trial is based on a verified count; premium always needs an active license in paid mode. */
    public static function channelState(PDO $pdo,array $config,array $channel,string $feature='basic',?int $verifiedMemberCount=null,?int $now=null): array
    {
        if(!in_array($feature,['basic','premium'],true)||($verifiedMemberCount!==null&&($verifiedMemberCount<0||$verifiedMemberCount>2147483647)))throw new InvalidArgumentException('Некорректная проверка подписки.');
        $id=(int)self::positiveId($channel['id']??null);self::chatId($channel['chat_id']??null);$now??=time();
        $result=['allowed'=>false,'state'=>'invalid_policy','channel_id'=>$id,'feature'=>$feature,'expires_at'=>null,'member_count'=>$verifiedMemberCount];
        try{$policy=self::fromConfig($config);}catch(InvalidArgumentException){return $result;}
        if($policy['pricing_mode']==='free'){return array_replace($result,['allowed'=>true,'state'=>'free']);}
        $licenseState='required';
        try{
            $license=self::readLicense($pdo,$channel,$pdo->inTransaction());
            if($license!==null){
                $result['expires_at']=$license['expires_at'];
                if($license['status']==='revoked')$licenseState='revoked';
                elseif(self::utcTimestamp($license['expires_at'])>$now)return array_replace($result,['allowed'=>true,'state'=>'active']);
                else $licenseState='expired';
            }
        }catch(InvalidArgumentException){return array_replace($result,['state'=>'invalid_license']);}
        if($feature==='basic'){
            if($verifiedMemberCount===null)return array_replace($result,['state'=>'member_count_unknown']);
            if($verifiedMemberCount<self::BASIC_TRIAL_MEMBER_LIMIT)return array_replace($result,['allowed'=>true,'state'=>'trial']);
        }
        return array_replace($result,['state'=>$licenseState]);
    }

    public static function requireChannel(PDO $pdo,array $config,array $channel,string $feature='basic',?int $verifiedMemberCount=null): void
    {
        $state=self::channelState($pdo,$config,$channel,$feature,$verifiedMemberCount);
        if(!$state['allowed'])throw new GitPostedMaxPolicyException($state['state'],$state['channel_id']);
    }

    /** Explicit authenticated admin operation; policy mode changes never call this method. */
    public static function saveLicense(PDO $pdo,int $channelId,string $action,mixed $expiresInput=null,?int $now=null,?string $expectedLicenseHash=null): array
    {
        self::positiveId($channelId);if(!in_array($action,['grant','revoke'],true))throw new InvalidArgumentException('Неизвестное действие с подпиской.');
        $now??=time();$expires=null;
        if($action==='grant'){
            if(!is_string($expiresInput)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$expiresInput)!==1)throw new InvalidArgumentException('Укажите срок подписки в UTC.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$expiresInput,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
            if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d\TH:i')!==$expiresInput
                ||$date->getTimestamp()<=$now||$date->getTimestamp()>$now+3660*86400)throw new InvalidArgumentException('Срок должен быть в будущем, не более десяти лет.');
            $expires=$date->format('Y-m-d\TH:i:s\Z');
        }
        $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
        try{
            $query=$pdo->prepare('SELECT id,chat_id FROM posted_max_channels WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$channelId]);$channel=$query->fetch();
            if(!is_array($channel))throw new InvalidArgumentException('Канал МАКС не найден.');
            $current=self::readLicense($pdo,$channel,true);
            if($expectedLicenseHash!==null&&(!preg_match('/\A[a-f0-9]{64}\z/D',$expectedLicenseHash)||!hash_equals($expectedLicenseHash,self::licenseHash($current))))throw new InvalidArgumentException('Подписка изменилась. Обновите данные и подтвердите действие заново.');
            if($action==='revoke'){
                $license=self::readLicense($pdo,$channel,true);if($license===null)throw new InvalidArgumentException('У канала нет выданной подписки.');$license['status']='revoked';
            }else $license=['version'=>1,'channel_id'=>$channelId,'chat_id'=>self::chatId($channel['chat_id']),'expires_at'=>$expires,'status'=>'active'];
            $license=self::normalizeLicense($license,$channel);
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
                ->execute(['key'=>self::licenseKey($channelId),'value'=>json_encode($license,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            if($owns)$pdo->commit();return $license;
        }catch(Throwable$error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function licenseHash(?array $license): string
    {if($license!==null)ksort($license,SORT_STRING);return hash('sha256',json_encode($license,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}

    public static function checkoutUrl(string $baseUrl,array $channel): string
    {
        $baseUrl=self::checkoutBase($baseUrl);
        return $baseUrl.'/?'.http_build_query(['checkout'=>'max','channel'=>self::positiveId($channel['id']??null),'chat'=>self::chatId($channel['chat_id']??null)],'','&',PHP_QUERY_RFC3986);
    }

    public static function checkoutSelectionUrl(string $baseUrl,string $quoteToken): string
    {
        if(preg_match('/\A[a-f0-9]{24}\z/D',$quoteToken)!==1)throw new InvalidArgumentException('Некорректная ссылка на выбранные каналы.');
        return self::checkoutBase($baseUrl).'/?checkout=max&quote='.$quoteToken;
    }

    private static function checkoutBase(string $baseUrl): string
    {
        $baseUrl=rtrim($baseUrl,'/');$parts=parse_url($baseUrl);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||preg_match('/[\x00-\x20\x7f\\\\]/',$baseUrl))throw new InvalidArgumentException('Адрес страницы подписки не настроен.');
        return $baseUrl;
    }

    public static function positiveId(mixed $value): string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)!==1||(strlen($value)===19&&strcmp($value,'9223372036854775807')>0))throw new InvalidArgumentException('Используйте только положительные числовые МАКС ID без @username.');
        return $value;
    }

    public static function actorId(mixed $value): string
    {
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)!==1)throw new InvalidArgumentException('Используйте числовой ID МАКС без @username.');return $value;
    }

    public static function chatId(mixed $value): string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||preg_match('/\A-?[1-9][0-9]{0,18}\z/D',$value)!==1||((str_starts_with($value,'-')&&strlen($value)===20&&strcmp(substr($value,1),'9223372036854775808')>0)||(!str_starts_with($value,'-')&&strlen($value)===19&&strcmp($value,'9223372036854775807')>0)))throw new InvalidArgumentException('Некорректный МАКС ID канала.');
        return $value;
    }

    private static function utcTimestamp(string $value): int
    {
        if(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$value)!==1)throw new InvalidArgumentException('Некорректный срок подписки.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new InvalidArgumentException('Некорректный срок подписки.');return $date->getTimestamp();
    }
}
