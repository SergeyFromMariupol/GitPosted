<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-store.php';
require_once __DIR__.'/telegram-native-preview.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

trait GitPostedTelegramNativeRuntime
{
    private function telegramNativePreviewDescriptor(array $post):?array
    {
        if(array_key_exists('_telegram_native_content',$post))$d=$post['_telegram_native_content'];
        elseif(PostedTelegramNativeContent::isKind($post['media_type']??null))$d=PostedTelegramNativeStore::load($this->pdo,$post)['descriptor'];
        else return null;
        if($d===null){if(PostedTelegramNativeContent::isKind($post['media_type']??null))throw new InvalidArgumentException('Native содержимое этой попытки недоступно. Сохранённый пост не изменён.');return null;}
        $d=PostedTelegramNativeContent::normalize($d);
        if(($post['media_type']??null)!==$d['kind']||($post['media_file_id']??null)!==($d['payload']['file_id']??null))throw new InvalidArgumentException('native_content_preview_binding');return$d;
    }
    private function telegramNativeAssertOrdinaryPayload(array $post,array $payload):void
    {
        if((string)($payload['text']??'')!==''||(array)($payload['entities']??[])!==[])throw new InvalidArgumentException('Этот вид содержимого не поддерживает описание или подпись канала. Данные поста сохранены.');
        $o=$this->telegramPostOptions($post);if(($o['paid_media_stars']??null)!==null||!empty($o['media_spoiler']))throw new InvalidArgumentException('Эти параметры недоступны для данного вида содержимого. Данные поста сохранены.');
    }
    private function telegramNativePreviewPlan(array $post,string $prefixText=''):?array
    {
        $d=$this->telegramNativePreviewDescriptor($post);if($d===null)return null;$payload=$this->effectivePostPayload($post);$this->telegramNativeAssertOrdinaryPayload($post,$payload);$request=PostedTelegramNativeContent::request($d);$notice=trim($prefixText);
        if(!mb_check_encoding($notice,'UTF-8')||mb_strlen($notice,'UTF-8')>4096)throw new InvalidArgumentException('native_preview_notice');
        return['mode'=>'native','media'=>[],'text_parts'=>[],'native_request'=>$request,'native_descriptor'=>$d,'native_notice'=>$notice,'buttons'=>$this->telegramPostInlineButtonsAllowed($post)?$this->buttonsMarkup((array)$payload['buttons']):[],'fingerprint'=>PostedTelegramNativeContent::hash(['version'=>1,'native'=>$d,'notice'=>$notice])];
    }
    private function dispatchTelegramNativePreviewPlan(int $actor,array $post,array $plan,array $finalExtra):array
    {
        $d=PostedTelegramNativeContent::normalize($plan['native_descriptor']??null);$request=PostedTelegramNativeContent::request($d);
        if(($plan['mode']??null)!=='native'||($plan['media']??null)!==[]||($plan['text_parts']??null)!==[]||($plan['native_request']??null)!==$request||array_diff(array_keys($finalExtra),['reply_markup'])!==[])throw new InvalidArgumentException('native_preview_plan_changed');
        // Native private I/O is deliberately unavailable through the legacy transaction dispatcher.
        // Draft/post previews use tgnpv; template previews own their separate durable journal.
        throw new LogicException('native_preview_requires_durable_journal');
    }
    private function telegramNativeObservedPrivateId(array $result,int $actor):?int
    {$m=$result['result']??null;return($result['ok']??null)===true&&is_array($m)&&is_int($m['message_id']??null)&&$m['message_id']>0&&(string)($m['chat']['id']??'')===(string)$actor?$m['message_id']:null;}
    private function telegramNativePrivatePlan(array $post,string $prefix,?array $panel):?array
    {
        $plan=$this->telegramNativePreviewPlan($post,$prefix);if($plan===null||$panel===null)return$plan;
        if(!is_string($panel['text']??null)||$panel['text']===''||mb_strlen($panel['text'],'UTF-8')>4096||!is_array($panel['extra']??null)||array_diff(array_keys($panel['extra']),['parse_mode','entities','link_preview_options','disable_web_page_preview','reply_markup'])!==[])throw new InvalidArgumentException('native_private_panel');
        $plan['_native_panel']=true;$plan['buttons']=[];$plan['fingerprint']=PostedTelegramNativeContent::hash(['native'=>$plan['fingerprint'],'private_panel'=>$panel]);return$plan;
    }
    private function telegramNativePreviewWorkflow(int $actor):string
    {$u=$this->user($actor);if(!is_array($u)||(int)($u['is_active']??0)!==1)throw new RuntimeException('native_preview_actor_inactive');return PostedTelegramNativeContent::hash([$u['state']??null,$u['state_data']??null]);}
    private function telegramNativePreviewLock(int $actor,int $postId,callable $operation):mixed
    {
        if($this->pdo->inTransaction())throw new LogicException('native_preview_outer_transaction');
        return$this->withTelegramManagedPostLock($actor,$postId,['draft','scheduled','failed','sent','cancelled'],$operation);
    }
    /** Every new private effect is marked sending durably before I/O. A crash/unknown never retries it. */
    private function sendTelegramNativePreview(int $actor,array $post,array $controls,string $prefix='',?array $panel=null):bool
    {
        if($this->pdo->inTransaction())throw new LogicException('native_preview_outer_transaction');$id=(int)($post['id']??0);if($id<1)return false;
        try{$expectedSnapshot=PostedTelegramNativePreview::snapshot($post);$expectedWorkflow=$this->telegramNativePreviewWorkflow($actor);
            $prepared=$this->telegramNativePreviewLock($actor,$id,function(array $current)use($actor,$controls,$prefix,$panel,$expectedSnapshot,$expectedWorkflow):array{
                if(!hash_equals($expectedSnapshot,PostedTelegramNativePreview::snapshot($current))||!hash_equals($expectedWorkflow,$this->telegramNativePreviewWorkflow($actor)))throw new RuntimeException('native_preview_input_changed');
                $plan=$this->telegramNativePrivatePlan($current,$prefix,$panel);if($plan===null)throw new RuntimeException('native_preview_kind_changed');$markup=['inline_keyboard'=>array_merge((array)$plan['buttons'],$controls)];
                return['journal'=>PostedTelegramNativePreview::prepareLocked($this->pdo,$current,$actor,$plan,$markup,$this->telegramNativePreviewWorkflow($actor)),'plan'=>$plan,'markup'=>$markup];
            });
            if(!is_array($prepared))return false;$v=$prepared['journal'];$plan=$prepared['plan'];$markup=$prepared['markup'];
            foreach($v['steps']as$i=>$step){
                $authorized=$this->telegramNativePreviewLock($actor,$id,function(array $current)use($actor,$v,$i,$controls,$prefix,$markup,$panel):bool{
                    $live=PostedTelegramNativePreview::load($this->pdo,(int)$current['id'],$actor,true);$livePlan=$this->telegramNativePrivatePlan($current,$prefix,$panel);if($live===null||!hash_equals($live['token'],$v['token'])||$livePlan===null)throw new RuntimeException('native_preview_operation_changed');
                    $liveMarkup=['inline_keyboard'=>array_merge((array)$livePlan['buttons'],$controls)];if($liveMarkup!==$markup)throw new RuntimeException('native_preview_markup_changed');
                    PostedTelegramNativePreview::assertCurrent($live,$current,$actor,$livePlan,$liveMarkup,$this->telegramNativePreviewWorkflow($actor));PostedTelegramNativePreview::beginStepLocked($this->pdo,$live,$i);return true;
                });
                if($authorized!==true)throw new RuntimeException('native_preview_access_changed');
                $phase='unknown';$mid=null;$observed=null;
                try{
                    if($step['type']==='panel'){$r=$this->telegram->sendMessage($actor,$panel['text'],array_merge($panel['extra'],['reply_markup'=>$markup]));$m=$r['result']??null;$observed=$this->telegramNativeObservedPrivateId($r,$actor);$expected=isset($panel['extra']['parse_mode'])?html_entity_decode(strip_tags($panel['text']),ENT_QUOTES|ENT_HTML5,'UTF-8'):$panel['text'];if(($r['ok']??null)===true&&is_array($m)&&is_int($m['message_id']??null)&&$m['message_id']>0&&(string)($m['chat']['id']??'')===(string)$actor&&($m['text']??null)===$expected){$phase='known';$mid=$m['message_id'];}}
                    elseif($step['type']==='notice'){$result=$this->telegram->sendMessage($actor,$plan['native_notice']);$m=$result['result']??null;$observed=$this->telegramNativeObservedPrivateId($result,$actor);if(($result['ok']??null)===true&&is_array($m)&&is_int($m['message_id']??null)&&$m['message_id']>0&&(string)($m['chat']['id']??'')===(string)$actor&&($m['text']??null)===$plan['native_notice']){$phase='known';$mid=$m['message_id'];}}
                    elseif($step['type']==='markup'){$ids=$this->decodeMessageSlots((string)$v['old_pointers'][1]);$mid=$ids===[]?0:(int)end($ids);if($mid<1)throw new RuntimeException('native_preview_old_pointer');try{$r=$this->telegram->call('editMessageReplyMarkup',['chat_id'=>(string)$actor,'message_id'=>$mid,'reply_markup'=>$markup]);$observed=$this->telegramNativeObservedPrivateId($r,$actor);if($observed!==$mid||!is_array($r['result']['reply_markup']??null)||PostedTelegramNativeContent::hash($r['result']['reply_markup'])!==PostedTelegramNativeContent::hash($markup))throw new RuntimeException('native_preview_edit_unknown');}catch(PostedTelegramRequestException$e){if($e->deliveryMayBeUnknown()||!str_contains(mb_strtolower($e->getMessage()),'message is not modified'))throw$e;}$phase='known';}
                    else{$request=$plan['native_request'];$r=$this->telegram->call($request['method'],array_merge(['chat_id'=>(string)$actor],$request['params'],['reply_markup'=>$markup]));$ack=PostedTelegramNativeContent::acknowledgement($plan['native_descriptor'],$r,(string)$actor);$observed=$ack['message_id'];if($ack['known']){$phase='known';$mid=$ack['message_id'];}}
                }catch(Throwable$e){$mid=null;if($e instanceof PostedTelegramRequestException&&!$e->deliveryMayBeUnknown())$phase='rejected';PostedTelegramNativePreview::record($this->pdo,$id,$actor,$v['token'],$i,$phase,null,$observed);throw$e;}
                PostedTelegramNativePreview::record($this->pdo,$id,$actor,$v['token'],$i,$phase,$phase==='known'?$mid:null,$observed);if($phase!=='known')throw new RuntimeException('Предпросмотр не подтверждён. Автоматический повтор отключён.');
            }
            $retire=$this->telegramNativePreviewLock($actor,$id,function(array $current)use($actor,$v,$controls,$prefix,$markup,$panel):array{
                $live=PostedTelegramNativePreview::load($this->pdo,(int)$current['id'],$actor,true);$plan=$this->telegramNativePrivatePlan($current,$prefix,$panel);if($live===null||!hash_equals($live['token'],$v['token'])||$plan===null)throw new RuntimeException('native_preview_operation_changed');$liveMarkup=['inline_keyboard'=>array_merge((array)$plan['buttons'],$controls)];if($liveMarkup!==$markup)throw new RuntimeException('native_preview_markup_changed');PostedTelegramNativePreview::assertCurrent($live,$current,$actor,$plan,$liveMarkup,$this->telegramNativePreviewWorkflow($actor));
                foreach($live['steps']as$s)if($s['phase']!=='known')throw new RuntimeException('native_preview_incomplete');$new=$live['kind']==='markup'?$this->decodeMessageSlots((string)$live['old_pointers'][1]):array_column($live['steps'],'message_id');$new=PostedTelegramPreviewProof::requireKnownIds($new,count($new));$control=(int)end($new);
                $projection=PostedTelegramNativePreview::pointers($current);$q=$this->pdo->prepare('UPDATE posted_posts SET telegram_preview_chat_id=?,telegram_preview_message_ids=?,telegram_preview_control_message_id=?,telegram_preview_fingerprint=? WHERE id=? AND author_id=? AND channel_id=? AND status=? AND telegram_preview_chat_id<=>? AND telegram_preview_message_ids<=>CAST(? AS JSON) AND telegram_preview_control_message_id<=>? AND telegram_preview_fingerprint<=>?');$q->execute([(string)$actor,$this->encodeMessageIds($new),$control,$plan['fingerprint'],(int)$current['id'],(int)$current['author_id'],(int)$current['channel_id'],$current['status'],...$projection]);
                $check=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1');$check->execute([(int)$current['id']]);$after=$check->fetch();if(!is_array($after)||(string)($after['telegram_preview_chat_id']??'')!==(string)$actor||$this->decodeMessageSlots((string)($after['telegram_preview_message_ids']??''))!==$new||(int)($after['telegram_preview_control_message_id']??0)!==$control||($after['telegram_preview_fingerprint']??null)!==$plan['fingerprint'])throw new RuntimeException('native_preview_pointer_cas');
                $live['phase']='applied';PostedTelegramNativePreview::write($this->pdo,$live);$old=$this->decodeMessageSlots((string)$live['old_pointers'][1]);$old[]=(int)$live['old_pointers'][2];return(string)$live['old_pointers'][0]===(string)$actor?array_values(array_diff(array_unique($old),$new)):[];
            });
            if(!is_array($retire))throw new RuntimeException('native_preview_access_changed');foreach($retire as$old)if($old>0)try{$this->telegram->deleteMessage($actor,$old);}catch(Throwable){}return true;
        }catch(Throwable$e){error_log('GitPosted native preview held: '.$e->getMessage());return false;}
    }
    private function telegramNativeExpectedParts(array $post):?array
    {
        if(!PostedTelegramNativeContent::isKind($post['media_type']??null))return null;$post=PostedTelegramNativeStore::freeze($this->pdo,$post);$d=PostedTelegramNativeStore::behavior($post);
        if($d===[])throw new RuntimeException('Native формат этой записанной попытки отсутствует. Новая отправка запрещена.');
        $this->telegramNativeAssertOrdinaryPayload($post,$this->effectivePostPayload($post));$part=$this->telegramPendingPartFromMessage(PostedTelegramNativeContent::expectedMessage($d,(string)$post['chat_id']),0,(string)$post['chat_id']);
        $part['native_content_version']=1;$part['native_content_sha256']=PostedTelegramNativeContent::hash($d);$part['native_no_reconcile']=!PostedTelegramNativeContent::capabilities($d['kind'])['reconcile_semantic'];return[$part];
    }
    private function telegramNativePublish(array $post,array $extra):?array
    {
        if(!PostedTelegramNativeContent::isKind($post['media_type']??null))return null;$post=PostedTelegramNativeStore::freeze($this->pdo,$post);$d=PostedTelegramNativeStore::behavior($post);
        if($d===[])throw new RuntimeException('Native формат этой записанной попытки отсутствует. Новая отправка запрещена.');$this->telegramNativeAssertOrdinaryPayload($post,$this->effectivePostPayload($post));$request=PostedTelegramNativeContent::request($d);
        if(array_diff(array_keys($extra),['disable_notification','protect_content','reply_markup','reply_parameters'])!==[])throw new InvalidArgumentException('native_delivery_extra');
        $result=$this->telegram->call($request['method'],array_merge(['chat_id'=>(string)$post['chat_id']],$request['params'],$extra));$ack=PostedTelegramNativeContent::acknowledgement($d,$result,(string)$post['chat_id']);
        if(!$ack['known']&&is_int($ack['message_id'])){try{PostedTelegramNativeStore::recordObservedAck($this->pdo,$post,$ack['message_id']);}catch(Throwable$e){throw new PostedTelegramAcceptedButNotPersistedException($e);}}
        if(!$ack['known'])throw new PostedTelegramRequestException('Ответ Telegram не доказывает точную native-публикацию. Исход требует проверки; отправка не повторяется.',true);
        $slots=[];$parts=[];$this->appendTelegramSendResult($post,$result,$slots,$parts);return$this->telegramPublishOutcome($slots,$parts,[]);
    }
    private function telegramNativeDraftKeyboard(array $post,bool $expanded):?array
    {
        if(!PostedTelegramNativeContent::isKind($post['media_type']??null))return null;$this->telegramNativePreviewDescriptor($post);$id=(int)$post['id'];$silent=!empty($post['silent']);$rows=[];$first=[['text'=>$silent?'🔕':'🔔','callback_data'=>'draft:silent:'.$id.':'.($silent?0:1)]];
        $allowed=$this->telegramPostInlineButtonsAllowed($post);$buttons=$allowed?$this->decodeJson((string)($post['buttons_json']??'[]')):[];
        if($allowed)$first[]=['text'=>'Кнопки','callback_data'=>'draft:buttons:'.$id];$rows[]=$first;
        if($this->hasInlineButtons($buttons))$rows[]=[['text'=>'🎨 Раскрасить кнопки','callback_data'=>'draft:paintopen:'.$id.':danger']];
        $hasUrl=false;foreach($buttons as$row)foreach((array)$row as$b)if(is_array($b)&&!empty($b['url']))$hasUrl=true;
        if($hasUrl){$rows[]=[['text'=>'Замена ссылок','callback_data'=>'draft:replace_links:'.$id]];$rows[]=[['text'=>(!empty($post['is_ad'])?'✅️':'☑️').' Это рекламный пост','callback_data'=>'draft:ad:'.$id.':'.(empty($post['is_ad'])?1:0)]];}
        $rows[]=[['text'=>!empty($post['pin_after_publish'])?'✅ Закрепить':'☑️ Закрепить','callback_data'=>'draft:pin:'.$id.':'.(empty($post['pin_after_publish'])?1:0)]];
        $rows[]=[['text'=>!empty($post['reply_message_id'])?'Ответный пост: да':'Ответный пост: нет','callback_data'=>'draft:reply:'.$id]];
        array_push($rows,...$this->telegramPostLinkDraftRows($post));$rows[]=[$this->telegramTopHoldPostButton($post,'d')];
        $rows[]=[['text'=>'← Отменить','callback_data'=>'draft:cancel:'.$id],['text'=>'Далее →','callback_data'=>'draft:next:'.$id]];
        return$this->telegramDraftEditorKeyboard($post,$rows,$expanded);
    }
}
