<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

session_name('posted_admin');
session_set_cookie_params(['lifetime'=>0,'path'=>'/control/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    reply(['ok' => false, 'error' => $message], $status);
}

function b64uEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function b64uDecode(mixed $value): string
{
    $value = (string) $value;
    if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) fail('Некорректные данные ключа.');
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    if ($decoded === false) fail('Некорректные данные ключа.');
    return $decoded;
}

function body(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($decoded)) fail('Некорректный запрос.');
    return $decoded;
}

function ensureTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS posted_admin_passkeys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            credential_id VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(80) NOT NULL,
            public_key_spki MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            algorithm SMALLINT NOT NULL,
            sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            transports VARCHAR(255) NOT NULL DEFAULT '[]',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            UNIQUE KEY uq_posted_admin_passkey_credential (credential_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function listKeys(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id,name,transports,created_at,last_used_at FROM posted_admin_passkeys ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function challenge(string $kind): string
{
    $value = b64uEncode(random_bytes(32));
    $_SESSION['passkey_' . $kind] = ['value' => $value, 'expires' => time() + 300];
    return $value;
}

function takeChallenge(string $kind): string
{
    $key = 'passkey_' . $kind;
    $data = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    if (!is_array($data) || (int) ($data['expires'] ?? 0) < time()) fail('Запрос ключа истёк. Повторите действие.');
    return (string) ($data['value'] ?? '');
}

/** @return array{origin:string,rp_id:string} */
function passkeySite(): array
{
    static $site = null;
    if (is_array($site)) {
        return $site;
    }

    $config = PostedConfig::get();
    $baseUrl = trim((string) ($config['base_url'] ?? ''));
    $parts = parse_url($baseUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($scheme !== 'https'
        || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1
    ) {
        throw new RuntimeException('Для ключей доступа требуется корректный HTTPS base_url.');
    }

    $origin = $scheme . '://' . $host;
    if ($port !== null && $port !== 443) {
        $origin .= ':' . $port;
    }
    return $site = ['origin' => $origin, 'rp_id' => $host];
}

function verifyClient(array $response, string $type, string $expectedChallenge): string
{
    $site = passkeySite();
    $raw = b64uDecode($response['clientDataJSON'] ?? '');
    $client = json_decode($raw, true);
    if (!is_array($client)
        || !hash_equals($type, (string) ($client['type'] ?? ''))
        || !hash_equals($expectedChallenge, (string) ($client['challenge'] ?? ''))
        || !hash_equals($site['origin'], rtrim((string) ($client['origin'] ?? ''), '/'))
    ) fail('Проверка браузера не пройдена.');
    return $raw;
}

function parseAuthenticator(string $encoded): array
{
    $site = passkeySite();
    $raw = b64uDecode($encoded);
    if (strlen($raw) < 37) fail('Некорректные данные устройства.');
    if (!hash_equals(hash('sha256', $site['rp_id'], true), substr($raw, 0, 32))) fail('Ключ выпущен для другого сайта.');
    $flags = ord($raw[32]);
    if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) fail('Нужно подтвердить присутствие и проверку пользователя.');
    $count = unpack('Nvalue', substr($raw, 33, 4));
    return [$raw, (int) ($count['value'] ?? 0)];
}

function pem(string $spki): string
{
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function authorize(): void
{
    session_regenerate_id(true);
    $_SESSION['posted_admin_authenticated'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    unset($_SESSION['login_csrf']);
}

try {
    $pdo = PostedDb::pdo(PostedConfig::get());
    ensureTable($pdo);
    $path = trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $authorized = !empty($_SESSION['posted_admin_authenticated']);

    if ($path === 'status' && $method === 'GET') {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn();
        reply(['ok' => true, 'available' => $count > 0, 'supported' => true]);
    }

    if ($path === 'auth/options' && $method === 'POST') {
        $site = passkeySite();
        $rows = $pdo->query('SELECT credential_id,transports FROM posted_admin_passkeys ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) fail('Ключи доступа ещё не добавлены.', 404);
        reply([
            'ok' => true,
            'publicKey' => [
                'challenge' => challenge('auth'),
                'rpId' => $site['rp_id'],
                'timeout' => 60000,
                'userVerification' => 'required',
                'allowCredentials' => array_map(static fn(array $row): array => [
                    'type' => 'public-key',
                    'id' => $row['credential_id'],
                    'transports' => json_decode((string) $row['transports'], true) ?: [],
                ], $rows),
            ],
        ]);
    }

    if ($path === 'auth/verify' && $method === 'POST') {
        $input = body();
        $credential = (string) ($input['credential_id'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM posted_admin_passkeys WHERE credential_id=:credential LIMIT 1');
        $stmt->execute(['credential' => $credential]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$key) fail('Ключ доступа не найден.', 404);
        $response = is_array($input['response'] ?? null) ? $input['response'] : [];
        $clientRaw = verifyClient($response, 'webauthn.get', takeChallenge('auth'));
        [$authRaw, $counter] = parseAuthenticator((string) ($response['authenticatorData'] ?? ''));
        $algorithm = (int) $key['algorithm'];
        $opensslAlgorithm = $algorithm === -7 ? OPENSSL_ALGO_SHA256 : ($algorithm === -257 ? OPENSSL_ALGO_SHA256 : null);
        if ($opensslAlgorithm === null
            || openssl_verify(
                $authRaw . hash('sha256', $clientRaw, true),
                b64uDecode($response['signature'] ?? ''),
                pem(base64_decode((string) $key['public_key_spki'], true) ?: ''),
                $opensslAlgorithm
            ) !== 1
        ) fail('Подпись ключа недействительна.', 401);
        if ((int) $key['sign_count'] > 0 && $counter > 0 && $counter <= (int) $key['sign_count']) fail('Счётчик ключа недействителен.', 401);
        $stmt = $pdo->prepare('UPDATE posted_admin_passkeys SET sign_count=:counter,last_used_at=NOW() WHERE id=:id');
        $stmt->execute(['counter' => max($counter, (int) $key['sign_count']), 'id' => $key['id']]);
        authorize();
        reply(['ok' => true, 'redirect' => '/control/?page=overview']);
    }

    if (!$authorized) fail('Требуется вход в панель.', 401);
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail('CSRF validation failed', 403);

    if ($path === '' && $method === 'GET') reply(['ok' => true, 'passkeys' => listKeys($pdo), 'limit' => 3]);

    if ($path === 'register/options' && $method === 'POST') {
        $site = passkeySite();
        if ((int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn() >= 3) fail('Можно добавить не более трёх ключей доступа.');
        $rows = $pdo->query('SELECT credential_id FROM posted_admin_passkeys')->fetchAll(PDO::FETCH_COLUMN);
        reply([
            'ok' => true,
            'publicKey' => [
                'challenge' => challenge('register'),
                'rp' => ['id' => $site['rp_id'], 'name' => 'GitPosted'],
                'user' => [
                    'id' => b64uEncode(hash('sha256', 'gitposted-developer', true)),
                    'name' => 'Developer',
                    'displayName' => 'Developer',
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
                'excludeCredentials' => array_map(static fn(string $id): array => ['type' => 'public-key', 'id' => $id], $rows),
            ],
        ]);
    }

    if ($path === 'register/verify' && $method === 'POST') {
        $input = body();
        if ((int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn() >= 3) fail('Можно добавить не более трёх ключей доступа.');
        $response = is_array($input['response'] ?? null) ? $input['response'] : [];
        verifyClient($response, 'webauthn.create', takeChallenge('register'));
        [, $counter] = parseAuthenticator((string) ($response['authenticatorData'] ?? ''));
        $algorithm = (int) ($response['publicKeyAlgorithm'] ?? 0);
        if (!in_array($algorithm, [-7, -257], true)) fail('Этот тип ключа не поддерживается.');
        $spki = b64uDecode($response['publicKey'] ?? '');
        if (openssl_pkey_get_public(pem($spki)) === false) fail('Открытый ключ устройства недействителен.');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn();
        $name = $count === 0 ? 'Developer' : 'Developer ' . ($count + 1);
        $stmt = $pdo->prepare(
            'INSERT INTO posted_admin_passkeys(credential_id,name,public_key_spki,algorithm,sign_count,transports)
             VALUES(:credential,:name,:public_key,:algorithm,:counter,:transports)'
        );
        $stmt->execute([
            'credential' => (string) ($input['credential_id'] ?? ''),
            'name' => $name,
            'public_key' => base64_encode($spki),
            'algorithm' => $algorithm,
            'counter' => $counter,
            'transports' => json_encode(array_values((array) ($response['transports'] ?? [])), JSON_UNESCAPED_SLASHES),
        ]);
        reply(['ok' => true, 'passkeys' => listKeys($pdo)]);
    }

    if (preg_match('~\A(\d+)\z~D', $path, $match) === 1 && $method === 'DELETE') {
        $stmt = $pdo->prepare('DELETE FROM posted_admin_passkeys WHERE id=:id');
        $stmt->execute(['id' => (int) $match[1]]);
        reply(['ok' => true, 'passkeys' => listKeys($pdo)]);
    }

    fail('Маршрут не найден.', 404);
} catch (Throwable $error) {
    fail('Операция с ключом доступа не выполнена.', 500);
}
