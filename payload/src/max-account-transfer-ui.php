<?php

declare(strict_types=1);

/** MAX account migration UI. Every screen goes only to the initiating user. */
trait GitPostedMaxAccountTransferUi
{
    private function maxAccountTransferService(): PostedMaxAccountTransfer
    {
        return new PostedMaxAccountTransfer(
            $this->pdo,
            fn(string $method, string $path, ?array $body = null): array => $this->api->request($method, $path, $body)
        );
    }

    /** Route before callback upsert: a forwarded button must not replace the private chat. */
    private function handleMaxAccountTransferCallback(string $callbackId, string $userId, string $payload): bool
    {
        if (!str_starts_with($payload, 'gp:transfer:')) return false;
        try {
            $user = $this->user($userId);
            $this->maxAccountTransferAssertActor($userId, $user);
            $request = hash('sha256', 'callback:' . $payload);
            $saved = $this->maxAccountTransferSavedEffect();
            if ($saved !== null) {
                $this->maxAccountTransferReplayUiEffect($userId, $request, $saved);
                return true;
            }
            $state = (string) ($user['state'] ?? '');
            $data = $this->decodeJson((string) ($user['state_data'] ?? ''));
            if ($payload === 'gp:transfer:open') {
                if ($state !== '' && !in_array($state, ['await_account_transfer', 'confirm_account_transfer', 'account_transfer_done'], true)) {
                    throw new InvalidArgumentException('Сначала завершите или отмените текущее действие, затем откройте «Перенос данных».');
                }
                if (in_array($state, ['await_account_transfer', 'confirm_account_transfer'], true)
                    && $this->maxAccountTransferStateIsFresh($data)) {
                    $nextState = $state;
                    $next = $data;
                } else {
                    $nextState = 'await_account_transfer';
                    $next = ['token' => bin2hex(random_bytes(12)), 'created_at' => time()];
                }
                $effect = $this->maxAccountTransferTransition($userId, $user, $nextState, $next, $request);
                $this->maxAccountTransferReplayUiEffect($userId, $request, $effect);
                return true;
            }
            if (preg_match('/^gp:transfer:(confirm|cancel|change):([a-f0-9]{24})$/D', $payload, $match) !== 1) {
                throw new InvalidArgumentException('Эта кнопка переноса устарела. Откройте «Перенос данных» в настройках.');
            }
            $action = $match[1];
            $token = $match[2];
            if ($action === 'confirm') {
                $completed = $this->maxAccountTransferService()->completedTransfer($userId, $token);
                if ($completed !== null) {
                    $this->showMaxAccountTransferResult($userId, $completed);
                    return true;
                }
            }
            if (!hash_equals((string) ($data['token'] ?? ''), $token)) {
                throw new InvalidArgumentException('Эта кнопка переноса устарела. Используйте последнее меню.');
            }
            if ($state === 'account_transfer_done' && $action === 'confirm') {
                $this->showMaxAccountTransferResult($userId, $data);
                return true;
            }
            if (!in_array($state, ['await_account_transfer', 'confirm_account_transfer'], true)
                || !$this->maxAccountTransferStateIsFresh($data)) {
                throw new InvalidArgumentException('Срок подтверждения переноса истёк или вы открыли другое меню. Откройте «Перенос данных» заново.');
            }
            if ($action === 'cancel') {
                $effect = $this->maxAccountTransferTransition($userId, $user, null, null, $request);
                $this->maxAccountTransferReplayUiEffect($userId, $request, $effect);
                return true;
            }
            if ($action === 'change') {
                $next = ['token' => bin2hex(random_bytes(12)), 'created_at' => time()];
                $effect = $this->maxAccountTransferTransition($userId, $user, 'await_account_transfer', $next, $request);
                $this->maxAccountTransferReplayUiEffect($userId, $request, $effect);
                return true;
            }
            $targetId = (string) ($data['target_id'] ?? '');
            if ($state !== 'confirm_account_transfer' || preg_match('/^[1-9][0-9]{0,18}$/D', $targetId) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($data['fingerprint'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Сначала укажите получателя и проверьте данные переноса.');
            }
            // The service owns its transaction and durable receipt. In particular,
            // its external permission checks must stay outside any UI transaction.
            $result = $this->maxAccountTransferService()->transfer(
                $userId,
                $targetId,
                (string) $data['fingerprint'],
                $token
            );
            $this->showMaxAccountTransferResult($userId, ['token' => $token, 'target_id' => $targetId, 'result' => $result]);
        } catch (Throwable $exception) {
            $this->maxAccountTransferError($userId, $exception);
        } finally {
            // Never include a replacement message: this button may be forwarded
            // into a group or channel. Screens are sent with user_id instead.
            try {
                $this->api->answerCallback($callbackId, '', [], 'Перенос данных — в личном чате GitPosted');
            } catch (Throwable $exception) {
                error_log('GitPosted MAX transfer callback acknowledgement: ' . get_class($exception));
            }
        }
        return true;
    }

    /** Call after commands, before other input handlers, with the observed account row. */
    private function handleMaxAccountTransferMessage(string $chatId, string $userId, string $text, array $expectedUser): bool
    {
        $saved = $this->maxAccountTransferSavedEffect();
        if ($saved !== null && ($saved['effect_type'] ?? '') !== 'account_transfer_ui') return false;
        $state = (string) ($expectedUser['state'] ?? '');
        if ($saved === null && !in_array($state, ['await_account_transfer', 'confirm_account_transfer', 'account_transfer_done'], true)) return false;
        try {
            $user = $this->user($userId);
            $this->maxAccountTransferAssertActor($userId, $user);
            if ($chatId === '' || !hash_equals((string) ($user['private_chat_id'] ?? ''), $chatId)) {
                throw new InvalidArgumentException('Перенос данных доступен в личном чате с GitPosted.');
            }
            $request = hash('sha256', 'input:' . $text);
            if ($saved !== null) {
                $this->maxAccountTransferReplayUiEffect($userId, $request, $saved);
                return true;
            }
            $data = $this->decodeJson((string) ($expectedUser['state_data'] ?? ''));
            if ((string) ($user['state'] ?? '') !== $state
                || !$this->maxAccountTransferSameData($this->decodeJson((string) ($user['state_data'] ?? '')), $data)) {
                throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню переноса.');
            }
            if ($state === 'account_transfer_done') {
                $this->showMaxAccountTransferResult($userId, $data);
                return true;
            }
            if (!$this->maxAccountTransferStateIsFresh($data)) {
                throw new InvalidArgumentException('Срок подготовки переноса истёк. Откройте «Перенос данных» заново в настройках.');
            }
            if ($state === 'confirm_account_transfer') {
                $this->showMaxAccountTransferConfirmation($userId, $data);
                return true;
            }
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > 191) {
                throw new InvalidArgumentException('Отправьте @username или числовой ID МАКС получателя одним текстовым сообщением.');
            }
            $service = $this->maxAccountTransferService();
            $recipient = $service->resolveRecipient($text);
            $targetId = (string) ($recipient['max_user_id'] ?? '');
            $summary = $service->preview($userId, $targetId);
            $recipient = (array) ($summary['recipient'] ?? []);
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $targetId) !== 1
                || !hash_equals((string) ($recipient['max_user_id'] ?? ''), $targetId)
                || (str_starts_with($text, '@') && strcasecmp(ltrim((string) ($recipient['username'] ?? ''), '@'), substr($text, 1)) !== 0)) {
                throw new InvalidArgumentException('Данные получателя изменились во время проверки. Укажите актуальный @username или числовой ID МАКС заново.');
            }
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['fingerprint'] ?? '')) !== 1) {
                throw new RuntimeException('MAX transfer preview returned an invalid fingerprint.');
            }
            $next = [
                'token' => (string) $data['token'],
                'created_at' => (int) $data['created_at'],
                'target_id' => $targetId,
                'fingerprint' => (string) $summary['fingerprint'],
                'recipient' => [
                    'max_user_id' => $targetId,
                    'username' => (string) ($recipient['username'] ?? ''),
                    'display_name' => (string) ($recipient['display_name'] ?? ''),
                ],
                'summary' => $summary,
            ];
            $effect = $this->maxAccountTransferTransition($userId, $user, 'confirm_account_transfer', $next, $request);
            $this->maxAccountTransferReplayUiEffect($userId, $request, $effect);
        } catch (Throwable $exception) {
            $this->maxAccountTransferError($userId, $exception);
        }
        return true;
    }

    private function maxAccountTransferAssertActor(string $userId, array $user): void
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $userId) !== 1
            || !hash_equals((string) ($user['max_user_id'] ?? ''), $userId)
            || (int) ($user['is_active'] ?? 0) !== 1
            || trim((string) ($user['private_chat_id'] ?? '')) === '') {
            throw new InvalidArgumentException('Сначала запустите этого бота в личном чате. Доступ к GitPosted должен быть включён.');
        }
    }

    private function maxAccountTransferStateIsFresh(array $data): bool
    {
        $createdAt = $data['created_at'] ?? null;
        return preg_match('/^[a-f0-9]{24}$/D', (string) ($data['token'] ?? '')) === 1
            && is_int($createdAt) && $createdAt > 0
            && $createdAt <= time() + 30 && $createdAt >= time() - 900;
    }

    /** MySQL JSON may reorder object keys; scalar types and list order remain exact. */
    private function maxAccountTransferSameData(array $left, array $right): bool
    {
        $normalize = static function (array $value) use (&$normalize): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) $value[$key] = $normalize($item);
            }
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return $value;
        };
        return $normalize($left) === $normalize($right);
    }

    /** Null means no previous effect; a different effect must never be consumed here. */
    private function maxAccountTransferSavedEffect(): ?array
    {
        if ($this->processingUpdateKey === null || $this->processingUpdateKey === '') return null;
        $statement = $this->pdo->prepare(
            'SELECT effect_type,result_json FROM posted_max_update_effects WHERE update_key=:update_key LIMIT 1'
        );
        $statement->execute(['update_key' => $this->processingUpdateKey]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;
        return ['effect_type' => (string) $row['effect_type'], 'result' => $this->decodeJson((string) $row['result_json'])];
    }

    /** Actor lock, exact observed prompt and journal record share one transaction. */
    private function maxAccountTransferTransition(string $userId, array $observed, ?string $nextState, ?array $nextData, string $request): array
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Подготовка переноса МАКС требует отдельной транзакции.');
        $this->pdo->beginTransaction();
        try {
            $this->assertActiveMaxActorLocked($userId);
            // Same-actor duplicates are serialized above. A locking read for a
            // missing effect key would instead gap-lock unrelated users' keys.
            $result = $this->completeMaxUpdateEffectLocked('account_transfer_ui', function () use ($userId, $observed, $nextState, $nextData, $request): array {
                $current = $this->user($userId);
                $this->maxAccountTransferAssertActor($userId, $current);
                if (($current['state'] ?? null) !== ($observed['state'] ?? null)
                    || !$this->maxAccountTransferSameData($this->decodeJson((string) ($current['state_data'] ?? '')), $this->decodeJson((string) ($observed['state_data'] ?? '')))) {
                    throw new InvalidArgumentException('Пока проверялись данные, вы открыли другое меню. Используйте последнее меню переноса.');
                }
                if ($nextState === 'confirm_account_transfer' && !$this->maxAccountTransferStateIsFresh((array) $nextData)) {
                    throw new InvalidArgumentException('Срок подготовки переноса истёк во время проверки. Откройте «Перенос данных» заново.');
                }
                // Still use SQL CAS: it also protects the exact JSON value if another
                // writer does not yet follow the actor-lock convention.
                $statement = $this->pdo->prepare(
                    'UPDATE posted_max_users SET state=:next_state,state_data=CAST(:next_data AS JSON)
                     WHERE max_user_id=:user AND is_active=1
                       AND state <=> :old_state AND state_data <=> CAST(:old_data AS JSON)'
                );
                $statement->execute([
                    'next_state' => $nextState,
                    'next_data' => $nextData === null ? null : $this->encodeJson($nextData),
                    'user' => $userId,
                    'old_state' => $observed['state'] ?? null,
                    'old_data' => $observed['state_data'] ?? null,
                ]);
                if ($statement->rowCount() !== 1
                    && (($current['state'] ?? null) !== $nextState
                        || ($nextData === null ? ($current['state_data'] ?? null) !== null : !$this->maxAccountTransferSameData($this->decodeJson((string) ($current['state_data'] ?? '')), $nextData)))) {
                    throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню переноса.');
                }
                return ['user_id' => $userId, 'request' => $request, 'state' => $nextState, 'data' => $nextData];
            }, false);
            $this->pdo->commit();
            return ['effect_type' => 'account_transfer_ui', 'result' => $result];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function maxAccountTransferReplayUiEffect(string $userId, string $request, array $saved): void
    {
        $result = (array) ($saved['result'] ?? []);
        if (($saved['effect_type'] ?? '') !== 'account_transfer_ui'
            || !hash_equals((string) ($result['user_id'] ?? ''), $userId)
            || !hash_equals((string) ($result['request'] ?? ''), $request)) {
            throw new InvalidArgumentException('Сохранённое действие не совпадает с этим меню переноса.');
        }
        $current = $this->user($userId);
        $this->maxAccountTransferAssertActor($userId, $current);
        $state = $result['state'] ?? null;
        $data = $result['data'] ?? null;
        if (($current['state'] ?? null) !== $state
            || ($data === null ? ($current['state_data'] ?? null) !== null : !is_array($data) || !$this->maxAccountTransferSameData($this->decodeJson((string) ($current['state_data'] ?? '')), $data))) {
            throw new InvalidArgumentException('Действие уже обработано; затем вы открыли другое меню. Текущее действие сохранено. Используйте последнее меню.');
        }
        if ($state === null) {
            [$text, $rows] = $this->settingsScreen($userId);
            $this->api->sendToUser($userId, "Перенос отменён.\n\n" . $text, [], $rows);
            return;
        }
        if (!$this->maxAccountTransferStateIsFresh((array) $data)) {
            throw new InvalidArgumentException('Срок подготовки переноса истёк. Откройте «Перенос данных» заново.');
        }
        if ($state === 'confirm_account_transfer') $this->showMaxAccountTransferConfirmation($userId, (array) $data);
        elseif ($state === 'await_account_transfer') $this->showMaxAccountTransferInput($userId, (array) $data);
        else throw new InvalidArgumentException('Меню переноса изменилось. Откройте его заново в настройках.');
    }

    private function showMaxAccountTransferInput(string $userId, array $data): void
    {
        $text = "♻️ ПЕРЕНОС ДАННЫХ МАКС\n\n"
            . "Ваш ID МАКС: " . $userId . "\n\n"
            . 'Передайте ваши каналы, публикации и шаблоны другому аккаунту МАКС в этом GitPosted. '
            . "Даты, повторы, закреп, удаление и режим репоста сохранятся.\n\n"
            . 'Новый аккаунт должен сначала запустить этого бота и стать администратором передаваемых каналов. '
            . "Свой ID он увидит в этом разделе. Перенос нужно завершить до удаления старого аккаунта.\n\n"
            . 'Отправьте @username или числовой ID МАКС нового аккаунта. Если username нельзя подтвердить, используйте ID. '
            . "Затем проверьте получателя и подтвердите перенос.\n\n"
            . 'Владение каналами в самом МАКС и ботом передаётся отдельно. Данные Telegram не затрагиваются.';
        $this->api->sendToUser($userId, $text, [], [
            [GitPostedMaxApi::callbackButton('Отмена', 'gp:transfer:cancel:' . (string) $data['token'])],
        ]);
    }

    private function showMaxAccountTransferConfirmation(string $userId, array $data): void
    {
        $text = "♻️ ПОДТВЕРЖДЕНИЕ ПЕРЕНОСА МАКС\n\n"
            . $this->maxAccountTransferRecipientText((array) ($data['recipient'] ?? []), (string) ($data['target_id'] ?? '')) . "\n\n"
            . $this->maxAccountTransferCounts((array) ($data['summary'] ?? [])) . "\n\n"
            . 'Даты, повторы, закреп, удаление и режим репоста сохранятся. Публикации будут принадлежать указанному аккаунту; '
            . "владение вашими каналами в GitPosted также перейдёт получателю.\n\n"
            . 'Проверьте ID МАКС получателя и подтвердите передачу. Подтверждение действует 15 минут с начала подготовки.';
        $token = (string) ($data['token'] ?? '');
        $this->api->sendToUser($userId, $text, [], [
            [GitPostedMaxApi::callbackButton('Подтвердить перенос', 'gp:transfer:confirm:' . $token, 'positive')],
            [GitPostedMaxApi::callbackButton('Другой получатель', 'gp:transfer:change:' . $token)],
            [GitPostedMaxApi::callbackButton('Отмена', 'gp:transfer:cancel:' . $token)],
        ]);
    }

    private function showMaxAccountTransferResult(string $userId, array $receipt): void
    {
        $targetId = (string) ($receipt['target_id'] ?? '');
        $result = (array) ($receipt['result'] ?? []);
        $recipient = (array) ($result['recipient'] ?? []);
        $text = "✅ ПЕРЕНОС ДАННЫХ МАКС ЗАВЕРШЁН\n\n"
            . $this->maxAccountTransferRecipientText($recipient, $targetId) . "\n\n"
            . $this->maxAccountTransferCounts($result) . "\n\n"
            . "Даты и настройки сохранены. Получатель может управлять переданными публикациями со своего аккаунта.\n\n"
            . 'Владение каналами в самом МАКС и ботом при необходимости передайте отдельно до удаления старого аккаунта.';
        $this->api->sendToUser($userId, $text, [], [[GitPostedMaxApi::callbackButton('Настройки', 'gp:settings')]]);
    }

    private function maxAccountTransferRecipientText(array $recipient, string $targetId): string
    {
        $name = trim((string) ($recipient['display_name'] ?? ''));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $name) ?? '';
        $name = mb_substr($name, 0, 100);
        $username = ltrim((string) ($recipient['username'] ?? ''), '@');
        $label = $name !== '' ? $name : 'Аккаунт МАКС';
        if (preg_match('/^[A-Za-z0-9_]{1,190}$/D', $username) === 1) $label .= ' (@' . $username . ')';
        return 'Получатель: ' . $label . "\nID МАКС: " . $targetId;
    }

    private function maxAccountTransferCounts(array $summary): string
    {
        return 'Каналов: ' . max(0, (int) ($summary['channels'] ?? 0))
            . "\nПубликаций: " . max(0, (int) ($summary['posts'] ?? 0))
            . "\nИз них запланировано: " . max(0, (int) ($summary['scheduled'] ?? 0))
            . "\nШаблонов: " . max(0, (int) ($summary['templates'] ?? 0));
    }

    private function maxAccountTransferError(string $userId, Throwable $exception): void
    {
        error_log('GitPosted MAX account transfer UI: ' . get_class($exception));
        $message = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
            ? $exception->getMessage()
            : 'Не удалось подтвердить результат операции. Откройте «Перенос данных» ещё раз и используйте последнее меню. Не удаляйте старый аккаунт до сообщения об успешном переносе.';
        $this->api->sendToUser($userId, $message, [], [[GitPostedMaxApi::callbackButton('Настройки', 'gp:settings')]]);
    }
}
