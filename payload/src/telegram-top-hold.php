<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
final class PostedTelegramTopHoldException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason,public readonly ?array $blocking=null){parent::__construct(match($reason){
        'hold_active'=>'В канале действует TOP. Следующая публикация доступна после окончания удержания.',
        'delivery_unknown'=>'Результат TOP-публикации ещё не подтверждён. Повторная отправка отключена.',
        'schedule_conflict'=>'Выбранное время пересекается с TOP или другой запланированной публикацией.',
        'quota_exhausted'=>'У автора закончилась квота TOP для этого канала.',
        'stale_revision'=>'Настройка TOP изменилась. Откройте пост заново.',
        default=>'Настройки TOP требуют проверки. Пост и дата сохранены.'});}
}
final class PostedTelegramTopHold
{
    public const MAX_QUOTA=1000000;
    public const MAX_REVISION=2147483647;
    public const MAX_DURATION_MINUTES=525600;
    private static function fail(string$r='invalid_top',?array$b=null):never{throw new PostedTelegramTopHoldException($r,$b);}
    private static function tx(PDO$p):void{if(!$p->inTransaction())throw new LogicException('TOP requires the existing locked transaction.');}
    private static function id(int$v):int{if($v<=0)self::fail();return$v;}
    private static function json(array$v):string{return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function rows(PDO$p,string$sql,array$args=[]):array{$s=$p->prepare($sql);$s->execute($args);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private static function raw(PDO$p,string$key,bool$lock=false):?array{$r=self::rows($p,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''),[$key]);if($r===[])return null;try{$v=json_decode($r[0]['setting_value'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){self::fail();}if(!is_array($v))self::fail();return$v;}
    private static function write(PDO$p,string$key,array$v):void{self::tx($p);$p->prepare('INSERT INTO posted_app_settings(setting_key,setting_value)VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,self::json($v)]);}
    private static function keys(array$v,array$expected):void{$k=array_keys($v);sort($k);sort($expected);if($k!==$expected)self::fail();}
    public static function optionKey(int$id):string{return'tgh_o_'.self::id($id);}
    public static function quotaKey(int$c,int$u):string{return'tgh_q_'.self::id($c).'_'.self::id($u);}
    public static function receiptKey(int$id):string{return'tgh_p_'.self::id($id);}
    public static function channelKey(int$id):string{return'tgh_c_'.self::id($id);}
    public static function defaults():array{return['version'=>1,'duration_minutes'=>60];}
    public static function normalizePolicy(mixed$v):array{if(!is_array($v))self::fail();self::keys($v,['version','duration_minutes']);if(($v['version']??null)!==1||!is_int($v['duration_minutes']??null)||$v['duration_minutes']<1||$v['duration_minutes']>self::MAX_DURATION_MINUTES)self::fail();return$v;}
    public static function fromConfig(array$c):array{return array_key_exists('telegram_top_hold',$c)?self::normalizePolicy($c['telegram_top_hold']):self::defaults();}
    public static function fromAdminDuration(array$i):array{$v=$i['telegram_top_hold_minutes']??null;if(!is_string($v)||preg_match('/\A[1-9][0-9]{0,5}\z/D',$v)!==1)self::fail();return self::normalizePolicy(['version'=>1,'duration_minutes'=>(int)$v]);}
    public static function load(PDO$p,array$post,bool$lock=false):array{
        if(isset($post['_telegram_top_options']))return self::normalize($post['_telegram_top_options'],$post);
        $v=self::raw($p,self::optionKey((int)$post['id']),$lock);
        return$v===null?['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'enabled'=>false,'duration_minutes'=>0,'revision'=>0]:self::normalize($v,$post);
    }
    public static function normalize(mixed$v,array$post):array{
        if(!is_array($v))self::fail();self::keys($v,['version','post_id','channel_id','enabled','duration_minutes','revision']);
        if(($v['version']??null)!==1||($v['post_id']??null)!==(int)$post['id']||($v['channel_id']??null)!==(int)$post['channel_id']||!is_bool($v['enabled']??null)||!is_int($v['revision']??null)||$v['revision']<0||$v['revision']>self::MAX_REVISION||!is_int($v['duration_minutes']??null)||$v['duration_minutes']<0||$v['duration_minutes']>self::MAX_DURATION_MINUTES||($v['enabled']&&$v['duration_minutes']<1))self::fail();return$v;
    }
    private static function bucket(PDO$p,array$c,int$u,bool$lock=false):array{
        $v=self::raw($p,self::quotaKey((int)$c['id'],$u),$lock);if($v===null)return['version'=>1,'channel_id'=>(int)$c['id'],'chat_id'=>(string)$c['chat_id'],'user_id'=>$u,'remaining'=>0,'revision'=>0,'generation'=>null];
        self::keys($v,['version','channel_id','chat_id','user_id','remaining','revision','generation']);
        if($v['version']!==1||$v['channel_id']!==(int)$c['id']||$v['chat_id']!==(string)$c['chat_id']||$v['user_id']!==$u||!is_int($v['remaining'])||$v['remaining']<0||$v['remaining']>self::MAX_QUOTA||!is_int($v['revision'])||$v['revision']<1||$v['revision']>self::MAX_REVISION||!is_string($v['generation'])||preg_match('/\A[a-f0-9]{32}\z/D',$v['generation'])!==1)self::fail();return$v;
    }
    public static function quotaRecord(PDO$p,array$c,int$u,bool$lock=false):array{return self::bucket($p,$c,$u,$lock);}
    public static function validateReceipt(array$v):array{return self::validReceipt($v);}
    public static function transferQuotasLocked(PDO$p,array$c,int$source,int$target,array$expectedSource,array$expectedTarget):void{
        self::tx($p);self::pendingBucket($p,$c,$source);self::pendingBucket($p,$c,$target);$a=self::bucket($p,$c,$source,true);$b=self::bucket($p,$c,$target,true);
        if($a!==$expectedSource||$b!==$expectedTarget)self::fail('stale_revision');if($a['revision']===0)return;
        if($a['remaining']+$b['remaining']>self::MAX_QUOTA)self::fail();$b['remaining']+=$a['remaining'];$b['revision']++;$b['generation']=bin2hex(random_bytes(16));self::write($p,self::quotaKey((int)$c['id'],$target),$b);$p->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::quotaKey((int)$c['id'],$source)]);
    }
    public static function quota(PDO$p,array$c,int$u,bool$lock=false):array{$v=self::bucket($p,$c,$u,$lock);return['remaining'=>$v['remaining'],'revision'=>$v['revision']];}
    private static function validReceipt(array$v):array{
        self::keys($v,['version','post_id','channel_id','chat_id','duration_minutes','quota_user_id','quota_generation','charged','state','nonce','started_at','until_at','message_id']);
        if($v['version']!==1||!is_int($v['post_id'])||$v['post_id']<=0||!is_int($v['channel_id'])||$v['channel_id']<=0||!is_string($v['chat_id'])||preg_match('/\A-[1-9][0-9]*\z/D',$v['chat_id'])!==1||!is_int($v['duration_minutes'])||$v['duration_minutes']<1||$v['duration_minutes']>self::MAX_DURATION_MINUTES||!is_int($v['quota_user_id'])||$v['quota_user_id']<=0||!is_bool($v['charged'])||!in_array($v['state'],['reserved','uncertain','active','refunded'],true)||!is_string($v['nonce'])||preg_match('/\A[a-f0-9]{32}\z/D',$v['nonce'])!==1||($v['quota_generation']!==null&&(!is_string($v['quota_generation'])||preg_match('/\A[a-f0-9]{32}\z/D',$v['quota_generation'])!==1)))self::fail();
        foreach(['started_at','until_at','message_id']as$k)if($v[$k]!==null&&(!is_int($v[$k])||$v[$k]<=0))self::fail();
        if($v['state']==='active'&&($v['started_at']===null||$v['until_at']!==$v['started_at']+$v['duration_minutes']*60||$v['message_id']===null))self::fail();return$v;
    }
    public static function receipt(PDO$p,int$id,bool$lock=false):?array{$v=self::raw($p,self::receiptKey($id),$lock);if($v===null)return null;$v=self::validReceipt($v);if($v['post_id']!==$id)self::fail();return$v;}
    public static function hold(PDO$p,array$c,bool$lock=false):?array{$v=self::raw($p,self::channelKey((int)$c['id']),$lock);if($v===null)return null;$v=self::validReceipt($v);if($v['channel_id']!==(int)$c['id']||$v['chat_id']!==(string)$c['chat_id'])self::fail();return$v;}
    public static function link(array$c,int$mid):?string{if($mid<=0)return null;$u=(string)($c['username']??'');if(preg_match('/\A[A-Za-z][A-Za-z0-9_]{3,31}\z/D',$u)===1)return'https://t.me/'.$u.'/'.$mid;$id=(string)$c['chat_id'];return preg_match('/\A-100([1-9][0-9]*)\z/D',$id,$m)===1?'https://t.me/c/'.$m[1].'/'.$mid:null;}
    public static function publicHold(array$c,array$r):array{return['state'=>$r['state'],'post_id'=>$r['post_id'],'post_link'=>self::link($c,(int)($r['message_id']??0)),'started_at'=>$r['started_at'],'unlock_at'=>$r['until_at']];}
    public static function assertAvailableLocked(PDO$p,array$c,int$ownPost=0):void{
        self::tx($p);$r=self::hold($p,$c,true);if($r===null||$r['post_id']===$ownPost||$r['state']==='refunded')return;
        if(in_array($r['state'],['reserved','uncertain'],true))self::fail('delivery_unknown',self::publicHold($c,$r));
        if($r['until_at']>time())self::fail('hold_active',self::publicHold($c,$r));
    }
    private static function pendingBucket(PDO$p,array$c,int$u):void{
        $rows=self::rows($p,"SELECT setting_value FROM posted_app_settings WHERE setting_key REGEXP '^tgh_p_[1-9][0-9]*$' ORDER BY setting_key LIMIT 10001");if(count($rows)>10000)self::fail();
        foreach($rows as$row){try{$r=self::validReceipt(json_decode($row['setting_value'],true,32,JSON_THROW_ON_ERROR));}catch(Throwable){self::fail();}
            if($r['channel_id']===(int)$c['id']&&$r['quota_user_id']===$u&&$r['charged']&&in_array($r['state'],['reserved','uncertain'],true))self::fail('delivery_unknown');}
    }
    private static function setQuotaLocked(PDO$p,array$c,int$u,int$n,int$rev):array{
        self::tx($p);if($n<0||$n>self::MAX_QUOTA||$rev<0)self::fail();
        $target=self::rows($p,'SELECT telegram_id FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE',[$u]);
        $grant=self::rows($p,'SELECT can_post,can_schedule,can_manage_posts,can_manage_admins FROM posted_permissions WHERE channel_id=? AND user_id=? LIMIT 1 FOR UPDATE',[(int)$c['id'],$u]);
        if($target===[]||$grant===[]||array_sum(array_map('intval',$grant[0]))<=0||$u===(int)$c['added_by'])self::fail('actor_denied');
        self::pendingBucket($p,$c,$u);$b=self::bucket($p,$c,$u,true);if($b['revision']!==$rev||$rev>=self::MAX_REVISION)self::fail('stale_revision');
        $b['remaining']=$n;$b['revision']++;$b['generation']=bin2hex(random_bytes(16));self::write($p,self::quotaKey((int)$c['id'],$u),$b);return['remaining'=>$n,'revision'=>$b['revision']];
    }
    public static function setQuotaForOwnerLocked(PDO$p,array$c,int$issuer,int$target,int$n,int$rev):array{
        self::tx($p);$row=self::rows($p,'SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE',[(int)$c['id']]);if($row===[]||(int)$row[0]['added_by']!==$issuer||(string)$row[0]['chat_id']!==(string)$c['chat_id']||empty($row[0]['is_active']))self::fail('actor_denied');
        $ids=[$issuer,$target];sort($ids,SORT_NUMERIC);foreach(array_unique($ids)as$id){if(self::rows($p,'SELECT telegram_id FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE',[$id])===[])self::fail('actor_denied');}
        $g=self::rows($p,'SELECT can_manage_admins FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE',[$issuer,(int)$c['id']]);if($g===[]||empty($g[0]['can_manage_admins']))self::fail('actor_denied');return self::setQuotaLocked($p,$row[0],$target,$n,$rev);
    }
    public static function retireChannelQuotasLocked(PDO$p,array$c):void{
        self::tx($p);$receipts=self::rows($p,"SELECT setting_value FROM posted_app_settings WHERE setting_key REGEXP '^tgh_p_[1-9][0-9]*$' ORDER BY setting_key LIMIT 10001");if(count($receipts)>10000)self::fail();
        foreach($receipts as$r){try{$v=self::validReceipt(json_decode($r['setting_value'],true,32,JSON_THROW_ON_ERROR));}catch(Throwable){self::fail();}if($v['channel_id']===(int)$c['id']&&in_array($v['state'],['reserved','uncertain'],true))self::fail('delivery_unknown');}
        $keys=self::rows($p,'SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP ? ORDER BY setting_key FOR UPDATE',['^tgh_q_'.(int)$c['id'].'_[1-9][0-9]*$']);
        foreach($keys as$r){$v=json_decode($r['setting_value'],true,32,JSON_THROW_ON_ERROR);if(!is_array($v)||!is_int($v['user_id']??null))self::fail();$b=self::bucket($p,$c,$v['user_id'],true);if($r['setting_key']!==self::quotaKey((int)$c['id'],$v['user_id'])||$b['revision']>=self::MAX_REVISION)self::fail();$b['remaining']=0;$b['revision']++;$b['generation']=bin2hex(random_bytes(16));self::write($p,$r['setting_key'],$b);}
        // Published holds/consumed receipts are immutable, including after explicit deletion/reconnect.
    }
    public static function adminSetQuota(PDO$p,int$cid,int$u,int$n,int$rev):array{
        $own=!$p->inTransaction();if($own)$p->beginTransaction();try{$rows=self::rows($p,'SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE',[$cid]);if($rows===[])self::fail();$out=self::setQuotaLocked($p,$rows[0],$u,$n,$rev);if($own)$p->commit();return$out;}catch(Throwable$e){if($own&&$p->inTransaction())$p->rollBack();throw$e;}
    }
    private static function epoch(string$s):int{$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$s,new DateTimeZone(date_default_timezone_get()));if(!$d||$d->format('Y-m-d H:i:s')!==$s)self::fail();return$d->getTimestamp();}
    public static function assertScheduleLocked(PDO$p,array$c,array$post,string$at,?array$override=null):void{
        self::tx($p);$begin=self::epoch($at);$own=(int)$post['id'];$opt=$override??self::load($p,$post,true);$end=$begin+($opt['enabled']?$opt['duration_minutes']*60:0);
        $hold=self::hold($p,$c,true);if($hold!==null&&$hold['post_id']!==$own&&$hold['state']!=='refunded'){
            if(in_array($hold['state'],['reserved','uncertain'],true))self::fail('delivery_unknown',self::publicHold($c,$hold));
            if(($begin>=$hold['started_at']&&$begin<$hold['until_at'])||($opt['enabled']&&$begin<$hold['until_at']&&$end>$hold['started_at']))self::fail('schedule_conflict',self::publicHold($c,$hold));}
        foreach(self::bookings($p,(int)$c['id'])as$booking){$other=$booking['post'];if((int)$other['id']===$own)continue;$b=$booking['at'];$o=$booking['option'];$e=$b+($o['enabled']?$o['duration_minutes']*60:0);
            if(($o['enabled']&&$begin>=$b&&$begin<$e)||($opt['enabled']&&(($b>=$begin&&$b<$end)||($o['enabled']&&$begin<$e&&$end>$b))))self::fail('schedule_conflict');}
    }
    public static function setLocked(PDO$p,array$config,array$post,array$c,bool$enabled,int$rev):array{
        self::tx($p);if(!in_array($post['status']??null,['draft','scheduled','failed'],true))self::fail('post_locked');$old=self::load($p,$post,true);if($old['revision']!==$rev||$rev>=self::MAX_REVISION)self::fail('stale_revision');
        if($old['enabled']===$enabled)return$old;$next=$old;$next['enabled']=$enabled;$next['revision']++;if($enabled){$next['duration_minutes']=self::fromConfig($config)['duration_minutes'];if((int)($post['repeat_interval_minutes']??0)>0&&(int)$post['repeat_interval_minutes']<$next['duration_minutes'])self::fail('schedule_conflict');if((int)$post['author_id']!==(int)$c['added_by']&&self::bucket($p,$c,(int)$post['author_id'],true)['remaining']<=0)self::fail('quota_exhausted');}
        if($post['status']==='scheduled'&&is_string($post['scheduled_at']??null))self::assertProjectedScheduleLocked($p,$c,$post,$post['scheduled_at'],$next);
        self::write($p,self::optionKey((int)$post['id']),$next);return$next;
    }
    public static function freeze(PDO$p,array$post):array{$o=self::load($p,$post);if($o['enabled']||$o['revision']>0)$post['_telegram_top_options']=$o;else unset($post['_telegram_top_options']);return$post;}
    public static function submission(PDO$p,array$post):?array{$o=self::load($p,$post);return$o['enabled']||$o['revision']>0?['enabled'=>$o['enabled'],'duration_minutes'=>$o['duration_minutes'],'revision'=>$o['revision']]:null;}
    public static function cloneLocked(PDO$p,array$source,array$target):void{self::tx($p);$o=self::load($p,$source);if(!$o['enabled'])return;$o['post_id']=(int)$target['id'];$o['channel_id']=(int)$target['channel_id'];$o['revision']=1;self::write($p,self::optionKey((int)$target['id']),$o);}
    public static function toTemplateLocked(PDO$p,array$post,array$template):void{self::tx($p);$o=self::load($p,$post);if(!$o['enabled'])return;self::write($p,'tgh_t_'.(int)$template['id'],['version'=>1,'template_id'=>(int)$template['id'],'enabled'=>true,'duration_minutes'=>$o['duration_minutes']]);}
    public static function fromTemplateLocked(PDO$p,array$template,array$post):void{self::tx($p);$v=self::raw($p,'tgh_t_'.(int)$template['id']);if($v===null)return;self::keys($v,['version','template_id','enabled','duration_minutes']);if($v['version']!==1||$v['template_id']!==(int)$template['id']||$v['enabled']!==true||!is_int($v['duration_minutes'])||$v['duration_minutes']<1||$v['duration_minutes']>self::MAX_DURATION_MINUTES)self::fail();self::write($p,self::optionKey((int)$post['id']),['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'enabled'=>true,'duration_minutes'=>$v['duration_minutes'],'revision'=>1]);}
    public static function reserveLocked(PDO$p,array$config,array$post,array$c):void{
        self::tx($p);self::assertAvailableLocked($p,$c,(int)$post['id']);$o=self::load($p,$post,true);if(!$o['enabled'])return;
        $existing=self::receipt($p,(int)$post['id'],true);if($existing!==null&&$existing['state']!=='refunded')self::fail('delivery_unknown');
        foreach(self::plans($p)as$plan){$d=$plan['doc'];if($d['binding']['post_id']!==(int)$post['id'])continue;$seen=[];
            foreach($d['targets']as$t){if(isset($seen[$t['channel_id']]))self::fail('schedule_conflict');$seen[$t['channel_id']]=true;
                $dest=self::rows($p,'SELECT * FROM posted_channels WHERE id=? LIMIT 1',[$t['channel_id']]);if($dest===[]||(string)$dest[0]['chat_id']!==$t['chat_id'])self::fail();
                self::assertScheduleLocked($p,$dest[0],$post,date('Y-m-d H:i:s'),$o);if((int)$post['author_id']!==(int)$dest[0]['added_by']&&self::bucket($p,$dest[0],(int)$post['author_id'],true)['remaining']<1)self::fail('quota_exhausted');
            }}

        self::assertScheduleLocked($p,$c,$post,date('Y-m-d H:i:s'),$o);
        $u=(int)$post['author_id'];$charged=$u!==(int)$c['added_by'];$generation=null;
        if($charged){$b=self::bucket($p,$c,$u,true);if($b['remaining']<=0)self::fail('quota_exhausted');$b['remaining']--;$b['revision']++;$generation=$b['generation'];self::write($p,self::quotaKey((int)$c['id'],$u),$b);}
        $r=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$c['id'],'chat_id'=>(string)$c['chat_id'],'duration_minutes'=>$o['duration_minutes'],'quota_user_id'=>$u,'quota_generation'=>$generation,'charged'=>$charged,'state'=>'reserved','nonce'=>bin2hex(random_bytes(16)),'started_at'=>null,'until_at'=>null,'message_id'=>null];
        self::write($p,self::receiptKey((int)$post['id']),$r);self::write($p,self::channelKey((int)$c['id']),$r);
    }
    public static function finalizeKnownLocked(PDO$p,int$postId):void{
        self::tx($p);$r=self::receipt($p,$postId,true);if($r===null||$r['state']==='active'||$r['state']==='refunded')return;
        $rows=self::rows($p,'SELECT status,published_at,telegram_message_id,channel_id FROM posted_posts WHERE id=? LIMIT 1',[$postId]);$post=$rows[0]??null;
        if(!$post||!in_array($post['status'],['sent','deleted'],true)||!is_string($post['published_at'])||(int)$post['telegram_message_id']<=0||(int)$post['channel_id']!==$r['channel_id'])return;
        $current=self::raw($p,self::channelKey($r['channel_id']),true);if($current===null||self::validReceipt($current)['nonce']!==$r['nonce'])self::fail();
        $r['state']='active';$r['started_at']=self::epoch($post['published_at']);$r['until_at']=$r['started_at']+$r['duration_minutes']*60;$r['message_id']=(int)$post['telegram_message_id'];
        self::write($p,self::receiptKey($postId),$r);self::write($p,self::channelKey($r['channel_id']),$r);
    }
    public static function knownRejectLocked(PDO$p,int$postId):void{
        self::tx($p);$r=self::receipt($p,$postId,true);if($r===null||$r['state']==='active'||$r['state']==='refunded')return;
        $rows=self::rows($p,'SELECT telegram_message_id,telegram_message_ids FROM posted_posts WHERE id=? LIMIT 1',[$postId]);if($rows===[]||!empty($rows[0]['telegram_message_id'])||!empty($rows[0]['telegram_message_ids']))self::fail('delivery_unknown');
        if($r['charged']){$c=['id'=>$r['channel_id'],'chat_id'=>$r['chat_id']];$b=self::bucket($p,$c,$r['quota_user_id'],true);if($b['generation']!==$r['quota_generation']||$b['remaining']>=self::MAX_QUOTA)self::fail();$b['remaining']++;$b['revision']++;self::write($p,self::quotaKey($r['channel_id'],$r['quota_user_id']),$b);}
        $r['state']='refunded';self::write($p,self::receiptKey($postId),$r);$c=self::raw($p,self::channelKey($r['channel_id']),true);if($c!==null&&($c['nonce']??null)===$r['nonce'])self::write($p,self::channelKey($r['channel_id']),$r);
    }
    private static function plans(PDO$p):array{
        // Completed history never consumes the active planning limit. Authoritative
        // SQL post rows (and active child parent bindings) select candidates;
        // untrusted JSON phase/review flags cannot hide an active reference.
        $active="('draft','scheduled','publishing','telegram_pending')";
        $rows=self::rows($p,"SELECT s.setting_key,s.setting_value,r.id AS authority_id,r.channel_id AS authority_channel,c.chat_id AS authority_chat FROM posted_app_settings s JOIN posted_posts r ON s.setting_key=CONCAT('telegram_multipost_',r.id) JOIN posted_channels c ON c.id=r.channel_id WHERE r.status IN {$active} ORDER BY s.setting_key LIMIT 1001");
        $parents=self::rows($p,"SELECT s.setting_key,s.setting_value,p.id AS child_id,p.channel_id AS child_channel FROM posted_app_settings s JOIN posted_posts p ON s.setting_key=CONCAT('telegram_multipost_parent_',p.id) WHERE p.status IN {$active} ORDER BY s.setting_key LIMIT 1001");
        if(count($rows)>1000||count($parents)>1000)self::fail();$byKey=[];foreach($rows as$r)$byKey[$r['setting_key']]=$r;
        foreach($parents as$r){try{$v=json_decode($r['setting_value'],true,16,JSON_THROW_ON_ERROR);}catch(Throwable){self::fail();}
            if(!is_array($v))self::fail();$expected=['version','post_id','channel_id','root_post_id'];if(array_key_exists('mode_override',$v)){$expected[]='mode_override';if($v['mode_override']!=='copy')self::fail();}self::keys($v,$expected);
            if(($v['version']??null)!==1||($v['post_id']??null)!==(int)$r['child_id']||($v['channel_id']??null)!==(int)$r['child_channel']||!is_int($v['root_post_id']??null)||$v['root_post_id']<=0)self::fail();
            $key=PostedTelegramMultipost::key($v['root_post_id']);if(!isset($byKey[$key])){$root=self::rows($p,"SELECT s.setting_key,s.setting_value,r.id AS authority_id,r.channel_id AS authority_channel,c.chat_id AS authority_chat FROM posted_app_settings s JOIN posted_posts r ON s.setting_key=CONCAT('telegram_multipost_',r.id) JOIN posted_channels c ON c.id=r.channel_id WHERE s.setting_key=? LIMIT 1",[$key]);if($root===[])self::fail();$byKey[$key]=$root[0];}
            $byKey[$key]['active_children'][]=['post_id'=>$v['post_id'],'channel_id'=>$v['channel_id']];
        }
        if(count($byKey)>1000)self::fail();ksort($byKey,SORT_STRING);$out=[];
        foreach($byKey as$r){try{$d=json_decode($r['setting_value'],true,64,JSON_THROW_ON_ERROR);PostedTelegramMultipost::validate($d);}catch(Throwable){self::fail();}
            if($d['binding']['post_id']!==(int)$r['authority_id']||$d['binding']['channel_id']!==(int)$r['authority_channel']||$d['binding']['chat_id']!==(string)$r['authority_chat'])self::fail();
            foreach($r['active_children']??[]as$child){$found=false;if($d['phase']==='materialized')foreach($d['children']as$known)if($known['post_id']===$child['post_id']&&$known['channel_id']===$child['channel_id'])$found=true;if(!$found)self::fail();}
            $out[]=['key'=>$r['setting_key'],'raw'=>$r['setting_value'],'doc'=>$d];
        }return$out;
    }

    public static function planningSnapshot(PDO$p,int$channelId,array$extra=[]):array{
        $ids=[$channelId];foreach($extra as$id){if(!is_int($id)||$id<=0)self::fail();$ids[]=$id;}$seeds=array_values(array_unique($ids));$plans=self::plans($p);$changed=true;
        while($changed){$changed=false;foreach($plans as$v){$members=[$v['doc']['binding']['channel_id'],...array_column($v['doc']['targets'],'channel_id')];if(array_intersect($members,$ids)===[])continue;foreach($members as$id)if(!in_array($id,$ids,true)){$ids[]=$id;$changed=true;}if(count($ids)>256)self::fail();}}
        sort($ids,SORT_NUMERIC);$bound=[];foreach($plans as$v)if(in_array($v['doc']['binding']['channel_id'],$ids,true)||array_intersect(array_column($v['doc']['targets'],'channel_id'),$ids)!==[])$bound[$v['key']]=hash('sha256',$v['raw']);
        return['channel_ids'=>$ids,'seed'=>$channelId,'extra'=>$extra,'digest'=>hash('sha256',self::json($bound))];
    }
    public static function verifyPlanningLocked(PDO$p,array$hint):void{self::tx($p);$actual=self::planningSnapshot($p,$hint['seed'],$hint['extra']);if($actual['channel_ids']!==$hint['channel_ids']||$actual['digest']!==$hint['digest'])self::fail('stale_revision');}
    private static function bookings(PDO$p,int$channelId):array{
        $rows=self::rows($p,"SELECT id,channel_id,author_id,scheduled_at,text FROM posted_posts WHERE channel_id=? AND status='scheduled' AND scheduled_at IS NOT NULL ORDER BY scheduled_at,id LIMIT 10001",[$channelId]);if(count($rows)>10000)self::fail();$out=[];
        foreach($rows as$r)$out[]=['post'=>$r,'option'=>self::load($p,$r),'at'=>self::epoch($r['scheduled_at']),'projected'=>false];
        foreach(self::plans($p)as$v){$d=$v['doc'];if(!in_array($channelId,array_column($d['targets'],'channel_id'),true))continue;
            $root=self::rows($p,"SELECT id,channel_id,author_id,scheduled_at,text FROM posted_posts WHERE id=? AND status='scheduled' AND scheduled_at IS NOT NULL LIMIT 1",[$d['binding']['post_id']]);if($root===[])continue;$r=$root[0];
            $opt=isset($d['snapshot']['_telegram_top_options'])?self::normalize($d['snapshot']['_telegram_top_options'],$r):self::load($p,$r);
            $out[]=['post'=>$r,'option'=>$opt,'at'=>self::epoch($r['scheduled_at']),'projected'=>true];
        }return$out;
    }
    public static function assertProjectedScheduleLocked(PDO$p,array$c,array$post,string$at,?array$override=null):void{
        self::assertScheduleLocked($p,$c,$post,$at,$override);foreach(self::plans($p)as$v){$d=$v['doc'];if($d['binding']['post_id']!==(int)$post['id'])continue;
            foreach($d['targets']as$t){$dest=self::rows($p,'SELECT * FROM posted_channels WHERE id=? LIMIT 1',[$t['channel_id']]);if($dest===[]||(string)$dest[0]['chat_id']!==$t['chat_id'])self::fail();self::assertScheduleLocked($p,$dest[0],$post,$at,$override);}}
    }
    public static function assertSelectionScheduleLocked(PDO$p,array$post,array$targets):void{
        self::tx($p);if($post['status']!=='scheduled'||!is_string($post['scheduled_at']??null))return;
        foreach($targets as$t){$dest=self::rows($p,'SELECT * FROM posted_channels WHERE id=? LIMIT 1',[$t['channel_id']]);if($dest===[]||(string)$dest[0]['chat_id']!==$t['chat_id'])self::fail();self::assertScheduleLocked($p,$dest[0],$post,$post['scheduled_at']);}
    }
    public static function graph(PDO$p,array$config,array$c,int$actor,string$day,string$timezone,bool$manage):array{
        $tz=new DateTimeZone($timezone);$begin=DateTimeImmutable::createFromFormat('!Y-m-d',$day,$tz);if(!$begin||$begin->format('Y-m-d')!==$day)self::fail();$end=$begin->modify('+1 day');$a=$begin->getTimestamp();$z=$end->getTimestamp();$duration=self::fromConfig($config)['duration_minutes'];$seconds=$duration*60;$known=true;$intervals=[];$points=[];$exclude=[];
        $h=self::hold($p,$c);if($h!==null&&$h['state']!=='refunded'){
            if(in_array($h['state'],['reserved','uncertain'],true)){$known=false;$intervals[]=['begin'=>$a,'end'=>$z,'kind'=>'uncertain','label'=>'Ожидается подтверждение TOP','post_url'=>null];}
            else{$intervals[]=['begin'=>$h['started_at'],'end'=>$h['until_at'],'kind'=>'active_top','label'=>'Опубликованный TOP','post_url'=>self::link($c,(int)$h['message_id'])];$exclude[]=[$h['started_at']-$seconds+1,$h['until_at']];}}
        $pending=self::rows($p,"SELECT post_id FROM posted_telegram_publish_attempts WHERE channel_id=? AND (state<>'completed' OR gate_channel_id IS NOT NULL) LIMIT 1",[(int)$c['id']]);if($pending!==[])$known=false;
        $bookings=self::bookings($p,(int)$c['id']);foreach($bookings as$b){$r=$b['post'];$at=$b['at'];$opt=$b['option'];$label=$manage||(int)$r['author_id']===$actor?mb_substr((string)$r['text'],0,120,'UTF-8'):'Занято';if($label==='')$label='Публикация';
            if($opt['enabled']){$e=$at+$opt['duration_minutes']*60;$exclude[]=[$at-$seconds+1,$e];if($at<$z&&$e>$a)$intervals[]=['begin'=>$at,'end'=>$e,'kind'=>'scheduled_top','label'=>$label,'post_url'=>null];}
            else{$exclude[]=[$at-$seconds+1,$at+1];if($at>=$a&&$at<$z)$points[]=['at'=>$at,'label'=>$label,'post_url'=>null];}}
        $failed=self::rows($p,"SELECT id,author_id,scheduled_at FROM posted_posts WHERE channel_id=? AND status='failed' AND scheduled_at IS NOT NULL AND error_text='Повтор требует другой даты: конфликт удержания в топе. Отправка не начиналась.'".($manage?'':' AND author_id=?').' ORDER BY scheduled_at,id LIMIT 201',$manage?[(int)$c['id']]:[(int)$c['id'],$actor]);
        foreach($failed as$r){$at=self::epoch($r['scheduled_at']);if($at>=$a&&$at<$z)$points[]=['at'=>$at,'label'=>'⚠️ Не запланирован: повтор #'.(int)$r['id'].', нужна другая дата','post_url'=>null];}
        $free=[];if($known){usort($exclude,static fn($x,$y)=>$x[0]<=>$y[0]);$cursor=max($a,time()+60);foreach($exclude as[$b,$e]){if($e<=$cursor||$b>=$z)continue;if($b>$cursor)$free[]=['begin'=>$cursor,'end'=>min($b,$z)];$cursor=max($cursor,$e);if($cursor>=$z)break;}if($cursor<$z)$free[]=['begin'=>$cursor,'end'=>$z];}
        $truncated=count($intervals)>200||count($points)>200||count($free)>200;if($truncated){$known=false;$free=[];$intervals=array_slice($intervals,0,200);$points=array_slice($points,0,200);}
        return['channel_id'=>(int)$c['id'],'channel_title'=>(string)$c['title'],'day'=>$day,'today'=>(new DateTimeImmutable('now',$tz))->format('Y-m-d'),'timezone'=>$timezone,'day_start'=>$a,'day_end'=>$z,'duration_minutes'=>$duration,'availability_known'=>$known,'intervals'=>$intervals,'points'=>$points,'free_intervals'=>$free,'truncated'=>$truncated];
    }
    public static function state(PDO$p,array$config,array$post,array$c,int$actor):array{
        $o=self::load($p,$post);$unlimited=(int)$post['author_id']===(int)$c['added_by'];$q=$unlimited?null:self::quota($p,$c,(int)$post['author_id']);$mutable=in_array($post['status'],['draft','scheduled','failed'],true);$r=self::receipt($p,(int)$post['id']);$h=self::hold($p,$c);
        $locked=$r!==null&&$r['state']!=='refunded';$enable=$mutable&&!$locked&&($unlimited||$q['remaining']>0);
        return['enabled'=>$o['enabled'],'duration_minutes'=>$o['enabled']?$o['duration_minutes']:self::fromConfig($config)['duration_minutes'],'revision'=>$o['revision'],'quota_remaining'=>$q['remaining']??null,'unlimited'=>$unlimited,'can_enable'=>$enable,'can_disable'=>$mutable&&!$locked,'reason'=>$locked||!$mutable?'post_locked':($enable?'':'quota_exhausted'),'hold'=>$h!==null&&($h['state']!=='active'||$h['until_at']>time())&&$h['state']!=='refunded'?['post_id'=>$h['post_id'],'post_url'=>self::link($c,(int)($h['message_id']??0)),'until'=>$h['until_at'],'uncertain'=>in_array($h['state'],['reserved','uncertain'],true)]:null];
    }
}


trait GitPostedTelegramTopHold
{
    private function telegramTopHoldOptions(int$actor,int|array$post):array{
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);$post=is_array($post)?$post:$this->post($post);
        if(!$post||!$this->isKnownActiveUser($this->user($actor))||!$this->canManagePost($actor,(int)$post['id']))throw new PostedTelegramTopHoldException('actor_denied');
        $channel=$this->channel((int)$post['channel_id']);if(!$channel)throw new PostedTelegramTopHoldException('actor_denied');return PostedTelegramTopHold::state($this->pdo,$this->config,$post,$channel,$actor);
    }
    private function setTelegramTopHold(int$actor,int$postId,bool$enabled,?int$revision=null):array{
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
        $changed=$this->withTelegramManagedPostLock($actor,$postId,['draft','scheduled','failed'],function(array$post,array$c)use($enabled,$revision):bool{
            $o=PostedTelegramTopHold::load($this->pdo,$post,true);PostedTelegramTopHold::setLocked($this->pdo,$this->config,$post,$c,$enabled,$revision??$o['revision']);return true;
        });if($changed!==true)throw new PostedTelegramTopHoldException('actor_denied');return$this->telegramTopHoldOptions($actor,$postId);
    }
    private function telegramTopPlacementGraph(int$actor,int$channelId,string$day,?int$postId=null):array{
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);if(!$this->isKnownActiveUser($this->user($actor))||!$this->can($actor,$channelId,'post'))throw new PostedTelegramTopHoldException('actor_denied');
        $c=$this->channel($channelId);if(!$c)throw new PostedTelegramTopHoldException('actor_denied');
        $config=$this->config;if($postId!==null){$post=$this->post($postId);if(!is_array($post)||(int)$post['channel_id']!==$channelId||($post['status']??null)!=='draft'||(int)$post['author_id']!==$actor||!$this->canManagePost($actor,$postId)||!$this->can($actor,$channelId,'schedule'))throw new PostedTelegramTopHoldException('actor_denied');$option=PostedTelegramTopHold::load($this->pdo,$post);if($option['enabled'])$config['telegram_top_hold']=['version'=>1,'duration_minutes'=>$option['duration_minutes']];}
        return PostedTelegramTopHold::graph($this->pdo,$config,$c,$actor,$day,$this->userTimezone($actor),$this->can($actor,$channelId,'manage_posts'));
    }
}
