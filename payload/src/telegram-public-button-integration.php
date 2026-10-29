<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-public-button-layout.php';
require_once __DIR__.'/telegram-public-button-edit.php';

trait GitPostedTelegramPublicButtonIntegration
{
    /** An old editor callback may not replace input or cancel a retained Save
     * session between its committed intent and exact-MID transport outcome.
     */
    private function handleEditCallback(int$actor,string$data):void
    {
        if($this->pdo->inTransaction())throw new LogicException('public_button_editor_outer_transaction');
        $parts=explode(':',$data);$postId=(int)($parts[2]??0);$post=$this->post($postId);
        if(!is_array($post)){$this->handleLegacyEditCallback($actor,$data);return;}
        $name='gitposted_pin_'.substr(hash('sha256',(string)$post['channel_id']),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return;
        try{
            $r=PostedTelegramPublicButtonEdit::current($this->pdo,$postId);
            if($r!==null&&PostedTelegramPublicButtonLayout::activeEditState($r['header']['state'])){if($r['plan']['actor_id']===$actor)$this->telegramPublicEditNotice($actor,$r);else$this->telegram->sendMessage($actor,'Сохранение поста ещё не завершено. Текущий ввод не изменён.');return;}
            $this->handleLegacyEditCallback($actor,$data);
        }finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
    }
    /** Serialize every public callback with the existing Save/pin/delete lock.
     * The legacy body remains byte-identical and receives no new layout choice.
     */
    private function handlePublicInteractiveButton(array$callback,string$data):void
    {
        if($this->pdo->inTransaction())throw new LogicException('public_button_callback_outer_transaction');
        if(str_starts_with($data,'reaction:')&&($callback['message']['chat']['type']??null)==='private'){$this->answerCallback($callback);return;}
        $parts=explode(':',$data,3);$postId=(int)($parts[1]??0);$hint=$this->post($postId);
        if(!is_array($hint)||$postId<1){$this->handleLegacyPublicInteractiveButton($callback,$data);return;}
        $name='gitposted_pin_'.substr(hash('sha256',(string)$hint['channel_id']),0,32);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);
        if((int)$q->fetchColumn()!==1){$this->answerCallback($callback,'Пост обновляется. Повторите после завершения сохранения.');return;}
        try{if(!$this->handleVersionedTelegramPublicButton($callback,$data,$postId))$this->handleLegacyPublicInteractiveButton($callback,$data);}
        finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
    }
    /** The outer entry holds the same advisory lock used by Save/pin/delete. */
    private function handleVersionedTelegramPublicButton(array$callback,string$data,int$postId):bool
    {
        if($this->pdo->inTransaction())throw new LogicException('public_button_callback_outer_transaction');
        $actor=$callback['from']['id']??null;if(!is_int($actor)||$actor<1)return false;
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT channel_id FROM posted_posts WHERE id=? LIMIT 1');$q->execute([$postId]);$cid=(int)($q->fetchColumn()?:0);
            if($cid<1){$this->pdo->commit();return false;}
            $q=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$cid]);$channel=$q->fetch();
            $q=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId,$cid]);$post=$q->fetch();
            if(!is_array($channel)||!is_array($post)){$this->pdo->commit();return false;}
            $operation=PostedTelegramPublicButtonEdit::current($this->pdo,$postId,true);
            if($operation!==null&&PostedTelegramPublicButtonLayout::activeEditState($operation['header']['state'])){$this->pdo->commit();$this->answerCallback($callback,'Пост обновляется. Повторите после завершения сохранения.');return true;}
            $hasReceipt=PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::receiptKey($postId),true)!==null;
            $hasMarker=PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::attemptKey($postId),true)!==null;
            if(!$hasReceipt&&!$hasMarker){$this->pdo->commit();return false;}
            $frozen=PostedTelegramPostOptions::freeze($this->pdo,$post);$receipt=PostedTelegramPublicButtonLayout::receiptLocked($this->pdo,$frozen,$channel);if($receipt===null)throw new RuntimeException('public_button_receipt_required');
            $message=$callback['message']??null;
            if(!is_array($message)||(string)($message['chat']['id']??'')!==$receipt['chat_id']||($message['message_id']??null)!==$receipt['target_mid']||preg_match('/\A(reaction|hidden):([1-9][0-9]{0,18}):([A-Za-z0-9_]+)\z/D',$data,$parts)!==1||(string)$postId!==$parts[2])throw new RuntimeException('public_button_callback_binding');
            $button=null;foreach(PostedTelegramPublicButtonLayout::rawRows($post)as$row)foreach((array)$row as$b)if(is_array($b)&&($b['callback_data']??null)===$data){if($button!==null)throw new RuntimeException('public_button_duplicate_callback');$button=$b;}
            if($button===null)throw new RuntimeException('public_button_stale_callback');$kind=$parts[1];$key=$parts[3];
            if($kind==='hidden'){
                if(!is_string($button['hidden_text']??null)||!mb_check_encoding($button['hidden_text'],'UTF-8'))throw new RuntimeException('public_button_hidden_payload');
                $hidden=$button['hidden_text'];$chat=$receipt['chat_id'];$this->pdo->commit();
                try{$r=$this->telegram->call('getChatMember',['chat_id'=>$chat,'user_id'=>$actor]);$status=$r['result']['status']??null;if(($r['ok']??null)!==true||!in_array($status,['member','administrator','creator'],true)){$this->answerCallback($callback,'Сначала подпишитесь на канал.');return true;}}
                catch(Throwable){$this->answerCallback($callback,'Не удалось проверить подписку.');return true;}
                $text=mb_strlen($hidden,'UTF-8')>self::HIDDEN_TEXT_LIMIT?mb_substr($hidden,0,self::HIDDEN_TEXT_LIMIT-1,'UTF-8').'…':$hidden;
                $this->telegram->call('answerCallbackQuery',['callback_query_id'=>(string)($callback['id']??''),'text'=>$text,'show_alert'=>true]);return true;
            }
            if(($button['reaction_key']??null)!==$key)throw new RuntimeException('public_button_reaction_binding');
            $callbackId=$callback['id']??null;if(!is_string($callbackId)||$callbackId===''||strlen($callbackId)>256||preg_match('/[\x00-\x20\x7f]/',$callbackId)===1)throw new RuntimeException('public_button_callback_identity');$q=$this->pdo->prepare('SELECT reaction_key,last_callback_id FROM posted_reactions WHERE post_id=? AND user_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId,$actor]);$old=$q->fetch();$current=is_array($old)?(string)$old['reaction_key']:'';
            $duplicate=$callbackId!==''&&is_array($old)&&hash_equals((string)($old['last_callback_id']??''),$callbackId);$toast='';
            if(!$duplicate){
                $next=$current===$key?'':$key;$toast=$next===''?'You removed reaction from the post.':($current===''?'Вы отреагировали на пост.':'You changed post reaction.');
                $this->pdo->prepare('INSERT INTO posted_reactions(post_id,user_id,reaction_key,last_callback_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE reaction_key=VALUES(reaction_key),last_callback_id=VALUES(last_callback_id)')->execute([$postId,$actor,$next,$callbackId===''?null:$callbackId]);
            }
            if($duplicate){$this->pdo->commit();$this->answerCallback($callback);return true;}
            $counts=[];$q=$this->pdo->prepare("SELECT reaction_key,COUNT(*) AS cnt FROM posted_reactions WHERE post_id=? AND reaction_key<>'' GROUP BY reaction_key");$q->execute([$postId]);foreach($q->fetchAll()as$row)$counts[(string)$row['reaction_key']]=(int)$row['cnt'];
            $payload=$this->effectivePostPayload($post);$rows=PostedTelegramPublicButtonLayout::reactionCounts((array)$payload['buttons'],$counts,1);$request=['chat_id'=>$receipt['chat_id'],'message_id'=>$receipt['target_mid'],'reply_markup'=>['inline_keyboard'=>$this->buttonsMarkup($rows)]];
            $this->pdo->commit();
            // A user callback performs at most one idempotent exact-MID refresh.
            // There is no retry loop and the advisory lock still excludes Save.
            try{$this->telegram->call('editMessageReplyMarkup',$request);}catch(Throwable$e){error_log('GitPosted public reaction markup outcome: '.$e::class);}
            $this->answerCallback($callback,$toast);return true;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('GitPosted public button held: '.$e::class);$this->answerCallback($callback,'Пост изменился или обновляется. Откройте актуальную публикацию.');return true;}
    }
    private function handleTelegramPublicEditStatusCallback(array$callback):bool
    {
        $data=$callback['data']??null;if(!is_string($data)||preg_match('/\Apsedit:save:([1-9][0-9]{0,18}):[a-f0-9]{16}\z/D',$data,$m)!==1||(string)(int)$m[1]!==$m[1])return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor)return false;
        if($this->pdo->inTransaction())throw new LogicException('public_button_status_outer_transaction');
        $postId=(int)$m[1];try{$old=PostedTelegramPublicButtonEdit::current($this->pdo,$postId);}catch(Throwable){$this->answerCallback($callback,'Сохранение требует проверки. Ваши данные не изменены.');return true;}if($old===null||!PostedTelegramPublicButtonLayout::activeEditState($old['header']['state']))return false;
        $this->answerCallback($callback);$post=$this->post($postId);if(!is_array($post))return true;$name='gitposted_pin_'.substr(hash('sha256',(string)$post['channel_id']),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return true;
        try{
            $held=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$p,array$c)use($actor,$postId):array{
                $r=PostedTelegramPublicButtonEdit::current($this->pdo,$postId,true);if($r===null||$r['plan']['actor_id']!==$actor||$r['plan']['owner_id']!==(int)$c['added_by'])throw new RuntimeException('public_button_edit_status_denied');
                return PostedTelegramPublicButtonEdit::holdLocked($this->pdo,$postId,$r['plan']['operation_id']);
            },false,true);
            if(is_array($held))$this->telegramPublicEditNotice($actor,$held);
        }catch(Throwable$e){error_log('GitPosted public edit status held: '.$e::class);}
        finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}return true;
    }
    private function telegramPublicButtonsMarkup(array$post,array$buttons):array
    {$mode=PostedTelegramPublicButtonLayout::mode($post);return$this->buttonsMarkup($mode===1?PostedTelegramPublicButtonLayout::reactionCounts($buttons,[],1):$buttons);}
    private function telegramPublicEditContext(array$post,array$document=[]):string
    {$context=['row'=>$post,'options'=>PostedTelegramPostOptions::load($this->pdo,$post)];if(($placement=PostedTelegramPublicButtonEdit::placement($document))!==null)$context['caption_placement']=$placement;return PostedTelegramPublicButtonLayout::hash($context);}
    private function telegramPublicPlacementHint(int$actor,array$post):bool
    {
        if(!class_exists('PostedTelegramCaptionPlacement',false))return false;if(PostedTelegramCaptionPlacement::load($this->pdo,$post)!==null)return true;
        $q=$this->pdo->prepare('SELECT snapshot_json FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1');$q->execute([$actor,(int)$post['id']]);$raw=$q->fetchColumn();if(!is_string($raw))return false;$doc=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($doc)&&array_key_exists('caption_placement',$doc);
    }
    private function telegramPublicEditGuardLocked(int$actor,array$post,array$channel,array$record,bool$needInput=true):array
    {
        // The regular UI view is intentionally fenced while this operation is
        // active. This internal path revalidates the same session without
        // permitting a new renderer, staging mutation, or cancellation.
        if(!$this->pdo->inTransaction()||!$this->telegramPublishedSingleCandidate($post))throw new RuntimeException('public_button_edit_unavailable');
        $q=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor,(int)$post['id']]);$s=$q->fetch();if(!is_array($s))throw new RuntimeException('public_button_edit_no_session');$doc=$this->editSessionDocument($s,$post);
        if($doc['base']!==$this->editablePostSnapshot($post)||PostedTelegramAuthorityPause::blocked($this->pdo,$channel,true))throw new RuntimeException('public_button_edit_stale_or_paused');$pause=PostedTelegramAuthorityPause::read($this->pdo,$channel,true);
        $p=$record['plan'];$staged=$this->postWithEditableSnapshot($post,$doc['draft']);if(array_key_exists('caption_placement',$doc))$staged=PostedTelegramCaptionPlacement::editPost($staged,$doc);$v=['stored'=>$post,'post'=>$staged,'channel'=>$channel,'document'=>$doc,'hash'=>PostedTelegramPublishedSingleUi::hash($doc),'epoch'=>(string)($pause['generation']??'legacy')];
        if($p['actor_id']!==$actor||$p['post_id']!==(int)$post['id']||$p['channel_id']!==(int)$channel['id']||$p['chat_id']!==(string)$channel['chat_id']||$p['owner_id']!==(int)$channel['added_by']||$p['epoch']!==$v['epoch']||!hash_equals($p['session_hash'],$v['hash'])||!hash_equals($p['context_hash'],$this->telegramPublicEditContext($post,$doc))||PostedTelegramPublicButtonLayout::hash($v['document']['base'])!==PostedTelegramPublicButtonLayout::hash($p['base'])||PostedTelegramPublicButtonLayout::hash($v['document']['draft'])!==PostedTelegramPublicButtonLayout::hash($p['draft'])||($needInput&&!$this->telegramPublishedSingleInputMatches($actor,$p['input']['state'],$p['input']['data'])))throw new RuntimeException('public_button_edit_context_changed');
        return$v;
    }
    private function telegramPublicEditNotice(int$actor,array$record):void
    {
        if($record['header']['deletion']!==null){try{$this->telegram->sendMessage($actor,match($record['header']['deletion']['state']){'done'=>'Удаление исходного сообщения подтверждено. Изменения редактора не объявлены сохранёнными; выполняется только локальная фиксация удаления.','running'=>'Удаление исходного сообщения выполняется. Повторное сохранение и реакции приостановлены.',default=>'Результат удаления исходного сообщения не подтверждён. Журналы удаления и изменения сохранены; сохранение и автоматическое повторное удаление не выполняются.'});}catch(Throwable$e){error_log('GitPosted public deletion acknowledgement: '.$e::class);}return;}
        $state=$record['header']['state'];$extra=[];
        $allKnown=array_filter($record['header']['receipts'],static fn(array$r):bool=>$r['state']!=='done')===[];
        if($state==='unknown'&&$record['plan']['actor_id']===$actor)$extra=['reply_markup'=>['inline_keyboard'=>[[['text'=>$allKnown?'Завершить фиксацию':'Проверить и повторить сохранение','callback_data'=>'pbl:resume:'.$record['plan']['post_id'].':'.$record['header']['resume_nonce']]]]]];
        try{$this->telegram->sendMessage($actor,match($state){'unknown'=>$allKnown?'Telegram подтвердил изменение. Локальная фиксация приостановлена; новая публикация не отправлялась. Реакции заблокированы до завершения.':'Результат изменения поста не подтверждён. Новая публикация не отправлялась; реакции заблокированы до разрешения сохранения. Повтор возможен только по вашему явному действию.','rejected'=>'Telegram отклонил изменение поста. Сохранённые данные и ваши изменения в редакторе не удалены.',default=>'Сохранение уже выполняется. Ваши данные сохранены.'},$extra);}catch(Throwable$e){error_log('GitPosted public edit status acknowledgement: '.$e::class);}
    }
    /** null delegates to the byte-identical established deletion path.
     * Only a new active single-photo Save journal uses this durable override.
     */
    private function telegramPublicDeleteOverride(array$hint,string$kind,int$actor=0,?array$confirmation=null):?bool
    {
        if(!in_array($kind,['manual','due'],true)||($kind==='manual'?$actor<1:$actor!==0))throw new InvalidArgumentException('public_button_delete_origin');
        if($this->pdo->inTransaction())throw new LogicException('public_button_delete_outer_transaction');
        $postId=(int)($hint['id']??0);if($postId<1)return null;$old=PostedTelegramPublicButtonEdit::current($this->pdo,$postId);
        if($old===null||!PostedTelegramPublicButtonLayout::activeEditState($old['header']['state']))return null;
        $name='gitposted_pin_'.substr(hash('sha256',(string)$hint['channel_id']),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return false;
        try{
            $this->pdo->beginTransaction();
            $q=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$hint['channel_id']]);$channel=$q->fetch();
            $q=$this->pdo->prepare("SELECT * FROM posted_posts WHERE id=? AND channel_id=? AND status='sent'".($kind==='due'?' AND delete_at IS NOT NULL AND delete_at<=NOW()':'').' LIMIT 1 FOR UPDATE');$q->execute([$postId,(int)$hint['channel_id']]);$post=$q->fetch();
            $r=PostedTelegramPublicButtonEdit::current($this->pdo,$postId,true);
            if(!is_array($channel)||!is_array($post)||$r===null||!PostedTelegramPublicButtonLayout::activeEditState($r['header']['state'])){$this->pdo->commit();return false;}
            // Already known DELETE can finish SQL even after later authority
            // loss. It can never issue another Telegram request.
            if(($r['header']['deletion']['state']??null)==='done'){$this->pdo->commit();return$this->telegramPublicFinishKnownDeletion($r);}
            if($r['header']['deletion']!==null){$this->pdo->commit();if($actor>0)$this->telegramPublicEditNotice($actor,$r);return false;}
            if(empty($channel['is_active'])||(int)$channel['added_by']<1)throw new RuntimeException('public_button_delete_channel_unavailable');
            if($kind==='due'&&(($post['delete_at']??null)!==($hint['delete_at']??null)||(string)($post['delete_after_minutes']??'')!==(string)($hint['delete_after_minutes']??''))){$this->pdo->commit();return false;}
            if($kind==='manual'){
                if(!$this->lockActiveTelegramActor($actor))throw new RuntimeException('public_button_delete_actor_unavailable');
                $q=$this->pdo->prepare('SELECT can_post,can_manage_posts FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor,(int)$channel['id']]);$permission=$q->fetch();
                if(!is_array($permission)||(empty($permission['can_manage_posts'])&&((int)$post['author_id']!==$actor||empty($permission['can_post']))))throw new RuntimeException('public_button_delete_forbidden');
            }
            if($confirmation!==null&&!$this->telegramCpDeleteGuardLocked($actor,$post,$confirmation)){$this->pdo->commit();return false;}
            if(!$this->cancelTelegramPinEffect($postId))throw new RuntimeException('public_button_delete_pin_pending');
            $r=PostedTelegramPublicButtonEdit::beginDeletionLocked($this->pdo,$post,$channel,$kind,$actor);$this->pdo->commit();
            $proof=null;
            try{
                if($this->pdo->inTransaction())throw new LogicException('public_button_delete_transport_inside_sql');
                $response=$this->telegram->call('deleteMessage',$r['header']['deletion']['request']);
                if(($response['ok']??null)===true&&($response['result']??null)===true)$proof=PostedTelegramPublicButtonLayout::hash($response);
            }catch(PostedTelegramRequestException$e){if(!$e->deliveryMayBeUnknown()&&$e->httpStatus()===400&&preg_match('/\ABad Request: message to delete not found\z/iD',$e->getMessage())===1)$proof=PostedTelegramPublicButtonLayout::hash(['already_deleted'=>$r['header']['deletion']['request']]);}
            catch(Throwable$e){error_log('GitPosted public delete outcome: '.$e::class);}
            $this->pdo->beginTransaction();
            try{
                $q=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$r['plan']['channel_id']]);if($q->fetchColumn()===false)throw new RuntimeException('public_button_delete_channel_missing');
                $q=$this->pdo->prepare('SELECT id FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId,$r['plan']['channel_id']]);if($q->fetchColumn()===false)throw new RuntimeException('public_button_delete_post_missing');
                $r=PostedTelegramPublicButtonEdit::settleDeletionLocked($this->pdo,$postId,$r['plan']['operation_id'],$r['header']['deletion']['intent'],$proof);$this->pdo->commit();
            }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
            if($proof!==null)return$this->telegramPublicFinishKnownDeletion($r);
            if($actor>0)$this->telegramPublicEditNotice($actor,$r);return false;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('GitPosted public delete held: '.$e::class);return false;}
        finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
    }
    /** The known deletion proof was committed before this local-only step. */
    private function telegramPublicFinishKnownDeletion(array$known):bool
    {
        if($this->pdo->inTransaction())throw new LogicException('public_button_delete_finalization_outer_transaction');
        $p=$known['plan'];$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$p['channel_id']]);$channel=$q->fetch();
            $q=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$p['post_id'],$p['channel_id']]);$post=$q->fetch();
            $r=PostedTelegramPublicButtonEdit::current($this->pdo,$p['post_id'],true);$ids=is_array($post)?json_decode((string)($post['telegram_message_ids']??''),true,32,JSON_THROW_ON_ERROR):null;
            if(!is_array($channel)||!is_array($post)||$r===null||$r['plan']['operation_id']!==$p['operation_id']||($r['header']['deletion']['state']??null)!=='done'||(string)$channel['chat_id']!==$p['chat_id']||$ids!==$p['delivery']['known_ids']||!in_array($post['status'],['sent','deleted'],true))throw new RuntimeException('public_button_delete_finalization_changed');
            if($post['status']==='sent'){
                $q=$this->pdo->prepare("UPDATE posted_posts SET status='deleted',delete_at=NULL,delete_attempts=0,error_text=NULL WHERE id=? AND channel_id=? AND status='sent'");$q->execute([$p['post_id'],$p['channel_id']]);if($q->rowCount()!==1)throw new RuntimeException('public_button_delete_status_changed');
            }
            $q=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1 FOR UPDATE');$q->execute([$p['actor_id'],$p['post_id']]);$session=$q->fetch();
            if(is_array($session)){
                $doc=$this->editSessionDocument($session,$post);if(PostedTelegramPublishedSingleUi::hash($doc)===$p['session_hash']){
                    $this->pdo->prepare('DELETE FROM posted_edit_sessions WHERE user_id=? AND post_id=?')->execute([$p['actor_id'],$p['post_id']]);$this->telegramPublishedSingleClearInput($p['actor_id'],$p['post_id'],$p['input']);
                }
            }
            PostedTelegramPublicButtonEdit::completeDeletionLocked($this->pdo,$p['post_id'],$p['operation_id']);$this->pdo->commit();return true;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('GitPosted known public deletion finalization held: '.$e::class);return false;}
    }
    private function saveTelegramPublicButtonEdit(int$actor,int$postId):bool
    {
        $hint=$this->post($postId);if(!is_array($hint))return false;PostedTelegramPublicButtonLayout::assertNotComponents($hint);
        $placementHint=$this->telegramPublicPlacementHint($actor,$hint);$versionedHint=$placementHint||PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::attemptKey($postId))!==null||PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::receiptKey($postId))!==null;
        if($this->telegramPublishedSingleSaveHash===null){if(!$versionedHint)return false;$this->telegram->sendMessage($actor,'Откройте актуальный редактор этого поста. Старое сохранение не изменило публикацию; ваши изменения сохранены в редакторе.');return true;}
        if(!$this->telegramPublishedSingleCandidate($hint)){if(!$versionedHint)return false;$this->telegram->sendMessage($actor,'Это изменение пока нельзя безопасно применить к версионному посту. Публикация и ваши изменения сохранены; старый способ сохранения не запускался.');return true;}
        if($this->pdo->inTransaction())throw new LogicException('public_button_edit_outside_transaction');
        $name='gitposted_pin_'.substr(hash('sha256',(string)$hint['channel_id']),0,32);$held=false;
        try{
            $q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1){$this->telegram->sendMessage($actor,'В канале завершается закрепление, удаление или сохранение. Повторите позже; ваши изменения сохранены.');return true;}$held=true;
            $record=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId):array{
                $old=PostedTelegramPublicButtonEdit::current($this->pdo,$postId,true);
                if($old!==null&&PostedTelegramPublicButtonLayout::activeEditState($old['header']['state']))return['active'=>$old];
                $v=$this->telegramPublishedSingleViewLocked($actor,$post,$channel);$input=$this->telegramPublishedSingleSaveInput;
                if(!is_array($input)||!is_int($input['owner_id']??null)||!is_string($input['epoch']??null)||!is_string($input['state']??null)||!is_array($input['data']??null)||!hash_equals($this->telegramPublishedSingleSaveHash,$v['hash'])||$input['owner_id']!==(int)$channel['added_by']||$input['epoch']!==$v['epoch']||!$this->telegramPublishedSingleInputMatches($actor,$input['state'],$input['data']))throw new RuntimeException('public_button_edit_signed_session_changed');
                $placement=PostedTelegramPublicButtonEdit::placement($v['document']);$requiresDurable=$placement!==null;
                $versioned=PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::attemptKey($postId),true)!==null||PostedTelegramPublicButtonLayout::read($this->pdo,PostedTelegramPublicButtonLayout::receiptKey($postId),true)!==null;
                foreach(['media_type','media_file_id','media_json']as$key)if($v['document']['base'][$key]!==$v['document']['draft'][$key]){if($versioned||$requiresDurable)throw new InvalidArgumentException('Замена медиа этого версионного поста пока не применялась. Исходная публикация и новые данные сохранены в редакторе.');return['legacy'=>true];}
                $frozen=PostedTelegramPostOptions::freeze($this->pdo,$post);if($frozen['_telegram_post_options']['paid_media_stars']!==null){if($versioned||$requiresDurable)throw new InvalidArgumentException('Платное медиа не может изменить сохранённый протокол этого поста. Данные не изменены.');return['legacy'=>true];}
                if(!$versioned&&!$requiresDurable&&!PostedTelegramPublicButtonLayout::hasReactions((array)$v['document']['draft']['buttons_json']))return['legacy'=>true];
                if($versioned&&PostedTelegramPublicButtonLayout::receiptLocked($this->pdo,$frozen,$channel)===null)throw new RuntimeException('public_button_versioned_receipt_missing');
                $q=$this->pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$attempt=$q->fetch();if(!is_array($attempt)){if($requiresDurable)throw new RuntimeException('public_button_placement_delivery_missing');return['legacy'=>true];}
                $delivery=PostedTelegramPublicButtonLayout::knownDelivery($frozen,$channel,$attempt);if(count($delivery['known_ids'])!==1){if($requiresDurable)throw new RuntimeException('public_button_placement_delivery_count');return['legacy'=>true];}
                $payload=$this->effectivePostPayload($v['post']);$text=(string)$payload['text'];
                $length=class_exists('PostedTelegramTransportLayout',false)?PostedTelegramTransportLayout::characters($text):$this->telegramUtf16Length($text);
                if($length>self::PHOTO_CAPTION_LIMIT)throw new InvalidArgumentException('Подпись к медиа при редактировании через Bot API может быть не длиннее 1024 символов.');
                $counts=[];$q=$this->pdo->prepare("SELECT reaction_key,COUNT(*) AS cnt FROM posted_reactions WHERE post_id=? AND reaction_key<>'' GROUP BY reaction_key");$q->execute([$postId]);foreach($q->fetchAll()as$row)$counts[(string)$row['reaction_key']]=(int)$row['cnt'];
                $rows=PostedTelegramPublicButtonLayout::reactionCounts((array)$payload['buttons'],$counts,1);
                $caption=['chat_id'=>$delivery['chat_id'],'message_id'=>$delivery['target_mid'],'caption'=>$text,'caption_entities'=>(array)$payload['entities'],'reply_markup'=>['inline_keyboard'=>$this->buttonsMarkup($rows)]];
                $caption=PostedTelegramPublicButtonEdit::placementPayload($v['document'],$caption);
                $plan=PostedTelegramPublicButtonEdit::plan($actor,$channel,$v['epoch'],$this->telegramPublicEditContext($post,$v['document']),$v['hash'],['state'=>$input['state'],'data'=>$input['data']],$v['document'],$delivery,$caption);
                return['created'=>PostedTelegramPublicButtonEdit::createLocked($this->pdo,$plan)];
            },false,true);
            if(!is_array($record)){$this->telegram->sendMessage($actor,'Пост недоступен или доступ изменился. Ваши изменения сохранены.');return true;}
            if(($record['legacy']??false)===true)return false;
            if(isset($record['active'])){$this->telegramPublicEditNotice($actor,$record['active']);return true;}
            $this->runTelegramPublicButtonEdit($actor,$postId,null);return true;
        }catch(Throwable$e){error_log('GitPosted public Save: '.$e::class);$this->telegram->sendMessage($actor,'Не удалось подтвердить сохранение. Данные и текущий ввод сохранены; повторная публикация не отправлялась.');return true;}
        finally{if($held){$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}}
    }
    /** Caller retains the channel advisory lock across every SQL-free RPC. */
    private function runTelegramPublicButtonEdit(int$actor,int$postId,?string$resume):void
    {
        for($iteration=0;$iteration<3;$iteration++){
            $r=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$post,array$channel)use($actor,$postId,$resume):array{
                $r=PostedTelegramPublicButtonEdit::current($this->pdo,$postId,true);if($r===null)throw new RuntimeException('public_button_edit_missing');
                $allKnown=array_filter($r['header']['receipts'],static fn(array$x):bool=>$x['state']!=='done')===[];
                $started=array_filter($r['header']['receipts'],static fn(array$x):bool=>$x['intent']!==null)!==[];
                $this->telegramPublicEditGuardLocked($actor,$post,$channel,$r,!$allKnown&&!$started&&$resume===null);
                $next=PostedTelegramPublicButtonEdit::nextLocked($this->pdo,$postId,$resume);
                if($next['next']!==null)return$next;
                if($next['blocked']===false&&array_filter($next['header']['receipts'],static fn(array$x):bool=>$x['state']!=='done')===[]){
                    $draft=$next['plan']['draft'];$q=$this->pdo->prepare('SELECT * FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);$attempt=$q->fetch();if(!is_array($attempt))throw new RuntimeException('public_button_edit_delivery_changed');
                    $edited=$this->postWithEditableSnapshot($post,$draft);$frozen=PostedTelegramPostOptions::freeze($this->pdo,$edited);
                    $receipt=PostedTelegramPublicButtonLayout::receiptFromKnown($frozen,$channel,$attempt,['kind'=>'save','hash'=>$next['header']['plan_hash']]);
                    $this->pdo->prepare("UPDATE posted_posts SET text=?,text_entities_json=?,buttons_json=?,pin_after_publish=?,autosignature_text=?,autosignature_entities_json=? WHERE id=? AND status='sent'")->execute([$draft['text'],$draft['text_entities_json']===[]?null:$this->encodeJson($draft['text_entities_json']),$draft['buttons_json']===[]?null:$this->encodeJson($draft['buttons_json']),$draft['pin_after_publish'],$draft['autosignature_text'],$draft['autosignature_entities_json']===[]?null:$this->encodeJson($draft['autosignature_entities_json']),$postId]);
                    if(array_key_exists('caption_placement',$next['plan']))PostedTelegramCaptionPlacement::commitEditLocked($this->pdo,$post,$edited,$next['plan']);
                    PostedTelegramPublicButtonLayout::putLocked($this->pdo,PostedTelegramPublicButtonLayout::receiptKey($postId),$receipt);
                    PostedTelegramPublicButtonEdit::completeLocked($this->pdo,$postId,$next['plan']['operation_id']);
                    $q=$this->pdo->prepare('DELETE FROM posted_edit_sessions WHERE user_id=? AND post_id=?');$q->execute([$actor,$postId]);if($q->rowCount()!==1)throw new RuntimeException('public_button_edit_session_changed');
                    $this->telegramPublishedSingleClearInput($actor,$postId,$next['plan']['input']);return$next+['committed'=>true];
                }
                return$next;
            },false,true);
            $resume=null;
            if(!is_array($r)){$this->telegram->sendMessage($actor,'Сохранение приостановлено: доступ к посту изменился. Известные результаты сохранены.');return;}
            if(($r['committed']??false)===true){try{$this->telegram->sendMessage($actor,'Изменения сохранены.');}catch(Throwable$e){error_log('GitPosted committed public edit acknowledgement: '.$e::class);}return;}
            $index=$r['next'];if($index===null){$this->telegramPublicEditNotice($actor,$r);return;}
            $step=$r['plan']['steps'][$index];$intent=$r['header']['receipts'][$index]['intent'];$outcome='unknown';$proof=null;
            try{
                if($this->pdo->inTransaction())throw new LogicException('public_button_edit_transport_inside_sql');
                $response=$this->telegram->call($step['method'],$step['payload']);
                if(PostedTelegramPublicButtonEdit::knownResponse($step,$response)){$outcome='done';$proof=PostedTelegramPublicButtonLayout::hash($response);}
            }catch(PostedTelegramRequestException$e){
                if(!$e->deliveryMayBeUnknown()){
                    if($step['method']==='editMessageCaption'&&array_key_exists('show_caption_above_media',$step['payload'])&&$e->httpStatus()===400&&preg_match('/\ABad Request: message is not modified(?:\z|:)/iD',$e->getMessage())===1){$outcome='unknown';}
                    elseif($step['method']==='editMessageCaption'&&$e->httpStatus()===400&&preg_match('/\ABad Request: message is not modified(?:\z|:)/iD',$e->getMessage())===1){$outcome='done';$proof=PostedTelegramPublicButtonLayout::hash(['already_applied'=>$step]);}
                    else$outcome='rejected';
                }
            }catch(Throwable$e){error_log('GitPosted public edit RPC: '.$e::class);}
            // Persist an exact known response even if authority changed during the RPC.
            $this->pdo->beginTransaction();try{
                $q=$this->pdo->prepare('SELECT id FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$r['plan']['channel_id']]);if($q->fetchColumn()===false)throw new RuntimeException('public_button_edit_channel_missing');
                $q=$this->pdo->prepare('SELECT id FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId,$r['plan']['channel_id']]);if($q->fetchColumn()===false)throw new RuntimeException('public_button_edit_post_missing');
                $settled=PostedTelegramPublicButtonEdit::settleLocked($this->pdo,$postId,$r['plan']['operation_id'],$index,$intent,$outcome,$proof);$this->pdo->commit();
            }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
            if($outcome!=='done'){$this->telegramPublicEditNotice($actor,$settled);return;}
        }
    }
    private function handleTelegramPublicButtonResume(array$callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||!str_starts_with($raw,'pbl:'))return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||preg_match('/\Apbl:resume:([1-9][0-9]{0,18}):([a-f0-9]{24})\z/D',$raw,$m)!==1||(string)(int)$m[1]!==$m[1]){$this->answerCallback($callback,'Откройте сохранение в личном чате бота.');return true;}
        if($this->pdo->inTransaction())throw new LogicException('public_button_resume_outer_transaction');
        $this->answerCallback($callback);$postId=(int)$m[1];$post=$this->post($postId);if(!is_array($post))return true;
        $name='gitposted_pin_'.substr(hash('sha256',(string)$post['channel_id']),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return true;
        try{$this->runTelegramPublicButtonEdit($actor,$postId,$m[2]);}
        catch(Throwable$e){error_log('GitPosted explicit public Save resume: '.$e::class);$this->telegram->sendMessage($actor,'Сохранение не возобновлено: пост, доступ или сессия изменились. Сохранённые результаты не удалены.');}
        finally{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}return true;
    }
}
