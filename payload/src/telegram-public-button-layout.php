<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Explicit public layout. Stored records are binding evidence, never an ACL grant. */
final class PostedTelegramPublicButtonLayout
{
    public const VERSION=1;
    public const LAYOUT='hidden_reaction_url_v1';
    private const FIELD='_telegram_public_button_layout';
    public static function canonical(mixed$value):mixed
    {if(!is_array($value))return$value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$part)$value[$key]=self::canonical($part);return$value;}
    public static function json(mixed$value):string
    {return json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function hash(mixed$value):string{return hash('sha256',self::json($value));}
    public static function digest(mixed$value):bool{return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;}
    private static function identity(int$id):void{if($id<1)throw new InvalidArgumentException('public_button_identity');}
    public static function attemptKey(int$post):string{self::identity($post);return'tg_button_order_a_'.$post;}
    public static function receiptKey(int$post):string{self::identity($post);return'tg_button_order_p_'.$post;}
    public static function editKey(int$post):string{self::identity($post);return'tg_button_order_e_'.$post;}
    public static function operationKey(int$post,string$operation):string
    {self::identity($post);if(preg_match('/\A[a-f0-9]{32}\z/D',$operation)!==1)throw new InvalidArgumentException('public_button_operation_identity');return'tg_button_edit_'.substr(hash('sha256',$post.':'.$operation),0,48);}
    public static function chunkKey(int$post,string$operation,int$index):string
    {self::identity($post);if(preg_match('/\A[a-f0-9]{32}\z/D',$operation)!==1||$index<0||$index>31)throw new InvalidArgumentException('public_button_chunk_identity');return'tg_button_chunk_'.substr(hash('sha256',$post.':'.$operation),0,40).'_'.$index;}
    public static function rawRows(array$post):array
    {$value=$post['buttons_json']??[];if(is_string($value))$value=$value===''?[]:json_decode($value,true,32,JSON_THROW_ON_ERROR);if($value===null)$value=[];if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('public_button_rows');return$value;}
    public static function family(array$button):string
    {if(isset($button['url']))return'url';$callback=(string)($button['callback_data']??'');return str_starts_with($callback,'hidden:')?'hidden':(str_starts_with($callback,'reaction:')?'reaction':'other');}
    public static function assertNotComponents(array$post):void
    {if(($post['_telegram_text_components']??null)!==null)throw new RuntimeException('public_button_logical_components_unsupported');}
    public static function hasReactions(array$rows):bool
    {foreach($rows as$row)foreach((array)$row as$b)if(is_array($b)&&self::family($b)==='reaction')return true;return false;}
    /** Explicit context only. Version0 preserves every legacy request byte ordering. */
    public static function rows(array$rows,int$version):array
    {
        if($version===0)return$rows;if($version!==self::VERSION)throw new InvalidArgumentException('public_button_layout_version');$ranked=[];
        foreach($rows as$row){if(!is_array($row))throw new InvalidArgumentException('public_button_row');$rank=3;foreach($row as$button){if(!is_array($button))throw new InvalidArgumentException('public_button_value');$rank=min($rank,match(self::family($button)){'hidden'=>0,'reaction'=>1,'url'=>2,default=>3});}if($row!==[])$ranked[]=[$rank,count($ranked),$row];}
        usort($ranked,static fn(array$a,array$b):int=>($a[0]<=>$b[0])?:($a[1]<=>$b[1]));return array_column($ranked,2);
    }
    private static function attemptShape(array$value,array$post):array
    {
        $keys=array_keys($value);sort($keys);if($keys!==['buttons_hash','channel_id','layout','post_id','submission_key','version']||($value['version']??null)!==self::VERSION||($value['layout']??null)!==self::LAYOUT||($value['post_id']??null)!==(int)($post['id']??0)||$value['post_id']<1||($value['channel_id']??null)!==(int)($post['channel_id']??0)||$value['channel_id']<1||!self::digest($value['submission_key']??null)||!self::digest($value['buttons_hash']??null))throw new InvalidArgumentException('public_button_attempt_binding');return$value;
    }
    public static function attempt(array$value,array$post):array
    {self::attemptShape($value,$post);if(!hash_equals(self::hash(self::rawRows($post)),$value['buttons_hash']))throw new InvalidArgumentException('public_button_attempt_binding');return$value;}
    public static function knownIds(array$ids):array
    {if(!array_is_list($ids)||$ids===[]||count($ids)>40)throw new InvalidArgumentException('public_button_known_ids');foreach($ids as$id)if(!is_int($id)||$id<1||$id>2147483647)throw new InvalidArgumentException('public_button_known_ids');if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('public_button_known_ids');return$ids;}
    public static function activeEditState(string$state):bool
    {if(!in_array($state,['prepared','running','unknown','rejected','completed','retired_known_deletion'],true))throw new InvalidArgumentException('public_button_edit_state');return in_array($state,['prepared','running','unknown'],true);}
    public static function reactionCounts(array$rows,array$counts,int$version):array
    {
        if(!in_array($version,[0,self::VERSION],true))throw new InvalidArgumentException('public_button_layout_version');
        foreach($counts as$key=>$count)if(!is_string($key)||!is_int($count)||$count<0)throw new InvalidArgumentException('public_button_reaction_count');
        foreach($rows as$ri=>$row)foreach((array)$row as$bi=>$button){
            if(!is_array($button)||!isset($button['reaction_key']))continue;
            $key=(string)$button['reaction_key'];$label=(string)($button['reaction_label']??$button['text']??'');$count=$counts[$key]??0;
            $rows[$ri][$bi]['text']=$label.(($version===0||$count>0)?' '.$count:'');
        }return self::rows($rows,$version);
    }
    /** Outer channel/post locks and all authorization are the caller's responsibility. */
    public static function read(PDO$pdo,string$key,bool$lock=false):?array
    {
        if(strlen($key)>64||preg_match('/\Atg_button_(?:order_[ape]_[1-9][0-9]{0,18}|edit_[a-f0-9]{48}|chunk_[a-f0-9]{40}_[0-9]{1,2})\z/D',$key)!==1)throw new InvalidArgumentException('public_button_storage_key');
        if($lock&&!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$key]);$raw=$q->fetchColumn();
        if($raw===false)return null;if(!is_string($raw)||strlen($raw)>49152)throw new RuntimeException('public_button_storage_corrupt');
        $r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($r))throw new RuntimeException('public_button_storage_corrupt');return$r;
    }
    public static function putLocked(PDO$pdo,string$key,array$value,bool$insertOnly=false):void
    {
        if(!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');$old=self::read($pdo,$key,true);$raw=self::json($value);if(strlen($raw)>49152)throw new InvalidArgumentException('public_button_storage_size');
        if($insertOnly&&$old!==null)throw new RuntimeException('public_button_record_exists');
        $sql='INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)'.($insertOnly?'':' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$pdo->prepare($sql)->execute([$key,$raw]);
    }
    private static function ordinary(array$post):bool
    {
        // Logical multi-component posts need their own per-component callback
        // registry. They must never be interpreted as the first legacy body.
        if(($post['_telegram_text_components']??null)!==null||($post['_telegram_native_content']??null)!==null)return false;
        foreach(['external_chat_id','external_message_id','repost_chat_id','repost_message_id','repost_input_chat_id','repost_input_message_id']as$key)if(!empty($post[$key]))return false;
        $options=$post['_telegram_post_options']??null;
        if(!is_array($options)||($options['version']??null)!==2||($options['post_id']??null)!==(int)($post['id']??0)||($options['channel_id']??null)!==(int)($post['channel_id']??0)||!array_key_exists('paid_media_stars',$options)||$options['paid_media_stars']!==null)return false;
        if(class_exists('PostedTelegramMultipostFork',false)&&PostedTelegramMultipostFork::marked($post))return false;
        if(class_exists('PostedTelegramMultipost',false)&&PostedTelegramMultipost::forwardRootPostId($post)!==null)return false;
        foreach(['media_type','original_media_type']as$key)if(isset($post[$key])&&!in_array($post[$key],['none','photo','video','animation','document','audio','voice'],true))return false;
        $media=$post['media_json']??[];if(is_string($media))$media=$media===''?[]:json_decode($media,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($media)||!array_is_list($media)||count($media)>1)return false;
        self::rawRows($post);return true;
    }
    private static function choice(array$post,int$mode):array
    {
        $pid=(int)($post['id']??0);$cid=(int)($post['channel_id']??0);self::identity($pid);self::identity($cid);
        if(!in_array($mode,[0,self::VERSION],true))throw new InvalidArgumentException('public_button_layout_version');
        return['version'=>1,'post_id'=>$pid,'channel_id'=>$cid,'mode'=>$mode,'context'=>'attempt'];
    }
    public static function mode(array$post):int
    {
        self::assertNotComponents($post);
        if(!array_key_exists(self::FIELD,$post))return 0;$choice=$post[self::FIELD];
        if(!is_array($choice)||$choice!==self::choice($post,(int)($choice['mode']??-1)))throw new InvalidArgumentException('public_button_local_binding');return$choice['mode'];
    }
    private static function boundAttempt(array$value,array$post,array$attempt):array
    {
        self::attempt($value,$post);
        if((int)($attempt['post_id']??0)!==(int)$post['id']||(int)($attempt['channel_id']??0)!==(int)$post['channel_id'])throw new RuntimeException('public_button_attempt_changed');
        $closed=($attempt['state']??null)==='completed'&&($attempt['submission_key']??null)===null&&($attempt['gate_channel_id']??null)===null&&in_array($post['status']??null,['sent','deleted'],true);
        if(!$closed&&(!is_string($attempt['submission_key']??null)||!hash_equals($value['submission_key'],$attempt['submission_key'])))throw new RuntimeException('public_button_attempt_changed');return$value;
    }
    private static function eligibleFreshPhoto(array$post,?array$payload):bool
    {
        if(!self::ordinary($post)||($post['media_type']??null)!=='photo'||!in_array($post['original_media_type']??'none',['none','photo'],true)||$payload===null||!is_string($payload['text']??null)||!mb_check_encoding($payload['text'],'UTF-8')||mb_strlen($payload['text'],'UTF-8')>1024)return false;
        $file=$post['media_file_id']??null;if(!is_string($file)||preg_match('/\A[A-Za-z0-9_=-]{1,1024}\z/D',$file)!==1)return false;
        $media=$post['media_json']??[];if(is_string($media))$media=$media===''?[]:json_decode($media,true,32,JSON_THROW_ON_ERROR);if($media===[])$media=[['type'=>'photo','file_id'=>$file]];
        return is_array($media)&&array_is_list($media)&&count($media)===1&&($media[0]['type']??null)==='photo'&&($media[0]['file_id']??null)===$file;
    }
    public static function freeze(PDO$pdo,array$post,?array$effectivePayload=null):array
    {
        self::assertNotComponents($post);
        if(!array_key_exists('_telegram_post_options',$post))$post=PostedTelegramPostOptions::freeze($pdo,$post);
        if(array_key_exists(self::FIELD,$post)){self::mode($post);return$post;}
        $q=$pdo->prepare('SELECT post_id,channel_id,state,submission_key,gate_channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$q->execute([(int)$post['id']]);$attempt=$q->fetch();$marker=self::read($pdo,self::attemptKey((int)$post['id']));
        if(is_array($attempt)){
            if($marker===null)$mode=0; // Missing legacy attempt marker is never upgraded.
            else{
                $saved=self::read($pdo,self::receiptKey((int)$post['id']));
                if(($post['status']??null)==='sent'&&($attempt['state']??null)==='completed'&&is_array($saved)&&($saved['origin']['kind']??null)==='save'){
                    self::attemptShape($marker,$post);
                    $q=$pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$channel=$q->fetch();$q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$q->execute([(int)$post['id']]);$full=$q->fetch();if(!is_array($channel)||!is_array($full))throw new RuntimeException('public_button_saved_delivery_missing');
                    $saved=self::receipt($saved,$post,$channel);$known=self::knownDelivery($post,$channel,$full);if(!hash_equals($saved['delivery_hash'],$known['delivery_hash']))throw new RuntimeException('public_button_saved_delivery_changed');$mode=self::VERSION;
                }else$mode=self::boundAttempt($marker,$post,$attempt)['version'];
            }
        }
        else{if($marker!==null)throw new RuntimeException('public_button_orphan_attempt');$mode=self::eligibleFreshPhoto($post,$effectivePayload)&&self::hasReactions(self::rawRows($post))?self::VERSION:0;}
        $post[self::FIELD]=self::choice($post,$mode);return$post;
    }
    public static function behavior(array$post):array
    {return self::mode($post)===self::VERSION?['version'=>self::VERSION,'layout'=>self::LAYOUT]:[];}
    public static function snapshotLocked(PDO$pdo,array$post,string$key,bool$freshInserted=false):void
    {
        if(!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');if(!self::digest($key))throw new InvalidArgumentException('public_button_submission_key');
        if(self::mode($post)===0)return;
        if(!self::ordinary($post))throw new RuntimeException('public_button_unsupported_post');
        $q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$attempt=$q->fetch();
        if(!is_array($attempt)||$attempt['state']!=='sending'||(int)$attempt['channel_id']!==(int)$post['channel_id']||(int)$attempt['gate_channel_id']!==(int)$post['channel_id']||!is_string($attempt['submission_key'])||!hash_equals($key,$attempt['submission_key']))throw new RuntimeException('public_button_reservation_changed');
        $value=['version'=>self::VERSION,'layout'=>self::LAYOUT,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'submission_key'=>$key,'buttons_hash'=>self::hash(self::rawRows($post))];
        $old=self::read($pdo,self::attemptKey((int)$post['id']),true);if($old!==null){if(!hash_equals(self::hash(self::boundAttempt($old,$post,$attempt)),self::hash($value)))throw new RuntimeException('public_button_snapshot_changed');return;}
        if(!$freshInserted)throw new RuntimeException('public_button_fresh_reservation_required');
        self::clearPrepublicationGhostVotesLocked($pdo,$post,$attempt);self::putLocked($pdo,self::attemptKey((int)$post['id']),$value,true);
    }
    /** Only immediately after this transaction's successful unique INSERT.
     * No accepted/public MID or previously recorded attempt may enter this path.
     */
    private static function clearPrepublicationGhostVotesLocked(PDO$pdo,array$post,array$attempt):void
    {
        if(!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$actual=$q->fetch();
        if(!is_array($actual)||$actual['status']!=='publishing'||(int)$actual['channel_id']!==(int)$post['channel_id']||$actual['media_type']!=='photo'||(int)($actual['telegram_message_id']??0)!==0)throw new RuntimeException('public_button_ghost_publication_exists');
        $decode=static function(mixed$v,bool$nullable=false):array{if($v===null&&$nullable)return[];if(!is_string($v)||strlen($v)>1048576)throw new RuntimeException('public_button_ghost_unknown_proof');$r=json_decode($v,true,64,JSON_THROW_ON_ERROR);if(!is_array($r)||!array_is_list($r))throw new RuntimeException('public_button_ghost_unknown_proof');return$r;};
        foreach($decode($actual['telegram_message_ids']??null,true)as$id)if($id!==0)throw new RuntimeException('public_button_ghost_publication_exists');
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$field)if(($attempt[$field]??null)!==null)throw new RuntimeException('public_button_ghost_delivery_started');
        if((int)($attempt['pin_attempts']??0)!==0||(int)($attempt['pin_delivery_uncertain']??0)!==0)throw new RuntimeException('public_button_ghost_delivery_started');
        $slots=$decode($attempt['message_slots_json']??null);$parts=$decode($attempt['pending_parts_json']??null);if($slots!==[0]||count($parts)!==1||$decode($attempt['continuation_json']??null)!==[])throw new RuntimeException('public_button_ghost_unknown_proof');
        $part=$parts[0];if(!is_array($part)||($part['slot']??null)!==0)throw new RuntimeException('public_button_ghost_unknown_proof');
        foreach(['message_id','update_id','published_at']as$field)if(!array_key_exists($field,$part)||$part[$field]!==null)throw new RuntimeException('public_button_ghost_delivery_started');
        foreach(['api_confirmed','channel_confirmed']as$field)if(array_key_exists($field,$part)&&$part[$field]!==false)throw new RuntimeException('public_button_ghost_delivery_started');
        if(($part['native_observed_message_id']??null)!==null)throw new RuntimeException('public_button_ghost_delivery_started');
        $pdo->prepare('DELETE FROM posted_reactions WHERE post_id=?')->execute([(int)$post['id']]);
    }
    /** Full known delivery only; completion status alone is never sufficient. */
    public static function knownDelivery(array$post,array$channel,array$attempt):array
    {
        $pid=(int)($post['id']??0);$cid=(int)($post['channel_id']??0);self::identity($pid);self::identity($cid);$chat=(string)($channel['chat_id']??'');
        if((int)($channel['id']??0)!==$cid||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$chat)!==1||($post['status']??null)!=='sent'||!self::ordinary($post))throw new RuntimeException('public_button_delivery_unavailable');
        if((int)($attempt['post_id']??0)!==$pid||(int)($attempt['channel_id']??0)!==$cid||(string)($attempt['chat_id']??'')!==$chat||($attempt['state']??null)!=='completed'||($attempt['gate_channel_id']??null)!==null||($attempt['submission_key']??null)!==null||empty($attempt['completed_at'])||empty($attempt['finalized_at'])||(!empty($attempt['pin_started_at'])&&empty($attempt['pin_completed_at']))||!empty($attempt['pin_delivery_uncertain']))throw new RuntimeException('public_button_delivery_unresolved');
        $decode=static function(mixed$value):array{if(is_string($value))$value=json_decode($value,true,64,JSON_THROW_ON_ERROR);if(!is_array($value)||!array_is_list($value))throw new RuntimeException('public_button_delivery_vector');return$value;};
        $ids=self::knownIds($decode($post['telegram_message_ids']??null));$slots=$decode($attempt['message_slots_json']??null);$parts=$decode($attempt['pending_parts_json']??null);$continuation=$decode($attempt['continuation_json']??null);
        if((int)($post['telegram_message_id']??0)!==$ids[0]||$ids!==$slots||count($parts)!==count($ids)||$continuation!==[])throw new RuntimeException('public_button_delivery_partial');
        foreach($parts as$i=>$part){
            if(!is_array($part)||($part['slot']??null)!==$i||($part['message_id']??null)!==$ids[$i])throw new RuntimeException('public_button_delivery_part');
            $known=($part['api_confirmed']??null)===true||(($part['channel_confirmed']??null)===true&&is_int($part['update_id']??null)&&$part['update_id']>0&&is_int($part['published_at']??null)&&$part['published_at']>0);
            if(!$known)throw new RuntimeException('public_button_delivery_unknown');
        }
        return['post_id'=>$pid,'channel_id'=>$cid,'chat_id'=>$chat,'known_ids'=>$ids,'target_mid'=>$ids[count($ids)-1],'delivery_hash'=>self::hash(array_intersect_key($attempt,array_flip(['post_id','channel_id','chat_id','state','message_slots_json','pending_parts_json','continuation_json','completed_at','finalized_at'])))];
    }
    public static function receipt(array$value,array$post,array$channel):array
    {
        self::assertNotComponents($post);
        $keys=array_keys($value);sort($keys);if($keys!==['buttons_hash','channel_id','chat_id','delivery_hash','known_ids','layout','origin','post_id','target_mid','version']||($value['version']??null)!==self::VERSION||($value['layout']??null)!==self::LAYOUT||($value['post_id']??null)!==(int)$post['id']||($value['channel_id']??null)!==(int)$post['channel_id']||($value['channel_id']??null)!==(int)$channel['id']||($value['chat_id']??null)!==(string)$channel['chat_id']||!self::digest($value['buttons_hash']??null)||!hash_equals($value['buttons_hash'],self::hash(self::rawRows($post)))||!self::digest($value['delivery_hash']??null))throw new RuntimeException('public_button_receipt_changed');
        $ids=self::knownIds($value['known_ids']??[]);$current=$post['telegram_message_ids']??null;if(is_string($current))$current=json_decode($current,true,32,JSON_THROW_ON_ERROR);
        if(($post['status']??null)!=='sent'||$current!==$ids||($value['target_mid']??null)!==$ids[count($ids)-1]||(int)($post['telegram_message_id']??0)!==$ids[0]||!is_array($value['origin']??null))throw new RuntimeException('public_button_receipt_changed');
        $origin=$value['origin'];$keys=array_keys($origin);sort($keys);if($keys!==['hash','kind']||!in_array($origin['kind']??null,['attempt','save'],true)||!self::digest($origin['hash']??null))throw new RuntimeException('public_button_receipt_origin');return$value;
    }
    public static function receiptFromKnown(array$post,array$channel,array$attempt,array$origin):array
    {
        $r=self::knownDelivery($post,$channel,$attempt)+['version'=>self::VERSION,'layout'=>self::LAYOUT,'buttons_hash'=>self::hash(self::rawRows($post)),'origin'=>$origin];return self::receipt($r,$post,$channel);
    }
    /** Lazy mint after full completion; does not change delivery or attempt rows. */
    public static function receiptLocked(PDO$pdo,array$post,array$channel):?array
    {
        if(!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');
        $old=self::read($pdo,self::receiptKey((int)$post['id']),true);if($old!==null)return self::receipt($old,$post,$channel);
        $marker=self::read($pdo,self::attemptKey((int)$post['id']),true);if($marker===null)return null;
        $q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['id']]);$attempt=$q->fetch();if(!is_array($attempt))throw new RuntimeException('public_button_known_attempt_missing');
        self::boundAttempt($marker,$post,$attempt);$r=self::receiptFromKnown($post,$channel,$attempt,['kind'=>'attempt','hash'=>$marker['submission_key']]);self::putLocked($pdo,self::receiptKey((int)$post['id']),$r,true);return$r;
    }
    /** Only the two existing definite-zero-rejection transitions may call this. */
    public static function retireKnownRejectedAttemptLocked(PDO$pdo,int$postId):void
    {
        if(!$pdo->inTransaction())throw new LogicException('public_button_transaction_required');$marker=self::read($pdo,self::attemptKey($postId),true);if($marker===null)return;
        $q=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$post=$q->fetch();$q=$pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$attempt=$q->fetch();
        if(!is_array($post)||!is_array($attempt)||$post['status']!=='publishing'||$attempt['state']!=='sending'||(int)$attempt['gate_channel_id']!==(int)$post['channel_id'])throw new RuntimeException('public_button_reject_binding');
        self::boundAttempt($marker,$post,$attempt);
        foreach(['accepted_at','deadline_at','completed_at','finalized_at','pin_started_at','pin_completed_at']as$key)if(($attempt[$key]??null)!==null)throw new RuntimeException('public_button_reject_accepted');
        if((int)($post['telegram_message_id']??0)!==0||(int)($attempt['pin_attempts']??0)!==0||(int)($attempt['pin_delivery_uncertain']??0)!==0)throw new RuntimeException('public_button_reject_accepted');
        $decode=static function(mixed$raw,bool$nullable=false):array{if($raw===null&&$nullable)return[];if(!is_string($raw)||strlen($raw)>1048576)throw new RuntimeException('public_button_reject_parts');$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||!array_is_list($v))throw new RuntimeException('public_button_reject_parts');return$v;};
        foreach($decode($post['telegram_message_ids']??null,true)as$mid)if($mid!==0)throw new RuntimeException('public_button_reject_accepted');
        $slots=$decode($attempt['message_slots_json']);$parts=$decode($attempt['pending_parts_json']);if($slots===[]||count($slots)!==count($parts)||count($parts)>1000)throw new RuntimeException('public_button_reject_parts');
        foreach($slots as$mid)if($mid!==0)throw new RuntimeException('public_button_reject_accepted');
        foreach($parts as$i=>$part){if(!is_array($part)||($part['slot']??null)!==$i)throw new RuntimeException('public_button_reject_parts');foreach(['message_id','update_id','published_at']as$key)if(!array_key_exists($key,$part)||$part[$key]!==null)throw new RuntimeException('public_button_reject_accepted');foreach(['api_confirmed','channel_confirmed']as$key)if(array_key_exists($key,$part)&&$part[$key]!==false)throw new RuntimeException('public_button_reject_accepted');}
        foreach($decode($attempt['continuation_json'])as$part)if(!is_array($part)||array_diff(array_keys($part),['text','extra'])!==[]||!is_string($part['text']??null)||!is_array($part['extra']??null))throw new RuntimeException('public_button_reject_parts');
        $q=$pdo->prepare('SELECT chat_id FROM posted_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$chat=$q->fetchColumn();if(!is_string($chat)||$chat===''||(string)$attempt['chat_id']!==$chat)throw new RuntimeException('public_button_reject_binding');
        $q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');$q->execute([self::attemptKey($postId)]);if($q->rowCount()!==1)throw new RuntimeException('public_button_reject_changed');
    }
}
