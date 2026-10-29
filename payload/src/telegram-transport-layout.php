<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Request character caps are separate from the UTF-16 coordinate system of entities. */
final class PostedTelegramTransportLayout
{
    public const LEGACY='legacy_utf16_v1';
    public const SCALAR='unicode_scalar_v1';
    private const FIELD='_telegram_text_layout';
    public static function characters(string $text):int
    {if(!mb_check_encoding($text,'UTF-8'))throw new InvalidArgumentException('transport_layout_utf8');return mb_strlen($text,'UTF-8');}
    public static function key(int $id):string
    {if($id<1)throw new InvalidArgumentException('transport_layout_identity');return 'tg_text_layout_a_'.$id;}
    private static function ordinary(array $post):bool
    {
        if(!empty($post['repost_chat_id'])&&!empty($post['repost_message_id']))return false;
        if(($post['_telegram_post_options']['paid_media_stars']??null)!==null)return false;
        if(class_exists('PostedTelegramMultipostFork',false)&&PostedTelegramMultipostFork::marked($post))return false;
        if(class_exists('PostedTelegramMultipost',false)&&PostedTelegramMultipost::forwardRootPostId($post)!==null)return false;
        return true;
    }
    private static function choice(array $post,string $mode,string $context):array
    {if(!in_array($mode,[self::LEGACY,self::SCALAR],true)||!in_array($context,['preview','attempt'],true))throw new InvalidArgumentException('transport_layout_mode');return ['version'=>1,'post_id'=>(int)($post['id']??0),'channel_id'=>(int)($post['channel_id']??0),'mode'=>$mode,'context'=>$context];}
    private static function normalizeChoice(array $value,array $post):array
    {$keys=array_keys($value);sort($keys);if($keys!==['channel_id','context','mode','post_id','version']||($value['version']??null)!==1||$value!==self::choice($post,(string)($value['mode']??''),(string)($value['context']??'')))throw new InvalidArgumentException('transport_layout_local_binding');return$value;}
    public static function preview(array $post):array
    {$post[self::FIELD]=self::choice($post,self::ordinary($post)?self::SCALAR:self::LEGACY,'preview');return$post;}
    public static function scalar(array $post):bool
    {return isset($post[self::FIELD])&&self::normalizeChoice($post[self::FIELD],$post)['mode']===self::SCALAR;}
    public static function length(array $post,string $text):int
    {if(self::scalar($post))return self::characters($text);self::characters($text);return intdiv(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8')),2);}
    private static function raw(PDO $pdo,int $id,bool $lock=false):?array
    {$q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([self::key($id)]);$raw=$q->fetchColumn();if($raw===false)return null;if(!is_string($raw)||strlen($raw)>4096)throw new InvalidArgumentException('transport_layout_storage');$v=json_decode($raw,true,12,JSON_THROW_ON_ERROR);if(!is_array($v))throw new InvalidArgumentException('transport_layout_storage');return$v;}
    private static function marker(array $value,array $post,array $attempt):array
    {
        $keys=array_keys($value);sort($keys);
        if($keys!==['channel_id','mode','post_id','submission_key','version']||($value['version']??null)!==1||($value['post_id']??null)!==(int)$post['id']||($value['channel_id']??null)!==(int)$post['channel_id']
            ||!in_array($value['mode']??null,[self::LEGACY,self::SCALAR],true)||!is_string($value['submission_key']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['submission_key'])!==1
            ||(int)($attempt['channel_id']??0)!==(int)$post['channel_id'])throw new InvalidArgumentException('transport_layout_marker_binding');
        $completed=($attempt['submission_key']??null)===null&&($attempt['state']??null)==='completed'&&($attempt['gate_channel_id']??null)===null&&in_array($post['status']??null,['sent','deleted'],true);
        if(!$completed&&(!is_string($attempt['submission_key']??null)||!hash_equals($value['submission_key'],$attempt['submission_key'])))throw new InvalidArgumentException('transport_layout_attempt_changed');
        return$value;
    }
    public static function freeze(PDO $pdo,array $post):array
    {
        if(!array_key_exists('_telegram_post_options',$post))$post=PostedTelegramPostOptions::freeze($pdo,$post);
        if(isset($post[self::FIELD])){$v=self::normalizeChoice($post[self::FIELD],$post);if($v['context']==='attempt')return$post;}
        $q=$pdo->prepare('SELECT submission_key,state,gate_channel_id,channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$q->execute([(int)$post['id']]);$attempt=$q->fetch();$marker=self::raw($pdo,(int)$post['id']);
        if(is_array($attempt)){$mode=$marker===null?self::LEGACY:self::marker($marker,$post,$attempt)['mode'];}
        else{if($marker!==null)throw new InvalidArgumentException('transport_layout_orphan_marker');$mode=self::ordinary($post)?self::SCALAR:self::LEGACY;}
        $post[self::FIELD]=self::choice($post,$mode,'attempt');return$post;
    }
    public static function snapshotLocked(PDO $pdo,array $post,string $key):void
    {
        if(!$pdo->inTransaction()||preg_match('/\A[a-f0-9]{64}\z/D',$key)!==1)throw new LogicException('transport_layout_requires_transaction');
        $choice=self::normalizeChoice($post[self::FIELD]??[],$post);if($choice['context']!=='attempt')throw new LogicException('transport_layout_not_frozen');
        if(!self::ordinary($post))return;
        $q=$pdo->prepare('SELECT post_id,channel_id,state,submission_key,gate_channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$attempt=$q->fetch();
        if(!is_array($attempt)||$attempt['state']!=='sending'||(int)$attempt['channel_id']!==(int)$post['channel_id']||(int)$attempt['gate_channel_id']!==(int)$post['channel_id']||!is_string($attempt['submission_key'])||!hash_equals($key,$attempt['submission_key']))throw new RuntimeException('transport_layout_reservation_changed');
        $value=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'submission_key'=>$key,'mode'=>$choice['mode']];$old=self::raw($pdo,(int)$post['id'],true);
        if($old!==null){if(self::marker($old,$post,$attempt)!=$value)throw new RuntimeException('transport_layout_snapshot_changed');return;}
        $q=$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)');$q->execute([self::key((int)$post['id']),json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }
    public static function retireKnownRejectedAttemptLocked(PDO $pdo,int $id):void
    {
        if(!$pdo->inTransaction())throw new LogicException('transport_layout_requires_transaction');$marker=self::raw($pdo,$id,true);if($marker===null)return;
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$post=$q->fetch();$q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$attempt=$q->fetch();
        if(!is_array($post)||!is_array($attempt)||$post['status']!=='publishing'||$attempt['state']!=='sending'||(int)$attempt['gate_channel_id']!==(int)$post['channel_id'])throw new RuntimeException('transport_layout_reject_binding');self::marker($marker,$post,$attempt);
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$f)if(($attempt[$f]??null)!==null)throw new RuntimeException('transport_layout_reject_accepted');
        if((int)($post['telegram_message_id']??0)!==0||(int)($attempt['pin_attempts']??0)!==0||(int)($attempt['pin_delivery_uncertain']??0)!==0)throw new RuntimeException('transport_layout_reject_accepted');
        $decode=static function(mixed$raw,bool$nullable=false):array{if($raw===null&&$nullable)return[];if(!is_string($raw)||strlen($raw)>1048576)throw new RuntimeException('transport_layout_reject_parts');$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))throw new RuntimeException('transport_layout_reject_parts');return$v;};
        foreach($decode($post['telegram_message_ids']??null,true)as$mid)if($mid!==0)throw new RuntimeException('transport_layout_reject_accepted');
        $slots=$decode($attempt['message_slots_json']);$parts=$decode($attempt['pending_parts_json']);if($slots===[]||count($slots)!==count($parts)||count($parts)>1000)throw new RuntimeException('transport_layout_reject_parts');
        foreach($slots as$mid)if($mid!==0)throw new RuntimeException('transport_layout_reject_accepted');
        foreach($parts as$i=>$part){if(!is_array($part)||($part['slot']??null)!==$i)throw new RuntimeException('transport_layout_reject_parts');foreach(['message_id','update_id','published_at']as$k)if(!array_key_exists($k,$part)||$part[$k]!==null)throw new RuntimeException('transport_layout_reject_accepted');foreach(['api_confirmed','channel_confirmed']as$k)if(array_key_exists($k,$part)&&$part[$k]!==false)throw new RuntimeException('transport_layout_reject_accepted');}
        foreach($decode($attempt['continuation_json'])as$part)if(!is_array($part)||array_diff(array_keys($part),['text','extra'])!==[]||!is_string($part['text']??null)||!is_array($part['extra']??null))throw new RuntimeException('transport_layout_reject_parts');
        $q=$pdo->prepare('SELECT chat_id FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$chat=$q->fetchColumn();if(!is_string($chat)||$chat===''||(string)$attempt['chat_id']!==$chat)throw new RuntimeException('transport_layout_reject_binding');
        $q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');$q->execute([self::key($id)]);if($q->rowCount()!==1)throw new RuntimeException('transport_layout_reject_changed');
    }
}
