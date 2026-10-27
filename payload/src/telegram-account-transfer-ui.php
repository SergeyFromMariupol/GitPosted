<?php

declare(strict_types=1);

/** Telegram account migration UI. All replies stay in the initiating account. */
trait GitPostedTelegramAccountTransferUi
{
    private function telegramAccountTransferService(): PostedTelegramAccountTransfer
    {
        return new PostedTelegramAccountTransfer(
            $this->pdo,
            fn(string $method, array $payload): array => $this->telegram->call($method, $payload)
        );
    }

    private function handleTelegramAccountTransferCallback(int $userId, string $callback, ?array $target = null): bool
    {
        if (!str_starts_with($callback, 'transfer:')) return false;
        if ($target !== null && (int) ($target['chat_id'] ?? 0) !== $userId) $target = null;
        try {
            $user = $this->user($userId);
            if (!$this->isKnownActiveUser($user)) {
                throw new InvalidArgumentException('Доступ к GitPosted отключён.');
            }
            $state = (string) ($user['state'] ?? '');
            $data = $this->stateData($user);
            if ($callback === 'transfer:open') {
                if ($state !== '' && !in_array($state, ['await_account_transfer', 'confirm_account_transfer', 'account_transfer_done'], true)) {
                    throw new InvalidArgumentException('Сначала завершите или отмените текущее действие, затем откройте «Перенос данных».');
                }
                if (in_array($state, ['await_account_transfer', 'confirm_account_transfer'], true)
                    && $this->telegramAccountTransferStateIsFresh($data)) {
                    if ($state === 'confirm_account_transfer') $this->showTelegramAccountTransferConfirmation($userId, $data, $target);
                    else $this->showTelegramAccountTransferInput($userId, $data, $target);
                    return true;
                }
                $next = ['token' => bin2hex(random_bytes(12)), 'created_at' => time()];
                if (!$this->telegramAccountTransferCompareState($userId, $user, 'await_account_transfer', $next)) {
                    throw new InvalidArgumentException('Меню изменилось. Откройте «Перенос данных» ещё раз.');
                }
                $this->showTelegramAccountTransferInput($userId, $next, $target);
                return true;
            }
            if (preg_match('/^transfer:(confirm|cancel|change):([a-f0-9]{24})$/D', $callback, $match) !== 1) {
                throw new InvalidArgumentException('Эта кнопка переноса устарела. Откройте «Перенос данных» в настройках.');
            }
            $action = $match[1];
            $token = $match[2];
            if ($action === 'confirm') {
                $completed = $this->telegramAccountTransferService()->completedTransfer($userId, $token);
                if ($completed !== null) {
                    $this->showTelegramAccountTransferResult($userId, $completed, $target);
                    return true;
                }
            }
            if (!hash_equals((string) ($data['token'] ?? ''), $token)) {
                throw new InvalidArgumentException('Эта кнопка переноса устарела. Используйте последнее меню.');
            }
            // The receipt is committed with the migration. A lost Telegram reply
            // must never turn a repeated confirmation into another migration.
            if ($state === 'account_transfer_done' && $action === 'confirm') {
                $this->showTelegramAccountTransferResult($userId, $data, $target);
                return true;
            }
            if (!in_array($state, ['await_account_transfer', 'confirm_account_transfer'], true)
                || !$this->telegramAccountTransferStateIsFresh($data)) {
                throw new InvalidArgumentException('Срок подтверждения переноса истёк или вы открыли другое меню. Откройте «Перенос данных» заново.');
            }
            if ($action === 'cancel') {
                if (!$this->telegramAccountTransferCompareState($userId, $user, null, null)) {
                    throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню.');
                }
                $this->showSettings($userId, $target);
                return true;
            }
            if ($action === 'change') {
                $next = ['token' => bin2hex(random_bytes(12)), 'created_at' => time()];
                if (!$this->telegramAccountTransferCompareState($userId, $user, 'await_account_transfer', $next)) {
                    throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню.');
                }
                $this->showTelegramAccountTransferInput($userId, $next, $target);
                return true;
            }
            if ($state !== 'confirm_account_transfer' || (int) ($data['target_id'] ?? 0) <= 0
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($data['fingerprint'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Сначала укажите получателя и проверьте данные переноса.');
            }
            $result = $this->telegramAccountTransferService()->transfer(
                $userId,
                (int) $data['target_id'],
                (string) $data['fingerprint'],
                $token
            );
            // The returned result follows a confirmed COMMIT. A newer menu may
            // already own state_data; do not reread it as proof of this result.
            $receipt = ['token'=>$token,'target_id'=>(int)$data['target_id'],'result'=>$result];
            $this->showTelegramAccountTransferResult($userId, $receipt, $target);
        } catch (Throwable $exception) {
            $this->telegramAccountTransferError($userId, $exception);
        }
        return true;
    }

    private function handleTelegramAccountTransferInput(int $userId, string $state, array $data, array $message): bool
    {
        if (!in_array($state, ['await_account_transfer', 'confirm_account_transfer', 'account_transfer_done'], true)) return false;
        try {
            $user = $this->user($userId);
            if (!$this->isKnownActiveUser($user) || (string) ($user['state'] ?? '') !== $state
                || $this->stateData($user) !== $data) {
                throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню переноса.');
            }
            if ($state === 'account_transfer_done') {
                $text = trim((string) ($message['text'] ?? ''));
                if (in_array($text, ['Создать пост', 'Изменить пост', 'Контент-план', 'Настройки', 'Шаблоны', 'Каталог эмодзи', 'Мои каналы'], true)) {
                    if (!$this->telegramAccountTransferCompareState($userId, $user, null, null)) {
                        throw new InvalidArgumentException('Меню изменилось. Используйте последнее меню.');
                    }
                    match ($text) {
                        'Создать пост' => $this->showChannelPicker($userId, 'post_channel'),
                        'Изменить пост' => $this->startEditPost($userId),
                        'Контент-план' => $this->showContentPlanChannels($userId),
                        'Настройки' => $this->showSettings($userId),
                        'Шаблоны' => $this->showTemplates($userId),
                        'Каталог эмодзи' => $this->showEmojiCatalogV2($userId),
                        'Мои каналы' => $this->showMyChannels($userId),
                    };
                } else {
                    $this->showTelegramAccountTransferResult($userId, $data);
                }
                return true;
            }
            if (!$this->telegramAccountTransferStateIsFresh($data)) {
                throw new InvalidArgumentException('Срок подготовки переноса истёк. Откройте «Перенос данных» заново в настройках.');
            }
            if ($state === 'confirm_account_transfer') {
                $this->showTelegramAccountTransferConfirmation($userId, $data);
                return true;
            }
            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 64) {
                throw new InvalidArgumentException('Отправьте @username или числовой Telegram ID получателя одним текстовым сообщением.');
            }
            $service = $this->telegramAccountTransferService();
            $recipient = $service->resolveRecipient($text);
            $targetId = (int) ($recipient['telegram_id'] ?? 0);
            $summary = $service->preview($userId, $targetId);
            $recipient = (array) ($summary['recipient'] ?? []);
            if ((int) ($recipient['telegram_id'] ?? 0) !== $targetId
                || (str_starts_with($text, '@') && strcasecmp((string) ($recipient['username'] ?? ''), substr($text, 1)) !== 0)) {
                throw new InvalidArgumentException('Username получателя изменился во время проверки. Укажите актуальный @username или числовой Telegram ID заново.');
            }
            $next = [
                'token' => (string) $data['token'],
                'created_at' => (int) $data['created_at'],
                'target_id' => $targetId,
                'fingerprint' => (string) ($summary['fingerprint'] ?? ''),
                'recipient' => [
                    'telegram_id' => $targetId,
                    'username' => (string) ($recipient['username'] ?? ''),
                    'first_name' => (string) ($recipient['first_name'] ?? ''),
                ],
                'summary' => $summary,
            ];
            if (!$this->telegramAccountTransferCompareState($userId, $user, 'confirm_account_transfer', $next)) {
                throw new InvalidArgumentException('Пока проверялись права, вы открыли другое меню. Повторите подготовку переноса.');
            }
            $this->showTelegramAccountTransferConfirmation($userId, $next);
        } catch (Throwable $exception) {
            $this->telegramAccountTransferError($userId, $exception);
        }
        return true;
    }

    private function telegramAccountTransferStateIsFresh(array $data): bool
    {
        $createdAt = $data['created_at'] ?? null;
        return preg_match('/^[a-f0-9]{24}$/D', (string) ($data['token'] ?? '')) === 1
            && is_int($createdAt) && $createdAt > 0
            && $createdAt <= time() + 30 && $createdAt >= time() - 900;
    }

    private function telegramAccountTransferCompareState(int $userId, array $observed, ?string $nextState, ?array $nextData): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE posted_users SET state=:next_state,state_data=CAST(:next_data AS JSON)
             WHERE telegram_id=:user AND is_active=1
               AND state <=> :old_state AND state_data <=> CAST(:old_data AS JSON)'
        );
        $statement->execute([
            'next_state' => $nextState,
            'next_data' => $nextData === null ? null : $this->encodeJson($nextData),
            'user' => $userId,
            'old_state' => $observed['state'] ?? null,
            'old_data' => $observed['state_data'] ?? null,
        ]);
        return $statement->rowCount() === 1;
    }

    private function showTelegramAccountTransferInput(int $userId, array $data, ?array $target = null): void
    {
        $text = "♻️ ПЕРЕНОС ДАННЫХ\n\n"
            . "Передайте ваши каналы, публикации, шаблоны, папки, права доступа и рекламные категории с оставшимися лимитами другому аккаунту Telegram в этом GitPosted. "
            . "Даты, повторы, закреп, удаление и режим репоста сохранятся.\n\n"
            . "Новый аккаунт должен сначала запустить этого бота командой /start. Заранее выдавать ему права в GitPosted или назначать администратором каналов Telegram не требуется. "
            . "Бот должен сохранять права публикации в передаваемых каналах. Перенос нужно завершить до удаления старого аккаунта.\n\n"
            . "Отправьте @username или числовой Telegram ID нового аккаунта. Затем вы увидите получателя и подтвердите перенос.\n\n"
            . "Владение каналами в самом Telegram и ботом в BotFather передаётся отдельно. Для будущих репостов из закрытых источников получателю может понадобиться доступ к этим источникам.";
        $this->sendOrEditMessage($userId, $text, ['reply_markup' => ['inline_keyboard' => [
            [['text' => 'Отмена', 'callback_data' => 'transfer:cancel:' . (string) $data['token']]],
        ]]], $target);
    }

    private function showTelegramAccountTransferConfirmation(int $userId, array $data, ?array $target = null): void
    {
        $summary = (array) ($data['summary'] ?? []);
        $recipient = (array) ($data['recipient'] ?? []);
        $text = "♻️ ПОДТВЕРЖДЕНИЕ ПЕРЕНОСА\n\n"
            . $this->telegramAccountTransferRecipientText($recipient, (int) ($data['target_id'] ?? 0)) . "\n\n"
            . $this->telegramAccountTransferCounts($summary) . "\n\n"
            . "Даты, повторы, закреп, удаление и режим репоста сохранятся. Публикации будут принадлежать указанному аккаунту; "
            . "владение вашими каналами в GitPosted, ваши права доступа и рекламные категории с оставшимися лимитами также перейдут получателю. Его существующие данные сохранятся.\n\n"
            . "Проверьте Telegram ID получателя и подтвердите передачу. Подтверждение действует 15 минут с начала подготовки.";
        $token = (string) ($data['token'] ?? '');
        $this->sendOrEditMessage($userId, $text, ['reply_markup' => ['inline_keyboard' => [
            [['text' => 'Подтвердить перенос', 'callback_data' => 'transfer:confirm:' . $token]],
            [['text' => 'Другой получатель', 'callback_data' => 'transfer:change:' . $token]],
            [['text' => 'Отмена', 'callback_data' => 'transfer:cancel:' . $token]],
        ]]], $target);
    }

    private function showTelegramAccountTransferResult(int $userId, array $receipt, ?array $target = null): void
    {
        $targetId = (int) ($receipt['target_id'] ?? 0);
        $recipient = (array) ($receipt['result']['recipient'] ?? $this->user($targetId) ?? []);
        $text = "✅ ПЕРЕНОС ЗАВЕРШЁН\n\n"
            . $this->telegramAccountTransferRecipientText($recipient, $targetId) . "\n\n"
            . $this->telegramAccountTransferCounts((array) ($receipt['result'] ?? [])) . "\n\n"
            . "Даты, настройки, права доступа и рекламные лимиты сохранены. Получатель может открыть бота со своего аккаунта и управлять переданными публикациями.\n\n"
            . "Владение каналами в Telegram и ботом в BotFather при необходимости передайте отдельно до удаления старого аккаунта.";
        $this->sendOrEditMessage($userId, $text, ['reply_markup' => ['inline_keyboard' => [
            [['text' => 'Настройки', 'callback_data' => 'settings:root']],
        ]]], $target);
    }

    private function telegramAccountTransferRecipientText(array $recipient, int $targetId): string
    {
        $name = trim((string) ($recipient['first_name'] ?? ''));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $name) ?? '';
        $name = mb_substr($name, 0, 100);
        $username = ltrim((string) ($recipient['username'] ?? ''), '@');
        $label = $name !== '' ? $name : 'Аккаунт Telegram';
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/D', $username) === 1) $label .= ' (@' . $username . ')';
        return 'Получатель: ' . $label . "\nTelegram ID: " . $targetId;
    }

    private function telegramAccountTransferCounts(array $summary): string
    {
        return 'Каналов: ' . max(0, (int) ($summary['channels'] ?? 0))
            . "\nПубликаций: " . max(0, (int) ($summary['posts'] ?? 0))
            . "\nИз них запланировано: " . max(0, (int) ($summary['scheduled'] ?? 0))
            . "\nШаблонов: " . max(0, (int) ($summary['templates'] ?? 0))
            . "\nПапок: " . max(0, (int) ($summary['folders'] ?? 0))
            . "\nДоступов к каналам: " . max(0, (int) ($summary['permissions'] ?? 0))
            . "\nРекламных категорий: " . max(0, (int) ($summary['categories'] ?? 0));
    }

    private function telegramAccountTransferError(int $userId, Throwable $exception): void
    {
        error_log('GitPosted account transfer UI: ' . get_class($exception));
        $message = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
            ? $exception->getMessage()
            : 'Не удалось подтвердить результат операции. Откройте «Перенос данных» ещё раз и используйте последнее меню. Не удаляйте старый аккаунт до сообщения об успешном переносе.';
        $this->telegram->sendMessage($userId, $message, ['reply_markup' => ['inline_keyboard' => [
            [['text' => 'Настройки', 'callback_data' => 'settings:root']],
        ]]]);
    }
}
