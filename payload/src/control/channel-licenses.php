<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Authenticated admin-only operations; never calls a bot or a payment provider. */
final class PostedAdminChannelLicenses
{
    public const DAYS=[30,90,180,365];
    public const REVIEW_TTL=600;

    public static function hash(mixed $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$item)$v[$k]=$sort($item);return$v;};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function platform(mixed $value):string
    {if(!is_string($value)||!in_array($value,['telegram','max'],true))throw new InvalidArgumentException('Выберите Telegram или МАКС.');return$value;}

    public static function id(mixed $value):int
    {return(int)PostedTelegramPolicy::positiveId($value);}

    public static function token(mixed $value):string
    {if(!is_string($value)||preg_match('/\A[a-f0-9]{32}\z/D',$value)!==1)throw new InvalidArgumentException('Откройте подтверждение заново.');return$value;}

    public static function digest(mixed $value):string
    {if(!is_string($value)||preg_match('/\A[a-f0-9]{64}\z/D',$value)!==1)throw new InvalidArgumentException('Обновите текущие данные.');return$value;}

    public static function timezone(array $config):DateTimeZone
    {try{return new DateTimeZone((string)($config['timezone']??'UTC'));}catch(Throwable){throw new InvalidArgumentException('Часовой пояс сервиса повреждён.');}}

    public static function format(?string $utc,DateTimeZone $zone):string
    {return$utc===null?'—':(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone($zone)->format('d.m.Y H:i:s P');}

    /** Fixed-day periods use the active expiry; revoked/expired licenses start now. */
    public static function expiry(?array $license,mixed $period,mixed $custom,DateTimeZone $zone,int $now):string
    {
        if(is_string($period)&&in_array($period,array_map('strval',self::DAYS),true)){
            $base=$now;
            if(($license['status']??null)==='active')$base=max($now,(new DateTimeImmutable($license['expires_at'],new DateTimeZone('UTC')))->getTimestamp());
            $epoch=$base+(int)$period*86400;
        }elseif($period==='custom'){
            if(!is_string($custom)||preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D',$custom)!==1)throw new InvalidArgumentException('Укажите дату и время.');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$custom,$zone);$errors=DateTimeImmutable::getLastErrors();
            if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i')!==$custom)throw new InvalidArgumentException('Такого местного времени нет. Укажите другую дату.');
            $epoch=$date->getTimestamp();
            if(($license['status']??null)==='active'&&$epoch<(new DateTimeImmutable($license['expires_at'],new DateTimeZone('UTC')))->getTimestamp())throw new InvalidArgumentException('Новый срок не может сокращать действующую подписку.');
            $offsets=[];
            foreach($zone->getTransitions($epoch-172800,$epoch+172800)?:[]as$t)$offsets[(int)$t['offset']]=true;
            foreach(array_keys($offsets)as$offset){$alternative=$epoch+$date->getOffset()-$offset;if($alternative!==$epoch&&(new DateTimeImmutable('@'.$alternative))->setTimezone($zone)->format('Y-m-d\TH:i')===$custom)throw new InvalidArgumentException('Это местное время повторяется при переводе часов. Выберите однозначное время.');}
        }else throw new InvalidArgumentException('Выберите 30, 90, 180, 365 дней или свою дату.');
        if($epoch<=$now||$epoch>$now+3660*86400)throw new InvalidArgumentException('Срок должен быть в будущем, не более десяти лет.');
        return gmdate('Y-m-d\TH:i:s\Z',$epoch);
    }

    private static function tables(string $platform):array
    {self::platform($platform);return$platform==='telegram'?['posted_channels','posted_users','telegram_id','first_name']:['posted_max_channels','posted_max_users','max_user_id','display_name'];}

    private static function license(PDO $pdo,string $platform,array $channel,bool $lock=false):?array
    {return$platform==='telegram'?PostedTelegramPolicy::readLicense($pdo,$channel,$lock):GitPostedMaxPolicy::readLicense($pdo,$channel,$lock);}

    public static function view(PDO $pdo,string $platform,int $id,bool $lock=false,?int $now=null):array
    {
        if($lock&&!$pdo->inTransaction())throw new LogicException('License view lock requires a transaction.');
        [$table]=self::tables($platform);$q=$pdo->prepare('SELECT id,chat_id,title,added_by,is_active FROM '.$table.' WHERE id=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$id]);$channel=$q->fetch();
        if(!is_array($channel))throw new InvalidArgumentException('Канал не найден.');
        $license=self::license($pdo,$platform,$channel,$lock);$now??=time();
        $state=$license===null?'missing':($license['status']==='revoked'?'revoked':((new DateTimeImmutable($license['expires_at']))->getTimestamp()>$now?'active':'expired'));
        return['platform'=>$platform,'channel'=>self::channelBinding($channel),'license'=>$license,'license_hash'=>self::hash($license),'state'=>$state];
    }

    private static function channelBinding(array $channel):array
    {return['id'=>self::id($channel['id']??null),'chat_id'=>(string)$channel['chat_id'],'title'=>(string)$channel['title'],'added_by'=>$channel['added_by']===null?null:(string)$channel['added_by'],'is_active'=>(int)$channel['is_active']];}

    public static function search(PDO $pdo,string $platform,mixed $query,mixed $page):array
    {
        [$table,$users,$userId,$name]=self::tables($platform);
        if(!is_string($query)||mb_strlen($query,'UTF-8')>100||preg_match('/[\x00-\x1f\x7f]/u',$query))throw new InvalidArgumentException('Поисковый запрос должен содержать не более 100 символов.');
        if(!is_string($page)||preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$page)!==1)throw new InvalidArgumentException('Некорректная страница.');
        $needle='%'.str_replace(['=','%','_'],['==','=%','=_'],trim($query)).'%';$offset=(int)$page*20;
        $where="(c.title LIKE ? ESCAPE '=' OR CAST(c.chat_id AS CHAR) LIKE ? ESCAPE '=' OR CAST(c.id AS CHAR) LIKE ? ESCAPE '=' OR CAST(c.added_by AS CHAR) LIKE ? ESCAPE '=' OR u.username LIKE ? ESCAPE '=' OR u.$name LIKE ? ESCAPE '=')";
        $q=$pdo->prepare("SELECT c.id,c.chat_id,c.title,c.added_by,c.is_active,u.$name AS owner_name,u.username AS owner_username FROM $table c LEFT JOIN $users u ON u.$userId=c.added_by WHERE $where ORDER BY c.id DESC LIMIT 21 OFFSET $offset");$q->execute(array_fill(0,6,$needle));$rows=$q->fetchAll();$more=count($rows)>20;$rows=array_slice($rows,0,20);
        foreach($rows as&$row){try{$row['subscription']=self::view($pdo,$platform,(int)$row['id']);$row['error']=null;}catch(InvalidArgumentException){$row['subscription']=null;$row['error']='Подписка повреждена: изменение заблокировано.';}}
        return['rows'=>$rows,'page'=>(int)$page,'has_more'=>$more,'query'=>$query,'platform'=>$platform];
    }

    public static function prepareLicense(PDO $pdo,array $input,array $config,string $session,?int $now=null):array
    {
        $now??=time();$platform=self::platform($input['platform']??null);$view=self::view($pdo,$platform,self::id($input['channel_id']??null),false,$now);
        if(!hash_equals($view['license_hash'],self::digest($input['license_hash']??null)))throw new InvalidArgumentException('Подписка изменилась. Откройте канал заново.');
        $action=$input['license_action']??null;if(!in_array($action,['grant','revoke'],true))throw new InvalidArgumentException('Выберите действие с подпиской.');
        $proposed=$view['license'];
        if($action==='revoke'){if($proposed===null)throw new InvalidArgumentException('У канала нет подписки для отзыва.');$proposed['status']='revoked';}
        else $proposed=['version'=>1,'channel_id'=>$view['channel']['id'],'chat_id'=>$view['channel']['chat_id'],'expires_at'=>self::expiry($view['license'],$input['period']??null,$input['custom_expires']??null,self::timezone($config),$now),'status'=>'active'];
        $proposed=$platform==='telegram'?PostedTelegramPolicy::normalizeLicense($proposed,$view['channel']):GitPostedMaxPolicy::normalizeLicense($proposed,$view['channel']);
        return['version'=>1,'kind'=>'license','nonce'=>bin2hex(random_bytes(16)),'session'=>self::hash($session),'prepared_at'=>$now,'expires_at'=>$now+self::REVIEW_TTL,'platform'=>$platform,'channel'=>$view['channel'],'before'=>$view['license'],'before_hash'=>$view['license_hash'],'after'=>$proposed,'action'=>$action];
    }

    public static function validatePlan(array $plan,string $session,string $kind,?int $now=null,bool $allowExpired=false):void
    {
        $now??=time();if(!in_array($kind,['license','tariffs'],true)||($plan['version']??null)!==1||($plan['kind']??null)!==$kind||!is_string($plan['session']??null)||!hash_equals(self::hash($session),$plan['session'])||!is_int($plan['expires_at']??null)||!is_int($plan['prepared_at']??null)||$plan['expires_at']!==$plan['prepared_at']+self::REVIEW_TTL||$plan['prepared_at']>$now||(!$allowExpired&&$plan['expires_at']<=$now))throw new InvalidArgumentException('Подтверждение устарело. Подготовьте действие заново.');
        self::token($plan['nonce']??null);
    }

    private static function receipt(PDO $pdo,string $key,bool $lock=false):?array
    {
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$key]);$raw=$q->fetchColumn();if($raw===false)return null;
        try{$value=json_decode((string)$raw,true,24,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('Журнал операции повреждён.');}
        if(!is_array($value)||($value['version']??null)!==1||!is_string($value['plan_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['plan_hash'])!==1||!in_array($value['phase']??null,['prepared','completed'],true)||!in_array($value['kind']??null,['license','tariffs'],true)||!is_string($value['nonce']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$value['nonce'])!==1||!str_ends_with($key,$value['nonce'])||!array_key_exists('before',$value)||!array_key_exists('after',$value))throw new RuntimeException('Журнал операции повреждён.');return$value;
    }

    private static function writeReceipt(PDO $pdo,string $key,array $value,bool $insert=false):void
    {
        $sql=$insert?'INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)':'UPDATE posted_app_settings SET setting_value=? WHERE setting_key=?';
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare($sql)->execute($insert?[$key,$json]:[$json,$key]);
    }

    public static function confirmLicense(PDO $pdo,array $plan,string $session,?int $now=null):array
    {
        if($pdo->inTransaction())throw new LogicException('Admin license operation owns its transaction.');
        self::validatePlan($plan,$session,'license',$now,true);$platform=self::platform($plan['platform']??null);$id=self::id($plan['channel']['id']??null);$key='admin_channel_license_'.self::token($plan['nonce']);$hash=self::hash($plan);
        $pdo->beginTransaction();
        try{
            $view=self::view($pdo,$platform,$id,true,$now);$prior=self::receipt($pdo,$key,true);
            if($prior!==null){if(!hash_equals($prior['plan_hash'],$hash)||$prior['phase']!=='completed'||$prior['kind']!=='license'||($prior['platform']??null)!==$platform||($prior['channel_id']??null)!==$id||self::hash($prior['after'])!==self::hash($plan['after']??null)||self::hash($prior['before'])!==self::hash($plan['before']??null))throw new RuntimeException('Операция требует проверки.');$pdo->commit();return$prior;}
            self::validatePlan($plan,$session,'license',$now);
            if(self::hash($view['channel'])!==self::hash($plan['channel'])||!hash_equals($view['license_hash'],self::digest($plan['before_hash']??null))||self::hash($plan['before']??null)!==$view['license_hash'])throw new InvalidArgumentException('Канал или подписка изменились. Откройте данные и подтвердите заново.');
            $after=$platform==='telegram'?PostedTelegramPolicy::normalizeLicense($plan['after']??null,$view['channel']):GitPostedMaxPolicy::normalizeLicense($plan['after']??null,$view['channel']);
            if(!in_array($plan['action']??null,['grant','revoke'],true)||($plan['action']==='grant'&&($after['status']!=='active'||(new DateTimeImmutable($after['expires_at']))->getTimestamp()<=($now??time())))||($plan['action']==='revoke'&&($view['license']===null||self::hash($after)!==self::hash(array_replace($view['license'],['status'=>'revoked'])))))throw new InvalidArgumentException('Подтверждение подписки повреждено или срок уже истёк.');
            $licenseKey=$platform==='telegram'?PostedTelegramPolicy::licenseKey($id):GitPostedMaxPolicy::licenseKey($id);
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$licenseKey,json_encode($after,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            $receipt=['version'=>1,'kind'=>'license','phase'=>'completed','plan_hash'=>$hash,'nonce'=>$plan['nonce'],'platform'=>$platform,'channel_id'=>$id,'before'=>$plan['before'],'after'=>$after,'committed_at'=>$now??time()];
            self::writeReceipt($pdo,$key,$receipt,true);$pdo->commit();return$receipt;
        }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    public static function tariffs(array $config):array
    {return PostedTelegramSubscription::tariffs($config);}

    public static function prices(array $input):array
    {
        $prices=[];foreach(self::DAYS as$days){$value=$input['price_'.$days]??null;if(!is_string($value)||preg_match('/\A[1-9][0-9]{0,6}\z/D',$value)!==1||(int)$value>1000000)throw new InvalidArgumentException('Стоимость должна быть целым числом от 1 до 1000000 рублей.');$prices[$days]=(int)$value;}return$prices;
    }

    public static function prepareTariffs(array $config,string $configHash,array $input,string $session,?int $now=null):array
    {
        $now??=time();$current=self::tariffs($config);$expected=self::digest($input['config_hash']??null);if(!hash_equals(self::digest($configHash),$expected))throw new InvalidArgumentException('Настройки изменились. Обновите страницу тарифов.');
        return['version'=>1,'kind'=>'tariffs','nonce'=>bin2hex(random_bytes(16)),'session'=>self::hash($session),'prepared_at'=>$now,'expires_at'=>$now+self::REVIEW_TTL,'config_hash'=>$configHash,'before'=>$current,'after'=>self::prices($input)];
    }

    public static function configPayload(array $config):string
    {return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($config,true).";\n";}

    /** Caller holds admin-config.lock throughout; intent commits BEFORE the atomic file write. */
    public static function confirmTariffs(PDO $pdo,array $plan,string $session,array $config,string $configHash,callable $write,callable $readHash,?int $now=null):array
    {
        if($pdo->inTransaction())throw new LogicException('Admin tariff operation owns its transaction.');
        self::validatePlan($plan,$session,'tariffs',$now,true);$key='admin_channel_tariffs_'.self::token($plan['nonce']);$hash=self::hash($plan);
        $new=$config;$new['telegram_subscription_tariffs']=['version'=>1,'currency'=>'RUB','prices'=>$plan['after']??null];self::tariffs($new);$afterHash=hash('sha256',self::configPayload($new));
        $pdo->beginTransaction();
        try{
            $prior=self::receipt($pdo,$key,true);
            if($prior!==null){
                if(!hash_equals($prior['plan_hash'],$hash)||$prior['kind']!=='tariffs'||self::hash($prior['after'])!==self::hash($plan['after']??null)||self::hash($prior['before'])!==self::hash($plan['before']??null))throw new RuntimeException('Журнал относится к другому действию.');
                if($prior['phase']==='completed'){$pdo->commit();return$prior;}
                if(!hash_equals(self::digest($prior['after_config_hash']??null),self::digest($configHash)))throw new RuntimeException('Результат сохранения требует проверки. Повторная запись не выполнялась.');
                $prior['phase']='completed';$prior['committed_at']=$now??time();self::writeReceipt($pdo,$key,$prior);$pdo->commit();return$prior;
            }
            self::validatePlan($plan,$session,'tariffs',$now);
            if(!hash_equals(self::digest($plan['config_hash']??null),self::digest($configHash))||self::hash(self::tariffs($config))!==self::hash($plan['before']??null))throw new InvalidArgumentException('Настройки изменились. Подготовьте действие заново.');
            $record=['version'=>1,'kind'=>'tariffs','phase'=>'prepared','plan_hash'=>$hash,'nonce'=>$plan['nonce'],'before'=>$plan['before'],'after'=>$plan['after'],'before_config_hash'=>$configHash,'after_config_hash'=>$afterHash,'prepared_at'=>$now??time()];self::writeReceipt($pdo,$key,$record,true);$pdo->commit();
        }catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
        // No SQL transaction spans the filesystem operation. A crash leaves a held intent.
        $write($new);
        if(!hash_equals($afterHash,self::digest($readHash())))throw new RuntimeException('Проверка сохранённой конфигурации не пройдена. Операция требует проверки.');
        $pdo->beginTransaction();try{$current=self::receipt($pdo,$key,true);if($current===null||!hash_equals($current['plan_hash'],$hash)||$current['phase']!=='prepared')throw new RuntimeException('Журнал сохранения изменился.');$current['phase']='completed';$current['committed_at']=$now??time();self::writeReceipt($pdo,$key,$current);$pdo->commit();return$current;}catch(Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    public static function remember(array &$session,array $plan):void
    {
        $plans=$session['admin_channel_reviews']??[];if(!is_array($plans))$plans=[];foreach($plans as$k=>$v)if(!is_array($v)||($v['expires_at']??0)<time()-86400)unset($plans[$k]);
        while(count($plans)>=10)array_shift($plans);$plans[self::token($plan['nonce']??null)]=$plan;$session['admin_channel_reviews']=$plans;
    }

    public static function review(array $session,mixed $nonce):array
    {$value=$session['admin_channel_reviews'][self::token($nonce)]??null;if(!is_array($value))throw new InvalidArgumentException('Подтверждение не найдено. Откройте канал или тарифы заново.');return$value;}
}
