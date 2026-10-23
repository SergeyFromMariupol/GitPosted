<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/publication-plan.php';
require_once __DIR__ . '/max-post-options.php';
require_once __DIR__ . '/max-interactive.php';
require_once __DIR__ . '/max-comments.php';
require_once __DIR__ . '/max-rights.php';
require_once __DIR__ . '/max-post-links.php';

/**
 * Isolated MAX implementation for GitPosted.
 *
 * MAX users, channels, drafts, deliveries and update claims live in
 * posted_max_* tables.
 */

final class GitPostedMaxApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $responseBody = '',
        public readonly bool $deliveryMayBeUnknown = false
    ) {
        parent::__construct($message);
    }
}

final class GitPostedMaxStaleInputException extends InvalidArgumentException
{
}

final class GitPostedMaxForbiddenFileFallback
{
    private const FORBIDDEN_EXTENSIONS = ['apk','exe','html'];

    public static function isForbiddenExtensionRejection(
        GitPostedMaxApiException $exception
    ): bool {
        return $exception->httpStatus === 415
            && stripos($exception->responseBody, 'File extension is forbidden') !== false;
    }

    public static function originalFilename(array $attachment): string
    {
        $payload = is_array($attachment['payload'] ?? null)
            ? $attachment['payload']
            : [];
        $candidates = [
            $attachment['filename'] ?? null,
            $attachment['file_name'] ?? null,
            $attachment['name'] ?? null,
            $payload['filename'] ?? null,
            $payload['file_name'] ?? null,
            $payload['name'] ?? null,
        ];
        $sourceUrl = self::sourceUrl($attachment);
        if ($sourceUrl !== '') {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $candidates[] = rawurldecode(basename($path));
            }
        }
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_int($candidate)) {
                continue;
            }
            $filename = str_replace('\\', '/', trim((string) $candidate));
            $filename = basename($filename);
            $filename = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
            $filename = trim($filename, " .\t\n\r\0\x0B");
            if ($filename !== '') {
                return mb_substr($filename, 0, 220);
            }
        }
        return '';
    }

    public static function renamedFilename(array $attachment): ?string
    {
        $filename = self::originalFilename($attachment);
        if ($filename === '') {
            return null;
        }
        $extension = mb_strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::FORBIDDEN_EXTENSIONS, true)
            ? $filename . '.zip'
            : null;
    }

    public static function sourceUrl(array $attachment): string
    {
        $payload = is_array($attachment['payload'] ?? null)
            ? $attachment['payload']
            : [];
        $url = trim((string) ($payload['url'] ?? ''));
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    public static function outboundAttachment(array $attachment): ?array
    {
        $type = mb_strtolower(trim((string) ($attachment['type'] ?? '')));
        $payload = $attachment['payload'] ?? null;
        if (
            !in_array($type, ['image','video','audio','file'], true)
            || !is_array($payload)
            || $payload === []
        ) {
            return null;
        }
        return ['type'=>$type,'payload'=>$payload];
    }
}

final class GitPostedMaxApi
{
    private const MAX_TEXT_LENGTH = 4000;

    private string $baseUrl;
    private string $token;
    private ?string $caFile;

    public function __construct(private readonly array $config)
    {
        $this->token = trim((string) ($config['max_bot_token'] ?? ''));
        if ($this->token === '') {
            throw new RuntimeException('MAX bot token is not configured.');
        }

        $baseUrl = rtrim(trim((string) ($config['max_api_base_url'] ?? 'https://platform-api2.max.ru')), '/');
        if (!preg_match('~^https://[A-Za-z0-9.-]+(?::\d+)?$~', $baseUrl)) {
            throw new RuntimeException('MAX API base URL is invalid.');
        }
        $this->baseUrl = $baseUrl;

        $caFile = trim((string) ($config['max_ca_file'] ?? ''));
        if ($caFile !== '' && !is_file($caFile)) {
            throw new RuntimeException('Configured MAX CA file is unavailable.');
        }
        $this->caFile = $caFile !== '' ? $caFile : null;
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $singleDeliveryAttempt = false
    ): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported MAX API method.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new InvalidArgumentException('Invalid MAX API path.');
        }

        $payload = $body === null
            ? null
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // MAX does not document an idempotency key for POST /messages.
        // Ambiguous transport/5xx results are therefore never retried for a
        // channel publication. Explicit rejections such as 429 and
        // attachment.not.ready are safe to retry because MAX did not create a
        // message for that request.
        $delaysMs = [0, 250, 750, 1500];
        $lastException = null;

        foreach ($delaysMs as $attempt => $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $headers = ['Authorization: ' . $this->token, 'Accept: application/json'];
            if ($payload !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            $responseHeaders = [];
            $curl = curl_init($this->baseUrl . $path);
            if ($curl === false) {
                throw new RuntimeException('Unable to initialize MAX API request.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $length;
                },
            ];
            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = $payload;
            }
            if ($this->caFile !== null) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }
            curl_setopt_array($curl, $options);

            $raw = curl_exec($curl);
            $curlError = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if ($raw === false) {
                $lastException = new GitPostedMaxApiException(
                    'MAX API transport error: ' . $curlError,
                    0,
                    '',
                    $singleDeliveryAttempt
                );
                if (!$singleDeliveryAttempt && $attempt < count($delaysMs) - 1) {
                    continue;
                }
                throw $lastException;
            }

            $raw = (string) $raw;
            if ($status >= 200 && $status < 300) {
                return $this->decodeSuccessfulResponse($raw, $status, $singleDeliveryAttempt);
            }

            $lastException = new GitPostedMaxApiException(
                'MAX API ' . $method . ' ' . $path . ' returned HTTP ' . $status,
                $status,
                mb_substr($raw, 0, 4000),
                $singleDeliveryAttempt && $status >= 500
            );
            $retryable = in_array($status, [429, 502, 503, 504], true)
                || str_contains($raw, 'attachment.not.ready');
            if ($lastException->deliveryMayBeUnknown) {
                throw $lastException;
            }
            if (!$retryable || $attempt >= count($delaysMs) - 1) {
                throw $lastException;
            }

            $retryAfter = isset($responseHeaders['retry-after']) ? (float) $responseHeaders['retry-after'] : 0.0;
            if ($retryAfter > 0) {
                usleep((int) (min($retryAfter, 2.0) * 1_000_000));
            }
        }

        throw $lastException ?? new RuntimeException('MAX API request failed.');
    }

    private function decodeSuccessfulResponse(string $raw, int $status, bool $singleDeliveryAttempt): array
    {
        try {
            $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$value instanceof stdClass) {
                throw new JsonException('Expected a JSON object.');
            }
            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GitPostedMaxApiException(
                'MAX API returned an invalid JSON object for a successful HTTP response.',
                $status,
                mb_substr($raw, 0, 4000),
                $singleDeliveryAttempt
            );
        }
    }

    public function sendMessage(
        string $chatId,
        string $text,
        array $media = [],
        array $buttonRows = [],
        bool $notify = true,
        ?array $link = null,
        bool $singleDeliveryAttempt = false,
        ?string $format = null,
        bool $disableLinkPreview = false
    ): array {
        $text = self::prepareMessageText($text, $format);
        self::validateMediaAttachments($media, $buttonRows);
        $attachments = self::outboundMedia($media);
        if ($buttonRows !== []) {
            $attachments[] = self::keyboard($buttonRows);
        }
        if ($text === '' && $attachments === [] && $link === null) {
            throw new InvalidArgumentException('MAX message cannot be empty.');
        }
        $body = ['text' => $text, 'attachments' => $attachments, 'notify' => $notify];
        if (in_array($format, ['html', 'markdown'], true)) {
            $body['format'] = $format;
        }
        if ($link !== null) {
            $body['link'] = $link;
        }
        return $this->request(
            'POST',
            '/messages?chat_id=' . rawurlencode($chatId) . ($disableLinkPreview ? '&disable_link_preview=true' : ''),
            $body,
            $singleDeliveryAttempt
        );
    }

    public function sendToUser(
        string $userId,
        string $text,
        array $media = [],
        array $buttonRows = [],
        bool $notify = true
    ): array {
        if (preg_match('/^\d+$/D', $userId) !== 1) {
            throw new InvalidArgumentException('Invalid MAX user ID.');
        }
        $text = self::limitText($text);
        self::validateMediaAttachments($media, $buttonRows);
        $attachments = self::outboundMedia($media);
        if ($buttonRows !== []) {
            $attachments[] = self::keyboard($buttonRows);
        }
        if ($text === '' && $attachments === []) {
            throw new InvalidArgumentException('MAX message cannot be empty.');
        }
        return $this->request(
            'POST',
            '/messages?user_id=' . rawurlencode($userId),
            ['text' => $text, 'attachments' => $attachments, 'notify' => $notify]
        );
    }

    public function editMessage(
        string $messageId,
        string $text,
        array $media = [],
        array $buttonRows = [],
        ?string $format = null
    ): array {
        $text = self::prepareMessageText($text, $format);
        self::validateMediaAttachments($media, $buttonRows);
        $attachments = self::outboundMedia($media);
        if ($buttonRows !== []) {
            $attachments[] = self::keyboard($buttonRows);
        }
        if ($text === '' && $attachments === []) {
            throw new InvalidArgumentException('MAX message cannot be empty.');
        }
        $body = ['text' => $text, 'attachments' => $attachments];
        if (in_array($format, ['html', 'markdown'], true)) {
            $body['format'] = $format;
        }
        return $this->requireSuccessResult(
            $this->request(
                'PUT',
                '/messages?message_id=' . rawurlencode($messageId),
                $body
            ),
            'message edit'
        );
    }

    public function answerCallback(
        string $callbackId,
        string $text = '',
        array $buttonRows = [],
        string $notification = '',
        array $media = [],
        ?array $link = null,
        ?string $format = null,
        bool $disableLinkPreview = false
    ): array {
        $body = [];
        if ($text !== '' || $buttonRows !== [] || $media !== [] || $link !== null) {
            self::validateMediaAttachments($media, $buttonRows);
            $message = ['text' => self::prepareMessageText($text, $format)];
            if (in_array($format, ['html', 'markdown'], true)) $message['format'] = $format;
            $attachments = self::outboundMedia($media);
            if ($buttonRows !== []) {
                $attachments[] = self::keyboard($buttonRows);
            }
            if ($attachments !== []) {
                $message['attachments'] = $attachments;
            }
            if ($link !== null) {
                $message['link'] = $link;
            }
            $body['message'] = $message;
        }
        if ($notification !== '') {
            $body['notification'] = mb_substr($notification, 0, 160);
        }
        return $this->requireSuccessResult(
            $this->request(
                'POST',
                '/answers?callback_id=' . rawurlencode($callbackId) . ($disableLinkPreview ? '&disable_link_preview=true' : ''),
                $body
            ),
            'callback answer'
        );
    }

    public function getChat(string $chatId): array
    {
        return $this->request('GET', '/chats/' . rawurlencode($chatId));
    }

    public function sendComment(string $messageId, string $text, ?string $format = null, bool $singleDeliveryAttempt = true): array
    {
        if (preg_match('/^(?:mid\.)?[A-Za-z0-9_-]+$/D', $messageId) !== 1 || trim($text) === '' || mb_strlen($text) > 4000) {
            throw new InvalidArgumentException('Укажите действующий пост и комментарий длиной до 4000 символов.');
        }
        $body = ['text' => $text];
        if (in_array($format, ['html', 'markdown'], true)) $body['format'] = $format;
        $response = $this->request('POST', '/messages/' . rawurlencode($messageId) . '/comments', $body, $singleDeliveryAttempt);
        if (self::messageId($response) === '') {
            throw new GitPostedMaxApiException('МАКС не вернул ID комментария; результат отправки неизвестен.', 200, '', true);
        }
        return $response;
    }

    public function getComments(string $messageId, int $count = 100, ?int $before = null, ?int $after = null): array
    {
        if (preg_match('/^(?:mid\.)?[A-Za-z0-9_-]+$/D', $messageId) !== 1) throw new InvalidArgumentException('Некорректный ID поста МАКС.');
        $query = ['count' => max(1, min(100, $count))];
        if ($before !== null) $query['before'] = $before;
        if ($after !== null) $query['after'] = $after;
        return $this->request('GET', '/messages/' . rawurlencode($messageId) . '/comments?' . http_build_query($query));
    }

    public function getMyMembership(string $chatId): array
    {
        return $this->request('GET', '/chats/' . rawurlencode($chatId) . '/members/me');
    }

    public function getMembers(string $chatId, array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $userId) {
            $value = trim((string) $userId);
            if (preg_match('/^\d+$/D', $value) === 1) {
                $ids[] = $value;
            }
        }
        if ($ids === []) {
            return ['members' => []];
        }
        $ids = array_values(array_unique($ids));
        return $this->request(
            'GET',
            '/chats/' . rawurlencode($chatId) . '/members?'
            . 'user_ids=' . rawurlencode(implode(',', $ids))
        );
    }

    public function getMembersPage(string $chatId, ?string $marker = null, int $count = 100): array
    {
        $count = max(1, min(100, $count));
        $path = '/chats/' . rawurlencode($chatId) . '/members?count=' . $count;
        if ($marker !== null && preg_match('/^-?\d+$/D', $marker) === 1) {
            $path .= '&marker=' . rawurlencode($marker);
        }
        return $this->request('GET', $path);
    }

    public function getMessage(string $messageId): array
    {
        return $this->request('GET', '/messages?message_ids=' . rawurlencode($messageId));
    }

    public function recentMessages(string $chatId, int $count = 100): array
    {
        $count = max(1, min(100, $count));
        return $this->request(
            'GET',
            '/messages?chat_id=' . rawurlencode($chatId) . '&count=' . $count
        );
    }

    public function reuploadForbiddenFiles(array $media): array
    {
        $result = [];
        $replaced = false;
        foreach ($media as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $renamed = GitPostedMaxForbiddenFileFallback::renamedFilename($attachment);
            if (
                mb_strtolower(trim((string) ($attachment['type'] ?? ''))) !== 'file'
                || $renamed === null
            ) {
                $result[] = $attachment;
                continue;
            }
            $sourceUrl = GitPostedMaxForbiddenFileFallback::sourceUrl($attachment);
            if ($sourceUrl === '') {
                throw new RuntimeException(
                    'MAX did not provide a download URL for the forbidden file.'
                );
            }
            $temporaryPath = $this->downloadMaxFileToTemporaryPath($sourceUrl);
            try {
                $token = $this->uploadRenamedFile($temporaryPath, $renamed);
                $size = filesize($temporaryPath);
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
            $replacement = [
                'type'=>'file',
                'payload'=>['token'=>$token],
                'filename'=>$renamed,
            ];
            if (is_int($size) && $size >= 0) {
                $replacement['size'] = $size;
            }
            $result[] = $replacement;
            $replaced = true;
        }
        if (!$replaced) {
            throw new RuntimeException(
                'MAX rejected a file extension, but no .apk, .exe or .html attachment could be recovered.'
            );
        }
        return $result;
    }

    private function downloadMaxFileToTemporaryPath(string $sourceUrl): string
    {
        $this->assertTrustedMediaUrl($sourceUrl, true);
        $configuredLimit = (int) ($this->config['max_forbidden_reupload_limit_bytes'] ?? 536_870_912);
        $maximumBytes = max(1_048_576, min(4_294_967_296, $configuredLimit));
        $dataDir = rtrim((string) ($this->config['data_dir'] ?? ''), "/\\");
        if ($dataDir === '') {
            throw new RuntimeException('GitPosted data directory is not configured.');
        }
        $temporaryDirectory = $dataDir . DIRECTORY_SEPARATOR . 'tmp'
            . DIRECTORY_SEPARATOR . 'max-forbidden-files';
        if (
            !is_dir($temporaryDirectory)
            && !mkdir($temporaryDirectory, 0700, true)
            && !is_dir($temporaryDirectory)
        ) {
            throw new RuntimeException('Unable to create a private MAX temporary directory.');
        }
        @chmod($temporaryDirectory, 0700);
        $temporaryPath = tempnam($temporaryDirectory, 'max-file-');
        if (!is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('Unable to allocate a temporary MAX file.');
        }
        @chmod($temporaryPath, 0600);
        $handle = fopen($temporaryPath, 'wb');
        if (!is_resource($handle)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to open a temporary MAX file.');
        }
        $curl = curl_init($sourceUrl);
        if ($curl === false) {
            fclose($handle);
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to initialize a MAX file download.');
        }
        $tooLarge = false;
        $options = [
            CURLOPT_FILE=>$handle,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>600,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function(
                mixed $curlHandle,
                float $downloadTotal,
                float $downloadNow,
                float $uploadTotal,
                float $uploadNow
            ) use (&$tooLarge,$maximumBytes):int {
                if ($downloadTotal > $maximumBytes || $downloadNow > $maximumBytes) {
                    $tooLarge = true;
                    return 1;
                }
                return 0;
            },
            CURLOPT_USERAGENT=>'GitPosted Bot',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        curl_setopt_array($curl,$options);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fflush($handle);
        fclose($handle);
        $size = filesize($temporaryPath);
        if (
            $ok === false
            || $status < 200
            || $status >= 300
            || !is_int($size)
            || $size < 1
            || $size > $maximumBytes
        ) {
            @unlink($temporaryPath);
            if ($tooLarge || (is_int($size) && $size > $maximumBytes)) {
                throw new RuntimeException('The forbidden MAX file is larger than the configured re-upload limit.');
            }
            throw new RuntimeException(
                'Unable to download the rejected MAX file'
                . ($status > 0 ? ' (HTTP ' . $status . ')' : '')
                . ($error !== '' ? ': ' . $error : '.')
            );
        }
        return $temporaryPath;
    }

    private function uploadRenamedFile(string $path, string $filename): string
    {
        if (!is_file($path) || $filename === '') {
            throw new InvalidArgumentException('Invalid MAX re-upload source.');
        }
        $slot = $this->request('POST','/uploads?type=file');
        $uploadUrl = trim((string) ($slot['url'] ?? ''));
        $this->assertTrustedMediaUrl($uploadUrl, false);
        $curl = curl_init($uploadUrl);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize a MAX file upload.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>[
                'data'=>new CURLFile($path,'application/zip',$filename),
            ],
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>600,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'GitPosted Bot',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        curl_setopt_array($curl,$options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $raw = is_string($raw) ? $raw : '';
        if ($status < 200 || $status >= 300) {
            throw new GitPostedMaxApiException(
                'MAX renamed file upload returned HTTP ' . $status
                    . ($error !== '' ? ': ' . $error : ''),
                $status,
                mb_substr($raw,0,4000),
                false
            );
        }
        $uploaded=[];
        if($raw!==''){
            try {
                $uploaded = json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('MAX renamed file upload returned invalid JSON.',0,$exception);
            }
            if(!is_array($uploaded)){
                throw new RuntimeException('MAX renamed file upload returned an invalid response.');
            }
        }
        $token = trim((string) (
            $uploaded['token']
            ?? $uploaded['retval']['token']
            ?? $slot['token']
            ?? ''
        ));
        if ($token === '') {
            throw new RuntimeException('MAX renamed file upload did not return a token.');
        }
        return $token;
    }

    private function assertTrustedMediaUrl(string $url, bool $allowMediaCdn): void
    {
        if (filter_var($url,FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('MAX media URL is invalid.');
        }
        $scheme = mb_strtolower((string) parse_url($url,PHP_URL_SCHEME));
        $host = mb_strtolower(rtrim((string) parse_url($url,PHP_URL_HOST),'.'));
        $user = parse_url($url,PHP_URL_USER);
        $password = parse_url($url,PHP_URL_PASS);
        $port = parse_url($url,PHP_URL_PORT);
        $oneme = $host === 'oneme.ru' || str_ends_with($host,'.oneme.ru');
        $okCdn = $host === 'okcdn.ru' || str_ends_with($host,'.okcdn.ru');
        if (
            $scheme !== 'https'
            || $host === ''
            || $user !== null
            || $password !== null
            || ($port !== null && (int) $port !== 443)
            || (!$oneme && !($allowMediaCdn && $okCdn))
        ) {
            throw new RuntimeException('MAX media URL uses an untrusted host.');
        }
    }

    public function deleteMessage(string $messageId): array
    {
        return $this->requireSuccessResult(
            $this->request('DELETE', '/messages?message_id=' . rawurlencode($messageId)),
            'message deletion'
        );
    }

    public function pinMessage(string $chatId, string $messageId, bool $notify = false): array
    {
        return $this->requireSuccessResult(
            $this->request(
                'PUT',
                '/chats/' . rawurlencode($chatId) . '/pin',
                ['message_id' => $messageId, 'notify' => $notify],
                true
            ),
            'message pin',
            true
        );
    }

    private function requireSuccessResult(array $result, string $operation, bool $singleDeliveryAttempt = false): array
    {
        if (($result['success'] ?? null) !== true) {
            $explicitFailure = ($result['success'] ?? null) === false
                || (is_string($result['code'] ?? null) && trim($result['code']) !== '');
            throw new GitPostedMaxApiException(
                'MAX ' . $operation . ' was rejected: '
                    . mb_substr((string) ($result['message'] ?? 'unknown error'), 0, 1000),
                0,
                '',
                $singleDeliveryAttempt && !$explicitFailure
            );
        }
        return $result;
    }

    public function subscribe(string $url, string $secret, array $types): array
    {
        return $this->requireSuccessResult(
            $this->request('POST', '/subscriptions', [
                'url' => $url,
                'secret' => $secret,
                'update_types' => array_values(array_unique($types)),
            ]),
            'webhook subscription'
        );
    }

    public function subscriptions(): array
    {
        return $this->request('GET', '/subscriptions');
    }

    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    public function setCommands(array $commands): array
    {
        $safe = [];
        foreach (array_slice($commands, 0, 32) as $command) {
            if (!is_array($command)) {
                continue;
            }
            $name = ltrim(trim((string) ($command['name'] ?? '')), '/');
            $description = trim((string) ($command['description'] ?? ''));
            if (preg_match('/^[a-z0-9_]{1,64}$/D', $name) !== 1 || $description === '') {
                throw new InvalidArgumentException('Invalid MAX bot command.');
            }
            $safe[] = [
                'name' => $name,
                'description' => mb_substr($description, 0, 256),
            ];
        }
        $result = $this->request('PATCH', '/me/commands', ['commands' => $safe]);
        $returned = $result['commands'] ?? ($safe === [] ? [] : null);
        if (!is_array($returned)) {
            throw new GitPostedMaxApiException('MAX did not confirm the command list.');
        }
        $confirmed = [];
        foreach ($returned as $command) {
            if (!is_array($command)) {
                throw new GitPostedMaxApiException('MAX returned an invalid command list.');
            }
            $confirmed[] = [
                'name' => ltrim(trim((string) ($command['name'] ?? '')), '/'),
                'description' => trim((string) ($command['description'] ?? '')),
            ];
        }
        $sortCommands = static function (array &$commands): void {
            usort(
                $commands,
                static fn (array $left, array $right): int => [
                    $left['name'],
                    $left['description'],
                ] <=> [
                    $right['name'],
                    $right['description'],
                ]
            );
        };
        $sortCommands($safe);
        $sortCommands($confirmed);
        if ($confirmed !== $safe) {
            throw new GitPostedMaxApiException('MAX confirmed a different command list.');
        }
        return $result;
    }

    public function unsubscribe(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('Invalid MAX webhook subscription URL.');
        }
        return $this->requireSuccessResult(
            $this->request('DELETE', '/subscriptions?url=' . rawurlencode($url)),
            'webhook unsubscription'
        );
    }

    public static function callbackButton(
        string $text,
        string $payload,
        string $intent = 'default'
    ): array {
        return [
            'type' => 'callback',
            'text' => mb_substr($text, 0, 128),
            'payload' => mb_substr($payload, 0, 256),
            'intent' => self::intent($intent),
        ];
    }

    public static function linkButton(
        string $text,
        string $url,
        string $intent = 'default'
    ): array {
        return [
            'type' => 'link',
            'text' => mb_substr($text, 0, 128),
            'url' => mb_substr($url, 0, 2048),
            'intent' => self::intent($intent),
        ];
    }

    public static function keyboard(array $rows): array
    {
        return ['type' => 'inline_keyboard', 'payload' => ['buttons' => array_values($rows)]];
    }

    public static function messageId(array $response): string
    {
        $candidates = [
            $response['message']['body']['mid'] ?? null,
            $response['message']['mid'] ?? null,
            $response['body']['mid'] ?? null,
            $response['mid'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_int($candidate) || is_string($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    public static function prepareMessageText(string $text, ?string $format): string
    {
        if (in_array($format, ['html', 'markdown'], true)) {
            // Slicing markup can cut a tag, link or entity in half. Preserve
            // the draft and reject it before dispatch instead of corrupting it.
            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                throw new InvalidArgumentException(
                    'Форматированный текст МАКС длиннее 4000 символов вместе с разметкой. Сократите текст или оформление; содержимое сохранено.'
                );
            }
            return $text;
        }
        return self::limitText($text);
    }

    public static function limitText(string $text): string
    {
        return mb_strlen($text) <= self::MAX_TEXT_LENGTH
            ? $text
            : mb_substr($text, 0, self::MAX_TEXT_LENGTH - 1) . '…';
    }

    public static function validateMediaAttachments(array $media, array $buttonRows = []): void
    {
        $attachments = self::outboundMedia($media);
        $files = array_filter($attachments, static fn(array $item): bool => ($item['type'] ?? '') === 'file');
        if ($files !== [] && count($attachments) !== 1) {
            throw new InvalidArgumentException(
                'В МАКС документ отправляется отдельно: один файл и, при необходимости, кнопки. Уберите остальные медиа.'
            );
        }
        // The keyboard is one attachment, regardless of its row/button count.
        if (count($attachments) + ($buttonRows !== [] ? 1 : 0) > 12) {
            throw new InvalidArgumentException(
                'В МАКС можно прикрепить до 12 медиа без кнопок или до 11 медиа с кнопками. Уберите лишние вложения.'
            );
        }
    }

    private static function outboundMedia(array $media): array
    {
        $attachments = [];
        foreach ($media as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $safe = GitPostedMaxForbiddenFileFallback::outboundAttachment($attachment);
            if ($safe !== null) {
                $attachments[] = $safe;
            }
        }
        return $attachments;
    }

    private static function intent(string $intent): string
    {
        return in_array($intent, ['default', 'positive', 'negative'], true) ? $intent : 'default';
    }
}

final class GitPostedMaxSchema
{
    private const SCHEMA_VERSION = 5;

    /** @var array<int,bool> */
    private static array $runtimeEnsured = [];

    /**
     * Runtime requests only read one tiny version row. Full DDL and cleanup
     * are executed once after deployment, or whenever the schema version is
     * bumped, instead of on every webhook and cron invocation.
     */
    public static function ensureRuntime(PDO $pdo): void
    {
        $connectionId = spl_object_id($pdo);
        if (isset(self::$runtimeEnsured[$connectionId])) {
            return;
        }
        try {
            $version = (int) ($pdo->query(
                "SELECT schema_version FROM posted_max_schema_meta WHERE id=1 LIMIT 1"
            )->fetchColumn() ?: 0);
        } catch (Throwable) {
            $version = 0;
        }
        if ($version < self::SCHEMA_VERSION) {
            self::ensure($pdo);
        }
        self::$runtimeEnsured[$connectionId] = true;
    }

    public static function ensure(PDO $pdo): void
    {
        PostedAccounts::installSchema($pdo);
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_users (
                max_user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                username VARCHAR(190) DEFAULT NULL,
                display_name VARCHAR(255) DEFAULT NULL,
                private_chat_id VARCHAR(64) DEFAULT NULL,
                linked_telegram_id BIGINT DEFAULT NULL,
                role ENUM('user') NOT NULL DEFAULT 'user',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                user_timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
                state VARCHAR(64) DEFAULT NULL,
                state_data JSON DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_max_users_telegram (linked_telegram_id),
                KEY idx_posted_max_users_chat (private_chat_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec("UPDATE posted_max_users SET role='user' WHERE role<>'user'");
        $roleType = strtolower((string) ($pdo->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_users'
               AND COLUMN_NAME='role'
             LIMIT 1"
        )->fetchColumn() ?: ''));
        if ($roleType !== "enum('user')") {
            $pdo->exec(
                "ALTER TABLE posted_max_users
                 MODIFY role ENUM('user') NOT NULL DEFAULT 'user'"
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_channels (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                chat_id VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                chat_type VARCHAR(32) DEFAULT NULL,
                bot_status VARCHAR(32) DEFAULT NULL,
                can_post TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_pin TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                rights_json JSON DEFAULT NULL,
                rights_checked_at DATETIME DEFAULT NULL,
                added_by VARCHAR(64) DEFAULT NULL,
                owner_checked_at DATETIME DEFAULT NULL,
                schedule_json JSON DEFAULT NULL,
                schedule_timezone VARCHAR(64) DEFAULT NULL,
                post_silent TINYINT(1) NOT NULL DEFAULT 0,
                post_pin TINYINT(1) NOT NULL DEFAULT 0,
                post_delete_minutes INT UNSIGNED DEFAULT NULL,
                post_repeat_interval_minutes INT UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                admin_blocked TINYINT(1) NOT NULL DEFAULT 0,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_max_channels_chat (chat_id),
                KEY idx_posted_max_channels_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'can_edit',
            'ALTER TABLE posted_max_channels ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_post'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'owner_checked_at',
            'ALTER TABLE posted_max_channels ADD COLUMN owner_checked_at DATETIME DEFAULT NULL AFTER added_by'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'schedule_json',
            'ALTER TABLE posted_max_channels ADD COLUMN schedule_json JSON DEFAULT NULL AFTER added_by'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'schedule_timezone',
            'ALTER TABLE posted_max_channels ADD COLUMN schedule_timezone VARCHAR(64) DEFAULT NULL AFTER schedule_json'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'post_silent',
            'ALTER TABLE posted_max_channels ADD COLUMN post_silent TINYINT(1) NOT NULL DEFAULT 0 AFTER schedule_timezone'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'post_pin',
            'ALTER TABLE posted_max_channels ADD COLUMN post_pin TINYINT(1) NOT NULL DEFAULT 0 AFTER post_silent'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'post_delete_minutes',
            'ALTER TABLE posted_max_channels ADD COLUMN post_delete_minutes INT UNSIGNED DEFAULT NULL AFTER post_pin'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'post_repeat_interval_minutes',
            'ALTER TABLE posted_max_channels ADD COLUMN post_repeat_interval_minutes INT UNSIGNED DEFAULT NULL AFTER post_delete_minutes'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'admin_blocked',
            'ALTER TABLE posted_max_channels ADD COLUMN admin_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_channels',
            'is_archived',
            'ALTER TABLE posted_max_channels ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_permissions (
                max_user_id VARCHAR(64) NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                can_post TINYINT(1) NOT NULL DEFAULT 1,
                can_schedule TINYINT(1) NOT NULL DEFAULT 1,
                can_manage TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (max_user_id, channel_id),
                KEY idx_posted_max_permissions_channel (channel_id),
                CONSTRAINT fk_posted_max_permissions_channel
                    FOREIGN KEY (channel_id) REFERENCES posted_max_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo,'posted_max_permissions','is_ad_manager','ALTER TABLE posted_max_permissions ADD COLUMN is_ad_manager TINYINT(1) NOT NULL DEFAULT 0 AFTER can_manage');
        self::ensureColumn($pdo,'posted_max_permissions','manager_description','ALTER TABLE posted_max_permissions ADD COLUMN manager_description VARCHAR(250) DEFAULT NULL AFTER is_ad_manager');
        self::ensureColumn($pdo,'posted_max_permissions','post_quota_remaining','ALTER TABLE posted_max_permissions ADD COLUMN post_quota_remaining INT UNSIGNED DEFAULT NULL AFTER manager_description');
        self::ensureColumn($pdo,'posted_max_permissions','pin_quota_remaining','ALTER TABLE posted_max_permissions ADD COLUMN pin_quota_remaining INT UNSIGNED DEFAULT NULL AFTER post_quota_remaining');
        $pdo->exec("CREATE TABLE IF NOT EXISTS posted_max_manager_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            max_user_id VARCHAR(64) NOT NULL, channel_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL, post_quota_remaining INT UNSIGNED DEFAULT NULL,
            pin_quota_remaining INT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_posted_max_manager_category_name(max_user_id,channel_id,name),
            KEY idx_posted_max_manager_categories_channel(channel_id),
            CONSTRAINT fk_posted_max_manager_categories_channel FOREIGN KEY(channel_id) REFERENCES posted_max_channels(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::ensureColumn($pdo,'posted_max_manager_categories','is_active','ALTER TABLE posted_max_manager_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER pin_quota_remaining');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_posts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                channel_id BIGINT UNSIGNED NOT NULL,
                author_max_user_id VARCHAR(64) NOT NULL,
                text MEDIUMTEXT DEFAULT NULL,
                text_html MEDIUMTEXT DEFAULT NULL,
                attachments_json JSON DEFAULT NULL,
                forward_message_id VARCHAR(255) DEFAULT NULL,
                buttons_json JSON DEFAULT NULL,
                status ENUM('draft','scheduled','publishing','max_pending','sent','cancelled','deleted','failed') NOT NULL DEFAULT 'draft',
                scheduled_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                max_message_id VARCHAR(255) DEFAULT NULL,
                notify TINYINT(1) NOT NULL DEFAULT 1,
                pin_after_publish TINYINT(1) NOT NULL DEFAULT 0,
                delete_after_minutes INT UNSIGNED DEFAULT NULL,
                repeat_interval_minutes INT UNSIGNED DEFAULT NULL,
                repeat_remaining SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                repeat_next_at DATETIME DEFAULT NULL,
                error_text TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_max_posts_due (status, scheduled_at),
                KEY idx_posted_max_posts_repeat (status, repeat_next_at),
                KEY idx_posted_max_posts_author (author_max_user_id, status),
                CONSTRAINT fk_posted_max_posts_channel
                    FOREIGN KEY (channel_id) REFERENCES posted_max_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn(
            $pdo,
            'posted_max_posts',
            'text_html',
            'ALTER TABLE posted_max_posts ADD COLUMN text_html MEDIUMTEXT DEFAULT NULL AFTER text'
        );
        self::ensureColumn($pdo,'posted_max_posts','manager_category_id','ALTER TABLE posted_max_posts ADD COLUMN manager_category_id BIGINT UNSIGNED DEFAULT NULL AFTER author_max_user_id');
        $postStatusType = strtolower((string) ($pdo->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_posts'
               AND COLUMN_NAME='status'
             LIMIT 1"
        )->fetchColumn() ?: ''));
        if (!str_contains($postStatusType, "'max_pending'")) {
            $pdo->exec(
                "ALTER TABLE posted_max_posts
                 MODIFY status ENUM(
                   'draft','scheduled','publishing','max_pending',
                   'sent','cancelled','deleted','failed'
                 ) NOT NULL DEFAULT 'draft'"
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_publish_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                attempt_key CHAR(36) NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                attempt_kind ENUM('initial','repeat') NOT NULL,
                status ENUM('sending','preparing','pending','completed','failed','needs_review') NOT NULL DEFAULT 'sending',
                request_json JSON NOT NULL,
                fingerprint CHAR(64) NOT NULL,
                baseline_message_ids_json JSON NOT NULL,
                gate_post_id BIGINT UNSIGNED DEFAULT NULL,
                gate_chat_id VARCHAR(64) DEFAULT NULL,
                max_message_id VARCHAR(255) DEFAULT NULL,
                check_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_checked_at DATETIME DEFAULT NULL,
                requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                deadline_at DATETIME NOT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                pin_status ENUM('pending','processing','done','failed','not_required')
                    NOT NULL DEFAULT 'not_required',
                pin_started_at DATETIME DEFAULT NULL,
                error_text TEXT DEFAULT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
                    ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_posted_max_attempt_key (attempt_key),
                UNIQUE KEY uq_posted_max_attempt_message (max_message_id),
                UNIQUE KEY uq_posted_max_attempt_gate_post (gate_post_id),
                UNIQUE KEY uq_posted_max_attempt_gate_chat (gate_chat_id),
                KEY idx_posted_max_attempt_queue (status, deadline_at),
                KEY idx_posted_max_attempt_post (post_id, created_at),
                CONSTRAINT fk_posted_max_attempt_post
                    FOREIGN KEY (post_id) REFERENCES posted_max_posts(id) ON DELETE CASCADE,
                CONSTRAINT fk_posted_max_attempt_channel
                    FOREIGN KEY (channel_id) REFERENCES posted_max_channels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn($pdo,'posted_max_publish_attempts','manager_post_quota_charged','ALTER TABLE posted_max_publish_attempts ADD COLUMN manager_post_quota_charged TINYINT(1) NOT NULL DEFAULT 0 AFTER pin_started_at');
        self::ensureColumn($pdo,'posted_max_publish_attempts','manager_pin_quota_charged','ALTER TABLE posted_max_publish_attempts ADD COLUMN manager_pin_quota_charged TINYINT(1) NOT NULL DEFAULT 0 AFTER manager_post_quota_charged');
        self::ensureColumn($pdo,'posted_max_publish_attempts','pin_attempts','ALTER TABLE posted_max_publish_attempts ADD COLUMN pin_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_started_at');
        $attemptStatusType = strtolower((string) ($pdo->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_publish_attempts'
               AND COLUMN_NAME='status'
             LIMIT 1"
        )->fetchColumn() ?: ''));
        if (!str_contains($attemptStatusType, "'preparing'")) {
            $pdo->exec(
                "ALTER TABLE posted_max_publish_attempts
                 MODIFY status ENUM(
                   'sending','preparing','pending','completed','failed','needs_review'
                 ) NOT NULL DEFAULT 'sending'"
            );
        }
        $pinStartedColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_publish_attempts'
               AND COLUMN_NAME='pin_started_at'"
        )->fetchColumn();
        if ($pinStartedColumn === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_publish_attempts
                 ADD COLUMN pin_started_at DATETIME DEFAULT NULL AFTER pin_status"
            );
        }
        $reconcileIndex = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_publish_attempts'
               AND INDEX_NAME='idx_posted_max_attempt_reconcile'"
        )->fetchColumn();
        if ($reconcileIndex === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_publish_attempts
                 ADD KEY idx_posted_max_attempt_reconcile
                   (status,last_checked_at,requested_at)"
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                post_id BIGINT UNSIGNED NOT NULL,
                max_message_id VARCHAR(255) NOT NULL,
                published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delete_at DATETIME DEFAULT NULL,
                deleted_at DATETIME DEFAULT NULL,
                delete_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                delete_started_at DATETIME DEFAULT NULL,
                error_text TEXT DEFAULT NULL,
                UNIQUE KEY uq_posted_max_delivery_message (max_message_id),
                KEY idx_posted_max_deliveries_delete (deleted_at, delete_at, delete_started_at),
                CONSTRAINT fk_posted_max_deliveries_post
                    FOREIGN KEY (post_id) REFERENCES posted_max_posts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $publishedAtColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_deliveries'
               AND COLUMN_NAME='published_at'"
        )->fetchColumn();
        if ($publishedAtColumn === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_deliveries
                 ADD COLUMN published_at DATETIME DEFAULT NULL AFTER max_message_id"
            );
            $pdo->exec(
                "UPDATE posted_max_deliveries delivery
                 JOIN posted_max_posts post ON post.id=delivery.post_id
                 SET delivery.published_at=COALESCE(
                   post.published_at,
                   post.created_at,
                   NOW()
                 )
                 WHERE delivery.published_at IS NULL"
            );
            $pdo->exec(
                "ALTER TABLE posted_max_deliveries
                 MODIFY published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
            );
        }
        $deleteStartedColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_deliveries'
               AND COLUMN_NAME='delete_started_at'"
        )->fetchColumn();
        if ($deleteStartedColumn === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_deliveries
                 ADD COLUMN delete_started_at DATETIME DEFAULT NULL AFTER delete_attempts"
            );
        }
        $deleteIndexColumns = (string) ($pdo->query(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_deliveries'
               AND INDEX_NAME='idx_posted_max_deliveries_delete'"
        )->fetchColumn() ?: '');
        if ($deleteIndexColumns !== 'deleted_at,delete_at,delete_started_at') {
            $replaceDeleteIndex = 'ALTER TABLE posted_max_deliveries ';
            if ($deleteIndexColumns !== '') {
                $replaceDeleteIndex .= 'DROP INDEX idx_posted_max_deliveries_delete, ';
            }
            $replaceDeleteIndex .=
                'ADD KEY idx_posted_max_deliveries_delete '
                . '(deleted_at,delete_at,delete_started_at)';
            $pdo->exec($replaceDeleteIndex);
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_max_user_id VARCHAR(64) NOT NULL,
                title VARCHAR(190) NOT NULL,
                text MEDIUMTEXT NOT NULL,
                buttons_json JSON DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_max_templates_owner (owner_max_user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $templateMediaColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_templates'
               AND COLUMN_NAME='attachments_json'"
        )->fetchColumn();
        if ($templateMediaColumn === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_templates
                 ADD COLUMN attachments_json JSON DEFAULT NULL AFTER text"
            );
        }
        $forwardMessageColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME='posted_max_posts'
               AND COLUMN_NAME='forward_message_id'"
        )->fetchColumn();
        if ($forwardMessageColumn === 0) {
            $pdo->exec(
                "ALTER TABLE posted_max_posts
                 ADD COLUMN forward_message_id VARCHAR(255) DEFAULT NULL AFTER attachments_json"
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_updates (
                sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                update_key CHAR(64) NOT NULL PRIMARY KEY,
                update_type VARCHAR(64) NOT NULL,
                payload_json MEDIUMTEXT NOT NULL,
                status ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                error_text TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                processed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                actor_key CHAR(64) DEFAULT NULL,
                UNIQUE KEY uq_posted_max_updates_sequence (sequence_id),
                KEY idx_posted_max_updates_queue (status, attempts, updated_at),
                KEY idx_posted_max_updates_actor (actor_key,sequence_id,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_update_effects (
                update_key CHAR(64) NOT NULL PRIMARY KEY,
                effect_type VARCHAR(48) NOT NULL,
                result_json JSON NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                CONSTRAINT fk_posted_max_update_effect_update
                    FOREIGN KEY (update_key) REFERENCES posted_max_updates(update_key)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureColumn(
            $pdo,
            'posted_max_updates',
            'sequence_id',
            'ALTER TABLE posted_max_updates ADD COLUMN sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY uq_posted_max_updates_sequence(sequence_id)'
        );
        self::ensureColumn(
            $pdo,
            'posted_max_updates',
            'actor_key',
            'ALTER TABLE posted_max_updates ADD COLUMN actor_key CHAR(64) DEFAULT NULL, ADD KEY idx_posted_max_updates_actor(actor_key,sequence_id,status)'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_link_codes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                code_hash CHAR(64) NOT NULL,
                max_user_id VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                consumed_by_telegram_id BIGINT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_posted_max_link_codes_hash (code_hash),
                KEY idx_posted_max_link_codes_user (max_user_id, expires_at),
                KEY idx_posted_max_link_codes_expiry (expires_at, consumed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_link_attempts (
                telegram_user_id BIGINT NOT NULL PRIMARY KEY,
                failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                blocked_until DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_max_link_attempts_cleanup (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "DELETE permission
             FROM posted_max_permissions permission
             JOIN posted_max_channels channel
               ON channel.id=permission.channel_id
              AND channel.added_by=permission.max_user_id"
        );
        $pdo->exec(
            "UPDATE posted_max_users max_user
             JOIN posted_max_permissions permission
               ON permission.max_user_id=max_user.max_user_id
              AND permission.can_post=0
              AND permission.can_schedule=0
              AND permission.can_manage=0
             LEFT JOIN posted_max_posts state_post
               ON state_post.id=CAST(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(max_user.state_data,'$.post_id')),'')
                    AS UNSIGNED
                  )
              AND state_post.channel_id=permission.channel_id
             SET max_user.state=NULL,max_user.state_data=NULL
             WHERE JSON_UNQUOTE(JSON_EXTRACT(max_user.state_data,'$.channel_id'))
                       =CAST(permission.channel_id AS CHAR)
                OR state_post.id IS NOT NULL"
        );
        $pdo->exec(
            'DELETE FROM posted_max_permissions
             WHERE can_post=0 AND can_schedule=0 AND can_manage=0'
        );
        // MAX channels do not support notify=false. Normalize legacy settings
        // once during migration so old drafts cannot fail after deployment.
        $pdo->exec('UPDATE posted_max_channels SET post_silent=0 WHERE post_silent<>0');
        $pdo->exec('UPDATE posted_max_posts SET notify=1 WHERE notify<>1');
        $pdo->exec(
            "UPDATE posted_max_channels
             SET can_edit=IF(
               COALESCE(JSON_UNQUOTE(JSON_EXTRACT(rights_json,'$.is_admin')),'false')='true'
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(rights_json,'$.is_owner')),'false')='true',
               IF(
                 COALESCE(JSON_LENGTH(JSON_EXTRACT(rights_json,'$.permissions')),0)=0
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('edit'))
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('edit_message'))
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('post_edit_delete_message')),
                 1,0
               ),
               0
             ),
             can_delete=IF(
               COALESCE(JSON_UNQUOTE(JSON_EXTRACT(rights_json,'$.is_admin')),'false')='true'
               OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(rights_json,'$.is_owner')),'false')='true',
               IF(
                 COALESCE(JSON_LENGTH(JSON_EXTRACT(rights_json,'$.permissions')),0)=0
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('delete'))
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('delete_message'))
                 OR JSON_CONTAINS(JSON_EXTRACT(rights_json,'$.permissions'),JSON_QUOTE('post_edit_delete_message')),
                 1,0
               ),
               0
             )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_max_schema_meta (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                schema_version INT UNSIGNED NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::ensureFeatures($pdo);
        $version = $pdo->prepare(
            'INSERT INTO posted_max_schema_meta(id,schema_version) VALUES(1,:version)
             ON DUPLICATE KEY UPDATE schema_version=VALUES(schema_version)'
        );
        $version->execute(['version' => self::SCHEMA_VERSION]);
    }

    public static function ensureFeatures(PDO $pdo): void
    {
        foreach (['posted_max_posts', 'posted_max_channels', 'posted_max_templates'] as $table) {
            self::ensureColumn($pdo, $table, 'options_json', 'ALTER TABLE ' . $table . ' ADD COLUMN options_json JSON DEFAULT NULL');
        }
        self::ensureColumn($pdo, 'posted_max_deliveries', 'comment_json', 'ALTER TABLE posted_max_deliveries ADD COLUMN comment_json JSON DEFAULT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS posted_max_reactions (
            delivery_id BIGINT UNSIGNED NOT NULL,
            max_user_id VARCHAR(64) NOT NULL,
            reaction_key VARCHAR(32) NOT NULL,
            last_callback_id VARCHAR(128) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (delivery_id,max_user_id),
            KEY idx_posted_max_reactions_counts (delivery_id,reaction_key),
            CONSTRAINT fk_posted_max_reactions_delivery FOREIGN KEY (delivery_id)
                REFERENCES posted_max_deliveries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureColumn(
        PDO $pdo,
        string $table,
        string $column,
        string $alterSql
    ): void {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name=:table_name
               AND column_name=:column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        if ((int) $statement->fetchColumn() === 0) {
            $pdo->exec($alterSql);
        }
    }
}

/**
 * Public cross-platform account-link helper.
 *
 * Telegram bootstrap may require max-bootstrap.php and call:
 * GitPostedMaxLink::consume($pdo, $config, $telegramUserId, $sixDigitCode).
 */
final class GitPostedMaxLink
{
    public static function create(PDO $pdo, array $config, string $maxUserId): string
    {
        GitPostedMaxSchema::ensureRuntime($pdo);
        return self::insertCode($pdo, $config, $maxUserId);
    }

    public static function createLocked(PDO $pdo, array $config, string $maxUserId): string
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Создание кода связи требует активной транзакции.');
        }
        return self::insertCode($pdo, $config, $maxUserId);
    }

    private static function insertCode(PDO $pdo, array $config, string $maxUserId): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = self::hash($config, $code);
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO posted_max_link_codes(code_hash,max_user_id,expires_at)
                     VALUES(:hash,:max_user,DATE_ADD(NOW(),INTERVAL 10 MINUTE))"
                );
                $stmt->execute(['hash' => $hash, 'max_user' => $maxUserId]);
                return $code;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }
        throw new RuntimeException('Unable to create a unique account-link code.');
    }

    public static function consume(
        PDO $pdo,
        array $config,
        int $telegramUserId,
        string $code
    ): array {
        if ($telegramUserId <= 0 || preg_match('/^\d{6}$/D', $code) !== 1) {
            return ['ok' => false, 'message' => 'Код связи должен состоять ровно из 6 цифр.'];
        }
        GitPostedMaxSchema::ensureRuntime($pdo);
        PostedAccounts::ensureSchema($pdo);
        $hash = self::hash($config, $code);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT IGNORE INTO posted_max_link_attempts(telegram_user_id)
                 VALUES(:telegram)"
            )->execute(['telegram' => $telegramUserId]);
            $attemptStmt = $pdo->prepare(
                "SELECT failed_attempts,window_started_at,blocked_until
                 FROM posted_max_link_attempts
                 WHERE telegram_user_id=:telegram
                 LIMIT 1 FOR UPDATE"
            );
            $attemptStmt->execute(['telegram' => $telegramUserId]);
            $attempt = $attemptStmt->fetch();
            if (
                is_array($attempt)
                && !empty($attempt['blocked_until'])
                && strtotime((string) $attempt['blocked_until']) > time()
            ) {
                $pdo->commit();
                return [
                    'ok' => false,
                    'message' => 'Слишком много неверных кодов. Повторите попытку через 15 минут.',
                ];
            }
            if (
                is_array($attempt)
                && strtotime((string) ($attempt['window_started_at'] ?? '')) <= time() - 600
            ) {
                $pdo->prepare(
                    "UPDATE posted_max_link_attempts
                     SET failed_attempts=0,window_started_at=NOW(),blocked_until=NULL
                     WHERE telegram_user_id=:telegram"
                )->execute(['telegram' => $telegramUserId]);
                $attempt['failed_attempts'] = 0;
            }

            $stmt = $pdo->prepare(
                "SELECT id,max_user_id
                 FROM posted_max_link_codes
                 WHERE code_hash=:hash AND consumed_at IS NULL AND expires_at>NOW()
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['hash' => $hash]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $failedAttempts = (int) ($attempt['failed_attempts'] ?? 0) + 1;
                $blocked = $failedAttempts >= 5;
                $pdo->prepare(
                    "UPDATE posted_max_link_attempts
                     SET failed_attempts=:attempts,
                         blocked_until=IF(:blocked=1,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)
                     WHERE telegram_user_id=:telegram"
                )->execute([
                    'attempts' => $failedAttempts,
                    'blocked' => $blocked ? 1 : 0,
                    'telegram' => $telegramUserId,
                ]);
                $pdo->commit();
                return [
                    'ok' => false,
                    'message' => $blocked
                        ? 'Слишком много неверных кодов. Повторите попытку через 15 минут.'
                        : 'Код недействителен или уже истёк.',
                ];
            }

            $maxUserId = (string) $row['max_user_id'];
            PostedAccounts::linkTelegramMax($pdo, $telegramUserId, $maxUserId);
            $pdo->prepare(
                "UPDATE posted_max_users
                 SET linked_telegram_id=:telegram
                 WHERE max_user_id=:max_user"
            )->execute([
                'telegram' => $telegramUserId,
                'max_user' => $maxUserId,
            ]);
            $pdo->prepare(
                "UPDATE posted_max_link_codes
                 SET consumed_at=NOW(),consumed_by_telegram_id=:telegram
                 WHERE max_user_id=:max_user AND consumed_at IS NULL"
            )->execute([
                'telegram'=>$telegramUserId,
                'max_user'=>$maxUserId,
            ]);
            $pdo->prepare(
                'DELETE FROM posted_max_link_attempts WHERE telegram_user_id=:telegram'
            )->execute(['telegram' => $telegramUserId]);
            $pdo->commit();
            return [
                'ok' => true,
                'max_user_id' => $maxUserId,
                'telegram_user_id' => $telegramUserId,
                'message' => 'Аккаунты Telegram и МАКС связаны.',
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function hash(array $config, string $code): string
    {
        $pepper = trim((string) ($config['max_webhook_secret'] ?? ''));
        if ($pepper === '') {
            throw new RuntimeException('Связывание аккаунта МАКС временно недоступно.');
        }
        return hash_hmac('sha256', 'gitposted|max-link|' . $code, $pepper);
    }
}

final class GitPostedMaxBot
{
    use GitPostedMaxPostOptions;
    use GitPostedMaxInteractive;
    use GitPostedMaxComments;
    use GitPostedMaxPostLinks;

    private const UPDATE_TYPES = [
        'message_created',
        'message_callback',
        'bot_started',
        'bot_stopped',
        'bot_added',
        'bot_removed',
        'user_removed',
        'chat_title_changed',
        'message_removed',
        'bot_admin_permissions_changed',
    ];

    /** @var array<string,?bool> */
    private array $ownerManagerStatusCache = [];
    private ?string $processingUpdateKey = null;
    private ?array $draftCallbackContext = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly GitPostedMaxApi $api,
        private readonly array $config
    ) {
        GitPostedMaxSchema::ensureRuntime($pdo);
        PostedAccounts::ensureSchema($pdo);
    }

    public function setupWebhook(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('MAX webhook URL must be a valid HTTPS URL.');
        }
        $secret = trim((string) ($this->config['max_webhook_secret'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{5,256}$/D', $secret) !== 1) {
            throw new RuntimeException('MAX webhook secret is missing or invalid.');
        }
        return $this->api->subscribe($url, $secret, self::UPDATE_TYPES);
    }

    /** @return array<int,string> */
    public static function webhookUpdateTypes(): array
    {
        return self::UPDATE_TYPES;
    }

    public function subscriptions(): array
    {
        return $this->api->subscriptions();
    }

    public function botInfo(): array
    {
        return $this->api->me();
    }

    public function setCommands(): array
    {
        return $this->api->setCommands([
            ['name' => 'start', 'description' => 'Запустить GitPosted'],
            ['name' => 'menu', 'description' => 'Открыть главное меню'],
            ['name' => 'help', 'description' => 'Открыть справку'],
            ['name' => 'cancel', 'description' => 'Отменить текущее действие'],
        ]);
    }

    public function removeSubscription(string $url): array
    {
        return $this->api->unsubscribe($url);
    }

    public function enqueueUpdate(array $update, string $raw = ''): ?string
    {
        $type = trim((string) ($update['update_type'] ?? $update['type'] ?? 'unknown'));
        $key = $this->updateKey($update, $raw);
        $payload = $raw !== ''
            ? $raw
            : json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $actorKey = $this->maxUpdateActorKey($update, $key);
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO posted_max_updates(update_key,update_type,payload_json,actor_key)
             VALUES(:update_key,:update_type,:payload,:actor_key)"
        );
        $stmt->execute([
            'update_key' => $key,
            'update_type' => mb_substr($type, 0, 64),
            'payload' => $payload,
            'actor_key' => $actorKey,
        ]);
        if ($stmt->rowCount() === 1) {
            return $key;
        }

        $statusStmt = $this->pdo->prepare(
            "SELECT status,attempts,started_at
             FROM posted_max_updates WHERE update_key=:update_key LIMIT 1"
        );
        $statusStmt->execute(['update_key' => $key]);
        $row = $statusStmt->fetch();
        if (!is_array($row) || (string) $row['status'] === 'done' || (int) $row['attempts'] >= 5) {
            return null;
        }
        if ((string) $row['status'] === 'processing' && !empty($row['started_at'])) {
            $startedAt = strtotime((string) $row['started_at']) ?: time();
            if ($startedAt > time() - 120) {
                return null;
            }
        }
        return $key;
    }

    public function processUpdateByKey(string $key): bool
    {
        $lookup = $this->pdo->prepare(
            'SELECT sequence_id,actor_key,payload_json
             FROM posted_max_updates WHERE update_key=:update_key LIMIT 1'
        );
        $lookup->execute(['update_key' => $key]);
        $lookupRow = $lookup->fetch();
        if (!is_array($lookupRow)) {
            return false;
        }
        $actorKey = trim((string) ($lookupRow['actor_key'] ?? ''));
        if ($actorKey === '') {
            $storedUpdate = json_decode((string) $lookupRow['payload_json'], true);
            $actorKey = $this->maxUpdateActorKey(
                is_array($storedUpdate) ? $storedUpdate : [],
                $key
            );
            $this->pdo->prepare(
                'UPDATE posted_max_updates SET actor_key=:actor WHERE update_key=:update_key'
            )->execute(['actor' => $actorKey, 'update_key' => $key]);
        }
        $lockName = 'gitposted_max_update_' . substr($actorKey, 0, 32);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $lock->execute(['lock_name' => $lockName]);
        if ((int) ($lock->fetchColumn() ?: 0) !== 1) {
            return false;
        }
        $claimed = false;
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT * FROM posted_max_updates WHERE update_key=:update_key LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['update_key' => $key]);
            $row = $stmt->fetch();
            if (!is_array($row) || (string) $row['status'] === 'done' || (int) $row['attempts'] >= 5) {
                $this->pdo->commit();
                return false;
            }
            if ((string) $row['status'] === 'processing' && !empty($row['started_at'])) {
                $startedAt = strtotime((string) $row['started_at']) ?: time();
                if ($startedAt > time() - 120) {
                    $this->pdo->commit();
                    return false;
                }
            }
            $earlier = $this->pdo->prepare(
                "SELECT 1 FROM posted_max_updates
                 WHERE actor_key=:actor AND sequence_id<:sequence_id
                   AND status<>'done' AND attempts<5
                 LIMIT 1"
            );
            $earlier->execute([
                'actor' => $actorKey,
                'sequence_id' => (int) $row['sequence_id'],
            ]);
            if ($earlier->fetchColumn() !== false) {
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare(
                "UPDATE posted_max_updates
                 SET status='processing',attempts=attempts+1,started_at=NOW(),error_text=NULL
                 WHERE update_key=:update_key"
            )->execute(['update_key' => $key]);
            $claimed = true;
            $this->pdo->commit();

            $update = json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($update)) {
                throw new RuntimeException('Stored MAX update is invalid.');
            }
            $this->processingUpdateKey=$key;
            $this->handleUpdate($update);
            $this->pdo->prepare(
                "UPDATE posted_max_updates
                 SET status='done',processed_at=NOW(),error_text=NULL
                 WHERE update_key=:update_key"
            )->execute(['update_key' => $key]);
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($claimed) {
                $this->pdo->prepare(
                    "UPDATE posted_max_updates
                     SET status='failed',error_text=:error
                     WHERE update_key=:update_key"
                )->execute([
                    'error' => mb_substr($exception->getMessage(), 0, 4000),
                    'update_key' => $key,
                ]);
            }
            throw $exception;
        } finally {
            $this->processingUpdateKey=null;
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }

    public function runCron(int $limit = 20): array
    {
        $lock = (int) $this->pdo->query("SELECT GET_LOCK('gitposted_max_cron',0)")->fetchColumn();
        if ($lock !== 1) {
            return [
                'skipped' => 'already_running',
                'updates' => 0,
                'published' => 0,
                'repeated' => 0,
                'pending' => 0,
                'reconciled' => 0,
                'needs_review' => 0,
                'pins' => 0,
                'recovered' => 0,
                'deleted' => 0,
                'attempts_pruned' => 0,
                'owners_checked' => 0,
                'owners_revoked' => 0,
                'errors' => [],
            ];
        }

        $result = [
            'updates' => 0,
            'published' => 0,
            'repeated' => 0,
            'pending' => 0,
            'reconciled' => 0,
            'needs_review' => 0,
            'pins' => 0,
            'recovered' => 0,
            'deleted' => 0,
            'attempts_pruned' => 0,
            'owners_checked' => 0,
            'owners_revoked' => 0,
            'errors' => [],
        ];
        try {
            $owners = $this->revalidateSavedMaxOwners(min(10, max(1, $limit)));
            $result['owners_checked'] += $owners['checked'];
            $result['owners_revoked'] += $owners['revoked'];

            // No MAX request exists before a durable attempt row is inserted.
            // A stale publishing row without such an attempt is therefore
            // safe to recover after a killed PHP process.
            $recovered = $this->pdo->exec(
                "UPDATE posted_max_posts p
                 SET p.status='failed',
                     p.error_text='Предыдущий процесс остановился до отправки запроса в МАКС.'
                 WHERE p.status='publishing'
                   AND p.updated_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                   AND NOT EXISTS (
                      SELECT 1 FROM posted_max_publish_attempts attempt
                      WHERE attempt.post_id=p.id
                        AND attempt.status IN ('sending','preparing','pending','needs_review')
                    )"
            );
            $result['recovered'] += max(0, (int) $recovered);

            $updates = $this->pdo->query(
                "SELECT update_key
                 FROM posted_max_updates
                 WHERE attempts<5
                   AND (
                     status IN ('queued','failed')
                     OR (status='processing' AND started_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE))
                   )
                 ORDER BY sequence_id
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($updates as $key) {
                try {
                    if ($this->processUpdateByKey((string) $key)) {
                        $result['updates']++;
                    }
                } catch (Throwable $exception) {
                    $result['errors'][] = 'update ' . $key . ': ' . $exception->getMessage();
                }
            }

            $reconciliation = $this->reconcilePendingPublicationAttempts($limit);
            $result['reconciled'] += $reconciliation['reconciled'];
            $result['needs_review'] += $reconciliation['needs_review'];
            array_push($result['errors'], ...$reconciliation['errors']);
            $comments = $this->processPendingMaxComments($limit);
            $result['comments'] = $comments['sent'];
            array_push($result['errors'], ...$comments['errors']);
            $pinAttempts = $this->pdo->query(
                "SELECT id FROM posted_max_publish_attempts
                 WHERE status='completed'
                   AND (
                     pin_status='pending'
                     OR (
                       pin_status='processing'
                       AND (
                         pin_started_at IS NULL
                         OR pin_started_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                       )
                     )
                   )
                 ORDER BY confirmed_at,id
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($pinAttempts as $pinAttemptId) {
                try {
                    if ($this->finishPublicationPin((int) $pinAttemptId)) {
                        $result['pins']++;
                    }
                } catch (Throwable $exception) {
                    $result['errors'][] = 'MAX pin ' . $pinAttemptId . ': ' . $exception->getMessage();
                }
            }

            $processedChats = [];
            $scheduled = $this->pdo->query(
                "SELECT p.id,c.chat_id
                   FROM posted_max_posts p
                   JOIN posted_max_channels c ON c.id=p.channel_id
                   JOIN posted_max_users owner_user
                     ON owner_user.max_user_id=c.added_by AND owner_user.is_active=1
                   JOIN posted_max_users author_user
                     ON author_user.max_user_id=p.author_max_user_id AND author_user.is_active=1
                   LEFT JOIN posted_max_permissions author_permission
                     ON author_permission.max_user_id=p.author_max_user_id
                    AND author_permission.channel_id=p.channel_id
                   LEFT JOIN posted_max_manager_categories manager_category
                     ON manager_category.id=p.manager_category_id
                    AND manager_category.max_user_id=p.author_max_user_id
                    AND manager_category.channel_id=p.channel_id
                   WHERE p.status='scheduled' AND p.scheduled_at<=NOW()
                     AND c.is_active=1
                     AND c.added_by IS NOT NULL AND c.added_by<>''
                     AND (
                       p.author_max_user_id=c.added_by
                       OR author_permission.can_schedule=1
                     )
                     AND (
                       p.manager_category_id IS NULL
                       OR (
                         author_permission.is_ad_manager=1
                         AND manager_category.is_active=1
                       )
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM posted_max_publish_attempts active_attempt
                       WHERE active_attempt.gate_chat_id=c.chat_id
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM posted_max_publish_attempts pin_attempt
                       WHERE pin_attempt.chat_id=c.chat_id
                         AND pin_attempt.status='completed'
                         AND pin_attempt.pin_status IN ('pending','processing')
                     )
                 ORDER BY p.scheduled_at,p.id
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll();
            foreach ($scheduled as $scheduledPost) {
                $postId = (int) $scheduledPost['id'];
                $scheduledChatId = (string) $scheduledPost['chat_id'];
                if (isset($processedChats[$scheduledChatId])) {
                    continue;
                }
                try {
                    if ($this->claimScheduledPost($postId)) {
                        $publication = $this->publishPost($postId, false);
                        if ($publication === 'confirmed') {
                            $result['published']++;
                            if($this->hasUnresolvedMaxPinForChat($scheduledChatId)){
                                $processedChats[$scheduledChatId]=true;
                            }
                        } else {
                            $processedChats[$scheduledChatId] = true;
                            $result['pending']++;
                        }
                    }
                } catch (Throwable $exception) {
                    $processedChats[$scheduledChatId] = true;
                    $this->markPostFailed($postId, $exception);
                    $result['errors'][] = 'post ' . $postId . ': ' . $exception->getMessage();
                }
            }

            $repeats = $this->pdo->query(
                "SELECT p.id,c.chat_id,p.repeat_next_at AS selected_repeat_next_at
                   FROM posted_max_posts p
                   JOIN posted_max_channels c ON c.id=p.channel_id
                   JOIN posted_max_users owner_user
                     ON owner_user.max_user_id=c.added_by AND owner_user.is_active=1
                   JOIN posted_max_users author_user
                     ON author_user.max_user_id=p.author_max_user_id AND author_user.is_active=1
                   LEFT JOIN posted_max_permissions author_permission
                     ON author_permission.max_user_id=p.author_max_user_id
                    AND author_permission.channel_id=p.channel_id
                   LEFT JOIN posted_max_manager_categories manager_category
                     ON manager_category.id=p.manager_category_id
                    AND manager_category.max_user_id=p.author_max_user_id
                    AND manager_category.channel_id=p.channel_id
                   WHERE p.status='sent' AND p.repeat_remaining>0
                     AND p.repeat_next_at IS NOT NULL AND p.repeat_next_at<=NOW()
                     AND c.is_active=1
                     AND c.added_by IS NOT NULL AND c.added_by<>''
                     AND (
                       p.author_max_user_id=c.added_by
                       OR author_permission.can_post=1
                     )
                     AND (
                       p.manager_category_id IS NULL
                       OR (
                         author_permission.is_ad_manager=1
                         AND manager_category.is_active=1
                       )
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM posted_max_publish_attempts active_post_attempt
                       WHERE active_post_attempt.gate_post_id=p.id
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM posted_max_publish_attempts active_channel_attempt
                       WHERE active_channel_attempt.gate_chat_id=c.chat_id
                     )
                     AND NOT EXISTS (
                       SELECT 1 FROM posted_max_publish_attempts pin_attempt
                       WHERE pin_attempt.chat_id=c.chat_id
                         AND pin_attempt.status='completed'
                         AND pin_attempt.pin_status IN ('pending','processing')
                     )
                 ORDER BY p.repeat_next_at,p.id
                 LIMIT " . max(1, min(100, $limit))
            )->fetchAll();
            foreach ($repeats as $repeatPost) {
                $postId = (int) $repeatPost['id'];
                $repeatChatId = (string) $repeatPost['chat_id'];
                if (isset($processedChats[$repeatChatId])) {
                    continue;
                }
                try {
                    $publication = $this->publishPost($postId, true);
                    if ($publication === 'confirmed') {
                        $result['repeated']++;
                        if($this->hasUnresolvedMaxPinForChat($repeatChatId)){
                            $processedChats[$repeatChatId]=true;
                        }
                    } else {
                        $processedChats[$repeatChatId] = true;
                        $result['pending']++;
                    }
                } catch (Throwable $exception) {
                    $processedChats[$repeatChatId] = true;
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts p
                         SET repeat_next_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),error_text=:error
                         WHERE p.id=:id AND p.status='sent'
                           AND p.repeat_next_at=:selected_repeat_next_at
                           AND NOT EXISTS (
                             SELECT 1 FROM posted_max_publish_attempts active_attempt
                             WHERE active_attempt.gate_post_id=p.id
                           )"
                    )->execute([
                        'error' => mb_substr($exception->getMessage(), 0, 4000),
                        'id' => $postId,
                        'selected_repeat_next_at' => (string) $repeatPost['selected_repeat_next_at'],
                    ]);
                    $result['errors'][] = 'repeat ' . $postId . ': ' . $exception->getMessage();
                }
            }

            $deletion = $this->processDueMaxDeliveries(max(1, min(20, $limit)));
            $result['deleted'] += $deletion['deleted'];
            array_push($result['errors'], ...$deletion['errors']);

            $pruned = $this->pdo->exec(
                "DELETE FROM posted_max_publish_attempts
                 WHERE gate_post_id IS NULL
                   AND gate_chat_id IS NULL
                   AND (
                     status='failed'
                     OR (
                       status='completed'
                       AND pin_status IN ('done','failed','not_required')
                     )
                   )
                   AND updated_at<DATE_SUB(NOW(),INTERVAL 30 DAY)
                 ORDER BY id
                 LIMIT 500"
            );
            $result['attempts_pruned'] += max(0, (int) $pruned);
            $this->pdo->exec(
                "DELETE FROM posted_max_link_codes
                 WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)"
            );
            $this->pdo->exec(
                "DELETE FROM posted_max_updates
                 WHERE status='done' AND processed_at<DATE_SUB(NOW(),INTERVAL 14 DAY)"
            );
            return $result;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('gitposted_max_cron')");
        }
    }

    public function syncChannelById(
        string $chatId,
        string $ownerMaxId = '',
        bool $allowExistingClaim = true
    ): array
    {
        $chatId = trim($chatId);
        if (preg_match('/^-?\d+$/D', $chatId) !== 1) {
            throw new InvalidArgumentException('Некорректный ID канала МАКС.');
        }
        return $this->withMaxChannelSyncLock(
            $chatId,
            fn (): array => $this->syncChannelByIdLocked(
                $chatId,
                $ownerMaxId,
                $allowExistingClaim
            )
        );
    }

    private function syncChannelByIdLocked(
        string $chatId,
        string $ownerMaxId,
        bool $allowExistingClaim
    ): array
    {
        $chatId = trim($chatId);
        $ownerMaxId = trim($ownerMaxId);
        if (preg_match('/^-?\d+$/D', $chatId) !== 1) {
            throw new InvalidArgumentException('Некорректный ID канала МАКС.');
        }
        // On a fresh installation the seeded owner does not exist yet.  Create
        // the local user/account row before channel.added_by can reference it.
        if ($ownerMaxId !== '') {
            $this->upsertUser(['user_id' => $ownerMaxId], '');
        }
        $chat = $this->api->getChat($chatId);
        $membership = $this->api->getMyMembership($chatId);
        $permissions = array_values(array_filter(
            (array) ($membership['permissions'] ?? []),
            static fn ($value): bool => is_string($value)
        ));
        $isAdmin = !empty($membership['is_admin']) || !empty($membership['is_owner']);
        $canPost = $isAdmin && ($permissions === [] || $this->hasAny($permissions, ['write', 'post_edit_delete_message']));
        $canEdit = $isAdmin && ($permissions === [] || $this->hasAny($permissions, ['edit', 'edit_message', 'post_edit_delete_message']));
        $canPin = $isAdmin && ($permissions === [] || $this->hasAny($permissions, ['pin_message']));
        $canDelete = $isAdmin && ($permissions === [] || $this->hasAny($permissions, ['delete', 'delete_message', 'post_edit_delete_message']));
        $status = (string) ($chat['status'] ?? 'active');
        $active = $status === '' || $status === 'active';
        $title = trim((string) ($chat['title'] ?? ''));
        if ($title === '') {
            $title = 'МАКС ' . $chatId;
        }
        $snapshot = $this->pdo->prepare(
            'SELECT added_by FROM posted_max_channels WHERE chat_id=:chat LIMIT 1'
        );
        $snapshot->execute(['chat' => $chatId]);
        $snapshotOwner = trim((string) ($snapshot->fetchColumn() ?: ''));
        $snapshotOwnerStatus = $snapshotOwner !== ''
            ? $this->channelManagerStatus($chatId, $snapshotOwner)
            : null;
        $claimantStatus = $ownerMaxId !== ''
            ? (hash_equals($snapshotOwner, $ownerMaxId)
                ? $snapshotOwnerStatus
                : $this->channelManagerStatus($chatId, $ownerMaxId))
            : null;
        $ownerVerified = $claimantStatus === true;
        $rightsJson = $this->encodeJson([
            'is_admin' => $isAdmin,
            'is_owner' => !empty($membership['is_owner']),
            'permissions' => $permissions,
        ]);
        $storedOwner = '';
        $isStoredOwner = false;
        $channelId = 0;
        $this->pdo->beginTransaction();
        try {
            $locked = $this->pdo->prepare(
                'SELECT * FROM posted_max_channels
                 WHERE chat_id=:chat LIMIT 1 FOR UPDATE'
            );
            $locked->execute(['chat' => $chatId]);
            $existing = $locked->fetch();
            if ($ownerMaxId !== '') {
                $this->assertActiveMaxActorLocked($ownerMaxId);
            }
            if (!is_array($existing)) {
                $storedOwner = $ownerVerified ? $ownerMaxId : '';
                $insert = $this->pdo->prepare(
                    'INSERT INTO posted_max_channels
                        (chat_id,title,chat_type,bot_status,can_post,can_edit,can_pin,can_delete,
                         rights_json,rights_checked_at,added_by,owner_checked_at,is_active)
                     VALUES
                        (:chat,:title,:type,:status,:post,:edit,:pin,:delete,
                         :rights,NOW(),:owner,IF(:owner_checked=1,NOW(),NULL),:active)'
                );
                $insert->execute([
                    'chat' => $chatId,
                    'title' => mb_substr($title, 0, 255),
                    'type' => (string) ($chat['type'] ?? ''),
                    'status' => $status,
                    'post' => $canPost ? 1 : 0,
                    'edit' => $canEdit ? 1 : 0,
                    'pin' => $canPin ? 1 : 0,
                    'delete' => $canDelete ? 1 : 0,
                    'rights' => $rightsJson,
                    'owner' => $storedOwner !== '' ? $storedOwner : null,
                    'owner_checked' => $ownerVerified ? 1 : 0,
                    'active' => $active && $storedOwner !== '' ? 1 : 0,
                ]);
                $channelId = (int) $this->pdo->lastInsertId();
                $isStoredOwner = $ownerVerified && $storedOwner !== '';
            } else {
                $channelId = (int) $existing['id'];
                $lockedOwner = trim((string) ($existing['added_by'] ?? ''));
                $ownerSnapshotWasRevoked = self::maxOwnerSnapshotWasRevoked(
                    $snapshotOwner,
                    $lockedOwner,
                    $ownerMaxId
                );
                $storedOwner = $lockedOwner;
                $storedOwnerStatus = hash_equals($storedOwner, $snapshotOwner)
                    ? $snapshotOwnerStatus
                    : null;
                if ($storedOwner !== '' && $storedOwnerStatus === false) {
                    $this->revokeMaxChannelAccessLocked(
                        $channelId,
                        $storedOwner,
                        'Владелец больше не является администратором МАКС-канала.'
                    );
                    $storedOwner = '';
                }
                $isStoredOwner = $ownerVerified
                    && $storedOwner !== ''
                    && hash_equals($storedOwner, $ownerMaxId);
                if ($ownerVerified && $storedOwner === '' && $allowExistingClaim) {
                    if ($ownerSnapshotWasRevoked) {
                        throw new RuntimeException(
                            'Владелец канала был отозван во время проверки МАКС. Повторите подключение.'
                        );
                    }
                    $storedOwner = $ownerMaxId;
                    $isStoredOwner = true;
                }
                $ownerWasChecked = $storedOwner !== ''
                    && (
                        ($isStoredOwner && $claimantStatus !== null)
                        || (hash_equals($storedOwner, $snapshotOwner) && $snapshotOwnerStatus !== null)
                    );
                $update = $this->pdo->prepare(
                    'UPDATE posted_max_channels
                     SET title=:title,chat_type=:type,bot_status=:status,
                         can_post=:post,can_edit=:edit,can_pin=:pin,can_delete=:delete,
                         rights_json=:rights,rights_checked_at=NOW(),
                         added_by=:owner,
                         owner_checked_at=CASE
                           WHEN :owner_present=0 THEN NULL
                           WHEN :owner_checked=1 THEN NOW()
                           ELSE owner_checked_at
                         END,
                         is_active=IF(admin_blocked=1,0,:active),
                         is_archived=IF(:accepted_owner=1,0,is_archived)
                     WHERE id=:channel'
                );
                $update->execute([
                    'title' => mb_substr($title, 0, 255),
                    'type' => (string) ($chat['type'] ?? ''),
                    'status' => $status,
                    'post' => $canPost ? 1 : 0,
                    'edit' => $canEdit ? 1 : 0,
                    'pin' => $canPin ? 1 : 0,
                    'delete' => $canDelete ? 1 : 0,
                    'rights' => $rightsJson,
                    'owner' => $storedOwner !== '' ? $storedOwner : null,
                    'owner_present' => $storedOwner !== '' ? 1 : 0,
                    'owner_checked' => $ownerWasChecked ? 1 : 0,
                    'active' => $active && $storedOwner !== '' ? 1 : 0,
                    'accepted_owner' => $isStoredOwner ? 1 : 0,
                    'channel' => $channelId,
                ]);
            }
            if ($isStoredOwner) {
                $this->pdo->prepare(
                    'DELETE FROM posted_max_permissions
                     WHERE max_user_id=:owner AND channel_id=:channel'
                )->execute(['owner' => $ownerMaxId, 'channel' => $channelId]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        if ($isStoredOwner) {
            $this->upsertUser(['user_id' => $ownerMaxId], '');
        }
        return [
            'id' => $channelId,
            'chat_id' => $chatId,
            'title' => $title,
            'can_post' => $canPost,
            'can_edit' => $canEdit,
            'can_pin' => $canPin,
            'can_delete' => $canDelete,
            'permissions' => $permissions,
            'active' => $active && $storedOwner !== '',
            'owner_verified' => $isStoredOwner,
            'owner_max_user_id' => $storedOwner,
        ];
    }

    private static function maxOwnerSnapshotWasRevoked(
        string $snapshotOwner,
        string $lockedOwner,
        string $claimantOwner
    ): bool {
        return $snapshotOwner !== ''
            && $lockedOwner === ''
            && $claimantOwner !== ''
            && hash_equals($snapshotOwner, $claimantOwner);
    }

    private function handleMaxRightsUpdate(array $update): void
    {
        $event = GitPostedMaxRightsEvent::parse($update);
        if ($event === null) return;
        $bot = $this->api->me();
        $botId = trim((string) ($bot['user_id'] ?? $bot['id'] ?? ''));
        if ($botId === '') throw new RuntimeException('Не удалось проверить бота для обновления прав МАКС.');
        if (!hash_equals($botId, $event['bot_id'])) return;
        if ($this->channelByChatId($event['chat_id']) === null) return;
        // The actor and permissions in the event never assign ownership.
        // An authoritative refresh also prevents stale events granting rights.
        $this->syncChannelById($event['chat_id'], '', false);
    }

    private function handleUpdate(array $update): void
    {
        $type = (string) ($update['update_type'] ?? $update['type'] ?? '');
        if ($type === 'bot_admin_permissions_changed') {
            $this->handleMaxRightsUpdate($update);
            return;
        }
        $user = $this->extractUser($update);
        $userId = $this->userId($user);
        $chatId = $this->eventChatId($update);

        if ($type === 'bot_started') {
            if ($userId === '' || $chatId === '') {
                return;
            }
            $this->upsertUser($user, $chatId);
            if (!$this->isMaxUserActive($userId)) {
                $this->api->sendMessage(
                    $chatId,
                    'Доступ к GitPosted отключён администратором.'
                );
                return;
            }
            $this->setState($userId, null, []);
            $this->sendMainMenu($chatId);
            return;
        }

        if ($type === 'bot_stopped') {
            if ($userId !== '') {
                $this->pdo->prepare(
                    "UPDATE posted_max_users
                     SET private_chat_id=NULL
                     WHERE max_user_id=:max_user"
                )->execute(['max_user' => $userId]);
            }
            return;
        }

        if ($type === 'bot_added') {
            if ($chatId === '') {
                return;
            }
            if ($userId !== '') {
                $this->upsertUser($user, '');
            }
            // Adding the bot only records its technical membership and rights.
            // Ownership is assigned later, after an explicit forwarded channel post.
            $this->syncChannelById($chatId, '', false);
            return;
        }

        if ($type === 'user_removed') {
            if ($chatId !== '' && $userId !== '') {
                $this->revokeMaxUserChannelAccessByChat($chatId, $userId);
            }
            return;
        }

        if ($type === 'bot_removed') {
            if ($chatId !== '') {
                $this->pdo->prepare(
                    "UPDATE posted_max_channels
                     SET is_active=0,bot_status='removed',can_post=0,can_edit=0,
                         can_pin=0,can_delete=0
                     WHERE chat_id=:chat"
                )->execute(['chat' => $chatId]);
            }
            return;
        }

        if ($type === 'chat_title_changed') {
            if ($chatId !== '') {
                $title = trim((string) ($update['title'] ?? $update['chat_title'] ?? ''));
                if ($title !== '') {
                    $this->pdo->prepare(
                        "UPDATE posted_max_channels SET title=:title WHERE chat_id=:chat"
                    )->execute(['title' => mb_substr($title, 0, 255), 'chat' => $chatId]);
                } else {
                    try {
                        $this->syncChannelById($chatId, '');
                    } catch (Throwable $exception) {
                        error_log('GitPosted MAX title sync: ' . $exception->getMessage());
                    }
                }
            }
            return;
        }

        if ($type === 'message_removed') {
            $messageId = $this->eventMessageId($update);
            if ($messageId !== '') {
                $this->pdo->prepare(
                    "UPDATE posted_max_deliveries
                     SET deleted_at=NOW(),delete_started_at=NULL,error_text=NULL
                     WHERE max_message_id=:message"
                )->execute(['message' => $messageId]);
            }
            return;
        }

        if ($type === 'message_created') {
            // Outgoing channel posts may be delivered here before the HTTP
            // response reaches PHP. Reconcile them before handleMessage()
            // discards bot/channel authors.
            $this->reconcileCreatedPublicationMessage($update, $chatId);
            try {
                $this->handleMessage($update, $user, $userId, $chatId);
            } catch (GitPostedMaxStaleInputException $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
            }
            return;
        }

        if ($type === 'message_callback') {
            $this->handleCallback($update, $user, $userId, $chatId);
        }
    }

    private function handleMessage(
        array $update,
        array $user,
        string $userId,
        string $chatId
    ): void {
        if ($userId === '' || $chatId === '' || !empty($user['is_bot'])) {
            return;
        }
        $knownChannel = $this->channelByChatId($chatId);
        $chatType = $this->eventChatType($update);
        $isDirect = $knownChannel === null
            && in_array($chatType, ['', 'dialog', 'private'], true);
        if (!$isDirect) {
            if ($knownChannel === null) {
                try {
                    $this->syncChannelById($chatId, '');
                } catch (Throwable $exception) {
                    error_log('GitPosted MAX channel message sync: ' . $exception->getMessage());
                }
            }
            return;
        }

        $this->upsertUser($user, $chatId);
        if (!$this->isMaxUserActive($userId)) {
            $this->api->sendMessage(
                $chatId,
                'Доступ к GitPosted отключён администратором.'
            );
            return;
        }
        $message = $this->extractMessage($update);
        $messageId = $this->eventMessageId($update);
        if ($messageId !== '') {
            try {
                $full = $this->api->getMessage($messageId);
                $message = $this->firstMessage($full) ?? $message;
            } catch (Throwable) {
            }
        }

        $account = $this->user($userId);
        $state = (string) ($account['state'] ?? '');
        $stateData = $this->decodeJson((string) ($account['state_data'] ?? ''));
        $forwardedChannelId = $this->forwardedChatId($message);
        if ($state === 'await_channel_ref' && $forwardedChannelId !== null) {
            try {
                $channel = $this->syncChannelById($forwardedChannelId, $userId);
                $this->setState($userId, null, []);
                $this->notifyChannelRegistration($userId, $channel);
            } catch (Throwable $exception) {
                error_log('GitPosted MAX forwarded channel registration: ' . $exception->getMessage());
                $this->api->sendMessage(
                    $chatId,
                    "Не удалось подключить канал из пересланного сообщения.\n\n"
                        . "Проверьте, что GitPosted добавлен администратором этого канала "
                        . "с правом публикации, а вы являетесь владельцем или администратором канала.\n\n"
                        . "Ошибка: " . mb_substr($exception->getMessage(), 0, 500)
                );
            }
            return;
        }
        if ($state === 'await_channel_ref') {
            $this->api->sendMessage(
                $chatId,
                'Перешлите любое сообщение именно из канала МАКС.',
                [],
                [[GitPostedMaxApi::callbackButton('← Назад', 'gp:settings')]]
            );
            return;
        }

        $contentMessage = $this->forwardedContentMessage($message) ?? $message;
        ['text' => $text, 'html' => $textHtml] = $this->trimmedMessageContent($contentMessage);
        try {
            $attachments = $this->reusableAttachments($contentMessage);
        } catch (InvalidArgumentException $exception) {
            $this->api->sendMessage($chatId, $exception->getMessage());
            return;
        }
        $forwardMessageId = $this->forwardedMessageId($message);

        if (preg_match('/^\/start(?:\s+(\S+))?$/iu', $text, $startMatch) === 1) {
            $this->setState($userId, null, []);
            $this->sendMainMenu($chatId);
            return;
        }
        if (preg_match('/^(?:\/menu|меню|start)$/iu', $text) === 1) {
            $this->setState($userId, null, []);
            $this->sendMainMenu($chatId);
            return;
        }
        if (preg_match('/^(?:\/help|помощь)$/iu', $text) === 1) {
            $this->sendHelp($chatId);
            return;
        }
        if (preg_match('/^(?:\/cancel|отмена)$/iu', $text) === 1) {
            $this->setState($userId, null, []);
            $this->sendMainMenu($chatId, 'Действие отменено.');
            return;
        }

        if ($this->handleMaxOptionsInput($chatId, $userId, $text, $message)
            || $this->handleMaxInteractiveInput($chatId, $userId, $text, $message)
            || $this->handleMaxCommentInput($chatId, $userId, $text, $message)) {
            return;
        }
        if ($this->handleMaxPostLinkMessage($chatId, $userId, $text, $message)) return;
        if ($state === 'await_channel_schedule') {
            $channelId = (int) ($stateData['channel_id'] ?? 0);
            if ($this->ownerChannel($userId, $channelId) === null) {
                $this->setState($userId, null, []);
                $this->api->sendMessage($chatId, 'Канал отключён или его владелец изменился.');
                return;
            }
            preg_match_all('/(?:[01]?\d|2[0-3]):[0-5]\d/u', $text, $matches);
            $times = [];
            foreach ((array) ($matches[0] ?? []) as $time) {
                [$hour, $minute] = array_pad(explode(':', (string) $time, 2), 2, '0');
                $times[] = sprintf('%02d:%02d', (int) $hour, (int) $minute);
            }
            $times = array_values(array_unique($times));
            if ($times === [] && !in_array(mb_strtolower($text), ['-', 'нет'], true)) {
                $this->api->sendMessage(
                    $chatId,
                    'Не нашёл время. Отправьте список в формате 09:00, 13:30, 18:00 либо «-», чтобы очистить расписание.'
                );
                return;
            }
            sort($times);
            $timezone = (string) ($account['user_timezone'] ?? 'Europe/Moscow');
            $this->withOwnedMaxChannelLock(
                $userId,
                $channelId,
                function () use ($channelId, $times, $timezone): void {
                    $this->pdo->prepare(
                        'UPDATE posted_max_channels
                         SET schedule_json=:schedule,schedule_timezone=:timezone
                         WHERE id=:channel'
                    )->execute([
                        'schedule' => $this->encodeJson($times),
                        'timezone' => $timezone,
                        'channel' => $channelId,
                    ]);
                }
            );
            $this->sendMaxChannelSchedule(
                $chatId,
                $userId,
                $channelId,
                'Расписание сохранено.'
            );
            return;
        }

        if (in_array($state, ['await_channel_post_delete', 'await_channel_post_repeat'], true)) {
            $channelId = (int) ($stateData['channel_id'] ?? 0);
            $kind = $state === 'await_channel_post_delete' ? 'delete' : 'repeat';
            if ($this->ownerChannel($userId, $channelId) === null) {
                $this->setState($userId, null, []);
                $this->api->sendMessage($chatId, 'Канал отключён или его владелец изменился.');
                return;
            }
            $maximum = $kind === 'delete' ? 10080 : 43200;
            $minutes = $this->parseFlexibleMinutes($text, $maximum);
            if ($minutes === null || ($kind === 'repeat' && $minutes > 0 && $minutes < 5)) {
                $this->api->sendMessage(
                    $chatId,
                    $kind === 'delete'
                        ? "Не удалось распознать срок.\n\nПримеры: 6, 6:30, 10д, 90мин или 0."
                        : "Не удалось распознать интервал.\n\nПримеры: 2, 6:30, 1д, 120мин или 0."
                );
                return;
            }
            $column = $kind === 'delete'
                ? 'post_delete_minutes'
                : 'post_repeat_interval_minutes';
            $this->withOwnedMaxChannelLock(
                $userId,
                $channelId,
                function () use ($channelId, $column, $minutes): void {
                    $this->pdo->prepare(
                        "UPDATE posted_max_channels
                         SET {$column}=:minutes
                         WHERE id=:channel"
                    )->execute([
                        'minutes' => $minutes > 0 ? $minutes : null,
                        'channel' => $channelId,
                    ]);
                }
            );
            $this->sendMaxChannelPostSettings(
                $chatId,
                $userId,
                $channelId,
                'Настройки сохранены.'
            );
            return;
        }

        if ($state === 'await_max_review_message') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $attemptId = (int) ($stateData['attempt_id'] ?? 0);
            $attempt = $this->activePublicationAttemptForPost($postId);
            if (
                $this->ownedPost($userId, $postId, 'post') === null
                || !is_array($attempt)
                || (int) $attempt['id'] !== $attemptId
                || (string) $attempt['status'] !== 'needs_review'
            ) {
                $this->setState($userId, null, []);
                $this->api->sendMessage(
                    $chatId,
                    'Эта ручная проверка уже завершена или устарела.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
                return;
            }
            if ($forwardMessageId === null) {
                $this->api->sendMessage(
                    $chatId,
                    'Перешлите сюда именно опубликованный пост из нужного канала МАКС.',
                    [],
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:view:' . $postId)]]
                );
                return;
            }
            try {
                $this->confirmPublicationAttemptAfterReview(
                    $postId,
                    $attemptId,
                    $forwardMessageId,
                    $userId
                );
            } catch (Throwable $exception) {
                $this->api->sendMessage(
                    $chatId,
                    "Этот пост не совпал с ожидаемой публикацией.\n\n"
                        . mb_substr($exception->getMessage(), 0, 500),
                    [],
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:view:' . $postId)]]
                );
                return;
            }
            $this->setState($userId, null, []);
            $this->api->sendMessage(
                $chatId,
                'Публикация найдена и связана с контент-планом. Повторная отправка не выполнялась.',
                [],
                [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
            );
            return;
        }

        if ($state === 'await_manager') {
            $channelId=(int)($stateData['channel_id']??0);
            $channel=$this->ownerChannel($userId,$channelId);
            if($channel===null){$this->setState($userId,null,[]);$this->sendMainMenu($chatId,'Только владелец канала управляет рекламными менеджерами.');return;}
            try{
                $manager=$this->resolveEditorCandidate($channel,$text,$message);
                $managerId=$this->userId($manager);
                if($managerId===''||hash_equals($userId,$managerId))throw new RuntimeException('Владелец не добавляется в список менеджеров.');
                $this->upsertUser($manager,'');
                $this->upsertMaxManager($channelId,$managerId,$userId);
            }catch(Throwable $exception){$this->api->sendMessage($chatId,$exception->getMessage(),[],[[GitPostedMaxApi::callbackButton('Отмена','gp:managers:'.$channelId.':0')]]);return;}
            $this->setState($userId,null,[]);
            [$managerText,$managerRows]=$this->maxManagerScreen($userId,$channelId,$managerId,0);
            $this->api->sendMessage($chatId,"Рекламный менеджер добавлен.\n\n".$managerText,[],$managerRows);return;
        }
        if ($state === 'await_manager_field') {
            $channelId=(int)($stateData['channel_id']??0); $managerId=(string)($stateData['manager_id']??'');
            $field=(string)($stateData['field']??''); $page=(int)($stateData['page']??0);
            try{$this->saveMaxManagerField($userId,$channelId,$managerId,$field,$text);}catch(Throwable $exception){$this->api->sendMessage($chatId,$exception->getMessage());return;}
            $this->setState($userId,null,[]); [$managerText,$managerRows]=$this->maxManagerScreen($userId,$channelId,$managerId,$page);
            $this->api->sendMessage($chatId,$managerText,[],$managerRows);return;
        }
        if($state==='await_manager_category_name'){
            $channelId=(int)($stateData['channel_id']??0);$managerId=(string)($stateData['manager_id']??'');$page=(int)($stateData['page']??0);$name=trim($text);
            if($name===''||mb_strlen($name)>100){$this->api->sendMessage($chatId,'Название должно содержать от 1 до 100 символов.');return;}
            try{
                $id=(int)$this->withOwnedMaxChannelLock(
                    $userId,
                    $channelId,
                    function()use($managerId,$channelId,$name):int{
                        $permission=$this->pdo->prepare(
                            'SELECT is_ad_manager FROM posted_max_permissions
                             WHERE max_user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE'
                        );
                        $permission->execute(['user'=>$managerId,'channel'=>$channelId]);
                        if((int)($permission->fetchColumn()?:0)!==1){
                            throw new RuntimeException('Настройки рекламного менеджера уже недоступны.');
                        }
                        return $this->createOrReactivateMaxManagerCategoryLocked($managerId,$channelId,$name);
                    }
                );
            }catch(Throwable $exception){
                $this->api->sendMessage($chatId,$exception->getMessage());
                return;
            }
            $this->setState($userId,null,[]);[$t,$r]=$this->maxManagerCategoryScreen($userId,$channelId,$managerId,$id,$page);$this->api->sendMessage($chatId,$t,[],$r);return;
        }
        if($state==='await_manager_category_field'){
            $channelId=(int)($stateData['channel_id']??0);$managerId=(string)($stateData['manager_id']??'');$id=(int)($stateData['category_id']??0);$page=(int)($stateData['page']??0);$field=(string)($stateData['field']??'');$input=trim($text);$unlimited=in_array(mb_strtolower($input),['безлимит','безлим','unlimited','∞'],true);
            if(!$unlimited&&preg_match('/^\d{1,9}$/D',$input)!==1){$this->api->sendMessage($chatId,'Отправьте целое число или «безлимит».');return;}
            if($this->ownerChannel($userId,$channelId)===null||!in_array($field,['posts','pins'],true))return;$column=$field==='posts'?'post_quota_remaining':'pin_quota_remaining';$value=$unlimited?null:(int)$input;$stmt=$this->pdo->prepare("UPDATE posted_max_manager_categories SET {$column}=:value WHERE id=:id AND max_user_id=:user AND channel_id=:channel AND is_active=1");$stmt->bindValue(':value',$value,$value===null?PDO::PARAM_NULL:PDO::PARAM_INT);$stmt->bindValue(':id',$id,PDO::PARAM_INT);$stmt->bindValue(':user',$managerId);$stmt->bindValue(':channel',$channelId,PDO::PARAM_INT);$stmt->execute();$this->setState($userId,null,[]);[$t,$r]=$this->maxManagerCategoryScreen($userId,$channelId,$managerId,$id,$page);$this->api->sendMessage($chatId,$t,[],$r);return;
        }
        if ($state === 'await_editor') {
            $channelId = (int) ($stateData['channel_id'] ?? 0);
            $channel = $this->ownerChannel($userId, $channelId);
            if ($channel === null) {
                $this->setState($userId, null, []);
                $this->sendMainMenu($chatId, 'Только владелец канала управляет редакторами.');
                return;
            }
            try {
                $editor = $this->resolveEditorCandidate($channel, $text, $message);
                $editorId = $this->userId($editor);
                if ($editorId === '' || hash_equals($userId, $editorId)) {
                    throw new RuntimeException('Владелец не добавляется в список редакторов.');
                }
                $this->upsertUser($editor, '');
                if (!$this->isMaxUserActive($editorId)) {
                    throw new RuntimeException(
                        'Этот пользователь отключён администратором GitPosted.'
                    );
                }
                $this->upsertEditor(
                    $channelId,
                    $editorId,
                    true,
                    true,
                    true,
                    $userId
                );
            } catch (Throwable $exception) {
                $this->api->sendMessage(
                    $chatId,
                    $exception->getMessage(),
                    [],
                    [[GitPostedMaxApi::callbackButton('Отмена', 'gp:editors:' . $channelId . ':0')]]
                );
                return;
            }
            $this->setState($userId, null, []);
            [$editorText, $editorRows] = $this->editorsScreen($userId, $channelId, 0);
            $this->api->sendMessage($chatId, "Редактор добавлен.\n\n" . $editorText, [], $editorRows);
            return;
        }

        if ($state === 'await_content') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'post');
            if ($post === null || (string) $post['status'] !== 'draft') {
                $this->setState($userId, null, []);
                $this->sendMainMenu($chatId, 'Черновик не найден.');
                return;
            }
            try {
                GitPostedMaxApi::validateMediaAttachments(
                    $forwardMessageId === null ? $attachments : [],
                    $this->decodeJson((string) ($post['buttons_json'] ?? ''))
                );
            } catch (InvalidArgumentException $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
                return;
            }
            if ($text === '' && $attachments === [] && $forwardMessageId === null) {
                $this->api->sendMessage($chatId, 'Отправьте текст или поддерживаемое медиа.');
                return;
            }
            if (mb_strlen($text) > 4000) {
                $this->api->sendMessage($chatId, 'Текст длиннее лимита МАКС — 4000 символов.');
                return;
            }
            try {
                $this->maxEffectivePostContent(array_replace($post, [
                    'text' => $text, 'text_html' => $forwardMessageId === null ? $textHtml : null,
                    'attachments_json' => $this->encodeJson($attachments), 'forward_message_id' => $forwardMessageId,
                ]));
            } catch (RuntimeException $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
                return;
            }
            $this->withManagedMaxPostLock(
                $userId,
                $postId,
                'post',
                ['draft'],
                function (array $lockedPost) use ($postId, $text, $textHtml, $attachments, $forwardMessageId): void {
                    GitPostedMaxApi::validateMediaAttachments(
                        $forwardMessageId === null ? $attachments : [],
                        $this->decodeJson((string) ($lockedPost['buttons_json'] ?? ''))
                    );
                    $this->maxEffectivePostContent(array_replace($lockedPost, [
                        'text' => $text,
                        'text_html' => $forwardMessageId === null ? $textHtml : null,
                        'attachments_json' => $this->encodeJson($attachments),
                        'forward_message_id' => $forwardMessageId,
                    ]));
                    $update = $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET text=:text,text_html=:text_html,attachments_json=:attachments,
                             forward_message_id=:forward_message_id,error_text=NULL
                         WHERE id=:id AND status='draft'"
                    );
                    $update->execute([
                        'text' => $text !== '' ? $text : null,
                        'text_html' => $forwardMessageId === null ? $textHtml : null,
                        'attachments' => $this->encodeJson($attachments),
                        'forward_message_id' => $forwardMessageId,
                        'id' => $postId,
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Состояние черновика изменилось.');
                    }
                }
            );
            $this->setState($userId, null, []);
            $this->showDraft($chatId, $userId, $postId);
            return;
        }

        if ($state === 'await_edit_text') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'manage');
            if (
                $post === null
                || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
                || $text === ''
                || mb_strlen($text) > 4000
            ) {
                $this->api->sendMessage($chatId, 'Отправьте текст длиной от 1 до 4000 символов.');
                return;
            }
            try {
                $this->maxEffectivePostContent(array_replace($post, [
                    'text' => $text, 'text_html' => $textHtml, 'forward_message_id' => null,
                ]));
            } catch (RuntimeException $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
                return;
            }
            if ((string) $post['status'] === 'sent') {
                // Keep the proposed edit in user state until every live delivery
                // accepts it. Failed webhook attempts can then be retried safely.
                $this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'manage',
                    ['sent'],
                    function (array $lockedPost) use ($postId, $text, $textHtml, $stateData, $userId): void {
                        $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_edit_text');
                        $this->syncPublishedPostEdit($postId, ['text' => $text, 'text_html' => $textHtml]);
                        $update = $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET text=:text,text_html=:text_html,forward_message_id=NULL
                             WHERE id=:id AND status='sent'"
                        );
                        $update->execute(['text' => $text, 'text_html' => $textHtml, 'id' => $postId]);
                        if ($update->rowCount() !== 1) {
                            throw new RuntimeException('Состояние поста изменилось во время редактирования.');
                        }
                    }
                );
                $this->setState($userId, null, []);
                $this->api->sendMessage(
                    $chatId,
                    'Опубликованный пост изменён.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
                return;
            }
            $this->withManagedMaxPostLock(
                $userId,
                $postId,
                'manage',
                ['draft', 'scheduled', 'failed'],
                function (array $lockedPost) use ($postId, $text, $textHtml, $stateData, $userId): void {
                    $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_edit_text');
                    $this->maxEffectivePostContent(array_replace($lockedPost, [
                        'text' => $text, 'text_html' => $textHtml, 'forward_message_id' => null,
                    ]));
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET text=:text,text_html=:text_html,forward_message_id=NULL
                         WHERE id=:id AND status IN ('draft','scheduled','failed')"
                    )->execute(['text' => $text, 'text_html' => $textHtml, 'id' => $postId]);
                }
            );
            $this->setState($userId, null, []);
            if ((string) $post['status'] === 'draft') {
                $this->showDraft($chatId, $userId, $postId);
            } else {
                $this->api->sendMessage(
                    $chatId,
                    'Текст запланированного поста изменён.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            }
            return;
        }

        if ($state === 'await_media_update') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'manage');
            if (
                $post === null
                || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
            ) {
                $this->setState($userId, null, []);
                $this->sendMainMenu($chatId, 'Пост не найден.');
                return;
            }
            if ($text === '-' && $attachments === []) {
                if ((string) $post['status'] === 'sent') {
                    $this->withManagedMaxPostLock(
                        $userId,
                        $postId,
                        'manage',
                        ['sent'],
                        function (array $lockedPost) use ($postId, $stateData, $userId): void {
                            $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_media_update');
                            $this->syncPublishedPostEdit($postId, ['attachments' => []]);
                            $update = $this->pdo->prepare(
                                "UPDATE posted_max_posts
                                 SET attachments_json=NULL,forward_message_id=NULL
                                 WHERE id=:id AND status='sent'"
                            );
                            $update->execute(['id' => $postId]);
                            if ($update->rowCount() !== 1) {
                                throw new RuntimeException(
                                    'Состояние поста изменилось во время редактирования.'
                                );
                            }
                        }
                    );
                } else {
                    $this->withManagedMaxPostLock(
                        $userId,
                        $postId,
                        'manage',
                        ['draft', 'scheduled', 'failed'],
                        function (array $lockedPost) use ($postId, $stateData, $userId): void {
                            $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_media_update');
                            $this->pdo->prepare(
                                "UPDATE posted_max_posts
                                 SET attachments_json=NULL,forward_message_id=NULL
                                 WHERE id=:id AND status IN ('draft','scheduled','failed')"
                            )->execute(['id' => $postId]);
                        }
                    );
                }
                $this->setState($userId, null, []);
                if ((string) $post['status'] === 'sent') {
                    $this->api->sendMessage(
                        $chatId,
                        'Медиа опубликованного поста удалено.',
                        [],
                        [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                    );
                } elseif ((string) $post['status'] === 'draft') {
                    $this->showDraft($chatId, $userId, $postId);
                } else {
                    $this->api->sendMessage(
                        $chatId,
                        'Медиа запланированного поста удалено.',
                        [],
                        [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                    );
                }
                return;
            }
            if ($attachments === []) {
                $this->api->sendMessage(
                    $chatId,
                    'Отправьте новое изображение, видео, аудио или файл. Для удаления медиа отправьте «-».'
                );
                return;
            }
            try {
                GitPostedMaxApi::validateMediaAttachments(
                    $attachments,
                    $this->decodeJson((string) ($post['buttons_json'] ?? ''))
                );
            } catch (InvalidArgumentException $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
                return;
            }
            if ((string) $post['status'] === 'sent') {
                $this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'manage',
                    ['sent'],
                    function (array $lockedPost) use ($postId, $attachments, $stateData, $userId): void {
                        $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_media_update');
                        GitPostedMaxApi::validateMediaAttachments(
                            $attachments,
                            $this->decodeJson((string) ($lockedPost['buttons_json'] ?? ''))
                        );
                        $this->syncPublishedPostEdit($postId, ['attachments' => $attachments]);
                        $update = $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET attachments_json=:attachments,forward_message_id=NULL
                             WHERE id=:id AND status='sent'"
                        );
                        $update->execute([
                            'attachments' => $this->encodeJson($attachments),
                            'id' => $postId,
                        ]);
                        if ($update->rowCount() !== 1) {
                            throw new RuntimeException(
                                'Состояние поста изменилось во время редактирования.'
                            );
                        }
                    }
                );
            } else {
                $this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'manage',
                    ['draft', 'scheduled', 'failed'],
                    function (array $lockedPost) use ($postId, $attachments, $stateData, $userId): void {
                        $this->assertMaxPostInputMode($lockedPost, $stateData, $userId, 'await_media_update');
                        GitPostedMaxApi::validateMediaAttachments(
                            $attachments,
                            $this->decodeJson((string) ($lockedPost['buttons_json'] ?? ''))
                        );
                        $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET attachments_json=:attachments,forward_message_id=NULL
                             WHERE id=:id AND status IN ('draft','scheduled','failed')"
                        )->execute([
                            'attachments' => $this->encodeJson($attachments),
                            'id' => $postId,
                        ]);
                    }
                );
            }
            $this->setState($userId, null, []);
            if ((string) $post['status'] === 'sent') {
                $this->api->sendMessage(
                    $chatId,
                    'Медиа опубликованного поста изменено.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            } elseif ((string) $post['status'] === 'draft') {
                $this->showDraft($chatId, $userId, $postId);
            } else {
                $this->api->sendMessage(
                    $chatId,
                    'Медиа запланированного поста изменено.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            }
            return;
        }

        if ($state === 'await_buttons') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'manage');
            if (
                $post === null
                || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
            ) {
                $this->setState($userId, null, []);
                return;
            }
            try {
                $buttons = $this->parseUrlButtons($text);
                GitPostedMaxApi::validateMediaAttachments(
                    empty($post['forward_message_id'])
                        ? $this->decodeJson((string) ($post['attachments_json'] ?? ''))
                        : [],
                    $buttons
                );
            } catch (Throwable $exception) {
                $this->api->sendMessage($chatId, $exception->getMessage());
                return;
            }
            $buttonsJson = $this->encodeJson($buttons);
            if ((string) $post['status'] === 'sent') {
                // As with text edits, persist only after MAX accepted the edit.
                $this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'manage',
                    ['sent'],
                    function () use ($postId, $buttons, $buttonsJson): void {
                        $this->syncPublishedPostEdit($postId, ['buttons' => $buttons]);
                        $update = $this->pdo->prepare(
                            "UPDATE posted_max_posts SET buttons_json=:buttons
                             WHERE id=:id AND status='sent'"
                        );
                        $update->execute(['buttons' => $buttonsJson, 'id' => $postId]);
                        if ($update->rowCount() !== 1) {
                            throw new RuntimeException(
                                'Состояние поста изменилось во время редактирования.'
                            );
                        }
                    }
                );
                $this->setState($userId, null, []);
                $this->api->sendMessage(
                    $chatId,
                    'Кнопки опубликованного поста изменены.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
                return;
            }
            $this->withManagedMaxPostLock(
                $userId,
                $postId,
                'manage',
                ['draft', 'scheduled', 'failed'],
                function (array $lockedPost) use ($postId, $buttons, $buttonsJson): void {
                    GitPostedMaxApi::validateMediaAttachments(
                        empty($lockedPost['forward_message_id'])
                            ? $this->decodeJson((string) ($lockedPost['attachments_json'] ?? ''))
                            : [],
                        $buttons
                    );
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts SET buttons_json=:buttons
                         WHERE id=:id AND status IN ('draft','scheduled','failed')"
                    )->execute(['buttons' => $buttonsJson, 'id' => $postId]);
                }
            );
            $this->setState($userId, null, []);
            if ((string) $post['status'] === 'draft') {
                $this->showDraft($chatId, $userId, $postId);
            } else {
                $this->api->sendMessage(
                    $chatId,
                    'Кнопки запланированного поста изменены.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            }
            return;
        }

        if (str_starts_with($state, 'max_series_')) {
            $this->handleMaxSeriesInput($chatId, $userId, $state, $stateData, $text);
            return;
        }

        if ($state === 'await_schedule') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'schedule');
            if ($post === null) {
                $this->setState($userId, null, []);
                return;
            }
            if (
                !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
                || $this->activePublicationAttemptForPost($postId) !== null
            ) {
                $this->setState($userId, null, []);
                $this->api->sendMessage(
                    $chatId,
                    'Пост уже отправляется или опубликован. Старая дата не применена.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
                return;
            }
            $effect=$this->currentMaxUpdateEffect('schedule_input');
            if($effect===null){
                try {
                    $scheduled = $this->parseMaxScheduleForDate(
                        $text,
                        (string) ($account['user_timezone'] ?? 'Europe/Moscow'),
                        (string) ($stateData['date'] ?? $this->maxToday($userId))
                    );
                } catch (Throwable $exception) {
                    $this->api->sendMessage($chatId, $exception->getMessage());
                    return;
                }
                $effect=$this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'schedule',
                    ['draft','scheduled','failed'],
                    function()use($postId,$scheduled):array{
                        return $this->completeMaxUpdateEffectLocked(
                            'schedule_input',
                            function()use($postId,$scheduled):array{
                                $schedule=$this->pdo->prepare(
                                    "UPDATE posted_max_posts
                                     SET status='scheduled',scheduled_at=:scheduled,error_text=NULL
                                     WHERE id=:id AND status IN ('draft','scheduled','failed')"
                                );
                                $schedule->execute(['scheduled'=>$scheduled,'id'=>$postId]);
                                if($schedule->rowCount()!==1){
                                    throw new RuntimeException(
                                        'Пост уже отправляется или опубликован. Старая дата не применена.'
                                    );
                                }
                                return ['post_id'=>$postId,'scheduled_at'=>$scheduled];
                            }
                        );
                    }
                );
            }
            if(
                (int)($effect['post_id']??0)!==$postId
                ||trim((string)($effect['scheduled_at']??''))===''
            ){
                throw new RuntimeException('Сохранённое время MAX повреждено.');
            }
            $scheduled=(string)$effect['scheduled_at'];
            $this->setState($userId, null, []);
            $this->api->sendMessage(
                $chatId,
                'Пост запланирован на ' . $this->displayDate($scheduled, (string) $account['user_timezone']) . '.',
                [],
                [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
            );
            return;
        }

        if ($state === 'await_delete_minutes') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'manage');
            if (
                $post === null
                || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
            ) {
                $this->setState($userId, null, []);
                return;
            }
            $minutes = $this->parseFlexibleMinutes($text, 10080);
            if ($minutes === null) {
                $this->api->sendMessage(
                    $chatId,
                    "Не удалось распознать срок.\n\nПримеры: 6, 6:30, 6 30, 10д или 90мин."
                );
                return;
            }
            $this->withManagedMaxPostLock(
                $userId,
                $postId,
                'manage',
                ['draft', 'scheduled', 'failed'],
                function () use ($postId, $minutes): void {
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET delete_after_minutes=:minutes
                         WHERE id=:id AND status IN ('draft','scheduled','failed')"
                    )->execute([
                        'minutes' => $minutes > 0 ? $minutes : null,
                        'id' => $postId,
                    ]);
                }
            );
            $this->setState($userId, null, []);
            if ((string) $post['status'] === 'draft') {
                $this->sendDraftSendSettings($chatId, $userId, $postId);
            } else {
                $this->api->sendMessage(
                    $chatId,
                    'Таймер удаления сохранён.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            }
            return;
        }

        if ($state === 'await_repeat') {
            $postId = (int) ($stateData['post_id'] ?? 0);
            $post = $this->ownedPost($userId, $postId, 'manage');
            if (
                $post === null
                || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
            ) {
                $this->setState($userId, null, []);
                return;
            }
            $minutes = $this->parseFlexibleMinutes($text, 43200);
            if ($minutes === null || ($minutes > 0 && $minutes < 5)) {
                $this->api->sendMessage(
                    $chatId,
                    "Не удалось распознать интервал.\n\nПримеры: 2, 6:30, 1д или 120мин. Ноль отключает."
                );
                return;
            }
            $effect=$this->currentMaxUpdateEffect('repeat_timer');
            if($effect===null){
                $effect=$this->withManagedMaxPostLock(
                    $userId,
                    $postId,
                    'manage',
                    ['draft','scheduled','failed','sent'],
                    function()use($postId,$minutes):array{
                        return $this->completeMaxUpdateEffectLocked(
                            'repeat_timer',
                            function()use($postId,$minutes):array{
                                $this->pdo->prepare(
                                    "UPDATE posted_max_posts
                                     SET repeat_interval_minutes=:minutes,
                                         repeat_remaining=:remaining,
                                         repeat_next_at=CASE
                                           WHEN status='sent' AND :minutes2>0
                                             THEN DATE_ADD(NOW(),INTERVAL :minutes3 MINUTE)
                                           ELSE NULL
                                         END
                                     WHERE id=:id
                                       AND status IN ('draft','scheduled','failed','sent')"
                                )->execute([
                                    'minutes'=>$minutes>0?$minutes:null,
                                    'remaining'=>$minutes>0?65535:0,
                                    'minutes2'=>$minutes,
                                    'minutes3'=>max(1,$minutes),
                                    'id'=>$postId,
                                ]);
                                $stored=$this->pdo->prepare(
                                    'SELECT repeat_next_at FROM posted_max_posts
                                     WHERE id=:id LIMIT 1'
                                );
                                $stored->execute(['id'=>$postId]);
                                return [
                                    'post_id'=>$postId,
                                    'minutes'=>$minutes,
                                    'repeat_next_at'=>$stored->fetchColumn()?:null,
                                ];
                            }
                        );
                    }
                );
            }
            if(
                (int)($effect['post_id']??0)!==$postId
                ||(int)($effect['minutes']??-1)!==$minutes
            ){
                throw new RuntimeException('Сохранённая настройка повтора MAX повреждена.');
            }
            $this->setState($userId, null, []);
            if ((string) $post['status'] === 'draft') {
                $this->sendDraftSendSettings($chatId, $userId, $postId);
            } else {
                $this->api->sendMessage(
                    $chatId,
                    'Настройки повтора сохранены.',
                    [],
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]]
                );
            }
            return;
        }

        if ($state === 'await_template') {
            $parts = preg_split('/\R/u', $text, 2);
            $title = trim((string) ($parts[0] ?? ''));
            $templateText = trim((string) ($parts[1] ?? ''));
            if (
                $title === ''
                || ($templateText === '' && $attachments === [])
                || mb_strlen($templateText) > 4000
            ) {
                $this->api->sendMessage(
                    $chatId,
                    "Первая строка — название шаблона, со второй — текст. "
                        . "К сообщению можно прикрепить медиа."
                );
                return;
            }
            $effect=$this->currentMaxUpdateEffect('create_template');
            if($effect===null){
                $effect=$this->completeMaxUpdateEffect(
                    'create_template',
                    function()use($userId,$title,$templateText,$attachments):array{
                        $this->pdo->prepare(
                            "INSERT INTO posted_max_templates(
                                owner_max_user_id,title,text,attachments_json
                             ) VALUES(:owner,:title,:text,:attachments)"
                        )->execute([
                            'owner'=>$userId,
                            'title'=>mb_substr($title,0,190),
                            'text'=>$templateText,
                            'attachments'=>$attachments!==[]
                                ?$this->encodeJson($attachments)
                                :null,
                        ]);
                        $templateId=(int)$this->pdo->lastInsertId();
                        if($templateId<=0){
                            throw new RuntimeException('Не удалось создать шаблон MAX.');
                        }
                        return ['template_id'=>$templateId,'owner_max_user_id'=>$userId];
                    }
                );
            }
            $templateId=(int)($effect['template_id']??0);
            if(
                $templateId<=0
                ||!hash_equals((string)($effect['owner_max_user_id']??''),$userId)
                ||$this->ownedTemplate($userId,$templateId)===null
            ){
                throw new RuntimeException('Сохранённый шаблон MAX недоступен.');
            }
            $this->setState($userId, null, []);
            $this->sendTemplates($chatId, $userId);
            return;
        }

        if ($state === 'await_timezone') {
            try {
                new DateTimeZone($text);
            } catch (Throwable) {
                $this->api->sendMessage($chatId, 'Неизвестная зона. Пример: Europe/Moscow или Asia/Almaty.');
                return;
            }
            $this->pdo->prepare(
                "UPDATE posted_max_users SET user_timezone=:timezone,state=NULL,state_data=NULL WHERE max_user_id=:max_user"
            )->execute(['timezone' => $text, 'max_user' => $userId]);
            $this->sendSettings($chatId, $userId);
            return;
        }

        $this->sendMainMenu($chatId, 'Выберите действие кнопкой.');
    }

    private function handleCallback(
        array $update,
        array $user,
        string $userId,
        string $chatId
    ): void {
        $callback = $this->extractCallback($update);
        $callbackId = trim((string) ($callback['callback_id'] ?? $callback['id'] ?? ''));
        $payload = trim((string) ($callback['payload'] ?? $update['payload'] ?? ''));
        if ($callbackId === '' || $userId === '' || $chatId === '') {
            return;
        }
        $this->draftCallbackContext = ['callback_id' => $callbackId, 'chat_id' => $chatId, 'message' => $this->extractMessage($update)];
        if (str_starts_with($payload, 'gp:react:') || str_starts_with($payload, 'gp:hidden:')) {
            $this->handleMaxInteractiveCallback($callbackId, $userId, $payload, $this->extractMessage($update));
            return;
        }
        $this->upsertUser($user, $chatId);
        if (!$this->isMaxUserActive($userId)) {
            $this->api->answerCallback(
                $callbackId,
                'Доступ к GitPosted отключён администратором.',
                [],
                'Доступ отключён'
            );
            return;
        }

        try {
            if ($this->handleMaxOptionsCallback($callbackId, $userId, $payload)
                || $this->handleMaxInteractiveCallback($callbackId, $userId, $payload)
                || $this->handleMaxCommentCallback($callbackId, $userId, $payload)
                || $this->handleMaxPostLinkCallback($callbackId, $userId, $payload)) {
                return;
            }
            if ($payload === 'gp:menu') {
                $this->setState($userId, null, []);
                $this->answerMainMenu($callbackId);
                return;
            }
            if ($payload === 'gp:access') {
                $this->answerChannelAccess($callbackId, $userId);
                return;
            }
            if (preg_match('/^gp:channelsettings:(\d+)$/D', $payload, $match) === 1) {
                $this->answerChannelSettings($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:channelrefresh:(\d+)$/D', $payload, $match) === 1) {
                $channel = $this->ownerChannel($userId, (int) $match[1]);
                if ($channel === null) {
                    throw new RuntimeException('Только владелец обновляет данные канала.');
                }
                $this->syncChannelById((string) $channel['chat_id'], $userId, false);
                $this->answerChannelSettings($callbackId, $userId, (int) $match[1], 'Данные канала обновлены.');
                return;
            }
            if (preg_match('/^gp:channelschedule:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                if ($this->ownerChannel($userId, $channelId) === null) {
                    throw new RuntimeException('Только владелец настраивает расписание канала.');
                }
                $this->answerMaxChannelSchedule($callbackId, $userId, $channelId);
                return;
            }
            if (preg_match('/^gp:channelpostsettings:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                if ($this->ownerChannel($userId, $channelId) === null) {
                    throw new RuntimeException('Только владелец настраивает канал.');
                }
                $this->answerMaxChannelPostSettings($callbackId, $userId, $channelId);
                return;
            }
            if (preg_match('/^gp:chpostset:(\d+):pin:(0|1)$/D', $payload, $match) === 1) {
                $this->toggleMaxChannelPostDefault(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    'pin',
                    (string)$match[2]==='1'
                );
                return;
            }
            if (preg_match('/^gp:chposttoggle:(\d+):(silent|pin)$/D', $payload, $match) === 1) {
                if((string)$match[2]==='silent'){
                    $this->toggleMaxChannelPostDefault($callbackId,$userId,(int)$match[1],'silent',false);
                    return;
                }
                $this->answerMaxChannelPostSettings(
                    $callbackId,
                    $userId,
                    (int)$match[1],
                    'Кнопка обновлена. Нажмите нужную настройку ещё раз.'
                );
                return;
            }
            if (preg_match('/^gp:chpost(delete|repeat):(\d+)$/D', $payload, $match) === 1) {
                $this->answerMaxChannelDefaultTimerMenu(
                    $callbackId,
                    $userId,
                    (int) $match[2],
                    (string) $match[1]
                );
                return;
            }
            if (preg_match('/^gp:chpost(del|rep)set:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $this->setMaxChannelDefaultTimer(
                    $callbackId,
                    $userId,
                    (int) $match[2],
                    (string) $match[1] === 'del' ? 'delete' : 'repeat',
                    (int) $match[3]
                );
                return;
            }
            if (preg_match('/^gp:chpost(del|rep)custom:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[2];
                if ($this->ownerChannel($userId, $channelId) === null) {
                    throw new RuntimeException('Только владелец настраивает канал.');
                }
                $kind = (string) $match[1] === 'del' ? 'delete' : 'repeat';
                $this->setState(
                    $userId,
                    $kind === 'delete'
                        ? 'await_channel_post_delete'
                        : 'await_channel_post_repeat',
                    ['channel_id' => $channelId]
                );
                $this->api->answerCallback(
                    $callbackId,
                    $kind === 'delete'
                        ? "Введите срок удаления по умолчанию.\n\nПримеры: 6, 6:30, 10д, 90мин или 0."
                        : "Введите интервал автоповтора по умолчанию.\n\nПримеры: 2, 6:30, 1д, 120мин или 0.",
                    [[GitPostedMaxApi::callbackButton(
                        '← Назад',
                        'gp:chpost' . ($kind === 'delete' ? 'delete:' : 'repeat:') . $channelId
                    )]]
                );
                return;
            }
            if (preg_match('/^gp:channelarchive:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                $this->withOwnedMaxChannelLock(
                    $userId,
                    $channelId,
                    function () use ($channelId): void {
                        $this->pdo->prepare(
                            'UPDATE posted_max_channels SET is_archived=1 WHERE id=:channel'
                        )->execute(['channel' => $channelId]);
                    }
                );
                $this->answerSettings($callbackId, $userId);
                return;
            }
            if (preg_match('/^gp:channeldisable:(\d+)$/D', $payload, $match) === 1) {
                $channel = $this->ownerChannel($userId, (int) $match[1]);
                if ($channel === null) {
                    throw new RuntimeException('Только владелец отключает канал.');
                }
                $this->api->answerCallback(
                    $callbackId,
                    "ОТКЛЮЧИТЬ КАНАЛ?\n\nКанал: " . (string) $channel['title']
                        . "\n\nКанал и его публикации исчезнут из рабочих списков. Его можно будет подключить к другому владельцу.",
                    [
                        [GitPostedMaxApi::callbackButton('Да, отключить', 'gp:channeldisableyes:' . (int) $channel['id'], 'negative')],
                        [GitPostedMaxApi::callbackButton('← Назад', 'gp:channelsettings:' . (int) $channel['id'])],
                    ]
                );
                return;
            }
            if (preg_match('/^gp:channeldisableyes:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                if ($this->ownerChannel($userId, $channelId) === null) {
                    throw new RuntimeException('Только текущий администратор отключает канал.');
                }
                $abandonedDeleteCount = 0;
                $cronLockName = 'gitposted_max_cron';
                $cronLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
                $cronLock->execute(['lock_name' => $cronLockName]);
                if ((int) ($cronLock->fetchColumn() ?: 0) !== 1) {
                    throw new RuntimeException(
                        'Завершается фоновая операция с каналами. Повторите отключение.'
                    );
                }
                try {
                    $pinLockName = 'gitposted_max_pin_' . substr(hash('sha256', (string) $channelId), 0, 28);
                    $pinLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
                    $pinLock->execute(['lock_name' => $pinLockName]);
                    if ((int) ($pinLock->fetchColumn() ?: 0) !== 1) {
                        throw new RuntimeException(
                            'В канале завершается закрепление публикации. Повторите отключение.'
                        );
                    }
                    try {
                        $this->pdo->beginTransaction();
                        try {
                            $lockedChannel = $this->pdo->prepare(
                            'SELECT title,added_by
                             FROM posted_max_channels
                             WHERE id=:channel
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $lockedChannel->execute(['channel' => $channelId]);
                        $channel = $lockedChannel->fetch();
                        if (
                            !is_array($channel)
                            || !hash_equals(
                                $userId,
                                trim((string) ($channel['added_by'] ?? ''))
                            )
                        ) {
                            throw new RuntimeException('Только текущий владелец отключает канал.');
                        }
                        $this->assertActiveMaxActorLocked($userId);
                        $this->pdo->prepare(
                            'UPDATE posted_max_users max_user
                             LEFT JOIN posted_max_permissions permission
                               ON permission.max_user_id=max_user.max_user_id
                              AND permission.channel_id=:channel
                             SET max_user.state=NULL,max_user.state_data=NULL
                             WHERE max_user.max_user_id=:owner
                                OR permission.channel_id IS NOT NULL'
                        )->execute(['channel' => $channelId, 'owner' => $userId]);
                        $this->settleMaxChannelDetachLocked(
                            $channelId,
                            'Публикация или закрепление отменены при отключении канала.'
                        );
                        $this->pdo->prepare(
                            'UPDATE posted_max_manager_categories
                             SET is_active=0 WHERE channel_id=:channel'
                        )->execute(['channel' => $channelId]);
                        $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET status='cancelled',scheduled_at=NULL,
                                 repeat_interval_minutes=NULL,repeat_remaining=0,
                                 repeat_next_at=NULL,error_text=NULL
                             WHERE channel_id=:channel
                               AND status IN ('draft','scheduled','failed')"
                        )->execute(['channel' => $channelId]);
                        $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET delete_after_minutes=NULL,repeat_interval_minutes=NULL,
                                 repeat_remaining=0,repeat_next_at=NULL
                             WHERE channel_id=:channel
                               AND status IN ('sent','deleted')"
                        )->execute(['channel' => $channelId]);
                        $abandonDeletes = $this->pdo->prepare(
                            'UPDATE posted_max_deliveries delivery
                             JOIN posted_max_posts post ON post.id=delivery.post_id
                             SET delivery.error_text=IF(
                                   delivery.delete_at IS NOT NULL,
                                   :error,
                                   delivery.error_text
                                 ),
                                 delivery.delete_at=NULL,delivery.delete_attempts=0,
                                 delivery.delete_started_at=NULL
                             WHERE post.channel_id=:channel AND delivery.deleted_at IS NULL'
                        );
                        $abandonDeletes->execute([
                            'error' => 'Удаление остановлено при отключении канала; сообщение можно удалить вручную.',
                            'channel' => $channelId,
                        ]);
                        $abandonedDeleteCount = $abandonDeletes->rowCount();
                        // The permission/category rows below belong to the old
                        // accounting generation. Close every surviving marker
                        // so later reconciliation cannot credit a new grant.
                        $this->pdo->prepare(
                            'UPDATE posted_max_publish_attempts
                             SET manager_post_quota_charged=0,
                                 manager_pin_quota_charged=0
                             WHERE channel_id=:channel
                               AND (manager_post_quota_charged=1
                                    OR manager_pin_quota_charged=1)'
                        )->execute(['channel' => $channelId]);
                        $this->pdo->prepare('DELETE FROM posted_max_permissions WHERE channel_id=:channel')
                            ->execute(['channel' => $channelId]);
                        $detach = $this->pdo->prepare(
                            'UPDATE posted_max_channels
                             SET is_active=0,admin_blocked=0,added_by=NULL
                             WHERE id=:channel AND added_by=:owner'
                        );
                        $detach->execute(['channel' => $channelId, 'owner' => $userId]);
                        if ($detach->rowCount() !== 1) {
                            throw new RuntimeException('Владелец канала изменился во время отключения.');
                        }
                            $this->pdo->commit();
                        } catch (Throwable $exception) {
                            if ($this->pdo->inTransaction()) {
                                $this->pdo->rollBack();
                            }
                            throw $exception;
                        }
                    } finally {
                        $releasePinLock = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                        $releasePinLock->execute(['lock_name' => $pinLockName]);
                    }
                } finally {
                    $releaseCronLock = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $releaseCronLock->execute(['lock_name' => $cronLockName]);
                }
                $this->answerSettings(
                    $callbackId,
                    $userId,
                    $abandonedDeleteCount > 0
                        ? 'Канал отключён. Незавершённые удаления остановлены; при необходимости удалите сообщения в канале вручную.'
                        : 'Канал отключён и готов к привязке новым владельцем.'
                );
                return;
            }
            if (preg_match('/^gp:editors:(\d+):(\d+)$/D', $payload, $match) === 1) {
                [$text, $rows] = $this->editorsScreen($userId, (int) $match[1], (int) $match[2]);
                $this->api->answerCallback($callbackId, $text, $rows);
                return;
            }
            if (preg_match('/^gp:managers:(\d+):(\d+)$/D', $payload, $match) === 1) {
                [$text,$rows]=$this->maxManagersScreen($userId,(int)$match[1],(int)$match[2]);
                $this->api->answerCallback($callbackId,$text,$rows); return;
            }
            if (preg_match('/^gp:manageradd:(\d+)$/D',$payload,$match)===1) {
                $channelId=(int)$match[1];
                if($this->ownerChannel($userId,$channelId)===null)throw new RuntimeException('Только владелец добавляет рекламных менеджеров.');
                $this->setState($userId,'await_manager',['channel_id'=>$channelId]);
                $this->api->answerCallback($callbackId,"Отправьте числовой ID МАКС, @username известного пользователя или перешлите его сообщение.",[[GitPostedMaxApi::callbackButton('Отмена','gp:managers:'.$channelId.':0')]]); return;
            }
            if (preg_match('/^gp:manager:(\d+):(\d+):(\d+)$/D',$payload,$match)===1) {
                [$text,$rows]=$this->maxManagerScreen($userId,(int)$match[1],(string)$match[2],(int)$match[3]);
                $this->api->answerCallback($callbackId,$text,$rows); return;
            }
            if (preg_match('/^gp:managerfield:(\d+):(\d+):(description|posts|pins):(\d+)$/D',$payload,$match)===1) {
                $this->setState($userId,'await_manager_field',['channel_id'=>(int)$match[1],'manager_id'=>(string)$match[2],'field'=>(string)$match[3],'page'=>(int)$match[4]]);
                $prompt=$match[3]==='description'?'Отправьте описание до 250 символов. Для очистки отправьте «-».':'Отправьте текущий остаток числом либо слово «безлимит».';
                $this->api->answerCallback($callbackId,$prompt,[[GitPostedMaxApi::callbackButton('Назад','gp:manager:'.$match[1].':'.$match[2].':'.$match[4])]]); return;
            }
            if (preg_match('/^gp:managerremove:(\d+):(\d+):(\d+)$/D',$payload,$match)===1) {
                $channelId=(int)$match[1]; $managerId=(string)$match[2];
                $this->withOwnedMaxChannelLock($userId,$channelId,function()use($channelId,$managerId):void{$this->stopMaxUserChannelWorkLocked($managerId,$channelId,null,'Роль рекламного менеджера отозвана.');$this->pdo->prepare('UPDATE posted_max_manager_categories SET is_active=0 WHERE channel_id=:channel AND max_user_id=:user')->execute(['channel'=>$channelId,'user'=>$managerId]);$this->pdo->prepare('DELETE FROM posted_max_permissions WHERE channel_id=:channel AND max_user_id=:user AND is_ad_manager=1')->execute(['channel'=>$channelId,'user'=>$managerId]);$this->clearMaxUserChannelWorkflow($managerId,$channelId);});
                [$text,$rows]=$this->maxManagersScreen($userId,$channelId,(int)$match[3]);
                $this->api->answerCallback($callbackId,"Рекламный менеджер удалён.\n\n".$text,$rows); return;
            }
            if(preg_match('/^gp:managercats:(\d+):(\d+):(\d+)$/D',$payload,$match)===1){[$t,$r]=$this->maxManagerCategoriesScreen($userId,(int)$match[1],(string)$match[2],(int)$match[3]);$this->api->answerCallback($callbackId,$t,$r);return;}
            if(preg_match('/^gp:managercatadd:(\d+):(\d+):(\d+)$/D',$payload,$match)===1){if($this->ownerChannel($userId,(int)$match[1])===null)throw new RuntimeException('Нет доступа.');$this->setState($userId,'await_manager_category_name',['channel_id'=>(int)$match[1],'manager_id'=>(string)$match[2],'page'=>(int)$match[3]]);$this->api->answerCallback($callbackId,'Отправьте название рекламной компании (до 100 символов).',[[GitPostedMaxApi::callbackButton('Назад','gp:managercats:'.$match[1].':'.$match[2].':'.$match[3])]]);return;}
            if(preg_match('/^gp:managercat:(\d+):(\d+):(\d+):(\d+)$/D',$payload,$match)===1){[$t,$r]=$this->maxManagerCategoryScreen($userId,(int)$match[1],(string)$match[2],(int)$match[3],(int)$match[4]);$this->api->answerCallback($callbackId,$t,$r);return;}
            if(preg_match('/^gp:managercatfield:(\d+):(\d+):(\d+):(\d+):(posts|pins)$/D',$payload,$match)===1){if($this->ownerChannel($userId,(int)$match[1])===null)throw new RuntimeException('Нет доступа.');$this->setState($userId,'await_manager_category_field',['channel_id'=>(int)$match[1],'manager_id'=>(string)$match[2],'category_id'=>(int)$match[3],'page'=>(int)$match[4],'field'=>$match[5]]);$this->api->answerCallback($callbackId,'Отправьте новый лимит числом или «безлимит».');return;}
            if(preg_match('/^gp:managercatdel:(\d+):(\d+):(\d+):(\d+)$/D',$payload,$match)===1){$channelId=(int)$match[1];$managerId=(string)$match[2];$categoryId=(int)$match[3];$this->withOwnedMaxChannelLock($userId,$channelId,function()use($channelId,$managerId,$categoryId):void{$category=$this->pdo->prepare('UPDATE posted_max_manager_categories SET is_active=0 WHERE id=:id AND max_user_id=:user AND channel_id=:channel AND is_active=1');$category->execute(['id'=>$categoryId,'user'=>$managerId,'channel'=>$channelId]);if($category->rowCount()!==1)throw new RuntimeException('Рекламная компания уже удалена.');$this->stopMaxUserChannelWorkLocked($managerId,$channelId,$categoryId,'Рекламная компания удалена владельцем канала.');});[$t,$r]=$this->maxManagerCategoriesScreen($userId,$channelId,$managerId,(int)$match[4]);$this->api->answerCallback($callbackId,$t,$r);return;}
            if (preg_match('/^gp:editoradd:(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                if ($this->ownerChannel($userId, $channelId) === null) {
                    throw new RuntimeException('Только владелец добавляет редакторов.');
                }
                $this->setState($userId, 'await_editor', ['channel_id' => $channelId]);
                $this->api->answerCallback(
                    $callbackId,
                    "Отправьте числовой ID МАКС, @username известного пользователя или перешлите его сообщение.\n\n"
                    . "Пользователь должен состоять в канале. После добавления права можно изменить отдельно.",
                    [[GitPostedMaxApi::callbackButton('Отмена', 'gp:editors:' . $channelId . ':0')]]
                );
                return;
            }
            if (preg_match('/^gp:editor:(\d+):(\d+):(\d+)$/D', $payload, $match) === 1) {
                [$text, $rows] = $this->editorScreen(
                    $userId,
                    (int) $match[1],
                    (string) $match[2],
                    (int) $match[3]
                );
                $this->api->answerCallback($callbackId, $text, $rows);
                return;
            }
            if (preg_match('/^gp:edset:(\d+):(\d+):(p|s|m):(0|1):(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                $editorId = (string) $match[2];
                $editorRemains = $this->toggleEditorPermission(
                    $channelId,
                    $editorId,
                    (string) $match[3],
                    $userId,
                    (string)$match[4]==='1'
                );
                if ($editorRemains) {
                    [$text, $rows] = $this->editorScreen(
                        $userId,
                        $channelId,
                        $editorId,
                        (int) $match[5]
                    );
                } else {
                    [$text, $rows] = $this->editorsScreen($userId, $channelId, (int) $match[5]);
                    $text = "Редактор удалён: у него не осталось прав.\n\n" . $text;
                }
                $this->api->answerCallback($callbackId, $text, $rows);
                return;
            }
            if (preg_match('/^gp:edtoggle:(\d+):(\d+):(p|s|m):(\d+)$/D', $payload, $match) === 1) {
                [$text,$rows]=$this->editorScreen(
                    $userId,
                    (int)$match[1],
                    (string)$match[2],
                    (int)$match[4]
                );
                $this->api->answerCallback(
                    $callbackId,
                    "Кнопка обновлена. Нажмите нужное право ещё раз.\n\n".$text,
                    $rows
                );
                return;
            }
            if (preg_match('/^gp:edremove:(\d+):(\d+):(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                $editorId = (string) $match[2];
                $this->withOwnedMaxChannelLock(
                    $userId,
                    $channelId,
                    function () use ($channelId, $editorId): void {
                        $this->stopMaxUserChannelWorkLocked(
                            $editorId,
                            $channelId,
                            null,
                            'Роль редактора отозвана.'
                        );
                        $removed = $this->pdo->prepare(
                            'DELETE FROM posted_max_permissions
                             WHERE channel_id=:channel AND max_user_id=:editor
                               AND is_ad_manager=0'
                        );
                        $removed->execute(['channel' => $channelId, 'editor' => $editorId]);
                        if ($removed->rowCount() !== 1) {
                            throw new RuntimeException('Редактор не найден.');
                        }
                        $this->clearMaxUserChannelWorkflow($editorId, $channelId);
                    }
                );
                [$text, $rows] = $this->editorsScreen($userId, $channelId, (int) $match[3]);
                $this->api->answerCallback($callbackId, "Редактор удалён.\n\n" . $text, $rows);
                return;
            }
            if (preg_match('/^gp:edleave:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $channelId = (int) $match[1];
                $expectedOwnerId = (string) $match[2];
                $this->leaveMaxChannelAccess($userId,$channelId,$expectedOwnerId);
                $this->answerChannelAccess($callbackId, $userId, 'Вы покинули канал.');
                return;
            }
            if ($payload === 'gp:create') {
                $this->answerChannelPicker($callbackId, $userId, 'channel');
                return;
            }
            if (preg_match('/^gp:next:(\d+)$/D', $payload, $match) === 1) {
                $this->setState($userId, null, []);
                $this->answerDraftSendSettings($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:channel:(\d+)$/D', $payload, $match) === 1) {
                $channelId=(int)$match[1];
                $flow=$this->currentMaxUpdateEffect('begin_draft');
                if($flow!==null&&(int)($flow['channel_id']??0)!==$channelId){
                    throw new RuntimeException('Сохранённое действие создания MAX повреждено.');
                }
                if($flow!==null&&($flow['mode']??'')==='draft'){
                    $this->beginMaxDraft($callbackId,$userId,$channelId,0);return;
                }
                $categoryStmt=$this->pdo->prepare(
                    'SELECT c.* FROM posted_max_manager_categories c
                     INNER JOIN posted_max_permissions p
                       ON p.max_user_id=c.max_user_id AND p.channel_id=c.channel_id
                      AND p.is_ad_manager=1
                     WHERE c.max_user_id=:user AND c.channel_id=:channel AND c.is_active=1
                     ORDER BY c.id'
                );
                $categoryStmt->execute(['user'=>$userId,'channel'=>$channelId]);
                $categories=$categoryStmt->fetchAll();
                if($flow!==null&&($flow['mode']??'')!=='category_picker'){
                    throw new RuntimeException('Сохранённое действие создания MAX повреждено.');
                }
                if($categories!==[]){
                    if($flow===null){
                        $this->completeMaxUpdateEffect(
                            'begin_draft',
                            static fn():array=>[
                                'mode'=>'category_picker',
                                'channel_id'=>$channelId,
                                'manager_category_id'=>0,
                            ]
                        );
                    }
                    $rows=[];
                    foreach($categories as $category){
                        $label=$category['name'].' · '.$this->maxManagerQuotaLabel($category['post_quota_remaining']??null).' / '.$this->maxManagerQuotaLabel($category['pin_quota_remaining']??null);
                        $rows[]=[GitPostedMaxApi::callbackButton(mb_substr($label,0,120),'gp:adcat:'.$channelId.':'.(int)$category['id'])];
                    }
                    $rows[]=[GitPostedMaxApi::callbackButton('Назад','gp:create')];
                    $this->api->answerCallback($callbackId,'Выберите рекламную компанию. Лимиты будут списаны с неё.',$rows);return;
                }
                if($flow!==null){
                    throw new RuntimeException('Список рекламных компаний изменился. Откройте создание поста заново.');
                }
                $this->beginMaxDraft($callbackId,$userId,$channelId,0);return;
            }
            if(preg_match('/^gp:adcat:(\d+):(\d+)$/D',$payload,$match)===1){
                $this->beginMaxDraft($callbackId,$userId,(int)$match[1],(int)$match[2]);return;
            }
            if (preg_match('/^gp:publish:(\d+)$/D', $payload, $match) === 1) {
                $post = $this->ownedPost($userId, (int) $match[1], 'post');
                if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)) {
                    throw new RuntimeException('Черновик недоступен.');
                }
                $this->setState($userId, null, []);
                $post = $this->withMaxChannelCapabilityLock(
                    $userId,
                    (int) $post['channel_id'],
                    'post',
                    function (array $lockedChannel, array $permission) use ($userId, $post): array {
                        $postStmt = $this->pdo->prepare(
                            "SELECT *
                             FROM posted_max_posts
                             WHERE id=:post AND channel_id=:channel
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $postStmt->execute([
                            'post' => (int) $post['id'],
                            'channel' => (int) $lockedChannel['id'],
                        ]);
                        $lockedPost = $postStmt->fetch();
                        $isOwner = hash_equals(
                            trim((string) ($lockedChannel['added_by'] ?? '')),
                            $userId
                        );
                        if (
                            !is_array($lockedPost)
                            || !in_array(
                                (string) ($lockedPost['status'] ?? ''),
                                ['draft', 'scheduled', 'failed'],
                                true
                            )
                            || (
                                !$isOwner
                                && !hash_equals(
                                    trim((string) ($lockedPost['author_max_user_id'] ?? '')),
                                    $userId
                                )
                                && (int) ($permission['can_manage'] ?? 0) !== 1
                            )
                        ) {
                            throw new RuntimeException('Черновик недоступен или права изменились.');
                        }
                        $pendingPin = $this->pdo->prepare(
                            "SELECT 1 FROM posted_max_publish_attempts
                             WHERE chat_id=:chat AND status='completed'
                               AND pin_status IN ('pending','processing')
                             LIMIT 1 FOR UPDATE"
                        );
                        $pendingPin->execute(['chat' => (string) $lockedChannel['chat_id']]);
                        if ($pendingPin->fetchColumn() !== false) {
                            throw new RuntimeException(
                                'В канале ещё завершается закрепление предыдущей публикации.'
                            );
                        }
                        $claim = $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET status='publishing',scheduled_at=NULL,error_text=NULL
                             WHERE id=:id AND status IN ('draft','scheduled','failed')"
                        );
                        $claim->execute(['id' => (int) $lockedPost['id']]);
                        if ($claim->rowCount() !== 1) {
                            throw new RuntimeException('Публикация уже обрабатывается.');
                        }
                        $lockedPost['status'] = 'publishing';
                        $lockedPost['scheduled_at'] = null;
                        $lockedPost['error_text'] = null;
                        return $lockedPost;
                    }
                );
                try {
                    $publication = $this->publishPost((int) $post['id'], false, $userId);
                } catch (Throwable $exception) {
                    $this->markPostFailed((int) $post['id'], $exception);
                    throw $exception;
                }
                $publicationRows = [];
                if ($publication !== 'confirmed') {
                    $pendingAttempt = $this->activePublicationAttemptForPost((int) $post['id']);
                    if (is_array($pendingAttempt)) {
                        $publicationRows[] = [GitPostedMaxApi::callbackButton(
                            'Проверить публикацию',
                            'gp:maxcheck:' . (int) $post['id'] . ':' . (int) $pendingAttempt['id']
                        )];
                    }
                }
                $publicationRows[] = [GitPostedMaxApi::callbackButton(
                    'Открыть пост',
                    'gp:view:' . (int) $post['id']
                )];
                $publicationRows[] = [GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')];
                $publicationRows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
                $this->api->answerCallback(
                    $callbackId,
                    $publication === 'confirmed'
                        ? trim('Пост опубликован.' . "\n\n" . $this->maxCommentStatusText((int) $post['id']))
                        : "МАКС пока не подтвердил ID публикации.\n\n"
                            . "Повторная отправка заблокирована, бот сверит канал автоматически.",
                    $publicationRows,
                    $publication === 'confirmed' ? 'Готово' : 'Ожидаем подтверждение МАКС'
                );
                return;
            }
            if (preg_match('/^gp:edit:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Черновик недоступен.');
                }
                $this->setState($userId, 'await_edit_text', [
                    'post_id' => $postId, 'expected_forward_mid' => trim((string) ($post['forward_message_id'] ?? '')),
                    'expected_status' => (string) $post['status'],
                    'input_token' => bin2hex(random_bytes(12)),
                ]);
                $this->api->answerCallback(
                    $callbackId,
                    'Отправьте новый текст.' . (!empty($post['forward_message_id']) ? ' Изменение содержимого превратит репост в редактируемую копию.' : ''),
                    [[GitPostedMaxApi::callbackButton('Назад', 'gp:draft:' . $postId)]]
                );
                return;
            }
            if (preg_match('/^gp:media:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'sent'], true)) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->setState($userId, 'await_media_update', [
                    'post_id' => $postId, 'expected_forward_mid' => trim((string) ($post['forward_message_id'] ?? '')),
                    'expected_status' => (string) $post['status'],
                    'input_token' => bin2hex(random_bytes(12)),
                ]);
                $this->api->answerCallback(
                    $callbackId,
                    "Отправьте новое изображение, видео, аудио или файл.\n\n"
                        . "Текущее медиа будет заменено. Для удаления медиа отправьте «-»."
                        . (!empty($post['forward_message_id']) ? ' Изменение содержимого превратит репост в редактируемую копию.' : ''),
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:draft:' . $postId)]]
                );
                return;
            }
            if (preg_match('/^gp:buttons:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Черновик недоступен.');
                }
                $this->setState($userId, 'await_buttons', ['post_id' => $postId]);
                $this->api->answerCallback(
                    $callbackId,
                    "🔗 URL-КНОПКИ\n\n"
                        . "Отправьте кнопки в формате:\nНазвание - https://example.ru\n\n"
                        . "Кнопки одной строки разделяйте символом |.\n"
                        . "Цвет: !r красный, !g зелёный, !b синий.\n\n"
                        . "Пример:\n!g Позвонить - https://example.ru | !b Сайт - https://example.org\n\n"
                        . "Отправьте «-», чтобы убрать кнопки.",
                    [[GitPostedMaxApi::callbackButton('Назад', 'gp:draft:' . $postId)]]
                );
                return;
            }
            if (preg_match('/^gp:series:(\d+)$/D', $payload, $match) === 1) {
                $this->startMaxSeries($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:sr:([a-f0-9]{12}):([a-z]+)(?::([0-9-]+))?(?::([01]))?$/D', $payload, $match) === 1) {
                $this->handleMaxSeriesCallback($callbackId, $userId, $match[1], $match[2], $match[3] ?? '', $match[4] ?? '');
                return;
            }
            if (preg_match('/^gp:schedule:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'schedule');
                if (
                    $post === null
                    || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
                    || $this->activePublicationAttemptForPost($postId) !== null
                ) {
                    throw new RuntimeException('Черновик недоступен.');
                }
                $this->showMaxDraftScheduleMenu($callbackId, $userId, $postId, $this->maxToday($userId));
                return;
            }
            if (preg_match('/^gp:scheddate:(\d+):(\d{8})$/D', $payload, $match) === 1) {
                $date = substr($match[2], 0, 4) . '-' . substr($match[2], 4, 2) . '-' . substr($match[2], 6, 2);
                $this->showMaxDraftScheduleMenu($callbackId, $userId, (int) $match[1], $date);
                return;
            }
            if (preg_match('/^gp:scheduletimes:(\d+):(\d{8})$/D', $payload, $match) === 1) {
                $date = substr($match[2], 0, 4) . '-' . substr($match[2], 4, 2) . '-' . substr($match[2], 6, 2);
                $this->showMaxDraftScheduleMenu($callbackId, $userId, (int) $match[1], $date, true);
                return;
            }
            if (preg_match('/^gp:scheduletime:(\d+):(\d{8}):((?:[01]\d|2[0-3])(?:00|30))$/D', $payload, $match) === 1) {
                $this->scheduleMaxPostAtChosenTime(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    substr($match[2], 0, 4) . '-' . substr($match[2], 4, 2) . '-' . substr($match[2], 6, 2),
                    substr($match[3], 0, 2) . ':' . substr($match[3], 2, 2)
                );
                return;
            }
            if (preg_match('/^gp:schedrel:(\d+):(15|30|60|120)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $minutes = (int) $match[2];
                $effect=$this->currentMaxUpdateEffect('schedule_relative');
                $post=$this->ownedPost($userId,$postId,'schedule');
                if($post===null)throw new RuntimeException('Черновик недоступен для планирования.');
                if($effect===null){
                    if(
                        !in_array((string)$post['status'],['draft','scheduled','failed'],true)
                        ||$this->activePublicationAttemptForPost($postId)!==null
                    )throw new RuntimeException('Черновик недоступен для планирования.');
                    try{
                        $storageZone=new DateTimeZone((string)($this->config['timezone']??'Europe/Moscow'));
                    }catch(Throwable){
                        $storageZone=new DateTimeZone('Europe/Moscow');
                    }
                    $effect=$this->withManagedMaxPostLock(
                        $userId,
                        $postId,
                        'schedule',
                        ['draft','scheduled','failed'],
                        function()use($postId,$minutes,$storageZone):array{
                            return $this->completeMaxUpdateEffectLocked(
                                'schedule_relative',
                                function()use($postId,$minutes,$storageZone):array{
                                    $scheduled=(new DateTimeImmutable('now',$storageZone))
                                        ->modify('+'.$minutes.' minutes')
                                        ->format('Y-m-d H:i:s');
                                    $schedule=$this->pdo->prepare(
                                        "UPDATE posted_max_posts
                                         SET status='scheduled',scheduled_at=:scheduled,error_text=NULL
                                         WHERE id=:id AND status IN ('draft','scheduled','failed')"
                                    );
                                    $schedule->execute(['scheduled'=>$scheduled,'id'=>$postId]);
                                    if($schedule->rowCount()!==1){
                                        throw new RuntimeException(
                                            'Пост уже отправляется или опубликован. Старая дата не применена.'
                                        );
                                    }
                                    return ['post_id'=>$postId,'minutes'=>$minutes,'scheduled_at'=>$scheduled];
                                }
                            );
                        }
                    );
                }
                if(
                    (int)($effect['post_id']??0)!==$postId
                    ||(int)($effect['minutes']??0)!==$minutes
                    ||trim((string)($effect['scheduled_at']??''))===''
                )throw new RuntimeException('Сохранённое время MAX повреждено.');
                $scheduled=(string)$effect['scheduled_at'];
                $this->setState($userId, null, []);
                $account = $this->user($userId);
                $this->api->answerCallback(
                    $callbackId,
                    'Пост запланирован на ' . $this->displayDate(
                        $scheduled,
                        (string) ($account['user_timezone'] ?? 'Europe/Moscow')
                    ) . '.',
                    [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]],
                    'Готово'
                );
                return;
            }
            if (preg_match('/^gp:schedslot:(\d+):(\d{8}):([01]\d|2[0-3])([0-5]\d)$/D', $payload, $match) === 1) {
                $this->scheduleMaxPostAtDailySlot(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (string) $match[3] . ':' . (string) $match[4],
                    substr($match[2], 0, 4) . '-' . substr($match[2], 4, 2) . '-' . substr($match[2], 6, 2)
                );
                return;
            }
            if (preg_match('/^gp:draft:(\d+)$/D', $payload, $match) === 1) {
                $this->setState($userId, null, []);
                $this->answerDraft($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:more:(\d+)$/D', $payload, $match) === 1) {
                $this->answerMore($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:pin:(\d+):(0|1)$/D', $payload, $match) === 1) {
                $post = $this->ownedPost($userId, (int) $match[1], 'manage');
                if (
                    $post === null
                    || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
                ) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->withManagedMaxPostLock(
                    $userId,
                    (int) $post['id'],
                    'manage',
                    ['draft', 'scheduled', 'failed'],
                    function () use ($post,$match): void {
                        $this->pdo->prepare(
                            "UPDATE posted_max_posts
                             SET pin_after_publish=:desired
                             WHERE id=:id AND status IN ('draft','scheduled','failed')"
                        )->execute([
                            'desired'=>(string)$match[2]==='1'?1:0,
                            'id'=>(int)$post['id'],
                        ]);
                    }
                );
                $this->answerMore($callbackId, $userId, (int) $post['id']);
                return;
            }
            if (preg_match('/^gp:pin:(\d+)$/D', $payload, $match) === 1) {
                $this->answerMore($callbackId,$userId,(int)$match[1]);
                return;
            }
            if (preg_match('/^gp:notify:(\d+)$/D', $payload, $match) === 1) {
                throw new RuntimeException(
                    'MAX не поддерживает публикации в каналах без уведомления.'
                );
            }
            if (preg_match('/^gp:delafter:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->answerDeleteTimerMenu($callbackId, $userId, $postId);
                return;
            }
            if (preg_match('/^gp:delset:(\d+):(0|5|15|30|60|120|240|360|720|1080|1440|2880|4320|7200|10080)$/D', $payload, $match) === 1) {
                $this->setDeleteTimerPreset(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (int) $match[2]
                );
                return;
            }
            if (preg_match('/^gp:delcustom:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->setState($userId, 'await_delete_minutes', ['post_id' => $postId]);
                $this->api->answerCallback(
                    $callbackId,
                    "Введите свой срок удаления.\n\n"
                        . "Примеры:\n6 — через 6 часов\n6:30 — через 6 часов 30 минут\n"
                        . "6 30 — через 6 часов 30 минут\n10д — через 10 дней\n"
                        . "90мин — через 90 минут\n0 — отключить.",
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:delafter:' . $postId)]]
                );
                return;
            }
            if (preg_match('/^gp:repeat:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->answerRepeatTimerMenu($callbackId, $userId, $postId);
                return;
            }
            if (preg_match('/^gp:repset:(\d+):(0|120|240|360|720|1080|1440|2880|4320|7200|10080|14400)$/D', $payload, $match) === 1) {
                $this->setRepeatTimerPreset(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (int) $match[2]
                );
                return;
            }
            if (preg_match('/^gp:repcustom:(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $post = $this->ownedPost($userId, $postId, 'manage');
                if ($post === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->setState($userId, 'await_repeat', ['post_id' => $postId]);
                $this->api->answerCallback(
                    $callbackId,
                    "Введите свой интервал автоповтора.\n\n"
                        . "Примеры: 2, 6:30, 1д или 120мин.\n0 — отключить.",
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:repeat:' . $postId)]]
                );
                return;
            }
            if (preg_match('/^gp:cancel:(\d+)$/D', $payload, $match) === 1) {
                $post = $this->ownedPost($userId, (int) $match[1], 'manage');
                if ($post === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->withManagedMaxPostLock(
                    $userId,
                    (int) $post['id'],
                    'manage',
                    ['draft', 'scheduled', 'failed'],
                    function () use ($post): void {
                        $cancel = $this->pdo->prepare(
                            "UPDATE posted_max_posts SET status='cancelled'
                             WHERE id=:id AND status IN ('draft','scheduled','failed')"
                        );
                        $cancel->execute(['id' => (int) $post['id']]);
                        if ($cancel->rowCount() !== 1) {
                            throw new RuntimeException('Пост уже отправляется или опубликован.');
                        }
                    }
                );
                $this->setState($userId, null, []);
                $this->answerMainMenu($callbackId, 'Пост отменён.');
                return;
            }
            if ($payload === 'gp:plan') {
                $this->answerPlan($callbackId, $userId);
                return;
            }
            if (preg_match('/^gp:planch:(\d+):(\d{4}-\d{2}-\d{2})$/D', $payload, $match) === 1) {
                $this->answerPlanDay(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (string) $match[2]
                );
                return;
            }
            if (preg_match('/^gp:plancal:(\d+):(\d{4}-\d{2}(?:-\d{2})?)$/D', $payload, $match) === 1) {
                $this->answerPlanCalendar(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (string) $match[2]
                );
                return;
            }
            if (preg_match('/^gp:planmanage:(\d+):(\d{4}-\d{2}-\d{2})$/D', $payload, $match) === 1) {
                $this->answerPlanManagement(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (string) $match[2]
                );
                return;
            }
            if (preg_match('/^gp:planall:(\d+):(\d{4}-\d{2}-\d{2})(?::(\d+))?$/D', $payload, $match) === 1) {
                $this->answerPlanAll(
                    $callbackId,
                    $userId,
                    (int) $match[1],
                    (string) $match[2],
                    isset($match[3]) ? (int)$match[3] : 0
                );
                return;
            }
            if (preg_match('/^gp:view:(\d+)$/D', $payload, $match) === 1) {
                $this->answerPost($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:maxcheck:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $attemptId = (int) $match[2];
                $effect=$this->currentMaxUpdateEffect('manual_reconcile');
                if($effect!==null){
                    if(
                        (int)($effect['post_id']??0)!==$postId
                        ||(int)($effect['attempt_id']??0)!==$attemptId
                        ||$this->ownedPost($userId,$postId,'post')===null
                    )throw new RuntimeException('Сохранённая проверка публикации MAX недоступна.');
                    $this->answerPost($callbackId,$userId,$postId);
                    return;
                }
                $attempt = $this->activePublicationAttemptForPost($postId);
                if ($this->ownedPost($userId, $postId, 'post') === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                if (!is_array($attempt) || (int) $attempt['id'] !== $attemptId) {
                    throw new RuntimeException('Эта кнопка относится к уже завершённой попытке.');
                }
                $this->reconcilePostPublicationAttempt($postId, $attemptId);
                $this->answerPost($callbackId, $userId, $postId);
                return;
            }
            if (preg_match('/^gp:maxacceptstart:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $attemptId = (int) $match[2];
                $attempt = $this->activePublicationAttemptForPost($postId);
                if (
                    $this->ownedPost($userId, $postId, 'post') === null
                    || !is_array($attempt)
                    || (int) $attempt['id'] !== $attemptId
                    || (string) $attempt['status'] !== 'needs_review'
                ) {
                    throw new RuntimeException('Ручное решение для этого поста недоступно.');
                }
                $this->setState($userId, 'await_max_review_message', [
                    'post_id' => $postId,
                    'attempt_id' => $attemptId,
                ]);
                $this->api->answerCallback(
                    $callbackId,
                    "Перешлите сюда из нужного канала именно тот пост, который опубликовал GitPosted.\n\n"
                        . "По пересланному ID бот свяжет публикацию с контент-планом "
                        . "без повторной отправки.",
                    [
                        [GitPostedMaxApi::callbackButton('← Назад', 'gp:view:' . $postId)],
                    ]
                );
                return;
            }
            if (preg_match('/^gp:maxreleaseconfirm:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $attemptId = (int) $match[2];
                $attempt = $this->activePublicationAttemptForPost($postId);
                if (
                    $this->ownedPost($userId, $postId, 'post') === null
                    || !is_array($attempt)
                    || (int) $attempt['id'] !== $attemptId
                    || (string) $attempt['status'] !== 'needs_review'
                ) {
                    throw new RuntimeException('Ручное решение для этого поста недоступно.');
                }
                $this->api->answerCallback(
                    $callbackId,
                    "Сначала проверьте сам канал МАКС.\n\n"
                        . "Снимайте блокировку только если публикации там точно нет. "
                        . "После снятия бот сам ничего не отправит.",
                    [
                        [GitPostedMaxApi::callbackButton(
                            'Я проверил — публикации нет',
                            'gp:maxrelease:' . $postId . ':' . $attemptId,
                            'negative'
                        )],
                        [GitPostedMaxApi::callbackButton('← Назад', 'gp:view:' . $postId)],
                    ]
                );
                return;
            }
            if (preg_match('/^gp:maxrelease:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $postId = (int) $match[1];
                $attemptId = (int) $match[2];
                if ($this->ownedPost($userId, $postId, 'post') === null) {
                    throw new RuntimeException('Пост недоступен.');
                }
                $this->releasePublicationAttemptAfterReview($postId, $attemptId, $userId);
                $this->answerPost($callbackId, $userId, $postId);
                return;
            }
            if (preg_match('/^gp:duplicate:(\d+)$/D', $payload, $match) === 1) {
                $this->duplicateMaxPost($callbackId, $userId, (int) $match[1]);
                return;
            }
            if(preg_match('/^gp:dupcat:(\d+):(\d+)$/D',$payload,$match)===1){
                $this->duplicateMaxPost(
                    $callbackId,
                    $userId,
                    (int)$match[1],
                    (int)$match[2]
                );
                return;
            }
            if (preg_match('/^gp:delete:(\d+)$/D', $payload, $match) === 1) {
                $this->deletePublishedPost($userId, (int) $match[1]);
                $this->answerPlan(
                    $callbackId,
                    $userId,
                    'Публикации поставлены в безопасную очередь удаления.'
                );
                return;
            }
            if ($payload === 'gp:templates') {
                $this->answerTemplates($callbackId, $userId);
                return;
            }
            if ($payload === 'gp:catalog') {
                $this->answerEmojiCatalog($callbackId);
                return;
            }
            if ($payload === 'gp:tplnew') {
                $this->setState($userId, 'await_template', []);
                $this->api->answerCallback(
                    $callbackId,
                    "Первая строка — название шаблона.\n"
                        . "Со второй строки — текст. Можно приложить медиа.",
                    [[GitPostedMaxApi::callbackButton('Назад', 'gp:templates')]]
                );
                return;
            }
            if (preg_match('/^gp:tplview:(\d+)$/D', $payload, $match) === 1) {
                $this->answerTemplateView($callbackId, $userId, (int) $match[1]);
                return;
            }
            if (preg_match('/^gp:tpluse:(\d+)$/D', $payload, $match) === 1) {
                $template = $this->ownedTemplate($userId, (int) $match[1]);
                if ($template === null) {
                    throw new RuntimeException('Шаблон недоступен.');
                }
                $this->answerChannelPicker($callbackId, $userId, 'tplchannel:' . (int) $template['id']);
                return;
            }
            if (preg_match('/^gp:tplchannel:(\d+):(\d+)$/D', $payload, $match) === 1) {
                $templateId=(int)$match[1];$channelId=(int)$match[2];
                $flow=$this->currentMaxUpdateEffect('template_draft');
                if(
                    $flow!==null
                    &&(
                        (int)($flow['template_id']??0)!==$templateId
                        ||(int)($flow['channel_id']??0)!==$channelId
                    )
                )throw new RuntimeException('Сохранённое действие шаблона MAX повреждено.');
                if($flow!==null&&($flow['mode']??'')==='draft'){
                    $postId=$this->createMaxDraftFromTemplate($userId,$templateId,$channelId,0);
                    $this->answerDraft($callbackId,$userId,$postId);return;
                }
                $cats=$this->pdo->prepare('SELECT c.* FROM posted_max_manager_categories c INNER JOIN posted_max_permissions p ON p.max_user_id=c.max_user_id AND p.channel_id=c.channel_id AND p.is_ad_manager=1 WHERE c.max_user_id=:user AND c.channel_id=:channel AND c.is_active=1 ORDER BY c.id');$cats->execute(['user'=>$userId,'channel'=>$channelId]);$categories=$cats->fetchAll();
                if($flow!==null&&($flow['mode']??'')!=='category_picker')throw new RuntimeException('Сохранённое действие шаблона MAX повреждено.');
                if($categories!==[]){
                    if($flow===null){$this->completeMaxUpdateEffect('template_draft',static fn():array=>['mode'=>'category_picker','template_id'=>$templateId,'channel_id'=>$channelId,'manager_category_id'=>0]);}
                    $rows=[];foreach($categories as $category){$rows[]=[GitPostedMaxApi::callbackButton(mb_substr((string)$category['name'].' · '.$this->maxManagerQuotaLabel($category['post_quota_remaining']??null),0,120),'gp:tplcat:'.$templateId.':'.$channelId.':'.(int)$category['id'])];}$rows[]=[GitPostedMaxApi::callbackButton('Назад','gp:tplview:'.$templateId)];$this->api->answerCallback($callbackId,'Выберите рекламную компанию для поста из шаблона.',$rows);return;
                }
                if($flow!==null)throw new RuntimeException('Список рекламных компаний изменился. Откройте шаблон заново.');
                $postId=$this->createMaxDraftFromTemplate($userId,$templateId,$channelId,0);
                $this->answerDraft($callbackId, $userId, $postId);
                return;
            }
            if(preg_match('/^gp:tplcat:(\d+):(\d+):(\d+)$/D',$payload,$match)===1){
                $templateId=(int)$match[1];$channelId=(int)$match[2];$categoryId=(int)$match[3];$postId=$this->createMaxDraftFromTemplate($userId,$templateId,$channelId,$categoryId);
                $this->answerDraft($callbackId, $userId, $postId);
                return;
            }
            if (preg_match('/^gp:tpldel:(\d+)$/D', $payload, $match) === 1) {
                $template = $this->ownedTemplate($userId, (int) $match[1]);
                if ($template !== null) {
                    $this->pdo->prepare("DELETE FROM posted_max_templates WHERE id=:id")
                        ->execute(['id' => (int) $template['id']]);
                }
                $this->answerTemplates($callbackId, $userId);
                return;
            }
            if ($payload === 'gp:settings') {
                $this->setState($userId, null, []);
                $this->answerSettings($callbackId, $userId);
                return;
            }
            if ($payload === 'gp:addchannel') {
                $this->setState($userId, 'await_channel_ref', []);
                $this->api->answerCallback(
                    $callbackId,
                    "➕ ДОБАВЛЕНИЕ КАНАЛА\n\n"
                        . "1. Откройте нужный канал МАКС.\n"
                        . "2. Добавьте бота GitPosted в подписчики.\n"
                        . "3. Добавьте GitPosted администратором и разрешите публикацию сообщений.\n"
                        . "4. Перешлите боту GitPosted любое сообщение из канала.\n\n"
                        . "После пересылки бот сам определит канал, проверит права и подключит его.",
                    [[GitPostedMaxApi::callbackButton('← Назад', 'gp:settings')]]
                );
                return;
            }
            if ($payload === 'gp:timezone') {
                [$text, $rows] = $this->timezoneScreen($userId);
                $this->api->answerCallback($callbackId, $text, $rows);
                return;
            }
            if ($payload === 'gp:advanced') {
                [$text, $rows] = $this->advancedSettingsScreen();
                $this->api->answerCallback($callbackId, $text, $rows);
                return;
            }
            if (preg_match('/^gp:tz:(moscow|utc|almaty|yerevan)$/D', $payload, $match) === 1) {
                $zones = [
                    'moscow' => 'Europe/Moscow',
                    'utc' => 'UTC',
                    'almaty' => 'Asia/Almaty',
                    'yerevan' => 'Asia/Yerevan',
                ];
                $this->pdo->prepare(
                    "UPDATE posted_max_users SET user_timezone=:timezone WHERE max_user_id=:max_user"
                )->execute(['timezone' => $zones[$match[1]], 'max_user' => $userId]);
                $this->answerSettings($callbackId, $userId);
                return;
            }
            if ($payload === 'gp:tzcustom') {
                $this->setState($userId, 'await_timezone', []);
                $this->api->answerCallback(
                    $callbackId,
                    'Отправьте часовой пояс IANA, например Europe/Moscow.',
                    [[GitPostedMaxApi::callbackButton('Назад', 'gp:settings')]]
                );
                return;
            }
            if ($payload === 'gp:link') {
                $effect=$this->currentMaxUpdateEffect('link_code');
                if($effect===null){
                    $effect=$this->completeMaxUpdateEffect(
                        'link_code',
                        function()use($userId):array{
                            return [
                                'code'=>GitPostedMaxLink::createLocked(
                                    $this->pdo,
                                    $this->config,
                                    $userId
                                ),
                                'max_user_id'=>$userId,
                            ];
                        }
                    );
                }
                $code=trim((string)($effect['code']??''));
                if(
                    preg_match('/^\d{6}$/D',$code)!==1
                    ||!hash_equals((string)($effect['max_user_id']??''),$userId)
                ){
                    throw new RuntimeException('Сохранённый код связи MAX повреждён.');
                }
                $rows = [
                    [GitPostedMaxApi::callbackButton('Новый код', 'gp:link')],
                    [GitPostedMaxApi::callbackButton('← Назад', 'gp:advanced')],
                ];
                $telegramUrl = $this->telegramBotUrl();
                if ($telegramUrl !== '') {
                    array_unshift($rows, [GitPostedMaxApi::linkButton('Открыть Telegram-бота', $telegramUrl, 'positive')]);
                }
                $this->api->answerCallback(
                    $callbackId,
                    "Код связи: {$code}\n\nОтправьте эти 6 цифр боту GitPosted в Telegram в течение 10 минут.",
                    $rows
                );
                return;
            }
            if ($payload === 'gp:help') {
                $this->answerHelp($callbackId);
                return;
            }
            throw new RuntimeException('Команда устарела. Откройте главное меню.');
        } catch (Throwable $exception) {
            error_log('GitPosted MAX callback error: ' . $exception->getMessage());
            try {
                $this->api->answerCallback(
                    $callbackId,
                    '',
                    [],
                    mb_substr($exception->getMessage(), 0, 150)
                );
            } catch (Throwable) {
            }
        }
    }

    private function sendMainMenu(string $chatId, string $prefix = ''): void
    {
        $text = ($prefix !== '' ? $prefix . "\n\n" : '')
            . "✅ GitPosted — это простой и удобный бот для отложенного постинга в МАКС.\n\n"
            . "Бот позволяет:\n\n"
            . "🕔 Планировать выход публикаций в ваших каналах\n"
            . "🗑 Автоматически удалять их по таймеру\n"
            . "🎨 Создавать и настраивать посты любого формата\n"
            . "🔄 Зацикливать публикации, добавлять кнопки и водяные знаки\n"
            . "👀 И многое другое";
        $this->api->sendMessage($chatId, $text, [], $this->mainButtons());
    }

    private function answerMainMenu(string $callbackId, string $prefix = ''): void
    {
        $text = ($prefix !== '' ? $prefix . "\n\n" : '')
            . "✅ GitPosted — это простой и удобный бот для отложенного постинга в МАКС.\n\n"
            . "Бот позволяет:\n\n"
            . "🕔 Планировать выход публикаций в ваших каналах\n"
            . "🗑 Автоматически удалять их по таймеру\n"
            . "🎨 Создавать и настраивать посты любого формата\n"
            . "🔄 Зацикливать публикации, добавлять кнопки и водяные знаки\n"
            . "👀 И многое другое";
        $this->api->answerCallback($callbackId, $text, $this->mainButtons());
    }

    private function mainButtons(): array
    {
        return [
            [
                GitPostedMaxApi::callbackButton('Создать пост', 'gp:create', 'positive'),
                GitPostedMaxApi::callbackButton('Изменить пост', 'gp:plan'),
            ],
            [
                GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan'),
                GitPostedMaxApi::callbackButton('Настройки', 'gp:settings'),
            ],
            [
                GitPostedMaxApi::callbackButton('Шаблоны', 'gp:templates'),
                GitPostedMaxApi::callbackButton('Каталог эмодзи', 'gp:catalog'),
            ],
        ];
    }

    private function sendHelp(string $chatId): void
    {
        $this->api->sendMessage($chatId, $this->helpText(), [], [
            [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')],
        ]);
    }

    private function answerHelp(string $callbackId): void
    {
        $this->api->answerCallback($callbackId, $this->helpText(), [
            [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')],
        ]);
    }

    private function helpText(): string
    {
        return "СПРАВКА GITPOSTED\n\n"
            . "1. Откройте нужный канал МАКС.\n"
            . "2. Добавьте бота GitPosted в подписчики.\n"
            . "3. Добавьте GitPosted администратором и разрешите публикацию сообщений.\n"
            . "4. Перешлите боту GitPosted любое сообщение из канала.\n"
            . "5. Нажмите «Создать пост», выберите канал и отправьте текст или медиа.\n"
            . "6. Добавьте URL-кнопки, настройте время, закрепление, повтор или удаление.\n\n"
            . "Команды: /start, /menu, /help, /cancel.\n"
            . "Лимит текста МАКС — 4000 символов.";
    }

    private function answerChannelPicker(string $callbackId, string $userId, string $action): void
    {
        $channels = $this->publishableChannelsForUser($userId);
        if ($channels === []) {
            $this->api->answerCallback(
                $callbackId,
                "Каналов пока нет.\n\nОткройте канал МАКС, добавьте бота GitPosted в подписчики, "
                    . "назначьте его администратором с правом публикации и перешлите боту любое сообщение из канала.",
                [
                    [GitPostedMaxApi::callbackButton(
                        '➕ Добавить новый канал',
                        'gp:addchannel',
                        'positive'
                    )],
                    [GitPostedMaxApi::callbackButton('Назад', 'gp:menu')],
                ]
            );
            return;
        }
        $rows = [];
        foreach ($channels as $channel) {
            $payload = $action === 'channel'
                ? 'gp:channel:' . (int) $channel['id']
                : 'gp:' . $action . ':' . (int) $channel['id'];
            $rows[] = [GitPostedMaxApi::callbackButton((string) $channel['title'], $payload)];
        }
        $rows[] = [GitPostedMaxApi::callbackButton(
            '➕ Добавить новый канал',
            'gp:addchannel',
            'positive'
        )];
        $rows[] = [GitPostedMaxApi::callbackButton('Назад', 'gp:menu')];
        $text = $action === 'channel'
            ? "⬆️ СОЗДАНИЕ ПОСТА\n\nВыберите канал, в котором хотите создать публикацию."
            : 'Выберите канал:';
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function showDraft(string $chatId, string $userId, int $postId): void
    {
        [$text, $rows, $media] = $this->draftScreen($userId, $postId);
        $post = $this->ownedPost($userId, $postId);
        $effective = $this->maxEffectivePostContent($post);
        $format = $effective['html'] !== '' ? 'html' : null;
        if ($format !== null) $text = $effective['html'];
        $disablePreview = empty($this->maxPostOptions($post)['link_preview']);
        $linkNotice = $this->maxPostLinkNotice($post);
        if (!empty($post['forward_message_id'])) {
            $this->api->sendMessage(
                $chatId,
                '',
                [],
                [],
                true,
                ['type' => 'forward', 'mid' => (string) $post['forward_message_id']]
            );
            $this->api->sendMessage(
                $chatId,
                'Репост принят. Выберите действие.' . ($linkNotice !== '' ? "\n\n" . $linkNotice : ''),
                [],
                $rows
            );
            return;
        }
        if (count($media) === 12) {
            $this->api->sendMessage($chatId, $text, $media, [], true, null, false, $format, $disablePreview);
            $this->api->sendMessage($chatId, 'Альбом: 12 медиа. Выберите действие.' . ($linkNotice !== '' ? "\n\n" . $linkNotice : ''), [], $rows);
            return;
        }
        $this->api->sendMessage($chatId, $text, $media, $rows, true, null, false, $format, $disablePreview);
        if ($linkNotice !== '') $this->api->sendMessage($chatId, $linkNotice);
    }

    private function answerDraft(string $callbackId, string $userId, int $postId): void
    {
        [$text, $rows, $media] = $this->draftScreen($userId, $postId);
        $post = $this->ownedPost($userId, $postId);
        $effective = $this->maxEffectivePostContent($post);
        $format = $effective['html'] !== '' ? 'html' : null;
        if ($format !== null) $text = $effective['html'];
        $disablePreview = empty($this->maxPostOptions($post)['link_preview']);
        $linkNotice = $this->maxPostLinkNotice($post);
        if (!empty($post['forward_message_id'])) {
            $chatId = trim((string) (($this->user($userId)['private_chat_id'] ?? '')));
            if ($chatId === '') throw new RuntimeException('Личный чат для предпросмотра недоступен.');
            $this->api->sendMessage($chatId, '', [], [], true,
                ['type' => 'forward', 'mid' => (string) $post['forward_message_id']], true);
            $this->api->sendMessage($chatId,
                'Репост принят. Выберите действие.' . ($linkNotice !== '' ? "\n\n" . $linkNotice : ''), [], $rows, true, null, true);
            $this->retireMaxDraftKeyboard($callbackId, $chatId);
            $this->api->answerCallback($callbackId, notification: 'Управление постом — под предпросмотром');
            return;
        }
        if (count($media) === 12) {
            $chatId = trim((string) (($this->user($userId)['private_chat_id'] ?? '')));
            if ($chatId === '') throw new RuntimeException('Личный чат для предпросмотра недоступен.');
            $this->api->sendMessage($chatId, $text, $media, [], true, null, true, $format, $disablePreview);
            $this->api->sendMessage($chatId,
                'Альбом: 12 медиа. Выберите действие.' . ($linkNotice !== '' ? "\n\n" . $linkNotice : ''), [], $rows, true, null, true);
            $this->retireMaxDraftKeyboard($callbackId, $chatId);
            $this->api->answerCallback($callbackId, notification: 'Управление постом — под предпросмотром');
            return;
        }
        $this->api->answerCallback($callbackId, $text, $rows, '', $media, null, $format, $disablePreview);
        if ($linkNotice !== '') {
            $privateChat = trim((string) ($this->user($userId)['private_chat_id'] ?? ''));
            if ($privateChat !== '') $this->api->sendMessage($privateChat, $linkNotice);
        }
    }

    private function retireMaxDraftKeyboard(string $callbackId, string $chatId): void
    {
        $context = $this->draftCallbackContext;
        if (($context['callback_id'] ?? '') !== $callbackId || ($context['chat_id'] ?? '') !== $chatId) return;
        $message = (array) ($context['message'] ?? []);
        $mid = $this->maxMessageIdFromMessage($message);
        if ($mid === '' || ($message['link']['type'] ?? '') === 'forward') return;
        try {
            $actual = $this->firstMessage($this->api->getMessage($mid));
            if (!is_array($actual) || $this->maxMessageIdFromMessage($actual) !== $mid
                || $this->maxMessageChatId($actual) !== $chatId || ($actual['link']['type'] ?? '') === 'forward') return;
            $attachments = [];
            $hasControls = false;
            foreach ((array) ($actual['body']['attachments'] ?? $actual['attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) return;
                if (($attachment['type'] ?? '') === 'inline_keyboard') {
                    $remaining = [];
                    foreach ((array) ($attachment['payload']['buttons'] ?? []) as $row) {
                        $kept = [];
                        foreach ((array) $row as $button) {
                            if (!is_array($button)) return;
                            if (str_starts_with((string) ($button['payload'] ?? ''), 'gp:')) $hasControls = true;
                            else $kept[] = $button;
                        }
                        if ($kept !== []) $remaining[] = $kept;
                    }
                    if ($remaining !== []) $attachments[] = GitPostedMaxApi::keyboard($remaining);
                    continue;
                }
                $safe = GitPostedMaxForbiddenFileFallback::outboundAttachment($attachment);
                if ($safe === null) return;
                $attachments[] = $safe;
            }
            if (!$hasControls) return;
            $result = $this->api->request('PUT', '/messages?message_id=' . rawurlencode($mid), ['attachments' => $attachments], true);
            if (($result['success'] ?? null) !== true) throw new RuntimeException('МАКС не подтвердил обновление старого меню.');
        } catch (Throwable $exception) {
            error_log('GitPosted MAX previous draft keyboard: ' . $exception->getMessage());
        }
    }

    private function draftScreen(string $userId, int $postId): array
    {
        $post = $this->ownedPost($userId, $postId);
        if ($post === null) {
            throw new RuntimeException('Черновик недоступен.');
        }
        $preview = trim((string) $this->maxEffectivePostContent($post)['text']);
        if ($preview === '') {
            $preview = 'Медиа без текста';
        }
        $media = $this->decodeJson((string) ($post['attachments_json'] ?? ''));
        $text = $preview;
        $rows = [
            [
                GitPostedMaxApi::callbackButton(
                    $media !== [] ? 'Изменить описание' : 'Изменить текст',
                    'gp:edit:' . $postId
                ),
                GitPostedMaxApi::callbackButton(
                    $media !== [] ? 'Медиа' : 'Добавить медиа',
                    'gp:media:' . $postId
                ),
            ],
            [GitPostedMaxApi::callbackButton('Кнопки', 'gp:buttons:' . $postId)],
            [GitPostedMaxApi::callbackButton(
                !empty($post['pin_after_publish']) ? '✅ Закрепить' : '☑️ Закрепить',
                'gp:pin:' . $postId . ':' . (empty($post['pin_after_publish']) ? 1 : 0)
            )],
            [
                GitPostedMaxApi::callbackButton('← Отменить', 'gp:cancel:' . $postId, 'negative'),
                GitPostedMaxApi::callbackButton('Далее →', 'gp:next:' . $postId, 'positive'),
            ],
        ];
        $last = array_pop($rows);
        array_push($rows, ...$this->maxPostLinkRows($post), ...$this->maxOptionsRows($post), ...$this->maxCommentRows($post));
        $rows[] = $last;
        return [$text, $rows, $media];
    }

    private function answerMore(string $callbackId, string $userId, int $postId): void
    {
        $post = $this->ownedPost($userId, $postId, 'manage');
        if (
            $post === null
            || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
            || $this->activePublicationAttemptForPost($postId) !== null
        ) {
            throw new RuntimeException('Пост недоступен.');
        }
        if ((string) $post['status'] === 'draft') {
            $this->answerDraft($callbackId, $userId, $postId);
        } else {
            $this->answerPost($callbackId, $userId, $postId);
        }
    }

    private function answerDraftSendSettings(
        string $callbackId,
        string $userId,
        int $postId
    ): void {
        [$text, $rows] = $this->draftSendSettingsScreen($userId, $postId);
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function sendDraftSendSettings(
        string $chatId,
        string $userId,
        int $postId
    ): void {
        [$text, $rows] = $this->draftSendSettingsScreen($userId, $postId);
        $this->api->sendMessage($chatId, $text, [], $rows);
    }

    private function draftSendSettingsScreen(string $userId, int $postId): array
    {
        $post = $this->ownedPost($userId, $postId, 'manage');
        if ($post === null || !in_array((string) $post['status'], ['draft', 'sent'], true)) {
            throw new RuntimeException('Пост недоступен.');
        }
        $delete = (int) ($post['delete_after_minutes'] ?? 0);
        $repeat = (int) ($post['repeat_interval_minutes'] ?? 0);
        $deleteLabel = $delete > 0 ? $this->maxMinutesLabel($delete) : 'отключён';
        $repeatLabel = $repeat > 0 ? $this->maxMinutesLabel($repeat) : 'отключён';
        return [
            "💼 НАСТРОЙКИ ОТПРАВКИ\n\n"
                . "Пост готов к публикации в канале "
                . (string) $post['channel_title']
                . '.',
            [
                [GitPostedMaxApi::callbackButton(
                    'Таймер удаления: ' . $deleteLabel,
                    'gp:delafter:' . $postId
                )],
                [GitPostedMaxApi::callbackButton(
                    'Автоповтор: ' . $repeatLabel,
                    'gp:repeat:' . $postId
                )],
                [GitPostedMaxApi::callbackButton('📅 Серия публикаций', 'gp:series:' . $postId)],
                [
                    GitPostedMaxApi::callbackButton('⏰ Отложить', 'gp:schedule:' . $postId),
                    GitPostedMaxApi::callbackButton('🔥 Опубликовать', 'gp:publish:' . $postId, 'positive'),
                ],
                [GitPostedMaxApi::callbackButton('← Назад', 'gp:draft:' . $postId)],
            ],
        ];
    }

    private function maxMinutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'отключён';
        }
        if ($minutes % 1440 === 0) {
            return (int) ($minutes / 1440) . ' д';
        }
        if ($minutes % 60 === 0) {
            return (int) ($minutes / 60) . ' ч';
        }
        return $minutes . ' мин';
    }

    private function parseFlexibleMinutes(string $value, int $maximum): ?int
    {
        $value = mb_strtolower(trim($value));
        if ($value === '0' || $value === 'нет' || $value === 'выкл') {
            return 0;
        }
        $minutes = null;
        if (preg_match('/^(\d{1,3})\s*(?:д|дн|дня|дней)$/uD', $value, $match) === 1) {
            $minutes = (int) $match[1] * 1440;
        } elseif (preg_match('/^(\d{1,5})\s*(?:мин|м)$/uD', $value, $match) === 1) {
            $minutes = (int) $match[1];
        } elseif (preg_match('/^(\d{1,3})\s*(?:ч|час|часа|часов)$/uD', $value, $match) === 1) {
            $minutes = (int) $match[1] * 60;
        } elseif (preg_match('/^(\d{1,3})[:\s](\d{1,2})$/uD', $value, $match) === 1) {
            if ((int) $match[2] >= 60) {
                return null;
            }
            $minutes = ((int) $match[1] * 60) + (int) $match[2];
        } elseif (preg_match('/^\d{1,3}$/D', $value) === 1) {
            $minutes = (int) $value * 60;
        }
        if ($minutes === null || $minutes < 0 || $minutes > $maximum) {
            return null;
        }
        return $minutes;
    }

    private function answerDeleteTimerMenu(
        string $callbackId,
        string $userId,
        int $postId
    ): void {
        $post = $this->ownedPost($userId, $postId, 'manage');
        if (
            $post === null
            || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
        ) {
            throw new RuntimeException('Пост недоступен.');
        }
        $selected = (int) ($post['delete_after_minutes'] ?? 0);
        $values = [
            ['Нет', 0], ['5 мин', 5], ['15 мин', 15], ['30 мин', 30],
            ['1 ч', 60], ['2 ч', 120], ['4 ч', 240], ['6 ч', 360],
            ['12 ч', 720], ['18 ч', 1080], ['24 ч', 1440], ['48 ч', 2880],
            ['3 д', 4320], ['5 д', 7200], ['7 д', 10080],
        ];
        $buttons = [];
        foreach ($values as [$label, $minutes]) {
            $buttons[] = GitPostedMaxApi::callbackButton(
                ($selected === $minutes ? '✅ ' : '') . $label,
                'gp:delset:' . $postId . ':' . $minutes,
                $selected === $minutes ? 'positive' : 'default'
            );
        }
        $rows = array_chunk($buttons, 4);
        $rows[] = [GitPostedMaxApi::callbackButton('👁 Свой срок', 'gp:delcustom:' . $postId)];
        $rows[] = [GitPostedMaxApi::callbackButton(
            '← Назад',
            (string) $post['status'] === 'scheduled' ? 'gp:view:' . $postId : 'gp:next:' . $postId
        )];
        $this->api->answerCallback(
            $callbackId,
            "🗑 ТАЙМЕР УДАЛЕНИЯ\n\n"
                . "Выберите, через какое время после публикации удалить пост.",
            $rows
        );
    }

    private function setDeleteTimerPreset(
        string $callbackId,
        string $userId,
        int $postId,
        int $minutes
    ): void {
        $post = $this->ownedPost($userId, $postId, 'manage');
        if (
            $post === null
            || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
        ) {
            throw new RuntimeException('Пост недоступен.');
        }
        $this->withManagedMaxPostLock(
            $userId,
            $postId,
            'manage',
            ['draft', 'scheduled', 'failed'],
            function () use ($postId, $minutes): void {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET delete_after_minutes=:minutes
                     WHERE id=:id AND status IN ('draft','scheduled','failed')"
                )->execute([
                    'minutes' => $minutes > 0 ? $minutes : null,
                    'id' => $postId,
                ]);
            }
        );
        $this->setState($userId, null, []);
        if ((string) $post['status'] === 'scheduled') {
            $this->answerPost($callbackId, $userId, $postId);
        } else {
            $this->answerDraftSendSettings($callbackId, $userId, $postId);
        }
    }

    private function answerRepeatTimerMenu(
        string $callbackId,
        string $userId,
        int $postId
    ): void {
        $post = $this->ownedPost($userId, $postId, 'manage');
        if (
            $post === null
            || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
        ) {
            throw new RuntimeException('Пост недоступен.');
        }
        $selected = (int) ($post['repeat_interval_minutes'] ?? 0);
        $values = [
            ['Нет', 0], ['2 ч', 120], ['4 ч', 240], ['6 ч', 360],
            ['12 ч', 720], ['18 ч', 1080], ['24 ч', 1440], ['48 ч', 2880],
            ['3 д', 4320], ['5 д', 7200], ['7 д', 10080], ['10 д', 14400],
        ];
        $buttons = [];
        foreach ($values as [$label, $minutes]) {
            $buttons[] = GitPostedMaxApi::callbackButton(
                ($selected === $minutes ? '✅ ' : '') . $label,
                'gp:repset:' . $postId . ':' . $minutes,
                $selected === $minutes ? 'positive' : 'default'
            );
        }
        $rows = array_chunk($buttons, 4);
        $rows[] = [GitPostedMaxApi::callbackButton('👁 Свой интервал', 'gp:repcustom:' . $postId)];
        $rows[] = [GitPostedMaxApi::callbackButton(
            '← Назад',
            in_array((string) $post['status'], ['scheduled', 'sent'], true)
                ? 'gp:view:' . $postId
                : 'gp:next:' . $postId
        )];
        $this->api->answerCallback(
            $callbackId,
            "🔄 АВТОПОВТОР\n\nВыберите интервал, с которым пост будет повторяться.",
            $rows
        );
    }

    private function setRepeatTimerPreset(
        string $callbackId,
        string $userId,
        int $postId,
        int $minutes
    ): void {
        $effect=$this->currentMaxUpdateEffect('repeat_timer');
        $post = $this->ownedPost($userId, $postId, 'manage');
        if($post===null)throw new RuntimeException('Пост недоступен.');
        if($effect===null){
            if(!in_array((string)$post['status'],['draft','scheduled','failed','sent'],true)){
                throw new RuntimeException('Пост недоступен.');
            }
            $effect=$this->withManagedMaxPostLock(
                $userId,
                $postId,
                'manage',
                ['draft','scheduled','failed','sent'],
                function()use($postId,$minutes):array{
                    return $this->completeMaxUpdateEffectLocked(
                        'repeat_timer',
                        function()use($postId,$minutes):array{
                            $this->pdo->prepare(
                                "UPDATE posted_max_posts
                                 SET repeat_interval_minutes=:minutes,repeat_remaining=:remaining,
                                     repeat_next_at=CASE
                                       WHEN status='sent' AND :minutes2>0
                                         THEN DATE_ADD(NOW(),INTERVAL :minutes3 MINUTE)
                                       ELSE NULL END
                                 WHERE id=:id AND status IN ('draft','scheduled','failed','sent')"
                            )->execute([
                                'minutes'=>$minutes>0?$minutes:null,
                                'remaining'=>$minutes>0?65535:0,
                                'minutes2'=>$minutes,
                                'minutes3'=>max(1,$minutes),
                                'id'=>$postId,
                            ]);
                            $stored=$this->pdo->prepare(
                                'SELECT repeat_interval_minutes,repeat_remaining,repeat_next_at
                                 FROM posted_max_posts WHERE id=:id LIMIT 1'
                            );
                            $stored->execute(['id'=>$postId]);
                            $result=$stored->fetch();
                            if(!is_array($result))throw new RuntimeException('Пост недоступен.');
                            return [
                                'post_id'=>$postId,
                                'minutes'=>$minutes,
                                'repeat_remaining'=>(int)($result['repeat_remaining']??0),
                                'repeat_next_at'=>$result['repeat_next_at']??null,
                            ];
                        }
                    );
                }
            );
        }
        if(
            (int)($effect['post_id']??0)!==$postId
            ||(int)($effect['minutes']??-1)!==$minutes
        )throw new RuntimeException('Сохранённая настройка повтора MAX повреждена.');
        $this->setState($userId, null, []);
        if (in_array((string) $post['status'], ['scheduled', 'sent'], true)) {
            $this->answerPost($callbackId, $userId, $postId);
        } else {
            $this->answerDraftSendSettings($callbackId, $userId, $postId);
        }
    }

    private function answerPlan(string $callbackId, string $userId, string $prefix = ''): void
    {
        $channels = $this->channelsForUser($userId);
        if ($channels === []) {
            $this->api->answerCallback(
                $callbackId,
                "📅 КОНТЕНТ-ПЛАН\n\nНет доступных каналов.",
                [
                    [GitPostedMaxApi::callbackButton('➕ Добавить канал', 'gp:addchannel')],
                    [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')],
                ]
            );
            return;
        }
        $today = $this->maxToday($userId);
        if (count($channels) === 1) {
            $this->answerPlanDay(
                $callbackId,
                $userId,
                (int) $channels[0]['id'],
                $today,
                $prefix
            );
            return;
        }
        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = [GitPostedMaxApi::callbackButton(
                (string) $channel['title'],
                'gp:planch:' . (int) $channel['id'] . ':' . $today
            )];
        }
        $rows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
        $this->api->answerCallback(
            $callbackId,
            ($prefix !== '' ? $prefix . "\n\n" : '')
                . "📅 КОНТЕНТ-ПЛАН\n\n"
                . "Выберите канал, в котором хотите посмотреть публикации.",
            $rows
        );
    }

    private function answerPlanDay(
        string $callbackId,
        string $userId,
        int $channelId,
        string $date,
        string $prefix = '',
        bool $calendar = false
    ): void {
        $channel = $this->maxChannelForUser($userId, $channelId);
        $date = $this->normalizeMaxDate($date) ?? $this->maxToday($userId);
        if ($channel === null) {
            throw new RuntimeException('Канал недоступен.');
        }
        $posts = $this->maxPostsForDate($userId, $channelId, $date);
        $count = count($posts);
        $text = ($prefix !== '' ? $prefix . "\n\n" : '')
            . "📅 КОНТЕНТ-ПЛАН\n\n"
            . 'На ' . $this->maxHumanDate($date)
            . ' в канале ' . (string) $channel['title'] . ' '
            . ($count === 0
                ? 'нет ни одного поста.'
                : 'найдено ' . $this->maxPlural($count, 'пост', 'поста', 'постов') . '.');
        $rows = [];
        foreach ($posts as $post) {
            $rows[] = [GitPostedMaxApi::callbackButton(
                $this->maxPlanPostLabel($userId, $post),
                'gp:view:' . (int) $post['id']
            )];
        }
        if ($calendar) {
            $rows = array_merge($rows, $this->maxCalendarRows($userId, $channelId, $date));
        } else {
            $selected = new DateTimeImmutable($date);
            $prev = $selected->modify('-1 day')->format('Y-m-d');
            $next = $selected->modify('+1 day')->format('Y-m-d');
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    '← ' . $this->maxShortDate($prev),
                    'gp:planch:' . $channelId . ':' . $prev
                ),
                GitPostedMaxApi::callbackButton(
                    $this->maxShortDate($date),
                    'gp:planch:' . $channelId . ':' . $date
                ),
                GitPostedMaxApi::callbackButton(
                    $this->maxShortDate($next) . ' →',
                    'gp:planch:' . $channelId . ':' . $next
                ),
            ];
            $rows[] = [GitPostedMaxApi::callbackButton(
                '↓ Развернуть календарь',
                'gp:plancal:' . $channelId . ':' . $date
            )];
        }
        $rows[] = [GitPostedMaxApi::callbackButton(
            '⚙️ Управление контент-планом',
            'gp:planmanage:' . $channelId . ':' . $date
        )];
        if (count($this->channelsForUser($userId)) > 1) {
            $rows[] = [GitPostedMaxApi::callbackButton('← К каналам', 'gp:plan')];
        } else {
            $rows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
        }
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function answerPlanCalendar(
        string $callbackId,
        string $userId,
        int $channelId,
        string $date
    ): void {
        if (preg_match('/^\d{4}-\d{2}$/D', $date) === 1) {
            $date .= '-01';
        }
        if (
            $this->maxChannelForUser($userId, $channelId) === null
            || $this->normalizeMaxDate($date) === null
        ) {
            throw new RuntimeException('Канал или месяц недоступен.');
        }
        $this->answerPlanDay($callbackId, $userId, $channelId, $date, '', true);
    }

    private function maxCalendarRows(
        string $userId,
        int $channelId,
        string $date
    ): array {
        $selectedDate = $date;
        $month = substr($selectedDate, 0, 7);
        $first = new DateTimeImmutable($month . '-01');
        $lastDay = (int) $first->format('t');
        $counts = $this->maxPostCountsForMonth($userId, $channelId, $month);
        $rows = [[
            GitPostedMaxApi::callbackButton(
                '← ' . $this->maxMonthTitle($first->modify('-1 month')),
                'gp:plancal:' . $channelId . ':' . $first->modify('-1 month')->format('Y-m')
            ),
            GitPostedMaxApi::callbackButton(
                $this->maxMonthTitle($first),
                'gp:plancal:' . $channelId . ':' . $month
            ),
            GitPostedMaxApi::callbackButton(
                $this->maxMonthTitle($first->modify('+1 month')) . ' →',
                'gp:plancal:' . $channelId . ':' . $first->modify('+1 month')->format('Y-m')
            ),
        ], [
            GitPostedMaxApi::callbackButton('Пн', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Вт', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Ср', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Чт', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Пт', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Сб', 'gp:plancal:' . $channelId . ':' . $month),
            GitPostedMaxApi::callbackButton('Вс', 'gp:plancal:' . $channelId . ':' . $month),
        ]];
        $week = [];
        $offset = (int) $first->format('N') - 1;
        for ($empty = 0; $empty < $offset; $empty++) {
            $week[] = GitPostedMaxApi::callbackButton('·', 'gp:plancal:' . $channelId . ':' . $month);
        }
        for ($day = 1; $day <= $lastDay; $day++) {
            $currentDate = $month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            $label = !empty($counts[$currentDate]) && $currentDate !== $selectedDate
                ? '•' . $day
                : (string) $day;
            $week[] = GitPostedMaxApi::callbackButton(
                $label,
                'gp:planch:' . $channelId . ':' . $currentDate,
                $currentDate === $selectedDate ? 'positive' : 'default'
            );
            if (count($week) === 7) {
                $rows[] = $week;
                $week = [];
            }
        }
        if ($week !== []) {
            while (count($week) < 7) {
                $week[] = GitPostedMaxApi::callbackButton('·', 'gp:plancal:' . $channelId . ':' . $month);
            }
            $rows[] = $week;
        }
        return $rows;
    }

    private function answerPlanManagement(
        string $callbackId,
        string $userId,
        int $channelId,
        string $date
    ): void {
        $channel = $this->maxChannelForUser($userId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Канал недоступен.');
        }
        $date = $this->normalizeMaxDate($date) ?? $this->maxToday($userId);
        $this->api->answerCallback(
            $callbackId,
            "🕘 УПРАВЛЕНИЕ КОНТЕНТ-ПЛАНОМ\n\n"
                . "В этом разделе вы использовать продвинутые функции для работы с контент-планом.",
            [
                [GitPostedMaxApi::callbackButton(
                    '🔎 Все отложенные посты',
                    'gp:planall:' . $channelId . ':' . $date
                )],
                [GitPostedMaxApi::callbackButton(
                    '← Назад',
                    'gp:planch:' . $channelId . ':' . $date
                )],
            ]
        );
    }

    private function answerPlanAll(
        string $callbackId,
        string $userId,
        int $channelId,
        string $date,
        int $page = 0
    ): void {
        $channel = $this->maxChannelForUser($userId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Канал недоступен.');
        }
        $date = $this->normalizeMaxDate($date) ?? $this->maxToday($userId);
        $page=max(0,min(9999,$page));
        $pageSize=25;
        $offset=$page*$pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT p.*,c.title AS channel_title,c.chat_id,c.chat_type,
                    (
                      SELECT active_attempt.status
                      FROM posted_max_publish_attempts active_attempt
                      WHERE active_attempt.gate_post_id=p.id
                      LIMIT 1
                    ) AS active_attempt_status
              FROM posted_max_posts p
              JOIN posted_max_channels c ON c.id=p.channel_id
              WHERE p.channel_id=:channel
                AND (
                  (p.status='scheduled' AND p.scheduled_at>=NOW())
                  OR p.status IN ('max_pending','sent','failed')
                  OR EXISTS (
                    SELECT 1 FROM posted_max_publish_attempts visible_attempt
                    WHERE visible_attempt.gate_post_id=p.id
                  )
                )
              ORDER BY active_attempt_status IS NULL,
                       COALESCE(p.published_at,p.scheduled_at,p.created_at) DESC,
                       p.id DESC
             LIMIT ".($pageSize+1)." OFFSET ".$offset
        );
        $stmt->execute(['channel' => $channelId]);
        $posts = $stmt->fetchAll();
        $hasNext=count($posts)>$pageSize;
        if($hasNext)$posts=array_slice($posts,0,$pageSize);
        $back = 'gp:planmanage:' . $channelId . ':' . $date;
        if ($posts === []) {
            $emptyRows=[];
            if($page>0){
                $emptyRows[]=[GitPostedMaxApi::callbackButton(
                    '← Предыдущие',
                    'gp:planall:'.$channelId.':'.$date.':'.($page-1)
                )];
            }
            $emptyRows[]=[GitPostedMaxApi::callbackButton('← Назад',$back)];
            $this->api->answerCallback(
                $callbackId,
                'В канале ' . (string) $channel['title'] . ' пока нет доступных постов.',
                $emptyRows
            );
            return;
        }
        $rows = [];
        foreach ($posts as $post) {
            $rows[] = [GitPostedMaxApi::callbackButton(
                $this->maxPlanPostLabel($userId, $post),
                'gp:view:' . (int) $post['id']
            )];
        }
        if($page>0||$hasNext){
            $navigation=[];
            if($page>0){
                $navigation[]=GitPostedMaxApi::callbackButton(
                    '← Предыдущие',
                    'gp:planall:'.$channelId.':'.$date.':'.($page-1)
                );
            }
            if($hasNext){
                $navigation[]=GitPostedMaxApi::callbackButton(
                    'Следующие →',
                    'gp:planall:'.$channelId.':'.$date.':'.($page+1)
                );
            }
            $rows[]=$navigation;
        }
        $rows[] = [GitPostedMaxApi::callbackButton('← Назад', $back)];
        $this->api->answerCallback(
            $callbackId,
            "🔎 ВСЕ ПОСТЫ\n\nВыберите публикацию.",
            $rows
        );
    }

    private function maxChannelForUser(string $userId, int $channelId): ?array
    {
        foreach ($this->channelsForUser($userId) as $channel) {
            if ((int) $channel['id'] === $channelId) {
                return $channel;
            }
        }
        return null;
    }

    private function maxToday(string $userId): string
    {
        $account = $this->user($userId);
        try {
            $zone = new DateTimeZone((string) ($account['user_timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $zone = new DateTimeZone('Europe/Moscow');
        }
        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    private function showMaxDraftScheduleMenu(
        string $callbackId,
        string $maxUserId,
        int $postId,
        string $date,
        bool $showTimes = false
    ): void {
        $post = $this->ownedPost($maxUserId, $postId, 'schedule');
        if (
            $post === null
            || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
            || $this->activePublicationAttemptForPost($postId) !== null
        ) {
            throw new RuntimeException('Черновик недоступен.');
        }
        $account = $this->user($maxUserId);
        try {
            $userZone = new DateTimeZone((string) ($account['user_timezone'] ?? 'Europe/Moscow'));
            $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $userZone = new DateTimeZone('Europe/Moscow');
            $storageZone = new DateTimeZone('Europe/Moscow');
        }
        $date = $this->normalizeMaxDate($date) ?? $this->maxToday($maxUserId);
        $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $userZone);
        if (!$selected instanceof DateTimeImmutable) {
            $selected = new DateTimeImmutable('today', $userZone);
        }
        $date = $selected->format('Y-m-d');
        $rows = [];
        $schedule = $this->pdo->prepare(
            'SELECT schedule_json FROM posted_max_channels WHERE id=:channel AND is_active=1 LIMIT 1'
        );
        $schedule->execute(['channel' => (int) $post['channel_id']]);
        $times = $this->decodeJson((string) ($schedule->fetchColumn() ?: '[]'));
        foreach (array_chunk($showTimes ? [] : $times, 4) as $timeRow) {
            $buttons = [];
            foreach ($timeRow as $time) {
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) $time) !== 1) {
                    continue;
                }
                $buttons[] = GitPostedMaxApi::callbackButton(
                    (string) $time,
                    'gp:schedslot:' . $postId . ':' . $selected->format('Ymd') . ':' . str_replace(':', '', (string) $time)
                );
            }
            if ($buttons !== []) {
                $rows[] = $buttons;
            }
        }
        if ($showTimes) {
            $buttons = [];
            for ($minutes = 0; $minutes < 1440; $minutes += 30) {
                $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
                $buttons[] = GitPostedMaxApi::callbackButton(
                    $time,
                    'gp:scheduletime:' . $postId . ':' . $selected->format('Ymd') . ':' . str_replace(':', '', $time)
                );
            }
            foreach (array_chunk($buttons, 4) as $timeRow) {
                $rows[] = $timeRow;
            }
        }
        $compact = static fn (DateTimeImmutable $value): string => $value->format('Ymd');
        $dateAction = $showTimes ? 'gp:scheduletimes:' : 'gp:scheddate:';
        $rows[] = [
            GitPostedMaxApi::callbackButton('← ' . $selected->modify('-1 day')->format('d.m'), $dateAction . $postId . ':' . $compact($selected->modify('-1 day'))),
            GitPostedMaxApi::callbackButton($selected->format('d.m'), 'gp:scheduletimes:' . $postId . ':' . $compact($selected)),
            GitPostedMaxApi::callbackButton($selected->modify('+1 day')->format('d.m') . ' →', $dateAction . $postId . ':' . $compact($selected->modify('+1 day'))),
        ];
        $rows[] = [GitPostedMaxApi::callbackButton('← Назад', 'gp:next:' . $postId)];
        $start = $selected->setTimezone($storageZone);
        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM posted_max_posts
             WHERE channel_id=:channel AND status='scheduled'
               AND scheduled_at>=:start AND scheduled_at<:finish"
        );
        $count->execute([
            'channel' => (int) $post['channel_id'],
            'start' => $start->format('Y-m-d H:i:s'),
            'finish' => $start->modify('+1 day')->format('Y-m-d H:i:s'),
        ]);
        $scheduledCount = (int) $count->fetchColumn();
        $status = $scheduledCount === 0
            ? 'не запланировано ни одного поста'
            : 'запланировано постов: ' . $scheduledCount;
        $this->setState($maxUserId, 'await_schedule', ['post_id' => $postId, 'date' => $date]);
        $this->api->answerCallback(
            $callbackId,
            "🕔 ОТЛОЖИТЬ ПОСТ\n\nНа " . $this->maxHumanDate($date) . ' ' . $status
                . ".\n\nОтправьте дату и время публикации в вашем часовом поясе: сначала дата, затем время.\n\n"
                . 'Например: ' . $selected->format('d.m.Y') . " 18:30\n"
                . $selected->format('d.m') . " 18:30\n\n"
                . "Для выбранной даты можно отправить только время: 18:30, 18 30 или 1830.\n\n"
                . ($showTimes ? 'Выберите время кнопкой ниже или напишите его вручную.' : 'Нажмите на дату, чтобы выбрать время кнопками с шагом 30 минут.'),
            $rows
        );
    }

    private function scheduleMaxPostAtChosenTime(
        string $callbackId,
        string $maxUserId,
        int $postId,
        string $date,
        string $time
    ): void {
        if ($this->normalizeMaxDate($date) === null) {
            throw new InvalidArgumentException('Некорректная дата публикации.');
        }
        $post = $this->ownedPost($maxUserId, $postId, 'schedule');
        if ($post === null) {
            throw new RuntimeException('Черновик недоступен для планирования.');
        }
        $effect = $this->currentMaxUpdateEffect('schedule_time');
        $account = $this->user($maxUserId);
        if ($effect === null) {
            if (
                !in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
                || $this->activePublicationAttemptForPost($postId) !== null
            ) {
                throw new RuntimeException('Пост уже отправляется или опубликован. Старая дата не применена.');
            }
            $scheduled = $this->parseMaxScheduleForDate(
                $time,
                (string) ($account['user_timezone'] ?? 'Europe/Moscow'),
                $date
            );
            $effect = $this->withManagedMaxPostLock(
                $maxUserId,
                $postId,
                'schedule',
                ['draft', 'scheduled', 'failed'],
                function () use ($postId, $date, $time, $scheduled): array {
                    return $this->completeMaxUpdateEffectLocked(
                        'schedule_time',
                        function () use ($postId, $date, $time, $scheduled): array {
                            $schedule = $this->pdo->prepare(
                                "UPDATE posted_max_posts
                                 SET status='scheduled',scheduled_at=:scheduled,error_text=NULL
                                 WHERE id=:id AND status IN ('draft','scheduled','failed')"
                            );
                            $schedule->execute(['scheduled' => $scheduled, 'id' => $postId]);
                            if ($schedule->rowCount() !== 1) {
                                throw new RuntimeException('Пост уже отправляется или опубликован. Старая дата не применена.');
                            }
                            return ['post_id' => $postId, 'date' => $date, 'time' => $time, 'scheduled_at' => $scheduled];
                        }
                    );
                }
            );
        }
        if (
            (int) ($effect['post_id'] ?? 0) !== $postId
            || (string) ($effect['date'] ?? '') !== $date
            || (string) ($effect['time'] ?? '') !== $time
            || trim((string) ($effect['scheduled_at'] ?? '')) === ''
        ) {
            throw new RuntimeException('Сохранённое время МАКС повреждено.');
        }
        $this->setState($maxUserId, null, []);
        $this->api->answerCallback(
            $callbackId,
            'Пост запланирован на ' . $this->displayDate(
                (string) $effect['scheduled_at'],
                (string) ($account['user_timezone'] ?? 'Europe/Moscow')
            ) . '.',
            [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]],
            'Готово'
        );
    }

    private function scheduleMaxPostAtDailySlot(
        string $callbackId,
        string $maxUserId,
        int $postId,
        string $slot,
        string $date
    ): void {
        if (
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $slot) !== 1
            || $this->ownedPost($maxUserId, $postId, 'schedule') === null
        ) {
            throw new RuntimeException('Черновик или время расписания недоступны.');
        }
        $scheduledAt = $this->withManagedMaxPostLock(
            $maxUserId,
            $postId,
            'schedule',
            ['draft', 'scheduled', 'failed'],
            function (array $_post, array $channelData) use ($postId, $slot, $date, $maxUserId): string {
                $times = array_values(array_filter(
                    $this->decodeJson((string) ($channelData['schedule_json'] ?? '[]')),
                    static fn (mixed $time): bool => is_string($time)
                        && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) === 1
                ));
                if (!in_array($slot, $times, true)) {
                    throw new RuntimeException('Этот слот уже удалён из расписания канала.');
                }
                $account = $this->user($maxUserId);
                $timezoneName = trim((string) (
                    $channelData['schedule_timezone']
                    ?? ($account['user_timezone'] ?? 'Europe/Moscow')
                ));
                try {
                    $scheduleZone = new DateTimeZone($timezoneName);
                } catch (Throwable) {
                    $scheduleZone = new DateTimeZone('Europe/Moscow');
                }
                try {
                    $storageZone = new DateTimeZone(
                        (string) ($this->config['timezone'] ?? 'Europe/Moscow')
                    );
                } catch (Throwable) {
                    $storageZone = new DateTimeZone('Europe/Moscow');
                }
                $now = new DateTimeImmutable('now', $scheduleZone);
                $when = new DateTimeImmutable(
                    $date . ' ' . $slot . ':00',
                    $scheduleZone
                );
                if ($when <= $now) {
                    throw new RuntimeException('Выберите будущее время публикации.');
                }
                $stored = $when->setTimezone($storageZone)->format('Y-m-d H:i:s');
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='scheduled',scheduled_at=:scheduled,error_text=NULL
                     WHERE id=:post AND status IN ('draft','scheduled','failed')"
                )->execute(['scheduled' => $stored, 'post' => $postId]);
                return $stored;
            }
        );
        $this->setState($maxUserId, null, []);
        $account = $this->user($maxUserId);
        $this->api->answerCallback(
            $callbackId,
            'Пост запланирован на ' . $this->displayDate(
                $scheduledAt,
                (string) ($account['user_timezone'] ?? 'Europe/Moscow')
            ) . '.',
            [[GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')]],
            'Готово'
        );
    }

    private function normalizeMaxDate(string $date): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed instanceof DateTimeImmutable
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date
                ? $date
                : null;
    }

    private function maxPostsForDate(string $userId, int $channelId, string $date): array
    {
        $account = $this->user($userId);
        try {
            $userZone = new DateTimeZone((string) ($account['user_timezone'] ?? 'Europe/Moscow'));
            $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $userZone = new DateTimeZone('Europe/Moscow');
            $storageZone = new DateTimeZone('Europe/Moscow');
        }
        $start = (new DateTimeImmutable($date . ' 00:00:00', $userZone))->setTimezone($storageZone);
        $end = $start->modify('+1 day');
        $stmt = $this->pdo->prepare(
            "SELECT p.*,c.title AS channel_title,c.chat_id,c.chat_type,
                    (
                      SELECT active_attempt.status
                      FROM posted_max_publish_attempts active_attempt
                      WHERE active_attempt.gate_post_id=p.id
                      LIMIT 1
                    ) AS active_attempt_status
              FROM posted_max_posts p
              JOIN posted_max_channels c ON c.id=p.channel_id
              WHERE p.channel_id=:channel
                AND p.status IN ('scheduled','max_pending','sent','failed')
                AND COALESCE(p.published_at,p.scheduled_at,p.created_at)>=:start_at
               AND COALESCE(p.published_at,p.scheduled_at,p.created_at)<:end_at
             ORDER BY COALESCE(p.published_at,p.scheduled_at,p.created_at),p.id"
        );
        $stmt->execute([
            'channel' => $channelId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchAll();
    }

    private function maxPostCountsForMonth(
        string $userId,
        int $channelId,
        string $month
    ): array {
        $account = $this->user($userId);
        try {
            $userZone = new DateTimeZone((string) ($account['user_timezone'] ?? 'Europe/Moscow'));
            $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $userZone = new DateTimeZone('Europe/Moscow');
            $storageZone = new DateTimeZone('Europe/Moscow');
        }
        $start = (new DateTimeImmutable($month . '-01 00:00:00', $userZone))->setTimezone($storageZone);
        $end = $start->modify('+1 month');
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(published_at,scheduled_at,created_at) AS effective_at
             FROM posted_max_posts
             WHERE channel_id=:channel
               AND status IN ('scheduled','max_pending','sent','failed')
               AND COALESCE(published_at,scheduled_at,created_at)>=:start_at
               AND COALESCE(published_at,scheduled_at,created_at)<:end_at"
        );
        $stmt->execute([
            'channel' => $channelId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            try {
                $date = (new DateTimeImmutable((string) $row['effective_at'], $storageZone))
                    ->setTimezone($userZone)
                    ->format('Y-m-d');
            } catch (Throwable) {
                continue;
            }
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }
        return $counts;
    }

    private function maxPlanPostLabel(string $userId, array $post): string
    {
        $effectiveAt = (string) (
            $post['published_at']
            ?? $post['scheduled_at']
            ?? $post['created_at']
            ?? ''
        );
        $time = $effectiveAt !== ''
            ? substr($this->displayDate(
                $effectiveAt,
                (string) (($this->user($userId)['user_timezone'] ?? 'Europe/Moscow'))
            ), -5)
            : '--:--';
        $title = trim((string) ($post['text'] ?? ''));
        $firstBreak = mb_strpos($title, "\n");
        if ($firstBreak !== false) {
            $title = mb_substr($title, 0, $firstBreak);
        }
        $title = trim($title);
        if ($title === '') {
            $title = '[медиа]';
        }
        if (mb_strlen($title) > 28) {
            $title = mb_substr($title, 0, 25) . '...';
        }
        $attemptStatus = (string) ($post['active_attempt_status'] ?? '');
        $status = $attemptStatus === 'needs_review'
            ? '⚠️'
            : ($attemptStatus !== ''
                ? '⏳'
                : match ((string) ($post['status'] ?? 'scheduled')) {
                    'sent' => '✅',
                    'failed' => '⚠️',
                    'draft' => '📝',
                    default => '⏳',
                });
        $parts = [$time, $status, $title];
        $delete = (int) ($post['delete_after_minutes'] ?? 0);
        $repeat = (int) ($post['repeat_interval_minutes'] ?? 0);
        if ($delete > 0) {
            $parts[] = '🗑' . $this->maxShortMinutesLabel($delete);
        }
        if ($repeat > 0) {
            $parts[] = '🔁' . $this->maxShortMinutesLabel($repeat);
        }
        return implode(' ', $parts);
    }

    private function maxShortMinutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'нет';
        }
        if ($minutes % 1440 === 0) {
            return (int) ($minutes / 1440) . 'д';
        }
        if ($minutes % 60 === 0) {
            return (int) ($minutes / 60) . 'ч';
        }
        return $minutes . 'м';
    }

    private function maxHumanDate(string $date): string
    {
        $value = new DateTimeImmutable($date);
        $weekdays = [1 => 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        return $weekdays[(int) $value->format('N')] . ', '
            . (int) $value->format('j') . ' '
            . $months[(int) $value->format('n')] . ' '
            . $value->format('Y') . ' г.';
    }

    private function maxShortDate(string $date): string
    {
        $value = new DateTimeImmutable($date);
        $weekdays = [1 => 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        return $weekdays[(int) $value->format('N')] . ', ' . $value->format('d.m');
    }

    private function maxMonthTitle(DateTimeImmutable $date): string
    {
        $months = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        return $months[(int) $date->format('n')];
    }

    private function maxPlural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;
        $word = $mod100 >= 11 && $mod100 <= 14
            ? $many
            : ($mod10 === 1 ? $one : ($mod10 >= 2 && $mod10 <= 4 ? $few : $many));
        return $count . ' ' . $word;
    }

    private function answerPost(string $callbackId, string $userId, int $postId): void
    {
        $post = $this->ownedPost($userId, $postId);
        if ($post === null) {
            throw new RuntimeException('Пост недоступен.');
        }
        $preview = trim((string) ($post['text'] ?? ''));
        if (mb_strlen($preview) > 900) {
            $preview = mb_substr($preview, 0, 899) . '…';
        }
        $text = $preview !== '' ? $preview : 'Медиа без текста';
        $rows = [];
        $canPost = $this->ownedPost($userId, $postId, 'post') !== null;
        $canManage = $this->ownedPost($userId, $postId, 'manage') !== null;
        $activeAttempt = $this->activePublicationAttemptForPost($postId);
        if (is_array($activeAttempt)) {
            $attemptId = (int) $activeAttempt['id'];
            $needsReview = (string) $activeAttempt['status'] === 'needs_review';
            $text = ($needsReview
                    ? "⚠️ МАКС не удалось подтвердить автоматически.\n"
                        . "Проверьте канал вручную; бот ничего повторно не отправляет.\n\n"
                    : "⏳ МАКС ещё подтверждает публикацию.\n"
                        . "Повторная отправка заблокирована, чтобы не создать дубль.\n\n")
                . $text;
            if ($canPost && !$this->maxAttemptIsFreshSending($activeAttempt)) {
                $rows[] = [GitPostedMaxApi::callbackButton(
                    'Проверить публикацию',
                    'gp:maxcheck:' . $postId . ':' . $attemptId
                )];
            }
            if ($canPost && $needsReview) {
                $rows[] = [GitPostedMaxApi::callbackButton(
                    'Публикация есть — указать пост',
                    'gp:maxacceptstart:' . $postId . ':' . $attemptId,
                    'positive'
                )];
                $rows[] = [GitPostedMaxApi::callbackButton(
                    'Публикации нет — снять блокировку',
                    'gp:maxreleaseconfirm:' . $postId . ':' . $attemptId,
                    'negative'
                )];
            }
        }
        if (
            $activeAttempt === null
            && $canManage
            && in_array((string) $post['status'], ['scheduled', 'failed'], true)
        ) {
            $rows[] = [
                GitPostedMaxApi::callbackButton('Изменить текст', 'gp:edit:' . $postId),
                GitPostedMaxApi::callbackButton('Медиа', 'gp:media:' . $postId),
            ];
            $rows[] = [GitPostedMaxApi::callbackButton('Кнопки', 'gp:buttons:' . $postId)];
            $rows[] = [
                GitPostedMaxApi::callbackButton('📅 Серия публикаций', 'gp:series:' . $postId),
                GitPostedMaxApi::callbackButton(
                    'Закреп: ' . (!empty($post['pin_after_publish']) ? 'да' : 'нет'),
                    'gp:pin:' . $postId . ':' . (empty($post['pin_after_publish']) ? 1 : 0)
                ),
            ];
            array_push($rows, ...$this->maxOptionsRows($post), ...$this->maxCommentRows($post));
            $rows[] = [
                GitPostedMaxApi::callbackButton('Дублировать', 'gp:duplicate:' . $postId),
                GitPostedMaxApi::callbackButton('Изменить дату', 'gp:schedule:' . $postId),
            ];
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    'Таймер удаления: ' . $this->maxMinutesLabel(
                        (int) ($post['delete_after_minutes'] ?? 0)
                    ),
                    'gp:delafter:' . $postId
                ),
                GitPostedMaxApi::callbackButton(
                    'Автоповтор: ' . $this->maxMinutesLabel(
                        (int) ($post['repeat_interval_minutes'] ?? 0)
                    ),
                    'gp:repeat:' . $postId
                ),
            ];
            $rows[] = [
                GitPostedMaxApi::callbackButton('Удалить', 'gp:cancel:' . $postId, 'negative'),
                GitPostedMaxApi::callbackButton('Опубликовать', 'gp:publish:' . $postId, 'positive'),
            ];
        }
        if (
            $activeAttempt === null
            && !$canManage
            && $canPost
            && in_array((string) $post['status'], ['draft', 'scheduled', 'failed'], true)
        ) {
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    'Опубликовать',
                    'gp:publish:' . $postId,
                    'positive'
                ),
            ];
        }
        if (
            $activeAttempt === null
            && $canManage
            && (string) $post['status'] === 'sent'
        ) {
            $rows[] = [
                GitPostedMaxApi::callbackButton('Изменить текст', 'gp:edit:' . $postId),
                GitPostedMaxApi::callbackButton('Медиа', 'gp:media:' . $postId),
            ];
            $rows[] = [GitPostedMaxApi::callbackButton('Кнопки', 'gp:buttons:' . $postId)];
            $rows[] = [GitPostedMaxApi::callbackButton('Настроить повтор', 'gp:repeat:' . $postId)];
            $rows[] = [GitPostedMaxApi::callbackButton('📅 Серия публикаций / дубликаты', 'gp:series:' . $postId)];
            $rows[] = [GitPostedMaxApi::callbackButton('Удалить публикации', 'gp:delete:' . $postId, 'negative')];
        }
        $backPayload = 'gp:plan';
        $effectiveAt = (string) (
            $post['published_at']
            ?? $post['scheduled_at']
            ?? $post['created_at']
            ?? ''
        );
        if ($effectiveAt !== '') {
            $account = $this->user($userId);
            $shown = $this->displayDate(
                $effectiveAt,
                (string) ($account['user_timezone'] ?? 'Europe/Moscow')
            );
            $parts = explode(' ', $shown, 2);
            $date = DateTimeImmutable::createFromFormat('!d.m.Y', (string) ($parts[0] ?? ''));
            if ($date instanceof DateTimeImmutable) {
                $backPayload = 'gp:planch:' . (int) $post['channel_id'] . ':' . $date->format('Y-m-d');
            }
        }
        $rows[] = [GitPostedMaxApi::callbackButton('← Назад', $backPayload)];
        $commentStatus = $this->maxCommentStatusText($postId);
        if ($commentStatus !== '') $text .= "\n\n" . $commentStatus;
        if (!empty($post['forward_message_id'])) {
            $chatId = trim((string) (($this->user($userId)['private_chat_id'] ?? '')));
            if ($chatId !== '') {
                $this->api->sendMessage(
                    $chatId,
                    '',
                    [],
                    [],
                    true,
                    ['type' => 'forward', 'mid' => (string) $post['forward_message_id']]
                );
            }
            $this->api->answerCallback(
                $callbackId,
                $activeAttempt !== null ? $text : 'Репост. Выберите действие.',
                $rows
            );
            return;
        }
        $media = $this->decodeJson((string) ($post['attachments_json'] ?? ''));
        if (count($media) === 12) {
            $chatId = trim((string) (($this->user($userId)['private_chat_id'] ?? '')));
            if ($chatId !== '') {
                $this->api->sendMessage($chatId, (string) ($post['text'] ?? ''), $media);
            }
            $media = [];
        }
        $this->api->answerCallback(
            $callbackId,
            $text,
            $rows,
            '',
            $media
        );
    }

    private function maxSeriesZones(string $userId): array
    {
        $account = $this->user($userId);
        return [
            new DateTimeZone((string) ($account['user_timezone'] ?? 'Europe/Moscow')),
            new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow')),
        ];
    }

    private function startMaxSeries(string $callbackId, string $userId, int $postId): void
    {
        $post = $this->ownedPost($userId, $postId, 'schedule');
        if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
            || $this->activePublicationAttemptForPost($postId) !== null) {
            throw new RuntimeException('Пост недоступен для серии публикаций.');
        }
        if ((string) $post['status'] === 'draft' && !hash_equals((string) $post['author_max_user_id'], $userId)) {
            $this->api->answerCallback($callbackId, 'Для серии из чужого черновика сначала создайте свой дубликат.',
                [[GitPostedMaxApi::callbackButton('Дублировать в свой черновик', 'gp:duplicate:' . $postId)]]);
            return;
        }
        if ($this->ownedPost($userId, $postId, 'post') === null) {
            throw new RuntimeException('Для создания серии нужны права публикации и планирования.');
        }
        if (!hash_equals((string) $post['author_max_user_id'], $userId)
            && $this->activeMaxManagerCategories($userId, (int) $post['channel_id']) !== []) {
            $this->api->answerCallback($callbackId, 'Сначала создайте свой дубликат и выберите рекламную компанию. Затем откройте в нём серию публикаций.',
                [[GitPostedMaxApi::callbackButton('Дублировать и выбрать компанию', 'gp:duplicate:' . $postId)]]);
            return;
        }
        $account = $this->user($userId);
        $existing = $this->decodeJson((string) ($account['state_data'] ?? ''));
        if (str_starts_with((string) ($account['state'] ?? ''), 'max_series_')
            && (int) ($existing['post_id'] ?? 0) === $postId
            && (string) ($existing['source_status'] ?? '') === (string) $post['status']
            && preg_match('/^[a-f0-9]{12}$/D', (string) ($existing['token'] ?? '')) === 1) {
            $data = $existing;
        } else {
            $data = ['post_id' => $postId, 'source_status' => (string) $post['status'], 'token' => bin2hex(random_bytes(6)), 'slots' => [], 'days' => [], 'time' => '12:00'];
            $this->setState($userId, 'max_series_setup', $data);
        }
        $this->showMaxSeries($callbackId, $userId, $data);
    }

    /** Consume the old UI revision before exposing a new one to the user. */
    private function saveMaxSeriesState(string $userId, string $state, array $data): array
    {
        $oldToken = (string) ($data['token'] ?? '');
        $data['token'] = bin2hex(random_bytes(6));
        $save = $this->pdo->prepare("UPDATE posted_max_users SET state=:state,state_data=:data
            WHERE max_user_id=:user AND LEFT(state,11)='max_series_'
              AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:old_token");
        $save->execute(['state' => $state, 'data' => $this->encodeJson($data), 'user' => $userId, 'old_token' => $oldToken]);
        if ($save->rowCount() !== 1) {
            throw new RuntimeException('Это меню серии устарело. Откройте актуальное меню в карточке поста.');
        }
        return $data;
    }

    private function maxSeriesData(string $userId, string $token): array
    {
        $account = $this->user($userId);
        $data = $this->decodeJson((string) ($account['state_data'] ?? ''));
        if (!str_starts_with((string) ($account['state'] ?? ''), 'max_series_')
            || !hash_equals((string) ($data['token'] ?? ''), $token)) {
            throw new RuntimeException('Это меню серии устарело. Откройте «Серия публикаций» в карточке поста.');
        }
        $post = $this->ownedPost($userId, (int) ($data['post_id'] ?? 0), 'schedule');
        if ($post === null || !in_array((string) $post['status'], ['draft', 'scheduled', 'failed', 'sent'], true)
            || $this->activePublicationAttemptForPost((int) $post['id']) !== null) {
            throw new RuntimeException('Состояние или права на исходный пост изменились.');
        }
        return $this->canonicalMaxSeriesCalendar($userId, $data);
    }

    private function showMaxSeries(string $callbackId, string $userId, array $data, string $view = 'menu', int $page = 0, string $month = '', ?string $chatId = null): void
    {
        [$localZone] = $this->maxSeriesZones($userId);
        $data = $this->canonicalMaxSeriesCalendar($userId, $data);
        $post = $this->ownedPost($userId, (int) $data['post_id'], 'schedule');
        if ($post === null) {
            throw new RuntimeException('Исходный пост недоступен.');
        }
        $slots = (array) ($data['slots'] ?? []);
        if ($view === 'calendar') {
            $month = $month !== '' ? $month : (string) ($data['month'] ?? (new DateTimeImmutable('now', $localZone))->format('Y-m'));
            if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1 || $this->normalizeMaxDate($month . '-01') === null) {
                throw new InvalidArgumentException('Неизвестный месяц.');
            }
            $data['month'] = $month;
        } elseif ($view === 'slot' && !isset($slots[$page])) {
            throw new RuntimeException('Экземпляр серии недоступен.');
        } elseif ($view === 'preview' && $slots === []) {
            throw new InvalidArgumentException('Сначала выберите даты публикаций.');
        }
        $nextState = match ($view) {
            'calendar' => 'max_series_calendar',
            'slot', 'preview' => 'max_series_preview',
            default => 'max_series_setup',
        };
        $data = $this->saveMaxSeriesState($userId, $nextState, $data);
        $prefix = 'gp:sr:' . $data['token'] . ':';
        $sourceNote = (string) $post['status'] === 'draft'
            ? 'Черновик станет первой публикацией; остальные даты получат отдельные копии.'
            : 'Будут созданы дополнительные копии. Исходный пост, его дата и автоповтор сохранятся.';
        $text = "📅 СЕРИЯ ПУБЛИКАЦИЙ\n\n" . $sourceNote . "\nЧасовой пояс: " . $localZone->getName()
            . "\nКаждый экземпляр можно отдельно закрепить и удалить. Автоповтор экземпляров выключен.\n";
        $rows = [];
        if ($view === 'calendar') {
            $month = $month !== '' ? $month : (string) ($data['month'] ?? (new DateTimeImmutable('now', $localZone))->format('Y-m'));
            if (preg_match('/^\d{4}-\d{2}$/D', $month) !== 1
                || $this->normalizeMaxDate($month . '-01') === null) {
                throw new InvalidArgumentException('Неизвестный месяц.');
            }
            $first = new DateTimeImmutable($month . '-01', $localZone);
            $data['month'] = $month;
            $days = (array) ($data['days'] ?? []);
            $text .= "\nОтметьте нужные дни. Время для новых дат: " . $data['time'] . ".\nВыбрано дней: " . count($days) . ". Уже настроенные экземпляры сохраняют своё время.";
            $rows[] = [
                GitPostedMaxApi::callbackButton('←', $prefix . 'cal:' . $first->modify('-1 month')->format('Y-m')),
                GitPostedMaxApi::callbackButton($this->maxMonthTitle($first), $prefix . 'cal:' . $month),
                GitPostedMaxApi::callbackButton('→', $prefix . 'cal:' . $first->modify('+1 month')->format('Y-m')),
            ];
            $week = [];
            foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $label) {
                $week[] = GitPostedMaxApi::callbackButton($label, $prefix . 'cal:' . $month);
            }
            $rows[] = $week;
            $week = [];
            for ($i = 1; $i < (int) $first->format('N'); $i++) {
                $week[] = GitPostedMaxApi::callbackButton('·', $prefix . 'cal:' . $month);
            }
            for ($day = 1; $day <= (int) $first->format('t'); $day++) {
                $date = $month . '-' . sprintf('%02d', $day);
                $selected = !empty($days[$date]);
                $week[] = GitPostedMaxApi::callbackButton(($selected ? '✓' : '') . $day, $prefix . 'day:' . $date . ':' . ($selected ? '0' : '1'), $selected ? 'positive' : 'default');
                if (count($week) === 7) {
                    $rows[] = $week;
                    $week = [];
                }
            }
            if ($week !== []) {
                $rows[] = $week;
            }
            $rows[] = [GitPostedMaxApi::callbackButton('Общее время / новые даты: ' . $data['time'], $prefix . 'time')];
            $rows[] = [GitPostedMaxApi::callbackButton('Проверить даты →', $prefix . 'calendarready', 'positive')];
            $rows[] = [GitPostedMaxApi::callbackButton('← Назад', $prefix . 'menu')];
        } elseif ($view === 'slot') {
            if (!isset($slots[$page])) {
                throw new RuntimeException('Экземпляр серии недоступен.');
            }
            $slot = $slots[$page];
            $text .= "\nЭкземпляр " . ($page + 1) . ' из ' . count($slots) . "\n"
                . $this->displayDate((string) $slot['at'], $localZone->getName())
                . "\nЗакреп: " . (!empty($slot['pin']) ? 'да' : 'нет')
                . "\nУдаление: " . $this->maxMinutesLabel((int) ($slot['delete'] ?? 0));
            $rows[] = [GitPostedMaxApi::callbackButton('Изменить дату и время', $prefix . 'when:' . $page)];
            $rows[] = [GitPostedMaxApi::callbackButton(!empty($slot['pin']) ? 'Выключить закреп' : 'Включить закреп', $prefix . 'pin:' . $page . ':' . (empty($slot['pin']) ? '1' : '0'))];
            $rows[] = [GitPostedMaxApi::callbackButton('Таймер удаления', $prefix . 'delete:' . $page)];
            $rows[] = [GitPostedMaxApi::callbackButton('← К списку', $prefix . 'preview:' . intdiv($page, 5))];
        } elseif ($view === 'preview') {
            if ($slots === []) {
                throw new InvalidArgumentException('Сначала выберите даты публикаций.');
            }
            $page = max(0, min($page, intdiv(count($slots) - 1, 5)));
            $text .= "\nБудет запланировано: " . count($slots) . ".\nНажмите экземпляр, чтобы изменить его дату, закреп или удаление.\n";
            foreach (array_slice($slots, $page * 5, 5, true) as $index => $slot) {
                $label = ($index + 1) . '. ' . $this->displayDate((string) $slot['at'], $localZone->getName());
                $text .= "\n" . $label . ' · закреп ' . (!empty($slot['pin']) ? 'да' : 'нет')
                    . ' · удаление ' . $this->maxMinutesLabel((int) ($slot['delete'] ?? 0));
                $rows[] = [GitPostedMaxApi::callbackButton($label, $prefix . 'slot:' . $index)];
            }
            $nav = [];
            if ($page > 0) {
                $nav[] = GitPostedMaxApi::callbackButton('←', $prefix . 'preview:' . ($page - 1));
            }
            if (($page + 1) * 5 < count($slots)) {
                $nav[] = GitPostedMaxApi::callbackButton('→', $prefix . 'preview:' . ($page + 1));
            }
            if ($nav !== []) {
                $rows[] = $nav;
            }
            $rows[] = [GitPostedMaxApi::callbackButton('✅ Запланировать ' . count($slots), $prefix . 'confirm', 'positive')];
            $rows[] = [GitPostedMaxApi::callbackButton('← Настройка дат', $prefix . 'menu')];
        } else {
            $text .= "\nВыберите период с шагом в днях, введите список дат либо отметьте дни в календаре.\nДо подтверждения публикации не создаются.";
            $rows[] = [GitPostedMaxApi::callbackButton('Период: каждые N дней', $prefix . 'period')];
            $rows[] = [GitPostedMaxApi::callbackButton('Произвольные даты и время', $prefix . 'dates')];
            $rows[] = [GitPostedMaxApi::callbackButton('Отметить дни в календаре', $prefix . 'cal')];
            if ($slots !== []) {
                $rows[] = [GitPostedMaxApi::callbackButton('Проверить экземпляры (' . count($slots) . ')', $prefix . 'preview:0')];
            }
            $rows[] = [GitPostedMaxApi::callbackButton('Отменить настройку', $prefix . 'cancel')];
        }
        if ($chatId !== null) {
            $this->api->sendMessage($chatId, $text, [], $rows);
        } else {
            $this->api->answerCallback($callbackId, $text, $rows);
        }
    }

    private function maxSeriesSlots(array $dates, array $post, array $previous = []): array
    {
        $settings = [];
        foreach ($previous as $slot) {
            $settings[(string) ($slot['at'] ?? '')] = $slot;
        }
        return array_map(static fn (string $date): array => $settings[$date] ?? [
            'at' => $date,
            'pin' => !empty($post['pin_after_publish']) ? 1 : 0,
            'delete' => max(0, (int) ($post['delete_after_minutes'] ?? 0)),
        ], $dates);
    }

    /** Calendar selection is a projection of the actual publication slots. */
    private function canonicalMaxSeriesCalendar(string $userId, array $data): array
    {
        [$localZone, $storageZone] = $this->maxSeriesZones($userId);
        $slots = array_values((array) ($data['slots'] ?? []));
        // Preserve selections left in the previous menu format during deployment.
        if (empty($data['calendar_canonical']) && $slots === [] && !empty($data['days'])) {
            $source = $this->ownedPost($userId, (int) $data['post_id'], 'schedule');
            foreach (array_keys((array) $data['days']) as $day) {
                $value = $day . ' ' . (string) ($data['time'] ?? '12:00') . ':00';
                $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $localZone);
                if ($date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value) {
                    $slots[] = ['at' => $date->setTimezone($storageZone)->format('Y-m-d H:i:s'),
                        'pin' => !empty($source['pin_after_publish']) ? 1 : 0,
                        'delete' => (int) ($source['delete_after_minutes'] ?? 0)];
                }
            }
        }
        $days = [];
        foreach ($slots as $slot) {
            $date = new DateTimeImmutable((string) $slot['at'], $storageZone);
            $days[$date->setTimezone($localZone)->format('Y-m-d')] = true;
        }
        ksort($days, SORT_STRING);
        $data['slots'] = $slots;
        $data['days'] = $days;
        $data['calendar_canonical'] = true;
        return $data;
    }

    private function handleMaxSeriesCallback(string $callbackId, string $userId, string $token, string $action, string $argument, string $desired): void
    {
        // A worker may retry after the transaction committed but before its response was delivered.
        if ($action === 'confirm') {
            $effect = $this->currentMaxUpdateEffect('schedule_series');
            if ($effect !== null) {
                if (!hash_equals((string) ($effect['token'] ?? ''), $token)) {
                    throw new RuntimeException('Сохранённая серия не совпадает с действием.');
                }
                $this->answerMaxSeriesCreated($callbackId, $effect);
                return;
            }
        }
        $data = $this->maxSeriesData($userId, $token);
        $prefix = 'gp:sr:' . $token . ':';
        if ($action === 'cancel') {
            $cancel = $this->pdo->prepare("UPDATE posted_max_users SET state=NULL,state_data=NULL
                WHERE max_user_id=:user AND LEFT(state,11)='max_series_'
                  AND JSON_UNQUOTE(JSON_EXTRACT(state_data,'$.token'))=:token");
            $cancel->execute(['user' => $userId, 'token' => $token]);
            if ($cancel->rowCount() !== 1) {
                throw new RuntimeException('Это меню серии уже закрыто либо устарело.');
            }
            $post = $this->ownedPost($userId, (int) $data['post_id']);
            if ($post !== null && (string) $post['status'] === 'draft') {
                $this->answerDraftSendSettings($callbackId, $userId, (int) $data['post_id']);
            } else {
                $this->answerPost($callbackId, $userId, (int) $data['post_id']);
            }
            return;
        }
        if ($action === 'confirm') {
            $effect = $this->confirmMaxSeries($userId, $token, (int) $data['post_id']);
            $this->answerMaxSeriesCreated($callbackId, $effect);
            return;
        }
        if (in_array($action, ['menu', 'preview', 'slot', 'cal'], true)) {
            $this->showMaxSeries($callbackId, $userId, $data, $action === 'cal' ? 'calendar' : $action, (int) $argument, $action === 'cal' ? $argument : '');
            return;
        }
        if ($action === 'day') {
            if ($this->normalizeMaxDate($argument) === null || !in_array($desired, ['0', '1'], true)) {
                throw new InvalidArgumentException('Неизвестная дата.');
            }
            [$localZone, $storageZone] = $this->maxSeriesZones($userId);
            $slots = array_values((array) ($data['slots'] ?? []));
            if ($desired === '1') {
                if (empty($data['days'][$argument])) {
                    $at = PostedPublicationPlan::one($argument . ' ' . $data['time'], $localZone, $storageZone);
                    $post = $this->ownedPost($userId, (int) $data['post_id'], 'schedule');
                    $slots[] = $this->maxSeriesSlots([$at], $post ?? [])[0];
                }
            } else {
                $slots = array_values(array_filter($slots, static function (array $slot) use ($argument, $localZone, $storageZone): bool {
                    return (new DateTimeImmutable((string) $slot['at'], $storageZone))->setTimezone($localZone)->format('Y-m-d') !== $argument;
                }));
            }
            if (count($slots) > PostedPublicationPlan::MAX_SLOTS) {
                throw new InvalidArgumentException('В серии может быть не более 366 публикаций.');
            }
            usort($slots, static fn (array $left, array $right): int => strcmp($left['at'], $right['at']));
            $data['slots'] = $slots;
            $this->showMaxSeries($callbackId, $userId, $data, 'calendar', 0, substr($argument, 0, 7));
            return;
        }
        if ($action === 'calendarready') {
            $this->showMaxSeries($callbackId, $userId, $data, 'preview');
            return;
        }
        if ($action === 'pin') {
            $index = (int) $argument;
            if (!isset($data['slots'][$index]) || !in_array($desired, ['0', '1'], true)) {
                throw new InvalidArgumentException('Экземпляр недоступен.');
            }
            $data['slots'][$index]['pin'] = (int) $desired;
            $this->showMaxSeries($callbackId, $userId, $data, 'slot', $index);
            return;
        }
        if (in_array($action, ['period', 'dates', 'time', 'when', 'delete'], true)) {
            if (in_array($action, ['when', 'delete'], true)) {
                if (!isset($data['slots'][(int) $argument])) {
                    throw new RuntimeException('Экземпляр недоступен.');
                }
                $data['index'] = (int) $argument;
            }
            [$localZone] = $this->maxSeriesZones($userId);
            $example = (new DateTimeImmutable('tomorrow', $localZone))->format('d.m.Y');
            $prompt = match ($action) {
                'period' => "Отправьте три строки:\n1. Первая дата и время\n2. Последняя дата (включительно)\n3. Шаг в календарных днях\n\nНапример:\n{$example} 18:30\n" . (new DateTimeImmutable('+2 months', $localZone))->format('d.m.Y') . "\n2\n\nПрошедшие даты не допускаются; не более 366 публикаций.",
                'dates' => "Отправьте даты и время, каждую публикацию с новой строки:\n{$example} 12:00\n{$example} 18:30\n\nНе более 366 публикаций. Повторяющиеся дата и время не допускаются.",
                'time' => 'Отправьте время ЧЧ:ММ, например 18:30. Оно заменит время ВСЕХ выбранных экземпляров, сохранив их закреп и удаление, и станет временем для новых дат. Если в один день есть несколько экземпляров, сначала задайте им разные даты.',
                'when' => 'Отправьте новую дату и время этого экземпляра: ' . $example . ' 18:30.',
                'delete' => "Отправьте таймер удаления только этого экземпляра.\n6 — часов, 90мин — минут, 2д — дней.\n0 — без удаления.",
            };
            $data = $this->saveMaxSeriesState($userId, 'max_series_' . $action, $data);
            $prefix = 'gp:sr:' . $data['token'] . ':';
            $back = in_array($action, ['when', 'delete'], true) ? 'slot:' . $data['index'] : ($action === 'time' ? 'cal' : 'menu');
            $this->api->answerCallback($callbackId, $prompt, [[GitPostedMaxApi::callbackButton('← Назад', $prefix . $back)]]);
            return;
        }
        throw new InvalidArgumentException('Неизвестное действие серии.');
    }

    private function handleMaxSeriesInput(string $chatId, string $userId, string $state, array $data, string $text): void
    {
        try {
            $data = $this->maxSeriesData($userId, (string) ($data['token'] ?? ''));
            [$localZone, $storageZone] = $this->maxSeriesZones($userId);
            $post = $this->ownedPost($userId, (int) $data['post_id'], 'schedule');
            $action = substr($state, strlen('max_series_'));
            if ($action === 'period') {
                $parts = array_values(array_filter(array_map('trim', preg_split('/\R/u', trim($text)) ?: []), static fn (string $line): bool => $line !== ''));
                if (count($parts) !== 3 || preg_match('/^\d{1,4}$/D', $parts[2]) !== 1) {
                    throw new InvalidArgumentException('Нужны три строки: первая дата и время, последняя дата, шаг в днях.');
                }
                $dates = PostedPublicationPlan::period($parts[0], $parts[1], (int) $parts[2], $localZone, $storageZone);
                $data['slots'] = $this->maxSeriesSlots($dates, $post ?? [], (array) ($data['slots'] ?? []));
            } elseif ($action === 'dates') {
                $data['slots'] = $this->maxSeriesSlots(PostedPublicationPlan::dates($text, $localZone, $storageZone), $post ?? [], (array) ($data['slots'] ?? []));
            } elseif ($action === 'time') {
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', trim($text)) !== 1) {
                    throw new InvalidArgumentException('Укажите время в формате ЧЧ:ММ, например 18:30.');
                }
                $clock = trim($text);
                $changed = [];
                $seen = [];
                foreach ((array) ($data['slots'] ?? []) as $slot) {
                    $day = (new DateTimeImmutable((string) $slot['at'], $storageZone))->setTimezone($localZone)->format('Y-m-d');
                    $at = PostedPublicationPlan::one($day . ' ' . $clock, $localZone, $storageZone);
                    if (isset($seen[$at])) {
                        throw new InvalidArgumentException('В один день есть несколько экземпляров. Общее время сделает их одинаковыми; сначала измените даты отдельных экземпляров.');
                    }
                    $seen[$at] = true;
                    $slot['at'] = $at;
                    $changed[] = $slot;
                }
                $data['slots'] = $changed;
                $data['time'] = $clock;
            } elseif (in_array($action, ['when', 'delete'], true)) {
                $index = (int) ($data['index'] ?? -1);
                if (!isset($data['slots'][$index])) {
                    throw new RuntimeException('Экземпляр серии недоступен.');
                }
                if ($action === 'when') {
                    $at = PostedPublicationPlan::one($text, $localZone, $storageZone);
                    foreach ($data['slots'] as $otherIndex => $slot) {
                        if ($otherIndex !== $index && (string) $slot['at'] === $at) {
                            throw new InvalidArgumentException('На эту дату и время уже есть экземпляр серии.');
                        }
                    }
                    $data['slots'][$index]['at'] = $at;
                } else {
                    $minutes = $this->parseFlexibleMinutes($text, 10080);
                    if ($minutes === null) {
                        throw new InvalidArgumentException('Укажите срок удаления до 7 дней: 6, 90мин, 2д или 0.');
                    }
                    $data['slots'][$index]['delete'] = $minutes;
                }
            } else {
                throw new InvalidArgumentException('Выберите способ настройки дат кнопкой в меню серии.');
            }
            $view = $action === 'time' ? 'calendar' : (in_array($action, ['when', 'delete'], true) ? 'slot' : 'preview');
            $this->showMaxSeries('', $userId, $data, $view, (int) ($data['index'] ?? 0), '', $chatId);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->api->sendMessage($chatId, $exception->getMessage());
        }
    }

    private function confirmMaxSeries(string $userId, string $token, int $postId): array
    {
        return $this->withManagedMaxPostLock($userId, $postId, 'schedule', ['draft', 'scheduled', 'failed', 'sent'],
            function (array $source, array $channel, array $permission) use ($userId, $token, $postId): array {
                if (empty($permission['can_post'])) {
                    throw new RuntimeException('Для создания серии нужны права публикации и планирования.');
                }
                if ((string) $source['status'] === 'draft' && !hash_equals((string) $source['author_max_user_id'], $userId)) {
                    throw new RuntimeException('Для серии из чужого черновика сначала создайте свой дубликат.');
                }
                return $this->completeMaxUpdateEffectLocked('schedule_series', function () use ($userId, $token, $postId, $source, $channel): array {
                    $stateStmt = $this->pdo->prepare('SELECT state,state_data FROM posted_max_users WHERE max_user_id=:user LIMIT 1 FOR UPDATE');
                    $stateStmt->execute(['user' => $userId]);
                    $state = $stateStmt->fetch();
                    $data = is_array($state) ? $this->decodeJson((string) ($state['state_data'] ?? '')) : [];
                    if (!is_array($state) || (string) $state['state'] !== 'max_series_preview'
                        || !hash_equals((string) ($data['token'] ?? ''), $token)
                        || (int) ($data['post_id'] ?? 0) !== $postId) {
                        throw new RuntimeException('Эта серия уже сохранена либо меню устарело.');
                    }
                    if ((string) ($data['source_status'] ?? '') !== (string) $source['status']) {
                        throw new RuntimeException('Состояние исходного поста изменилось. Откройте новую настройку серии.');
                    }
                    $slots = array_values((array) ($data['slots'] ?? []));
                    if ($slots === [] || count($slots) > PostedPublicationPlan::MAX_SLOTS) {
                        throw new InvalidArgumentException('Выберите от 1 до 366 публикаций.');
                    }
                    [, $storageZone] = $this->maxSeriesZones($userId);
                    $now = new DateTimeImmutable('now', $storageZone);
                    $seen = [];
                    $pins = 0;
                    $deletes = 0;
                    foreach ($slots as &$slot) {
                        $at = (string) ($slot['at'] ?? '');
                        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $at, $storageZone);
                        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $at || $date <= $now) {
                            throw new InvalidArgumentException('Одна из дат уже прошла или некорректна. Исправьте её в списке серии.');
                        }
                        if (isset($seen[$at])) {
                            throw new InvalidArgumentException('В серии повторяется дата и время.');
                        }
                        if ((string) $source['status'] === 'scheduled' && (string) ($source['scheduled_at'] ?? '') === $at) {
                            throw new InvalidArgumentException('На это время уже запланирован исходный пост. Укажите для копии другое время.');
                        }
                        $seen[$at] = true;
                        $slot['pin'] = !empty($slot['pin']) ? 1 : 0;
                        $slot['delete'] = (int) ($slot['delete'] ?? 0);
                        if ($slot['delete'] < 0 || $slot['delete'] > 10080) {
                            throw new InvalidArgumentException('Некорректный таймер удаления.');
                        }
                        $pins += $slot['pin'];
                        $deletes += $slot['delete'] > 0 ? 1 : 0;
                    }
                    unset($slot);
                    usort($slots, static fn (array $left, array $right): int => strcmp($left['at'], $right['at']));
                    if (empty($channel['can_post']) || ($pins > 0 && empty($channel['can_pin']))
                        || ($deletes > 0 && empty($channel['can_delete']))) {
                        throw new RuntimeException('У бота недостаточно прав для публикации, закрепа или удаления.');
                    }
                    $categoryId = $this->validateMaxSeriesAuthorLocked($source, $channel, $userId, count($slots), $pins);
                    $insert = $this->pdo->prepare("INSERT INTO posted_max_posts(
                        channel_id,author_max_user_id,manager_category_id,text,text_html,attachments_json,forward_message_id,buttons_json,options_json,
                        status,scheduled_at,notify,pin_after_publish,delete_after_minutes,
                        repeat_interval_minutes,repeat_remaining,repeat_next_at
                    ) VALUES(:channel,:author,:category,:text,:html,:attachments,:forward,:buttons,:options,
                        'scheduled',:at,:notify,:pin,:delete_minutes,NULL,0,NULL)");
                    $ids = [];
                    foreach ($slots as $index => $slot) {
                        if ($index === 0 && (string) $source['status'] === 'draft') {
                            $update = $this->pdo->prepare("UPDATE posted_max_posts SET status='scheduled',scheduled_at=:at,
                                pin_after_publish=:pin,delete_after_minutes=:delete_minutes,manager_category_id=:category,
                                repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL,error_text=NULL
                                WHERE id=:id AND status='draft'");
                            $update->execute(['at' => $slot['at'], 'pin' => $slot['pin'], 'delete_minutes' => $slot['delete'] > 0 ? $slot['delete'] : null, 'category' => $categoryId, 'id' => $postId]);
                            if ($update->rowCount() !== 1) {
                                throw new RuntimeException('Черновик уже изменён. Серия не создана.');
                            }
                            $ids[] = $postId;
                        } else {
                            $insert->execute([
                                'channel' => (int) $source['channel_id'], 'author' => $userId,
                                'category' => $categoryId, 'text' => $source['text'] ?? null,
                                'html' => $source['text_html'] ?? null, 'attachments' => $source['attachments_json'] ?? null,
                                'forward' => $source['forward_message_id'] ?? null, 'buttons' => $source['buttons_json'] ?? null,
                                'options' => $source['options_json'] ?? null,
                                'at' => $slot['at'], 'notify' => !empty($source['notify']) ? 1 : 0,
                                'pin' => $slot['pin'], 'delete_minutes' => $slot['delete'] > 0 ? $slot['delete'] : null,
                            ]);
                            $newId = (int) $this->pdo->lastInsertId();
                            $this->rebindMaxCopiedButtons($newId, $this->decodeJson((string) ($source['buttons_json'] ?? '')));
                            $ids[] = $newId;
                        }
                    }
                    $this->setState($userId, null, []);
                    return ['token' => $token, 'source_post_id' => $postId, 'post_ids' => $ids,
                        'source_preserved' => (string) $source['status'] !== 'draft'];
                });
            });
    }

    private function validateMaxSeriesAuthorLocked(array $source, array $channel, string $authorId, int $posts, int $pins): ?int
    {
        $ownerId = trim((string) ($channel['added_by'] ?? ''));
        $this->assertActiveMaxUsersLocked([$ownerId, $authorId]);
        if (hash_equals($authorId, $ownerId)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT can_post,can_schedule,is_ad_manager,post_quota_remaining,pin_quota_remaining
            FROM posted_max_permissions WHERE max_user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE');
        $stmt->execute(['user' => $authorId, 'channel' => (int) $channel['id']]);
        $permission = $stmt->fetch();
        if (!is_array($permission) || empty($permission['can_post']) || empty($permission['can_schedule'])) {
            throw new RuntimeException('У автора больше нет прав публикации и планирования в этом канале.');
        }
        // New publications belong to the actor, as with the ordinary duplicate action.
        $categoryId = hash_equals((string) $source['author_max_user_id'], $authorId)
            ? (int) ($source['manager_category_id'] ?? 0) : 0;
        $quota = $permission;
        if ($categoryId > 0) {
            if (empty($permission['is_ad_manager'])) {
                throw new RuntimeException('Автор больше не является рекламным менеджером.');
            }
            $category = $this->pdo->prepare('SELECT post_quota_remaining,pin_quota_remaining FROM posted_max_manager_categories
                WHERE id=:id AND max_user_id=:user AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE');
            $category->execute(['id' => $categoryId, 'user' => $authorId, 'channel' => (int) $channel['id']]);
            $quota = $category->fetch();
            if (!is_array($quota)) {
                throw new RuntimeException('Рекламная компания больше недоступна.');
            }
        } elseif (!empty($permission['is_ad_manager'])) {
            $category = $this->pdo->prepare('SELECT id FROM posted_max_manager_categories WHERE max_user_id=:user AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE');
            $category->execute(['user' => $authorId, 'channel' => (int) $channel['id']]);
            if ($category->fetchColumn() !== false) {
                throw new RuntimeException('Сначала создайте свой дубликат кнопкой «Дублировать», выберите рекламную компанию и настройте серию в этом черновике.');
            }
        }
        if ($categoryId > 0 || !empty($permission['is_ad_manager'])) {
            // The worker charges quota on dispatch. Account for earlier queued
            // initial publications without charging them again or modifying them.
            // The caller holds the same channel lock used by publication dispatch.
            $pending = $this->pdo->prepare("SELECT post.id,post.pin_after_publish,
                    COALESCE(attempt.manager_post_quota_charged,0) AS post_charged,
                    COALESCE(attempt.manager_pin_quota_charged,0) AS pin_charged
                FROM posted_max_posts post
                LEFT JOIN posted_max_publish_attempts attempt
                  ON attempt.gate_post_id=post.id AND attempt.attempt_kind='initial'
                WHERE post.channel_id=:channel AND post.author_max_user_id=:author
                  AND COALESCE(post.manager_category_id,0)=:category
                  AND post.status IN ('scheduled','publishing','max_pending')
                ORDER BY post.id FOR UPDATE");
            $pending->execute(['channel' => (int) $channel['id'], 'author' => $authorId, 'category' => $categoryId]);
            $queuedPosts = 0;
            $queuedPins = 0;
            foreach ($pending->fetchAll() as $queued) {
                $queuedPosts += empty($queued['post_charged']) ? 1 : 0;
                $queuedPins += !empty($queued['pin_after_publish']) && empty($queued['pin_charged']) ? 1 : 0;
            }
            if ($quota['post_quota_remaining'] !== null && (int) $quota['post_quota_remaining'] < $posts + $queuedPosts) {
                throw new RuntimeException('В рекламном лимите недостаточно публикаций для этой серии с учётом уже запланированных постов.');
            }
            if ($quota['pin_quota_remaining'] !== null && (int) $quota['pin_quota_remaining'] < $pins + $queuedPins) {
                throw new RuntimeException('В рекламном лимите недостаточно закреплений для этой серии с учётом уже запланированных постов.');
            }
        }
        return $categoryId > 0 ? $categoryId : null;
    }

    private function answerMaxSeriesCreated(string $callbackId, array $effect): void
    {
        $ids = array_values((array) ($effect['post_ids'] ?? []));
        $rows = [];
        foreach (array_slice($ids, 0, 5) as $index => $id) {
            $rows[] = [GitPostedMaxApi::callbackButton('Экземпляр ' . ($index + 1), 'gp:view:' . (int) $id)];
        }
        $rows[] = [GitPostedMaxApi::callbackButton('Контент-план', 'gp:plan')];
        $this->api->answerCallback($callbackId, 'Запланировано публикаций: ' . count($ids) . ".\n"
            . (!empty($effect['source_preserved']) ? 'Исходный пост и его настройки сохранены.' : 'Черновик включён в серию как первая публикация.')
            . "\nКаждый экземпляр доступен в контент-плане со своими закрепом и удалением.", $rows, 'Готово');
    }

    private function duplicateMaxPost(
        string $callbackId,
        string $userId,
        int $postId,
        ?int $managerCategoryId=null
    ): void {
        $effect=$this->currentMaxUpdateEffect('duplicate_post');
        if($effect!==null){
            if((int)($effect['source_post_id']??0)!==$postId){
                throw new RuntimeException('Сохранённое дублирование MAX повреждено.');
            }
            if(($effect['mode']??'')==='category_picker'){
                if($managerCategoryId!==null||$this->ownedPost($userId,$postId,'post')===null){
                    throw new RuntimeException('Сохранённый выбор рекламной компании MAX недоступен.');
                }
                $this->answerMaxDuplicateCategoryPicker($callbackId,$userId,$postId);
                return;
            }
            $newPostId=(int)($effect['post_id']??0);
            if(
                ($effect['mode']??'')!=='draft'
                ||$newPostId<=0
                ||(int)($effect['manager_category_id']??0)!==max(0,(int)($managerCategoryId??0))
                ||$this->ownedPost($userId,$newPostId,'manage')===null
            )throw new RuntimeException('Сохранённый черновик MAX недоступен.');
            $this->answerDraft($callbackId,$userId,$newPostId);return;
        }
        $post = $this->ownedPost($userId, $postId, 'post');
        if ($post === null) {
            throw new RuntimeException('Пост недоступен.');
        }
        if($managerCategoryId===null&&$this->activeMaxManagerCategories($userId,(int)$post['channel_id'])!==[]){
            $this->completeMaxUpdateEffect(
                'duplicate_post',
                static fn():array=>[
                    'mode'=>'category_picker',
                    'source_post_id'=>$postId,
                    'channel_id'=>(int)$post['channel_id'],
                    'manager_category_id'=>0,
                ]
            );
            $this->answerMaxDuplicateCategoryPicker($callbackId,$userId,$postId);
            return;
        }
        $effect = $this->withMaxChannelCapabilityLock(
            $userId,
            (int) $post['channel_id'],
            'post',
            function (array $lockedChannel,array $permission) use (
                $userId,
                $postId,
                $managerCategoryId
            ): array {
                return $this->completeMaxUpdateEffectLocked(
                    'duplicate_post',
                    function()use($lockedChannel,$permission,$userId,$postId,$managerCategoryId):array{
                        $sourceStmt = $this->pdo->prepare(
                            'SELECT * FROM posted_max_posts
                             WHERE id=:post AND channel_id=:channel LIMIT 1 FOR UPDATE'
                        );
                        $sourceStmt->execute([
                            'post'=>$postId,
                            'channel'=>(int)$lockedChannel['id'],
                        ]);
                        $source=$sourceStmt->fetch();
                        if(!is_array($source))throw new RuntimeException('Пост недоступен.');
                        $isOwner=hash_equals(
                            trim((string)($lockedChannel['added_by']??'')),
                            $userId
                        );
                        $isAuthor=hash_equals(
                            trim((string)($source['author_max_user_id']??'')),
                            $userId
                        );
                        if(!$isOwner&&!$isAuthor&&empty($permission['can_manage'])){
                            throw new RuntimeException('Права на исходный пост изменились.');
                        }
                        $resolvedCategoryId=null;
                        if(!empty($permission['is_ad_manager'])){
                            if((int)($managerCategoryId??0)>0){
                                $category=$this->pdo->prepare(
                                    'SELECT id FROM posted_max_manager_categories
                                     WHERE id=:category AND max_user_id=:user
                                       AND channel_id=:channel AND is_active=1
                                     LIMIT 1 FOR UPDATE'
                                );
                                $category->execute([
                                    'category'=>(int)$managerCategoryId,
                                    'user'=>$userId,
                                    'channel'=>(int)$lockedChannel['id'],
                                ]);
                                if($category->fetchColumn()===false){
                                    throw new RuntimeException('Рекламная компания недоступна.');
                                }
                                $resolvedCategoryId=(int)$managerCategoryId;
                            }else{
                                $category=$this->pdo->prepare(
                                    'SELECT id FROM posted_max_manager_categories
                                     WHERE max_user_id=:user AND channel_id=:channel
                                       AND is_active=1
                                     ORDER BY id LIMIT 1 FOR UPDATE'
                                );
                                $category->execute([
                                    'user'=>$userId,
                                    'channel'=>(int)$lockedChannel['id'],
                                ]);
                                if($category->fetchColumn()!==false){
                                    throw new RuntimeException(
                                        'Выберите рекламную компанию перед дублированием поста.'
                                    );
                                }
                            }
                        }elseif((int)($managerCategoryId??0)>0){
                            throw new RuntimeException('Рекламная компания недоступна.');
                        }
                        $stmt=$this->pdo->prepare(
                            "INSERT INTO posted_max_posts(
                                channel_id,author_max_user_id,text,text_html,attachments_json,forward_message_id,buttons_json,options_json,
                                notify,pin_after_publish,delete_after_minutes,
                                repeat_interval_minutes,repeat_remaining,manager_category_id
                             ) VALUES(
                                :channel,:author,:text,:text_html,:attachments,:forward_message_id,:buttons,:options,
                                :notify,:pin,:delete_minutes,:repeat_minutes,:repeat_remaining,:manager_category_id
                             )"
                        );
                        $stmt->execute([
                            'channel'=>(int)$source['channel_id'],
                            'author'=>$userId,
                            'text'=>$source['text']??null,
                            'text_html'=>$source['text_html']??null,
                            'attachments'=>$source['attachments_json']??null,
                            'forward_message_id'=>$source['forward_message_id']??null,
                            'buttons'=>$source['buttons_json']??null,
                            'options'=>$source['options_json']??null,
                            'notify'=>!empty($source['notify'])?1:0,
                            'pin'=>!empty($source['pin_after_publish'])?1:0,
                            'delete_minutes'=>$source['delete_after_minutes']??null,
                            'repeat_minutes'=>$source['repeat_interval_minutes']??null,
                            'repeat_remaining'=>!empty($source['repeat_interval_minutes'])?65535:0,
                            'manager_category_id'=>$resolvedCategoryId,
                        ]);
                        $newPostId=(int)$this->pdo->lastInsertId();
                        if($newPostId<=0)throw new RuntimeException('Не удалось продублировать публикацию.');
                        $this->rebindMaxCopiedButtons($newPostId, $this->decodeJson((string) ($source['buttons_json'] ?? '')));
                        return [
                            'mode'=>'draft',
                            'post_id'=>$newPostId,
                            'source_post_id'=>$postId,
                            'channel_id'=>(int)$lockedChannel['id'],
                            'manager_category_id'=>$resolvedCategoryId??0,
                        ];
                    }
                );
            }
        );
        $newPostId=(int)($effect['post_id']??0);
        if(
            ($effect['mode']??'')!=='draft'
            ||$newPostId<=0
            ||(int)($effect['source_post_id']??0)!==$postId
            ||(int)($effect['manager_category_id']??0)!==max(0,(int)($managerCategoryId??0))
        ){
            throw new RuntimeException('Сохранённый черновик MAX повреждён.');
        }
        $this->answerDraft(
            $callbackId,
            $userId,
            $newPostId
        );
    }

    private function beginMaxDraft(string $callbackId,string $userId,int $channelId,int $managerCategoryId): void
    {
        $effect=$this->currentMaxUpdateEffect('begin_draft');
        if($effect===null){
            $effect=$this->withMaxChannelCapabilityLock(
                $userId,
                $channelId,
                'post',
                function(array $lockedChannel,array $permission)use($userId,$managerCategoryId):array{
                    return $this->completeMaxUpdateEffectLocked(
                        'begin_draft',
                        function()use($lockedChannel,$permission,$userId,$managerCategoryId):array{
                            if($managerCategoryId>0){
                                $category=$this->pdo->prepare(
                                    'SELECT id FROM posted_max_manager_categories
                                     WHERE id=:category AND max_user_id=:user
                                       AND channel_id=:channel AND is_active=1
                                     LIMIT 1 FOR UPDATE'
                                );
                                $category->execute([
                                    'category'=>$managerCategoryId,
                                    'user'=>$userId,
                                    'channel'=>(int)$lockedChannel['id'],
                                ]);
                                if($category->fetchColumn()===false){
                                    throw new RuntimeException('Рекламная компания недоступна.');
                                }
                            }elseif(!empty($permission['is_ad_manager'])){
                                $categories=$this->pdo->prepare(
                                    'SELECT id FROM posted_max_manager_categories
                                     WHERE max_user_id=:user AND channel_id=:channel AND is_active=1
                                     ORDER BY id LIMIT 1 FOR UPDATE'
                                );
                                $categories->execute([
                                    'user'=>$userId,
                                    'channel'=>(int)$lockedChannel['id'],
                                ]);
                                if($categories->fetchColumn()!==false){
                                    throw new RuntimeException('Выберите рекламную компанию для нового поста.');
                                }
                            }
                            $stmt=$this->pdo->prepare(
                                'INSERT INTO posted_max_posts(channel_id,author_max_user_id,manager_category_id)
                                 VALUES(:channel,:author,:category)'
                            );
                            $stmt->execute([
                                'channel'=>(int)$lockedChannel['id'],
                                'author'=>$userId,
                                'category'=>$managerCategoryId>0?$managerCategoryId:null,
                            ]);
                            $postId=(int)$this->pdo->lastInsertId();
                            if($postId<=0)throw new RuntimeException('Не удалось создать черновик МАКС.');
                            $this->applyMaxChannelPostDefaults($postId,$userId);
                            return [
                                'mode'=>'draft',
                                'post_id'=>$postId,
                                'channel_id'=>(int)$lockedChannel['id'],
                                'channel_title'=>(string)$lockedChannel['title'],
                                'manager_category_id'=>$managerCategoryId>0?$managerCategoryId:0,
                            ];
                        }
                    );
                }
            );
        }
        $postId=(int)($effect['post_id']??0);
        if(
            ($effect['mode']??'')!=='draft'
            ||
            $postId<=0
            ||(int)($effect['channel_id']??0)!==$channelId
            ||(int)($effect['manager_category_id']??0)!==max(0,$managerCategoryId)
            ||$this->ownedPost($userId,$postId,'manage')===null
        )throw new RuntimeException('Сохранённый черновик MAX недоступен.');
        $this->setState($userId,'await_content',['post_id'=>$postId]);
        $this->api->answerCallback(
            $callbackId,
            'Отправьте текст или медиа для публикации в «'.(string)($effect['channel_title']??'канал').'».',
            [[GitPostedMaxApi::callbackButton('Отмена','gp:cancel:'.$postId,'negative')]]
        );
    }

    private function createMaxDraftFromTemplate(string $userId,int $templateId,int $channelId,int $managerCategoryId=0): int
    {
        $effect=$this->currentMaxUpdateEffect('template_draft');
        if($effect!==null){
            $postId=(int)($effect['post_id']??0);
            if(
                ($effect['mode']??'')!=='draft'
                ||$postId<=0
                ||(int)($effect['template_id']??0)!==$templateId
                ||(int)($effect['channel_id']??0)!==$channelId
                ||(int)($effect['manager_category_id']??0)!==max(0,$managerCategoryId)
                ||$this->ownedPost($userId,$postId,'manage')===null
            )throw new RuntimeException('Сохранённый черновик из шаблона недоступен.');
            return $postId;
        }
        $effect=$this->withMaxChannelCapabilityLock(
            $userId,
            $channelId,
            'post',
            function(array $lockedChannel,array $permission)use($userId,$templateId,$managerCategoryId):array{
                return $this->completeMaxUpdateEffectLocked(
                    'template_draft',
                    function()use($lockedChannel,$permission,$userId,$templateId,$managerCategoryId):array{
                        if($managerCategoryId>0){
                            $category=$this->pdo->prepare(
                                'SELECT id FROM posted_max_manager_categories
                                 WHERE id=:category AND max_user_id=:user
                                   AND channel_id=:channel AND is_active=1
                                 LIMIT 1 FOR UPDATE'
                            );
                            $category->execute([
                                'category'=>$managerCategoryId,
                                'user'=>$userId,
                                'channel'=>(int)$lockedChannel['id'],
                            ]);
                            if($category->fetchColumn()===false){
                                throw new RuntimeException('Рекламная компания недоступна.');
                            }
                        }elseif(!empty($permission['is_ad_manager'])){
                            $categories=$this->pdo->prepare(
                                'SELECT id FROM posted_max_manager_categories
                                 WHERE max_user_id=:user AND channel_id=:channel AND is_active=1
                                 ORDER BY id LIMIT 1 FOR UPDATE'
                            );
                            $categories->execute([
                                'user'=>$userId,
                                'channel'=>(int)$lockedChannel['id'],
                            ]);
                            if($categories->fetchColumn()!==false){
                                throw new RuntimeException('Выберите рекламную компанию для поста из шаблона.');
                            }
                        }
                        $templateStmt=$this->pdo->prepare(
                            'SELECT * FROM posted_max_templates
                             WHERE id=:template AND owner_max_user_id=:owner LIMIT 1 FOR UPDATE'
                        );
                        $templateStmt->execute(['template'=>$templateId,'owner'=>$userId]);
                        $template=$templateStmt->fetch();
                        if(!is_array($template))throw new RuntimeException('Шаблон недоступен.');
                        $stmt=$this->pdo->prepare(
                            'INSERT INTO posted_max_posts(
                                channel_id,author_max_user_id,text,attachments_json,buttons_json,manager_category_id,options_json
                             ) VALUES(:channel,:author,:text,:attachments,:buttons,:category,:options)'
                        );
                        $stmt->execute([
                            'channel'=>(int)$lockedChannel['id'],
                            'author'=>$userId,
                            'text'=>(string)$template['text'],
                            'attachments'=>(string)($template['attachments_json']??'[]'),
                            'buttons'=>(string)($template['buttons_json']??'[]'),
                            'category'=>$managerCategoryId>0?$managerCategoryId:null,
                            'options'=>$template['options_json']??null,
                        ]);
                        $postId=(int)$this->pdo->lastInsertId();
                        if($postId<=0)throw new RuntimeException('Не удалось создать черновик из шаблона.');
                        $this->rebindMaxCopiedButtons($postId, $this->decodeJson((string) ($template['buttons_json'] ?? '')));
                        $this->applyMaxChannelPostDefaults($postId,$userId);
                        return [
                            'mode'=>'draft',
                            'post_id'=>$postId,
                            'template_id'=>$templateId,
                            'channel_id'=>(int)$lockedChannel['id'],
                            'manager_category_id'=>$managerCategoryId>0?$managerCategoryId:0,
                        ];
                    }
                );
            }
        );
        return (int)($effect['post_id']??0);
    }

    private function rebindMaxCopiedButtons(int $postId, array $rows): void
    {
        if (!$this->pdo->inTransaction() || $postId <= 0) throw new RuntimeException('Копирование кнопок требует транзакцию.');
        $this->pdo->prepare('UPDATE posted_max_posts SET buttons_json=:buttons WHERE id=:post')
            ->execute(['buttons' => $this->encodeJson($this->maxRebindButtons($rows, $postId)), 'post' => $postId]);
    }

    private function sendTemplates(string $chatId, string $userId): void
    {
        [$text, $rows] = $this->templatesScreen($userId);
        $this->api->sendMessage($chatId, $text, [], $rows);
    }

    private function answerTemplates(string $callbackId, string $userId): void
    {
        [$text, $rows] = $this->templatesScreen($userId);
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function templatesScreen(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id,title FROM posted_max_templates
             WHERE owner_max_user_id=:owner ORDER BY updated_at DESC LIMIT 20"
        );
        $stmt->execute(['owner' => $userId]);
        $templates = $stmt->fetchAll();
        $rows = [[GitPostedMaxApi::callbackButton('Создать шаблон', 'gp:tplnew', 'positive')]];
        foreach ($templates as $template) {
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    (string) $template['title'],
                    'gp:tplview:' . (int) $template['id']
                ),
            ];
        }
        $rows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
        $text = "ШАБЛОНЫ\n\n";
        $text .= $templates === [] ? 'Шаблонов пока нет.' : 'Нажмите название, чтобы создать пост из шаблона.';
        return [$text, $rows];
    }

    private function answerTemplateView(
        string $callbackId,
        string $userId,
        int $templateId
    ): void {
        $template = $this->ownedTemplate($userId, $templateId);
        if ($template === null) {
            throw new RuntimeException('Шаблон недоступен.');
        }
        $media = $this->decodeJson((string) ($template['attachments_json'] ?? ''));
        $text = "ШАБЛОН: " . (string) $template['title']
            . "\nМедиа: " . count($media)
            . "\n\n"
            . (trim((string) ($template['text'] ?? '')) !== ''
                ? (string) $template['text']
                : 'Медиа без текста');
        if (count($media) === 12) {
            $chatId = trim((string) (($this->user($userId)['private_chat_id'] ?? '')));
            if ($chatId !== '') {
                $this->api->sendMessage($chatId, (string) $template['text'], $media);
            }
            $media = [];
        }
        $this->api->answerCallback(
            $callbackId,
            $text,
            [
                [GitPostedMaxApi::callbackButton(
                    'Использовать',
                    'gp:tpluse:' . $templateId,
                    'positive'
                )],
                [GitPostedMaxApi::callbackButton(
                    'Удалить',
                    'gp:tpldel:' . $templateId,
                    'negative'
                )],
                [GitPostedMaxApi::callbackButton('← Назад', 'gp:templates')],
            ],
            '',
            $media
        );
    }

    private function sendSettings(string $chatId, string $userId): void
    {
        [$text, $rows] = $this->settingsScreen($userId);
        $this->api->sendMessage($chatId, $text, [], $rows);
    }

    private function answerSettings(
        string $callbackId,
        string $userId,
        string $prefix = ''
    ): void
    {
        [$text, $rows] = $this->settingsScreen($userId);
        if ($prefix !== '') {
            $text = $prefix . "\n\n" . $text;
        }
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function settingsScreen(string $userId): array
    {
        $account = $this->user($userId);
        $zone = (string) ($account['user_timezone'] ?? 'Europe/Moscow');
        try {
            $time = (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('H:i');
        } catch (Throwable) {
            $time = '--:--';
        }
        $rows = [];
        $channels = $this->channelsForUser($userId);
        foreach (array_chunk($channels, 2) as $channelRow) {
            $row = [];
            foreach ($channelRow as $channel) {
                $row[] = GitPostedMaxApi::callbackButton(
                    mb_substr((string) $channel['title'], 0, 28),
                    'gp:channelsettings:' . (int) $channel['id']
                );
            }
            $rows[] = $row;
        }
        $rows[] = [GitPostedMaxApi::callbackButton('➕ Добавить новый канал', 'gp:addchannel', 'positive')];
        if ($channels !== []) {
            $rows[] = [GitPostedMaxApi::callbackButton('👥 Редакторы каналов', 'gp:access')];
        }
        $rows[] = [GitPostedMaxApi::callbackButton(
            'Часовой пояс: ' . $zone . ' (' . $time . ')',
            'gp:timezone'
        )];
        $rows[] = [GitPostedMaxApi::callbackButton('Продвинутые настройки', 'gp:advanced')];
        $rows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
        return [
            "⚙️ НАСТРОЙКИ\n\n"
                . "В этом разделе вы можете настроить работу с ботом, с отдельным каналом, "
                . "а также добавить новый канал в GitPosted.",
            $rows,
        ];
    }

    private function maxChannelScheduleScreen(
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): array {
        $channel = $this->ownerChannel($maxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Только владелец настраивает расписание канала.');
        }
        $times = [];
        foreach ($this->decodeJson((string) ($channel['schedule_json'] ?? '')) as $time) {
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) $time) === 1) {
                $times[] = (string) $time;
            }
        }
        $timezone = (string) (
            $channel['schedule_timezone']
            ?? ($this->user($maxUserId)['user_timezone'] ?? 'Europe/Moscow')
        );
        $lines = [];
        if ($prefix !== '') {
            $lines[] = $prefix;
            $lines[] = '';
        }
        $lines[] = '🗓️ РАСПИСАНИЕ';
        $lines[] = '';
        $lines[] = 'Канал: ' . (string) $channel['title'];
        $lines[] = 'Часовой пояс: ' . $timezone;
        $lines[] = 'Ежедневные слоты: ' . ($times !== [] ? implode(', ', $times) : 'не заданы');
        $lines[] = '';
        $lines[] = 'Отправьте список времени, например:';
        $lines[] = '09:00, 13:30, 18:00';
        $lines[] = '';
        $lines[] = 'Чтобы очистить расписание, отправьте «-».';
        return [
            implode("\n", $lines),
            [[GitPostedMaxApi::callbackButton(
                '← Назад',
                'gp:channelsettings:' . $channelId
            )]],
        ];
    }

    private function answerMaxChannelSchedule(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): void {
        [$text, $rows] = $this->maxChannelScheduleScreen($maxUserId, $channelId, $prefix);
        $this->setState($maxUserId, 'await_channel_schedule', ['channel_id' => $channelId]);
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function sendMaxChannelSchedule(
        string $chatId,
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): void {
        [$text, $rows] = $this->maxChannelScheduleScreen($maxUserId, $channelId, $prefix);
        $this->setState($maxUserId, 'await_channel_schedule', ['channel_id' => $channelId]);
        $this->api->sendMessage($chatId, $text, [], $rows);
    }

    private function maxChannelPostSettingsScreen(
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): array {
        $channel = $this->ownerChannel($maxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Только владелец настраивает канал.');
        }
        $deleteMinutes = (int) ($channel['post_delete_minutes'] ?? 0);
        $repeatMinutes = (int) ($channel['post_repeat_interval_minutes'] ?? 0);
        $lines = [];
        if ($prefix !== '') {
            $lines[] = $prefix;
            $lines[] = '';
        }
        $lines[] = '⚙️ НАСТРОЙКИ ПОСТОВ';
        $lines[] = '';
        $lines[] = 'Канал: ' . (string) $channel['title'];
        $lines[] = 'Эти значения автоматически применяются к каждому новому черновику.';
        return [
            implode("\n", $lines),
            [
                [GitPostedMaxApi::callbackButton(
                    !empty($channel['post_pin']) ? '✅ Закрепить' : '☑️ Закрепить',
                    'gp:chpostset:'.$channelId.':pin:'.(empty($channel['post_pin'])?1:0)
                )],
                [GitPostedMaxApi::callbackButton(
                    'Таймер удаления: ' . $this->maxMinutesLabel($deleteMinutes),
                    'gp:chpostdelete:' . $channelId
                )],
                [GitPostedMaxApi::callbackButton(
                    'Автоповтор: ' . $this->maxMinutesLabel($repeatMinutes),
                    'gp:chpostrepeat:' . $channelId
                )],
                ...$this->maxChannelOptionsRows($channel),
                [GitPostedMaxApi::callbackButton(
                    '← Назад',
                    'gp:channelsettings:' . $channelId
                )],
            ],
        ];
    }

    private function answerMaxChannelPostSettings(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): void {
        [$text, $rows] = $this->maxChannelPostSettingsScreen(
            $maxUserId,
            $channelId,
            $prefix
        );
        $this->setState($maxUserId, null, []);
        $this->api->answerCallback($callbackId, $text, $rows);
    }

    private function sendMaxChannelPostSettings(
        string $chatId,
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): void {
        [$text, $rows] = $this->maxChannelPostSettingsScreen(
            $maxUserId,
            $channelId,
            $prefix
        );
        $this->setState($maxUserId, null, []);
        $this->api->sendMessage($chatId, $text, [], $rows);
    }

    private function toggleMaxChannelPostDefault(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $setting,
        bool $desiredState
    ): void {
        if ($setting === 'silent') {
            $this->withOwnedMaxChannelLock(
                $maxUserId,
                $channelId,
                function () use ($channelId): void {
                    $this->pdo->prepare(
                        'UPDATE posted_max_channels SET post_silent=0 WHERE id=:channel'
                    )->execute(['channel' => $channelId]);
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts SET notify=1
                         WHERE channel_id=:channel AND status IN ('draft','scheduled','failed')"
                    )->execute(['channel' => $channelId]);
                }
            );
            throw new RuntimeException(
                'MAX не поддерживает публикации в каналах без уведомления.'
            );
        }
        $column = match ($setting) {
            'pin' => 'post_pin',
            default => throw new InvalidArgumentException('Неизвестная настройка поста.'),
        };
        $this->withOwnedMaxChannelLock(
            $maxUserId,
            $channelId,
            function () use ($channelId, $column, $desiredState): void {
                $this->pdo->prepare(
                    'UPDATE posted_max_channels SET '.$column.'=:desired WHERE id=:channel'
                )->execute(['desired'=>$desiredState?1:0,'channel'=>$channelId]);
            }
        );
        $this->answerMaxChannelPostSettings($callbackId, $maxUserId, $channelId);
    }

    private function answerMaxChannelDefaultTimerMenu(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $kind
    ): void {
        if (!in_array($kind, ['delete', 'repeat'], true)) {
            throw new InvalidArgumentException('Неизвестный таймер канала.');
        }
        $channel = $this->ownerChannel($maxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Только владелец настраивает канал.');
        }
        $isDelete = $kind === 'delete';
        $column = $isDelete ? 'post_delete_minutes' : 'post_repeat_interval_minutes';
        $current = (int) ($channel[$column] ?? 0);
        $values = $isDelete
            ? [0, 5, 15, 30, 60, 120, 240, 360, 720, 1440, 2880, 4320, 10080]
            : [0, 120, 240, 360, 720, 1440, 2880, 4320, 10080, 14400, 43200];
        $rows = [];
        foreach (array_chunk($values, 4) as $valueRow) {
            $buttons = [];
            foreach ($valueRow as $minutes) {
                $label = $minutes === 0 ? 'Нет' : $this->maxMinutesLabel($minutes);
                if ($minutes === $current) {
                    $label = '✅ ' . $label;
                }
                $buttons[] = GitPostedMaxApi::callbackButton(
                    $label,
                    'gp:chpost' . ($isDelete ? 'delset:' : 'repset:')
                        . $channelId . ':' . $minutes
                );
            }
            $rows[] = $buttons;
        }
        $rows[] = [GitPostedMaxApi::callbackButton(
            'Свой вариант',
            'gp:chpost' . ($isDelete ? 'delcustom:' : 'repcustom:') . $channelId
        )];
        $rows[] = [GitPostedMaxApi::callbackButton(
            '← Назад',
            'gp:channelpostsettings:' . $channelId
        )];
        $this->api->answerCallback(
            $callbackId,
            $isDelete
                ? "🗑 ТАЙМЕР УДАЛЕНИЯ\n\nВыберите срок удаления новых постов по умолчанию."
                : "🔁 АВТОПОВТОР\n\nВыберите интервал повтора новых постов по умолчанию.",
            $rows
        );
    }

    private function setMaxChannelDefaultTimer(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $kind,
        int $minutes
    ): void {
        if (!in_array($kind, ['delete', 'repeat'], true)) {
            throw new InvalidArgumentException('Неизвестный таймер канала.');
        }
        $maximum = $kind === 'delete' ? 10080 : 43200;
        if (
            $minutes < 0
            || $minutes > $maximum
            || ($kind === 'repeat' && $minutes > 0 && $minutes < 5)
        ) {
            throw new InvalidArgumentException('Недопустимое значение таймера.');
        }
        $column = $kind === 'delete'
            ? 'post_delete_minutes'
            : 'post_repeat_interval_minutes';
        $this->withOwnedMaxChannelLock(
            $maxUserId,
            $channelId,
            function () use ($channelId, $column, $minutes): void {
                $this->pdo->prepare(
                    "UPDATE posted_max_channels
                     SET {$column}=:minutes
                     WHERE id=:channel"
                )->execute([
                    'minutes' => $minutes > 0 ? $minutes : null,
                    'channel' => $channelId,
                ]);
            }
        );
        $this->answerMaxChannelPostSettings($callbackId, $maxUserId, $channelId);
    }

    private function applyMaxChannelPostDefaults(int $postId, string $maxUserId): void
    {
        if ($postId <= 0 || $maxUserId === '') {
            throw new InvalidArgumentException('Некорректный новый черновик МАКС.');
        }
        $apply = $this->pdo->prepare(
            "UPDATE posted_max_posts post
             JOIN posted_max_channels channel ON channel.id=post.channel_id
             SET post.notify=1,
                 post.pin_after_publish=channel.post_pin,
                 post.delete_after_minutes=channel.post_delete_minutes,
                 post.repeat_interval_minutes=channel.post_repeat_interval_minutes,
                 post.repeat_remaining=IF(
                   channel.post_repeat_interval_minutes IS NULL
                   OR channel.post_repeat_interval_minutes=0,
                   0,
                   65535
                 )
             WHERE post.id=:post
               AND post.author_max_user_id=:author
               AND post.status='draft'"
        );
        $apply->execute(['post' => $postId, 'author' => $maxUserId]);
        $options = $this->pdo->prepare(
            'SELECT post.options_json AS post_options,channel.options_json AS channel_options
             FROM posted_max_posts post JOIN posted_max_channels channel ON channel.id=post.channel_id
             WHERE post.id=:post AND post.author_max_user_id=:author AND post.status=\'draft\' LIMIT 1'
        );
        $options->execute(['post' => $postId, 'author' => $maxUserId]);
        $row = $options->fetch();
        if (is_array($row)) {
            $merged = $this->maxApplyChannelOptions(['options_json' => $row['post_options']], ['options_json' => $row['channel_options']]);
            $this->pdo->prepare('UPDATE posted_max_posts SET options_json=:options WHERE id=:post')
                ->execute(['options' => $this->encodeJson($merged), 'post' => $postId]);
        }
    }

    private function answerChannelSettings(
        string $callbackId,
        string $maxUserId,
        int $channelId,
        string $prefix = ''
    ): void {
        $this->setState($maxUserId, null, []);
        $channel = $this->maxChannelForUser($maxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Канал недоступен.');
        }
        $isOwner = (string) ($channel['added_by'] ?? '') === $maxUserId;
        $lines = [];
        if ($prefix !== '') {
            $lines[] = $prefix;
            $lines[] = '';
        }
        $lines[] = (string) $channel['title'];
        $lines[] = 'ID: ' . (string) $channel['chat_id'];
        $subscribers = 0;
        try {
            $chat = $this->api->getChat((string) $channel['chat_id']);
            $subscribers = (int) (
                $chat['participants_count']
                ?? $chat['members_count']
                ?? $chat['participants']
                ?? 0
            );
        } catch (Throwable) {
        }
        $lines[] = 'Подписчиков: ' . $subscribers;
        $lines[] = '';
        $rows = [];
        if ($isOwner) {
            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM posted_max_permissions
                 WHERE channel_id=:channel
                   AND is_ad_manager=0
                   AND (can_post=1 OR can_schedule=1 OR can_manage=1)'
            );
            $count->execute(['channel' => $channelId]);
            $editorCount = (int) $count->fetchColumn();
            $managerCountStmt=$this->pdo->prepare('SELECT COUNT(*) FROM posted_max_permissions WHERE channel_id=:channel AND is_ad_manager=1');
            $managerCountStmt->execute(['channel'=>$channelId]);$managerCount=(int)$managerCountStmt->fetchColumn();
            $lines[] = 'Роль: владелец канала';
            $lines[] = 'Редакторов: ' . $editorCount;
            $lines[] = 'Рекламных менеджеров: ' . $managerCount;
            $lines[] = '';
            $lines[] = (!empty($channel['can_post']) ? '✅' : '❌') . ' Бот может публиковать';
            $lines[] = (!empty($channel['can_edit']) ? '✅' : '❌') . ' Бот может редактировать публикации';
            $lines[] = (!empty($channel['can_pin']) ? '✅' : '❌') . ' Бот может закреплять';
            $lines[] = (!empty($channel['can_delete']) ? '✅' : '❌') . ' Бот может удалять публикации';
            $lines[] = '';
            $lines[] = 'Только владелец назначает редакторов, архивирует и отключает канал.';
            $rows[] = [
                GitPostedMaxApi::callbackButton('🔄 Обновить данные', 'gp:channelrefresh:' . $channelId),
                GitPostedMaxApi::callbackButton('✈️ Создать пост', 'gp:channel:' . $channelId, 'positive'),
            ];
            $rows[] = [
                GitPostedMaxApi::callbackButton('🗓️ Расписание', 'gp:channelschedule:' . $channelId),
                GitPostedMaxApi::callbackButton(
                    '📅 Контент-план',
                    'gp:planch:' . $channelId . ':' . $this->maxToday($maxUserId)
                ),
            ];
            $rows[] = [
                GitPostedMaxApi::callbackButton('Настройки постов', 'gp:channelpostsettings:' . $channelId),
            ];
            $rows[] = [GitPostedMaxApi::callbackButton(
                '👥 Редакторы (' . $editorCount . ')',
                'gp:editors:' . $channelId . ':0'
            )];
            $rows[]=[GitPostedMaxApi::callbackButton('💼 Рекламные менеджеры ('.$managerCount.')','gp:managers:'.$channelId.':0')];
            $rows[] = [GitPostedMaxApi::callbackButton(
                '🗂️ Добавить в архив',
                'gp:channelarchive:' . $channelId
            )];
            $rows[] = [GitPostedMaxApi::callbackButton(
                '🗑️ Отключить канал',
                'gp:channeldisable:' . $channelId,
                'negative'
            )];
        } else {
            $permission = $this->pdo->prepare(
                'SELECT can_post,can_schedule,can_manage,is_ad_manager,manager_description,post_quota_remaining,pin_quota_remaining FROM posted_max_permissions '
                    . 'WHERE channel_id=:channel AND max_user_id=:max_user LIMIT 1'
            );
            $permission->execute(['channel' => $channelId, 'max_user' => $maxUserId]);
            $permissionRow = $permission->fetch();
            $permissionRow = is_array($permissionRow) ? $permissionRow : [];
            if(!empty($permissionRow['is_ad_manager'])){
                $lines[]='Роль: рекламный менеджер';
                $lines[]='Публикации: '.$this->maxManagerQuotaLabel($permissionRow['post_quota_remaining']??null);
                $lines[]='Закрепления: '.$this->maxManagerQuotaLabel($permissionRow['pin_quota_remaining']??null);
                $description=trim((string)($permissionRow['manager_description']??''));if($description!=='')$lines[]="Описание:\n".$description;
            }else{$lines[] = 'Роль: редактор';$lines[] = 'Права: ' . $this->editorRightsLabel($permissionRow);}
            if (!empty($permissionRow['can_post'])) {
                $rows[] = [GitPostedMaxApi::callbackButton(
                    '✈️ Создать пост',
                    'gp:channel:' . $channelId,
                    'positive'
                )];
            }
            if (
                !empty($permissionRow['can_post'])
                || !empty($permissionRow['can_schedule'])
                || !empty($permissionRow['can_manage'])
            ) {
                $rows[] = [GitPostedMaxApi::callbackButton(
                    '📅 Контент-план',
                    'gp:planch:' . $channelId . ':' . $this->maxToday($maxUserId)
                )];
            }
            $rows[] = [GitPostedMaxApi::callbackButton(
                'Покинуть канал',
                'gp:edleave:' . $channelId . ':' . (string) $channel['added_by'],
                'negative'
            )];
        }
        $rows[] = [GitPostedMaxApi::callbackButton('← Назад', 'gp:settings')];
        $this->api->answerCallback($callbackId, implode("\n", $lines), $rows);
    }

    private function timezoneScreen(string $userId): array
    {
        $account = $this->user($userId);
        $zone = (string) ($account['user_timezone'] ?? 'Europe/Moscow');
        return [
            "ЧАСОВОЙ ПОЯС\n\nТекущий: {$zone}",
            [
                [
                    GitPostedMaxApi::callbackButton('Москва', 'gp:tz:moscow'),
                    GitPostedMaxApi::callbackButton('UTC', 'gp:tz:utc'),
                ],
                [
                    GitPostedMaxApi::callbackButton('Алматы', 'gp:tz:almaty'),
                    GitPostedMaxApi::callbackButton('Ереван', 'gp:tz:yerevan'),
                ],
                [GitPostedMaxApi::callbackButton('Другой часовой пояс', 'gp:tzcustom')],
                [GitPostedMaxApi::callbackButton('← Назад', 'gp:settings')],
            ],
        ];
    }

    private function advancedSettingsScreen(): array
    {
        $rows = [
            [GitPostedMaxApi::callbackButton('Каналы и роли', 'gp:access')],
            [GitPostedMaxApi::callbackButton('Связать Telegram', 'gp:link')],
            [GitPostedMaxApi::callbackButton('Справка', 'gp:help')],
            [GitPostedMaxApi::callbackButton('← Назад', 'gp:settings')],
        ];
        return [
            "🎓 ПРОДВИНУТЫЕ НАСТРОЙКИ\n\n"
                . "Управление каналами и редакторами, связь аккаунта Telegram и справка.",
            $rows,
        ];
    }

    private function answerEmojiCatalog(string $callbackId): void
    {
        $this->api->answerCallback(
            $callbackId,
            "КАТАЛОГ ЭМОДЗИ\n\n"
                . "Часто используемые:\n"
                . "😀 😂 😊 😍 🤩 😎 🤔 😢 😡\n"
                . "❤️ 🔥 ⭐ ✅ ❌ ⚠️ 💥 🎉\n"
                . "👍 👎 👏 🙏 💪 👀 📌 📣\n"
                . "🕔 📅 🗑 🔄 🔗 🎨 📷 🎬\n\n"
                . "Нажмите и удерживайте нужный эмодзи, чтобы скопировать его.",
            [[GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')]]
        );
    }

    private function notifyChannelRegistration(string $maxUserId, array $channel): void
    {
        $isOwner = !empty($channel['owner_verified']);
        $hasOwner = trim((string) ($channel['owner_max_user_id'] ?? '')) !== '';
        $lines = [
            'ПОДКЛЮЧЕНИЕ КАНАЛА',
            '',
            'Канал: ' . (string) ($channel['title'] ?? 'МАКС'),
            ($isOwner ? '✅' : '❌') . ' Вы владелец канала в GitPosted',
            (!empty($channel['can_post']) ? '✅' : '❌') . ' Бот может публиковать',
            (!empty($channel['can_edit']) ? '✅' : '❌') . ' Бот может редактировать публикации',
            (!empty($channel['can_pin']) ? '✅' : '❌') . ' Бот может закреплять',
            (!empty($channel['can_delete']) ? '✅' : '❌') . ' Бот может удалять публикации',
        ];
        if (!$isOwner) {
            $lines[] = '';
            $lines[] = $hasOwner
                ? 'У канала уже есть владелец в GitPosted. Он может назначить вас редактором.'
                : 'Канал сохранён без владельца: добавьте бота заново от имени владельца или администратора.';
        } elseif (empty($channel['can_post'])) {
            $lines[] = '';
            $lines[] = 'Выдайте боту право публикации, иначе отправка постов будет недоступна.';
        } else {
            $lines[] = '';
            $lines[] = 'Вы закреплены единственным владельцем канала в GitPosted и можете назначать редакторов.';
        }
        try {
            $this->api->sendToUser(
                $maxUserId,
                implode("\n", $lines),
                [],
                array_values(array_filter([
                    [GitPostedMaxApi::callbackButton('Каналы и роли', 'gp:access', 'positive')],
                ]))
            );
        } catch (Throwable $exception) {
            error_log('GitPosted MAX channel registration notice: ' . $exception->getMessage());
        }
    }

    private function answerChannelAccess(
        string $callbackId,
        string $maxUserId,
        string $prefix = ''
    ): void {
        $stmt = $this->pdo->prepare(
            "SELECT c.id,c.title,c.added_by,
                    COALESCE(p.can_post,0) AS can_post,
                    COALESCE(p.can_schedule,0) AS can_schedule,
                    COALESCE(p.can_manage,0) AS can_manage
             FROM posted_max_channels c
             LEFT JOIN posted_max_permissions p
               ON p.channel_id=c.id AND p.max_user_id=:max_user
             WHERE c.is_active=1 AND c.is_archived=0
               AND (
                 c.added_by=:max_user2
                 OR p.can_post=1
                 OR p.can_schedule=1
                 OR p.can_manage=1
               )
             ORDER BY (c.added_by=:max_user3) DESC,c.title,c.id
             LIMIT 100"
        );
        $stmt->execute([
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
            'max_user3' => $maxUserId,
        ]);
        $channels = $stmt->fetchAll();
        $lines = ['КАНАЛЫ И РОЛИ', ''];
        $rows = [];
        if ($prefix !== '') {
            $lines[] = $prefix;
            $lines[] = '';
        }
        if ($channels === []) {
            $lines[] = 'Доступных каналов пока нет. Добавьте бота в канал от имени владельца или администратора.';
        }
        foreach ($channels as $channel) {
            $channelId = (int) $channel['id'];
            $title = (string) $channel['title'];
            if ((string) ($channel['added_by'] ?? '') === $maxUserId) {
                $lines[] = '👑 ' . $title . ' — владелец';
                $rows[] = [
                    GitPostedMaxApi::callbackButton(
                        '👑 ' . mb_substr($title, 0, 90) . ' · редакторы',
                        'gp:editors:' . $channelId . ':0'
                    ),
                ];
                continue;
            }
            $rights = $this->editorRightsLabel($channel);
            $lines[] = '✍️ ' . $title . ' — ' . $rights;
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    'Покинуть «' . mb_substr($title, 0, 72) . '»',
                    'gp:edleave:' . $channelId . ':' . (string) $channel['added_by'],
                    'negative'
                ),
            ];
        }
        $rows[] = [GitPostedMaxApi::callbackButton('Главное меню', 'gp:menu')];
        $this->api->answerCallback($callbackId, implode("\n", $lines), $rows);
    }

    private function editorsScreen(string $ownerMaxUserId, int $channelId, int $page): array
    {
        $channel = $this->ownerChannel($ownerMaxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Только владелец канала управляет редакторами.');
        }
        $pageSize = 10;
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM posted_max_permissions
             WHERE channel_id=:channel
               AND is_ad_manager=0
               AND (can_post=1 OR can_schedule=1 OR can_manage=1)"
        );
        $countStmt->execute(['channel' => $channelId]);
        $total = (int) $countStmt->fetchColumn();
        $lastPage = max(0, (int) ceil($total / $pageSize) - 1);
        $page = max(0, min($page, $lastPage));
        $offset = $page * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT p.*,u.username,u.display_name
             FROM posted_max_permissions p
             LEFT JOIN posted_max_users u ON u.max_user_id=p.max_user_id
             WHERE p.channel_id=:channel
               AND p.is_ad_manager=0
               AND (p.can_post=1 OR p.can_schedule=1 OR p.can_manage=1)
             ORDER BY COALESCE(NULLIF(u.display_name,''),NULLIF(u.username,''),p.max_user_id)
             LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute(['channel' => $channelId]);
        $editors = $stmt->fetchAll();

        $text = "РЕДАКТОРЫ\n\nКанал: " . (string) $channel['title']
            . "\nВсего: {$total}\nСтраница: " . ($page + 1) . '/' . ($lastPage + 1);
        if ($editors === []) {
            $text .= "\n\nРедакторов пока нет.";
        }
        $rows = [];
        foreach ($editors as $editor) {
            $label = $this->maxUserLabel($editor) . ' · ' . $this->editorRightsLabel($editor);
            $rows[] = [
                GitPostedMaxApi::callbackButton(
                    mb_substr($label, 0, 120),
                    'gp:editor:' . $channelId . ':' . (string) $editor['max_user_id'] . ':' . $page
                ),
            ];
        }
        if ($lastPage > 0) {
            $navigation = [];
            if ($page > 0) {
                $navigation[] = GitPostedMaxApi::callbackButton(
                    '← ' . $page,
                    'gp:editors:' . $channelId . ':' . ($page - 1)
                );
            }
            if ($page < $lastPage) {
                $navigation[] = GitPostedMaxApi::callbackButton(
                    ($page + 2) . ' →',
                    'gp:editors:' . $channelId . ':' . ($page + 1)
                );
            }
            if ($navigation !== []) {
                $rows[] = $navigation;
            }
        }
        $rows[] = [GitPostedMaxApi::callbackButton(
            'Добавить редактора',
            'gp:editoradd:' . $channelId,
            'positive'
        )];
        $rows[] = [GitPostedMaxApi::callbackButton('К каналам', 'gp:access')];
        return [$text, $rows];
    }

    private function maxManagerQuotaLabel(mixed $value): string
    {
        return $value===null?'безлимит':(string)max(0,(int)$value);
    }

    private function maxManagersScreen(string $ownerId,int $channelId,int $page): array
    {
        $channel=$this->ownerChannel($ownerId,$channelId);
        if($channel===null)throw new RuntimeException('Только владелец канала управляет рекламными менеджерами.');
        $size=10;
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM posted_max_permissions WHERE channel_id=:channel AND is_ad_manager=1');
        $count->execute(['channel'=>$channelId]); $total=(int)$count->fetchColumn();
        $last=max(0,(int)ceil($total/$size)-1); $page=max(0,min($page,$last)); $offset=$page*$size;
        $stmt=$this->pdo->prepare("SELECT p.*,u.username,u.display_name FROM posted_max_permissions p LEFT JOIN posted_max_users u ON u.max_user_id=p.max_user_id WHERE p.channel_id=:channel AND p.is_ad_manager=1 ORDER BY COALESCE(NULLIF(u.display_name,''),NULLIF(u.username,''),p.max_user_id) LIMIT {$size} OFFSET {$offset}");
        $stmt->execute(['channel'=>$channelId]); $rows=[];
        foreach($stmt->fetchAll() as $manager){$label=$this->maxUserLabel($manager).' · '.$this->maxManagerQuotaLabel($manager['post_quota_remaining']??null).' / '.$this->maxManagerQuotaLabel($manager['pin_quota_remaining']??null);$rows[]=[GitPostedMaxApi::callbackButton(mb_substr($label,0,120),'gp:manager:'.$channelId.':'.$manager['max_user_id'].':'.$page)];}
        if($last>0){$nav=[];if($page>0)$nav[]=GitPostedMaxApi::callbackButton('← '.$page,'gp:managers:'.$channelId.':'.($page-1));if($page<$last)$nav[]=GitPostedMaxApi::callbackButton(($page+2).' →','gp:managers:'.$channelId.':'.($page+1));if($nav!==[])$rows[]=$nav;}
        $rows[]=[GitPostedMaxApi::callbackButton('Добавить рекламного менеджера','gp:manageradd:'.$channelId,'positive')];
        $rows[]=[GitPostedMaxApi::callbackButton('К каналу','gp:channelsettings:'.$channelId)];
        $text="РЕКЛАМНЫЕ МЕНЕДЖЕРЫ\n\nКанал: ".$channel['title']."\nВсего: {$total}";
        if($total===0)$text.="\n\nРекламных менеджеров пока нет.";
        return [$text,$rows];
    }

    private function maxManagerScreen(string $ownerId,int $channelId,string $managerId,int $page): array
    {
        $channel=$this->ownerChannel($ownerId,$channelId);if($channel===null)throw new RuntimeException('Нет доступа.');
        $stmt=$this->pdo->prepare("SELECT p.*,u.username,u.display_name FROM posted_max_permissions p LEFT JOIN posted_max_users u ON u.max_user_id=p.max_user_id WHERE p.channel_id=:channel AND p.max_user_id=:user AND p.is_ad_manager=1 LIMIT 1");
        $stmt->execute(['channel'=>$channelId,'user'=>$managerId]);$manager=$stmt->fetch();if(!is_array($manager))throw new RuntimeException('Рекламный менеджер не найден.');
        $description=trim((string)($manager['manager_description']??''));
        $text="РЕКЛАМНЫЙ МЕНЕДЖЕР\n\nКанал: ".$channel['title']."\nПользователь: ".$this->maxUserLabel($manager)."\nПубликации: ".$this->maxManagerQuotaLabel($manager['post_quota_remaining']??null)."\nЗакрепления: ".$this->maxManagerQuotaLabel($manager['pin_quota_remaining']??null)."\n\nОписание:\n".($description!==''?$description:'не указано');
        $rows=[
            [GitPostedMaxApi::callbackButton('Описание','gp:managerfield:'.$channelId.':'.$managerId.':description:'.$page)],
            [GitPostedMaxApi::callbackButton('Лимит публикаций','gp:managerfield:'.$channelId.':'.$managerId.':posts:'.$page)],
            [GitPostedMaxApi::callbackButton('Лимит закреплений','gp:managerfield:'.$channelId.':'.$managerId.':pins:'.$page)],
            [GitPostedMaxApi::callbackButton('Рекламные компании','gp:managercats:'.$channelId.':'.$managerId.':'.$page)],
            [GitPostedMaxApi::callbackButton('Удалить менеджера','gp:managerremove:'.$channelId.':'.$managerId.':'.$page,'negative')],
            [GitPostedMaxApi::callbackButton('Назад','gp:managers:'.$channelId.':'.$page)],
        ];return[$text,$rows];
    }

    private function maxManagerCategory(string $managerId,int $channelId,int $id,bool $activeOnly=false): ?array
    { $s=$this->pdo->prepare('SELECT * FROM posted_max_manager_categories WHERE id=:id AND max_user_id=:user AND channel_id=:channel'.($activeOnly?' AND is_active=1':'').' LIMIT 1');$s->execute(['id'=>$id,'user'=>$managerId,'channel'=>$channelId]);$r=$s->fetch();return is_array($r)?$r:null; }

    private function activeMaxManagerCategories(string $managerId,int $channelId):array
    {
        $stmt=$this->pdo->prepare(
            'SELECT category.* FROM posted_max_manager_categories category
             INNER JOIN posted_max_permissions permission
               ON permission.max_user_id=category.max_user_id
              AND permission.channel_id=category.channel_id
              AND permission.is_ad_manager=1
             WHERE category.max_user_id=:user AND category.channel_id=:channel
               AND category.is_active=1
             ORDER BY category.id'
        );
        $stmt->execute(['user'=>$managerId,'channel'=>$channelId]);
        return $stmt->fetchAll();
    }

    private function answerMaxDuplicateCategoryPicker(
        string $callbackId,
        string $managerId,
        int $postId
    ):void {
        $post=$this->ownedPost($managerId,$postId,'post');
        if($post===null)throw new RuntimeException('Исходный пост недоступен.');
        $categories=$this->activeMaxManagerCategories(
            $managerId,
            (int)$post['channel_id']
        );
        if($categories===[]){
            throw new RuntimeException(
                'Список рекламных компаний изменился. Откройте пост заново.'
            );
        }
        $rows=[];
        foreach($categories as $category){
            $rows[]=[GitPostedMaxApi::callbackButton(
                mb_substr(
                    (string)$category['name'].' · '
                        .$this->maxManagerQuotaLabel($category['post_quota_remaining']??null),
                    0,
                    120
                ),
                'gp:dupcat:'.$postId.':'.(int)$category['id']
            )];
        }
        $rows[]=[GitPostedMaxApi::callbackButton('← К посту','gp:view:'.$postId)];
        $this->api->answerCallback(
            $callbackId,
            'Выберите рекламную компанию для копии поста.',
            $rows
        );
    }

    private function createOrReactivateMaxManagerCategoryLocked(
        string $managerId,
        int $channelId,
        string $name
    ): int {
        if($managerId===''||$channelId<=0||$name===''||!$this->pdo->inTransaction()){
            throw new RuntimeException('Создание рекламной компании требует активной транзакции.');
        }
        $existing=$this->pdo->prepare(
            'SELECT id,is_active FROM posted_max_manager_categories
             WHERE max_user_id=:user AND channel_id=:channel AND name=:name
             LIMIT 1 FOR UPDATE'
        );
        $existing->execute(['user'=>$managerId,'channel'=>$channelId,'name'=>$name]);
        $category=$existing->fetch();
        if(is_array($category)){
            $categoryId=(int)$category['id'];
            if(!empty($category['is_active']))return $categoryId;
            $busy=$this->pdo->prepare(
                "SELECT 1
                 FROM posted_max_publish_attempts attempt
                 INNER JOIN posted_max_posts post ON post.id=attempt.post_id
                 WHERE post.author_max_user_id=:user AND post.channel_id=:channel
                   AND post.manager_category_id=:category
                   AND (
                     attempt.status NOT IN ('completed','failed')
                     OR attempt.gate_post_id IS NOT NULL
                     OR attempt.gate_chat_id IS NOT NULL
                     OR (
                       attempt.status='completed'
                       AND attempt.pin_status IN ('pending','processing')
                     )
                   )
                 LIMIT 1 FOR UPDATE"
            );
            $busy->execute(['user'=>$managerId,'channel'=>$channelId,'category'=>$categoryId]);
            if($busy->fetchColumn()!==false){
                throw new RuntimeException(
                    'По этой компании ещё завершается публикация или закрепление. Повторите создание позже.'
                );
            }
            $this->pdo->prepare(
                'UPDATE posted_max_manager_categories
                 SET post_quota_remaining=0,pin_quota_remaining=0,is_active=1
                 WHERE id=:id AND max_user_id=:user AND channel_id=:channel AND is_active=0'
            )->execute(['id'=>$categoryId,'user'=>$managerId,'channel'=>$channelId]);
            return $categoryId;
        }
        $insert=$this->pdo->prepare(
            'INSERT INTO posted_max_manager_categories(
                max_user_id,channel_id,name,post_quota_remaining,pin_quota_remaining,is_active
             ) VALUES(:user,:channel,:name,0,0,1)'
        );
        $insert->execute(['user'=>$managerId,'channel'=>$channelId,'name'=>$name]);
        $categoryId=(int)$this->pdo->lastInsertId();
        if($categoryId<=0)throw new RuntimeException('Не удалось создать рекламную компанию.');
        return $categoryId;
    }

    private function maxManagerCategoriesScreen(string $ownerId,int $channelId,string $managerId,int $page): array
    {
        if($this->ownerChannel($ownerId,$channelId)===null)throw new RuntimeException('Нет доступа.');$s=$this->pdo->prepare('SELECT * FROM posted_max_manager_categories WHERE max_user_id=:user AND channel_id=:channel AND is_active=1 ORDER BY id');$s->execute(['user'=>$managerId,'channel'=>$channelId]);$rows=[];
        foreach($s->fetchAll() as $c){$label=$c['name'].' · '.$this->maxManagerQuotaLabel($c['post_quota_remaining']??null).' / '.$this->maxManagerQuotaLabel($c['pin_quota_remaining']??null);$rows[]=[GitPostedMaxApi::callbackButton(mb_substr($label,0,120),'gp:managercat:'.$channelId.':'.$managerId.':'.$c['id'].':'.$page)];}
        $rows[]=[GitPostedMaxApi::callbackButton('Добавить компанию','gp:managercatadd:'.$channelId.':'.$managerId.':'.$page,'positive')];$rows[]=[GitPostedMaxApi::callbackButton('К менеджеру','gp:manager:'.$channelId.':'.$managerId.':'.$page)];return["РЕКЛАМНЫЕ КОМПАНИИ\n\nЕсли список пуст, действует общий лимит. Если компании созданы, менеджер выбирает одну перед каждым новым постом.",$rows];
    }

    private function maxManagerCategoryScreen(string $ownerId,int $channelId,string $managerId,int $id,int $page): array
    {
        if($this->ownerChannel($ownerId,$channelId)===null)throw new RuntimeException('Нет доступа.');$c=$this->maxManagerCategory($managerId,$channelId,$id,true);if(!$c)return $this->maxManagerCategoriesScreen($ownerId,$channelId,$managerId,$page);$t="РЕКЛАМНАЯ КОМПАНИЯ\n\n".$c['name']."\nПубликации: ".$this->maxManagerQuotaLabel($c['post_quota_remaining']??null)."\nЗакрепления: ".$this->maxManagerQuotaLabel($c['pin_quota_remaining']??null);$r=[[GitPostedMaxApi::callbackButton('Лимит публикаций','gp:managercatfield:'.$channelId.':'.$managerId.':'.$id.':'.$page.':posts')],[GitPostedMaxApi::callbackButton('Лимит закреплений','gp:managercatfield:'.$channelId.':'.$managerId.':'.$id.':'.$page.':pins')],[GitPostedMaxApi::callbackButton('Удалить компанию','gp:managercatdel:'.$channelId.':'.$managerId.':'.$id.':'.$page,'negative')],[GitPostedMaxApi::callbackButton('Назад','gp:managercats:'.$channelId.':'.$managerId.':'.$page)]];return[$t,$r];
    }

    private function editorScreen(
        string $ownerMaxUserId,
        int $channelId,
        string $editorMaxUserId,
        int $page
    ): array {
        $channel = $this->ownerChannel($ownerMaxUserId, $channelId);
        if ($channel === null) {
            throw new RuntimeException('Только владелец канала изменяет права.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT p.*,u.username,u.display_name
             FROM posted_max_permissions p
             LEFT JOIN posted_max_users u ON u.max_user_id=p.max_user_id
             WHERE p.channel_id=:channel AND p.max_user_id=:editor
               AND p.is_ad_manager=0
             LIMIT 1"
        );
        $stmt->execute(['channel' => $channelId, 'editor' => $editorMaxUserId]);
        $editor = $stmt->fetch();
        if (!is_array($editor)) {
            throw new RuntimeException('Редактор не найден.');
        }
        $text = "ПРАВА РЕДАКТОРА\n\nКанал: " . (string) $channel['title']
            . "\nРедактор: " . $this->maxUserLabel($editor)
            . "\nID МАКС: {$editorMaxUserId}"
            . "\n\nРедактор не может назначать или удалять других редакторов.";
        $rows = [
            [GitPostedMaxApi::callbackButton(
                (!empty($editor['can_post']) ? '✅' : '☑️') . ' Публикация',
                'gp:edset:'.$channelId.':'.$editorMaxUserId.':p:'.(empty($editor['can_post'])?1:0).':'.$page
            )],
            [GitPostedMaxApi::callbackButton(
                (!empty($editor['can_schedule']) ? '✅' : '☑️') . ' Планирование',
                'gp:edset:'.$channelId.':'.$editorMaxUserId.':s:'.(empty($editor['can_schedule'])?1:0).':'.$page
            )],
            [GitPostedMaxApi::callbackButton(
                (!empty($editor['can_manage']) ? '✅' : '☑️') . ' Редактирование',
                'gp:edset:'.$channelId.':'.$editorMaxUserId.':m:'.(empty($editor['can_manage'])?1:0).':'.$page
            )],
            [GitPostedMaxApi::callbackButton(
                'Удалить редактора',
                'gp:edremove:' . $channelId . ':' . $editorMaxUserId . ':' . $page,
                'negative'
            )],
            [GitPostedMaxApi::callbackButton('Назад', 'gp:editors:' . $channelId . ':' . $page)],
        ];
        return [$text, $rows];
    }

    private function editorRightsLabel(array $row): string
    {
        $rights = [];
        if (!empty($row['can_post'])) {
            $rights[] = 'публикация';
        }
        if (!empty($row['can_schedule'])) {
            $rights[] = 'планирование';
        }
        if (!empty($row['can_manage'])) {
            $rights[] = 'редактирование';
        }
        return $rights !== [] ? implode(', ', $rights) : 'только просмотр отключён';
    }

    private function maxUserLabel(array $user): string
    {
        $displayName = trim((string) ($user['display_name'] ?? $user['name'] ?? ''));
        $username = ltrim(trim((string) ($user['username'] ?? '')), '@');
        if ($displayName !== '' && $username !== '') {
            return $displayName . ' (@' . $username . ')';
        }
        if ($displayName !== '') {
            return $displayName;
        }
        if ($username !== '') {
            return '@' . $username;
        }
        return 'МАКС ' . (string) ($user['max_user_id'] ?? $this->userId($user));
    }

    private function resolveEditorCandidate(array $channel, string $text, array $message): array
    {
        $candidateIds = [];
        if (preg_match('/^\d+$/D', $text) === 1) {
            $candidateIds[] = $text;
        } elseif (preg_match('/^@([A-Za-z0-9_]{2,64})$/D', $text, $match) === 1) {
            $stmt = $this->pdo->prepare(
                "SELECT max_user_id FROM posted_max_users
                 WHERE LOWER(username)=LOWER(:username) AND is_active=1
                 ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute(['username' => (string) $match[1]]);
            $knownId = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($knownId === '') {
                throw new RuntimeException('Этот @username ещё не известен боту. Используйте числовой ID МАКС или пересылку.');
            }
            $candidateIds[] = $knownId;
        }

        $link = $message['link'] ?? ($message['body']['link'] ?? null);
        if (is_array($link)) {
            $this->collectLinkedSenderIds($link, $candidateIds, 0);
        }
        $candidateIds = array_values(array_unique(array_filter(
            $candidateIds,
            static fn ($value): bool => is_string($value) && preg_match('/^\d+$/D', $value) === 1
        )));
        if ($candidateIds === []) {
            throw new RuntimeException('Не удалось определить пользователя. Укажите числовой ID МАКС, известный @username или перешлите сообщение с открытым отправителем.');
        }
        foreach ($candidateIds as $candidateId) {
            $member = $this->memberForChannel((string) $channel['chat_id'], $candidateId);
            if ($member === null || !empty($member['is_bot'])) {
                continue;
            }
            return $member;
        }
        throw new RuntimeException('Пользователь не найден среди участников этого канала.');
    }

    private function collectLinkedSenderIds(array $value, array &$candidateIds, int $depth): void
    {
        if ($depth > 4) {
            return;
        }
        foreach (['sender', 'sender_user', 'from', 'user'] as $key) {
            if (!is_array($value[$key] ?? null)) {
                continue;
            }
            $candidateId = $this->userId($value[$key]);
            if ($candidateId !== '') {
                $candidateIds[] = $candidateId;
            }
        }
        foreach (['message', 'linked_message', 'forwarded_message', 'link'] as $key) {
            if (is_array($value[$key] ?? null)) {
                $this->collectLinkedSenderIds($value[$key], $candidateIds, $depth + 1);
            }
        }
    }

    private function memberForChannel(string $chatId, string $maxUserId): ?array
    {
        try {
            $response = $this->api->getMembers($chatId, [$maxUserId]);
        } catch (Throwable $exception) {
            error_log('GitPosted MAX editor member lookup: ' . $exception->getMessage());
            return null;
        }
        foreach ((array) ($response['members'] ?? []) as $member) {
            if (is_array($member) && $this->userId($member) === $maxUserId) {
                return $member;
            }
        }
        return null;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function publishPost(int $postId, bool $repeat, ?string $actorMaxUserId = null): string
    {
        return $this->withMaxPostOperationLock(
            $postId,
            fn (): string => $this->publishPostUnlocked($postId, $repeat, $actorMaxUserId)
        );
    }

    private function publishPostUnlocked(
        int $postId,
        bool $repeat,
        ?string $actorMaxUserId = null
    ): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*,c.chat_id,c.title AS channel_title,c.chat_type,c.is_active,
                    c.can_post,c.can_pin,c.can_delete,c.added_by
             FROM posted_max_posts p
             JOIN posted_max_channels c ON c.id=p.channel_id
             WHERE p.id=:id LIMIT 1"
        );
        $stmt->execute(['id' => $postId]);
        $post = $stmt->fetch();
        if (!is_array($post)) {
            throw new RuntimeException('Публикация МАКС не найдена.');
        }
        $this->syncChannelById((string) $post['chat_id'], '');
        $stmt->execute(['id' => $postId]);
        $post = $stmt->fetch();
        if (
            !is_array($post)
            || empty($post['is_active'])
            || trim((string) ($post['added_by'] ?? '')) === ''
            || empty($post['can_post'])
        ) {
            throw new RuntimeException('У бота нет права публикации в выбранном МАКС-канале.');
        }

        $forwardMessageId = trim((string) ($post['forward_message_id'] ?? ''));
        $effective = $this->maxEffectivePostContent($post);
        $buttonPost = array_replace($post, ['buttons_json' => $this->encodeJson((array) $effective['buttons'])]);
        $postOptions = $this->maxPostOptions($post);
        $request = [
            'chat_id' => (string) $post['chat_id'],
            'text' => $forwardMessageId !== ''
                ? ''
                : (string) $effective['text'],
            'formatted_text' => $forwardMessageId === '' && (string) ($effective['html'] ?? '') !== ''
                ? (string) $effective['html']
                : null,
            'format' => $forwardMessageId === '' && (string) ($effective['html'] ?? '') !== ''
                ? 'html'
                : null,
            'media' => $forwardMessageId !== ''
                ? []
                : $this->decodeJson((string) ($post['attachments_json'] ?? '')),
            // MAX does not carry an inline keyboard when a message is forwarded.
            // Keep the URL buttons configured in GitPosted next to the forward link.
            'buttons' => $this->maxPublicationButtons($buttonPost),
            'interactive_buttons' => (array) $effective['buttons'],
            'disable_link_preview' => empty($postOptions['link_preview']),
            // Channel posts accept only notify=true (or an omitted field).
            'notify' => true,
            'link' => $forwardMessageId !== ''
                ? ['type' => 'forward', 'mid' => $forwardMessageId]
                : $this->maxPostReplyLink($post, (string) $post['chat_id']),
        ];
        GitPostedMaxApi::validateMediaAttachments((array) $request['media'], (array) $request['buttons']);
        GitPostedMaxApi::prepareMessageText(
            is_string($request['formatted_text']) ? $request['formatted_text'] : (string) $request['text'],
            is_string($request['format']) ? $request['format'] : null
        );

        // A history baseline is taken before dispatch. If it cannot be read,
        // nothing is sent: otherwise an old identical post could be mistaken
        // for an ambiguously accepted new one.
        $history = $this->api->recentMessages((string) $post['chat_id'], 100);
        $baselineMessageIds = [];
        foreach ($this->maxHistoryMessages($history) as $message) {
            $messageId = $this->maxMessageIdFromMessage($message);
            if ($messageId !== '') {
                $baselineMessageIds[] = $messageId;
            }
        }
        $baselineMessageIds = array_values(array_unique($baselineMessageIds));
        $attemptId = $this->reservePublicationAttempt(
            $postId,
            (int) $post['channel_id'],
            $repeat,
            $request,
            $baselineMessageIds,
            $actorMaxUserId
        );

        try {
            $response = $this->api->sendMessage(
                (string) $request['chat_id'],
                is_string($request['formatted_text']) ? $request['formatted_text'] : (string) $request['text'],
                (array) $request['media'],
                (array) $request['buttons'],
                (bool) $request['notify'],
                is_array($request['link']) ? $request['link'] : null,
                true,
                is_string($request['format']) ? $request['format'] : null,
                !empty($request['disable_link_preview'])
            );
        } catch (GitPostedMaxApiException $exception) {
            if (GitPostedMaxForbiddenFileFallback::isForbiddenExtensionRejection($exception)) {
                try {
                    $this->beginPublicationAttemptFilePreparation($attemptId);
                    $request['media'] = $this->api->reuploadForbiddenFiles(
                        (array) $request['media']
                    );
                    $this->replacePublicationAttemptRequestAfterDefinitiveRejection(
                        $attemptId,
                        $request
                    );
                    $response = $this->api->sendMessage(
                        (string) $request['chat_id'],
                        is_string($request['formatted_text']) ? $request['formatted_text'] : (string) $request['text'],
                        (array) $request['media'],
                        (array) $request['buttons'],
                        (bool) $request['notify'],
                        is_array($request['link']) ? $request['link'] : null,
                        true,
                        is_string($request['format']) ? $request['format'] : null,
                        !empty($request['disable_link_preview'])
                    );
                } catch (GitPostedMaxApiException $fallbackException) {
                    if ($fallbackException->deliveryMayBeUnknown) {
                        return $this->markPublicationAttemptPending(
                            $attemptId,
                            $fallbackException->getMessage()
                        );
                    }
                    if ($this->failPublicationAttempt($attemptId,$fallbackException->getMessage())) {
                        $this->finishPublicationPin($attemptId);
                        return 'confirmed';
                    }
                    throw $fallbackException;
                } catch (Throwable $fallbackException) {
                    if ($this->failPublicationAttempt($attemptId,$fallbackException->getMessage())) {
                        $this->finishPublicationPin($attemptId);
                        return 'confirmed';
                    }
                    throw $fallbackException;
                }
            } elseif ($exception->deliveryMayBeUnknown) {
                return $this->markPublicationAttemptPending($attemptId, $exception->getMessage());
            } elseif ($this->failPublicationAttempt($attemptId, $exception->getMessage())) {
                $this->finishPublicationPin($attemptId);
                return 'confirmed';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($this->failPublicationAttempt($attemptId, $exception->getMessage())) {
                $this->finishPublicationPin($attemptId);
                return 'confirmed';
            }
            throw $exception;
        }

        $messageId = GitPostedMaxApi::messageId($response);
        if ($messageId === '') {
            return $this->markPublicationAttemptPending(
                $attemptId,
                'МАКС принял запрос, но не вернул ID публикации.'
            );
        }
        $responseMessage = $this->firstMessage($response);
        $publishedTimestamp = is_array($responseMessage)
            ? $this->maxMessageTimestamp($responseMessage)
            : 0;
        $this->confirmPublicationAttempt(
            $attemptId,
            $messageId,
            $publishedTimestamp > 0
                ? $this->maxStorageDateFromTimestamp($publishedTimestamp)
                : null
        );
        $this->finishPublicationPin($attemptId);
        return 'confirmed';
    }

    private function withMaxPostOperationLock(int $postId, callable $operation): mixed
    {
        $lockName = $this->acquireMaxPostOperationLock($postId);
        if ($lockName === null) {
            throw new RuntimeException(
                'Пост уже изменяется или отправляется другим процессом.'
            );
        }
        try {
            return $operation();
        } finally {
            $this->releaseMaxPostOperationLock($lockName);
        }
    }

    private function assertMaxPostInputMode(array $post, array $stateData, string $userId, string $state): void
    {
        $account = $this->user($userId);
        $current = $this->decodeJson((string) ($account['state_data'] ?? ''));
        if (($account['state'] ?? '') !== $state || $current !== $stateData
            || (array_key_exists('expected_status', $stateData) && $stateData['expected_status'] !== (string) ($post['status'] ?? ''))
            || (array_key_exists('expected_forward_mid', $stateData)
                && !hash_equals((string) $stateData['expected_forward_mid'], trim((string) ($post['forward_message_id'] ?? ''))))) {
            throw new GitPostedMaxStaleInputException('Настройка поста изменилась после открытия редактора. Откройте редактирование заново; текущее содержимое сохранено.');
        }
    }

    /**
     * Revalidates the current channel tenure and actor permission while the
     * channel and post are locked. The named post lock also serializes live
     * MAX API edits with publication, deletion and other post mutations.
     *
     * @param list<string> $allowedStatuses
     */
    private function withManagedMaxPostLock(
        string $maxUserId,
        int $postId,
        string $capability,
        array $allowedStatuses,
        callable $operation
    ): mixed {
        if ($maxUserId === '' || $postId <= 0 || $allowedStatuses === []) {
            throw new InvalidArgumentException('Некорректный пользователь или пост МАКС.');
        }
        $requiredColumn = match ($capability) {
            'post' => 'can_post',
            'schedule' => 'can_schedule',
            'manage' => 'can_manage',
            default => throw new InvalidArgumentException('Некорректное право на пост МАКС.'),
        };
        $hint = $this->pdo->prepare(
            'SELECT channel_id FROM posted_max_posts WHERE id=:post LIMIT 1'
        );
        $hint->execute(['post' => $postId]);
        $channelId = (int) ($hint->fetchColumn() ?: 0);
        if ($channelId <= 0) {
            throw new RuntimeException('Пост МАКС не найден.');
        }
        if (!$this->ensureMaxChannelOwnerCurrent($channelId)) {
            throw new RuntimeException(
                'Владелец канала больше не является администратором либо проверка МАКС временно недоступна.'
            );
        }

        return $this->withMaxPostOperationLock(
            $postId,
            function () use (
                $maxUserId,
                $postId,
                $channelId,
                $capability,
                $requiredColumn,
                $allowedStatuses,
                $operation
            ): mixed {
                $ownsTransaction = !$this->pdo->inTransaction();
                if ($ownsTransaction) {
                    $this->pdo->beginTransaction();
                }
                try {
                    // Detach and permission changes take the same channel lock
                    // first, so an old callback cannot outlive revoked access.
                    $channelStmt = $this->pdo->prepare(
                        'SELECT *
                         FROM posted_max_channels
                         WHERE id=:channel
                           AND is_active=1
                           AND added_by IS NOT NULL
                           AND added_by<>\'\'
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $channelStmt->execute(['channel' => $channelId]);
                    $channel = $channelStmt->fetch();
                    if (!is_array($channel)) {
                        throw new RuntimeException('Канал МАКС отключён или остался без владельца.');
                    }
                    $this->assertActiveMaxActorLocked($maxUserId);

                    $postStmt = $this->pdo->prepare(
                        'SELECT *
                         FROM posted_max_posts
                         WHERE id=:post AND channel_id=:channel
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $postStmt->execute(['post' => $postId, 'channel' => $channelId]);
                    $post = $postStmt->fetch();
                    if (
                        !is_array($post)
                        || !in_array((string) ($post['status'] ?? ''), $allowedStatuses, true)
                    ) {
                        throw new RuntimeException(
                            'Пост уже отправляется или его состояние изменилось.'
                        );
                    }

                    $permission = [
                        'can_post' => 1,
                        'can_schedule' => 1,
                        'can_manage' => 1,
                    ];
                    $isOwner = hash_equals(
                        trim((string) $channel['added_by']),
                        $maxUserId
                    );
                    if (!$isOwner) {
                        $permissionStmt = $this->pdo->prepare(
                            'SELECT can_post,can_schedule,can_manage
                             FROM posted_max_permissions
                             WHERE max_user_id=:user AND channel_id=:channel
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $permissionStmt->execute([
                            'user' => $maxUserId,
                            'channel' => $channelId,
                        ]);
                        $permissionRow = $permissionStmt->fetch();
                        if (!is_array($permissionRow)) {
                            throw new RuntimeException(
                                'Права пользователя на пост МАКС изменились.'
                            );
                        }
                        $permission = $permissionRow;
                        $isAuthor = hash_equals(
                            trim((string) ($post['author_max_user_id'] ?? '')),
                            $maxUserId
                        );
                        $authorOrManager = $isAuthor
                            || (int) ($permission['can_manage'] ?? 0) === 1;
                        $hasCapability = $capability === 'manage' && $isAuthor
                            ? (int) ($permission['can_post'] ?? 0) === 1
                            : (int) ($permission[$requiredColumn] ?? 0) === 1;
                        if (
                            !$hasCapability
                            || (
                                in_array($capability, ['post', 'schedule'], true)
                                && !$authorOrManager
                            )
                        ) {
                            throw new RuntimeException(
                                'Права пользователя на пост МАКС изменились.'
                            );
                        }
                    }
                    if ($capability === 'post' && empty($channel['can_post'])) {
                        throw new RuntimeException(
                            'У бота больше нет права публикации в МАКС-канале.'
                        );
                    }
                    $this->assertNoActivePublicationAttempt($postId);
                    $result = $operation($post, $channel, $permission);
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }
                    return $result;
                } catch (Throwable $exception) {
                    if ($ownsTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }

    private function acquireMaxPostOperationLock(int $postId): ?string
    {
        $lockName = 'gitposted_max_post_' . $postId;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
        $lock->execute(['lock_name' => $lockName]);
        return (int) $lock->fetchColumn() === 1 ? $lockName : null;
    }

    private function hasUnresolvedMaxPinForChat(string $chatId):bool
    {
        if($chatId==='')return false;
        $stmt=$this->pdo->prepare(
            "SELECT 1 FROM posted_max_publish_attempts
             WHERE chat_id=:chat AND status='completed'
               AND pin_status IN ('pending','processing')
             LIMIT 1"
        );
        $stmt->execute(['chat'=>$chatId]);
        return $stmt->fetchColumn()!==false;
    }

    private function releaseMaxPostOperationLock(string $lockName): void
    {
        $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }

    private function assertNoActivePublicationAttempt(int $postId): void
    {
        $active = $this->pdo->prepare(
            "SELECT 1 FROM posted_max_publish_attempts
             WHERE gate_post_id=:post LIMIT 1"
        );
        $active->execute(['post' => $postId]);
        if ($active->fetchColumn() !== false) {
            throw new RuntimeException(
                'Публикация МАКС ещё подтверждается. Изменение временно заблокировано.'
            );
        }
    }

    private function reservePublicationAttempt(
        int $postId,
        int $channelId,
        bool $repeat,
        array $request,
        array $baselineMessageIds,
        ?string $actorMaxUserId = null
    ): int {
        $requestJson = json_encode(
            $request,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $baselineJson = json_encode(
            array_values($baselineMessageIds),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $fingerprint = $this->maxPublicationFingerprint($request);

        $this->pdo->beginTransaction();
        try {
            // Detach uses the same first lock. Whichever transaction wins
            // decides whether the publication can be reserved.
            $channelLock = $this->pdo->prepare(
                "SELECT id,chat_id,title,is_active,added_by,can_post
                 FROM posted_max_channels
                 WHERE id=:channel LIMIT 1 FOR UPDATE"
            );
            $channelLock->execute(['channel' => $channelId]);
            $channel = $channelLock->fetch();
            if (
                !is_array($channel)
                || empty($channel['is_active'])
                || trim((string) ($channel['added_by'] ?? '')) === ''
                || empty($channel['can_post'])
                || !hash_equals((string) $channel['chat_id'], (string) $request['chat_id'])
            ) {
                throw new RuntimeException('Канал МАКС отключён или не имеет владельца и права публикации.');
            }
            $postStmt = $this->pdo->prepare(
                "SELECT * FROM posted_max_posts WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $postStmt->execute(['id' => $postId]);
            $post = $postStmt->fetch();
            if (!is_array($post) || (int) ($post['channel_id'] ?? 0) !== $channelId) {
                throw new RuntimeException('Публикация МАКС не найдена.');
            }
            $activeUserIds = [
                trim((string) ($channel['added_by'] ?? '')),
                trim((string) ($post['author_max_user_id'] ?? '')),
            ];
            if ($actorMaxUserId !== null) {
                $activeUserIds[] = $actorMaxUserId;
            }
            $this->assertActiveMaxUsersLocked($activeUserIds);
            if (
                $actorMaxUserId !== null
                && !hash_equals(trim((string) $channel['added_by']), $actorMaxUserId)
            ) {
                $permission = $this->pdo->prepare(
                    'SELECT can_post,can_manage
                     FROM posted_max_permissions
                     WHERE max_user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permission->execute([
                    'user' => $actorMaxUserId,
                    'channel' => $channelId,
                ]);
                $currentPermission = $permission->fetch();
                if (
                    !is_array($currentPermission)
                    || (int) ($currentPermission['can_post'] ?? 0) !== 1
                    || (
                        !hash_equals(
                            trim((string) ($post['author_max_user_id'] ?? '')),
                            $actorMaxUserId
                        )
                        && (int) ($currentPermission['can_manage'] ?? 0) !== 1
                    )
                ) {
                    throw new RuntimeException('Право публикации в МАКС-канале изменилось.');
                }
            }
            $ownerId = trim((string) ($channel['added_by'] ?? ''));
            $authorId = trim((string) ($post['author_max_user_id'] ?? ''));
            $authorPermission = null;
            if ($authorId !== '' && !hash_equals($ownerId, $authorId)) {
                $authorPermissionStmt = $this->pdo->prepare(
                    'SELECT can_post,can_schedule,is_ad_manager,
                            post_quota_remaining,pin_quota_remaining
                     FROM posted_max_permissions
                     WHERE max_user_id=:user AND channel_id=:channel
                     LIMIT 1 FOR UPDATE'
                );
                $authorPermissionStmt->execute([
                    'user'=>$authorId,
                    'channel'=>$channelId,
                ]);
                $authorPermission = $authorPermissionStmt->fetch();
                $scheduledCron = !$repeat && $actorMaxUserId === null;
                if (
                    !is_array($authorPermission)
                    || empty($authorPermission['can_post'])
                    || ($scheduledCron && empty($authorPermission['can_schedule']))
                ) {
                    throw new RuntimeException(
                        'Публикация отменена: у автора больше нет необходимых прав на МАКС-канал.'
                    );
                }
                if (
                    !empty($authorPermission['is_ad_manager'])
                    && (int) ($post['manager_category_id'] ?? 0) === 0
                ) {
                    $activeCategory = $this->pdo->prepare(
                        'SELECT id FROM posted_max_manager_categories
                         WHERE max_user_id=:user AND channel_id=:channel AND is_active=1
                         ORDER BY id LIMIT 1 FOR UPDATE'
                    );
                    $activeCategory->execute([
                        'user' => $authorId,
                        'channel' => $channelId,
                    ]);
                    if ($activeCategory->fetchColumn() !== false) {
                        throw new RuntimeException(
                            'Публикация отменена: выберите рекламную компанию.'
                        );
                    }
                }
            }
            $active = $this->pdo->prepare(
                "SELECT id FROM posted_max_publish_attempts
                 WHERE gate_post_id=:post LIMIT 1"
            );
            $active->execute(['post' => $postId]);
            if ($active->fetchColumn() !== false) {
                throw new RuntimeException('Эта публикация уже отправляется или ждёт подтверждения МАКС.');
            }
            $activeChat = $this->pdo->prepare(
                "SELECT id FROM posted_max_publish_attempts
                 WHERE gate_chat_id=:chat LIMIT 1"
            );
            $activeChat->execute(['chat' => (string) $request['chat_id']]);
            if ($activeChat->fetchColumn() !== false) {
                throw new RuntimeException(
                    'В этом канале уже выполняется публикация. Дождитесь подтверждения МАКС.'
                );
            }
            $pendingPin=$this->pdo->prepare(
                "SELECT 1 FROM posted_max_publish_attempts
                 WHERE chat_id=:chat AND status='completed'
                   AND pin_status IN ('pending','processing')
                 LIMIT 1 FOR UPDATE"
            );
            $pendingPin->execute(['chat'=>(string)$request['chat_id']]);
            if($pendingPin->fetchColumn()!==false){
                throw new RuntimeException(
                    'В канале ещё завершается закрепление предыдущей публикации.'
                );
            }
            if ($repeat) {
                $repeatAt = !empty($post['repeat_next_at'])
                    ? (strtotime((string) $post['repeat_next_at']) ?: 0)
                    : 0;
                if (
                    (string) $post['status'] !== 'sent'
                    || (int) $post['repeat_remaining'] < 1
                    || $repeatAt < 1
                    || $repeatAt > time()
                ) {
                    throw new RuntimeException('Автоповтор уже обработан другим процессом.');
                }
                $this->pdo->prepare(
                    "UPDATE posted_max_posts SET repeat_next_at=NULL,error_text=NULL WHERE id=:id"
                )->execute(['id' => $postId]);
            } elseif ((string) $post['status'] !== 'publishing') {
                throw new RuntimeException('Публикация уже обработана другим процессом.');
            }

            $chargePost = false;
            $chargePin = false;
            if ($authorId !== '' && !hash_equals($ownerId, $authorId)) {
                $manager = $authorPermission;
                $categoryId=(int)($post['manager_category_id']??0);
                if($categoryId>0){
                    if(!is_array($manager)||empty($manager['is_ad_manager'])){
                        throw new RuntimeException(
                            'Публикация отменена: автор больше не является рекламным менеджером.'
                        );
                    }
                    $categoryStmt=$this->pdo->prepare(
                        'SELECT post_quota_remaining,pin_quota_remaining
                         FROM posted_max_manager_categories
                         WHERE id=:id AND max_user_id=:user AND channel_id=:channel
                           AND is_active=1
                         LIMIT 1 FOR UPDATE'
                    );
                    $categoryStmt->execute([
                        'id'=>$categoryId,
                        'user'=>$authorId,
                        'channel'=>$channelId,
                    ]);
                    $category=$categoryStmt->fetch();
                    if(!is_array($category)){
                        throw new RuntimeException(
                            'Публикация отменена: рекламная компания удалена или отключена.'
                        );
                    }
                    $manager=$category;
                }
                if ($categoryId>0 || (is_array($manager) && !empty($manager['is_ad_manager']))) {
                    $chargePost = true;
                    $chargePin = !empty($post['pin_after_publish']);
                    if ($manager['post_quota_remaining'] !== null && (int) $manager['post_quota_remaining'] <= 0) {
                        throw new RuntimeException($this->maxManagerLimitMessage('публикаций',$ownerId,(string)($channel['title']??'')));
                    }
                    if ($chargePin && $manager['pin_quota_remaining'] !== null && (int) $manager['pin_quota_remaining'] <= 0) {
                        throw new RuntimeException($this->maxManagerLimitMessage('закреплений',$ownerId,(string)($channel['title']??'')));
                    }
                    $quotaTable=$categoryId>0?'posted_max_manager_categories':'posted_max_permissions';$quotaWhere=$categoryId>0?'id=:category AND max_user_id=:user AND channel_id=:channel':'max_user_id=:user AND channel_id=:channel AND is_ad_manager=1';$quota=$this->pdo->prepare(
                        'UPDATE '.$quotaTable.'
                         SET post_quota_remaining=IF(post_quota_remaining IS NULL,NULL,post_quota_remaining-1),
                             pin_quota_remaining=IF(:charge_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining-1,pin_quota_remaining)
                         WHERE '.$quotaWhere
                    );$quotaParams=['charge_pin'=>$chargePin?1:0,'user'=>$authorId,'channel'=>$channelId];if($categoryId>0)$quotaParams['category']=$categoryId;$quota->execute($quotaParams);
                }
            }

            $lastDispatch = $this->pdo->prepare(
                "SELECT TIMESTAMPDIFF(MICROSECOND,requested_at,NOW(6))
                 FROM posted_max_publish_attempts
                 WHERE chat_id=:chat
                 ORDER BY requested_at DESC
                 LIMIT 1"
            );
            $lastDispatch->execute(['chat' => (string) $request['chat_id']]);
            $elapsedMicros = $lastDispatch->fetchColumn();
            if ($elapsedMicros !== false) {
                $waitMicros = 550_000 - max(0, (int) $elapsedMicros);
                if ($waitMicros > 0) {
                    usleep($waitMicros);
                }
            }

            $attempt = $this->pdo->prepare(
                "INSERT INTO posted_max_publish_attempts(
                    attempt_key,post_id,channel_id,chat_id,attempt_kind,status,
                    request_json,fingerprint,baseline_message_ids_json,
                    gate_post_id,gate_chat_id,deadline_at,
                    manager_post_quota_charged,manager_pin_quota_charged
                 ) VALUES(
                    :attempt_key,:post,:channel,:chat,:kind,'sending',
                    :request_json,:fingerprint,:baseline_json,
                    :gate_post,:gate_chat,DATE_ADD(NOW(),INTERVAL 30 MINUTE),
                    :quota_post,:quota_pin
                 )"
            );
            try {
                $attempt->execute([
                    'attempt_key' => $this->newUuid(),
                    'post' => $postId,
                    'channel' => (int) $post['channel_id'],
                    'chat' => (string) $request['chat_id'],
                    'kind' => $repeat ? 'repeat' : 'initial',
                    'request_json' => $requestJson,
                    'fingerprint' => $fingerprint,
                    'baseline_json' => $baselineJson,
                    'gate_post' => $postId,
                    'gate_chat' => (string) $request['chat_id'],
                    'quota_post'=>$chargePost?1:0,
                    'quota_pin'=>$chargePin?1:0,
                ]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw new RuntimeException(
                        'В этом канале уже выполняется публикация. Дождитесь подтверждения МАКС.',
                        0,
                        $exception
                    );
                }
                throw $exception;
            }
            $attemptId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $attemptId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (
                str_starts_with($exception->getMessage(),'Лимит ')
                || str_contains($exception->getMessage(),'выберите рекламную компанию')
            ) {
                if ($repeat) {
                    $this->pdo->prepare("UPDATE posted_max_posts SET repeat_remaining=0,repeat_next_at=NULL,error_text=:error WHERE id=:id AND status='sent'")->execute(['error'=>$exception->getMessage(),'id'=>$postId]);
                } else {
                    $this->pdo->prepare("UPDATE posted_max_posts SET status='failed',error_text=:error WHERE id=:id AND status='publishing'")->execute(['error'=>$exception->getMessage(),'id'=>$postId]);
                }
            }
            throw $exception;
        }
    }

    private function beginPublicationAttemptFilePreparation(int $attemptId): void
    {
        $prepare = $this->pdo->prepare(
            "UPDATE posted_max_publish_attempts
             SET status='preparing',
                 requested_at=NOW(6),
                 deadline_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE),
                 last_checked_at=NULL,
                 error_text=NULL
             WHERE id=:id AND status='sending'"
        );
        $prepare->execute(['id' => $attemptId]);
        if ($prepare->rowCount() !== 1) {
            throw new RuntimeException(
                'MAX publication attempt changed state before file preparation.'
            );
        }
    }

    private function replacePublicationAttemptRequestAfterDefinitiveRejection(
        int $attemptId,
        array $request
    ): void {
        $requestJson = $this->encodeJson($request);
        $fingerprint = $this->maxPublicationFingerprint($request);
        $mediaJson = $this->encodeJson((array) ($request['media'] ?? []));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT post_id,status
                 FROM posted_max_publish_attempts
                 WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch();
            if (!is_array($attempt)) {
                throw new RuntimeException('MAX publication attempt was not found.');
            }
            if ((string) $attempt['status'] !== 'preparing') {
                throw new RuntimeException(
                    'MAX publication attempt changed state before the safe file retry.'
                );
            }

            $updated = $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET request_json=:request_json,
                     fingerprint=:fingerprint,
                     status='sending',
                     requested_at=NOW(6),
                     deadline_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE),
                     error_text=NULL
                 WHERE id=:id AND status='preparing'"
            );
            $updated->execute([
                'request_json' => $requestJson,
                'fingerprint' => $fingerprint,
                'id' => $attemptId,
            ]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException(
                    'MAX publication attempt could not be prepared for the safe file retry.'
                );
            }

            $this->pdo->prepare(
                "UPDATE posted_max_posts
                 SET attachments_json=:attachments,error_text=NULL
                 WHERE id=:id"
            )->execute([
                'attachments' => $mediaJson,
                'id' => (int) $attempt['post_id'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function markPublicationAttemptPending(int $attemptId, string $error): string
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT post_id,attempt_kind,status
                 FROM posted_max_publish_attempts
                 WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch();
            if (!is_array($attempt)) {
                throw new RuntimeException('Попытка публикации МАКС не найдена.');
            }
            if ((string) $attempt['status'] === 'completed') {
                $this->pdo->commit();
                return 'confirmed';
            }
            if (!in_array((string) $attempt['status'], ['sending', 'pending'], true)) {
                $this->pdo->commit();
                return 'pending';
            }
            $shortError = mb_substr($error, 0, 4000);
            $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET status='pending',error_text=:error
                 WHERE id=:id AND status IN ('sending','pending')"
            )->execute(['error' => $shortError, 'id' => $attemptId]);
            if ((string) $attempt['attempt_kind'] === 'initial') {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='max_pending',error_text=:error
                     WHERE id=:id AND status IN ('publishing','max_pending','failed')"
                )->execute(['error' => $shortError, 'id' => (int) $attempt['post_id']]);
            } else {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts SET error_text=:error
                     WHERE id=:id AND status='sent'"
                )->execute(['error' => $shortError, 'id' => (int) $attempt['post_id']]);
            }
            $this->pdo->commit();
            return 'pending';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Returns true when a concurrent webhook already completed the attempt.
     */
    private function failPublicationAttempt(int $attemptId, string $error): bool
    {
        $scope = $this->pdo->prepare(
            'SELECT channel_id,post_id FROM posted_max_publish_attempts
             WHERE id=:id LIMIT 1'
        );
        $scope->execute(['id' => $attemptId]);
        $scopeRow = $scope->fetch();
        if (!is_array($scopeRow)) {
            throw new RuntimeException('Попытка публикации МАКС не найдена.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'SELECT id FROM posted_max_channels WHERE id=:id LIMIT 1 FOR UPDATE'
            )->execute(['id' => (int) $scopeRow['channel_id']]);
            $this->pdo->prepare(
                'SELECT id FROM posted_max_posts
                 WHERE id=:id AND channel_id=:channel LIMIT 1 FOR UPDATE'
            )->execute([
                'id' => (int) $scopeRow['post_id'],
                'channel' => (int) $scopeRow['channel_id'],
            ]);
            $stmt = $this->pdo->prepare(
                "SELECT id,post_id,channel_id,attempt_kind,status,
                        manager_post_quota_charged,manager_pin_quota_charged
                 FROM posted_max_publish_attempts
                 WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch();
            if (!is_array($attempt)) {
                throw new RuntimeException('Попытка публикации МАКС не найдена.');
            }
            if ((string) $attempt['status'] === 'completed') {
                $this->pdo->commit();
                return true;
            }
            $shortError = mb_substr($error, 0, 4000);
            $failed = $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET status='failed',gate_post_id=NULL,gate_chat_id=NULL,error_text=:error
                 WHERE id=:id AND status IN ('sending','preparing','pending')"
            );
            $failed->execute([
                'error' => $shortError,
                'id' => $attemptId,
            ]);
            if ($failed->rowCount() === 1) {
                $this->refundMaxManagerQuota($attempt);
                if ((string) $attempt['attempt_kind'] === 'initial') {
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET status='failed',error_text=:error
                         WHERE id=:id AND status IN ('publishing','max_pending')"
                    )->execute([
                        'error' => $shortError,
                        'id' => (int) $attempt['post_id'],
                    ]);
                } else {
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET repeat_next_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE),error_text=:error
                         WHERE id=:id AND status='sent'"
                    )->execute([
                        'error' => $shortError,
                        'id' => (int) $attempt['post_id'],
                    ]);
                }
            }
            $this->pdo->commit();
            return false;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function refundMaxManagerQuota(array $attempt): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Возврат лимита публикации требует транзакцию.');
        }
        $refundPost = !empty($attempt['manager_post_quota_charged']);
        $refundPin = !empty($attempt['manager_pin_quota_charged']);
        if (!$refundPost && !$refundPin) return;
        $authorStmt = $this->pdo->prepare(
            'SELECT author_max_user_id,manager_category_id FROM posted_max_posts WHERE id=:post LIMIT 1 FOR UPDATE'
        );
        $authorStmt->execute(['post'=>(int)($attempt['post_id']??0)]);
        $authorRow=$authorStmt->fetch();$authorId=trim((string)($authorRow['author_max_user_id']??''));$categoryId=(int)($authorRow['manager_category_id']??0);
        if ($authorId !== '') {
            // Lifecycle revocation clears the charged marker before deleting
            // this ledger, so a later grant can never receive an old refund.
            $quotaTable=$categoryId>0?'posted_max_manager_categories':'posted_max_permissions';$quotaWhere=$categoryId>0?'id=:category AND max_user_id=:user AND channel_id=:channel':'max_user_id=:user AND channel_id=:channel';$quota=$this->pdo->prepare(
                'UPDATE '.$quotaTable.'
                 SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                     pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
                 WHERE '.$quotaWhere
            );$params=[
                'refund_post'=>$refundPost?1:0,'refund_pin'=>$refundPin?1:0,
                'user'=>$authorId,'channel'=>(int)($attempt['channel_id']??0),
            ];if($categoryId>0)$params['category']=$categoryId;$quota->execute($params);
        }
        $this->pdo->prepare(
            'UPDATE posted_max_publish_attempts
             SET manager_post_quota_charged=0,manager_pin_quota_charged=0
             WHERE id=:id'
        )->execute(['id'=>(int)($attempt['id']??0)]);
    }

    private function refundMaxManagerPinQuota(array $attempt): void
    {
        if (empty($attempt['manager_pin_quota_charged'])) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Возврат лимита закрепления требует транзакцию.');
        }
        $post = $this->pdo->prepare(
            'SELECT author_max_user_id,manager_category_id
             FROM posted_max_posts WHERE id=:post LIMIT 1 FOR UPDATE'
        );
        $post->execute(['post' => (int) ($attempt['post_id'] ?? 0)]);
        $row = $post->fetch();
        $author = trim((string) ($row['author_max_user_id'] ?? ''));
        $categoryId = (int) ($row['manager_category_id'] ?? 0);
        if ($author !== '') {
            if ($categoryId > 0) {
                $this->pdo->prepare(
                    'UPDATE posted_max_manager_categories
                     SET pin_quota_remaining=IF(
                       pin_quota_remaining IS NULL,NULL,pin_quota_remaining+1
                     )
                     WHERE id=:category AND max_user_id=:author AND channel_id=:channel'
                )->execute([
                    'category' => $categoryId,
                    'author' => $author,
                    'channel' => (int) ($attempt['channel_id'] ?? 0),
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE posted_max_permissions
                     SET pin_quota_remaining=IF(
                       pin_quota_remaining IS NULL,NULL,pin_quota_remaining+1
                     )
                     WHERE max_user_id=:author AND channel_id=:channel'
                )->execute([
                    'author' => $author,
                    'channel' => (int) ($attempt['channel_id'] ?? 0),
                ]);
            }
        }
        $this->pdo->prepare(
            'UPDATE posted_max_publish_attempts
             SET manager_pin_quota_charged=0 WHERE id=:attempt'
        )->execute(['attempt' => (int) ($attempt['id'] ?? 0)]);
    }

    /**
     * Resolves only accounting effects which are provably safe before an
     * owner detaches a channel. Ambiguous deliveries and review gates keep the
     * channel attached; a recent pin worker does the same.
     */
    private function settleMaxChannelDetachLocked(int $channelId, string $reason): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Отключение МАКС-канала требует транзакцию.');
        }

        $activeAttempt = $this->pdo->prepare(
            "SELECT id
             FROM posted_max_publish_attempts
             WHERE channel_id=:channel
               AND (
                 gate_post_id IS NOT NULL OR gate_chat_id IS NOT NULL
                 OR status IN ('sending','preparing','pending','needs_review')
               )
             LIMIT 1
             FOR UPDATE"
        );
        $activeAttempt->execute(['channel' => $channelId]);
        if ($activeAttempt->fetchColumn() !== false) {
            throw new RuntimeException(
                'В канале есть незавершённая или требующая проверки публикация. Завершите её проверку перед отключением.'
            );
        }

        if (!$this->settleMaxPinsWithoutPinLocked($channelId, null, $reason)) {
            throw new RuntimeException(
                'В канале завершается закрепление публикации. Повторите отключение.'
            );
        }

        $safeAttempts = $this->pdo->prepare(
            "SELECT attempt.*
             FROM posted_max_publish_attempts attempt
             JOIN posted_max_posts post ON post.id=attempt.post_id
             WHERE attempt.channel_id=:channel
               AND post.status IN ('draft','scheduled','failed','cancelled')
               AND (
                 attempt.manager_post_quota_charged=1
                 OR attempt.manager_pin_quota_charged=1
               )
             ORDER BY attempt.id
             FOR UPDATE"
        );
        $safeAttempts->execute(['channel' => $channelId]);
        foreach ($safeAttempts->fetchAll() as $safeAttempt) {
            $this->refundMaxManagerQuota($safeAttempt);
        }
    }

    /**
     * Settles pin effects that a lifecycle operation makes unnecessary.
     *
     * A pending pin was never dispatched, so its manager quota is returned.
     * A stale processing pin has an unknown external result and is conservatively
     * marked failed without a refund. A recent processing row is left untouched
     * so the caller can defer its lifecycle operation.
     */
    private function settleMaxPinsWithoutPinLocked(
        int $channelId,
        ?int $postId,
        string $reason,
        ?string $authorMaxUserId = null,
        ?int $categoryId = null
    ): bool {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Завершение закреплений требует транзакцию.');
        }
        $where = ['attempt.channel_id=:channel'];
        $params = ['channel' => $channelId];
        if ($postId !== null) {
            $where[] = 'attempt.post_id=:post';
            $params['post'] = $postId;
        }
        if ($authorMaxUserId !== null) {
            $where[] = 'post.author_max_user_id=:author';
            $params['author'] = $authorMaxUserId;
        }
        if ($categoryId !== null) {
            $where[] = 'post.manager_category_id=:category';
            $params['category'] = $categoryId;
        }
        $stmt = $this->pdo->prepare(
            "SELECT attempt.*
             FROM posted_max_publish_attempts attempt
             JOIN posted_max_posts post ON post.id=attempt.post_id
             WHERE " . implode(' AND ', $where) . "
               AND attempt.status='completed'
               AND attempt.pin_status IN ('pending','processing')
             ORDER BY attempt.id
             FOR UPDATE"
        );
        $stmt->execute($params);
        $attempts = $stmt->fetchAll();
        $hasRecentProcessing = false;
        foreach ($attempts as $attempt) {
            if (
                (string) ($attempt['pin_status'] ?? '') === 'processing'
                && !empty($attempt['pin_started_at'])
                && strtotime((string) $attempt['pin_started_at']) >= time() - 120
            ) {
                $hasRecentProcessing = true;
            }
        }

        $shortReason = mb_substr($reason, 0, 4000);
        foreach ($attempts as $attempt) {
            $attemptId = (int) ($attempt['id'] ?? 0);
            if (
                (string) ($attempt['pin_status'] ?? '') === 'processing'
                && !empty($attempt['pin_started_at'])
                && strtotime((string) $attempt['pin_started_at']) >= time() - 120
            ) {
                continue;
            }
            if ((string) ($attempt['pin_status'] ?? '') === 'pending') {
                $this->refundMaxManagerPinQuota($attempt);
                $this->pdo->prepare(
                    "UPDATE posted_max_publish_attempts
                     SET pin_status='not_required',pin_started_at=NULL,
                         error_text=COALESCE(error_text,:reason)
                     WHERE id=:attempt AND pin_status='pending'"
                )->execute(['reason' => $shortReason, 'attempt' => $attemptId]);
                continue;
            }
            // The pin request may have reached MAX before the worker stopped.
            // Never manufacture quota by assuming that this unknown result failed.
            $unknownReason = mb_substr(
                '[pin-result-unknown] Результат закрепления неизвестен. ' . $reason,
                0,
                4000
            );
            $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status='failed',pin_started_at=NULL,
                     manager_pin_quota_charged=0,
                     error_text=:reason
                 WHERE id=:attempt AND pin_status='processing'"
            )->execute(['reason' => $unknownReason, 'attempt' => $attemptId]);
        }
        return !$hasRecentProcessing;
    }

    private function confirmPublicationAttempt(
        int $attemptId,
        string $messageId,
        ?string $publishedAt = null,
        ?string $actorMaxUserId = null
    ): void
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            throw new InvalidArgumentException('Пустой ID публикации МАКС.');
        }
        $publishedAt = $publishedAt !== null
            ? trim($publishedAt)
            : $this->maxStorageDateFromTimestamp(time());
        if (
            DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $publishedAt) === false
        ) {
            throw new InvalidArgumentException('Некорректное время публикации МАКС.');
        }
        $lookup = $this->pdo->prepare(
            'SELECT post_id,channel_id
             FROM posted_max_publish_attempts
             WHERE id=:attempt
             LIMIT 1'
        );
        $lookup->execute(['attempt' => $attemptId]);
        $lookupRow = $lookup->fetch();
        $postId = (int) ($lookupRow['post_id'] ?? 0);
        $channelId = (int) ($lookupRow['channel_id'] ?? 0);
        if ($postId <= 0 || $channelId <= 0) {
            throw new RuntimeException('Попытка публикации МАКС не найдена.');
        }
        $this->pdo->beginTransaction();
        try {
            $channelStmt = $this->pdo->prepare(
                'SELECT *
                 FROM posted_max_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelStmt->execute(['channel' => $channelId]);
            $channel = $channelStmt->fetch();
            if (!is_array($channel)) {
                throw new RuntimeException('Канал МАКС не найден.');
            }
            $channelLifecycleActive = !empty($channel['is_active'])
                && trim((string) ($channel['added_by'] ?? '')) !== '';
            if ($actorMaxUserId !== null && !$channelLifecycleActive) {
                throw new RuntimeException(
                    'Канал МАКС отключён или его владелец изменился.'
                );
            }
            if ($actorMaxUserId !== null) {
                $this->assertActiveMaxActorLocked($actorMaxUserId);
            }
            $postStmt = $this->pdo->prepare(
                'SELECT *
                 FROM posted_max_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute(['post' => $postId, 'channel' => $channelId]);
            $post = $postStmt->fetch();
            $stmt = $this->pdo->prepare(
                "SELECT * FROM posted_max_publish_attempts
                 WHERE id=:id AND post_id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'id' => $attemptId,
                'post' => $postId,
                'channel' => $channelId,
            ]);
            $attempt = $stmt->fetch();
            if (!is_array($attempt) || !is_array($post) || !is_array($channel)) {
                throw new RuntimeException('Попытка публикации МАКС не найдена.');
            }
            if ((string) $attempt['status'] === 'completed') {
                if (
                    !empty($attempt['max_message_id'])
                    && !hash_equals((string) $attempt['max_message_id'], $messageId)
                ) {
                    throw new RuntimeException('МАКС вернул разные ID для одной попытки публикации.');
                }
                $this->pdo->commit();
                return;
            }
            if (
                $actorMaxUserId !== null
                && !hash_equals(trim((string) $channel['added_by']), $actorMaxUserId)
            ) {
                $permission = $this->pdo->prepare(
                    'SELECT can_post,can_manage
                     FROM posted_max_permissions
                     WHERE max_user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permission->execute([
                    'user' => $actorMaxUserId,
                    'channel' => $channelId,
                ]);
                $rights = $permission->fetch();
                if (
                    !is_array($rights)
                    || (int) ($rights['can_post'] ?? 0) !== 1
                    || (
                        !hash_equals(
                            trim((string) ($post['author_max_user_id'] ?? '')),
                            $actorMaxUserId
                        )
                        && (int) ($rights['can_manage'] ?? 0) !== 1
                    )
                ) {
                    throw new RuntimeException('Права на ручное подтверждение изменились.');
                }
            }
            if (!in_array((string) $attempt['status'], ['sending', 'pending', 'needs_review'], true)) {
                throw new RuntimeException('Попытка публикации МАКС уже завершена с ошибкой.');
            }

            $known = $this->pdo->prepare(
                "SELECT post_id FROM posted_max_deliveries
                 WHERE max_message_id=:message LIMIT 1 FOR UPDATE"
            );
            $known->execute(['message' => $messageId]);
            $knownPostId = $known->fetchColumn();
            if ($knownPostId !== false && (int) $knownPostId !== (int) $attempt['post_id']) {
                throw new RuntimeException('ID публикации МАКС уже принадлежит другому посту.');
            }

            $deleteMinutes = (int) ($post['delete_after_minutes'] ?? 0);
            $commentSnapshot = $this->maxFirstCommentSnapshot($post, $channel);
            $this->pdo->prepare(
                "INSERT IGNORE INTO posted_max_deliveries(
                    post_id,max_message_id,published_at,delete_at,comment_json
                 )
                 VALUES(:post,:message,:published_at,
                   CASE WHEN :delete_minutes>0
                     THEN DATE_ADD(:delete_base,INTERVAL :delete_minutes2 MINUTE)
                     ELSE NULL END,:comment_json)"
            )->execute([
                'post' => (int) $attempt['post_id'],
                'message' => $messageId,
                'published_at' => $publishedAt,
                'delete_base' => $publishedAt,
                'delete_minutes' => $deleteMinutes,
                'delete_minutes2' => max(1, $deleteMinutes),
                'comment_json' => $commentSnapshot === null ? null : $this->encodeJson($commentSnapshot),
            ]);

            $interval = (int) ($post['repeat_interval_minutes'] ?? 0);
            if ((string) $attempt['attempt_kind'] === 'repeat') {
                $remaining = max(0, (int) $post['repeat_remaining'] - 1);
                $postUpdate = $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status=IF(status='deleted','deleted','sent'),
                         max_message_id=:message,published_at=:published_at,
                         repeat_remaining=:remaining,
                         repeat_next_at=CASE
                           WHEN :lifecycle_active=1
                                AND :remaining2>0 AND :interval_minutes>0
                              THEN DATE_ADD(:repeat_base,INTERVAL :interval_minutes2 MINUTE)
                            ELSE NULL END,
                         error_text=NULL
                     WHERE id=:id AND status IN ('sent','deleted')"
                );
                $postUpdate->execute([
                    'message' => $messageId,
                    'published_at' => $publishedAt,
                    'repeat_base' => $publishedAt,
                    'remaining' => $remaining,
                    'remaining2' => $remaining,
                    'lifecycle_active' => $channelLifecycleActive ? 1 : 0,
                    'interval_minutes' => $interval,
                    'interval_minutes2' => max(1, $interval),
                    'id' => (int) $attempt['post_id'],
                ]);
            } else {
                $remaining = (int) ($post['repeat_remaining'] ?? 0);
                $postUpdate = $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='sent',max_message_id=:message,published_at=:published_at,
                         repeat_next_at=CASE
                           WHEN :lifecycle_active=1
                                AND :remaining>0 AND :interval_minutes>0
                              THEN DATE_ADD(:repeat_base,INTERVAL :interval_minutes2 MINUTE)
                            ELSE NULL END,
                           error_text=NULL
                     WHERE id=:id AND status IN ('publishing','max_pending','failed','cancelled')"
                );
                $postUpdate->execute([
                    'message' => $messageId,
                    'published_at' => $publishedAt,
                    'repeat_base' => $publishedAt,
                    'remaining' => $remaining,
                    'lifecycle_active' => $channelLifecycleActive ? 1 : 0,
                    'interval_minutes' => $interval,
                    'interval_minutes2' => max(1, $interval),
                    'id' => (int) $attempt['post_id'],
                ]);
            }
            if ($postUpdate->rowCount() !== 1) {
                throw new RuntimeException(
                    'Состояние поста изменилось во время подтверждения публикации МАКС.'
                );
            }

            $pinRequested = !empty($post['pin_after_publish']);
            $pinStatus = $pinRequested && $channelLifecycleActive ? 'pending' : 'not_required';
            if ($pinRequested && !$channelLifecycleActive) {
                $this->refundMaxManagerPinQuota($attempt);
            }
            $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET status='completed',max_message_id=:message,confirmed_at=NOW(),
                     pin_status=:pin_status,gate_post_id=NULL,gate_chat_id=NULL,error_text=NULL
                 WHERE id=:id"
            )->execute([
                'message' => $messageId,
                'pin_status' => $pinStatus,
                'id' => $attemptId,
            ]);
            $this->pdo->commit();
            if ($commentSnapshot !== null) {
                try {
                    $delivery = $this->pdo->prepare('SELECT id FROM posted_max_deliveries WHERE post_id=:post AND max_message_id=:message LIMIT 1');
                    $delivery->execute(['post' => $postId, 'message' => $messageId]);
                    $deliveryId = (int) ($delivery->fetchColumn() ?: 0);
                    if ($deliveryId > 0) $this->finishMaxFirstComment($deliveryId);
                } catch (Throwable $commentError) {
                    error_log('GitPosted MAX first comment: ' . $commentError->getMessage());
                }
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function maxPinAuthorityFailureLocked(array $post, array $channel): ?string
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Проверка прав закрепления требует транзакцию.');
        }
        $owner = trim((string) ($channel['added_by'] ?? ''));
        $author = trim((string) ($post['author_max_user_id'] ?? ''));
        if ($owner === '' || $author === '') {
            return 'У публикации больше нет действующего автора или владельца.';
        }
        $users = array_values(array_unique([$owner, $author]));
        sort($users, SORT_STRING);
        $active = $this->pdo->prepare(
            'SELECT max_user_id,is_active FROM posted_max_users
             WHERE max_user_id IN (' . implode(',', array_fill(0, count($users), '?')) . ')
             ORDER BY max_user_id FOR UPDATE'
        );
        $active->execute($users);
        $states = $active->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($users as $user) {
            if ((int) ($states[$user] ?? 0) !== 1) {
                return 'Доступ автора или владельца к GitPosted отключён.';
            }
        }
        if (hash_equals($owner, $author)) {
            return null;
        }
        $permission = $this->pdo->prepare(
            'SELECT can_post,is_ad_manager FROM posted_max_permissions
             WHERE channel_id=:channel AND max_user_id=:author LIMIT 1 FOR UPDATE'
        );
        $permission->execute(['channel' => (int) $post['channel_id'], 'author' => $author]);
        $rights = $permission->fetch();
        if (!is_array($rights) || empty($rights['can_post'])) {
            return 'Право публикации автора отозвано.';
        }
        $category = (int) ($post['manager_category_id'] ?? 0);
        if ($category > 0) {
            if (empty($rights['is_ad_manager'])) {
                return 'Автор больше не является рекламным менеджером.';
            }
            $campaign = $this->pdo->prepare(
                'SELECT is_active FROM posted_max_manager_categories
                 WHERE id=:category AND channel_id=:channel AND max_user_id=:author
                 LIMIT 1 FOR UPDATE'
            );
            $campaign->execute([
                'category' => $category,
                'channel' => (int) $post['channel_id'],
                'author' => $author,
            ]);
            if ((int) ($campaign->fetchColumn() ?: 0) !== 1) {
                return 'Рекламная компания удалена или отключена.';
            }
        }
        return null;
    }

    private function finishPublicationPin(int $attemptId): bool
    {
        $lookup = $this->pdo->prepare(
            'SELECT channel_id,post_id
             FROM posted_max_publish_attempts
             WHERE id=:id
             LIMIT 1'
        );
        $lookup->execute(['id' => $attemptId]);
        $lookupRow = $lookup->fetch();
        $channelId = (int) ($lookupRow['channel_id'] ?? 0);
        $postId = (int) ($lookupRow['post_id'] ?? 0);
        if ($channelId <= 0 || $postId <= 0) {
            return false;
        }

        $pinLockName = 'gitposted_max_pin_' . substr(hash('sha256', (string) $channelId), 0, 28);
        $pinLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $pinLock->execute(['lock_name' => $pinLockName]);
        if ((int) ($pinLock->fetchColumn() ?: 0) !== 1) {
            return false;
        }
        $attempt = null;
        $pinRequestSucceeded = false;
        try {
            $this->pdo->beginTransaction();
            $channelLock = $this->pdo->prepare(
                'SELECT chat_id,is_active,added_by,can_pin
                 FROM posted_max_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel' => $channelId]);
            $channel = $channelLock->fetch();
            $postLock = $this->pdo->prepare(
                'SELECT status,channel_id,author_max_user_id,manager_category_id
                 FROM posted_max_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postLock->execute(['post' => $postId, 'channel' => $channelId]);
            $post = $postLock->fetch();
            $postStatus = (string) ($post['status'] ?? '');
            $attemptLock = $this->pdo->prepare(
                "SELECT id,post_id,channel_id,chat_id,max_message_id,status,
                        pin_status,pin_started_at,pin_attempts,manager_pin_quota_charged,
                        pin_started_at>DATE_SUB(NOW(),INTERVAL 2 MINUTE) AS pin_is_fresh
                 FROM posted_max_publish_attempts
                 WHERE id=:id AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE"
            );
            $attemptLock->execute(['id' => $attemptId, 'channel' => $channelId]);
            $attempt = $attemptLock->fetch();
            if (
                !is_array($attempt)
                || (int) ($attempt['post_id'] ?? 0) !== $postId
                || (string) ($attempt['status'] ?? '') !== 'completed'
                || !in_array((string) ($attempt['pin_status'] ?? ''), ['pending', 'processing'], true)
            ) {
                $this->pdo->commit();
                return false;
            }
            if ((string) ($attempt['pin_status'] ?? '') === 'processing') {
                if (!empty($attempt['pin_started_at']) && !empty($attempt['pin_is_fresh'])) {
                    // Совместимость с уже запущенным обработчиком старой версии во время deploy.
                    $this->pdo->commit();
                    return false;
                }
                $unknownError = mb_substr(
                    '[pin-result-unknown] Результат закрепления неизвестен после прерванной операции; лимит закрепления сохранён как использованный.',
                    0,
                    4000
                );
                $this->pdo->prepare(
                    "UPDATE posted_max_publish_attempts
                     SET pin_status='failed',pin_started_at=NULL,
                         manager_pin_quota_charged=0,error_text=:error
                     WHERE id=:id AND status='completed' AND pin_status='processing'"
                )->execute(['error' => $unknownError, 'id' => $attemptId]);
                $this->pdo->prepare(
                    "UPDATE posted_max_posts SET error_text=:error
                     WHERE id=:post AND status='sent'"
                )->execute(['error' => $unknownError, 'post' => $postId]);
                $this->pdo->commit();
                return true;
            }
            $authorityFailure = null;
            if (
                !is_array($channel)
                || empty($channel['is_active'])
                || trim((string) ($channel['added_by'] ?? '')) === ''
                || $postStatus !== 'sent'
            ) {
                $authorityFailure = 'Канал отключён или пост больше не опубликован.';
            } else {
                $authorityFailure = $this->maxPinAuthorityFailureLocked($post, $channel);
            }
            if ($authorityFailure !== null) {
                $this->settleMaxPinsWithoutPinLocked(
                    $channelId,
                    $postId,
                    'Закрепление отменено: ' . $authorityFailure
                );
                $this->pdo->commit();
                return false;
            }
            $claim = $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status='processing',pin_started_at=NOW(),
                     pin_attempts=pin_attempts+1
                 WHERE id=:id AND status='completed'
                   AND pin_status IN ('pending','processing')"
            );
            $claim->execute(['id' => $attemptId]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('Состояние закрепления публикации МАКС уже изменилось.');
            }
            $attempt['pin_attempts']=(int)($attempt['pin_attempts']??0)+1;
            $this->pdo->commit();
            if (empty($channel['can_pin'])) {
                throw new RuntimeException('У бота нет права закрепления.');
            }
            $this->api->pinMessage(
                (string) ($channel['chat_id'] ?? $attempt['chat_id']),
                (string) $attempt['max_message_id'],
                false
            );
            $pinRequestSucceeded = true;
            $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status='done',pin_started_at=NULL,error_text=NULL
                 WHERE id=:id AND pin_status='processing'"
            )->execute(['id' => $attemptId]);
            $this->pdo->prepare(
                "UPDATE posted_max_posts SET error_text=NULL
                 WHERE id=:id AND status='sent'"
            )->execute(['id'=>$postId]);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $error = mb_substr(
                'Публикация отправлена, закрепление не выполнено: ' . $exception->getMessage(),
                0,
                4000
            );
            $definitiveFailure = !$pinRequestSucceeded && !(
                $exception instanceof GitPostedMaxApiException
                && $exception->deliveryMayBeUnknown
            );
            $this->recordMaxPinFailure($attemptId, $error, $definitiveFailure);
            return true;
        } finally {
            $releasePinLock = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releasePinLock->execute(['lock_name' => $pinLockName]);
        }
        return true;
    }

    private function recordMaxPinFailure(
        int $attemptId,
        string $error,
        bool $refundOnFinalFailure
    ): void
    {
        $scope = $this->pdo->prepare(
            'SELECT channel_id,post_id FROM posted_max_publish_attempts
             WHERE id=:id LIMIT 1'
        );
        $scope->execute(['id' => $attemptId]);
        $scopeRow = $scope->fetch();
        if (!is_array($scopeRow)) {
            return;
        }
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare(
                'SELECT id FROM posted_max_channels WHERE id=:id LIMIT 1 FOR UPDATE'
            )->execute(['id' => (int) $scopeRow['channel_id']]);
            $this->pdo->prepare(
                'SELECT id FROM posted_max_posts
                 WHERE id=:id AND channel_id=:channel LIMIT 1 FOR UPDATE'
            )->execute([
                'id' => (int) $scopeRow['post_id'],
                'channel' => (int) $scopeRow['channel_id'],
            ]);
            $stmt=$this->pdo->prepare(
                "SELECT * FROM posted_max_publish_attempts
                 WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id'=>$attemptId]);
            $attempt=$stmt->fetch();
            if(
                !is_array($attempt)
                ||(string)($attempt['status']??'')!=='completed'
                ||(string)($attempt['pin_status']??'')!=='processing'
            ){
                $this->pdo->commit();
                return;
            }
            $attempts=max(1,(int)($attempt['pin_attempts']??1));
            $unknownMarker='[pin-result-unknown] ';
            $resultMayAlreadyExist=!$refundOnFinalFailure
                ||str_starts_with((string)($attempt['error_text']??''),$unknownMarker);
            // An unknown response may already have pinned the message. Avoid
            // repeating that external effect or crediting its quota back.
            $retry=!$resultMayAlreadyExist&&$attempts<3;
            if($resultMayAlreadyExist){
                $refundOnFinalFailure=false;
                if(!str_starts_with($error,$unknownMarker))$error=$unknownMarker.$error;
            }
            $quotaReturned=!$retry
                &&$refundOnFinalFailure
                &&!empty($attempt['manager_pin_quota_charged']);
            if($quotaReturned)$this->refundMaxManagerPinQuota($attempt);
            $storedError=$retry
                ? mb_substr($error.' Попытка '.$attempts.' из 3; закрепление будет повторено.',0,4000)
                : mb_substr(
                    $error
                        .($quotaReturned
                            ?' Лимит закрепления возвращён рекламному менеджеру.'
                            :(!$refundOnFinalFailure
                                ?' Результат запроса неизвестен; лимит не возвращён.'
                                :'')),
                    0,
                    4000
                );
            $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status=:pin_status,pin_started_at=NULL,error_text=:error
                 WHERE id=:id AND pin_status='processing'"
            )->execute([
                'pin_status'=>$retry?'pending':'failed',
                'error'=>$storedError,
                'id'=>$attemptId,
            ]);
            $postId=(int)($attempt['post_id']??0);
            if($postId>0){
                $this->pdo->prepare(
                    'UPDATE posted_max_posts SET error_text=:error WHERE id=:id'
                )->execute(['error'=>$storedError,'id'=>$postId]);
            }
            $this->pdo->commit();
        }catch(Throwable $exception){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
    }

    private function activePublicationAttemptForPost(int $postId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_publish_attempts
             WHERE gate_post_id=:post LIMIT 1"
        );
        $stmt->execute(['post' => $postId]);
        $attempt = $stmt->fetch();
        return is_array($attempt) ? $attempt : null;
    }

    private function reconcilePostPublicationAttempt(int $postId, int $attemptId): string
    {
        $lockName = $this->acquireMaxPostOperationLock($postId);
        if ($lockName === null) {
            return 'sending';
        }
        try {
            $attempt = $this->activePublicationAttemptForPost($postId);
            if ($attempt === null || (int) $attempt['id'] !== $attemptId) {
                return 'none';
            }
            if ((string) $attempt['status'] === 'needs_review') {
                return 'needs_review';
            }
            if ((string) $attempt['status'] === 'preparing') {
                return 'sending';
            }
            if ($this->maxAttemptIsFreshSending($attempt)) {
                return 'sending';
            }
            $history = $this->api->recentMessages((string) $attempt['chat_id'], 100);
            $matches = [];
            foreach ($this->maxHistoryMessages($history) as $message) {
                if ($this->maxMessageMatchesAttempt($message, $attempt)) {
                    $matches[] = $message;
                }
            }
            if (count($matches) === 1) {
                $this->markPublicationNeedsReview(
                    (int) $attempt['id'],
                    'Найдена похожая публикация без уникального медиа-ID. Проверьте канал вручную.'
                );
                return 'needs_review';
            }
            if (count($matches) > 1) {
                $this->markPublicationNeedsReview(
                    (int) $attempt['id'],
                    'Найдено несколько одинаковых публикаций МАКС; автоматический выбор небезопасен.'
                );
                return 'needs_review';
            }
            if ((strtotime((string) $attempt['deadline_at']) ?: 0) <= time()) {
                $this->markPublicationNeedsReview(
                    (int) $attempt['id'],
                    'МАКС не подтвердил публикацию за 30 минут. Повторная отправка не выполнена.'
                );
                return 'needs_review';
            }
            $this->markPublicationAttemptPending(
                (int) $attempt['id'],
                'Ожидается подтверждение публикации от МАКС.'
            );
            $this->pdo->beginTransaction();
            try{
                $attemptId=(int)$attempt['id'];
                $this->pdo->prepare(
                    'SELECT id FROM posted_max_publish_attempts
                     WHERE id=:id LIMIT 1 FOR UPDATE'
                )->execute(['id'=>$attemptId]);
                $this->completeMaxUpdateEffectLocked(
                    'manual_reconcile',
                    function()use($postId,$attemptId):array{
                        $this->pdo->prepare(
                            "UPDATE posted_max_publish_attempts
                             SET check_attempts=check_attempts+1,last_checked_at=NOW()
                             WHERE id=:id AND status='pending'"
                        )->execute(['id'=>$attemptId]);
                        return [
                            'post_id'=>$postId,
                            'attempt_id'=>$attemptId,
                            'outcome'=>'pending',
                        ];
                    }
                );
                $this->pdo->commit();
            }catch(Throwable $exception){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                throw $exception;
            }
            return 'pending';
        } finally {
            $this->releaseMaxPostOperationLock($lockName);
        }
    }

    private function maxAttemptIsFreshSending(array $attempt): bool
    {
        $status = (string) ($attempt['status'] ?? '');
        if ($status === 'preparing') {
            return true;
        }
        if ($status !== 'sending') {
            return false;
        }
        $requestedAt = strtotime((string) ($attempt['requested_at'] ?? '')) ?: 0;
        return $requestedAt > time() - 30;
    }

    private function confirmPublicationAttemptAfterReview(
        int $postId,
        int $attemptId,
        string $messageId,
        string $actorMaxUserId
    ): void {
        $messageId = trim($messageId);
        $attempt = $this->activePublicationAttemptForPost($postId);
        if (
            $messageId === ''
            ||
            !is_array($attempt)
            || (int) $attempt['id'] !== $attemptId
            || (string) $attempt['status'] !== 'needs_review'
        ) {
            throw new RuntimeException('Эта ручная проверка уже устарела.');
        }
        $message = $this->firstMessage($this->api->getMessage($messageId));
        $messageChatId = is_array($message) ? $this->maxMessageChatId($message) : '';
        if (
            !is_array($message)
            || $messageChatId === ''
            || !hash_equals((string) $attempt['chat_id'], $messageChatId)
            || !$this->maxMessageMatchesAttempt($message, $attempt)
        ) {
            throw new RuntimeException('Пересланный пост не соответствует этой попытке.');
        }
        $publishedTimestamp = $this->maxMessageTimestamp($message);
        if ($publishedTimestamp <= 0) {
            throw new RuntimeException('МАКС не вернул время создания пересланного поста.');
        }
        $this->confirmPublicationAttempt(
            $attemptId,
            $messageId,
            $this->maxStorageDateFromTimestamp($publishedTimestamp),
            $actorMaxUserId
        );
        $this->finishPublicationPin($attemptId);
    }

    private function releasePublicationAttemptAfterReview(
        int $postId,
        int $attemptId,
        string $actorMaxUserId
    ): void
    {
        $lookup = $this->pdo->prepare(
            'SELECT channel_id FROM posted_max_posts WHERE id=:post LIMIT 1'
        );
        $lookup->execute(['post' => $postId]);
        $channelId = (int) ($lookup->fetchColumn() ?: 0);
        if ($channelId <= 0 || $actorMaxUserId === '') {
            throw new RuntimeException('Публикация МАКС недоступна.');
        }
        $this->pdo->beginTransaction();
        try {
            $channelStmt = $this->pdo->prepare(
                'SELECT added_by,is_active
                 FROM posted_max_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelStmt->execute(['channel' => $channelId]);
            $channel = $channelStmt->fetch();
            if (!is_array($channel)) {
                throw new RuntimeException('Канал МАКС недоступен.');
            }
            $this->assertActiveMaxActorLocked($actorMaxUserId);
            $postStmt = $this->pdo->prepare(
                'SELECT author_max_user_id
                 FROM posted_max_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $postStmt->execute(['post' => $postId, 'channel' => $channelId]);
            $lockedPost = $postStmt->fetch();
            if (
                !is_array($channel)
                || empty($channel['is_active'])
                || trim((string) ($channel['added_by'] ?? '')) === ''
                || !is_array($lockedPost)
            ) {
                throw new RuntimeException('Канал МАКС отключён или публикация недоступна.');
            }
            if (!hash_equals(trim((string) $channel['added_by']), $actorMaxUserId)) {
                $permission = $this->pdo->prepare(
                    'SELECT can_post,can_manage
                     FROM posted_max_permissions
                     WHERE max_user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permission->execute([
                    'user' => $actorMaxUserId,
                    'channel' => $channelId,
                ]);
                $rights = $permission->fetch();
                if (
                    !is_array($rights)
                    || (int) ($rights['can_post'] ?? 0) !== 1
                    || (
                        !hash_equals(
                            trim((string) ($lockedPost['author_max_user_id'] ?? '')),
                            $actorMaxUserId
                        )
                        && (int) ($rights['can_manage'] ?? 0) !== 1
                    )
                ) {
                    throw new RuntimeException('Права на ручное решение изменились.');
                }
            }
            $stmt = $this->pdo->prepare(
                "SELECT * FROM posted_max_publish_attempts
                 WHERE id=:attempt AND gate_post_id=:post
                   AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'attempt' => $attemptId,
                'post' => $postId,
                'channel' => $channelId,
            ]);
            $attempt = $stmt->fetch();
            if (!is_array($attempt) || (string) $attempt['status'] !== 'needs_review') {
                throw new RuntimeException('Публикация ещё не перешла к ручной проверке.');
            }
            $error = 'Блокировка снята пользователем после ручной проверки канала МАКС.';
            $release = $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET status='failed',gate_post_id=NULL,gate_chat_id=NULL,error_text=:error
                 WHERE id=:id AND status='needs_review'"
            );
            $release->execute(['error' => $error, 'id' => $attemptId]);
            if ($release->rowCount() !== 1) {
                throw new RuntimeException('Ручная проверка уже завершена.');
            }
            // The user explicitly confirmed that no publication exists. Both
            // reservations therefore return exactly once under the row lock.
            $this->refundMaxManagerQuota($attempt);
            if ((string) $attempt['attempt_kind'] === 'initial') {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='failed',error_text=:error
                     WHERE id=:id AND status IN ('publishing','max_pending','failed')"
                )->execute(['error' => $error, 'id' => $postId]);
            } else {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET repeat_remaining=0,repeat_next_at=NULL,error_text=:error
                     WHERE id=:id AND status='sent'"
                )->execute(['error' => $error, 'id' => $postId]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function reconcileCreatedPublicationMessage(array $update, string $chatId): void
    {
        if ($chatId === '') {
            return;
        }
        $message = $this->extractMessage($update);
        $messageId = $this->maxMessageIdFromMessage($message);
        if ($messageId === '') {
            $messageId = $this->eventMessageId($update);
        }
        if ($messageId === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_publish_attempts
             WHERE chat_id=:chat
               AND status='pending'
             ORDER BY requested_at DESC LIMIT 20"
        );
        $stmt->execute(['chat' => $chatId]);
        $matches = [];
        foreach ($stmt->fetchAll() as $attempt) {
            if ($this->maxMessageMatchesAttempt($message, $attempt)) {
                $matches[] = $attempt;
            }
        }
        if (count($matches) !== 1) {
            return;
        }
        $attemptId = (int) $matches[0]['id'];
        $this->markPublicationNeedsReview(
            $attemptId,
            'МАКС прислал похожую публикацию без ID исходного POST. Нужна ручная проверка.'
        );
    }

    /**
     * @return array{reconciled:int,needs_review:int,errors:array<int,string>}
     */
    private function reconcilePendingPublicationAttempts(int $limit): array
    {
        $result = ['reconciled' => 0, 'needs_review' => 0, 'errors' => []];
        $attempts = $this->pdo->query(
            "SELECT * FROM posted_max_publish_attempts
             WHERE status='pending'
                OR (
                  status='sending'
                  AND requested_at<DATE_SUB(NOW(),INTERVAL 30 SECOND)
                )
                OR (
                  status='preparing'
                  AND deadline_at<=NOW()
                )
             ORDER BY COALESCE(last_checked_at,requested_at),requested_at
             LIMIT " . max(1, min(100, $limit))
        )->fetchAll();
        foreach ($attempts as $attempt) {
            $attemptId = (int) $attempt['id'];
            $lockName = $this->acquireMaxPostOperationLock((int) $attempt['post_id']);
            if ($lockName === null) {
                continue;
            }
            try {
                $freshAttempt = $this->pdo->prepare(
                    "SELECT * FROM posted_max_publish_attempts
                     WHERE id=:id
                       AND (
                         status='pending'
                         OR (
                           status='sending'
                           AND requested_at<DATE_SUB(NOW(),INTERVAL 30 SECOND)
                         )
                         OR (
                           status='preparing'
                           AND deadline_at<=NOW()
                         )
                       )
                     LIMIT 1"
                );
                $freshAttempt->execute(['id' => $attemptId]);
                $currentAttempt = $freshAttempt->fetch();
                if (!is_array($currentAttempt)) {
                    continue;
                }
                $attempt = $currentAttempt;
                if ((string) $attempt['status'] === 'preparing') {
                    $this->failPublicationAttempt(
                        $attemptId,
                        'Процесс безопасной повторной загрузки файла остановился до отправки в МАКС.'
                    );
                    $result['reconciled']++;
                    continue;
                }
                $history = $this->api->recentMessages((string) $attempt['chat_id'], 100);
                $matches = [];
                foreach ($this->maxHistoryMessages($history) as $message) {
                    if ($this->maxMessageMatchesAttempt($message, $attempt)) {
                        $matches[] = $message;
                    }
                }
                if (count($matches) === 1) {
                    $this->markPublicationNeedsReview(
                        $attemptId,
                        'Найдена похожая публикация без ID исходного POST. Нужна ручная проверка.'
                    );
                    $result['needs_review']++;
                    continue;
                }
                if (count($matches) > 1) {
                    $this->markPublicationNeedsReview(
                        $attemptId,
                        'Найдено несколько одинаковых публикаций МАКС; автоматический выбор небезопасен.'
                    );
                    $result['needs_review']++;
                    continue;
                }
                if ((strtotime((string) $attempt['deadline_at']) ?: 0) <= time()) {
                    $this->markPublicationNeedsReview(
                        $attemptId,
                        'МАКС не подтвердил публикацию за 30 минут. Повторная отправка не выполнена.'
                    );
                    $result['needs_review']++;
                    continue;
                }
                $this->markPublicationAttemptPending(
                    $attemptId,
                    'Ожидается подтверждение публикации от МАКС.'
                );
                $this->pdo->prepare(
                    "UPDATE posted_max_publish_attempts
                     SET check_attempts=check_attempts+1,last_checked_at=NOW()
                     WHERE id=:id AND status='pending'"
                )->execute(['id' => $attemptId]);
            } catch (Throwable $exception) {
                if ((strtotime((string) ($attempt['deadline_at'] ?? '')) ?: 0) <= time()) {
                    try {
                        $this->markPublicationNeedsReview(
                            $attemptId,
                            'Срок безопасной сверки МАКС истёк; повторная отправка не выполнена.'
                        );
                        $result['needs_review']++;
                    } catch (Throwable $reviewException) {
                        $result['errors'][] = 'MAX review ' . $attemptId . ': '
                            . $reviewException->getMessage();
                    }
                } else {
                    $checkError = mb_substr(
                        'Не удалось проверить историю МАКС: ' . $exception->getMessage(),
                        0,
                        4000
                    );
                    $this->markPublicationAttemptPending($attemptId, $checkError);
                    $this->pdo->prepare(
                        "UPDATE posted_max_publish_attempts
                         SET check_attempts=check_attempts+1,
                             last_checked_at=NOW(),
                             error_text=:error
                         WHERE id=:id AND status='pending'"
                    )->execute([
                        'error' => $checkError,
                        'id' => $attemptId,
                    ]);
                }
                $result['errors'][] = 'MAX reconcile ' . $attemptId . ': ' . $exception->getMessage();
            } finally {
                $this->releaseMaxPostOperationLock($lockName);
            }
        }
        return $result;
    }

    private function markPublicationNeedsReview(int $attemptId, string $error): void
    {
        $notifyPostId = null;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT post_id,attempt_kind,status
                 FROM posted_max_publish_attempts
                 WHERE id=:id LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch();
            if (
                !is_array($attempt)
                || !in_array((string) $attempt['status'], ['sending', 'pending'], true)
            ) {
                $this->pdo->commit();
                return;
            }
            $shortError = mb_substr($error, 0, 4000);
            $review = $this->pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET status='needs_review',
                     check_attempts=check_attempts+1,last_checked_at=NOW(),error_text=:error
                 WHERE id=:id AND status IN ('sending','pending')"
            );
            $review->execute(['error' => $shortError, 'id' => $attemptId]);
            if ($review->rowCount() !== 1) {
                $this->pdo->commit();
                return;
            }
            if ((string) $attempt['attempt_kind'] === 'initial') {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='max_pending',error_text=:error
                     WHERE id=:id AND status IN ('publishing','max_pending','failed')"
                )->execute(['error' => $shortError, 'id' => (int) $attempt['post_id']]);
            } else {
                $this->pdo->prepare(
                    "UPDATE posted_max_posts SET error_text=:error,repeat_next_at=NULL
                     WHERE id=:id AND status='sent'"
                )->execute(['error' => $shortError, 'id' => (int) $attempt['post_id']]);
            }
            $notifyPostId = (int) $attempt['post_id'];
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        if ($notifyPostId !== null) {
            $this->notifyPublicationNeedsReview($notifyPostId);
        }
    }

    private function notifyPublicationNeedsReview(int $postId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT u.private_chat_id,c.title
                 FROM posted_max_posts p
                 JOIN posted_max_users u ON u.max_user_id=p.author_max_user_id
                 JOIN posted_max_channels c ON c.id=p.channel_id
                 WHERE p.id=:post
                   AND u.is_active=1
                   AND u.private_chat_id IS NOT NULL
                 LIMIT 1"
            );
            $stmt->execute(['post' => $postId]);
            $recipient = $stmt->fetch();
            if (!is_array($recipient) || trim((string) $recipient['private_chat_id']) === '') {
                return;
            }
            $this->api->sendMessage(
                (string) $recipient['private_chat_id'],
                "⚠️ Публикация в канале «" . (string) $recipient['title']
                    . "» требует ручной проверки.\n\n"
                    . "GitPosted не отправляет её повторно, чтобы не создать дубль.",
                [],
                [[GitPostedMaxApi::callbackButton('Открыть пост', 'gp:view:' . $postId)]],
                true,
                null,
                true
            );
        } catch (Throwable $exception) {
            error_log(
                'GitPosted MAX publication review notification: '
                    . $exception->getMessage()
            );
        }
    }

    private function maxPublicationFingerprint(array $request): string
    {
        $signature = [
            'text' => $this->normalizeMaxPublicationText((string) ($request['text'] ?? '')),
            'forward_mid' => $this->maxForwardMessageId((array) ($request['link'] ?? [])),
            'reply_mid' => $this->maxReplyMessageId((array) ($request['link'] ?? [])),
            'media' => $this->maxMediaDescriptors((array) ($request['media'] ?? [])),
            'buttons' => $this->maxButtonRows((array) ($request['buttons'] ?? [])),
        ];
        return hash(
            'sha256',
            json_encode(
                $signature,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    private function maxMessageMatchesAttempt(array $message, array $attempt): bool
    {
        $messageId = $this->maxMessageIdFromMessage($message);
        if ($messageId === '') {
            return false;
        }
        $messageChatId = $this->maxMessageChatId($message);
        if (
            $messageChatId !== ''
            && !hash_equals((string) ($attempt['chat_id'] ?? ''), $messageChatId)
        ) {
            return false;
        }
        $baseline = $this->decodeJson((string) ($attempt['baseline_message_ids_json'] ?? ''));
        if (in_array($messageId, array_map('strval', $baseline), true)) {
            return false;
        }
        $known = $this->pdo->prepare(
            "SELECT 1 FROM posted_max_deliveries WHERE max_message_id=:message LIMIT 1"
        );
        $known->execute(['message' => $messageId]);
        if ($known->fetchColumn() !== false) {
            return false;
        }
        $timestamp = $this->maxMessageTimestamp($message);
        if ($timestamp <= 0) {
            return false;
        }
        $requestedAt = strtotime((string) ($attempt['requested_at'] ?? '')) ?: 0;
        $deadlineAt = strtotime((string) ($attempt['deadline_at'] ?? '')) ?: 0;
        if (
            $requestedAt <= 0
            || $deadlineAt <= 0
            || $timestamp < $requestedAt - 5
            || $timestamp > $deadlineAt + 300
        ) {
            return false;
        }
        $body = is_array($message['body'] ?? null) ? $message['body'] : $message;
        $sender = is_array($message['sender'] ?? null)
            ? $message['sender']
            : (is_array($body['sender'] ?? null) ? $body['sender'] : null);
        if (
            is_array($sender)
            && array_key_exists('is_bot', $sender)
            && !$sender['is_bot']
        ) {
            return false;
        }

        try {
            $request = json_decode(
                (string) ($attempt['request_json'] ?? ''),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return false;
        }
        if (!is_array($request)) {
            return false;
        }
        $expectedForward = $this->maxForwardMessageId((array) ($request['link'] ?? []));
        $actualLink = is_array($message['link'] ?? null)
            ? $message['link']
            : (is_array($body['link'] ?? null) ? $body['link'] : []);
        $actualForward = $this->maxForwardMessageId($actualLink);
        if ($expectedForward !== '') {
            return $actualForward !== '' && hash_equals($expectedForward, $actualForward);
        }
        if ($actualForward !== '') {
            return false;
        }
        if (!hash_equals(
            $this->maxReplyMessageId((array) ($request['link'] ?? [])),
            $this->maxReplyMessageId($actualLink)
        )) {
            return false;
        }
        $expectedText = $this->normalizeMaxPublicationText((string) ($request['text'] ?? ''));
        $actualText = $this->normalizeMaxPublicationText((string) ($body['text'] ?? ''));
        if (!hash_equals($expectedText, $actualText)) {
            return false;
        }
        $expectedMedia = $this->maxMediaDescriptors((array) ($request['media'] ?? []));
        $actualMedia = $this->maxMediaDescriptors(
            (array) ($body['attachments'] ?? $message['attachments'] ?? [])
        );
        if (count($expectedMedia) !== count($actualMedia)) {
            return false;
        }
        foreach ($expectedMedia as $index => $expected) {
            $actual = $actualMedia[$index] ?? null;
            if (!is_array($actual) || (string) $expected['type'] !== (string) $actual['type']) {
                return false;
            }
            $expectedIds = (array) ($expected['ids'] ?? []);
            $actualIds = (array) ($actual['ids'] ?? []);
            if ($expectedIds !== []) {
                if ($actualIds === [] || array_intersect($expectedIds, $actualIds) === []) {
                    return false;
                }
            }
        }
        $actualButtons = [];
        foreach ((array) ($body['attachments'] ?? $message['attachments'] ?? []) as $attachment) {
            if (
                is_array($attachment)
                && strtolower(trim((string) ($attachment['type'] ?? ''))) === 'inline_keyboard'
                && is_array($attachment['payload']['buttons'] ?? null)
            ) {
                $actualButtons = $attachment['payload']['buttons'];
                break;
            }
        }
        return $this->maxButtonRows((array) ($request['buttons'] ?? []))
            === $this->maxButtonRows($actualButtons);
    }

    private function maxReplyMessageId(array $link): string
    {
        if (strtolower((string) ($link['type'] ?? '')) !== 'reply') return '';
        return trim((string) ($link['mid'] ?? $link['message']['body']['mid'] ?? $link['message']['mid'] ?? ''));
    }

    private function maxMediaDescriptors(array $attachments): array
    {
        $result = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $type = strtolower(trim((string) ($attachment['type'] ?? '')));
            if (!in_array($type, ['image', 'video', 'audio', 'file'], true)) {
                continue;
            }
            $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
            $ids = [];
            foreach (['token', 'photo_id', 'video_id', 'audio_id', 'file_id', 'id', 'url'] as $key) {
                $value = $payload[$key] ?? null;
                if (is_int($value) || is_string($value)) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $ids[] = $key . ':' . $value;
                    }
                }
            }
            sort($ids, SORT_STRING);
            $result[] = ['type' => $type, 'ids' => array_values(array_unique($ids))];
        }
        return $result;
    }

    private function maxButtonRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedRow = [];
            foreach ($row as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $normalizedRow[] = [
                    'type' => strtolower(trim((string) ($button['type'] ?? ''))),
                    'text' => (string) ($button['text'] ?? ''),
                    'url' => (string) ($button['url'] ?? ''),
                    'payload' => (string) ($button['payload'] ?? ''),
                    'intent' => strtolower(trim((string) ($button['intent'] ?? 'default'))),
                ];
            }
            if ($normalizedRow !== []) {
                $result[] = $normalizedRow;
            }
        }
        return $result;
    }

    private function maxForwardMessageId(array $link): string
    {
        if (strtolower(trim((string) ($link['type'] ?? ''))) !== 'forward') {
            return '';
        }
        $linked = is_array($link['message'] ?? null) ? $link['message'] : [];
        foreach ([$link['mid'] ?? null, $linked['mid'] ?? null, $linked['message_id'] ?? null] as $value) {
            if (is_int($value) || is_string($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function normalizeMaxPublicationText(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function maxHistoryMessages(array $response): array
    {
        if (isset($response['messages']) && is_array($response['messages'])) {
            return array_values(array_filter($response['messages'], 'is_array'));
        }
        if (isset($response['message']) && is_array($response['message'])) {
            return [$response['message']];
        }
        return [];
    }

    private function maxMessageIdFromMessage(array $message): string
    {
        return GitPostedMaxApi::messageId(['message' => $message]);
    }

    private function maxMessageTimestamp(array $message): int
    {
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $value = $message['timestamp'] ?? $body['timestamp'] ?? null;
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return 0;
        }
        $timestamp = (float) $value;
        if ($timestamp > 20_000_000_000) {
            $timestamp /= 1000;
        }
        return $timestamp > 0 ? (int) floor($timestamp) : 0;
    }

    private function maxStorageDateFromTimestamp(int $timestamp): string
    {
        try {
            $zone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $zone = new DateTimeZone('Europe/Moscow');
        }
        return (new DateTimeImmutable('@' . max(1, $timestamp)))
            ->setTimezone($zone)
            ->format('Y-m-d H:i:s');
    }

    private function maxMessageChatId(array $message): string
    {
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $recipient = is_array($message['recipient'] ?? null)
            ? $message['recipient']
            : (is_array($body['recipient'] ?? null) ? $body['recipient'] : []);
        foreach ([
            $recipient['chat_id'] ?? null,
            $message['chat_id'] ?? null,
            $body['chat_id'] ?? null,
        ] as $value) {
            if (is_int($value) || is_string($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function deletePublishedPost(string $userId, int $postId): void
    {
        $post = $this->ownedPost($userId, $postId, 'view');
        if ($post === null) {
            throw new RuntimeException('Пост недоступен.');
        }
        $channelId = (int) ($post['channel_id'] ?? 0);
        $cronLockName = 'gitposted_max_cron';
        $cronLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $cronLock->execute(['lock_name' => $cronLockName]);
        if ((int) ($cronLock->fetchColumn() ?: 0) !== 1) {
            throw new RuntimeException('Фоновая операция МАКС ещё выполняется. Повторите удаление.');
        }
        $pinLockName = 'gitposted_max_pin_' . substr(hash('sha256', (string) $channelId), 0, 28);
        $pinLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $pinLock->execute(['lock_name' => $pinLockName]);
        if ((int) ($pinLock->fetchColumn() ?: 0) !== 1) {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)')
                ->execute(['lock_name' => $cronLockName]);
            throw new RuntimeException('В канале завершается закрепление публикации. Повторите удаление.');
        }
        try {
            $this->pdo->beginTransaction();
            try {
                $channelLock = $this->pdo->prepare(
                    'SELECT added_by,is_active
                     FROM posted_max_channels
                     WHERE id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $channelLock->execute(['channel' => $channelId]);
                $channel = $channelLock->fetch();
                if (
                    !is_array($channel)
                    || empty($channel['is_active'])
                    || trim((string) ($channel['added_by'] ?? '')) === ''
                ) {
                    throw new RuntimeException('Канал МАКС отключён или его владелец изменился.');
                }
                $this->assertActiveMaxActorLocked($userId);
                $lockedPost = $this->pdo->prepare(
                    "SELECT status,author_max_user_id FROM posted_max_posts
                     WHERE id=:id AND channel_id=:channel LIMIT 1 FOR UPDATE"
                );
                $lockedPost->execute(['id' => $postId, 'channel' => $channelId]);
                $lockedPostRow = $lockedPost->fetch();
                if (
                    !is_array($lockedPostRow)
                    || (string) ($lockedPostRow['status'] ?? '') !== 'sent'
                ) {
                    throw new RuntimeException('Опубликованный пост уже обрабатывается.');
                }
                if (!hash_equals(trim((string) $channel['added_by']), $userId)) {
                    $permission = $this->pdo->prepare(
                        'SELECT can_post,can_manage
                         FROM posted_max_permissions
                         WHERE max_user_id=:user AND channel_id=:channel
                         LIMIT 1
                         FOR UPDATE'
                    );
                    $permission->execute(['user' => $userId, 'channel' => $channelId]);
                    $rights = $permission->fetch();
                    if (
                        !is_array($rights)
                        || (
                            (int) ($rights['can_manage'] ?? 0) !== 1
                            && (
                                !hash_equals(
                                    trim((string) ($lockedPostRow['author_max_user_id'] ?? '')),
                                    $userId
                                )
                                || (int) ($rights['can_post'] ?? 0) !== 1
                            )
                        )
                    ) {
                        throw new RuntimeException('Права на удаление поста изменились.');
                    }
                }
                $activeAttempt = $this->pdo->prepare(
                    "SELECT 1 FROM posted_max_publish_attempts
                     WHERE gate_post_id=:post LIMIT 1"
                );
                $activeAttempt->execute(['post' => $postId]);
                if ($activeAttempt->fetchColumn() !== false) {
                    throw new RuntimeException(
                        'Публикация МАКС ещё подтверждается. Удаление временно заблокировано.'
                    );
                }
                if (!$this->settleMaxPinsWithoutPinLocked(
                    $channelId,
                    $postId,
                    'Закрепление отменено перед удалением публикации.'
                )) {
                    throw new RuntimeException(
                        'Завершается закрепление. Повторите удаление через несколько секунд.'
                    );
                }
                // Claim deletion before cron or a repeat can use the post.
                $this->pdo->prepare(
                    "UPDATE posted_max_posts
                     SET status='deleted',repeat_remaining=0,repeat_next_at=NULL
                     WHERE id=:id AND status='sent'"
                )->execute(['id' => $postId]);
                $this->pdo->prepare(
                    "UPDATE posted_max_deliveries
                     SET delete_at=NOW(),delete_attempts=0,
                         delete_started_at=NULL,error_text=NULL
                     WHERE post_id=:post AND deleted_at IS NULL"
                )->execute(['post' => $postId]);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $pinLockName]);
            $release->execute(['lock_name' => $cronLockName]);
        }
    }

    /**
     * @return array{deleted:int,errors:array<int,string>}
     */
    private function processDueMaxDeliveries(int $limit): array
    {
        $result = ['deleted' => 0, 'errors' => []];
        $deliveries = $this->pdo->query(
            "SELECT d.id,d.max_message_id,d.delete_attempts,
                    p.id AS post_id,c.id AS channel_id,c.chat_id
             FROM posted_max_deliveries d
             JOIN posted_max_posts p ON p.id=d.post_id
             JOIN posted_max_channels c ON c.id=p.channel_id
              WHERE d.deleted_at IS NULL
                AND d.delete_at IS NOT NULL
                AND d.delete_at<=NOW()
                AND c.is_active=1
                AND c.added_by IS NOT NULL AND c.added_by<>''
                AND (
                 d.delete_started_at IS NULL
                 OR d.delete_started_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)
               )
             ORDER BY c.chat_id,d.delete_at,d.id
             LIMIT " . max(1, min(20, $limit))
        )->fetchAll();
        $lastDeleteStartedAt = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (int) $delivery['id'];
            $channelId = (int) ($delivery['channel_id'] ?? 0);
            $pinLockName = 'gitposted_max_pin_' . substr(hash('sha256', (string) $channelId), 0, 28);
            $pinLock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
            $pinLock->execute(['lock_name' => $pinLockName]);
            if ((int) ($pinLock->fetchColumn() ?: 0) !== 1) {
                continue;
            }
            try {
                if (!$this->claimMaxDeliveryDeletion($delivery)) {
                    continue;
                }
                $chatId = (string) $delivery['chat_id'];
                $lastStarted = (float) ($lastDeleteStartedAt[$chatId] ?? 0.0);
                $remainingMicros = (int) ceil(550_000 - ((microtime(true) - $lastStarted) * 1_000_000));
                if ($lastStarted > 0.0 && $remainingMicros > 0) {
                    usleep($remainingMicros);
                }
                $lastDeleteStartedAt[$chatId] = microtime(true);
                try {
                    $this->api->deleteMessage((string) $delivery['max_message_id']);
                    $this->completeMaxDeliveryDeletion($deliveryId);
                    $result['deleted']++;
                } catch (Throwable $exception) {
                    // A lookup miss (including HTTP 404) can also mean lost
                    // access. Only an acknowledged delete or message_removed
                    // update proves deletion; keep failures visible and retry.
                    $nextAttempt = (int) $delivery['delete_attempts'] + 1;
                    $deleteError = mb_substr($exception->getMessage(), 0, 3500);
                    if ($nextAttempt >= 10) {
                        $this->pdo->prepare(
                            "UPDATE posted_max_deliveries
                              SET delete_attempts=delete_attempts+1,delete_at=NULL,
                                  delete_started_at=NULL,
                                  error_text=CONCAT(
                                    'Автоудаление остановлено после 10 неудачных попыток; удалите сообщение вручную. ',
                                    :error
                                  )
                              WHERE id=:id AND deleted_at IS NULL"
                        )->execute(['error' => $deleteError, 'id' => $deliveryId]);
                        $result['errors'][] = 'delete ' . $deliveryId
                            . ': автоудаление остановлено после 10 попыток';
                        continue;
                    }
                    $retryDelaySeconds = min(
                        21_600,
                        30 * (2 ** min(max(0, $nextAttempt - 1), 10))
                    );
                    $this->pdo->prepare(
                        "UPDATE posted_max_deliveries
                          SET delete_attempts=delete_attempts+1,
                              delete_at=DATE_ADD(NOW(),INTERVAL {$retryDelaySeconds} SECOND),
                              delete_started_at=NULL,error_text=:error
                          WHERE id=:id AND deleted_at IS NULL"
                    )->execute([
                        'error' => $deleteError,
                        'id' => $deliveryId,
                    ]);
                    $result['errors'][] = 'delete ' . $deliveryId . ': ' . $exception->getMessage();
                }
            } finally {
                $releasePinLock = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releasePinLock->execute(['lock_name' => $pinLockName]);
            }
        }
        return $result;
    }

    private function claimMaxDeliveryDeletion(array $delivery): bool
    {
        $deliveryId = (int) ($delivery['id'] ?? 0);
        $postId = (int) ($delivery['post_id'] ?? 0);
        $channelId = (int) ($delivery['channel_id'] ?? 0);
        if ($deliveryId <= 0 || $postId <= 0 || $channelId <= 0) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $channelLock = $this->pdo->prepare(
                'SELECT is_active,added_by
                 FROM posted_max_channels
                 WHERE id=:channel
                 LIMIT 1
                 FOR UPDATE'
            );
            $channelLock->execute(['channel' => $channelId]);
            $channel = $channelLock->fetch();
            $postLock = $this->pdo->prepare(
                "SELECT status
                 FROM posted_max_posts
                 WHERE id=:post AND channel_id=:channel
                 LIMIT 1
                 FOR UPDATE"
            );
            $postLock->execute(['post' => $postId, 'channel' => $channelId]);
            $status = (string) ($postLock->fetchColumn() ?: '');
            $deliveryLock = $this->pdo->prepare(
                'SELECT deleted_at,delete_at,delete_started_at
                 FROM posted_max_deliveries
                 WHERE id=:delivery AND post_id=:post
                 LIMIT 1
                 FOR UPDATE'
            );
            $deliveryLock->execute(['delivery' => $deliveryId, 'post' => $postId]);
            $lockedDelivery = $deliveryLock->fetch();
            if (
                !is_array($channel)
                || empty($channel['is_active'])
                || trim((string) ($channel['added_by'] ?? '')) === ''
                || !in_array($status, ['sent', 'deleted'], true)
                || !is_array($lockedDelivery)
                || !empty($lockedDelivery['deleted_at'])
                || empty($lockedDelivery['delete_at'])
                || strtotime((string) $lockedDelivery['delete_at']) > time()
                || (
                    !empty($lockedDelivery['delete_started_at'])
                    && strtotime((string) $lockedDelivery['delete_started_at']) >= time() - 300
                )
            ) {
                $this->pdo->commit();
                return false;
            }
            if (!$this->settleMaxPinsWithoutPinLocked(
                $channelId,
                $postId,
                'Закрепление отменено перед автоудалением публикации.'
            )) {
                $this->pdo->commit();
                return false;
            }
            $claim = $this->pdo->prepare(
                'UPDATE posted_max_deliveries
                 SET delete_started_at=NOW()
                 WHERE id=:delivery AND deleted_at IS NULL'
            );
            $claim->execute(['delivery' => $deliveryId]);
            $this->pdo->commit();
            return $claim->rowCount() === 1;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function completeMaxDeliveryDeletion(int $deliveryId): void
    {
        $this->pdo->prepare(
            "UPDATE posted_max_deliveries
             SET deleted_at=COALESCE(deleted_at,NOW()),
                 delete_started_at=NULL,error_text=NULL
             WHERE id=:id"
        )->execute(['id' => $deliveryId]);
    }

    private function syncPublishedPostEdit(int $postId, array $overrides = []): void
    {
        $this->assertNoActivePublicationAttempt($postId);
        $stmt = $this->pdo->prepare(
            "SELECT post.*,
                    channel.can_edit
             FROM posted_max_posts post
             JOIN posted_max_channels channel ON channel.id=post.channel_id
             WHERE post.id=:id AND post.status='sent' AND channel.is_active=1
             LIMIT 1"
        );
        $stmt->execute(['id' => $postId]);
        $post = $stmt->fetch();
        if (!is_array($post)) {
            throw new RuntimeException('Опубликованный пост не найден.');
        }
        if (empty($post['can_edit'])) {
            throw new RuntimeException('У бота нет отдельного права редактирования публикаций.');
        }
        $text = array_key_exists('text', $overrides)
            ? (string) $overrides['text']
            : (string) ($post['text'] ?? '');
        $textHtml = array_key_exists('text_html', $overrides)
            ? (string) ($overrides['text_html'] ?? '')
            : (string) ($post['text_html'] ?? '');
        $attachments = array_key_exists('attachments', $overrides) && is_array($overrides['attachments'])
            ? $overrides['attachments']
            : $this->decodeJson((string) ($post['attachments_json'] ?? ''));
        $buttons = array_key_exists('buttons', $overrides) && is_array($overrides['buttons'])
            ? $overrides['buttons']
            : $this->decodeJson((string) ($post['buttons_json'] ?? ''));
        $post = array_replace($post, ['text' => $text, 'text_html' => $textHtml, 'buttons_json' => $this->encodeJson($buttons)]);
        if (array_key_exists('text', $overrides) || array_key_exists('text_html', $overrides)
            || array_key_exists('attachments', $overrides)) {
            // A content edit intentionally creates an editable copy; validate
            // and apply its options before changing any published delivery.
            $post['forward_message_id'] = null;
        }
        $content = $this->maxEffectivePostContent($post);
        $post['buttons_json'] = $this->encodeJson((array) $content['buttons']);
        $deliveries = $this->pdo->prepare(
            "SELECT id,post_id,max_message_id FROM posted_max_deliveries
             WHERE post_id=:post AND deleted_at IS NULL ORDER BY id"
        );
        $deliveries->execute(['post' => $postId]);
        foreach ($deliveries->fetchAll() as $delivery) {
            if (count($overrides) === 1 && array_key_exists('buttons', $overrides)) {
                $this->maxEditPublishedKeyboard($post, $delivery, $this->maxPublicationButtons($post, $delivery));
                continue;
            }
            $this->api->editMessage(
                (string) $delivery['max_message_id'],
                $content['html'] !== '' ? $content['html'] : $content['text'],
                $attachments,
                $this->maxPublicationButtons($post, $delivery),
                $content['html'] !== '' ? 'html' : null
            );
        }
    }

    private function claimScheduledPost(int $postId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE posted_max_posts p
             JOIN posted_max_channels c ON c.id=p.channel_id
             SET p.status='publishing'
             WHERE p.id=:id AND p.status='scheduled' AND p.scheduled_at<=NOW()
               AND NOT EXISTS (
                 SELECT 1 FROM posted_max_publish_attempts active_attempt
                 WHERE active_attempt.gate_chat_id=c.chat_id
               )
               AND NOT EXISTS (
                 SELECT 1 FROM posted_max_publish_attempts pin_attempt
                 WHERE pin_attempt.chat_id=c.chat_id
                   AND pin_attempt.status='completed'
                   AND pin_attempt.pin_status IN ('pending','processing')
               )"
        );
        $stmt->execute(['id' => $postId]);
        return $stmt->rowCount() === 1;
    }

    private function markPostFailed(int $postId, Throwable $exception): void
    {
        $this->pdo->prepare(
            "UPDATE posted_max_posts p
             SET status='failed',error_text=:error
             WHERE p.id=:id AND p.status='publishing'
               AND NOT EXISTS (
                 SELECT 1 FROM posted_max_publish_attempts active_attempt
                 WHERE active_attempt.gate_post_id=p.id
               )"
        )->execute(['error' => mb_substr($exception->getMessage(), 0, 4000), 'id' => $postId]);
    }

    private function isMaxUserActive(string $maxUserId): bool
    {
        if ($maxUserId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT is_active
             FROM posted_max_users
             WHERE max_user_id=:max_user
             LIMIT 1"
        );
        $stmt->execute(['max_user' => $maxUserId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }

    private function upsertUser(array $user, string $privateChatId): string
    {
        $userId = $this->userId($user);
        if ($userId === '') {
            return '';
        }
        $displayName = trim(
            (string) ($user['first_name'] ?? $user['name'] ?? '')
            . ' '
            . (string) ($user['last_name'] ?? '')
        );
        $stmt = $this->pdo->prepare(
            "INSERT INTO posted_max_users
                (max_user_id,username,display_name,private_chat_id,role,is_active)
             VALUES
                (:max_user,:username,:display_name,:private_chat,'user',1)
             ON DUPLICATE KEY UPDATE
                username=COALESCE(VALUES(username),username),
                display_name=COALESCE(VALUES(display_name),display_name),
                private_chat_id=COALESCE(VALUES(private_chat_id),private_chat_id),
                role='user'"
        );
        $stmt->execute([
            'max_user' => $userId,
            'username' => trim((string) ($user['username'] ?? '')) ?: null,
            'display_name' => $displayName !== '' ? mb_substr($displayName, 0, 255) : null,
            'private_chat' => $privateChatId !== '' ? $privateChatId : null,
        ]);
        PostedAccounts::forMax($this->pdo, $userId);
        return $userId;
    }

    private function upsertMaxManager(int $channelId,string $managerId,string $ownerId): void
    {
        $this->withOwnedMaxChannelLock($ownerId,$channelId,function()use($channelId,$managerId):void{
            $existing=$this->pdo->prepare('SELECT is_ad_manager FROM posted_max_permissions WHERE channel_id=:channel AND max_user_id=:user LIMIT 1 FOR UPDATE');
            $existing->execute(['channel'=>$channelId,'user'=>$managerId]);$wasManager=$existing->fetchColumn();
            if($wasManager!==false&&(int)$wasManager!==1){$this->stopMaxUserChannelWorkLocked($managerId,$channelId,null,'Роль редактора заменена ролью рекламного менеджера.');}
            $this->pdo->prepare('INSERT INTO posted_max_permissions(max_user_id,channel_id,can_post,can_schedule,can_manage,is_ad_manager,post_quota_remaining,pin_quota_remaining) VALUES(:user,:channel,1,1,0,1,0,0) ON DUPLICATE KEY UPDATE can_post=1,can_schedule=1,can_manage=0,manager_description=IF(is_ad_manager=1,manager_description,NULL),post_quota_remaining=IF(is_ad_manager=1,post_quota_remaining,0),pin_quota_remaining=IF(is_ad_manager=1,pin_quota_remaining,0),is_ad_manager=1')->execute(['user'=>$managerId,'channel'=>$channelId]);
        });
    }

    private function saveMaxManagerField(string $ownerId,int $channelId,string $managerId,string $field,string $input): void
    {
        if($this->ownerChannel($ownerId,$channelId)===null)throw new RuntimeException('Нет доступа.');$input=trim($input);
        if($field==='description'){if(mb_strlen($input)>250)throw new RuntimeException('Описание длиннее 250 символов.');$column='manager_description';$value=$input==='-'?null:($input!==''?$input:null);}
        elseif(in_array($field,['posts','pins'],true)){$unlimited=in_array(mb_strtolower($input),['безлимит','безлим','unlimited','∞'],true);if(!$unlimited&&preg_match('/^\d{1,9}$/D',$input)!==1)throw new RuntimeException('Отправьте целое число либо слово «безлимит».');$column=$field==='posts'?'post_quota_remaining':'pin_quota_remaining';$value=$unlimited?null:(int)$input;}
        else throw new RuntimeException('Неизвестная настройка.');
        $stmt=$this->pdo->prepare("UPDATE posted_max_permissions SET {$column}=:value WHERE channel_id=:channel AND max_user_id=:user AND is_ad_manager=1");
        $stmt->bindValue(':value',$value,$value===null?PDO::PARAM_NULL:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR));$stmt->bindValue(':channel',$channelId,PDO::PARAM_INT);$stmt->bindValue(':user',$managerId,PDO::PARAM_STR);$stmt->execute();
    }

    private function maxManagerLimitMessage(string $type,string $ownerId,string $channelTitle): string
    {
        $stmt=$this->pdo->prepare('SELECT username,display_name FROM posted_max_users WHERE max_user_id=:user LIMIT 1');$stmt->execute(['user'=>$ownerId]);$owner=$stmt->fetch();$owner=is_array($owner)?$owner:[];
        $username=ltrim(trim((string)($owner['username']??'')),'@');$contact=$username!==''?'@'.$username:$this->maxUserLabel(array_merge($owner,['max_user_id'=>$ownerId]));
        $action=$type==='закреплений'?'публиковать посты с закреплением':'размещать новые посты';
        return 'Лимит '.$type.' для канала «'.trim($channelTitle)."» исчерпан.\nЧтобы продолжить ".$action.', обратитесь к владельцу канала: '.$contact.'.';
    }

    private function upsertEditor(
        int $channelId,
        string $maxUserId,
        bool $canPost,
        bool $canSchedule,
        bool $canManage,
        string $expectedOwnerMaxUserId
    ): void
    {
        if ($maxUserId === '' || $expectedOwnerMaxUserId === '' || $channelId < 1) {
            throw new InvalidArgumentException('Некорректные данные редактора канала.');
        }
        if ($this->ownerChannel($expectedOwnerMaxUserId, $channelId) === null) {
            throw new RuntimeException('Владелец канала больше не является администратором МАКС.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $channel = $this->pdo->prepare(
                'SELECT id
                 FROM posted_max_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute([
                'channel' => $channelId,
                'owner' => $expectedOwnerMaxUserId,
            ]);
            if ($channel->fetchColumn() === false) {
                throw new RuntimeException('Канал уже отключён или его владелец изменился.');
            }
            $this->assertActiveMaxActorLocked($expectedOwnerMaxUserId);
            $existing = $this->pdo->prepare(
                'SELECT is_ad_manager FROM posted_max_permissions
                 WHERE channel_id=:channel AND max_user_id=:user LIMIT 1 FOR UPDATE'
            );
            $existing->execute(['channel' => $channelId, 'user' => $maxUserId]);
            $wasManager = $existing->fetchColumn();
            if ($wasManager !== false && (int) $wasManager === 1) {
                $this->stopMaxUserChannelWorkLocked(
                    $maxUserId,
                    $channelId,
                    null,
                    'Роль рекламного менеджера заменена ролью редактора.'
                );
            }
            $this->pdo->prepare(
                'INSERT INTO posted_max_permissions
                    (max_user_id,channel_id,can_post,can_schedule,can_manage)
                 VALUES (:max_user,:channel,:can_post,:can_schedule,:can_manage)
                 ON DUPLICATE KEY UPDATE
                    can_post=VALUES(can_post),can_schedule=VALUES(can_schedule),
                    can_manage=VALUES(can_manage),is_ad_manager=0,
                    manager_description=NULL,post_quota_remaining=NULL,
                    pin_quota_remaining=NULL'
            )->execute([
                'max_user' => $maxUserId,
                'channel' => $channelId,
                'can_post' => $canPost ? 1 : 0,
                'can_schedule' => $canSchedule ? 1 : 0,
                'can_manage' => $canManage ? 1 : 0,
            ]);
            $this->pdo->prepare('UPDATE posted_max_manager_categories SET is_active=0 WHERE max_user_id=:user AND channel_id=:channel')->execute(['user'=>$maxUserId,'channel'=>$channelId]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function leaveMaxChannelAccess(
        string $maxUserId,
        int $channelId,
        string $expectedOwnerMaxUserId
    ):void {
        if($maxUserId===''||$expectedOwnerMaxUserId===''||$channelId<=0){
            throw new InvalidArgumentException('Некорректный пользователь или канал МАКС.');
        }
        if(hash_equals($maxUserId,$expectedOwnerMaxUserId)){
            throw new RuntimeException('Владелец не может покинуть собственный канал.');
        }
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $channel=$this->pdo->prepare(
                'SELECT id FROM posted_max_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1 FOR UPDATE'
            );
            $channel->execute([
                'channel'=>$channelId,
                'owner'=>$expectedOwnerMaxUserId,
            ]);
            if($channel->fetchColumn()===false){
                throw new RuntimeException('Владелец канала изменился. Откройте настройки заново.');
            }
            $permission=$this->pdo->prepare(
                'SELECT is_ad_manager FROM posted_max_permissions
                 WHERE channel_id=:channel AND max_user_id=:user
                 LIMIT 1 FOR UPDATE'
            );
            $permission->execute(['channel'=>$channelId,'user'=>$maxUserId]);
            if($permission->fetchColumn()===false){
                throw new RuntimeException('Доступ к каналу уже отсутствует.');
            }
            $this->stopMaxUserChannelWorkLocked(
                $maxUserId,
                $channelId,
                null,
                'Пользователь покинул канал.'
            );
            $this->pdo->prepare(
                'UPDATE posted_max_manager_categories SET is_active=0
                 WHERE channel_id=:channel AND max_user_id=:user'
            )->execute(['channel'=>$channelId,'user'=>$maxUserId]);
            $removed=$this->pdo->prepare(
                'DELETE FROM posted_max_permissions
                 WHERE channel_id=:channel AND max_user_id=:user'
            );
            $removed->execute(['channel'=>$channelId,'user'=>$maxUserId]);
            if($removed->rowCount()!==1){
                throw new RuntimeException('Доступ к каналу уже отсутствует.');
            }
            $this->clearMaxUserChannelWorkflow($maxUserId,$channelId);
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $exception){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
    }

    private function toggleEditorPermission(
        int $channelId,
        string $maxUserId,
        string $permission,
        string $expectedOwnerMaxUserId,
        bool $desiredState
    ): bool
    {
        $column = [
            'p' => 'can_post',
            's' => 'can_schedule',
            'm' => 'can_manage',
        ][$permission] ?? null;
        if ($column === null || $maxUserId === '') {
            throw new RuntimeException('Неизвестное право редактора.');
        }
        return (bool) $this->withOwnedMaxChannelLock(
            $expectedOwnerMaxUserId,
            $channelId,
            function () use ($column, $channelId, $maxUserId, $desiredState): bool {
                $current = $this->pdo->prepare(
                    'SELECT can_post,can_schedule,can_manage
                     FROM posted_max_permissions
                     WHERE channel_id=:channel AND max_user_id=:editor
                       AND is_ad_manager=0
                     LIMIT 1 FOR UPDATE'
                );
                $current->execute(['channel' => $channelId, 'editor' => $maxUserId]);
                $rights = $current->fetch();
                if(!is_array($rights))return false;
                $wasEnabled=!empty($rights[$column]);
                if($wasEnabled!==$desiredState){
                    $this->pdo->prepare(
                        "UPDATE posted_max_permissions SET {$column}=:desired
                         WHERE channel_id=:channel AND max_user_id=:editor
                           AND is_ad_manager=0"
                    )->execute([
                        'desired'=>$desiredState?1:0,
                        'channel'=>$channelId,
                        'editor'=>$maxUserId,
                    ]);
                    $rights[$column]=$desiredState?1:0;
                }
                if ($wasEnabled && !$desiredState && $column === 'can_schedule') {
                    $this->pdo->prepare(
                        "UPDATE posted_max_posts
                         SET status='cancelled',scheduled_at=NULL,
                             repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL,
                             error_text='Право планирования автора отозвано.'
                         WHERE channel_id=:channel AND author_max_user_id=:author
                           AND status='scheduled'"
                    )->execute(['channel' => $channelId, 'author' => $maxUserId]);
                }
                if ($wasEnabled && !$desiredState && $column === 'can_post') {
                    $this->pdo->prepare(
                        'UPDATE posted_max_permissions
                         SET can_schedule=0
                         WHERE channel_id=:channel AND max_user_id=:author
                           AND is_ad_manager=0'
                    )->execute(['channel' => $channelId, 'author' => $maxUserId]);
                    $rights['can_schedule']=0;
                    $this->stopMaxUserChannelWorkLocked(
                        $maxUserId,
                        $channelId,
                        null,
                        'Право публикации автора отозвано.'
                    );
                }
                $delete = $this->pdo->prepare(
                    'DELETE FROM posted_max_permissions
                     WHERE channel_id=:channel AND max_user_id=:editor
                       AND is_ad_manager=0
                       AND can_post=0 AND can_schedule=0 AND can_manage=0'
                );
                $delete->execute(['channel' => $channelId, 'editor' => $maxUserId]);
                if ($delete->rowCount() === 1) {
                    $this->stopMaxUserChannelWorkLocked(
                        $maxUserId,
                        $channelId,
                        null,
                        'У редактора не осталось прав на канал.'
                    );
                    $this->clearMaxUserChannelWorkflow($maxUserId, $channelId);
                    return false;
                }
                return true;
            }
        );
    }

    private function clearMaxUserChannelWorkflow(string $maxUserId, int $channelId): void
    {
        if (!$this->pdo->inTransaction() || $maxUserId === '' || $channelId < 1) {
            throw new RuntimeException('Не удалось очистить состояние редактора МАКС.');
        }
        $account = $this->pdo->prepare(
            'SELECT state_data
             FROM posted_max_users
             WHERE max_user_id=:max_user
             LIMIT 1
             FOR UPDATE'
        );
        $account->execute(['max_user' => $maxUserId]);
        $stateData = $account->fetchColumn();
        if ($stateData === false || $stateData === null || $stateData === '') {
            return;
        }
        $data = $this->decodeJson((string) $stateData);
        $matchesChannel = (int) ($data['channel_id'] ?? 0) === $channelId;
        $postId = (int) ($data['post_id'] ?? 0);
        if (!$matchesChannel && $postId > 0) {
            $post = $this->pdo->prepare(
                'SELECT 1 FROM posted_max_posts
                 WHERE id=:post AND channel_id=:channel LIMIT 1'
            );
            $post->execute(['post' => $postId, 'channel' => $channelId]);
            $matchesChannel = $post->fetchColumn() !== false;
        }
        if ($matchesChannel) {
            $this->pdo->prepare(
                'UPDATE posted_max_users SET state=NULL,state_data=NULL
                 WHERE max_user_id=:max_user'
            )->execute(['max_user' => $maxUserId]);
        }
    }

    private function channelManagerStatus(
        string $chatId,
        string $maxUserId,
        bool $refresh = false
    ): ?bool {
        $cacheKey = $chatId . '|' . $maxUserId;
        if (!$refresh && array_key_exists($cacheKey, $this->ownerManagerStatusCache)) {
            return $this->ownerManagerStatusCache[$cacheKey];
        }
        try {
            $response = $this->api->getMembers($chatId, [$maxUserId]);
            if (!array_key_exists('members', $response) || !is_array($response['members'])) {
                $this->ownerManagerStatusCache[$cacheKey] = null;
                return null;
            }
            foreach ($response['members'] as $member) {
                if (!is_array($member) || $this->userId($member) !== $maxUserId) {
                    continue;
                }
                return $this->ownerManagerStatusCache[$cacheKey]
                    = (!empty($member['is_owner']) || !empty($member['is_admin']));
            }
            return $this->ownerManagerStatusCache[$cacheKey] = false;
        } catch (Throwable $exception) {
            error_log('GitPosted MAX owner verification: ' . $exception->getMessage());
            $this->ownerManagerStatusCache[$cacheKey] = null;
            return null;
        }
    }

    private function verifyChannelManager(string $chatId, string $maxUserId): bool
    {
        return $this->channelManagerStatus($chatId, $maxUserId) === true;
    }

    private function stopMaxUserChannelWorkLocked(
        string $maxUserId,
        int $channelId,
        ?int $categoryId = null,
        string $reason = 'Доступ автора к каналу отозван.'
    ): void {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Не удалось остановить публикации пользователя МАКС.');
        }
        $categorySql = $categoryId === null
            ? ''
            : ' AND manager_category_id=:category';
        $params = [
            'author' => $maxUserId,
            'channel' => $channelId,
            'reason' => mb_substr($reason, 0, 4000),
        ];
        if ($categoryId !== null) {
            $params['category'] = $categoryId;
        }
        $attemptCategorySql = $categoryId === null
            ? ''
            : ' AND post.manager_category_id=:category';
        $attemptParams = ['author' => $maxUserId, 'channel' => $channelId];
        if ($categoryId !== null) {
            $attemptParams['category'] = $categoryId;
        }
        $busy = $this->pdo->prepare(
            "SELECT 1
             FROM posted_max_publish_attempts attempt
             JOIN posted_max_posts post ON post.id=attempt.post_id
             WHERE post.author_max_user_id=:author AND post.channel_id=:channel
               {$attemptCategorySql}
               AND (
                 attempt.gate_post_id IS NOT NULL
                 OR attempt.gate_chat_id IS NOT NULL
                 OR (
                   attempt.status='completed' AND attempt.pin_status='processing'
                   AND attempt.pin_started_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                 )
               )
             LIMIT 1 FOR UPDATE"
        );
        $busy->execute($attemptParams);
        if ($busy->fetchColumn() !== false) {
            throw new RuntimeException(
                'У пользователя завершается публикация или закрепление. Повторите изменение роли позже.'
            );
        }
        if (!$this->settleMaxPinsWithoutPinLocked(
            $channelId,
            null,
            $reason,
            $maxUserId,
            $categoryId
        )) {
            throw new RuntimeException(
                'У пользователя завершается закрепление. Повторите изменение роли позже.'
            );
        }
        $this->pdo->prepare(
            "UPDATE posted_max_posts
             SET status='cancelled',scheduled_at=NULL,
                 repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL,
                 error_text=:reason
             WHERE author_max_user_id=:author AND channel_id=:channel
               AND status IN ('draft','scheduled','failed'){$categorySql}"
        )->execute($params);
        $this->pdo->prepare(
            "UPDATE posted_max_posts
             SET repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL
             WHERE author_max_user_id=:author AND channel_id=:channel
               AND status IN ('publishing','max_pending','sent','deleted'){$categorySql}"
        )->execute(array_diff_key($params, ['reason' => true]));
    }

    private function revokeMaxChannelAccessLocked(
        int $channelId,
        string $ownerMaxUserId,
        string $reason
    ): void {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Не удалось отозвать доступ к МАКС-каналу.');
        }
        $this->pdo->prepare(
            'UPDATE posted_max_users max_user
             LEFT JOIN posted_max_permissions permission
               ON permission.max_user_id=max_user.max_user_id
              AND permission.channel_id=:channel
             LEFT JOIN posted_max_posts state_post
               ON state_post.id=CAST(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(max_user.state_data,\'$.post_id\')),\'\')
                    AS UNSIGNED
                  )
              AND state_post.channel_id=:channel2
             SET max_user.state=NULL,max_user.state_data=NULL
             WHERE max_user.max_user_id=:owner
                OR permission.channel_id IS NOT NULL
                OR state_post.id IS NOT NULL'
        )->execute([
            'channel' => $channelId,
            'channel2' => $channelId,
            'owner' => $ownerMaxUserId,
        ]);
        // Do not resolve an in-flight publication here: its MAX delivery can
        // still be unknown. Pins which were never dispatched are settled while
        // the old quota rows still exist; recent/unknown pin calls are not
        // refunded speculatively.
        $this->settleMaxPinsWithoutPinLocked($channelId, null, $reason);
        $this->pdo->prepare(
            "UPDATE posted_max_posts
             SET status='cancelled',scheduled_at=NULL,
                 repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL,
                 error_text=:reason
             WHERE channel_id=:channel AND status IN ('draft','scheduled','failed')"
        )->execute(['reason' => mb_substr($reason, 0, 4000), 'channel' => $channelId]);
        $this->pdo->prepare(
            "UPDATE posted_max_posts
             SET pin_after_publish=0,
                 repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL
             WHERE channel_id=:channel
               AND status IN ('publishing','max_pending','sent','deleted')"
        )->execute(['channel' => $channelId]);
        $this->pdo->prepare(
            'UPDATE posted_max_manager_categories SET is_active=0 WHERE channel_id=:channel'
        )->execute(['channel' => $channelId]);
        // Closing the old grant also closes its accounting generation. Later
        // reconciliation must not credit a newly-created permission/category.
        $this->pdo->prepare(
            'UPDATE posted_max_publish_attempts
             SET manager_post_quota_charged=0,manager_pin_quota_charged=0
             WHERE channel_id=:channel
               AND (manager_post_quota_charged=1 OR manager_pin_quota_charged=1)'
        )->execute(['channel' => $channelId]);
        $this->pdo->prepare(
            'DELETE FROM posted_max_permissions WHERE channel_id=:channel'
        )->execute(['channel' => $channelId]);
    }

    private function detachStaleMaxOwner(
        int $channelId,
        string $ownerMaxUserId,
        string $reason
    ): bool {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $channel = $this->pdo->prepare(
                'SELECT added_by FROM posted_max_channels
                 WHERE id=:channel LIMIT 1 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId]);
            $storedOwner = trim((string) ($channel->fetchColumn() ?: ''));
            if ($storedOwner === '' || !hash_equals($storedOwner, $ownerMaxUserId)) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return false;
            }
            $this->revokeMaxChannelAccessLocked($channelId, $ownerMaxUserId, $reason);
            $this->pdo->prepare(
                'UPDATE posted_max_channels
                 SET is_active=0,added_by=NULL,owner_checked_at=NULL
                 WHERE id=:channel AND added_by=:owner'
            )->execute(['channel' => $channelId, 'owner' => $ownerMaxUserId]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function revokeMaxUserChannelAccessByChat(string $chatId, string $maxUserId): void
    {
        $chatId=trim($chatId);
        $maxUserId=trim($maxUserId);
        if ($chatId==='' || $maxUserId==='') {
            return;
        }
        $this->withMaxChannelSyncLock(
            $chatId,
            function () use ($chatId,$maxUserId): void {
                $this->revokeMaxUserChannelAccessByChatLocked($chatId,$maxUserId);
            }
        );
    }

    private function revokeMaxUserChannelAccessByChatLocked(string $chatId, string $maxUserId): void
    {
        $lookup = $this->pdo->prepare(
            'SELECT id,added_by FROM posted_max_channels WHERE chat_id=:chat LIMIT 1'
        );
        $lookup->execute(['chat' => $chatId]);
        $channel = $lookup->fetch();
        if (!is_array($channel)) {
            return;
        }
        $channelId = (int) $channel['id'];
        $owner = trim((string) ($channel['added_by'] ?? ''));
        if ($owner !== '' && hash_equals($owner, $maxUserId)) {
            $this->ownerManagerStatusCache[$chatId . '|' . $maxUserId] = false;
            if ($this->detachStaleMaxOwner(
                $channelId,
                $maxUserId,
                'Пользователь удалён из МАКС-канала.'
            )) {
                return;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $locked = $this->pdo->prepare(
                'SELECT id FROM posted_max_channels WHERE id=:channel LIMIT 1 FOR UPDATE'
            );
            $locked->execute(['channel' => $channelId]);
            if ($locked->fetchColumn() === false) {
                $this->pdo->commit();
                return;
            }
            $reason = 'Пользователь удалён из МАКС-канала.';
            // Membership removal is an authoritative ACL event. Revoke access
            // immediately, but preserve every ambiguous send/gate so a later
            // MAX message ID can still complete the publication journal.
            $this->settleMaxPinsWithoutPinLocked(
                $channelId,
                null,
                $reason,
                $maxUserId
            );
            $this->pdo->prepare(
                "UPDATE posted_max_posts
                 SET status='cancelled',scheduled_at=NULL,
                     repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL,
                     error_text=:reason
                 WHERE author_max_user_id=:author AND channel_id=:channel
                   AND status IN ('draft','scheduled','failed')"
            )->execute([
                'reason' => $reason,
                'author' => $maxUserId,
                'channel' => $channelId,
            ]);
            $this->pdo->prepare(
                "UPDATE posted_max_posts
                 SET pin_after_publish=0,
                     repeat_interval_minutes=NULL,repeat_remaining=0,repeat_next_at=NULL
                 WHERE author_max_user_id=:author AND channel_id=:channel
                   AND status IN ('publishing','max_pending','sent','deleted')"
            )->execute(['author' => $maxUserId, 'channel' => $channelId]);
            $this->pdo->prepare(
                'UPDATE posted_max_manager_categories
                 SET is_active=0 WHERE channel_id=:channel AND max_user_id=:user'
            )->execute(['channel' => $channelId, 'user' => $maxUserId]);
            $this->pdo->prepare(
                'UPDATE posted_max_publish_attempts attempt
                 JOIN posted_max_posts post ON post.id=attempt.post_id
                 SET attempt.manager_post_quota_charged=0,
                     attempt.manager_pin_quota_charged=0
                 WHERE post.channel_id=:channel AND post.author_max_user_id=:user
                   AND (attempt.manager_post_quota_charged=1
                        OR attempt.manager_pin_quota_charged=1)'
            )->execute(['channel' => $channelId, 'user' => $maxUserId]);
            $this->pdo->prepare(
                'DELETE FROM posted_max_permissions
                 WHERE channel_id=:channel AND max_user_id=:user'
            )->execute(['channel' => $channelId, 'user' => $maxUserId]);
            $this->clearMaxUserChannelWorkflow($maxUserId, $channelId);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function ensureMaxChannelOwnerCurrent(int $channelId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT chat_id,added_by,is_active
             FROM posted_max_channels WHERE id=:channel LIMIT 1'
        );
        $stmt->execute(['channel' => $channelId]);
        $channel = $stmt->fetch();
        $owner = trim((string) ($channel['added_by'] ?? ''));
        if (!is_array($channel) || empty($channel['is_active']) || $owner === '') {
            return false;
        }
        $status = $this->channelManagerStatus((string) $channel['chat_id'], $owner);
        if ($status === true) {
            $this->pdo->prepare(
                'UPDATE posted_max_channels SET owner_checked_at=NOW()
                 WHERE id=:channel AND added_by=:owner
                   AND (owner_checked_at IS NULL OR owner_checked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))'
            )->execute(['channel' => $channelId, 'owner' => $owner]);
            return true;
        }
        if ($status === false) {
            $this->detachStaleMaxOwner(
                $channelId,
                $owner,
                'Владелец больше не является администратором МАКС-канала.'
            );
        }
        return false;
    }

    /** @return array{checked:int,revoked:int} */
    private function revalidateSavedMaxOwners(int $limit): array
    {
        $stmt = $this->pdo->query(
            'SELECT id,chat_id,added_by
             FROM posted_max_channels
             WHERE is_active=1 AND added_by IS NOT NULL AND added_by<>\'\'
               AND (owner_checked_at IS NULL OR owner_checked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))
             ORDER BY COALESCE(owner_checked_at,\'1000-01-01\'),id
             LIMIT ' . max(1, min(20, $limit))
        );
        $result = ['checked' => 0, 'revoked' => 0];
        foreach ($stmt->fetchAll() as $channel) {
            $owner = trim((string) ($channel['added_by'] ?? ''));
            if ($owner === '') {
                continue;
            }
            $result['checked']++;
            $status = $this->channelManagerStatus(
                (string) $channel['chat_id'],
                $owner,
                true
            );
            if ($status === false) {
                if ($this->detachStaleMaxOwner(
                    (int) $channel['id'],
                    $owner,
                    'Владелец больше не является администратором МАКС-канала.'
                )) {
                    $result['revoked']++;
                }
                continue;
            }
            $this->pdo->prepare(
                'UPDATE posted_max_channels SET owner_checked_at=NOW()
                 WHERE id=:channel AND added_by=:owner'
            )->execute(['channel' => (int) $channel['id'], 'owner' => $owner]);
        }
        return $result;
    }

    private function user(string $maxUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_users WHERE max_user_id=:max_user LIMIT 1"
        );
        $stmt->execute(['max_user' => $maxUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private function assertActiveMaxActorLocked(string $maxUserId): void
    {
        $this->assertActiveMaxUsersLocked([$maxUserId]);
    }

    /** @param list<string> $maxUserIds */
    private function assertActiveMaxUsersLocked(array $maxUserIds): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Не удалось проверить пользователей МАКС.');
        }
        $maxUserIds = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): string => trim((string) $id), $maxUserIds),
            static fn(string $id): bool => $id !== ''
        )));
        sort($maxUserIds, SORT_STRING);
        if ($maxUserIds === []) {
            throw new RuntimeException('Не удалось определить пользователей МАКС.');
        }
        $placeholders = [];
        $params = [];
        foreach ($maxUserIds as $index => $maxUserId) {
            $key = 'max_user_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $maxUserId;
        }
        $users = $this->pdo->prepare(
            'SELECT max_user_id,is_active
             FROM posted_max_users
             WHERE max_user_id IN (' . implode(',', $placeholders) . ')
             ORDER BY max_user_id
             FOR UPDATE'
        );
        $users->execute($params);
        $rows = $users->fetchAll();
        if (count($rows) !== count($maxUserIds)) {
            throw new RuntimeException('Пользователь МАКС не найден.');
        }
        foreach ($rows as $row) {
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Пользователь МАКС отключён администратором.');
            }
        }
    }

    private function setState(string $maxUserId, ?string $state, array $data): void
    {
        $this->pdo->prepare(
            "UPDATE posted_max_users
             SET state=:state,state_data=:data WHERE max_user_id=:max_user"
        )->execute([
            'state' => $state,
            'data' => $state !== null ? $this->encodeJson($data) : null,
            'max_user' => $maxUserId,
        ]);
    }

    private function savedMaxUserEffect(string $effectType, string $userId, ?bool &$otherEffect = null): ?array
    {
        $otherEffect = false;
        if ($this->processingUpdateKey === null || $this->processingUpdateKey === '') return null;
        $stmt = $this->pdo->prepare('SELECT effect_type,result_json FROM posted_max_update_effects WHERE update_key=:key LIMIT 1');
        $stmt->execute(['key' => $this->processingUpdateKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) return null;
        if (($row['effect_type'] ?? '') !== $effectType) { $otherEffect = true; return null; }
        $result = $this->decodeJson((string) ($row['result_json'] ?? ''));
        if (($result['user_id'] ?? '') !== $userId || (int) ($result['post_id'] ?? 0) <= 0) {
            throw new RuntimeException('Сохранённое действие МАКС относится к другому пользователю или посту.');
        }
        return $result;
    }

    private function currentMaxUpdateEffect(string $effectType): ?array
    {
        $updateKey=$this->processingUpdateKey;
        if($updateKey===null||$updateKey==='')return null;
        $stmt=$this->pdo->prepare(
            'SELECT effect_type,result_json FROM posted_max_update_effects
             WHERE update_key=:update_key LIMIT 1'
        );
        $stmt->execute(['update_key'=>$updateKey]);
        $effect=$stmt->fetch();
        if(!is_array($effect))return null;
        if(!hash_equals((string)$effect['effect_type'],$effectType)){
            throw new RuntimeException('Сохранённое действие MAX не совпадает с повторным событием.');
        }
        return $this->decodeJson((string)$effect['result_json']);
    }

    private function completeMaxUpdateEffectLocked(
        string $effectType,
        callable $operation
    ): array {
        if(!$this->pdo->inTransaction()){
            throw new RuntimeException('Фиксация действия MAX требует активной транзакции.');
        }
        $updateKey=$this->processingUpdateKey;
        if($updateKey===null||$updateKey===''){
            $result=$operation();
            if(!is_array($result))throw new RuntimeException('Действие MAX вернуло некорректный результат.');
            return $result;
        }
        $existing=$this->pdo->prepare(
            'SELECT effect_type,result_json FROM posted_max_update_effects
             WHERE update_key=:update_key LIMIT 1 FOR UPDATE'
        );
        $existing->execute(['update_key'=>$updateKey]);
        $effect=$existing->fetch();
        if(is_array($effect)){
            if(!hash_equals((string)$effect['effect_type'],$effectType)){
                throw new RuntimeException('Сохранённое действие MAX не совпадает с повторным событием.');
            }
            return $this->decodeJson((string)$effect['result_json']);
        }
        $result=$operation();
        if(!is_array($result))throw new RuntimeException('Действие MAX вернуло некорректный результат.');
        $this->pdo->prepare(
            'INSERT INTO posted_max_update_effects(update_key,effect_type,result_json)
             VALUES(:update_key,:effect_type,:result_json)'
        )->execute([
            'update_key'=>$updateKey,
            'effect_type'=>$effectType,
            'result_json'=>$this->encodeJson($result),
        ]);
        return $result;
    }

    private function completeMaxUpdateEffect(string $effectType,callable $operation):array
    {
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $result=$this->completeMaxUpdateEffectLocked($effectType,$operation);
            if($ownsTransaction)$this->pdo->commit();
            return $result;
        }catch(Throwable $exception){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $exception;
        }
    }

    private function withMaxChannelSyncLock(string $chatId, callable $operation): mixed
    {
        $lockName='gitposted_max_channel_'.substr(hash('sha256',$chatId),0,32);
        $lock=$this->pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $lock->execute(['lock_name'=>$lockName]);
        if ((int)($lock->fetchColumn()?:0)!==1) {
            throw new RuntimeException('Канал МАКС занят синхронизацией. Повторите действие.');
        }
        try {
            return $operation();
        } finally {
            $release=$this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name'=>$lockName]);
        }
    }

    private function channelByChatId(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_channels WHERE chat_id=:chat LIMIT 1"
        );
        $stmt->execute(['chat' => $chatId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function ownerChannel(string $maxUserId, int $channelId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_channels
             WHERE id=:channel AND added_by=:owner AND is_active=1
             LIMIT 1"
        );
        $stmt->execute(['channel' => $channelId, 'owner' => $maxUserId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !$this->ensureMaxChannelOwnerCurrent($channelId)) {
            return null;
        }
        $stmt->execute(['channel' => $channelId, 'owner' => $maxUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function withOwnedMaxChannelLock(
        string $ownerMaxUserId,
        int $channelId,
        callable $operation
    ): mixed {
        if ($ownerMaxUserId === '' || $channelId <= 0) {
            throw new InvalidArgumentException('Некорректный владелец или канал МАКС.');
        }
        if ($this->ownerChannel($ownerMaxUserId, $channelId) === null) {
            throw new RuntimeException(
                'Владелец больше не является администратором МАКС-канала либо проверка временно недоступна.'
            );
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $channel = $this->pdo->prepare(
                'SELECT *
                 FROM posted_max_channels
                 WHERE id=:channel AND is_active=1 AND added_by=:owner
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId, 'owner' => $ownerMaxUserId]);
            $lockedChannel = $channel->fetch();
            if (!is_array($lockedChannel)) {
                throw new RuntimeException('Канал МАКС отключён или его владелец изменился.');
            }
            $this->assertActiveMaxActorLocked($ownerMaxUserId);
            $result = $operation($lockedChannel);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function withMaxChannelCapabilityLock(
        string $maxUserId,
        int $channelId,
        string $capability,
        callable $operation
    ): mixed {
        if ($maxUserId === '' || $channelId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь или канал МАКС.');
        }
        if (!$this->ensureMaxChannelOwnerCurrent($channelId)) {
            throw new RuntimeException(
                'Владелец канала больше не является администратором либо проверка МАКС временно недоступна.'
            );
        }
        $column = match ($capability) {
            'post' => 'can_post',
            'schedule' => 'can_schedule',
            'manage' => 'can_manage',
            default => throw new InvalidArgumentException('Некорректное право канала МАКС.'),
        };
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $channel = $this->pdo->prepare(
                'SELECT *
                 FROM posted_max_channels
                 WHERE id=:channel
                   AND is_active=1
                   AND added_by IS NOT NULL
                   AND added_by<>\'\'
                 LIMIT 1
                 FOR UPDATE'
            );
            $channel->execute(['channel' => $channelId]);
            $lockedChannel = $channel->fetch();
            if (!is_array($lockedChannel)) {
                throw new RuntimeException('Канал МАКС отключён или его владелец изменился.');
            }
            $this->assertActiveMaxActorLocked($maxUserId);
            $lockedPermission = [
                'can_post' => 1,
                'can_schedule' => 1,
                'can_manage' => 1,
                'is_ad_manager' => 0,
            ];
            if (!hash_equals(trim((string) $lockedChannel['added_by']), $maxUserId)) {
                $permission = $this->pdo->prepare(
                    'SELECT can_post,can_schedule,can_manage,is_ad_manager
                     FROM posted_max_permissions
                     WHERE max_user_id=:user AND channel_id=:channel
                     LIMIT 1
                     FOR UPDATE'
                );
                $permission->execute(['user' => $maxUserId, 'channel' => $channelId]);
                $lockedPermission = $permission->fetch();
                if (
                    !is_array($lockedPermission)
                    || (int) ($lockedPermission[$column] ?? 0) !== 1
                ) {
                    throw new RuntimeException('Права пользователя на канал МАКС изменились.');
                }
            }
            if ($capability === 'post' && empty($lockedChannel['can_post'])) {
                throw new RuntimeException('У бота больше нет права публикации в МАКС-канале.');
            }
            $result = $operation($lockedChannel, $lockedPermission);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function channelCapability(
        string $maxUserId,
        int $channelId,
        string $capability = 'view'
    ): bool {
        if (!$this->ensureMaxChannelOwnerCurrent($channelId)) {
            return false;
        }
        $columnCondition = match ($capability) {
            'post' => 'p.can_post=1',
            'schedule' => 'p.can_schedule=1',
            'manage' => 'p.can_manage=1',
            default => '(p.can_post=1 OR p.can_schedule=1 OR p.can_manage=1)',
        };
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM posted_max_channels c
             LEFT JOIN posted_max_permissions p
               ON p.channel_id=c.id AND p.max_user_id=:max_user
             WHERE c.id=:channel AND c.is_active=1
               AND (c.added_by=:max_user2 OR {$columnCondition})
             LIMIT 1"
        );
        $stmt->execute([
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
            'channel' => $channelId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    private function ownedChannel(string $maxUserId, int $channelId): ?array
    {
        if (!$this->ensureMaxChannelOwnerCurrent($channelId)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT c.*
             FROM posted_max_channels c
             LEFT JOIN posted_max_permissions p
               ON p.channel_id=c.id AND p.max_user_id=:max_user
             WHERE c.id=:channel AND c.is_active=1
               AND (c.added_by=:max_user2 OR p.can_post=1)
             LIMIT 1"
        );
        $stmt->execute([
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
            'channel' => $channelId,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function channelsForUser(string $maxUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT c.*
             FROM posted_max_channels c
             LEFT JOIN posted_max_permissions p
               ON p.channel_id=c.id AND p.max_user_id=:max_user
             WHERE c.is_active=1 AND c.is_archived=0
               AND (
                 c.added_by=:max_user2
                 OR p.can_post=1
                 OR p.can_schedule=1
                 OR p.can_manage=1
               )
             ORDER BY c.title,c.id"
        );
        $stmt->execute([
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
        ]);
        return array_values(array_filter(
            $stmt->fetchAll(),
            fn (array $channel): bool => $this->ensureMaxChannelOwnerCurrent((int) $channel['id'])
        ));
    }

    private function publishableChannelsForUser(string $maxUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT c.*
             FROM posted_max_channels c
             LEFT JOIN posted_max_permissions p
               ON p.channel_id=c.id AND p.max_user_id=:max_user
             WHERE c.is_active=1 AND c.is_archived=0 AND c.can_post=1
               AND (c.added_by=:max_user2 OR p.can_post=1)
             ORDER BY c.title,c.id"
        );
        $stmt->execute([
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
        ]);
        return array_values(array_filter(
            $stmt->fetchAll(),
            fn (array $channel): bool => $this->ensureMaxChannelOwnerCurrent((int) $channel['id'])
        ));
    }

    private function ownedPost(
        string $maxUserId,
        int $postId,
        string $capability = 'view'
    ): ?array
    {
        $channelLookup = $this->pdo->prepare(
            'SELECT channel_id FROM posted_max_posts WHERE id=:post LIMIT 1'
        );
        $channelLookup->execute(['post' => $postId]);
        $channelId = (int) ($channelLookup->fetchColumn() ?: 0);
        if ($channelId <= 0 || !$this->ensureMaxChannelOwnerCurrent($channelId)) {
            return null;
        }
        $permissionCondition = match ($capability) {
            'post' => '(
                access_permission.can_post=1
                AND (
                    p.author_max_user_id=:max_user3
                    OR access_permission.can_manage=1
                )
            )',
            'schedule' => '(
                access_permission.can_schedule=1
                AND (
                    p.author_max_user_id=:max_user3
                    OR access_permission.can_manage=1
                )
            )',
            'manage' => '(
                access_permission.can_manage=1
                OR (
                    access_permission.can_post=1
                    AND p.author_max_user_id=:max_user3
                )
            )',
            default => '(
                access_permission.can_post=1
                OR access_permission.can_schedule=1
                OR access_permission.can_manage=1
            )',
        };
        $stmt = $this->pdo->prepare(
            "SELECT p.*,c.title AS channel_title,c.chat_id,c.chat_type
             FROM posted_max_posts p
             JOIN posted_max_channels c ON c.id=p.channel_id
             LEFT JOIN posted_max_permissions access_permission
               ON access_permission.channel_id=c.id
              AND access_permission.max_user_id=:max_user
             WHERE p.id=:id
               AND c.is_active=1
               AND (c.added_by=:max_user2 OR {$permissionCondition})
             LIMIT 1"
        );
        $params = [
            'id' => $postId,
            'max_user' => $maxUserId,
            'max_user2' => $maxUserId,
        ];
        if (in_array($capability, ['post', 'schedule', 'manage'], true)) {
            $params['max_user3'] = $maxUserId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function ownedTemplate(string $maxUserId, int $templateId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM posted_max_templates
             WHERE id=:id AND owner_max_user_id=:owner LIMIT 1"
        );
        $stmt->execute(['id' => $templateId, 'owner' => $maxUserId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function parseUrlButtons(string $text): array
    {
        if ($text === '-') {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = [];
            foreach (explode('|', $line) as $piece) {
                $piece = trim($piece);
                $intent = 'default';
                if (preg_match('/^!(r|g|b)\s+(.+)$/u', $piece, $styleMatch) === 1) {
                    $intent = match ((string) $styleMatch[1]) {
                        'r' => 'negative',
                        'g' => 'positive',
                        'b' => 'default',
                    };
                    $piece = trim((string) $styleMatch[2]);
                }
                if (preg_match('/^(.+?)\s+-\s+(https?:\/\/\S+)$/u', $piece, $match) !== 1) {
                    throw new InvalidArgumentException('Неверный формат кнопок. Пример: Сайт - https://example.ru');
                }
                $url = trim($match[2]);
                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    throw new InvalidArgumentException('Некорректная ссылка в кнопке.');
                }
                $row[] = GitPostedMaxApi::linkButton(trim($match[1]), $url, $intent);
                if (count($row) > 3) {
                    throw new InvalidArgumentException('Не более 3 URL-кнопок в строке.');
                }
            }
            if ($row !== []) {
                $rows[] = $row;
            }
            if (count($rows) > 10) {
                throw new InvalidArgumentException('Не более 10 строк кнопок.');
            }
        }
        if ($rows === []) {
            throw new InvalidArgumentException('Кнопки не распознаны.');
        }
        return $rows;
    }

    private function parseMaxScheduleForDate(string $value, string $timezone, string $defaultDate): string
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('Europe/Moscow');
        }
        $value = trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
        $match = [];
        if (preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?\s+(\d{1,2})(?::|\s+)(\d{2})$/uD', $value, $dateFirst) === 1) {
            $match = [
                0 => $value,
                1 => $dateFirst[4],
                2 => $dateFirst[5],
                3 => $dateFirst[1],
                4 => $dateFirst[2],
                5 => $dateFirst[3],
            ];
        } elseif (preg_match('/^(\d{1,2})(?::|\s+)(\d{2})(?:\s+(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?)?$/uD', $value, $match) !== 1) {
            if (preg_match('/^(\d{2})(\d{2})$/D', $value, $compact) === 1) {
                $match = [0 => $value, 1 => $compact[1], 2 => $compact[2]];
            }
        }
        if ($match !== []) {
            $defaultDate = $this->normalizeMaxDate($defaultDate) ?? (new DateTimeImmutable('now', $zone))->format('Y-m-d');
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $defaultDate, $dateMatch);
            $hour = (int) $match[1];
            $minute = (int) $match[2];
            $year = isset($match[5]) && (string) $match[5] !== '' ? (int) $match[5] : (int) $dateMatch[1];
            $month = isset($match[4]) && (string) $match[4] !== '' ? (int) $match[4] : (int) $dateMatch[2];
            $day = isset($match[3]) && (string) $match[3] !== '' ? (int) $match[3] : (int) $dateMatch[3];
            if ($hour <= 23 && $minute <= 59 && checkdate($month, $day, $year)) {
                $localValue = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);
                $date = new DateTimeImmutable($localValue, $zone);
                if ($date->format('Y-m-d H:i:s') !== $localValue) {
                    throw new InvalidArgumentException('Такого местного времени нет из-за перевода часов. Укажите другое время.');
                }
                if ($date <= new DateTimeImmutable('now', $zone)) {
                    throw new InvalidArgumentException('Это время уже прошло. Укажите будущую дату и время.');
                }
                try {
                    $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
                } catch (Throwable) {
                    $storageZone = new DateTimeZone('Europe/Moscow');
                }
                return $date->setTimezone($storageZone)->format('Y-m-d H:i:s');
            }
            throw new InvalidArgumentException(
                'Не понял дату или время. Формат: ДД.ММ.ГГГГ ЧЧ:ММ. Можно отправить только время: 18:30, 18 30 или 1830.'
            );
        }
        return $this->parseUserDate($value, $timezone);
    }

    private function parseUserDate(string $value, string $timezone): string
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('Europe/Moscow');
        }
        $value = mb_strtolower(trim($value));
        $now = new DateTimeImmutable('now', $zone);
        $date = false;
        if (preg_match('/^через\s+(\d{1,5})\s*(мин|минут|минуты|ч|час|часа|часов)$/uD', $value, $relative) === 1) {
            $amount = (int) $relative[1];
            $minutes = str_starts_with((string) $relative[2], 'ч') ? $amount * 60 : $amount;
            if ($minutes > 0 && $minutes <= 43200) {
                $date = $now->modify('+' . $minutes . ' minutes');
            }
        } elseif (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) === 1) {
            $candidate = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                $now->format('Y-m-d') . ' ' . $value,
                $zone
            );
            if ($candidate instanceof DateTimeImmutable) {
                $date = $candidate <= $now ? $candidate->modify('+1 day') : $candidate;
            }
        }
        foreach (['!d.m.Y H:i', '!Y-m-d H:i'] as $format) {
            if ($date instanceof DateTimeImmutable) {
                break;
            }
            $candidate = DateTimeImmutable::createFromFormat($format, $value, $zone);
            if ($candidate instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0)) {
                    $date = $candidate;
                    break;
                }
            }
        }
        if (!$date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(
                'Не понял дату или время. Формат: ДД.ММ.ГГГГ ЧЧ:ММ. Можно отправить только время: 18:30, 18 30 или 1830.'
            );
        }
        if ($date <= $now) {
            throw new InvalidArgumentException('Это время уже прошло. Укажите будущую дату и время.');
        }
        try {
            $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
        } catch (Throwable) {
            $storageZone = new DateTimeZone('Europe/Moscow');
        }
        return $date->setTimezone($storageZone)->format('Y-m-d H:i:s');
    }

    private function displayDate(string $storageDate, string $timezone): string
    {
        try {
            $storageZone = new DateTimeZone((string) ($this->config['timezone'] ?? 'Europe/Moscow'));
            $userZone = new DateTimeZone($timezone);
            return (new DateTimeImmutable($storageDate, $storageZone))
                ->setTimezone($userZone)
                ->format('d.m.Y H:i');
        } catch (Throwable) {
            return $storageDate;
        }
    }

    private function forwardedChatId(array $message): ?string
    {
        $link = $message['link'] ?? ($message['body']['link'] ?? null);
        if (!is_array($link) || mb_strtolower(trim((string) ($link['type'] ?? ''))) !== 'forward') {
            return null;
        }

        $linkedMessage = is_array($link['message'] ?? null) ? $link['message'] : [];
        $recipient = is_array($linkedMessage['recipient'] ?? null)
            ? $linkedMessage['recipient']
            : [];
        $candidates = [
            $link['chat_id'] ?? null,
            $link['chatId'] ?? null,
            $linkedMessage['chat_id'] ?? null,
            $linkedMessage['chatId'] ?? null,
            $recipient['chat_id'] ?? null,
            $recipient['chatId'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (!is_int($candidate) && !is_string($candidate)) {
                continue;
            }
            $candidate = trim((string) $candidate);
            if (preg_match('/^-?\d+$/D', $candidate) === 1) {
                return $candidate;
            }
        }
        return null;
    }

    private function forwardedContentMessage(array $message): ?array
    {
        $link = $message['link'] ?? ($message['body']['link'] ?? null);
        if (!is_array($link) || mb_strtolower(trim((string) ($link['type'] ?? ''))) !== 'forward') {
            return null;
        }
        $forwarded = $link['message'] ?? null;
        return is_array($forwarded) && $forwarded !== [] ? $forwarded : null;
    }

    private function forwardedMessageId(array $message): ?string
    {
        $forwarded = $this->forwardedContentMessage($message);
        if ($forwarded === null) {
            return null;
        }
        $mid = trim((string) ($forwarded['mid'] ?? $forwarded['message_id'] ?? ''));
        return $mid !== '' ? $mid : null;
    }

    private function reusableAttachments(array $message): array
    {
        $attachments = (array) ($message['attachments'] ?? $message['body']['attachments'] ?? []);
        $safe = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $type = (string) ($attachment['type'] ?? '');
            if (!in_array($type, ['image', 'video', 'audio', 'file'], true)) {
                continue;
            }
            $payload = $attachment['payload'] ?? null;
            if (!is_array($payload) || $payload === []) {
                continue;
            }
            $item = ['type' => $type, 'payload' => $payload];
            if ($type === 'file') {
                $filename = GitPostedMaxForbiddenFileFallback::originalFilename($attachment);
                if ($filename !== '') {
                    $item['filename'] = $filename;
                }
                $size = $attachment['size'] ?? null;
                if ((is_int($size) || is_string($size)) && (int) $size >= 0) {
                    $item['size'] = (int) $size;
                }
            }
            $safe[] = $item;
        }
        GitPostedMaxApi::validateMediaAttachments($safe);
        return $safe;
    }

    private function firstMessage(array $response): ?array
    {
        if (isset($response['messages'][0]) && is_array($response['messages'][0])) {
            return $response['messages'][0];
        }
        if (isset($response['message']) && is_array($response['message'])) {
            return $response['message'];
        }
        if (isset($response['body']) || isset($response['attachments'])) {
            return $response;
        }
        return null;
    }

    private function extractUser(array $update): array
    {
        $candidates = [
            $update['callback']['user'] ?? null,
            $update['user'] ?? null,
            $update['message']['sender'] ?? null,
            $update['sender'] ?? null,
            $update['from'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }
        $callbackUserId = $update['callback']['user_id'] ?? null;
        if (is_int($callbackUserId) || is_string($callbackUserId)) {
            $callbackUserId = trim((string) $callbackUserId);
            if (preg_match('/^\d+$/D', $callbackUserId) === 1) {
                return ['user_id' => $callbackUserId];
            }
        }
        return [];
    }

    private function extractMessage(array $update): array
    {
        if (isset($update['message']) && is_array($update['message'])) {
            return $update['message'];
        }
        if (isset($update['data']['message']) && is_array($update['data']['message'])) {
            return $update['data']['message'];
        }
        return $update;
    }

    private function extractCallback(array $update): array
    {
        if (isset($update['callback']) && is_array($update['callback'])) {
            return $update['callback'];
        }
        if (isset($update['data']['callback']) && is_array($update['data']['callback'])) {
            return $update['data']['callback'];
        }
        return $update;
    }

    private function eventChatId(array $update): string
    {
        $values = [
            $update['chat_id'] ?? null,
            $update['callback']['chat_id'] ?? null,
            $update['callback']['message']['chat_id'] ?? null,
            $update['callback']['message']['recipient']['chat_id'] ?? null,
            $update['callback']['message']['recipient']['chat']['chat_id'] ?? null,
            $update['callback']['message']['recipient']['chat']['id'] ?? null,
            $update['message']['chat_id'] ?? null,
            $update['message']['recipient']['chat_id'] ?? null,
            $update['message']['recipient']['chat']['chat_id'] ?? null,
            $update['message']['recipient']['chat']['id'] ?? null,
            $update['message']['chat']['chat_id'] ?? null,
            $update['message']['chat']['id'] ?? null,
            $update['message_created']['chat_id'] ?? null,
            $update['chat']['chat_id'] ?? null,
            $update['chat']['id'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function eventChatType(array $update): string
    {
        if (isset($update['is_channel']) && is_bool($update['is_channel'])) {
            return $update['is_channel'] ? 'channel' : '';
        }
        $values = [
            $update['message']['recipient']['chat_type'] ?? null,
            $update['message']['recipient']['chat']['type'] ?? null,
            $update['message']['chat']['type'] ?? null,
            $update['chat_type'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function eventMessageId(array $update): string
    {
        $values = [
            $update['message']['body']['mid'] ?? null,
            $update['message']['mid'] ?? null,
            $update['message_id'] ?? null,
            $update['mid'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function messageText(array $message): string
    {
        $values = [
            $message['body']['text'] ?? null,
            is_string($message['body'] ?? null) ? $message['body'] : null,
            $message['text'] ?? null,
        ];
        foreach ($values as $value) {
            if (is_string($value)) {
                return $value;
            }
        }
        return '';
    }

    /** Preserve UTF-16 markup positions when removing outer input whitespace. */
    private function trimmedMessageContent(array $message): array
    {
        $raw = $this->messageText($message);
        $text = trim($raw);
        if ($text === $raw) {
            return ['text' => $text, 'html' => $this->messageHtml($message)];
        }
        $leadingBytes = strlen($raw) - strlen(ltrim($raw));
        $leadingUnits = intdiv(strlen(mb_convert_encoding(substr($raw, 0, $leadingBytes), 'UTF-16LE', 'UTF-8')), 2);
        $textUnits = intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $markup = is_array($body['markup'] ?? null)
            ? $body['markup'] : (is_array($message['markup'] ?? null) ? $message['markup'] : []);
        $trimmedMarkup = [];
        foreach ($markup as $item) {
            if (!is_array($item)) continue;
            $from = (int) ($item['from'] ?? -1);
            $length = (int) ($item['length'] ?? 0);
            if ($from < 0 || $length <= 0) continue;
            $start = max(0, $from - $leadingUnits);
            $end = min($textUnits, $from + $length - $leadingUnits);
            if ($end <= $start) continue;
            $item['from'] = $start;
            $item['length'] = $end - $start;
            $trimmedMarkup[] = $item;
        }
        return ['text' => $text, 'html' => $this->messageHtml(['text' => $text, 'markup' => $trimmedMarkup])];
    }

    private function messageHtml(array $message): ?string
    {
        $text = $this->messageText($message);
        $body = is_array($message['body'] ?? null) ? $message['body'] : [];
        $markup = is_array($body['markup'] ?? null)
            ? $body['markup']
            : (is_array($message['markup'] ?? null) ? $message['markup'] : []);
        $tags = [
            'strong' => 'b',
            'bold' => 'b',
            'emphasized' => 'i',
            'italic' => 'i',
            'underline' => 'u',
            'strikethrough' => 's',
            'monospaced' => 'code',
            'code' => 'code',
            'blockquote' => 'blockquote',
            'quote' => 'blockquote',
        ];
        $ranges = [];
        foreach ($markup as $order => $item) {
            if (!is_array($item)) {
                continue;
            }
            $from = (int) ($item['from'] ?? -1);
            $length = (int) ($item['length'] ?? 0);
            $type = strtolower((string) ($item['type'] ?? ''));
            if ($from < 0 || $length <= 0) {
                continue;
            }
            $mentionUrl = $type === 'user_mention' ? GitPostedPostLink::maxMentionUrl((string) ($item['user_link'] ?? '')) : null;
            if ($mentionUrl !== null) {
                $url = htmlspecialchars($mentionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $ranges[] = compact('from', 'length', 'order') + ['open' => '<a href="' . $url . '">', 'close' => '</a>'];
                continue;
            }
            if ($type === 'link' && filter_var($item['url'] ?? null, FILTER_VALIDATE_URL)) {
                $url = htmlspecialchars((string) $item['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $ranges[] = compact('from', 'length', 'order') + [
                    'open' => '<a href="' . $url . '">',
                    'close' => '</a>',
                ];
                continue;
            }
            if (isset($tags[$type])) {
                $tag = $tags[$type];
                $ranges[] = compact('from', 'length', 'order') + [
                    'open' => '<' . $tag . '>',
                    'close' => '</' . $tag . '>',
                ];
            }
        }
        if ($ranges === []) {
            return null;
        }
        foreach($ranges as $leftIndex=>$left){
            $leftEnd=$left['from']+$left['length'];
            foreach($ranges as $rightIndex=>$right){
                if($rightIndex<=$leftIndex)continue;
                $rightEnd=$right['from']+$right['length'];
                if(
                    ($left['from']<$right['from']&&$right['from']<$leftEnd&&$leftEnd<$rightEnd)
                    ||($right['from']<$left['from']&&$left['from']<$rightEnd&&$rightEnd<$leftEnd)
                ){
                    // Crossing ranges cannot be represented by well-formed HTML.
                    // Preserve the content and publish it as plain text instead.
                    return null;
                }
            }
        }
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);
        $boundaries = [0, $units];
        foreach ($ranges as $range) {
            if ($range['from'] + $range['length'] > $units) {
                return null;
            }
            $boundaries[] = $range['from'];
            $boundaries[] = $range['from'] + $range['length'];
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries, SORT_NUMERIC);
        $html = '';
        for ($index = 0, $last = count($boundaries) - 1; $index < $last; $index++) {
            $from = $boundaries[$index];
            $to = $boundaries[$index + 1];
            $closing = array_values(array_filter(
                $ranges,
                static fn (array $range): bool => $range['from'] + $range['length'] === $from
            ));
            usort($closing, static fn (array $left, array $right): int =>
                ($right['from'] <=> $left['from']) ?: ($right['order'] <=> $left['order'])
            );
            $opening = array_values(array_filter(
                $ranges,
                static fn (array $range): bool => $range['from'] === $from
            ));
            usort($opening, static fn (array $left, array $right): int =>
                ($right['length'] <=> $left['length']) ?: ($left['order'] <=> $right['order'])
            );
            $html .= implode('', array_column($closing, 'close'));
            $html .= implode('', array_column($opening, 'open'));
            $part = mb_convert_encoding(substr($utf16, $from * 2, ($to - $from) * 2), 'UTF-8', 'UTF-16LE');
            $html .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $closing = array_values(array_filter(
            $ranges,
            static fn (array $range): bool => $range['from'] + $range['length'] === $units
        ));
        usort($closing, static fn (array $left, array $right): int =>
            ($right['from'] <=> $left['from']) ?: ($right['order'] <=> $left['order'])
        );
        $html .= implode('', array_column($closing, 'close'));
        return mb_strlen($html) <= 4000 ? $html : null;
    }

    private function userId(array $user): string
    {
        $value = $user['user_id'] ?? $user['id'] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return '';
        }
        $value = trim((string) $value);
        return preg_match('/^\d+$/D', $value) === 1 ? $value : '';
    }

    private function telegramBotUrl(): string
    {
        $username = ltrim(trim((string) ($this->config['bot_username'] ?? '')), '@');
        return preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) === 1
            ? 'https://t.me/' . $username
            : '';
    }

    private function updateKey(array $update, string $raw): string
    {
        $type = (string) ($update['update_type'] ?? $update['type'] ?? 'unknown');
        $identity = $update['update_id'] ?? $update['event_id'] ?? null;
        if (is_int($identity) || (is_string($identity) && $identity !== '')) {
            return hash('sha256', 'id|' . $type . '|' . (string) $identity);
        }
        $callback = $this->extractCallback($update);
        $callbackId = $callback['callback_id'] ?? $callback['id'] ?? null;
        if (is_int($callbackId) || (is_string($callbackId) && $callbackId !== '')) {
            return hash('sha256', 'callback|' . (string) $callbackId);
        }
        $messageId = $this->eventMessageId($update);
        if ($messageId !== '') {
            return hash('sha256', 'message|' . $type . '|' . $messageId);
        }
        $source = $raw !== ''
            ? $raw
            : json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', 'payload|' . $source);
    }

    private function maxUpdateActorKey(array $update, string $fallback): string
    {
        $type = trim((string) ($update['update_type'] ?? $update['type'] ?? ''));
        $chatId = $this->eventChatId($update);
        if (
            $chatId !== ''
            && in_array(
                $type,
                ['bot_added', 'bot_removed', 'user_removed', 'chat_title_changed', 'message_removed'],
                true
            )
        ) {
            return hash('sha256', 'chat|' . $chatId);
        }
        $userId = $this->userId($this->extractUser($update));
        if ($userId !== '') {
            return hash('sha256', 'user|' . $userId);
        }
        if ($chatId !== '') {
            return hash('sha256', 'chat|' . $chatId);
        }
        return hash('sha256', 'update|' . $fallback);
    }

    private function hasAny(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    private function buttonCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $count += count($row);
            }
        }
        return $count;
    }

    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

function posted_max_app(): GitPostedMaxBot
{
    $config = PostedConfig::get();
    $pdo = PostedDb::pdo($config);
    PostedDb::assertSchema($pdo);
    return new GitPostedMaxBot($pdo, new GitPostedMaxApi($config), $config);
}

function posted_max_consume_link_code(int $telegramUserId, string $code): array
{
    $config = PostedConfig::get();
    $pdo = PostedDb::pdo($config);
    PostedDb::assertSchema($pdo);
    return GitPostedMaxLink::consume($pdo, $config, $telegramUserId, $code);
}
