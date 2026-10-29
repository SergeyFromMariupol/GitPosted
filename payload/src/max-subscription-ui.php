<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/max-policy.php';

/** Subscription menus have their own CAS record. They never borrow a publication input state. */
final class GitPostedMaxSubscription
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

    public static function fresh(string $userId,array $selected=[],string $screen='status',?int $now=null): array
    {
        GitPostedMaxPolicy::actorId($userId);$now??=time();
        return self::validate(['version'=>1,'user_id'=>$userId,'nonce'=>bin2hex(random_bytes(12)),'revision'=>0,'expires_at'=>$now+self::TTL,
            'screen'=>$screen,'days'=>30,'selected'=>self::ids($selected),'sources'=>[],'targets'=>[],'page'=>0,'plan'=>null,'receipt'=>null,'quote'=>null,'promo_prompt_id'=>0]);
    }

    public static function validate(mixed $flow): array
    {
        $expected=['days','expires_at','nonce','page','plan','promo_prompt_id','quote','receipt','revision','screen','selected','sources','targets','user_id','version'];
        if(!is_array($flow))throw new InvalidArgumentException('Меню подписки повреждено.');$keys=array_keys($flow);sort($keys);
        if($keys!==$expected||($flow['version']??null)!==1||!is_string($flow['user_id']??null)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$flow['user_id'])!==1
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

    public static function key(string $userId): string
    {return 'max_subscription_flow_'.GitPostedMaxPolicy::actorId($userId);}

    /** MySQL JSON may reorder object keys. Bindings retain exact scalar types and ordered lists. */
    public static function sameJson(mixed $left,mixed $right): bool
    {return self::canonicalJson($left)===self::canonicalJson($right);}

    private static function canonicalJson(mixed $value): mixed
    {
        if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$item)$value[$key]=self::canonicalJson($item);return $value;
    }

    public static function read(PDO $pdo,string $userId,bool $lock=false): ?array
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
        if(preg_match('/\Amax_(?:subscription_flow|channel_license)_[1-9][0-9]{0,18}\z/D',$key)!==1)throw new LogicException('Unexpected subscription setting key.');
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['key'=>$key,'value'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }

    public static function actor(PDO $pdo,array $config,string $userId): void
    {
        GitPostedMaxPolicy::requireOperator($config,$userId);
        $query=$pdo->prepare('SELECT max_user_id,is_active FROM posted_max_users WHERE max_user_id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$userId]);$actor=$query->fetch();
        if(!is_array($actor)||(int)$actor['is_active']!==1)throw new InvalidArgumentException('Пользователь недоступен.');
        if(GitPostedMaxPolicy::fromConfig($config)['pricing_mode']!=='paid')throw new InvalidArgumentException('Бот работает бесплатно.');
    }

    public static function open(PDO $pdo,array $config,string $userId,array $selected=[],string $screen='status',?int $now=null): array
    {
        if($pdo->inTransaction())throw new LogicException('Subscription flow must own its transaction.');$pdo->beginTransaction();
        try{self::actor($pdo,$config,$userId);$flow=self::fresh($userId,$selected,$screen,$now);self::write($pdo,$flow);$pdo->commit();return $flow;}
        catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function mutate(PDO $pdo,array $config,string $userId,string $nonce,int $revision,Closure $change,?int $now=null): array
    {
        if($pdo->inTransaction())throw new LogicException('Subscription flow must own its transaction.');$now??=time();$pdo->beginTransaction();
        try{self::actor($pdo,$config,$userId);$flow=self::read($pdo,$userId,true);self::current($flow,$nonce,$revision,$now);
            $next=$change($flow);if(!is_array($next)||$next['nonce']!==$flow['nonce']||$next['user_id']!==$userId)throw new LogicException('Invalid subscription transition.');
            $next['revision']=$revision+1;$next['expires_at']=$now+self::TTL;self::write($pdo,$next);$pdo->commit();return $next;
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    public static function current(?array $flow,string $nonce,int $revision,int $now): void
    {if($flow===null||!hash_equals($flow['nonce'],$nonce)||$flow['revision']!==$revision||$flow['expires_at']<=$now||$revision>=999999)throw new InvalidArgumentException('Это меню устарело. Откройте подписку заново.');}

    public static function callback(array $flow,string $action,string|int|null $argument=null): string
    {
        if(!in_array($action,self::ACTIONS,true))throw new InvalidArgumentException('Неизвестное действие подписки.');
        $value='gp:subscription:'.$flow['nonce'].':'.$flow['revision'].':'.$action.($argument!==null?':'.$argument:'');
        if(strlen($value)>160)throw new InvalidArgumentException('Слишком длинная кнопка подписки.');return $value;
    }

    public static function parse(string $callback): array
    {
        if(strlen($callback)>160||preg_match('/\Agp:subscription:([a-f0-9]{24}):(0|[1-9][0-9]{0,5}):([a-z]{1,12})(?::([0-9]{1,19}))?\z/D',$callback,$matches)!==1
            ||!in_array($matches[3],self::ACTIONS,true))throw new InvalidArgumentException('Некорректная кнопка подписки.');
        $argument=null;if(isset($matches[4])){
            if($matches[3]==='page'){
                if(preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D',$matches[4])!==1||(int)$matches[4]>1000)throw new InvalidArgumentException('Некорректная страница.');$argument=(int)$matches[4];
            }else $argument=(int)GitPostedMaxPolicy::positiveId($matches[4]);
        }
        return [$matches[1],(int)$matches[2],$matches[3],$argument];
    }

    public static function quote(PDO $pdo,array $config,string $userId,string $nonce,int $revision,array $channels,?int $now=null): array
    {
        $now??=time();$prices=self::tariffs($config);$quote=null;
        $flow=self::mutate($pdo,$config,$userId,$nonce,$revision,static function(array $flow)use($pdo,$prices,$channels,$userId,$now,&$quote):array {
            if($flow['screen']!=='offer'||$flow['selected']===[])throw new InvalidArgumentException('Выберите каналы для подписки.');
            $bindings=[];foreach($flow['selected'] as$id){$channel=$channels[$id]??null;if(!is_array($channel))throw new InvalidArgumentException('Доступ к выбранному каналу изменился.');
                $bindings[]=['channel_id'=>$id,'chat_id'=>GitPostedMaxPolicy::chatId($channel['chat_id']??null)];}
            $token=bin2hex(random_bytes(12));$quote=['version'=>1,'token'=>$token,'user_id'=>$userId,'channels'=>$bindings,'period_days'=>$flow['days'],
                'amount_rub'=>$prices[$flow['days']]*count($bindings),'currency'=>'RUB','created_at'=>$now,'expires_at'=>$now+self::TTL];
            // A new random key is insert-only: a price selection cannot rewrite a previous quote.
            // A future payment provider MUST revalidate current ACL, channel identity, tariff and expiry before charging or granting.
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)')
                ->execute(['key'=>'max_checkout_quote_'.$token,'value'=>json_encode($quote,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $flow['quote']=$token;$flow['screen']='checkout';return $flow;
        },$now);return [$flow,$quote];
    }

    /** SQL-only discovery: no MAX API, account linking, ownership cleanup or bot construction. */
    public static function channels(PDO $pdo,array $config,string $userId): array
    {
        GitPostedMaxPolicy::requireOperator($config,$userId);GitPostedMaxPolicy::actorId($userId);
        $q=$pdo->prepare('SELECT max_user_id,is_active FROM posted_max_users WHERE max_user_id=? LIMIT 1');$q->execute([$userId]);$u=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($u)||(int)$u['is_active']!==1)throw new InvalidArgumentException('Пользователь недоступен.');
        $q=$pdo->prepare('SELECT c.* FROM posted_max_channels c LEFT JOIN posted_max_permissions p ON p.channel_id=c.id AND p.max_user_id=? WHERE c.is_active=1 AND c.is_archived=0 AND c.admin_blocked=0 AND (c.added_by=? OR p.can_post=1) ORDER BY c.title,c.id LIMIT 501');$q->execute([$userId,$userId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>self::MAX_CHANNELS)throw new InvalidArgumentException('Слишком много каналов для одного меню.');$out=[];
        foreach($rows as$c){$id=(int)GitPostedMaxPolicy::positiveId($c['id']);GitPostedMaxPolicy::chatId($c['chat_id']);$out[$id]=$c;}return$out;
    }

    public static function utc(string $value): int
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new InvalidArgumentException('Некорректный срок подписки.');return $date->getTimestamp();
    }

}

/** Subscription UI has a dedicated nonce/revision record; publication input state is never changed. */
trait GitPostedMaxSubscriptionUiTrait
{
    private function maxSubscriptionPaid(): bool
    {try{return GitPostedMaxPolicy::fromConfig($this->config)['pricing_mode']==='paid';}catch(InvalidArgumentException){return false;}}

    /** Match the stored private dialog before upsert, so forwarded buttons cannot replace its binding. */
    private function maxSubscriptionAssertActor(string $userId,?string $chatId=null): array
    {
        GitPostedMaxPolicy::requireOperator($this->config,$userId);$user=$this->user($userId);
        if(($user['max_user_id']??null)!==$userId||(int)($user['is_active']??0)!==1||!is_string($user['private_chat_id']??null)||$user['private_chat_id']===''
            ||($chatId!==null&&!hash_equals($user['private_chat_id'],$chatId)))throw new InvalidArgumentException('Откройте подписку в личном чате GitPosted.');
        return$user;
    }

    private function showMaxSubscription(string $userId,?int $preselect=null,string $screen='status'): void
    {
        $this->maxSubscriptionAssertActor($userId);
        if(!$this->maxSubscriptionPaid()){$this->api->sendToUser($userId,'Бот работает бесплатно. Платная подписка не требуется.');return;}
        if(!in_array($screen,['status','offer'],true))throw new InvalidArgumentException('Некорректный раздел подписки.');
        $channels=GitPostedMaxSubscription::channels($this->pdo,$this->config,$userId);$selected=[];
        if($preselect!==null){if(!isset($channels[$preselect]))throw new InvalidArgumentException('Канал недоступен.');$selected=[$preselect];$screen='offer';}
        $flow=GitPostedMaxSubscription::open($this->pdo,$this->config,$userId,$selected,$screen);
        $this->renderMaxSubscription($userId,$flow,$channels);
    }

    /** Return true only for this namespace. It must be dispatched before generic input-state clearing. */
    private function handleMaxSubscriptionCallback(string $callbackId,string $userId,string $chatId,string $payload): bool
    {
        if($payload!=='gp:subscription:open'&&!str_starts_with($payload,'gp:subscription:'))return false;
        try{
            $this->maxSubscriptionAssertActor($userId,$chatId);
            if($payload==='gp:subscription:open'){$this->showMaxSubscription($userId);return true;}
            [$nonce,$revision,$action,$argument]=GitPostedMaxSubscription::parse($payload);
            $channels=GitPostedMaxSubscription::channels($this->pdo,$this->config,$userId);
            if($action==='pay'){
                [, $quote]=GitPostedMaxSubscription::quote($this->pdo,$this->config,$userId,$nonce,$revision,$channels);
                $url=GitPostedMaxPolicy::checkoutSelectionUrl((string)($this->config['base_url']??''),$quote['token']);
                $this->api->sendToUser($userId,"ОПЛАТА ПОДПИСКИ\n\nСрок и стоимость выбранных каналов сохранены в заказе. Оплата и проверка её результата откроются на странице GitPosted.",[],[
                    [GitPostedMaxApi::linkButton('Перейти к оплате',$url,'positive')],[GitPostedMaxApi::callbackButton('← Подписка','gp:subscription:open')],
                ]);return true;
            }
            $flow=GitPostedMaxSubscription::mutate($this->pdo,$this->config,$userId,$nonce,$revision,static function(array $flow)use($action,$argument,$channels):array{
                switch($action){
                    case'status':case'offer':case'channels':case'periods':$flow['screen']=$action;$flow['page']=0;break;
                    case'period':if(!in_array($argument,[30,90,180,365],true))throw new InvalidArgumentException('Некорректный срок.');$flow['days']=$argument;$flow['screen']='offer';break;
                    case'page':if(!is_int($argument)||$argument<0||$argument>1000)throw new InvalidArgumentException('Некорректная страница.');$flow['page']=$argument;break;
                    case'toggle':if(!isset($channels[$argument]))throw new InvalidArgumentException('Доступ к каналу изменился.');$selected=$flow['selected'];$position=array_search($argument,$selected,true);if($position===false)$selected[]=$argument;else array_splice($selected,$position,1);$flow['selected']=GitPostedMaxSubscription::ids($selected);break;
                    case'all':$flow['selected']=GitPostedMaxSubscription::ids(array_keys($channels));break;
                    case'none':$flow['selected']=[];break;
                    default:throw new InvalidArgumentException('Откройте раздел подписки заново.');
                }
                return$flow;
            });
            $this->renderMaxSubscription($userId,$flow,$channels);
        }catch(Throwable$error){$message=$error instanceof InvalidArgumentException||$error instanceof GitPostedMaxPolicyException?$error->getMessage():'Не удалось открыть подписку. Обновите меню и повторите.';
            $this->api->sendToUser($userId,$message,[],[[GitPostedMaxApi::callbackButton('Обновить подписку','gp:subscription:open')]]);
        }finally{try{$this->api->answerCallback($callbackId,'',[],'Подписка');}catch(Throwable){}}
        return true;
    }

    private function renderMaxSubscription(string $userId,array $flow,array $channels): void
    {
        $this->maxSubscriptionAssertActor($userId);$flow=GitPostedMaxSubscription::validate($flow);if($flow['user_id']!==$userId)throw new InvalidArgumentException('Меню принадлежит другому пользователю.');
        $button=static fn(string$text,string$action,string|int|null$arg=null,string$intent='default'):array=>GitPostedMaxApi::callbackButton($text,GitPostedMaxSubscription::callback($flow,$action,$arg),$intent);
        $rows=[];$text='';$selected=$flow['selected'];$stale=array_diff($selected,array_keys($channels))!==[];
        if($flow['screen']==='status'){
            $text="ПОДПИСКА\n\nДля использования платных возможностей GitPosted необходима подписка на канал. Чтобы оформить подписку, нажмите «Далее».";
            $active=[];foreach($channels as$c){$state=GitPostedMaxPolicy::channelState($this->pdo,$this->config,$c,'premium');if($state['state']==='active')$active[]=mb_substr((string)$c['title'],0,45,'UTF-8').' — '.(new DateTimeImmutable($state['expires_at']))->setTimezone(new DateTimeZone($this->maxSubscriptionTimezone($userId)))->format('d.m.Y H:i');}
            if($active!==[])$text.="\n\n".implode("\n",array_slice($active,$flow['page']*10,10));
            if(count($active)>10){$nav=[];if($flow['page']>0)$nav[]=$button('←','page',$flow['page']-1);if(($flow['page']+1)*10<count($active))$nav[]=$button('→','page',$flow['page']+1);if($nav!==[])$rows[]=$nav;}
            $rows[]=[$button('Далее','offer')];$rows[]=[GitPostedMaxApi::callbackButton('← Настройки','gp:settings')];
        }elseif($flow['screen']==='channels'){
            $text="ВЫБОР КАНАЛОВ\n\nВыберите каналы для подписки.";$page=array_slice($channels,$flow['page']*10,10,true);
            foreach($page as$id=>$c)$rows[]=[$button((in_array($id,$selected,true)?'✅ ':'').mb_substr((string)$c['title'],0,55,'UTF-8'),'toggle',$id,in_array($id,$selected,true)?'positive':'default')];
            $nav=[];if($flow['page']>0)$nav[]=$button('←','page',$flow['page']-1);if(($flow['page']+1)*10<count($channels))$nav[]=$button('→','page',$flow['page']+1);if($nav!==[])$rows[]=$nav;
            $rows[]=[$button('Выбрать все','all'),$button('Очистить','none')];$rows[]=[$button('Выбрать','offer')];
        }elseif($flow['screen']==='periods'){
            $text="ПЕРИОД ПОДПИСКИ\n\nВыберите срок подписки.";foreach([30=>'1 месяц',90=>'3 месяца',180=>'6 месяцев',365=>'1 год']as$days=>$label)$rows[]=[$button($label,'period',$days,$flow['days']===$days?'positive':'default')];$rows[]=[$button('← Назад','offer')];
        }else{
            $prices=GitPostedMaxSubscription::tariffs($this->config);$text="ОПЛАТА ПОДПИСКИ\n\nВыберите каналы и срок подписки, затем нажмите «Оплатить».\n\nИтоговая стоимость: ".($prices[$flow['days']]*count($selected)).'₽';
            if($stale)$text.="\n\nДоступ к одному из выбранных каналов изменился. Обновите выбор каналов.";
            $rows[]=[$button('Количество каналов: '.count($selected),'channels')];$rows[]=[$button('Срок: '.([30=>'1 месяц',90=>'3 месяца',180=>'6 месяцев',365=>'1 год'][$flow['days']]),'periods')];
            if($selected!==[]&&!$stale)$rows[]=[$button('Оплатить','pay',null,'positive')];$rows[]=[$button('← Назад','status')];
        }
        $this->api->sendToUser($userId,$text,[],$rows);
    }

    private function maxSubscriptionTimezone(string $userId): string
    {$zone=(string)($this->user($userId)['user_timezone']??'Europe/Moscow');try{return(new DateTimeZone($zone))->getName();}catch(Throwable){return'Europe/Moscow';}}
}
