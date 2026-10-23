<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/max-bootstrap.php';

try {
    $config = PostedConfig::get();
    if (($config['max_bot_enabled'] ?? false) !== true) {
        echo json_encode(['ok' => true, 'disabled' => true]) . PHP_EOL;
        exit;
    }
    $options = getopt('', ['url::', 'seed::', 'no-webhook']);
    $baseUrl = rtrim((string) ($config['base_url'] ?? $config['site_url'] ?? 'https://example.com'), '/');
    $webhookUrl = trim((string) ($options['url'] ?? $config['max_webhook_url'] ?? ($baseUrl . '/max-webhook.php')));
    $setupWebhook = !array_key_exists('no-webhook', $options);
    if ($setupWebhook
        && (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($webhookUrl, 'https://'))
    ) {
        throw new InvalidArgumentException('Webhook МАКС должен быть корректным HTTPS URL.');
    }
    $expectedUsername = ltrim(trim((string) ($config['max_bot_username'] ?? '')), '@');
    if (preg_match('/^[A-Za-z0-9_]{3,128}$/D', $expectedUsername) !== 1) {
        throw new RuntimeException('Username бота МАКС не настроен.');
    }

    $app = posted_max_app();
    $botInfo = $app->botInfo();
    $actualUsername = ltrim(trim((string) ($botInfo['username'] ?? '')), '@');
    if ($actualUsername === '' || !hash_equals(strtolower($expectedUsername), strtolower($actualUsername))) {
        throw new RuntimeException(
            'Токен МАКС принадлежит @' . ($actualUsername !== '' ? $actualUsername : 'неизвестно')
            . ', ожидался @' . $expectedUsername . '.'
        );
    }

    $removedSubscriptions = [];
    $subscriptionWarnings = [];
    $webhookResult = ['skipped' => true];
    if ($setupWebhook) {
        // First create/update and confirm the replacement. Existing subscriptions
        // remain intact if validation or the MAX API request fails.
        $webhookResult = $app->setupWebhook($webhookUrl);
        $confirmed = $app->subscriptions();
        $confirmedRows = is_array($confirmed['subscriptions'] ?? null)
            ? $confirmed['subscriptions']
            : (array_is_list($confirmed) ? $confirmed : []);
        $urlCount = 0;
        $exactCount = 0;
        $expectedTypes = array_values(array_unique(GitPostedMaxBot::webhookUpdateTypes()));
        sort($expectedTypes, SORT_STRING);
        foreach ($confirmedRows as $subscription) {
            if (is_array($subscription)
                && hash_equals($webhookUrl, trim((string) ($subscription['url'] ?? '')))
            ) {
                $urlCount++;
                $actualTypes = [];
                if (is_array($subscription['update_types'] ?? null)) {
                    foreach ($subscription['update_types'] as $type) {
                        if (is_string($type) && trim($type) !== '') {
                            $actualTypes[] = trim($type);
                        }
                    }
                }
                $actualTypes = array_values(array_unique($actualTypes));
                sort($actualTypes, SORT_STRING);
                if ($actualTypes === $expectedTypes) {
                    $exactCount++;
                }
            }
        }
        if ($exactCount < 1) {
            throw new RuntimeException(
                'МАКС не подтвердил URL и полный набор событий новой webhook-подписки; старые подписки сохранены.'
            );
        }

        // Removing by URL may remove every equal subscription. Keep duplicate
        // target rows rather than creating a delivery gap.
        if ($urlCount > 1) {
            $subscriptionWarnings[] = 'Обнаружено несколько подписок на целевой URL; они сохранены для непрерывной доставки.';
        }
        foreach ($confirmedRows as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }
            $url = trim((string) ($subscription['url'] ?? ''));
            if ($url === '' || hash_equals($webhookUrl, $url)) {
                continue;
            }
            $app->removeSubscription($url);
            $removedSubscriptions[] = $url;
        }
    }

    $seeds = [];
    $configuredSeeds = $config['max_seed_channels'] ?? [];
    if (is_array($configuredSeeds)) {
        foreach ($configuredSeeds as $seed) {
            if (is_array($seed)) {
                $seeds[] = [
                    'chat_id' => (string) ($seed['chat_id'] ?? ''),
                    'owner_max_id' => (string) ($seed['owner_max_id'] ?? $seed['owner_id'] ?? ''),
                ];
            } elseif (is_string($seed)) {
                $parts = explode(':', $seed, 2);
                $seeds[] = ['chat_id' => $parts[0], 'owner_max_id' => $parts[1] ?? ''];
            }
        }
    }
    $cliSeeds = $options['seed'] ?? [];
    if (!is_array($cliSeeds)) {
        $cliSeeds = [$cliSeeds];
    }
    foreach ($cliSeeds as $seed) {
        $parts = explode(':', (string) $seed, 2);
        $seeds[] = ['chat_id' => $parts[0], 'owner_max_id' => $parts[1] ?? ''];
    }

    $result = [
        'ok' => true,
        'bot' => [
            'user_id' => $botInfo['user_id'] ?? null,
            'username' => $actualUsername,
        ],
        'removed_subscriptions' => $removedSubscriptions,
        'subscription_warnings' => $subscriptionWarnings,
        'webhook' => $webhookResult,
        'commands' => $app->setCommands(),
        'seeded_channels' => [],
    ];
    foreach ($seeds as $seed) {
        $chatId = trim((string) ($seed['chat_id'] ?? ''));
        if ($chatId === '') {
            continue;
        }
        $result['seeded_channels'][] = $app->syncChannelById(
            $chatId,
            trim((string) ($seed['owner_max_id'] ?? ''))
        );
    }
    $result['subscriptions'] = $app->subscriptions();

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Настройка GitPosted в МАКС не выполнена: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
