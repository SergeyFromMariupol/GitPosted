<?php
declare(strict_types=1);

/** Captured database interleavings; never opens a connection or HTTP endpoint. */
$source = file_get_contents(dirname(__DIR__) . '/payload/src/control/passkeys.php');
$tokens = token_get_all($source);
$wanted = ['fail', 'b64uEncode', 'b64uDecode', 'challenge', 'takeChallenge', 'passkeySite',
    'passkeyCapabilities', 'requirePasskeySite', 'verifyClient', 'parseAuthenticator', 'pem',
    'verifyAndUsePasskey', 'registerPasskey'];
$loaded = [];
for ($i = 0, $length = count($tokens); $i < $length; ++$i) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    for ($j = $i + 1; $j < $length && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; ++$j) {}
    $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';
    if (!in_array($name, $wanted, true)) continue;
    $code = ''; $depth = 0; $opened = false;
    for ($j = $i; $j < $length; ++$j) {
        $token = $tokens[$j]; $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { ++$depth; $opened = true; }
        elseif ($token === '}') --$depth;
        if ($opened && $depth === 0) break;
    }
    if ($name === 'verifyClient' && defined('GITPOSTED_PASSKEY_RACE_WRAP_VERIFY')) {
        $code = str_replace('function verifyClient(', 'function originalPasskeyVerifyClient(', $code);
    }
    eval($code); $loaded[] = $name;
}
if (array_diff($wanted, $loaded) !== []) throw new RuntimeException('Passkey declarations were not extracted');
final class GitPostedPasskeyRequestFailure extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}
final class PostedConfig {
    public static function get(): array { return ['base_url' => 'https://fixture.example']; }
}
function checked(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
if (defined('GITPOSTED_PASSKEY_RACE_DECLARATIONS_ONLY')) return;
final class KeyStore {
    public array $rows = [];
    public array $events = [];
    public ?object $registrationOwner = null;
    public ?object $rowOwner = null;
    public mixed $onCount = null;
    public mixed $onRowRead = null;
    public mixed $afterCommit = null;
    public bool $failInsert = false;
    public bool $failCommit = false;
}
final class CapturedPDO extends PDO {
    private bool $transaction = false;
    private array $snapshot = [];
    public function __construct(public KeyStore $store) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new CapturedStatement($this, $query); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new CapturedStatement($this, $query); }
    public function beginTransaction(): bool {
        checked(!$this->transaction, 'Nested passkey transaction');
        $this->transaction = true; $this->snapshot = $this->store->rows; $this->store->events[] = 'begin'; return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool {
        if ($this->store->failCommit) return false;
        $this->transaction = false; $this->store->rowOwner = null; $this->store->events[] = 'commit';
        if ($this->store->afterCommit !== null) { $callback = $this->store->afterCommit; $this->store->afterCommit = null; $callback(); }
        return true;
    }
    public function rollBack(): bool {
        $this->store->rows = $this->snapshot; $this->transaction = false;
        $this->store->rowOwner = null; $this->store->events[] = 'rollback'; return true;
    }
}
final class CapturedStatement extends PDOStatement {
    private array $parameters = [];
    private mixed $answer = null;
    public function __construct(private CapturedPDO $connection, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->parameters = $params ?? []; $store = $this->connection->store;
        if (str_contains($this->sql, 'GET_LOCK')) {
            $store->events[] = ['get-lock', $this->parameters['name']];
            $this->answer = $store->registrationOwner === null ? 1 : 0;
            if ($this->answer === 1) $store->registrationOwner = $this->connection;
        } elseif (str_contains($this->sql, 'RELEASE_LOCK')) {
            checked($store->registrationOwner === $this->connection, 'Releasing another request lock');
            $store->events[] = ['release-lock', $this->parameters['name']]; $store->registrationOwner = null;
        } elseif (str_starts_with($this->sql, 'SELECT *')) {
            checked($this->connection->inTransaction() && str_contains($this->sql, 'FOR UPDATE'), 'Credential read is not protected by its transaction');
            checked($store->rowOwner === null, 'Another request owns the credential');
            $store->rowOwner = $this->connection; $store->events[] = 'read-for-update';
            $this->answer = $store->rows[$this->parameters['credential']] ?? false;
            if ($store->onRowRead !== null) { $callback = $store->onRowRead; $store->onRowRead = null; $callback($this->connection); }
        } elseif (str_starts_with($this->sql, 'UPDATE')) {
            checked($store->rowOwner === $this->connection, 'Counter changed without owning the credential');
            foreach ($store->rows as &$row) if ($row['id'] === $this->parameters['id']) $row['sign_count'] = $this->parameters['counter'];
            unset($row); $store->events[] = 'counter-update';
        } elseif (str_contains($this->sql, 'INSERT INTO')) {
            checked($store->registrationOwner === $this->connection, 'Insert happened without registration lock');
            if ($store->failInsert) throw new RuntimeException('Captured SQL failure');
            $credential = $this->parameters['credential'];
            if (isset($store->rows[$credential])) throw new RuntimeException('Captured duplicate credential');
            $store->rows[$credential] = ['id' => count($store->rows) + 1, 'credential_id' => $credential,
                'public_key_spki' => $this->parameters['public_key'], 'algorithm' => $this->parameters['algorithm'],
                'sign_count' => $this->parameters['counter']]; $store->events[] = 'insert';
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed {
        $store = $this->connection->store;
        if ($this->sql === 'SELECT DATABASE()') return 'fixture';
        if (str_contains($this->sql, 'SELECT COUNT(*)')) {
            checked($store->registrationOwner === $this->connection, 'Key limit checked outside registration lock');
            $value = count($store->rows);
            if ($store->onCount !== null) { $callback = $store->onCount; $store->onCount = null; $callback(); }
            return $value;
        }
        return $this->answer;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->answer; }
}

$private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
checked($private !== false, 'Fixture key unavailable');
$publicPem = openssl_pkey_get_details($private)['key'];
$spki = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $publicPem), true);
checked(is_string($spki), 'Fixture SPKI unavailable');
function authBytes(int $counter): string { return hash('sha256', 'fixture.example', true) . chr(5) . pack('N', $counter); }
function newRegistration(string $credential, string $spki): array {
    $challenge = challenge('register');
    return ['credential_id' => $credential, 'response' => [
        'clientDataJSON' => b64uEncode(json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => 'https://fixture.example'])),
        'authenticatorData' => b64uEncode(authBytes(0)), 'publicKey' => b64uEncode($spki), 'publicKeyAlgorithm' => -7, 'transports' => ['internal']]];
}
function newAssertion(string $credential, int $counter, OpenSSLAsymmetricKey $private): array {
    $challenge = challenge('auth');
    $raw = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => 'https://fixture.example']);
    openssl_sign(authBytes($counter) . hash('sha256', $raw, true), $signature, $private, OPENSSL_ALGO_SHA256);
    return ['credential_id' => $credential, 'response' => ['clientDataJSON' => b64uEncode($raw),
        'authenticatorData' => b64uEncode(authBytes($counter)), 'signature' => b64uEncode($signature)]];
}
function keyRow(string $spki, int $counter = 2): array { return ['id' => 1, 'credential_id' => 'fixture', 'public_key_spki' => base64_encode($spki), 'algorithm' => -7, 'sign_count' => $counter]; }
$tests = [];
$tests['independent registration requests cannot create a fourth key'] = static function () use ($spki): void {
    $_SESSION = []; $store = new KeyStore(); $store->rows = ['one' => keyRow($spki), 'two' => keyRow($spki)];
    $first = new CapturedPDO($store); $second = new CapturedPDO($store); $input = newRegistration('third', $spki);
    $store->onCount = static function () use ($store, $second, $spki): void {
        $saved = $_SESSION;
        try { registerPasskey($second, newRegistration('fourth', $spki)); throw new RuntimeException('Parallel registration entered a held limit section'); }
        catch (GitPostedPasskeyRequestFailure $error) { checked($error->status === 409, 'Busy registration did not fail safely'); }
        finally { $_SESSION = $saved; }
    };
    registerPasskey($first, $input); checked(count($store->rows) === 3, 'Three-key limit was exceeded');
    try { registerPasskey($second, newRegistration('fourth', $spki)); throw new RuntimeException('Full key set accepted another credential'); }
    catch (GitPostedPasskeyRequestFailure $error) { checked($error->status === 400, 'Wrong full-limit status'); }
    checked(count($store->rows) === 3 && $store->registrationOwner === null, 'Failed registration leaked lock or row');
};
$tests['failed insert and invalid signature release the exact acquired registration lock'] = static function () use ($spki): void {
    foreach (['insert', 'validation'] as $mode) {
        $_SESSION = []; $store = new KeyStore(); $pdo = new CapturedPDO($store); $input = newRegistration('fixture', $spki);
        if ($mode === 'insert') $store->failInsert = true;
        else $input['response']['publicKeyAlgorithm'] = 0;
        try { registerPasskey($pdo, $input); throw new RuntimeException('Captured failure accepted'); }
        catch (Throwable $error) {
            checked($mode === 'insert' ? $error->getMessage() === 'Captured SQL failure'
                : $error instanceof GitPostedPasskeyRequestFailure && $error->status === 400, 'Unexpected registration failure');
        }
        $lock = array_values(array_filter($store->events, 'is_array'));
        checked(count($lock) === 2 && $lock[0][1] === $lock[1][1] && strlen($lock[0][1]) <= 64, 'Different lock was released');
        checked($store->registrationOwner === null && $store->rows === [], 'Failed registration retained lock or data');
    }
};
$tests['deletion before credential read rejects authentication and rolls back'] = static function () use ($private): void {
    $_SESSION = []; $store = new KeyStore(); $pdo = new CapturedPDO($store);
    try { verifyAndUsePasskey($pdo, newAssertion('deleted', 3, $private)); throw new RuntimeException('Deleted key authenticated'); }
    catch (GitPostedPasskeyRequestFailure $error) { checked($error->status === 404, 'Deleted key status'); }
    checked(!$pdo->inTransaction() && in_array('rollback', $store->events, true), 'Missing key retained a transaction');
};
$tests['deletion after locked read completes after the valid authentication commit'] = static function () use ($private, $spki): void {
    $_SESSION = []; $store = new KeyStore(); $store->rows['fixture'] = keyRow($spki); $pdo = new CapturedPDO($store);
    $store->onRowRead = static function (CapturedPDO $holder) use ($store): void {
        checked($store->rowOwner === $holder && $holder->inTransaction(), 'Deletion can pass an unprotected read');
        $store->afterCommit = static function () use ($store): void { unset($store->rows['fixture']); $store->events[] = 'delete-commit'; };
    };
    verifyAndUsePasskey($pdo, newAssertion('fixture', 3, $private));
    checked(array_search('commit', $store->events, true) < array_search('delete-commit', $store->events, true), 'Deleted key was accepted after revocation committed');
};
$tests['fresh counter is checked under the row lock and never rolls back a newer assertion'] = static function () use ($private, $spki): void {
    $_SESSION = []; $store = new KeyStore(); $store->rows['fixture'] = keyRow($spki, 4); $pdo = new CapturedPDO($store);
    try { verifyAndUsePasskey($pdo, newAssertion('fixture', 3, $private)); throw new RuntimeException('Old counter accepted'); }
    catch (GitPostedPasskeyRequestFailure $error) { checked($error->status === 401, 'Old counter status'); }
    checked($store->rows['fixture']['sign_count'] === 4 && !$pdo->inTransaction(), 'A stale assertion rolled back the counter');
    verifyAndUsePasskey($pdo, newAssertion('fixture', 5, $private)); checked($store->rows['fixture']['sign_count'] === 5, 'Fresh assertion not committed');
};
$tests['invalid signatures and commit failure do not complete authentication'] = static function () use ($private, $spki): void {
    foreach (['signature', 'commit'] as $mode) {
        $_SESSION = []; $store = new KeyStore(); $store->rows['fixture'] = keyRow($spki); $pdo = new CapturedPDO($store); $input = newAssertion('fixture', 3, $private);
        if ($mode === 'signature') $input['response']['signature'] = b64uEncode('invalid');
        else $store->failCommit = true;
        try { verifyAndUsePasskey($pdo, $input); throw new RuntimeException('Invalid authentication completed'); }
        catch (Throwable $error) {
            checked($mode === 'signature' ? $error instanceof GitPostedPasskeyRequestFailure && $error->status === 401
                : $error->getMessage() === 'Passkey transaction was not committed.', 'Unexpected authentication failure');
        }
        checked($store->rows['fixture']['sign_count'] === 2 && !$pdo->inTransaction() && $store->rowOwner === null, 'Failure changed counter or retained row lock');
    }
};
foreach ($tests as $name => $test) { $test(); echo 'PASS ', $name, "\n"; }
echo 'passkey concurrency groups passed: ', count($tests), "\n";
