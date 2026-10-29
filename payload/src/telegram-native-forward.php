<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

final class GitPostedTelegramNativeForward
{
    public static function available(array $config): bool
    {
        $data = rtrim((string) ($config['data_dir'] ?? ''), '/');
        if (preg_match('/^([1-9][0-9]*):/', (string) ($config['bot_token'] ?? ''), $match) !== 1) return false;
        $session = $data . '/runtime/post-links-bot/bot-' . $match[1] . '.session';
        return method_exists(GitPostedPostLink::class, 'runTelegramForwardWorker')
            && GitPostedPostLink::telegramLoaderAvailable($config)
            && is_file($session) && !is_link($session)
            && is_file(__DIR__ . '/tools/forward_post_link_telegram.py');
    }

    public static function forward(array $config, array $post, string $targetChat, int $actorId, string $randomKey, bool $silent = false, bool $protectContent = false): array
    {
        return self::execute($config, $post, $targetChat, $actorId, $randomKey, $silent, false, $protectContent);
    }

    public static function dryRun(array $config, array $post, string $targetChat, int $actorId): array
    {
        return self::execute($config, $post, $targetChat, $actorId, 'read-only-probe', false, true);
    }

    private static function execute(array $config, array $post, string $targetChat, int $actorId, string $randomKey, bool $silent, bool $dryRun, bool $protectContent = false): array
    {
        if (!self::available($config)) throw new InvalidArgumentException('Сессия пересылки GitPosted недоступна. Повторите позже; режим репоста сохранён.');
        $import = json_decode((string) ($post['import_json'] ?? ''), true);
        $original = (array) ($import['source'] ?? []);
        $parsed = GitPostedPostLink::parse((string) ($original['url'] ?? ''));
        $sourceChat = (string) ($original['chat_id'] ?? '');
        $messageIds = (array) ($original['message_ids'] ?? []);
        if (($import['version'] ?? null) !== 1 || ($import['mode'] ?? '') !== 'native'
            || $parsed === null || $parsed['platform'] !== 'telegram'
            || (!str_starts_with((string) $parsed['chat'], '@') && (string) $parsed['chat'] !== $sourceChat)
            || preg_match('/^-1[0-9]{12,15}$/D', $sourceChat) !== 1
            || count($messageIds) !== 1 || !is_int($messageIds[0]) || $messageIds[0] <= 0
            || (int) $parsed['message_id'] !== $messageIds[0]
            || $sourceChat !== (string) ($post['repost_chat_id'] ?? '')
            || $messageIds[0] !== (int) ($post['repost_message_id'] ?? 0)
            || $sourceChat !== (string) ($post['repost_input_chat_id'] ?? '')
            || $messageIds[0] !== (int) ($post['repost_input_message_id'] ?? 0)
            || $actorId <= 0 || $randomKey === '') {
            throw new InvalidArgumentException('Не удалось подтвердить исходный пост для репоста. Отправьте ссылку заново.');
        }
        if (preg_match('/^(?:[1-9][0-9]{0,18}|-1[0-9]{12,15})$/D', $targetChat) !== 1
            || ($targetChat[0] !== '-' && $targetChat !== (string) $actorId)) {
            throw new InvalidArgumentException('Некорректный получатель репоста.');
        }
        $targetUsername = null;
        if ($targetChat[0] === '-') {
            try {
                $response = (new PostedTelegram((string) $config['bot_token']))->call('getChat', ['chat_id' => $targetChat]);
            } catch (PostedTelegramRequestException $exception) {
                throw new PostedTelegramRequestException('Не удалось проверить канал назначения: ' . $exception->getMessage(), false, $exception->httpStatus(), $exception->retryAfter());
            }
            $target = (array) ($response['result'] ?? []);
            if ((string) ($target['id'] ?? '') !== $targetChat || ($target['type'] ?? '') !== 'channel') {
                throw new InvalidArgumentException('Не удалось подтвердить канал назначения репоста.');
            }
            $name = $target['username'] ?? null;
            if (is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $name) === 1) $targetUsername = $name;
        }
        $data = rtrim((string) $config['data_dir'], '/');
        $parent = $data . '/tmp/post-links';
        if (!is_dir($parent) || is_link($parent) || realpath($parent) !== $parent) {
            throw new InvalidArgumentException('Закрытый каталог пересылки GitPosted недоступен.');
        }
        $job = $parent . '/' . bin2hex(random_bytes(16));
        if (!mkdir($job, 0700)) throw new InvalidArgumentException('Не удалось подготовить пересылку.');
        $source = ['url' => $parsed['url'], 'message_id' => $messageIds[0]];
        if (str_starts_with($parsed['chat'], '@')) $source['username'] = substr($parsed['chat'], 1);
        else $source['channel_id'] = -(int) $sourceChat - 1000000000000;
        $id = explode(':', (string) $config['bot_token'], 2)[0];
        $mtproto = (array) ($config['mtproto'] ?? []);
        // A deterministic ID is stable across an interrupted publication attempt;
        // distinct post IDs and preview nonces are supplied by the locked caller.
        $randomId = (int) hexdec(substr(hash('sha256', $id . ':' . $randomKey), 0, 15));
        $input = ['api_id' => (int) ($mtproto['api_id'] ?? 0), 'api_hash' => (string) ($mtproto['api_hash'] ?? ''),
            'bot_token' => (string) $config['bot_token'], 'data_dir' => $data, 'job_dir' => $job,
            'session_path' => $data . '/runtime/post-links-bot/bot-' . $id . '.session',
            'source' => $source, 'actor_telegram_id' => $actorId, 'expected_source_chat_id' => $sourceChat,
            'target_chat_id' => $targetChat, 'target_username' => $targetUsername,
            'random_id' => max(1, $randomId), 'silent' => $silent, 'protect_content'=>$protectContent, 'dry_run' => $dryRun, 'timeout_seconds' => 80];
        try {
            try {
                $result = GitPostedPostLink::runTelegramForwardWorker($config, $input);
            } catch (Throwable $exception) {
                // Once the child has started it may have sent the request. Never
                // try another transport or turn the post into a copy on timeout.
                throw new PostedTelegramRequestException('Нет подтверждения пересылки Telegram. Повторная отправка остановлена до сверки.', !$dryRun, 0);
            }
            if (($result['ok'] ?? false) !== true) {
                $error = (array) ($result['error'] ?? []);
                $code = (string) ($error['code'] ?? 'FORWARD_FAILED');
                $unknown = !$dryRun && ($result['delivery_may_be_unknown'] ?? true) !== false;
                $status = max(0, (int) ($error['http_status'] ?? 400));
                $retry = isset($error['retry_after']) ? max(1, (int) $error['retry_after']) : null;
                if ($unknown) {
                    // The publication pipeline schedules 429 retries before it
                    // handles ambiguity. Unknown delivery must never enter it.
                    $status = 0;
                    $retry = null;
                } elseif ($code === 'SESSION_BUSY') { $status = 429; $retry = 5; }
                $notice = match ($code) {
                    'PROTECTED_CONTENT', 'ChatForwardsRestrictedError' => 'В исходном канале запрещена пересылка постов.',
                    'SOURCE_UNAVAILABLE', 'SOURCE_MISMATCH', 'MESSAGE_NOT_FOUND' => 'Исходный пост недоступен или изменился. Режим репоста сохранён.',
                    'TARGET_UNAVAILABLE', 'TARGET_MISMATCH', 'TARGET_FORBIDDEN' => 'Сессия бота не подтвердила право публикации в канале назначения.',
                    'RATE_LIMITED', 'SESSION_BUSY' => 'Telegram временно ограничил пересылку. Повторите позже.',
                    default => $unknown ? 'Telegram не вернул подтверждение репоста. Повторная отправка остановлена до сверки.' : 'Не удалось выполнить репост через сессию бота. Проверьте доступность источника и права бота.',
                };
                throw new PostedTelegramRequestException($notice, $unknown, $status, $retry);
            }
            if ($dryRun) {
                if (($result['dry_run'] ?? false) !== true || (string) ($result['source_chat_id'] ?? '') !== $sourceChat
                    || (int) ($result['source_message_id'] ?? 0) !== $messageIds[0] || (string) ($result['target_chat_id'] ?? '') !== $targetChat) {
                    throw new InvalidArgumentException('Проверка пересылки вернула другой источник или получателя.');
                }
                return $result;
            }
            $message = (array) ($result['result'] ?? []);
            if (!self::validMessage($message, $targetChat)) {
                throw new PostedTelegramRequestException('Telegram не вернул точный результат репоста. Повторная отправка остановлена до сверки.', true);
            }
            return ['ok' => true, 'result' => $message];
        } finally {
            if (is_dir($job) && !is_link($job) && realpath($job) === $job) @rmdir($job);
        }
    }

    private static function validMessage(array $message, string $targetChat): bool
    {
        $id = $message['message_id'] ?? null;
        $date = $message['date'] ?? null;
        $origin = $message['forward_origin'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 2147483647 || !is_int($date) || $date < 1
            || (string) ($message['chat']['id'] ?? '') !== $targetChat
            || ($message['chat']['type'] ?? '') !== ($targetChat[0] === '-' ? 'channel' : 'private')
            || !is_array($origin) || !is_int($origin['date'] ?? null) || $origin['date'] < 1
            || (isset($message['text']) && !is_string($message['text']))
            || (isset($message['caption']) && !is_string($message['caption']))
            || (isset($message['text']) && isset($message['caption']))) return false;
        if (($origin['type'] ?? '') === 'channel') {
            $chat = (string) ($origin['chat']['id'] ?? '');
            return preg_match('/^-1[0-9]{12}$/D', $chat) === 1 && $chat !== '-1000000000000'
                && is_int($origin['message_id'] ?? null) && $origin['message_id'] > 0 && $origin['message_id'] <= 2147483647;
        }
        if (($origin['type'] ?? '') === 'user') {
            return is_int($origin['sender_user']['id'] ?? null) && $origin['sender_user']['id'] > 0;
        }
        if (($origin['type'] ?? '') === 'hidden_user') {
            return is_string($origin['sender_user_name'] ?? null) && $origin['sender_user_name'] !== ''
                && strlen($origin['sender_user_name']) <= 4096;
        }
        return false;
    }
}
