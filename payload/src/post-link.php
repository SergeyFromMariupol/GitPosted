<?php

declare(strict_types=1);

/** Strict post references. User supplied URLs are never arbitrary fetch targets. */
final class GitPostedPostLink
{
    public static function maxMentionUrl(string $reference): ?string
    {
        if (preg_match('~^max://user/[1-9][0-9]*$~D', $reference) === 1) return $reference;
        if (preg_match('/^[1-9][0-9]*$/D', $reference) === 1) return 'max://user/' . $reference;
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{1,63}$/D', $reference) === 1) return 'https://max.ru/' . $reference;
        if (preg_match('~^https://max\.ru/([A-Za-z][A-Za-z0-9_]{1,63})/?$~D', $reference, $match) === 1) return 'https://max.ru/' . $match[1];
        return null;
    }

    public static function parse(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || preg_match('/\s/u', $text) === 1) return null;
        if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me|telegram\.dog|max\.ru)/~iD', $text) !== 1) return null;
        if (!str_contains($text, '://')) $text = 'https://' . $text;
        $url = parse_url($text);
        if (!is_array($url) || !in_array(strtolower((string) ($url['scheme'] ?? '')), ['https', 'http'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])) {
            throw new InvalidArgumentException('Отправьте прямую ссылку на пост Telegram или МАКС.');
        }
        $host = strtolower((string) ($url['host'] ?? ''));
        $path = (string) ($url['path'] ?? '');
        if (str_contains($path, '%') || str_contains($path, '\\') || str_contains($path, '//')) {
            throw new InvalidArgumentException('Некорректная ссылка на пост.');
        }
        parse_str((string) ($url['query'] ?? ''), $query);
        if (array_intersect(['comment', 'thread', 'reply', 'comment_id'], array_keys($query)) !== []) {
            throw new InvalidArgumentException('Нужна ссылка на сам пост, а не на комментарий или ответ.');
        }
        if (in_array($host, ['t.me', 'telegram.me', 'telegram.dog'], true)) {
            if (preg_match('~^/c/([1-9][0-9]{0,13})/([1-9][0-9]{0,9})/?$~D', $path, $match) === 1) {
                $rawChannelId = (int) $match[1];
                if ($rawChannelId > 999999999999) throw new InvalidArgumentException('Некорректный ID канала Telegram.');
                $chat = (string) (-1000000000000 - $rawChannelId);
                $canonical = 'https://t.me/c/' . $match[1] . '/' . $match[2];
            } elseif (preg_match('~^/(?:s/)?([A-Za-z][A-Za-z0-9_]{2,63})/([1-9][0-9]{0,9})/?$~D', $path, $match) === 1
                && !in_array(strtolower($match[1]), ['joinchat', 'addstickers', 'addemoji', 'share', 'proxy', 'socks', 'login', 'iv', 'boost'], true)) {
                $chat = '@' . strtolower($match[1]);
                $canonical = 'https://t.me/' . strtolower($match[1]) . '/' . $match[2];
            } else {
                throw new InvalidArgumentException('Нужна ссылка на пост вида https://t.me/channel/123.');
            }
            $id = (int) $match[2];
            if ($id < 1 || $id > 2147483647) throw new InvalidArgumentException('Некорректный номер поста Telegram.');
            return ['platform' => 'telegram', 'url' => $canonical, 'chat' => $chat, 'message_id' => (string) $id];
        }
        if ($host === 'max.ru') {
            if (preg_match('~^/c/(-?[1-9][0-9]{0,17})/([A-Za-z0-9_-]{11})/?$~D', $path, $match) === 1) {
                $chat = $match[1];
                $canonical = 'https://max.ru/c/' . $chat . '/' . $match[2];
            } elseif (preg_match('~^/([A-Za-z][A-Za-z0-9_]{1,63})/([A-Za-z0-9_-]{11})/?$~D', $path, $match) === 1
                && !in_array(strtolower($match[1]), ['join', 'share', 'web', 'auth'], true)) {
                $chat = $match[1];
                $canonical = 'https://max.ru/' . $chat . '/' . $match[2];
            } else {
                throw new InvalidArgumentException('Нужна прямая ссылка на пост МАКС: max.ru/канал/пост.');
            }
            $decoded = base64_decode(strtr($match[2], '-_', '+/') . '=', true);
            if (!is_string($decoded) || strlen($decoded) !== 8
                || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $match[2]) {
                throw new InvalidArgumentException('Некорректный идентификатор поста МАКС.');
            }
            return ['platform' => 'max', 'url' => $canonical, 'chat' => $chat, 'message_id' => $match[2]];
        }
        return null;
    }

    /** Resolve the public MAX landing page, then verify identity through Bot API. */
    public static function maxReference(array $parsed, ?callable $fetcher = null): array
    {
        $checked = self::parse((string) ($parsed['url'] ?? ''));
        if ($checked === null || $checked['platform'] !== 'max' || !self::sameReference($checked, $parsed)) {
            throw new InvalidArgumentException('Некорректная ссылка МАКС.');
        }
        $chat = (string) $parsed['chat'];
        if (preg_match('/^-?[1-9][0-9]{0,17}$/D', $chat) !== 1) {
            $html = $fetcher !== null ? $fetcher($parsed['url']) : self::maxLandingPage($parsed['url']);
            if (!is_string($html) || strlen($html) > 524288) throw new RuntimeException('Не удалось открыть ссылку МАКС.');
            preg_match_all('/\bchannelId["\x27]?\s*:\s*["\x27]?([1-9][0-9]{0,17})/', $html, $matches);
            $ids = array_values(array_unique($matches[1] ?? []));
            if (count($ids) !== 1) throw new RuntimeException('В ссылке МАКС не найден однозначный канал.');
            $chat = '-' . $ids[0];
        }
        if (PHP_INT_SIZE < 8 || (string) (int) $chat !== $chat || (int) $chat === 0) {
            throw new RuntimeException('Некорректный ID канала МАКС.');
        }
        $tail = base64_decode(strtr((string) $parsed['message_id'], '-_', '+/') . '=', true);
        return ['chat_id' => $chat, 'message_id' => 'mid.' . str_pad(dechex((int) $chat), 16, '0', STR_PAD_LEFT) . bin2hex($tail), 'url' => $parsed['url']];
    }

    private static function maxLandingPage(string $url): string
    {
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'GitPosted/1.0 PostLink',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 524288) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) throw new RuntimeException('МАКС не открыл ссылку. Проверьте её и повторите.');
        return $body;
    }

    public static function loadTelegram(array $config, array $parsed, int $actorId, bool $initializeFromCli = false): array
    {
        $checked = self::parse((string) ($parsed['url'] ?? ''));
        if ($checked === null || $checked['platform'] !== 'telegram' || !self::sameReference($checked, $parsed) || $actorId <= 0) {
            throw new InvalidArgumentException('Некорректная ссылка Telegram.');
        }
        $base = self::dataRoot($config);
        GitPostedPostLinkAssets::cleanup(PostedDb::pdo($config), $config);
        $python = $base . '/runtime/post-links-venv/bin/python3';
        $sessionDirectory = $base . '/runtime/post-links-bot';
        $worker = __DIR__ . '/tools/import_post_link_telegram.py';
        if (!is_executable($python) || !is_file($worker) || !is_dir($sessionDirectory)
            || (!self::telegramLoaderAvailable($config) && !($initializeFromCli && PHP_SAPI === 'cli'))) {
            throw new RuntimeException('Загрузчик ссылок Telegram ещё не подключён. Пока перешлите сам пост или альбом в бот.');
        }
        $mtproto = (array) ($config['mtproto'] ?? []);
        $token = (string) ($config['bot_token'] ?? '');
        if (preg_match('/^([1-9][0-9]*):[A-Za-z0-9_-]+$/D', $token, $botMatch) !== 1) throw new RuntimeException('Загрузчик Telegram не настроен.');
        $parent = $base . '/tmp/post-links';
        self::privateDirectory($parent, $base . '/tmp');
        $job = $parent . '/' . bin2hex(random_bytes(16));
        if (!mkdir($job, 0700)) throw new RuntimeException('Не удалось подготовить загрузку поста.');
        $chat = (string) $parsed['chat'];
        $source = ['url' => $parsed['url'], 'message_id' => (int) $parsed['message_id']];
        if (str_starts_with($chat, '@')) $source['username'] = substr($chat, 1);
        else $source['channel_id'] = -(int) $chat - 1000000000000;
        $input = ['api_id' => (int) ($mtproto['api_id'] ?? 0), 'api_hash' => (string) ($mtproto['api_hash'] ?? ''),
            'bot_token' => $token, 'data_dir' => $base, 'job_dir' => $job,
            'session_path' => $sessionDirectory . '/bot-' . $botMatch[1] . '.session',
            'source' => $source, 'actor_telegram_id' => $actorId, 'max_bytes' => 536870912, 'timeout_seconds' => 85];
        try {
            $result = self::runTelegramWorker($python, $worker, $input);
            if (($result['ok'] ?? false) !== true) {
                $code = strtolower((string) ($result['error']['code'] ?? 'unavailable'));
                $notice = match ($code) {
                    'protected_content' => 'В канале запрещено копирование и пересылка постов.',
                    'access_denied', 'private_access_denied', 'private_access_required', 'source_unavailable' => 'Нет доступа к исходному каналу. Для закрытого канала нужен доступ и вам, и боту.',
                    'message_not_found', 'post_not_found' => 'Пост не найден или удалён.',
                    'album_incomplete', 'album_too_large' => 'Не удалось получить альбом целиком. Перешлите весь альбом в бот.',
                    'size_limit', 'file_too_large', 'too_large' => 'Медиа превышает предел загрузки Telegram: фото — 10 МиБ, остальные файлы — 50 МиБ, весь альбом — 512 МиБ.',
                    'flood_wait', 'rate_limited' => 'Telegram временно ограничил загрузку. Повторите позже.',
                    'timeout' => 'Telegram не ответил вовремя. Повторите импорт позже.',
                    'session_busy' => 'Другой пост ещё загружается. Повторите через несколько секунд.',
                    'unsupported_media', 'unsupported_entity' => 'Этот формат поста пока не поддерживается импортом. Содержимое не было обрезано.',
                    default => 'Не удалось загрузить пост Telegram. Проверьте ссылку и доступ к каналу.',
                };
                throw new RuntimeException($notice);
            }
            if (($result['platform'] ?? '') !== 'telegram' || ($result['url'] ?? '') !== $parsed['url']
                || ($result['complete'] ?? false) !== true || !is_array($result['messages'] ?? null)) {
                throw new RuntimeException('Загрузчик вернул неполный пост.');
            }
            $files = [];
            $names = [];
            $collectNames = static function (array $value) use (&$collectNames, &$names): void {
                if (isset($value['local_path'], $value['file_name'])) $names[(string) $value['local_path']] = (string) $value['file_name'];
                foreach ($value as $item) if (is_array($item)) $collectNames($item);
            };
            $collectNames($result['messages']);
            $total = 0;
            foreach ((array) ($result['files'] ?? []) as $file) {
                if (!is_array($file)) throw new RuntimeException('Некорректное медиа поста.');
                $path = (string) ($file['path'] ?? '');
                $resolved = realpath($path);
                if ($resolved === false || dirname($resolved) !== $job || is_link($path) || !is_file($resolved)) throw new RuntimeException('Некорректный путь медиа поста.');
                $size = filesize($resolved);
                if ($size === false || $size < 1 || ($total += $size) > 536870912
                    || $size !== (int) ($file['size'] ?? -1)
                    || !hash_equals((string) ($file['sha256'] ?? ''), (string) hash_file('sha256', $resolved))) {
                    throw new RuntimeException('Медиа поста не прошло проверку целостности.');
                }
                $files[$path] = GitPostedPostLinkAssets::persist($config, $resolved, (string) ($file['mime_type'] ?? 'application/octet-stream'), $size, $names[$path] ?? basename($resolved));
            }
            $replace = static function (array $value) use (&$replace, $files): array {
                if (isset($value['local_path'])) {
                    $path = (string) $value['local_path'];
                    if (!isset($files[$path])) throw new RuntimeException('Медиа отсутствует в проверенном списке файлов.');
                    $value['file_id'] = $files[$path];
                    unset($value['local_path']);
                }
                foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $replace($item);
                return $value;
            };
            $result['messages'] = $replace($result['messages']);
            unset($result['files']);
            return $result;
        } finally {
            // Only direct files in this exact newly-created job directory are removed.
            if (realpath($job) === $job && dirname($job) === $parent && !is_link($job)) {
                foreach (new DirectoryIterator($job) as $entry) {
                    if ($entry->isDot()) continue;
                    if ($entry->isFile() || $entry->isLink()) unlink($entry->getPathname());
                }
                @rmdir($job);
            }
        }
    }

    public static function telegramLoaderAvailable(array $config): bool
    {
        $base = rtrim((string) ($config['data_dir'] ?? ''), '/');
        if (preg_match('/^([1-9][0-9]*):/', (string) ($config['bot_token'] ?? ''), $match) !== 1) return false;
        $session = $base . '/runtime/post-links-bot/bot-' . $match[1] . '.session';
        return $base !== '' && is_executable($base . '/runtime/post-links-venv/bin/python3')
            && is_readable($session) && !is_link($session) && is_file(__DIR__ . '/tools/import_post_link_telegram.py');
    }

    public static function runTelegramForwardWorker(array $config, array $input): array
    {
        $worker = __DIR__ . '/tools/forward_post_link_telegram.py';
        if (!self::telegramLoaderAvailable($config) || !is_file($worker)) {
            throw new RuntimeException('Загрузчик репостов Telegram недоступен.');
        }
        return self::runTelegramWorker(self::dataRoot($config) . '/runtime/post-links-venv/bin/python3', $worker, $input);
    }

    private static function sameReference(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) return false;
        foreach ($expected as $key => $value) if (!array_key_exists($key, $actual) || $actual[$key] !== $value) return false;
        return true;
    }

    private static function runTelegramWorker(string $python, string $worker, array $input, float $limitSeconds = 90): array
    {
        $pipes = [];
        $process = proc_open([$python, '-I', $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PYTHONUNBUFFERED' => '1']);
        if (!is_resource($process)) throw new RuntimeException('Загрузчик Telegram недоступен.');
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $timedOut = false; $code = -1;
        try {
            $json = json_encode($input, JSON_THROW_ON_ERROR);
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($pipes[0], substr($json, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Не удалось передать ссылку загрузчику.');
                $offset += $written;
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + max(0.01, min(90, $limitSeconds));
            do {
                $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
                if (strlen($stdout) > 2097152 || strlen($stderr) > 65536 || microtime(true) > $deadline) { $timedOut = true; proc_terminate($process); break; }
                $status = proc_get_status($process);
                if (!$status['running']) { $code = (int) $status['exitcode']; break; }
                usleep(20000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
                $grace = microtime(true) + 0.3;
                do {
                    foreach ([1, 2] as $index) if (isset($pipes[$index]) && is_resource($pipes[$index])) stream_get_contents($pipes[$index]);
                    $status = proc_get_status($process);
                    if (!$status['running']) break;
                    usleep(10000);
                } while (microtime(true) < $grace);
                if ($status['running']) proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
        if ($timedOut) throw new RuntimeException('Загрузка поста заняла слишком много времени. Повторите позже.');
        $result = json_decode($stdout, true);
        if (!is_array($result) || ($code !== 0 && ($result['ok'] ?? null) !== false)) throw new RuntimeException('Загрузчик Telegram временно недоступен.');
        return $result;
    }

    public static function dataRoot(array $config): string
    {
        $path = rtrim((string) ($config['data_dir'] ?? ''), '/');
        if ($path === '' || !str_starts_with($path, '/') || realpath($path) !== $path || is_link($path)) throw new RuntimeException('Некорректный каталог данных GitPosted.');
        return $path;
    }

    public static function privateDirectory(string $path, string $parent): void
    {
        if (dirname($path) !== $parent || realpath($parent) !== $parent || is_link($parent)) throw new RuntimeException('Некорректный каталог загрузки.');
        if (!file_exists($path) && !mkdir($path, 0700)) throw new RuntimeException('Не удалось создать каталог загрузки.');
        if (realpath($path) !== $path || is_link($path) || !is_dir($path)) throw new RuntimeException('Некорректный каталог загрузки.');
        chmod($path, 0700);
    }
}

require_once __DIR__ . '/post-link-assets.php';
