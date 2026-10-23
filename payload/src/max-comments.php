<?php

declare(strict_types=1);

/** First-comment UI and a separate journal for each confirmed publication. */
trait GitPostedMaxComments
{
    private function maxCommentRows(array $post): array
    {
        $options = $this->decodeJson((string) ($post['options_json'] ?? ''));
        $configured = trim((string) ($options['first_comment']['text'] ?? '')) !== '';
        $row = [GitPostedMaxApi::callbackButton(
            $configured ? '💬 Первый комментарий: настроен' : '💬 Первый комментарий',
            'gp:comment:' . (int) $post['id']
        )];
        if ($configured) {
            $row[] = GitPostedMaxApi::callbackButton('Убрать комментарий', 'gp:commentclear:' . (int) $post['id']);
        }
        return [$row];
    }

    private function handleMaxCommentCallback(string $callbackId, string $userId, string $data): bool
    {
        if (preg_match('/^gp:comment(clear)?:([1-9][0-9]*)$/D', $data, $match) !== 1) {
            return false;
        }
        $postId = (int) $match[2];
        $post = $this->ownedPost($userId, $postId, 'manage');
        if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)) {
            throw new RuntimeException('Пост недоступен.');
        }
        $this->assertNoActivePublicationAttempt($postId);
        if (($match[1] ?? '') === 'clear') {
            $effect = $this->withManagedMaxPostLock($userId, $postId, 'manage', ['draft', 'scheduled', 'failed', 'sent'],
                function (array $lockedPost) use ($postId, $userId): array {
                    return $this->completeMaxUpdateEffectLocked('comment_clear', function () use ($lockedPost, $postId, $userId): array {
                        $options = $this->decodeJson((string) ($lockedPost['options_json'] ?? ''));
                        unset($options['first_comment']);
                        $this->pdo->prepare('UPDATE posted_max_posts SET options_json=:options WHERE id=:id')
                            ->execute(['options' => $this->encodeJson($options), 'id' => $postId]);
                        $this->pdo->prepare("UPDATE posted_max_users SET state=NULL,state_data=NULL
                            WHERE max_user_id=:user AND state='await_first_comment'
                              AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.post_id'))=:post")
                            ->execute(['user' => $userId, 'post' => (string) $postId]);
                        return ['post_id' => $postId];
                    });
                });
            if ((int) ($effect['post_id'] ?? 0) !== $postId) throw new RuntimeException('Сохранённое действие относится к другому посту.');
            $this->api->answerCallback($callbackId, notification: 'Первый комментарий убран для будущих публикаций.');
            return true;
        }
        $effect = $this->withManagedMaxPostLock($userId, $postId, 'manage', ['draft', 'scheduled', 'failed', 'sent'],
            function () use ($userId, $postId): array {
                return $this->completeMaxUpdateEffectLocked('comment_open', function () use ($userId, $postId): array {
                    $token = bin2hex(random_bytes(16));
                    $this->setState($userId, 'await_first_comment', ['post_id' => $postId, 'token' => $token]);
                    return ['post_id' => $postId, 'token' => $token];
                });
            });
        if ((int) ($effect['post_id'] ?? 0) !== $postId) throw new RuntimeException('Сохранённое действие относится к другому посту.');
        $currentUser = $this->user($userId);
        $currentState = $this->decodeJson((string) ($currentUser['state_data'] ?? ''));
        if (($currentUser['state'] ?? '') !== 'await_first_comment'
            || !hash_equals((string) ($effect['token'] ?? ''), (string) ($currentState['token'] ?? ''))) {
            $this->api->answerCallback($callbackId, notification: 'Настройка уже завершена или изменена. Откройте первый комментарий снова.');
            return true;
        }
        $options = $this->decodeJson((string) ($post['options_json'] ?? ''));
        $current = trim((string) ($options['first_comment']['text'] ?? ''));
        $prompt = "💬 ПЕРВЫЙ КОММЕНТАРИЙ\n\nОтправьте текст до 4000 символов. Он появится после подтверждения публикации, в том числе каждого повтора."
            . "\nВключите комментарии в настройках канала. Ссылки и упоминания в комментариях МАКС не поддерживаются."
            . "\nОтправьте «-», чтобы убрать. Изменение применяется только к будущим публикациям.";
        if ($current !== '') $prompt .= "\n\nСейчас:\n" . mb_substr($current, 0, 1200);
        $summary = $this->maxCommentStatusText($postId);
        if ($summary !== '') $prompt .= "\n\n" . $summary;
        $this->api->answerCallback($callbackId, $prompt, [[GitPostedMaxApi::callbackButton('← Назад',
            ((string) $post['status'] === 'draft' ? 'gp:draft:' : 'gp:view:') . $postId)]]);
        return true;
    }

    private function handleMaxCommentInput(string $chatId, string $userId, string $text, array $message): bool
    {
        $otherEffect = false;
        $saved = $this->savedMaxUserEffect('first_comment_input', $userId, $otherEffect);
        if ($saved !== null) {
            $this->sendMaxCommentInputResult($chatId, $saved);
            return true;
        }
        if ($otherEffect) return false;
        $user = $this->user($userId);
        if (($user['state'] ?? '') !== 'await_first_comment') return false;
        $state = $this->decodeJson((string) ($user['state_data'] ?? ''));
        $postId = (int) ($state['post_id'] ?? 0);
        $token = (string) ($state['token'] ?? '');
        try {
            if ($postId <= 0 || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
                throw new RuntimeException('Настройка комментария устарела. Откройте её снова.');
            }
            $comment = null;
            if (trim($text) !== '-') {
                if ((array) ($message['body']['attachments'] ?? $message['attachments'] ?? []) !== []) {
                    throw new InvalidArgumentException('Первый комментарий МАКС поддерживает только текст, без медиа и кнопок.');
                }
                // MAX comments support formatting, but cannot contain hyperlinks
                // or user mentions. Keep the visible text and other formatting.
                if (is_array($message['body'] ?? null)) {
                    $message['body']['markup'] = array_values(array_filter((array) ($message['body']['markup'] ?? []),
                        static fn (mixed $item): bool => is_array($item) && !in_array(strtolower((string) ($item['type'] ?? '')), ['link', 'user_mention', 'mention'], true)));
                } else {
                    $message['markup'] = array_values(array_filter((array) ($message['markup'] ?? []),
                        static fn (mixed $item): bool => is_array($item) && !in_array(strtolower((string) ($item['type'] ?? '')), ['link', 'user_mention', 'mention'], true)));
                }
                $content = $this->trimmedMessageContent($message);
                $comment = $this->normalizeMaxFirstComment(['text' => $content['text'] !== '' ? $content['text'] : trim($text), 'html' => $content['html']]);
                if ($comment === null) throw new InvalidArgumentException('Отправьте текст первого комментария или «-», чтобы убрать.');
            }
            $result = $this->withManagedMaxPostLock($userId, $postId, 'manage', ['draft', 'scheduled', 'failed', 'sent'],
                function (array $post) use ($postId, $userId, $token, $comment): array {
                    return $this->completeMaxUpdateEffectLocked('first_comment_input', function () use ($post, $postId, $userId, $token, $comment): array {
                    $consume = $this->pdo->prepare("UPDATE posted_max_users SET state=NULL,state_data=NULL
                        WHERE max_user_id=:user AND state='await_first_comment'
                          AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token
                          AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.post_id'))=:post");
                    $consume->execute(['user' => $userId, 'token' => $token, 'post' => (string) $postId]);
                    if ($consume->rowCount() !== 1) throw new RuntimeException('Настройка комментария уже изменена. Откройте её снова.');
                    $options = $this->decodeJson((string) ($post['options_json'] ?? ''));
                    if ($comment === null) unset($options['first_comment']);
                    else $options['first_comment'] = $comment;
                    $this->pdo->prepare('UPDATE posted_max_posts SET options_json=:options WHERE id=:id')
                        ->execute(['options' => $this->encodeJson($options), 'id' => $postId]);
                    return ['post_id' => $postId, 'user_id' => $userId, 'removed' => $comment === null,
                        'view' => (string) $post['status'] === 'draft' ? 'draft' : 'view'];
                    });
                });
        } catch (InvalidArgumentException | RuntimeException $error) {
            $this->api->sendMessage($chatId, $error->getMessage());
            return true;
        }
        $this->sendMaxCommentInputResult($chatId, $result);
        return true;
    }

    private function sendMaxCommentInputResult(string $chatId, array $result): void
    {
        $this->api->sendMessage($chatId, !empty($result['removed']) ? 'Первый комментарий убран.' : 'Первый комментарий сохранён.', [],
            [[GitPostedMaxApi::callbackButton('К посту', 'gp:' . (($result['view'] ?? '') === 'draft' ? 'draft' : 'view') . ':' . (int) $result['post_id'])]]);
    }

    private function normalizeMaxFirstComment(array $value): ?array
    {
        if (!is_string($value['text'] ?? null) || (isset($value['html']) && !is_string($value['html']))) {
            throw new InvalidArgumentException('Некорректный текст или разметка первого комментария.');
        }
        $text = trim((string) ($value['text'] ?? ''));
        if ($text === '') return null;
        if (mb_strlen($text) > 4000) throw new InvalidArgumentException('Первый комментарий длиннее лимита МАКС — 4000 символов.');
        $result = ['text' => $text];
        $html = trim((string) ($value['html'] ?? ''));
        if ($html !== '') {
            $html = strip_tags($html, '<b><strong><i><em><u><ins><s><del><code><pre><blockquote><mark><h1><h2><h3><h4><h5><h6>');
            $html = (string) preg_replace('/<\s*(\/?)\s*([a-z][a-z0-9]*)\b[^>]*>/iu', '<$1$2>', $html);
            if (mb_strlen($html) > 4000) throw new InvalidArgumentException('Разметка первого комментария превышает лимит МАКС — 4000 символов. Сократите текст или уберите форматирование.');
            $result['html'] = $html;
        }
        return $result;
    }

    /** Only use while inserting a NEW delivery inside primary confirmation. */
    private function maxFirstCommentSnapshot(array $post, array $channel = []): ?array
    {
        $options = $this->decodeJson((string) ($post['options_json'] ?? ''));
        $value = $options['first_comment'] ?? null;
        if (!is_array($value)) return null;
        $error = null;
        try {
            $comment = $this->normalizeMaxFirstComment($value);
        } catch (InvalidArgumentException $invalid) {
            // A bad optional comment must never roll back confirmation of a
            // primary message that MAX has already accepted.
            $comment = ['text' => ''];
            $error = $invalid->getMessage();
        }
        if ($comment === null) return null;
        return $comment + ['v' => 1, 'state' => $error === null ? 'pending' : 'failed', 'author' => (string) ($post['author_max_user_id'] ?? ''),
            'owner' => (string) ($channel['added_by'] ?? $post['added_by'] ?? ''),
            'category' => (int) ($post['manager_category_id'] ?? 0), 'attempts' => 0,
            'id' => null, 'error' => $error, 'started_at' => null, 'retry_at' => null];
    }

    private function maxCommentStatusText(int $postId): string
    {
        $stmt = $this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.state')) AS state,COUNT(*) AS amount
            FROM posted_max_deliveries WHERE post_id=:post AND comment_json IS NOT NULL GROUP BY state");
        $stmt->execute(['post' => $postId]);
        $labels = ['pending' => 'ожидают отправки', 'processing' => 'отправляются', 'sent' => 'отправлены', 'failed' => 'не отправлены', 'unknown' => 'результат неизвестен — проверьте канал вручную, автоматического повтора не будет'];
        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) ($row['state'] ?? '');
            if (isset($labels[$status])) $lines[] = 'Комментарии: ' . (int) $row['amount'] . ' — ' . $labels[$status] . '.';
        }
        $errors = $this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.error')) AS error
            FROM posted_max_deliveries WHERE post_id=:post
              AND JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.state')) IN ('failed','unknown')
            ORDER BY id DESC LIMIT 3");
        $errors->execute(['post' => $postId]);
        foreach (array_unique($errors->fetchAll(PDO::FETCH_COLUMN)) as $error) {
            if (!is_string($error) || $error === '' || $error === 'null') continue;
            $error = (string) preg_replace('/(?:access_token|authorization|token)\s*[:=]\s*\S+/iu', '[скрыто]', $error);
            $lines[] = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error), 0, 200);
        }
        return implode("\n", $lines);
    }

    private function finishMaxFirstComment(int $deliveryId): bool
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Комментарий можно отправлять только после сохранения основной публикации.');
        $lookup = $this->pdo->prepare('SELECT d.post_id,p.channel_id,c.chat_id FROM posted_max_deliveries d
            JOIN posted_max_posts p ON p.id=d.post_id JOIN posted_max_channels c ON c.id=p.channel_id WHERE d.id=:id LIMIT 1');
        $lookup->execute(['id' => $deliveryId]);
        $scope = $lookup->fetch();
        if (!is_array($scope)) return false;
        return $this->withMaxPostOperationLock((int) $scope['post_id'], function () use ($deliveryId, $scope): bool {
            // Refresh before taking the authority snapshot. Revocation during
            // this API read is then observed under the channel/user row locks.
            $pendingLookup = $this->pdo->prepare('SELECT comment_json FROM posted_max_deliveries WHERE id=:id');
            $pendingLookup->execute(['id' => $deliveryId]);
            $pending = $this->decodeJson((string) $pendingLookup->fetchColumn());
            $refreshFailure = null;
            if (($pending['state'] ?? '') === 'pending') {
                try {
                    $this->syncChannelById((string) $scope['chat_id'], '', false);
                } catch (GitPostedMaxApiException $error) {
                    if ($error->deliveryMayBeUnknown || !in_array($error->httpStatus, [401, 403, 404], true)) throw $error;
                    $refreshFailure = 'Не удалось проверить право отправки комментария: ' . $error->getMessage();
                }
            }
            $snapshot = null;
            $claimToken = '';
            try {
                $this->pdo->beginTransaction();
                $channelStmt = $this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=:id LIMIT 1 FOR UPDATE');
                $channelStmt->execute(['id' => (int) $scope['channel_id']]);
                $channel = $channelStmt->fetch();
                $postStmt = $this->pdo->prepare('SELECT * FROM posted_max_posts WHERE id=:id LIMIT 1 FOR UPDATE');
                $postStmt->execute(['id' => (int) $scope['post_id']]);
                $post = $postStmt->fetch();
                $deliveryStmt = $this->pdo->prepare("SELECT *,CAST(JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.started_at')) AS DATETIME)>DATE_SUB(NOW(),INTERVAL 2 MINUTE) AS is_fresh,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.retry_at')) AS DATETIME)>NOW() AS retry_later
                    FROM posted_max_deliveries WHERE id=:id LIMIT 1 FOR UPDATE");
                $deliveryStmt->execute(['id' => $deliveryId]);
                $delivery = $deliveryStmt->fetch();
                $snapshot = $this->decodeJson((string) ($delivery['comment_json'] ?? ''));
                $state = (string) ($snapshot['state'] ?? '');
                if (!in_array($state, ['pending', 'processing'], true)) {
                    $this->pdo->commit();
                    return false;
                }
                if ($state === 'processing') {
                    if (empty($delivery['is_fresh'])) {
                        $snapshot['state'] = 'unknown';
                        $snapshot['error'] = 'Результат отправки комментария неизвестен после прерванной операции. Проверьте канал вручную; автоматического повтора не будет.';
                        $this->saveMaxCommentLocked($deliveryId, $snapshot);
                        $this->pdo->commit();
                        return true;
                    }
                    $this->pdo->commit();
                    return false;
                }
                if (!empty($delivery['retry_later'])) {
                    $this->pdo->commit();
                    return false;
                }
                $failure = $refreshFailure;
                if ($failure !== null) {
                    // A definitive preflight rejection is terminal, with no send.
                } elseif (!is_array($post) || !is_array($channel) || !empty($delivery['deleted_at'])
                    || !in_array((string) ($post['status'] ?? ''), ['sent', 'max_pending'], true)
                    || empty($channel['is_active']) || empty($channel['can_post'])) {
                    $failure = 'Основная публикация удалена или канал недоступен.';
                } elseif (!hash_equals((string) ($snapshot['author'] ?? ''), (string) $post['author_max_user_id'])
                    || !hash_equals((string) ($snapshot['owner'] ?? ''), (string) ($channel['added_by'] ?? ''))
                    || (int) ($snapshot['category'] ?? 0) !== (int) ($post['manager_category_id'] ?? 0)) {
                    $failure = 'Автор, владелец или рекламная компания публикации изменились.';
                } else {
                    $failure = $this->maxPinAuthorityFailureLocked($post, $channel);
                }
                try {
                    $content = $this->normalizeMaxFirstComment($snapshot);
                    if ($content === null) $failure = 'Текст первого комментария отсутствует.';
                } catch (InvalidArgumentException $error) {
                    $failure = $error->getMessage();
                }
                if ($failure !== null) {
                    $snapshot['state'] = 'failed';
                    $snapshot['error'] = $failure;
                    $this->saveMaxCommentLocked($deliveryId, $snapshot);
                    $this->pdo->commit();
                    return true;
                }
                $claimToken = bin2hex(random_bytes(16));
                $snapshot['state'] = 'processing';
                $snapshot['token'] = $claimToken;
                $snapshot['attempts'] = (int) ($snapshot['attempts'] ?? 0) + 1;
                $snapshot['started_at'] = (string) $this->pdo->query('SELECT NOW()')->fetchColumn();
                $snapshot['retry_at'] = null;
                $this->saveMaxCommentLocked($deliveryId, $snapshot);
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $error;
            }

            try {
                $result = $this->api->sendComment((string) $delivery['max_message_id'], (string) ($content['html'] ?? $content['text']), isset($content['html']) ? 'html' : null, true);
                $commentId = GitPostedMaxApi::messageId($result);
                if ($commentId === '') throw new GitPostedMaxApiException('МАКС не вернул ID комментария; результат неизвестен.', 200, '', true);
                $snapshot['state'] = 'sent';
                $snapshot['id'] = $commentId;
                $snapshot['error'] = null;
            } catch (Throwable $error) {
                $definitive = $error instanceof GitPostedMaxApiException && !$error->deliveryMayBeUnknown;
                $retry = $definitive && $error->httpStatus === 429 && (int) $snapshot['attempts'] < 3;
                $snapshot['state'] = $retry ? 'pending' : ($definitive ? 'failed' : 'unknown');
                $snapshot['retry_at'] = $retry ? (string) $this->pdo->query('SELECT DATE_ADD(NOW(),INTERVAL 1 MINUTE)')->fetchColumn() : null;
                $snapshot['error'] = mb_substr($error->getMessage(), 0, 1000);
                if (!$definitive) $snapshot['error'] .= ' Проверьте канал вручную; автоматического повтора не будет.';
            }
            $this->settleMaxComment($deliveryId, $claimToken, $snapshot);
            return true;
        });
    }

    private function saveMaxCommentLocked(int $deliveryId, array $snapshot): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Изменение комментария требует транзакцию.');
        $this->pdo->prepare('UPDATE posted_max_deliveries SET comment_json=:comment WHERE id=:id')
            ->execute(['comment' => $this->encodeJson($snapshot), 'id' => $deliveryId]);
    }

    private function settleMaxComment(int $deliveryId, string $token, array $snapshot): void
    {
        $this->pdo->prepare("UPDATE posted_max_deliveries SET comment_json=:comment WHERE id=:id
            AND JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.state'))='processing'
            AND JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.token'))=:token")
            ->execute(['comment' => $this->encodeJson($snapshot), 'id' => $deliveryId, 'token' => $token]);
    }

    private function processPendingMaxComments(int $limit = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'unknown' => 0, 'errors' => []];
        $rows = $this->pdo->query("SELECT id FROM posted_max_deliveries WHERE comment_json IS NOT NULL AND (
            (JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.state'))='pending' AND
                (JSON_EXTRACT(comment_json,'$.retry_at') IS NULL OR JSON_TYPE(JSON_EXTRACT(comment_json,'$.retry_at'))='NULL'
                 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.retry_at')) AS DATETIME)<=NOW()))
            OR (JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.state'))='processing' AND
                (JSON_EXTRACT(comment_json,'$.started_at') IS NULL OR JSON_TYPE(JSON_EXTRACT(comment_json,'$.started_at'))='NULL'
                 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(comment_json,'$.started_at')) AS DATETIME)<=DATE_SUB(NOW(),INTERVAL 2 MINUTE))))
            ORDER BY id LIMIT " . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $id) {
            try {
                if (!$this->finishMaxFirstComment((int) $id)) continue;
                $lookup = $this->pdo->prepare('SELECT comment_json FROM posted_max_deliveries WHERE id=:id');
                $lookup->execute(['id' => (int) $id]);
                $snapshot = $this->decodeJson((string) $lookup->fetchColumn());
                $state = (string) ($snapshot['state'] ?? '');
                if (array_key_exists($state, $result) && $state !== 'errors') $result[$state]++;
            } catch (Throwable $error) {
                $result['errors'][] = 'Комментарий публикации ' . (int) $id . ': ' . mb_substr($error->getMessage(), 0, 500);
            }
        }
        return $result;
    }
}
