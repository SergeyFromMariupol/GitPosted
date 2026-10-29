<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-template.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Native private output only. A token records one outcome; it never grants template access. */
final class PostedTelegramNativeTemplatePreview
{
    public static function key(int$template,int$actor):string
    {if($template<1||$actor<1)throw new InvalidArgumentException('native_template_preview_identity');return'tgnptv_'.$template.'_'.$actor;}
    public static function legacyKey(int$template):string
    {if($template<1)throw new InvalidArgumentException('native_template_preview_identity');return'tg_template_preview_'.$template;}
    private static function normalize(array$r,int$id,?int$actor=null):array
    {
        $r=PostedTelegramTemplateMedia::preview($r,$id);
        if(($actor!==null&&$r['actor_id']!==$actor)||$r['expected']>2||($r['previous']!==null&&count($r['previous']['media_ids'])>2))throw new RuntimeException('native_template_preview_binding');
        return$r;
    }
    private static function read(PDO$pdo,string$key,int$id,?int$actor,bool$lock):?array
    {
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$key]);$raw=$q->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>16000)throw new RuntimeException('native_template_preview_size');$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('native_template_preview_shape');return self::normalize($v,$id,$actor);
    }
    public static function validateLegacy(PDO$pdo,int$id,bool$lock=false):?array
    {return self::read($pdo,self::legacyKey($id),$id,null,$lock);}
    /** Corrupt global data fails closed; a held global alias remains held for its original actor. */
    public static function selected(PDO$pdo,int$id,int$actor,bool$lock=false):?array
    {
        $legacy=self::validateLegacy($pdo,$id,$lock);$own=self::read($pdo,self::key($id,$actor),$id,$actor,$lock);
        if($legacy!==null&&$legacy['actor_id']===$actor&&$legacy['phase']!=='shown')return['key'=>self::legacyKey($id),'receipt'=>$legacy];
        if($own!==null)return['key'=>self::key($id,$actor),'receipt'=>$own];
        return$legacy!==null&&$legacy['actor_id']===$actor?['key'=>self::legacyKey($id),'receipt'=>$legacy]:null;
    }
    public static function load(PDO$pdo,int$id,int$actor,bool$lock=false):?array
    {return self::selected($pdo,$id,$actor,$lock)['receipt']??null;}
    public static function assertActorReady(PDO$pdo,array$template,int$actor,bool$requireShown=false):void
    {
        $r=self::load($pdo,(int)$template['id'],$actor,$pdo->inTransaction());
        if(($requireShown&&$r===null)||($r!==null&&$r['phase']!=='shown'))throw new RuntimeException('Предпросмотр шаблона не подтверждён. Повтор не выполнялся.');
    }
    private static function write(PDO$pdo,string$key,array$r):void
    {
        if(!$pdo->inTransaction())throw new LogicException('native_template_preview_transaction');self::normalize($r,$r['template_id'],$r['actor_id']);
        if(!in_array($key,[self::key($r['template_id'],$r['actor_id']),self::legacyKey($r['template_id'])],true))throw new RuntimeException('native_template_preview_key');
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,PostedTelegramTemplateMedia::json($r)]);
    }
    public static function prepareLocked(PDO$pdo,array$template,int$actor,array$plan,string$mode,string$fingerprint):array
    {
        if(!$pdo->inTransaction()||(int)$template['owner_id']!==$actor||!PostedTelegramNativeTemplate::isNative($template)||!in_array($mode,['editor','view'],true))throw new RuntimeException('native_template_preview_authority');
        $id=(int)$template['id'];$old=self::load($pdo,$id,$actor,true);if($old!==null&&$old['phase']!=='shown')throw new RuntimeException('Результат предпросмотра не подтверждён. Повторная отправка отключена.');
        if($old!==null&&$old['fingerprint']===$fingerprint&&$old['mode']===$mode)return['reuse'=>true,'receipt'=>$old,'old'=>$old];
        if(($plan['mode']??null)!=='native')throw new RuntimeException('native_template_preview_kind');
        $previous=$old===null?null:array_intersect_key($old,array_flip(['actor_id','fingerprint','mode','media_ids','control_id']));
        $r=['version'=>1,'template_id'=>$id,'actor_id'=>$actor,'fingerprint'=>$fingerprint,'mode'=>$mode,'nonce'=>bin2hex(random_bytes(16)),'phase'=>'sending','expected'=>PostedTelegramPreviewProof::expectedCount($plan,false),'media_ids'=>[],'control_id'=>null,'previous'=>$previous];
        self::write($pdo,self::key($id,$actor),$r);return['reuse'=>false,'receipt'=>$r,'old'=>$old];
    }
    private static function outcome(PDO$pdo,int$id,int$actor,string$nonce):array
    {
        $legacy=self::validateLegacy($pdo,$id,true);$own=self::read($pdo,self::key($id,$actor),$id,$actor,true);$matches=[];
        foreach([self::legacyKey($id)=>$legacy,self::key($id,$actor)=>$own]as$key=>$r)if($r!==null&&$r['actor_id']===$actor&&hash_equals($r['nonce'],$nonce))$matches[]=['key'=>$key,'receipt'=>$r];
        if(count($matches)!==1)throw new RuntimeException('native_template_preview_outcome_changed');return$matches[0];
    }
    /** Current ownership may have changed. Only the exact existing actor+nonce receipt can be updated. */
    public static function record(PDO$pdo,int$id,int$actor,string$nonce,callable$change):array
    {
        if($pdo->inTransaction())throw new LogicException('native_template_preview_outcome_outer_sql');$pdo->beginTransaction();try{
            $found=self::outcome($pdo,$id,$actor,$nonce);$r=$found['receipt'];if(!in_array($r['phase'],['sending','media_accepted'],true))throw new RuntimeException('native_template_preview_not_pending');$next=self::normalize($change($r),$id,$actor);
            foreach(['version','template_id','actor_id','fingerprint','mode','nonce','expected','previous']as$key)if($next[$key]!==$r[$key])throw new RuntimeException('native_template_preview_outcome_identity');
            if(array_diff($r['media_ids'],$next['media_ids'])!==[]||($r['control_id']!==null&&$next['control_id']!==$r['control_id']))throw new RuntimeException('native_template_preview_known_ids_changed');
            if($next['phase']==='shown')throw new RuntimeException('native_template_preview_outcome_not_authority');self::write($pdo,$found['key'],$next);$pdo->commit();return$next;
        }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
    public static function currentLocked(PDO$pdo,array$template,int$actor,string$nonce,string$fingerprint):array
    {
        if(!$pdo->inTransaction()||(int)$template['owner_id']!==$actor||!PostedTelegramNativeTemplate::isNative($template))throw new RuntimeException('native_template_preview_authority_changed');
        $r=self::load($pdo,(int)$template['id'],$actor,true);if($r===null||!hash_equals($r['nonce'],$nonce)||!hash_equals($r['fingerprint'],$fingerprint)||!in_array($r['phase'],['sending','media_accepted'],true))throw new RuntimeException('native_template_preview_claim_changed');return$r;
    }
    public static function shownLocked(PDO$pdo,array$template,int$actor,string$nonce,string$fingerprint):array
    {
        $r=self::currentLocked($pdo,$template,$actor,$nonce,$fingerprint);$r['phase']='shown';self::normalize($r,(int)$template['id'],$actor);
        self::write($pdo,self::key((int)$template['id'],$actor),$r);return$r;
    }
}
