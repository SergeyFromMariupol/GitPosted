<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bot-commands.php';
require_once __DIR__ . '/setup-policy.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

try {
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
    if (preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $webhookSecret) !== 1) {
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

    $options = getopt('', ['takeover']);
    $me = $telegram->call('getMe');
    $actualUsername = (string) ($me['result']['username'] ?? '');
    if ($expectedUsername === '' || strcasecmp($actualUsername, $expectedUsername) !== 0) {
        throw new RuntimeException('Configured bot username does not match the Bot API token.');
    }
    $webhookInfo = $telegram->call('getWebhookInfo');
    if (!is_array($webhookInfo['result'] ?? null) || !is_string($webhookInfo['result']['url'] ?? null)) {
        throw new GitPostedSetupPolicyFailure('Telegram не подтвердил состояние webhook. Настройка не изменена.');
    }
    gitpostedSetupRequireTakeover(
        $webhookInfo['result']['url'] === '' ? [] : [$webhookInfo['result']],
        $baseUrl . '/webhook.php',
        array_key_exists('takeover', $options)
    );
    PostedDb::ensureSchema(PostedDb::pdo($config));
    posted_app();
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
    $commandSets = gitpostedTelegramCommandSets();
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
    $expectedUpdates = ['message', 'channel_post', 'edited_channel_post', 'callback_query', 'my_chat_member', 'chat_member'];
    $webhook = $telegram->call('setWebhook', [
        'url' => $baseUrl . '/webhook.php',
        'secret_token' => $webhookSecret,
        'allowed_updates' => $expectedUpdates,
        'max_connections' => 1,
        'drop_pending_updates' => false,
    ]);
    foreach (array_merge([$name, $description, $shortDescription, $menu, $webhook], array_values($commands)) as $acknowledgement) {
        if (($acknowledgement['result'] ?? null) !== true) {
            throw new GitPostedSetupPolicyFailure('Telegram не подтвердил настройку. Проверьте состояние перед повторной командой.');
        }
    }
    $confirmed = $telegram->call('getWebhookInfo');
    $confirmedTypes = $confirmed['result']['allowed_updates'] ?? null;
    if (($confirmed['result']['url'] ?? null) !== $baseUrl . '/webhook.php' || !is_array($confirmedTypes)
        || array_filter($confirmedTypes, static fn(mixed $type): bool => !is_string($type)) !== []) {
        throw new GitPostedSetupPolicyFailure('Telegram не подтвердил URL и события webhook. Проверьте состояние перед повторной командой.');
    }
    $confirmedTypes = array_values(array_unique($confirmedTypes));
    sort($confirmedTypes, SORT_STRING);
    sort($expectedUpdates, SORT_STRING);
    if ($confirmedTypes !== $expectedUpdates) {
        throw new GitPostedSetupPolicyFailure('Telegram не подтвердил URL и события webhook. Проверьте состояние перед повторной командой.');
    }
    echo json_encode([
        'ok' => true,
        'username' => $actualUsername,
        'profile_configured' => true,
        'command_languages' => array_keys($commandSets),
        'command_count' => count($commandSets['default']),
        'menu_configured' => true,
        'webhook_registered' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => gitpostedSetupSafeError($exception)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(1);
}
