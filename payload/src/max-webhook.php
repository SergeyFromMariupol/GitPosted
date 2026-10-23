<?php

declare(strict_types=1);

require __DIR__ . '/max-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if ($contentType === '' || !str_starts_with($contentType, 'application/json')) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Unsupported media type']);
    exit;
}

$rawContentLength = trim((string) ($_SERVER['CONTENT_LENGTH'] ?? ''));
$contentLength = $rawContentLength === '' ? null : filter_var($rawContentLength, FILTER_VALIDATE_INT);
if ($contentLength === false || ($contentLength !== null && ($contentLength < 1 || $contentLength > 1048576))) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Request too large']);
    exit;
}

try {
    $config = PostedConfig::get();
    if (($config['max_bot_enabled'] ?? false) !== true) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'MAX bot is disabled']);
        exit;
    }
    $secret = trim((string) ($config['max_webhook_secret'] ?? ''));
    $providedSecret = (string) ($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '');
    if ($secret === '' || !hash_equals($secret, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $stream = fopen('php://input', 'rb');
    $raw = is_resource($stream) ? (string) stream_get_contents($stream, 1048577) : '';
    if (is_resource($stream)) {
        fclose($stream);
    }
    if ($raw === '' || strlen($raw) > 1048576) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    $update = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($update) || !isset($update['update_type'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid update']);
        exit;
    }

    $app = posted_max_app();
    $updateKey = $app->enqueueUpdate($update, $raw);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'duplicate' => $updateKey === null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        flush();
    }
    ignore_user_abort(true);
    @set_time_limit(45);

    if ($updateKey !== null) {
        try {
            $app->processUpdateByKey($updateKey);
        } catch (Throwable $exception) {
            error_log('GitPosted MAX webhook worker: ' . $exception->getMessage());
        }
    }
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
} catch (Throwable $exception) {
    error_log('GitPosted MAX webhook error: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
