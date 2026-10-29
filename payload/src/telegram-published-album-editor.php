<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Exact existing ordinary album edits. No transport, no post creation, no inferred MIDs. */
final class PostedTelegramPublishedAlbumEditor
{
    public static function canonical(mixed $v): mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=self::canonical($x);return$v;}
    public static function hash(mixed $v): string{return hash('sha256',json_encode(self::canonical($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    public static function id(mixed $v): int{if(is_string($v)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$v)===1&&(string)(int)$v===$v)$v=(int)$v;if(!is_int($v)||$v<1)throw new InvalidArgumentException('album_edit_identity');return$v;}
    private static function vector(mixed $v): array{if(is_string($v))$v=json_decode($v,true,32,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))throw new InvalidArgumentException('album_edit_vector');return$v;}
    private static function flag(mixed $v): bool{if($v===0||$v==='0'||$v===false)return false;if($v===1||$v==='1'||$v===true)return true;throw new InvalidArgumentException('album_edit_flag');}
    public static function stageKey(int $actor,int $post): string{return 'tg_album_stage_'.self::id($post).'_'.self::id($actor);}
    public static function operationKey(int $post): string{return 'tg_album_edit_'.self::id($post);}
    public static function read(PDO $pdo,string $key,bool $lock=false): ?array
    {
        if(preg_match('/\Atg_album_(?:edit_[1-9][0-9]{0,18}|stage_[1-9][0-9]{0,18}_[1-9][0-9]{0,18})\z/D',$key)!==1)throw new LogicException('album_edit_key');
        $s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([$key]);$raw=$s->fetchColumn();if($raw===false)return null;
        $r=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($r))throw new RuntimeException('album_edit_record_corrupt');return$r;
    }
    public static function putLocked(PDO $pdo,string $key,array $record): void
    {
        if(!$pdo->inTransaction())throw new LogicException('album_edit_requires_transaction');self::read($pdo,$key,true);
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([$key,json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }
    public static function removeStageLocked(PDO $pdo,int $actor,int $post): void
    {if(!$pdo->inTransaction())throw new LogicException('album_edit_requires_transaction');$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::stageKey($actor,$post)]);}
    public static function descriptor(PDO $pdo,array $post,array $channel,array $options,bool $lock=false): array
    {
        $post=PostedTelegramCaptionVector::freeze($pdo,$post);
        $s=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([self::id($post['id']??null)]);$a=$s->fetch();
        if(!is_array($a))throw new InvalidArgumentException('album_edit_known_delivery_required');return self::validate($post,$channel,$options,$a,PostedTelegramAuthorityPause::read($pdo,$channel,$lock));
    }
    public static function validate(array $post,array $channel,array $options,array $attempt,?array $authority=null): array
    {
        $pid=self::id($post['id']??null);$cid=self::id($post['channel_id']??null);$chat=(string)($channel['chat_id']??'');
        if($cid!==self::id($channel['id']??null)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$chat)!==1||($post['status']??null)!=='sent'||!self::flag($channel['is_active']??0)||self::id($channel['added_by']??null)<1)throw new InvalidArgumentException('album_edit_unavailable');
        foreach(['external_chat_id','external_message_id','repost_chat_id','repost_message_id','repost_input_chat_id','repost_input_message_id']as$key)if(!empty($post[$key]))throw new InvalidArgumentException('album_edit_native_or_external');
        if(($options['version']??null)!==2||($options['post_id']??null)!==$pid||($options['channel_id']??null)!==$cid||!array_key_exists('paid_media_stars',$options)||$options['paid_media_stars']!==null||!is_bool($options['media_spoiler']??null))throw new InvalidArgumentException('album_edit_paid_or_invalid_options');
        $media=self::vector($post['media_json']??null);$mids=self::vector($post['telegram_message_ids']??null);$n=count($media);
        if($n<2||$n>10||count($mids)!==$n)throw new InvalidArgumentException('album_edit_exact_media_count');
        foreach($mids as$mid)if(!is_int($mid)||$mid<1||$mid>2147483647)throw new InvalidArgumentException('album_edit_exact_mid_required');
        if(count(array_unique($mids,SORT_REGULAR))!==$n||self::id($post['telegram_message_id']??null)!==$mids[0])throw new InvalidArgumentException('album_edit_duplicate_or_unbound_mid');
        foreach($media as$item)if(!is_array($item)||!in_array($item['type']??null,['photo','video'],true)||!is_string($item['file_id']??null)||preg_match('/\A[A-Za-z0-9_=-]{1,1024}\z/D',$item['file_id'])!==1)throw new InvalidArgumentException('album_edit_unsupported_media');
        if(($post['media_type']??null)!==$media[0]['type']||($post['media_file_id']??null)!==$media[0]['file_id'])throw new InvalidArgumentException('album_edit_media_binding');
        if(self::id($attempt['post_id']??null)!==$pid||self::id($attempt['channel_id']??null)!==$cid||(string)($attempt['chat_id']??'')!==$chat||($attempt['state']??null)!=='completed'
            ||($attempt['gate_channel_id']??null)!==null||($attempt['submission_key']??null)!==null||empty($attempt['completed_at'])||empty($attempt['finalized_at'])
            ||!empty($attempt['pin_started_at'])||self::flag($attempt['pin_delivery_uncertain']??0)||(!empty($post['pin_after_publish'])&&empty($attempt['pin_completed_at'])))throw new InvalidArgumentException('album_edit_delivery_unresolved');
        $slots=self::vector($attempt['message_slots_json']??null);$parts=self::vector($attempt['pending_parts_json']??null);$continuation=self::vector($attempt['continuation_json']??'[]');
        if($slots!==$mids||count($parts)!==$n||$continuation!==[])throw new InvalidArgumentException('album_edit_composite_delivery');
        foreach($parts as$i=>$part){
            if(!is_array($part)||($part['slot']??null)!==$i||($part['message_id']??null)!==$mids[$i]||($part['media_type']??null)!==$media[$i]['type'])throw new InvalidArgumentException('album_edit_part_binding');
            $aliases=$part['media_aliases']??[];$unique=$media[$i]['file_unique_id']??null;
            if(!is_array($aliases)||(!in_array($media[$i]['file_id'],$aliases,true)&&(!is_string($unique)||$unique===''||!in_array($unique,$aliases,true))))throw new InvalidArgumentException('album_edit_part_media_binding');
            $known=($part['api_confirmed']??null)===true||(($part['channel_confirmed']??null)===true&&is_int($part['update_id']??null)&&$part['update_id']>0&&is_int($part['published_at']??null)&&$part['published_at']>0);
            if(!$known)throw new InvalidArgumentException('album_edit_part_unknown');
        }
        $binding=['post_id'=>$pid,'channel_id'=>$cid,'chat_id'=>$chat,'owner_id'=>self::id($channel['added_by']),'authority_context'=>$authority===null?null:array_intersect_key($authority,array_flip(['generation','phase','source_owner_id','bound_owner_id','since','changed_at','review_required','resumed_at'])),'media'=>$media,'mids'=>$mids,'options'=>$options,
            'row'=>array_intersect_key($post,array_flip(['author_id','text','text_entities_json','media_type','media_file_id','media_json','buttons_json','pin_after_publish','autosignature_text','autosignature_entities_json','scheduled_at','repeat_interval_minutes','repeat_until','delete_after_minutes','published_at','manager_category_id'])),
            'delivery'=>array_intersect_key($attempt,array_flip(['state','message_slots_json','pending_parts_json','continuation_json','completed_at','finalized_at','pin_started_at','pin_completed_at','pin_delivery_uncertain']))];
        if(($post['_telegram_caption_vector']??null)!==null)$binding['caption_vector']=PostedTelegramCaptionVector::normalize($post['_telegram_caption_vector'],$post);
        $binding['hash']=self::hash($binding);return$binding;
    }
    public static function active(?array $record): bool
    {if($record===null)return false;self::validateOperation($record);return$record['state']!=='completed';}
    public static function stage(array $descriptor,int $actor,string $baseHash,bool $spoiler): array
    {if(preg_match('/\A[a-f0-9]{64}\z/D',$baseHash)!==1)throw new InvalidArgumentException('album_edit_session');return['version'=>1,'post_id'=>$descriptor['post_id'],'actor_id'=>self::id($actor),'base_hash'=>$baseHash,'album_hash'=>$descriptor['hash'],'spoiler'=>$spoiler];}
    public static function validateStage(array $record,array $descriptor,int $actor,string $baseHash): array
    {
        $keys=array_keys($record);sort($keys);if($keys!==['actor_id','album_hash','base_hash','post_id','spoiler','version']||($record['version']??null)!==1||($record['post_id']??null)!==$descriptor['post_id']||($record['actor_id']??null)!==$actor||($record['album_hash']??null)!==$descriptor['hash']||($record['base_hash']??null)!==$baseHash||!is_bool($record['spoiler']??null))throw new RuntimeException('album_edit_stage_stale');return$record;
    }
    public static function plan(array $album,int $actor,string $sessionHash,array $draft,array $payload,bool $spoiler,bool $captionChanged,bool $pinChanged): array
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$sessionHash)!==1||!is_string($payload['text']??null)||!is_array($payload['entities']??null))throw new InvalidArgumentException('album_edit_plan');
        $steps=[];$chat=$album['chat_id'];$mids=$album['mids'];$spoilerChanged=$album['options']['media_spoiler']!==$spoiler;
        if($spoilerChanged){foreach($album['media']as$i=>$media)$steps[]=['method'=>'editMessageMedia','payload'=>['chat_id'=>$chat,'message_id'=>$mids[$i],'media'=>['type'=>$media['type'],'media'=>$media['file_id'],'caption'=>$i===0?$payload['text']:($album['caption_vector']['parts'][$i]['text']??''),'caption_entities'=>$i===0?$payload['entities']:($album['caption_vector']['parts'][$i]['entities']??[]),'has_spoiler'=>$spoiler]]];}
        elseif($captionChanged)$steps[]=['method'=>'editMessageCaption','payload'=>['chat_id'=>$chat,'message_id'=>$mids[0],'caption'=>$payload['text'],'caption_entities'=>$payload['entities']]];
        if($pinChanged)$steps[]=['method'=>!empty($draft['pin_after_publish'])?'pinChatMessage':'unpinChatMessage','payload'=>['chat_id'=>$chat,'message_id'=>$mids[0]]+(!empty($draft['pin_after_publish'])?['disable_notification'=>true]:[])];
        $plan=['version'=>1,'post_id'=>$album['post_id'],'channel_id'=>$album['channel_id'],'actor_id'=>self::id($actor),'album_hash'=>$album['hash'],'session_hash'=>$sessionHash,'draft'=>$draft,'spoiler'=>$spoiler,'steps'=>$steps];
        $record=['version'=>1,'plan'=>$plan,'plan_hash'=>self::hash($plan),'state'=>'applying','receipts'=>array_map(static fn(array$s):array=>['state'=>'pending','intent'=>null,'finished_at'=>null],$steps),'resume_nonce'=>null];self::validateOperation($record);return$record;
    }
    public static function validateOperation(array $record): void
    {
        $keys=array_keys($record);sort($keys);if($keys!==['plan','plan_hash','receipts','resume_nonce','state','version']||($record['version']??null)!==1||!is_array($record['plan']??null)||!is_string($record['plan_hash']??null)||!hash_equals(self::hash($record['plan']),$record['plan_hash'])||!in_array($record['state']??null,['applying','blocked','completed'],true)||!is_array($record['receipts']??null)||!array_is_list($record['receipts']))throw new RuntimeException('album_edit_operation_corrupt');
        $p=$record['plan'];$keys=array_keys($p);sort($keys);if($keys!==['actor_id','album_hash','channel_id','draft','post_id','session_hash','spoiler','steps','version']||($p['version']??null)!==1||!is_array($p['draft'])||!is_bool($p['spoiler'])||!is_array($p['steps'])||!array_is_list($p['steps'])||count($p['steps'])>11||count($p['steps'])!==count($record['receipts']))throw new RuntimeException('album_edit_operation_corrupt');
        foreach(['post_id','channel_id','actor_id']as$key)self::id($p[$key]);foreach(['album_hash','session_hash']as$key)if(!is_string($p[$key])||preg_match('/\A[a-f0-9]{64}\z/D',$p[$key])!==1)throw new RuntimeException('album_edit_operation_corrupt');
        foreach($p['steps']as$i=>$step){
            if(!is_array($step)||!in_array($step['method']??null,['editMessageMedia','editMessageCaption','pinChatMessage','unpinChatMessage'],true)||!is_array($step['payload']??null))throw new RuntimeException('album_edit_operation_corrupt');
            $keys=array_keys($step);sort($keys);if($keys!==['method','payload'])throw new RuntimeException('album_edit_operation_corrupt');$payload=$step['payload'];
            if(!is_string($payload['chat_id']??null)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$payload['chat_id'])!==1||!is_int($payload['message_id']??null)||$payload['message_id']<1||$payload['message_id']>2147483647)throw new RuntimeException('album_edit_operation_corrupt');
            $keys=array_keys($payload);sort($keys);$expected=match($step['method']){'editMessageMedia'=>['chat_id','media','message_id'],'editMessageCaption'=>['caption','caption_entities','chat_id','message_id'],'pinChatMessage'=>['chat_id','disable_notification','message_id'],default=>['chat_id','message_id']};if($keys!==$expected)throw new RuntimeException('album_edit_operation_corrupt');
            if($step['method']==='editMessageMedia'){
                $media=$payload['media'];if(!is_array($media))throw new RuntimeException('album_edit_operation_corrupt');$keys=array_keys($media);sort($keys);
                if($keys!==['caption','caption_entities','has_spoiler','media','type']||!in_array($media['type'],['photo','video'],true)||!is_string($media['media'])||preg_match('/\A[A-Za-z0-9_=-]{1,1024}\z/D',$media['media'])!==1||!is_bool($media['has_spoiler'])||!is_string($media['caption'])||!is_array($media['caption_entities'])||!array_is_list($media['caption_entities']))throw new RuntimeException('album_edit_operation_corrupt');
            }elseif($step['method']==='editMessageCaption'&&(!is_string($payload['caption'])||!is_array($payload['caption_entities'])||!array_is_list($payload['caption_entities'])))throw new RuntimeException('album_edit_operation_corrupt');
            elseif($step['method']==='pinChatMessage'&&$payload['disable_notification']!==true)throw new RuntimeException('album_edit_operation_corrupt');
            $receipt=$record['receipts'][$i];if(!is_array($receipt))throw new RuntimeException('album_edit_operation_corrupt');$keys=array_keys($receipt);sort($keys);
            if($keys!==['finished_at','intent','state']||!in_array($receipt['state']??null,['pending','sending','done','unknown','failed'],true))throw new RuntimeException('album_edit_operation_corrupt');
            if($receipt['state']==='pending'){if($receipt['intent']!==null||$receipt['finished_at']!==null)throw new RuntimeException('album_edit_operation_corrupt');}
            else{if(!is_string($receipt['intent'])||preg_match('/\A[a-f0-9]{24}\z/D',$receipt['intent'])!==1)throw new RuntimeException('album_edit_operation_corrupt');if($receipt['state']==='sending'?$receipt['finished_at']!==null:(!is_int($receipt['finished_at'])||$receipt['finished_at']<1))throw new RuntimeException('album_edit_operation_corrupt');}
            if($record['state']==='completed'&&$receipt['state']!=='done')throw new RuntimeException('album_edit_operation_corrupt');
        }
        if($record['resume_nonce']!==null&&(!is_string($record['resume_nonce'])||preg_match('/\A[a-f0-9]{24}\z/D',$record['resume_nonce'])!==1))throw new RuntimeException('album_edit_operation_corrupt');
        if(($record['state']==='blocked')!==is_string($record['resume_nonce']))throw new RuntimeException('album_edit_operation_corrupt');
    }
    public static function knownResponse(array $step,array $response): bool
    {
        if(($response['ok']??null)!==true)return false;if(in_array($step['method'],['pinChatMessage','unpinChatMessage'],true))return($response['result']??null)===true;
        $r=$response['result']??null;$payload=$step['payload'];if(!is_array($r)||($r['message_id']??null)!==$payload['message_id']||(string)($r['chat']['id']??'')!==$payload['chat_id'])return false;
        $wanted=$step['method']==='editMessageMedia'?$payload['media']:$payload;
        if(($r['caption']??'')!==$wanted['caption']||self::canonical($r['caption_entities']??[])!==self::canonical($wanted['caption_entities']))return false;
        if($step['method']==='editMessageMedia'){
            if(($wanted['type']==='photo'&&(!is_array($r['photo']??null)||$r['photo']===[]))||($wanted['type']==='video'&&!is_array($r['video']??null)))return false;
            if(($r['has_media_spoiler']??false)!==$wanted['has_spoiler'])return false;
        }return true;
    }
}
