<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Read-only views. A view capability is never a channel grant or a resume approval. */
final class PostedTelegramAuthorityPauseView
{
    public const CHANNEL_LIMIT = 20;
    public const POST_LIMIT = 10;
    public const MAX_OFFSET = 10000;
    public const PHASES = ['paused', 'review_required', 'resumed', 'closed_explicitly'];
    public const REASONS = ['owner_role_lost', 'bot_removed', 'bot_rights_lost', 'author_permission_lost', 'author_inactive', 'category_unavailable', 'explicit_disable', 'explicit_admin_detach'];
    public const STATUSES = ['draft', 'scheduled', 'failed', 'publishing', 'telegram_pending', 'sent', 'cancelled', 'deleted'];
    public const MEDIA = ['', 'none', 'text', 'photo', 'video', 'animation', 'document', 'audio', 'voice', 'video_note', 'sticker', 'media_group'];
    public const BLOCKERS = ['overdue_posts','missing_schedule','author_permissions','residual_publish','pending_ledger','pending_multipost','owner_change_requires_review'];

    public static function id(mixed $value, bool $zero = false, ?int $max = null): int
    {
        if (is_int($value)) $raw = (string) $value;
        elseif (is_string($value)) $raw = $value;
        else throw new InvalidArgumentException('Invalid view identifier.');
        if (preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D', $raw) !== 1
            || (string) (int) $raw !== $raw || (int) $raw < ($zero ? 0 : 1)
            || ($max !== null && (int) $raw > $max)) throw new InvalidArgumentException('Invalid view identifier.');
        return (int) $raw;
    }

    /** No arbitrary callback fields or mutation actions share this namespace. */
    public static function parse(string $callback): array
    {
        if (strlen($callback) > 64) throw new InvalidArgumentException('Invalid pause callback.');
        if (preg_match('/\Apause:(list|owners):([0-9]+):(cp|settings|channels)\z/D', $callback, $m) === 1)
            return ['action'=>$m[1], 'value'=>self::id($m[2], true, self::MAX_OFFSET), 'cursor'=>null, 'origin'=>$m[3]];
        if (preg_match('/\Apause:(channel):([0-9]+):([0-9]+):(cp|settings|channels)\z/D', $callback, $m) === 1)
            return ['action'=>$m[1], 'value'=>self::id($m[2]), 'cursor'=>self::id($m[3], true) ?: null, 'origin'=>$m[4]];
        if (preg_match('/\Apause:(post|help|verify):([0-9]+):(cp|settings|channels)\z/D', $callback, $m) === 1)
            return ['action'=>$m[1], 'value'=>self::id($m[2]), 'cursor'=>null, 'origin'=>$m[3]];
        if (preg_match('/\Apause:(ok):([0-9]+):([a-f0-9]{32})\z/D', $callback, $m) === 1)
            return ['action'=>$m[1], 'value'=>self::id($m[2]), 'cursor'=>null, 'origin'=>'cp', 'nonce'=>$m[3]];
        if (preg_match('/\Apause:(exit):(cp|settings|channels)\z/D', $callback, $m) === 1)
            return ['action'=>$m[1], 'value'=>0, 'cursor'=>null, 'origin'=>$m[2]];
        throw new InvalidArgumentException('Invalid pause callback.');
    }

    public static function origin(string $origin): string
    {
        if (!in_array($origin, ['cp', 'settings', 'channels'], true)) throw new InvalidArgumentException('Invalid pause origin.');
        return $origin;
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value); sort($keys); sort($expected);
        if ($keys !== $expected) throw new InvalidArgumentException('Invalid read-only view data.');
    }

    public static function channel(array $row): array
    {
        self::keys($row, ['id','title','phase','reason','since','generation','review_required','own_counts']);
        if (!is_int($row['id']) || !is_string($row['title']) || mb_strlen($row['title'], 'UTF-8') > 1000
            || !in_array($row['phase'], self::PHASES, true) || !in_array($row['reason'], self::REASONS, true)
            || !is_int($row['since']) || $row['since'] <= 0 || $row['since'] > 32503680000
            || !is_string($row['generation']) || preg_match('/\A[a-f0-9]{32}\z/D', $row['generation']) !== 1
            || !is_bool($row['review_required']) || !is_array($row['own_counts'])) throw new InvalidArgumentException('Invalid pause binding.');
        self::id($row['id']);
        if ($row['review_required'] !== in_array($row['phase'], ['paused','review_required'], true)) throw new InvalidArgumentException('Invalid pause phase.');
        self::keys($row['own_counts'], [...self::STATUSES, 'future', 'overdue']);
        foreach ($row['own_counts'] as $count) if (!is_int($count) || $count < 0 || $count > 1000000000) throw new InvalidArgumentException('Invalid own post count.');
        return $row;
    }

    public static function post(array $row, int $actor, int $channel): array
    {
        self::keys($row, ['id','channel_id','author_id','status','scheduled_at','text','media_type','repeat_interval_minutes','repeat_until']);
        if (!is_int($row['id']) || !is_int($row['channel_id']) || !is_int($row['author_id'])
            || $row['author_id'] !== $actor || $row['channel_id'] !== $channel
            || !in_array($row['status'], self::STATUSES, true) || !is_string($row['text']) || mb_strlen($row['text'], 'UTF-8') > 600
            || !is_string($row['media_type']) || !in_array($row['media_type'], self::MEDIA, true)
            || ($row['repeat_interval_minutes'] !== null && (!is_int($row['repeat_interval_minutes']) || $row['repeat_interval_minutes'] < 0 || $row['repeat_interval_minutes'] > 10000000))) throw new InvalidArgumentException('Invalid own post binding.');
        self::id($row['id']); self::id($actor); self::id($channel);
        foreach (['scheduled_at','repeat_until'] as $field) {
            if ($row[$field] === null) continue;
            if (!is_string($row[$field]) || preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $row[$field]) !== 1) throw new InvalidArgumentException('Invalid stored date.');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row[$field], new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $row[$field]) throw new InvalidArgumentException('Invalid stored date.');
        }
        return $row;
    }

    public static function listing(array $data, int $offset, int $limit): array
    {
        self::keys($data, ['items','total','offset','limit']);
        if (!is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > $limit
            || !is_int($data['total']) || $data['total'] < 0 || $data['total'] > 1000000
            || $data['offset'] !== $offset || $data['limit'] !== $limit) throw new InvalidArgumentException('Invalid pause list.');
        $seen=[];
        foreach ($data['items'] as &$row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid pause list item.');
            $row=self::channel($row);
            if (!$row['review_required'] || isset($seen[$row['id']])) throw new InvalidArgumentException('Invalid listed pause binding.');
            $seen[$row['id']]=true;
        }
        unset($row);
        if (count($data['items']) > max(0, $data['total']-$offset)) throw new InvalidArgumentException('Invalid pause total.');
        return $data;
    }

    public static function posts(array $data, int $actor, int $channel, ?int $cursor, int $limit): array
    {
        self::keys($data, ['channel','items','next_cursor']);
        if (!is_array($data['channel']) || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > $limit) throw new InvalidArgumentException('Invalid own post list.');
        $data['channel']=self::channel($data['channel']);
        if ($data['channel']['id'] !== $channel) throw new InvalidArgumentException('Invalid channel binding.');
        if ($data['next_cursor'] !== null && (!is_int($data['next_cursor']) || self::id($data['next_cursor']) === $cursor || $data['items'] === [])) throw new InvalidArgumentException('Invalid post cursor.');
        $seen=[];
        foreach ($data['items'] as &$post) {
            if (!is_array($post)) throw new InvalidArgumentException('Invalid own post list item.');
            $post=self::post($post, $actor, $channel);
            if (isset($seen[$post['id']])) throw new InvalidArgumentException('Duplicate own post.');
            $seen[$post['id']]=true;
        }
        unset($post);
        return $data;
    }

    public static function detail(?array $data, int $actor, int $post): ?array
    {
        if ($data === null) return null;
        self::keys($data, ['channel','post']);
        if (!is_array($data['channel']) || !is_array($data['post'])) throw new InvalidArgumentException('Invalid own post view.');
        $data['channel']=self::channel($data['channel']);
        $data['post']=self::post($data['post'], $actor, $data['channel']['id']);
        if ($data['post']['id'] !== $post) throw new InvalidArgumentException('Invalid post identifier.');
        return $data;
    }

    /** The prepare API has already verified CURRENT ownership/grants using fresh Telegram proof. */
    public static function ownerReview(array $review,int $channel): array
    {
        self::keys($review,['channel_id','title','generation','nonce','expires_at','future_count','overdue_count','blocked_reasons','can_resume']);
        if ($review['channel_id']!==$channel || !is_string($review['title']) || mb_strlen($review['title'],'UTF-8')>1000
            || !is_string($review['generation']) || preg_match('/\A[a-f0-9]{32}\z/D',$review['generation'])!==1
            || !is_string($review['nonce']) || preg_match('/\A[a-f0-9]{32}\z/D',$review['nonce'])!==1
            || !is_int($review['expires_at']) || $review['expires_at']<=time() || $review['expires_at']>time()+86400
            || !is_int($review['future_count']) || $review['future_count']<0 || $review['future_count']>1000000000
            || !is_int($review['overdue_count']) || $review['overdue_count']<0 || $review['overdue_count']>1000000000
            || !is_array($review['blocked_reasons']) || !array_is_list($review['blocked_reasons']) || count($review['blocked_reasons'])>count(self::BLOCKERS)
            || !is_bool($review['can_resume'])) throw new InvalidArgumentException('Invalid owner review.');
        $seen=[];
        foreach ($review['blocked_reasons'] as $reason) {
            if (!is_string($reason) || !in_array($reason,self::BLOCKERS,true) || isset($seen[$reason])) throw new InvalidArgumentException('Invalid review blocker.');
            $seen[$reason]=true;
        }
        if ($review['can_resume']!==($review['blocked_reasons']===[] && $review['overdue_count']===0)) throw new InvalidArgumentException('Invalid resume readiness.');
        return $review;
    }

    public static function blocker(string $reason,bool $en): string
    {
        return match ($reason) {
            'overdue_posts'=>self::text($en,'Для прошедших и ближайших публикаций нужно выбрать новые даты или отменить их.','Past-due and imminent publications need new dates or must be cancelled.'),
            'missing_schedule'=>self::text($en,'У части запланированных постов не задана корректная дата.','Some scheduled posts do not have a valid date.'),
            'author_permissions'=>self::text($en,'Нужно проверить действующие права авторов и рекламные категории.','Current author permissions and advertising categories need review.'),
            'residual_publish'=>self::text($en,'Есть незавершённая отправка; сначала нужно установить её результат.','A publication is unfinished; its result must be established first.'),
            'pending_ledger'=>self::text($en,'Результат операции Telegram ещё не подтверждён.','A Telegram operation result is still unconfirmed.'),
            'pending_multipost'=>self::text($en,'Есть незавершённый мультипостинг.','A multipost operation is unfinished.'),
            'owner_change_requires_review'=>self::text($en,'Смена владельца требует отдельной проверки администратора сервиса.','An ownership change requires a separate review by the service administrator.'),
            default=>throw new InvalidArgumentException('Invalid review blocker.'),
        };
    }

    public static function text(bool $en, string $ru, string $english): string { return $en ? $english : $ru; }
    public static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function title(string $value, int $max=55): string
    {
        $value=preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';
        $value=trim($value); if ($value==='') $value='—';
        return mb_strlen($value, 'UTF-8')>$max ? mb_substr($value,0,$max-1,'UTF-8').'…' : $value;
    }

    public static function intro(array $channel, bool $en): string
    {
        $title=self::escape(self::title($channel['title'],64));
        return match ($channel['phase']) {
            'paused' => self::text($en,
                "⏸ <b>ПУБЛИКАЦИИ ПРИОСТАНОВЛЕНЫ</b>\n\nКанал: {$title}\nВаши посты, даты и настройки сохранены. Отправка остановлена. После проверки прав даты потребуется пересмотреть. Пропущенные публикации сами не выйдут.",
                "⏸ <b>PUBLICATION PAUSED</b>\n\nChannel: {$title}\nYour posts, dates and settings are preserved. Publication has stopped. Dates need review after access is verified. Missed posts will not be published automatically."),
            'review_required' => self::text($en,
                "⏸ <b>НУЖНА ПРОВЕРКА ПЛАНА</b>\n\nКанал: {$title}\nКанал подключён повторно. Ваши посты, даты и настройки сохранены; отправка остаётся приостановленной до проверки плана. Пропущенные публикации сами не выйдут.",
                "⏸ <b>PLAN REVIEW REQUIRED</b>\n\nChannel: {$title}\nThe channel is connected again. Your posts, dates and settings are preserved; publication remains paused until the plan is reviewed. Missed posts will not be published automatically."),
            'resumed' => self::text($en,
                "🗂 <b>ВАША СОХРАНЁННАЯ ЗАПИСЬ</b>\n\nКанал: {$title}\nЗдесь доступны только ваши записи. Этот просмотр не даёт права управлять каналом или отправлять публикации.",
                "🗂 <b>YOUR SAVED RECORD</b>\n\nChannel: {$title}\nOnly your records are shown here. This view does not grant channel management or publication rights."),
            'closed_explicitly' => self::text($en,
                "🗂 <b>КАНАЛ ОТКЛЮЧЁН</b>\n\nКанал: {$title}\nКанал отключён по подтверждённой команде. Здесь доступна ваша сохранённая запись. Даты и повторы могли быть очищены при отключении.",
                "🗂 <b>CHANNEL DISCONNECTED</b>\n\nChannel: {$title}\nThe channel was disconnected by an explicit command. Your saved record is available here. Dates and repeats may have been cleared when it was disconnected."),
        };
    }

    public static function reason(string $reason, bool $en): string
    {
        return match ($reason) {
            'owner_role_lost'=>self::text($en,'У прежнего владельца GitPosted изменились права в Telegram.','The previous GitPosted owner’s Telegram role changed.'),
            'bot_removed'=>self::text($en,'Бот удалён из канала.','The bot was removed from the channel.'),
            'bot_rights_lost'=>self::text($en,'У бота недостаточно прав для публикации.','The bot no longer has the required publication rights.'),
            'author_permission_lost'=>self::text($en,'Доступ автора к управлению публикациями изменился.','The author’s publication access changed.'),
            'author_inactive'=>self::text($en,'Доступ автора к боту был отключён.','The author’s access to the bot was disabled.'),
            'category_unavailable'=>self::text($en,'Рекламная категория стала недоступна.','The advertising category is no longer available.'),
            'explicit_disable'=>self::text($en,'Канал отключён по подтверждённой команде.','The channel was explicitly disconnected.'),
            'explicit_admin_detach'=>self::text($en,'Администратор сервиса отключил канал.','A service administrator disconnected the channel.'),
            default=>throw new InvalidArgumentException('Invalid pause reason.'),
        };
    }

    public static function status(string $status, bool $en): string
    {
        $labels=['draft'=>['Черновик','Draft'],'scheduled'=>['Запланирован','Scheduled'],'failed'=>['Ошибка публикации','Publication failed'],
            'publishing'=>['Результат отправки проверяется','Publication result is being checked'],'telegram_pending'=>['Ожидает подтверждения Telegram','Awaiting Telegram confirmation'],
            'sent'=>['Опубликован','Published'],'cancelled'=>['Отменён','Cancelled'],'deleted'=>['Удалён','Deleted']];
        if (!isset($labels[$status])) throw new InvalidArgumentException('Invalid post status.');
        return $labels[$status][$en ? 1 : 0];
    }
}

/** Read views use SQL-only readers. Explicit owner-check/confirm actions delegate to the separate guarded core API. */
trait PostedTelegramAuthorityPauseUi
{
    private function telegramAuthorityPauseUiAllowed(int $actor): bool
    {
        return $actor>0 && PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo)
            && $this->isKnownActiveUser($this->user($actor));
    }

    private function telegramAuthorityPauseUiEnglish(int $actor): bool { return $this->telegramUiLanguage($actor)==='en'; }

    /** Add this button to existing menus. Invalid metadata remains discoverable as an honest error screen. */
    private function telegramAuthorityPauseEntryButton(int $actor,string $origin): ?array
    {
        PostedTelegramAuthorityPauseView::origin($origin);
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) return null;
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        try {
            $list=PostedTelegramAuthorityPauseView::listing(PostedTelegramAuthorityPause::listForAuthor($this->pdo,$actor,0,1),0,1);
            $owned=PostedTelegramAuthorityPauseView::listing(PostedTelegramAuthorityPause::listForOwner($this->pdo,$actor,0,1),0,1);
            if ($list['total']===0 && $owned['total']===0) return null;
            $text=PostedTelegramAuthorityPauseView::text($en,'⏸ Приостановленные каналы','⏸ Paused channels');
            if ($owned['total']===0) $text.=' ('.$list['total'].')';
        } catch (Throwable) {
            $text=PostedTelegramAuthorityPauseView::text($en,'⏸ Сохранённые посты: нужна проверка','⏸ Saved posts: review required');
        }
        return ['text'=>$text,'callback_data'=>'pause:list:0:'.$origin];
    }

    /** Dispatch BEFORE generic workflow clearing; private chat binding is required even for reads. */
    private function handleTelegramAuthorityPauseCallback(array $callback): bool
    {
        $raw=$callback['data']??null;
        if (!is_string($raw)||!str_starts_with($raw,'pause:')) return false;
        $actor=$callback['from']['id']??null;
        $chat=$callback['message']['chat']??null;
        if (!is_int($actor)||$actor<=0||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor
            ||!$this->telegramAuthorityPauseUiAllowed($actor)) {
            $this->answerCallback($callback,'Недоступно.'); return true;
        }
        $target=$this->callbackTarget($callback);
        $this->answerCallback($callback);
        try {
            $action=PostedTelegramAuthorityPauseView::parse($raw);
            match ($action['action']) {
                'list'=>$this->showTelegramAuthorityPauseChannels($actor,$action['value'],$action['origin'],$target),
                'owners'=>$this->showTelegramAuthorityPauseOwnerChannels($actor,$action['value'],$action['origin'],$target),
                'channel'=>$this->showTelegramAuthorityPausePosts($actor,$action['value'],$action['cursor'],$action['origin'],$target),
                'post'=>$this->showTelegramAuthorityPausePost($actor,$action['value'],$action['origin'],$target),
                'help'=>$this->showTelegramAuthorityPauseHelp($actor,$action['value'],$action['origin'],$target),
                'verify'=>$this->showTelegramAuthorityPauseOwnerReview($actor,$action['value'],$action['origin'],$target),
                'ok'=>$this->confirmTelegramAuthorityPauseOwnerReview($actor,$action['value'],$action['nonce'],$target),
                'exit'=>$this->exitTelegramAuthorityPauseUi($actor,$action['origin'],$target),
            };
        } catch (Throwable $error) {
            $this->showTelegramAuthorityPauseError($actor,$error,$target,in_array($action['action']??'', ['verify','ok'],true));
        }
        return true;
    }

    private function telegramAuthorityPauseUiSend(int $actor,string $text,array $keyboard,?array $target): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        if ($target!==null && (($target['chat_id']??null)!==$actor || !is_int($target['message_id']??null) || $target['message_id']<=0))
            throw new InvalidArgumentException('Invalid private view target.');
        $this->sendOrEditMessage($actor,$text,['parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true],
            'reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
    }

    private function showTelegramAuthorityPauseChannels(int $actor,int $offset=0,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        PostedTelegramAuthorityPauseView::origin($origin); PostedTelegramAuthorityPauseView::id($offset,true,PostedTelegramAuthorityPauseView::MAX_OFFSET);
        $en=$this->telegramAuthorityPauseUiEnglish($actor); $v=PostedTelegramAuthorityPauseView::class;
        $data=$v::listing(PostedTelegramAuthorityPause::listForAuthor($this->pdo,$actor,$offset,$v::CHANNEL_LIMIT),$offset,$v::CHANNEL_LIMIT);
        $owned=$v::listing(PostedTelegramAuthorityPause::listForOwner($this->pdo,$actor,0,1),0,1);
        $text=$v::text($en,"⏸ <b>ПРИОСТАНОВЛЕННЫЕ КАНАЛЫ</b>\n\nЗдесь показаны только ваши сохранённые публикации. Просмотр не возвращает права на канал.","⏸ <b>PAUSED CHANNELS</b>\n\nOnly your saved publications are shown here. Viewing them does not restore channel rights.");
        $keyboard=[];
        if ($owned['total']>0) $keyboard[]=[['text'=>$v::text($en,'Проверка моих каналов','Review my channels').' ('.$owned['total'].')','callback_data'=>'pause:owners:0:'.$origin]];
        foreach ($data['items'] as $channel) {
            $own=0; foreach ($v::STATUSES as $status) $own+=$channel['own_counts'][$status];
            $keyboard[]=[['text'=>'⏸ '.$v::title($channel['title'],45).' · '.$own,
                'callback_data'=>'pause:channel:'.$channel['id'].':0:'.$origin]];
        }
        if ($data['items']===[]) $text.=$v::text($en,"\n\nНа этой странице нет каналов с вашими сохранёнными постами.","\n\nThere are no channels with your saved posts on this page.");
        $nav=[];
        if ($offset>0) $nav[]=['text'=>'←','callback_data'=>'pause:list:'.max(0,$offset-$v::CHANNEL_LIMIT).':'.$origin];
        if ($offset+$v::CHANNEL_LIMIT<=$v::MAX_OFFSET && $offset+count($data['items'])<$data['total'] && $data['items']!==[]) $nav[]=['text'=>'→','callback_data'=>'pause:list:'.($offset+$v::CHANNEL_LIMIT).':'.$origin];
        if ($nav!==[]) $keyboard[]=$nav;
        $keyboard[]=[['text'=>$v::text($en,'← Назад','← Back'),'callback_data'=>'pause:exit:'.$origin]];
        $this->telegramAuthorityPauseUiSend($actor,$text,$keyboard,$target);
    }

    private function showTelegramAuthorityPausePosts(int $actor,int $channel,?int $cursor=null,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::origin($origin); $v::id($channel); if ($cursor!==null) $v::id($cursor);
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $data=$v::posts(PostedTelegramAuthorityPause::postsForAuthor($this->pdo,$actor,$channel,$cursor,$v::POST_LIMIT),$actor,$channel,$cursor,$v::POST_LIMIT);
        $row=$data['channel']; $counts=$row['own_counts'];
        $text=$v::intro($row,$en)."\n\n".$v::reason($row['reason'],$en);
        $since=(new DateTimeImmutable('@'.$row['since']))->setTimezone($this->userTimezoneObject($actor))->format('d.m.Y H:i');
        $text.="\n".$v::text($en,'Событие: ','Event: ').$v::escape($since);
        $text.=$v::text($en,"\n\nВаших запланированных постов: ","\n\nYour scheduled posts: ").$counts['scheduled'];
        $text.=$v::text($en,"\nБудущие даты: ","\nFuture dates: ").$counts['future'].$v::text($en,' · Пропущенные: ',' · Missed: ').$counts['overdue'];
        $keyboard=[];
        foreach ($data['items'] as $post) {
            $date=$post['scheduled_at']===null ? $v::status($post['status'],$en) : $this->formatStoredForUser($actor,$post['scheduled_at'],'d.m.Y H:i');
            $label=$v::title(trim($post['text'])!==''?$post['text']:$v::text($en,'[Медиа]','[Media]'),30);
            $keyboard[]=[['text'=>$date.' · '.$label,'callback_data'=>'pause:post:'.$post['id'].':'.$origin]];
        }
        if ($data['items']===[]) $text.=$v::text($en,"\n\nНа этой странице нет ваших постов.","\n\nThere are no posts of yours on this page.");
        $nav=[];
        if ($cursor!==null) $nav[]=['text'=>$v::text($en,'В начало списка','First page'),'callback_data'=>'pause:channel:'.$channel.':0:'.$origin];
        if ($data['next_cursor']!==null) $nav[]=['text'=>$v::text($en,'Далее →','Next →'),'callback_data'=>'pause:channel:'.$channel.':'.$data['next_cursor'].':'.$origin];
        if ($nav!==[]) $keyboard[]=$nav;
        $keyboard[]=[['text'=>$v::text($en,'Как вернуть доступ','How to restore access'),'callback_data'=>'pause:help:'.$channel.':'.$origin]];
        if ($row['review_required']) $keyboard[]=[['text'=>$v::text($en,'Проверить права','Check access'),'callback_data'=>'pause:verify:'.$channel.':'.$origin]];
        $keyboard[]=[['text'=>$v::text($en,'← Каналы','← Channels'),'callback_data'=>'pause:list:0:'.$origin]];
        $this->telegramAuthorityPauseUiSend($actor,$text,$keyboard,$target);
    }

    private function showTelegramAuthorityPausePost(int $actor,int $post,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::origin($origin); $v::id($post);
        $data=$v::detail(PostedTelegramAuthorityPause::postForAuthor($this->pdo,$actor,$post),$actor,$post);
        if ($data===null) throw new RuntimeException('not_found');
        $en=$this->telegramAuthorityPauseUiEnglish($actor); $row=$data['post']; $channel=$data['channel'];
        $date=$row['scheduled_at']===null ? $v::text($en,'не задана','not set') : $this->formatStoredForUser($actor,$row['scheduled_at'],'d.m.Y H:i');
        $text=$v::intro($channel,$en)."\n\n".$v::text($en,'Статус записи: ','Record status: ').$v::status($row['status'],$en);
        $text.="\n".$v::text($en,'Дата: ','Date: ').$v::escape($date);
        $repeat=$row['repeat_interval_minutes'];
        $text.="\n".$v::text($en,'Автоповтор: ','Repeat: ').($repeat===null||$repeat===0?$v::text($en,'нет','none'):$repeat.$v::text($en,' мин',' min'));
        if ($row['repeat_until']!==null) $text.=$v::text($en,' до ',' until ').$v::escape($this->formatStoredForUser($actor,$row['repeat_until'],'d.m.Y H:i'));
        if (!in_array($row['media_type'],['','none','text'],true)) $text.="\n".$v::text($en,'Медиа: ','Media: ').$v::escape($row['media_type']).$v::text($en,' (без загрузки)',' (not loaded)');
        $text.=$v::text($en,"\n\n<b>Фрагмент текста</b>\n","\n\n<b>Text excerpt</b>\n").$v::escape(trim($row['text'])!==''?$row['text']:$v::text($en,'[Без текста]','[No text]'));
        $keyboard=[[['text'=>$v::text($en,'← Ваши посты','← Your posts'),'callback_data'=>'pause:channel:'.$channel['id'].':0:'.$origin]]];
        $this->telegramAuthorityPauseUiSend($actor,$text,$keyboard,$target);
    }

    private function showTelegramAuthorityPauseHelp(int $actor,int $channel,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::origin($origin); $v::id($channel);
        $data=$v::posts(PostedTelegramAuthorityPause::postsForAuthor($this->pdo,$actor,$channel,null,1),$actor,$channel,null,1);
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $text=$v::intro($data['channel'],$en).$v::text($en,
            "\n\n<b>Как вернуть доступ</b>\nДействующий администратор должен проверить права бота и повторно подключить канал через «Настройки → Добавить новый канал». Сам просмотр сохранённых постов не назначает владельца и не выдаёт доступ.\n\nПосле подключения потребуется отдельно проверить план, включая пропущенные даты, и подтвердить продолжение отправки. Если права уже проверены, откройте обычные настройки канала.",
            "\n\n<b>How to restore access</b>\nA current administrator must verify the bot’s rights and reconnect the channel through Settings → Add new channel. Viewing saved posts does not assign ownership or grant access.\n\nAfter connecting, the plan and missed dates must be reviewed separately before publication can resume. If access has already been verified, open the channel’s normal settings.");
        $this->telegramAuthorityPauseUiSend($actor,$text,[[['text'=>$v::text($en,'← Ваши посты','← Your posts'),'callback_data'=>'pause:channel:'.$channel.':0:'.$origin]]],$target);
    }

    private function showTelegramAuthorityPauseOwnerChannels(int $actor,int $offset=0,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::origin($origin); $v::id($offset,true,$v::MAX_OFFSET);
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $data=$v::listing(PostedTelegramAuthorityPause::listForOwner($this->pdo,$actor,$offset,$v::CHANNEL_LIMIT),$offset,$v::CHANNEL_LIMIT);
        $text=$v::text($en,"⏸ <b>ПРОВЕРКА МОИХ КАНАЛОВ</b>\n\nЭто каналы, где вы сейчас указаны владельцем GitPosted. Выберите канал для новой проверки прав и плана. Публикации других авторов здесь не показываются.","⏸ <b>REVIEW MY CHANNELS</b>\n\nYou are currently listed as the GitPosted owner of these channels. Choose one for a fresh access and plan check. Other authors’ publications are not shown here.");
        $keyboard=[];
        foreach ($data['items'] as $channel) $keyboard[]=[['text'=>'⏸ '.$v::title($channel['title']),'callback_data'=>'pause:verify:'.$channel['id'].':'.$origin]];
        if ($data['items']===[]) $text.=$v::text($en,"\n\nНа этой странице нет каналов для проверки.","\n\nThere are no channels to review on this page.");
        $nav=[];
        if ($offset>0) $nav[]=['text'=>'←','callback_data'=>'pause:owners:'.max(0,$offset-$v::CHANNEL_LIMIT).':'.$origin];
        if ($offset+$v::CHANNEL_LIMIT<=$v::MAX_OFFSET && $offset+count($data['items'])<$data['total'] && $data['items']!==[]) $nav[]=['text'=>'→','callback_data'=>'pause:owners:'.($offset+$v::CHANNEL_LIMIT).':'.$origin];
        if ($nav!==[]) $keyboard[]=$nav;
        $keyboard[]=[['text'=>$v::text($en,'← Сохранённые посты','← Saved posts'),'callback_data'=>'pause:list:0:'.$origin]];
        $this->telegramAuthorityPauseUiSend($actor,$text,$keyboard,$target);
    }

    private function showTelegramAuthorityPauseOwnerReview(int $actor,int $channel,string $origin='cp',?array $target=null): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::origin($origin); $v::id($channel);
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $review=$v::ownerReview($this->telegramAuthorityOwnerReviewPrepare($actor,$channel),$channel);
        $text=$v::text($en,'<b>ПРОВЕРКА ПЕРЕД ВОЗОБНОВЛЕНИЕМ</b>','<b>REVIEW BEFORE RESUMING</b>')."\n\n".$v::escape($v::title($review['title'],64));
        $text.=$v::text($en,"\nБудущие публикации: ","\nFuture publications: ").$review['future_count'];
        $text.=$v::text($en,"\nПрошедшие и ближайшие даты (до 5 минут): ","\nPast-due and imminent dates (within 5 minutes): ").$review['overdue_count'];
        $keyboard=[];
        if ($review['can_resume']) {
            $text.=$v::text($en,"\n\nПрава и план проверены. После подтверждения будущие публикации смогут выходить по сохранённым датам. Пропущенные публикации автоматически не запускаются.","\n\nAccess and the plan have been checked. After confirmation, future publications may proceed on their saved dates. Missed publications will not be started automatically.");
            $keyboard[]=[['text'=>$v::text($en,'Возобновить отправку','Resume publication'),'callback_data'=>'pause:ok:'.$channel.':'.$review['nonce']]];
        } else {
            $text.=$v::text($en,"\n\nОтправка пока остаётся приостановленной:","\n\nPublication remains paused:");
            foreach ($review['blocked_reasons'] as $reason) $text.="\n• ".$v::blocker($reason,$en);
            $text.=$v::text($en,"\n\nРазберите эти пункты в обычном контент-плане или с администратором сервиса, затем проверьте канал снова.","\n\nResolve these items in the normal content plan or with the service administrator, then check the channel again.");
        }
        $keyboard[]=[['text'=>$v::text($en,'Проверить заново','Check again'),'callback_data'=>'pause:verify:'.$channel.':'.$origin]];
        $keyboard[]=[['text'=>$v::text($en,'← Мои каналы','← My channels'),'callback_data'=>'pause:owners:0:'.$origin]];
        $this->telegramAuthorityPauseUiSend($actor,$text,$keyboard,$target);
    }

    private function confirmTelegramAuthorityPauseOwnerReview(int $actor,int $channel,string $nonce,?array $target): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        $v=PostedTelegramAuthorityPauseView::class; $v::id($channel);
        if (preg_match('/\A[a-f0-9]{32}\z/D',$nonce)!==1) throw new InvalidArgumentException('Invalid review nonce.');
        // Generation is resolved only from the core server record bound to this exact actor/channel/nonce/TTL.
        $generation=$this->telegramAuthorityOwnerReviewBinding($actor,$channel,$nonce);
        if (!is_string($generation)||preg_match('/\A[a-f0-9]{32}\z/D',$generation)!==1) throw new InvalidArgumentException('Invalid review binding.');
        $result=$this->telegramAuthorityOwnerReviewConfirm($actor,$channel,$nonce,$generation);
        if (!is_array($result)||array_diff(array_keys($result),['resumed','notice'])!==[]||count($result)!==2||!is_bool($result['resumed']??null)
            ||!is_string($result['notice']??null)||mb_strlen($result['notice'],'UTF-8')>1000) throw new InvalidArgumentException('Invalid resume outcome.');
        $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $text=$result['resumed'] ? $v::text($en,'✅ Отправка возобновлена. Будущие публикации будут обрабатываться по сохранённым датам.','✅ Publication resumed. Future publications will be processed on their saved dates.')
            : $v::text($en,'Отправка не возобновлена. Нужна новая проверка плана.','Publication was not resumed. The plan needs a fresh review.');
        if ($result['notice']!=='') $text.="\n\n".$v::escape($result['notice']);
        $this->telegramAuthorityPauseUiSend($actor,$text,[[['text'=>$v::text($en,'← Контент-план','← Content plan'),'callback_data'=>'pause:exit:cp']]],$target);
    }

    private function showTelegramAuthorityPauseError(int $actor,Throwable $error,?array $target,bool $ownerAction=false): void
    {
        $v=PostedTelegramAuthorityPauseView::class; $en=$this->telegramAuthorityPauseUiEnglish($actor);
        $reason=$error instanceof PostedTelegramAuthorityPauseException ? $error->reason : '';
        $notFound=in_array($reason,['not_found','actor_denied'],true)||in_array($error->getMessage(),['not_found','actor_denied'],true);
        $text=$ownerAction ? $v::text($en,'Не удалось подтвердить права или результат проверки плана. Проверьте текущее состояние канала. Операция не повторяется автоматически.','Access or the plan review result could not be confirmed. Check the channel’s current state. The operation is not retried automatically.')
            : ($notFound ? $v::text($en,'Запись недоступна. Откройте список заново.','This record is unavailable. Open the list again.')
            : $v::text($en,'Не удалось проверить сохранённые записи. Нужна проверка администратора. Этот экран ничего не изменил и не запускал публикации.','Saved records could not be verified. An administrator needs to check them. This screen did not change records or start publication.'));
        $this->telegramAuthorityPauseUiSend($actor,$text,[[['text'=>$v::text($en,'← Список каналов','← Channel list'),'callback_data'=>'pause:list:0:cp']]],$target);
    }

    private function exitTelegramAuthorityPauseUi(int $actor,string $origin,?array $target): void
    {
        if (!$this->telegramAuthorityPauseUiAllowed($actor)) throw new RuntimeException('actor_denied');
        match (PostedTelegramAuthorityPauseView::origin($origin)) {
            'cp'=>$this->showContentPlanChannels($actor,$target),
            'settings'=>$this->showSettings($actor,$target),
            'channels'=>$this->showMyChannels($actor,$target),
        };
    }
}
