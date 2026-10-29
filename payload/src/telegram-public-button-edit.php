<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-public-button-layout.php';

/**
 * Durable exact-MID edit intent. This class performs SQL only, never authorization
 * or transport. Callers must hold channel -> actor -> post locks and the existing
 * channel pin advisory lock, and revalidate the original signed edit session.
 */
final class PostedTelegramPublicButtonEdit
{
    private static function keys(array$value,array$keys):void
    {$actual=array_keys($value);sort($actual);sort($keys);if($actual!==$keys)throw new RuntimeException('public_button_edit_shape');}
    private static function id(mixed$value):int
    {if(!is_int($value)||$value<1)throw new RuntimeException('public_button_edit_identity');return$value;}
    private static function nonce(mixed$value,int$bytes):bool
    {return is_string($value)&&preg_match('/\A[a-f0-9]{'.$bytes.'}\z/D',$value)===1;}
    private static function tx(PDO$pdo):void
    {if(!$pdo->inTransaction())throw new LogicException('public_button_edit_transaction_required');}
    private static function snapshot(array$value):void
    {
        self::keys($value,['text','text_entities_json','media_type','media_file_id','media_json','buttons_json','pin_after_publish','autosignature_text','autosignature_entities_json']);
        foreach(['text','autosignature_text']as$key)if($value[$key]!==null&&!is_string($value[$key]))throw new RuntimeException('public_button_edit_snapshot');
        foreach(['text_entities_json','media_json','buttons_json','autosignature_entities_json']as$key)if(!is_array($value[$key])||!array_is_list($value[$key]))throw new RuntimeException('public_button_edit_snapshot');
        if($value['media_type']!=='photo'||!is_string($value['media_file_id'])||preg_match('/\A[A-Za-z0-9_=-]{1,1024}\z/D',$value['media_file_id'])!==1||!in_array($value['pin_after_publish'],[0,1],true)||count($value['media_json'])!==1||($value['media_json'][0]['type']??null)!=='photo'||($value['media_json'][0]['file_id']??null)!==$value['media_file_id'])throw new RuntimeException('public_button_edit_snapshot');
    }
    /** Optional metadata is absent on every old immutable plan. */
    public static function placement(array$document):?array
    {
        if(!array_key_exists('caption_placement',$document))return null;$v=$document['caption_placement'];
        if(!is_array($v))throw new RuntimeException('public_button_placement_document');self::keys($v,['base','draft']);
        if(!in_array($v['base'],[null,true],true)||!in_array($v['draft'],[null,true],true)||($v['base']===null&&$v['draft']===null))throw new RuntimeException('public_button_placement_document');return$v;
    }
    public static function placementPayload(array$document,array$payload):array
    {
        $v=self::placement($document);if($v===null){if(array_key_exists('show_caption_above_media',$payload))throw new RuntimeException('public_button_unbound_placement');return$payload;}
        $payload['show_caption_above_media']=$v['draft']===true;return$payload;
    }
    public static function validatePlan(array$p):void
    {
        $planKeys=['version','operation_id','post_id','channel_id','chat_id','actor_id','owner_id','epoch','context_hash','session_hash','input','base','draft','delivery','steps'];$placement=self::placement($p);if($placement!==null)$planKeys[]='caption_placement';self::keys($p,$planKeys);
        if(($p['version']??null)!==1||!self::nonce($p['operation_id'],32)||!PostedTelegramPublicButtonLayout::digest($p['session_hash'])||!PostedTelegramPublicButtonLayout::digest($p['context_hash'])||!is_string($p['epoch'])||preg_match('/\A(?:legacy|[a-f0-9]{32})\z/D',$p['epoch'])!==1||!is_string($p['chat_id'])||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$p['chat_id'])!==1)throw new RuntimeException('public_button_edit_plan');
        foreach(['post_id','channel_id','actor_id','owner_id']as$key)self::id($p[$key]);
        if(!is_array($p['input'])||!is_array($p['base'])||!is_array($p['draft'])||!is_array($p['delivery'])||!is_array($p['steps'])||!array_is_list($p['steps'])||$p['steps']===[]||count($p['steps'])>2)throw new RuntimeException('public_button_edit_plan');
        self::keys($p['input'],['state','data']);if(!is_string($p['input']['state'])||!is_array($p['input']['data']))throw new RuntimeException('public_button_edit_input');
        self::snapshot($p['base']);self::snapshot($p['draft']);
        // This new route edits the existing photo caption/buttons/pin only.
        foreach(['media_type','media_file_id','media_json']as$key)if($p['base'][$key]!==$p['draft'][$key])throw new RuntimeException('public_button_edit_media_replacement');
        self::keys($p['delivery'],['post_id','channel_id','chat_id','known_ids','target_mid','delivery_hash']);
        $ids=PostedTelegramPublicButtonLayout::knownIds($p['delivery']['known_ids']??[]);
        if(count($ids)!==1||$p['delivery']['post_id']!==$p['post_id']||$p['delivery']['channel_id']!==$p['channel_id']||$p['delivery']['chat_id']!==$p['chat_id']||$p['delivery']['target_mid']!==$ids[0]||!PostedTelegramPublicButtonLayout::digest($p['delivery']['delivery_hash']??null))throw new RuntimeException('public_button_edit_delivery');
        $seen=[];foreach($p['steps']as$step){
            if(!is_array($step))throw new RuntimeException('public_button_edit_step');self::keys($step,['method','payload']);
            if(!in_array($step['method']??null,['editMessageCaption','pinChatMessage','unpinChatMessage'],true)||!is_array($step['payload']??null)||isset($seen[$step['method']]))throw new RuntimeException('public_button_edit_step');$seen[$step['method']]=true;
            $v=$step['payload'];$keys=match($step['method']){'editMessageCaption'=>['chat_id','message_id','caption','caption_entities','reply_markup'],'pinChatMessage'=>['chat_id','message_id','disable_notification'],default=>['chat_id','message_id']};if($step['method']==='editMessageCaption'&&$placement!==null)$keys[]='show_caption_above_media';self::keys($v,$keys);
            if($v['chat_id']!==$p['chat_id']||$v['message_id']!==$ids[0])throw new RuntimeException('public_button_edit_target');
            if($step['method']==='editMessageCaption'){
                if($placement!==null&&$v['show_caption_above_media']!==($placement['draft']===true))throw new RuntimeException('public_button_placement_payload');
                if(!is_string($v['caption'])||!is_array($v['caption_entities'])||!array_is_list($v['caption_entities'])||!is_array($v['reply_markup']))throw new RuntimeException('public_button_edit_caption');self::keys($v['reply_markup'],['inline_keyboard']);
                if(!is_array($v['reply_markup']['inline_keyboard'])||!array_is_list($v['reply_markup']['inline_keyboard']))throw new RuntimeException('public_button_edit_markup');
            }elseif($step['method']==='pinChatMessage'&&$v['disable_notification']!==true)throw new RuntimeException('public_button_edit_pin');
        }
        if(!isset($seen['editMessageCaption'])||isset($seen['pinChatMessage'],$seen['unpinChatMessage']))throw new RuntimeException('public_button_edit_pin');
        if(count($p['steps'])===2&&$p['steps'][0]['method']!=='editMessageCaption')throw new RuntimeException('public_button_edit_step_order');
    }
    public static function plan(int$actor,array$channel,string$epoch,string$contextHash,string$sessionHash,array$input,array$document,array$delivery,array$captionPayload):array
    {
        if(($document['version']??null)!==2||!is_array($document['base']??null)||!is_array($document['draft']??null))throw new RuntimeException('public_button_edit_session');
        $base=$document['base'];$draft=$document['draft'];$steps=[];
        // Always freeze the desired exact markup, including a deliberate clear.
        $steps[]=['method'=>'editMessageCaption','payload'=>$captionPayload];
        if($base['pin_after_publish']!==$draft['pin_after_publish'])$steps[]=['method'=>$draft['pin_after_publish']===1?'pinChatMessage':'unpinChatMessage','payload'=>['chat_id'=>$delivery['chat_id'],'message_id'=>$delivery['target_mid']]+($draft['pin_after_publish']===1?['disable_notification'=>true]:[])];
        $p=['version'=>1,'operation_id'=>bin2hex(random_bytes(16)),'post_id'=>$delivery['post_id'],'channel_id'=>$delivery['channel_id'],'chat_id'=>$delivery['chat_id'],'actor_id'=>$actor,'owner_id'=>(int)$channel['added_by'],'epoch'=>$epoch,'context_hash'=>$contextHash,'session_hash'=>$sessionHash,'input'=>$input,'base'=>$base,'draft'=>$draft,'delivery'=>$delivery,'steps'=>$steps];if(($placement=self::placement($document))!==null)$p['caption_placement']=$placement;self::validatePlan($p);return$p;
    }
    private static function validateHeader(array$h,int$post,string$operation):void
    {
        self::keys($h,['version','post_id','operation_id','plan_hash','plan_bytes','chunks','state','receipts','resume_nonce','deletion','issued_intents','late_observations']);
        if(($h['version']??null)!==1||$h['post_id']!==$post||$h['operation_id']!==$operation||!self::nonce($operation,32)||!PostedTelegramPublicButtonLayout::digest($h['plan_hash']??null)||!is_int($h['plan_bytes'])||$h['plan_bytes']<2||$h['plan_bytes']>1048576||!is_array($h['chunks'])||!array_is_list($h['chunks'])||$h['chunks']===[]||count($h['chunks'])>32||!is_array($h['receipts'])||!array_is_list($h['receipts'])||count($h['receipts'])>2)throw new RuntimeException('public_button_edit_header');
        PostedTelegramPublicButtonLayout::activeEditState((string)($h['state']??''));foreach($h['chunks']as$hash)if(!PostedTelegramPublicButtonLayout::digest($hash))throw new RuntimeException('public_button_edit_chunk_hash');
        $unknown=false;$done=0;$rejected=0;
        foreach($h['receipts']as$r){
            if(!is_array($r))throw new RuntimeException('public_button_edit_receipt');self::keys($r,['state','intent','finished_at','proof_hash','had_unknown','generation']);
            if(!in_array($r['state']??null,['pending','running','unknown','rejected','done'],true)||!is_bool($r['had_unknown'])||!is_int($r['generation'])||$r['generation']<0||$r['generation']>16)throw new RuntimeException('public_button_edit_receipt');
            if($r['state']==='pending'){if($r['intent']!==null||$r['finished_at']!==null||$r['proof_hash']!==null)throw new RuntimeException('public_button_edit_receipt');}
            else{
                if(!self::nonce($r['intent'],24)||($r['state']==='running'?$r['finished_at']!==null:(!is_int($r['finished_at'])||$r['finished_at']<1)))throw new RuntimeException('public_button_edit_receipt');
                if($r['state']==='done'){if(!PostedTelegramPublicButtonLayout::digest($r['proof_hash']))throw new RuntimeException('public_button_edit_receipt');$done++;}elseif($r['proof_hash']!==null)throw new RuntimeException('public_button_edit_receipt');
                if($r['state']==='rejected')$rejected++;
                if(in_array($r['state'],['running','unknown'],true)||$r['had_unknown'])$unknown=true;
            }
        }
        if($h['state']==='prepared'&&array_filter($h['receipts'],static fn(array$r):bool=>$r['state']!=='pending')!==[])throw new RuntimeException('public_button_edit_state');
        if($h['state']==='completed'&&$done!==count($h['receipts']))throw new RuntimeException('public_button_edit_state');
        if($h['state']==='rejected'&&($done>0||$unknown||$rejected!==1))throw new RuntimeException('public_button_edit_state');
        if($h['state']==='unknown'?!self::nonce($h['resume_nonce'],24):$h['resume_nonce']!==null)throw new RuntimeException('public_button_edit_resume');
        if(!is_array($h['issued_intents'])||!array_is_list($h['issued_intents'])||count($h['issued_intents'])>32)throw new RuntimeException('public_button_intent_history');
        $issued=[];$nonces=[];foreach($h['issued_intents']as$i){if(!is_array($i))throw new RuntimeException('public_button_intent_history');self::keys($i,['index','generation','intent','issued_at']);if(!is_int($i['index'])||$i['index']<0||$i['index']>=count($h['receipts'])||!is_int($i['generation'])||$i['generation']<1||$i['generation']>16||!self::nonce($i['intent'],24)||isset($nonces[$i['intent']])||isset($issued[$i['index']][$i['generation']])||!is_int($i['issued_at'])||$i['issued_at']<1)throw new RuntimeException('public_button_intent_history');$nonces[$i['intent']]=true;$issued[$i['index']][$i['generation']]=$i['intent'];}
        foreach($h['receipts']as$index=>$r){$history=$issued[$index]??[];if(array_keys($history)!==($r['generation']===0?[]:range(1,$r['generation']))||($r['state']!=='pending'&&($history[$r['generation']]??null)!==$r['intent']))throw new RuntimeException('public_button_intent_history');}
        if(!is_array($h['late_observations'])||!array_is_list($h['late_observations'])||count($h['late_observations'])>64)throw new RuntimeException('public_button_late_shape');
        foreach($h['late_observations']as$o){if(!is_array($o))throw new RuntimeException('public_button_late_shape');self::keys($o,['index','generation','intent','outcome','proof_hash','observed_at']);if(!is_int($o['index'])||$o['index']<0||!is_int($o['generation'])||$o['generation']<1||!self::nonce($o['intent'],24)||($issued[$o['index']][$o['generation']]??null)!==$o['intent']||!in_array($o['outcome'],['done','unknown','rejected'],true)||($o['outcome']==='done'?!PostedTelegramPublicButtonLayout::digest($o['proof_hash']):$o['proof_hash']!==null)||!is_int($o['observed_at'])||$o['observed_at']<1)throw new RuntimeException('public_button_late_shape');}
        if($h['deletion']!==null){self::validateDeletion($h['deletion'],$post);if($h['state']==='retired_known_deletion'&&$h['deletion']['state']!=='done')throw new RuntimeException('public_button_delete_terminal');if(!in_array($h['state'],['unknown','retired_known_deletion'],true))throw new RuntimeException('public_button_delete_terminal');}
        elseif($h['state']==='retired_known_deletion')throw new RuntimeException('public_button_delete_terminal');
    }
    public static function current(PDO$pdo,int$post,bool$lock=false):?array
    {
        $pointer=PostedTelegramPublicButtonLayout::read($pdo,PostedTelegramPublicButtonLayout::editKey($post),$lock);if($pointer===null)return null;
        self::keys($pointer,['version','post_id','operation_id','plan_hash']);if(($pointer['version']??null)!==1||$pointer['post_id']!==$post||!self::nonce($pointer['operation_id']??null,32)||!PostedTelegramPublicButtonLayout::digest($pointer['plan_hash']??null))throw new RuntimeException('public_button_edit_pointer');
        $op=$pointer['operation_id'];$h=PostedTelegramPublicButtonLayout::read($pdo,PostedTelegramPublicButtonLayout::operationKey($post,$op),$lock);if($h===null)throw new RuntimeException('public_button_edit_header_missing');self::validateHeader($h,$post,$op);
        if(!hash_equals($pointer['plan_hash'],$h['plan_hash']))throw new RuntimeException('public_button_edit_pointer_changed');$bytes='';
        foreach($h['chunks']as$i=>$hash){
            $c=PostedTelegramPublicButtonLayout::read($pdo,PostedTelegramPublicButtonLayout::chunkKey($post,$op,$i),$lock);if($c===null)throw new RuntimeException('public_button_edit_chunk_missing');self::keys($c,['version','post_id','operation_id','index','data']);
            if(($c['version']??null)!==1||$c['post_id']!==$post||$c['operation_id']!==$op||$c['index']!==$i||!is_string($c['data'])||strlen($c['data'])>43692)throw new RuntimeException('public_button_edit_chunk');
            $part=base64_decode($c['data'],true);if(!is_string($part)||strlen($part)>32768||base64_encode($part)!==$c['data']||!hash_equals($hash,hash('sha256',$part)))throw new RuntimeException('public_button_edit_chunk');$bytes.=$part;
        }
        if(strlen($bytes)!==$h['plan_bytes']||!hash_equals($h['plan_hash'],hash('sha256',$bytes)))throw new RuntimeException('public_button_edit_plan_hash');
        $p=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);if(!is_array($p))throw new RuntimeException('public_button_edit_plan');self::validatePlan($p);if(PostedTelegramPublicButtonLayout::json($p)!==$bytes||$p['post_id']!==$post||$p['operation_id']!==$op||count($p['steps'])!==count($h['receipts']))throw new RuntimeException('public_button_edit_plan_changed');
        if($h['deletion']!==null&&$h['deletion']['request']!==['chat_id'=>$p['chat_id'],'message_id'=>$p['delivery']['target_mid']])throw new RuntimeException('public_button_delete_plan_changed');return['header'=>$h,'plan'=>$p];
    }
    public static function assertIdleLocked(PDO$pdo,int$post):void
    {$r=self::current($pdo,$post,true);if($r!==null&&PostedTelegramPublicButtonLayout::activeEditState($r['header']['state']))throw new RuntimeException('public_button_edit_in_progress');}
    public static function assertLegacySaveLocked(PDO$pdo,int$post):void
    {self::assertIdleLocked($pdo,$post);if(PostedTelegramPublicButtonLayout::read($pdo,PostedTelegramPublicButtonLayout::attemptKey($post),true)!==null||PostedTelegramPublicButtonLayout::read($pdo,PostedTelegramPublicButtonLayout::receiptKey($post),true)!==null)throw new InvalidArgumentException('Откройте актуальный редактор этого поста. Старое сохранение не может изменить версионные кнопки; ваши изменения сохранены в редакторе.');}
    /** The channel advisory lock must exclude a still-running transport owner. */
    public static function holdLocked(PDO$pdo,int$post,string$operation):array
    {
        self::tx($pdo);$r=self::current($pdo,$post,true);if($r===null||$r['plan']['operation_id']!==$operation)throw new RuntimeException('public_button_edit_changed');
        if(!PostedTelegramPublicButtonLayout::activeEditState($r['header']['state']))return$r;
        foreach($r['header']['receipts']as$i=>$receipt)if($receipt['state']==='running'){$r['header']['receipts'][$i]['state']='unknown';$r['header']['receipts'][$i]['finished_at']=time();$r['header']['receipts'][$i]['had_unknown']=true;}
        $r['header']['state']='unknown';if($r['header']['resume_nonce']===null)$r['header']['resume_nonce']=bin2hex(random_bytes(12));self::storeHeader($pdo,$r);return$r;
    }
    public static function createLocked(PDO$pdo,array$p):array
    {
        self::tx($pdo);self::validatePlan($p);$post=$p['post_id'];self::assertIdleLocked($pdo,$post);$bytes=PostedTelegramPublicButtonLayout::json($p);if(strlen($bytes)>1048576)throw new RuntimeException('public_button_edit_size');$op=$p['operation_id'];$chunks=[];
        foreach(str_split($bytes,32768)as$i=>$part){$chunks[]=hash('sha256',$part);PostedTelegramPublicButtonLayout::putLocked($pdo,PostedTelegramPublicButtonLayout::chunkKey($post,$op,$i),['version'=>1,'post_id'=>$post,'operation_id'=>$op,'index'=>$i,'data'=>base64_encode($part)],true);}
        $h=['version'=>1,'post_id'=>$post,'operation_id'=>$op,'plan_hash'=>hash('sha256',$bytes),'plan_bytes'=>strlen($bytes),'chunks'=>$chunks,'state'=>'prepared','receipts'=>array_map(static fn(array$s):array=>['state'=>'pending','intent'=>null,'finished_at'=>null,'proof_hash'=>null,'had_unknown'=>false,'generation'=>0],$p['steps']),'resume_nonce'=>null,'deletion'=>null,'issued_intents'=>[],'late_observations'=>[]];
        self::validateHeader($h,$post,$op);PostedTelegramPublicButtonLayout::putLocked($pdo,PostedTelegramPublicButtonLayout::operationKey($post,$op),$h,true);PostedTelegramPublicButtonLayout::putLocked($pdo,PostedTelegramPublicButtonLayout::editKey($post),['version'=>1,'post_id'=>$post,'operation_id'=>$op,'plan_hash'=>$h['plan_hash']]);return['header'=>$h,'plan'=>$p];
    }
    private static function storeHeader(PDO$pdo,array$r):void
    {self::tx($pdo);self::validateHeader($r['header'],$r['plan']['post_id'],$r['plan']['operation_id']);PostedTelegramPublicButtonLayout::putLocked($pdo,PostedTelegramPublicButtonLayout::operationKey($r['plan']['post_id'],$r['plan']['operation_id']),$r['header']);}
    private static function validateDeletion(mixed$d,int$post):void
    {
        if(!is_array($d))throw new RuntimeException('public_button_delete_shape');self::keys($d,['version','post_id','kind','actor_id','request','delete_at','delete_after_minutes','created_at','intent','state','proof_hash']);
        if($d['version']!==1||$d['post_id']!==$post||!in_array($d['kind'],['manual','due'],true)||!is_int($d['actor_id'])||($d['kind']==='manual'?$d['actor_id']<1:$d['actor_id']!==0)||!is_array($d['request'])||!is_int($d['created_at'])||$d['created_at']<1||!self::nonce($d['intent'],24)||!in_array($d['state'],['running','unknown','done'],true)||($d['state']==='done'?!PostedTelegramPublicButtonLayout::digest($d['proof_hash']):$d['proof_hash']!==null))throw new RuntimeException('public_button_delete_shape');
        self::keys($d['request'],['chat_id','message_id']);if(!is_string($d['request']['chat_id'])||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$d['request']['chat_id'])!==1||!is_int($d['request']['message_id'])||$d['request']['message_id']<1||$d['request']['message_id']>2147483647||($d['delete_at']!==null&&!is_string($d['delete_at']))||($d['delete_after_minutes']!==null&&!is_int($d['delete_after_minutes'])))throw new RuntimeException('public_button_delete_shape');
    }
    /** An explicit delete intent overrides an edit without certifying its payload.
     * Caller retains the pin mutex; normal delete ACL/due checks already ran.
     */
    public static function beginDeletionLocked(PDO$pdo,array$post,array$channel,string$kind,int$actor):array
    {
        self::tx($pdo);$r=self::current($pdo,(int)$post['id'],true);if($r===null||!PostedTelegramPublicButtonLayout::activeEditState($r['header']['state']))throw new RuntimeException('public_button_delete_no_active_edit');
        if($r['header']['deletion']!==null)return$r;
        $ids=$post['telegram_message_ids']??null;if(is_string($ids))$ids=json_decode($ids,true,32,JSON_THROW_ON_ERROR);
        $p=$r['plan'];if(($post['status']??null)!=='sent'||(int)$post['channel_id']!==$p['channel_id']||(int)$channel['id']!==$p['channel_id']||(string)$channel['chat_id']!==$p['chat_id']||$ids!==$p['delivery']['known_ids']||count($ids)!==1||(int)$post['telegram_message_id']!==$ids[0])throw new RuntimeException('public_button_delete_target_changed');
        foreach($r['header']['receipts']as$i=>$receipt)if($receipt['state']==='running'){$r['header']['receipts'][$i]['state']='unknown';$r['header']['receipts'][$i]['finished_at']=time();$r['header']['receipts'][$i]['had_unknown']=true;}
        $r['header']['state']='unknown';$r['header']['resume_nonce']=bin2hex(random_bytes(12));
        $r['header']['deletion']=['version'=>1,'post_id'=>$p['post_id'],'kind'=>$kind,'actor_id'=>$actor,'request'=>['chat_id'=>$p['chat_id'],'message_id'=>$ids[0]],'delete_at'=>$post['delete_at']??null,'delete_after_minutes'=>isset($post['delete_after_minutes'])?(int)$post['delete_after_minutes']:null,'created_at'=>time(),'intent'=>bin2hex(random_bytes(12)),'state'=>'running','proof_hash'=>null];self::storeHeader($pdo,$r);return$r;
    }
    /** Store deletion outcome independently of a later role/owner change.
     * Known terminal deletion and post status/session cleanup share this Tx.
     */
    public static function settleDeletionLocked(PDO$pdo,int$post,string$op,string$intent,?string$knownProof):array
    {
        self::tx($pdo);$r=self::current($pdo,$post,true);$d=$r['header']['deletion']??null;
        if($r===null||$r['plan']['operation_id']!==$op||!is_array($d)||!hash_equals($d['intent'],$intent)||$d['state']!=='running')throw new RuntimeException('public_button_delete_intent_changed');
        if($knownProof!==null&&!PostedTelegramPublicButtonLayout::digest($knownProof))throw new RuntimeException('public_button_delete_proof');
        $r['header']['deletion']['state']=$knownProof===null?'unknown':'done';$r['header']['deletion']['proof_hash']=$knownProof;
        self::storeHeader($pdo,$r);return$r;
    }
    public static function completeDeletionLocked(PDO$pdo,int$post,string$op):array
    {
        self::tx($pdo);$r=self::current($pdo,$post,true);if($r===null||$r['plan']['operation_id']!==$op||($r['header']['deletion']['state']??null)!=='done')throw new RuntimeException('public_button_delete_not_known');
        $r['header']['state']='retired_known_deletion';$r['header']['resume_nonce']=null;self::storeHeader($pdo,$r);return$r;
    }
    /** A sending crash becomes unknown. Only a fresh explicit nonce may retry it. */
    public static function nextLocked(PDO$pdo,int$post,?string$explicitResume=null):array
    {
        self::tx($pdo);$r=self::current($pdo,$post,true);if($r===null)throw new RuntimeException('public_button_edit_missing');$h=&$r['header'];
        if($h['deletion']!==null)return$r+['next'=>null,'blocked'=>true];
        if(in_array($h['state'],['completed','rejected'],true))return$r+['next'=>null,'blocked'=>true];
        foreach($h['receipts']as$i=>$receipt)if($receipt['state']==='running'){
            $h['receipts'][$i]['state']='unknown';$h['receipts'][$i]['finished_at']=time();$h['receipts'][$i]['had_unknown']=true;$h['state']='unknown';$h['resume_nonce']=bin2hex(random_bytes(12));self::storeHeader($pdo,$r);return$r+['next'=>null,'blocked'=>true];
        }
        if($h['state']==='unknown'){
            if(!is_string($explicitResume)||!hash_equals($h['resume_nonce'],$explicitResume))return$r+['next'=>null,'blocked'=>true];
            foreach($h['receipts']as$i=>$receipt)if(in_array($receipt['state'],['unknown','rejected'],true))$h['receipts'][$i]=['state'=>'pending','intent'=>null,'finished_at'=>null,'proof_hash'=>null,'had_unknown'=>$receipt['had_unknown'],'generation'=>$receipt['generation']];
            $h['resume_nonce']=null;$h['state']='prepared';
        }
        $next=null;foreach($h['receipts']as$i=>$receipt)if($receipt['state']==='pending'){$next=$i;break;}
        if($next!==null){$generation=$h['receipts'][$next]['generation']+1;if($generation>16)throw new RuntimeException('public_button_explicit_resume_limit');$intent=bin2hex(random_bytes(12));$h['state']='running';$h['receipts'][$next]=['state'=>'running','intent'=>$intent,'finished_at'=>null,'proof_hash'=>null,'had_unknown'=>$h['receipts'][$next]['had_unknown'],'generation'=>$generation];$h['issued_intents'][]=['index'=>$next,'generation'=>$generation,'intent'=>$intent,'issued_at'=>time()];self::storeHeader($pdo,$r);}
        return$r+['next'=>$next,'blocked'=>false];
    }
    public static function settleLocked(PDO$pdo,int$post,string$op,int$index,string$intent,string$outcome,?string$proof=null):array
    {
        self::tx($pdo);if(!in_array($outcome,['done','unknown','rejected'],true))throw new InvalidArgumentException('public_button_edit_outcome');
        $r=self::current($pdo,$post,true);if($r===null||$r['plan']['operation_id']!==$op||!isset($r['header']['receipts'][$index]))throw new RuntimeException('public_button_edit_changed');
        $h=&$r['header'];$receipt=$h['receipts'][$index];
        $issued=null;foreach($h['issued_intents']as$i)if($i['index']===$index&&hash_equals($i['intent'],$intent)){$issued=$i;break;}if($issued===null)throw new RuntimeException('public_button_edit_intent_changed');
        if($h['deletion']!==null||$receipt['state']!=='running'||!is_string($receipt['intent'])||!hash_equals($receipt['intent'],$intent)){
            if($outcome==='done'?!PostedTelegramPublicButtonLayout::digest($proof):$proof!==null)throw new RuntimeException('public_button_edit_proof');
            foreach($h['late_observations']as$o)if($o['index']===$index&&hash_equals($o['intent'],$intent)&&$o['outcome']===$outcome&&$o['proof_hash']===$proof)return$r;
            if(count($h['late_observations'])>=64)throw new RuntimeException('public_button_late_observation_limit');
            $h['late_observations'][]=['index'=>$index,'generation'=>$issued['generation'],'intent'=>$intent,'outcome'=>$outcome,'proof_hash'=>$proof,'observed_at'=>time()];self::storeHeader($pdo,$r);return$r;
        }
        if($outcome==='done'?!PostedTelegramPublicButtonLayout::digest($proof):$proof!==null)throw new RuntimeException('public_button_edit_proof');
        $h['receipts'][$index]=['state'=>$outcome,'intent'=>$intent,'finished_at'=>time(),'proof_hash'=>$proof,'had_unknown'=>$receipt['had_unknown']||$outcome==='unknown','generation'=>$receipt['generation']];
        if($outcome!=='done'){
            $priorDone=count(array_filter($h['receipts'],static fn(array$x):bool=>$x['state']==='done'));
            $priorUnknown=count(array_filter($h['receipts'],static fn(array$x):bool=>$x['had_unknown']));
            $h['state']=$outcome==='rejected'&&$priorDone===0&&$priorUnknown===0?'rejected':'unknown';$h['resume_nonce']=$h['state']==='unknown'?bin2hex(random_bytes(12)):null;
        }
        self::storeHeader($pdo,$r);return$r;
    }
    /** Canonical SQL and the new layout receipt must commit in this SAME transaction. */
    public static function completeLocked(PDO$pdo,int$post,string$op):array
    {
        self::tx($pdo);$r=self::current($pdo,$post,true);if($r===null||$r['plan']['operation_id']!==$op||$r['header']['deletion']!==null||array_filter($r['header']['receipts'],static fn(array$x):bool=>$x['state']!=='done')!==[])throw new RuntimeException('public_button_edit_not_complete');
        $r['header']['state']='completed';$r['header']['resume_nonce']=null;self::storeHeader($pdo,$r);return$r;
    }
    public static function knownResponse(array$step,array$response):bool
    {
        if(($response['ok']??null)!==true)return false;
        if(in_array($step['method'],['pinChatMessage','unpinChatMessage'],true))return($response['result']??null)===true;
        $r=$response['result']??null;$v=$step['payload'];if(!is_array($r)||($r['message_id']??null)!==$v['message_id']||(string)($r['chat']['id']??'')!==$v['chat_id']||!is_array($r['photo']??null)||$r['photo']===[]||($r['caption']??'')!==$v['caption']||PostedTelegramPublicButtonLayout::canonical($r['caption_entities']??[])!==PostedTelegramPublicButtonLayout::canonical($v['caption_entities']))return false;
        if(array_key_exists('show_caption_above_media',$v)){
            $actual=$r['show_caption_above_media']??false;if(!is_bool($actual)||$actual!==$v['show_caption_above_media'])return false;
        }
        return PostedTelegramPublicButtonLayout::canonical($r['reply_markup']['inline_keyboard']??[])===PostedTelegramPublicButtonLayout::canonical($v['reply_markup']['inline_keyboard']);
    }
}
