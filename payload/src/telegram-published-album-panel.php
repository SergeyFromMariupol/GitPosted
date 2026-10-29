<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Private presentation evidence only. It never authorizes publication or a grant. */
final class PostedTelegramPublishedAlbumPanel
{
    public static function key(int$actor,int$post):string
    {if($actor<1||$post<1)throw new InvalidArgumentException('album_panel_identity');return'palb_ui_'.$actor.'_'.$post;}
    public static function opKey(int$actor,int$post,string$nonce):string
    {self::key($actor,$post);if(preg_match('/\A[a-f0-9]{24}\z/D',$nonce)!==1)throw new InvalidArgumentException('album_panel_nonce');return'palb_op_'.substr(hash('sha256',$actor.':'.$post.':'.$nonce),0,56);}
    public static function detailKey(int$actor,int$post):string
    {return str_replace('palb_ui_','palb_detail_',self::key($actor,$post));}
    public static function hash(mixed$v):string{return PostedTelegramPublishedAlbumEditor::hash($v);}
    public static function read(PDO$pdo,string$key,bool$lock=false):?array
    {
        if(preg_match('/\Apalb_(?:(?:ui|detail)_[1-9][0-9]{0,18}_[1-9][0-9]{0,18}|op_[a-f0-9]{56})\z/D',$key)!==1)throw new InvalidArgumentException('album_panel_key');
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([$key]);$raw=$q->fetchColumn();if($raw===false)return null;$r=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);self::validate($r);self::assertKey($key,$r);return$r;
    }
    private static function assertKey(string$key,array$r):void
    {if(!in_array($key,[self::key($r['actor_id'],$r['post_id']),self::detailKey($r['actor_id'],$r['post_id']),self::opKey($r['actor_id'],$r['post_id'],$r['nonce'])],true)||($key===self::detailKey($r['actor_id'],$r['post_id'])&&$r['kind']!=='detail')||($key===self::key($r['actor_id'],$r['post_id'])&&$r['kind']==='detail'))throw new RuntimeException('album_panel_key_binding');}
    public static function validate(mixed$r):void
    {
        if(!is_array($r)||($r['version']??null)!==1)throw new RuntimeException('album_panel_record');
        foreach(['actor_id','post_id','channel_id','owner_id']as$k)if(!is_int($r[$k]??null)||$r[$k]<1)throw new RuntimeException('album_panel_binding');
        foreach(['session_hash','base_hash','vector_hash','fingerprint','control_hash']as$k)if(!is_string($r[$k]??null)||preg_match('/\A[a-f0-9]{64}\z/D',$r[$k])!==1)throw new RuntimeException('album_panel_fingerprint');
        if(!is_string($r['nonce']??null)||preg_match('/\A[a-f0-9]{24}\z/D',$r['nonce'])!==1||!is_string($r['epoch']??null)||preg_match('/\A(?:legacy|[a-f0-9]{32})\z/D',$r['epoch'])!==1||!is_string($r['session_created_at']??null)||!in_array($r['state']??null,['rendering','shown','unknown','failed','consumed'],true)||!in_array($r['phase']??null,['detail','main','media'],true)||!in_array($r['kind']??null,['detail','control_edit','gallery_replace'],true))throw new RuntimeException('album_panel_state');
        if(!is_int($r['expected_media_count']??null)||$r['expected_media_count']<2||$r['expected_media_count']>10||!is_bool($r['gallery_complete']??null))throw new RuntimeException('album_panel_gallery_proof');
        if(($r['kind']==='detail')!==($r['phase']==='detail')||($r['state']==='consumed'&&$r['kind']!=='detail'))throw new RuntimeException('album_panel_kind');
        foreach(['control_id','old_control_id']as$k)if(!array_key_exists($k,$r)||($r[$k]!==null&&(!is_int($r[$k])||$r[$k]<1)))throw new RuntimeException('album_panel_control');
        if(!is_array($r['gallery_ids']??null)||!array_is_list($r['gallery_ids'])||count($r['gallery_ids'])>10||count(array_unique($r['gallery_ids']))!==count($r['gallery_ids']))throw new RuntimeException('album_panel_gallery');
        foreach($r['gallery_ids']as$id)if(!is_int($id)||$id<1)throw new RuntimeException('album_panel_gallery_id');
        if($r['gallery_complete']&&count($r['gallery_ids'])!==$r['expected_media_count'])throw new RuntimeException('album_panel_gallery_count');
        if($r['state']==='shown'&&(!$r['gallery_complete']||$r['control_id']===null||in_array($r['control_id'],$r['gallery_ids'],true)))throw new RuntimeException('album_panel_shown_proof');
        if(!in_array($r['retirement']??null,['none','pending','sending','done','unknown','superseded'],true))throw new RuntimeException('album_panel_retirement');
    }
    public static function put(PDO$pdo,string$key,array$r):void
    {if(!$pdo->inTransaction())throw new LogicException('album_panel_transaction');self::validate($r);self::assertKey($key,$r);$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    public static function ack(array$response,int$actor,?int$expected=null):?int
    {$r=$response['result']??null;$id=is_array($r)?($r['message_id']??null):null;return($response['ok']??null)===true&&is_int($id)&&$id>0&&($expected===null||$id===$expected)&&is_array($r['chat']??null)&&($r['chat']['type']??null)==='private'&&(string)($r['chat']['id']??'')===(string)$actor?$id:null;}
    /** Preserve a bounded known subset even when the complete group is malformed. */
    public static function knownGallery(array$response,int$actor):array
    {$out=[];$rows=$response['result']??null;if(($response['ok']??null)!==true||!is_array($rows))return[];foreach($rows as$row){if(!is_array($row))continue;$id=self::ack(['ok'=>true,'result'=>$row],$actor);if($id!==null&&!in_array($id,$out,true))$out[]=$id;if(count($out)===10)break;}return$out;}
    public static function completeGallery(array$response,int$actor,int$count):bool
    {$rows=$response['result']??null;if(($response['ok']??null)!==true||!is_array($rows)||!array_is_list($rows)||count($rows)!==$count)return false;$ids=[];foreach($rows as$row){if(!is_array($row))return false;$id=self::ack(['ok'=>true,'result'=>$row],$actor);if($id===null||in_array($id,$ids,true))return false;$ids[]=$id;}return count($ids)===$count;}
}

trait GitPostedTelegramPublishedAlbumPanel
{
    private ?array$telegramPublishedAlbumPanelContext=null;
    private function telegramAlbumPanelOwnWorkflow(int$actor,int$post):bool
    {$u=$this->user($actor);$s=(string)($u['state']??'');$d=$this->stateData($u);return$s===''||(in_array($s,['await_edit_text','await_edit_media','await_edit_buttons'],true)&&(int)($d['post_id']??0)===$post);}
    private function telegramAlbumPanelTarget(array$callback,string$action):?array
    {
        $actor=$callback['from']['id']??null;$message=$callback['message']??null;$chat=is_array($message)?($message['chat']??null):null;
        if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||!is_int($message['message_id']??null)||$message['message_id']<1||($message['from']['is_bot']??null)!==true)return null;
        $target=$this->callbackTarget($callback);if($target===null||!empty($target['has_media']))return null;return['action'=>$action,'target'=>$target];
    }
    private function handleTelegramAlbumPanelEntry(array$callback):bool
    {
        $data=$callback['data']??null;if(!is_string($data)||preg_match('/\Acp:edit:([1-9][0-9]{0,18})\z/D',$data,$m)!==1||(string)(int)$m[1]!==$m[1])return false;$post=$this->post((int)$m[1]);if(!is_array($post)||!$this->telegramAlbumEditCandidate($post))return false;
        $actor=$callback['from']['id']??null;$context=$this->telegramAlbumPanelTarget($callback,'cp_entry');
        if($context===null||!is_int($actor)||!$this->isKnownActiveUser($this->user($actor))||!$this->can($actor,(int)$post['channel_id'],'manage_posts')||!$this->telegramPublishedAlbumEditable($post)||!$this->telegramAlbumPanelOwnWorkflow($actor,(int)$post['id'])){$this->answerCallback($callback,'Пост недоступен. Откройте его в личном чате бота.');return true;}
        $this->answerCallback($callback);$previous=$this->telegramPublishedAlbumPanelContext;$this->telegramPublishedAlbumPanelContext=$context;
        try{$this->openContentPlanPublishedEditor($actor,(int)$m[1]);}finally{$this->telegramPublishedAlbumPanelContext=$previous;}return true;
    }
    private function telegramAlbumPanelBound(array$r,array$v):bool
    {return$r['actor_id']===$v['actor_id']&&$r['post_id']===(int)$v['stored']['id']&&$r['channel_id']===(int)$v['channel']['id']&&$r['owner_id']===(int)$v['channel']['added_by']&&$r['epoch']===$v['epoch']&&$r['base_hash']===$v['base_hash']&&$r['vector_hash']===$v['vector_hash']&&$r['session_created_at']===$v['session_created_at'];}
    private function telegramAlbumPanelDetailBound(array$r,array$v):bool
    {return$r['kind']==='detail'&&$r['state']==='shown'&&$r['gallery_complete']&&$r['actor_id']===$v['actor_id']&&$r['post_id']===(int)$v['stored']['id']&&$r['channel_id']===(int)$v['channel']['id']&&$r['owner_id']===(int)$v['channel']['added_by']&&$r['epoch']===$v['epoch']&&$r['base_hash']===$v['base_hash']&&$r['vector_hash']===$v['vector_hash']&&$r['fingerprint']===$v['canonical_fingerprint'];}
    private function telegramAlbumPanelDetailEligible(int$actor,array$post):bool
    {return(string)($post['status']??'')==='sent'&&$this->can($actor,(int)$post['channel_id'],'manage_posts')&&$this->telegramPublishedAlbumEditable($post);}
    private function telegramAlbumPanelCanonicalLocked(int$actor,array$post,array$channel):array
    {
        $album=$this->telegramAlbumEditDescriptorLocked($post,$channel);$options=PostedTelegramPostOptions::load($this->pdo,$post);$plan=$this->telegramPreviewPlan(array_replace($post,['_telegram_post_options'=>$options]));
        if($plan['mode']!=='group'||count($plan['media'])<2||count($plan['media'])>10||$plan['text_parts']!==[])throw new RuntimeException('album_panel_nonordinary_preview');
        return['actor_id'=>$actor,'stored'=>$post,'channel'=>$channel,'plan'=>$plan,'canonical_fingerprint'=>$plan['fingerprint'],'base_hash'=>PostedTelegramPublishedAlbumEditor::hash($this->editablePostSnapshot($post)),'vector_hash'=>PostedTelegramPublishedAlbumEditor::hash([$album['hash'],$this->postMessageIds($post),$this->postMedia($post)]),'epoch'=>PostedTelegramAuthorityPause::generation($this->pdo,$channel,true)?:'legacy'];
    }
    private function telegramAlbumPanelDetailStillCurrent(int$actor,int$post,array$op):bool
    {try{return$this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$op):bool{$v=$this->telegramAlbumPanelCanonicalLocked($actor,$p,$c);$r=PostedTelegramPublishedAlbumPanel::read($this->pdo,PostedTelegramPublishedAlbumPanel::detailKey($actor,(int)$p['id']),true);return$r!==null&&$r['nonce']===$op['nonce']&&$r['state']==='rendering'&&$r['actor_id']===$actor&&$r['owner_id']===(int)$c['added_by']&&$r['epoch']===$v['epoch']&&$r['base_hash']===$v['base_hash']&&$r['vector_hash']===$v['vector_hash']&&$r['fingerprint']===$v['canonical_fingerprint'];},false,true)===true;}catch(Throwable){return false;}}
    /** Only this complete detail proof permits a later zero-gallery CP entry. */
    private function renderTelegramPublishedAlbumDetail(int$actor,int$post,array$control,array$extra):void
    {
        if($this->pdo->inTransaction())return;$class=PostedTelegramPublishedAlbumPanel::class;$known=[];$controlId=null;
        try{$prepared=$this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$post,$control,$extra,$class):?array{
            $v=$this->telegramAlbumPanelCanonicalLocked($actor,$p,$c);$old=$class::read($this->pdo,$class::detailKey($actor,$post),true);
            if($old!==null&&in_array($old['state'],['rendering','unknown','failed'],true))return null;
            $op=['version'=>1,'actor_id'=>$actor,'post_id'=>$post,'channel_id'=>(int)$c['id'],'owner_id'=>(int)$c['added_by'],'epoch'=>$v['epoch'],'nonce'=>bin2hex(random_bytes(12)),'state'=>'rendering','phase'=>'detail','kind'=>'detail','control_id'=>null,'old_control_id'=>null,'gallery_ids'=>[],'expected_media_count'=>count($v['plan']['media']),'gallery_complete'=>false,'session_hash'=>$class::hash(null),'base_hash'=>$v['base_hash'],'vector_hash'=>$v['vector_hash'],'session_created_at'=>'','fingerprint'=>$v['canonical_fingerprint'],'control_hash'=>$class::hash([$control,$extra]),'retirement'=>'none'];
            $class::put($this->pdo,$class::opKey($actor,$post,$op['nonce']),$op);$class::put($this->pdo,$class::detailKey($actor,$post),$op);return['operation'=>$op,'view'=>$v];
        },false,true);if(!is_array($prepared))throw new RuntimeException('album_panel_detail_unconfirmed');$op=$prepared['operation'];
        if(!$this->telegramAlbumPanelDetailStillCurrent($actor,$post,$op))throw new RuntimeException('album_panel_detail_changed');
        $response=$this->telegram->sendMediaGroup($actor,$prepared['view']['plan']['media']);$known=$class::knownGallery($response,$actor);$complete=$class::completeGallery($response,$actor,$op['expected_media_count']);$this->telegramAlbumPanelStoreOutcome($op,'rendering',$known,null,$complete);
        if(!$complete)throw new RuntimeException('album_panel_incomplete_detail_gallery');if(!$this->telegramAlbumPanelDetailStillCurrent($actor,$post,$op))throw new RuntimeException('album_panel_detail_changed');
        $response=$this->telegram->sendMessage($actor,$control['text'],$extra);$controlId=$class::ack($response,$actor);if($controlId===null||in_array($controlId,$known,true))throw new RuntimeException('album_panel_detail_control_unknown');$this->telegramAlbumPanelStoreOutcome($op,'shown',[],$controlId);
        }catch(Throwable$e){if(isset($op)){try{$this->telegramAlbumPanelStoreOutcome($op,'unknown',$known,$controlId);}catch(Throwable){}}error_log('GitPosted album private detail: '.$e::class);try{$this->telegram->sendMessage($actor,'Не удалось подтвердить карточку альбома. Публикация и ваши данные сохранены; автоматического повтора нет.');}catch(Throwable){}}
    }
    private function telegramAlbumPanelViewLocked(int$actor,array$post,array$channel,array$expected):array
    {
        if(!$this->telegramAlbumPanelOwnWorkflow($actor,(int)$post['id']))throw new RuntimeException('album_panel_unrelated_workflow');
        $album=$this->telegramAlbumEditDescriptorLocked($post,$channel);$session=$this->telegramAlbumEditSessionLocked($actor,(int)$post['id']);$doc=$this->editSessionDocument($session,$post);if($doc['base']!==$this->editablePostSnapshot($post))throw new RuntimeException('album_panel_stale_base');$stage=$this->telegramAlbumEditStageLocked($actor,$post,$channel,$doc);$revision=substr(PostedTelegramPublishedAlbumEditor::hash([$doc,$stage]),0,16);if(!hash_equals($expected['revision'],$revision))throw new RuntimeException('album_panel_stale_view');
        $options=PostedTelegramPostOptions::load($this->pdo,$post);$canonical=$this->telegramPreviewPlan(array_replace($post,['_telegram_post_options'=>$options]));$options['media_spoiler']=$stage['spoiler'];$preview=array_replace($this->postWithEditableSnapshot($post,$doc['draft']),['_telegram_post_options'=>$options]);$plan=$this->telegramPreviewPlan($preview);
        if($plan['mode']!=='group'||count($plan['media'])<2||count($plan['media'])>10||$plan['text_parts']!==[])throw new RuntimeException('album_panel_nonordinary_preview');
        return['actor_id'=>$actor,'stored'=>$post,'channel'=>$channel,'post'=>$preview,'plan'=>$plan,'canonical_fingerprint'=>$canonical['fingerprint'],'session_hash'=>PostedTelegramPublishedAlbumEditor::hash([$doc,$stage]),'base_hash'=>PostedTelegramPublishedAlbumEditor::hash($doc['base']),'vector_hash'=>PostedTelegramPublishedAlbumEditor::hash([$album['hash'],$this->postMessageIds($post),$this->postMedia($post)]),'session_created_at'=>(string)$session['created_at'],'epoch'=>PostedTelegramAuthorityPause::generation($this->pdo,$channel,true)?:'legacy'];
    }
    private function telegramAlbumPanelStoreOutcome(array$op,string$state,array$known=[],?int$control=null,bool$complete=false):bool
    {
        if($this->pdo->inTransaction())throw new LogicException('album_panel_outcome_outer_transaction');$class=PostedTelegramPublishedAlbumPanel::class;$currentKey=$op['kind']==='detail'?$class::detailKey($op['actor_id'],$op['post_id']):$class::key($op['actor_id'],$op['post_id']);$key=$class::opKey($op['actor_id'],$op['post_id'],$op['nonce']);$this->pdo->beginTransaction();
        try{$r=$class::read($this->pdo,$key,true);if($r===null)throw new RuntimeException('album_panel_intent_missing');$r['state']=$state;$r['gallery_ids']=array_values(array_unique(array_merge($r['gallery_ids'],$known)));if($control!==null)$r['control_id']=$control;if($complete)$r['gallery_complete']=true;$class::put($this->pdo,$key,$r);$current=$class::read($this->pdo,$currentKey,true);$latest=$current!==null&&$current['nonce']===$op['nonce'];if($latest)$class::put($this->pdo,$currentKey,$r);$this->pdo->commit();return$latest;}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function telegramAlbumPanelStillCurrent(int$actor,int$post,array$op,array$view):bool
    {
        try{return$this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$post,$op,$view):bool{$v=$this->telegramAlbumPanelViewLocked($actor,$p,$c,$view);$r=PostedTelegramPublishedAlbumPanel::read($this->pdo,PostedTelegramPublishedAlbumPanel::key($actor,$post),true);return$r!==null&&$r['nonce']===$op['nonce']&&$r['state']==='rendering'&&$r['session_hash']===$v['session_hash']&&$this->telegramAlbumPanelBound($r,$v);},false,true)===true;}catch(Throwable){return false;}
    }
    private function renderTelegramPublishedAlbumPanel(int$actor,int$post,bool$media,array$view,array$rows):void
    {
        if($this->pdo->inTransaction())return;$class=PostedTelegramPublishedAlbumPanel::class;$context=$this->telegramPublishedAlbumPanelContext;
        try{$prepared=$this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$post,$media,$view,$rows,$context,$class):?array{
            $v=$this->telegramAlbumPanelViewLocked($actor,$p,$c,$view);$old=$class::read($this->pdo,$class::key($actor,$post),true);$bound=is_array($old)&&$this->telegramAlbumPanelBound($old,$v);$cp=$context!==null&&$context['action']==='cp_entry';$target=$context['target']['message_id']??null;
            if($context!==null&&!$cp&&(!$bound||$old['state']!=='shown'||$old['control_id']!==$target)){
                // A normal caption prompt is a separate legacy text message.
                // Its Back never promotes that unrecorded MID into a panel.
                $u=$this->user($actor);$d=$this->stateData($u);$captionBack=$context['action']==='album_back'&&($u['state']??null)==='await_edit_text'&&(int)($d['post_id']??0)===$post&&$bound&&$old['state']==='shown';
                if(!$captionBack)return null;
            }
            $detail=$cp?$class::read($this->pdo,$class::detailKey($actor,$post),true):null;
            $freshCp=$cp&&is_array($detail)&&$this->telegramAlbumPanelDetailBound($detail,$v)&&$detail['control_id']===$target&&hash_equals($v['canonical_fingerprint'],$v['plan']['fingerprint']);
            // Historical CP cards have no evidence record. They may create one
            // first guarded preview, but cannot reuse any claimed old gallery.
            if($cp&&!$freshCp&&($detail!==null||$old!==null))return null;
            if(is_array($old)&&in_array($old['state'],['rendering','unknown','failed'],true)&&!$freshCp)return null;
            $reuse=$freshCp||($bound&&$old['state']==='shown'&&hash_equals($old['fingerprint'],$v['plan']['fingerprint']));
            $explicitMedia=$context!==null&&$context['action']==='media'&&$bound&&$old['phase']==='main';$gallery=!$reuse||$explicitMedia;$control=$gallery?null:($freshCp?$target:$old['control_id']);if(!$gallery&&(!is_int($control)||$control<1))return null;
            $op=['version'=>1,'actor_id'=>$actor,'post_id'=>$post,'channel_id'=>(int)$c['id'],'owner_id'=>(int)$c['added_by'],'epoch'=>$v['epoch'],'nonce'=>bin2hex(random_bytes(12)),'state'=>'rendering','phase'=>$media?'media':'main','kind'=>$gallery?'gallery_replace':'control_edit','control_id'=>$control,'old_control_id'=>$gallery&&$bound&&$old['state']==='shown'?$old['control_id']:null,'gallery_ids'=>$gallery?[]:($freshCp?$detail['gallery_ids']:$old['gallery_ids']),'expected_media_count'=>count($v['plan']['media']),'gallery_complete'=>!$gallery,'session_hash'=>$v['session_hash'],'base_hash'=>$v['base_hash'],'vector_hash'=>$v['vector_hash'],'session_created_at'=>$v['session_created_at'],'fingerprint'=>$v['plan']['fingerprint'],'control_hash'=>$class::hash(['text'=>'Настройте пост перед публикацией.','rows'=>$rows]),'retirement'=>'none'];
            if($freshCp){$detail['state']='consumed';$class::put($this->pdo,$class::detailKey($actor,$post),$detail);}
            $class::put($this->pdo,$class::opKey($actor,$post,$op['nonce']),$op);$class::put($this->pdo,$class::key($actor,$post),$op);return['operation'=>$op,'view'=>$v,'same_control_payload'=>$bound&&$old['state']==='shown'&&$old['control_id']===$control&&$old['control_hash']===$op['control_hash']];
        },false,true);if(!is_array($prepared)){$this->telegram->sendMessage($actor,'Панель изменилась или предпросмотр ещё не подтверждён. Откройте пост заново.');return;}
        $op=$prepared['operation'];$control=$op['control_id'];$known=[];$extra=['reply_markup'=>['inline_keyboard'=>$rows]];
        if($op['kind']==='gallery_replace'){
            if(!$this->telegramAlbumPanelStillCurrent($actor,$post,$op,$view))throw new RuntimeException('album_panel_authority_changed');
            try{$response=$this->telegram->sendMediaGroup($actor,$prepared['view']['plan']['media']);}catch(Throwable$e){$this->telegramAlbumPanelStoreOutcome($op,'unknown');throw$e;}
            $known=$class::knownGallery($response,$actor);$complete=$class::completeGallery($response,$actor,count($prepared['view']['plan']['media']));$this->telegramAlbumPanelStoreOutcome($op,'rendering',$known,null,$complete);
            if(!$complete)throw new RuntimeException('album_panel_incomplete_gallery');
            if(!$this->telegramAlbumPanelStillCurrent($actor,$post,$op,$view))throw new RuntimeException('album_panel_authority_changed');
            $response=$this->telegram->sendMessage($actor,'Настройте пост перед публикацией.',$extra);$control=$class::ack($response,$actor);if($control===null||in_array($control,$known,true))throw new RuntimeException('album_panel_control_unknown');
        }else{
            if(!$this->telegramAlbumPanelStillCurrent($actor,$post,$op,$view))throw new RuntimeException('album_panel_authority_changed');
            try{$response=$this->telegram->editMessageText($actor,$control,'Настройте пост перед публикацией.',$extra);$id=$class::ack($response,$actor,$control);if($id===null)throw new RuntimeException('album_panel_edit_unknown');}
            catch(PostedTelegramRequestException$e){$message=mb_strtolower(trim($e->getMessage()));if($e->deliveryMayBeUnknown()||!$prepared['same_control_payload']||!in_array($message,['bad request: message is not modified','message is not modified'],true))throw$e;}
        }
        $latest=$this->telegramAlbumPanelStoreOutcome($op,'shown',$known,$control);if($latest&&$op['kind']==='gallery_replace'&&$op['old_control_id']!==null&&$op['old_control_id']!==$control&&!in_array($op['old_control_id'],$known,true)){try{$this->retireTelegramAlbumPrivateControl($op,$control);}catch(Throwable$retirementError){error_log('GitPosted old album control retirement: '.$retirementError::class);}}
        }catch(Throwable$e){if(isset($op)&&is_array($op)){try{$this->telegramAlbumPanelStoreOutcome($op,'unknown',$known??[],$control??null);}catch(Throwable){}}error_log('GitPosted album private panel: '.$e::class);$this->telegram->sendMessage($actor,'Не удалось подтвердить панель альбома. Данные и текущие изменения сохранены; автоматического повтора нет.');}
    }
    private function retireTelegramAlbumPrivateControl(array$op,int$newControl):void
    {
        $class=PostedTelegramPublishedAlbumPanel::class;$key=$class::opKey($op['actor_id'],$op['post_id'],$op['nonce']);$this->pdo->beginTransaction();
        try{$r=$class::read($this->pdo,$key,true);$current=$class::read($this->pdo,$class::key($op['actor_id'],$op['post_id']),true);if($r===null||$current===null||$current['nonce']!==$op['nonce']||$r['state']!=='shown'||$r['control_id']!==$newControl||$r['retirement']!=='none'){$this->pdo->rollBack();return;}$r['retirement']='sending';$class::put($this->pdo,$key,$r);$class::put($this->pdo,$class::key($op['actor_id'],$op['post_id']),$r);$this->pdo->commit();}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        $done=false;try{$response=$this->telegram->deleteMessage($op['actor_id'],$op['old_control_id']);$done=($response['ok']??null)===true&&($response['result']??null)===true;}catch(Throwable){}
        $this->pdo->beginTransaction();try{$r=$class::read($this->pdo,$key,true);if($r!==null&&$r['retirement']==='sending'){$r['retirement']=$done?'done':'unknown';$class::put($this->pdo,$key,$r);$current=$class::read($this->pdo,$class::key($op['actor_id'],$op['post_id']),true);if($current!==null&&$current['nonce']===$op['nonce'])$class::put($this->pdo,$class::key($op['actor_id'],$op['post_id']),$r);}$this->pdo->commit();}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
}
