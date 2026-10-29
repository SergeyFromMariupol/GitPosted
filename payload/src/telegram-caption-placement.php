<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Optional positive placement metadata. Current SQL callers own all authorization. */
final class PostedTelegramCaptionPlacement
{
    public const FIELD='_telegram_caption_placement';
    public static function key(string$kind,int$id):string
    {if(!in_array($kind,['p','t','a'],true)||$id<1)throw new InvalidArgumentException('caption_placement_identity');return'tg_caption_place_'.$kind.'_'.$id;}
    public static function json(mixed$value):string
    {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function normalize(array$value,array$object,string$kind):array
    {
        $keys=array_keys($value);sort($keys);$wanted=['above','channel_id','object_id','object_kind','version'];if($kind==='a')$wanted[]='submission_key';sort($wanted);
        if($keys!==$wanted||($value['version']??null)!==1||$value['object_kind']!==$kind||$value['object_id']!==(int)($object['id']??0)||$value['object_id']<1||$value['channel_id']!==(int)($object['channel_id']??0)||($kind==='t'?$value['channel_id']<0:$value['channel_id']<1)||$value['above']!==true)throw new InvalidArgumentException('caption_placement_binding');
        self::key($kind,$value['object_id']);if($kind==='a'&&(!is_string($value['submission_key'])||preg_match('/\A[a-f0-9]{64}\z/D',$value['submission_key'])!==1))throw new InvalidArgumentException('caption_placement_attempt_key');return$value;
    }
    public static function raw(PDO$pdo,string$kind,int$id,bool$lock=false):?array
    {$q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([self::key($kind,$id)]);$raw=$q->fetchColumn();if($raw===false)return null;if(!is_string($raw)||strlen($raw)>4096)throw new InvalidArgumentException('caption_placement_record');$v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new InvalidArgumentException('caption_placement_record');return$v;}
    public static function load(PDO$pdo,array$object,string$kind='p',bool$lock=false):?array
    {$v=self::raw($pdo,$kind,(int)($object['id']??0),$lock);return$v===null?null:self::normalize($v,$object,$kind);}
    public static function input(array$message,array$content,?array$repost=null):bool
    {
        $above=$message['show_caption_above_media']??false;if(!is_bool($above))throw new InvalidArgumentException('Некорректное расположение описания. Исходный ввод сохранён.');if(!$above)return false;
        if($repost!==null||isset($message['forward_origin'])||isset($message['forward_from_chat'])||isset($message['paid_media'])||trim((string)($message['media_group_id']??''))!==''||count($content['media']??[])!==1||!in_array($content['media_type']??null,['photo','video','animation'],true))throw new InvalidArgumentException('Расположение описания над медиа пока поддерживается только для отдельного фото, видео или анимации. Исходный ввод сохранён.');return true;
    }
    public static function assertCompatible(array$object):void
    {
        $media=$object['media_json']??[];if(is_string($media))$media=$media===''?[]:json_decode($media,true,32,JSON_THROW_ON_ERROR);if(!is_array($media))throw new InvalidArgumentException('caption_placement_media');
        if($media===[]&&trim((string)($object['media_file_id']??''))!=='')$media=[['type'=>$object['media_type']??'none','file_id'=>$object['media_file_id']]];
        if(count($media)!==1||!in_array($object['media_type']??null,['photo','video','animation'],true)||($media[0]['type']??null)!==($object['media_type']??null)||trim((string)($media[0]['media_group_id']??''))!==''||!empty($object['repost_chat_id'])||!empty($object['repost_message_id'])||!empty($object['repost_input_chat_id'])||!empty($object['repost_input_message_id'])||!empty($object['external_message_id'])||($object['_telegram_post_options']['paid_media_stars']??null)!==null)throw new InvalidArgumentException('Расположение описания над этим видом содержимого не поддерживается. Пост и изменения сохранены.');
    }
    public static function captureLocked(PDO$pdo,array$object,bool$above,string$kind='p'):void
    {
        if(!$pdo->inTransaction()||!in_array($kind,['p','t'],true))throw new LogicException('caption_placement_transaction');$old=self::load($pdo,$object,$kind,true);
        if(!$above){if($old!==null){$q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=? AND setting_value=CAST(? AS JSON)');$q->execute([self::key($kind,(int)$object['id']),self::json($old)]);if($q->rowCount()!==1)throw new RuntimeException('caption_placement_clear_changed');}return;}
        self::assertCompatible($object);if($old!==null)return;$v=self::normalize(['version'=>1,'object_kind'=>$kind,'object_id'=>(int)$object['id'],'channel_id'=>(int)($object['channel_id']??0),'above'=>true],$object,$kind);$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value)VALUES(?,?)')->execute([self::key($kind,(int)$object['id']),self::json($v)]);
    }
    private static function choice(array$post,?array$value,string$context):array
    {if(!in_array($context,['preview','attempt','edit'],true))throw new InvalidArgumentException('caption_placement_context');return['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'context'=>$context,'above'=>$value===null?null:true];}
    private static function local(array$post):?array
    {if(!array_key_exists(self::FIELD,$post))return null;$v=$post[self::FIELD];$keys=is_array($v)?array_keys($v):[];sort($keys);if($keys!==['above','channel_id','context','post_id','version']||($v['version']??null)!==1||$v['post_id']!==(int)$post['id']||$v['channel_id']!==(int)$post['channel_id']||!in_array($v['context']??null,['preview','attempt','edit'],true)||!in_array($v['above'],[null,true],true))throw new InvalidArgumentException('caption_placement_local');return$v;}
    private static function attempt(PDO$pdo,array$post,bool$lock=false):?array
    {$q=$pdo->prepare('SELECT submission_key,state,gate_channel_id,channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([(int)$post['id']]);$a=$q->fetch();return is_array($a)?$a:null;}
    private static function attemptValue(array$value,array$post,array$attempt):array
    {
        $value=self::normalize($value,$post,'a');if((int)$attempt['channel_id']!==(int)$post['channel_id'])throw new InvalidArgumentException('caption_placement_attempt_channel');$completed=$attempt['submission_key']===null&&$attempt['state']==='completed'&&$attempt['gate_channel_id']===null&&in_array($post['status']??null,['sent','deleted'],true);
        if(!$completed&&(!is_string($attempt['submission_key'])||!hash_equals($value['submission_key'],$attempt['submission_key'])))throw new InvalidArgumentException('caption_placement_attempt_changed');return$value;
    }
    public static function freeze(PDO$pdo,array$post):array
    {
        $local=self::local($post);if($local!==null&&$local['context']!=='preview')return$post;
        $a=self::attempt($pdo,$post);$snapshot=self::raw($pdo,'a',(int)$post['id']);
        if($a!==null)$value=$snapshot===null?null:self::attemptValue($snapshot,$post,$a);
        else{if($snapshot!==null)throw new InvalidArgumentException('caption_placement_orphan_snapshot');$value=self::load($pdo,$post);}
        if($value!==null)self::assertCompatible($post);$post[self::FIELD]=self::choice($post,$value,'attempt');return$post;
    }
    public static function preview(PDO$pdo,array$post):array
    {
        $local=self::local($post);if($local!==null&&$local['context']==='edit')return$post;
        // Recorded unresolved publications never adopt a later live P envelope.
        $a=self::attempt($pdo,$post);$snapshot=self::raw($pdo,'a',(int)$post['id']);if($a===null&&$snapshot!==null)throw new InvalidArgumentException('caption_placement_orphan_snapshot');if($a!==null&&$snapshot!==null)self::attemptValue($snapshot,$post,$a);if($a!==null&&$a['state']!=='completed')return self::freeze($pdo,$post);
        $v=self::load($pdo,$post);if($v!==null)self::assertCompatible($post);$post[self::FIELD]=self::choice($post,$v,'preview');return$post;
    }
    public static function enabled(array$post):bool{return(self::local($post)['above']??null)===true;}
    public static function behavior(array$post):array{return self::enabled($post)?['version'=>1,'show_caption_above_media'=>true]:[];}
    public static function project(array$post,array$wire):array
    {if(self::enabled($post)){self::assertCompatible($post);$wire['show_caption_above_media']=true;}return$wire;}
    public static function assertCaption(array$post,string$text):void
    {if(self::enabled($post)&&PostedTelegramTransportLayout::characters($text)>1024)throw new InvalidArgumentException('Описание над медиа длиннее 1024 символов пока нельзя безопасно отправить. Пост и описание сохранены; публикация не выполнялась.');}
    public static function templatePost(PDO$pdo,array$template,array$post):array
    {$value=self::load($pdo,$template,'t');if($value!==null)self::assertCompatible($post);$post[self::FIELD]=self::choice($post,$value,'edit');return$post;}
    public static function snapshotLocked(PDO$pdo,array$post,string$key):void
    {
        if(!$pdo->inTransaction()||preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1)throw new LogicException('caption_placement_snapshot_transaction');$local=self::local($post);if($local===null||$local['context']!=='attempt')throw new LogicException('caption_placement_not_frozen');
        $a=self::attempt($pdo,$post,true);if($a===null||$a['state']!=='sending'||(int)$a['channel_id']!==(int)$post['channel_id']||(int)$a['gate_channel_id']!==(int)$post['channel_id']||!is_string($a['submission_key'])||!hash_equals($key,$a['submission_key']))throw new RuntimeException('caption_placement_reservation_changed');$old=self::raw($pdo,'a',(int)$post['id'],true);
        if($local['above']===null){if($old!==null)throw new RuntimeException('caption_placement_snapshot_changed');return;}
        self::assertCompatible($post);$v=['version'=>1,'object_kind'=>'a','object_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'above'=>true,'submission_key'=>$key];if($old!==null){if(self::attemptValue($old,$post,$a)!=$v)throw new RuntimeException('caption_placement_snapshot_changed');return;}$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value)VALUES(?,?)')->execute([self::key('a',(int)$post['id']),self::json($v)]);
    }
    public static function cloneLocked(PDO$pdo,array$source,array$target,string$from='p',string$to='p'):void
    {
        if(!$pdo->inTransaction()||!in_array($from,['p','t'],true)||!in_array($to,['p','t'],true))throw new LogicException('caption_placement_clone_transaction');
        if($from==='p'){$a=self::attempt($pdo,$source);if($a!==null&&$a['state']!=='completed'){$source=self::freeze($pdo,$source);$above=self::enabled($source);}else$above=self::load($pdo,$source)!==null;}
        else$above=self::load($pdo,$source,$from)!==null;
        $q=$pdo->prepare('SELECT * FROM '.($to==='p'?'posted_posts':'posted_templates').' WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$target['id']]);$current=$q->fetch();if(!is_array($current)||(int)($current['channel_id']??0)!==(int)($target['channel_id']??0))throw new RuntimeException('caption_placement_clone_target');
        if($to==='t'&&class_exists('PostedTelegramTemplateMedia',false))$current=PostedTelegramTemplateMedia::hydrate($pdo,$current);self::captureLocked($pdo,$current,$above,$to);
    }
    public static function scopeSnapshot(PDO$pdo,array$posts,array$templates):array
    {
        $result=['posts'=>[],'templates'=>[]];foreach($posts as$p){$values=[];foreach(['p','a']as$k){$v=self::load($pdo,$p,$k);if($v!==null)$values[$k]=hash('sha256',self::json($v));}if($values!==[])$result['posts'][(int)$p['id']]=$values;}foreach($templates as$t){$v=self::load($pdo,$t,'t');if($v!==null)$result['templates'][(int)$t['id']]=hash('sha256',self::json($v));}ksort($result['posts'],SORT_NUMERIC);ksort($result['templates'],SORT_NUMERIC);return$result;
    }
    public static function editDocument(PDO$pdo,array$post,array$document):array
    {
        $current=self::load($pdo,$post)!==null?true:null;$present=array_key_exists('caption_placement',$document);$v=$document['caption_placement']??null;
        if(!$present){if($current===null)return$document;$document['caption_placement']=['base'=>true,'draft'=>true];return$document;}
        $keys=is_array($v)?array_keys($v):[];sort($keys);if($keys!==['base','draft']||!in_array($v['base'],[null,true],true)||!in_array($v['draft'],[null,true],true)||$v['base']!==$current)throw new RuntimeException('caption_placement_edit_stale');return$document;
    }
    public static function replaceEdit(array$document,bool$above):array
    {
        $base=$document['caption_placement']['base']??null;$draft=$above?true:null;if($base===null&&$draft===null){unset($document['caption_placement']);return$document;}$document['caption_placement']=['base'=>$base,'draft'=>$draft];return$document;
    }
    public static function editPost(array$post,array$document):array
    {$v=$document['caption_placement']['draft']??null;$post[self::FIELD]=self::choice($post,$v===true?['above'=>true]:null,'edit');if($v===true)self::assertCompatible($post);return$post;}
    public static function editChanged(array$document):bool
    {return isset($document['caption_placement'])&&$document['caption_placement']['base']!==$document['caption_placement']['draft'];}
    public static function commitEditLocked(PDO$pdo,array$before,array$after,array$document):void
    {if(!array_key_exists('caption_placement',$document))return;self::editDocument($pdo,$before,$document);self::captureLocked($pdo,$after,($document['caption_placement']['draft']??null)===true);}
    public static function observedPart(array$message,array$part):array
    {if(($message['show_caption_above_media']??null)===true)$part['caption_above']=true;return$part;}
    public static function matches(array$expected,array$observed):bool
    {return !array_key_exists('caption_above',$expected)||($expected['caption_above']===true&&($observed['caption_above']??null)===true);}
    public static function retireKnownRejectedAttemptLocked(PDO$pdo,int$id):void
    {
        if(!$pdo->inTransaction())throw new LogicException('caption_placement_reject_transaction');$v=self::raw($pdo,'a',$id,true);if($v===null)return;
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$p=$q->fetch();$q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$a=$q->fetch();if(!is_array($p)||!is_array($a)||$p['status']!=='publishing'||$a['state']!=='sending'||(int)$a['gate_channel_id']!==(int)$p['channel_id'])throw new RuntimeException('caption_placement_reject_binding');self::attemptValue($v,$p,$a);
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$k)if(($a[$k]??null)!==null)throw new RuntimeException('caption_placement_reject_accepted');if((int)($p['telegram_message_id']??0)!==0||(int)($a['pin_attempts']??0)!==0||(int)($a['pin_delivery_uncertain']??0)!==0)throw new RuntimeException('caption_placement_reject_accepted');
        $decode=static function(mixed$r,bool$nullable=false):array{if($r===null&&$nullable)return[];if(!is_string($r)||strlen($r)>1048576)throw new RuntimeException('caption_placement_reject_parts');$x=json_decode($r,true,64,JSON_THROW_ON_ERROR);if(!is_array($x)||!array_is_list($x))throw new RuntimeException('caption_placement_reject_parts');return$x;};
        foreach($decode($p['telegram_message_ids']??null,true)as$mid)if($mid!==0)throw new RuntimeException('caption_placement_reject_accepted');$slots=$decode($a['message_slots_json']);$parts=$decode($a['pending_parts_json']);if($slots===[]||count($slots)!==count($parts)||count($parts)>1000)throw new RuntimeException('caption_placement_reject_parts');foreach($slots as$mid)if($mid!==0)throw new RuntimeException('caption_placement_reject_accepted');foreach($parts as$i=>$part){if(!is_array($part)||($part['slot']??null)!==$i)throw new RuntimeException('caption_placement_reject_parts');foreach(['message_id','update_id','published_at','observed_message_id']as$k)if(($part[$k]??null)!==null)throw new RuntimeException('caption_placement_reject_accepted');foreach(['api_confirmed','channel_confirmed']as$k)if(array_key_exists($k,$part)&&$part[$k]!==false)throw new RuntimeException('caption_placement_reject_accepted');}
        foreach($decode($a['continuation_json'])as$part)if(!is_array($part)||array_diff(array_keys($part),['text','extra'])!==[]||!is_string($part['text']??null)||!is_array($part['extra']??null))throw new RuntimeException('caption_placement_reject_parts');$q=$pdo->prepare('SELECT chat_id FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$p['channel_id']]);$chat=$q->fetchColumn();if(!is_string($chat)||$chat===''||(string)$a['chat_id']!==$chat)throw new RuntimeException('caption_placement_reject_binding');$q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');$q->execute([self::key('a',$id)]);if($q->rowCount()!==1)throw new RuntimeException('caption_placement_reject_changed');
    }
}
