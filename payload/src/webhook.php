<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/webhook-dedup.php';

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
if ($contentType !== '' && !str_starts_with($contentType, 'application/json')) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Unsupported media type']);
    exit;
}

$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? 0, FILTER_VALIDATE_INT);
if ($contentLength === false || $contentLength < 0 || $contentLength > 1048576) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Request too large']);
    exit;
}

$claim = null;

try {
    $config = PostedConfig::get();
    if (($config['telegram_bot_enabled'] ?? false) !== true) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Telegram bot is disabled']);
        exit;
    }
    $botToken = (string) ($config['bot_token'] ?? '');
    if (preg_match('/\A[0-9]{6,12}:[A-Za-z0-9_-]{30,200}\z/D', $botToken) !== 1) {
        throw new RuntimeException('Configured Telegram Bot API token is invalid');
    }
    $secret = (string) ($config['webhook_secret'] ?? '');
    $providedSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    $authorized = $secret !== '' && hash_equals($secret, $providedSecret);
    posted_webhook_rate_limit(
        $authorized ? 'telegram_authorized' : 'unauthorized',
        $authorized ? hash('sha256', $secret) : posted_webhook_client_ip(),
        $authorized ? 6000 : 120
    );
    if (!$authorized) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stream = fopen('php://input', 'rb');
    $raw = is_resource($stream) ? (string) stream_get_contents($stream, 1048577) : '';
    if (is_resource($stream)) {
        fclose($stream);
    }
    if (strlen($raw) > 1048576) {
        throw new RuntimeException('Request too large');
    }
    try {
        $update = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }
    if (!is_array($update)) {
        echo json_encode(['ok' => true, 'ignored' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
    if ($updateId === false || $updateId < 1) {
        echo json_encode(['ok' => true, 'ignored' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $claim = posted_webhook_claim_for_bot((int) $updateId, $botToken);
    if ($claim === null) {
        echo json_encode(['ok' => true, 'duplicate' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $telegramAlbumApp=posted_app();
    $telegramAlbumApp->handle($update);
    posted_webhook_complete($claim);
    $claim = null;
    $telegramReply=json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
    $telegramAlbumTail=$telegramAlbumApp->hasTelegramAlbumPreviewWork()||$telegramAlbumApp->hasTelegramTemplatePreviewWork()||$telegramAlbumApp->hasTelegramCommentsWork();
    if($telegramAlbumTail&&!headers_sent()){
        @ini_set('zlib.output_compression','0');if(function_exists('apache_setenv'))@apache_setenv('no-gzip','1');
        header('Content-Length: '.strlen($telegramReply));header('Connection: close');header('X-Accel-Buffering: no');
    }
    echo $telegramReply;
    if($telegramAlbumTail){
        ignore_user_abort(true);
        if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
        else{while(ob_get_level()>0){if(!@ob_end_flush())break;}flush();}
        try{$telegramAlbumApp->flushTelegramAlbumPreviews(true,1);}catch(Throwable $tailError){error_log('GitPosted album tail: '.$tailError::class);}
        try{$telegramAlbumApp->flushTelegramTemplatePreviews(true,1);}catch(Throwable $tailError){error_log('GitPosted template tail: '.$tailError::class);}
        try{$telegramAlbumApp->maintainTelegramComments(1);}catch(Throwable $commentsFailure){error_log('GitPosted comments tail: '.$commentsFailure::class);}
    }
} catch (Throwable $exception) {
    if (is_array($claim)) posted_webhook_release($claim);
    error_log('GitPosted webhook error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error'], JSON_UNESCAPED_UNICODE);
}

function posted_webhook_rate_limit(string $scope, string $identity, int $limit): void
{
    if ($scope === '' || $identity === '' || $limit < 1) {
        throw new InvalidArgumentException('Invalid rate limiter configuration');
    }
    $window = (int) floor(time() / 60);
    $path = posted_webhook_storage('rate_limits')
        . DIRECTORY_SEPARATOR
        . hash('sha256', 'posted|' . $scope . '|' . $identity . '|' . $window)
        . '.json';
    $handle = @fopen($path, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Rate limiter unavailable');
    }
    $raw = stream_get_contents($handle);
    $count = max(0, (int) $raw) + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) $count);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
    if (random_int(1, 100) === 1) {
        $checked = 0;
        foreach (glob(dirname($path) . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidate) {
            if (++$checked > 1000) break;
            if (is_file($candidate) && filemtime($candidate) < time() - 172800) @unlink($candidate);
        }
    }
    if ($count > $limit) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['ok' => false, 'error' => 'Too many requests']);
        exit;
    }
}

function posted_webhook_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) return 'unknown';
    $trusted = ['127.0.0.1', '::1'];
    foreach (explode(',', (string) (getenv('TREVOGA_TRUSTED_PROXY_IPS') ?: '')) as $proxy) {
        $proxy = trim($proxy);
        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) $trusted[] = $proxy;
    }
    if (!in_array($remote, $trusted, true)) return $remote;
    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if (filter_var($real, FILTER_VALIDATE_IP) !== false) return $real;
    $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($chain as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
    }
    return $remote;
}

