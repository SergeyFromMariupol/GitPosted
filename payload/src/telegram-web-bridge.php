<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-web-request.php';
require_once __DIR__.'/telegram-init-data.php';

/** Private prototype. Connect as a PostedBot trait only after isolated integration review. */
trait GitPostedTelegramWebBridge
{
    private function telegramWebSetting(string $key,bool $locked=false):?array
    {
        $query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($locked?' FOR UPDATE':''));$query->execute([$key]);$raw=$query->fetchColumn();
        if($raw===false)return null;
        try{$value=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PostedTelegramWebException('invalid_context','Откройте настройки поста заново в боте.');}
        if(!is_array($value))throw new PostedTelegramWebException('invalid_context','Откройте настройки поста заново в боте.');return$value;
    }
    private function telegramWebWrite(string $key,array $value):void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Web metadata writes require the post transaction.');
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($json)>49152)throw new PostedTelegramWebException('context_full','Откройте настройки поста заново в боте.');
        $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$json]);
    }
    private function telegramWebLaunchKey(string $selector):string{return'tgw_l_'.substr(hash('sha256',$selector),0,58);}
    private function telegramWebOperationKey(int $postId):string{return'telegram_web_operation_'.$postId;}
    private function telegramWebDenied():never{throw new PostedTelegramWebException('access_denied','Нет доступа к этому посту. Откройте его заново в боте.',403);}
    private function telegramWebReady():bool{return($this->config['telegram_web_window_enabled']??false)===true&&(!array_key_exists('telegram_bot_enabled',$this->config)||$this->config['telegram_bot_enabled']===true)&&PostedTelegramWebRequest::serviceBaseUrl($this->config['base_url']??null)!==null;}

    /** Public selector is not authentication; every later request must verify signed initData. */
    public function telegramWebIssueLaunch(int $actor,int $postId,?int $now=null):array
    {
        if(!$this->telegramWebReady())throw new PostedTelegramWebException('not_connected','Веб-окно пока недоступно.',503);
        if($this->pdo->inTransaction())throw new PostedTelegramWebException('busy_context','Откройте настройки поста заново после завершения текущего действия.');
        $baseUrl=PostedTelegramWebRequest::serviceBaseUrl($this->config['base_url']??null);
        $now??=time();PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);if(!$this->isKnownActiveUser($this->user($actor)))$this->telegramWebDenied();
        $selector=bin2hex(random_bytes(24));$expiresAt=$now+900;
        $created=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post,array$channel)use($actor,$now,&$selector,&$expiresAt):bool{
            $now=max($now,time());$expiresAt=$now+900;
            if($this->telegramWebAttempt($post)!==null)throw new PostedTelegramWebException('already_started','Отправка этого поста уже запускалась. Откройте его карточку в боте.');
            $indexKey='telegram_web_index_'.$post['id'].'_'.$actor;$index=$this->telegramWebSetting($indexKey,true)??['version'=>1,'entries'=>[]];
            if(($index['version']??null)!==1||!is_array($index['entries']??null))throw new PostedTelegramWebException('invalid_context','Откройте настройки поста заново в боте.');
            $entries=[];$pendingSchedule=null;$pendingTimezone=null;
            foreach($index['entries']as$entry){
                if(!is_array($entry)||!PostedTelegramWebRequest::hex($entry['hash']??null,64)||!PostedTelegramWebRequest::hex($entry['selector']??null,48)||!hash_equals($entry['hash'],hash('sha256',$entry['selector']))||!is_int($entry['expires_at']??null)||(array_key_exists('retired',$entry)&&!is_bool($entry['retired'])))throw new PostedTelegramWebException('invalid_context','Откройте настройки поста заново в боте.');
                if($entry['expires_at']<=$now){$key=$this->telegramWebLaunchKey($entry['selector']);$expired=$this->telegramWebSetting($key,true);if($expired!==null&&($expired['actor_id']??null)===$actor&&($expired['post_id']??null)===(int)$post['id']&&($expired['expires_at']??PHP_INT_MAX)<=$now)$this->pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([$key]);}
                else{
                    $existing=$this->telegramWebSetting($this->telegramWebLaunchKey($entry['selector']),true);
                    if($existing!==null&&($existing['actor_id']??null)===$actor&&($existing['post_id']??null)===(int)$post['id']&&($existing['owner_id']??null)===(int)$channel['added_by']&&($existing['author_id']??null)===(int)$post['author_id']&&($existing['chat_id']??null)===(string)$channel['chat_id']){
                        $this->telegramWebValidateLaunch($existing,$actor,$now);
                        if(!($entry['retired']??false)&&$existing['sequence']<64&&count($existing['receipts'])<64){$selector=$entry['selector'];$expiresAt=$existing['expires_at'];return true;}
                        $entry['retired']=true;
                        $pendingTimezone=($existing['schedule_tz']??null)===$this->userTimezone($actor)?$existing['schedule_tz']:null;
                        $pendingSchedule=$pendingTimezone!==null&&is_string($existing['schedule_at']??null)?$existing['schedule_at']:null;
                    }
                    $entries[]=$entry;
                }
            }
            if(count($entries)>=4)throw new PostedTelegramWebException('too_many_windows','Лимит окон для этого поста исчерпан. Дождитесь истечения времени старых окон или продолжите в боте.');
            $launch=['version'=>1,'actor_id'=>$actor,'post_id'=>(int)$post['id'],'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],
                'owner_id'=>(int)$channel['added_by'],'author_id'=>(int)$post['author_id'],'created_at'=>$now,'expires_at'=>$now+900,'sequence'=>0,'schedule_at'=>$pendingSchedule,'schedule_tz'=>$pendingTimezone,'confirmation'=>null,'receipts'=>[]];
            $this->telegramWebWrite($this->telegramWebLaunchKey($selector),$launch);$entries[]=['hash'=>hash('sha256',$selector),'selector'=>$selector,'expires_at'=>$now+900];$this->telegramWebWrite($indexKey,['version'=>1,'entries'=>$entries]);return true;
        },false,false,true);
        if($created!==true)$this->telegramWebDenied();return['url'=>$baseUrl.'/?telegram_webapp=1&launch='.$selector,'expires_at'=>$expiresAt];
    }

    /** Future draft-keyboard hook. The global readiness flag defaults OFF until deployment review. */
    private function telegramWebInlineNextButton(int$actor,array$post):array
    {
        $classic=['text'=>'Далее →','callback_data'=>'draft:next:'.(int)$post['id']];
        if($this->pdo->inTransaction())return$classic;
        if(!$this->telegramWebReady()||(int)($this->user($actor)['setting_web_mode']??0)!==1)return$classic;
        try{$launch=$this->telegramWebIssueLaunch($actor,(int)$post['id']);return['text'=>'Далее →','web_app'=>['url'=>$launch['url']]];}
        catch(Throwable$error){error_log('GitPosted web launch unavailable: '.$error::class);return$classic;}
    }

    /** One future preview hook covers both the regular and expanded classic editor. */
    private function telegramWebDraftNextKeyboard(int$actor,array$post,array$keyboard):array
    {
        if($this->pdo->inTransaction()||!$this->telegramWebReady())return$keyboard;
        $eligible=false;$callback='draft:next:'.(int)($post['id']??0);
        foreach($keyboard as$row)foreach((array)$row as$button)if(is_array($button)&&($button['callback_data']??null)===$callback&&in_array($button['text']??null,['Далее →','Next →'],true))$eligible=true;
        if(!$eligible)return$keyboard;
        $replacement=$this->telegramWebInlineNextButton($actor,$post);
        foreach($keyboard as&$row)foreach($row as&$button)if(is_array($button)&&($button['callback_data']??null)===$callback&&in_array($button['text']??null,['Далее →','Next →'],true)){$replacement['text']=$button['text'];$button=$replacement;}
        unset($button,$row);return$keyboard;
    }

    private function telegramWebAttempt(array$post):?array
    {
        $query=$this->pdo->prepare('SELECT state,message_slots_json,pending_parts_json,gate_channel_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$query->execute([(int)$post['id']]);$row=$query->fetch();return is_array($row)?$row:null;
    }
    private function telegramWebValidateLaunch(array $launch,int $actor,int $now):void
    {
        if(($launch['version']??null)!==1||($launch['actor_id']??null)!==$actor||!is_int($launch['post_id']??null)||$launch['post_id']<1||!is_int($launch['channel_id']??null)||$launch['channel_id']<1
            ||!is_string($launch['chat_id']??null)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$launch['chat_id'])!==1||!is_int($launch['owner_id']??null)||!is_int($launch['author_id']??null)
            ||!is_int($launch['created_at']??null)||!is_int($launch['expires_at']??null)||$launch['created_at']>$now+30||$launch['expires_at']-$launch['created_at']!==900
            ||!is_int($launch['sequence']??null)||$launch['sequence']<0||$launch['sequence']>64||!is_array($launch['receipts']??null)||count($launch['receipts'])>64)$this->telegramWebDenied();
        if($launch['expires_at']<=$now)throw new PostedTelegramWebException('expired_context','Время этого окна истекло. Откройте пост заново в боте.',401);
    }
    private function telegramWebBinding(array$post,array$channel,array$launch):void
    {
        if((int)$post['id']!==$launch['post_id']||(int)$post['channel_id']!==$launch['channel_id']||(int)$channel['id']!==$launch['channel_id']
            ||(string)$channel['chat_id']!==$launch['chat_id']||(int)$channel['added_by']!==$launch['owner_id']||(int)$post['author_id']!==$launch['author_id'])throw new PostedTelegramWebException('stale_context','Права или канал поста изменились. Откройте окно заново.');
    }
    private function telegramWebOperation(array$post,bool$locked=false):?array
    {
        $operation=$this->telegramWebSetting($this->telegramWebOperationKey((int)$post['id']),$locked);if($operation===null)return null;
        if(($operation['version']??null)!==1||($operation['post_id']??null)!==(int)$post['id']||($operation['channel_id']??null)!==(int)$post['channel_id']||!is_int($operation['actor_id']??null)||$operation['actor_id']<1
            ||!PostedTelegramWebRequest::hex($operation['request_id']??null,32)||!PostedTelegramWebRequest::hex($operation['revision']??null,64)||!is_string($operation['launch_key']??null)
            ||preg_match('/\Atgw_l_[a-f0-9]{58}\z/D',$operation['launch_key'])!==1||!in_array($operation['kind']??null,['publish','schedule'],true)||!in_array($operation['status']??null,['preparing','processing','scheduled','not_started'],true)
            ||!is_int($operation['created_at']??null)||!is_int($operation['deadline']??null)||$operation['deadline']<=$operation['created_at']||$operation['deadline']>$operation['created_at']+120)throw new PostedTelegramWebException('invalid_context','Состояние отправки требует проверки в боте.');
        return$operation;
    }
    private function telegramWebRevision(int$actor,array$post,array$channel,array$launch):string
    {
        $post['chat_id']=(string)$channel['chat_id'];$settings=[];foreach(['status','scheduled_at','delete_after_minutes','delete_report_enabled','repeat_interval_minutes','repeat_until','silent','pin_after_publish','comments_enabled','is_ad','manager_category_id','link_preview_enabled','link_preview_large','link_preview_above','import_json']as$key)$settings[$key]=$post[$key]??null;
        // The core claim performs this exact legacy 48-hour safety normalization before delivery.
        if((int)($settings['delete_after_minutes']??0)===2880)$settings['delete_after_minutes']=2875;
        $top=PostedTelegramTopHold::submission($this->pdo,$post);if($top!==null)$settings['top']=$top;
        return PostedTelegramWebRequest::hash(['actor'=>$actor,'post'=>(int)$post['id'],'author'=>(int)$post['author_id'],'owner'=>(int)$channel['added_by'],'submission'=>$this->telegramSubmissionKey($post),'settings'=>$settings,'timezone'=>$this->userTimezone($actor),
            'sequence'=>$launch['sequence'],'schedule_at'=>$launch['schedule_at']??null,'schedule_tz'=>$launch['schedule_tz']??null]);
    }
    private function telegramWebReadOperation(array$post,?array$operation):?array
    {
        if($operation===null)return null;$attempt=$this->telegramWebAttempt($post);$status=(string)($post['status']??'');$state='processing';$message='Telegram обрабатывает отправку. Повторно отправлять пост не нужно.';
        if($attempt!==null&&($attempt['state']??null)!=='completed')return['request_id'=>$operation['request_id']??null,'status'=>'processing','message'=>$message];
        if($status==='scheduled'&&($operation['kind']??null)==='schedule'){$state='scheduled';$message='Пост запланирован.';}
        elseif($status==='sent'&&is_array($attempt)&&($attempt['state']??null)==='completed'&&$attempt['gate_channel_id']===null){
            $slots=json_decode((string)($attempt['message_slots_json']??''),true);$parts=json_decode((string)($attempt['pending_parts_json']??''),true);
            if(is_array($slots)&&$slots!==[]&&is_array($parts)&&count($slots)===count($parts)&&array_filter($slots,static fn(mixed$id):bool=>!is_int($id)||$id<1)===[]){$state='sent';$message='Пост опубликован.';}
        }elseif(in_array($status,['deleted','cancelled','failed'],true)){$state='failed';$message='Операция остановлена. Проверьте результат в боте.';}
        elseif($status==='draft'&&$attempt===null&&($operation['status']??null)==='not_started'){$state='not_started';$message='Отправка не начиналась. Обновите окно и подтвердите действие заново.';}
        return['request_id'=>$operation['request_id']??null,'status'=>$state,'message'=>$message];
    }

    private function telegramWebTopConflict(PostedTelegramTopHoldException $error,int $actor):never
    {
        $message=$error->getMessage();$blocking=$error->blocking;
        if(is_array($blocking)){
            $url=$blocking['post_link']??null;
            if(is_string($url)&&preg_match('~\Ahttps://t\.me/(?:[A-Za-z0-9_]{1,64}|c/[1-9][0-9]{0,18})/[1-9][0-9]{0,18}\z~D',$url)===1)$message.=' Пост: '.$url.'.';
            if(is_int($blocking['unlock_at']??null)&&$blocking['unlock_at']>0&&!in_array($blocking['state']??null,['reserved','uncertain'],true))$message.=' Публикация возможна после '.(new DateTimeImmutable('@'.$blocking['unlock_at']))->setTimezone($this->userTimezoneObject($actor))->format('d.m.Y H:i:s').' ('.$this->userTimezone($actor).').';
        }
        throw new PostedTelegramWebException('top_'.$error->reason,$message,409);
    }
    private function telegramWebTopState(int $actor,array $post,array $channel,bool $mutable):array
    {
        $top=PostedTelegramTopHold::state($this->pdo,$this->config,$post,$channel,$actor);
        $top['can_enable']=$mutable&&$top['can_enable'];$top['can_disable']=$mutable&&$top['can_disable'];
        $top['reason']=match($top['reason']){'quota_exhausted'=>'У автора закончился лимит топов для этого канала.','post_locked'=>'Пост уже отправляется или опубликован. Настройку топа менять нельзя.',''=>'',default=>'Настройки топа требуют проверки.'};
        if($top['hold']!==null){$at=$top['hold']['until'];$top['hold']['until_label']=is_int($at)?(new DateTimeImmutable('@'.$at))->setTimezone($this->userTimezoneObject($actor))->format('d.m.Y H:i:s').' ('.$this->userTimezone($actor).')':'';}
        return$top;
    }
    private function telegramWebState(int$actor,array$post,array$channel,array$permission,array$launch):array
    {
        $post['chat_id']=(string)$channel['chat_id'];$attempt=$this->telegramWebAttempt($post);$mutable=($post['status']??null)==='draft'&&$attempt===null;
        $basic=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'basic');$premium=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'premium');
        // A paid trial's count is checked outside SQL only on preparation/confirmation; it is never assumed licensed here.
        $potentialBasic=$basic['allowed']||$basic['state']==='member_count_unknown';
        $postOptions=$this->telegramPostOptions($post);
        if(PostedTelegramNativeContent::isKind($post['media_type']??null)&&!$this->isNativeRepost($post)){$native=PostedTelegramNativeStore::load($this->pdo,$post);if($native===null)throw new PostedTelegramWebException('invalid_context','Содержимое поста требует проверки. Пост сохранён.');$this->telegramNativeAssertOrdinaryPayload($post,$this->effectivePostPayload($post));}

        $timer=[];foreach(PostedTelegramWebRequest::TIMER_PRESETS as$value)$timer[]=['value'=>$value,'label'=>$this->telegramDeleteTimerLabel($value)];
        $effectiveTimer=(int)($post['delete_after_minutes']??0);if($effectiveTimer===2880)$effectiveTimer=2875;
        $repeat=[];foreach(PostedTelegramWebRequest::REPEAT_PRESETS as$value)$repeat[]=['value'=>$value,'label'=>$this->minutesLabel($value)];
        $displayTimer=$effectiveTimer===2875?2880:$effectiveTimer;
        $timerComponents=$displayTimer>=0&&$displayTimer<=PostedTelegramWebRequest::MAX_TIMER_CUSTOM_MINUTES
            ?['days'=>intdiv($displayTimer,1440),'hours'=>intdiv($displayTimer%1440,60),'minutes'=>$displayTimer%60]:null;
        $timerMode=$effectiveTimer<=0?'off':($effectiveTimer>2875?'manual':'automatic');
        $timerWarning=$timerMode==='manual'?'Пост понадобится удалить вручную. Автоудаление для этого срока недоступно.'
            :($effectiveTimer===2875?'«≈48 ч» — это 47 ч 55 мин: 5 минут запаса на работу бота.':'');
        $effectiveRepeat=(int)($post['repeat_interval_minutes']??0);
        $repeatComponents=$effectiveRepeat>=0&&$effectiveRepeat<=PostedTelegramWebRequest::MAX_REPEAT_CUSTOM_MINUTES&&$effectiveRepeat%60===0
            ?['days'=>intdiv($effectiveRepeat,1440),'hours'=>intdiv($effectiveRepeat%1440,60)]:null;
        $choices=[];
        if($mutable&&$premium['allowed'])foreach($this->telegramMultipostChoices($actor,(int)$channel['id'])as$item){$id=$item['channel_id'];$category=$item['category_id'];$options=$postOptions;
            $modes=$options['paid_media_stars']!==null||$options['protect_content']?['copy']:['copy','forward'];
            if(!isset($choices[$id])){$destination=$this->channel($id);$choices[$id]=['id'=>$id,'title'=>(string)$destination['title'],'modes'=>$modes,'categories'=>[],'disabled'=>!$item['licensed'],'reason'=>!$item['licensed']?'Для канала нужна подписка.':''];}
            $choices[$id]['categories'][]=['id'=>$category,'title'=>$category>0?$item['title']:'Общие'];}
        $selected=[];$plan=$this->telegramMultipostRead((int)$post['id']);if($plan!==null)foreach($plan['targets']as$target)$selected[]=['id'=>$target['channel_id'],'mode'=>$target['mode'],'category_id'=>$target['category_id']];
        $validDate=($launch['schedule_tz']??null)===$this->userTimezone($actor);$local=$validDate&&!empty($launch['schedule_at'])?$this->formatStoredForUser($actor,$launch['schedule_at'],'Y-m-d\TH:i'):'';
        $operation=$this->telegramWebOperation($post);$operationState=$this->telegramWebReadOperation($post,$operation);
        if($operationState!==null&&in_array($operationState['status'],['processing','sent','scheduled'],true))$mutable=false;
        $top=$this->telegramWebTopState($actor,$post,$channel,$mutable);$topBlocked=$top['hold']!==null;
        $topPublishReason=$topBlocked?($top['hold']['uncertain']?'Результат предыдущей публикации с топом ещё не подтверждён. Отправка недоступна до проверки.':'В канале действует топ. Публикация возможна после '.$top['hold']['until_label'].'.'):'';
        $repeatBilling=$mutable&&!empty($permission['can_schedule'])&&in_array($premium['state'],['required','expired','revoked'],true);
        $repeatReason=$mutable?(empty($permission['can_schedule'])?'Нет права на планирование в этом канале.'
            :(!$premium['allowed']?($repeatBilling?'У канала нет премиум-подписки. Чтобы продолжить, приобретите подписку для канала.'
                :'Настройки доступа или подписки канала требуют проверки администратором.') :'')):'Пост уже запланирован или отправляется. Проверьте его состояние.';
        $title=trim((string)($post['text']??''));$title=preg_split('/\R/u',$title)[0]??'';$title=$title!==''?mb_substr($title,0,120):'Пост с медиа';
        $previewRaw=(string)($post['text']??'');$previewTruncated=mb_strlen($previewRaw,'UTF-8')>32768;
        $previewText=mb_substr($previewRaw,0,32768,'UTF-8');$author=$this->user((int)$post['author_id']);
        $authorLabel=is_array($author)?trim((string)($author['first_name']??'')):'';
        $authorLabel=mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]/u',' ',$authorLabel)),0,190,'UTF-8');
        if($authorLabel===''&&is_array($author)&&preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',(string)($author['username']??''))===1)$authorLabel='@'.$author['username'];
        $previewNow=(new DateTimeImmutable('@'.time()))->setTimezone($this->userTimezoneObject($actor));$timeLabel=$previewNow->format('H:i');
        return['revision'=>$this->telegramWebRevision($actor,$post,$channel,$launch),'post'=>['title'=>$title,'status'=>(string)$post['status'],'channel_title'=>(string)$channel['title'],
                'preview_text'=>$previewText,'preview_truncated'=>$previewTruncated,'author_label'=>$authorLabel,'time_label'=>$timeLabel,
                'has_media'=>in_array($post['media_type']??'none',['photo','video','animation','document','audio','voice','video_note','sticker','poll','location','contact'],true),
                'forward_allowed'=>$postOptions['paid_media_stars']===null&&!$postOptions['protect_content']],
            'capabilities'=>['can_publish'=>$mutable&&$potentialBasic&&!$topBlocked,'publish_reason'=>$topPublishReason,'can_schedule'=>$mutable&&$potentialBasic&&!empty($permission['can_schedule']),'can_repeat'=>$mutable&&!empty($permission['can_schedule'])&&$premium['allowed'],'can_clear_repeat'=>$mutable,'repeat_requires_subscription'=>$repeatBilling,'repeat_reason'=>$repeatReason,
                'multipost'=>$mutable&&$premium['allowed'],'multipost_requires_subscription'=>$mutable&&in_array($premium['state'],['required','expired','revoked'],true)],
            'timer'=>['selected'=>$effectiveTimer,'selected_label'=>$this->telegramDeleteTimerLabel($effectiveTimer),'choices'=>$timer,
                'display_minutes'=>$displayTimer,'custom_components'=>$timerComponents,'mode'=>$timerMode,'warning'=>$timerWarning,
                'limits'=>['days'=>60,'hours'=>23,'minutes'=>59,'total_minutes'=>PostedTelegramWebRequest::MAX_TIMER_CUSTOM_MINUTES]],
            'repeat'=>['selected'=>$effectiveRepeat,'selected_label'=>$this->minutesLabel($effectiveRepeat),'choices'=>$repeat,
                'display_minutes'=>$effectiveRepeat,'custom_components'=>$repeatComponents,
                'limits'=>['days'=>365,'hours'=>23,'total_minutes'=>PostedTelegramWebRequest::MAX_REPEAT_CUSTOM_MINUTES,'min_positive_minutes'=>60]],
            'schedule'=>['local_value'=>$local,'timezone'=>$this->userTimezone($actor),'today'=>$previewNow->format('Y-m-d')],
            'targets'=>['choices'=>array_values($choices),'selected'=>$selected,'metadata_available'=>$choices!==[]],'top'=>$top,'operation'=>$operationState];
    }

    /** Controller calls this ONLY with the verified initData result, never a client actor field. */
    public function telegramWebAction(array$identity,array$request,?int$now=null):array
    {
        if(!$this->telegramWebReady())throw new PostedTelegramWebException('not_connected','Веб-окно пока недоступно.',503);
        if($this->pdo->inTransaction())throw new PostedTelegramWebException('busy_context','Откройте настройки поста заново после завершения текущего действия.');
        $now??=time();$verified=PostedTelegramWebInitData::verify($request['init_data'],(string)($this->config['bot_token']??''),$now);
        if(($identity['actor_id']??null)!==$verified['actor_id']||($identity['session_digest']??null)!==$verified['session_digest'])$this->telegramWebDenied();$actor=$verified['actor_id'];$authExpires=$verified['expires_at'];
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);if(!$this->isKnownActiveUser($this->user($actor)))$this->telegramWebDenied();
        $key=$this->telegramWebLaunchKey($request['launch']);$hint=$this->telegramWebSetting($key);if($hint===null)$this->telegramWebDenied();$this->telegramWebValidateLaunch($hint,$actor,$now);
        $action=$request['action'];$publishJob=null;
        if($action==='read_calendar')return$this->telegramWebCalendarRead($actor,$hint,$key,$request,$now,$authExpires);
        if($action==='read_target_metadata')return$this->telegramWebTargetMetadataRead($actor,$hint,$key,$request,$now,$authExpires);
        $policyProof=null;
        if(in_array($action,['prepare_publish','prepare_schedule','confirm'],true)&&!isset($hint['receipts'][$request['request_id']])){
            $channel=$this->channel($hint['channel_id']);if(!is_array($channel))$this->telegramWebDenied();$entitlement=$this->telegramPolicyPreflightChannel($channel,'basic');
            if(!$entitlement['allowed'])throw new PostedTelegramWebException('subscription_required','Для публикации в этом канале требуется доступ по подписке или подтверждение числа подписчиков.',403);
            $policyProof=['channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'checked_at'=>time(),'member_count'=>$entitlement['member_count']];
        }
        try{$response=$this->withTelegramManagedPostLock($actor,$hint['post_id'],[],function(array$post,array$channel,array$permission)use($actor,$request,$action,$key,$now,$policyProof,$authExpires,&$publishJob):array{
            $now=max($now,time());
            if($authExpires<=$now)throw new PostedTelegramWebException('expired_init_data','Откройте окно заново в Telegram.',401);
            $launch=$this->telegramWebSetting($key,true);if($launch===null)$this->telegramWebDenied();$this->telegramWebValidateLaunch($launch,$actor,$now);$this->telegramWebBinding($post,$channel,$launch);
            $operation=$this->telegramWebOperation($post,true);
            if($operation!==null&&$post['status']==='draft'&&$this->telegramWebAttempt($post)===null&&$operation['deadline']<=time()&&in_array($operation['status'],['preparing','processing'],true)){$operation['status']='not_started';$this->telegramWebWrite($this->telegramWebOperationKey((int)$post['id']),$operation);}
            $post['chat_id']=(string)$channel['chat_id'];$state=$this->telegramWebState($actor,$post,$channel,$permission,$launch);
            if($action==='read')return['ok'=>true,'state'=>$state];
            if($action==='operation_state'){
                $operation=$this->telegramWebOperation($post,true);
                if($operation===null){$state['operation']=['request_id'=>$request['payload']['request_id'],'status'=>$post['status']==='draft'&&$this->telegramWebAttempt($post)===null?'not_started':'processing','message'=>'Проверьте состояние поста. Повторная отправка не выполнялась.'];return['ok'=>true,'state'=>$state];}
                if(($operation['actor_id']??null)!==$actor||($operation['request_id']??null)!==$request['payload']['request_id'])throw new PostedTelegramWebException('unknown_operation','Не удалось найти эту операцию. Проверьте пост в боте.');
                return['ok'=>true,'state'=>$state];
            }
            $payloadHash=PostedTelegramWebRequest::hash(['action'=>$action,'payload'=>$request['payload'],'revision'=>$request['revision']]);
            $oldReceipt=$launch['receipts'][$request['request_id']]??null;
            if($oldReceipt!==null){if(($oldReceipt['hash']??null)!==$payloadHash)throw new PostedTelegramWebException('request_conflict','Это действие уже использовано. Обновите окно.');return['ok'=>true,'state'=>$state];}
            if(!hash_equals($state['revision'],(string)$request['revision']))throw new PostedTelegramWebException('stale_revision','Пост или настройки изменились. Обновите окно.');
            if((string)$post['status']!=='draft'||$this->telegramWebAttempt($post)!==null)throw new PostedTelegramWebException('already_started','Пост уже запланирован или отправляется. Повторная отправка не выполнялась.');
            if($launch['sequence']>=64||count($launch['receipts'])>=64)throw new PostedTelegramWebException('context_full','Откройте настройки поста заново в боте.');
            $active=$this->telegramWebOperation($post,true);
            if($active!==null&&($active['status']??null)!=='not_started')throw new PostedTelegramWebException('already_started','Операция уже выполняется. Проверьте результат в боте.');
            if($policyProof!==null){
                if($policyProof['channel_id']!==(int)$channel['id']||$policyProof['chat_id']!==(string)$channel['chat_id']||$policyProof['checked_at']<time()-60)throw new PostedTelegramWebException('stale_revision','Канал изменился. Обновите окно.');
                PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$channel,'basic',is_int($policyProof['member_count'])?$policyProof['member_count']:null);
            }
            $confirmation=null;$payload=$request['payload'];
            if(in_array($action,['set_timer','set_timer_custom'],true)){
                $minutes=$action==='set_timer_custom'?PostedTelegramWebRequest::customMinutes($action,$payload):$payload['minutes'];
                $minutes=$this->normalizeTelegramDeleteTimerMinutes($minutes);
                $this->pdo->prepare('UPDATE posted_posts SET delete_after_minutes=?,delete_at=NULL,manual_delete_required=0,delete_notice_sent_at=NULL,delete_attempts=0 WHERE id=? AND status=\'draft\'')->execute([$minutes?:null,(int)$post['id']]);
            }elseif(in_array($action,['set_repeat','set_repeat_custom'],true)){
                $minutes=$action==='set_repeat_custom'?PostedTelegramWebRequest::customMinutes($action,$payload):$payload['minutes'];
                if($minutes>0){
                    if(empty($permission['can_schedule']))$this->telegramWebDenied();
                    $premium=PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'premium');
                    if(!$premium['allowed']){
                        $billing=in_array($premium['state'],['required','expired','revoked'],true);
                        throw new PostedTelegramWebException($billing?'subscription_required':'policy_check_unavailable',$billing
                            ?'У канала нет премиум-подписки. Чтобы продолжить, приобретите подписку для канала.'
                            :'Настройки доступа или подписки канала требуют проверки администратором.',403);
                    }
                }
                $this->replaceTelegramRepeatIntervalLocked($post,$minutes);
            }elseif($action==='set_top'){
                $top=PostedTelegramTopHold::load($this->pdo,$post,true);PostedTelegramTopHold::setLocked($this->pdo,$this->config,$post,$channel,$payload['enabled'],$top['revision']);
                if(($launch['schedule_tz']??null)===$this->userTimezone($actor)&&is_string($launch['schedule_at']??null))PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$channel,$post,$launch['schedule_at']);
            }elseif($action==='set_schedule'){
                if(empty($permission['can_schedule']))$this->telegramWebDenied();
                try{$launch['schedule_at']=PostedPublicationPlan::one(str_replace('T',' ',$payload['local_value']),$this->userTimezoneObject($actor),$this->storageTimezone(),new DateTimeImmutable('@'.$now));}catch(InvalidArgumentException$error){throw new PostedTelegramWebException('invalid_schedule',$error->getMessage(),400);}$launch['schedule_tz']=$this->userTimezone($actor);
                PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$channel,$post,$launch['schedule_at']);
            }elseif($action==='set_targets')$this->telegramWebSetTargets($actor,$post,$channel,$payload['targets']);
            elseif(in_array($action,['prepare_publish','prepare_schedule'],true)){
                $this->validatePostForPublish($post);$kind=$action==='prepare_schedule'?'schedule':'publish';
                $this->telegramWebCheckTargets($actor,$post,$kind==='schedule');
                if($kind==='schedule')$this->telegramWebScheduleAt($actor,$launch,$permission,$now);
                PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$channel,$post,$kind==='schedule'?$launch['schedule_at']:(new DateTimeImmutable('@'.$now))->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s'));
                $token=bin2hex(random_bytes(16));$launch['confirmation']=['hash'=>hash('sha256',$token),'kind'=>$kind,'revision'=>$state['revision'],'expires_at'=>min($now+90,$launch['expires_at']),'used_by'=>null];
                $confirmation=['token'=>$token,'label'=>$kind==='schedule'?'Запланировать':'Опубликовать','summary'=>$kind==='schedule'?'Запланировать пост на '.$this->formatStoredForUser($actor,$launch['schedule_at'],'d.m.Y H:i').'?':'Опубликовать пост сейчас?','expires_at'=>$launch['confirmation']['expires_at']];
            }elseif($action==='confirm'){
                $confirm=$launch['confirmation']??null;
                if(!is_array($confirm)||($confirm['expires_at']??0)<=$now||!is_string($confirm['hash']??null)||!hash_equals($confirm['hash'],hash('sha256',$payload['token']))||($confirm['revision']??null)!==$state['revision']||($confirm['used_by']??null)!==null)throw new PostedTelegramWebException('stale_confirmation','Подтверждение устарело. Обновите окно и проверьте настройки.');
                $kind=$confirm['kind'];$this->validatePostForPublish($post);$this->telegramWebCheckTargets($actor,$post,$kind==='schedule');
                if($kind==='publish')PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$channel,$post,(new DateTimeImmutable('@'.$now))->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s'));
                $operation=['version'=>1,'actor_id'=>$actor,'post_id'=>(int)$post['id'],'channel_id'=>(int)$channel['id'],'launch_key'=>$key,'request_id'=>$request['request_id'],'kind'=>$kind,'revision'=>$state['revision'],'created_at'=>$now,'deadline'=>min($now+120,$authExpires,$launch['expires_at']),'status'=>'preparing'];
                $launch['confirmation']['used_by']=$request['request_id'];
                if($kind==='schedule'){$at=$this->telegramWebScheduleAt($actor,$launch,$permission,$now);PostedTelegramTopHold::assertProjectedScheduleLocked($this->pdo,$channel,$post,$at);$update=$this->pdo->prepare("UPDATE posted_posts SET status='scheduled',scheduled_at=? WHERE id=? AND status='draft'");$update->execute([$at,(int)$post['id']]);if($update->rowCount()!==1)throw new PostedTelegramWebException('stale_revision','Пост уже изменился.');$operation['status']='scheduled';}
                else$publishJob=['actor'=>$actor,'post_id'=>(int)$post['id'],'request_id'=>$request['request_id'],'key'=>$key,'operation'=>$operation,'launch'=>$launch];
                $this->telegramWebWrite($this->telegramWebOperationKey((int)$post['id']),$operation);
            }
            if(!in_array($action,['prepare_publish','prepare_schedule','confirm'],true)){$launch['sequence']++;$launch['confirmation']=null;}
            $launch['receipts'][$request['request_id']]=['hash'=>$payloadHash,'action'=>$action,'at'=>$now];$this->telegramWebWrite($key,$launch);
            $fresh=$this->post((int)$post['id']);$fresh['chat_id']=(string)$channel['chat_id'];$result=['ok'=>true,'state'=>$this->telegramWebState($actor,$fresh,$channel,$permission,$launch)];if($confirmation!==null)$result['confirmation']=$confirmation;return$result;
        },false,false,true,false,$action==='set_targets'?array_column($request['payload']['targets'],'id'):[]);}
        catch(PostedTelegramTopHoldException $error){$this->telegramWebTopConflict($error,$actor);}
        if(!is_array($response))$this->telegramWebDenied();
        if($publishJob!==null){$this->telegramWebPublish($publishJob,$now);$read=$request;$read['action']='read';$read['payload']=[];$read['revision']=null;return$this->telegramWebAction($identity,$read,$now);}
        return$response;
    }

    /** Read-only DB ACL path. Unlike mutations, it never probes Telegram tenure or freezes a channel. */
    /** Optional presentation only: no launch receipt, selector, licence or post write. */
    private function telegramWebTargetMetadataRead(int$actor,array$hint,string$key,array$request,int$now,int$authExpires):array
    {
        $targetId=PostedTelegramWebRequest::targetMetadataId($request['payload']);
        $before=$this->telegramWebTargetMetadataSnapshot($actor,$hint,$key,$targetId,$now,$authExpires);
        $count=null;
        try{
            if($this->pdo->inTransaction())throw new LogicException('Target count transport requires no SQL transaction.');
            $response=$this->telegram->call('getChatMemberCount',['chat_id'=>$before['target_chat_id']]);
            if(($response['ok']??null)===true&&is_int($response['result']??null)&&$response['result']>=0&&$response['result']<=2147483647)$count=$response['result'];
        }catch(Throwable){$count=null;}
        $after=$this->telegramWebTargetMetadataSnapshot($actor,$hint,$key,$targetId,$now,$authExpires);
        if(!hash_equals($before['binding'],$after['binding']))throw new PostedTelegramWebException('stale_context','Права или канал изменились. Обновите список каналов.');
        return['ok'=>true,'target_metadata'=>['id'=>$targetId,'subscriber_count'=>$count]];
    }

    /** SQL-only equivalent of the current visible-choice predicate, restricted to one target.
     * Do not call the full choices helper here: in paid mode it locks every visible
     * licence. Disabled subscription rows are still visible metadata, never grants.
     */
    private function telegramWebTargetMetadataSnapshot(int$actor,array$hint,string$key,int$targetId,int$now,int$authExpires):array
    {
        if($this->pdo->inTransaction())throw new PostedTelegramWebException('busy_context','Обновите список после завершения текущего действия.');
        if($targetId===$hint['channel_id'])$this->telegramWebDenied();
        $ids=[$hint['channel_id'],$targetId];sort($ids,SORT_NUMERIC);
        PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        try{
            $channels=[];
            foreach($ids as$id){$q=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? AND is_active=1 AND added_by IS NOT NULL AND added_by>0 LIMIT 1 FOR UPDATE');$q->execute([$id]);$c=$q->fetch();if(!is_array($c)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',(string)$c['chat_id'])!==1)$this->telegramWebDenied();$channels[(int)$c['id']]=$c;}
            $source=$channels[$hint['channel_id']];$target=$channels[$targetId];
            foreach($ids as$id)PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actor,$id);
            if((int)($target['is_archived']??0)!==0||(int)($target['bot_can_post']??0)!==1)$this->telegramWebDenied();
            $q=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$hint['post_id'],$hint['channel_id']]);$post=$q->fetch();if(!is_array($post))$this->telegramWebDenied();
            if(!$this->lockActiveTelegramActor($actor))$this->telegramWebDenied();PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            $permissions=[];
            foreach($ids as$id){$q=$this->pdo->prepare('SELECT can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor,$id]);$p=$q->fetch();if(!is_array($p)||(int)($p['can_post']??0)!==1)$this->telegramWebDenied();$permissions[$id]=$p;}
            if((int)$post['author_id']!==$actor&&(int)($permissions[$hint['channel_id']]['can_manage_posts']??0)!==1)$this->telegramWebDenied();
            $q=$this->pdo->prepare('SELECT id,user_id,channel_id,name,is_active FROM posted_manager_categories WHERE user_id=? AND channel_id=? ORDER BY id FOR UPDATE');$q->execute([$actor,$targetId]);$categories=$q->fetchAll();
            $launch=$this->telegramWebSetting($key,true);$now=max($now,time());
            if($authExpires<=$now)throw new PostedTelegramWebException('expired_init_data','Откройте окно заново в Telegram.',401);
            if($launch===null)$this->telegramWebDenied();$this->telegramWebValidateLaunch($launch,$actor,$now);$this->telegramWebBinding($post,$source,$launch);
            $authority=[];foreach($ids as$id){if(PostedTelegramAuthorityPause::blocked($this->pdo,$channels[$id],true))$this->telegramWebDenied();$authority[$id]=PostedTelegramAuthorityPause::generation($this->pdo,$channels[$id],true);}
            // The main DTO exposes picker rows only for an editable draft and
            // premium source. Target premium is deliberately NOT a metadata gate.
            if(($post['status']??null)!=='draft'||$this->telegramWebAttempt($post)!==null||!PostedTelegramPolicy::channelState($this->pdo,$this->config,$source,'premium')['allowed'])$this->telegramWebDenied();
            $binding=PostedTelegramWebRequest::hash(['actor'=>$actor,'launch'=>$launch,'source'=>array_intersect_key($source,array_flip(['id','chat_id','added_by','is_active'])),'target'=>array_intersect_key($target,array_flip(['id','chat_id','added_by','is_active','is_archived','bot_can_post'])),'post'=>$post,'permissions'=>$permissions,'categories'=>$categories,'authority'=>$authority]);
            PostedTelegramRootedAccess::commit($this->pdo);return['binding'=>$binding,'target_chat_id'=>(string)$target['chat_id']];
        }catch(Throwable$error){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$error;}
    }

    private function telegramWebCalendarRead(int $actor,array $hint,string $key,array $request,int $now,int $authExpires):array
    {
        if($this->pdo->inTransaction())throw new PostedTelegramWebException('busy_context','Обновите календарь после завершения текущего действия.');
        PostedTelegramRootedAccess::begin($this->pdo,$this->config);
        try{
            $query=$this->pdo->prepare('SELECT id,chat_id,added_by FROM posted_channels WHERE id=? AND is_active=1 AND added_by IS NOT NULL AND added_by>0 LIMIT 1 FOR UPDATE');
            $query->execute([$hint['channel_id']]);$channel=$query->fetch();if(!is_array($channel))$this->telegramWebDenied();
            PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actor,(int)$channel['id']);
            $query=$this->pdo->prepare('SELECT id,channel_id,author_id FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');
            $query->execute([$hint['post_id'],$hint['channel_id']]);$post=$query->fetch();if(!is_array($post))$this->telegramWebDenied();
            if(!$this->lockActiveTelegramActor($actor))$this->telegramWebDenied();PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            $query=$this->pdo->prepare('SELECT can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');
            $query->execute([$actor,$hint['channel_id']]);$permission=$query->fetch();
            if(!is_array($permission)||(int)($permission['can_post']??0)!==1||(int)($permission['can_schedule']??0)!==1
                ||((int)($post['author_id']??0)!==$actor&&(int)($permission['can_manage_posts']??0)!==1))$this->telegramWebDenied();
            $launch=$this->telegramWebSetting($key,true);$now=max($now,time());
            if($authExpires<=$now)throw new PostedTelegramWebException('expired_init_data','Откройте окно заново в Telegram.',401);
            if($launch===null)$this->telegramWebDenied();$this->telegramWebValidateLaunch($launch,$actor,$now);$this->telegramWebBinding($post,$channel,$launch);
            $today=(new DateTimeImmutable('@'.time()))->setTimezone($this->userTimezoneObject($actor))->format('Y-m-d');
            $calendar=$this->telegramWebCalendar($actor,$channel,$permission,$request['payload'],$today);
            PostedTelegramRootedAccess::commit($this->pdo);return['ok'=>true,'calendar'=>$calendar];
        }catch(Throwable$error){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$error;}
    }

    /** Clamped to the existing DATETIME storage domain, with no values supplied as SQL syntax. */
    private function telegramWebCalendarRange(DateTimeImmutable $start,DateTimeImmutable $end):array
    {
        $storage=$this->storageTimezone();$start=$start->setTimezone($storage);$end=(new DateTimeImmutable('@'.($end->getTimestamp()-1)))->setTimezone($storage);
        $minimum=new DateTimeImmutable('1000-01-01 00:00:00',$storage);$maximum=new DateTimeImmutable('9999-12-31 23:59:59',$storage);
        $empty=$end<$minimum||$start>$maximum||$end<$start;
        if($start<$minimum)$start=$minimum;if($start>$maximum)$start=$maximum;
        if($end<$minimum)$end=$minimum;if($end>$maximum)$end=$maximum;
        return[$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$empty];
    }

    /** Read only: exact source channel, current schedule ACL, and author filter under the managed lock. */
    private function telegramWebCalendar(int $actor,array $channel,array $permission,array $payload,string $today):array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Calendar requires the managed source-channel lock.');
        if(empty($permission['can_schedule']))$this->telegramWebDenied();
        $selection=PostedTelegramWebRequest::calendarSelection($payload);$month=$selection['month'];$day=$selection['day'];
        $zone=$this->userTimezoneObject($actor);$storage=$this->storageTimezone();
        $monthStart=new DateTimeImmutable($month.'-01 00:00:00',$zone);$monthEnd=$monthStart->modify('first day of next month')->setTime(0,0,0);
        $dayStart=new DateTimeImmutable($day.' 00:00:00',$zone);$dayEnd=$dayStart->modify('+1 day')->setTime(0,0,0);
        if($dayStart->format('Y-m-d')!==$day)throw new PostedTelegramWebException('invalid_request','Эта дата недоступна в выбранном часовом поясе.',400);
        [$monthFrom,$monthTo,$monthEmpty]=$this->telegramWebCalendarRange($monthStart,$monthEnd);
        [$dayFrom,$dayTo,$dayEmpty]=$this->telegramWebCalendarRange($dayStart,$dayEnd);
        $effective=PostedTelegramContentPlanUi::effectiveDateSql('p.');
        $base=" FROM posted_posts p WHERE p.channel_id=? AND p.status IN ('scheduled','telegram_pending','sent','failed') AND {$effective} BETWEEN ? AND ?";
        $authorOnly=(int)($permission['can_manage_posts']??0)!==1;if($authorOnly)$base.=' AND p.author_id=?';
        $monthParameters=[(int)$channel['id'],$monthFrom,$monthTo];$dayParameters=[(int)$channel['id'],$dayFrom,$dayTo];
        if($authorOnly){$monthParameters[]=$actor;$dayParameters[]=$actor;}
        try{
            $query=$this->pdo->prepare("SELECT {$effective} AS calendar_effective_at".$base.($monthEmpty?' AND 1=0':'')." ORDER BY {$effective},p.id LIMIT 10001");
            $query->execute($monthParameters);$rows=$query->fetchAll();$partial=count($rows)>10000;$counts=[];
            foreach(array_slice($rows,0,10000)as$row){
                $raw=(string)($row['calendar_effective_at']??'');$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$storage);
                if($date===false||$date->format('Y-m-d H:i:s')!==$raw)throw new PostedTelegramWebException('calendar_unavailable','Не удалось прочитать даты календаря. Повторите попытку.',503);
                $local=$date->setTimezone($zone)->format('Y-m-d');if(substr($local,0,7)!==$month)throw new PostedTelegramWebException('calendar_unavailable','Не удалось прочитать даты календаря. Повторите попытку.',503);
                $counts[$local]=($counts[$local]??0)+1;
            }
            ksort($counts,SORT_STRING);
            $query=$this->pdo->prepare("SELECT {$effective} AS calendar_effective_at,p.status,LEFT(COALESCE(p.text,''),601) AS calendar_text".$base.($dayEmpty?' AND 1=0':'')." ORDER BY {$effective},p.id LIMIT 101");
            $query->execute($dayParameters);$rows=$query->fetchAll();$truncated=count($rows)>100;$posts=[];
            foreach(array_slice($rows,0,100)as$row){
                $raw=(string)($row['calendar_effective_at']??'');$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$storage);
                if($date===false||$date->format('Y-m-d H:i:s')!==$raw||$date->setTimezone($zone)->format('Y-m-d')!==$day)throw new PostedTelegramWebException('calendar_unavailable','Не удалось прочитать даты календаря. Повторите попытку.',503);
                $posts[]=['time_label'=>$date->setTimezone($zone)->format('H:i'),'text_preview'=>mb_substr((string)$row['calendar_text'],0,600,'UTF-8'),'status'=>(string)$row['status']];
            }
        }catch(PDOException){throw new PostedTelegramWebException('calendar_unavailable','Не удалось загрузить календарь. Повторите попытку.',503);}
        return['month'=>$month,'day'=>$day,'today'=>$today,'timezone'=>$zone->getName(),'countsByDate'=>(object)$counts,'posts'=>$posts,'posts_truncated'=>$truncated,'counts_partial'=>$partial];
    }

    private function telegramWebScheduleAt(int$actor,array$launch,array$permission,int$now):string
    {
        if(empty($permission['can_schedule'])||($launch['schedule_tz']??null)!==$this->userTimezone($actor)||!is_string($launch['schedule_at']??null))throw new PostedTelegramWebException('schedule_required','Выберите дату и время публикации.');
        $local=$this->formatStoredForUser($actor,$launch['schedule_at'],'Y-m-d H:i');
        try{return PostedPublicationPlan::one($local,$this->userTimezoneObject($actor),$this->storageTimezone(),new DateTimeImmutable('@'.$now));}catch(InvalidArgumentException$error){throw new PostedTelegramWebException('invalid_schedule',$error->getMessage(),400);}
    }
    private function telegramWebSetTargets(int$actor,array$post,array$channel,array$requested):void
    {
        if($requested!==[])$this->telegramMultipostPolicy($actor,$channel);$available=[];
        foreach($this->telegramMultipostChoices($actor,(int)$channel['id'])as$choice)$available[$choice['channel_id'].':'.$choice['category_id']]=$choice;
        $targets=[];
        foreach($requested as$item){$choice=$available[$item['id'].':'.$item['category_id']]??null;if(!is_array($choice)||!$choice['licensed'])$this->telegramWebDenied();$destination=$this->channel($choice['channel_id']);$this->telegramMultipostPolicy($actor,$destination);
            if($item['mode']==='forward'&&($this->telegramPostOptions($post)['paid_media_stars']!==null||$this->telegramPostOptions($post)['protect_content']))throw new PostedTelegramWebException('forward_unavailable','Для этого поста выберите копирование.');
            $targets[]=['channel_id'=>$choice['channel_id'],'chat_id'=>$choice['chat_id'],'mode'=>$item['mode'],'category_id'=>$choice['category_id']];}
        $current=$this->telegramMultipostRead((int)$post['id'],true);$this->telegramMultipostSelectLocked($post,$channel,$actor,$targets,$current['nonce']??bin2hex(random_bytes(6)),$current['selection_digest']??null);
    }

    /** Read-only target preflight. Actual delivery still uses the core sorted-channel reservation gates. */
    private function telegramWebCheckTargets(int$actor,array$post,bool$schedule):void
    {
        $plan=$this->telegramMultipostRead((int)$post['id']);if($plan===null||$plan['targets']===[])return;
        $this->telegramMultipostPolicy($actor,$this->channel((int)$post['channel_id']));
        foreach($plan['targets']as$target){
            $destination=$this->channel($target['channel_id']);
            if(!is_array($destination)||empty($destination['is_active'])||empty($destination['bot_can_post'])||(string)$destination['chat_id']!==$target['chat_id']||!$this->can($actor,$target['channel_id'],'post')||(($schedule||(int)($post['repeat_interval_minutes']??0)>0)&&!$this->can($actor,$target['channel_id'],'schedule')))$this->telegramWebDenied();
            $this->telegramMultipostPolicy($actor,$destination);
            $allowed=false;foreach($this->telegramMultipostChoices($actor,(int)$post['channel_id'])as$choice)if($choice['channel_id']===$target['channel_id']&&$choice['category_id']===$target['category_id']&&$choice['licensed'])$allowed=true;
            if(!$allowed)$this->telegramWebDenied();
        }
    }

    /** No side-send: the established claimed-attempt publisher owns every delivery slot. */
    private function telegramWebPublish(array$job,int$now):void
    {
        $claimed=null;$publisherEntered=false;
        try{
            if(time()>=$job['operation']['deadline'])return;
            $claimed=$this->claimPostForPublish($job['post_id'],['draft']);
            if(!is_array($claimed))return;
            $owned=$this->withTelegramManagedPostLock($job['actor'],$job['post_id'],['publishing'],function(array$post,array$channel)use($job):bool{
                $operation=$this->telegramWebOperation($post,true);
                if(($operation['request_id']??null)!==$job['request_id']||($operation['launch_key']??null)!==$job['key']||($operation['status']??null)!=='preparing'||$operation['deadline']<=time())return false;
                $post['status']='draft';return hash_equals($job['operation']['revision'],$this->telegramWebRevision($job['actor'],$post,$channel,$job['launch']));
            },false,false,true);
            if($owned!==true){$this->restoreBlockedPublishClaim($claimed,'Пост изменился после подтверждения веб-отправки.');return;}
            $publisherEntered=true;$outcome=$this->publishClaimedPost($claimed,$job['actor']);
            if(($outcome['state']??null)==='sent')$this->sendTelegramPublicationOutcome($job['actor'],$claimed,$outcome);
        }catch(Throwable$error){
            error_log('GitPosted web publication result: '.$error::class);
            if(is_array($claimed)&&!$publisherEntered){try{$this->restoreBlockedPublishClaim($claimed,'Не удалось проверить подтверждение веб-отправки.');}catch(Throwable$restoreError){error_log('GitPosted web unentered claim restoration: '.$restoreError::class);}}
        }
        finally{
            // Existing core journal remains authoritative even if this metadata update fails.
            try{$this->withTelegramManagedPostLock($job['actor'],$job['post_id'],[],function(array$post)use($job):bool{
                $operation=$this->telegramWebOperation($post,true);if(($operation['request_id']??null)!==$job['request_id'])return false;
                $operation['status']=($post['status']==='draft'&&$this->telegramWebAttempt($post)===null)?'not_started':'processing';$this->telegramWebWrite($this->telegramWebOperationKey((int)$post['id']),$operation);return true;
            },false,false,true);}catch(Throwable$error){error_log('GitPosted web operation receipt: '.$error::class);}
        }
    }
}
