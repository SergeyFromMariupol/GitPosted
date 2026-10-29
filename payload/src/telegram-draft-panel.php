<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** A private UI edit receipt. It never authorizes publication or changes a post. */
final class PostedTelegramDraftPanel
{
    public static function key(int $actor,int $post):string
    {
        if($actor<1||$post<1)throw new InvalidArgumentException('Invalid private draft panel.');
        return 'tg_draft_panel_'.$post.'_'.$actor;
    }
    public static function canonical(mixed $value):string
    {
        $sort=static function(mixed $v)use(&$sort):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$item)$v[$k]=$sort($item);return$v;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    public static function digest(mixed $value):string{return hash('sha256',self::canonical($value));}
    public static function normalize(array $r):array
    {
        $keys=['version','actor_id','post_id','channel_id','author_id','owner_id','preview_ids','control_id','target_id','kind','preview_fingerprint','binding','original','phase','nonce','payload_hash','attempted_at','attempts','acknowledged'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||($r['version']??null)!==1)throw new RuntimeException('Invalid private draft panel receipt.');
        foreach(['actor_id','post_id','channel_id','author_id','owner_id','control_id','target_id','attempted_at','attempts']as$k)if(!is_int($r[$k])||$r[$k]<1)throw new RuntimeException('Invalid private panel identity.');
        if($r['attempts']>100||$r['control_id']!==$r['target_id']||!is_bool($r['acknowledged'])||!in_array($r['kind'],['text','caption','group_control'],true)||!in_array($r['phase'],['intent','shown','unknown','restored'],true))throw new RuntimeException('Invalid private panel state.');
        foreach(['preview_fingerprint','binding','payload_hash']as$k)if(!is_string($r[$k])||preg_match('/\A[a-f0-9]{64}\z/D',$r[$k])!==1)throw new RuntimeException('Invalid private panel digest.');
        if(!is_string($r['nonce'])||preg_match('/\A[a-f0-9]{32}\z/D',$r['nonce'])!==1||!is_array($r['preview_ids'])||!array_is_list($r['preview_ids'])||count($r['preview_ids'])<1||count($r['preview_ids'])>110)throw new RuntimeException('Invalid private panel vector.');
        foreach($r['preview_ids']as$id)if(!is_int($id)||$id<1)throw new RuntimeException('Invalid private preview MID.');
        if(count(array_unique($r['preview_ids']))!==count($r['preview_ids'])||($r['kind']==='group_control'?in_array($r['target_id'],$r['preview_ids'],true):$r['target_id']!==$r['preview_ids'][count($r['preview_ids'])-1]))throw new RuntimeException('Invalid private panel target.');
        if(!is_array($r['original'])||count($r['original'])!==2||array_diff(array_keys($r['original']),['text','entities'])!==[]||!is_string($r['original']['text'])||!is_array($r['original']['entities'])||!array_is_list($r['original']['entities'])||count($r['original']['entities'])>128||strlen($r['original']['text'])>16384||strlen(self::canonical($r))>49152)throw new RuntimeException('Invalid private control snapshot.');
        if($r['kind']!=='group_control'&&($r['original']['text']!==''||$r['original']['entities']!==[]))throw new RuntimeException('Unexpected private control snapshot.');
        return$r;
    }
    public static function read(PDO $pdo,int $actor,int $post,bool $lock=false):?array
    {
        if($lock&&!$pdo->inTransaction())throw new RuntimeException('Private panel lock requires a transaction.');
        $q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=?'.($lock?' FOR UPDATE':''));$q->execute([self::key($actor,$post)]);$raw=$q->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>49152)throw new RuntimeException('Invalid private panel storage.');$r=self::normalize(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
        if($r['actor_id']!==$actor||$r['post_id']!==$post)throw new RuntimeException('Private panel identity changed.');return$r;
    }
    public static function write(PDO $pdo,array $r):void
    {
        if(!$pdo->inTransaction())throw new RuntimeException('Private panel write requires a transaction.');$r=self::normalize($r);
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key($r['actor_id'],$r['post_id']),self::canonical($r)]);
    }
}

trait GitPostedTelegramDraftPanel
{
    private function handleTelegramDraftPanelCallback(array $callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||preg_match('/\Adraft:(next|cancel_timer)(?::|\z)/D',$raw)!==1)return false;
        $actor=$callback['from']['id']??null;$message=$callback['message']??null;$chat=is_array($message)?($message['chat']??null):null;
        if($this->pdo->inTransaction()||!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||($message['from']['is_bot']??null)!==true
            ||preg_match('/\Adraft:(next|cancel_timer):([1-9][0-9]{0,18})\z/D',$raw,$m)!==1||(string)(int)$m[2]!==$m[2])return true;
        if(!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo))return true;
        $postId=(int)$m[2];$post=$this->post($postId);if(!is_array($post)||($post['status']??null)!=='draft'||!$this->canManagePost($actor,$postId))return true;
        // Native and paid previews retain their existing distinct flow.
        if($this->isNativeRepost($post)||$this->telegramPostOptions($post)['paid_media_stars']!==null)return false;
        $target=$this->callbackTarget($callback);if(!is_array($target))return true;
        $seen=$message['text']??($message['caption']??null);if(is_string($seen))$target['draft_panel_seen']=$seen;
        if(is_string($message['text']??null)){
            $entities=is_array($message['entities']??null)?$message['entities']:[];
            $target['draft_panel_original']=['text'=>$message['text'],'entities'=>$this->normalizeTelegramEntities($message['text'],$entities)];
        }
        $this->answerCallback($callback);
        // A stale navigation button never consumes a newer collecting/input workflow.
        if($m[1]==='next')$this->showDraftSendSettings($actor,$postId,$target);
        else $this->showDraftActions($actor,$postId,'',$target);
        return true;
    }

    private function telegramDraftPanelBinding(int $actor,array $post,array $channel,array $plan):?array
    {
        if($actor<1||(int)($post['id']??0)<1||(string)($post['status']??'')!=='draft'||$this->isNativeRepost($post)||!in_array($plan['mode']??null,['none','single','group'],true))return null;
        $owner=(int)($channel['added_by']??0);$author=(int)($post['author_id']??0);$control=$post['telegram_preview_control_message_id']??null;
        if($owner<1||$author<1||(string)($post['telegram_preview_chat_id']??'')!==(string)$actor||!is_numeric($control)||(string)(int)$control!==(string)$control||(int)$control<1)return null;
        $ids=json_decode((string)($post['telegram_preview_message_ids']??''),true);if(!is_array($ids)||!array_is_list($ids))return null;
        try{$ids=PostedTelegramPreviewProof::requireKnownIds($ids,PostedTelegramPreviewProof::expectedCount($plan,$plan['mode']!=='group'));}catch(Throwable){return null;}
        $fp=(string)($post['telegram_preview_fingerprint']??'');if(preg_match('/\A[a-f0-9]{64}\z/D',$fp)!==1||!hash_equals($fp,(string)$plan['fingerprint']))return null;
        if($plan['mode']==='group'){if(in_array((int)$control,$ids,true))return null;$kind='group_control';}
        else{if((int)$control!==$ids[count($ids)-1])return null;$kind=$plan['text_parts']!==[]?'text':'caption';if($plan['mode']==='none')$kind='text';}
        $b=['actor_id'=>$actor,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'author_id'=>$author,'owner_id'=>$owner,'preview_ids'=>$ids,'control_id'=>(int)$control,'target_id'=>(int)$control,'kind'=>$kind,'preview_fingerprint'=>$fp];
        $b['binding']=PostedTelegramDraftPanel::digest($b);return$b;
    }

    private function telegramDraftPanelRestorePayload(array $post,array $plan,array $binding,array $original,array $keyboard):array
    {
        $markup=['inline_keyboard'=>array_merge((array)$plan['buttons'],$keyboard)];
        $base=['chat_id'=>(string)$binding['actor_id'],'message_id'=>$binding['target_id'],'reply_markup'=>$markup];
        if($binding['kind']==='group_control')return['method'=>'editMessageText','payload'=>array_merge($base,['text'=>$original['text'],'entities'=>$original['entities'],'link_preview_options'=>['is_disabled'=>true]])];
        if($binding['kind']==='caption'){
            $item=$plan['media'][0]??[];return['method'=>'editMessageCaption','payload'=>array_merge($base,['caption'=>(string)($item['caption']??''),'caption_entities'=>(array)($item['caption_entities']??[])])];
        }
        $part=$plan['text_parts'][count($plan['text_parts'])-1]??['text'=>'[медиа без текста]','entities'=>[]];
        return['method'=>'editMessageText','payload'=>array_merge($base,$this->linkPreviewExtra($post),['text'=>(string)$part['text'],'entities'=>(array)($part['entities']??[])])];
    }

    /** null means no owned panel: only that case may use the legacy new-menu path. */
    private function showTelegramDraftBoundSettings(int $actor,int $postId,?array $target,string $text,array $extra):?bool
    {
        if($this->pdo->inTransaction())return false;
        return$this->telegramDraftPanelEdit($actor,$postId,'settings',$target,['text'=>$text,'extra'=>$extra],[]);
    }
    private function restoreTelegramDraftBoundPanel(int $actor,array $post,array $keyboard,string $prefix,?array $target):?bool
    {
        if($this->pdo->inTransaction())return false;
        $id=(int)($post['id']??0);if($id<1)return false;
        try{$r=PostedTelegramDraftPanel::read($this->pdo,$actor,$id);}catch(Throwable){return false;}
        if($r===null)return null;
        // Explicit status/error prefixes keep their normal rendering, after a known panel is restored.
        $result=$this->telegramDraftPanelEdit($actor,$id,'restore',$target,null,$keyboard);
        return$result===true&&trim($prefix)!==''?null:$result;
    }

    private function telegramDraftPanelBlocksOuterSql(int $actor,int $postId):bool
    {
        if(!$this->pdo->inTransaction())return false;
        try{return PostedTelegramDraftPanel::read($this->pdo,$actor,$postId)!==null;}catch(Throwable){return true;}
    }

    private function telegramDraftPanelEdit(int $actor,int $postId,string $action,?array $target,?array $settings,array $keyboard):?bool
    {
        $v=PostedTelegramDraftPanel::class;if($this->pdo->inTransaction()||!PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo)||!in_array($action,['settings','restore'],true))return false;
        $hint=$this->post($postId);if(!is_array($hint)||!$this->canManagePost($actor,$postId))return false;$channelId=(int)$hint['channel_id'];
        $lockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);$q=$this->pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$lockName]);if((int)$q->fetchColumn()!==1)return false;
        $receipt=null;
        try{
            $prepared=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post,array$channel)use($actor,$postId,$action,$target,$settings,$keyboard,$v):mixed{
                $this->assertTelegramAlbumPreviewClaim($post,null);
                $old=$v::read($this->pdo,$actor,$postId,true);$plan=$this->telegramPreviewPlan($post);$binding=$this->telegramDraftPanelBinding($actor,$post,$channel,$plan);
                if($binding===null){
                    if($old===null)return['fallback'=>true];
                    if(in_array($old['phase'],['shown','restored'],true)&&(!hash_equals($old['preview_fingerprint'],(string)$plan['fingerprint'])
                        ||(string)($post['telegram_preview_chat_id']??'')!==(string)$actor||$this->isNativeRepost($post)))return['fallback'=>true];
                    return false;
                }
                if($old!==null&&!hash_equals($old['binding'],$binding['binding'])){
                    // Never turn an unresolved edit into a new preview after a content/owner change.
                    if(in_array($old['phase'],['intent','unknown'],true))return false;
                    $newVector=$old['preview_ids']!==$binding['preview_ids']||$old['control_id']!==$binding['control_id'];
                    if($action==='restore')return$newVector||!hash_equals($old['preview_fingerprint'],$binding['preview_fingerprint'])?['fallback'=>true]:false;
                    // Current owner/author changes alone cannot bless the old private vector.
                    if(!$newVector)return false;
                    $old=null;
                }
                if($action==='restore'&&($old===null||$old['phase']==='restored'))return['fallback'=>true];
                if($action==='settings'&&($target===null||($target['chat_id']??null)!==$actor||(int)($target['message_id']??0)!==$binding['target_id']))return$old===null?['fallback'=>true]:false;
                $original=$old['original']??['text'=>'','entities'=>[]];
                if($binding['kind']==='group_control'&&$old===null){
                    $raw=$target['draft_panel_original']??null;
                    if(!is_array($raw)||!is_string($raw['text']??null)||$raw['text']===''||$this->telegramUtf16Length($raw['text'])>4096||!is_array($raw['entities']??null))return false;
                    $original=['text'=>$raw['text'],'entities'=>$this->normalizeTelegramEntities($raw['text'],$raw['entities'])];
                }
                if($action==='settings'){
                    $extra=$settings['extra'];unset($extra['preserve_message']);
                    $field=$binding['kind']==='caption'?'caption':'text';if($field==='caption')unset($extra['disable_web_page_preview'],$extra['link_preview_options']);
                    $edit=['method'=>$field==='caption'?'editMessageCaption':'editMessageText','payload'=>array_merge(['chat_id'=>(string)$actor,'message_id'=>$binding['target_id']],$extra,[$field=>$settings['text']])];
                }else $edit=$this->telegramDraftPanelRestorePayload($post,$plan,$binding,$original,$keyboard);
                $hash=$v::digest($edit);$now=time();
                if($action==='settings'&&$old!==null&&$old['phase']==='shown'&&$old['acknowledged']&&hash_equals($old['payload_hash'],$hash)
                    &&($target['draft_panel_seen']??null)===html_entity_decode(strip_tags($settings['text']),ENT_QUOTES|ENT_HTML5,'UTF-8'))return true;
                if($old!==null&&in_array($old['phase'],['intent','unknown'],true)&&($now<$old['attempted_at']||$now-$old['attempted_at']<15||$old['attempts']>=100))return false;
                $attempts=$old!==null&&in_array($old['phase'],['intent','unknown'],true)?$old['attempts']+1:1;
                $r=array_merge(['version'=>1],$binding,['original'=>$original,'phase'=>'intent','nonce'=>bin2hex(random_bytes(16)),'payload_hash'=>$hash,'attempted_at'=>$now,'attempts'=>$attempts,'acknowledged'=>false]);
                $r=$v::normalize($r);$v::write($this->pdo,$r);return['record'=>$r,'edit'=>$edit];
            });
            if(!is_array($prepared))return$prepared===true;if(($prepared['fallback']??false)===true)return null;$receipt=$prepared['record'];
            // Re-check the exact current owner, author, vector and intent after the intent COMMITTED.
            $permit=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post,array$channel)use($actor,$postId,$receipt,$v):bool{
                $this->assertTelegramAlbumPreviewClaim($post,null);$r=$v::read($this->pdo,$actor,$postId,true);$b=$this->telegramDraftPanelBinding($actor,$post,$channel,$this->telegramPreviewPlan($post));
                return is_array($r)&&is_array($b)&&$r['phase']==='intent'&&hash_equals($v::digest($r),$v::digest($receipt))&&hash_equals($r['binding'],$b['binding']);
            });
            if($permit!==true)return false;
            if($this->pdo->inTransaction())throw new RuntimeException('Private panel transport cannot run in SQL.');
            $edit=$prepared['edit'];$reply=$this->telegram->call($edit['method'],$edit['payload']);$message=$reply['result']??null;
            if(($reply['ok']??null)!==true||!is_array($message)||($message['message_id']??null)!==$receipt['target_id']||(string)($message['chat']['id']??'')!==(string)$actor)throw new RuntimeException('Unconfirmed private panel edit.');
            $accepted=true;
            $saved=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post,array$channel)use($actor,$postId,$receipt,$action,$v):bool{
                $r=$v::read($this->pdo,$actor,$postId,true);$b=$this->telegramDraftPanelBinding($actor,$post,$channel,$this->telegramPreviewPlan($post));
                if(!is_array($r)||!is_array($b)||!hash_equals($v::digest($r),$v::digest($receipt))||!hash_equals($r['binding'],$b['binding']))return false;
                $r['phase']=$action==='settings'?'shown':'restored';$r['acknowledged']=true;$v::write($this->pdo,$r);return true;
            });
            if($saved!==true)$this->telegramDraftPanelUnknown($receipt,true);
            return$saved===true;
        }catch(Throwable$error){
            // NOT_MODIFIED alone is not evidence that the desired body/markup was applied.
            if(is_array($receipt))try{$this->telegramDraftPanelUnknown($receipt,!empty($accepted));}catch(Throwable){}
            error_log('GitPosted private draft panel edit deferred: '.get_class($error));return false;
        }finally{try{$r=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);}catch(Throwable$error){error_log('GitPosted private draft panel lock release failed: '.get_class($error));}}
    }

    private function telegramDraftPanelUnknown(array $expected,bool $accepted):void
    {
        if($this->pdo->inTransaction())return;$v=PostedTelegramDraftPanel::class;$this->pdo->beginTransaction();try{
            $r=$v::read($this->pdo,$expected['actor_id'],$expected['post_id'],true);
            if(is_array($r)&&hash_equals($v::digest($r),$v::digest($expected))){$r['phase']='unknown';$r['acknowledged']=$accepted;$v::write($this->pdo,$r);}
            $this->pdo->commit();
        }catch(Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function telegramDraftSettingsPanelView(array $post,array $sendChannel):array
    {
        $postId=(int)$post['id'];
        return ['text'=>$this->customEmojiHtml('5258260149037965799','💼').' <b>НАСТРОЙКИ ОТПРАВКИ</b>' . "\n\n" . 'Пост готов к публикации в канале ' . $this->channelTitleHtml($sendChannel) . '.','extra'=>[
            'parse_mode'=>'HTML','disable_web_page_preview'=>true,
            'reply_markup' => ['inline_keyboard' => [
                [['text' => 'Таймер удаления: ' . mb_strtolower($this->telegramDeleteTimerLabel((int) ($post['delete_after_minutes'] ?? 0))), 'callback_data' => 'draft:delete_timer:' . $postId]],
                [['text' => 'Автоповтор: ' . mb_strtolower($this->minutesLabel((int) ($post['repeat_interval_minutes'] ?? 0))), 'callback_data' => 'draft:repeat_timer:' . $postId]],
                [['text'=>'Копировать','callback_data'=>'mp:open:'.$postId.':c'],['text'=>'Переслать','callback_data'=>'mp:open:'.$postId.':f']],
                [['text' => '📅 Серия публикаций', 'callback_data' => 'series:open:' . $postId]],
                [['text' => '⏰ Отложить', 'callback_data' => 'draft:schedule:' . $postId], ['text' => '🔥 Опубликовать', 'callback_data' => 'draft:send:' . $postId]],
                [['text' => '← Назад', 'callback_data' => 'draft:cancel_timer:' . $postId]],
            ]],
        ]];
    }
}
