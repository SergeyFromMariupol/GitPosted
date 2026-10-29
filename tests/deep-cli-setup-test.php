<?php

declare(strict_types=1);

/** Execute rewritten CLI entry points against captured SDK/config/database adapters. */
require dirname(__DIR__) . '/payload/src/bot-commands.php';
require dirname(__DIR__) . '/payload/src/setup-policy.php';
function cliAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
final class DeepSetupExit extends RuntimeException { public function __construct(public int $status) { parent::__construct('Fixture exit'); } }
final class DeepCliConfig { public static function get(): array { return $GLOBALS['cli_fixture']['config']; } }
final class DeepCliDb {
    public static function pdo(array $config): object { $GLOBALS['cli_fixture']['calls'][] = 'database'; return new stdClass(); }
    public static function ensureSchema(object $pdo): void {}
}
function deepCliPostedApp(): void {}
function deepCliStderr(string $message): void { $GLOBALS['cli_fixture']['stderr'] .= $message; }
function deepCliMaxApp(): DeepCliMaxApp { $GLOBALS['cli_fixture']['calls'][] = 'database'; return new DeepCliMaxApp(); }
final class DeepCliTelegram {
    public function __construct(string $token) {}
    public function call(string $method, array $body = []): array {
        $fixture =& $GLOBALS['cli_fixture']; $fixture['calls'][] = $method;
        if ($method === 'getMe') return ['ok' => true, 'result' => ['id'=>123456,'is_bot'=>true,'username' => 'FixtureBot']];
        if ($method === 'getWebhookInfo') return ['ok' => true, 'result' => $fixture['rows'][0] ?? ['url' => '']];
        if (!empty($fixture['api_fail'])) throw new RuntimeException('Captured transport secret-marker');
        if ($method === 'setWebhook') $fixture['rows'] = [['url' => !empty($fixture['wrong_endpoint']) ? 'https://foreign.example/secret-marker' : $body['url'],
            'allowed_updates' => !empty($fixture['wrong_events']) ? ['message'] : $body['allowed_updates']]];
        return ['ok' => true, 'result' => true];
    }
}
final class DeepCliMaxApi {
    public function __construct(array $config) {}
    public function me(): array { $GLOBALS['cli_fixture']['calls'][] = 'getMe'; return ['user_id' => 123, 'username' => 'FixtureBot']; }
    public function subscriptions(): array { $GLOBALS['cli_fixture']['calls'][] = 'GET'; return ['subscriptions' => $GLOBALS['cli_fixture']['rows']]; }
}
final class DeepCliMaxApp {
    public static function webhookUpdateTypes(): array { return ['message_created', 'bot_started']; }
    public function setupWebhook(string $url): array {
        $fixture =& $GLOBALS['cli_fixture']; $fixture['calls'][] = 'POST';
        if (empty($fixture['unconfirmed'])) $fixture['rows'][] = ['url' => $url, 'update_types' => self::webhookUpdateTypes()];
        if (!empty($fixture['new_foreign'])) $fixture['rows'][] = ['url' => 'https://later.example/secret-marker'];
        return ['success' => true, 'unexpected_private' => 'secret-marker'];
    }
    public function subscriptions(): array { return (new DeepCliMaxApi([]))->subscriptions(); }
    public function setCommands(bool $attachmentIntent=false): array {
        $fixture =& $GLOBALS['cli_fixture'];$commands=gitpostedMaxCommands($fixture['config'],$attachmentIntent);
        if($commands===[])return ['success'=>true,'skipped'=>true];
        $fixture['calls'][] = 'COMMANDS';
        if (!empty($fixture['api_fail'])) throw new RuntimeException('MAX /subscriptions?url=secret-marker raw error');
        return ['commands' => $commands];
    }
    public function removeSubscription(string $url): void {
        $fixture =& $GLOBALS['cli_fixture']; $fixture['calls'][] = 'DELETE'; $fixture['removed'][] = $url;
        if (empty($fixture['delete_unconfirmed'])) $fixture['rows'] = array_values(array_filter($fixture['rows'], static fn(array $row): bool => $row['url'] !== $url));
    }
    public function syncChannelById(string $chatId, string $ownerId): array { return ['private_payload' => 'secret-marker']; }
}
function cliRun(string $platform, array $options = [], array $extra = []): array {
    $config = ['base_url' => 'https://selfhost.example', 'bot_token' => '123456:' . str_repeat('t', 32),
        'bot_username' => 'FixtureBot', 'webhook_secret' => str_repeat('w', 64), 'telegram_bot_enabled' => true,
        'max_bot_token' => 'fixture-max-token', 'max_bot_username' => 'FixtureBot', 'max_bot_enabled' => true,
        'max_seed_channels' => []];
    $GLOBALS['cli_fixture'] = array_replace(['config' => $config, 'rows' => [['url' => 'https://foreign.example/secret-marker?key=secret-marker']],
        'calls' => [], 'removed' => [], 'stderr' => '', 'options' => $options], $extra);
    $source = file_get_contents(dirname(__DIR__) . '/payload/src/' . ($platform === 'telegram' ? 'setup.php' : 'max-setup.php'));
    $source = preg_replace('/^<\?php\s*declare\(strict_types=1\);/', '', $source);
    $source = preg_replace('/^require(?:_once)? [^;]+;\r?$/m', '', $source);
    $source = str_replace(['PostedConfig', 'PostedTelegram', 'PostedDb', 'GitPostedMaxApi', 'GitPostedMaxBot', 'posted_max_app()', 'posted_app()'],
        ['DeepCliConfig', 'DeepCliTelegram', 'DeepCliDb', 'DeepCliMaxApi', 'DeepCliMaxApp', 'deepCliMaxApp()', 'deepCliPostedApp()'], $source);
    $source = preg_replace("/getopt\('', \[[^\]]+\]\)/", "\$GLOBALS['cli_fixture']['options']", $source);
    $source = str_replace('catch (Throwable $exception) {', 'catch (Throwable $exception) { if ($exception instanceof DeepSetupExit) throw $exception;', $source);
    $source = str_replace(['exit(1);', 'exit;', 'fwrite(STDERR, ', "header('Content-Type: application/json; charset=utf-8');", "header('X-Robots-Tag: noindex, nofollow');"],
        ['throw new DeepSetupExit(1);', 'throw new DeepSetupExit(0);', 'deepCliStderr(', '', ''], $source);
    ob_start(); $status = 0;
    try { eval($source); }
    catch (DeepSetupExit $exit) { $status = $exit->status; }
    finally { $output = ob_get_clean(); }
    return ['status' => $status, 'output' => $output, 'fixture' => $GLOBALS['cli_fixture']];
}

$tests = [];
$tests['CLI fallback refuses foreign webhook before schema profile commands or webhook mutations'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $result = cliRun($platform);
        cliAssert($result['status'] === 1, 'Missing takeover accepted');
        cliAssert($result['fixture']['calls'] === ($platform === 'telegram' ? ['getMe', 'getWebhookInfo'] : ['getMe', 'GET']), 'Refused CLI mutated database or API');
        cliAssert(str_contains($result['output'] . $result['fixture']['stderr'], '--takeover'), 'Missing takeover not explained');
        cliAssert(!str_contains($result['output'] . $result['fixture']['stderr'], 'secret-marker'), 'Foreign webhook secret escaped');
    }
};
$tests['explicit CLI takeover permits the requested platform setup'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $result = cliRun($platform, ['takeover' => false]); // getopt represents a supplied no-value flag as false.
        cliAssert($result['status'] === 0 && (json_decode($result['output'], true)['ok'] ?? false), 'Explicit CLI takeover failed');
        cliAssert(in_array($platform === 'telegram' ? 'setWebhook' : 'POST', $result['fixture']['calls'], true), 'Selected webhook not registered');
    }
};
$tests['MAX CLI removes only initially approved URLs after replacement and commands confirmation'] = static function (): void {
    $result = cliRun('max', ['takeover' => false], ['new_foreign' => true]); $fixture = $result['fixture'];
    cliAssert($result['status'] === 0 && $fixture['removed'] === ['https://foreign.example/secret-marker?key=secret-marker'], 'Unapproved later URL deleted');
    cliAssert(array_search('COMMANDS', $fixture['calls'], true) < array_search('DELETE', $fixture['calls'], true), 'Old service removed before commands');
    cliAssert(!str_contains($result['output'], 'secret-marker') && str_contains($result['output'], '[configured-webhook]'), 'MAX CLI echoed foreign credentials or raw API fields');
};
$tests['unconfirmed MAX replacement or command failure preserves former subscriptions and sanitizes errors'] = static function (): void {
    foreach (['unconfirmed', 'api_fail'] as $failure) {
        $result = cliRun('max', ['takeover' => false], [$failure => true]);
        cliAssert($result['status'] === 1 && !in_array('DELETE', $result['fixture']['calls'], true), 'Failed replacement removed former webhook');
        cliAssert(!str_contains($result['output'] . $result['fixture']['stderr'], 'secret-marker'), 'Raw transport error leaked');
    }
};
$tests['MAX no-webhook mode cannot silently replace another service command menu'] = static function (): void {
    $result = cliRun('max', ['no-webhook' => false]);
    cliAssert($result['status'] === 1 && $result['fixture']['calls'] === ['getMe', 'GET'], 'No-webhook bypassed takeover guard');
    $result = cliRun('max', ['no-webhook' => false, 'takeover' => false]);
    cliAssert($result['status'] === 0 && !in_array('POST', $result['fixture']['calls'], true) && !in_array('DELETE', $result['fixture']['calls'], true), 'No-webhook changed endpoints');
};
$tests['disabled platforms exit without API or database access'] = static function (): void {
    foreach (['telegram', 'max'] as $platform) {
        $config = ['telegram_bot_enabled' => false, 'max_bot_enabled' => false];
        $result = cliRun($platform, [], ['config' => $config]);
        cliAssert($result['status'] === 0 && $result['fixture']['calls'] === [] && (json_decode($result['output'], true)['disabled'] ?? false), 'Disabled CLI contacted a platform');
    }
};
$tests['Telegram CLI failure cannot expose raw transport exceptions'] = static function (): void {
    $result = cliRun('telegram', ['takeover' => false], ['api_fail' => true]);
    cliAssert($result['status'] === 1 && !str_contains($result['output'], 'secret-marker') && !(json_decode($result['output'], true)['ok'] ?? true), 'TG CLI exposed private error');
};
$tests['Telegram CLI confirms recorded URL and events instead of trusting only success acknowledgements'] = static function (): void {
    foreach (['wrong_endpoint', 'wrong_events'] as $failure) {
        $result = cliRun('telegram', ['takeover' => false], [$failure => true]);
        cliAssert($result['status'] === 1 && !(json_decode($result['output'], true)['ok'] ?? true), 'Incorrect recorded Telegram endpoint accepted');
        cliAssert(!str_contains($result['output'], 'secret-marker'), 'Endpoint confirmation exposed foreign credentials');
    }
};
$tests['MAX CLI confirms old URL removal and never reports an ignored delete as success'] = static function (): void {
    $result = cliRun('max', ['takeover' => false], ['delete_unconfirmed' => true]);
    cliAssert($result['status'] === 1 && count($result['fixture']['rows']) === 2, 'Unconfirmed deletion reported complete');
    cliAssert(!str_contains($result['output'] . $result['fixture']['stderr'], 'secret-marker'), 'Unconfirmed deletion exposed foreign credentials');
};

$passed = 0;
// Rewritten entry points also exercise HTTP error statuses. Keep the runner's
// progress output buffered so later fixtures can still set those headers.
ob_start();
foreach ($tests as $name => $test) {
    try { $test(); ++$passed; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo 'deep CLI setup tests passed: ', $passed, PHP_EOL;
ob_end_flush();
