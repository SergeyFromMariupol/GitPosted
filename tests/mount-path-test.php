<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bin/application.php';
require dirname(__DIR__) . '/lib/Wizard.php';

use GitPostedInstaller\Wizard;

function mountAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach (['' => '', '/' => '', '/GitPosted' => '/GitPosted'] as $path => $mount) {
    $config = ['base_url' => 'https://posts.example' . $path];
    $_SERVER = ['HTTP_HOST' => 'wrong.example', 'HTTP_X_FORWARDED_PREFIX' => '/GitRePosted', 'REQUEST_URI' => '/elsewhere/'];
    mountAssert(gitpostedUrlMount($config['base_url']) === $mount, 'Incorrect configured mount');
    foreach (['/', '/control/', '/control/?page=overview', '/assets/promo/02(1).svg'] as $local) {
        mountAssert(gitpostedAppPath($config, $local) === $mount . $local, 'Request headers changed local URL');
    }
    mountAssert(Wizard::pathPrefix(['base_url' => $config['base_url'], 'path_prefix' => $mount]) === $mount, 'Incorrect installer cookie mount');
}
echo "PASS legacy and mounted local URLs ignore request headers\n";

foreach (['/GitRePosted', '/gitposted', '/GitPosted/', '/GitPosted/../', '/%47itPosted', '//GitPosted', '/GitPosted?x=1', '/GitPosted#secret'] as $path) {
    try { gitpostedUrlMount('https://posts.example' . $path); throw new RuntimeException('Unsafe URL was accepted'); }
    catch (InvalidArgumentException) {}
}
foreach (['http://posts.example/GitPosted', 'https://user@posts.example/GitPosted', "https://posts.example/GitPosted\n", 'https://posts.example\\evil/GitPosted'] as $url) {
    try { gitpostedUrlMount($url); throw new RuntimeException('Unsafe authority was accepted'); }
    catch (InvalidArgumentException) {}
}
foreach (['//evil.example', '/../control/', '/control/../', '/control\\escape', '/control/ bad'] as $path) {
    try { gitpostedAppPath(['base_url' => 'https://posts.example/GitPosted'], $path); throw new RuntimeException('Unsafe local path accepted'); }
    catch (InvalidArgumentException) {}
}
echo "PASS unknown mounts and unsafe URLs fail closed\n";

$server = ['HTTPS' => 'on', 'HTTP_HOST' => 'posts.example'];
$public = ['base_url' => 'https://posts.example/GitPosted', 'host' => 'posts.example', 'path_prefix' => '/GitPosted'];
mountAssert(Wizard::trustedHost($server, $public), 'Mounted wizard rejected its configured host');
mountAssert(!Wizard::trustedHost($server, array_replace($public, ['path_prefix' => ''])), 'Inconsistent root cookie accepted');
mountAssert(!Wizard::trustedHost($server, array_replace($public, ['base_url' => 'https://posts.example/GitRePosted'])), 'Sibling mount accepted');
mountAssert(Wizard::trustedHost($server, ['base_url' => 'https://posts.example', 'host' => 'posts.example']), 'Legacy root wizard rejected');
echo "PASS wizard preserves host checks and exact prefix metadata\n";

foreach (['', '/GitPosted'] as $mount) {
    foreach (['telegram', 'max'] as $platform) {
        $config = ['base_url' => 'https://posts.example' . $mount, 'telegram_bot_enabled' => false, 'max_bot_enabled' => false,
            'bot_token' => '123456:' . str_repeat('a', 32), 'bot_username' => 'FixtureBot', 'max_bot_token' => 'fixture-max-token',
            'max_bot_username' => 'FixtureMaxBot', 'webhook_secret' => str_repeat('a', 64), 'max_webhook_secret' => str_repeat('b', 64)];
        $rows = []; $calls = [];
        $transport = static function (string $target, string $token, string $method, string $path, array $body) use (&$rows, &$calls): array {
            $calls[] = compact('method', 'path', 'body');
            if ($path === 'getMe') return ['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'FixtureBot', 'first_name' => 'Fixture']];
            if ($path === '/me') return ['user_id' => 987654, 'username' => 'FixtureMaxBot', 'name' => 'Fixture'];
            if ($path === 'getWebhookInfo') return ['ok' => true, 'result' => $rows[0] ?? ['url' => '']];
            if ($path === '/subscriptions' && $method === 'GET') return ['subscriptions' => $rows];
            if ($path === 'setWebhook' || ($path === '/subscriptions' && $method === 'POST')) {
                $rows = [['url' => $body['url'], $target === 'telegram' ? 'allowed_updates' : 'update_types' => $body[$target === 'telegram' ? 'allowed_updates' : 'update_types']]];
                return ['ok' => true, 'result' => true, 'success' => true];
            }
            if (in_array($path, ['setMyCommands', 'setChatMenuButton'], true)) return ['ok' => true, 'result' => true];
            if ($path === '/me/commands') return ['commands' => $body['commands']];
            throw new RuntimeException('Unexpected intercepted API request');
        };
        $app = new InstallerApplication($transport, static fn(): array => [], $config);
        $result = $app->run(['action' => 'register', 'platform' => $platform]);
        mountAssert($result['ok'], 'Mounted registration failed');
        $expected = $config['base_url'] . ($platform === 'telegram' ? '/webhook.php' : '/max-webhook.php');
        mountAssert($rows[0]['url'] === $expected, 'Webhook escaped its configured application mount');
        mountAssert(count(array_filter($calls, static fn(array $call): bool => $call['method'] === 'DELETE')) === 0, 'Registration removed another webhook');
        foreach (['/GitRePosted', '/GitPosted/', '/%47itPosted'] as $badMount) {
            $bad = array_replace($config, ['base_url' => 'https://posts.example' . $badMount]);
            $failed = (new InstallerApplication(static function (): never { throw new RuntimeException('Invalid mount reached API'); }, null, $bad))->run(['action' => 'register', 'platform' => $platform]);
            mountAssert(!$failed['ok'] && $failed['error']['code'] === 'INVALID_BASE_URL', 'Invalid endpoint was not rejected before API');
        }
    }
}
echo "PASS both webhook platforms preserve exact legacy or mounted endpoints\n";
