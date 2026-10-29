<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Known bot-private CP messages only; no incoming or public message can enter this vector. */
final class PostedTelegramCpDetailReceipt
{
    public static function key(int$a,int$p,?string$n=null):string
    {if($a<1||$p<1||($n!==null&&preg_match('/\A[a-f0-9]{24}\z/D',$n)!==1))throw new InvalidArgumentException('cp_detail_identity');return($n===null?'tg_cpdet_':'tg_cpdop_').substr(hash('sha256',$a.':'.$p.($n===null?'':':'.$n)),0,48);}
    public static function normalize(array$r):array
    {
        $keys=['version','actor_id','post_id','nonce','state','binding','plan_hash','expected_count','preview_ids','control_id','retirements'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||($r['version']??null)!==1||!is_int($r['actor_id'])||$r['actor_id']<1||!is_int($r['post_id'])||$r['post_id']<1||!is_string($r['nonce'])||preg_match('/\A[a-f0-9]{24}\z/D',$r['nonce'])!==1||!in_array($r['state'],['rendering','shown','question','unknown'],true))throw new RuntimeException('cp_detail_receipt');
        if(!is_array($r['binding'])||($r['binding']['actor_id']??null)!==$r['actor_id']||($r['binding']['post_id']??null)!==$r['post_id']||!is_string($r['plan_hash'])||preg_match('/\A[a-f0-9]{64}\z/D',$r['plan_hash'])!==1||!is_int($r['expected_count'])||$r['expected_count']<1||$r['expected_count']>110||!is_array($r['preview_ids'])||!array_is_list($r['preview_ids'])||count($r['preview_ids'])>$r['expected_count'])throw new RuntimeException('cp_detail_vector');
        PostedTelegramCpDeleteConfirmation::normalize(array_merge(['version'=>1],$r['binding'],['issued_at'=>1,'expires_at'=>601,'nonce'=>$r['nonce'],'phase'=>'rendering','question_mid'=>null,'observed_mid'=>null]));
        foreach($r['preview_ids']as$id)if(!is_int($id)||$id<1)throw new RuntimeException('cp_detail_mid');
        if(count(array_unique($r['preview_ids']))!==count($r['preview_ids'])||($r['control_id']!==null&&(!is_int($r['control_id'])||$r['control_id']<1||in_array($r['control_id'],$r['preview_ids'],true)))||!is_array($r['retirements'])||count($r['retirements'])>111)throw new RuntimeException('cp_detail_control');
        if(in_array($r['state'],['shown','question'],true)&&(count($r['preview_ids'])!==$r['expected_count']||$r['control_id']===null))throw new RuntimeException('cp_detail_incomplete');
        foreach($r['retirements']as$mid=>$v)if((string)(int)$mid!==(string)$mid||(int)$mid<1||!is_array($v)||count($v)!==2||!in_array($v['purpose']??null,['question_preview','back_question'],true)||!in_array($v['state']??null,['intent','done','unknown'],true))throw new RuntimeException('cp_detail_retirement');
        return$r;
    }
    public static function read(PDO$p,int$a,int$id,bool$lock=false,?string$n=null):?array
    {
        if($lock&&!$p->inTransaction())throw new LogicException('cp_detail_lock');$q=$p->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=?'.($lock?' FOR UPDATE':''));$q->execute([self::key($a,$id,$n)]);$raw=$q->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>32768)throw new RuntimeException('cp_detail_storage');$r=self::normalize(json_decode($raw,true,32,JSON_THROW_ON_ERROR));if($r['actor_id']!==$a||$r['post_id']!==$id||($n!==null&&$r['nonce']!==$n))throw new RuntimeException('cp_detail_storage_identity');return$r;
    }
    public static function write(PDO$p,array$r,bool$current=true):void
    {
        if(!$p->inTransaction())throw new LogicException('cp_detail_write');$r=self::normalize($r);$raw=json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$keys=[self::key($r['actor_id'],$r['post_id'],$r['nonce'])];if($current)$keys[]=self::key($r['actor_id'],$r['post_id']);
        foreach($keys as$key)$p->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$raw]);
    }
    public static function ack(?array$response,int$actor):?int
    {$m=$response['result']??null;return($response['ok']??null)===true&&is_array($m)&&($m['from']['is_bot']??null)===true&&($m['chat']['type']??null)==='private'&&(string)($m['chat']['id']??'')===(string)$actor&&is_int($m['message_id']??null)&&$m['message_id']>0?$m['message_id']:null;}
}

trait GitPostedTelegramCpDetailReceipt
{
    /** null preserves an unsupported legacy renderer; false refuses an unresolved owned render. */
    private function telegramCpDetailBegin(int$actor,array$hint):array|false|null
    {
        if(($hint['status']??null)!=='sent'||!$this->canManagePost($actor,(int)$hint['id'])||$this->isNativeRepost($hint)||PostedTelegramNativeContent::isKind($hint['media_type']??null)||$this->telegramPostOptions($hint)['paid_media_stars']!==null)return null;
        return$this->withTelegramManagedPostLock($actor,(int)$hint['id'],['sent'],function(array$post,array$channel)use($actor):array|false|null{
            $preview=PostedTelegramPostOptions::freeze($this->pdo,array_replace($post,['chat_id'=>(string)$channel['chat_id'],'channel_title'=>(string)$channel['title']]));$plan=$this->telegramPreviewPlan($preview);
            // Album has its own existing complete-gallery receipt. No foreign/native/paid path is changed.
            if(!in_array($plan['mode'],['none','single'],true)||($plan['mode']==='none'&&$plan['text_parts']===[]))return null;
            $v=PostedTelegramCpDetailReceipt::class;$old=$v::read($this->pdo,$actor,(int)$post['id'],true);if($old!==null&&in_array($old['state'],['rendering','unknown'],true))return false;
            $r=['version'=>1,'actor_id'=>$actor,'post_id'=>(int)$post['id'],'nonce'=>bin2hex(random_bytes(12)),'state'=>'rendering','binding'=>$this->telegramCpDeleteSnapshotLocked($actor,$post,$channel),'plan_hash'=>PostedTelegramCpDeleteConfirmation::hash($plan),'expected_count'=>PostedTelegramPreviewProof::expectedCount($plan,(array)$plan['buttons']!==[]),'preview_ids'=>[],'control_id'=>null,'retirements'=>[]];$v::write($this->pdo,$r);return['record'=>$r,'post'=>$preview,'plan'=>$plan];
        })??false;
    }
    private function telegramCpDetailStoreOutcome(array$op,?array$response,bool$control=false,bool$failed=false):void
    {
        if($this->pdo->inTransaction())throw new LogicException('cp_detail_outcome_outer_sql');$v=PostedTelegramCpDetailReceipt::class;$this->pdo->beginTransaction();
        try{
            $r=$v::read($this->pdo,$op['actor_id'],$op['post_id'],true,$op['nonce']);if($r===null)throw new RuntimeException('cp_detail_intent_missing');$current=$v::read($this->pdo,$op['actor_id'],$op['post_id'],true);$live=$current!==null&&$current['nonce']===$op['nonce'];$mid=$v::ack($response,$op['actor_id']);
            if($control&&$mid!==null&&!in_array($mid,$r['preview_ids'],true))$r['control_id']=$mid;
            if($failed||$mid===null||$r['state']!=='rendering')$r['state']='unknown';
            elseif($control){if(count($r['preview_ids'])!==$r['expected_count']||in_array($mid,$r['preview_ids'],true))$r['state']='unknown';else$r['state']='shown';}
            elseif(in_array($mid,$r['preview_ids'],true)||count($r['preview_ids'])>=$r['expected_count'])$r['state']='unknown';else$r['preview_ids'][]=$mid;
            $v::write($this->pdo,$r,$live);$this->pdo->commit();if($r['state']==='unknown')throw new RuntimeException('cp_detail_outcome_unknown');
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function telegramCpDetailObserved(array$op,array$response):void
    {
        $this->telegramCpDetailStoreOutcome($op,$response);
        $current=$this->withTelegramManagedPostLock($op['actor_id'],$op['post_id'],['sent'],fn(array$p,array$c):bool=>PostedTelegramCpDeleteConfirmation::same($op['binding'],$this->telegramCpDeleteSnapshotLocked($op['actor_id'],$p,$c)));
        if($current!==true){$this->telegramCpDetailStoreOutcome($op,null,false,true);throw new RuntimeException('cp_detail_changed_after_preview');}
    }
    private function telegramCpDetailDispatch(array$prepared):void
    {
        $op=$prepared['record'];$current=$this->withTelegramManagedPostLock($op['actor_id'],$op['post_id'],['sent'],fn(array$p,array$c):bool=>PostedTelegramCpDeleteConfirmation::same($op['binding'],$this->telegramCpDeleteSnapshotLocked($op['actor_id'],$p,$c)));
        if($current!==true){$this->telegramCpDetailStoreOutcome($op,null,false,true);throw new RuntimeException('cp_detail_changed_before_preview');}
        $buttons=(array)$prepared['plan']['buttons'];$extra=$buttons!==[]?['reply_markup'=>['inline_keyboard'=>$buttons]]:[];
        $this->dispatchTelegramPreviewPlan($op['actor_id'],$prepared['post'],$prepared['plan'],$extra,fn(array$response)=>$this->telegramCpDetailObserved($op,$response));
    }
    private function telegramCpDetailControlObserved(array$op,array$response,array$control,array$extra):void
    {
        $message=$response['result']??null;$known=is_array($message)&&($message['text']??null)===$control['text']&&PostedTelegramCpDeleteConfirmation::sameValue($message['entities']??[],$extra['entities']??[])&&PostedTelegramCpDeleteConfirmation::sameValue($message['reply_markup']??null,$extra['reply_markup']??null);
        $this->telegramCpDetailStoreOutcome($op,$response,true,!$known);
    }
    private function telegramCpDetailBeforeControl(array$op):void
    {
        $valid=$this->withTelegramManagedPostLock($op['actor_id'],$op['post_id'],['sent'],function(array$p,array$c)use($op):bool{
            $r=PostedTelegramCpDetailReceipt::read($this->pdo,$op['actor_id'],$op['post_id'],true);return$r!==null&&$r['nonce']===$op['nonce']&&$r['state']==='rendering'&&count($r['preview_ids'])===$r['expected_count']&&PostedTelegramCpDeleteConfirmation::same($op['binding'],$this->telegramCpDeleteSnapshotLocked($op['actor_id'],$p,$c));
        });if($valid!==true){$this->telegramCpDetailStoreOutcome($op,null,false,true);throw new RuntimeException('cp_detail_changed_before_control');}
    }
    private function telegramCpDetailAlbumFrame(int$actor,int$postId,int$control):?array
    {
        return$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$p,array$c)use($actor,$postId,$control):?array{
            $a=PostedTelegramPublishedAlbumPanel::read($this->pdo,PostedTelegramPublishedAlbumPanel::detailKey($actor,$postId),true);
            if($a===null||$a['control_id']!==$control||!$this->telegramAlbumPanelDetailBound($a,$this->telegramAlbumPanelCanonicalLocked($actor,$p,$c)))return null;
            $r=['version'=>1,'actor_id'=>$actor,'post_id'=>$postId,'nonce'=>bin2hex(random_bytes(12)),'state'=>'shown','binding'=>$this->telegramCpDeleteSnapshotLocked($actor,$p,$c),'plan_hash'=>$a['fingerprint'],'expected_count'=>count($a['gallery_ids']),'preview_ids'=>$a['gallery_ids'],'control_id'=>$control,'retirements'=>[]];PostedTelegramCpDetailReceipt::write($this->pdo,$r);return$r;
        });
    }
    /** A retirement intent is committed before one DELETE of a known bot-private MID. */
    private function telegramCpDetailRetire(array$frame,int$mid,string$purpose):void
    {
        if($mid<1||!in_array($purpose,['question_preview','back_question'],true)||$this->pdo->inTransaction())return;$v=PostedTelegramCpDetailReceipt::class;$this->pdo->beginTransaction();
        try{$r=$v::read($this->pdo,$frame['actor_id'],$frame['post_id'],true,$frame['nonce']);if($r===null||isset($r['retirements'][(string)$mid])){$this->pdo->commit();return;}
            if($purpose==='question_preview'&&!in_array($mid,$r['preview_ids'],true))throw new RuntimeException('cp_detail_retire_foreign_mid');
            if($purpose==='back_question'&&($r['state']!=='shown'||in_array($mid,$r['preview_ids'],true)||$mid===$r['control_id']))throw new RuntimeException('cp_detail_retire_replacement');
            $r['retirements'][(string)$mid]=['purpose'=>$purpose,'state'=>'intent'];$current=$v::read($this->pdo,$r['actor_id'],$r['post_id'],true);$v::write($this->pdo,$r,$current!==null&&$current['nonce']===$r['nonce']);$this->pdo->commit();
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        $known=false;try{$ack=$this->telegram->call('deleteMessage',['chat_id'=>(string)$r['actor_id'],'message_id'=>$mid]);$known=($ack['ok']??null)===true&&($ack['result']??null)===true;}catch(Throwable$e){error_log('GitPosted CP private retirement outcome: '.$e::class);}
        $this->pdo->beginTransaction();try{$r=$v::read($this->pdo,$frame['actor_id'],$frame['post_id'],true,$frame['nonce']);if($r===null||($r['retirements'][(string)$mid]['state']??null)!=='intent')throw new RuntimeException('cp_detail_retire_intent_changed');$r['retirements'][(string)$mid]['state']=$known?'done':'unknown';$current=$v::read($this->pdo,$r['actor_id'],$r['post_id'],true);$v::write($this->pdo,$r,$current!==null&&$current['nonce']===$r['nonce']);$this->pdo->commit();}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function telegramCpDeleteRetirePreview(array$question):void
    {
        $v=PostedTelegramCpDetailReceipt::class;$r=$v::read($this->pdo,$question['actor_id'],$question['post_id']);
        if($r===null||$r['state']!=='shown'||$r['control_id']!==$question['question_mid'])$r=$this->telegramCpDetailAlbumFrame($question['actor_id'],$question['post_id'],$question['question_mid']);
        if($r===null)return;
        // Ignore only input: this vector belongs to the earlier CP view, never to a newer workflow.
        $b=$r['binding'];$now=$question;unset($b['input_hash'],$now['input_hash']);if(!PostedTelegramCpDeleteConfirmation::same($now,$b))return;
        foreach($r['preview_ids']as$mid)$this->telegramCpDetailRetire($r,$mid,'question_preview');
        $this->pdo->beginTransaction();try{$current=$v::read($this->pdo,$r['actor_id'],$r['post_id'],true);if($current!==null&&$current['nonce']===$r['nonce']){$current['state']='question';$v::write($this->pdo,$current);}$this->pdo->commit();}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function telegramCpDeleteBack(int$actor,int$postId,array$question):void
    {
        $v=PostedTelegramCpDetailReceipt::class;$before=$v::read($this->pdo,$actor,$postId);$albumBefore=PostedTelegramPublishedAlbumPanel::read($this->pdo,PostedTelegramPublishedAlbumPanel::detailKey($actor,$postId));
        $this->showManagedPost($actor,$postId);$new=$v::read($this->pdo,$actor,$postId);
        if($new===null||$new['state']!=='shown'||$new['nonce']===($before['nonce']??null)||$new['control_id']===$question['question_mid']){$new=null;$album=PostedTelegramPublishedAlbumPanel::read($this->pdo,PostedTelegramPublishedAlbumPanel::detailKey($actor,$postId));if($album!==null&&$album['nonce']!==($albumBefore['nonce']??null)&&is_int($album['control_id'])&&$album['control_id']!==$question['question_mid'])$new=$this->telegramCpDetailAlbumFrame($actor,$postId,$album['control_id']);}
        if($new!==null&&$new['state']==='shown'&&$new['control_id']!==$question['question_mid'])$this->telegramCpDetailRetire($new,$question['question_mid'],'back_question');
    }
}
