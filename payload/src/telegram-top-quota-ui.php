<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** TOP allowance is a user/channel balance, independent of advertising categories and pin allowance. */
final class PostedTelegramTopQuotaView
{
    public const STATE = 'await_top_quota';
    public static function id(mixed $value, bool $zero = false): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
            || (string) (int) $value !== (string) $value || (int) $value < ($zero ? 0 : 1)) throw new InvalidArgumentException('Некорректный идентификатор.');
        return (int) $value;
    }
    public static function amount(mixed $value): int
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D', trim($value)) !== 1 || (int) trim($value) > PostedTelegramTopHold::MAX_QUOTA)
            throw new InvalidArgumentException('Отправьте целое число от 0 до 1000000.');
        return (int) trim($value);
    }
    public static function parse(string $data): array
    {
        if (strlen($data) > 64) throw new InvalidArgumentException('Меню устарело.');
        if (preg_match('/\Atopq:edit:([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([0-9]{1,4})\z/D', $data, $m) === 1)
            return ['action' => 'edit', 'channel_id' => self::id($m[1]), 'target_id' => self::id($m[2]), 'page' => self::id($m[3], true)];
        if (preg_match('/\Atopq:cancel:([a-f0-9]{32})\z/D', $data, $m) === 1) return ['action' => 'cancel', 'nonce' => $m[1]];
        throw new InvalidArgumentException('Меню устарело.');
    }
    public static function flow(mixed $value, int $actor, bool $allowExpired = false): array
    {
        if (is_string($value)) $value = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        $keys = is_array($value) ? array_keys($value) : []; sort($keys); $wanted = ['version','actor_id','channel_id','target_id','revision','nonce','expires_at','page']; sort($wanted);
        if ($keys !== $wanted || $value['version'] !== 1 || $value['actor_id'] !== $actor || !is_int($value['channel_id']) || $value['channel_id'] <= 0
            || !is_int($value['target_id']) || $value['target_id'] <= 0 || !is_int($value['revision']) || $value['revision'] < 0
            || !is_int($value['page']) || $value['page'] < 0 || $value['page'] > 9999 || !is_int($value['expires_at'])
            || (!$allowExpired && $value['expires_at'] < time()) || !is_string($value['nonce']) || preg_match('/\A[a-f0-9]{32}\z/D', $value['nonce']) !== 1)
            throw new InvalidArgumentException('Срок ввода истёк. Откройте лимит заново.');
        return $value;
    }
    public static function privateActor(array $payload, bool $callback): ?int
    {
        $message = $callback ? ($payload['message'] ?? []) : $payload;
        $actor = $payload['from']['id'] ?? null; $chat = $message['chat'] ?? [];
        return is_int($actor) && $actor > 0 && ($chat['type'] ?? null) === 'private' && ($chat['id'] ?? null) === $actor ? $actor : null;
    }
}

trait PostedTelegramTopQuotaUi
{
    private function telegramTopQuotaUiActor(int $actor): bool
    { return $this->telegramPolicyOperatorAllowed($actor) && $this->isKnownActiveUser($this->user($actor)); }

    /** One order for both users prevents reciprocal owner/target grant deadlocks. */
    private function telegramTopQuotaOwnerTransaction(int $actor,int $channelId,int $targetId,Closure $operation): mixed
    {
        if($this->pdo->inTransaction())throw new LogicException('TOP quota starts outside SQL locks.');
        if(!$this->isChannelOwner($actor,$channelId))throw new RuntimeException('Owner access is unavailable.');
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            if(!$channel||(int)$channel['added_by']!==$actor||empty($channel['is_active'])||!empty($channel['admin_blocked']))throw new RuntimeException('Owner access changed.');
            PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$channel);
            $actors=array_values(array_unique([$actor,$targetId]));sort($actors,SORT_NUMERIC);
            foreach($actors as$id){$s=$this->pdo->prepare('SELECT telegram_id FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$s->execute([$id]);if($s->fetchColumn()===false)throw new RuntimeException('Actor access changed.');}
            PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
            $s=$this->pdo->prepare('SELECT can_manage_admins FROM posted_permissions WHERE user_id=? AND channel_id=? LIMIT 1 FOR UPDATE');$s->execute([$actor,$channelId]);if((int)($s->fetchColumn()?:0)!==1)throw new RuntimeException('Owner permission changed.');
            $result=$operation($channel);$this->pdo->commit();return$result;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    /** Called only from an already authorized owner grant-detail screen. No role or quota writes. */
    private function telegramTopQuotaButton(int $owner, int $channelId, int $targetId, int $page = 0): ?array
    {
        $channel = $this->channel($channelId); $grant = $this->channelPermission($targetId, $channelId);
        if (!$channel || (int) $channel['added_by'] !== $owner || $targetId === $owner || !$grant || !$this->telegramTopQuotaUiActor($owner)
            || !$this->isKnownActiveUser($this->user($targetId)) || empty($channel['is_active']) || !empty($channel['admin_blocked'])
            || (empty($grant['can_post']) && empty($grant['can_schedule']) && empty($grant['can_manage_posts']) && empty($grant['can_manage_admins']))) return null;
        $issuer = $this->channelPermission($owner, $channelId); if (!$issuer || empty($issuer['can_manage_admins'])) return null;
        $quota = PostedTelegramTopHold::quota($this->pdo, $channel, $targetId);
        $callback = 'topq:edit:'.$channelId.':'.$targetId.':'.min(9999,max(0,$page));
        if (strlen($callback) > 64) throw new RuntimeException('TOP quota callback exceeds Telegram limit.');
        return ['text' => 'Лимит топов: '.$quota['remaining'], 'callback_data' => $callback];
    }

    private function handleTelegramTopQuotaCallback(array $callback): bool
    {
        $data = $callback['data'] ?? null; if (!is_string($data) || !str_starts_with($data, 'topq:')) return false;
        $actor = PostedTelegramTopQuotaView::privateActor($callback, true);
        if ($actor === null || !$this->telegramTopQuotaUiActor($actor)) return true;
        $this->answerCallback($callback);
        try {
            $route = PostedTelegramTopQuotaView::parse($data); $user = $this->user($actor);
            if ($route['action'] === 'cancel') {
                if (($user['state'] ?? null) !== PostedTelegramTopQuotaView::STATE) return true;
                $flow = PostedTelegramTopQuotaView::flow($user['state_data'] ?? null, $actor, true);
                if (!hash_equals($flow['nonce'], $route['nonce'])) return true;
                $clear = $this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state=? AND state_data<=>CAST(? AS JSON)');
                $clear->execute([$actor, PostedTelegramTopQuotaView::STATE, $user['state_data']]);
                if ($clear->rowCount() === 1) $this->showRightsPicker($actor,$flow['channel_id'],$flow['target_id'],$flow['page']);
                return true;
            }
            if (!empty($user['state'])) { $this->telegram->sendMessage($actor,'Сначала завершите или отмените текущее действие. Лимит топов не изменён.'); return true; }
            if (!$this->isChannelOwner($actor,$route['channel_id'])) throw new RuntimeException('Owner access is unavailable.');
            $flow = $this->telegramTopQuotaOwnerTransaction($actor,$route['channel_id'],$route['target_id'],function() use ($actor,$route): array {
                $channel = $this->channel($route['channel_id']); $issuer = $this->channelPermission($actor,$route['channel_id']); $target = $this->channelPermission($route['target_id'],$route['channel_id']);
                if (!$channel || (int)$channel['added_by'] !== $actor || !$issuer || empty($issuer['can_manage_admins']) || !$target || $route['target_id'] === $actor
                    || !$this->isKnownActiveUser($this->user($route['target_id'])) || (empty($target['can_post']) && empty($target['can_schedule']) && empty($target['can_manage_posts']) && empty($target['can_manage_admins']))) throw new RuntimeException('Target grant is unavailable.');
                $state = $this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE'); $state->execute([$actor]); $current=$state->fetch();
                if (!$current || !empty($current['state'])) throw new RuntimeException('Workflow changed.');
                $quota = PostedTelegramTopHold::quota($this->pdo,$channel,$route['target_id'],true);
                $flow = ['version'=>1,'actor_id'=>$actor,'channel_id'=>$route['channel_id'],'target_id'=>$route['target_id'],'revision'=>$quota['revision'],
                    'nonce'=>bin2hex(random_bytes(16)),'expires_at'=>time()+600,'page'=>$route['page']];
                $save=$this->pdo->prepare('UPDATE posted_users SET state=?,state_data=? WHERE telegram_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)');
                $save->execute([PostedTelegramTopQuotaView::STATE,json_encode($flow,JSON_THROW_ON_ERROR),$actor,$current['state'],$current['state_data']]);
                if($save->rowCount()!==1) throw new RuntimeException('Workflow changed.'); return $flow+['remaining'=>$quota['remaining']];
            });
            if (!is_array($flow)) throw new RuntimeException('Owner access is unavailable.');
            $this->telegram->sendMessage($actor,'Лимит топов: '.$flow['remaining']."\n\nОтправьте новое количество от 0 до 1000000. Один пост с ТОП расходует одну единицу. Лимиты публикаций и закреплений не изменятся.",
                ['reply_markup'=>['inline_keyboard'=>[[['text'=>'← Отмена','callback_data'=>'topq:cancel:'.$flow['nonce']]]]]]);
        } catch (Throwable $e) {
            error_log('GitPosted TOP quota UI: '.$e::class);
            $this->telegram->sendMessage($actor,'Не удалось открыть лимит топов. Проверьте права и откройте меню заново. Данные и другие лимиты сохранены.');
        }
        return true;
    }

    private function handleTelegramTopQuotaInput(array $message, array $user): bool
    {
        if (($user['state'] ?? null) !== PostedTelegramTopQuotaView::STATE) return false;
        $actor=PostedTelegramTopQuotaView::privateActor($message,false);
        if ($actor===null || (int)($user['telegram_id']??0)!==$actor || !$this->telegramTopQuotaUiActor($actor)) return true;
        try { $flow=PostedTelegramTopQuotaView::flow($user['state_data']??null,$actor); $remaining=PostedTelegramTopQuotaView::amount($message['text']??null); }
        catch(Throwable) { $this->telegram->sendMessage($actor,'Отправьте целое число от 0 до 1000000. Если меню устарело, отмените ввод и откройте лимит заново.'); return true; }
        try {
            if (!$this->isChannelOwner($actor,$flow['channel_id'])) throw new RuntimeException('Owner access is unavailable.');
            $saved=$this->telegramTopQuotaOwnerTransaction($actor,$flow['channel_id'],$flow['target_id'],function() use ($actor,$flow,$remaining,$user): array {
                $state=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE'); $state->execute([$actor]); $current=$state->fetch();
                if(!$current || $current['state']!==PostedTelegramTopQuotaView::STATE || $current['state_data']!==$user['state_data']) throw new RuntimeException('Workflow changed.');
                PostedTelegramTopQuotaView::flow($current['state_data'],$actor);
                $channel=$this->channel($flow['channel_id']); if(!$channel) throw new RuntimeException('Channel is unavailable.');
                $quota=PostedTelegramTopHold::setQuotaForOwnerLocked($this->pdo,$channel,$actor,$flow['target_id'],$remaining,$flow['revision']);
                $clear=$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state=? AND state_data<=>CAST(? AS JSON)');
                $clear->execute([$actor,PostedTelegramTopQuotaView::STATE,$current['state_data']]); if($clear->rowCount()!==1) throw new RuntimeException('Workflow changed.'); return $quota;
            });
            if(!is_array($saved)) throw new RuntimeException('Owner access is unavailable.');
        } catch(Throwable $e) {
            error_log('GitPosted TOP quota save: '.$e::class);
            $this->telegram->sendMessage($actor,'Лимит не изменён: права, остаток или состояние отправки изменились. Отмените ввод и откройте лимит заново.'); return true;
        }
        // SQL committed. A private UI failure must never retry the allowance write.
        try { $this->telegram->sendMessage($actor,'Лимит топов сохранён: '.$saved['remaining'].'.', ['reply_markup'=>['inline_keyboard'=>[[['text'=>'← К правам','callback_data'=>'admin_edit:'.$flow['channel_id'].':'.$flow['target_id'].':'.$flow['page']]]]]]); }
        catch(Throwable $e) { error_log('GitPosted TOP quota saved; private notice unavailable: '.$e::class); }
        return true;
    }
}
