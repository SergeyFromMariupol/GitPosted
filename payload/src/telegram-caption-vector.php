<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Ordinary ingress album captions; immutable attempt snapshots never consult later live metadata. */
final class PostedTelegramCaptionVector
{
    public const PART_LIMIT=49152;
    public static function canonical(mixed $value):mixed
    {if(!is_array($value))return$value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$part)$value[$key]=self::canonical($part);return$value;}
    public static function json(mixed $value):string
    {return json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function hash(mixed $value):string{return hash('sha256',self::json($value));}
    public static function key(string $kind,int $id,?int $part=null):string
    {
        if(!in_array($kind,['p','t','a'],true)||$id<1||($part!==null&&($part<0||$part>9)))throw new InvalidArgumentException('caption_vector_identity');
        return 'tg_caption_'.$kind.'_'.$id.($part===null?'':'_'.$part);
    }
    public static function media(array $object):array
    {
        $raw=$object['media_json']??null;
        if(is_string($raw)&&$raw!=='')$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if($raw===null||$raw==='')$raw=empty($object['media_file_id'])?[]:[['type'=>$object['media_type'],'file_id'=>$object['media_file_id'],'file_unique_id'=>null,'media_group_id'=>null]];
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>10)throw new InvalidArgumentException('caption_vector_media');
        $result=[];foreach($raw as$item){
            if(!is_array($item)||!in_array($item['type']??null,['photo','video','document','audio','animation','voice'],true)||!is_string($item['file_id']??null)||$item['file_id']===''||strlen($item['file_id'])>4096)throw new InvalidArgumentException('caption_vector_media');
            $result[]=['type'=>$item['type'],'file_id'=>$item['file_id'],'file_unique_id'=>$item['file_unique_id']??null,'media_group_id'=>$item['media_group_id']??null];
        }return$result;
    }
    public static function utf16(string $text):int
    {if(!mb_check_encoding($text,'UTF-8'))throw new InvalidArgumentException('caption_vector_utf8');return intdiv(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8')),2);}
    public static function part(array $part):array
    {
        $keys=array_keys($part);sort($keys);
        if($keys!==['entities','text']||!is_string($part['text'])||!is_array($part['entities'])||!array_is_list($part['entities'])||count($part['entities'])>1000)throw new InvalidArgumentException('caption_vector_part');
        $length=self::utf16($part['text']);if(mb_strlen($part['text'],'UTF-8')>4096)throw new InvalidArgumentException('caption_vector_caption_limit');
        foreach($part['entities']as$entity){
            if(!is_array($entity)||array_diff(array_keys($entity),['type','offset','length','url','user','language','custom_emoji_id'])!==[]
                ||!in_array($entity['type']??null,['mention','hashtag','cashtag','bot_command','url','email','phone_number','bold','italic','underline','strikethrough','spoiler','blockquote','expandable_blockquote','code','pre','text_link','text_mention','custom_emoji'],true)
                ||!is_int($entity['offset']??null)||!is_int($entity['length']??null)||$entity['offset']<0||$entity['length']<1||$entity['offset']+$entity['length']>$length)throw new InvalidArgumentException('caption_vector_entities');
            foreach(['url','language','custom_emoji_id']as$field)if(isset($entity[$field])&&(!is_string($entity[$field])||strlen($entity[$field])>8192))throw new InvalidArgumentException('caption_vector_entities');
            if(isset($entity['user'])&&(!is_array($entity['user'])||strlen(self::json($entity['user']))>8192))throw new InvalidArgumentException('caption_vector_entities');
        }
        if(strlen(self::json($part))>self::PART_LIMIT)throw new InvalidArgumentException('caption_vector_storage_limit');return$part;
    }
    public static function normalize(array $value,array $object,string $kind='p'):array
    {
        $keys=array_keys($value);sort($keys);
        if($keys!==['channel_id','kind','media_hash','object_id','parts','version']||($value['version']??null)!==1||($value['kind']??null)!==$kind
            ||($value['object_id']??null)!==(int)($object['id']??0)||$value['object_id']<1||($value['channel_id']??null)!==(int)($object['channel_id']??0)
            ||!is_string($value['media_hash']??null)||!hash_equals(self::hash(self::media($object)),$value['media_hash'])||!is_array($value['parts']??null)||!array_is_list($value['parts'])
            ||count($value['parts'])<2||count($value['parts'])!==count(self::media($object)))throw new InvalidArgumentException('caption_vector_binding');
        foreach($value['parts']as$i=>$part){if(!is_array($part))throw new InvalidArgumentException('caption_vector_part');$value['parts'][$i]=self::part($part);}
        return$value;
    }
    public static function fromItems(array $object,array $items,string $kind='p'):?array
    {
        if(count($items)<2)return null;$parts=[];$media=[];$secondary=false;
        foreach($items as$i=>$item){if(!is_array($item)||!is_array($item['media']??null))throw new InvalidArgumentException('caption_vector_ingress');$media[]=$item['media'];
            $parts[]=self::part(['text'=>$item['text']??null,'entities'=>$item['entities']??[]]);if($i>0&&($parts[$i]['text']!==''||$parts[$i]['entities']!==[]))$secondary=true;}
        if(!$secondary)return null;if(self::hash(self::media(['media_json'=>$media]))!==self::hash(self::media($object)))throw new InvalidArgumentException('caption_vector_ingress_binding');
        return self::normalize(['version'=>1,'kind'=>$kind,'object_id'=>(int)$object['id'],'channel_id'=>(int)($object['channel_id']??0),'media_hash'=>self::hash(self::media($object)),'parts'=>$parts],$object,$kind);
    }
    private static function raw(PDO $pdo,string $key,bool $lock=false):?array
    {
        $s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([$key]);$raw=$s->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>self::PART_LIMIT)throw new InvalidArgumentException('caption_vector_storage');$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($value))throw new InvalidArgumentException('caption_vector_storage');return$value;
    }
    public static function load(PDO $pdo,array $object,string $kind='p',bool $lock=false):?array
    {
        $header=self::raw($pdo,self::key($kind,(int)$object['id']),$lock);if($header===null)return null;
        $keys=array_keys($header);sort($keys);
        if($keys!==['channel_id','count','kind','media_hash','object_id','part_hashes','submission_key','version']||($header['version']??null)!==1||($header['kind']??null)!==$kind
            ||($header['object_id']??null)!==(int)$object['id']||($header['channel_id']??null)!==(int)($object['channel_id']??0)||!is_int($header['count']??null)||$header['count']<2||$header['count']>10
            ||!is_array($header['part_hashes']??null)||!array_is_list($header['part_hashes'])||count($header['part_hashes'])!==$header['count']
            ||($kind==='a'?(!is_string($header['submission_key'])||preg_match('/\A[a-f0-9]{64}\z/D',$header['submission_key'])!==1):$header['submission_key']!==null))throw new InvalidArgumentException('caption_vector_header');
        $parts=[];for($i=0;$i<$header['count'];$i++){$part=self::raw($pdo,self::key($kind,(int)$object['id'],$i),$lock);if($part===null||!is_string($header['part_hashes'][$i])||!hash_equals(self::hash($part),$header['part_hashes'][$i]))throw new InvalidArgumentException('caption_vector_missing_part');$parts[]=$part;}
        return self::normalize(['version'=>1,'kind'=>$kind,'object_id'=>$header['object_id'],'channel_id'=>$header['channel_id'],'media_hash'=>$header['media_hash'],'parts'=>$parts],$object,$kind);
    }
    public static function saveLocked(PDO $pdo,array $object,?array $value,string $kind='p',?string $submissionKey=null):void
    {
        if(!$pdo->inTransaction())throw new LogicException('caption_vector_requires_transaction');$id=(int)$object['id'];self::key($kind,$id);
        if($kind==='a'&&($submissionKey===null||preg_match('/\A[a-f0-9]{64}\z/D',$submissionKey)!==1))throw new LogicException('caption_vector_attempt_binding');
        if($value===null){self::detachLocked($pdo,$object,$kind);return;}$value=self::normalize($value,$object,$kind);
        $header=array_diff_key($value,['parts'=>true]);$header['count']=count($value['parts']);$header['part_hashes']=array_map(self::hash(...),$value['parts']);$header['submission_key']=$submissionKey;
        $statement=$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach($value['parts']as$i=>$part)$statement->execute([self::key($kind,$id,$i),self::json($part)]);$statement->execute([self::key($kind,$id),self::json($header)]);
        for($i=count($value['parts']);$i<10;$i++)$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::key($kind,$id,$i)]);
    }
    public static function detachLocked(PDO $pdo,array $object,string $kind='p'):void
    {
        if(!$pdo->inTransaction())throw new LogicException('caption_vector_requires_transaction');
        for($i=0;$i<10;$i++)$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::key($kind,(int)$object['id'],$i)]);
        $pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::key($kind,(int)$object['id'])]);
    }
    public static function captureLocked(PDO $pdo,array $object,array $items,string $kind='p'):void
    {self::saveLocked($pdo,$object,self::fromItems($object,$items,$kind),$kind);}
    public static function rebind(?array $value,array $object,string $kind='p'):?array
    {if($value===null)return null;$value['kind']=$kind;$value['object_id']=(int)$object['id'];$value['channel_id']=(int)($object['channel_id']??0);return self::normalize($value,$object,$kind);}
    public static function freeze(PDO $pdo,array $post):array
    {
        if(array_key_exists('_telegram_caption_vector',$post)){$v=$post['_telegram_caption_vector'];if($v!==null){if(!is_array($v))throw new InvalidArgumentException('caption_vector_frozen');$post['_telegram_caption_vector']=self::normalize($v,$post);}return self::compatible($post);}
        // Native forwards and their legacy stored-copy fields never adopt ingress album metadata.
        if(!empty($post['repost_chat_id'])&&!empty($post['repost_message_id'])){$post['_telegram_caption_vector']=null;return$post;}
        $s=$pdo->prepare('SELECT submission_key,state,gate_channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$s->execute([(int)$post['id']]);$attempt=$s->fetch();
        if(is_array($attempt)){
            $header=self::raw($pdo,self::key('a',(int)$post['id']));
            if($header===null){$post['_telegram_caption_vector']=null;return$post;}
            $completed=$attempt['submission_key']===null&&$attempt['state']==='completed'&&$attempt['gate_channel_id']===null&&in_array($post['status']??null,['sent','deleted'],true);
            if(!$completed&&(!is_string($attempt['submission_key'])||!is_string($header['submission_key']??null)||!hash_equals($attempt['submission_key'],$header['submission_key'])))throw new InvalidArgumentException('caption_vector_attempt_changed');
            $post['_telegram_caption_vector']=self::rebind(self::load($pdo,$post,'a'),$post);return self::compatible($post);
        }
        $post['_telegram_caption_vector']=self::load($pdo,$post);return self::compatible($post);
    }
    private static function compatible(array $post):array
    {if(($post['_telegram_caption_vector']??null)!==null&&($post['_telegram_post_options']['paid_media_stars']??null)!==null)throw new InvalidArgumentException('Платное медиа не поддерживает отдельные описания файлов альбома.');return$post;}
    public static function snapshotLocked(PDO $pdo,array $post,string $submissionKey):void
    {
        if(!$pdo->inTransaction())throw new LogicException('caption_vector_requires_transaction');$v=self::load($pdo,$post);
        $old=self::raw($pdo,self::key('a',(int)$post['id']),true);
        if($old!==null){if(($old['submission_key']??null)!==$submissionKey||self::hash(self::load($pdo,$post,'a',true))!==self::hash(self::rebind($v,$post,'a')))throw new RuntimeException('caption_vector_snapshot_changed');return;}
        if($v!==null)self::saveLocked($pdo,$post,self::rebind($v,$post,'a'),'a',$submissionKey);
    }
    /** Only the two definite zero-delivery rejection transitions may retire an attempt snapshot. */
    public static function retireKnownRejectedAttemptLocked(PDO $pdo,int $postId):void
    {
        if(!$pdo->inTransaction()||$postId<1)throw new LogicException('caption_vector_requires_transaction');
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$post=$q->fetch();
        $q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$attempt=$q->fetch();
        $header=self::raw($pdo,self::key('a',$postId),true);if($header===null)return; // Established legacy attempts have no caption snapshot.
        if(!is_array($post)||!is_array($attempt)||($post['status']??null)!=='publishing'||($attempt['state']??null)!=='sending'
            ||(int)$attempt['post_id']!==$postId||(int)$post['channel_id']<1||(int)$attempt['channel_id']!==(int)$post['channel_id']
            ||(int)($attempt['gate_channel_id']??0)!==(int)$post['channel_id']||!is_string($attempt['submission_key']??null)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$attempt['submission_key'])!==1||!is_string($header['submission_key']??null)
            ||!hash_equals($attempt['submission_key'],$header['submission_key']))throw new RuntimeException('caption_vector_reject_binding');
        // No authority/network lookup here: settling a known rejection must also work after access loss.
        // This nonlocking read preserves the existing post/attempt lock order.
        $q=$pdo->prepare('SELECT chat_id FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$chat=$q->fetchColumn();
        if(!is_string($chat)||$chat===''||(string)$attempt['chat_id']!==$chat)throw new RuntimeException('caption_vector_reject_chat');
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$f)if(($attempt[$f]??null)!==null)throw new RuntimeException('caption_vector_reject_accepted');
        if((int)($attempt['pin_attempts']??0)!==0||(int)($attempt['pin_delivery_uncertain']??0)!==0||(int)($post['telegram_message_id']??0)!==0)throw new RuntimeException('caption_vector_reject_accepted');
        $decode=static function(mixed $raw,bool $nullable=false):array{if($raw===null&&$nullable)return[];if(!is_string($raw)||strlen($raw)>1048576)throw new RuntimeException('caption_vector_reject_json');$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))throw new RuntimeException('caption_vector_reject_json');return$v;};
        foreach($decode($post['telegram_message_ids']??null,true)as$id)if(!is_int($id)||$id!==0)throw new RuntimeException('caption_vector_reject_accepted');
        $slots=$decode($attempt['message_slots_json']??null);$parts=$decode($attempt['pending_parts_json']??null);
        if($slots===[]||count($slots)!==count($parts)||count($parts)>1000)throw new RuntimeException('caption_vector_reject_parts');
        foreach($slots as$id)if(!is_int($id)||$id!==0)throw new RuntimeException('caption_vector_reject_accepted');
        foreach($parts as$i=>$part){
            if(!is_array($part)||($part['slot']??null)!==$i)throw new RuntimeException('caption_vector_reject_parts');
            foreach(['message_id','update_id','published_at']as$f)if(!array_key_exists($f,$part)||$part[$f]!==null)throw new RuntimeException('caption_vector_reject_accepted');
            foreach(['api_confirmed','channel_confirmed']as$f)if(array_key_exists($f,$part)&&$part[$f]!==false)throw new RuntimeException('caption_vector_reject_accepted');
        }
        foreach($decode($attempt['continuation_json']??null)as$part){if(!is_array($part)||array_diff(array_keys($part),['text','extra'])!==[]||!is_string($part['text']??null)||!is_array($part['extra']??null))throw new RuntimeException('caption_vector_reject_continuation');}
        $snapshot=self::load($pdo,$post,'a',true);if($snapshot===null||count($snapshot['parts'])>count($parts))throw new RuntimeException('caption_vector_reject_snapshot');
        $delete=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');
        foreach(array_merge(range(0,count($snapshot['parts'])-1),[null])as$i){$delete->execute([self::key('a',$postId,$i)]);if($delete->rowCount()!==1)throw new RuntimeException('caption_vector_reject_changed');}
    }
    public static function behavior(array $post):array
    {
        $v=$post['_telegram_caption_vector']??null;if($v===null)return[];$v=self::normalize($v,$post);
        return['version'=>1,'media_hash'=>$v['media_hash'],'secondary'=>array_slice($v['parts'],1)];
    }
    public static function project(array $post,int $index,array $entry):array
    {
        $v=$post['_telegram_caption_vector']??null;if($v===null||$index===0)return$entry;$v=self::normalize($v,$post);$p=$v['parts'][$index]??null;if($p===null)throw new InvalidArgumentException('caption_vector_index');
        if(mb_strlen($p['text'],'UTF-8')>1024)throw new InvalidArgumentException('Описание одного файла альбома превышает 1024 символа.');
        unset($entry['caption'],$entry['caption_entities']);if($p['text']!==''){$entry['caption']=$p['text'];if($p['entities']!==[])$entry['caption_entities']=$p['entities'];}return$entry;
    }
    public static function cloneLocked(PDO $pdo,array $source,array $target,string $sourceKind='p',string $targetKind='p'):void
    {
        if(!$pdo->inTransaction())throw new LogicException('caption_vector_requires_transaction');
        if(!empty($source['repost_chat_id'])&&!empty($source['repost_message_id']))return;
        if($sourceKind==='p'){$source=self::freeze($pdo,$source);$value=$source['_telegram_caption_vector'];}else$value=self::load($pdo,$source,$sourceKind);
        if($value===null)return;
        if(!array_key_exists('media_json',$target)){$table=$targetKind==='t'?'posted_templates':'posted_posts';$s=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$target['id']]);$row=$s->fetch();if(!is_array($row))throw new RuntimeException('caption_vector_clone_missing');$target=$row;
            if($targetKind==='t')$target=PostedTelegramTemplateMedia::hydrate($pdo,$target);}
        if(self::raw($pdo,self::key($targetKind,(int)$target['id']),true)!==null)throw new RuntimeException('caption_vector_clone_exists');
        self::saveLocked($pdo,$target,self::rebind($value,$target,$targetKind),$targetKind);
    }
    public static function scopeSnapshot(PDO $pdo,array $posts,array $templates):array
    {
        $result=['posts'=>[],'templates'=>[]];foreach(['posts'=>[$posts,'p'],'templates'=>[$templates,'t']]as$key=>[$rows,$kind])foreach($rows as$row){if($kind==='t')$row=PostedTelegramTemplateMedia::hydrate($pdo,$row);$v=self::load($pdo,$row,$kind);if($v!==null)$result[$key][(int)$row['id']]=$v;}
        ksort($result['posts'],SORT_NUMERIC);ksort($result['templates'],SORT_NUMERIC);return$result;
    }
}
