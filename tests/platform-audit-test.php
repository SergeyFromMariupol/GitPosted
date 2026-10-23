<?php

declare(strict_types=1);

/** Offline: extract declarations only; never execute the admin entry point. */
$source = file_get_contents(dirname(__DIR__) . '/payload/src/control/index.php');
$wanted = ['adminTelegramWebhookSnapshot', 'adminTelegramWebhookIsForeign', 'adminAssertTelegramWebhookAccess',
    'adminTelegramWebhookState', 'adminToggleTelegram', 'adminConfigureTelegram', 'adminBotUsername',
    'adminSecretInput', 'adminMaxTokenInput', 'adminMaxSubscriptionRows', 'adminAssertMaxWebhookAccess',
    'adminMaxSubscriptionCount', 'adminMaxSubscriptionIsCurrent', 'adminMaxCurrentSubscriptionCount',
    'adminCreateAndConfirmMaxSubscription', 'adminRemoveAcknowledgedMaxSubscriptions', 'adminRemoveAndConfirmMaxSubscription'];
$tokens = token_get_all($source);
$loaded = [];
for ($i = 0, $length = count($tokens); $i < $length; ++$i) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $name = '';
    for ($j = $i + 1; $j < $length; ++$j) {
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $name = $tokens[$j][1];
        break;
    }
    if (!in_array($name, $wanted, true)) continue;
    $code = ''; $depth = 0; $opened = false;
    for ($j = $i; $j < $length; ++$j) {
        $token = $tokens[$j];
        $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $opened = true; }
        elseif ($token === '}') --$depth;
        if ($opened && $depth === 0) break;
    }
    eval($code);
    $loaded[] = $name;
}
if (array_diff($wanted, $loaded) !== []) throw new RuntimeException('Admin declarations were not extracted');

final class PostedTelegram
{
    public static mixed $transport;
    public function __construct(private readonly string $token) {}
    public function call(string $method, array $body = []): array { return (self::$transport)($this->token, $method, $body); }
}
final class GitPostedMaxBot
{
    public static array $runtimeRows = [];
    public static array $runtimeCalls = [];
    public array $calls = [];
    public array $rows = [];
    private array $config = [];
    public bool $confirm = true;
    public bool $deleteFails = false;
    public function __construct(mixed $rows = [], mixed $api = null, ?array $config = null) {
        if ($config === null) $this->rows = $rows;
        else { $this->rows =& self::$runtimeRows; $this->calls =& self::$runtimeCalls; $this->config = $config; }
    }
    public static function webhookUpdateTypes(): array { return ['message_created', 'bot_started']; }
    public function subscriptions(): array { $this->calls[] = ['GET']; return ['subscriptions' => $this->rows]; }
    public function setupWebhook(string $url): array {
        $this->calls[] = ['POST', $url, $this->config['max_webhook_secret'] ?? ''];
        if ($this->confirm) {
            $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['url'] !== $url));
            $this->rows[] = ['url' => $url, 'update_types' => self::webhookUpdateTypes()];
        }
        return ['success' => true];
    }
    public function removeSubscription(string $url): array {
        $this->calls[] = ['DELETE', $url];
        if ($this->deleteFails) throw new RuntimeException('Captured cleanup failure');
        $this->rows = array_values(array_filter($this->rows, static fn(array $row): bool => $row['url'] !== $url));
        return ['success' => true];
    }
    public function setCommands(): array { $this->calls[] = ['COMMANDS']; return ['success' => true]; }
}
final class GitPostedMaxApi
{
    public function __construct(array $config) {}
    public function me(): array { return ['user_id' => '222', 'username' => 'LocalMaxBot']; }
}
final class PlatformAuditRedirect extends RuntimeException {}
function adminWriteConfig(string $path, array $config, string $directory): void { $GLOBALS['platform_audit_writes'][] = $config; }
function adminPage(mixed $page): string { return 'max'; }
function go(string $message, string $page): never { throw new PlatformAuditRedirect($message); }
require dirname(__DIR__) . '/payload/src/admin-auth-policy.php';
function platformAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function platformThrows(callable $operation): Throwable {
    try { $operation(); } catch (Throwable $error) { return $error; }
    throw new RuntimeException('Expected operation to fail');
}
function platformConfig(string $token = ''): array {
    return ['base_url' => 'https://selfhost.example', 'bot_token' => $token, 'telegram_bot_enabled' => false, 'webhook_secret' => str_repeat('x', 64)];
}
function platformTelegramFixture(array &$calls, array &$states, string $failure = ''): void {
    PostedTelegram::$transport = static function (string $token, string $method, array $body) use (&$calls, &$states, $failure): array {
        $calls[] = [$token, $method, $body];
        if ($method === 'getMe') return ['ok' => true, 'result' => ['id' => $token === 'old-fixture' ? 111 : 222]];
        if ($method === 'getWebhookInfo') return ['ok' => true, 'result' => $states[$token] ?? ['url' => '']];
        if ($method === 'setWebhook') {
            $states[$token] = ['url' => $body['url'], 'allowed_updates' => $failure === 'events' ? ['message'] : $body['allowed_updates']];
            if ($failure === 'uncertain') throw new RuntimeException('Captured timeout after request');
            return ['ok' => true, 'result' => true];
        }
        if ($method === 'deleteWebhook') { $states[$token] = ['url' => '']; return ['ok' => true, 'result' => true]; }
        throw new RuntimeException('Unexpected captured method');
    };
}

$tests = [];
$tests['platform usernames use MAX sixty and Telegram thirty-two character limits'] = static function (): void {
    foreach ([3, 32, 60] as $size) {
        $name = str_repeat('a', $size - 3) . 'bot';
        platformAssert(adminBotUsername($name, 'MAX', 'max') === $name, 'Valid MAX username rejected');
    }
    platformThrows(static fn() => adminBotUsername(str_repeat('a', 58) . 'bot', 'MAX', 'max'));
    platformAssert(strlen(adminBotUsername(str_repeat('a', 29) . 'bot', 'TG')) === 32, 'Telegram boundary rejected');
    platformThrows(static fn() => adminBotUsername(str_repeat('a', 30) . 'bot', 'TG'));
    platformThrows(static fn() => adminBotUsername('ordinary_name', 'MAX', 'max'));
};
$tests['opaque MAX tokens and webhook secrets have separate validation'] = static function (): void {
    foreach (['fixture.token', str_repeat('a', 4096), ''] as $token) platformAssert(adminMaxTokenInput($token) === $token, 'Opaque MAX token rejected');
    foreach (['short', ' token-value', "token\nvalue", str_repeat('a', 4097)] as $token) platformThrows(static fn() => adminMaxTokenInput($token));
    platformAssert(adminSecretInput(str_repeat('a', 32), 'Webhook') === str_repeat('a', 32), 'Valid secret rejected');
    platformThrows(static fn() => adminSecretInput(str_repeat(':', 32), 'Webhook'));
};
$tests['Telegram first attach confirms endpoint without constructing an empty retired client'] = static function (): void {
    $calls = []; $states = []; $writes = [];
    platformTelegramFixture($calls, $states);
    $next = platformConfig('new-fixture');
    adminConfigureTelegram(new PostedTelegram('new-fixture'), platformConfig(), $next, '222', static function (array $value) use (&$writes): void { $writes[] = $value; });
    platformAssert(count($writes) === 1 && $writes[0]['telegram_bot_enabled'] === true, 'First attach did not persist enabled config');
    platformAssert(array_unique(array_column($calls, 0)) === ['new-fixture'], 'Empty or retired token contacted');
    platformAssert(count(array_filter($calls, static fn(array $call): bool => $call[1] === 'setWebhook')) === 1, 'First attach mutation count incorrect');
};
$tests['Telegram foreign webhook is rejected before persistence or API writes'] = static function (): void {
    $calls = []; $states = ['new-fixture' => ['url' => 'https://foreign.example/private?secret=hidden']]; $writes = [];
    platformTelegramFixture($calls, $states);
    $error = platformThrows(static function () use (&$writes): void {
        adminConfigureTelegram(new PostedTelegram('new-fixture'), platformConfig(), platformConfig('new-fixture'), '222', static function (array $value) use (&$writes): void { $writes[] = $value; });
    });
    platformAssert($writes === [] && array_column($calls, 1) === ['getWebhookInfo'], 'Blocked first attach mutated state');
    platformAssert(!str_contains($error->getMessage(), 'hidden'), 'Foreign webhook secret disclosed');
};
$tests['Telegram toggle cannot overwrite or delete a foreign webhook'] = static function (): void {
    foreach ([false, true] as $enabled) {
        $calls = []; $states = ['new-fixture' => ['url' => 'https://foreign.example/private']]; $writes = [];
        platformTelegramFixture($calls, $states);
        platformThrows(static function () use (&$writes, $enabled): void { adminToggleTelegram(new PostedTelegram('new-fixture'), platformConfig('new-fixture'), $enabled, static function (array $value) use (&$writes): void { $writes[] = $value; }); });
        platformAssert($writes === [] && array_column($calls, 1) === ['getWebhookInfo'], 'Blocked toggle mutated state');
    }
};
$tests['Telegram explicit takeover confirms its target and preserves uncertain external state'] = static function (): void {
    foreach (['', 'uncertain'] as $failure) {
        $calls = []; $states = ['new-fixture' => ['url' => 'https://foreign.example/private']]; $writes = [];
        platformTelegramFixture($calls, $states, $failure);
        $operation = static function () use (&$writes): void { adminConfigureTelegram(new PostedTelegram('new-fixture'), platformConfig(), platformConfig('new-fixture'), '222', static function (array $value) use (&$writes): void { $writes[] = $value; }, true); };
        if ($failure === '') $operation(); else platformThrows($operation);
        platformAssert(!in_array('deleteWebhook', array_column($calls, 1), true), 'Takeover failure deleted an uncertain webhook');
        platformAssert(count(array_filter($calls, static fn(array $call): bool => $call[1] === 'setWebhook')) === 1, 'Unexpected takeover mutation count');
        platformAssert($failure === '' ? $writes[0]['telegram_bot_enabled'] === true : $writes[array_key_last($writes)]['bot_token'] === '', 'Config outcome incorrect');
    }
};
$tests['Telegram rejects incomplete confirmed events and leaves a foreign retired bot alone'] = static function (): void {
    $calls = []; $states = []; $writes = [];
    platformTelegramFixture($calls, $states, 'events');
    platformThrows(static function () use (&$writes): void { adminConfigureTelegram(new PostedTelegram('new-fixture'), platformConfig(), platformConfig('new-fixture'), '222', static function (array $value) use (&$writes): void { $writes[] = $value; }); });
    platformAssert($writes[array_key_last($writes)]['bot_token'] === '', 'Unconfirmed endpoint left config active');
    $calls = []; $states = ['old-fixture' => ['url' => 'https://foreign.example/private']]; $writes = [];
    platformTelegramFixture($calls, $states);
    $warning = adminConfigureTelegram(new PostedTelegram('new-fixture'), platformConfig('old-fixture'), platformConfig('new-fixture'), '222', static function (array $value) use (&$writes): void { $writes[] = $value; });
    platformAssert($warning !== '' && !in_array('deleteWebhook', array_column($calls, 1), true), 'Retired foreign bot webhook was removed');
};
$tests['MAX foreign registration is rejected before POST and malformed rows fail closed'] = static function (): void {
    foreach ([[['url' => 'https://foreign.example/private']], [null], [['url' => '']]] as $rows) {
        $app = new GitPostedMaxBot($rows);
        platformThrows(static fn() => adminCreateAndConfirmMaxSubscription($app, 'https://selfhost.example/max-webhook.php'));
        platformAssert(array_column($app->calls, 0) === ['GET'], 'Blocked MAX registration mutated API');
    }
};
$tests['MAX takeover confirms the target before deleting only acknowledged foreign URLs'] = static function (): void {
    $url = 'https://selfhost.example/max-webhook.php'; $old = 'https://foreign.example/private';
    $app = new GitPostedMaxBot([['url' => $old]]);
    $foreign = adminAssertMaxWebhookAccess($app, $url, true);
    adminCreateAndConfirmMaxSubscription($app, $url, true);
    $app->rows[] = ['url' => 'https://later.example/private'];
    adminRemoveAcknowledgedMaxSubscriptions($app, $url, $foreign);
    platformAssert(array_values(array_filter($app->calls, static fn(array $call): bool => $call[0] === 'DELETE')) === [['DELETE', $old]], 'Unacknowledged subscription removed');
    platformAssert(adminMaxCurrentSubscriptionCount($app, $url) === 1, 'New MAX subscription was lost');
    $methods = array_column($app->calls, 0);
    platformAssert(array_search('POST', $methods, true) < array_search('DELETE', $methods, true), 'MAX removed old endpoint before replacement');
};
$tests['MAX unconfirmed target and failed cleanup keep the previous endpoint or working replacement'] = static function (): void {
    $url = 'https://selfhost.example/max-webhook.php'; $old = 'https://foreign.example/private';
    $app = new GitPostedMaxBot([['url' => $old]]); $app->confirm = false;
    platformThrows(static fn() => adminCreateAndConfirmMaxSubscription($app, $url, true));
    platformThrows(static fn() => adminRemoveAcknowledgedMaxSubscriptions($app, $url, [$old]));
    platformAssert(!in_array('DELETE', array_column($app->calls, 0), true), 'Unconfirmed MAX target deleted old endpoint');
    $app->confirm = true; adminCreateAndConfirmMaxSubscription($app, $url, true); $app->deleteFails = true;
    platformThrows(static fn() => adminRemoveAcknowledgedMaxSubscriptions($app, $url, [$old]));
    platformAssert(adminMaxCurrentSubscriptionCount($app, $url) === 1, 'Cleanup failure removed working replacement');
};
$tests['MAX token rotation registers and persists the same retained secret in the actual admin action'] = static function () use ($source): void {
    $start = strpos($source, "        if (\$action === 'max_config') {");
    $end = strpos($source, "        if (\$action === 'account_link') {", $start);
    if ($start === false || $end === false) throw new RuntimeException('MAX admin action unavailable');
    $actionCode = str_replace("require_once dirname(__DIR__) . '/max-bootstrap.php';", '', substr($source, $start, $end - $start));
    $action = 'max_config'; $baseUrl = 'https://selfhost.example'; $pdo = null;
    $appConfigPath = '/captured/config.php'; $dataDirectory = '/captured';
    $config = ['max_bot_token' => 'old-fixture-token', 'max_bot_username' => 'LocalMaxBot', 'max_webhook_secret' => str_repeat('s', 64), 'max_bot_enabled' => true];
    $oldPost = $_POST;
    $_POST = ['max_bot_username' => 'LocalMaxBot', 'max_api_base_url' => 'https://platform-api2.max.ru', 'max_bot_token' => 'new-fixture-token'];
    GitPostedMaxBot::$runtimeRows = [['url' => $baseUrl . '/max-webhook.php', 'update_types' => GitPostedMaxBot::webhookUpdateTypes()]];
    GitPostedMaxBot::$runtimeCalls = []; $GLOBALS['platform_audit_writes'] = [];
    try {
        try { eval($actionCode); throw new RuntimeException('MAX action did not finish'); }
        catch (PlatformAuditRedirect) {}
        $writes = $GLOBALS['platform_audit_writes'];
        $posts = array_values(array_filter(GitPostedMaxBot::$runtimeCalls, static fn(array $call): bool => $call[0] === 'POST'));
        platformAssert(count($writes) === 1 && count($posts) === 1, 'Unexpected MAX action side effects');
        platformAssert($posts[0][2] === $config['max_webhook_secret'] && $writes[0]['max_webhook_secret'] === $posts[0][2], 'MAX registered a different secret from persisted config');
        platformAssert($writes[0]['max_bot_token'] === 'new-fixture-token' && $writes[0]['max_bot_enabled'] === true, 'MAX replacement did not activate');
    } finally { $_POST = $oldPost; unset($GLOBALS['platform_audit_writes']); }
};

$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); ++$passed; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo 'platform audit tests passed: ', $passed, PHP_EOL;
