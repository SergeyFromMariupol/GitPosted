<?php
declare(strict_types=1);

/** Native Telegram content options; independent of the service subscription checkout. */
trait GitPostedTelegramMediaOptions
{
    private function telegramMediaUi(int $userId,string $russian,string $english): string
    {return $this->telegramUiLanguage($userId)==='en'?$english:$russian;}

    private function telegramMediaOptionCallback(array $post,string $action,?bool $desired=null): string
    {return 'mopt:'.$action.':'.(int)$post['id'].($desired!==null?':'.($desired?'1':'0'):'').':'.substr($this->telegramSubmissionKey($post),0,16);}

    private function telegramMediaOptionCapabilities(array $post): array
    {
        $media=$this->postMedia($post);$types=array_column($media,'type');$eligible=!$this->isNativeRepost($post)&&$media!==[]&&count($media)<=10;
        return ['spoiler'=>$eligible&&array_diff($types,['photo','video','animation'])===[],
            'paid'=>$eligible&&array_diff($types,['photo','video'])===[]];
    }

    private function telegramMediaOptionsCompatible(array $post,array $media): bool
    {
        $options=$this->telegramPostOptions($post);$types=array_column($media,'type');
        if($options['paid_media_stars']!==null&&($media===[]||count($media)>10||array_diff($types,['photo','video'])!==[]))return false;
        if($options['media_spoiler']&&($media===[]||count($media)>10||array_diff($types,['photo','video','animation'])!==[]))return false;
        return true;
    }

    private function telegramValidateMediaOptions(array $post): void
    {
        $options=$this->telegramPostOptions($post);$capability=$this->telegramMediaOptionCapabilities($post);
        if(($options['media_spoiler']&&!$capability['spoiler'])||($options['paid_media_stars']!==null&&!$capability['paid']))throw new InvalidArgumentException('Сохранённые настройки медиа несовместимы с постом. Откройте медиа и проверьте формат.');
    }

    private function telegramInboundSpoilerLocked(array $post,array $content): bool
    {
        // Only this fresh Bot API input path may interpret its optional absence as false.
        if($this->isNativeRepost($post)){
            $types=array_column($this->postMedia($post),'type');
            if($types!==[]&&array_diff($types,['photo','video','animation'])===[])PostedTelegramSourceSpoiler::registerInboundNativeLocked($this->pdo,$post,!empty($content['media_spoiler']));
            return true;
        }
        if(empty($content['media_spoiler']))return true;
        $options=$this->telegramPostOptions($post);if($options['paid_media_stars']!==null)return false;
        PostedTelegramPostOptions::saveLocked($this->pdo,$post,['media_spoiler'=>true]);return true;
    }

    private function telegramUnknownSourceSpoilerNotice(int $userId,array $post): void
    {
        if($this->isNativeRepost($post)||!$this->telegramPostOptions($post)['media_spoiler']||!$this->canManagePost($userId,(int)$post['id']))return;
        $record=PostedTelegramSourceSpoiler::freeze($this->pdo,$post);
        if($record===null||$record['state']!=='unknown'||$record['mode']!=='ordinary')return;
        $query=$this->pdo->prepare('INSERT IGNORE INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)');
        $query->execute(['key'=>'telegram_spoiler_hint_'.(int)$post['id'].'_'.$userId,'value'=>$this->encodeJson(['version'=>1,'post_id'=>(int)$post['id'],'media_fingerprint'=>$record['media_fingerprint']])]);
        if($query->rowCount()===1)$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,
            'Состояние спойлера старого источника не сохранено. Для копии включён спойлер, чтобы не раскрыть медиа. При необходимости отключите его в меню «Медиа».',
            'The old source spoiler state was not saved. Spoiler is enabled for this copy to keep the media covered. You can turn it off in the Media menu.'));
    }

    private function telegramTemplateMediaPreview(int $userId,array $template,string $text,array $extra): bool
    {
        $options=PostedTelegramPostOptions::loadTemplate($this->pdo,$template);
        if(!$options['media_spoiler']&&$options['paid_media_stars']===null&&PostedTelegramCaptionPlacement::load($this->pdo,$template,'t')===null)return false;
        unset($options['template_id']);$options['post_id']=(int)$template['id'];$options['channel_id']=(int)($template['channel_id']??0)?:1;
        $post=array_replace($template,['id'=>(int)$template['id'],'channel_id'=>$options['channel_id'],'author_id'=>$userId,'chat_id'=>(string)$userId,
            'text'=>$text,'repost_chat_id'=>null,'repost_message_id'=>null,'_telegram_post_options'=>$options]);
        $post=PostedTelegramCaptionPlacement::templatePost($this->pdo,$template,$post);
        $this->dispatchTelegramPreviewPlan($userId,$post,$this->telegramPreviewPlan($post),$extra);return true;
    }

    private function telegramMediaOptionsKeyboard(array $post,?int $userId=null): array
    {
        $userId??=(int)$post['author_id'];
        $options=$this->telegramPostOptions($post);$capability=$this->telegramMediaOptionCapabilities($post);
        $rows=[[['text'=>$this->telegramMediaUi($userId,'Изменить медиа','Edit media'),'callback_data'=>$this->telegramMediaOptionCallback($post,'change')]]];
        if($capability['spoiler']&&$options['paid_media_stars']===null)$rows[]=[['text'=>($options['media_spoiler']?'✅️ ':'☑️ ').$this->telegramMediaUi($userId,'Спойлер','Spoiler'),
            'callback_data'=>$this->telegramMediaOptionCallback($post,'spoiler',!$options['media_spoiler'])]];
        if($capability['paid'])$rows[]=[['text'=>($options['paid_media_stars']!==null?'✅ ':'☑️ ').$this->telegramMediaUi($userId,'Платный пост','Paid post'),
            'callback_data'=>$this->telegramMediaOptionCallback($post,'paid',$options['paid_media_stars']===null)]];
        if($options['paid_media_stars']!==null)$rows[]=[['text'=>$this->telegramMediaUi($userId,'Цена за платный пост: ','Price for paid post: ').$options['paid_media_stars'].' ⭐️',
            'callback_data'=>$this->telegramMediaOptionCallback($post,'price')]];
        $rows[]=[['text'=>$this->telegramMediaUi($userId,'← Назад','← Back'),'callback_data'=>$this->telegramMediaOptionCallback($post,'back')]];return $rows;
    }

    private function showTelegramMediaOptions(int $userId,int $postId,?array $target=null): void
    {
        $post=$this->mutableDraft($userId,$postId);
        if(!$post||$this->isNativeRepost($post)||$this->postMedia($post)===[]){$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Медиа недоступно. Откройте черновик заново.','Media is unavailable. Reopen the draft.'));return;}
        $this->sendDraftPreview($userId,$post,$this->telegramMediaOptionsKeyboard($post,$userId),'',$target);
    }

    /** An explicit media entry finishes only this exact album; another workflow stays intact. */
    private function telegramMediaFinishOwnAlbum(int $userId,array $post): void
    {
        $observed=$this->user($userId);$data=$this->stateData($observed);
        if(($observed['state']??'')!=='collecting_media_group'||(int)($data['post_id']??0)!==(int)$post['id'])return;
        $fingerprint=$this->telegramSubmissionKey($post);
        $this->withTelegramManagedPostLock($userId,(int)$post['id'],['draft'],function(array $locked,array $channel)use($userId,$data,$fingerprint):bool{
            $locked['chat_id']=(string)$channel['chat_id'];if(!hash_equals($fingerprint,$this->telegramSubmissionKey($locked)))return false;
            $query=$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user AND state='collecting_media_group' AND state_data <=> CAST(:data AS JSON)");
            $query->execute(['user'=>$userId,'data'=>$this->encodeJson($data)]);return $query->rowCount()===1;
        });
    }

    /** Must run before generic callback code clears an unrelated album/input state. */
    private function handleTelegramMediaOptionsCallback(int $userId,string $data,?array $target=null): bool
    {
        if(preg_match('/\Adraft:add_media:([1-9][0-9]{0,18})\z/D',$data,$legacy)===1){
            $post=$this->mutableDraft($userId,(int)$legacy[1]);
            if(!$post||$this->isNativeRepost($post)||$this->postMedia($post)===[])return false;
            $this->telegramMediaFinishOwnAlbum($userId,$post);
            $this->showTelegramMediaOptions($userId,(int)$post['id'],$target);return true;
        }
        if(preg_match('/\Amprice:back:([1-9][0-9]{0,18}):([a-f0-9]{24})\z/D',$data,$back)===1){
            $user=$this->user($userId);$stored=$this->stateData($user);$input=$this->telegramPaidMediaPriceInput($stored,false);
            if($input!==null&&($user['state']??'')==='await_paid_media_price'&&$input['post_id']===(int)$back[1]&&hash_equals($input['token'],$back[2])){
                if($this->telegramPaidMediaConsumeInput($userId,$stored))$this->showTelegramMediaOptions($userId,$input['post_id'],$target);
            }
            return true;
        }
        if(!str_starts_with($data,'mopt:'))return false;
        if(preg_match('/\Amopt:(root|change|back|price|spoiler|paid):([1-9][0-9]{0,18})(?::([01]))?:([a-f0-9]{16})\z/D',$data,$match)!==1)return true;
        $action=$match[1];$postId=(int)$match[2];$flag=$match[3]??'';$post=$this->mutableDraft($userId,$postId);
        if(!$post||$this->isNativeRepost($post)||$this->postMedia($post)===[]||!hash_equals($match[4],substr($this->telegramSubmissionKey($post),0,16))){
            $this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Меню устарело. Откройте медиа заново.','This menu is outdated. Reopen the media menu.'));return true;
        }
        if(in_array($action,['spoiler','paid'],true)){
            if(!in_array($flag,['0','1'],true))return true;$enabled=$flag==='1';
            $changes=$action==='spoiler'?['media_spoiler'=>$enabled]:['paid_media_stars'=>$enabled?1:null,'media_spoiler'=>false];
            $changed=$this->setTelegramPostOptions($userId,$postId,$changes,null,
                fn(array $locked):bool=>hash_equals($match[4],substr($this->telegramSubmissionKey($locked),0,16)));
            if(!$changed)$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Меню устарело. Настройки медиа не изменены.','This menu is outdated. Media settings were not changed.'));
            else $this->showTelegramMediaOptions($userId,$postId,$target);
            return true;
        }
        if($flag!=='')return true;
        if($action==='root'){$this->telegramMediaFinishOwnAlbum($userId,$post);$this->showTelegramMediaOptions($userId,$postId,$target);return true;}
        if($action==='back'){$this->showDraftActions($userId,$postId,'',$target);return true;}
        if($action==='price'){$this->beginTelegramPaidMediaPrice($userId,$post,$target);return true;}
        $observed=$this->user($userId);
        if((string)($observed['state']??'')!==''){$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Завершите текущее действие перед изменением медиа.','Finish your current action before changing media.'));return true;}
        $started=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $locked,array $channel)use($userId,$observed,$postId,$match):bool{
            $locked['chat_id']=(string)$channel['chat_id'];if(!hash_equals($match[4],substr($this->telegramSubmissionKey($locked),0,16)))return false;
            $query=$this->pdo->prepare("UPDATE posted_users SET state='await_draft_media',state_data=:data WHERE telegram_id=:user AND state <=> :state AND state_data <=> CAST(:observed AS JSON)");
            $query->execute(['data'=>$this->encodeJson(['post_id'=>$postId]),'user'=>$userId,'state'=>$observed['state']??null,'observed'=>$observed['state_data']??null]);return $query->rowCount()===1;
        });
        $this->telegram->sendMessage($userId,$started===true?$this->telegramMediaUi($userId,'Отправьте новое медиа. Чтобы снять медиа, нажмите кнопку удаления медиа.','Send new media. To remove media, use the remove media button.'):$this->telegramMediaUi($userId,'Текущее действие изменилось. Откройте медиа заново.','Your current action changed. Reopen the media menu.'));return true;
    }

    private function telegramPaidMediaPriceInput(array $data,bool $fresh=true): ?array
    {
        if(count($data)!==6||($data['version']??null)!==1||!is_int($data['post_id']??null)||$data['post_id']<1
            ||!is_int($data['channel_id']??null)||$data['channel_id']<1||!is_string($data['token']??null)||preg_match('/\A[a-f0-9]{24}\z/D',$data['token'])!==1
            ||!is_string($data['post_fingerprint']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$data['post_fingerprint'])!==1
            ||!is_int($data['expires_at']??null)||($fresh&&($data['expires_at']<time()||$data['expires_at']>time()+900)))return null;
        return $data;
    }

    private function telegramPaidMediaConsumeInput(int $userId,array $data): bool
    {
        $query=$this->pdo->prepare("UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user AND state='await_paid_media_price' AND state_data <=> CAST(:data AS JSON)");
        $query->execute(['user'=>$userId,'data'=>$this->encodeJson($data)]);return $query->rowCount()===1;
    }

    private function beginTelegramPaidMediaPrice(int $userId,array $post,?array $target): void
    {
        if($this->telegramPostOptions($post)['paid_media_stars']===null)return;
        $observed=$this->user($userId);
        if(!$observed||(string)($observed['state']??'')!==''){$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Завершите текущее действие перед изменением цены.','Finish your current action before changing the price.'));return;}
        $input=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'token'=>bin2hex(random_bytes(12)),
            'expires_at'=>time()+600,'post_fingerprint'=>$this->telegramSubmissionKey($post)];
        $started=$this->withTelegramManagedPostLock($userId,(int)$post['id'],['draft'],function(array $locked,array $channel)use($userId,$input,$observed):bool{
            $locked['chat_id']=(string)$channel['chat_id'];
            if(!hash_equals($input['post_fingerprint'],$this->telegramSubmissionKey($locked))||$this->telegramPostOptions($locked)['paid_media_stars']===null)return false;
            $query=$this->pdo->prepare("UPDATE posted_users SET state='await_paid_media_price',state_data=:data WHERE telegram_id=:user AND state <=> :state AND state_data <=> CAST(:observed AS JSON)");
            $query->execute(['data'=>$this->encodeJson($input),'user'=>$userId,'state'=>$observed['state']??null,'observed'=>$observed['state_data']??null]);return $query->rowCount()===1;
        });
        if($started!==true){$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Меню устарело. Откройте медиа заново.','This menu is outdated. Reopen the media menu.'));return;}
        $text=$this->customEmojiHtml('5953817746004251548','⭐').' '.$this->telegramMediaUi($userId,
            "<b>ЦЕНА ЗА ПЛАТНЫЙ ПОСТ</b>\n\nОтправьте цену в звёздах <i>(от 1 до 10.000)</i> за платный пост.",
            "<b>PRICE FOR PAID POST</b>\n\nSend the price in stars for the paid post.\n\nThe price must be between 1 and 10,000 stars.");
        $this->sendOrEditMessage($userId,$text,['parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>[[['text'=>$this->telegramMediaUi($userId,'← Назад','← Back'),'callback_data'=>'mprice:back:'.$input['post_id'].':'.$input['token']]]]]],$target);
    }

    private function handleTelegramPaidMediaPriceInput(int $userId,array $data,array $message): void
    {
        if((int)($message['from']['id']??0)!==$userId||(int)($message['chat']['id']??0)!==$userId||($message['chat']['type']??null)!=='private')return;
        $input=$this->telegramPaidMediaPriceInput($data);$post=$input!==null?$this->mutableDraft($userId,$input['post_id']):null;
        if(!$input||!$post||(int)$post['channel_id']!==$input['channel_id']||!hash_equals($input['post_fingerprint'],$this->telegramSubmissionKey($post))){
            $this->telegramPaidMediaConsumeInput($userId,$data);$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Ввод цены устарел. Откройте медиа заново.','This price input is outdated. Reopen the media menu.'));return;
        }
        $text=trim((string)($message['text']??''));
        if(preg_match('/\A[1-9][0-9]{0,4}\z/D',$text)!==1||(int)$text>10000){$this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Отправьте целое число звёзд от 1 до 10.000.','Send an integer number of stars between 1 and 10,000.'));return;}
        $saved=$this->setTelegramPostOptions($userId,$input['post_id'],['paid_media_stars'=>(int)$text,'media_spoiler'=>false],
            ['state'=>'await_paid_media_price','data'=>$data],fn(array $locked):bool=>$this->telegramPostOptions($locked)['paid_media_stars']!==null);
        if($saved)$this->showTelegramMediaOptions($userId,$input['post_id']);else $this->telegram->sendMessage($userId,$this->telegramMediaUi($userId,'Ввод цены устарел. Цена не изменена.','This price input is outdated. The price was not changed.'));
    }

    private function telegramPaidMediaPayload(array $post,int|string $chatId,string $text,array $entities,array $extra=[]): ?array
    {
        $options=$this->telegramPostOptions($post);$price=$options['paid_media_stars'];if($price===null)return null;
        if(!$this->telegramMediaOptionCapabilities($post)['paid']||$options['media_spoiler'])throw new InvalidArgumentException('Платный пост поддерживает до десяти фото и видео без спойлера.');
        $media=[];foreach($this->postMedia($post)as$item)$media[]=['type'=>(string)$item['type'],'media'=>(string)$item['file_id']];
        $caption=$this->telegramUtf16Length($text)<=self::PHOTO_CAPTION_LIMIT?$text:'';
        $payload=['chat_id'=>(string)$chatId,'star_count'=>$price,'media'=>$media,'caption'=>$caption];if($caption!==''&&$entities!==[])$payload['caption_entities']=$entities;
        if(array_key_exists('allow_paid_broadcast',$extra))throw new InvalidArgumentException('Paid broadcasting is not enabled by media settings.');
        unset($extra['has_spoiler']);return array_merge($payload,$extra);
    }

    private function telegramExpectedPaidMediaMessage(array $post): ?array
    {
        $options=$this->telegramPostOptions($post);if($options['paid_media_stars']===null)return null;
        $payload=$this->effectivePostPayload($post);$paid=$this->telegramPaidMediaPayload($post,(string)$post['chat_id'],(string)$payload['text'],(array)$payload['entities']);
        $items=[];foreach($this->postMedia($post)as$item){$file=['file_id'=>(string)$item['file_id'],'file_unique_id'=>(string)($item['file_unique_id']??'')];$items[]=$item['type']==='photo'?['type'=>'photo','photo'=>[$file]]:['type'=>'video','video'=>$file];}
        return ['chat'=>['id'=>(string)$post['chat_id']],'caption'=>$paid['caption'],'caption_entities'=>$paid['caption_entities']??[],
            'paid_media'=>['star_count'=>$options['paid_media_stars'],'paid_media'=>$items]];
    }

    private function telegramPaidMediaIdentity(array $message): ?array
    {
        $info=$message['paid_media']??null;if(!is_array($info))return null;
        $stars=$info['star_count']??null;$items=$info['paid_media']??null;
        if(!is_int($stars)||$stars<1||$stars>25000||!is_array($items)||!array_is_list($items)||$items===[]||count($items)>10)return ['paid_media','',false,[]];
        $type='paid_media:'.$stars.':'.count($items);$preferred=[];$files=[];$stable=true;$allFiles=true;
        foreach($items as$item){
            if(!is_array($item)||!in_array($item['type']??null,['photo','video'],true))return [$type,'',false,[]];
            $file=$item['type']==='photo'?(is_array($item['photo']??null)?end($item['photo']):null):($item['video']??null);
            if(!is_array($file))return [$type,'',false,[]];$unique=(string)($file['file_unique_id']??'');$id=(string)($file['file_id']??'');
            if($unique===''&&$id==='')return [$type,'',false,[]];$stable=$stable&&$unique!=='';
            $allFiles=$allFiles&&$id!=='';
            $preferred[]=['type'=>$item['type'],'id'=>$unique!==''?$unique:$id];$files[]=['type'=>$item['type'],'id'=>$id];
        }
        $identity=hash('sha256',$this->encodeJson($preferred));$aliases=[$identity];
        if($allFiles)$aliases[]=hash('sha256',$this->encodeJson($files));
        return [$type,$identity,$stable,array_values(array_unique($aliases))];
    }
}
