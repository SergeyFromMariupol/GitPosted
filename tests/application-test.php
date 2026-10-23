<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bin/application.php';
require dirname(__DIR__) . '/payload/src/admin-auth-policy.php';

function applicationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function applicationConfig(string $selection = 'both'): array
{
    return ['telegram_bot_enabled' => false, 'max_bot_enabled' => false, 'base_url' => 'https://selfhost.example',
        'bot_token' => $selection === 'max' || $selection === 'neither' ? '' : '123456:' . str_repeat('a', 32),
        'bot_username' => 'LocalTelegramBot', 'max_bot_token' => $selection === 'telegram' || $selection === 'neither' ? '' : 'fixture-max-token',
        'max_bot_username' => 'LocalMaxBot', 'webhook_secret' => str_repeat('a', 64), 'max_webhook_secret' => str_repeat('b', 64)];
}

/** No real SDK calls: only this fake API can execute in these tests. */
function applicationFixture(array $config, array &$calls, array &$state, ?callable $schema = null): InstallerApplication
{
    $transport = static function (string $platform, string $token, string $method, string $path, array $body) use (&$calls, &$state): array {
        $calls[] = compact('platform', 'method', 'path', 'body');
        if (($state['throw'] ?? '') === $path) throw new RuntimeException('Raw private error: ' . $token);
        if ($path === 'getMe') return ['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'LocalTelegramBot', 'first_name' => 'Local Telegram']];
        if ($path === '/me') return ['user_id' => 987654, 'username' => 'LocalMaxBot', 'name' => 'Local MAX'];
        if ($path === 'getWebhookInfo') return ['ok' => true, 'result' => $state['telegram'] ?? ['url' => '']];
        if ($path === 'setWebhook') {
            $state['telegram'] = ['url' => $body['url'], 'allowed_updates' => $body['allowed_updates']];
            return ['ok' => true, 'result' => true];
        }
        if ($path === '/subscriptions' && $method === 'GET') return ['subscriptions' => $state['max'] ?? []];
        if ($path === '/subscriptions' && $method === 'POST') {
            if (empty($state['reject_confirmation'])) {
                $state['max'][] = ['url' => $body['url'], 'update_types' => $body['update_types']];
            }
            return ['success' => true];
        }
        if ($path === '/subscriptions' && $method === 'DELETE') {
            $state['max'] = array_values(array_filter($state['max'], static fn(array $row): bool => $row['url'] !== $body['url']));
            return ['success' => true];
        }
        throw new RuntimeException('Unexpected API operation');
    };
    return new InstallerApplication($transport, $schema ?? static fn(string $action, array $value): array => ['table_count' => 33, 'max_schema_version' => 5], $config);
}

$tests = [];
$tests['check is read-only and redacts foreign webhook credentials'] = static function (): void {
    $calls = []; $state = ['telegram' => ['url' => 'https://other.example/secret-token?key=private']];
    $app = applicationFixture(applicationConfig(), $calls, $state);
    $checked = $app->run(['action' => 'check', 'platform' => 'telegram', 'token' => applicationConfig()['bot_token']]);
    applicationAssert($checked['ok'], 'Valid Telegram check failed');
    applicationAssert($checked['result']['identity'] === ['id' => '123456', 'username' => 'LocalTelegramBot', 'name' => 'Local Telegram'], 'Identity not normalized');
    applicationAssert($checked['result']['existing_urls'] === ['https://other.example/[configured-webhook]'], 'Foreign secret was disclosed');
    applicationAssert(array_column($calls, 'path') === ['getMe', 'getWebhookInfo'], 'Check changed platform settings');
};
$tests['Telegram-only registers and verifies while MAX token is empty and flags are false'] = static function (): void {
    $calls = []; $state = []; $app = applicationFixture(applicationConfig('telegram'), $calls, $state);
    applicationAssert($app->run(['action' => 'register', 'platform' => 'telegram'])['ok'], 'Telegram-only registration failed');
    applicationAssert($app->run(['action' => 'verify', 'platform' => 'telegram'])['ok'], 'Disabled pending Telegram could not be verified');
    applicationAssert(array_unique(array_column($calls, 'platform')) === ['telegram'], 'Omitted MAX was contacted');
    $set = array_values(array_filter($calls, static fn(array $row): bool => $row['path'] === 'setWebhook'));
    applicationAssert(count($set) === 1 && $set[0]['body']['drop_pending_updates'] === false, 'Unexpected Telegram mutation');
    applicationAssert($set[0]['body']['url'] === 'https://selfhost.example/webhook.php', 'Wrong Telegram endpoint');
};
$tests['MAX-only first attach needs no old MAX or Telegram credentials and never changes commands'] = static function (): void {
    $calls = []; $state = []; $app = applicationFixture(applicationConfig('max'), $calls, $state);
    applicationAssert($app->run(['action' => 'register', 'platform' => 'max'])['ok'], 'MAX first attachment failed');
    applicationAssert($app->run(['action' => 'verify', 'platform' => 'max'])['ok'], 'Pending MAX verification failed');
    applicationAssert(array_unique(array_column($calls, 'platform')) === ['max'], 'Omitted Telegram was contacted');
    foreach ($calls as $call) applicationAssert(in_array($call['path'], ['/me', '/subscriptions'], true), 'Unexpected branding or message API call');
};
$tests['neither platform initializes the full schema and verifies without network calls'] = static function (): void {
    $calls = []; $state = []; $schemaCalls = [];
    $schema = static function (string $action, array $value) use (&$schemaCalls): array {
        $schemaCalls[] = $action;
        applicationAssert(($value['telegram_bot_enabled'] ?? null) === false && ($value['max_bot_enabled'] ?? null) === false, 'Activation escaped staging');
        return ['table_count' => 33, 'max_schema_version' => 5];
    };
    $config = applicationConfig('neither'); $app = applicationFixture($config, $calls, $state, $schema);
    applicationAssert($app->run(['action' => 'initialize', 'config' => $config])['ok'], 'Offline initialization required bot credentials');
    applicationAssert($app->run(['action' => 'verify', 'platforms' => []])['ok'], 'Site-only verification failed');
    applicationAssert($schemaCalls === ['initialize', 'verify'] && $calls === [], 'Site-only initialization contacted a platform');
};
$tests['foreign endpoint requires takeover before any mutation and MAX confirms replacement before deletion'] = static function (): void {
    $calls = []; $state = ['max' => [['url' => 'https://old.example/private', 'update_types' => []]]];
    $app = applicationFixture(applicationConfig('max'), $calls, $state);
    $blocked = $app->run(['action' => 'register', 'platform' => 'max']);
    applicationAssert(($blocked['error']['code'] ?? '') === 'TAKEOVER_REQUIRED', 'Foreign MAX endpoint was replaced without consent');
    applicationAssert(count(array_filter($calls, static fn(array $row): bool => $row['method'] !== 'GET')) === 0, 'Blocked registration mutated API');
    $calls = [];
    applicationAssert($app->run(['action' => 'register', 'platform' => 'max', 'takeover' => true])['ok'], 'Confirmed takeover failed');
    $sequence = array_map(static fn(array $row): string => $row['method'] . ' ' . $row['path'], $calls);
    applicationAssert($sequence === ['GET /me', 'GET /subscriptions', 'POST /subscriptions', 'GET /subscriptions', 'DELETE /subscriptions', 'GET /subscriptions'], 'Replacement was not confirmed before deletion');
};
$tests['unconfirmed replacement keeps the previous subscription'] = static function (): void {
    $calls = []; $state = ['max' => [['url' => 'https://old.example/private', 'update_types' => []]], 'reject_confirmation' => true];
    $app = applicationFixture(applicationConfig('max'), $calls, $state);
    $result = $app->run(['action' => 'register', 'platform' => 'max', 'takeover' => true]);
    applicationAssert(!$result['ok'], 'Unconfirmed replacement accepted');
    applicationAssert(count(array_filter($calls, static fn(array $row): bool => $row['method'] === 'DELETE')) === 0, 'Old subscription was deleted before replacement confirmation');
};
$tests['verification rejects wrong identity endpoint or event set without writes'] = static function (): void {
    foreach (['identity', 'endpoint', 'events'] as $case) {
        $config = applicationConfig('telegram');
        if ($case === 'identity') $config['bot_username'] = 'WrongLocalBot';
        $calls = []; $state = ['telegram' => ['url' => $case === 'endpoint' ? 'https://foreign.example/webhook.php' : 'https://selfhost.example/webhook.php', 'allowed_updates' => ['message']]];
        $app = applicationFixture($config, $calls, $state);
        applicationAssert(!$app->run(['action' => 'verify', 'platform' => 'telegram'])['ok'], 'Incorrect platform state accepted: ' . $case);
        applicationAssert(!in_array('setWebhook', array_column($calls, 'path'), true), 'Verify changed webhook');
    }
};
$tests['activation and arbitrary base URL fail before mutations'] = static function (): void {
    foreach (['enabled', 'userinfo', 'query', 'http'] as $case) {
        $config = applicationConfig('telegram');
        if ($case === 'enabled') $config['max_bot_enabled'] = true;
        else $config['base_url'] = match ($case) { 'userinfo' => 'https://username@selfhost.example', 'query' => 'https://selfhost.example?secret=1', default => 'http://selfhost.example' };
        $calls = []; $state = []; $app = applicationFixture($config, $calls, $state);
        applicationAssert(!$app->run(['action' => 'register', 'platform' => 'telegram'])['ok'], 'Unsafe registration accepted');
        applicationAssert($calls === [], 'Unsafe registration contacted a platform');
    }
};
$tests['API failures and invalid inputs never disclose credentials or exceptions'] = static function (): void {
    $config = applicationConfig(); $calls = []; $state = ['throw' => 'getMe'];
    $app = applicationFixture($config, $calls, $state);
    $failed = $app->run(['action' => 'check', 'platform' => 'telegram', 'token' => $config['bot_token']]);
    applicationAssert(($failed['error']['code'] ?? '') === 'PLATFORM_API_FAILED', 'API failure was not sanitized');
    $encoded = json_encode($failed);
    applicationAssert(!str_contains($encoded, $config['bot_token']) && !str_contains($encoded, 'Raw private'), 'Raw secret leaked');
    foreach ([['action' => 'sendMessage'], ['action' => 'check', 'platform' => 'telegram', 'token' => ''], ['action' => 'check', 'platform' => 'other', 'token' => 'anything']] as $input) {
        $calls = [];
        applicationAssert(!$app->run($input)['ok'] && $calls === [], 'Invalid action reached API');
    }
};
$tests['CAPTCHA is optional only with explicit false while password validation stays mandatory'] = static function (): void {
    $auth = ['username' => 'operator', 'password_hash' => password_hash('long-fixture-password', PASSWORD_DEFAULT)];
    foreach ([[], ['enabled' => true], ['enabled' => 'false'], ['enabled' => 0]] as $settings) applicationAssert(gitpostedAdminCaptchaRequired($settings), 'Malformed CAPTCHA policy bypassed verification');
    applicationAssert(!gitpostedAdminCaptchaRequired(['enabled' => false]), 'Explicitly disabled CAPTCHA still required');
    applicationAssert(gitpostedAdminPasswordValid($auth, 'operator', 'long-fixture-password', false, false), 'Password-only login failed');
    applicationAssert(!gitpostedAdminPasswordValid($auth, 'operator', 'wrong', true, false), 'Disabled CAPTCHA bypassed password');
    applicationAssert(!gitpostedAdminPasswordValid($auth, 'operator', 'long-fixture-password', false, true), 'Required CAPTCHA bypassed');
    applicationAssert(!gitpostedAdminPasswordValid($auth, 'wrong', 'long-fixture-password', true, false), 'Wrong administrator accepted');
};
$tests['first attach never constructs a retired client from an empty token'] = static function (): void {
    $created = [];
    $factory = static function (array $config) use (&$created): object { $created[] = $config; return (object) ['retired' => true]; };
    applicationAssert(gitpostedAdminPreviousClient(['max_bot_token' => ''], 'max_bot_token', $factory) === null && $created === [], 'Empty retired MAX client was constructed');
    applicationAssert(gitpostedAdminPreviousClient([], 'bot_token', $factory) === null && $created === [], 'Empty retired Telegram client was constructed');
    applicationAssert(gitpostedAdminPreviousClient(['max_bot_token' => 'old-fixture'], 'max_bot_token', $factory) !== null && count($created) === 1, 'Existing retired client was skipped');
};

$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); ++$passed; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo 'application tests passed: ', $passed, PHP_EOL;
