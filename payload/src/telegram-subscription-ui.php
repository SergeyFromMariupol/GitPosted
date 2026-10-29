<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Subscription menus have their own CAS record. They never borrow a publication input state. */
final class PostedTelegramSubscription
{
    public const TTL=900;
    public const MAX_CHANNELS=500;
    public const MAX_OPERATION_CHANNELS=30;
    public const MAX_REMAINING=316224000;
    public const SCREENS=['status','offer','channels','periods','promo','checkout','align','move','moveto','review','receipt','referral','creative'];
    public const ACTIONS=['status','offer','channels','periods','period','toggle','all','none','unpaid','page','promo','pay','align','move','moveto','target','review','ok','settings','referral','creative'];

    public static function tariffs(array $config): array
    {
        $value=array_key_exists('telegram_subscription_tariffs',$config)?$config['telegram_subscription_tariffs']:['version'=>1,'currency'=>'RUB','prices'=>[30=>299,90=>849,180=>1599,365=>2999]];
        if(!is_array($value)||count($value)!==3||($value['version']??null)!==1||($value['currency']??null)!=='RUB'||!is_array($value['prices']??null))throw new InvalidArgumentException('Тарифы подписки не настроены.');
        $keys=array_keys($value);sort($keys);if($keys!==['currency','prices','version'])throw new InvalidArgumentException('Некорректные тарифы подписки.');
        $prices=$value['prices'];$days=array_keys($prices);sort($days);if($days!==[30,90,180,365])throw new InvalidArgumentException('Некорректные периоды подписки.');
        foreach($prices as$price)if(!is_int($price)||$price<1||$price>1000000)throw new InvalidArgumentException('Некорректная стоимость подписки.');
        return $prices;
    }

    public static function ids(mixed $ids,int $max=self::MAX_CHANNELS): array
    {
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>$max)throw new InvalidArgumentException('Слишком много выбранных каналов.');
        $result=[];foreach($ids as$id){if(!is_int($id)||$id<=0)throw new InvalidArgumentException('Некорректный канал.');$result[$id]=$id;}
        $result=array_values($result);sort($result,SORT_NUMERIC);return $result;
    }

    public static function fresh(int $userId,array $selected=[],string $screen='status',?int $now=null): array
    {
        PostedTelegramPolicy::positiveId($userId);$now??=time();
        return self::validate(['version'=>1,'user_id'=>$userId,'nonce'=>bin2hex(random_bytes(12)),'revision'=>0,'expires_at'=>$now+self::TTL,
            'screen'=>$screen,'days'=>30,'selected'=>self::ids($selected),'sources'=>[],'targets'=>[],'page'=>0,'plan'=>null,'receipt'=>null,'quote'=>null,'promo_prompt_id'=>0]);
    }

    public static function validate(mixed $flow): array
    {
        $expected=['days','expires_at','nonce','page','plan','promo_prompt_id','quote','receipt','revision','screen','selected','sources','targets','user_id','version'];
        if(!is_array($flow))throw new InvalidArgumentException('Меню подписки повреждено.');$keys=array_keys($flow);sort($keys);
        if($keys!==$expected||($flow['version']??null)!==1||!is_int($flow['user_id']??null)||$flow['user_id']<=0
            ||!is_string($flow['nonce']??null)||preg_match('/\A[a-f0-9]{24}\z/D',$flow['nonce'])!==1
            ||!is_int($flow['revision']??null)||$flow['revision']<0||$flow['revision']>999999
            ||!is_int($flow['expires_at']??null)||!in_array($flow['screen']??null,self::SCREENS,true)
            ||!in_array($flow['days']??null,[30,90,180,365],true)||!is_int($flow['page']??null)||$flow['page']<0||$flow['page']>1000
            ||!is_int($flow['promo_prompt_id']??null)||$flow['promo_prompt_id']<0
            ||($flow['plan']!==null&&!is_array($flow['plan']))||($flow['receipt']!==null&&!is_array($flow['receipt']))
            ||($flow['quote']!==null&&(!is_string($flow['quote'])||preg_match('/\A[a-f0-9]{24}\z/D',$flow['quote'])!==1)))throw new InvalidArgumentException('Меню подписки повреждено.');
        $flow['selected']=self::ids($flow['selected']);$flow['sources']=self::ids($flow['sources'],self::MAX_OPERATION_CHANNELS);$flow['targets']=self::ids($flow['targets'],self::MAX_OPERATION_CHANNELS);
        if(count(array_unique([...$flow['sources'],...$flow['targets']]))>self::MAX_OPERATION_CHANNELS)throw new InvalidArgumentException('Выберите не более 30 каналов.');
        return $flow;
    }

    public static function key(int $userId): string
    {return 'telegram_subscription_flow_'.PostedTelegramPolicy::positiveId($userId);}

    /** MySQL JSON may reorder object keys. Bindings retain exact scalar types and ordered lists. */
    public static function sameJson(mixed $left,mixed $right): bool
    {return self::canonicalJson($left)===self::canonicalJson($right);}

    private static function canonicalJson(mixed $value): mixed
    {
        if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$item)$value[$key]=self::canonicalJson($item);return $value;
    }

    public static function read(PDO $pdo,int $userId,bool $lock=false): ?array
    {
        if($lock&&!$pdo->inTransaction())throw new LogicException('Subscription lock requires a transaction.');
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1'.($lock?' FOR UPDATE':''));$query->execute(['key'=>self::key($userId)]);$raw=$query->fetchColumn();
        if($raw===false)return null;
        try{$flow=self::validate(json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR));}catch(JsonException $error){throw new InvalidArgumentException('Меню подписки повреждено.',0,$error);}
        if($flow['user_id']!==$userId)throw new InvalidArgumentException('Меню принадлежит другому пользователю.');return $flow;
    }

    public static function write(PDO $pdo,array $flow): void
    {self::put($pdo,self::key($flow['user_id']),self::validate($flow));}

    public static function put(PDO $pdo,string $key,array $value): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Subscription write requires a transaction.');
        if(preg_match('/\Atelegram_(?:subscription_flow|channel_license)_[1-9][0-9]{0,18}\z/D',$key)!==1)throw new LogicException('Unexpected subscription setting key.');
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['key'=>$key,'value'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }

    public static function actor(PDO $pdo,array $config,int $userId): void
    {
        PostedTelegramPolicy::requireOperator($config,$userId,$pdo);
        $query=$pdo->prepare('SELECT telegram_id,is_active FROM posted_users WHERE telegram_id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$userId]);$actor=$query->fetch();
        if(!is_array($actor)||(int)$actor['is_active']!==1)throw new InvalidArgumentException('Пользователь недоступен.');
        if(PostedTelegramPolicy::fromConfig($config)['pricing_mode']!=='paid')throw new InvalidArgumentException('Бот работает бесплатно.');
    }

    public static function open(PDO $pdo,array $config,int $userId,array $selected=[],string $screen='status',?int $now=null): array
    {
        if($pdo->inTransaction())throw new LogicException('Subscription flow must own its transaction.');PostedTelegramRootedAccess::begin($pdo,$config);
        try{self::actor($pdo,$config,$userId);foreach($selected as$id)PostedTelegramRootedAccess::requireChannel($pdo,$config,$userId,(int)$id);$flow=self::fresh($userId,$selected,$screen,$now);self::write($pdo,$flow);PostedTelegramRootedAccess::commit($pdo);return $flow;}
        catch(Throwable $error){if($pdo->inTransaction())PostedTelegramRootedAccess::rollback($pdo);throw $error;}
    }

    public static function mutate(PDO $pdo,array $config,int $userId,string $nonce,int $revision,Closure $change,?int $now=null): array
    {
        if($pdo->inTransaction())throw new LogicException('Subscription flow must own its transaction.');$now??=time();PostedTelegramRootedAccess::begin($pdo,$config);
        try{self::actor($pdo,$config,$userId);$flow=self::read($pdo,$userId,true);self::current($flow,$nonce,$revision,$now);
            $next=$change($flow);if(!is_array($next)||$next['nonce']!==$flow['nonce']||$next['user_id']!==$userId)throw new LogicException('Invalid subscription transition.');
            $next['revision']=$revision+1;$next['expires_at']=$now+self::TTL;self::write($pdo,$next);PostedTelegramRootedAccess::commit($pdo);return $next;
        }catch(Throwable $error){if($pdo->inTransaction())PostedTelegramRootedAccess::rollback($pdo);throw $error;}
    }

    public static function current(?array $flow,string $nonce,int $revision,int $now): void
    {if($flow===null||!hash_equals($flow['nonce'],$nonce)||$flow['revision']!==$revision||$flow['expires_at']<=$now||$revision>=999999)throw new InvalidArgumentException('Это меню устарело. Откройте подписку заново.');}

    public static function callback(array $flow,string $action,string|int|null $argument=null): string
    {
        if(!in_array($action,self::ACTIONS,true))throw new InvalidArgumentException('Неизвестное действие подписки.');
        $value='sub:'.$flow['nonce'].':'.$flow['revision'].':'.$action.($argument!==null?':'.$argument:'');
        if(strlen($value)>64)throw new InvalidArgumentException('Слишком длинная кнопка подписки.');return $value;
    }

    public static function parse(string $callback): array
    {
        if(strlen($callback)>64||preg_match('/\Asub:([a-f0-9]{24}):(0|[1-9][0-9]{0,5}):([a-z]{1,12})(?::([0-9]{1,19}))?\z/D',$callback,$matches)!==1
            ||!in_array($matches[3],self::ACTIONS,true))throw new InvalidArgumentException('Некорректная кнопка подписки.');
        $argument=null;if(isset($matches[4])){
            if($matches[3]==='page'){
                if(preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D',$matches[4])!==1||(int)$matches[4]>1000)throw new InvalidArgumentException('Некорректная страница.');$argument=(int)$matches[4];
            }else $argument=(int)PostedTelegramPolicy::positiveId($matches[4]);
        }
        return [$matches[1],(int)$matches[2],$matches[3],$argument];
    }

    public static function quote(PDO $pdo,array $config,int $userId,string $nonce,int $revision,array $channels,?int $now=null): array
    {
        $now??=time();$prices=self::tariffs($config);$quote=null;
        $flow=self::mutate($pdo,$config,$userId,$nonce,$revision,static function(array $flow)use($pdo,$config,$prices,$channels,$userId,$now,&$quote):array {
            if($flow['screen']!=='offer'||$flow['selected']===[])throw new InvalidArgumentException('Выберите каналы для подписки.');
            $bindings=[];foreach($flow['selected'] as$id){$channel=$channels[$id]??null;if(!is_array($channel))throw new InvalidArgumentException('Доступ к выбранному каналу изменился.');
                PostedTelegramRootedAccess::requireChannel($pdo,$config,$userId,(int)$id);$bindings[]=['channel_id'=>$id,'chat_id'=>PostedTelegramPolicy::chatId($channel['chat_id']??null)];}
            $token=bin2hex(random_bytes(12));$quote=['version'=>1,'token'=>$token,'user_id'=>$userId,'channels'=>$bindings,'period_days'=>$flow['days'],
                'amount_rub'=>$prices[$flow['days']]*count($bindings),'currency'=>'RUB','created_at'=>$now,'expires_at'=>$now+self::TTL];
            // A new random key is insert-only: a price selection cannot rewrite a previous quote.
            // A future payment provider MUST revalidate current ACL, channel identity, tariff and expiry before charging or granting.
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)')
                ->execute(['key'=>'telegram_checkout_quote_'.$token,'value'=>json_encode($quote,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $flow['quote']=$token;$flow['screen']='checkout';return $flow;
        },$now);return [$flow,$quote];
    }

    public static function utc(string $value): int
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new InvalidArgumentException('Некорректный срок подписки.');return $date->getTimestamp();
    }

    public static function channelSnapshot(array $channel,?array $license,int $userId): array
    {
        $id=(int)PostedTelegramPolicy::positiveId($channel['id']??null);
        if((int)($channel['added_by']??0)!==$userId||(int)($channel['is_active']??0)!==1||(int)($channel['is_archived']??0)!==0)throw new InvalidArgumentException('Подписку можно изменять только в своих действующих каналах.');
        return ['id'=>$id,'chat_id'=>PostedTelegramPolicy::chatId($channel['chat_id']??null),'added_by'=>$userId,
            'title'=>(string)($channel['title']??''),'license'=>$license===null?null:PostedTelegramPolicy::normalizeLicense($license,$channel)];
    }

    /** A review contains full before bindings. Time pool is bounded; no money or invented credit is stored. */
    public static function plan(string $kind,int $userId,array $sources,array $targets,array $snapshots,?int $now=null,?string $operationId=null): array
    {
        $now??=time();$sources=self::ids($sources,self::MAX_OPERATION_CHANNELS);$targets=self::ids($targets,self::MAX_OPERATION_CHANNELS);
        $union=self::ids(array_values(array_unique([...$sources,...$targets])),self::MAX_OPERATION_CHANNELS);
        if(!in_array($kind,['align','move'],true)||$sources===[]||($kind==='align'&&($targets!==[]||count($sources)<2))
            ||($kind==='move'&&($targets===[]||array_intersect($sources,$targets)!==[])))throw new InvalidArgumentException('Выберите разные каналы для операции.');
        $before=[];$pool=0;$sumExpiry=0;
        foreach($union as$id){$snapshot=$snapshots[$id]??null;if(!is_array($snapshot)||($snapshot['id']??null)!==$id||($snapshot['added_by']??null)!==$userId)throw new InvalidArgumentException('Доступ к каналу изменился.');
            PostedTelegramPolicy::chatId($snapshot['chat_id']??null);$license=$snapshot['license']??null;$remaining=0;
            if($license!==null){$license=PostedTelegramPolicy::normalizeLicense($license,$snapshot);if($license['status']==='active')$remaining=max(0,self::utc($license['expires_at'])-$now);}
            if(in_array($id,$sources,true)&&$remaining<=0)throw new InvalidArgumentException('Одна из подписок уже закончилась. Обновите список.');
            if($remaining>self::MAX_REMAINING)throw new InvalidArgumentException('Срок подписки превышает допустимый предел.');
            $before[$id]=$snapshot;$pool+=$remaining;$sumExpiry+=$now+$remaining;}
        $count=$kind==='align'?count($sources):count($targets);$seconds=intdiv($pool,$count);
        if($seconds<1||$seconds>self::MAX_REMAINING)throw new InvalidArgumentException('Полученный срок подписки выходит за допустимые пределы.');
        $expiry=$kind==='align'?intdiv($sumExpiry,$count):$now+$seconds;
        $operationId??=bin2hex(random_bytes(12));if(preg_match('/\A[a-f0-9]{24}\z/D',$operationId)!==1)throw new InvalidArgumentException('Некорректная операция.');
        return ['version'=>1,'operation_id'=>$operationId,'kind'=>$kind,'user_id'=>$userId,'sources'=>$sources,'targets'=>$targets,'before'=>$before,
            'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$expiry),'review_minute'=>intdiv($expiry,60),'rounding_seconds'=>$pool%$count,
            'created_at'=>$now,'valid_until'=>$now+self::TTL];
    }

    public static function operation(PDO $pdo,array $config,int $userId,string $nonce,int $revision,?int $now=null): array
    {
        if($pdo->inTransaction())throw new LogicException('License operation must own its transaction.');$fixedNow=$now;$now??=time();
        PostedTelegramPolicy::requireOperator($config,$userId,$pdo);
        if(PostedTelegramPolicy::fromConfig($config)['pricing_mode']!=='paid')throw new InvalidArgumentException('Бот работает бесплатно.');
        $actor=$pdo->prepare('SELECT is_active FROM posted_users WHERE telegram_id=:id LIMIT 1');$actor->execute(['id'=>$userId]);if((int)$actor->fetchColumn()!==1)throw new InvalidArgumentException('Пользователь недоступен.');
        $initial=self::read($pdo,$userId);if($initial===null)throw new InvalidArgumentException('Меню подписки устарело.');
        // Replay returns a persisted receipt and never changes another menu or license.
        if($initial['screen']==='receipt'&&hash_equals($initial['nonce'],$nonce)&&($initial['receipt']['confirm_revision']??null)===$revision)return $initial;
        self::current($initial,$nonce,$revision,$now);$plan=$initial['plan'];
        if($initial['screen']!=='review'||!is_array($plan)||($plan['version']??null)!==1||($plan['user_id']??null)!==$userId
            ||($plan['valid_until']??0)<=$now||!is_string($plan['operation_id']??null)||preg_match('/\A[a-f0-9]{24}\z/D',$plan['operation_id'])!==1)throw new InvalidArgumentException('Подтверждение операции устарело.');
        $ids=self::ids(array_values(array_unique([...self::ids($plan['sources']??null,30),...self::ids($plan['targets']??null,30)])),30);
        PostedTelegramRootedAccess::begin($pdo,$config);
        try{
            $channels=[];foreach($ids as$id){$query=$pdo->prepare('SELECT * FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$id]);$channel=$query->fetch();if(!is_array($channel))throw new InvalidArgumentException('Канал недоступен.');PostedTelegramRootedAccess::requireChannel($pdo,$config,$userId,(int)$id);$channels[$id]=$channel;}
            self::actor($pdo,$config,$userId);$flow=self::read($pdo,$userId,true);$now=$fixedNow??time();self::current($flow,$nonce,$revision,$now);
            if($flow['screen']!=='review'||!self::sameJson($flow['plan'],$plan))throw new InvalidArgumentException('Подтверждение операции изменилось.');
            // Channel locks serialize all license writers, including admin grants; the first consistent read is now.
            $snapshots=[];foreach($channels as$id=>$channel)$snapshots[$id]=self::channelSnapshot($channel,PostedTelegramPolicy::readLicense($pdo,$channel,false),$userId);
            if(!self::sameJson($snapshots,$plan['before']))throw new InvalidArgumentException('Права или подписки изменились. Заново выберите каналы.');
            $fresh=self::plan($plan['kind'],$userId,$plan['sources'],$plan['targets'],$snapshots,$now,$plan['operation_id']);
            if($fresh['review_minute']!==$plan['review_minute']){
                $flow['plan']=$fresh;$flow['revision']++;$flow['expires_at']=$now+self::TTL;self::write($pdo,$flow);PostedTelegramRootedAccess::commit($pdo);return $flow;
            }
            $receiptKey='telegram_license_receipt_'.$plan['operation_id'];$query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1');$query->execute(['key'=>$receiptKey]);
            if($query->fetchColumn()!==false)throw new InvalidArgumentException('Операция уже выполнена. Откройте подписку заново.');
            $after=[];$destinations=$plan['kind']==='align'?$plan['sources']:$plan['targets'];
            foreach($ids as$id){$license=in_array($id,$destinations,true)?['version'=>1,'channel_id'=>$id,'chat_id'=>$snapshots[$id]['chat_id'],'expires_at'=>$fresh['expires_at'],'status'=>'active']:$snapshots[$id]['license'];
                if(!in_array($id,$destinations,true))$license['status']='revoked';$license=PostedTelegramPolicy::normalizeLicense($license,$channels[$id]);self::put($pdo,PostedTelegramPolicy::licenseKey($id),$license);$after[$id]=$license;}
            $receipt=['version'=>1,'operation_id'=>$plan['operation_id'],'user_id'=>$userId,'kind'=>$plan['kind'],'before'=>$snapshots,'after'=>$after,
                'rounding_seconds'=>$fresh['rounding_seconds'],'completed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'confirm_revision'=>$revision];
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)')->execute(['key'=>$receiptKey,'value'=>json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $flow['screen']='receipt';$flow['receipt']=$receipt;$flow['plan']=null;$flow['revision']++;$flow['expires_at']=$now+self::TTL;self::write($pdo,$flow);PostedTelegramRootedAccess::commit($pdo);return $flow;
        }catch(Throwable $error){if($pdo->inTransaction())PostedTelegramRootedAccess::rollback($pdo);throw $error;}
    }
}

trait PostedTelegramSubscriptionUi
{
    private function telegramSubscriptionPaid(): bool
    {try{return PostedTelegramPolicy::fromConfig($this->config)['pricing_mode']==='paid';}catch(InvalidArgumentException){return false;}}

    private function telegramSubscriptionText(int $userId,string $ru,string $en): string
    {return $this->telegramUiLanguage($userId)==='en'?$en:$ru;}

    private function telegramSubscriptionChannels(int $userId,bool $owned=false): array
    {
        $result=[];foreach($this->allowedChannels($userId,'post') as$channel){$id=(int)$channel['id'];if(!$owned||(int)$channel['added_by']===$userId)$result[$id]=$channel;}
        if(count($result)>PostedTelegramSubscription::MAX_CHANNELS)throw new InvalidArgumentException('В одном меню можно выбрать не более 500 каналов.');return $result;
    }

    private function telegramSubscriptionActive(array $channel,?int $now=null): bool
    {try{$license=PostedTelegramPolicy::readLicense($this->pdo,$channel);return $license!==null&&$license['status']==='active'&&PostedTelegramSubscription::utc($license['expires_at'])>($now??time());}catch(InvalidArgumentException){return false;}}

    private function showTelegramSubscription(int $userId,?array $target=null,?int $preselect=null,string $screen='status'): void
    {
        if(!$this->telegramSubscriptionPaid()){$this->sendOrEditMessage($userId,$this->telegramSubscriptionText($userId,'Бот полностью бесплатный. Подписка не требуется.','The bot is free. No subscription is required.'),[],$target);return;}
        try{
            $channels=$this->telegramSubscriptionChannels($userId);$selected=[];
            if($preselect!==null){if(!isset($channels[$preselect]))throw new InvalidArgumentException('Канал недоступен.');$selected=[$preselect];$screen='offer';}
            $flow=PostedTelegramSubscription::open($this->pdo,$this->config,$userId,$selected,$screen);$this->renderTelegramSubscription($userId,$flow,$target);
        }catch(InvalidArgumentException $error){$this->sendOrEditMessage($userId,$error->getMessage(),[],$target);}
    }

    private function telegramSubscriptionDate(int $userId,string $date,bool $time=false): string
    {
        $value=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone($this->userTimezoneObject($userId));
        if($this->telegramUiLanguage($userId)==='en')return strtolower($value->format('j M Y')).' y'.($time?' '.$value->format('H:i:s T'):'');
        $months=['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
        return $value->format('j').' '.$months[(int)$value->format('n')-1].' '.$value->format($time?'Y г H:i:s T':'Y г');
    }

    private function telegramSubscriptionPeriod(int $userId,int $days): string
    {return $this->telegramSubscriptionText($userId,[30=>'1 месяц',90=>'3 месяца',180=>'6 месяцев',365=>'1 год'][$days],[30=>'1 month',90=>'3 months',180=>'6 months',365=>'1 year'][$days]);}

    private function renderTelegramSubscription(int $userId,array $flow,?array $target=null,?string $notice=null): void
    {
        $flow=PostedTelegramSubscription::validate($flow);$channels=$this->telegramSubscriptionChannels($userId);$owned=$this->telegramSubscriptionChannels($userId,true);
        $prices=PostedTelegramSubscription::tariffs($this->config);$amount=$prices[$flow['days']]*count($flow['selected']);$screen=$flow['screen'];
        $button=static fn(string $text,string $action,?int $argument=null):array=>['text'=>$text,'callback_data'=>PostedTelegramSubscription::callback($flow,$action,$argument)];
        $back=$this->telegramSubscriptionText($userId,'← Назад','← Back');$keyboard=[];$text='';
        if($screen==='status'){
            $text=$this->customEmojiHtml('5258204546391351475','💰').' <b>'.$this->telegramSubscriptionText($userId,'ПОДПИСКА','SUBSCRIPTION')."</b>\n\n".
                $this->telegramSubscriptionText($userId,'Чтобы пользоваться GitPosted, необходима платная подписка. Чтобы подключить подписку для ваших каналов, нажмите <b>«Далее»</b>.','To use GitPosted, a paid subscription is required. To activate a subscription for your channels, click <b>«Next»</b>.');
            $lines=[];foreach($channels as$channel)if($this->telegramSubscriptionActive($channel)){$license=PostedTelegramPolicy::readLicense($this->pdo,$channel);$title=(string)$channel['title'];if(mb_strlen($title)>17)$title=mb_substr($title,0,16).'…';
                $name=htmlspecialchars($title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$username=(string)($channel['username']??'');if(preg_match('/\A[A-Za-z][A-Za-z0-9_]{3,31}\z/D',$username)===1)$name='<a href="https://t.me/'.$username.'">'.$name.'</a>';
                $lines[]=$this->customEmojiHtml('5260268501515377807','📢').' '.$name.' ('.$this->telegramSubscriptionDate($userId,$license['expires_at']).')';}
            if($lines!==[]){$activeCount=count($lines);$lines=array_slice($lines,0,20);if($activeCount>20)$lines[]=$this->telegramSubscriptionText($userId,'Ещё каналов с подпиской: ','More subscribed channels: ').($activeCount-20);
                $text.="\n\n<blockquote expandable><b>".$this->telegramSubscriptionText($userId,'Активные подписки:','Active subscriptions:')."</b>\n".implode("\n",$lines).'</blockquote>';
                $keyboard[]=[$button($this->telegramSubscriptionText($userId,'Выровнять подписку','Align subscription'),'align')];$keyboard[]=[$button($this->telegramSubscriptionText($userId,'Перенести подписку','Transfer subscription'),'move')];}
            $keyboard[]=[$button($back,'settings'),$button($this->telegramSubscriptionText($userId,'Далее →','Next →'),'offer')];
        }elseif($screen==='offer'){
            $text=$this->customEmojiHtml('5258204546391351475','💰').' <b>'.$this->telegramSubscriptionText($userId,'ОПЛАТА ПОДПИСКИ','PAYMENT FOR SUBSCRIPTION')."</b>\n\n".
                $this->telegramSubscriptionText($userId,'Выберите каналы и длительность подписки, а затем нажмите <b>«К оплате»</b>.','Select channels and subscription duration, and then click <b>«Payment»</b>.')."\n\n<b>".$this->telegramSubscriptionText($userId,'Стоимость:','Final cost:').'</b> '.number_format($amount,0,'.',',').'₽';
            $keyboard[]=[$button($this->telegramSubscriptionText($userId,'Каналы: ','Channels count: ').count($flow['selected']).$this->telegramSubscriptionText($userId,' шт.',''),'channels')];
            $keyboard[]=[$button($this->telegramSubscriptionText($userId,'Длительность: ','Duration: ').$this->telegramSubscriptionPeriod($userId,$flow['days']),'periods')];
            $keyboard[]=[$button($this->telegramSubscriptionText($userId,'🎁 Ввести промокод','🎁 Enter promocode'),'promo')];
            $keyboard[]=[$button($back,'status'),$button($this->telegramSubscriptionText($userId,'💰 К оплате →','💰 Pay →'),'pay')];
        }elseif($screen==='periods'){
            $text=$this->telegramSubscriptionText($userId,'Выберите длительность подписки. Дешевле брать подписку на больший срок.',"Select the duration of your subscription. It's cheaper to subscribe for a longer period.");
            foreach([30,90,180,365] as$days){$item=$button($this->telegramSubscriptionPeriod($userId,$days),'period',$days);if($days===$flow['days'])$item['style']='primary';$keyboard[]=[$item];}$keyboard[]=[$button($back,'offer')];
        }elseif(in_array($screen,['channels','align','move','moveto'],true)){
            $operation=$screen!=='channels';$items=$operation?$owned:$channels;$selection=$screen==='channels'?$flow['selected']:($screen==='moveto'?$flow['targets']:$flow['sources']);
            if(in_array($screen,['align','move'],true))$items=array_filter($items,fn(array $channel):bool=>$this->telegramSubscriptionActive($channel));
            if($screen==='moveto')$items=array_diff_key($items,array_flip($flow['sources']));
            if($screen==='channels')$text=$this->telegramSubscriptionText($userId,'Выберите каналы, в которых хотите приобрести подписку.','Choose channels you want to subscribe.')."\n\n<b>".$this->telegramSubscriptionText($userId,'Стоимость:','Price:').'</b> '.$amount.'₽';
            elseif($screen==='align')$text=$this->customEmojiHtml('5257965174979042426','📝').' <b>'.$this->telegramSubscriptionText($userId,'ВЫРАВНИВАНИЕ ПОДПИСКИ','ALIGN SUBSCRIPTIONS')."</b>\n\n".$this->telegramSubscriptionText($userId,'Выберите каналы, в которых вы хотите выровнять подписку. Во всех каналах после выравнивания срок окончания подписки будет одинаковым.','Choose channels to align. Their subscriptions will expire at the same time.');
            elseif($screen==='move')$text=$this->customEmojiHtml('5226513232549664618','🔢').' <b>'.$this->telegramSubscriptionText($userId,'ПЕРЕНОС ПОДПИСКИ','MOVE SUBSCRIPTIONS')."</b>\n\n".$this->telegramSubscriptionText($userId,'Выберите свои каналы, с которых хотите убрать подписку. Оставшийся срок будет передан выбранным каналам.','Choose your channels to remove subscriptions from. Their remaining time will move to the selected destination channels.');
            else $text=$this->telegramSubscriptionText($userId,'Выберите свои каналы, на которые хотите перенести оставшийся срок подписки.','Choose your destination channels to receive the remaining subscription time.');
            $page=min($flow['page'],max(0,(int)ceil(count($items)/20)-1));foreach(array_slice($items,$page*20,20,true) as$id=>$channel){$title=mb_strimwidth((string)$channel['title'],0,64,'…','UTF-8');if($operation&&$this->telegramSubscriptionActive($channel)){$license=PostedTelegramPolicy::readLicense($this->pdo,$channel);$title.=' ('.$this->telegramSubscriptionDate($userId,$license['expires_at']).')';}
                $item=$button($title,$screen==='moveto'?'target':'toggle',$id);if(in_array($id,$selection,true))$item['style']='primary';$keyboard[]=[$item];}
            if(count($items)>20){$row=[];if($page>0)$row[]=$button('←','page',$page-1);if(($page+1)*20<count($items))$row[]=$button('→','page',$page+1);$keyboard[]=$row;}
            if($screen==='channels')$keyboard[]=[$button($this->telegramSubscriptionText($userId,'Выбрать каналы без подписки','Select channels without a subscription'),'unpaid')];
            $keyboard[]=[$button($this->telegramSubscriptionText($userId,'Выбрать все','Select all'),'all'),$button($this->telegramSubscriptionText($userId,'Cнять все','Clear all'),'none')];
            if($screen==='align')$keyboard[]=[$button($this->telegramSubscriptionText($userId,'💾 Выровнять подписку','💾 Align subscriptions'),'review')];
            elseif($screen==='move')$keyboard[]=[$button($this->telegramSubscriptionText($userId,'Выбрать каналы для переноса','Choose destination channels'),'moveto')];
            elseif($screen==='moveto')$keyboard[]=[$button($this->telegramSubscriptionText($userId,'Перенести подписку','Move subscriptions'),'review')];
            $keyboard[]=[$button($back,$screen==='channels'?'offer':($screen==='moveto'?'move':'status'))];
        }elseif($screen==='promo'){
            $text=$this->customEmojiHtml('5341383052004179258','🎁').' <b>'.$this->telegramSubscriptionText($userId,'ПРОМОКОД','PROMO CODE')."</b>\n\n".$this->telegramSubscriptionText($userId,'Отправьте промокод, чтобы применить его.','Send a promo code to apply it.');
            $user=$this->user($userId);if(!empty($user['state']))$text.="\n\n".$this->telegramSubscriptionText($userId,'Ответьте на это сообщение, чтобы не прервать текущую работу с публикацией.','Reply to this message to keep your current publication workflow.');$keyboard[]=[$button($back,'offer')];
        }elseif($screen==='checkout'){
            if(PostedTelegramBillingApi::configured($this->config)){
                $text=$this->telegramSubscriptionText($userId,
                    'Проверьте выбранные каналы, срок и сумму на странице оплаты. Оплата проходит в YooKassa; подписка активируется после серверного подтверждения. Возврат со страницы оплаты сам по себе не подтверждает платёж.',
                    'Review the selected channels, duration and amount on the payment page. Payment is handled by YooKassa; the subscription activates after server verification. Returning from the payment page alone does not confirm payment.');
            }else{
            $text=$this->telegramSubscriptionText($userId,'Оплата подписки пока не подключена. Страница оплаты — заглушка; списания и активация подписки не выполняются.','Subscription payments are not connected yet. The payment page is a placeholder; it does not charge or activate subscriptions.');
            }
            $keyboard[]=[['text'=>$this->telegramSubscriptionText($userId,'💰 Открыть страницу оплаты','💰 Open payment page'),'url'=>PostedTelegramPolicy::checkoutSelectionUrl($this->serviceBaseUrl(),$flow['quote'])]];$keyboard[]=[$button($back,'offer')];
        }elseif($screen==='review'){
            $plan=$flow['plan'];if(!is_array($plan))throw new InvalidArgumentException('Подтверждение устарело.');
            $text='<b>'.$this->telegramSubscriptionText($userId,'Проверьте изменение подписок','Review subscription changes')."</b>\n\n";
            foreach($plan['before'] as$id=>$snapshot){$old=$snapshot['license'];$oldText=$old!==null&&$old['status']==='active'?$this->telegramSubscriptionDate($userId,$old['expires_at'],true):$this->telegramSubscriptionText($userId,'нет активной подписки','no active subscription');
                $isSource=$plan['kind']==='move'&&in_array((int)$id,$plan['sources'],true);$new=$isSource?$this->telegramSubscriptionText($userId,'подписка отключена','subscription removed'):$this->telegramSubscriptionDate($userId,$plan['expires_at'],true);
                $text.=htmlspecialchars(mb_substr($snapshot['title'],0,18),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n".$oldText.' → '.$new."\n\n";}
            $text.=$this->telegramSubscriptionText($userId,'Потеря при округлении: ','Time lost to rounding: ').$plan['rounding_seconds'].$this->telegramSubscriptionText($userId,' сек.',' sec.');
            if($plan['kind']==='move')$text.="\n".$this->telegramSubscriptionText($userId,'Срок рассчитывается на момент подтверждения. Если отображаемая минута изменится, потребуется повторное подтверждение.','Expiry is calculated when confirmed. If the displayed minute changes, you will review it again.');
            $keyboard[]=[$button($plan['kind']==='align'?$this->telegramSubscriptionText($userId,'💾 Выровнять подписку','💾 Align subscriptions'):$this->telegramSubscriptionText($userId,'Перенести подписку','Move subscriptions'),'ok')];$keyboard[]=[$button($back,$plan['kind']==='align'?'align':'moveto')];
        }elseif($screen==='receipt'){
            $receipt=$flow['receipt'];$text='<b>'.$this->telegramSubscriptionText($userId,'Подписки обновлены','Subscriptions updated')."</b>\n\n";
            foreach($receipt['after'] as$id=>$license)$text.=htmlspecialchars(mb_substr($receipt['before'][$id]['title'],0,18),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').' — '.($license['status']==='active'?$this->telegramSubscriptionDate($userId,$license['expires_at'],true):$this->telegramSubscriptionText($userId,'подписка отключена','subscription removed'))."\n";
            $text.="\n".$this->telegramSubscriptionText($userId,'Потеря при округлении: ','Time lost to rounding: ').$receipt['rounding_seconds'].$this->telegramSubscriptionText($userId,' сек.',' sec.');$keyboard[]=[$button($back,'status')];
        }elseif(in_array($screen,['referral','creative'],true)){
            [$text,$keyboard]=$this->telegramReferralView($userId,$flow);
        }
        if($notice!==null)$text.="\n\n".htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $messageId=$this->sendOrEditMessage($userId,$text,['parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true],'reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
        if($screen==='promo'&&$messageId>0){
            // No revision change: only record the prompt if the rendered menu is still current.
            $this->pdo->prepare("UPDATE posted_app_settings SET setting_value=JSON_SET(setting_value,'$.promo_prompt_id',CAST(:message AS UNSIGNED)) WHERE setting_key=:key AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.nonce'))=:nonce AND CAST(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.revision')) AS UNSIGNED)=CAST(:revision AS UNSIGNED) AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.screen'))='promo'")
                ->execute(['message'=>$messageId,'key'=>PostedTelegramSubscription::key($userId),'nonce'=>$flow['nonce'],'revision'=>$flow['revision']]);
        }
    }

    private function handleTelegramSubscriptionCallback(int $userId,string $callback,?array $target=null): bool
    {
        if(!str_starts_with($callback,'sub:'))return false;
        if(!$this->telegramSubscriptionPaid()){$this->showTelegramSubscription($userId,$target);return true;}
        try{
            if($callback==='sub:open'||$callback==='sub:referral'){$this->showTelegramSubscription($userId,$target,null,$callback==='sub:referral'?'referral':'status');return true;}
            if(preg_match('/\Asub:buy:([1-9][0-9]{0,18})\z/D',$callback,$match)===1){$this->showTelegramSubscription($userId,$target,(int)PostedTelegramPolicy::positiveId($match[1]));return true;}
            [$nonce,$revision,$action,$argument]=PostedTelegramSubscription::parse($callback);
            if($action==='ok'){$flow=PostedTelegramSubscription::operation($this->pdo,$this->config,$userId,$nonce,$revision);$this->renderTelegramSubscription($userId,$flow,$target);return true;}
            $channels=$this->telegramSubscriptionChannels($userId);$owned=$this->telegramSubscriptionChannels($userId,true);
            if($action==='pay'){[$flow]=PostedTelegramSubscription::quote($this->pdo,$this->config,$userId,$nonce,$revision,$channels);$this->renderTelegramSubscription($userId,$flow,$target);return true;}
            $flow=PostedTelegramSubscription::mutate($this->pdo,$this->config,$userId,$nonce,$revision,function(array $flow)use($action,$argument,$channels,$owned,$userId):array {
                $screen=$flow['screen'];$flow['quote']=null;$flow['receipt']=null;
                if(in_array($action,['status','offer','channels','periods','promo','referral','creative'],true)){$flow['screen']=$action;$flow['page']=0;$flow['plan']=null;return $flow;}
                if($action==='settings')return $flow;
                if($action==='period'){if($screen!=='periods'||!in_array($argument,[30,90,180,365],true))throw new InvalidArgumentException('Некорректный период подписки.');$flow['days']=$argument;$flow['screen']='offer';return $flow;}
                if(in_array($action,['align','move'],true)){$flow['screen']=$action;$flow['sources']=[];$flow['targets']=[];$flow['page']=0;$flow['plan']=null;return $flow;}
                if($action==='moveto'){if(!in_array($screen,['move','review'],true)||$flow['sources']===[])throw new InvalidArgumentException('Выберите каналы, с которых переносится подписка.');$flow['screen']='moveto';$flow['page']=0;$flow['plan']=null;return $flow;}
                if($action==='review'){
                    if(!in_array($screen,['align','moveto'],true))throw new InvalidArgumentException('Выберите каналы для операции.');$snapshots=[];
                    foreach(array_unique([...$flow['sources'],...$flow['targets']]) as$id){$channel=$owned[$id]??null;if(!is_array($channel))throw new InvalidArgumentException('Канал недоступен.');$snapshots[$id]=PostedTelegramSubscription::channelSnapshot($channel,PostedTelegramPolicy::readLicense($this->pdo,$channel),$userId);}
                    ksort($snapshots,SORT_NUMERIC);$flow['plan']=PostedTelegramSubscription::plan($screen==='align'?'align':'move',$userId,$flow['sources'],$flow['targets'],$snapshots);$flow['screen']='review';return $flow;
                }
                if(!in_array($screen,['channels','align','move','moveto'],true))throw new InvalidArgumentException('Это действие недоступно в текущем меню.');
                $field=$screen==='channels'?'selected':($screen==='moveto'?'targets':'sources');$items=$screen==='channels'?$channels:$owned;
                if(in_array($screen,['align','move'],true))$items=array_filter($items,fn(array $channel):bool=>$this->telegramSubscriptionActive($channel));
                if($screen==='moveto')$items=array_diff_key($items,array_flip($flow['sources']));
                if($action==='page'){if($argument===null||$argument>max(0,(int)ceil(count($items)/20)-1))throw new InvalidArgumentException('Страница недоступна.');$flow['page']=$argument;return $flow;}
                if($action==='none')$flow[$field]=[];
                elseif($action==='all')$flow[$field]=array_keys($items);
                elseif($action==='unpaid'&&$screen==='channels')$flow[$field]=array_keys(array_filter($items,fn(array $channel):bool=>!$this->telegramSubscriptionActive($channel)));
                elseif(($action==='toggle'&&$screen!=='moveto')||($action==='target'&&$screen==='moveto')){if($argument===null||!isset($items[$argument]))throw new InvalidArgumentException('Канал недоступен.');$flow[$field]=in_array($argument,$flow[$field],true)?array_values(array_diff($flow[$field],[$argument])):[...$flow[$field],$argument];}
                else throw new InvalidArgumentException('Действие недоступно.');
                $flow[$field]=PostedTelegramSubscription::ids($flow[$field],$screen==='channels'?500:30);if($screen==='move')$flow['targets']=[];return $flow;
            });
            if($action==='settings'){$this->showSettings($userId,$target);return true;}$this->renderTelegramSubscription($userId,$flow,$target);
        }catch(InvalidArgumentException $error){$this->sendOrEditMessage($userId,$error->getMessage(),[],$target);}
        return true;
    }

    /** Promo input never takes text away from a different draft: such users must reply to this prompt. */
    private function handleTelegramSubscriptionInput(int $userId,array $message): bool
    {
        if(!$this->telegramSubscriptionPaid()||!is_string($message['text']??null)||str_starts_with(ltrim($message['text']),'/'))return false;
        $menuText=PostedTelegramUi::mainButtonAction(trim($message['text']));
        if(in_array($menuText,['Меню','Создать пост','Изменить пост','Контент-план','Настройки','Шаблоны','Каталог эмодзи','Мои каналы'],true))return false;
        try{$flow=PostedTelegramSubscription::read($this->pdo,$userId);}catch(InvalidArgumentException){return false;}
        if($flow===null||$flow['screen']!=='promo'||$flow['expires_at']<=time())return false;
        $user=$this->user($userId);if(!empty($user['state'])&&(int)($message['reply_to_message']['message_id']??0)!==$flow['promo_prompt_id'])return false;
        try{$next=PostedTelegramSubscription::mutate($this->pdo,$this->config,$userId,$flow['nonce'],$flow['revision'],static fn(array $current):array=>$current);
            $this->renderTelegramSubscription($userId,$next,null,$this->telegramSubscriptionText($userId,'Промокоды пока не настроены. Скидка не применена.','Promo codes are not configured yet. No discount was applied.'));
        }catch(InvalidArgumentException $error){$this->sendOrEditMessage($userId,$error->getMessage());}return true;
    }

    private function telegramReferralView(int $userId,array $flow): array
    {
        $username=$this->botUsername();if(preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D',$username)!==1)throw new InvalidArgumentException('Имя бота не настроено.');
        $url='https://t.me/'.$username.'?start='.$userId;$count=0;
        $query=$this->pdo->prepare("SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key LIKE 'telegram!_referral!_%' ESCAPE '!' AND JSON_VALID(setting_value) AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.inviter_id'))=:inviter");$query->execute(['inviter'=>(string)$userId]);
        while($row=$query->fetch()){try{$value=json_decode((string)$row['setting_value'],true,8,JSON_THROW_ON_ERROR);}catch(JsonException){continue;}
            if(is_array($value)&&count($value)===4&&($value['version']??null)===1&&($value['inviter_id']??null)===$userId&&is_int($value['invitee_id']??null)&&$value['invitee_id']>0&&$value['invitee_id']!==$userId&&$row['setting_key']==='telegram_referral_'.$value['invitee_id']&&is_string($value['joined_at']??null))$count++;}
        $back=['text'=>$this->telegramSubscriptionText($userId,'← Назад','← Back'),'callback_data'=>PostedTelegramSubscription::callback($flow,$flow['screen']==='creative'?'referral':'settings')];
        if($flow['screen']==='creative'){
            $text=$this->customEmojiHtml('5258093637450866522','🤖').' <b>'.$this->telegramSubscriptionText($userId,'Удобный постинг в Telegram','Convenient Telegram publishing')."</b>\n\n<a href=\"".$url.'">@'.$username.'</a> — '.$this->telegramSubscriptionText($userId,'бот для создания, редактирования и отложенной публикации постов с ','a bot to create, edit and schedule posts with ').$this->customEmojiHtml('5258165702707125574','⭐️').' premium-эмодзи.'."\n\n".$this->telegramSubscriptionText($userId,'Создавайте публикации для своего проекта с GitPosted.','Create posts for your project with GitPosted.');return [$text,[[$back]]];
        }
        $text=$this->customEmojiHtml('5258513401784573443','👥').' <b>'.$this->telegramSubscriptionText($userId,'РЕФЕРАЛЬНАЯ ПРОГРАММА','REFERRAL PROGRAM')."</b>\n\n<b>".$this->telegramSubscriptionText($userId,'Приглашено пользователей: ','Users invited: ').$count."</b>\n\n".
            $this->telegramSubscriptionText($userId,'Приглашайте друзей и знакомых в GitPosted. Переход нового пользователя по вашей ссылке учитывается при первом запуске бота.','Invite friends to GitPosted. New users are counted when they first start the bot with your link.')."\n\n<b>".
            $this->telegramSubscriptionText($userId,'Просто поделитесь персональной ссылкой или воспользуйтесь заготовленным креативом:','Share your personal link or use the prepared creative:')."</b>\n\n".$url;
        return [$text,[[['text'=>$this->telegramSubscriptionText($userId,'Получить рекламный креатив','Get promotional creative'),'callback_data'=>PostedTelegramSubscription::callback($flow,'creative')]],[$back]]];
    }

    /** Invoke once, only when /start has just created a genuinely new user; repeat /start cannot overwrite attribution. */
    private function trackTelegramSubscriptionReferral(int $inviteeId,string $payload,bool $newUser): void
    {
        if(!$newUser||!$this->telegramSubscriptionPaid()||preg_match('/\A[1-9][0-9]{0,18}\z/D',$payload)!==1)return;
        try{$inviter=(int)PostedTelegramPolicy::positiveId($payload);}catch(InvalidArgumentException){return;}if($inviter===$inviteeId||!PostedTelegramPolicy::operatorAllowed($this->config,$inviteeId,$this->pdo))return;
        $query=$this->pdo->prepare('SELECT telegram_id FROM posted_users WHERE telegram_id=:id AND is_active=1 LIMIT 1');$query->execute(['id'=>$inviter]);if($query->fetchColumn()===false)return;
        $value=['version'=>1,'invitee_id'=>$inviteeId,'inviter_id'=>$inviter,'joined_at'=>gmdate('Y-m-d\TH:i:s\Z')];
        $this->pdo->prepare('INSERT IGNORE INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)')->execute(['key'=>'telegram_referral_'.$inviteeId,'value'=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }

    /** Send after the existing success message/effect; no such second message in free mode. */
    private function telegramSubscriptionChannelConnected(int $userId,array $channel,?int $verifiedMemberCount=null): void
    {
        if(!$this->telegramSubscriptionPaid())return;$state=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'premium');
        if($state['state']==='active')return;
        $basic=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'basic',$verifiedMemberCount);
        $text=$basic['state']==='trial'?$this->telegramSubscriptionText($userId,'Вы можете пользоваться ботом бесплатно, пока ваш канал не достигнет отметки в 500 подписчиков. Чтобы сразу продлить доступ, нажмите на кнопку ниже.','You can use the bot for free until your channel reaches 500 subscribers. To extend access now, press the button below.')
            :$this->telegramSubscriptionText($userId,'Чтобы пользоваться ботом в этом канале, подключите подписку. Нажмите на кнопку ниже.','To use the bot in this channel, connect a subscription using the button below.');
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>[
            [['text'=>$this->telegramSubscriptionText($userId,'Приобрести подписку','Buy subscription'),'callback_data'=>'sub:buy:'.(int)$channel['id']]],
            [['text'=>$this->telegramSubscriptionText($userId,'➕ Добавить ещё канал','➕ Add another channel'),'callback_data'=>'settings:add_channel']],
        ]]]);
    }
}
