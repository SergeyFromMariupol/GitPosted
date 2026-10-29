<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

require_once __DIR__.'/telegram-rooted-access.php';

final class PostedTelegramPolicyException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly ?int $channelId=null)
    {parent::__construct(in_array($reason,['operator_denied','rooted_channel_denied'],true)?'Доступ к боту ограничен.':'Для этого канала требуется подписка.');}
}

/** Independent operator access and per-channel licensing. No payments or permission mutations. */
final class PostedTelegramPolicy
{
    public const BASIC_TRIAL_MEMBER_LIMIT=500;
    public const MAX_ALLOWLIST=1000;

    public static function defaults(): array
    {return ['version'=>1,'pricing_mode'=>'free','access_mode'=>'open','allowed_telegram_ids'=>[]];}

    public static function fromConfig(array $config): array
    {return array_key_exists('telegram_policy',$config)?self::normalize($config['telegram_policy']):self::defaults();}

    public static function normalize(mixed $value): array
    {
        if(!is_array($value))throw new InvalidArgumentException('Настройки доступа Telegram повреждены.');
        $keys=array_keys($value);sort($keys);$expected=array_keys(self::defaults());sort($expected);
        if($keys!==$expected||($value['version']??null)!==1||!in_array($value['pricing_mode']??null,['free','paid'],true)
            ||!in_array($value['access_mode']??null,['open','allowlist','rooted'],true)||!is_array($value['allowed_telegram_ids']??null)
            ||!array_is_list($value['allowed_telegram_ids'])||count($value['allowed_telegram_ids'])>self::MAX_ALLOWLIST) {
            throw new InvalidArgumentException('Некорректные настройки доступа Telegram.');
        }
        $ids=[];foreach($value['allowed_telegram_ids'] as$id)$ids[self::positiveId($id)]=true;
        $ids=array_map('strval',array_keys($ids));usort($ids,static fn(string$a,string$b):int=>strlen($a)<=>strlen($b)?:strcmp($a,$b));
        return ['version'=>1,'pricing_mode'=>$value['pricing_mode'],'access_mode'=>$value['access_mode'],'allowed_telegram_ids'=>$ids];
    }

    public static function fromAdmin(array $input): array
    {
        foreach(['telegram_pricing_mode','telegram_access_mode','telegram_allowed_ids'] as$key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('Заполните режимы и список Telegram ID.');
        $raw=trim($input['telegram_allowed_ids']);if(strlen($raw)>24000)throw new InvalidArgumentException('Список Telegram ID слишком длинный.');
        $parts=$raw===''?[]:preg_split('/[\s,;]+/u',$raw,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($parts))throw new InvalidArgumentException('Некорректный список Telegram ID.');
        return self::normalize(['version'=>1,'pricing_mode'=>$input['telegram_pricing_mode'],'access_mode'=>$input['telegram_access_mode'],'allowed_telegram_ids'=>$parts]);
    }

    public static function operatorAllowed(array $config,int $telegramId,?PDO $pdo=null): bool
    {
        if($telegramId<=0)return false;
        $config=PostedTelegramRootedAccess::current($config);
        try{$policy=self::fromConfig($config);}catch(InvalidArgumentException){return false;}
        if($policy['access_mode']==='rooted')return $pdo!==null&&PostedTelegramRootedAccess::operator($pdo,$config,$telegramId);
        return $policy['access_mode']==='open'||in_array((string)$telegramId,$policy['allowed_telegram_ids'],true);
    }

    public static function requireOperator(array $config,int $telegramId,?PDO $pdo=null): void
    {if(!self::operatorAllowed($config,$telegramId,$pdo))throw new PostedTelegramPolicyException('operator_denied');}

    public static function licenseKey(int $channelId): string
    {self::positiveId($channelId);return 'telegram_channel_license_'.$channelId;}

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
        if(!$state['allowed'])throw new PostedTelegramPolicyException($state['state'],$state['channel_id']);
    }

    /** Explicit authenticated admin operation; policy mode changes never call this method. */
    public static function saveLicense(PDO $pdo,int $channelId,string $action,mixed $expiresInput=null,?int $now=null): array
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
            $query=$pdo->prepare('SELECT id,chat_id FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$channelId]);$channel=$query->fetch();
            if(!is_array($channel))throw new InvalidArgumentException('Канал Telegram не найден.');
            if($action==='revoke'){
                $license=self::readLicense($pdo,$channel,true);if($license===null)throw new InvalidArgumentException('У канала нет выданной подписки.');$license['status']='revoked';
            }else $license=['version'=>1,'channel_id'=>$channelId,'chat_id'=>self::chatId($channel['chat_id']),'expires_at'=>$expires,'status'=>'active'];
            $license=self::normalizeLicense($license,$channel);
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
                ->execute(['key'=>self::licenseKey($channelId),'value'=>json_encode($license,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            if($owns)$pdo->commit();return $license;
        }catch(Throwable$error){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function checkoutUrl(string $baseUrl,array $channel): string
    {
        $baseUrl=self::checkoutBase($baseUrl);
        return $baseUrl.'/?'.http_build_query(['checkout'=>'telegram','channel'=>self::positiveId($channel['id']??null),'chat'=>self::chatId($channel['chat_id']??null)],'','&',PHP_QUERY_RFC3986);
    }

    public static function checkoutSelectionUrl(string $baseUrl,string $quoteToken): string
    {
        if(preg_match('/\A[a-f0-9]{24}\z/D',$quoteToken)!==1)throw new InvalidArgumentException('Некорректная ссылка на выбранные каналы.');
        return self::checkoutBase($baseUrl).'/?checkout=telegram&quote='.$quoteToken;
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
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)!==1||(strlen($value)===19&&strcmp($value,'9223372036854775807')>0))throw new InvalidArgumentException('Используйте только положительные числовые Telegram ID без @username.');
        return $value;
    }

    public static function chatId(mixed $value): string
    {
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$value)!==1||(strlen($value)===20&&strcmp(substr($value,1),'9223372036854775808')>0))throw new InvalidArgumentException('Некорректный Telegram ID канала.');
        return $value;
    }

    private static function utcTimestamp(string $value): int
    {
        if(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$value)!==1)throw new InvalidArgumentException('Некорректный срок подписки.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new InvalidArgumentException('Некорректный срок подписки.');return $date->getTimestamp();
    }
}

trait GitPostedTelegramPolicy
{
    private function telegramPolicyOperatorAllowed(int $userId): bool{return PostedTelegramPolicy::operatorAllowed($this->config,$userId,$this->pdo);}

    /** Invoke before private operator upsert/state/grant. Public buttons and membership tracking bypass it. */
    private function telegramPolicyAllowOperator(int $userId,?array $callback=null): bool
    {
        if($this->telegramPolicyOperatorAllowed($userId))return true;
        $text='Доступ к боту ограничен. Ваш Telegram ID: '.$userId;
        if($callback!==null)$this->answerCallback($callback,$text);else $this->telegram->sendMessage($userId,$text);
        return false;
    }

    /** Preflight outside SQL locks. The verified count is ephemeral, never a stored license or grant. */
    private function telegramPolicyPreflightChannel(array $channel,string $feature='basic'): array
    {
        $state=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,$feature);
        if($state['state']!=='member_count_unknown')return $state;
        if($this->pdo->inTransaction())throw new LogicException('Subscriber count must be fetched before SQL locks.');
        try{
            $response=$this->telegram->call('getChatMemberCount',['chat_id'=>PostedTelegramPolicy::chatId($channel['chat_id']??null)]);
            $count=$response['result']??null;
            if(($response['ok']??null)!==true||!is_int($count)||$count<0||$count>2147483647)return $state;
            return PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,$feature,$count);
        }catch(Throwable){return $state;}
    }

    private function telegramPolicyPublishPreflight(array $post,?int $actorId): array
    {
        PostedTelegramPolicy::requireOperator($this->config,(int)($post['author_id']??0),$this->pdo);
        if($actorId!==null)PostedTelegramPolicy::requireOperator($this->config,$actorId,$this->pdo);
        PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,(int)$post['author_id'],(int)$post['channel_id']);
        if($actorId!==null)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actorId,(int)$post['channel_id']);
        $channel=['id'=>(int)$post['channel_id'],'chat_id'=>(string)$post['chat_id']];
        try{$state=$this->telegramPolicyPreflightChannel($channel);}
        catch(Throwable$error){error_log('GitPosted policy preflight unavailable: '.get_class($error));throw new PostedTelegramPolicyException('policy_check_unavailable',$channel['id']);}
        if(!$state['allowed'])throw new PostedTelegramPolicyException($state['state'],$state['channel_id']);
        if($state['state']!=='free')$post['_telegram_policy_preflight']=['channel_id'=>$channel['id'],'chat_id'=>$channel['chat_id'],'checked_at'=>time(),'member_count'=>$state['member_count']];
        return $post;
    }

    /** Locked, network-free final authorization before quota debit or attempt insertion. */
    private function telegramPolicyReserveLocked(array $post,array $channel,int $authorId,?int $actorId): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Final policy gate requires locked channel/post rows.');
        PostedTelegramPolicy::requireOperator($this->config,$authorId,$this->pdo);
        if($actorId!==null)PostedTelegramPolicy::requireOperator($this->config,$actorId,$this->pdo);
        PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$authorId,(int)$channel['id']);
        if($actorId!==null)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actorId,(int)$channel['id']);
        if((string)($post['chat_id']??'')!==(string)($channel['chat_id']??''))throw new PostedTelegramPolicyException('channel_changed',(int)$channel['id']);
        $count=null;$proof=$post['_telegram_policy_preflight']??null;
        if(is_array($proof)&&($proof['channel_id']??null)===(int)$channel['id']&&($proof['chat_id']??null)===(string)$channel['chat_id']
            &&is_int($proof['checked_at']??null)&&$proof['checked_at']>=time()-60&&$proof['checked_at']<=time()+2
            &&is_int($proof['member_count']??null))$count=$proof['member_count'];
        try{PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$channel,'basic',$count);}
        catch(PostedTelegramPolicyException$error){throw $error;}
        catch(Throwable$error){error_log('GitPosted final policy check unavailable: '.get_class($error));throw new PostedTelegramPolicyException('policy_check_unavailable',(int)$channel['id']);}
    }

    /** A policy change cannot rewind an attempt that has any recorded transport history. */
    private function telegramPolicyHoldUnreservedClaim(array $post,PostedTelegramPolicyException $exception,?int $actorId): array
    {
        $prior=(string)($post['_claimed_from_status']??'draft');if(!in_array($prior,['draft','scheduled','failed'],true))$prior='draft';
        $message=match($exception->reason){
            'operator_denied'=>'Отправка приостановлена: доступ автора или отправителя к боту ограничен. Пост и его настройки сохранены.',
            'member_count_unknown'=>'Отправка приостановлена: не удалось проверить число подписчиков канала. Пост сохранён, повторной отправки не было.',
            'invalid_policy','invalid_license','policy_check_unavailable'=>'Отправка приостановлена: настройки доступа или подписка канала требуют проверки администратором. Пост сохранён.',
            default=>'Отправка приостановлена: для этого канала нужна активная подписка. Пост и его дата сохранены.',
        };
        if(!$this->restoreBlockedPublishClaim($post,$message))return ['state'=>'processing','message_ids'=>[],'message_slots'=>[]];
        $recipient=$actorId??(int)$post['author_id'];$now=time();$key='telegram_policy_notice_'.(int)$post['id'].'_'.$recipient;
        $value=json_encode(['version'=>1,'reason'=>$exception->reason,'next_notice_at'=>$now+3600],JSON_THROW_ON_ERROR);
        try{
            $this->pdo->prepare("INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,'{}') ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)")->execute(['key'=>$key]);
            $notice=$this->pdo->prepare("UPDATE posted_app_settings SET setting_value=:value WHERE setting_key=:key
                AND (JSON_VALID(setting_value)=0 OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.reason')),'')<>:reason
                    OR COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.next_notice_at')) AS UNSIGNED),0)<=:now)");
            $notice->execute(['value'=>$value,'key'=>$key,'reason'=>$exception->reason,'now'=>$now]);
            if($notice->rowCount()===1&&$actorId===null)$this->telegram->sendMessage($recipient,$message);
        }catch(Throwable$error){error_log('GitPosted policy notification unavailable: '.get_class($error));}
        return ['state'=>'blocked','policy_blocked'=>true,'notice'=>$message,'message_ids'=>[],'message_slots'=>[]];
    }
}
