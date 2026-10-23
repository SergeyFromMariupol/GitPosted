<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$config = PostedConfig::get();
if (($config['telegram_bot_enabled'] ?? false) !== true) {
    echo json_encode(['ok' => true, 'disabled' => true]) . PHP_EOL;
    exit;
}
$botToken = (string) ($config['bot_token'] ?? '');
if (preg_match('/\A[0-9]{6,12}:[A-Za-z0-9_-]{30,200}\z/D', $botToken) !== 1) {
    throw new RuntimeException('Configured Telegram Bot API token is invalid.');
}
$webhookSecret = (string) ($config['webhook_secret'] ?? '');
if (preg_match('/\A[A-Za-z0-9:_-]{32,256}\z/D', $webhookSecret) !== 1) {
    throw new RuntimeException('Configured Telegram webhook secret is invalid.');
}
$telegram = new PostedTelegram($botToken);
$baseUrl = rtrim((string) ($config['base_url'] ?? 'https://example.com'), '/');
if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
    throw new RuntimeException('Configured base_url must be an HTTPS URL.');
}
$brandName = 'GitPosted';
$expectedUsername = ltrim((string) ($config['bot_username'] ?? ''), '@');
$supportUsername = ltrim(trim((string) ($config['service_admin_username'] ?? '')), '@');
if ($supportUsername !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $supportUsername) !== 1) {
    throw new RuntimeException('Configured support username is invalid.');
}

try {
    PostedDb::ensureSchema(PostedDb::pdo($config));
    posted_app();
    $me = $telegram->call('getMe');
    $actualUsername = (string) ($me['result']['username'] ?? '');
    if ($expectedUsername === '' || strcasecmp($actualUsername, $expectedUsername) !== 0) {
        throw new RuntimeException('Configured bot username does not match the Bot API token.');
    }
    $name = $telegram->call('setMyName', [
        'name' => $brandName,
    ]);
    $description = $telegram->call('setMyDescription', [
        'description' => $brandName . " - сервис для отложенного постинга в Telegram.\n\n"
            . 'Создавайте, редактируйте и откладывайте посты с анимированными эмодзи.',
    ]);
    $shortDescription = $telegram->call('setMyShortDescription', [
        'short_description' => 'Создавайте посты с анимированными эмодзи с помощью ' . $brandName
            . ($supportUsername !== '' ? ".\n\nПоддержка: @" . $supportUsername : '.'),
    ]);
    $commandSets = [
        'default' => [
            ['command' => 'start', 'description' => 'Перезапустить бота'],
            ['command' => 'create', 'description' => 'Создать пост'],
            ['command' => 'help', 'description' => 'Помощь'],
            ['command' => 'lang', 'description' => 'Сменить язык'],
            ['command' => 'edit', 'description' => 'Изменить пост'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблоны'],
            ['command' => 'settings', 'description' => 'Настройки'],
            ['command' => 'catalog', 'description' => 'Каталог эмодзи'],
            ['command' => 'addemoji', 'description' => 'Добавить набор эмодзи'],
            ['command' => 'support', 'description' => 'Поддержка'],
            ['command' => 'privacy', 'description' => 'Политика конфиденциальности'],
            ['command' => 'maxlink', 'description' => 'Связать аккаунт МАКС'],
        ],
        'ru' => [
            ['command' => 'start', 'description' => 'Перезапустить бота'],
            ['command' => 'create', 'description' => 'Создать пост'],
            ['command' => 'help', 'description' => 'Помощь'],
            ['command' => 'lang', 'description' => 'Сменить язык'],
            ['command' => 'edit', 'description' => 'Изменить пост'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблоны'],
            ['command' => 'settings', 'description' => 'Настройки'],
            ['command' => 'catalog', 'description' => 'Каталог эмодзи'],
            ['command' => 'addemoji', 'description' => 'Добавить набор эмодзи'],
            ['command' => 'support', 'description' => 'Поддержка'],
            ['command' => 'privacy', 'description' => 'Политика конфиденциальности'],
            ['command' => 'maxlink', 'description' => 'Связать аккаунт МАКС'],
        ],
        'en' => [
            ['command' => 'start', 'description' => 'Restart bot'],
            ['command' => 'create', 'description' => 'Create post'],
            ['command' => 'help', 'description' => 'Help'],
            ['command' => 'lang', 'description' => 'Change language'],
            ['command' => 'edit', 'description' => 'Edit post'],
            ['command' => 'content', 'description' => 'Content plan'],
            ['command' => 'templates', 'description' => 'Templates'],
            ['command' => 'settings', 'description' => 'Settings'],
            ['command' => 'catalog', 'description' => 'Emoji catalog'],
            ['command' => 'addemoji', 'description' => 'Add emoji set'],
            ['command' => 'support', 'description' => 'Support'],
            ['command' => 'privacy', 'description' => 'Privacy policy'],
            ['command' => 'maxlink', 'description' => 'Link МАКС account'],
        ],
        'uk' => [
            ['command' => 'start', 'description' => 'Перезапустити бота'],
            ['command' => 'create', 'description' => 'Створити допис'],
            ['command' => 'help', 'description' => 'Допомога'],
            ['command' => 'lang', 'description' => 'Змінити мову'],
            ['command' => 'edit', 'description' => 'Змінити допис'],
            ['command' => 'content', 'description' => 'Контент-план'],
            ['command' => 'templates', 'description' => 'Шаблони'],
            ['command' => 'settings', 'description' => 'Налаштування'],
            ['command' => 'catalog', 'description' => 'Каталог емодзі'],
            ['command' => 'addemoji', 'description' => 'Додати набір емодзі'],
            ['command' => 'support', 'description' => 'Підтримка'],
            ['command' => 'privacy', 'description' => 'Політика конфіденційності'],
            ['command' => 'maxlink', 'description' => 'Пов’язати акаунт МАКС'],
        ],
    ];
    $commands = [];
    foreach ($commandSets as $languageCode => $localizedCommands) {
        $payload = ['commands' => $localizedCommands];
        if ($languageCode !== 'default') {
            $payload['language_code'] = $languageCode;
        }
        $commands[$languageCode] = $telegram->call('setMyCommands', $payload);
    }
    $menu = $telegram->call('setChatMenuButton', [
        'menu_button' => ['type' => 'commands'],
    ]);
    $webhook = $telegram->call('setWebhook', [
        'url' => $baseUrl . '/webhook.php',
        'secret_token' => $webhookSecret,
        'allowed_updates' => [
            'message',
            'channel_post',
            'edited_channel_post',
            'callback_query',
            'my_chat_member',
            'chat_member',
        ],
        'max_connections' => 1,
        'drop_pending_updates' => false,
    ]);
    echo json_encode([
        'ok' => true,
        'username' => $actualUsername,
        'name' => $name,
        'description' => $description,
        'short_description' => $shortDescription,
        'commands' => $commands,
        'menu' => $menu,
        'webhook' => $webhook,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => $exception->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(1);
}
