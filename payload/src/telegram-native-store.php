<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-content.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Typed permanent post/template/attempt envelope; caller owns current SQL authorization. */
final class PostedTelegramNativeStore
{
    public static function key(string $kind,int $id):string
    {if(!in_array($kind,['p','t','a'],true)||$id<1)throw new InvalidArgumentException('native_content_key');return'tgnc_'.$kind.'_'.$id;}
    public static function raw(PDO $pdo,string $kind,int $id,bool $lock=false):?array
    {$s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([self::key($kind,$id)]);$raw=$s->fetchColumn();if($raw===false)return null;if(!is_string($raw)||strlen($raw)>PostedTelegramNativeContent::LIMIT)throw new InvalidArgumentException('native_content_envelope');$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new InvalidArgumentException('native_content_envelope');return$v;}
    public static function normalize(array $v,array $object,string $kind):array
    {
        $keys=array_keys($v);sort($keys);if($keys!==['channel_id','content_sha256','descriptor','object_id','object_kind','revision','submission_key','version']||($v['version']??null)!==1||$v['object_kind']!==$kind||$v['object_id']!==(int)($object['id']??0)||$v['object_id']<1||$v['channel_id']!==(int)($object['channel_id']??0)||!is_int($v['revision'])||$v['revision']<1||$v['revision']>2147483647)throw new InvalidArgumentException('native_content_binding');
        if(!in_array($kind,['p','t','a'],true)||($kind!=='t'&&$v['channel_id']<1)||($kind==='t'&&$v['channel_id']<0))throw new InvalidArgumentException('native_content_binding');
        $d=PostedTelegramNativeContent::normalize($v['descriptor']);if(!is_string($v['content_sha256'])||!hash_equals(PostedTelegramNativeContent::hash($d),$v['content_sha256']))throw new InvalidArgumentException('native_content_digest');
        if($kind==='a'){if(!is_string($v['submission_key'])||preg_match('/\A[a-f0-9]{64}\z/D',$v['submission_key'])!==1)throw new InvalidArgumentException('native_content_submission');}elseif($v['submission_key']!==null)throw new InvalidArgumentException('native_content_submission');
        if(($object['media_type']??null)!==$d['kind']||($object['media_file_id']??null)!==($d['payload']['file_id']??null))throw new InvalidArgumentException('native_content_row_changed');
        $v['descriptor']=$d;return$v;
    }
    public static function load(PDO $pdo,array $object,string $kind='p',bool $lock=false):?array
    {$v=self::raw($pdo,$kind,(int)($object['id']??0),$lock);if($v===null){if(PostedTelegramNativeContent::isKind($object['media_type']??null))throw new InvalidArgumentException('Native содержимое отсутствует. Пост сохранён; отправка остановлена.');return null;}return self::normalize($v,$object,$kind);}
    public static function captureLocked(PDO $pdo,array $object,array $descriptor,string $kind='p'):array
    {
        if(!$pdo->inTransaction()||!in_array($kind,['p','t'],true))throw new LogicException('native_content_transaction');PostedTelegramNativeSchema::requireStorageSupported($pdo);$d=PostedTelegramNativeContent::normalize($descriptor);$previous=self::raw($pdo,$kind,(int)$object['id'],true);
        if($previous!==null){$old=self::normalize($previous,$object,$kind);if(!hash_equals($old['content_sha256'],PostedTelegramNativeContent::hash($d)))throw new RuntimeException('native_content_replace_requires_explicit_cas');return$old;}
        $v=self::normalize(['version'=>1,'object_kind'=>$kind,'object_id'=>(int)$object['id'],'channel_id'=>(int)($object['channel_id']??0),'revision'=>1,'descriptor'=>$d,'content_sha256'=>PostedTelegramNativeContent::hash($d),'submission_key'=>null],$object,$kind);self::write($pdo,$v);return$v;
    }
    private static function write(PDO $pdo,array $v):void
    {$raw=PostedTelegramNativeContent::json($v);if(strlen($raw)>PostedTelegramNativeContent::LIMIT)throw new InvalidArgumentException('native_content_size');$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key($v['object_kind'],$v['object_id']),$raw]);}
    public static function freeze(PDO $pdo,array $post):array
    {
        if(array_key_exists('_telegram_native_content',$post)){if($post['_telegram_native_content']!==null)$post['_telegram_native_content']=PostedTelegramNativeContent::normalize($post['_telegram_native_content']);return$post;}
        $s=$pdo->prepare('SELECT submission_key,state,gate_channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$s->execute([(int)$post['id']]);$attempt=$s->fetch();
        if(is_array($attempt)){
            $raw=self::raw($pdo,'a',(int)$post['id']);
            // Absence on an existing journal is a permanent legacy choice, never a live lookup.
            if($raw===null){$post['_telegram_native_content']=null;$post['_telegram_native_legacy_recorded']=true;return$post;}
            $v=self::normalize($raw,$post,'a');$completed=$attempt['submission_key']===null&&$attempt['state']==='completed'&&$attempt['gate_channel_id']===null&&in_array($post['status']??null,['sent','deleted'],true);
            if(!$completed&&(!is_string($attempt['submission_key'])||!hash_equals($v['submission_key'],$attempt['submission_key'])))throw new InvalidArgumentException('native_content_attempt_changed');
            $post['_telegram_native_content']=$v['descriptor'];$post['_telegram_native_legacy_recorded']=false;return$post;
        }
        $v=self::load($pdo,$post);$post['_telegram_native_content']=$v['descriptor']??null;$post['_telegram_native_legacy_recorded']=false;return$post;
    }
    public static function behavior(array $post):array
    {return($post['_telegram_native_content']??null)===null?[]:PostedTelegramNativeContent::normalize($post['_telegram_native_content']);}
    /** AFTER new journal INSERT, same Tx, using the exact descriptor chosen in RAM before key generation. */
    public static function snapshotLocked(PDO $pdo,array $post,string $submissionKey):void
    {
        if(!$pdo->inTransaction()||!array_key_exists('_telegram_native_content',$post))throw new LogicException('native_content_choice_not_frozen');if(($post['_telegram_native_legacy_recorded']??true)===true)throw new LogicException('native_content_legacy_attempt_not_upgradeable');$d=$post['_telegram_native_content'];if($d===null)return;$d=PostedTelegramNativeContent::normalize($d);
        $s=$pdo->prepare('SELECT post_id,channel_id,submission_key,state,gate_channel_id,accepted_at,completed_at,finalized_at FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$post['id']]);$a=$s->fetch();
        if(!is_array($a)||(int)$a['post_id']!==(int)$post['id']||(int)$a['channel_id']!==(int)$post['channel_id']||(int)$a['gate_channel_id']!==(int)$post['channel_id']||$a['submission_key']!==$submissionKey||$a['state']!=='sending'||$a['accepted_at']!==null||$a['completed_at']!==null||$a['finalized_at']!==null)throw new RuntimeException('native_content_snapshot_attempt');
        $v=self::normalize(['version'=>1,'object_kind'=>'a','object_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'revision'=>1,'descriptor'=>$d,'content_sha256'=>PostedTelegramNativeContent::hash($d),'submission_key'=>$submissionKey],$post,'a');$old=self::raw($pdo,'a',(int)$post['id'],true);
        if($old!==null){if(PostedTelegramNativeContent::json(self::normalize($old,$post,'a'))!==PostedTelegramNativeContent::json($v))throw new RuntimeException('native_content_snapshot_changed');return;}self::write($pdo,$v);
    }
    public static function cloneLocked(PDO $pdo,array $source,array $target,string $from='p',string $to='p'):void
    {if(!$pdo->inTransaction())throw new LogicException('native_content_transaction');if($from==='p'){$frozen=self::freeze($pdo,$source);$d=$frozen['_telegram_native_content'];if($d===null&&PostedTelegramNativeContent::isKind($source['media_type']??null))throw new RuntimeException('native_content_source_attempt_unavailable');}else{$v=self::load($pdo,$source,$from,true);$d=$v['descriptor']??null;}if($d!==null){if(!in_array($to,['p','t'],true))throw new InvalidArgumentException('native_content_clone_target');$q=$pdo->prepare('SELECT * FROM '.($to==='p'?'posted_posts':'posted_templates').' WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$target['id']]);$actual=$q->fetch();if(!is_array($actual)||(int)($actual['channel_id']??0)!==(int)($target['channel_id']??0))throw new RuntimeException('native_content_clone_target_changed');self::captureLocked($pdo,$actual,$d,$to);}}
    /** Positive exact-peer MID is retained as observation only, never payload confirmation. */
    public static function recordObservedAck(PDO $pdo,array $post,int $mid):void
    {
        if($pdo->inTransaction()||$mid<1)throw new LogicException('native_content_observation_transaction');$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$actual=$q->fetch();$q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$a=$q->fetch();
            if(!is_array($actual)||!is_array($a)||(int)$actual['channel_id']!==(int)$post['channel_id']||(int)$a['channel_id']!==(int)$post['channel_id']||(string)$a['chat_id']!==(string)$post['chat_id']||$a['state']!=='sending')throw new RuntimeException('native_content_observation_changed');$v=self::load($pdo,$actual,'a',true);if($v===null||$v['submission_key']!==$a['submission_key'])throw new RuntimeException('native_content_observation_snapshot');$parts=json_decode($a['pending_parts_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($parts)||!array_is_list($parts)||count($parts)!==1||($parts[0]['slot']??null)!==0||($parts[0]['message_id']??null)!==null||($parts[0]['native_content_sha256']??null)!==$v['content_sha256'])throw new RuntimeException('native_content_observation_parts');if(isset($parts[0]['native_observed_message_id'])&&$parts[0]['native_observed_message_id']!==$mid)throw new RuntimeException('native_content_observation_conflict');$parts[0]['native_observed_message_id']=$mid;$pdo->prepare('UPDATE posted_telegram_publish_attempts SET pending_parts_json=? WHERE post_id=?')->execute([PostedTelegramNativeContent::json($parts),(int)$post['id']]);$pdo->commit();
        }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
    public static function scopeSnapshot(PDO $pdo,array $object,string $kind='p'):?array
    {$v=self::load($pdo,$object,$kind);return$v===null?null:['key'=>self::key($kind,(int)$object['id']),'sha256'=>PostedTelegramNativeContent::hash($v)];}
    public static function postScopeSnapshot(PDO $pdo,array $post):array
    {
        $out=[];$p=self::scopeSnapshot($pdo,$post);if($p!==null)$out['post']=$p;$a=self::raw($pdo,'a',(int)$post['id']);
        if($a!==null){$a=self::normalize($a,$post,'a');$out['attempt']=['key'=>self::key('a',(int)$post['id']),'sha256'=>PostedTelegramNativeContent::hash($a)];}return$out;
    }
    public static function detachTemplateLocked(PDO $pdo,array $template,int $expectedRevision,string $expectedContentHash):void
    {
        if(!$pdo->inTransaction())throw new LogicException('native_content_transaction');$v=self::load($pdo,$template,'t',true);
        if($v===null||$v['revision']!==$expectedRevision||!hash_equals($v['content_sha256'],$expectedContentHash))throw new RuntimeException('native_template_content_changed');
        $q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=? AND setting_value=CAST(? AS JSON)');$q->execute([self::key('t',(int)$template['id']),PostedTelegramNativeContent::json($v)]);if($q->rowCount()!==1)throw new RuntimeException('native_template_content_changed');
    }
    /** Explicit same-template native replacement only; caller owns actor/range/template locks and workflow CAS. */
    public static function replaceTemplateLocked(PDO $pdo,array $template,array $descriptor,int $expectedRevision,string $expectedContentHash):array
    {
        if(!$pdo->inTransaction())throw new LogicException('native_content_transaction');PostedTelegramNativeSchema::requireEnabled($pdo);
        $q=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$template['id']]);$actual=$q->fetch();
        if(!is_array($actual)||(isset($template['owner_id'])&&(int)$actual['owner_id']!==(int)$template['owner_id'])||(int)($actual['channel_id']??0)!==(int)($template['channel_id']??0))throw new RuntimeException('native_template_owner_changed');
        $old=self::load($pdo,$actual,'t',true);if($old===null||$old['revision']!==$expectedRevision||!hash_equals($old['content_sha256'],$expectedContentHash))throw new RuntimeException('native_template_content_changed');
        $d=PostedTelegramNativeContent::normalize($descriptor);if(hash_equals($old['content_sha256'],PostedTelegramNativeContent::hash($d)))return$old;
        if((string)($actual['text']??'')!==''||!empty($actual['text_entities_json'])&&$actual['text_entities_json']!=='[]')throw new RuntimeException('native_template_has_unexpected_caption');
        $next=$actual;$next['media_type']=$d['kind'];$next['media_file_id']=$d['payload']['file_id']??null;
        $q=$pdo->prepare('UPDATE posted_templates SET media_type=?,media_file_id=? WHERE id=? AND owner_id=? AND media_type=? AND media_file_id<=>?');$q->execute([$next['media_type'],$next['media_file_id'],(int)$actual['id'],(int)$actual['owner_id'],$actual['media_type'],$actual['media_file_id']]);
        $verify=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1');$verify->execute([(int)$actual['id']]);$current=$verify->fetch();if(!is_array($current)||(int)$current['owner_id']!==(int)$actual['owner_id']||$current['media_type']!==$next['media_type']||$current['media_file_id']!==$next['media_file_id'])throw new RuntimeException('native_template_content_changed');
        $v=self::normalize(['version'=>1,'object_kind'=>'t','object_id'=>(int)$actual['id'],'channel_id'=>(int)($actual['channel_id']??0),'revision'=>$old['revision']+1,'descriptor'=>$d,'content_sha256'=>PostedTelegramNativeContent::hash($d),'submission_key'=>null],$current,'t');self::write($pdo,$v);return$v;
    }
    /** Only the two definite zero-delivery rejection transitions may retire an attempt snapshot. */
    public static function retireKnownRejectedAttemptLocked(PDO $pdo,int $postId):void
    {
        if(!$pdo->inTransaction()||$postId<1)throw new LogicException('native_content_requires_transaction');
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$post=$q->fetch();
        $q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$attempt=$q->fetch();
        $header=self::raw($pdo,'a',$postId,true);if($header===null)return; // Established legacy attempts have no native snapshot.
        if(!is_array($post)||!is_array($attempt)||($post['status']??null)!=='publishing'||($attempt['state']??null)!=='sending'
            ||(int)$attempt['post_id']!==$postId||(int)$post['channel_id']<1||(int)$attempt['channel_id']!==(int)$post['channel_id']
            ||(int)($attempt['gate_channel_id']??0)!==(int)$post['channel_id']||!is_string($attempt['submission_key']??null)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$attempt['submission_key'])!==1||!is_string($header['submission_key']??null)
            ||!hash_equals($attempt['submission_key'],$header['submission_key']))throw new RuntimeException('native_content_reject_binding');
        // No authority/network lookup here: settling a known rejection must also work after access loss.
        // This nonlocking read preserves the existing post/attempt lock order.
        $q=$pdo->prepare('SELECT chat_id FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$chat=$q->fetchColumn();
        if(!is_string($chat)||$chat===''||(string)$attempt['chat_id']!==$chat)throw new RuntimeException('native_content_reject_chat');
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$f)if(($attempt[$f]??null)!==null)throw new RuntimeException('native_content_reject_accepted');
        if((int)($attempt['pin_attempts']??0)!==0||(int)($attempt['pin_delivery_uncertain']??0)!==0||(int)($post['telegram_message_id']??0)!==0)throw new RuntimeException('native_content_reject_accepted');
        $decode=static function(mixed $raw,bool $nullable=false):array{if($raw===null&&$nullable)return[];if(!is_string($raw)||strlen($raw)>1048576)throw new RuntimeException('native_content_reject_json');$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))throw new RuntimeException('native_content_reject_json');return$v;};
        foreach($decode($post['telegram_message_ids']??null,true)as$id)if(!is_int($id)||$id!==0)throw new RuntimeException('native_content_reject_accepted');
        $slots=$decode($attempt['message_slots_json']??null);$parts=$decode($attempt['pending_parts_json']??null);
        if($slots===[]||count($slots)!==count($parts)||count($parts)>1000)throw new RuntimeException('native_content_reject_parts');
        foreach($slots as$id)if(!is_int($id)||$id!==0)throw new RuntimeException('native_content_reject_accepted');
        foreach($parts as$i=>$part){
            if(!is_array($part)||($part['slot']??null)!==$i)throw new RuntimeException('native_content_reject_parts');
            foreach(['message_id','update_id','published_at']as$f)if(!array_key_exists($f,$part)||$part[$f]!==null)throw new RuntimeException('native_content_reject_accepted');
            foreach(['api_confirmed','channel_confirmed']as$f)if(array_key_exists($f,$part)&&$part[$f]!==false)throw new RuntimeException('native_content_reject_accepted');
        }
        foreach($decode($attempt['continuation_json']??null)as$part){if(!is_array($part)||array_diff(array_keys($part),['text','extra'])!==[]||!is_string($part['text']??null)||!is_array($part['extra']??null))throw new RuntimeException('native_content_reject_continuation');}
        $snapshot=self::load($pdo,$post,'a',true);if($snapshot===null||count($parts)!==1)throw new RuntimeException('native_content_reject_snapshot');
        $delete=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');$delete->execute([self::key('a',$postId)]);if($delete->rowCount()!==1)throw new RuntimeException('native_content_reject_changed');
    }
}
