<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$result = [];
$failures = [];

$writeFailureLog = static function (string $message): void {
    $written = false;
    if (function_exists('openlog') && function_exists('syslog')) {
        try {
            $options = (defined('LOG_PID') ? LOG_PID : 1) | (defined('LOG_NDELAY') ? LOG_NDELAY : 8);
            $facility = defined('LOG_USER') ? LOG_USER : 8;
            $priority = defined('LOG_ERR') ? LOG_ERR : 3;
            if (openlog('gitposted-cron', $options, $facility)) {
                $written = syslog($priority, $message);
            }
        } catch (Throwable) {
            $written = false;
        } finally {
            if (function_exists('closelog')) {
                try {
                    closelog();
                } catch (Throwable) {
                }
            }
        }
    }
    if (!$written) {
        error_log($message);
    }
};

$recordFailure = static function (string $component, Throwable|string $failure) use (&$failures, $writeFailureLog): void {
    $message = $failure instanceof Throwable
        ? get_class($failure) . ': ' . $failure->getMessage()
        : trim($failure);
    $message = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? $message, 0, 4000);
    $failures[] = ['component' => $component, 'message' => $message];
    $writeFailureLog('GitPosted cron ' . $component . ' error: ' . $message);
};

try {
    $config = PostedConfig::get();
} catch (Throwable $exception) {
    $recordFailure('config', $exception);
    $config = null;
}

if (is_array($config)) {
    if (($config['telegram_bot_enabled'] ?? false) === true) {
        try {
            $result['telegram'] = posted_app()->runCron();
            foreach ((array) ($result['telegram']['errors'] ?? []) as $error) {
                $recordFailure('telegram', (string) $error);
            }
        } catch (Throwable $exception) {
            $recordFailure('telegram', $exception);
            $result['telegram'] = ['ok' => false, 'error' => $exception->getMessage()];
        }
    } else {
        $result['telegram'] = ['disabled' => true];
    }

    if (($config['max_bot_enabled'] ?? false) === true) {
        try {
            require_once __DIR__ . '/max-bootstrap.php';
            $result['max'] = posted_max_app()->runCron();
            foreach ((array) ($result['max']['errors'] ?? []) as $error) {
                $recordFailure('max', (string) $error);
            }
        } catch (Throwable $exception) {
            $recordFailure('max', $exception);
            $result['max'] = ['ok' => false, 'error' => $exception->getMessage()];
        }
    } else {
        $result['max'] = ['disabled' => true];
    }
}

$ok = $failures === [];
if (!$ok) {
    http_response_code(500);
}
echo json_encode(
    ['ok' => $ok, 'result' => $result, 'errors' => $failures],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
) . PHP_EOL;
exit($ok ? 0 : 1);
