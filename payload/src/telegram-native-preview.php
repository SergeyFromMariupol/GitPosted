<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-store.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Private delivery journal. An operation token records outcomes, never authorizes post access. */
final class PostedTelegramNativePreview
{
    public static function key(int $post,int $actor):string {if($post<1||$actor<1)throw new InvalidArgumentException('native_preview_post_actor');$key='tgnpv_'.$post.'_'.$actor;if(strlen($key)>=64)throw new InvalidArgumentException('native_preview_key_length');return$key;}
    public static function legacyKey(int $post):string {if($post<1)throw new InvalidArgumentException('native_preview_post');return'tgnpv_'.$post;}
    public static function normalize(mixed $v,int $post):array
    {
        if(!is_array($v)||array_keys($v)!==['version','post_id','channel_id','actor_id','author_id','token','snapshot','workflow','fingerprint','markup_sha256','old_pointers','kind','phase','steps']||$v['version']!==1||$v['post_id']!==$post||!is_int($v['channel_id'])||$v['channel_id']<1||!is_int($v['actor_id'])||$v['actor_id']<1||!is_int($v['author_id'])||$v['author_id']<1||!is_string($v['token'])||preg_match('/\A[a-f0-9]{32}\z/D',$v['token'])!==1)throw new InvalidArgumentException('native_preview_binding');
        foreach(['snapshot','workflow','fingerprint','markup_sha256']as$k)if(!is_string($v[$k])||preg_match('/\A[a-f0-9]{64}\z/D',$v[$k])!==1)throw new InvalidArgumentException('native_preview_digest');
        if(!in_array($v['phase'],['prepared','applied','held'],true)||!in_array($v['kind'],['send','markup','panel'],true)||!is_array($v['old_pointers'])||count($v['old_pointers'])!==4||!array_is_list($v['steps'])||count($v['steps'])<1||count($v['steps'])>2)throw new InvalidArgumentException('native_preview_shape');
        foreach($v['steps']as$s){if(!is_array($s)||array_keys($s)!==['type','phase','message_id','observed_message_id']||!in_array($s['type'],['notice','native','markup','panel'],true)||!in_array($s['phase'],['planned','sending','known','unknown','rejected'],true)||($s['phase']==='known'?(!is_int($s['message_id'])||$s['message_id']<1):$s['message_id']!==null)||!array_key_exists('observed_message_id',$s)||($s['observed_message_id']!==null&&(!is_int($s['observed_message_id'])||$s['observed_message_id']<1)))throw new InvalidArgumentException('native_preview_step');if($s['phase']==='known'&&$s['observed_message_id']!==$s['message_id'])throw new InvalidArgumentException('native_preview_known_observation');if($v['phase']==='applied'&&$s['phase']!=='known')throw new InvalidArgumentException('native_preview_applied_incomplete');}
        $types=array_column($v['steps'],'type');if($v['kind']==='markup'&&$types!==['markup']||$v['kind']==='send'&&!in_array($types,[['native'],['notice','native']],true)||$v['kind']==='panel'&&$types!==['panel'])throw new InvalidArgumentException('native_preview_steps');return$v;
    }
    private static function readKey(PDO $pdo,string $key,int $post,?int $actor,bool $lock):?array
    {$q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$key]);$raw=$q->fetchColumn();if($raw===false)return null;if(!is_string($raw)||strlen($raw)>12000)throw new InvalidArgumentException('native_preview_size');$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);/* JSON storage sorts keys; restore the protocol order explicitly. */if(is_array($v)){$ordered=[];foreach(['version','post_id','channel_id','actor_id','author_id','token','snapshot','workflow','fingerprint','markup_sha256','old_pointers','kind','phase','steps']as$k)if(array_key_exists($k,$v))$ordered[$k]=$v[$k];if(count($ordered)!==count($v))throw new InvalidArgumentException('native_preview_shape');$v=$ordered;foreach($v['steps']??[]as$i=>$s){if(!is_array($s))throw new InvalidArgumentException('native_preview_step');$keys=array_keys($s);sort($keys);if($keys!==['message_id','observed_message_id','phase','type'])throw new InvalidArgumentException('native_preview_step_fields');$v['steps'][$i]=['type'=>$s['type'],'phase'=>$s['phase'],'message_id'=>$s['message_id'],'observed_message_id'=>$s['observed_message_id']];}}$v=self::normalize($v,$post);if($actor!==null&&$v['actor_id']!==$actor)throw new InvalidArgumentException('native_preview_actor_key_binding');return$v;}
    public static function load(PDO $pdo,int $post,int $actor,bool $lock=false):?array
    {
        // Keep the original actor's legacy pending outcome authoritative. A new key never escapes it.
        // Corrupt legacy records fail closed rather than guessing their intended private recipient.
        $legacy=self::readKey($pdo,self::legacyKey($post),$post,null,$lock);$own=self::readKey($pdo,self::key($post,$actor),$post,$actor,$lock);
        if($legacy!==null&&$legacy['actor_id']===$actor&&$legacy['phase']!=='applied'){
            if($own!==null&&$own['phase']!=='applied')throw new RuntimeException('native_preview_conflicting_pending_journals');return$legacy;
        }
        return$own??($legacy!==null&&$legacy['actor_id']===$actor?$legacy:null);
    }
    private static function writeKey(PDO $pdo,string $key,array $v):void
    {if(!$pdo->inTransaction())throw new LogicException('native_preview_transaction');self::normalize($v,$v['post_id']);$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,PostedTelegramNativeContent::json($v)]);}
    public static function write(PDO $pdo,array $v):void
    {self::writeKey($pdo,self::key($v['post_id'],$v['actor_id']),$v);}
    /** Only all-known applied actor-local history certifies prior private IDs for reuse/retirement. */
    public static function knownIds(?array $v,int $actor):array
    {
        if($v===null||$v['actor_id']!==$actor||$v['phase']!=='applied')return[];foreach($v['steps']as$s)if($s['phase']!=='known')throw new RuntimeException('native_preview_unproven_previous');
        if($v['kind']==='markup'){if((string)$v['old_pointers'][0]!== (string)$actor)throw new RuntimeException('native_preview_foreign_previous');$ids=json_decode((string)$v['old_pointers'][1],true,16,JSON_THROW_ON_ERROR);}else $ids=array_column($v['steps'],'message_id');
        if(!is_array($ids)||!array_is_list($ids)||$ids===[]||count($ids)>2||count($ids)!==count(array_unique($ids)))throw new RuntimeException('native_preview_previous_ids');foreach($ids as$id)if(!is_int($id)||$id<1)throw new RuntimeException('native_preview_previous_ids');return$ids;
    }
    public static function pointers(array $post):array
    {return[$post['telegram_preview_chat_id']??null,$post['telegram_preview_message_ids']??null,$post['telegram_preview_control_message_id']??null,$post['telegram_preview_fingerprint']??null];}
    public static function snapshot(array $post):string
    {foreach(['updated_at','telegram_preview_chat_id','telegram_preview_message_ids','telegram_preview_control_message_id','telegram_preview_fingerprint','chat_id','channel_title']as$k)unset($post[$k]);foreach(array_keys($post)as$k)if(str_starts_with((string)$k,'_'))unset($post[$k]);return PostedTelegramNativeContent::hash($post);}
    /** Called only with current channel/post/actor/grant locks held. No I/O. */
    public static function prepareLocked(PDO $pdo,array $post,int $actor,array $plan,array $markup,string $workflow):array
    {
        if(!$pdo->inTransaction()||$actor<1||!in_array($post['status']??null,['draft','scheduled','failed','sent','cancelled'],true))throw new LogicException('native_preview_prepare');$id=(int)$post['id'];$old=self::load($pdo,$id,$actor,true);$snapshot=self::snapshot($post);$mark=PostedTelegramNativeContent::hash($markup);
        if($old!==null&&$old['phase']!=='applied')throw new RuntimeException('Предпросмотр требует проверки. Автоматический повтор отключён.');
        $oldIds=self::knownIds($old,$actor);$same=$oldIds!==[]&&$old['fingerprint']===$plan['fingerprint'];$prior=$oldIds!==[]?[(string)$actor,json_encode($oldIds,JSON_THROW_ON_ERROR),(int)end($oldIds),$old['fingerprint']]:[null,null,null,null];
        $panel=($plan['_native_panel']??false)===true;
        $steps=$panel&&!$same?[['type'=>'panel','phase'=>'planned','message_id'=>null,'observed_message_id'=>null]]:($same?[['type'=>'markup','phase'=>'planned','message_id'=>null,'observed_message_id'=>null]]:array_merge(($plan['native_notice']??'')!==''?[['type'=>'notice','phase'=>'planned','message_id'=>null,'observed_message_id'=>null]]:[],[['type'=>'native','phase'=>'planned','message_id'=>null,'observed_message_id'=>null]]));
        $v=['version'=>1,'post_id'=>$id,'channel_id'=>(int)$post['channel_id'],'actor_id'=>$actor,'author_id'=>(int)$post['author_id'],'token'=>bin2hex(random_bytes(16)),'snapshot'=>$snapshot,'workflow'=>$workflow,'fingerprint'=>$plan['fingerprint'],'markup_sha256'=>$mark,'old_pointers'=>$prior,'kind'=>$same?'markup':($panel?'panel':'send'),'phase'=>'prepared','steps'=>$steps];self::write($pdo,$v);return$v;
    }
    public static function assertCurrent(array $v,array $post,int $actor,array $plan,array $markup,string $workflow):void
    {if($v['phase']!=='prepared'||!hash_equals($v['workflow'],$workflow)||$v['actor_id']!==$actor||$v['author_id']!==(int)$post['author_id']||$v['channel_id']!==(int)$post['channel_id']||!hash_equals($v['snapshot'],self::snapshot($post))||!hash_equals($v['fingerprint'],$plan['fingerprint'])||!hash_equals($v['markup_sha256'],PostedTelegramNativeContent::hash($markup)))throw new RuntimeException('Предпросмотр изменился. Исходные данные и прежние сообщения сохранены.');}
    public static function beginStepLocked(PDO $pdo,array $v,int $index):void
    {if(($v['steps'][$index]['phase']??null)!=='planned')throw new RuntimeException('native_preview_step_not_planned');foreach(array_slice($v['steps'],0,$index)as$s)if($s['phase']!=='known')throw new RuntimeException('native_preview_prior_unknown');$v['steps'][$index]['phase']='sending';self::write($pdo,$v);}
    /** Token is sufficient only for recording one pending result, even after current ACL/author changes. */
    public static function record(PDO $pdo,int $post,int $actor,string $token,int $index,string $phase,?int $mid,?int $observed=null):void
    {
        if($pdo->inTransaction())throw new LogicException('native_preview_outcome_outer_transaction');$pdo->beginTransaction();try{$found=[];foreach([self::legacyKey($post),self::key($post,$actor)]as$key){$candidate=self::readKey($pdo,$key,$post,$key===self::legacyKey($post)?null:$actor,true);if($candidate!==null&&$candidate['actor_id']===$actor&&hash_equals($candidate['token'],$token))$found[]=[$key,$candidate];}if(count($found)!==1)throw new RuntimeException('native_preview_outcome_identity');[$key,$v]=$found[0];if($v['phase']!=='prepared'||($v['steps'][$index]['phase']??null)!=='sending'||!in_array($phase,['known','unknown','rejected'],true)||($phase==='known'?($mid===null||$mid<1):$mid!==null)||($observed!==null&&$observed<1))throw new RuntimeException('native_preview_outcome_changed');$v['steps'][$index]=['type'=>$v['steps'][$index]['type'],'phase'=>$phase,'message_id'=>$mid,'observed_message_id'=>$phase==='known'?$mid:$observed];if($phase!=='known')$v['phase']='held';self::writeKey($pdo,$key,$v);$pdo->commit();}catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}
