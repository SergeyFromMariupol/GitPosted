<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/payload/src/bot-commands.php';

/** Private broker child. Credentials arrive only on stdin or in installed config. */
final class InstallerApplicationFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}

final class InstallerApplication
{
    private const TG_EVENTS = ['message', 'channel_post', 'edited_channel_post', 'callback_query', 'my_chat_member', 'chat_member'];
    private const TABLES = ['posted_account_identities', 'posted_accounts', 'posted_admin_passkeys', 'posted_admin_tokens',
        'posted_app_settings', 'posted_channels', 'posted_edit_sessions', 'posted_emoji_catalog', 'posted_emoji_sets',
        'posted_emoji_submissions', 'posted_folder_channels', 'posted_folders', 'posted_manager_categories',
        'posted_max_channels', 'posted_max_deliveries', 'posted_max_link_attempts', 'posted_max_link_codes',
        'posted_max_manager_categories', 'posted_max_permissions', 'posted_max_posts', 'posted_max_publish_attempts',
        'posted_max_reactions', 'posted_max_schema_meta', 'posted_max_templates', 'posted_max_update_effects',
        'posted_max_updates', 'posted_max_users', 'posted_permissions', 'posted_posts', 'posted_reactions',
        'posted_telegram_publish_attempts', 'posted_templates', 'posted_users'];

    /** Test hooks are constructor-only and cannot be supplied in a JSON request. */
    public function __construct(private readonly mixed $transport = null, private readonly mixed $schema = null,
        private readonly ?array $installedConfig = null)
    {
    }

    public function run(array $input): array
    {
        try {
            $result = match ($input['action'] ?? '') {
                'initialize' => $this->initialize($input),
                'check' => $this->check($input),
                'register' => $this->register($input),
                'verify' => $this->verify($input),
                'installation_state' => $this->installationState(),
                default => throw new InstallerApplicationFailure('INVALID_ACTION', 'Неизвестное действие установщика.'),
            };
            return ['ok' => true, 'result' => $result];
        } catch (InstallerApplicationFailure $error) {
            return ['ok' => false, 'error' => ['code' => $error->safeCode, 'message' => $error->getMessage()]];
        } catch (Throwable) {
            return ['ok' => false, 'error' => ['code' => 'APPLICATION_FAILED', 'message' => 'Проверка приложения не завершена. Проверьте локальную установку и повторите.']];
        }
    }

    private function config(): array
    {
        if ($this->installedConfig !== null) return $this->installedConfig;
        $this->runtime();
        try { return PostedConfig::get(); }
        catch (Throwable) { throw new InstallerApplicationFailure('CONFIG_UNAVAILABLE', 'Защищённая конфигурация приложения недоступна.'); }
    }

    /** Read actual installed activation flags without schema or platform calls. */
    private function installationState(): array
    {
        if ($this->installedConfig === null) {
            $path = trim((string) (getenv('POSTED_CONFIG_FILE') ?: '/etc/gitposted/config.php'));
            if (!is_dir(dirname($path)) || !is_readable(dirname($path))) {
                throw new InstallerApplicationFailure('CONFIG_UNAVAILABLE', 'Каталог защищённой конфигурации недоступен.');
            }
            if (is_link($path)) {
                throw new InstallerApplicationFailure('CONFIG_UNAVAILABLE', 'Защищённая конфигурация не должна быть ссылкой.');
            }
            if (!file_exists($path)) {
                return ['configured' => false, 'telegram_enabled' => false, 'max_enabled' => false];
            }
        }
        $config = $this->config();
        return ['configured' => true, 'telegram_enabled' => ($config['telegram_bot_enabled'] ?? false) === true,
            'max_enabled' => ($config['max_bot_enabled'] ?? false) === true];
    }

    private function runtime(): void
    {
        require_once dirname(__DIR__) . '/payload/src/max-bootstrap.php';
    }

    private function platform(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['telegram', 'max'], true)) {
            throw new InstallerApplicationFailure('INVALID_PLATFORM', 'Выберите Telegram или МАКС.');
        }
        return $value;
    }

    private function token(string $platform, mixed $value): string
    {
        if (!is_string($value) || $value !== trim($value) || preg_match('/[\x00-\x20\x7f]/', $value)
            || ($platform === 'telegram' ? preg_match('/^[1-9][0-9]{5,11}:[A-Za-z0-9_-]{30,200}$/D', $value) !== 1
                : strlen($value) < 10 || strlen($value) > 4096)) {
            throw new InstallerApplicationFailure('INVALID_TOKEN', 'Некорректный токен выбранной платформы.');
        }
        return $value;
    }

    private function configuredToken(array $config, string $platform): string
    {
        return $this->token($platform, $config[$platform === 'telegram' ? 'bot_token' : 'max_bot_token'] ?? null);
    }

    private function api(string $platform, string $token, string $method, string $path, array $body = [], array $config = []): array
    {
        try {
            if ($this->transport !== null) {
                $value = ($this->transport)($platform, $token, $method, $path, $body);
            } else {
                $this->runtime();
                if ($platform === 'telegram') {
                    $value = (new PostedTelegram($token))->call($path, $body);
                } else {
                    $ca = (string) ($config['max_ca_file'] ?? dirname(__DIR__) . '/payload/resources/certs/russiantrustedca.pem');
                    $api = new GitPostedMaxApi(['max_bot_token' => $token, 'max_api_base_url' => 'https://platform-api2.max.ru', 'max_ca_file' => $ca]);
                    $value = match ([$method, $path]) {
                        ['GET', '/me'] => $api->me(),
                        ['GET', '/subscriptions'] => $api->subscriptions(),
                        ['POST', '/subscriptions'] => $api->subscribe($body['url'], $body['secret'], $body['update_types']),
                        ['DELETE', '/subscriptions'] => $api->unsubscribe($body['url']),
                        ['PATCH', '/me/commands'] => $api->setCommands($body['commands']),
                        default => throw new LogicException('Unsupported installer API action'),
                    };
                }
            }
            if (!is_array($value) || ($value['ok'] ?? true) === false || ($value['success'] ?? true) === false) {
                throw new RuntimeException('API rejected installer action');
            }
            return $value;
        } catch (Throwable) {
            throw new InstallerApplicationFailure('PLATFORM_API_FAILED', 'Платформа не подтвердила запрос. Проверьте токен, доступ к API и права бота.');
        }
    }

    private function identity(string $platform, string $token, array $config): array
    {
        $response = $this->api($platform, $token, $platform === 'telegram' ? 'POST' : 'GET', $platform === 'telegram' ? 'getMe' : '/me', [], $config);
        $me = $platform === 'telegram' ? ($response['result'] ?? null) : $response;
        if (!is_array($me)) $me = [];
        $id = (string) ($me[$platform === 'telegram' ? 'id' : 'user_id'] ?? '');
        $username = (string) ($me['username'] ?? '');
        $format = $platform === 'telegram' ? '/^[A-Za-z0-9_]{5,32}$/D' : '/^[A-Za-z0-9_]{3,60}$/D';
        if (preg_match('/^[1-9][0-9]{0,63}$/D', $id) !== 1 || preg_match($format, $username) !== 1
            || ($platform === 'telegram' && (($me['is_bot'] ?? false) !== true || explode(':', $token, 2)[0] !== $id))) {
            throw new InstallerApplicationFailure('BOT_IDENTITY_INVALID', 'API не подтвердил личность выбранного бота.');
        }
        $name = (string) ($me['name'] ?? $me['first_name'] ?? $username);
        $name = (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
        return ['id' => $id, 'username' => $username, 'name' => mb_substr($name, 0, 160)];
    }

    /** Raw URLs remain private to this process; never return the API response. */
    private function subscriptions(string $platform, string $token, array $config): array
    {
        $response = $this->api($platform, $token, $platform === 'telegram' ? 'POST' : 'GET', $platform === 'telegram' ? 'getWebhookInfo' : '/subscriptions', [], $config);
        if ($platform === 'telegram') {
            if (!is_array($response['result'] ?? null) || !is_string($response['result']['url'] ?? null)) {
                throw new InstallerApplicationFailure('WEBHOOK_RESPONSE_INVALID', 'API не вернул состояние webhook.');
            }
            return $response['result']['url'] === '' ? [] : [$response['result']];
        }
        $rows = $response['subscriptions'] ?? (array_is_list($response) ? $response : null);
        if (!is_array($rows)) throw new InstallerApplicationFailure('WEBHOOK_RESPONSE_INVALID', 'API не вернул список подписок.');
        foreach ($rows as $row) if (!is_array($row) || !is_string($row['url'] ?? null) || $row['url'] === '') {
            throw new InstallerApplicationFailure('WEBHOOK_RESPONSE_INVALID', 'API вернул неподтверждённую подписку.');
        }
        return array_values($rows);
    }

    private function safeUrls(array $rows): array
    {
        $urls = [];
        foreach ($rows as $row) {
            $parts = parse_url($row['url']);
            if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)) continue;
            // Foreign webhook paths or query strings can contain other services' secrets.
            $path = (string) ($parts['path'] ?? '/');
            if (!in_array($path, ['/', '/webhook.php', '/max-webhook.php'], true)) $path = '/[configured-webhook]';
            $urls[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
        }
        return array_values(array_unique($urls));
    }

    private function check(array $input): array
    {
        $platform = $this->platform($input['platform'] ?? null);
        $token = $this->token($platform, $input['token'] ?? null);
        return ['identity' => $this->identity($platform, $token, []), 'existing_urls' => $this->safeUrls($this->subscriptions($platform, $token, []))];
    }

    private function assertInactive(array $config): void
    {
        if (($config['telegram_bot_enabled'] ?? false) !== false || ($config['max_bot_enabled'] ?? false) !== false) {
            throw new InstallerApplicationFailure('ACTIVATION_ORDER', 'На время установки обе платформы должны быть выключены.');
        }
    }

    private function initialize(array $input): array
    {
        if (!is_array($input['config'] ?? null)) throw new InstallerApplicationFailure('INVALID_CONFIG', 'Не передана конфигурация приложения.');
        $this->assertInactive($input['config']);
        return $this->schema('initialize', $input['config']);
    }

    private function schema(string $action, array $config): array
    {
        try {
            if ($this->schema !== null) return ($this->schema)($action, $config);
            $this->runtime();
            $pdo = PostedDb::pdo($config);
            if ($action === 'initialize') {
                PostedDb::ensureSchema($pdo);
                GitPostedMaxSchema::ensure($pdo);
            }
            PostedDb::assertSchema($pdo);
            $available = $pdo->query('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff(self::TABLES, $available) !== []) throw new RuntimeException('Missing application tables');
            $version = (int) $pdo->query('SELECT schema_version FROM posted_max_schema_meta WHERE id=1')->fetchColumn();
            if ($version < 5) throw new RuntimeException('MAX schema is obsolete');
            return ['table_count' => count(self::TABLES), 'max_schema_version' => $version];
        } catch (Throwable) {
            throw new InstallerApplicationFailure('SCHEMA_FAILED', 'Схема приложения не прошла проверку. Проверьте MySQL и права приложения.');
        }
    }

    private function endpoint(array $config, string $platform): string
    {
        $base = (string) ($config['base_url'] ?? '');
        $parts = parse_url($base);
        if (filter_var($base, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f]/', $base)
            || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true) || str_ends_with($base, '/')) {
            throw new InstallerApplicationFailure('INVALID_BASE_URL', 'Адрес приложения должен быть HTTPS origin без завершающего слеша.');
        }
        return $base . ($platform === 'telegram' ? '/webhook.php' : '/max-webhook.php');
    }

    private function expectedIdentity(array $config, string $platform, array $identity): void
    {
        $expected = ltrim((string) ($config[$platform === 'telegram' ? 'bot_username' : 'max_bot_username'] ?? ''), '@');
        if ($expected === '' || strcasecmp($expected, $identity['username']) !== 0) {
            throw new InstallerApplicationFailure('BOT_IDENTITY_MISMATCH', 'Токен не соответствует сохранённому username бота.');
        }
    }

    private function expectedEvents(string $platform): array
    {
        if ($platform === 'telegram') return self::TG_EVENTS;
        $this->runtime();
        return GitPostedMaxBot::webhookUpdateTypes();
    }

    private function confirmEndpoint(string $platform, array $rows, string $url): void
    {
        $expected = $this->expectedEvents($platform);
        sort($expected, SORT_STRING);
        $matched = false;
        foreach ($rows as $row) {
            if ($row['url'] !== $url) throw new InstallerApplicationFailure('FOREIGN_WEBHOOK', 'У бота настроен webhook другого приложения.');
            $types = $row[$platform === 'telegram' ? 'allowed_updates' : 'update_types'] ?? null;
            if (!is_array($types)) continue;
            $types = array_values(array_unique($types));
            sort($types, SORT_STRING);
            if ($types === $expected) $matched = true;
        }
        if (!$matched) throw new InstallerApplicationFailure('WEBHOOK_NOT_CONFIRMED', 'API не подтвердил точный endpoint и набор событий приложения.');
    }

    private function register(array $input): array
    {
        $config = $this->config();
        $this->assertInactive($config);
        $platform = $this->platform($input['platform'] ?? null);
        if (isset($input['takeover']) && !is_bool($input['takeover'])) throw new InstallerApplicationFailure('INVALID_TAKEOVER', 'Подтверждение смены webhook должно быть логическим значением.');
        $token = $this->configuredToken($config, $platform);
        $url = $this->endpoint($config, $platform);
        $identity = $this->identity($platform, $token, $config);
        $this->expectedIdentity($config, $platform, $identity);
        $rows = $this->subscriptions($platform, $token, $config);
        $foreign = array_values(array_filter($rows, static fn(array $row): bool => $row['url'] !== $url));
        if ($foreign !== [] && ($input['takeover'] ?? false) !== true) {
            throw new InstallerApplicationFailure('TAKEOVER_REQUIRED', 'Подтвердите перенос бота с существующего webhook перед подключением.');
        }
        $secret = $config[$platform === 'telegram' ? 'webhook_secret' : 'max_webhook_secret'] ?? null;
        if (!is_string($secret) || preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret) !== 1) {
            throw new InstallerApplicationFailure('INVALID_WEBHOOK_SECRET', 'Не задан безопасный секрет webhook.');
        }
        $events = $this->expectedEvents($platform);
        if ($platform === 'telegram') {
            $result = $this->api($platform, $token, 'POST', 'setWebhook', ['url' => $url, 'secret_token' => $secret,
                'allowed_updates' => $events, 'max_connections' => 1, 'drop_pending_updates' => false], $config);
            if (($result['result'] ?? null) !== true) throw new InstallerApplicationFailure('WEBHOOK_NOT_CONFIRMED', 'Telegram не подтвердил регистрацию webhook.');
            $this->confirmEndpoint($platform, $this->subscriptions($platform, $token, $config), $url);
            $this->registerCommands($platform, $token, $config);
        } else {
            $this->api($platform, $token, 'POST', '/subscriptions', ['url' => $url, 'secret' => $secret, 'update_types' => $events], $config);
            // Confirm the replacement before removing any operator-approved old URL.
            $confirmed = $this->subscriptions($platform, $token, $config);
            $targetRows = array_values(array_filter($confirmed, static fn(array $row): bool => $row['url'] === $url));
            $this->confirmEndpoint($platform, $targetRows, $url);
            $this->registerCommands($platform, $token, $config);
            foreach (array_unique(array_column($foreign, 'url')) as $oldUrl) {
                $this->api($platform, $token, 'DELETE', '/subscriptions', ['url' => $oldUrl], $config);
            }
        }
        $this->confirmEndpoint($platform, $this->subscriptions($platform, $token, $config), $url);
        return ['platform' => $platform, 'identity' => $identity, 'webhook_registered' => true];
    }

    /** Set the new application's fixed menus only after its webhook is confirmed. */
    private function registerCommands(string $platform, string $token, array $config): void
    {
        if ($platform === 'max') {
            $expected = gitpostedMaxCommands();
            $result = $this->api($platform, $token, 'PATCH', '/me/commands', ['commands' => $expected], $config);
            $actual = $result['commands'] ?? null;
            if (!is_array($actual) || !array_is_list($actual)) {
                throw new InstallerApplicationFailure('COMMANDS_NOT_CONFIRMED', 'МАКС не подтвердил меню команд. Продолжите настройку бота.');
            }
            $normalized = [];
            foreach ($actual as $command) {
                if (!is_array($command) || !is_string($command['name'] ?? null) || !is_string($command['description'] ?? null)) {
                    throw new InstallerApplicationFailure('COMMANDS_NOT_CONFIRMED', 'МАКС не подтвердил меню команд. Продолжите настройку бота.');
                }
                $normalized[] = ['name' => ltrim(trim($command['name']), '/'), 'description' => trim($command['description'])];
            }
            $sort = static fn(array $left, array $right): int => [$left['name'], $left['description']] <=> [$right['name'], $right['description']];
            usort($expected, $sort);
            usort($normalized, $sort);
            if ($normalized !== $expected) {
                throw new InstallerApplicationFailure('COMMANDS_NOT_CONFIRMED', 'МАКС не подтвердил меню команд. Продолжите настройку бота.');
            }
            return;
        }
        foreach (gitpostedTelegramCommandSets() as $language => $commands) {
            $body = ['commands' => $commands];
            if ($language !== 'default') $body['language_code'] = $language;
            $result = $this->api($platform, $token, 'POST', 'setMyCommands', $body, $config);
            if (($result['result'] ?? null) !== true) {
                throw new InstallerApplicationFailure('COMMANDS_NOT_CONFIRMED', 'Telegram не подтвердил меню команд. Продолжите настройку бота.');
            }
        }
        $result = $this->api($platform, $token, 'POST', 'setChatMenuButton', ['menu_button' => ['type' => 'commands']], $config);
        if (($result['result'] ?? null) !== true) {
            throw new InstallerApplicationFailure('COMMANDS_NOT_CONFIRMED', 'Telegram не подтвердил кнопку меню. Продолжите настройку бота.');
        }
    }

    private function verify(array $input): array
    {
        $config = $this->config();
        $result = ['schema' => $this->schema('verify', $config), 'platforms' => []];
        $platforms = isset($input['platform']) ? [$this->platform($input['platform'])] : ($input['platforms'] ?? []);
        if (!is_array($platforms) || !array_is_list($platforms) || count($platforms) > 2) {
            throw new InstallerApplicationFailure('INVALID_PLATFORM', 'Некорректный список платформ для проверки.');
        }
        foreach (array_unique($platforms) as $platform) {
            $platform = $this->platform($platform);
            $token = $this->configuredToken($config, $platform);
            $identity = $this->identity($platform, $token, $config);
            $this->expectedIdentity($config, $platform, $identity);
            $this->confirmEndpoint($platform, $this->subscriptions($platform, $token, $config), $this->endpoint($config, $platform));
            $result['platforms'][$platform] = ['identity' => $identity, 'webhook_confirmed' => true];
        }
        return $result;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException('Installer application error', 0, $severity, $file, $line);
    });
    try {
        $raw = stream_get_contents(STDIN, 65537);
        if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('Invalid input');
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new RuntimeException('Invalid input');
        $result = (new InstallerApplication())->run($input);
    } catch (Throwable) {
        $result = ['ok' => false, 'error' => ['code' => 'INVALID_INPUT', 'message' => 'Некорректное задание установщика.']];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
