<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

trait PostedTelegramPublishedAlbumEditorUi
{
    /** Route before the legacy generic callback can clear a newer album input. */
    private function handleTelegramPublishedAlbumCallback(array $callback): bool
    {
        if($this->handleTelegramAlbumPanelEntry($callback))return true;
        $data=$callback['data']??null;if(!is_string($data)||!str_starts_with($data,'edit:'))return false;
        if(preg_match('/\Aedit:([a-z_]+):([1-9][0-9]{0,18})(?::[^\x00-\x20]*)?\z/D',$data,$m)!==1||(string)(int)$m[2]!==$m[2])return str_starts_with($data,'edit:album_');
        $post=$this->post((int)$m[2]);if(!is_array($post)||!$this->telegramAlbumEditCandidate($post))return str_starts_with($data,'edit:album_');
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||!$this->isKnownActiveUser($this->user($actor))||!$this->can($actor,(int)$post['channel_id'],'manage_posts')){$this->answerCallback($callback,'Пост недоступен.');return true;}
        $context=$this->telegramAlbumPanelTarget($callback,$m[1]);
        if(in_array($m[1],['media','album_back'],true)&&$context===null){$this->answerCallback($callback,'Откройте редактор в личном чате бота.');return true;}
        $this->answerCallback($callback);$previous=$this->telegramPublishedAlbumPanelContext;$this->telegramPublishedAlbumPanelContext=$context;
        try{$this->handleEditCallback($actor,$data);}finally{$this->telegramPublishedAlbumPanelContext=$previous;}return true;
    }
    private function telegramAlbumEditCandidate(array $post): bool
    {
        $ids=$post['telegram_message_ids']??null;if(is_string($ids))$ids=json_decode($ids,true);if(is_array($ids)&&count($ids)>1)return true;
        $media=$post['media_json']??null;if(is_string($media))$media=json_decode($media,true);if(!is_array($media)||count($media)<2)return false;
        // A paid album is one real paid-media envelope. Its existing single-
        // message caption backend must retain its guarded behavior unchanged.
        try{if(PostedTelegramPostOptions::load($this->pdo,$post)['paid_media_stars']!==null)return false;}catch(Throwable){}
        return true;
    }
    private function telegramPublishedAlbumEditable(array $post): bool
    {
        if(!$this->telegramAlbumEditCandidate($post))return false;
        try{$channel=$this->channel((int)$post['channel_id']);if(!is_array($channel))return false;PostedTelegramPublishedAlbumEditor::descriptor($this->pdo,$post,$channel,PostedTelegramPostOptions::load($this->pdo,$post));return true;}catch(Throwable){return false;}
    }
    private function telegramAlbumEditBusy(int $postId,bool $lock=false): bool
    {return PostedTelegramPublishedAlbumEditor::active(PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),$lock));}
    private function telegramAlbumEditSessionLocked(int $actor,int $post): array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('album_edit_requires_transaction');
        $s=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1 FOR UPDATE');$s->execute([$actor,$post]);$r=$s->fetch();if(!is_array($r))throw new RuntimeException('album_edit_session_missing');return$r;
    }
    private function telegramAlbumEditDescriptorLocked(array $post,array $channel): array
    {
        PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$channel);
        return PostedTelegramPublishedAlbumEditor::descriptor($this->pdo,$post,$channel,PostedTelegramPostOptions::load($this->pdo,$post),true);
    }
    private function telegramAlbumEditStageLocked(int $actor,array $post,array $channel,array $document): array
    {
        $album=$this->telegramAlbumEditDescriptorLocked($post,$channel);$base=PostedTelegramPublishedAlbumEditor::hash($document['base']);$key=PostedTelegramPublishedAlbumEditor::stageKey($actor,(int)$post['id']);
        $stage=PostedTelegramPublishedAlbumEditor::read($this->pdo,$key,true);
        if($stage===null){$stage=PostedTelegramPublishedAlbumEditor::stage($album,$actor,$base,$album['options']['media_spoiler']);PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,$key,$stage);}
        else$stage=PostedTelegramPublishedAlbumEditor::validateStage($stage,$album,$actor,$base);
        return$stage;
    }
    private function telegramStartAlbumEditLocked(int $actor,array $post,array $channel): void
    {
        if(!$this->telegramAlbumEditCandidate($post))return;if($this->telegramAlbumEditBusy((int)$post['id'],true))throw new RuntimeException('album_edit_operation_pending');
        $session=$this->telegramAlbumEditSessionLocked($actor,(int)$post['id']);$document=$this->editSessionDocument($session,$post);
        if($this->editablePostSnapshot($post)!==$document['base'])throw new RuntimeException('album_edit_session_stale');$this->telegramAlbumEditStageLocked($actor,$post,$channel,$document);
    }
    private function telegramStageAlbumEditChangesLocked(int $actor,array $post,array $channel,array $changes): bool
    {
        if(!$this->telegramAlbumEditCandidate($post))return false;
        if(array_diff(array_keys($changes),['text','text_entities_json','pin_after_publish'])!==[])throw new InvalidArgumentException('Для альбома можно изменить описание, спойлер и закрепление.');
        if($this->telegramAlbumEditBusy((int)$post['id'],true))throw new RuntimeException('album_edit_operation_pending');
        $session=$this->telegramAlbumEditSessionLocked($actor,(int)$post['id']);$document=$this->editSessionDocument($session,$post);
        if($this->editablePostSnapshot($post)!==$document['base'])throw new RuntimeException('album_edit_session_stale');$this->telegramAlbumEditStageLocked($actor,$post,$channel,$document);
        $document['draft']=$this->editablePostSnapshot(array_replace($document['draft'],$changes));
        $this->pdo->prepare('UPDATE posted_edit_sessions SET snapshot_json=? WHERE user_id=? AND post_id=?')->execute([$this->encodeJson($document),$actor,(int)$post['id']]);return true;
    }
    private function telegramAlbumEditView(int $actor,int $postId): ?array
    {
        $stored=$this->post($postId);if(!is_array($stored)||!$this->telegramAlbumEditCandidate($stored)||!$this->can($actor,(int)$stored['channel_id'],'manage_posts'))return null;
        $channel=$this->channel((int)$stored['channel_id']);if(!is_array($channel))return null;
        $album=PostedTelegramPublishedAlbumEditor::descriptor($this->pdo,$stored,$channel,PostedTelegramPostOptions::load($this->pdo,$stored));
        $s=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1');$s->execute([$actor,$postId]);$session=$s->fetch();if(!is_array($session))return null;
        $document=$this->editSessionDocument($session,$stored);$stage=PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::stageKey($actor,$postId));if($stage===null)return null;
        PostedTelegramPublishedAlbumEditor::validateStage($stage,$album,$actor,PostedTelegramPublishedAlbumEditor::hash($document['base']));
        return['stored'=>$stored,'post'=>$this->postWithEditableSnapshot($stored,$document['draft']),'stage'=>$stage,'revision'=>substr(PostedTelegramPublishedAlbumEditor::hash([$document,$stage]),0,16)];
    }
    private function showTelegramPublishedAlbumEditor(int $actor,int $postId,bool $mediaMenu=false): bool
    {
        $stored=$this->post($postId);if(!is_array($stored)||!$this->telegramAlbumEditCandidate($stored))return false;
        try{
            // Existing valid v2 sessions predate this sidecar. Adopt only under
            // the normal current actor/channel/post locks and exact base proof.
            if(PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::stageKey($actor,$postId))===null){
                $prepared=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor):bool{$this->telegramStartAlbumEditLocked($actor,$post,$channel);return true;},false,true);
                if($prepared!==true)throw new RuntimeException('album_edit_session_unavailable');
            }
            $view=$this->telegramAlbumEditView($actor,$postId);if($view===null)throw new RuntimeException('album_edit_session_unavailable');
            $rows=$mediaMenu?[[['text'=>($view['stage']['spoiler']?'✅':'☑️').' Спойлер','callback_data'=>'edit:album_spoiler:'.$postId.':'.($view['stage']['spoiler']?0:1).':'.$view['revision']]],
                    [['text'=>'← Назад','callback_data'=>'edit:album_back:'.$postId]]]:[
                [['text'=>'Медиа','callback_data'=>'edit:media:'.$postId],['text'=>'Изменить описание','callback_data'=>'edit:text:'.$postId]],
                [['text'=>(!empty($view['post']['pin_after_publish'])?'✅':'☑️').' Закрепить','callback_data'=>'edit:pin:'.$postId.':'.(empty($view['post']['pin_after_publish'])?1:0)]],
                [['text'=>'💾 Сохранить изменения','callback_data'=>'edit:save:'.$postId]],
                [['text'=>'← Назад','callback_data'=>'edit:cancel:'.$postId]],
            ];$this->renderTelegramPublishedAlbumPanel($actor,$postId,$mediaMenu,$view,$rows);
        }catch(Throwable){$this->telegram->sendMessage($actor,'Альбом или сессия редактирования изменились. Откройте пост заново.');}return true;
    }
    private function handleTelegramPublishedAlbumEditCallback(int $actor,int $postId,string $action,array $parts): bool
    {
        $stored=$this->post($postId);if(!is_array($stored)||!$this->telegramAlbumEditCandidate($stored))return false;
        if($action==='album_resume'){$this->saveTelegramPublishedAlbumEdit($actor,$postId,(string)($parts[3]??''));return true;}
        try{$view=$this->telegramAlbumEditView($actor,$postId);if($view===null){$this->telegram->sendMessage($actor,'Сессия редактирования устарела. Откройте пост заново.');return true;}}
        catch(Throwable){$this->telegram->sendMessage($actor,'Альбом или сессия редактирования изменились. Откройте пост заново.');return true;}
        if($action==='save'){$this->saveTelegramPublishedAlbumEdit($actor,$postId);return true;}
        if($action==='pin'){
            $desired=$parts[3]??null;if(!in_array($desired,['0','1'],true))return true;
            if($this->telegramStagePublishedAlbumUi($actor,$postId,['pin_after_publish'=>(int)$desired]))$this->showTelegramPublishedAlbumEditor($actor,$postId);return true;
        }
        if(in_array($action,['media','album_back'],true))return$this->showTelegramPublishedAlbumEditor($actor,$postId,$action==='media');
        if($action==='album_spoiler'){
            $desired=$parts[3]??null;$revision=$parts[4]??null;if(!in_array($desired,['0','1'],true)||!is_string($revision)||preg_match('/\A[a-f0-9]{16}\z/D',$revision)!==1)return true;
            try{$ok=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId,$desired,$revision):bool{
                if($this->telegramAlbumEditBusy($postId,true))return false;$session=$this->telegramAlbumEditSessionLocked($actor,$postId);$document=$this->editSessionDocument($session,$post);$stage=$this->telegramAlbumEditStageLocked($actor,$post,$channel,$document);
                if(!hash_equals(substr(PostedTelegramPublishedAlbumEditor::hash([$document,$stage]),0,16),$revision))return false;$stage['spoiler']=$desired==='1';PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,PostedTelegramPublishedAlbumEditor::stageKey($actor,$postId),$stage);return true;
            },false,true);if($ok===true)$this->showTelegramPublishedAlbumEditor($actor,$postId,true);else$this->telegram->sendMessage($actor,'Настройка устарела или сохранение ещё не завершено.');}catch(Throwable){$this->telegram->sendMessage($actor,'Настройка устарела или сохранение ещё не завершено.');}return true;
        }
        if($action==='text'){
            if($this->telegramAlbumEditBusy($postId)){$this->telegramAlbumEditPendingNotice($actor,$postId);return true;}
            if(!$this->telegramPublishedAlbumEditable($stored)||!$this->can($actor,(int)$stored['channel_id'],'manage_posts'))return true;
            $this->setState($actor,'await_edit_text',['post_id'=>$postId]);$this->telegram->sendMessage($actor,'Отправьте новое описание для поста.',['reply_markup'=>['inline_keyboard'=>[
                [['text'=>'Удалить описание','callback_data'=>'edit:album_clear_text:'.$postId]],[['text'=>'← Назад','callback_data'=>'edit:album_back:'.$postId]],
            ]]]);return true;
        }
        if($action==='album_clear_text'){if($this->telegramStagePublishedAlbumUi($actor,$postId,['text'=>null,'text_entities_json'=>[]])){$this->telegramAlbumClearOwnInput($actor,$postId);$this->showTelegramPublishedAlbumEditor($actor,$postId);}return true;}
        if($action==='buttons'){$this->telegram->sendMessage($actor,'Кнопки недоступны для альбомов.');return true;}
        if($action==='cancel'){
            if($this->telegramAlbumEditBusy($postId)){$this->telegramAlbumEditPendingNotice($actor,$postId);return true;}
            $this->rollbackEditSession($actor,$postId);$this->telegramAlbumClearOwnInput($actor,$postId);$this->telegram->sendMessage($actor,'Изменение отменено.');return true;
        }
        return true;
    }
    private function telegramStagePublishedAlbumUi(int $actor,int $postId,array $changes): bool
    {
        try{$ok=$this->withTelegramManagedPostLock($actor,$postId,['sent'],fn(array$post,array$channel):bool=>$this->telegramStageAlbumEditChangesLocked($actor,$post,$channel,$changes),false,true);if($ok===true)return true;}catch(Throwable){}
        $this->telegram->sendMessage($actor,'Настройка устарела или сохранение ещё не завершено.');return false;
    }
    private function telegramAlbumClearOwnInput(int $actor,int $postId,?array $before=null): void
    {
        if($before===null){$s=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? LIMIT 1');$s->execute([$actor]);$r=$s->fetch();$before=is_array($r)?$r:null;}
        if(!is_array($before)||!in_array($before['state']??null,['await_edit_text','await_edit_media','await_edit_buttons'],true))return;
        $data=json_decode((string)($before['state_data']??''),true);if(!is_array($data)||(int)($data['post_id']??0)!==$postId)return;
        $this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state=? AND state_data <=> CAST(? AS JSON)')->execute([$actor,$before['state'],$before['state_data']]);
    }
    private function telegramAlbumEditPendingNotice(int $actor,int $postId): void
    {
        $op=PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId));$extra=[];
        if(is_array($op)){PostedTelegramPublishedAlbumEditor::validateOperation($op);if($op['plan']['actor_id']===$actor&&$op['state']==='completed'){$this->telegram->sendMessage($actor,'Изменения сохранены.');return;}if($op['plan']['actor_id']===$actor&&$op['state']==='blocked'&&is_string($op['resume_nonce']))$extra=['reply_markup'=>['inline_keyboard'=>[[['text'=>'Повторить сохранение','callback_data'=>'edit:album_resume:'.$postId.':'.$op['resume_nonce']]]]]];}
        $this->telegram->sendMessage($actor,'Сохранение альбома не завершено. Уже применённые части сохранены; новая публикация не создавалась. Проверьте альбом и явно повторите сохранение при необходимости.',$extra);
    }
    private function telegramAlbumEditCheckOperationLocked(int $actor,array $post,array $channel,array $record): array
    {
        PostedTelegramPublishedAlbumEditor::validateOperation($record);$p=$record['plan'];$album=$this->telegramAlbumEditDescriptorLocked($post,$channel);
        $session=$this->telegramAlbumEditSessionLocked($actor,(int)$post['id']);$document=$this->editSessionDocument($session,$post);
        if($p['actor_id']!==$actor||$p['post_id']!==(int)$post['id']||$p['channel_id']!==(int)$channel['id']||$p['album_hash']!==$album['hash']||$p['session_hash']!==PostedTelegramPublishedAlbumEditor::hash($document)||$this->editablePostSnapshot($post)!==$document['base'])throw new RuntimeException('album_edit_operation_stale');
        $stage=$this->telegramAlbumEditStageLocked($actor,$post,$channel,$document);if($stage['spoiler']!==$p['spoiler'])throw new RuntimeException('album_edit_stage_stale');
        $payload=$this->effectivePostPayload($this->postWithEditableSnapshot($post,$document['draft']));
        $expected=PostedTelegramPublishedAlbumEditor::plan($album,$actor,$p['session_hash'],$document['draft'],$payload,$stage['spoiler'],
            $document['base']['text']!==$document['draft']['text']||$document['base']['text_entities_json']!==$document['draft']['text_entities_json'],
            $document['base']['pin_after_publish']!==$document['draft']['pin_after_publish']);
        if(!hash_equals($expected['plan_hash'],$record['plan_hash']))throw new RuntimeException('album_edit_plan_changed');
        return[$album,$document];
    }
    private function saveTelegramPublishedAlbumEdit(int $actor,int $postId,?string $resume=null): bool
    {
        $post=$this->post($postId);if(!is_array($post)||!$this->telegramAlbumEditCandidate($post))return false;
        if($this->pdo->inTransaction())throw new LogicException('album_edit_transport_requires_no_outer_transaction');
        $input=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? LIMIT 1');$input->execute([$actor]);$inputBefore=$input->fetch();
        $lockName='gitposted_pin_'.substr(hash('sha256',(string)$post['channel_id']),0,32);$lock=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1){$this->telegram->sendMessage($actor,'В канале завершается закрепление или удаление. Повторите сохранение через несколько секунд.');return true;}
        try{
            $record=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId,$resume):?array{
                $key=PostedTelegramPublishedAlbumEditor::operationKey($postId);$old=PostedTelegramPublishedAlbumEditor::read($this->pdo,$key,true);
                if(PostedTelegramPublishedAlbumEditor::active($old)){
                    $this->telegramAlbumEditCheckOperationLocked($actor,$post,$channel,$old);
                    // The channel advisory lock proves the prior saver is no
                    // longer executing here. A crash leaves an unknown intent;
                    // first show a NEW explicit resume token, never replay it.
                    if($old['state']==='applying'){
                        foreach($old['receipts']as&$receipt)if($receipt['state']==='sending'){$receipt['state']='unknown';$receipt['finished_at']=time();}unset($receipt);
                        $old['state']='blocked';$old['resume_nonce']=bin2hex(random_bytes(12));PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,$key,$old);return null;
                    }
                    if($resume===null||$old['state']!=='blocked'||!is_string($old['resume_nonce'])||!hash_equals($old['resume_nonce'],$resume))return null;
                    $old['state']='applying';$old['resume_nonce']=null;PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,$key,$old);return$old;
                }
                if($resume!==null)return null;$album=$this->telegramAlbumEditDescriptorLocked($post,$channel);$session=$this->telegramAlbumEditSessionLocked($actor,$postId);$document=$this->editSessionDocument($session,$post);
                if($this->editablePostSnapshot($post)!==$document['base'])throw new RuntimeException('album_edit_session_stale');
                foreach($document['base']as$key=>$value)if(!in_array($key,['text','text_entities_json','pin_after_publish'],true)&&$document['draft'][$key]!==$value)throw new InvalidArgumentException('album_edit_unsupported_change');
                $stage=$this->telegramAlbumEditStageLocked($actor,$post,$channel,$document);$edited=$this->postWithEditableSnapshot($post,$document['draft']);$payload=$this->effectivePostPayload($edited);
                if(PostedTelegramTransportLayout::characters((string)$payload['text'])>self::PHOTO_CAPTION_LIMIT)throw new InvalidArgumentException('Подпись к медиа при редактировании через Bot API может быть не длиннее 1024 символов.');
                $captionChanged=$document['base']['text']!==$document['draft']['text']||$document['base']['text_entities_json']!==$document['draft']['text_entities_json'];
                $op=PostedTelegramPublishedAlbumEditor::plan($album,$actor,PostedTelegramPublishedAlbumEditor::hash($document),$document['draft'],$payload,$stage['spoiler'],$captionChanged,$document['base']['pin_after_publish']!==$document['draft']['pin_after_publish']);
                PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),$op);return$op;
            },false,true);
            if(!is_array($record)){$this->telegramAlbumEditPendingNotice($actor,$postId);return true;}
            foreach($record['plan']['steps']as$index=>$step){
                $intent=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId,$record,$index):?string{
                    $op=PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),true);if($op===null||$op['plan_hash']!==$record['plan_hash'])throw new RuntimeException('album_edit_operation_stale');$this->telegramAlbumEditCheckOperationLocked($actor,$post,$channel,$op);
                    if($op['receipts'][$index]['state']==='done')return null;if($op['state']!=='applying')throw new RuntimeException('album_edit_operation_blocked');
                    $nonce=bin2hex(random_bytes(12));$op['receipts'][$index]=['state'=>'sending','intent'=>$nonce,'finished_at'=>null];PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),$op);return$nonce;
                },false,true);
                if($intent===null){$current=PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId));if(($current['receipts'][$index]['state']??null)==='done')continue;throw new RuntimeException('album_edit_access_changed');}
                if($this->pdo->inTransaction())throw new LogicException('album_edit_transport_outside_sql');$outcome='unknown';
                try{$response=$this->telegram->call($step['method'],$step['payload']);if(PostedTelegramPublishedAlbumEditor::knownResponse($step,$response))$outcome='done';}
                catch(PostedTelegramRequestException$e){$description=mb_strtolower(trim($e->getMessage()));$accepted=['bad request: message is not modified','message is not modified'];if(in_array($step['method'],['pinChatMessage','unpinChatMessage'],true))$accepted=array_merge($accepted,['bad request: message is already pinned','bad request: message is not pinned']);if(!$e->deliveryMayBeUnknown())$outcome=in_array($description,$accepted,true)||str_starts_with($description,'bad request: message is not modified:')?'done':'failed';}
                catch(Throwable){$outcome='unknown';}
                if(!$this->telegramAlbumEditRecordResult($postId,$record['plan_hash'],$index,$intent,$outcome)||$outcome!=='done'){$this->telegramAlbumEditPendingNotice($actor,$postId);return true;}
            }
            $done=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId,$record):bool{
                $op=PostedTelegramPublishedAlbumEditor::read($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),true);if($op===null||$op['plan_hash']!==$record['plan_hash'])return false;$this->telegramAlbumEditCheckOperationLocked($actor,$post,$channel,$op);
                foreach($op['receipts']as$receipt)if($receipt['state']!=='done')return false;$draft=$op['plan']['draft'];
                $this->pdo->prepare("UPDATE posted_posts SET text=?,text_entities_json=?,pin_after_publish=? WHERE id=? AND status='sent'")->execute([$draft['text'],$draft['text_entities_json']!==[]?$this->encodeJson($draft['text_entities_json']):null,$draft['pin_after_publish'],$postId]);
                PostedTelegramPostOptions::saveLocked($this->pdo,$post,['media_spoiler'=>$op['plan']['spoiler']]);$op['state']='completed';$op['resume_nonce']=null;PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,PostedTelegramPublishedAlbumEditor::operationKey($postId),$op);
                $this->pdo->prepare('DELETE FROM posted_edit_sessions WHERE user_id=? AND post_id=?')->execute([$actor,$postId]);PostedTelegramPublishedAlbumEditor::removeStageLocked($this->pdo,$actor,$postId);return true;
            },false,true);
            if($done===true){try{
                $this->telegramAlbumClearOwnInput($actor,$postId,is_array($inputBefore)?$inputBefore:[]);
                $this->telegram->sendMessage($actor,'Изменения сохранены.');
            }catch(Throwable){}return true;}
            $this->telegramAlbumEditPendingNotice($actor,$postId);
        }catch(Throwable$e){$this->telegram->sendMessage($actor,$e instanceof InvalidArgumentException&&str_starts_with($e->getMessage(),'Подпись к медиа')?$e->getMessage():'Сохранение не завершено: права, публикация или сессия изменились. Применённые части не отправляются заново автоматически.');}
        finally{$s=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lockName]);}return true;
    }
    /** Persist history even if an actor loses rights after this exact already-recorded request. */
    private function telegramAlbumEditRecordResult(int $postId,string $planHash,int $index,string $intent,string $outcome): bool
    {
        if($this->pdo->inTransaction())throw new LogicException('album_edit_ack_requires_own_transaction');$key=PostedTelegramPublishedAlbumEditor::operationKey($postId);$hint=PostedTelegramPublishedAlbumEditor::read($this->pdo,$key);if(!is_array($hint))return false;
        PostedTelegramPublishedAlbumEditor::validateOperation($hint);
        $this->pdo->beginTransaction();try{
            $s=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$hint['plan']['channel_id']]);if($s->fetchColumn()===false)throw new RuntimeException('album_edit_channel_missing');
            $s=$this->pdo->prepare('SELECT id FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$s->execute([$postId,$hint['plan']['channel_id']]);if($s->fetchColumn()===false)throw new RuntimeException('album_edit_post_missing');
            $op=PostedTelegramPublishedAlbumEditor::read($this->pdo,$key,true);if($op===null)throw new RuntimeException('album_edit_operation_missing');PostedTelegramPublishedAlbumEditor::validateOperation($op);
            if($op['plan_hash']!==$planHash||($op['receipts'][$index]['intent']??null)!==$intent||($op['receipts'][$index]['state']??null)!=='sending'){$this->pdo->commit();return false;}
            if(!in_array($outcome,['done','unknown','failed'],true))throw new LogicException('album_edit_outcome');$op['receipts'][$index]['state']=$outcome;$op['receipts'][$index]['finished_at']=time();
            if($outcome!=='done'){$op['state']='blocked';$op['resume_nonce']=bin2hex(random_bytes(12));}PostedTelegramPublishedAlbumEditor::putLocked($this->pdo,$key,$op);$this->pdo->commit();return true;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
}
