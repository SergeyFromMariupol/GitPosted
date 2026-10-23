<?php

declare(strict_types=1);

/** Import a MAX post, choosing a target when necessary. No publication occurs here. */
trait GitPostedMaxPostLinks
{
    /** Main hook, after commands and before ordinary state-specific content handlers. */
    private function handleMaxPostLinkMessage(string $chatId, string $userId, string $text, array $message): bool
    {
        $saved = $this->maxPostLinkSavedEffect();
        if (($saved['type'] ?? '') === 'post_link_import') {
            return $this->handleMaxPostLinkInput($chatId, $userId, ['platform' => 'max'], $message);
        }
        if (($saved['type'] ?? '') === 'post_link_open') {
            if (($saved['result']['user_id'] ?? '') !== $userId) throw new RuntimeException('Сохранённое действие относится к другому пользователю.');
            $current = $this->user($userId);
            $pending = $this->decodeJson((string) ($current['state_data'] ?? ''));
            if (($current['state'] ?? '') === 'await_max_post_link' && ($pending['token'] ?? '') === ($saved['result']['token'] ?? '')) {
                [$prompt, $rows] = $this->maxPostLinkChannelPicker($userId, (string) $pending['token']);
                $this->api->sendMessage($chatId, $prompt, [], $rows);
            }
            return true;
        }
        $state = (string) ($this->user($userId)['state'] ?? '');
        if (!in_array($state, ['', 'await_content', 'await_max_post_link'], true)) return false;
        try { $parsed = GitPostedPostLink::parse($text); }
        catch (InvalidArgumentException $error) {
            $this->api->sendMessage($chatId, $error->getMessage());
            return true;
        }
        if ($parsed === null) return false;
        if ($this->maxPostLinkHasInputMedia($message) || $this->forwardedMessageId($message) !== null) return false;
        if (($parsed['platform'] ?? '') !== 'max') {
            $this->api->sendMessage($chatId, 'Ссылку на пост Telegram отправьте в Telegram-бот GitPosted. Здесь поддерживаются ссылки на посты МАКС.');
            return true;
        }
        if ($state === 'await_content') return $this->handleMaxPostLinkInput($chatId, $userId, $parsed, $message);
        $previous = $this->user($userId);
        $previousData = $this->decodeJson((string) ($previous['state_data'] ?? ''));
        $token = bin2hex(random_bytes(16));
        $this->completeMaxUpdateEffect('post_link_open', function () use ($userId, $parsed, $token, $previous, $previousData): array {
            $this->assertActiveMaxActorLocked($userId);
            $current = $this->user($userId);
            $data = $this->decodeJson((string) ($current['state_data'] ?? ''));
            if ((string) ($current['state'] ?? '') !== (string) ($previous['state'] ?? '')
                || (($current['state'] ?? '') === 'await_max_post_link' && ($data['token'] ?? '') !== ($previousData['token'] ?? ''))) {
                throw new RuntimeException('Настройка поста уже изменилась. Отправьте ссылку снова.');
            }
            $this->setState($userId, 'await_max_post_link', ['parsed' => $parsed, 'token' => $token]);
            return ['user_id' => $userId, 'token' => $token];
        });
        [$prompt, $rows] = $this->maxPostLinkChannelPicker($userId, $token);
        $this->api->sendMessage($chatId, $prompt, [], $rows);
        return true;
    }

    private function maxPostLinkSavedEffect(): ?array
    {
        if ($this->processingUpdateKey === null || $this->processingUpdateKey === '') return null;
        $stmt = $this->pdo->prepare('SELECT effect_type,result_json FROM posted_max_update_effects WHERE update_key=:key LIMIT 1');
        $stmt->execute(['key' => $this->processingUpdateKey]);
        $effect = $stmt->fetch();
        return is_array($effect) ? ['type' => (string) $effect['effect_type'], 'result' => $this->decodeJson((string) $effect['result_json'])] : null;
    }

    private function maxPostLinkHasInputMedia(array $message): bool
    {
        foreach ((array) ($message['body']['attachments'] ?? $message['attachments'] ?? []) as $attachment) {
            // MAX automatically attaches a share preview to a typed URL.
            if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'share') return true;
        }
        return false;
    }

    private function maxPostLinkChannelPicker(string $userId, string $token, int $page = 0): array
    {
        $eligible = [];
        foreach ($this->channelsForUser($userId) as $channel) {
            if (empty($channel['can_post']) || !$this->channelCapability($userId, (int) $channel['id'], 'post')) continue;
            $eligible[] = $channel;
        }
        $page = min(max(0, $page), max(0, (int) ceil(count($eligible) / 20) - 1));
        $rows = [];
        foreach (array_slice($eligible, $page * 20, 20) as $channel) {
            $rows[] = [GitPostedMaxApi::callbackButton(mb_substr((string) $channel['title'], 0, 100),
                'gp:linkchannel:' . $token . ':' . (int) $channel['id'])];
        }
        $navigation = [];
        if ($page > 0) $navigation[] = GitPostedMaxApi::callbackButton('←', 'gp:linkpage:' . $token . ':' . ($page - 1));
        if (count($eligible) > ($page + 1) * 20) $navigation[] = GitPostedMaxApi::callbackButton('→', 'gp:linkpage:' . $token . ':' . ($page + 1));
        if ($navigation !== []) $rows[] = $navigation;
        $prompt = $rows === [] ? 'Нет доступного канала для публикации. Подключите канал или запросите право публикации, затем отправьте ссылку снова.'
            : 'Пост по ссылке сохранён. Выберите канал для новой копии:';
        $rows[] = [GitPostedMaxApi::callbackButton('Отмена', 'gp:menu')];
        return [$prompt, $rows];
    }

    private function maxPostLinkData(array $post): ?array
    {
        $source = $this->maxDecodeOptions($post)['post_link_source'] ?? null;
        if ($source === null) return null;
        if (!is_array($source) || ($source['version'] ?? null) !== 1 || ($source['platform'] ?? '') !== 'max'
            || !is_string($source['message_id'] ?? null) || !is_string($source['chat_id'] ?? null)
            || !is_string($source['url'] ?? null) || !is_array($source['original'] ?? null)
            || !is_array($source['copy'] ?? null) || !is_array($source['warnings'] ?? null)) {
            throw new RuntimeException('Источник поста по ссылке повреждён. Создайте новый черновик.');
        }
        return $source;
    }

    private function maxPostLinkRows(array $post): array
    {
        if (($post['status'] ?? '') !== 'draft' || $this->maxPostLinkData($post) === null) return [];
        $native = trim((string) ($post['forward_message_id'] ?? '')) !== '';
        return [[GitPostedMaxApi::callbackButton($native ? '🔁 Репост → сделать копией' : '📄 Копия → сделать репостом',
            'gp:linkmode:' . (int) $post['id'] . ':' . ($native ? 'copy' : 'native'))]];
    }

    /** Private control-card text only; source metadata never becomes outbound content. */
    private function maxPostLinkNotice(array $post): string
    {
        $source = $this->maxPostLinkData($post);
        if ($source === null) return '';
        $text = trim((string) ($post['forward_message_id'] ?? '')) !== ''
            ? 'По ссылке: репост исходного поста. Правки копии сохранены отдельно.'
            : 'По ссылке: редактируемая копия. Можно переключить на репост.';
        if ($source['warnings'] !== []) $text .= "\nКнопки исходного бота, кроме ссылок, не перенесены: они работают только у исходного бота.";
        return $text;
    }

    private function handleMaxPostLinkInput(string $chatId, string $userId, array $parsed, array $message = []): bool
    {
        // A queue replay must not restore an older source after the user edited its copy.
        $effect = $this->currentMaxUpdateEffect('post_link_import');
        if ($effect !== null) {
            if ((string) ($effect['user_id'] ?? '') !== $userId) throw new RuntimeException('Сохранённое действие относится к другому пользователю.');
            $this->showDraft($chatId, $userId, (int) ($effect['post_id'] ?? 0));
            return true;
        }
        $account = $this->user($userId);
        if (($account['state'] ?? '') !== 'await_content') return false;
        if (($parsed['platform'] ?? '') !== 'max') {
            $this->api->sendMessage($chatId, 'Ссылку на пост Telegram отправьте в Telegram-бот GitPosted. Здесь поддерживаются ссылки на посты МАКС.');
            return true;
        }
        $state = $this->decodeJson((string) ($account['state_data'] ?? ''));
        $postId = (int) ($state['post_id'] ?? 0);
        try {
            if ($this->maxPostLinkHasInputMedia($message) || $this->forwardedMessageId($message) !== null) {
                throw new InvalidArgumentException('Отправьте одну ссылку на пост МАКС отдельным сообщением, без медиа и пересылки.');
            }
            $post = $this->ownedPost($userId, $postId, 'post');
            if (!is_array($post) || ($post['status'] ?? '') !== 'draft'
                || (string) ($post['author_max_user_id'] ?? '') !== $userId) {
                throw new InvalidArgumentException('Черновик недоступен. Создайте новый пост и отправьте ссылку снова.');
            }
            try { $reference = GitPostedPostLink::maxReference($parsed); }
            catch (Throwable $error) { throw new InvalidArgumentException('Не удалось открыть ссылку на пост МАКС. Проверьте ссылку и доступность канала; черновик сохранён.', 0, $error); }
            $sourceMessage = $this->maxReadPostLinkSource($reference, $userId);
            $snapshot = $this->maxPostLinkSnapshot($sourceMessage);
            $source = ['version' => 1, 'platform' => 'max', 'url' => $reference['url'],
                'chat_id' => $reference['chat_id'], 'message_id' => $reference['message_id'],
                'original' => $snapshot['content'], 'copy' => $snapshot['content'], 'warnings' => $snapshot['warnings']];
            $effect = $this->withManagedMaxPostLock($userId, $postId, 'post', ['draft'],
                function (array $lockedPost) use ($postId, $userId, $reference, $source, $snapshot): array {
                    return $this->completeMaxUpdateEffectLocked('post_link_import', function () use ($lockedPost, $postId, $userId, $reference, $source, $snapshot): array {
                        $current = $this->user($userId); // actor row is locked by the host wrapper
                        $state = $this->decodeJson((string) ($current['state_data'] ?? ''));
                        if (($current['state'] ?? '') !== 'await_content' || (int) ($state['post_id'] ?? 0) !== $postId
                            || (string) ($lockedPost['author_max_user_id'] ?? '') !== $userId
                            || (string) ($lockedPost['text'] ?? '') !== ''
                            || $this->decodeJson((string) ($lockedPost['attachments_json'] ?? '')) !== []
                            || trim((string) ($lockedPost['forward_message_id'] ?? '')) !== '') {
                            throw new InvalidArgumentException('Состояние черновика изменилось. Откройте его снова; содержимое сохранено.');
                        }
                        $this->maxAssertPostLinkAccess($reference['chat_id'], $userId, $reference['public'], true);
                        $options = $this->maxDecodeOptions($lockedPost);
                        $options['post_link_source'] = $source;
                        $buttons = $snapshot['has_keyboard'] ? $snapshot['buttons'] : $this->decodeJson((string) ($lockedPost['buttons_json'] ?? ''));
                        $candidate = array_replace($lockedPost, ['text' => $source['copy']['text'], 'text_html' => $source['copy']['html'],
                            'attachments_json' => $this->encodeJson($source['copy']['attachments']), 'buttons_json' => $this->encodeJson($buttons),
                            'forward_message_id' => null, 'options_json' => $this->encodeJson($options)]);
                        try { $effective = $this->maxEffectivePostContent($candidate); }
                        catch (RuntimeException $error) { throw new InvalidArgumentException($error->getMessage(), 0, $error); }
                        GitPostedMaxApi::validateMediaAttachments($source['copy']['attachments'], $effective['buttons']);
                        $this->pdo->prepare('UPDATE posted_max_posts SET text=:text,text_html=:html,attachments_json=:attachments,
                            buttons_json=:buttons,forward_message_id=NULL,options_json=:options WHERE id=:id')->execute([
                                'text' => $candidate['text'], 'html' => $candidate['text_html'], 'attachments' => $candidate['attachments_json'],
                                'buttons' => $candidate['buttons_json'], 'options' => $candidate['options_json'], 'id' => $postId]);
                        $consume = $this->pdo->prepare("UPDATE posted_max_users SET state=NULL,state_data=NULL WHERE max_user_id=:user
                            AND state='await_content' AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.post_id'))=:post");
                        $consume->execute(['user' => $userId, 'post' => (string) $postId]);
                        if ($consume->rowCount() !== 1) throw new RuntimeException('Состояние черновика изменилось.');
                        return ['post_id' => $postId, 'user_id' => $userId];
                    });
                });
        } catch (InvalidArgumentException $error) {
            $this->api->sendMessage($chatId, $error->getMessage());
            return true;
        }
        $this->showDraft($chatId, $userId, (int) $effect['post_id']);
        return true;
    }

    private function handleMaxPostLinkCallback(string $callbackId, string $userId, string $data): bool
    {
        if (preg_match('/\Agp:linkpage:([a-f0-9]{32}):([0-9]{1,6})\z/D', $data, $page) === 1) {
            $user = $this->user($userId);
            $pending = $this->decodeJson((string) ($user['state_data'] ?? ''));
            if (($user['state'] ?? '') !== 'await_max_post_link' || ($pending['token'] ?? '') !== $page[1]) throw new RuntimeException('Выбор канала устарел.');
            [$prompt, $rows] = $this->maxPostLinkChannelPicker($userId, $page[1], (int) $page[2]);
            $this->api->answerCallback($callbackId, $prompt, $rows);
            return true;
        }
        if (preg_match('/\Agp:linkcatpage:([a-f0-9]{32}):([1-9][0-9]*):([0-9]{1,6})\z/D', $data, $page) === 1) {
            $this->maxAnswerPostLinkCategories($callbackId, $userId, (int) $page[2], $page[1], (int) $page[3]);
            return true;
        }
        if (preg_match('/\Agp:link(channel|category):([a-f0-9]{32}):([1-9][0-9]*)(?::([1-9][0-9]*))?\z/D', $data, $pick) === 1) {
            if (($pick[1] === 'channel') !== !isset($pick[4])) throw new RuntimeException('Некорректный выбор канала или рекламной компании.');
            $this->maxChoosePostLinkTarget($callbackId, $userId, $pick[2], (int) $pick[3], isset($pick[4]) ? (int) $pick[4] : null);
            return true;
        }
        if (preg_match('/\Agp:linkmode:([1-9][0-9]*):(copy|native)\z/D', $data, $match) !== 1) return false;
        $postId = (int) $match[1];
        $mode = $match[2];
        $existing = $this->currentMaxUpdateEffect('post_link_mode');
        if ($existing !== null) {
            if ((int) ($existing['post_id'] ?? 0) !== $postId || ($existing['mode'] ?? '') !== $mode
                || ($existing['user_id'] ?? '') !== $userId) throw new RuntimeException('Сохранённое действие относится к другому посту.');
            $this->answerDraft($callbackId, $userId, $postId);
            return true;
        }
        $post = $this->ownedPost($userId, $postId, 'post');
        if (!is_array($post) || ($post['status'] ?? '') !== 'draft') throw new RuntimeException('Черновик недоступен.');
        $source = $this->maxPostLinkData($post);
        if ($source === null) throw new RuntimeException('У этого черновика нет исходного поста по ссылке.');
        $reference = ['chat_id' => $source['chat_id'], 'message_id' => $source['message_id'], 'url' => $source['url']];
        // The editable copy is already stored locally. Recovering it must not
        // depend on the original post still existing or being reachable.
        if ($mode === 'native') $this->maxReadPostLinkSource($reference, $userId);
        $this->withManagedMaxPostLock($userId, $postId, 'post', ['draft'],
            function (array $lockedPost) use ($postId, $userId, $mode, $reference): array {
                return $this->completeMaxUpdateEffectLocked('post_link_mode', function () use ($lockedPost, $postId, $userId, $mode, $reference): array {
                    $source = $this->maxPostLinkData($lockedPost);
                    if ($source === null || $source['message_id'] !== $reference['message_id'] || $source['chat_id'] !== $reference['chat_id']) {
                        throw new RuntimeException('Источник черновика изменился. Откройте пост снова.');
                    }
                    if ($mode === 'native') $this->maxAssertPostLinkAccess($reference['chat_id'], $userId, $reference['public'], true);
                    $native = trim((string) ($lockedPost['forward_message_id'] ?? '')) !== '';
                    if (($mode === 'native') !== $native) {
                        $options = $this->maxDecodeOptions($lockedPost);
                        if ($mode === 'native') {
                            if (trim((string) ($options['reply_mid'] ?? '')) !== '') throw new RuntimeException('Уберите ответ на пост перед переключением на репост.');
                            $source['copy'] = ['text' => (string) ($lockedPost['text'] ?? ''), 'html' => $lockedPost['text_html'] ?? null,
                                'attachments' => $this->decodeJson((string) ($lockedPost['attachments_json'] ?? ''))];
                        }
                        $content = $mode === 'native' ? $source['original'] : $source['copy'];
                        $this->maxValidatePostLinkContent($content);
                        $options['post_link_source'] = $source;
                        $candidate = array_replace($lockedPost, ['text' => $content['text'], 'text_html' => $content['html'],
                            'attachments_json' => $this->encodeJson($content['attachments']),
                            'forward_message_id' => $mode === 'native' ? $source['message_id'] : null, 'options_json' => $this->encodeJson($options)]);
                        $effective = $this->maxEffectivePostContent($candidate);
                        GitPostedMaxApi::validateMediaAttachments($mode === 'native' ? [] : $content['attachments'], $effective['buttons']);
                        $this->pdo->prepare('UPDATE posted_max_posts SET text=:text,text_html=:html,attachments_json=:attachments,
                            forward_message_id=:forward,options_json=:options WHERE id=:id')->execute([
                                'text' => $candidate['text'], 'html' => $candidate['text_html'], 'attachments' => $candidate['attachments_json'],
                                'forward' => $candidate['forward_message_id'], 'options' => $candidate['options_json'], 'id' => $postId]);
                    }
                    $account = $this->user($userId);
                    $pending = $this->decodeJson((string) ($account['state_data'] ?? ''));
                    if (in_array((string) ($account['state'] ?? ''), ['await_edit_text', 'await_media_update'], true)
                        && (int) ($pending['post_id'] ?? 0) === $postId) {
                        $this->setState($userId, null, []);
                    }
                    return ['post_id' => $postId, 'user_id' => $userId, 'mode' => $mode];
                });
            });
        $this->answerDraft($callbackId, $userId, $postId);
        return true;
    }

    private function maxChoosePostLinkTarget(string $callbackId, string $userId, string $token, int $channelId, ?int $categoryId): void
    {
        $saved = $this->maxPostLinkSavedEffect();
        if ($saved !== null) {
            $effect = $saved['result'];
            if (!in_array($saved['type'], ['post_link_create', 'post_link_category'], true)
                || ($effect['user_id'] ?? '') !== $userId || ($effect['input_token'] ?? '') !== $token
                || (int) ($effect['channel_id'] ?? 0) !== $channelId || (int) ($effect['category_id'] ?? 0) !== (int) $categoryId) {
                throw new RuntimeException('Сохранённое действие относится к другому выбору канала.');
            }
            if ($saved['type'] === 'post_link_create') $this->answerDraft($callbackId, $userId, (int) $effect['post_id']);
            else $this->maxAnswerPostLinkCategories($callbackId, $userId, $channelId, (string) $effect['token']);
            return;
        }
        $current = $this->user($userId);
        $pending = $this->decodeJson((string) ($current['state_data'] ?? ''));
        if (($current['state'] ?? '') !== 'await_max_post_link' || !hash_equals((string) ($pending['token'] ?? ''), $token)
            || !is_array($pending['parsed'] ?? null) || ($categoryId !== null && (int) ($pending['channel_id'] ?? 0) !== $channelId)) {
            throw new RuntimeException('Выбор канала устарел. Отправьте ссылку снова.');
        }
        // Select a campaign before fetching source content. Both transitions consume their token under the same target lock.
        if ($categoryId === null) {
            $effect = $this->withMaxChannelCapabilityLock($userId, $channelId, 'post', function (array $channel, array $permission) use ($userId, $pending, $token, $channelId): ?array {
                if (!empty($channel['is_archived'])) throw new RuntimeException('Канал отключён. Выберите другой канал.');
                if (empty($permission['is_ad_manager'])) return null;
                $stmt = $this->pdo->prepare('SELECT id FROM posted_max_manager_categories WHERE channel_id=:channel AND max_user_id=:user AND is_active=1 ORDER BY id LIMIT 1 FOR UPDATE');
                $stmt->execute(['channel' => $channelId, 'user' => $userId]);
                if ($stmt->fetchColumn() === false) return null;
                return $this->completeMaxUpdateEffectLocked('post_link_category', function () use ($pending, $token, $channelId, $userId): array {
                    $next = $pending;
                    $next['token'] = bin2hex(random_bytes(16));
                    $next['channel_id'] = $channelId;
                    $this->maxSavePostLinkPendingLocked($userId, $token, $next);
                    return ['user_id' => $userId, 'channel_id' => $channelId, 'category_id' => 0, 'input_token' => $token, 'token' => $next['token']];
                });
            });
            if ($effect !== null) {
                $this->maxAnswerPostLinkCategories($callbackId, $userId, $channelId, (string) $effect['token']);
                return;
            }
        }
        try { $reference = GitPostedPostLink::maxReference($pending['parsed']); }
        catch (Throwable $error) { throw new RuntimeException('Не удалось открыть ссылку МАКС. Выбор канала сохранён; попробуйте снова.', 0, $error); }
        $message = $this->maxReadPostLinkSource($reference, $userId);
        $snapshot = $this->maxPostLinkSnapshot($message);
        $effect = $this->withMaxChannelCapabilityLock($userId, $channelId, 'post', function (array $channel, array $permission) use ($userId, $channelId, $categoryId, $token, $reference, $snapshot): array {
            return $this->completeMaxUpdateEffectLocked('post_link_create', function () use ($channel, $permission, $userId, $channelId, $categoryId, $token, $reference, $snapshot): array {
                if (!empty($channel['is_archived'])) throw new RuntimeException('Канал отключён. Выберите другой канал.');
                $this->maxAssertPostLinkAccess($reference['chat_id'], $userId, $reference['public'], true);
                $quota = null;
                if ($categoryId !== null) {
                    if (empty($permission['is_ad_manager'])) throw new RuntimeException('Рекламная компания больше недоступна.');
                    $stmt = $this->pdo->prepare('SELECT * FROM posted_max_manager_categories WHERE id=:category AND channel_id=:channel AND max_user_id=:user AND is_active=1 LIMIT 1 FOR UPDATE');
                    $stmt->execute(['category' => $categoryId, 'channel' => $channelId, 'user' => $userId]);
                    $quota = $stmt->fetch();
                    if (!is_array($quota)) throw new RuntimeException('Рекламная компания больше недоступна.');
                } elseif (!empty($permission['is_ad_manager'])) {
                    $stmt = $this->pdo->prepare('SELECT * FROM posted_max_permissions WHERE channel_id=:channel AND max_user_id=:user LIMIT 1 FOR UPDATE');
                    $stmt->execute(['channel' => $channelId, 'user' => $userId]);
                    $quota = $stmt->fetch();
                    $stmt = $this->pdo->prepare('SELECT id FROM posted_max_manager_categories WHERE channel_id=:channel AND max_user_id=:user AND is_active=1 LIMIT 1 FOR UPDATE');
                    $stmt->execute(['channel' => $channelId, 'user' => $userId]);
                    if ($stmt->fetchColumn() !== false) throw new RuntimeException('Выберите рекламную компанию для поста по ссылке.');
                }
                if (is_array($quota) && $quota['post_quota_remaining'] !== null && (int) $quota['post_quota_remaining'] < 1) {
                    throw new RuntimeException('Лимит публикаций исчерпан. Выберите другую рекламную компанию или обратитесь к владельцу канала.');
                }
                $this->maxSavePostLinkPendingLocked($userId, $token, null);
                $this->pdo->prepare('INSERT INTO posted_max_posts(channel_id,author_max_user_id,manager_category_id) VALUES(:channel,:author,:category)')
                    ->execute(['channel' => $channelId, 'author' => $userId, 'category' => $categoryId]);
                $postId = (int) $this->pdo->lastInsertId();
                if ($postId <= 0) throw new RuntimeException('Не удалось создать черновик МАКС.');
                $this->applyMaxChannelPostDefaults($postId, $userId);
                $stmt = $this->pdo->prepare('SELECT * FROM posted_max_posts WHERE id=:id LIMIT 1 FOR UPDATE');
                $stmt->execute(['id' => $postId]);
                $post = $stmt->fetch();
                if (!is_array($post)) throw new RuntimeException('Черновик недоступен.');
                if (is_array($quota) && !empty($post['pin_after_publish']) && $quota['pin_quota_remaining'] !== null && (int) $quota['pin_quota_remaining'] < 1) {
                    throw new RuntimeException('Лимит закреплений исчерпан. Измените настройки канала или выберите другую рекламную компанию.');
                }
                $content = $snapshot['content'];
                $options = $this->maxDecodeOptions($post);
                $options['post_link_source'] = ['version' => 1, 'platform' => 'max', 'url' => $reference['url'],
                    'chat_id' => $reference['chat_id'], 'message_id' => $reference['message_id'],
                    'original' => $content, 'copy' => $content, 'warnings' => $snapshot['warnings']];
                $buttons = $snapshot['has_keyboard'] ? $snapshot['buttons'] : $this->decodeJson((string) ($post['buttons_json'] ?? ''));
                $post = array_replace($post, ['text' => $content['text'], 'text_html' => $content['html'], 'attachments_json' => $this->encodeJson($content['attachments']),
                    'buttons_json' => $this->encodeJson($buttons), 'options_json' => $this->encodeJson($options)]);
                $effective = $this->maxEffectivePostContent($post);
                GitPostedMaxApi::validateMediaAttachments($content['attachments'], $effective['buttons']);
                $this->pdo->prepare('UPDATE posted_max_posts SET text=:text,text_html=:html,attachments_json=:media,buttons_json=:buttons,options_json=:options WHERE id=:id')
                    ->execute(['text' => $post['text'], 'html' => $post['text_html'], 'media' => $post['attachments_json'], 'buttons' => $post['buttons_json'], 'options' => $post['options_json'], 'id' => $postId]);
                return ['post_id' => $postId, 'user_id' => $userId, 'channel_id' => $channelId, 'category_id' => (int) $categoryId, 'input_token' => $token];
            });
        });
        $this->answerDraft($callbackId, $userId, (int) $effect['post_id']);
    }

    private function maxSavePostLinkPendingLocked(string $userId, string $token, ?array $next): void
    {
        $stmt = $this->pdo->prepare("UPDATE posted_max_users SET state=:state,state_data=:data WHERE max_user_id=:user
            AND state='await_max_post_link' AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token");
        $stmt->execute(['state' => $next === null ? null : 'await_max_post_link', 'data' => $next === null ? null : $this->encodeJson($next), 'user' => $userId, 'token' => $token]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Выбор канала устарел. Отправьте ссылку снова.');
    }

    private function maxAnswerPostLinkCategories(string $callbackId, string $userId, int $channelId, string $token, int $page = 0): void
    {
        $current = $this->user($userId);
        $pending = $this->decodeJson((string) ($current['state_data'] ?? ''));
        if (($current['state'] ?? '') !== 'await_max_post_link' || ($pending['token'] ?? '') !== $token
            || (int) ($pending['channel_id'] ?? 0) !== $channelId) {
            $this->api->answerCallback($callbackId, notification: 'Выбор уже завершён или изменён.');
            return;
        }
        $stmt = $this->pdo->prepare('SELECT id,name FROM posted_max_manager_categories WHERE channel_id=:channel AND max_user_id=:user AND is_active=1 ORDER BY id');
        $stmt->execute(['channel' => $channelId, 'user' => $userId]);
        $categories = $stmt->fetchAll();
        $page = min(max(0, $page), max(0, (int) ceil(count($categories) / 20) - 1));
        $rows = [];
        foreach (array_slice($categories, $page * 20, 20) as $category) $rows[] = [GitPostedMaxApi::callbackButton(mb_substr((string) $category['name'], 0, 100), 'gp:linkcategory:' . $token . ':' . $channelId . ':' . (int) $category['id'])];
        $navigation = [];
        if ($page > 0) $navigation[] = GitPostedMaxApi::callbackButton('←', 'gp:linkcatpage:' . $token . ':' . $channelId . ':' . ($page - 1));
        if (count($categories) > ($page + 1) * 20) $navigation[] = GitPostedMaxApi::callbackButton('→', 'gp:linkcatpage:' . $token . ':' . $channelId . ':' . ($page + 1));
        if ($navigation !== []) $rows[] = $navigation;
        $rows[] = [GitPostedMaxApi::callbackButton('Отмена', 'gp:menu')];
        $this->api->answerCallback($callbackId, 'Выберите рекламную компанию для поста по ссылке:', $rows);
    }

    /** Mutates only the local reference's fresh public flag, never channel ownership. */
    private function maxReadPostLinkSource(array &$reference, string $userId): array
    {
        if (!is_string($reference['chat_id'] ?? null) || preg_match('/\A-[1-9][0-9]*\z/D', $reference['chat_id']) !== 1
            || !is_string($reference['message_id'] ?? null) || preg_match('/\Amid\.[a-f0-9]{32}\z/D', $reference['message_id']) !== 1
            || !is_string($reference['url'] ?? null)) throw new InvalidArgumentException('Некорректная ссылка на пост МАКС.');
        try {
            $chat = $this->api->getChat($reference['chat_id']);
            if ((string) ($chat['chat_id'] ?? '') !== $reference['chat_id'] || ($chat['type'] ?? '') !== 'channel'
                || ($chat['status'] ?? 'active') !== 'active') throw new InvalidArgumentException('Источник не является доступным каналом МАКС.');
            $reference['public'] = ($chat['is_public'] ?? false) === true;
            $this->maxAssertPostLinkAccess($reference['chat_id'], $userId, $reference['public'], false);
            $response = $this->api->getMessage($reference['message_id']);
        } catch (InvalidArgumentException $error) { throw $error; }
        catch (Throwable $error) { throw new InvalidArgumentException('Пост МАКС недоступен. Проверьте ссылку и права доступа; черновик сохранён.', 0, $error); }
        $messages = $response['messages'] ?? null;
        $message = is_array($messages) && count($messages) === 1 ? reset($messages) : null;
        if (!is_array($message) || (string) ($message['body']['mid'] ?? $message['mid'] ?? '') !== $reference['message_id']
            || (string) ($message['recipient']['chat_id'] ?? '') !== $reference['chat_id']) {
            throw new InvalidArgumentException('Ссылка не соответствует доступному исходному посту МАКС. Черновик сохранён.');
        }
        $requested = GitPostedPostLink::parse($reference['url']);
        $numeric = is_array($requested) && preg_match('/\A-?[1-9][0-9]*\z/D', (string) $requested['chat']) === 1;
        $actualUrl = $message['url'] ?? null;
        $matches = false;
        if ($actualUrl === null && $numeric && !$reference['public']) $matches = true;
        elseif (is_string($actualUrl)) {
            try { $actual = GitPostedPostLink::parse($actualUrl); }
            catch (InvalidArgumentException) { $actual = null; }
            $matches = is_array($actual) && ($actual['platform'] ?? '') === 'max'
                && $actual['message_id'] === ($requested['message_id'] ?? null)
                && ($numeric || strcasecmp((string) $actual['chat'], (string) ($requested['chat'] ?? '')) === 0);
        }
        if (!$matches) throw new InvalidArgumentException('Ссылка не соответствует доступному исходному посту МАКС. Черновик сохранён.');
        return $message;
    }

    private function maxAssertPostLinkAccess(string $sourceChat, string $userId, bool $public, bool $locked): void
    {
        if ($public) return;
        $sql = "SELECT c.id,c.added_by,c.is_active,c.is_archived,u.is_active AS owner_active
            FROM posted_max_channels c JOIN posted_max_users u ON u.max_user_id=c.added_by
            WHERE c.chat_id=:chat LIMIT 1" . ($locked ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['chat' => $sourceChat]);
        $source = $stmt->fetch();
        $allowed = is_array($source) && (int) $source['is_active'] === 1 && (int) $source['is_archived'] === 0
            && (int) $source['owner_active'] === 1;
        if ($allowed && !hash_equals((string) $source['added_by'], $userId)) {
            $stmt = $this->pdo->prepare('SELECT can_post,can_schedule,can_manage FROM posted_max_permissions
                WHERE channel_id=:channel AND max_user_id=:user LIMIT 1' . ($locked ? ' FOR UPDATE' : ''));
            $stmt->execute(['channel' => $source['id'], 'user' => $userId]);
            $permission = $stmt->fetch();
            $allowed = is_array($permission) && ((int) $permission['can_post'] === 1
                || (int) $permission['can_schedule'] === 1 || (int) $permission['can_manage'] === 1);
        }
        if (!$allowed) throw new InvalidArgumentException('Для закрытого исходного канала нужны ваши действующие права в GitPosted. Выберите доступный пост; черновик сохранён.');
    }

    private function maxPostLinkSnapshot(array $message): array
    {
        // A pure forward has no own body. Its linked MessageBody is the copy's content.
        $linked = $this->forwardedContentMessage($message);
        if ($linked !== null) {
            $own = $message['body'] ?? null;
            if (is_array($own) && ($this->messageText($message) !== '' || (array) ($own['attachments'] ?? []) !== [])) {
                throw new InvalidArgumentException('Составной репост с собственным содержимым пока нельзя полностью скопировать. Перешлите исходный пост отдельно.');
            }
            $contentMessage = $linked;
        } else $contentMessage = $message;
        $body = is_array($contentMessage['body'] ?? null) ? $contentMessage['body'] : $contentMessage;
        $text = $this->messageText($contentMessage);
        $markup = $body['markup'] ?? [];
        if ($markup === null) $markup = [];
        if (!is_array($markup)) throw new InvalidArgumentException('МАКС не вернул полное оформление исходного поста.');
        $units = 0;
        $boundaries = [0 => true];
        foreach (mb_str_split($text) as $character) {
            $units += intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);
            $boundaries[$units] = true;
        }
        foreach ($markup as $item) {
            if (!is_array($item) || !is_int($item['from'] ?? null) || !is_int($item['length'] ?? null)
                || $item['length'] <= 0 || !isset($boundaries[$item['from']], $boundaries[$item['from'] + $item['length']])
                || !in_array($item['type'] ?? '', ['strong', 'bold', 'emphasized', 'italic', 'underline', 'strikethrough', 'monospaced', 'code', 'blockquote', 'quote', 'link', 'user_mention'], true)
                || (($item['type'] ?? '') === 'link' && !filter_var($item['url'] ?? null, FILTER_VALIDATE_URL))
                || (($item['type'] ?? '') === 'user_mention'
                    && (!is_string($item['user_link'] ?? null) || GitPostedPostLink::maxMentionUrl($item['user_link']) === null))) {
                throw new InvalidArgumentException('Оформление исходного поста нельзя полностью перенести. Отправьте его содержимое вручную.');
            }
        }
        $html = $this->messageHtml($contentMessage);
        if ($markup !== [] && $html === null) throw new InvalidArgumentException('Оформление исходного поста не помещается в лимит МАКС или содержит несовместимые диапазоны.');
        $rawAttachments = $body['attachments'] ?? [];
        if ($rawAttachments === null) $rawAttachments = [];
        if (!is_array($rawAttachments)) throw new InvalidArgumentException('МАКС не вернул полное медиа исходного поста.');
        $media = [];
        $buttons = [];
        $hasKeyboard = false;
        $warnings = [];
        foreach ($rawAttachments as $attachment) {
            if (!is_array($attachment)) throw new InvalidArgumentException('Медиа исходного поста повреждено.');
            if (($attachment['type'] ?? '') === 'share') {
                $url = $attachment['payload']['url'] ?? null;
                $visible = is_string($url) && $this->maxOptionsHttpUrl($url) && str_contains($text, $url);
                if (!$visible && is_string($url) && $this->maxOptionsHttpUrl($url)) {
                    foreach ($markup as $item) {
                        if (($item['type'] ?? '') === 'link' && ($item['url'] ?? '') === $url) { $visible = true; break; }
                    }
                }
                if (!$visible) throw new InvalidArgumentException('В исходном посте есть отдельное превью, ссылку которого нельзя полностью перенести. Отправьте содержимое вручную.');
                // MAX reconstructs an automatic preview from this retained URL.
                continue;
            }
            if (($attachment['type'] ?? '') === 'inline_keyboard') {
                $hasKeyboard = true;
                $rows = $attachment['payload']['buttons'] ?? null;
                if (!is_array($rows) || count($rows) > 30) throw new InvalidArgumentException('Кнопки исходного поста повреждены или превышают лимит МАКС.');
                foreach ($rows as $row) {
                    if (!is_array($row)) throw new InvalidArgumentException('Кнопки исходного поста повреждены.');
                    $links = [];
                    foreach ($row as $button) {
                        if (!is_array($button)) throw new InvalidArgumentException('Кнопки исходного поста повреждены.');
                        if (($button['type'] ?? '') !== 'link') { $warnings['source_bot_buttons'] = true; continue; }
                        if (!is_string($button['text'] ?? null) || $button['text'] === '' || mb_strlen($button['text']) > 128
                            || !is_string($button['url'] ?? null) || !$this->maxOptionsHttpUrl($button['url'])
                            || !in_array($button['intent'] ?? 'default', ['default', 'positive', 'negative'], true)) {
                            throw new InvalidArgumentException('Ссылочную кнопку исходного поста нельзя полностью перенести.');
                        }
                        $links[] = GitPostedMaxApi::linkButton($button['text'], $button['url'], $button['intent'] ?? 'default');
                    }
                    if (count($links) > 3) throw new InvalidArgumentException('В исходном посте больше трёх ссылочных кнопок в строке.');
                    if ($links !== []) $buttons[] = $links;
                }
                continue;
            }
            if (!in_array($attachment['type'] ?? '', ['image', 'video', 'audio', 'file'], true)
                || !is_array($attachment['payload'] ?? null) || !is_string($attachment['payload']['token'] ?? null)
                || $attachment['payload']['token'] === '') {
                throw new InvalidArgumentException('Медиа исходного поста нельзя полностью перенести. Поддерживаются фото, видео, аудио и файлы с доступным токеном МАКС.');
            }
            $media[] = $attachment;
        }
        $attachments = $this->reusableAttachments(['attachments' => $media]);
        $content = compact('text', 'html', 'attachments');
        $this->maxValidatePostLinkContent($content);
        try { GitPostedMaxApi::validateMediaAttachments($attachments, $buttons); }
        catch (InvalidArgumentException | RuntimeException $error) { throw new InvalidArgumentException($error->getMessage(), 0, $error); }
        return ['content' => $content, 'buttons' => $buttons, 'has_keyboard' => $hasKeyboard, 'warnings' => array_keys($warnings)];
    }

    private function maxValidatePostLinkContent(array $content): void
    {
        if (!is_string($content['text'] ?? null) || (!is_null($content['html'] ?? null) && !is_string($content['html']))
            || !is_array($content['attachments'] ?? null) || (trim($content['text']) === '' && $content['attachments'] === [])
            || mb_strlen($content['text']) > 4000 || mb_strlen((string) ($content['html'] ?? '')) > 4000) {
            throw new InvalidArgumentException('Содержимое исходного поста пустое, неполное или превышает лимит 4000 символов МАКС.');
        }
        try { GitPostedMaxApi::validateMediaAttachments($content['attachments']); }
        catch (InvalidArgumentException | RuntimeException $error) { throw new InvalidArgumentException($error->getMessage(), 0, $error); }
    }
}
