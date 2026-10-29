<?php
declare(strict_types=1);

require_once __DIR__.'/telegram-multipost.php';
require_once __DIR__.'/telegram-multipost-fork.php';
require_once __DIR__.'/telegram-multipost-proof.php';

final class PostedTelegramMultipostPreflightException extends RuntimeException {}

/** Delivery hooks use the normal attempts. No transport is performed under SQL locks. */
trait GitPostedTelegramMultipostDelivery
{
    private function telegramMultipostReserveHint(array $post): array
    {
        try{$plan=$this->telegramMultipostRead((int)$post['id']);}
        catch(Throwable$error){throw new PostedTelegramMultipostPreflightException('Не удалось проверить мультипубликацию. Пост сохранён; откройте его и проверьте выбранные каналы.',0,$error);}
        $ids=[(int)$post['channel_id']];
        if($plan!==null)foreach($plan['targets']as$target){
            $ids[]=$target['channel_id'];
            try{$available=$this->ensureTelegramChannelTenure($target['channel_id']);}
            catch(Throwable$error){throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: не удалось проверить канал назначения. Пост и дата сохранены.',0,$error);}
            if(!$available)throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: один из каналов назначения сейчас недоступен. Пост и дата сохранены.');
        }
        $parentBinding=null;
        $parentQuery=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$parentQuery->execute(['telegram_multipost_parent_'.(int)$post['id']]);$parentRaw=$parentQuery->fetchColumn();
        if($parentRaw!==false){
            try{$parent=json_decode((string)$parentRaw,true,16,JSON_THROW_ON_ERROR);
                if(!is_array($parent)||($parent['version']??null)!==1||($parent['post_id']??null)!==(int)$post['id']||($parent['channel_id']??null)!==(int)$post['channel_id']||!is_int($parent['root_post_id']??null)||$parent['root_post_id']<=0)throw new InvalidArgumentException('Invalid parent.');
                $root=$this->telegramMultipostRead($parent['root_post_id']);$found=false;
                if($root!==null&&$root['phase']==='materialized'&&$root['binding']['actor_id']===(int)$post['author_id'])foreach($root['children']as$child)if($child['post_id']===(int)$post['id']&&$child['channel_id']===(int)$post['channel_id'])$found=true;
                if(!$found)throw new InvalidArgumentException('Invalid child.');
                $parentIds=[$root['binding']['channel_id'],...array_column($root['targets'],'channel_id')];
                foreach($parentIds as$id){$ids[]=$id;if(!$this->ensureTelegramChannelTenure($id))throw new PostedTelegramAuthorityPauseException('paused');}
                $parentBinding=['root_post_id'=>$parent['root_post_id'],'parent_sha256'=>hash('sha256',(string)$parentRaw),'selection_digest'=>$root['selection_digest'],'children_sha256'=>hash('sha256',PostedTelegramMultipost::encode($root['children']))];
            }catch(Throwable$error){throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: связь исходного поста или доступ к его каналам требует проверки. Пост и дата сохранены.',0,$error);}
        }
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
        return ['channel_ids'=>$ids,'selection_digest'=>$plan['selection_digest']??null,'authority_parent'=>$parentBinding];
    }

    private function telegramMultipostLockChannels(array $hint): array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Channel locks require the reservation transaction.');
        $channels=[];
        foreach($hint['channel_ids']as$id){
            $query=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$query->execute([$id]);$row=$query->fetch();
            if(is_array($row))$channels[$id]=$row;
        }return$channels;
    }

    private function telegramMultipostValidateTargetsLocked(array $post,array $channels,array $hint,int $authorId): void
    {
        if(($hint['authority_parent']??null)!==null){
            $binding=$hint['authority_parent'];$query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1 FOR UPDATE');$query->execute(['telegram_multipost_parent_'.(int)$post['id']]);$raw=$query->fetchColumn();
            $root=$this->telegramMultipostRead($binding['root_post_id'],true);$found=false;
            if($raw===false||!hash_equals($binding['parent_sha256'],hash('sha256',(string)$raw))||$root===null||$root['phase']!=='materialized'
                ||$root['binding']['actor_id']!==(int)$post['author_id']||$root['selection_digest']!==$binding['selection_digest']||!hash_equals($binding['children_sha256'],hash('sha256',PostedTelegramMultipost::encode($root['children']))))throw new PostedTelegramMultipostPreflightException('Связь мультипубликации изменилась. Пост сохранён.');
            foreach($root['children']as$child)if($child['post_id']===(int)$post['id']&&$child['channel_id']===(int)$post['channel_id'])$found=true;
            if(!$found)throw new PostedTelegramMultipostPreflightException('Дочерняя публикация изменилась. Пост сохранён.');
            foreach([$root['binding']['channel_id'],...array_column($root['targets'],'channel_id')]as$id){PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$authorId,$id);$authority=$channels[$id]??null;
                if(!$authority||empty($authority['is_active'])||empty($authority['bot_can_post'])||(int)($authority['added_by']??0)<=0)throw new PostedTelegramMultipostPreflightException('Канал связанной публикации отключён. Пост сохранён.');
                PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$authority);
            }
        }
        $plan=$this->telegramMultipostRead((int)$post['id'],true);
        if(($plan['selection_digest']??null)!==$hint['selection_digest'])throw new PostedTelegramMultipostPreflightException('Выбор каналов изменился во время подготовки. Откройте пост и проверьте мультипубликацию заново.');
        if($plan===null||$plan['targets']===[])return;
        if((int)$post['author_id']!==$authorId||$plan['binding']['actor_id']!==$authorId)throw new PostedTelegramMultipostPreflightException('Автор исходного поста изменился. Подготовьте мультипубликацию заново.');
        $needSchedule=($plan['phase']==='frozen'?$plan['purpose']==='schedule':($post['_claimed_from_status']??'draft')==='scheduled')||(int)($post['repeat_interval_minutes']??0)>0;
        $groups=[];
        foreach($plan['targets']as$target){
            $channel=$channels[$target['channel_id']]??null;
            if(!is_array($channel)||empty($channel['is_active'])||empty($channel['bot_can_post'])||(int)($channel['added_by']??0)<=0||(string)$channel['chat_id']!==$target['chat_id'])throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: канал назначения отключён или изменился. Пост и дата сохранены.');
            PostedTelegramTopHold::assertAvailableLocked($this->pdo,$channel,(int)$post['id']);
            $this->telegramMultipostPolicy($authorId,$channel);
            $query=$this->pdo->prepare('SELECT * FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');$query->execute([$authorId,$target['channel_id']]);$permission=$query->fetch();
            if(!is_array($permission)||empty($permission['can_post'])||($needSchedule&&empty($permission['can_schedule'])))throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: у автора больше нет необходимых прав в канале назначения. Пост и дата сохранены.');
            try{$category=$this->resolveTelegramManagerCategoryLocked($authorId,$target['channel_id'],$target['category_id'],$permission);}
            catch(Throwable$error){throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: рекламная компания назначения изменилась. Пост и дата сохранены.',0,$error);}
            if($authorId!==(int)$channel['added_by']&&(!empty($permission['is_ad_manager'])||$category!==null)){
                $key=$target['channel_id'].':'.($category??0);
                if(!isset($groups[$key]))$groups[$key]=['channel'=>$target['channel_id'],'category'=>$category,'permission'=>$permission,'posts'=>0,'pins'=>0];
                $groups[$key]['posts']++;$groups[$key]['pins']+=!empty($post['pin_after_publish'])?1:0;
            }
        }
        foreach($groups as$group)try{$this->telegramMultipostCheckQuotaLocked($authorId,$group);}
        catch(Throwable$error){throw new PostedTelegramMultipostPreflightException('Мультипубликация приостановлена: в канале назначения недостаточно лимита публикаций или закрепов. Пост и дата сохранены.',0,$error);}
    }

    private function telegramMultipostHoldUnreservedClaim(array $post,PostedTelegramMultipostPreflightException $error): array
    {
        $prior=(string)($post['_claimed_from_status']??'draft');if(!in_array($prior,['draft','scheduled','failed'],true))$prior='draft';
        return $this->restoreBlockedPublishClaim($post,$error->getMessage())?['state'=>'blocked','multipost_blocked'=>true,'notice'=>$error->getMessage(),'message_ids'=>[],'message_slots'=>[]]
            :['state'=>'processing','message_ids'=>[],'message_slots'=>[]];
    }

    private function telegramMultipostPolicy(int $actor,array $channel): void
    {
        if(class_exists('PostedTelegramPolicy',false)){
            PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            PostedTelegramRootedAccess::requireChannel($this->pdo,$this->config,$actor,(int)$channel['id']);
            PostedTelegramPolicy::requireChannel($this->pdo,$this->config,$channel,'premium');
        }
    }

    private function telegramMultipostSubmissionSnapshot(array $post): ?array
    {
        if(PostedTelegramMultipostFork::marked($post)){
            $request=$this->telegramMultipostForwardRequest($post);
            return ['forward_source'=>$request['payload']['from_chat_id'],'message_ids'=>$request['payload']['message_ids'],'origins'=>$request['expected_origins']];
        }
        $root=PostedTelegramMultipost::forwardRootPostId($post);
        if($root!==null){
            $plan=$this->telegramMultipostRead($root);
            if($plan===null)throw new InvalidArgumentException('Журнал пересылки недоступен.');
            $request=PostedTelegramMultipost::forwardRequest($post,(string)$post['chat_id'],$plan);
            return ['forward_source'=>$request['payload']['from_chat_id'],'message_ids'=>$request['payload']['message_ids'],
                'origins'=>$request['expected_origins']];
        }
        $plan=$this->telegramMultipostRead((int)$post['id']);
        if($plan===null||$plan['targets']===[])return null;
        return ['selection_digest'=>$plan['selection_digest']];
    }

    private function telegramMultipostForwardRequest(array $post): ?array
    {
        $fork=PostedTelegramMultipostFork::read($this->pdo,$post);
        if($fork!==null)return PostedTelegramMultipostFork::request($fork,$post);
        $root=PostedTelegramMultipost::forwardRootPostId($post);
        if($root===null)return null;
        $plan=$this->telegramMultipostRead($root,$this->pdo->inTransaction());
        if($plan===null)throw new InvalidArgumentException('Журнал пересылки недоступен.');
        return PostedTelegramMultipost::forwardRequest($post,(string)$post['chat_id'],$plan);
    }

    private function telegramMultipostForwardParts(array $post): ?array
    {
        $request=$this->telegramMultipostForwardRequest($post);
        if($request===null)return null;
        $parts=[];
        foreach($request['expected_origins'] as $slot=>$origin)$parts[]=$this->telegramPendingPartFromMessage([
            'chat'=>['id'=>(string)$post['chat_id']],'forward_origin'=>$origin,
        ],$slot,(string)$post['chat_id']);
        return $parts;
    }

    private function telegramMultipostPreviewForward(array $post,string $targetChat,int $actorId,string $purpose): ?array
    {
        if(!PostedTelegramMultipostFork::marked($post)&&PostedTelegramMultipost::forwardRootPostId($post)===null)return null;
        if($purpose==='publish')throw new LogicException('Batch publication requires its reserved delivery hook.');
        if($targetChat!==(string)$actorId||!$this->canManagePost($actorId,(int)$post['id']))throw new InvalidArgumentException('Предпросмотр доступен только участнику этого поста.');
        if(!isset($post['chat_id']))$post['chat_id']=(string)($this->channel((int)$post['channel_id'])['chat_id']??'');
        $request=$this->telegramMultipostForwardRequest($post);$payload=$request['payload'];$payload['chat_id']=$targetChat;unset($payload['protect_content']);
        $response=$this->telegram->call('forwardMessages',$payload);
        if(PostedTelegramMultipost::classifyForwardResult($request,$response)['state']!=='complete')throw new PostedTelegramRequestException('Telegram не подтвердил все части предпросмотра. Повтор не выполняется.',true);
        return$response;
    }

    private function telegramMultipostPublishForward(array $post): ?array
    {
        $request=$this->telegramMultipostForwardRequest($post);
        if($request===null)return null;
        $response=$this->telegram->call($request['method'],$request['payload']);
        $classified=PostedTelegramMultipost::classifyForwardResult($request,$response);
        $parts=$this->telegramMultipostForwardParts($post);
        $slots=array_fill(0,count($parts),0);
        if($classified['state']==='complete'){
            $slots=$classified['known_ids'];
            foreach($parts as $index=>&$part){$part['message_id']=$slots[$index];$part['published_at']=time();}
            unset($part);
        }else{
            // MessageId[] may skip source messages. Partial IDs have no safe
            // slot correspondence; retain them without guessing or resending.
            $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([
                    'telegram_multipost_forward_result_'.(int)$post['id'],
                    $this->encodeJson(['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],
                        'state'=>$classified['state'],'known_ids'=>$classified['known_ids'],
                        'expected_count'=>$request['expected_count'],'retry_whole_batch'=>false]),
                ]);
        }
        return ['message_ids'=>array_values(array_filter($slots)),'message_slots'=>$slots,'pending_parts'=>$parts,'continuation'=>[]];
    }

    private function telegramMultipostReserveLocked(array $post,array $channel,array $parts,array $continuation): void
    {
        if(method_exists($this,'telegramCommentsReservePrototype05'))$this->telegramCommentsReservePrototype05($post,$channel,count($parts),count($continuation));
        $query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$query->execute(['telegram_multipost_parent_'.(int)$post['id']]);$raw=$query->fetchColumn();
        if($raw!==false){
            $parent=json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($parent)||($parent['version']??null)!==1||($parent['post_id']??null)!==(int)$post['id']||($parent['channel_id']??null)!==(int)$post['channel_id']||!is_int($parent['root_post_id']??null))throw new InvalidArgumentException('Multipost child binding changed.');
            $root=$this->telegramMultipostRead($parent['root_post_id'],true);$allowed=false;$childMode=null;
            if($root&&$root['phase']==='materialized'&&$root['binding']['actor_id']===(int)$post['author_id'])foreach($root['children']as$child)if($child['post_id']===(int)$post['id']&&$child['channel_id']===(int)$post['channel_id']){$allowed=true;$childMode=$child['mode'];}
            if(!$allowed)throw new InvalidArgumentException('Multipost child receipt changed.');
            if(($parent['mode_override']??null)==='copy'){
                if($this->isNativeRepost($post)||PostedTelegramMultipost::forwardRootPostId($post)!==null||PostedTelegramMultipostFork::marked($post))throw new InvalidArgumentException('Explicit copy binding changed.');
            }elseif($childMode==='forward'&&PostedTelegramMultipost::forwardRootPostId($post)===null)throw new InvalidArgumentException('Batch forwarding mode changed without explicit copy.');
            $this->telegramMultipostPolicy((int)$post['author_id'],$channel);
        }elseif(PostedTelegramMultipostFork::marked($post))$this->telegramMultipostPolicy((int)$post['author_id'],$channel);
        $plan=$this->telegramMultipostRead((int)$post['id'],true);
        if($plan===null||$plan['targets']===[])return;
        $this->telegramMultipostPolicy((int)$post['author_id'],$channel);
        foreach($plan['targets'] as $target){
            $destination=$this->channel($target['channel_id']);
            if(!$destination||(string)$destination['chat_id']!==$target['chat_id'])throw new InvalidArgumentException('Канал мультипубликации изменился.');
            $this->telegramMultipostPolicy((int)$post['author_id'],$destination);
        }
        $purpose=$plan['phase']==='frozen'?$plan['purpose']:((string)($post['_claimed_from_status']??'draft')==='scheduled'?'schedule':'now');
        $this->telegramMultipostFreezeAttemptLocked($post,$channel,count($parts),count($continuation),$purpose);
    }

    private function telegramMultipostCloneSelectionLocked(array $source,array $target): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Multipost clone requires the new-row transaction.');
        if(($source['_telegram_multipost_child']??false)===true)return;
        if(PostedTelegramMultipostFork::marked($source)||PostedTelegramMultipost::forwardRootPostId($source)!==null){
            $channel=$this->channel((int)$target['channel_id']);if(!$channel)throw new InvalidArgumentException('Канал назначения недоступен.');
            // copyPostForUser legitimately changes the new author's field.
            // Authorization is derived from the still-locked original row.
            $original=$this->post((int)$source['id']);
            if(!$original||(int)$original['channel_id']!==(int)$source['channel_id'])throw new InvalidArgumentException('Исходная пересылка изменилась.');
            $request=$this->telegramMultipostForwardRequest($original);
            $receipt=PostedTelegramMultipostFork::create($target,$channel,(int)$target['author_id'],$request);
            $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)')->execute([
                PostedTelegramMultipostFork::key((int)$target['id']),PostedTelegramMultipost::encode($receipt),
            ]);
            $this->pdo->prepare('UPDATE posted_posts SET import_json=? WHERE id=?')->execute([
                $this->encodeJson([PostedTelegramMultipostFork::MARKER=>['version'=>1,'post_id'=>(int)$target['id'],'nonce'=>$receipt['nonce']]]),(int)$target['id'],
            ]);
        }
        $plan=$this->telegramMultipostRead((int)$source['id']);
        if($plan===null||$plan['targets']===[])return;
        $channel=$this->channel((int)$target['channel_id']);
        if(!$channel)throw new InvalidArgumentException('Новый канал недоступен.');
        $plan['targets']=array_values(array_filter($plan['targets'],static fn(array $entry):bool=>$entry['channel_id']!==(int)$target['channel_id']));
        // Revalidate by constructing a fresh document, not by transplanting the
        // original proof or its children into the new root identity.
        $fresh=PostedTelegramMultipost::selection(array_replace($target,['status'=>'draft']),$channel,(int)$target['author_id'],
            $plan['targets'],bin2hex(random_bytes(6)),$plan['allow_dual']);
        $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)')->execute([
            PostedTelegramMultipost::key((int)$target['id']),PostedTelegramMultipost::encode($fresh),
        ]);
    }

    private function telegramMultipostConvertCopyLocked(array $post): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Explicit batch copy requires the draft transaction.');
        $import=$this->decodeJson((string)($post['import_json']??''));
        if(!array_key_exists(PostedTelegramMultipost::FORWARD_KEY,$import)&&!array_key_exists(PostedTelegramMultipostFork::MARKER,$import))return;
        $query=$this->pdo->prepare('SELECT post_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$query->execute([(int)$post['id']]);
        if($query->fetchColumn()!==false)throw new InvalidArgumentException('Начатую пересылку нельзя преобразовать в копию.');
        unset($import[PostedTelegramMultipost::FORWARD_KEY],$import[PostedTelegramMultipostFork::MARKER]);
        $this->pdo->prepare('UPDATE posted_posts SET import_json=? WHERE id=?')->execute([$import===[]?null:$this->encodeJson($import),(int)$post['id']]);
        $this->pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([PostedTelegramMultipostFork::key((int)$post['id'])]);
        $query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$query->execute(['telegram_multipost_parent_'.(int)$post['id']]);$raw=$query->fetchColumn();
        if($raw!==false){
            $parent=json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($parent)||($parent['post_id']??null)!==(int)$post['id']||($parent['channel_id']??null)!==(int)$post['channel_id'])throw new InvalidArgumentException('Привязка дочерней публикации изменилась.');
            $parent['mode_override']='copy';
            $this->pdo->prepare('UPDATE posted_app_settings SET setting_value=? WHERE setting_key=?')->execute([$this->encodeJson($parent),'telegram_multipost_parent_'.(int)$post['id']]);
        }
    }

    private function telegramMultipostAfterCommit(int $rootPostId): void
    {
        try{
            $result=$this->telegramMultipostMaterialize($rootPostId);
            if($result['state']!=='materialized')return;
            foreach($result['children'] as $child){
                $post=$this->post($child['post_id']);
                if(!$post)continue;
                $status=(string)$post['status'];
                if($status==='scheduled'&&(!is_string($post['scheduled_at'])||$post['scheduled_at']>(new DateTimeImmutable('now',$this->storageTimezone()))->format('Y-m-d H:i:s')))continue;
                if(!in_array($status,['draft','scheduled'],true))continue;
                $claimed=$this->claimPostForPublish((int)$post['id'],[$status],$status==='scheduled');
                if($claimed){
                    if($this->telegramPolicyOperatorAllowed((int)$claimed['author_id']))try{
                        $this->telegramUnknownSourceSpoilerNotice((int)$claimed['author_id'],$claimed);
                    }catch(Throwable$error){error_log('GitPosted source spoiler notice unavailable: '.$error::class);}
                    $this->publishClaimedPost($claimed);
                }
            }
        }catch(Throwable $error){
            // Source success has committed. The durable ready/child receipt is
            // retried by bounded maintenance; an unresolved child gate is kept.
            error_log('GitPosted multipost pending: '.$error::class);
        }
    }

    private function resumeTelegramMultiposts(): void
    {
        $query=$this->pdo->query("SELECT post.id FROM posted_posts post
            INNER JOIN posted_app_settings setting ON setting.setting_key=CONCAT('telegram_multipost_',post.id)
            WHERE post.status='sent' AND JSON_UNQUOTE(JSON_EXTRACT(setting.setting_value,'$.phase')) IN ('ready','materialized')
            ORDER BY setting.updated_at,post.id LIMIT 20");
        foreach($query->fetchAll() as $post){
            $this->telegramMultipostAfterCommit((int)$post['id']);
            // Rotate even a gated or already-complete receipt so an older
            // unknown batch cannot starve newer independent roots.
            $this->pdo->prepare('UPDATE posted_app_settings SET updated_at=NOW() WHERE setting_key=?')->execute([PostedTelegramMultipost::key((int)$post['id'])]);
        }
    }

    private function handleTelegramMultipostCallback(int $userId,string $data,?array $target=null): void
    {
        try{
            if(preg_match('/\Amp:open:([1-9][0-9]{0,18})(?::([cf]))?\z/D',$data,$match)===1){
                $postId=$this->telegramMultipostPostId($match[1]);$viewMode=($match[2]??'c')==='f'?'forward':'copy';
                $plan=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $post,array $channel)use($userId):array{
                    $this->telegramMultipostPolicy($userId,$channel);
                    $current=$this->telegramMultipostRead((int)$post['id'],true);
                    return $this->telegramMultipostSelectLocked($post,$channel,$userId,$current['targets']??[],
                        $current['nonce']??bin2hex(random_bytes(6)));
                });
                if(!is_array($plan))throw new InvalidArgumentException('Черновик недоступен.');
            }elseif(preg_match('/\Amp:s:([1-9][0-9]{0,18}):([a-z0-9]{1,7}):([cf]):([a-z0-9]{1,7}):([a-f0-9]{12}):([0-9]{1,4})\z/D',$data,$match)===1){
                $postId=$this->telegramMultipostPostId($match[1]);$channelId=$this->telegramMultipostSmallId($match[2],false);$mode=$match[3]==='c'?'copy':'forward';$category=$this->telegramMultipostSmallId($match[4],true);$viewMode=$mode;
                $plan=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $post,array $channel)use($userId,$channelId,$mode,$category,$match):array{
                    $this->telegramMultipostPolicy($userId,$channel);
                    $current=$this->telegramMultipostRead((int)$post['id'],true);
                    if(!$current||$current['nonce']!==$match[5]||$current['revision']!==(int)$match[6])throw new InvalidArgumentException('Меню устарело. Откройте мультипубликацию заново.');
                    $destination=$this->channel($channelId);
                    $query=$this->pdo->prepare('SELECT * FROM posted_permissions WHERE user_id=? AND channel_id=?');$query->execute([$userId,$channelId]);$permission=$query->fetch();
                    if(!$destination||empty($destination['is_active'])||empty($destination['bot_can_post'])||!is_array($permission)||empty($permission['can_post']))throw new InvalidArgumentException('Канал недоступен для публикации.');
                    $this->telegramMultipostPolicy($userId,$destination);
                    if($category>0){$query=$this->pdo->prepare('SELECT id FROM posted_manager_categories WHERE id=? AND user_id=? AND channel_id=? AND is_active=1');$query->execute([$category,$userId,$channelId]);if($query->fetchColumn()===false||empty($permission['is_ad_manager']))throw new InvalidArgumentException('Рекламная компания недоступна.');}
                    $targets=[];$remove=false;
                    foreach($current['targets'] as $entry){if($entry['channel_id']===$channelId&&(!$current['allow_dual']||$entry['mode']===$mode)){$remove=$entry['mode']===$mode&&$entry['category_id']===$category;continue;}$targets[]=$entry;}
                    if(!$remove)$targets[]=['channel_id'=>$channelId,'chat_id'=>(string)$destination['chat_id'],'mode'=>$mode,'category_id'=>$category];
                    return $this->telegramMultipostSelectLocked($post,$channel,$userId,$targets,$current['nonce'],$current['selection_digest']);
                },false,false,false,false,[$channelId]);
                if(!is_array($plan))throw new InvalidArgumentException('Черновик недоступен.');
            }elseif(preg_match('/\Amp:a:([1-9][0-9]{0,18}):([cf]):([01]):([a-f0-9]{12}):([0-9]{1,4})\z/D',$data,$match)===1){
                $postId=$this->telegramMultipostPostId($match[1]);$viewMode=$match[2]==='f'?'forward':'copy';
                $choiceHint=$this->post($postId);$planningTargets=$choiceHint?array_column($this->telegramMultipostChoices($userId,(int)$choiceHint['channel_id']),'channel_id'):[];
                $plan=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $post,array $channel)use($userId,$viewMode,$match):array{
                    $this->telegramMultipostPolicy($userId,$channel);$current=$this->telegramMultipostRead((int)$post['id'],true);
                    if(!$current||$current['nonce']!==$match[4]||$current['revision']!==(int)$match[5])throw new InvalidArgumentException('Меню устарело.');
                    $targets=array_values(array_filter($current['targets'],static fn(array $entry):bool=>$entry['mode']!==$viewMode));
                    if($match[3]==='1')foreach($this->telegramMultipostChoices($userId,(int)$post['channel_id'])as$choice){
                        $channelId=$choice['channel_id'];if($choice['ambiguous'])throw new InvalidArgumentException('Выберите рекламную компанию для каждого канала отдельно.');
                        if(!$current['allow_dual'])$targets=array_values(array_filter($targets,static fn(array $entry):bool=>$entry['channel_id']!==$channelId));
                        $destination=$this->channel($channelId);$this->telegramMultipostPolicy($userId,$destination);
                        $targets[]=['channel_id'=>$channelId,'chat_id'=>$choice['chat_id'],'mode'=>$viewMode,'category_id'=>$choice['category_id']];
                    }
                    return $this->telegramMultipostSelectLocked($post,$channel,$userId,$targets,$current['nonce'],$current['selection_digest']);
                },false,false,false,false,$planningTargets);
                if(!is_array($plan))throw new InvalidArgumentException('Черновик недоступен.');
            }elseif(preg_match('/\Amp:back:([1-9][0-9]{0,18}):([a-f0-9]{12}):([0-9]{1,4})\z/D',$data,$match)===1){
                $postId=$this->telegramMultipostPostId($match[1]);$post=$this->mutableDraft($userId,$postId);$plan=$this->telegramMultipostRead($postId);
                if(!$post||!$plan||$plan['nonce']!==$match[2]||$plan['revision']!==(int)$match[3]||$plan['binding']['actor_id']!==$userId)throw new InvalidArgumentException('Меню устарело.');
                $this->showDraftSendSettings($userId,$postId,$target);return;
            }else return;
            $keyboard=[];
            $short=$viewMode==='copy'?'c':'f';
            foreach($this->telegramMultipostChoices($userId,$plan['binding']['channel_id'])as$choice){
                $chosen=false;foreach($plan['targets']as$entry)if($entry['channel_id']===$choice['channel_id']&&$entry['mode']===$viewMode&&$entry['category_id']===$choice['category_id'])$chosen=true;
                $callback='mp:s:'.$postId.':'.base_convert((string)$choice['channel_id'],10,36).':'.$short.':'.base_convert((string)$choice['category_id'],10,36).':'.$plan['nonce'].':'.$plan['revision'];
                if(strlen($callback)<=64){
                    $button=['text'=>$choice['title'],'callback_data'=>$callback];
                    if(!$choice['licensed'])$button['style']='danger';elseif($chosen)$button['style']='primary';
                    $keyboard[]=[$button];
                }
            }
            $keyboard[]=[['text'=>'Выбрать все','callback_data'=>'mp:a:'.$postId.':'.$short.':1:'.$plan['nonce'].':'.$plan['revision']]];
            $keyboard[]=[['text'=>'Cнять все','callback_data'=>'mp:a:'.$postId.':'.$short.':0:'.$plan['nonce'].':'.$plan['revision']]];
            $keyboard[]=[['text'=>'← Назад','callback_data'=>'mp:back:'.$postId.':'.$plan['nonce'].':'.$plan['revision']]];
            $text=$viewMode==='copy'
                ?$this->customEmojiHtml('5258477770735885832','📄').' <b>КОПИРОВАТЬ В КАНАЛЫ</b>'."\n\nВыберите каналы, в которые хотите скопировать пост. Публикация выйдет сразу во всех выбранных каналах."
                :$this->customEmojiHtml('5260450573768990626','➡️').' <b>ПЕРЕСЛАТЬ В КАНАЛЫ</b>'."\n\nВыберите каналы, в которые хотите переслать пост. Публикация будет переслана сразу во все выбранные каналы.";
            $this->sendOrEditMessage($userId,$text,['parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
        }catch(Throwable $error){
            if($error instanceof PostedTelegramPolicyException&&$error->channelId!==null&&in_array($error->reason,['required','expired','revoked'],true)&&method_exists($this,'showTelegramSubscription')){
                $this->showTelegramSubscription($userId,$target,$error->channelId,'offer');return;
            }
            $this->telegram->sendMessage($userId,$error instanceof InvalidArgumentException||$error instanceof PostedTelegramPolicyException?$error->getMessage():'Не удалось изменить мультипубликацию. Откройте меню заново.');
        }
    }

    private function telegramMultipostChoices(int $userId,int $rootChannel): array
    {
        $choices=[];
        foreach($this->allowedChannels($userId,'post')as$channel){
            $id=(int)$channel['id'];if($id===$rootChannel||empty($channel['bot_can_post']))continue;
            $licensed=!class_exists('PostedTelegramPolicy',false)||PostedTelegramPolicy::channelState($this->pdo,$this->config,$channel,'premium')['allowed'];
            $query=$this->pdo->prepare('SELECT id,name FROM posted_manager_categories WHERE user_id=? AND channel_id=? AND is_active=1 ORDER BY id');$query->execute([$userId,$id]);$categories=$query->fetchAll();
            if($categories===[])$categories=[['id'=>0,'name'=>'']];$ambiguous=count($categories)>1;
            foreach($categories as$category)$choices[]=['channel_id'=>$id,'chat_id'=>(string)$channel['chat_id'],'category_id'=>(int)$category['id'],
                'title'=>(string)$channel['title'].($category['name']!==''?' / '.$category['name']:''),'ambiguous'=>$ambiguous,'licensed'=>$licensed];
        }return$choices;
    }

    private function telegramMultipostSmallId(string $value,bool $zeroAllowed): int
    {
        $id=(int)base_convert($value,36,10);
        if($id<($zeroAllowed?0:1)||$id>4294967295||base_convert((string)$id,10,36)!==$value)throw new InvalidArgumentException('Идентификатор канала или компании недействителен.');
        return$id;
    }

    private function telegramMultipostPostId(string $value): int
    {
        $id=(int)$value;if($id<=0||(string)$id!==$value)throw new InvalidArgumentException('Идентификатор поста недействителен.');return$id;
    }
}
