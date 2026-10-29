<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** One complete in-memory config draft. No SQL, transport, file write or secret view. */
final class PostedAdminSettings
{
    public const VERSION = 1;
    public const NONCE_TTL = 900;
    public const CATEGORIES = ['telegram', 'max', 'payments', 'common'];
    public const FLAGS = ['telegram_bot_enabled', 'max_bot_enabled', 'telegram_web_window_enabled',
        'telegram_takeover_webhook', 'max_takeover_webhook', 'yookassa_enabled'];
    public const SECRETS = ['bot_token', 'webhook_secret', 'max_bot_token', 'max_webhook_secret', 'yookassa_secret_key'];
    public const TEXT_FIELDS = ['bot_username', 'max_bot_username', 'max_api_base_url', 'telegram_pricing_mode',
        'telegram_access_mode', 'telegram_allowed_ids', 'max_pricing_mode', 'max_access_mode', 'max_allowed_ids',
        'telegram_top_hold_minutes', 'price_30', 'price_90', 'price_180', 'price_365', 'yookassa_shop_id',
        'timezone', 'service_admin_username', 'service_news_username'];

    private static function label(string $field): string
    {
        return match ($field) {
            'bot_username', 'bot_token', 'webhook_secret', 'telegram_bot_enabled' => 'Telegram',
            'max_bot_username', 'max_bot_token', 'max_webhook_secret', 'max_bot_enabled' => 'МАКС',
            'yookassa_shop_id', 'yookassa_secret_key', 'yookassa_enabled' => 'ЮKassa',
            'telegram_allowed_ids', 'max_allowed_ids' => 'разрешённые пользователи',
            'service_admin_username' => 'поддержка', 'service_news_username' => 'канал новостей',
            'timezone' => 'часовой пояс хранения', 'telegram_top_hold_minutes' => 'длительность ТОП',
            default => 'настройки',
        };
    }

    /** Missing legacy flags mean enabled only for an already configured platform. */
    public static function effectiveEnabled(array $config, string $platform): bool
    {
        if (!in_array($platform, ['telegram', 'max'], true)) throw new InvalidArgumentException('Неизвестная платформа.');
        $key = $platform . '_bot_enabled';
        if (isset($config[$key])) return $config[$key] === true;
        $tokenKey = $platform === 'telegram' ? 'bot_token' : 'max_bot_token';
        $nameKey = $platform === 'telegram' ? 'bot_username' : 'max_bot_username';
        $token = $config[$tokenKey] ?? ''; $name = $config[$nameKey] ?? '';
        if (!is_string($token) || !is_string($name)) throw new InvalidArgumentException('Настройки бота повреждены.');
        return trim($token) !== '' && trim($name) !== '';
    }

    private static function text(mixed $value, string $field, int $limit = 24000): string
    {
        if (!is_string($value) || strlen($value) > $limit || preg_match('/\x00/', $value) === 1) {
            throw new InvalidArgumentException('Некорректный формат поля «' . self::label($field) . '».');
        }
        return $value;
    }

    private static function flag(mixed $value, string $field): bool
    {
        if (!is_string($value) || !in_array($value, ['0', '1'], true)) {
            throw new InvalidArgumentException('Выберите значение поля «' . self::label($field) . '».');
        }
        return $value === '1';
    }

    public static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Обновите страницу настроек.');
        }
        return $value;
    }

    public static function category(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Некорректная категория настроек.');
        }
        return $value;
    }

    private static function token(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Обновите форму настроек.');
        }
        return $value;
    }

    public static function issueNonce(array &$session, string $configHash, ?int $now = null): string
    {
        self::digest($configHash); $now ??= time();
        $old = $session['admin_settings_drafts'] ?? [];
        if (!is_array($old)) $old = [];
        $keep = [];
        foreach ($old as $nonce => $entry) {
            if (is_string($nonce) && preg_match('/\A[a-f0-9]{32}\z/D', $nonce) === 1
                && is_array($entry) && ($entry['version'] ?? null) === 1
                && is_int($entry['expires_at'] ?? null) && $entry['expires_at'] > $now) $keep[$nonce] = $entry;
        }
        while (count($keep) >= 16) array_shift($keep);
        $nonce = bin2hex(random_bytes(16));
        $keep[$nonce] = ['version' => 1, 'config_hash' => $configHash, 'expires_at' => $now + self::NONCE_TTL, 'used' => false];
        $session['admin_settings_drafts'] = $keep;
        return $nonce;
    }

    public static function requireNonce(array $session, mixed $nonce, string $configHash, ?int $now = null): void
    {
        $nonce = self::token($nonce); self::digest($configHash); $now ??= time();
        $entry = $session['admin_settings_drafts'][$nonce] ?? null;
        if (!is_array($entry) || ($entry['version'] ?? null) !== 1 || ($entry['used'] ?? null) !== false
            || !is_int($entry['expires_at'] ?? null) || $entry['expires_at'] <= $now
            || !is_string($entry['config_hash'] ?? null) || !hash_equals($configHash, $entry['config_hash'])) {
            throw new InvalidArgumentException('Форма устарела. Обновите страницу настроек.');
        }
    }

    public static function completeNonce(array &$session, string $nonce, string $afterHash): void
    {
        self::token($nonce); self::digest($afterHash);
        if (!is_array($session['admin_settings_drafts'][$nonce] ?? null)) throw new LogicException('Missing settings nonce.');
        $session['admin_settings_drafts'][$nonce]['used'] = true;
        $session['admin_settings_drafts'][$nonce]['after_hash'] = $afterHash;
    }

    private static function username(string $value, bool $bot, bool $allowEmpty): string
    {
        $name = ltrim(trim($value), '@');
        if ($name === '' && $allowEmpty) return '';
        if (preg_match('/\A[A-Za-z0-9_]{5,32}\z/D', $name) !== 1
            || ($bot && !str_ends_with(strtolower($name), 'bot'))) {
            throw new InvalidArgumentException($bot ? 'Укажите корректный username бота.' : 'Укажите корректный username поддержки или новостей.');
        }
        return $name;
    }

    private static function secret(string $value, int $minimum = 32): string
    {
        if ($value !== '' && preg_match('/\A[A-Za-z0-9:_-]{' . $minimum . ',256}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Недопустимый формат секретного поля.');
        }
        return $value;
    }

    public static function view(array $config, string $configHash, string $nonce, string $category): array
    {
        self::digest($configHash); self::token($nonce); self::category($category);
        $telegram = PostedTelegramPolicy::fromConfig($config); $max = GitPostedMaxPolicy::fromConfig($config);
        $prices = PostedTelegramSubscription::tariffs($config); $top = PostedTelegramTopHold::fromConfig($config);
        $payment = PostedTelegramBillingApi::fromConfig($config);
        $fields = [
            'telegram_bot_enabled' => self::effectiveEnabled($config, 'telegram') ? '1' : '0',
            'max_bot_enabled' => self::effectiveEnabled($config, 'max') ? '1' : '0',
            'telegram_web_window_enabled' => (($config['telegram_web_window_enabled'] ?? false) === true) ? '1' : '0',
            'telegram_takeover_webhook' => '0', 'max_takeover_webhook' => '0',
            'bot_username' => (string) ($config['bot_username'] ?? ''), 'max_bot_username' => (string) ($config['max_bot_username'] ?? ''),
            'max_api_base_url' => (string) ($config['max_api_base_url'] ?? 'https://platform-api2.max.ru'),
            'telegram_pricing_mode' => $telegram['pricing_mode'], 'telegram_access_mode' => $telegram['access_mode'],
            'telegram_allowed_ids' => implode("\n", $telegram['allowed_telegram_ids']),
            'max_pricing_mode' => $max['pricing_mode'], 'max_access_mode' => $max['access_mode'],
            'max_allowed_ids' => implode("\n", $max['allowed_max_ids']),
            'telegram_top_hold_minutes' => (string) $top['duration_minutes'],
            'yookassa_enabled' => $payment['enabled'] ? '1' : '0', 'yookassa_shop_id' => $payment['shop_id'],
            'timezone' => (string) ($config['timezone'] ?? 'Europe/Moscow'),
            'service_admin_username' => (string) ($config['service_admin_username'] ?? ''),
            'service_news_username' => (string) ($config['service_news_username'] ?? ''),
        ];
        foreach ($prices as $days => $price) $fields['price_' . $days] = (string) $price;
        $configured = [];
        foreach (self::SECRETS as $field) $configured[$field] = $field === 'yookassa_secret_key'
            ? $payment['secret_key'] !== '' : (is_string($config[$field] ?? null) && $config[$field] !== '');
        return ['version' => 1, 'config_hash' => $configHash, 'nonce' => $nonce, 'category' => $category,
            'fields' => $fields, 'secret_configured' => $configured,
            'previous_shop_ids' => PostedTelegramPaymentProfiles::publicShopIds($config),
            'notification_url' => rtrim((string) ($config['base_url'] ?? ''), '/') . '/?yookassa_webhook=1',
            'notification_events' => ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture', 'refund.succeeded'],
            'errors' => [], 'notices' => [], 'pending_sync' => false];
    }

    public static function normalise(array $config, array $input, bool $webWindowReady): array
    {
        $required = array_merge(self::FLAGS, self::SECRETS, self::TEXT_FIELDS,
            ['action', 'settings_version', 'config_hash', 'settings_nonce', 'settings_category', 'csrf']);
        $allowed = array_merge($required, ['return_page']);
        if (array_diff(array_keys($input), $allowed) !== [] || array_diff($required, array_keys($input)) !== []) {
            throw new InvalidArgumentException('Отправьте всю форму настроек заново.');
        }
        foreach ($input as $field => $value) self::text($value, (string) $field);
        if ($input['action'] !== 'admin_settings_save' || $input['settings_version'] !== '1') {
            throw new InvalidArgumentException('Обновите форму настроек.');
        }
        self::digest($input['config_hash']); self::token($input['settings_nonce']); self::category($input['settings_category']);
        $flags = []; foreach (self::FLAGS as $field) $flags[$field] = self::flag($input[$field], $field);
        $next = $config;
        $next['telegram_bot_enabled'] = $flags['telegram_bot_enabled']; $next['max_bot_enabled'] = $flags['max_bot_enabled'];
        $next['telegram_policy'] = PostedTelegramPolicy::fromAdmin($input); $next['max_policy'] = GitPostedMaxPolicy::fromAdmin($input);
        $next['telegram_top_hold'] = PostedTelegramTopHold::fromAdminDuration($input);
        $next['telegram_subscription_tariffs'] = ['version' => 1, 'currency' => 'RUB', 'prices' => PostedAdminChannelLicenses::prices($input)];
        $timezone = trim($input['timezone']);
        if (!in_array($timezone, timezone_identifiers_list(), true)
            || !hash_equals((string) ($config['timezone'] ?? 'Europe/Moscow'), $timezone)) {
            throw new InvalidArgumentException('Часовой пояс хранения изменить нельзя.');
        }
        $next['timezone'] = $timezone;
        $next['telegram_web_window_enabled'] = $flags['telegram_web_window_enabled'];
        if ($flags['telegram_web_window_enabled'] && !$webWindowReady) {
            throw new InvalidArgumentException('Веб-окно недоступно. Проверьте HTTPS и файлы интерфейса.');
        }
        $oldToken = (string) ($config['bot_token'] ?? ''); $token = $input['bot_token'] === '' ? $oldToken : $input['bot_token'];
        if ($token !== '' && preg_match('/\A[0-9]{6,12}:[A-Za-z0-9_-]{30,200}\z/D', $token) !== 1) throw new InvalidArgumentException('Некорректный токен Telegram Bot API.');
        $tgName = self::username($input['bot_username'], true, !$flags['telegram_bot_enabled'] && $token === '');
        if (($token === '') !== ($tgName === '')) throw new InvalidArgumentException('Для Telegram укажите токен и username одного бота.');
        $next['bot_token'] = $token; $next['bot_username'] = $tgName;
        $secret = self::secret($input['webhook_secret']);
        if ($secret !== '' && preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $secret) !== 1) throw new InvalidArgumentException('Секрет webhook Telegram допускает только буквы, цифры, _ и -.');
        if (!hash_equals($oldToken, $token)) {
            if ($secret !== '' && hash_equals((string) ($config['webhook_secret'] ?? ''), $secret)) throw new InvalidArgumentException('Для нового Telegram-токена нужен новый секрет webhook.');
            $next['webhook_secret'] = $secret !== '' ? $secret : bin2hex(random_bytes(32));
        } elseif ($secret !== '') $next['webhook_secret'] = $secret;
        $maxToken = $input['max_bot_token'] === '' ? (string) ($config['max_bot_token'] ?? '') : self::secret($input['max_bot_token']);
        $maxName = self::username($input['max_bot_username'], true, !$flags['max_bot_enabled'] && $maxToken === '');
        if (($maxToken === '') !== ($maxName === '')) throw new InvalidArgumentException('Для МАКС укажите токен и username одного бота.');
        if (rtrim(trim($input['max_api_base_url']), '/') !== 'https://platform-api2.max.ru') throw new InvalidArgumentException('Разрешён только официальный API МАКС.');
        $next['max_api_base_url'] = 'https://platform-api2.max.ru'; $next['max_bot_token'] = $maxToken; $next['max_bot_username'] = $maxName;
        $maxSecret = self::secret($input['max_webhook_secret'], 16);
        if ($maxSecret !== '' && preg_match('/\A[A-Za-z0-9_-]{16,256}\z/D', $maxSecret) !== 1) throw new InvalidArgumentException('Секрет webhook МАКС допускает только буквы, цифры, _ и -.');
        $maxTokenChanged = !hash_equals((string) ($config['max_bot_token'] ?? ''), $maxToken);
        if ($maxSecret !== '') {
            if ($maxTokenChanged && hash_equals((string) ($config['max_webhook_secret'] ?? ''), $maxSecret)) {
                throw new InvalidArgumentException('При смене токена МАКС задайте новый секрет или оставьте поле пустым.');
            }
            $next['max_webhook_secret'] = $maxSecret;
        } elseif ($maxTokenChanged) $next['max_webhook_secret'] = bin2hex(random_bytes(32));
        /* MAX same-bot secret preservation and subscription ambiguity are decided by the read-only preflight, before commit. */
        $support = self::username($input['service_admin_username'], false, true);
        $news = self::username($input['service_news_username'], false, true);
        $next['service_admin_username'] = $support; $next['service_admin_url'] = $support === '' ? '' : 'https://t.me/' . $support;
        $next['service_news_username'] = $news; $next['service_news_url'] = $news === '' ? '' : 'https://t.me/' . $news;
        $currentPayment = PostedTelegramBillingApi::fromConfig($config);
        $shop = trim($input['yookassa_shop_id']); $key = $input['yookassa_secret_key'];
        if ($shop !== $currentPayment['shop_id'] && $key === '') throw new InvalidArgumentException('Для нового магазина укажите новый секретный ключ.');
        if ($key === '') $key = $currentPayment['secret_key'];
        $payment = ['version' => 1, 'enabled' => $flags['yookassa_enabled'], 'provider' => 'yookassa', 'shop_id' => $shop, 'secret_key' => $key];
        return PostedTelegramPaymentProfiles::transition($next, $payment);
    }
}
