<?php

declare(strict_types=1);

/** Editors and public callbacks for buttons attached to MAX publications. */
trait GitPostedMaxInteractive
{
    /**
     * Public callbacks must be dispatched before private-account checks. The
     * message is mandatory for public actions: payload alone is never authority.
     */
    private function handleMaxInteractiveCallback(
        string $callbackId,
        string $userId,
        string $data,
        array $message = []
    ): bool {
        if (preg_match('/^gp:(react|hidden):(\d+):([a-zA-Z0-9_-]{1,32})$/D', $data, $match) === 1) {
            if (preg_match('/^\d{1,64}$/D', $userId) !== 1 || $callbackId === '' || strlen($callbackId) > 128) {
                return true;
            }
            try {
                $this->maxHandlePublicButton($callbackId, $userId, (string) $match[1], (int) $match[2], (string) $match[3], $message);
            } catch (InvalidArgumentException $exception) {
                $this->api->answerCallback($callbackId, '', [], mb_substr($exception->getMessage(), 0, 160));
            }
            return true;
        }
        if (preg_match('/^gp:buttons:(\d+)$/D', $data, $match) === 1) {
            $this->maxAnswerInteractiveEditor($callbackId, $userId, (int) $match[1]);
            return true;
        }
        if (preg_match('/^gp:ib:(\d+):(url|react|hidden|clear)$/D', $data, $match) !== 1) {
            return false;
        }
        $postId = (int) $match[1];
        $kind = (string) $match[2];
        if ($kind === 'clear') {
            $saved = $this->savedMaxUserEffect('max_interactive_clear', $userId);
            if ($saved !== null) {
                if ((int) $saved['post_id'] !== $postId) throw new RuntimeException('Сохранённое действие относится к другому посту.');
                $this->api->answerCallback($callbackId, notification: 'Удаление кнопок уже обработано.');
                return true;
            }
        }
        $post = $this->maxEditableInteractivePost($userId, $postId);
        if ($kind === 'clear') {
            $this->maxSaveInteractiveButtons($userId, $postId, [], null);
            $this->maxAnswerInteractiveEditor($callbackId, $userId, $postId, 'Все кнопки удалены.');
            return true;
        }
        $token = bin2hex(random_bytes(12));
        $this->setState($userId, 'await_max_interactive', ['post_id' => $postId, 'kind' => $kind, 'token' => $token]);
        $prompt = match ($kind) {
            'url' => "Отправьте URL-кнопки:\nСайт - https://example.ru\n\nКнопки в одном ряду разделяйте |, ряды — новой строкой.\nОтправьте - для удаления только URL-кнопок.",
            'react' => "Отправьте от 1 до 6 реакций через |, например:\n👍 | ❤️ | 🔥\n\nОдин человек может выбрать одну реакцию; повторное нажатие снимает голос.\nОтправьте - для удаления только реакций.",
            'hidden' => "Отправьте название кнопки и скрытый текст:\nУзнать ответ - Ваш скрытый текст\n\nСкрытый текст — до 160 символов. Его увидят только участники канала, в личном уведомлении без отправки сообщений.\nОтправьте - для удаления только скрытого текста.",
        };
        $this->api->answerCallback($callbackId, $prompt, [[GitPostedMaxApi::callbackButton('Назад', 'gp:buttons:' . $postId)]]);
        return true;
    }

    private function handleMaxInteractiveInput(string $chatId, string $userId, string $text, array $message = []): bool
    {
        $otherEffect = false;
        $saved = $this->savedMaxUserEffect('max_interactive_input', $userId, $otherEffect);
        if ($saved !== null) {
            $this->api->sendMessage($chatId, 'Кнопки сохранены.', [], [[GitPostedMaxApi::callbackButton('К посту',
                'gp:' . (($saved['view'] ?? '') === 'draft' ? 'draft' : 'view') . ':' . (int) $saved['post_id'])]]);
            return true;
        }
        if ($otherEffect) return false;
        $user = $this->user($userId);
        if ((string) ($user['state'] ?? '') !== 'await_max_interactive') {
            return false;
        }
        $state = $this->decodeJson((string) ($user['state_data'] ?? ''));
        $postId = (int) ($state['post_id'] ?? 0);
        $kind = (string) ($state['kind'] ?? '');
        try {
            $post = $this->maxEditableInteractivePost($userId, $postId);
            $buttons = $this->maxParseInteractiveButtons(trim($text), $kind, $post);
            $this->maxSaveInteractiveButtons($userId, $postId, $buttons, $state, ['kind' => $kind, 'text' => trim($text)]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->api->sendMessage($chatId, $exception->getMessage(), [], [[GitPostedMaxApi::callbackButton('К настройке кнопок', 'gp:buttons:' . $postId)]]);
            return true;
        }
        $this->api->sendMessage($chatId, 'Кнопки сохранены.', [], $this->maxInteractiveRows($post));
        if ((string) $post['status'] === 'draft') {
            $this->showDraft($chatId, $userId, $postId);
        }
        return true;
    }

    private function maxInteractiveRows(array $post): array
    {
        return [[GitPostedMaxApi::callbackButton('URL-кнопки · Реакции · Скрытый текст', 'gp:buttons:' . (int) $post['id'])]];
    }

    /** Removes application-only metadata before sending a MAX keyboard. */
    private function maxPublicationButtons(array $post, ?array $delivery = null): array
    {
        $counts = [];
        if ((int) ($delivery['id'] ?? 0) > 0) {
            $stmt = $this->pdo->prepare('SELECT reaction_key,COUNT(*) AS votes FROM posted_max_reactions WHERE delivery_id=:delivery AND reaction_key<>\'\' GROUP BY reaction_key');
            $stmt->execute(['delivery' => (int) $delivery['id']]);
            foreach ($stmt->fetchAll() as $row) $counts[(string) $row['reaction_key']] = (int) $row['votes'];
        }
        return $this->maxCleanInteractiveButtons($this->decodeJson((string) ($post['buttons_json'] ?? '')), (int) ($post['id'] ?? 0), $counts);
    }

    private function maxRebindButtons(array $buttons, int $newPostId): array
    {
        foreach ($buttons as &$row) {
            if (!is_array($row)) continue;
            foreach ($row as &$button) {
                if (!is_array($button)) continue;
                $meta = $this->maxInteractiveMeta($button);
                if ($meta !== null) $button['payload'] = 'gp:' . $meta['kind'] . ':' . $newPostId . ':' . $meta['key'];
            }
            unset($button);
        }
        unset($row);
        return $buttons;
    }

    private function maxInteractiveMeta(array $button): ?array
    {
        $meta = $button['_gp'] ?? null;
        if (!is_array($meta) || !in_array($meta['kind'] ?? '', ['react', 'hidden'], true)
            || preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', (string) ($meta['key'] ?? '')) !== 1) return null;
        return $meta;
    }

    private function maxCleanInteractiveButtons(array $buttons, int $postId, array $counts = []): array
    {
        $result = [];
        foreach ($buttons as $row) {
            if (!is_array($row)) continue;
            $clean = [];
            foreach ($row as $button) {
                if (!is_array($button)) continue;
                $meta = $this->maxInteractiveMeta($button);
                if ($meta !== null) {
                    $label = (string) ($meta['label'] ?? $button['text'] ?? '');
                    if ($meta['kind'] === 'react') $label .= ' ' . (int) ($counts[$meta['key']] ?? 0);
                    $clean[] = GitPostedMaxApi::callbackButton($label, 'gp:' . $meta['kind'] . ':' . $postId . ':' . $meta['key'], (string) ($button['intent'] ?? 'default'));
                } elseif (($button['type'] ?? 'link') === 'link' && filter_var($button['url'] ?? '', FILTER_VALIDATE_URL)
                    && preg_match('~^https?://~i', (string) $button['url']) === 1) {
                    $clean[] = GitPostedMaxApi::linkButton((string) ($button['text'] ?? ''), (string) $button['url'], (string) ($button['intent'] ?? 'default'));
                }
            }
            if ($clean !== []) $result[] = $clean;
        }
        return $result;
    }

    private function maxEditableInteractivePost(string $userId, int $postId): array
    {
        if (!$this->isMaxUserActive($userId)) throw new RuntimeException('Доступ к GitPosted отключён.');
        $post = $this->ownedPost($userId, $postId, 'manage');
        if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)) {
            throw new RuntimeException('Пост недоступен или уже отправляется.');
        }
        return $post;
    }

    private function maxAnswerInteractiveEditor(string $callbackId, string $userId, int $postId, string $notice = ''): void
    {
        $post = $this->maxEditableInteractivePost($userId, $postId);
        // Leaving an input prompt invalidates its token, including old keyboards.
        $user = $this->user($userId);
        $state = $this->decodeJson((string) ($user['state_data'] ?? ''));
        if (($user['state'] ?? '') === 'await_max_interactive' && (int) ($state['post_id'] ?? 0) === $postId) {
            $this->setState($userId, null, []);
        }
        $counts = ['url' => 0, 'react' => 0, 'hidden' => 0];
        foreach ($this->decodeJson((string) ($post['buttons_json'] ?? '')) as $row) {
            foreach (is_array($row) ? $row : [] as $button) {
                if (!is_array($button)) continue;
                $meta = $this->maxInteractiveMeta($button);
                if ($meta !== null) $counts[$meta['kind']]++;
                elseif (($button['type'] ?? 'link') === 'link' && isset($button['url'])) $counts['url']++;
            }
        }
        $this->api->answerCallback($callbackId, ($notice !== '' ? $notice . "\n\n" : '') . "КНОПКИ ПОСТА\n\nНастраивайте каждый вид отдельно. Остальные кнопки сохранятся.", [
            [GitPostedMaxApi::callbackButton('URL-кнопки: ' . $counts['url'], 'gp:ib:' . $postId . ':url')],
            [GitPostedMaxApi::callbackButton('Реакции: ' . $counts['react'], 'gp:ib:' . $postId . ':react')],
            [GitPostedMaxApi::callbackButton('Скрытый текст: ' . $counts['hidden'], 'gp:ib:' . $postId . ':hidden')],
            [GitPostedMaxApi::callbackButton('Удалить все кнопки', 'gp:ib:' . $postId . ':clear', 'negative')],
            [GitPostedMaxApi::callbackButton('К посту', ((string) $post['status'] === 'draft' ? 'gp:draft:' : 'gp:view:') . $postId)],
        ]);
    }

    /** Replace one kind of buttons, retaining the other kinds and stable keys. */
    private function maxParseInteractiveButtons(string $text, string $kind, array $post): array
    {
        if (!in_array($kind, ['url', 'react', 'hidden'], true)) throw new InvalidArgumentException('Откройте настройку кнопок заново.');
        $existing = $this->decodeJson((string) ($post['buttons_json'] ?? ''));
        $keep = [];
        $oldKeys = [];
        foreach ($existing as $row) {
            $kept = [];
            foreach (is_array($row) ? $row : [] as $button) {
                if (!is_array($button)) continue;
                $meta = $this->maxInteractiveMeta($button);
                $buttonKind = $meta['kind'] ?? (($button['type'] ?? 'link') === 'link' && isset($button['url']) ? 'url' : '');
                if ($buttonKind === 'url') {
                    // Persist the current MAX shape when retaining an older
                    // {text,url} row through another kind's editor.
                    $button = GitPostedMaxApi::linkButton((string) ($button['text'] ?? ''), (string) $button['url'], (string) ($button['intent'] ?? 'default'));
                }
                if ($buttonKind !== $kind) $kept[] = $button;
                elseif ($meta !== null) $oldKeys[(string) ($meta['label'] ?? $button['text'] ?? '') . "\n" . (string) ($meta['hidden_text'] ?? '')] = (string) $meta['key'];
            }
            if ($kept !== []) $keep[] = $kept;
        }
        if ($text !== '-') {
            if ($kind === 'url') {
                $new = $this->parseUrlButtons($text);
            } else {
                $labels = $kind === 'react' ? preg_split('/\s*\|\s*|\R/u', $text) : [$text];
                $labels = array_values(array_filter(array_map('trim', $labels ?: []), static fn(string $label): bool => $label !== ''));
                if ($labels === [] || count($labels) > ($kind === 'react' ? 6 : 1)) throw new InvalidArgumentException('Укажите от 1 до 6 реакций или одну кнопку скрытого текста.');
                $new = [[]];
                $seen = [];
                foreach ($labels as $label) {
                    $hidden = '';
                    if ($kind === 'hidden') {
                        if (preg_match('/^(.+?)\s+-\s+([\s\S]+)$/u', $label, $parts) !== 1) throw new InvalidArgumentException('Формат: Название кнопки - Скрытый текст');
                        $label = trim((string) $parts[1]);
                        $hidden = trim((string) $parts[2]);
                        if ($hidden === '' || mb_strlen($hidden) > 160) throw new InvalidArgumentException('Скрытый текст должен содержать от 1 до 160 символов.');
                    }
                    if ($label === '' || mb_strlen($label) > 48 || isset($seen[$label])) throw new InvalidArgumentException('Названия должны быть разными, от 1 до 48 символов.');
                    $seen[$label] = true;
                    $key = $oldKeys[$label . "\n" . $hidden] ?? bin2hex(random_bytes(8));
                    $button = GitPostedMaxApi::callbackButton($label, 'gp:' . $kind . ':' . (int) $post['id'] . ':' . $key);
                    $button['_gp'] = ['kind' => $kind, 'key' => $key, 'label' => $label];
                    if ($kind === 'hidden') $button['_gp']['hidden_text'] = $hidden;
                    $new[0][] = $button;
                }
            }
            $keep = array_merge($keep, $new);
        }
        $buttonCount = 0;
        foreach ($keep as $row) {
            if (count($row) > 7) throw new InvalidArgumentException('В одном ряду можно разместить до 7 кнопок.');
            $buttonCount += count($row);
        }
        if (count($keep) > 30 || $buttonCount > 100) throw new InvalidArgumentException('Слишком много кнопок: до 30 рядов и 100 кнопок.');
        GitPostedMaxApi::validateMediaAttachments(empty($post['forward_message_id']) ? $this->decodeJson((string) ($post['attachments_json'] ?? '')) : [], $keep);
        return $keep;
    }

    private function maxSaveInteractiveButtons(string $userId, int $postId, array $buttons, ?array $state, ?array $input = null): void
    {
        $hint = $this->pdo->prepare('SELECT c.chat_id FROM posted_max_posts p JOIN posted_max_channels c ON c.id=p.channel_id WHERE p.id=:post LIMIT 1');
        $hint->execute(['post' => $postId]);
        $chatId = (string) ($hint->fetchColumn() ?: '');
        if ($chatId === '') throw new RuntimeException('Пост недоступен.');
        // All advisory locks precede SQL locks. The managed wrapper takes the
        // same reentrant post lock and remains responsible for authorization.
        $this->withMaxPostOperationLock($postId, fn() => $this->withMaxInteractiveChannelLock($chatId, function () use ($userId, $postId, $buttons, $state, $input): void {
        $this->withManagedMaxPostLock($userId, $postId, 'manage', ['draft', 'scheduled', 'failed', 'sent'], function (array $post) use ($userId, $postId, $buttons, $state, $input): void {
            $effect = $this->completeMaxUpdateEffectLocked($state === null ? 'max_interactive_clear' : 'max_interactive_input',
                function () use ($post, $userId, $postId, $buttons, $state, $input): array {
            if ($state !== null) {
                $user = $this->user($userId);
                $current = $this->decodeJson((string) ($user['state_data'] ?? ''));
                if (($user['state'] ?? '') !== 'await_max_interactive' || ($state['token'] ?? '') === ''
                    || !hash_equals((string) ($state['token'] ?? ''), (string) ($current['token'] ?? ''))
                    || (int) ($current['post_id'] ?? 0) !== $postId) throw new RuntimeException('Настройка уже изменилась. Откройте кнопки поста заново.');
            }
            // Rebuild against the locked row, so another editor's different
            // button kind cannot be overwritten by a stale input snapshot.
            if ($input !== null) $buttons = $this->maxParseInteractiveButtons((string) $input['text'], (string) $input['kind'], $post);
            GitPostedMaxApi::validateMediaAttachments(empty($post['forward_message_id']) ? $this->decodeJson((string) ($post['attachments_json'] ?? '')) : [], $buttons);
            if ((string) $post['status'] === 'sent') {
                // The host edit routine owns live content/publish serialization.
                $this->syncPublishedPostEdit($postId, ['buttons' => $buttons]);
            }
            $this->pdo->prepare('UPDATE posted_max_posts SET buttons_json=:buttons WHERE id=:post')
                ->execute(['buttons' => $this->encodeJson($buttons), 'post' => $postId]);
            if ($state !== null) $this->setState($userId, null, []);
            return ['post_id' => $postId, 'user_id' => $userId, 'view' => (string) $post['status'] === 'draft' ? 'draft' : 'view'];
            });
            if (($effect['user_id'] ?? '') !== $userId || (int) ($effect['post_id'] ?? 0) !== $postId) {
                throw new RuntimeException('Сохранённое действие кнопок относится к другому пользователю или посту.');
            }
        });
        }));
    }

    /** Acquire after the post advisory lock, before opening an SQL transaction. */
    private function withMaxInteractiveChannelLock(string $chatId, callable $operation): mixed
    {
        if ($chatId === '' || $this->pdo->inTransaction()) throw new RuntimeException('Блокировка кнопок должна предшествовать транзакции.');
        $name = 'gitposted_max_interactive_' . substr(hash('sha256', $chatId), 0, 28);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name,3)');
        $lock->execute(['name' => $name]);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Кнопки канала сейчас обновляются. Повторите действие.');
        try {
            return $operation();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
        }
    }

    private function maxHandlePublicButton(string $callbackId, string $userId, string $kind, int $postId, string $key, array $message): void
    {
        if (is_array($message['callback']['message'] ?? null)) $message = $message['callback']['message'];
        $mid = $this->maxMessageIdFromMessage($message);
        $chatId = $this->maxMessageChatId($message);
        if ($postId <= 0 || $mid === '' || $chatId === '') throw new InvalidArgumentException('Кнопка недоступна. Откройте оригинальную публикацию в канале.');
        $hint = $this->pdo->prepare('SELECT channel_id FROM posted_max_posts WHERE id=:post LIMIT 1');
        $hint->execute(['post' => $postId]);
        $channelId = (int) ($hint->fetchColumn() ?: 0);
        if ($channelId <= 0) throw new InvalidArgumentException('Публикация недоступна.');
        $this->withMaxPostOperationLock($postId, function () use ($callbackId, $userId, $kind, $postId, $key, $mid, $chatId, $channelId): void {
            $lockName = 'gitposted_max_interactive_' . substr(hash('sha256', $chatId), 0, 28);
            $lock = $this->pdo->prepare('SELECT GET_LOCK(:name,3)');
            $lock->execute(['name' => $lockName]);
            if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Реакции сейчас обновляются. Повторите нажатие.');
            $dispatched = false;
            try {
                $this->pdo->beginTransaction();
                $channelStmt = $this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=:channel FOR UPDATE');
                $channelStmt->execute(['channel' => $channelId]);
                $channel = $channelStmt->fetch();
                if (!is_array($channel) || empty($channel['is_active']) || empty($channel['added_by'])
                    || !hash_equals((string) $channel['chat_id'], $chatId)) throw new InvalidArgumentException('Канал публикации недоступен.');
                $postStmt = $this->pdo->prepare('SELECT * FROM posted_max_posts WHERE id=:post AND channel_id=:channel FOR UPDATE');
                $postStmt->execute(['post' => $postId, 'channel' => $channelId]);
                $post = $postStmt->fetch();
                if (!is_array($post) || (string) $post['status'] === 'deleted') throw new InvalidArgumentException('Публикация недоступна.');
                $deliveryStmt = $this->pdo->prepare('SELECT * FROM posted_max_deliveries WHERE post_id=:post AND max_message_id=:mid AND deleted_at IS NULL FOR UPDATE');
                $deliveryStmt->execute(['post' => $postId, 'mid' => $mid]);
                $delivery = $deliveryStmt->fetch();
                if (!is_array($delivery) || !empty($delivery['delete_started_at'])) throw new InvalidArgumentException('Публикация уже удалена или удаляется.');
                $effect = $this->currentMaxUpdateEffect('max_public_button');
                if ($effect !== null && ((int) ($effect['delivery_id'] ?? 0) !== (int) $delivery['id']
                    || !hash_equals((string) ($effect['user_id'] ?? ''), $userId)
                    || !hash_equals((string) ($effect['callback_id'] ?? ''), $callbackId))) {
                    throw new RuntimeException('Повторное событие не совпадает с сохранённым действием.');
                }
                if ($effect !== null && !empty($effect['rendered']) && $kind === 'react') {
                    $this->pdo->commit();
                    $this->api->answerCallback($callbackId, '', [], (string) ($effect['notification'] ?? ''));
                    return;
                }
                $actual = $this->firstMessage($this->api->getMessage($mid));
                if (!is_array($actual) || !hash_equals($mid, $this->maxMessageIdFromMessage($actual))
                    || !hash_equals($chatId, $this->maxMessageChatId($actual))) throw new InvalidArgumentException('Не удалось проверить оригинальную публикацию.');
                $payload = 'gp:' . $kind . ':' . $postId . ':' . $key;
                if (!$this->maxActualKeyboardContains($actual, $payload)) throw new InvalidArgumentException('Эта кнопка уже изменена. Откройте публикацию заново.');
                $configs = $this->maxDeliveryInteractiveConfigs($post, $delivery);
                $button = $this->maxFindInteractiveConfig($configs, $kind, $key);
                if ($button === null) throw new InvalidArgumentException('Настройка этой кнопки больше недоступна.');
                if ($kind === 'hidden') {
                    $member = false;
                    foreach ((array) ($this->api->getMembers($chatId, [$userId])['members'] ?? []) as $entry) {
                        if (is_array($entry) && hash_equals($this->userId($entry), $userId)) { $member = true; break; }
                    }
                    if (!$member) throw new InvalidArgumentException('Скрытый текст доступен участникам этого канала. Подпишитесь и нажмите ещё раз.');
                    $notification = (string) ($button['_gp']['hidden_text'] ?? '');
                    if ($notification === '' || mb_strlen($notification) > 160) throw new InvalidArgumentException('Скрытый текст недоступен.');
                    $effect = $this->completeMaxUpdateEffectLocked('max_public_button', static fn(): array => [
                        'delivery_id' => (int) $delivery['id'], 'user_id' => $userId, 'callback_id' => $callbackId,
                        'rendered' => true, 'notification' => $notification,
                    ]);
                    $this->pdo->commit();
                    $dispatched = true;
                    $this->api->answerCallback($callbackId, '', [], $notification);
                    return;
                }
                if (empty($channel['can_edit'])) throw new InvalidArgumentException('Бот пока не может обновлять реакции в этом канале.');
                if ($effect === null) {
                    $effect = $this->completeMaxUpdateEffectLocked('max_public_button', function () use ($delivery, $userId, $key, $callbackId): array {
                        $voteStmt = $this->pdo->prepare('SELECT * FROM posted_max_reactions WHERE delivery_id=:delivery AND max_user_id=:user FOR UPDATE');
                        $voteStmt->execute(['delivery' => (int) $delivery['id'], 'user' => $userId]);
                        $vote = $voteStmt->fetch();
                        $duplicate = is_array($vote) && hash_equals((string) $vote['last_callback_id'], $callbackId);
                        if (!$duplicate) {
                            $selected = is_array($vote) && hash_equals((string) $vote['reaction_key'], $key) ? '' : $key;
                            $this->pdo->prepare('INSERT INTO posted_max_reactions(delivery_id,max_user_id,reaction_key,last_callback_id,updated_at) VALUES(:delivery,:user,:reaction,:callback,NOW()) ON DUPLICATE KEY UPDATE reaction_key=VALUES(reaction_key),last_callback_id=VALUES(last_callback_id),updated_at=NOW()')
                                ->execute(['delivery' => (int) $delivery['id'], 'user' => $userId, 'reaction' => $selected, 'callback' => $callbackId]);
                        }
                        return ['delivery_id' => (int) $delivery['id'], 'user_id' => $userId, 'callback_id' => $callbackId, 'rendered' => $duplicate, 'notification' => ''];
                    });
                }
                if (empty($effect['rendered'])) {
                    try {
                        $this->maxRenderPublicReactions($post, $delivery, $actual, $configs);
                    } catch (Throwable $exception) {
                        // The vote is durable even for an ambiguous edit. Retrying
                        // renders current totals; it must never toggle again.
                        $this->pdo->commit();
                        throw $exception;
                    }
                    $effect['rendered'] = true;
                    $this->maxUpdatePublicButtonEffect($effect);
                }
                $this->pdo->commit();
                $this->api->answerCallback($callbackId);
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            } finally {
                // Holding the channel advisory lock through cooldown also spaces
                // edits made by different webhook/cron PHP processes.
                if ($dispatched) usleep(550000);
                $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $lockName]);
            }
        });
    }

    private function maxActualKeyboardContains(array $message, string $payload): bool
    {
        foreach ((array) ($message['body']['attachments'] ?? $message['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'inline_keyboard') continue;
            foreach ((array) ($attachment['payload']['buttons'] ?? []) as $row) {
                foreach (is_array($row) ? $row : [] as $button) {
                    if (is_array($button) && ($button['type'] ?? '') === 'callback' && hash_equals((string) ($button['payload'] ?? ''), $payload)) return true;
                }
            }
        }
        return false;
    }

    private function maxFindInteractiveConfig(array $configs, string $kind, string $key): ?array
    {
        foreach ($configs as $row) foreach (is_array($row) ? $row : [] as $button) {
            if (!is_array($button)) continue;
            $meta = $this->maxInteractiveMeta($button);
            if ($meta !== null && $meta['kind'] === $kind && hash_equals((string) $meta['key'], $key)) return $button;
        }
        return null;
    }

    private function maxDeliveryInteractiveConfigs(array $post, array $delivery): array
    {
        $current = $this->decodeJson((string) ($post['buttons_json'] ?? ''));
        $stmt = $this->pdo->prepare('SELECT request_json FROM posted_max_publish_attempts WHERE post_id=:post AND max_message_id=:mid LIMIT 1');
        $stmt->execute(['post' => (int) $post['id'], 'mid' => (string) $delivery['max_message_id']]);
        $snapshot = $this->decodeJson((string) ($stmt->fetchColumn() ?: ''));
        // Current keys describe deliberate edits to published buttons. Immutable
        // keys retain hidden text for older deliveries of a repeating source.
        return array_merge($current, is_array($snapshot['interactive_buttons'] ?? null) ? $snapshot['interactive_buttons'] : []);
    }

    private function maxRenderPublicReactions(array $post, array $delivery, array $actual, array $configs): void
    {
        $counts = [];
        $stmt = $this->pdo->prepare('SELECT reaction_key,COUNT(*) AS votes FROM posted_max_reactions WHERE delivery_id=:delivery AND reaction_key<>\'\' GROUP BY reaction_key');
        $stmt->execute(['delivery' => (int) $delivery['id']]);
        foreach ($stmt->fetchAll() as $row) $counts[(string) $row['reaction_key']] = (int) $row['votes'];
        $rows = [];
        foreach ((array) ($actual['body']['attachments'] ?? $actual['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) throw new RuntimeException('Не удалось сохранить вложения публикации при обновлении реакций.');
            if (($attachment['type'] ?? '') !== 'inline_keyboard') continue;
            $rows = (array) ($attachment['payload']['buttons'] ?? []);
            foreach ($rows as &$row) foreach ($row as &$button) {
                if (!is_array($button) || ($button['type'] ?? '') !== 'callback') continue;
                if (preg_match('/^gp:react:' . (int) $post['id'] . ':([a-zA-Z0-9_-]{1,32})$/D', (string) ($button['payload'] ?? ''), $match) !== 1) continue;
                $config = $this->maxFindInteractiveConfig($configs, 'react', (string) $match[1]);
                if ($config !== null) $button['text'] = mb_substr((string) ($config['_gp']['label'] ?? $config['text'] ?? ''), 0, 100) . ' ' . (int) ($counts[$match[1]] ?? 0);
            }
            unset($row, $button);
        }
        $this->maxEditPublishedKeyboard($post, $delivery, $rows);
    }

    /**
     * Caller holds the post advisory lock, channel advisory lock and current
     * channel/post SQL locks. This helper never acquires locks or transactions.
     * Rows are an already-clean API keyboard, including delivery-specific counts.
     */
    private function maxEditPublishedKeyboard(array $post, array $delivery, array $rows): void
    {
        $mid = (string) ($delivery['max_message_id'] ?? '');
        if ($mid === '' || (int) ($delivery['post_id'] ?? 0) !== (int) ($post['id'] ?? 0) || !empty($delivery['deleted_at'])) {
            throw new RuntimeException('Публикация для изменения кнопок недоступна.');
        }
        $chatId = (string) ($post['chat_id'] ?? '');
        if ($chatId === '') {
            $channel = $this->pdo->prepare('SELECT chat_id FROM posted_max_channels WHERE id=:channel AND is_active=1 LIMIT 1');
            $channel->execute(['channel' => (int) $post['channel_id']]);
            $chatId = (string) ($channel->fetchColumn() ?: '');
        }
        $actual = $this->firstMessage($this->api->getMessage($mid));
        if (!is_array($actual) || $chatId === '' || !hash_equals($mid, $this->maxMessageIdFromMessage($actual))
            || !hash_equals($chatId, $this->maxMessageChatId($actual))) throw new RuntimeException('Не удалось проверить оригинальную публикацию для изменения кнопок.');
        $attachments = [];
        foreach ((array) ($actual['body']['attachments'] ?? $actual['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) throw new RuntimeException('Не удалось сохранить вложения публикации.');
            if (($attachment['type'] ?? '') === 'inline_keyboard') continue;
            $safe = GitPostedMaxForbiddenFileFallback::outboundAttachment($attachment);
            if ($safe === null) throw new RuntimeException('Этот тип вложения не поддерживает безопасное изменение кнопок.');
            $attachments[] = $safe;
        }
        if ($rows !== []) $attachments[] = GitPostedMaxApi::keyboard($rows);
        // Omitted text/format/link preserve exact text, formatting and forward or
        // reply relation. Replacing only the keyboard would delete the media.
        try {
            $result = $this->api->request('PUT', '/messages?message_id=' . rawurlencode($mid), ['attachments' => $attachments]);
            if (($result['success'] ?? null) !== true) throw new RuntimeException('МАКС не подтвердил изменение кнопок.');
        } finally {
            // Shared channel advisory lock remains held during the cooldown.
            usleep(550000);
        }
    }

    private function maxUpdatePublicButtonEffect(array $effect): void
    {
        if ($this->processingUpdateKey === null || $this->processingUpdateKey === '') return;
        $this->pdo->prepare('UPDATE posted_max_update_effects SET result_json=:result WHERE update_key=:update_key AND effect_type=\'max_public_button\'')
            ->execute(['result' => $this->encodeJson($effect), 'update_key' => $this->processingUpdateKey]);
    }
}
