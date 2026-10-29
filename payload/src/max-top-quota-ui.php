<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** MAX actor IDs stay strings; TOP stock never grants a channel capability. */
final class GitPostedMaxTopQuotaView
{
    public const STATE='await_max_top_quota';
    public static function userId(mixed $v):string
    {if((!is_string($v)&&!is_int($v))||preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)$v)!==1)throw new InvalidArgumentException('Некорректный ID МАКС.');return(string)$v;}
    public static function number(mixed $v,bool $zero=false):int
    {if((!is_int($v)&&!is_string($v))||preg_match($zero?'/\A(?:0|[1-9][0-9]{0,18})\z/D':'/\A[1-9][0-9]{0,18}\z/D',(string)$v)!==1||(string)(int)$v!==(string)$v)throw new InvalidArgumentException('Некорректное число.');return(int)$v;}
    public static function amount(mixed $v):int
    {if(!is_string($v)||preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D',trim($v))!==1||(int)trim($v)>GitPostedMaxTopHold::MAX_QUOTA)throw new InvalidArgumentException('Отправьте целое число от 0 до 1000000.');return(int)trim($v);}
    public static function parse(string $v):array
    {
        if(strlen($v)>256)throw new InvalidArgumentException('Меню устарело.');
        if(preg_match('/\Agp:topq:edit:([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([0-9]{1,4}):(e|a)\z/D',$v,$m)===1)return['action'=>'edit','channel_id'=>self::number($m[1]),'target_id'=>self::userId($m[2]),'page'=>self::number($m[3],true),'role'=>$m[4]];
        if(preg_match('/\Agp:topq:cancel:([a-f0-9]{32})\z/D',$v,$m)===1)return['action'=>'cancel','nonce'=>$m[1]];
        throw new InvalidArgumentException('Меню устарело.');
    }
    public static function flow(mixed $v,string $actor,bool $expired=false):array
    {
        if(is_string($v))$v=json_decode($v,true,16,JSON_THROW_ON_ERROR);$keys=is_array($v)?array_keys($v):[];sort($keys);$want=['version','actor_id','channel_id','target_id','revision','nonce','expires_at','page','role'];sort($want);
        if($keys!==$want||$v['version']!==1||$v['actor_id']!==self::userId($actor)||!is_int($v['channel_id'])||$v['channel_id']<=0||!is_string($v['target_id'])||self::userId($v['target_id'])!==$v['target_id']||!is_int($v['revision'])||$v['revision']<0||!is_int($v['page'])||$v['page']<0||$v['page']>9999||!in_array($v['role'],['e','a'],true)||!is_int($v['expires_at'])||(!$expired&&$v['expires_at']<time())||!is_string($v['nonce'])||preg_match('/\A[a-f0-9]{32}\z/D',$v['nonce'])!==1)throw new InvalidArgumentException('Срок ввода истёк. Откройте лимит заново.');return$v;
    }
    /** No identity fallback, no forwarded message author and no inferred private chat. */
    public static function privateActor(array $update,bool $callback):?array
    {
        $message=$update['message']??null;$user=$callback?($update['callback']['user']??null):($message['sender']??null);
        if(!is_array($message)||!is_array($user)||($user['is_bot']??false)!==false)return null;
        $recipient=$message['recipient']??null;if(!is_array($recipient)||!in_array($recipient['chat_type']??null,['dialog','private'],true)||isset($message['link'])||isset($message['forward'])||isset($message['forwarded_message']))return null;
        try{$id=self::userId($user['user_id']??null);}catch(Throwable){return null;}
        $chat=$recipient['chat_id']??null;if((!is_int($chat)&&!is_string($chat))||preg_match('/\A-?[1-9][0-9]{0,63}\z/D',(string)$chat)!==1)return null;
        return['actor_id'=>$id,'chat_id'=>(string)$chat];
    }
}

trait GitPostedMaxTopQuotaUi
{
    private function maxTopQuotaSavedEffect(string $actor):?array
    {
        $key=$this->processingUpdateKey;if($key===null||$key==='')return null;
        $s=$this->pdo->prepare('SELECT effect_type,result_json FROM posted_max_update_effects WHERE update_key=? LIMIT 1');$s->execute([$key]);$row=$s->fetch();if(!$row||$row['effect_type']!=='max_top_quota_ui')return null;
        $v=json_decode((string)$row['result_json'],true,16,JSON_THROW_ON_ERROR);if(!is_array($v)||($v['actor_id']??null)!==$actor||!in_array($v['action']??null,['open','cancel','save'],true))throw new RuntimeException('Сохранённое действие относится к другому пользователю.');return$v;
    }
    private function maxTopUiActor(array $update,bool $callback):?array
    {
        $actor=GitPostedMaxTopQuotaView::privateActor($update,$callback);if($actor===null)return null;
        if(!GitPostedMaxPolicy::operatorAllowed($this->config,$actor['actor_id']))return null;$user=$this->user($actor['actor_id']);
        if((int)($user['is_active']??0)!==1||!is_string($user['private_chat_id']??null)||!hash_equals($user['private_chat_id'],$actor['chat_id']))return null;
        return$actor+['user'=>$user];
    }
    /** Fresh role preflight outside SQL; one sorted lock order for owner and target. */
    private function maxTopQuotaOwnerTransaction(string $actor,int $channelId,string $target,Closure $operation):mixed
    {
        if($this->pdo->inTransaction())throw new LogicException('TOP quota starts outside SQL.');
        $hintQuery=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? AND added_by=? AND is_active=1 AND admin_blocked=0 LIMIT 1');$hintQuery->execute([$channelId,$actor]);$hint=$hintQuery->fetch();if(!is_array($hint))throw new RuntimeException('Нет доступа владельца.');
        // A prompt's earlier cached proof cannot authorize a later write. Unknown
        // membership changes no channel, workflow, stock or update-effect row.
        $proof=$this->api->getMembers((string)$hint['chat_id'],[$actor]);$members=$proof['members']??null;
        if(!is_array($members))throw new RuntimeException('МАКС не подтвердил права владельца.');
        $matching=array_values(array_filter($members,static fn(mixed $member):bool=>is_array($member)&&(string)($member['user_id']??$member['id']??'')===$actor));
        if(count($matching)!==1||($matching[0]['is_bot']??null)!==false||(($matching[0]['is_owner']??null)!==true&&($matching[0]['is_admin']??null)!==true))throw new RuntimeException('МАКС не подтвердил права владельца.');
        $verifiedAt=time();
        $this->pdo->beginTransaction();try{
            $s=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            if(!$channel||(string)($channel['added_by']??'')!==$actor||(string)$channel['chat_id']!==(string)$hint['chat_id']||empty($channel['is_active'])||!empty($channel['admin_blocked']))throw new RuntimeException('Владелец изменился.');
            $this->assertActiveMaxUsersLocked([$actor,$target]);if(time()<$verifiedAt-2||time()>$verifiedAt+60)throw new RuntimeException('Проверка прав устарела.');if(!GitPostedMaxPolicy::operatorAllowed($this->config,$actor))throw new RuntimeException('Доступ закрыт.');
            $result=$operation($channel);$this->pdo->commit();return$result;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function maxTopQuotaButton(string $owner,int $channelId,string $target,int $page=0,string $role='e'):?array
    {
        if(!in_array($role,['e','a'],true)||$target===$owner||!GitPostedMaxPolicy::operatorAllowed($this->config,$owner)||!$this->isMaxUserActive($owner)||!$this->isMaxUserActive($target))return null;
        $s=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? AND added_by=? AND is_active=1 AND admin_blocked=0 LIMIT 1');$s->execute([$channelId,$owner]);$channel=$s->fetch();if(!$channel)return null;
        $s=$this->pdo->prepare('SELECT * FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1');$s->execute([$channelId,$target]);$grant=$s->fetch();
        if(!$grant||((bool)$grant['is_ad_manager'])!==($role==='a')||(empty($grant['can_post'])&&empty($grant['can_schedule'])&&empty($grant['can_manage'])))return null;
        $q=GitPostedMaxTopHold::quota($this->pdo,$channel,$target);return GitPostedMaxApi::callbackButton('Лимит топов: '.$q['remaining'],'gp:topq:edit:'.$channelId.':'.$target.':'.min(9999,max(0,$page)).':'.$role);
    }
    private function maxTopQuotaBack(array $flow):array
    {return GitPostedMaxApi::callbackButton('← К правам',($flow['role']==='a'?'gp:manager:':'gp:editor:').$flow['channel_id'].':'.$flow['target_id'].':'.$flow['page']);}
    private function handleMaxTopQuotaCallback(array $update):bool
    {
        $raw=$update['callback']['payload']??null;if(!is_string($raw)||!str_starts_with($raw,'gp:topq:'))return false;
        $actor=$this->maxTopUiActor($update,true);if($actor===null)return true;$id=$actor['actor_id'];$cb=$update['callback']['callback_id']??null;if(!is_string($cb)||$cb==='')return true;
        try{if($this->maxTopQuotaSavedEffect($id)!==null){$this->api->answerCallback($cb,'Это изменение уже обработано. Откройте последнее меню прав.');return true;}$route=GitPostedMaxTopQuotaView::parse($raw);$user=$actor['user'];
            if($route['action']==='cancel'){
                if(($user['state']??null)!==GitPostedMaxTopQuotaView::STATE)return true;$f=GitPostedMaxTopQuotaView::flow($user['state_data']??null,$id,true);if(!hash_equals($f['nonce'],$route['nonce']))return true;
                $this->completeMaxUpdateEffect('max_top_quota_ui',function()use($id,$user):array{$s=$this->pdo->prepare('UPDATE posted_max_users SET state=NULL,state_data=NULL WHERE max_user_id=? AND state=? AND state_data<=>CAST(? AS JSON)');$s->execute([$id,GitPostedMaxTopQuotaView::STATE,$user['state_data']]);if($s->rowCount()!==1)throw new RuntimeException('Действие изменилось.');return['actor_id'=>$id,'action'=>'cancel'];});
                $this->api->answerCallback($cb,'Ввод лимита отменён.',[[$this->maxTopQuotaBack($f)]]);return true;
            }
            if(!empty($user['state'])){$this->api->answerCallback($cb,'Сначала завершите текущее действие. Лимит не изменён.');return true;}
            $flow=$this->maxTopQuotaOwnerTransaction($id,$route['channel_id'],$route['target_id'],function(array $channel)use($id,$route):array{return$this->completeMaxUpdateEffectLocked('max_top_quota_ui',function()use($channel,$id,$route):array{
                $s=$this->pdo->prepare('SELECT * FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1 FOR UPDATE');$s->execute([$route['channel_id'],$route['target_id']]);$g=$s->fetch();
                if(!$g||$route['target_id']===$id||((bool)$g['is_ad_manager'])!==($route['role']==='a')||(empty($g['can_post'])&&empty($g['can_schedule'])&&empty($g['can_manage'])))throw new RuntimeException('Права получателя изменились.');
                $s=$this->pdo->prepare('SELECT state,state_data FROM posted_max_users WHERE max_user_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);$current=$s->fetch();if(!$current||!empty($current['state']))throw new RuntimeException('Действие изменилось.');
                $q=GitPostedMaxTopHold::quota($this->pdo,$channel,$route['target_id'],true);$flow=['version'=>1,'actor_id'=>$id,'channel_id'=>$route['channel_id'],'target_id'=>$route['target_id'],'revision'=>$q['revision'],'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>time()+600,'page'=>$route['page'],'role'=>$route['role']];
                $s=$this->pdo->prepare('UPDATE posted_max_users SET state=?,state_data=? WHERE max_user_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)');$s->execute([GitPostedMaxTopQuotaView::STATE,json_encode($flow,JSON_THROW_ON_ERROR),$id,$current['state'],$current['state_data']]);if($s->rowCount()!==1)throw new RuntimeException('Действие изменилось.');return$flow+['remaining'=>$q['remaining'],'action'=>'open'];
            });});
            $this->api->answerCallback($cb,'Лимит топов: '.$flow['remaining']."\n\nОтправьте целое число от 0 до 1000000. Один пост с ТОП расходует одну единицу. Лимиты публикаций и закреплений не изменятся.",[[GitPostedMaxApi::callbackButton('← Отмена','gp:topq:cancel:'.$flow['nonce'])]]);
        }catch(Throwable $e){error_log('GitPosted MAX TOP quota UI: '.$e::class);$this->api->answerCallback($cb,'Не удалось открыть лимит. Проверьте права и откройте меню заново.');}
        return true;
    }
    private function handleMaxTopQuotaInput(array $update):bool
    {
        $id=$update['message']['sender']['user_id']??null;try{$id=GitPostedMaxTopQuotaView::userId($id);}catch(Throwable){return false;}
        $saved=$this->maxTopQuotaSavedEffect($id);if($saved!==null)return true;
        $user=$this->user($id);if(($user['state']??null)!==GitPostedMaxTopQuotaView::STATE)return false;
        $actor=$this->maxTopUiActor($update,false);if($actor===null)return true;
        try{$flow=GitPostedMaxTopQuotaView::flow($user['state_data']??null,$id);$remaining=GitPostedMaxTopQuotaView::amount($update['message']['body']['text']??null);}
        catch(Throwable){$this->api->sendToUser($id,'Отправьте целое число от 0 до 1000000. Если меню устарело, отмените ввод и откройте лимит заново.');return true;}
        try{$saved=$this->maxTopQuotaOwnerTransaction($id,$flow['channel_id'],$flow['target_id'],function(array $channel)use($id,$flow,$remaining,$user):array{return$this->completeMaxUpdateEffectLocked('max_top_quota_ui',function()use($channel,$id,$flow,$remaining,$user):array{
            $s=$this->pdo->prepare('SELECT state,state_data FROM posted_max_users WHERE max_user_id=? LIMIT 1 FOR UPDATE');$s->execute([$id]);$current=$s->fetch();if(!$current||$current['state']!==GitPostedMaxTopQuotaView::STATE||$current['state_data']!==$user['state_data'])throw new RuntimeException('Действие изменилось.');GitPostedMaxTopQuotaView::flow($current['state_data'],$id);
            $q=GitPostedMaxTopHold::setQuotaForOwnerLocked($this->pdo,$channel,$id,$flow['target_id'],$remaining,$flow['revision']);
            $s=$this->pdo->prepare('UPDATE posted_max_users SET state=NULL,state_data=NULL WHERE max_user_id=? AND state=? AND state_data<=>CAST(? AS JSON)');$s->execute([$id,GitPostedMaxTopQuotaView::STATE,$current['state_data']]);if($s->rowCount()!==1)throw new RuntimeException('Действие изменилось.');return$q+['actor_id'=>$id,'action'=>'save'];
        });});}catch(Throwable $e){error_log('GitPosted MAX TOP quota save: '.$e::class);try{$this->api->sendToUser($id,'Лимит не изменён: права, остаток или состояние отправки изменились. Отмените ввод и откройте лимит заново.');}catch(Throwable $notice){error_log('GitPosted MAX TOP denied; private notice unavailable: '.$notice::class);}return true;}
        try{$this->api->sendToUser($id,'Лимит топов сохранён: '.$saved['remaining'].'.',[],[[$this->maxTopQuotaBack($flow)]]);}catch(Throwable $e){error_log('GitPosted MAX TOP saved; private notice unavailable: '.$e::class);}return true;
    }
}
