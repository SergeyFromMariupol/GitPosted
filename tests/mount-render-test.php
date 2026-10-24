<?php

declare(strict_types=1);

// Render the actual templates with local view fixtures. No config, database or API is opened.
require dirname(__DIR__) . '/payload/src/web-path.php';

function mountEscape(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (($argv[1] ?? '') === '--render') {
    set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
    $mount = $argv[2]; $mode = $argv[3];
    $config = ['base_url' => 'https://posts.example' . $mount, 'telegram_bot_enabled' => false, 'max_bot_enabled' => false];
    $baseUrl = $siteBaseUrl = $config['base_url'];
    $sitePath = $legalPath = static fn(string $path): string => mountEscape(gitpostedAppPath($GLOBALS['config'], $path));
    if ($mode === 'home') {
        $telegramBotUrl = $maxBotUrl = null;
        $projectRepository = 'https://github.com/example/GitPosted';
        $seoTitle = $seoDescription = 'Fixture'; $structuredData = '{}'; $faqItems = [];
        $statText = static fn(string $key): string => '0';
        $renderPlatformCta = static function (): void {};
        $source = file_get_contents(dirname(__DIR__) . '/payload/src/index.php');
        eval('?>' . substr($source, stripos($source, '<!doctype html>')));
    } elseif ($mode === 'legal') {
        final class PostedConfig { public static function get(): array { return $GLOBALS['config']; } }
        $source = file_get_contents(dirname(__DIR__) . '/payload/src/legal.php');
        eval(substr($source, strpos($source, 'function gitpostedLegalPage(')));
        gitpostedLegalPage('privacy');
    } else {
        function e(mixed $value): string { return mountEscape($value); }
        $siteHostname = 'posts.example'; $page = $mode === 'login' ? 'overview' : $mode;
        $authorized = $mode !== 'login'; $hCaptchaRequired = $hCaptchaReady = false; $hCaptchaSiteKey = '';
        $flash = $error = $maxDataError = ''; $stats = [];
        $telegramConfigured = $maxConfigured = $maxSchemaInstalled = false;
        $users = $channels = $maxUsers = $maxChannels = [];
        $_SESSION = ['csrf' => 'fixture', 'login_csrf' => 'fixture'];
        require dirname(__DIR__) . '/payload/src/control/panel.php';
    }
    exit;
}

foreach (['', '/GitPosted'] as $mount) {
    foreach (['home', 'legal', 'login', 'overview', 'telegram', 'max'] as $mode) {
        $process = proc_open([PHP_BINARY, __FILE__, '--render', $mount, $mode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Render fixture unavailable');
        fclose($pipes[0]); $html = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); $code = proc_close($process);
        if ($code !== 0 || $stderr !== '') throw new RuntimeException('Template render failed: ' . $mode . ' ' . $stderr);
        preg_match_all('~(?:href|src|action)="(/[^"<>]*)"~', $html, $matches);
        if (count($matches[1]) < 3) throw new RuntimeException('Insufficient rendered local links');
        foreach ($matches[1] as $url) {
            if (!str_starts_with(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $mount . '/')) {
                throw new RuntimeException('Rendered URL escaped application mount: ' . $mode);
            }
        }
        if (!in_array($mode, ['home', 'legal'], true) && !str_contains($html, 'data-app-base-path="' . $mount . '"')) {
            throw new RuntimeException('Admin script did not receive configured mount');
        }
        echo 'PASS actual ', $mode, ' template URLs: ', $mount === '' ? 'legacy root' : $mount, "\n";
    }
}
