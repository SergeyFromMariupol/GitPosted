<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Private editor presentation receipt. Never a publication or grant authority. */
final class PostedTelegramPublishedSingleUi
{
    public static function key(int$actor,int$post):string{if($actor<1||$post<1)throw new InvalidArgumentException('single_ui_identity');return'psui_'.$actor.'_'.$post;}
    public static function hash(mixed$v):string{return PostedTelegramPublishedAlbumEditor::hash($v);}
    public static function read(PDO$pdo,int$actor,int$post,bool$lock=false):?array
    {
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$q->execute([self::key($actor,$post)]);$raw=$q->fetchColumn();if($raw===false)return null;$r=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($r)||($r['version']??null)!==1||($r['actor_id']??null)!==$actor||($r['post_id']??null)!==$post||!is_int($r['channel_id']??null)||$r['channel_id']<1||!is_int($r['owner_id']??null)||$r['owner_id']<1||!in_array($r['state']??null,['rendering','shown','unknown'],true)||!is_string($r['revision']??null)||preg_match('/\A[a-f0-9]{16}\z/D',$r['revision'])!==1||!is_string($r['session_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$r['session_hash'])!==1||!is_string($r['epoch']??null)||preg_match('/\A(?:legacy|[a-f0-9]{32})\z/D',$r['epoch'])!==1||!array_key_exists('message_id',$r)||($r['message_id']!==null&&(!is_int($r['message_id'])||$r['message_id']<1))||!is_array($r['menu_ids']??null)||!array_is_list($r['menu_ids'])||count($r['menu_ids'])>10||count(array_unique($r['menu_ids']))!==count($r['menu_ids']))throw new RuntimeException('single_ui_receipt_corrupt');foreach($r['menu_ids']as$id)if(!is_int($id)||$id<1)throw new RuntimeException('single_ui_menu_identity');return$r;
    }
    public static function put(PDO$pdo,array$r):void
    {if(!$pdo->inTransaction())throw new LogicException('single_ui_lock_required');$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key($r['actor_id'],$r['post_id']),json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    public static function ack(array$response,int$actor,?int$target=null):?int
    {$r=$response['result']??null;$mid=is_array($r)?($r['message_id']??null):null;return($response['ok']??null)===true&&is_int($mid)&&$mid>0&&($target===null||$mid===$target)&&is_array($r['chat']??null)&&($r['chat']['type']??null)==='private'&&(string)($r['chat']['id']??'')===(string)$actor?$mid:null;}
}

trait GitPostedTelegramPublishedSingleUi
{
    private ?array$telegramPublishedSingleTarget=null;
    private ?string$telegramPublishedSingleSaveHash=null;
    private ?array$telegramPublishedSingleSaveInput=null;
    private function telegramPublishedSingleCandidate(array$post):bool
    {
        if(($post['status']??null)!=='sent'||($post['original_media_type']??$post['media_type']??null)!=='photo'||!$this->isSingleMessageEditableTelegramPost($post))return false;
        foreach(['external_chat_id','external_message_id','repost_chat_id','repost_message_id','repost_input_chat_id','repost_input_message_id']as$k)if(!empty($post[$k]))return false;
        try{$ids=$post['telegram_message_ids']??null;if(is_string($ids)&&$ids!==''){$raw=json_decode($ids,true,16,JSON_THROW_ON_ERROR);if(!is_array($raw)||!array_is_list($raw)||count($raw)!==1)return false;}$q=$this->pdo->prepare('SELECT state FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$q->execute([(int)$post['id']]);$attempt=$q->fetchColumn();if($attempt!==false&&$attempt!=='completed')return false;if(PostedTelegramTransportLayout::characters((string)$this->effectivePostPayload($post)['text'])>self::PHOTO_CAPTION_LIMIT)return false;$media=$this->postMedia($post);return count($media)===1&&$media[0]['type']==='photo'&&!str_starts_with((string)$media[0]['file_id'],'post-link-asset:')&&PostedTelegramPostOptions::load($this->pdo,$post)['paid_media_stars']===null;}catch(Throwable){return false;}
    }
    private function telegramPublishedSingleViewLocked(int$actor,array$post,array$channel):array
    {
        if(!$this->pdo->inTransaction()||!$this->telegramPublishedSingleCandidate($post))throw new RuntimeException('single_ui_unavailable');
        PostedTelegramPublicButtonLayout::assertNotComponents($post);
        PostedTelegramPublicButtonEdit::assertIdleLocked($this->pdo,(int)$post['id']);
        $q=$this->pdo->prepare('SELECT * FROM posted_edit_sessions WHERE user_id=? AND post_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor,(int)$post['id']]);$s=$q->fetch();if(!is_array($s))throw new RuntimeException('single_ui_no_session');$doc=$this->editSessionDocument($s,$post);
        if($doc['base']!==$this->editablePostSnapshot($post))throw new RuntimeException('single_ui_stale_session');$pause=PostedTelegramAuthorityPause::read($this->pdo,$channel,true);if(PostedTelegramAuthorityPause::blocked($this->pdo,$channel,true))throw new RuntimeException('single_ui_channel_paused');
        return['stored'=>$post,'post'=>PostedTelegramCaptionPlacement::editPost($this->postWithEditableSnapshot($post,$doc['draft']),$doc),'channel'=>$channel,'document'=>$doc,'hash'=>PostedTelegramPublishedSingleUi::hash($doc),'epoch'=>(string)($pause['generation']??'legacy')];
    }
    private function telegramPublishedSingleReceiptMatches(array$r,array$v,int$actor):bool
    {return$r['actor_id']===$actor&&$r['post_id']===(int)$v['stored']['id']&&$r['channel_id']===(int)$v['channel']['id']&&$r['owner_id']===(int)$v['channel']['added_by']&&$r['epoch']===$v['epoch']&&hash_equals($r['session_hash'],$v['hash']);}
    private function telegramPublishedSingleKeyboard(array$post,string$revision):array
    {
        $id=(int)$post['id'];$cb=static fn(string$a):string=>'psedit:'.$a.':'.$id.':'.$revision;$rows=[[
            ['text'=>'Медиа','callback_data'=>$cb('media')],['text'=>'Изменить описание','callback_data'=>$cb('text')]],
            [['text'=>'Кнопки','callback_data'=>$cb('buttons')]],
        ];$payload=$this->effectivePostPayload($post);$has=preg_match('~https?://\S+~u',(string)$payload['text'])===1;foreach($payload['entities']as$e)if(in_array($e['type']??null,['url','text_link'],true))$has=true;
        if($has)$rows[]=[['text'=>'Замена ссылок','callback_data'=>$cb('replace_links')]];
        $rows[]=[['text'=>(!empty($post['pin_after_publish'])?'✅':'☑️').' Закрепить','callback_data'=>$cb(empty($post['pin_after_publish'])?'pin_on':'pin_off')]];
        $rows[]=[['text'=>'💾 Сохранить изменения','callback_data'=>$cb('save')]];$rows[]=[['text'=>'← Назад','callback_data'=>$cb('cancel')]];return$rows;
    }
    /** Only known private IDs are retained; no draft pointers or published rows change. */
    private function showTelegramPublishedSingleEditor(int$actor,int$postId,string$ignoredPrefix=''):bool
    {
        $post=$this->post($postId);if(!is_array($post)||!$this->telegramPublishedSingleCandidate($post))return false;
        if($this->pdo->inTransaction())return true;
        try{$prepared=$this->withTelegramManagedPostLock($actor,$postId,['sent'],function(array$p,array$c)use($actor,$postId):?array{
            $v=$this->telegramPublishedSingleViewLocked($actor,$p,$c);$plan=$this->telegramPreviewPlan($v['post']);if($plan['mode']!=='single'||count($plan['media'])!==1||$plan['text_parts']!==[])return['fallback'=>true];
            $old=PostedTelegramPublishedSingleUi::read($this->pdo,$actor,$postId,true);$target=$this->telegramPublishedSingleTarget;
            if($target!==null&&((int)($target['chat_id']??0)!==$actor||!is_int($target['message_id']??null)||$target['message_id']<1))throw new RuntimeException('single_ui_wrong_private_target');
            if($target===null&&is_array($old)&&$old['state']!=='shown')return null;
            $mid=$target['message_id']??(is_array($old)&&$old['state']==='shown'&&$old['owner_id']===(int)$c['added_by']&&$old['epoch']===$v['epoch']?$old['message_id']:null);
            $r=['version'=>1,'actor_id'=>$actor,'post_id'=>$postId,'channel_id'=>(int)$c['id'],'owner_id'=>(int)$c['added_by'],'epoch'=>$v['epoch'],'state'=>'rendering','message_id'=>$mid,'menu_ids'=>[],'revision'=>bin2hex(random_bytes(8)),'session_hash'=>$v['hash']];
            $rows=array_merge((array)$plan['buttons'],$this->telegramPublishedSingleKeyboard($v['post'],$r['revision']));PostedTelegramPublishedSingleUi::put($this->pdo,$r);return['receipt'=>$r,'post'=>$v['post'],'plan'=>$plan,'keyboard'=>$rows];
        },false,true);
        if(is_array($prepared)&&($prepared['fallback']??false)===true)return false;
        if(!is_array($prepared)){$this->telegram->sendMessage($actor,'Предпросмотр ещё не подтверждён. Откройте опубликованный пост заново.');return true;}
        $r=$prepared['receipt'];$known=null;
        try{if($this->pdo->inTransaction())throw new LogicException('single_ui_transport_outside_sql');$extra=['reply_markup'=>['inline_keyboard'=>$prepared['keyboard']]];
            if($r['message_id']!==null){$response=$this->telegram->call('editMessageMedia',['chat_id'=>(string)$actor,'message_id'=>$r['message_id'],'media'=>$prepared['plan']['media'][0]]+$extra);$known=PostedTelegramPublishedSingleUi::ack($response,$actor,$r['message_id']);}
            else{$media=$prepared['plan']['media'][0];if(($media['show_caption_above_media']??null)===true)$extra['show_caption_above_media']=true;if(isset($media['caption_entities']))$extra['caption_entities']=$media['caption_entities'];if(!empty($media['has_spoiler']))$extra['has_spoiler']=true;$response=$this->sendMedia($actor,$media['type'],$media['media'],(string)($media['caption']??''),$extra);$known=PostedTelegramPublishedSingleUi::ack($response,$actor);}
        }catch(Throwable$e){error_log('GitPosted private published panel: '.$e::class);}
        // Record the known outcome even if a later UI/session change made it obsolete.
        $this->pdo->beginTransaction();try{$current=PostedTelegramPublishedSingleUi::read($this->pdo,$actor,$postId,true);if($current!==null&&$current['revision']===$r['revision']&&$current['state']==='rendering'){$current['state']=$known!==null?'shown':'unknown';if($known!==null)$current['message_id']=$known;PostedTelegramPublishedSingleUi::put($this->pdo,$current);}$this->pdo->commit();}catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
        if($known===null)$this->telegram->sendMessage($actor,'Не удалось подтвердить предпросмотр. Сохранённые изменения не потеряны; откройте пост заново.');
        }catch(Throwable$e){error_log('GitPosted private published editor: '.$e::class);$this->telegram->sendMessage($actor,'Пост или сессия изменились. Откройте опубликованный пост заново.');}return true;
    }
    private function telegramPublishedSingleInputMatches(int$actor,string$state,array$data):bool
    {$q=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? LIMIT 1');$q->execute([$actor]);$u=$q->fetch();return is_array($u)&&(string)($u['state']??'')===$state&&PostedTelegramPublishedSingleUi::hash($this->decodeJson((string)($u['state_data']??'')))===PostedTelegramPublishedSingleUi::hash($data);}
    private function telegramPublishedSingleGuardLocked(int$actor,array$p,array$c,string$revision):array
    {$v=$this->telegramPublishedSingleViewLocked($actor,$p,$c);$r=PostedTelegramPublishedSingleUi::read($this->pdo,$actor,(int)$p['id'],true);if($r===null||$r['state']!=='shown'||!hash_equals($r['revision'],$revision)||!$this->telegramPublishedSingleReceiptMatches($r,$v,$actor))throw new RuntimeException('single_ui_stale_panel');return[$v,$r];}
    private function telegramPublishedSingleChanged(int$actor,int$post,string$revision,Closure$changes,?array$input=null):bool
    {
        try{$ok=$this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$post,$revision,$changes,$input):bool{
            [$v,$r]=$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$revision);if($input!==null&&!$this->telegramPublishedSingleInputMatches($actor,$input['state'],$input['data']))return false;
            $draft=$changes($v['document']['draft']);if(!is_array($draft))return false;$doc=$v['document'];$doc['draft']=$this->editablePostSnapshot($draft);$this->pdo->prepare('UPDATE posted_edit_sessions SET snapshot_json=? WHERE user_id=? AND post_id=?')->execute([$this->encodeJson($doc),$actor,$post]);
            if($input!==null&&$input['state']!==''){$q=$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state=? AND state_data <=> CAST(? AS JSON)');$q->execute([$actor,$input['state'],$this->encodeJson($input['data'])]);if($q->rowCount()!==1)throw new RuntimeException('single_ui_input_cas');}return true;
        },false,true);return$ok===true;}catch(Throwable$e){return false;}
    }
    private function telegramPublishedSingleTypeKeyboard(int$post,string$revision,string$selected='url',bool$canClear=false):array
    {$rows=PostedTelegramButtonUi::typeKeyboard($post,'edit',$selected,$canClear);foreach($rows as&$row)foreach($row as&$b){$b['callback_data']='psedit:'.substr($b['callback_data'],5).':'.$revision;}unset($row,$b);return$rows;}
    private function telegramPublishedSinglePrompt(int$actor,int$post,string$revision,string$kind,?array$error=null):void
    {
        if($this->pdo->inTransaction())return;
        try{
        $user=$this->user($actor);$state=(string)($user['state']??'');$data=$this->stateData($user);
        if($state!==''&&(!str_starts_with($state,'await_psui_')||(int)($data['post_id']??0)!==$post)){$this->telegram->sendMessage($actor,'Сначала завершите текущий ввод. Ваши данные сохранены.');return;}
        $v=$this->withTelegramManagedPostLock($actor,$post,['sent'],fn(array$p,array$c):array=>$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$revision),false,true);if(!is_array($v))return;[$view,$receipt]=$v;
        $selected=in_array($kind,['url','reactions','hidden'],true)?$kind:'url';$keyboard=$this->telegramPublishedSingleTypeKeyboard($post,$revision,$selected,$view['document']['draft']['buttons_json']!==[]);
        $prompt=$error??match($kind){'url'=>PostedTelegramButtonUi::urlPrompt(),'text'=>['text'=>'Отправьте новое описание для поста.','entities'=>[]],'media'=>['text'=>'Отправьте новое медиа для замены.','entities'=>[]],'replace_links'=>['text'=>"🔃 ЗАМЕНА ССЫЛОК\n\nВ этом разделе вы можете быстро заменить все ссылки в сообщении.\n\nОтправьте новую ссылку, на которую нужно заменить все остальные.",'entities'=>[]],'reactions'=>['text'=>'Отправьте боту список реакций, разделяя их символом «/».','entities'=>[]],'hidden'=>['text'=>'Отправьте название кнопки скрытого продолжения.','entities'=>[]],default=>throw new InvalidArgumentException('single_ui_input_kind')};
        if(!in_array($kind,['url','reactions','hidden'],true))$keyboard=[[['text'=>'← Назад','callback_data'=>'psedit:editor_back:'.$post.':'.$revision]]];
        if($kind==='text')array_unshift($keyboard,[['text'=>'Удалить описание','callback_data'=>'psedit:clear_text:'.$post.':'.$revision]]);
        $response=$this->telegram->sendMessage($actor,$prompt['text'],['entities'=>$prompt['entities'],'reply_markup'=>['inline_keyboard'=>$keyboard]]);$menu=PostedTelegramPublishedSingleUi::ack($response,$actor);if($menu===null)return;
        $new=['post_id'=>$post,'revision'=>$revision,'input_nonce'=>bin2hex(random_bytes(12)),'menu_message_id'=>$menu];$newState='await_psui_'.$kind;$preserve=$state===$newState&&($data['revision']??null)===$revision;
        $this->withTelegramManagedPostLock($actor,$post,['sent'],function(array$p,array$c)use($actor,$post,$revision,$state,$data,$newState,$new,$preserve,$menu):bool{[, $r]=$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$revision);if(!$this->telegramPublishedSingleInputMatches($actor,$state,$data))return false;$r['menu_ids']=array_slice(array_values(array_unique(array_merge((array)($r['menu_ids']??[]),[$menu]))),-10);PostedTelegramPublishedSingleUi::put($this->pdo,$r);if(!$preserve)$this->setState($actor,$newState,$new);return true;},false,true);
        }catch(Throwable$e){error_log('GitPosted published input prompt: '.$e::class);}
    }
    private function handleTelegramPublishedSingleCallback(array$callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw))return false;$isCp=preg_match('/\Acp:edit:([1-9][0-9]{0,18})\z/D',$raw,$cm)===1;
        if(!$isCp&&!str_starts_with($raw,'psedit:'))return false;$actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor){$this->answerCallback($callback,'Откройте редактор в личном чате бота.');return true;}
        if($isCp){$post=$this->post((int)$cm[1]);if(!is_array($post)||!$this->telegramPublishedSingleCandidate($post))return false;$this->answerCallback($callback);$this->telegramPublishedSingleTarget=$this->callbackTarget($callback);try{$this->openContentPlanPublishedEditor($actor,(int)$cm[1]);}finally{$this->telegramPublishedSingleTarget=null;}return true;}
        $this->answerCallback($callback);if(preg_match('/\Apsedit:([a-z_]+):([1-9][0-9]{0,18}):([a-f0-9]{16})\z/D',$raw,$m)!==1||(string)(int)$m[2]!==$m[2])return true;[, $action,$id,$rev]=$m;$id=(int)$id;
        try{$guard=$this->withTelegramManagedPostLock($actor,$id,['sent'],fn(array$p,array$c):array=>$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$rev),false,true);if(!is_array($guard))return true;$user=$this->user($actor);$state=(string)($user['state']??'');$input=$this->stateData($user);if($state!==''&&(!str_starts_with($state,'await_psui_')||(int)($input['post_id']??0)!==$id))throw new RuntimeException('single_ui_other_input');$messageId=$callback['message']['message_id']??null;$allowed=array_merge([$guard[1]['message_id'],$input['menu_message_id']??null],(array)($guard[1]['menu_ids']??[]));if(!is_int($messageId)||!in_array($messageId,$allowed,true))throw new RuntimeException('single_ui_wrong_message');
            if($action==='editor_back'){$this->telegramPublishedSingleClearInput($actor,$id,['state'=>$state,'data'=>$input]);$this->showEditPostActions($actor,$id);return true;}
            if(in_array($action,['buttons','reactions','hidden','text','media','replace_links'],true)){$this->telegramPublishedSinglePrompt($actor,$id,$rev,$action==='buttons'?'url':$action);return true;}
            if(in_array($action,['pin_on','pin_off','buttons_clear','clear_text'],true)){$ok=$this->telegramPublishedSingleChanged($actor,$id,$rev,static function(array$d)use($action):array{if($action==='buttons_clear')$d['buttons_json']=[];elseif($action==='clear_text'){$d['text']=null;$d['text_entities_json']=[];}else$d['pin_after_publish']=$action==='pin_on'?1:0;return$d;},['state'=>$state,'data'=>$input]);if($ok)$this->showEditPostActions($actor,$id);return true;}
            if($action==='save'){$this->telegramPublishedSingleSaveHash=$guard[0]['hash'];$this->telegramPublishedSingleSaveInput=['state'=>$state,'data'=>$input,'owner_id'=>$guard[1]['owner_id'],'epoch'=>$guard[1]['epoch']];try{$this->saveEditedPost($actor,$id);}finally{$this->telegramPublishedSingleSaveHash=null;$this->telegramPublishedSingleSaveInput=null;}return true;}if($action==='cancel'){$ok=$this->withTelegramManagedPostLock($actor,$id,['sent'],function(array$p,array$c)use($actor,$id,$rev,$state,$input):bool{$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$rev);if(!$this->telegramPublishedSingleInputMatches($actor,$state,$input))return false;$q=$this->pdo->prepare('DELETE FROM posted_edit_sessions WHERE user_id=? AND post_id=? AND delete_on_cancel=0');$q->execute([$actor,$id]);if($q->rowCount()!==1)return false;$this->telegramPublishedSingleClearInput($actor,$id,['state'=>$state,'data'=>$input]);return true;},false,true);if($ok)$this->telegram->sendMessage($actor,'Изменение отменено.');return true;}
        }catch(Throwable$e){$this->telegram->sendMessage($actor,'Кнопка устарела или пост изменился. Откройте опубликованный пост заново.');}return true;
    }
    private function telegramPublishedSingleClearInput(int$actor,int$post,?array$expected=null):void
    {$u=$this->user($actor);$s=(string)($u['state']??'');$d=$this->stateData($u);if($expected!==null&&($s!==$expected['state']||PostedTelegramPublishedSingleUi::hash($d)!==PostedTelegramPublishedSingleUi::hash($expected['data'])))return;if(!str_starts_with($s,'await_psui_')||(int)($d['post_id']??0)!==$post)return;$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state=? AND state_data <=> CAST(? AS JSON)')->execute([$actor,$s,$this->encodeJson($d)]);}
    private function telegramPublishedSingleSaveClear(int$actor,bool$knownCommitted=false):void
    {if($this->telegramPublishedSingleSaveHash===null){$this->clearState($actor);return;}if(!$knownCommitted||$this->telegramPublishedSingleSaveInput===null)return;$expected=$this->telegramPublishedSingleSaveInput;$post=(int)($expected['data']['post_id']??0);if($post>0)$this->telegramPublishedSingleClearInput($actor,$post,$expected);}
    private function handleTelegramPublishedSingleInput(int$actor,string$state,array$data,array$message):bool
    {
        if(!str_starts_with($state,'await_psui_'))return false;if($this->pdo->inTransaction())return true;$id=(int)($data['post_id']??0);$rev=$data['revision']??null;if($id<1||!is_string($rev)||preg_match('/\A[a-f0-9]{16}\z/D',$rev)!==1||!is_string($data['input_nonce']??null)||preg_match('/\A[a-f0-9]{24}\z/D',$data['input_nonce'])!==1)return true;$kind=substr($state,11);$raw=$message['text']??null;$changes=null;
        if($kind==='url'){$buttons=$this->parseButtonsInput($message);if($buttons===null){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'url',is_string($raw)&&trim($raw)!==''?PostedTelegramButtonUi::invalidUrl():null);return true;}$changes=fn(array$d):array=>$this->telegramPublishedSingleMergeButtons($d,$buttons,'url');}
        elseif($kind==='reactions'){$buttons=$this->parseReactionButtonsInput($id,$message);if($buttons===null){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'reactions');return true;}$changes=fn(array$d):array=>$this->telegramPublishedSingleMergeButtons($d,$buttons,'reaction');}
        elseif($kind==='text'){$content=$this->contentFromMessage($message);if($content['text']===''){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'text');return true;}$changes=static fn(array$d):array=>array_replace($d,['text'=>$content['text'],'text_entities_json'=>$content['text_entities']]);}
        elseif($kind==='media'){$c=$this->contentFromMessage($message);if($c['media_file_id']===null||$c['media_type']==='voice'){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'media');return true;}$changes=static function(array$d)use($c,$message):array{$d=array_replace($d,['media_type'=>$c['media_type'],'media_file_id'=>$c['media_file_id'],'media_json'=>$c['media']]);if(array_key_exists('caption',$message)){$d['text']=$c['text']!==''?$c['text']:null;$d['text_entities_json']=$c['text_entities'];}return$d;};}
        elseif($kind==='replace_links'){$url=is_string($raw)?trim($raw):'';if(!filter_var($url,FILTER_VALIDATE_URL)||preg_match('~\Ahttps?://~i',$url)!==1){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'replace_links');return true;}$changes=function(array$d)use($url):array{[$d['text'],$d['text_entities_json']]=$this->replaceTelegramUrls((string)($d['text']??''),(array)$d['text_entities_json'],$url);return$d;};}
        elseif($kind==='hidden'){$label=is_string($raw)?trim($raw):'';if($label===''){$this->telegramPublishedSinglePrompt($actor,$id,$rev,'hidden');return true;}$next=$data;$next['hidden_label']=$label;$this->withTelegramManagedPostLock($actor,$id,['sent'],function(array$p,array$c)use($actor,$id,$rev,$state,$data,$next):bool{$this->telegramPublishedSingleGuardLocked($actor,$p,$c,$rev);if(!$this->telegramPublishedSingleInputMatches($actor,$state,$data))return false;$this->setState($actor,'await_psui_hidden_text',$next);return true;},false,true);$this->telegram->sendMessage($actor,'Отправьте боту текст, который увидят подписчики вашего канала по нажатию на кнопку.');return true;}
        elseif($kind==='hidden_text'){$body=is_string($raw)?trim($raw):'';$label=(string)($data['hidden_label']??'');if($body===''||$label===''||mb_strlen($body)>self::HIDDEN_TEXT_LIMIT)return true;$changes=static function(array$d)use($body,$label,$id):array{$buttons=(array)$d['buttons_json'];$index=0;foreach($buttons as$row)foreach((array)$row as$b)if(preg_match('/\Ahidden:'.$id.':h([0-9]+)\z/D',(string)($b['callback_data']??''),$m)===1)$index=max($index,(int)$m[1]+1);$buttons[]=[['text'=>$label,'callback_data'=>'hidden:'.$id.':h'.$index,'hidden_text'=>$body]];$d['buttons_json']=PostedTelegramButtonUi::orderRows($buttons);return$d;};}
        if($changes instanceof Closure&&$this->telegramPublishedSingleChanged($actor,$id,$rev,$changes,['state'=>$state,'data'=>$data]))$this->showEditPostActions($actor,$id);return true;
    }
    private function telegramPublishedSingleMergeButtons(array$draft,array$replacement,string$type):array
    {$draft['buttons_json']=PostedTelegramButtonUi::replaceTypeRows((array)$draft['buttons_json'],$replacement,$type);return$draft;}
}
