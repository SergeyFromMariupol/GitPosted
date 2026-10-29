<?php

declare(strict_types=1);

if (!class_exists(GitPostedTelegramNativeForward::class, false)) {
    require_once __DIR__ . '/telegram-native-forward.php';
}

/** Import a complete, authorized source snapshot; fetching never publishes it. */
trait GitPostedTelegramPostLinks
{
    private function handleTelegramPostLinkMessage(int $userId, array $message): bool
    {
        $user = $this->user($userId);
        $stateName = (string) ($user['state'] ?? '');
        // A URL being edited as text, a button or a signature remains literal.
        if (!in_array($stateName, ['', 'await_post_content', 'await_post_link'], true)) return false;
        foreach (['forward_origin', 'forward_from_chat', 'forward_from', 'forward_date', 'forward_sender_name', 'media_group_id', 'photo', 'video', 'animation', 'document', 'audio', 'voice', 'sticker', 'video_note', 'poll'] as $contentKey) {
            if (!empty($message[$contentKey])) return false;
        }
        $text = trim((string) ($message['text'] ?? ''));
        try {
            $parsed = GitPostedPostLink::parse($text);
        } catch (InvalidArgumentException $exception) {
            $this->telegram->sendMessage($userId, $exception->getMessage());
            return true;
        }
        if ($parsed === null) return false;
        if (($parsed['platform'] ?? '') !== 'telegram') {
            $this->telegram->sendMessage($userId, 'Это ссылка на пост МАКС. Импорт в Telegram требует переноса медиа; откройте ссылку в боте GitPosted для МАКС.');
            return true;
        }
        try {
            if (!$this->isKnownActiveUser($user)) throw new InvalidArgumentException('Доступ к GitPosted отключён.');
            if ($this->allowedChannels($userId, 'post') === []) throw new InvalidArgumentException('Сначала добавьте канал и получите право создания постов.');
            $old = $this->stateData($user);
            if ($stateName === 'await_post_link' && (int) ($message['message_id'] ?? 0) > 0 && (int) ($old['request_message_id'] ?? 0) === (int) ($message['message_id'] ?? 0)
                && hash_equals((string) ($old['url'] ?? ''), (string) $parsed['url'])) {
                $this->telegramShowPostLinkImport($userId, $old);
                return true;
            }
            $snapshot = $this->telegramResolvePostLink($parsed, $userId);
            $data = [
                'token' => bin2hex(random_bytes(8)), 'revision' => 0, 'created_at' => time(),
                'url' => (string) $parsed['url'], 'request_message_id' => (int) ($message['message_id'] ?? 0),
                'source' => $snapshot, 'channel_id' => $stateName === 'await_post_content' ? (int) ($old['channel_id'] ?? 0) : 0,
                'manager_category_id' => $stateName === 'await_post_content' ? (int) ($old['manager_category_id'] ?? 0) : 0,
                'category_selected' => $stateName === 'await_post_content' && (int) ($old['manager_category_id'] ?? 0) > 0,
                'post_id' => 0,
            ];
            $this->pdo->beginTransaction();
            if (!$this->lockActiveTelegramActor($userId)) throw new RuntimeException('Пользователь отключён.');
            $fresh = $this->user($userId);
            if ((string) ($fresh['state'] ?? '') !== $stateName || (string) ($fresh['state_data'] ?? '') !== (string) ($user['state_data'] ?? '')) {
                throw new InvalidArgumentException('Пока читался пост, вы открыли другую настройку. Отправьте ссылку ещё раз, когда будете готовы к импорту.');
            }
            $this->setState($userId, 'await_post_link', $data);
            $this->pdo->commit();
            $this->telegramShowPostLinkImport($userId, $data);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->telegram->sendMessage($userId, $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Не удалось прочитать пост. Проверьте ссылку, доступ бота и доступность сервиса импорта. Исходные публикации не изменены.');
        }
        return true;
    }

    private function handleTelegramPostLinkCallback(int $userId, string $data, ?array $target = null): bool
    {
        if (preg_match('/^tpl:d:(\d+):(copy|native)$/D', $data, $match) === 1) {
            try {
                $this->telegramSetImportedPostMode($userId, (int) $match[1], (string) $match[2], $target);
            } catch (Throwable $exception) {
                $this->telegram->sendMessage($userId, $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Не удалось изменить режим. Проверьте права и состояние черновика.');
            }
            return true;
        }
        if (!str_starts_with($data, 'tpl:')) return false;
        if (preg_match('/^tpl:([a-f0-9]{16}):(\d{1,10}):(channel|category|create|cancel)(?::([a-z0-9]+))?$/D', $data, $match) !== 1) return true;
        try {
            $user = $this->user($userId);
            $plan = $this->stateData($user);
            if (!$this->isKnownActiveUser($user) || ($user['state'] ?? '') !== 'await_post_link'
                || !hash_equals((string) ($plan['token'] ?? ''), $match[1])) throw new InvalidArgumentException('Это меню уже устарело. Отправьте ссылку ещё раз.');
            if ((int) ($plan['post_id'] ?? 0) > 0 || (int) ($plan['revision'] ?? -1) !== (int) $match[2]) {
                $this->telegramShowPostLinkImport($userId, $plan, $target);
                return true;
            }
            if ((int) ($plan['created_at'] ?? 0) < time() - 1800) throw new InvalidArgumentException('Снимок ссылки устарел. Отправьте ссылку снова, чтобы проверить источник.');
            $action = (string) $match[3];
            $argument = (string) ($match[4] ?? '');
            if ($action === 'create') {
                $postId = $this->telegramCreateImportedDraft($userId, $plan, $argument);
                $this->showDraftActions($userId, $postId, $argument === 'native' ? 'Создан репост с указанием источника.' : 'Создана редактируемая копия без шапки пересылки.', $target);
                return true;
            }
            if ($action === 'cancel') {
                if ($this->telegramSavePostLinkState($userId, $plan, null)) $this->showMainMenu($userId);
                return true;
            }
            $next = $plan;
            if ($action === 'channel') {
                $channelId = (int) $argument;
                if (!$this->can($userId, $channelId, 'post')) throw new InvalidArgumentException('Нет права создания постов в этом канале.');
                $next['channel_id'] = $channelId;
                $next['manager_category_id'] = 0;
                $next['category_selected'] = false;
            } elseif ($action === 'category') {
                $category = $this->managerCategory($userId, (int) ($plan['channel_id'] ?? 0), (int) $argument, true);
                if ($category === null || !$this->can($userId, (int) $plan['channel_id'], 'post')) throw new InvalidArgumentException('Рекламная компания недоступна.');
                $next['manager_category_id'] = (int) $argument;
                $next['category_selected'] = true;
            }
            $next['revision'] = (int) $plan['revision'] + 1;
            if (!$this->telegramSavePostLinkState($userId, $plan, $next)) $next = $this->stateData($this->user($userId));
            $this->telegramShowPostLinkImport($userId, $next, $target);
        } catch (Throwable $exception) {
            $this->telegram->sendMessage($userId, $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Не удалось создать черновик. Права или настройки изменились; выберите канал и рекламную компанию заново.');
        }
        return true;
    }

    private function telegramPostLinkDraftRows(array $post): array
    {
        if (($post['status'] ?? '') !== 'draft') return [];
        $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
        if (($import['version'] ?? null) !== 1) return [];
        if ($this->isNativeRepost($post)) return [[['text' => '📝 Сделать копией без шапки', 'callback_data' => 'tpl:d:' . (int) $post['id'] . ':copy']]];
        if (empty($import['source']['native_supported'])) return [];
        return [[['text' => '↗️ Сделать репостом с шапкой', 'callback_data' => 'tpl:d:' . (int) $post['id'] . ':native']]];
    }

    private function resolveTelegramRepostSource(array $post): ?array
    {
        $source = $this->repostForwardSource($post);
        if ($source === null) return null;
        $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
        if (($import['version'] ?? null) !== 1 || ($import['mode'] ?? '') !== 'native') return $source;
        $parsed = $this->telegramImportedRepostReference($source, $import);
        if (!str_starts_with((string) $parsed['chat'], '@')) {
            $this->assertTelegramNativeSourceMembership($source['chat_id']);
            return $source;
        }

        // Resolve a public username but always forward its immutable numeric ID.
        // Names can later be renamed or assigned to another channel.
        foreach ([$parsed['chat'], $source['chat_id']] as $chat) {
            try {
                $response = $this->telegram->call('getChat', ['chat_id' => $chat]);
                $resolved = (array) ($response['result'] ?? []);
                if (($resolved['type'] ?? '') !== 'channel'
                    || !hash_equals($source['chat_id'], (string) ($resolved['id'] ?? ''))) continue;
            } catch (PostedTelegramRequestException $exception) {
                if ($exception->httpStatus() === 429 || $exception->retryAfter() !== null) {
                    throw new PostedTelegramRequestException(
                        'Проверка источника репоста временно ограничена Telegram: ' . $exception->getMessage(),
                        false,
                        $exception->httpStatus(),
                        $exception->retryAfter()
                    );
                }
                continue;
            }
            if (!empty($resolved['has_protected_content'])) {
                throw new InvalidArgumentException('В исходном канале запрещена пересылка. Режим репоста сохранён.');
            }
            $this->assertTelegramNativeSourceMembership($source['chat_id']);
            return $source;
        }
        throw new InvalidArgumentException('Telegram не предоставил боту доступ к исходному каналу. Режим репоста сохранён. Проверьте ссылку и доступ бота или выберите копию.');
    }

    private function telegramImportedRepostReference(array $source, array $import): array
    {
        $original = (array) ($import['source'] ?? []);
        $parsed = GitPostedPostLink::parse((string) ($original['url'] ?? ''));
        if ($parsed === null || $parsed['platform'] !== 'telegram'
            || !hash_equals((string) ($original['chat_id'] ?? ''), $source['chat_id'])
            || (array) ($original['message_ids'] ?? []) !== [$source['message_id']]
            || (int) $parsed['message_id'] !== $source['message_id']
            || (!str_starts_with((string) $parsed['chat'], '@') && !hash_equals((string) $parsed['chat'], $source['chat_id']))) {
            throw new InvalidArgumentException('Источник репоста изменился. Отправьте исходную ссылку заново.');
        }
        return $parsed;
    }

    private function forwardTelegramRepost(array $post, string $targetChat, int $actorId, string $purpose, array $extra = []): array
    {
        $batchImport=$this->decodeJson((string)($post['import_json']??''));
        if(array_key_exists('gitposted_multipost_forward',$batchImport)||array_key_exists('gitposted_multipost_forward_fork',$batchImport)){
            return $this->telegramMultipostPreviewForward($post,$targetChat,$actorId,$purpose);
        }
        $source = $this->repostForwardSource($post);
        if ($source === null) throw new InvalidArgumentException('Источник репоста не указан.');
        $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
        if (($import['version'] ?? null) === 1 && ($import['mode'] ?? '') === 'native'
            && GitPostedTelegramNativeForward::available($this->config)) {
            $this->telegramImportedRepostReference($source, $import);
            $randomKey = $purpose === 'publish'
                ? 'publish:' . (int) ($post['id'] ?? 0) . ':' . $source['chat_id'] . ':' . $source['message_id'] . ':' . $targetChat
                : 'preview:' . $purpose . ':' . bin2hex(random_bytes(16));
            // Once the native sender starts, its typed delivery result owns the
            // outcome. A transport failure must never trigger another send path.
            return GitPostedTelegramNativeForward::forward($this->config, $post, $targetChat, $actorId, $randomKey, !empty($extra['disable_notification']), ($extra['protect_content']??false)===true);
        }
        $source = $this->resolveTelegramRepostSource($post);
        return $this->telegram->forwardMessage($targetChat, $source['chat_id'], $source['message_id'], $extra);
    }

    private function assertTelegramNativeSourceMembership(string $chatId): void
    {
        $notice = 'Для репоста добавьте GitPosted в исходный канал. Чтения публичной ссылки недостаточно для пересылки через Telegram Bot API. Режим репоста сохранён; также доступна копия без шапки.';
        try {
            $botId = (int) ($this->telegramBotIdCache ?? 0);
            if ($botId <= 0) {
                $me = $this->telegram->call('getMe');
                $botId = (int) ($me['result']['id'] ?? 0);
                if ($botId <= 0) throw new InvalidArgumentException('Не удалось проверить доступ бота к источнику. Режим репоста сохранён.');
                $this->telegramBotIdCache = $botId;
            }
            $response = $this->telegram->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
            $member = (array) ($response['result'] ?? []);
        } catch (PostedTelegramRequestException $exception) {
            if (in_array($exception->httpStatus(), [400, 403], true) && !$exception->deliveryMayBeUnknown()) {
                throw new InvalidArgumentException($notice, 0, $exception);
            }
            // This is a read-only access probe: an uncertain HTTP response
            // cannot mean that a publication has been delivered.
            throw new PostedTelegramRequestException(
                'Не удалось проверить доступ бота к источнику репоста: ' . $exception->getMessage(),
                false,
                $exception->httpStatus(),
                $exception->retryAfter()
            );
        }
        if ((int) ($member['user']['id'] ?? 0) !== $botId
            || !in_array((string) ($member['status'] ?? ''), ['creator', 'administrator', 'member'], true)) {
            throw new InvalidArgumentException($notice);
        }
    }

    private function telegramShowPostLinkImport(int $userId, array $plan, ?array $target = null): void
    {
        if ((int) ($plan['post_id'] ?? 0) > 0) {
            $this->showDraftActions($userId, (int) $plan['post_id'], 'Черновик по этой ссылке уже создан.', $target);
            return;
        }
        $prefix = 'tpl:' . (string) $plan['token'] . ':' . (int) $plan['revision'] . ':';
        $rows = [];
        $source = (array) ($plan['source'] ?? []);
        $channelId = (int) ($plan['channel_id'] ?? 0);
        if ($channelId <= 0 || !$this->can($userId, $channelId, 'post')) {
            foreach ($this->allowedChannels($userId, 'post') as $channel) $rows[] = [['text' => (string) $channel['title'], 'callback_data' => $prefix . 'channel:' . (int) $channel['id']]];
            $text = 'Пост прочитан. Выберите канал для нового черновика.';
        } else {
            $categories = $this->activeTelegramManagerCategories($userId, $channelId);
            if ($categories !== [] && empty($plan['category_selected'])) {
                foreach ($categories as $category) $rows[] = [['text' => (string) $category['name'], 'callback_data' => $prefix . 'category:' . (int) $category['id']]];
                $text = 'Выберите рекламную компанию для импортируемого поста.';
            } else {
                $count = count((array) ($source['snapshot']['media'] ?? []));
                $text = "ПОСТ ПО ССЫЛКЕ\n\n" . (!empty($source['stored_snapshot']) ? 'Сохранённое содержимое.' : 'Содержимое прочитано полностью.')
                    . ' Медиа: ' . $count . ".\nВыберите режим нового черновика.";
                $rows[] = [['text' => '📝 Копия без шапки — можно редактировать', 'callback_data' => $prefix . 'create:copy']];
                if (!empty($source['native_supported'])) {
                    $rows[] = [['text' => '↗️ Репост с указанием источника', 'callback_data' => $prefix . 'create:native']];
                    $text .= GitPostedTelegramNativeForward::available($this->config)
                        ? "\n\nРепост сохраняет шапку исходного сообщения. Публичные посты можно переслать и из чужого канала."
                        : "\n\nДля репоста через Telegram Bot API бот должен быть добавлен в исходный канал. Если это чужой канал, выберите редактируемую копию.";
                }
                else $text .= "\n\nДля альбома или поста из нескольких сообщений доступна полная редактируемая копия. Репост такого поста целиком пока не поддерживается.";
            }
        }
        $notices = [];
        if (!empty($source['stored_snapshot'])) $notices[] = 'Сохранённая версия из истории GitPosted. Она может отличаться от текущего поста в канале.';
        foreach ((array) ($source['warnings'] ?? []) as $warning) {
            $notices[] = match ($warning) {
                'NON_URL_BUTTONS_OMITTED' => 'Кнопки с действиями исходного бота не перенесены. URL-кнопки сохранены.',
                'NON_HTTP_BUTTONS_OMITTED' => 'Кнопки со ссылками кроме HTTP и HTTPS не перенесены.',
                default => 'Некоторые элементы исходного поста не перенесены. Проверьте копию перед публикацией.',
            };
        }
        if ($notices !== []) $text = implode("\n\n", array_unique($notices)) . "\n\n" . $text;
        $rows[] = [['text' => 'Отменить импорт', 'callback_data' => $prefix . 'cancel']];
        $this->sendOrEditMessage($userId, $text, ['reply_markup' => ['inline_keyboard' => $rows]], $target);
    }

    private function telegramSavePostLinkState(int $userId, array $old, ?array $next): bool
    {
        $stmt = $this->pdo->prepare("UPDATE posted_users SET state=:state,state_data=:data
            WHERE telegram_id=:user AND is_active=1 AND state='await_post_link'
            AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.revision')) AS UNSIGNED)=:revision");
        $stmt->execute(['state' => $next === null ? null : 'await_post_link', 'data' => $next === null ? null : $this->encodeJson($next),
            'user' => $userId, 'token' => (string) $old['token'], 'revision' => (int) $old['revision']]);
        return $stmt->rowCount() === 1;
    }

    private function telegramResolvePostLink(array $parsed, int $userId): array
    {
        // A channel post may have been edited outside GitPosted. A configured
        // read-only loader is authoritative; its errors never fall back to stale
        // cached content or bypass a newly protected/private source.
        if (GitPostedPostLink::telegramLoaderAvailable($this->config) === true) {
            return $this->telegramPostLinkSnapshotFromMessages(GitPostedPostLink::loadTelegram($this->config, $parsed, $userId), $parsed, $userId);
        }
        $chat = (string) ($parsed['chat'] ?? '');
        $messageId = (int) ($parsed['message_id'] ?? 0);
        $channelStmt = $this->pdo->prepare(str_starts_with($chat, '@')
            ? 'SELECT * FROM posted_channels WHERE LOWER(username)=LOWER(:chat) AND is_active=1 LIMIT 1'
            : 'SELECT * FROM posted_channels WHERE chat_id=:chat AND is_active=1 LIMIT 1');
        $channelStmt->execute(['chat' => ltrim($chat, '@')]);
        $channel = $channelStmt->fetch();
        if (is_array($channel) && ($this->can($userId, (int) $channel['id'], 'post') || $this->can($userId, (int) $channel['id'], 'schedule') || $this->can($userId, (int) $channel['id'], 'manage_posts'))) {
            $postId = $this->postIdByChannelMessage((int) $channel['id'], $messageId);
            $post = $postId > 0 ? $this->post($postId) : null;
            if (is_array($post)) {
                $storedOptions=PostedTelegramPostOptions::load($this->pdo,PostedTelegramPostOptions::freeze($this->pdo,$post));
                if($storedOptions['paid_media_stars']!==null)throw new InvalidArgumentException('Импорт платного медиа пока недоступен. Платное содержимое не будет превращено в обычную копию.');
                if($storedOptions['media_spoiler'])throw new InvalidArgumentException('Импорт медиа со спойлером по ссылке пока недоступен. Скрытие не будет потеряно при создании копии.');
                $native = $this->isNativeRepost($post);
                if($native){$sourceSpoiler=PostedTelegramSourceSpoiler::freeze($this->pdo,$post);if($sourceSpoiler!==null&&($sourceSpoiler['state']==='unknown'||$sourceSpoiler['media_spoiler']===true))throw new InvalidArgumentException('Импорт этого сохранённого репоста по ссылке пока недоступен: состояние спойлера нельзя безопасно перенести. Дублируйте пост и выберите «Сделать копией».');}
                $payload = $native ? ['text' => (string) ($post['text'] ?? ''), 'entities' => $this->decodeJson((string) ($post['text_entities_json'] ?? '')), 'buttons' => []] : $this->effectivePostPayload($post);
                $media = $this->postMedia($post);
                if ((($payload['text'] ?? '') !== '' || $media !== []) && $this->telegramStoredLinkSourceComplete($post, $payload, $media, $messageId, (string) $channel['chat_id'])) {
                    $ids = $this->postMessageIds($post);
                    if ($ids === []) $ids = [$messageId];
                    return $this->telegramValidateLinkSnapshot([
                        'platform' => 'telegram', 'url' => (string) $parsed['url'], 'chat_id' => (string) $channel['chat_id'],
                        'message_ids' => $ids, 'native_supported' => count($ids) === 1 && count($media) <= 1,
                        'stored_channel_id' => (int) $channel['id'], 'stored_snapshot' => true, 'warnings' => [],
                        'source_media_spoiler' => false,
                        'forward_origin' => $native ? $this->telegramStoredNativeForwardOrigin($post) : null,
                        'snapshot' => ['text' => (string) $payload['text'], 'entities' => (array) ($payload['entities'] ?? []), 'media' => $media, 'buttons' => (array) ($payload['buttons'] ?? [])],
                    ]);
                }
            }
        }
        // The loader is read-only and must return the full album, verified source
        // access and durable reusable media references. No speculative forward.
        if (!method_exists(GitPostedPostLink::class, 'loadTelegram')) throw new InvalidArgumentException('Сервис чтения Telegram ещё не настроен. Можно переслать боту пост или весь альбом вручную.');
        $loaded = GitPostedPostLink::loadTelegram($this->config, $parsed, $userId);
        return $this->telegramPostLinkSnapshotFromMessages($loaded, $parsed, $userId);
    }

    /** A sent row may still describe a partial or uncertain multi-message send. */
    private function telegramStoredLinkSourceComplete(array $post, array $payload, array $media, int $requestedId, string $chatId): bool
    {
        if (trim((string) ($post['error_text'] ?? '')) !== '') return false;
        $rawSlots = trim((string) ($post['telegram_message_ids'] ?? ''));
        if ($rawSlots !== '') {
            $decoded = json_decode($rawSlots, true);
            if (!is_array($decoded) || !array_is_list($decoded)) return false;
        }
        $slots = $this->decodeMessageSlots((string) ($post['telegram_message_ids'] ?? ''));
        if ($slots !== [] && (in_array(0, $slots, true) || count(array_unique($slots)) !== count($slots))) return false;
        $ids = $this->postMessageIds($post);
        if ($ids === [] && (string) ($post['external_chat_id'] ?? '') === $chatId && (int) ($post['external_message_id'] ?? 0) === $requestedId) $ids = [$requestedId];
        if (!in_array($requestedId, $ids, true)) return false;
        if ($this->isNativeRepost($post)) $expected = 1;
        else {
            $text = (string) ($payload['text'] ?? '');
            $captionFits = $this->telegramUtf16Length($text) <= self::PHOTO_CAPTION_LIMIT;
            $expected = count($media);
            if ($media === [] || (!$captionFits && $text !== '')) $expected += count($this->splitTelegramFormattedText($text, (array) ($payload['entities'] ?? [])));
            elseif (count($media) > 1 && (array) ($payload['buttons'] ?? []) !== []) $expected++;
        }
        if (count($ids) !== $expected) return false;
        $attempt = $this->pdo->prepare('SELECT state FROM posted_telegram_publish_attempts WHERE post_id=:post LIMIT 1');
        $attempt->execute(['post' => (int) $post['id']]);
        $state = $attempt->fetchColumn();
        return $state === false || $state === 'completed';
    }

    private function telegramPostLinkSnapshotFromMessages(array $loaded, array $parsed, int $userId): array
    {
        $access = (array) ($loaded['access'] ?? []);
        if (($loaded['complete'] ?? null) !== true || (($loaded['platform'] ?? '') !== 'telegram')
            || (($access['public'] ?? null) !== true && (($access['actor_verified'] ?? null) !== true || (int) ($access['actor_id'] ?? 0) !== $userId))) {
            throw new InvalidArgumentException('Не подтверждены полнота поста или доступ к его источнику. Закрытый канал должен быть доступен вам и боту.');
        }
        $chatId = (string) ($loaded['source_chat_id'] ?? '');
        $messages = (array) ($loaded['messages'] ?? []);
        $rawIds = (array) ($loaded['source_message_ids'] ?? []);
        foreach ($rawIds as $id) {
            if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9]\d{0,9}$/D', $id) !== 1)) || (int) $id <= 0 || (int) $id > 2147483647) throw new InvalidArgumentException('Некорректные номера элементов альбома.');
        }
        $sourceIds = array_map('intval', $rawIds);
        $orderedIds = $sourceIds;
        sort($orderedIds, SORT_NUMERIC);
        if ($sourceIds !== $orderedIds) throw new InvalidArgumentException('Нарушен порядок элементов альбома.');
        if (preg_match('/^-?\d{1,20}$/D', $chatId) !== 1 || $messages === [] || count($messages) > 10 || count($messages) !== count($sourceIds)
            || count(array_unique($sourceIds)) !== count($sourceIds) || min($sourceIds) <= 0
            || !in_array((int) ($parsed['message_id'] ?? 0), $sourceIds, true)) throw new InvalidArgumentException('Не удалось получить весь пост или альбом. Неполная копия не создана.');
        if (!str_starts_with((string) ($parsed['chat'] ?? ''), '@') && !hash_equals((string) $parsed['chat'], $chatId)) throw new InvalidArgumentException('Источник ответа не совпадает со ссылкой.');
        $media = [];
        $text = '';
        $entities = [];
        $group = null;
        $buttons = [];
        foreach (array_values($messages) as $index => $message) {
            if (!is_array($message) || (int) ($message['message_id'] ?? 0) !== $sourceIds[$index]
                || !hash_equals($chatId, (string) ($message['chat']['id'] ?? ''))) throw new InvalidArgumentException('Нарушен порядок или источник элементов альбома.');
            if (!empty($message['has_protected_content'])) throw new InvalidArgumentException('Источник запрещает сохранение и пересылку содержимого. Импорт недоступен.');
            if((isset($message['photo'])||isset($message['video'])||isset($message['animation']))&&(!array_key_exists('has_media_spoiler',$message)||!is_bool($message['has_media_spoiler'])))throw new InvalidArgumentException('Loader не подтвердил состояние спойлера исходного медиа. Неполная копия не создана.');
            if (!empty($message['has_media_spoiler'])) throw new InvalidArgumentException('В посте есть медиа со спойлером. Этот редактор пока не сохраняет скрытие медиа; импорт без потери оформления недоступен.');
            foreach (['poll', 'sticker', 'video_note', 'contact', 'location', 'venue', 'dice', 'paid_media'] as $unsupported) {
                if (isset($message[$unsupported])) throw new InvalidArgumentException('В посте есть неподдерживаемое содержимое (' . $unsupported . '). Неполная копия не создана.');
            }
            $part = $this->contentFromMessage($message);
            if ($part['text'] === '' && $part['media'] === []) throw new InvalidArgumentException('Не удалось прочитать содержимое одного из сообщений.');
            if ($part['text'] !== '') {
                if ($text !== '') throw new InvalidArgumentException('В альбоме несколько отдельных подписей. Этот редактор хранит одну общую подпись; импорт без потерь пока недоступен.');
                $text = (string) $part['text'];
                $entities = (array) $part['text_entities'];
            }
            if (count($messages) > 1) {
                $partGroup = (string) ($message['media_group_id'] ?? '');
                if ($partGroup === '' || ($group !== null && !hash_equals($group, $partGroup)) || $part['media'] === []) throw new InvalidArgumentException('Не подтверждено, что все сообщения принадлежат одному полному альбому.');
                $group = $partGroup;
            }
            $media = array_merge($media, (array) $part['media']);
            foreach ((array) ($message['reply_markup']['inline_keyboard'] ?? []) as $row) $buttons[] = $row;
        }
        return $this->telegramValidateLinkSnapshot([
            'platform' => 'telegram', 'url' => (string) $parsed['url'], 'chat_id' => $chatId, 'message_ids' => $sourceIds,
            'native_supported' => count($sourceIds) === 1 && count($media) <= 1, 'stored_channel_id' => 0, 'stored_snapshot' => false,
            'source_media_spoiler' => false,
            'forward_origin' => count($messages) === 1 ? ($messages[0]['forward_origin'] ?? null) : null,
            'warnings' => array_values(array_unique(array_filter((array) ($loaded['warnings'] ?? []), static fn(mixed $warning): bool => is_string($warning) && preg_match('/^[A-Z0-9_]{1,80}$/D', $warning) === 1))),
            'snapshot' => compact('text', 'entities', 'media', 'buttons'),
        ]);
    }

    private function telegramValidateLinkSnapshot(array $source): array
    {
        if(array_key_exists('source_media_spoiler',$source)&&$source['source_media_spoiler']!==false)throw new InvalidArgumentException('Не подтверждено отсутствие спойлера в источнике.');
        $source['forward_origin'] = $this->telegramChannelForwardOrigin($source['forward_origin'] ?? null);
        $snapshot = (array) ($source['snapshot'] ?? []);
        $text = (string) ($snapshot['text'] ?? '');
        $media = (array) ($snapshot['media'] ?? []);
        if (($text === '' && $media === []) || count($media) > 10) throw new InvalidArgumentException('Пост пуст или содержит неподдерживаемый альбом.');
        foreach ($media as $item) {
            if (!is_array($item) || !in_array($item['type'] ?? '', ['photo', 'video', 'animation', 'document', 'audio', 'voice'], true)
                || !is_string($item['file_id'] ?? null)
                || preg_match('/^(?:[A-Za-z0-9_-]{1,2048}|post-link-asset:[a-f0-9]{64})$/D', $item['file_id']) !== 1) throw new InvalidArgumentException('Не удалось получить пригодное для публикации медиа.');
        }
        if (!$this->isSupportedTelegramMediaGroup($media)) throw new InvalidArgumentException('Пост содержит неподдерживаемое сочетание медиа в альбоме.');
        $buttons = [];
        foreach ((array) ($snapshot['buttons'] ?? []) as $row) {
            $safe = [];
            foreach (is_array($row) ? $row : [] as $button) {
                if (!is_array($button) || !is_string($button['url'] ?? null) || !filter_var($button['url'], FILTER_VALIDATE_URL)
                    || preg_match('~^https?://~i', $button['url']) !== 1 || trim((string) ($button['text'] ?? '')) === '') continue;
                $entry = ['text' => mb_substr((string) $button['text'], 0, 128), 'url' => $button['url']];
                if (in_array($button['style'] ?? '', ['primary', 'success', 'danger'], true)) $entry['style'] = $button['style'];
                $safe[] = $entry;
            }
            if ($safe !== []) $buttons[] = $safe;
        }
        $source['snapshot'] = ['text' => $text, 'entities' => $this->normalizeTelegramEntities($text, (array) ($snapshot['entities'] ?? [])), 'media' => $media, 'buttons' => $buttons];
        return $source;
    }

    private function telegramChannelForwardOrigin(mixed $origin): ?array
    {
        if ($origin === null) return null;
        if (!is_array($origin) || ($origin['type'] ?? '') !== 'channel') {
            throw new InvalidArgumentException('Не удалось подтвердить исходный канал репоста.');
        }
        $chat = (string) (($origin['chat'] ?? [])['id'] ?? '');
        $message = $origin['message_id'] ?? null;
        if (preg_match('/^-[1-9][0-9]{0,19}$/D', $chat) !== 1
            || (!is_int($message) && (!is_string($message) || preg_match('/^[1-9][0-9]{0,9}$/D', $message) !== 1))
            || (int) $message <= 0 || (int) $message > 2147483647) {
            throw new InvalidArgumentException('Некорректный источник исходной пересылки.');
        }
        return ['type' => 'channel', 'chat' => ['id' => $chat], 'message_id' => (int) $message];
    }

    private function telegramStoredNativeForwardOrigin(array $post): array
    {
        $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
        $origin = $this->telegramChannelForwardOrigin($import['source']['forward_origin'] ?? null);
        return $origin ?? ['type' => 'channel', 'chat' => ['id' => (string) $post['repost_chat_id']], 'message_id' => (int) $post['repost_message_id']];
    }

    private function telegramCreateImportedDraft(int $userId, array $plan, string $mode): int
    {
        if (!in_array($mode, ['copy', 'native'], true)) throw new InvalidArgumentException('Выберите режим копии или репоста.');
        $source = $this->telegramValidateLinkSnapshot((array) ($plan['source'] ?? []));
        if ($mode === 'native' && empty($source['native_supported'])) throw new InvalidArgumentException('Репост целого альбома пока недоступен. Выберите полную копию.');
        $channelId = (int) ($plan['channel_id'] ?? 0);
        return $this->withTelegramChannelCapabilityLock($userId, $channelId, 'post', function (array $channel, array $permission) use ($userId, $plan, $source, $mode, $channelId): int {
            $currentUser = $this->user($userId);
            $current = $this->stateData($currentUser);
            if (($currentUser['state'] ?? '') !== 'await_post_link' || !hash_equals((string) ($current['token'] ?? ''), (string) ($plan['token'] ?? ''))
                || (int) ($current['revision'] ?? -1) !== (int) $plan['revision']) throw new InvalidArgumentException('Настройки импорта изменились. Откройте последнее меню.');
            if ((int) ($current['post_id'] ?? 0) > 0) return (int) $current['post_id'];
            $sourceChannelId = (int) ($source['stored_channel_id'] ?? 0);
            if ($sourceChannelId > 0 && !$this->can($userId, $sourceChannelId, 'post') && !$this->can($userId, $sourceChannelId, 'schedule') && !$this->can($userId, $sourceChannelId, 'manage_posts')) throw new InvalidArgumentException('Доступ к исходному каналу изменился. Импорт отменён.');
            $categoryId = $this->resolveTelegramManagerCategoryLocked($userId, $channelId, (int) ($plan['manager_category_id'] ?? 0), $permission);
            $snapshot = $source['snapshot'];
            $media = $snapshot['media'];
            $first = $media[0] ?? ['type' => 'none', 'file_id' => null];
            $import = ['version' => 1, 'token' => $plan['token'], 'source' => $source, 'mode' => $mode];
            $stmt = $this->pdo->prepare("INSERT INTO posted_posts
                (channel_id,author_id,manager_category_id,text,text_entities_json,media_type,media_file_id,media_json,buttons_json,status,
                 silent,pin_after_publish,comments_enabled,is_ad,link_preview_enabled,link_preview_large,link_preview_above,
                 repost_chat_id,repost_message_id,repost_input_chat_id,repost_input_message_id,import_json)
                VALUES(:channel,:author,:category,:text,:entities,:media_type,:media_file,:media,:buttons,'draft',
                 :silent,:pin,:comments,0,:preview,:large,:above,:source_chat,:source_message,:input_chat,:input_message,:import)");
            $native = $mode === 'native';
            $stmt->execute([
                'channel' => $channelId, 'author' => $userId, 'category' => $categoryId,
                'text' => $snapshot['text'] !== '' ? $snapshot['text'] : null, 'entities' => $snapshot['entities'] !== [] ? $this->encodeJson($snapshot['entities']) : null,
                'media_type' => $first['type'], 'media_file' => $first['file_id'], 'media' => $this->encodeJson($media), 'buttons' => $snapshot['buttons'] !== [] ? $this->encodeJson($snapshot['buttons']) : null,
                'silent' => (int) ($channel['post_silent'] ?? 0), 'pin' => (int) ($channel['post_pin'] ?? 0), 'comments' => (int) ($channel['post_comments'] ?? 1),
                'preview' => $channel['post_preview'] ?? null, 'large' => $channel['post_preview_large'] ?? null, 'above' => $channel['post_preview_above'] ?? null,
                'source_chat' => $native ? $source['chat_id'] : null, 'source_message' => $native ? $source['message_ids'][0] : null,
                'input_chat' => $native ? $source['chat_id'] : null, 'input_message' => $native ? $source['message_ids'][0] : null, 'import' => $this->encodeJson($import),
            ]);
            $postId = (int) $this->pdo->lastInsertId();
            if($native&&($source['source_media_spoiler']??null)===false&&$media!==[]&&array_diff(array_column($media,'type'),['photo','video','animation'])===[])PostedTelegramSourceSpoiler::registerVerifiedLinkNativeLocked($this->pdo,$this->post($postId));
            $next = $current;
            $next['post_id'] = $postId;
            $next['revision'] = (int) $current['revision'] + 1;
            if ($postId <= 0 || !$this->telegramSavePostLinkState($userId, $current, $next)) throw new RuntimeException('Не удалось зафиксировать импорт.');
            return $postId;
        });
    }

    private function telegramSetImportedPostMode(int $userId, int $postId, string $mode, ?array $target): void
    {
        if (!in_array($mode, ['copy', 'native'], true)) throw new InvalidArgumentException('Выберите режим копии или репоста.');
        $updated = $this->withTelegramManagedPostLock($userId, $postId, ['draft'], function (array $post) use ($userId, $postId, $mode): bool {
            $import = $this->decodeJson((string) ($post['import_json'] ?? ''));
            if (($import['version'] ?? null) !== 1) throw new InvalidArgumentException('Этот черновик не был импортирован по ссылке.');
            $source = (array) ($import['source'] ?? []);
            if ($mode === 'native' && empty($source['native_supported'])) throw new InvalidArgumentException('Для этого поста доступна только полная копия.');
            if ($mode === 'copy' && !$this->hasStoredRepostCopy($post)) throw new InvalidArgumentException('В черновике не осталось содержимого для копии.');
            $import['mode'] = $mode;
            $native = $mode === 'native';
            $options=PostedTelegramPostOptions::load($this->pdo,$post);
            if($native){
                if($options['media_spoiler']||$options['paid_media_stars']!==null)throw new InvalidArgumentException('Для этих настроек медиа сохраните режим копии. Перед переключением отключите спойлер или платный пост.');
                PostedTelegramSourceSpoiler::transitionToNativeLocked($this->pdo,$post,(string)$source['chat_id'],(int)$source['message_ids'][0]);
            }elseif(PostedTelegramSourceSpoiler::transitionToCopyLocked($this->pdo,$post)){
                if($options['paid_media_stars']!==null)throw new InvalidArgumentException('Спойлер источника нельзя совмещать с платным постом.');
                PostedTelegramPostOptions::saveLocked($this->pdo,$post,['media_spoiler'=>true]);
            }
            $this->pdo->prepare('UPDATE posted_posts SET repost_chat_id=:chat,repost_message_id=:message,repost_input_chat_id=:input_chat,repost_input_message_id=:input_message,import_json=:import WHERE id=:post AND status=\'draft\'')
                ->execute(['chat' => $native ? $source['chat_id'] : null, 'message' => $native ? $source['message_ids'][0] : null,
                    'input_chat' => $native ? $source['chat_id'] : null, 'input_message' => $native ? $source['message_ids'][0] : null,
                    'import' => $this->encodeJson($import), 'post' => $postId]);
            if ($native) {
                $user = $this->user($userId);
                if ($this->isTelegramRepostCopyInput((string) ($user['state'] ?? ''))
                    && (int) ($this->stateData($user)['post_id'] ?? 0) === $postId) {
                    $this->clearState($userId);
                }
            }
            return true;
        }, false, false, true);
        if ($updated !== true) throw new InvalidArgumentException('Черновик недоступен или права изменились.');
        $this->showDraftActions($userId, $postId, $mode === 'native' ? 'Режим: репост с указанием источника. Изменённая копия сохранена для обратного переключения.' : 'Режим: редактируемая копия без шапки пересылки.', $target);
    }

    private function isTelegramRepostCopyInput(string $state): bool
    {
        return in_array($state, [
            'collecting_media_group', 'await_draft_text', 'await_draft_media',
            'await_reply_post', 'await_reactions', 'await_hidden_label',
            'await_hidden_text', 'await_replace_links', 'await_buttons',
        ], true);
    }
}
