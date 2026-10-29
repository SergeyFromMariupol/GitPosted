<?php

declare(strict_types=1);

/** Presentation-only draft controls; mutations remain in the locked handlers. */
trait GitPostedTelegramDraftUi
{
    private function telegramDraftEditorKeyboard(array $post, array $keyboard, bool $expanded = false): array
    {
        // A native repost keeps its existing restricted editor and copy switch.
        if ($this->isNativeRepost($post)) {
            return $keyboard;
        }
        $postId = (int) ($post['id'] ?? 0);
        if ($postId <= 0) {
            return $keyboard;
        }

        $media=$this->postMedia($post);$singleVoice=count($media)===1&&($media[0]['type']??null)==='voice';
        $main = [];
        $advanced = [];
        $navigation = [];
        foreach ($keyboard as $row) {
            $voiceMediaRow=false;
            if($media!==[]){
                foreach($row as&$mediaButton)if((string)($mediaButton['callback_data']??'')==='draft:add_media:'.$postId){
                    if($singleVoice){$mediaButton['text']='Удалить медиа';$mediaButton['callback_data']='draft:remove_media:'.$postId;$voiceMediaRow=true;}
                    else $mediaButton['callback_data']=$this->telegramMediaOptionCallback($post,'root');
                }
                unset($mediaButton);
            }
            $firstData = (string) ($row[0]['callback_data'] ?? '');
            if($singleVoice&&count($row)===1&&$firstData==='draft:remove_media:'.$postId)continue;
            if (str_starts_with($firstData, 'draft:cancel:')) {
                $navigation[] = $row;
                continue;
            }
            if (str_starts_with($firstData, 'draft:pin:')) {
                $row[0]['text'] = !empty($post['pin_after_publish']) ? '✅️ Закрепить' : '☑️ Закрепить';
                array_unshift($row,['text'=>!empty($post['comments_enabled'])?'✅️ Комментарии':'☑️ Комментарии','callback_data'=>'draft:comments:'.$postId.':'.(empty($post['comments_enabled'])?1:0)]);
            }
            $isAdvanced = false;
            foreach ($row as $button) {
                if (preg_match('/\Adraft:(?:pin|reply|ad|remove_media):' . $postId . '(?::|\z)/D', (string) ($button['callback_data'] ?? '')) === 1) {
                    $isAdvanced = true;
                    break;
                }
            }
            foreach($row as$button)if((string)($button['callback_data']??'')==='top:post:'.$postId.':d')$isAdvanced=true;
            if ($isAdvanced && !$voiceMediaRow) {
                $advanced[] = $row;
            } else {
                $main[] = $row;
            }
        }

        $protected=$this->telegramPostOptions($post)['protect_content'];
        $advanced[]=[['text'=>$protected?'✅️ Защита контента':'☑️ Защита контента',
            'callback_data'=>$this->telegramProtectionCallback($post,!$protected)]];
        usort($advanced, static function (array $left, array $right): int {
            $rank = static function (array $row): int {
                $data = (string) ($row[0]['callback_data'] ?? '');
                if (str_starts_with($data, 'draft:comments:')||str_starts_with($data, 'draft:pin:')) return 0;
                if (str_starts_with($data, 'top:post:')) return 1;
                if (str_starts_with($data, 'draft:reply:')) return 2;
                if (str_starts_with($data, 'post_option:protect:')) return 3;
                return 4;
            };
            return $rank($left) <=> $rank($right);
        });
        if ($expanded) {
            // Keep the expansion on supported flag refreshes without storing UI
            // state in post content, provenance, or the user's current workflow.
            foreach ($main as &$row) {
                $row = $this->telegramDraftExpandedFlagRow($row, $postId);
            }
            unset($row);
            foreach ($advanced as &$row) {
                $row = $this->telegramDraftExpandedFlagRow($row, $postId);
            }
            unset($row);
            array_push($main, ...$advanced);
        } else {
            $main[] = [['text' => '↓ Больше настроек', 'callback_data' => 'draft:more:' . $postId]];
        }
        array_push($main, ...$navigation);
        return $main;
    }

    private function telegramDraftExpandedFlagRow(array $row, int $postId): array
    {
        foreach ($row as &$button) {
            $data = (string) ($button['callback_data'] ?? '');
            if (preg_match('/\Adraft:(?:silent|pin|comments):' . $postId . ':[01]\z/D', $data) === 1) {
                $button['callback_data'] = $data . ':expanded';
            }
        }
        unset($button);
        return $row;
    }

    private function telegramDraftEditorCallbackTarget(array $parts, ?array $target): ?array
    {
        if (count($parts) === 5
            && ($parts[0] ?? '') === 'draft'
            && in_array((string) ($parts[1] ?? ''), ['silent', 'pin', 'comments'], true)
            && preg_match('/\A[1-9][0-9]*\z/D', (string) ($parts[2] ?? '')) === 1
            && in_array((string) ($parts[3] ?? ''), ['0', '1'], true)
            && ($parts[4] ?? '') === 'expanded') {
            $target ??= [];
            $target['draft_ui_expanded'] = true;
        }
        return $target;
    }

    private function showTelegramDraftMoreSettings(int $userId, int $postId, ?array $target = null): void
    {
        $post = $this->post($postId);
        if (!$post) {
            $this->telegram->sendMessage($userId, 'Черновик не найден.');
            return;
        }
        // sendDraftPreview performs the existing locked rights/status checks.
        $this->sendDraftPreview($userId, $post, $this->draftMainKeyboard($post, true), '', $target);
    }

    /** These two private menus never consume an unrelated newer collection workflow. */
    private function handleTelegramDraftMoreCallback(array $callback): bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||preg_match('/\Adraft:(?:more|comments)(?::|\z)/D',$raw)!==1)return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if($this->pdo->inTransaction()||!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor
            ||preg_match('/\Adraft:(?:more:([1-9][0-9]{0,18})|comments:([1-9][0-9]{0,18}):[01](?::expanded)?)\z/D',$raw,$m)!==1)return true;
        $id=($m[1]??'')!==''?$m[1]:($m[2]??'');if((string)(int)$id!==$id)return true;
        if(str_starts_with($raw,'draft:more:')){
            $this->answerCallback($callback);$this->handleDraftCallback($actor,$raw,$this->callbackTarget($callback));return true;
        }
        $commentsDisabled=false;
        try{
            $this->handleDraftCallback($actor,$raw,$this->callbackTarget($callback),$commentsDisabled);
        }finally{
            // A successful save can precede a failed preview refresh. Acknowledge the save once.
            if($commentsDisabled){
                try{$this->telegram->call('answerCallbackQuery',[
                    'callback_query_id'=>(string)($callback['id']??''),
                    'text'=>"☑️ Комментарии отключены.\n\nДля корректной работы дайте боту @".$this->botUsername()." право на удаление сообщений в прикрепленном к каналу чате.",
                    'show_alert'=>true,
                ]);}catch(Throwable){}
            }else $this->answerCallback($callback);
        }
        return true;
    }

    /** Reuses the reviewed fixed-path reader capability check; never reads a message. */
    private function telegramDraftCommentsReaderAvailable(int $botId): bool
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_ui_outside_sql');
        return (new PostedTelegramCommentsReaderProviderV3($this->pdo,$this->config))->available($botId);
    }

    /** Fresh prerequisites are advisory to this draft choice; the deletion worker rechecks them. */
    private function telegramDraftCommentsPrerequisites(array $post,array $channel): array
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_ui_outside_sql');
        $botId=$this->telegramBotId();
        if($botId<1||!$this->telegramDraftCommentsReaderAvailable($botId))throw new RuntimeException('comments_reader_unavailable');
        $started=time();
        $chat=PostedTelegramCommentsPrototype05::chatId($channel['chat_id']??null);
        $read=function(string $method,array $payload):array{
            if($this->pdo->inTransaction())throw new LogicException('comments_ui_read_inside_sql');
            $r=$this->telegram->call($method,$payload);
            if(($r['ok']??null)!==true||!is_array($r['result']??null))throw new RuntimeException('comments_prerequisite_unverified');
            return$r['result'];
        };
        $source=$read('getChat',['chat_id'=>$chat]);
        if(PostedTelegramCommentsPrototype05::chatId($source['id']??null)!==$chat||($source['type']??null)!=='channel')throw new RuntimeException('comments_channel_unverified');
        $group=PostedTelegramCommentsPrototype05::chatId($source['linked_chat_id']??null);
        if($group===$chat)throw new RuntimeException('comments_group_unverified');
        $channelMember=$read('getChatMember',['chat_id'=>$chat,'user_id'=>$botId]);
        $discussion=$read('getChat',['chat_id'=>$group]);
        $member=$read('getChatMember',['chat_id'=>$group,'user_id'=>$botId]);
        if(PostedTelegramCommentsPrototype05::chatId($discussion['id']??null)!==$group||($discussion['type']??null)!=='supergroup'||PostedTelegramCommentsPrototype05::chatId($discussion['linked_chat_id']??null)!==$chat)throw new RuntimeException('comments_reverse_link_unverified');
        foreach([$channelMember,$member]as$v)if(($v['user']['id']??null)!==$botId||($v['user']['is_bot']??null)!==true||($v['status']??null)!=='administrator')throw new RuntimeException('comments_bot_admin_required');
        if(($channelMember['can_post_messages']??null)!==true||($member['can_delete_messages']??null)!==true)throw new RuntimeException('comments_bot_right_required');
        return['channel_id'=>(int)$channel['id'],'chat_id'=>$chat,'owner_id'=>(int)$channel['added_by'],'author_id'=>(int)$post['author_id'],'comments_enabled'=>(int)$post['comments_enabled'],'checked_at'=>$started];
    }

    private function setTelegramDraftComments(int $actor,int $postId,?int $desired,?array $target,?bool &$commentsDisabled=null): void
    {
        $commentsDisabled=false;
        if($this->pdo->inTransaction()||!in_array($desired,[0,1],true)||$target===null||($target['chat_id']??null)!==$actor||!is_int($target['message_id']??null)||$target['message_id']<1)return;
        $post=$this->mutableDraft($actor,$postId);if(!is_array($post)||$this->isNativeRepost($post))return;
        $channel=$this->channel((int)$post['channel_id']);if(!is_array($channel))return;
        try{
            $proof=null;
            if($desired===0&&(int)$post['comments_enabled']!==0)$proof=$this->telegramDraftCommentsPrerequisites($post,$channel);
            $saved=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array $locked,array $lockedChannel)use($post,$channel,$proof,$desired,$postId):bool{
                if($this->isNativeRepost($locked)||(int)$locked['author_id']!==(int)$post['author_id']||(int)$lockedChannel['added_by']!==(int)$channel['added_by']||(string)$lockedChannel['chat_id']!==(string)$channel['chat_id'])throw new RuntimeException('comments_draft_context_changed');
                if((int)$locked['comments_enabled']!==(int)$post['comments_enabled'])throw new RuntimeException('comments_draft_choice_changed');
                if((int)$locked['comments_enabled']===$desired)return true; // Duplicate desired value is a no-op, never a toggle.
                $q=$this->pdo->prepare('SELECT post_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1 FOR UPDATE');$q->execute([$postId]);if($q->fetchColumn()!==false)throw new RuntimeException('comments_recorded_attempt_immutable');
                if($desired===0&&(!is_array($proof)||$proof['channel_id']!==(int)$lockedChannel['id']||$proof['chat_id']!==(string)$lockedChannel['chat_id']||$proof['owner_id']!==(int)$lockedChannel['added_by']||$proof['author_id']!==(int)$locked['author_id']||$proof['comments_enabled']!==(int)$locked['comments_enabled']||$proof['checked_at']>time()||time()-$proof['checked_at']>15))throw new RuntimeException('comments_prerequisite_expired');
                $this->pdo->prepare("UPDATE posted_posts SET comments_enabled=? WHERE id=? AND status='draft'")->execute([$desired,$postId]);return true;
            });
            if($saved!==true)throw new RuntimeException('comments_draft_unavailable');
        }catch(Throwable $error){
            error_log('GitPosted draft comments held: '.$error::class);
            $this->telegram->sendMessage($actor,'Не удалось изменить комментарии. Для отключения нужна связанная группа обсуждений, бот с правом удаления сообщений и доступный reader. Черновик и текущий ввод сохранены.');
            return;
        }
        $commentsDisabled=$desired===0&&(int)$post['comments_enabled']!==0;
        $target['draft_ui_expanded']=true;$this->showDraftActions($actor,$postId,'',$target);
    }

    private function telegramDraftUrlKeyboard(array $post): array
    {
        $rows=$this->decodeJson((string)($post['buttons_json']??''));
        return PostedTelegramButtonUi::typeKeyboard((int)$post['id'],'draft','url',$this->hasInlineButtons($rows));
    }

    private function showTelegramDraftUrlButtonMenu(int $actor,int $postId,?array $target=null):void
    {
        if($this->pdo->inTransaction()||$target===null||($target['chat_id']??null)!==$actor||(int)($target['message_id']??0)<1)return;
        $post=$this->mutableDraft($actor,$postId);if(!is_array($post)||!$this->telegramPostInlineButtonsAllowed($post)||$this->isNativeRepost($post))return;
        $view=PostedTelegramButtonUi::urlPrompt();
        $this->beginDraftInput($actor,'await_buttons',['post_id'=>$postId],$view['text'],['entities'=>$view['entities'],'reply_markup'=>['inline_keyboard'=>$this->telegramDraftUrlKeyboard($post)]],$target);
    }

    private function showTelegramDraftInvalidUrlInput(int $actor,int $postId):void
    {
        if($this->pdo->inTransaction())return;
        $post=$this->mutableDraft($actor,$postId);if(!is_array($post)||!$this->telegramPostInlineButtonsAllowed($post)||$this->isNativeRepost($post))return;
        $view=PostedTelegramButtonUi::invalidUrl();
        $this->telegram->sendMessage($actor,$view['text'],['entities'=>$view['entities'],'reply_markup'=>['inline_keyboard'=>$this->telegramDraftUrlKeyboard($post)]]);
    }

    private function clearTelegramDraftUrlButtons(int $actor,string $callback,?array $target):void
    {
        if($this->pdo->inTransaction()||$target===null||($target['chat_id']??null)!==$actor||(int)($target['message_id']??0)<1
            ||preg_match('/\Adraft:buttons_clear:([1-9][0-9]{0,18})\z/D',$callback,$m)!==1||(string)(int)$m[1]!==$m[1])return;
        $postId=(int)$m[1];
        $saved=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post)use($actor,$postId):bool{
            if(!$this->telegramPostInlineButtonsAllowed($post)||$this->isNativeRepost($post))return false;
            $this->pdo->prepare("UPDATE posted_posts SET buttons_json=NULL WHERE id=? AND status='draft'")->execute([$postId]);
            $user=$this->user($actor);$state=$user['state']??null;$data=$this->stateData($user);
            if(in_array($state,['await_buttons','await_reactions','await_hidden_label','await_hidden_text','await_hidden_nonmember'],true)&&(int)($data['post_id']??0)===$postId)$this->clearTelegramRepostInputIfCurrent($actor,$state,$data);
            return true;
        });
        if($saved===true)$this->showDraftActions($actor,$postId,'',$target);
    }
    private function handleTelegramDraftUrlCallback(array $callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||!str_starts_with($raw,'draft:buttons_clear'))return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<1||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor
            ||preg_match('/\Adraft:buttons_clear:([1-9][0-9]{0,18})\z/D',$raw,$m)!==1||(string)(int)$m[1]!==$m[1])return true;
        $this->answerCallback($callback);$this->handleDraftCallback($actor,$raw,$this->callbackTarget($callback));return true;
    }


}
