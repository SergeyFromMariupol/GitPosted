<?php

declare(strict_types=1);

/** Offline captured API transitions; no HTTP, SQL or admin entry point execution. */
require dirname(__DIR__) . '/bin/application.php';
require dirname(__DIR__) . '/payload/src/max-bootstrap.php';

function deepAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function deepConfig(): array {
    return ['base_url' => 'https://selfhost.example', 'bot_token' => '123456:' . str_repeat('a', 32),
        'bot_username' => 'LocalTelegramBot', 'max_bot_token' => 'fixture-max-token', 'max_bot_username' => 'LocalMaxBot',
        'telegram_bot_enabled' => false, 'max_bot_enabled' => false, 'webhook_secret' => str_repeat('t', 64),
        'max_webhook_secret' => str_repeat('m', 64)];
}
function deepFixture(array &$state, array &$calls, ?array $config = null): InstallerApplication {
    $transport = static function (string $platform, string $token, string $method, string $path, array $body) use (&$state, &$calls): array {
        $calls[] = [$platform, $method, $path, $body];
        if ($path === 'getMe') return ['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'LocalTelegramBot']];
        if ($path === '/me') return ['user_id' => 987654, 'username' => 'LocalMaxBot'];
        if ($path === 'getWebhookInfo') return ['ok' => true, 'result' => $state['telegram'] ?? ['url' => '']];
        if ($path === 'setWebhook') {
            if (empty($state['unconfirmed'])) $state['telegram'] = ['url' => $body['url'], 'allowed_updates' => $body['allowed_updates']];
            return ['ok' => true, 'result' => true];
        }
        if ($path === '/subscriptions') {
            if ($method === 'GET') return ['subscriptions' => $state['max'] ?? []];
            if ($method === 'POST') {
                if (empty($state['unconfirmed'])) {
                    $state['max'] = array_values(array_filter($state['max'] ?? [], static fn(array $row): bool => $row['url'] !== $body['url']));
                    $state['max'][] = ['url' => $body['url'], 'update_types' => $body['update_types']];
                }
                return ['success' => true];
            }
            if ($method === 'DELETE') {
                $state['max'] = array_values(array_filter($state['max'] ?? [], static fn(array $row): bool => $row['url'] !== $body['url']));
                return ['success' => true];
            }
        }
        if (in_array($path, ['setMyCommands', 'setChatMenuButton', '/me/commands'], true)) {
            if (!empty($state['commands_throw'])) throw new RuntimeException('Captured private error ' . $token);
            if ($path === '/me/commands') {
                $state['max_commands'] = $body['commands'];
                $confirmed = array_reverse($body['commands']);
                if (!empty($state['bad_commands'])) array_pop($confirmed);
                return ['commands' => $confirmed];
            }
            if ($path === 'setMyCommands') $state['tg_commands'][$body['language_code'] ?? 'default'] = $body['commands'];
            else $state['tg_menu'] = $body['menu_button'];
            return ['ok' => true, 'result' => empty($state['bad_commands'])];
        }
        throw new RuntimeException('Unexpected API mutation');
    };
    return new InstallerApplication($transport, static fn(): array => ['table_count' => 33, 'max_schema_version' => 5], $config ?? deepConfig());
}

// Extract declarations only. A distinct captured client type avoids replacing
// the actual SDK classes which the application fixture loads for event policy.
final class DeepTelegram {
    public static mixed $transport;
    public function __construct(private string $token) {}
    public function call(string $method, array $body = []): array { return (self::$transport)($this->token, $method, $body); }
}
$source = file_get_contents(dirname(__DIR__) . '/payload/src/control/index.php');
$wanted = ['adminTelegramWebhookSnapshot', 'adminTelegramWebhookIsForeign', 'adminAssertTelegramWebhookAccess',
    'adminTelegramWebhookState', 'adminConfigureTelegramCommands', 'adminConfigureTelegram'];
$tokens = token_get_all($source); $loaded = [];
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
        $token = $tokens[$j]; $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $opened = true; }
        elseif ($token === '}') --$depth;
        if ($opened && $depth === 0) break;
    }
    eval(str_replace('PostedTelegram', 'DeepTelegram', $code)); $loaded[] = $name;
}
deepAssert(array_diff($wanted, $loaded) === [], 'Admin declarations missing');
function deepAdminFixture(array &$state, array &$calls): void {
    DeepTelegram::$transport = static function (string $token, string $method, array $body) use (&$state, &$calls): array {
        $calls[] = [$method, $body];
        if ($method === 'getWebhookInfo') return ['ok' => true, 'result' => $state['webhook'] ?? ['url' => '']];
        if ($method === 'setWebhook') { $state['webhook'] = ['url' => $body['url'], 'allowed_updates' => $body['allowed_updates']]; return ['ok' => true, 'result' => true]; }
        if ($method === 'deleteWebhook') { $state['webhook'] = ['url' => '']; return ['ok' => true, 'result' => true]; }
        if (in_array($method, ['setMyCommands', 'setChatMenuButton'], true)) {
            if (!empty($state['commands_throw'])) throw new RuntimeException('Captured private menu failure');
            if ($method === 'setMyCommands') $state['commands'][$body['language_code'] ?? 'default'] = $body['commands'];
            else $state['menu'] = $body['menu_button'];
            return ['ok' => true, 'result' => true];
        }
        throw new RuntimeException('Unexpected captured admin action');
    };
}

$tests = [];
$tests['initial Telegram installs localized commands and command button after webhook confirmation'] = static function (): void {
    $state = []; $calls = []; $app = deepFixture($state, $calls);
    deepAssert($app->run(['action' => 'register', 'platform' => 'telegram'])['ok'], 'Telegram registration failed');
    deepAssert($state['tg_commands'] === gitpostedTelegramCommandSets() && $state['tg_menu'] === ['type' => 'commands'], 'Fresh Telegram menu incomplete');
    $paths = array_column($calls, 2); $first = array_search('setMyCommands', $paths, true);
    deepAssert($first !== false && $paths[$first - 1] === 'getWebhookInfo', 'Commands preceded endpoint confirmation');
    deepAssert(!in_array('setMyName', $paths, true) && !in_array('sendMessage', $paths, true), 'Unexpected profile or message mutation');
};
$tests['initial MAX confirms fixed commands before deleting acknowledged foreign URLs and retries idempotently'] = static function (): void {
    $state = ['max' => [['url' => 'https://foreign.example/private', 'update_types' => []]]]; $calls = []; $app = deepFixture($state, $calls);
    for ($attempt = 0; $attempt < 2; ++$attempt) deepAssert($app->run(['action' => 'register', 'platform' => 'max', 'takeover' => true])['ok'], 'MAX registration retry failed');
    deepAssert($state['max_commands'] === gitpostedMaxCommands() && count($state['max']) === 1, 'MAX menu or endpoint duplicated');
    $verbs = array_map(static fn(array $call): string => $call[1] . ' ' . $call[2], $calls);
    deepAssert(array_search('PATCH /me/commands', $verbs, true) < array_search('DELETE /subscriptions', $verbs, true), 'Foreign MAX endpoint removed before commands confirmation');
};
$tests['refused takeover never changes a former service menu or webhook'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $state = ['telegram' => ['url' => 'https://foreign.example/private'], 'max' => [['url' => 'https://foreign.example/private']], 'old_commands' => ['foreign']];
        $before = $state; $calls = []; $app = deepFixture($state, $calls);
        deepAssert(!$app->run(['action' => 'register', 'platform' => $platform])['ok'], 'Foreign bot accepted');
        deepAssert($state === $before, 'Refused takeover mutated former state');
    }
};
$tests['unconfirmed endpoint cannot modify commands or remove old MAX subscription'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $state = ['unconfirmed' => true, 'max' => [['url' => 'https://foreign.example/private']]]; $calls = []; $app = deepFixture($state, $calls);
        deepAssert(!$app->run(['action' => 'register', 'platform' => $platform, 'takeover' => true])['ok'], 'Unconfirmed endpoint accepted');
        foreach ($calls as $call) deepAssert(!in_array($call[2], ['setMyCommands', 'setChatMenuButton', '/me/commands'], true) && $call[1] !== 'DELETE', 'Unconfirmed endpoint changed menu or removed old state');
    }
};
$tests['command API failure stays retryable without pretending to restore unknown foreign commands'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $config = deepConfig(); $state = ['commands_throw' => true, 'max' => [['url' => 'https://foreign.example/private']]]; $calls = []; $app = deepFixture($state, $calls, $config);
        $result = $app->run(['action' => 'register', 'platform' => $platform, 'takeover' => true]);
        deepAssert(!$result['ok'] && !str_contains(json_encode($result), 'Captured private'), 'Menu failure leaked raw state');
        deepAssert($config['telegram_bot_enabled'] === false && $config['max_bot_enabled'] === false, 'Menu failure activated bot');
        deepAssert(!in_array('DELETE', array_column($calls, 1), true), 'Failed menu removed approved old endpoint too early');
        $state['commands_throw'] = false;
        deepAssert($app->run(['action' => 'register', 'platform' => $platform, 'takeover' => true])['ok'], 'Menu retry failed');
    }
};
$tests['incorrect command acknowledgement is rejected while MAX response order is immaterial'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $state = ['bad_commands' => true]; $calls = []; $app = deepFixture($state, $calls);
        $result = $app->run(['action' => 'register', 'platform' => $platform]);
        deepAssert(!$result['ok'] && $result['error']['code'] === 'COMMANDS_NOT_CONFIRMED', 'Invalid menu confirmation accepted');
        $state['bad_commands'] = false;
        deepAssert($app->run(['action' => 'register', 'platform' => $platform])['ok'], 'Correct reordered commands rejected');
    }
};
$tests['Telegram admin menu failure preserves the confirmed replacement and returns a useful retry warning'] = static function (): void {
    $state = ['commands_throw' => true, 'webhook' => ['url' => 'https://foreign.example/private']]; $calls = []; $writes = [];
    deepAdminFixture($state, $calls); $old = deepConfig(); $old['bot_token'] = ''; $next = deepConfig();
    $warning = adminConfigureTelegram(new DeepTelegram($next['bot_token']), $old, $next, '123456', static function (array $value) use (&$writes): void { $writes[] = $value; }, true);
    deepAssert($warning !== '' && !str_contains($warning, 'Captured private'), 'Partial menu failure not explained safely');
    deepAssert(count($writes) === 1 && $writes[0]['telegram_bot_enabled'] === true && $state['webhook']['url'] === 'https://selfhost.example/webhook.php', 'Menu failure rolled back working attachment');
    deepAssert(!in_array('deleteWebhook', array_column($calls, 0), true), 'Menu failure removed a confirmed endpoint');
    $state['commands_throw'] = false; $same = $writes[0];
    deepAssert(adminConfigureTelegram(new DeepTelegram($same['bot_token']), $same, $same, '123456', static function (array $value) use (&$writes): void { $writes[] = $value; }) === '', 'Menu retry failed');
    deepAssert($state['commands'] === gitpostedTelegramCommandSets() && $state['menu'] === ['type' => 'commands'], 'Admin menu retry incomplete');
};
$tests['Telegram persistence failure and missing takeover cannot change external menus'] = static function (): void {
    foreach ([false, true] as $takeover) {
        $state = ['webhook' => ['url' => 'https://foreign.example/private']]; $before = $state; $calls = []; $writes = 0;
        deepAdminFixture($state, $calls); $old = deepConfig(); $old['bot_token'] = ''; $next = deepConfig();
        try {
            adminConfigureTelegram(new DeepTelegram($next['bot_token']), $old, $next, '123456', static function () use (&$writes): void { if (++$writes === 1) throw new RuntimeException('Captured write failure'); }, $takeover);
            throw new RuntimeException('Unsafe operation succeeded');
        } catch (Throwable $error) { deepAssert($error->getMessage() !== 'Unsafe operation succeeded', 'Expected refusal'); }
        deepAssert($state === $before, 'Failed persistence changed foreign state');
        foreach ($calls as $call) deepAssert($call[0] === 'getWebhookInfo', 'Failed persistence changed external menu or webhook');
    }
};

$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); ++$passed; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo 'deep platform tests passed: ', $passed, PHP_EOL;
