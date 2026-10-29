<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Private CP confirmation only. It neither changes the post nor retries DELETE. */
final class PostedTelegramCpDeleteConfirmation
{
    public const QUESTION='Вы уверены, что хотите удалить выбранный пост?';
    public static function hash(mixed $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$item)$v[$k]=$sort($item);return$v;};
        return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function sameValue(mixed $a,mixed $b):bool{return hash_equals(self::hash($a),self::hash($b));}
    public static function key(int $actor,int $post):string
    {if($actor<1||$post<1)throw new InvalidArgumentException('cp_delete_identity');return'tg_cpdel_'.substr(hash('sha256',$actor.':'.$post),0,48);}
    public static function normalize(array $r):array
    {
        $keys=['version','actor_id','post_id','channel_id','chat_id','owner_id','epoch','post_hash','options_hash','input_hash','public_edit_hash','issued_at','expires_at','nonce','phase','question_mid','observed_mid'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||($r['version']??null)!==1)throw new RuntimeException('cp_delete_receipt');
        foreach(['actor_id','post_id','channel_id','owner_id','issued_at','expires_at']as$k)if(!is_int($r[$k])||$r[$k]<1)throw new RuntimeException('cp_delete_receipt_identity');
        if(!is_string($r['chat_id'])||preg_match('/\A-?[1-9][0-9]{0,19}\z/D',$r['chat_id'])!==1||!is_string($r['epoch'])||($r['epoch']!==''&&preg_match('/\A[a-f0-9]{32}\z/D',$r['epoch'])!==1))throw new RuntimeException('cp_delete_receipt_channel');
        foreach(['post_hash','options_hash','input_hash','public_edit_hash']as$k)if(!is_string($r[$k])||preg_match('/\A[a-f0-9]{64}\z/D',$r[$k])!==1)throw new RuntimeException('cp_delete_receipt_hash');
        if(!is_string($r['nonce'])||preg_match('/\A[a-f0-9]{24}\z/D',$r['nonce'])!==1||$r['expires_at']!==$r['issued_at']+600||!in_array($r['phase'],['rendering','ready','stale','cancelled','dispatch_started','done','unresolved'],true))throw new RuntimeException('cp_delete_receipt_phase');
        foreach(['question_mid','observed_mid']as$k)if($r[$k]!==null&&(!is_int($r[$k])||$r[$k]<1))throw new RuntimeException('cp_delete_receipt_mid');
        if(in_array($r['phase'],['ready','dispatch_started','done'],true)&&$r['question_mid']===null)throw new RuntimeException('cp_delete_receipt_question');
        return$r;
    }
    public static function read(PDO $pdo,int $actor,int $post,bool $lock=false):?array
    {
        if($lock&&!$pdo->inTransaction())throw new LogicException('cp_delete_lock_without_transaction');
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=?'.($lock?' FOR UPDATE':''));$q->execute([self::key($actor,$post)]);$raw=$q->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('cp_delete_storage');$r=self::normalize(json_decode($raw,true,16,JSON_THROW_ON_ERROR));
        if($r['actor_id']!==$actor||$r['post_id']!==$post)throw new RuntimeException('cp_delete_storage_identity');return$r;
    }
    public static function write(PDO $pdo,array $r):void
    {
        if(!$pdo->inTransaction())throw new LogicException('cp_delete_write_without_transaction');$r=self::normalize($r);
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key($r['actor_id'],$r['post_id']),json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    public static function keyboard(int $post,string $nonce):array
    {
        if($post<1||preg_match('/\A[a-f0-9]{24}\z/D',$nonce)!==1)throw new InvalidArgumentException('cp_delete_keyboard');
        return[[['text'=>'Да, удалить','callback_data'=>'cp:delok:'.$post.':'.$nonce]],[['text'=>'← Назад','callback_data'=>'cp:delback:'.$post.':'.$nonce]]];
    }
    public static function same(array $r,array $snapshot):bool
    {foreach($snapshot as$k=>$v)if(!array_key_exists($k,$r)||$r[$k]!==$v)return false;return true;}
}

trait GitPostedTelegramCpDeleteConfirmation
{
    private function telegramCpDeleteSnapshotLocked(int $actor,array $post,array $channel):array
    {
        if(!$this->pdo->inTransaction()||($post['status']??null)!=='sent'||(int)($post['channel_id']??0)!==(int)($channel['id']??0)||empty($channel['is_active'])||(int)($channel['added_by']??0)<1)throw new RuntimeException('cp_delete_snapshot');
        $q=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$q->execute([$actor]);$input=$q->fetch();if(!is_array($input))throw new RuntimeException('cp_delete_actor');
        $options=PostedTelegramPostOptions::scopeSnapshot($this->pdo,[$post],[]);$options['top']=PostedTelegramTopHold::load($this->pdo,$post,true);
        $public=PostedTelegramPublicButtonEdit::current($this->pdo,(int)$post['id'],true);
        return['actor_id'=>$actor,'post_id'=>(int)$post['id'],'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'owner_id'=>(int)$channel['added_by'],'epoch'=>PostedTelegramAuthorityPause::generation($this->pdo,$channel,true),
            'post_hash'=>PostedTelegramCpDeleteConfirmation::hash($post),'options_hash'=>PostedTelegramCpDeleteConfirmation::hash($options),'input_hash'=>PostedTelegramCpDeleteConfirmation::hash($input),'public_edit_hash'=>PostedTelegramCpDeleteConfirmation::hash($public)];
    }

    /** Return true only for the durable, single consumed confirmation still current at DELETE. */
    private function telegramCpDeleteGuardLocked(int $actor,array $post,?array $confirmation):bool
    {
        if($confirmation===null)return true;
        try{
            if(!$this->pdo->inTransaction())return false;$confirmation=PostedTelegramCpDeleteConfirmation::normalize($confirmation);
            if($confirmation['phase']!=='dispatch_started'||$confirmation['actor_id']!==$actor||$confirmation['post_id']!==(int)$post['id']||time()<$confirmation['issued_at']||time()>$confirmation['expires_at'])return false;
            $q=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$post['channel_id']]);$channel=$q->fetch();if(!is_array($channel))return false;
            $r=PostedTelegramCpDeleteConfirmation::read($this->pdo,$actor,(int)$post['id'],true);
            return$r!==null&&PostedTelegramCpDeleteConfirmation::sameValue($r,$confirmation)&&PostedTelegramCpDeleteConfirmation::same($r,$this->telegramCpDeleteSnapshotLocked($actor,$post,$channel));
        }catch(Throwable $e){error_log('GitPosted CP delete confirmation held: '.$e::class);return false;}
    }

    private function handleTelegramCpDeleteConfirmation(array $callback):bool
    {
        $data=$callback['data']??null;if(!is_string($data)||preg_match('/\Acp:(del|delok|delback)(?::|\z)/D',$data)!==1)return false;
        $match=[];if(preg_match('/\Acp:(del|delok|delback):([1-9][0-9]{0,18})(?::([a-f0-9]{24}))?\z/D',$data,$match)!==1||(string)(int)$match[2]!==$match[2])return true;
        $postId=(int)$match[2];$action=$match[1];$hint=$this->post($postId);
        // Draft, scheduled and pending-publication semantics are outside this Source-proven UI change.
        if($action==='del'&&(!is_array($hint)||($hint['status']??null)!=='sent'))return false;
        $actor=$callback['from']['id']??null;$message=$callback['message']??null;$chat=is_array($message)?($message['chat']??null):null;
        if($this->pdo->inTransaction()||!is_int($actor)||$actor<1||!is_array($message)||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||($message['from']['is_bot']??null)!==true||!is_int($message['message_id']??null)||$message['message_id']<1||isset($callback['inline_message_id'])||isset($message['forward_origin'])||isset($message['forward_from'])||isset($message['forward_from_chat'])||isset($message['forward_date']))return true;
        $nonce=$match[3]??null;if(($action==='del')!==($nonce===null))return true;
        if($action!=='del'&&(($message['text']??null)!==PostedTelegramCpDeleteConfirmation::QUESTION||($message['entities']??[])!==[]||!PostedTelegramCpDeleteConfirmation::sameValue($message['reply_markup']['inline_keyboard']??null,PostedTelegramCpDeleteConfirmation::keyboard($postId,$nonce))))return true;
        if(!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo)||!is_array($hint))return true;
        $this->answerCallback($callback);
        $name='gitposted_pin_'.substr(hash('sha256',(string)$hint['channel_id']),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return true;
        try{
            if($action==='del'){$this->showTelegramCpDeleteQuestion($actor,$postId,$message);return true;}
            $r=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array $post,array $channel)use($actor,$postId,$nonce,$message,$action):?array{
                $v=PostedTelegramCpDeleteConfirmation::class;$r=$v::read($this->pdo,$actor,$postId,true);
                if($r===null||$r['phase']!=='ready'||!hash_equals($r['nonce'],$nonce)||$r['question_mid']!==$message['message_id']||time()<$r['issued_at']||time()>$r['expires_at']||!$v::same($r,$this->telegramCpDeleteSnapshotLocked($actor,$post,$channel)))return null;
                $r['phase']=$action==='delok'?'dispatch_started':'cancelled';$v::write($this->pdo,$r);return$r;
            });
            if(!is_array($r))return true;
            if($action==='delback'){
                $this->telegramCpDeleteBack($actor,$postId,$r);return true;
            }
            // No direct Telegram delete, loop, or retry: the existing canonical path owns all outcomes.
            $this->deleteSentManagedPost($actor,$this->post($postId),$this->callbackTarget($callback),$r);
            $this->pdo->beginTransaction();
            try{$current=PostedTelegramCpDeleteConfirmation::read($this->pdo,$actor,$postId,true);if($current!==null&&PostedTelegramCpDeleteConfirmation::sameValue($current,$r)){$q=$this->pdo->prepare('SELECT status FROM posted_posts WHERE id=? LIMIT 1');$q->execute([$postId]);$current['phase']=$q->fetchColumn()==='deleted'?'done':'unresolved';PostedTelegramCpDeleteConfirmation::write($this->pdo,$current);}$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('GitPosted CP delete held: '.$e::class);}
        finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
        return true;
    }

    private function showTelegramCpDeleteQuestion(int $actor,int $postId,array $sourceMessage):void
    {
        $v=PostedTelegramCpDeleteConfirmation::class;
        if(!is_string($sourceMessage['text']??null)||isset($sourceMessage['caption']))return;
        $targetMid=$sourceMessage['message_id'];
        $r=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array $post,array $channel)use($actor,$postId,$targetMid,$v):?array{
            $snapshot=$this->telegramCpDeleteSnapshotLocked($actor,$post,$channel);$old=$v::read($this->pdo,$actor,$postId,true);
            if($old!==null&&(in_array($old['phase'],['rendering','dispatch_started','unresolved','done'],true)||($old['phase']==='ready'&&$old['question_mid']===$targetMid&&time()>=$old['issued_at']&&time()<=$old['expires_at']&&$v::same($old,$snapshot))))return null;
            $now=time();$r=array_merge(['version'=>1],$snapshot,['issued_at'=>$now,'expires_at'=>$now+600,'nonce'=>bin2hex(random_bytes(12)),'phase'=>'rendering','question_mid'=>$targetMid,'observed_mid'=>null]);$v::write($this->pdo,$r);return$r;
        });
        if(!is_array($r))return;
        $reply=['inline_keyboard'=>$v::keyboard($postId,$r['nonce'])];$ack=null;
        try{if($this->pdo->inTransaction())throw new LogicException('cp_delete_question_inside_sql');$ack=$this->telegram->call('editMessageText',['chat_id'=>(string)$actor,'message_id'=>$targetMid,'text'=>$v::QUESTION,'entities'=>[],'reply_markup'=>$reply,'link_preview_options'=>['is_disabled'=>true]]);}catch(Throwable $e){error_log('GitPosted CP delete question outcome: '.$e::class);}
        $message=is_array($ack)?($ack['result']??null):null;$observed=is_array($message)&&(string)($message['chat']['id']??'')===(string)$actor&&is_int($message['message_id']??null)&&$message['message_id']>0?$message['message_id']:null;
        $known=($ack['ok']??null)===true&&$observed===$targetMid&&($message['from']['is_bot']??null)===true&&($message['chat']['type']??null)==='private'&&($message['text']??null)===$v::QUESTION&&($message['entities']??[])===[]&&PostedTelegramCpDeleteConfirmation::sameValue($message['reply_markup']??null,$reply);
        $this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array $post,array $channel)use($actor,$postId,$r,$known,$observed,$v):void{
            $current=$v::read($this->pdo,$actor,$postId,true);if($current===null||!PostedTelegramCpDeleteConfirmation::sameValue($current,$r))return;
            $current['observed_mid']=$observed;$current['question_mid']=$known?$observed:null;$current['phase']=$known?($v::same($r,$this->telegramCpDeleteSnapshotLocked($actor,$post,$channel))?'ready':'stale'):'unresolved';$v::write($this->pdo,$current);
        });
        $shown=$v::read($this->pdo,$actor,$postId);if($shown!==null&&$shown['nonce']===$r['nonce']&&$shown['phase']==='ready')$this->telegramCpDeleteRetirePreview($shown);
    }
}
