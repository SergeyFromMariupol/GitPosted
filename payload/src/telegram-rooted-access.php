<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Relationship admission is separate from per-channel capability and channel licensing. */
final class PostedTelegramRootedAccess
{
    private const LOCK='gitposted_telegram_rooted_graph';
    private static array $guards=[];
    private static array $transactions=[];
    public static function current(array $fallback):array
    {return class_exists('PostedConfig',false)?PostedConfig::get():$fallback;}
    public static function enabled(array $config):bool{return($config['telegram_policy']['access_mode']??null)==='rooted';}
    public static function key(int $id):string{if($id<=0)throw new InvalidArgumentException('Некорректный Telegram ID.');return'tg_rooted_actor_'.$id;}
    public static function control(mixed $v,int $id):array
    {
        if($v===null)return['version'=>1,'user_id'=>$id,'can_add_channels'=>false,'revision'=>0];
        if(!is_array($v)||count($v)!==4||($v['version']??null)!==1||($v['user_id']??null)!==$id||!is_bool($v['can_add_channels']??null)||!is_int($v['revision']??null)||$v['revision']<0||$v['revision']>2147483646)throw new InvalidArgumentException('Настройка доступа повреждена.');
        return$v;
    }
    /** Least fixed point. No stored inherited membership and no cyclic self-admission. */
    public static function resolve(array $roots,array $users,array $channels,array $permissions,array $controls):array
    {
        $active=[];foreach($users as$u)$active[(int)$u['telegram_id']]=(int)$u['is_active']===1;
        $rootSet=[];$members=[];foreach($roots as$id){$id=(int)PostedTelegramPolicy::positiveId($id);$rootSet[$id]=true;if(!array_key_exists($id,$active)||$active[$id])$members[$id]=true;}
        $eligible=[];foreach($channels as$c){$id=(int)$c['id'];if($id>0&&(int)$c['is_active']===1&&(int)($c['is_archived']??0)===0&&(int)($c['admin_blocked']??0)===0&&(int)($c['added_by']??0)>0)$eligible[$id]=$c;}
        $edges=[];foreach($permissions as$p){$id=(int)$p['user_id'];$cid=(int)$p['channel_id'];$role=false;foreach(['can_post','can_schedule','can_manage_posts','can_manage_admins','is_ad_manager']as$k)$role=$role||(int)($p[$k]??0)===1;if($role&&($active[$id]??false))$edges[$cid][$id]=true;}
        $reached=[];$changed=true;$steps=0;
        while($changed){$changed=false;if(++$steps>count($eligible)+count($active)+2)throw new RuntimeException('Граф доступа требует проверки.');
            foreach($eligible as$cid=>$c){$owner=(int)$c['added_by'];if(isset($reached[$cid])||!isset($members[$owner])||!($active[$owner]??false))continue;
                $own=isset($rootSet[$owner])||self::control($controls[$owner]??null,$owner)['can_add_channels'];if(!$own)continue;
                $reached[$cid]=true;$changed=true;foreach($edges[$cid]??[]as$id=>$_)if(!isset($members[$id])){$members[$id]=true;$changed=true;}
            }
        }
        return['users'=>$members,'channels'=>$reached,'roots'=>$rootSet,'edges'=>$edges,'owners'=>array_map(static fn($c)=>(int)$c['added_by'],$eligible)];
    }
    private static function readGraph(PDO $pdo,array $config):array
    {
        if($pdo->inTransaction())throw new LogicException('Graph snapshot must precede transaction.');
        $policy=PostedTelegramPolicy::fromConfig($config);
        $u=$pdo->query('SELECT telegram_id,is_active FROM posted_users ORDER BY telegram_id LIMIT 10001')->fetchAll(PDO::FETCH_ASSOC);
        $c=$pdo->query('SELECT id,added_by,is_active,is_archived,admin_blocked FROM posted_channels ORDER BY id LIMIT 10001')->fetchAll(PDO::FETCH_ASSOC);
        $p=$pdo->query('SELECT user_id,channel_id,can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager FROM posted_permissions ORDER BY channel_id,user_id LIMIT 100001')->fetchAll(PDO::FETCH_ASSOC);
        if(count($u)>10000||count($c)>10000||count($p)>100000)throw new RuntimeException('Слишком большой граф доступа.');
        $rows=$pdo->query("SELECT setting_key,setting_value FROM posted_app_settings WHERE LEFT(setting_key,16)='tg_rooted_actor_' ORDER BY setting_key LIMIT 10001")->fetchAll(PDO::FETCH_ASSOC);$controls=[];
        if(count($rows)>10000)throw new RuntimeException('Слишком много настроек доступа.');
        foreach($rows as$r){if(preg_match('/\Atg_rooted_actor_([1-9][0-9]{0,18})\z/D',$r['setting_key'],$m)!==1)throw new RuntimeException('Повреждён ключ доступа.');$id=(int)PostedTelegramPolicy::positiveId($m[1]);$controls[$id]=self::control(json_decode($r['setting_value'],true,16,JSON_THROW_ON_ERROR),$id);}
        return self::resolve($policy['allowed_telegram_ids'],$u,$c,$p,$controls)+['controls'=>$controls,'user_rows'=>$u,'channel_rows'=>$c,'permission_rows'=>$p];
    }
    public static function graph(PDO $pdo,array $config):array
    {
        $config=self::current($config);$id=spl_object_id($pdo);
        $owned=$pdo->inTransaction()?false:self::acquire($pdo,$config);
        try{
            if(!isset(self::$guards[$id])||!is_array(self::$guards[$id]['graph']))throw new RuntimeException('Проверка доступа требует актуальной блокировки графа.');
            return self::$guards[$id]['graph'];
        }finally{self::release($pdo,$owned);}
    }
    public static function operator(PDO $pdo,array $config,int $id):bool
    {return isset(self::graph($pdo,$config)['users'][$id]);}
    public static function channel(PDO $pdo,array $config,int $id,int $channel):bool
    {
        $config=self::current($config);if(!self::enabled($config))return true;$g=self::graph($pdo,$config);
        return isset($g['users'][$id],$g['channels'][$channel])&&(($g['owners'][$channel]??0)===$id||isset($g['edges'][$channel][$id]));
    }
    public static function requireChannel(PDO $pdo,array $config,int $id,int $channel):void
    {if(!self::channel($pdo,$config,$id,$channel))throw new PostedTelegramPolicyException('rooted_channel_denied',$channel);}
    public static function canConnect(PDO $pdo,array $config,int $id):bool
    {$config=self::current($config);if(!self::enabled($config))return true;$g=self::graph($pdo,$config);return isset($g['users'][$id])&&(isset($g['roots'][$id])||self::control($g['controls'][$id]??null,$id)['can_add_channels']);}
    public static function transferProof(PDO $pdo,array $config,int $source,int $target):?string
    {
        $config=self::current($config);if(!self::enabled($config))return null;$g=self::graph($pdo,$config);
        if(isset($g['roots'][$source])&&!isset($g['roots'][$target]))throw new DomainException('Администратор должен сначала добавить получателя в корневые Telegram ID. Данные не изменены.');
        $channels=$g['channel_rows'];$permissions=$g['permission_rows'];$hasOwned=false;
        foreach($channels as&$c)if((int)$c['added_by']===$source){$hasOwned=true;$c['added_by']=$target;}unset($c);
        if($hasOwned&&!self::canConnect($pdo,$config,$target))throw new DomainException('Получателю не разрешено владеть собственными каналами. Обратитесь к администратору.');
        foreach($permissions as&$p)if((int)$p['user_id']===$source)$p['user_id']=$target;unset($p);
        $after=self::resolve(array_keys($g['roots']),$g['user_rows'],$channels,$permissions,$g['controls']);
        foreach($g['channels']as$id=>$_)if(!isset($after['channels'][$id]))throw new DomainException('Перенос разорвёт доступ сотрудников к каналам. Администратор должен сначала дать получателю независимый корневой доступ.');
        return hash('sha256',json_encode([$g['roots'],$g['user_rows'],$g['channel_rows'],$g['permission_rows'],$g['controls']],JSON_THROW_ON_ERROR));
    }
    public static function acquire(PDO $pdo,array $config,bool $force=false):bool
    {
        $config=self::current($config);if(!$force&&!self::enabled($config))return false;$id=spl_object_id($pdo);
        if(isset(self::$guards[$id]))return false;
        if($pdo->inTransaction())throw new RuntimeException('Откройте действие заново для проверки доступа.');
        $s=$pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([self::LOCK]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Доступ сейчас изменяется. Повторите действие позже.');self::$guards[$id]=['graph'=>null];
        try{$config=self::current($config);if(self::enabled($config))self::$guards[$id]['graph']=self::readGraph($pdo,$config);}catch(Throwable$e){self::release($pdo,true);throw$e;}return true;
    }
    public static function release(PDO $pdo,bool $owned):void
    {if(!$owned||$pdo->inTransaction())return;$id=spl_object_id($pdo);if(!isset(self::$guards[$id]))return;try{$s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([self::LOCK]);}finally{unset(self::$guards[$id]);}}
    /** Each transaction releases only the lease it acquired, never an outer caller's lease. */
    public static function begin(PDO $pdo,array $config=[],bool $force=false):bool
    {
        $id=spl_object_id($pdo);if($pdo->inTransaction()||isset(self::$transactions[$id]))throw new LogicException('Nested transaction is not supported.');
        $own=self::acquire($pdo,$config,$force);
        try{
            if(!$own&&isset(self::$guards[$id])){ $fresh=self::current($config);self::$guards[$id]['graph']=self::enabled($fresh)?self::readGraph($pdo,$fresh):null; }
            $ok=$pdo->beginTransaction();if(!$ok)throw new RuntimeException('Не удалось начать транзакцию.');self::$transactions[$id]=$own;return true;
        }catch(Throwable$e){self::release($pdo,$own);throw$e;}
    }
    public static function commit(PDO $pdo):bool
    {
        $id=spl_object_id($pdo);$own=self::$transactions[$id]??false;
        try{$ok=$pdo->commit();if(!$ok)throw new RuntimeException('Транзакция не подтверждена.');return true;}finally{if(!$pdo->inTransaction()){unset(self::$transactions[$id]);self::release($pdo,$own);}}
    }
    public static function rollback(PDO $pdo):bool
    {
        $id=spl_object_id($pdo);$own=self::$transactions[$id]??false;
        try{$ok=$pdo->rollBack();if(!$ok)throw new RuntimeException('Откат транзакции не подтверждён.');return true;}finally{if(!$pdo->inTransaction()){unset(self::$transactions[$id]);self::release($pdo,$own);}}
    }
    public static function setControl(PDO $pdo,array $config,int $user,bool $allow,int $revision):array
    {
        if($revision<0)throw new InvalidArgumentException('Откройте список заново.');$guard=self::acquire($pdo,$config,true);
        try{self::begin($pdo,$config);
        try{$u=$pdo->prepare('SELECT telegram_id FROM posted_users WHERE telegram_id=? FOR UPDATE');$u->execute([$user]);if($u->fetchColumn()===false)throw new InvalidArgumentException('Пользователь не найден.');
            $s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? FOR UPDATE');$s->execute([self::key($user)]);$raw=$s->fetchColumn();$old=self::control($raw===false?null:json_decode($raw,true,16,JSON_THROW_ON_ERROR),$user);if($old['revision']!==$revision)throw new InvalidArgumentException('Права изменились. Обновите список.');
            $next=['version'=>1,'user_id'=>$user,'can_add_channels'=>$allow,'revision'=>$revision+1];$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key($user),json_encode($next,JSON_THROW_ON_ERROR)]);self::commit($pdo);return$next;
        }catch(Throwable$e){if($pdo->inTransaction())self::rollback($pdo);throw$e;}
        }finally{self::release($pdo,$guard);}
    }
}
