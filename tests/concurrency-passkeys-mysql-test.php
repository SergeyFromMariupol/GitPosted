<?php
declare(strict_types=1);

/** Opt-in real MySQL concurrency. JSON connection credentials arrive on stdin.
 * Requires a fresh EMPTY GitPostedRace_<12 hex> DB owned by a fixture runner.
 * No production configuration is read; the caller removes its generated DB/user.
 */
define('GITPOSTED_PASSKEY_RACE_WRAP_VERIFY', true);
define('GITPOSTED_PASSKEY_RACE_DECLARATIONS_ONLY', true);
require __DIR__ . '/concurrency-passkeys-test.php';

function verifyClient(array $response, string $type, string $expectedChallenge): string {
    if (($GLOBALS['raceMode'] ?? '') === 'auth-hold') {
        file_put_contents($GLOBALS['raceDirectory'] . '/locked', '1');
        raceWait($GLOBALS['raceDirectory'] . '/resume');
    }
    return originalPasskeyVerifyClient($response, $type, $expectedChallenge);
}
function raceWait(string $path): void {
    $deadline = microtime(true) + 15;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Fixture synchronization timed out');
        usleep(10000);
    }
}
function raceConnection(array $value): PDO {
    checked(($value['host'] ?? '') === 'localhost' && preg_match('/\AGitPostedRace_[a-f0-9]{12}\z/D', $value['name'] ?? '') === 1, 'Only an explicit isolated local fixture DB is accepted');
    $pdo = new PDO('mysql:host=localhost;dbname=' . $value['name'] . ';charset=utf8mb4', $value['user'], $value['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    checked($pdo->query('SELECT DATABASE()')->fetchColumn() === $value['name'], 'Wrong fixture DB');
    return $pdo;
}
function racePrivate(): array {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    checked($key !== false && openssl_pkey_export($key, $private), 'Fixture key unavailable');
    $pem = openssl_pkey_get_details($key)['key'];
    $spki = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
    checked(is_string($spki), 'Fixture public key unavailable');
    return [$private, $spki];
}
function raceSeed(PDO $pdo, string $spki, int $count): void {
    $pdo->exec('DELETE FROM posted_admin_passkeys');
    $insert = $pdo->prepare('INSERT INTO posted_admin_passkeys(credential_id,name,public_key_spki,algorithm,sign_count,transports) VALUES(:id,:name,:key,-7,2,\'[]\')');
    for ($i = 0; $i < $count; ++$i) $insert->execute(['id' => 'fixture-' . $i, 'name' => 'Fixture', 'key' => base64_encode($spki)]);
}
function raceStart(array $config, string $directory, string $mode, string $tag, array $extra = []): array {
    $process = proc_open([PHP_BINARY, __FILE__, '--child'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    checked(is_resource($process), 'Fixture child unavailable');
    fwrite($pipes[0], json_encode(['connection' => $config, 'directory' => $directory, 'mode' => $mode, 'tag' => $tag] + $extra, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    return [$process, $pipes];
}
function raceResult(array $child): array {
    [$process, $pipes] = $child; $deadline = microtime(true) + 20;
    while (proc_get_status($process)['running']) {
        if (microtime(true) >= $deadline) { proc_terminate($process); throw new RuntimeException('Fixture child timed out'); }
        usleep(10000);
    }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($process);
    $data = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    checked(is_array($data) && isset($data['status']) && ($data['transaction'] ?? false) === false, 'Fixture child failed or retained transaction');
    return $data;
}

if (($argv[1] ?? '') === '--child') {
    $pdo = null;
    try {
        $request = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        $GLOBALS['raceMode'] = $request['mode']; $GLOBALS['raceDirectory'] = $request['directory'];
        checked(realpath($request['directory']) === $request['directory'] && !is_link($request['directory']), 'Unsafe synchronization directory');
        $pdo = raceConnection($request['connection']); $_SESSION = [];
        if ($request['mode'] === 'register') {
            [$private, $spki] = racePrivate(); $value = newRegistration($request['tag'], $spki);
            file_put_contents($request['directory'] . '/ready-' . $request['tag'], '1'); raceWait($request['directory'] . '/go');
            registerPasskey($pdo, $value);
        } elseif ($request['mode'] === 'delete') {
            file_put_contents($request['directory'] . '/working-delete', '1');
            $pdo->prepare('DELETE FROM posted_admin_passkeys WHERE credential_id=:id')->execute(['id' => 'fixture-0']);
        } else {
            $key = openssl_pkey_get_private($request['private']); checked($key !== false, 'Fixture signer unavailable');
            $value = newAssertion('fixture-0', $request['counter'], $key);
            if ($request['mode'] === 'auth-second') file_put_contents($request['directory'] . '/working-auth', '1');
            verifyAndUsePasskey($pdo, $value);
        }
        $result = ['status' => 200];
    } catch (GitPostedPasskeyRequestFailure $error) { $result = ['status' => $error->status]; }
    catch (Throwable) { $result = ['status' => 500]; }
    $result['transaction'] = $pdo instanceof PDO && $pdo->inTransaction();
    echo json_encode($result), "\n"; exit;
}
if (($argv[1] ?? '') !== '--run') {
    echo "Opt-in: php tests/concurrency-passkeys-mysql-test.php --run < private-fixture-connection.json\n";
    exit;
}
$config = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$pdo = raceConnection($config);
checked((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0, 'Fixture DB is not empty; refusing to modify it');
$directory = sys_get_temp_dir() . '/gitposted-passkey-race-' . bin2hex(random_bytes(8));
checked(mkdir($directory, 0700), 'Fixture directory unavailable');
$children = []; $created = false;
try {
    // The fixture owns this initially empty database; no existing tables are used.
    $pdo->exec('CREATE TABLE posted_admin_passkeys (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, credential_id VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, name VARCHAR(80) NOT NULL, public_key_spki MEDIUMTEXT NOT NULL, algorithm SMALLINT NOT NULL, sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0, transports VARCHAR(255) NOT NULL DEFAULT \'[]\', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME NULL) ENGINE=InnoDB');
    $created = true;
    [$private, $spki] = racePrivate(); raceSeed($pdo, $spki, 2);
    $first = raceStart($config, $directory, 'register', 'first'); $children[] = $first;
    $second = raceStart($config, $directory, 'register', 'second'); $children[] = $second;
    raceWait($directory . '/ready-first'); raceWait($directory . '/ready-second'); file_put_contents($directory . '/go', '1');
    $statuses = [raceResult($first)['status'], raceResult($second)['status']]; $children = []; sort($statuses);
    checked($statuses === [200, 400] && (int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn() === 3, 'Concurrent registrations exceeded the three-key limit');
    echo "PASS real MySQL concurrent registration preserves the three-key limit.\n";

    raceSeed($pdo, $spki, 1);
    $first = raceStart($config, $directory, 'auth-hold', 'first', ['private' => $private, 'counter' => 3]); $children[] = $first;
    raceWait($directory . '/locked');
    $second = raceStart($config, $directory, 'delete', 'second'); $children[] = $second; raceWait($directory . '/working-delete'); usleep(150000);
    checked(proc_get_status($second[0])['running'], 'Credential deletion bypassed the locked verification');
    file_put_contents($directory . '/resume', '1');
    checked(raceResult($first)['status'] === 200 && raceResult($second)['status'] === 200, 'Verification/delete serialization failed'); $children = [];
    checked((int) $pdo->query('SELECT COUNT(*) FROM posted_admin_passkeys')->fetchColumn() === 0, 'Credential was not removed');
    echo "PASS real MySQL deletion waits for an already locked verification commit.\n";

    $missing = raceStart($config, $directory, 'auth-second', 'missing', ['private' => $private, 'counter' => 4]); $children[] = $missing;
    checked(raceResult($missing)['status'] === 404, 'Removed key authenticated'); $children = [];
    echo "PASS real MySQL deletion before verification prevents authentication.\n";

    unlink($directory . '/locked'); unlink($directory . '/resume'); raceSeed($pdo, $spki, 1);
    $first = raceStart($config, $directory, 'auth-hold', 'first', ['private' => $private, 'counter' => 3]); $children[] = $first; raceWait($directory . '/locked');
    $second = raceStart($config, $directory, 'auth-second', 'second', ['private' => $private, 'counter' => 4]); $children[] = $second; raceWait($directory . '/working-auth'); usleep(150000);
    checked(proc_get_status($second[0])['running'], 'A second assertion read a stale unprotected counter');
    file_put_contents($directory . '/resume', '1');
    checked(raceResult($first)['status'] === 200 && raceResult($second)['status'] === 200, 'Concurrent valid assertions failed'); $children = [];
    checked((int) $pdo->query('SELECT sign_count FROM posted_admin_passkeys')->fetchColumn() === 4, 'Concurrent verification moved the counter backwards');
    echo "PASS real MySQL concurrent assertions keep the fresh monotonic counter.\n";
} finally {
    foreach ($children as [$process, $pipes]) {
        if (is_resource($process)) { if (proc_get_status($process)['running']) proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    }
    try { if ($created) $pdo->exec('DELETE FROM posted_admin_passkeys'); }
    finally {
        checked(realpath($directory) === $directory && dirname($directory) === realpath(sys_get_temp_dir()), 'Unexpected fixture cleanup directory');
        foreach (['ready-first', 'ready-second', 'go', 'locked', 'resume', 'working-delete', 'working-auth'] as $name) {
            $path = $directory . '/' . $name;
            if (is_file($path)) { checked(!is_link($path), 'Unexpected fixture cleanup link'); unlink($path); }
        }
        checked(rmdir($directory), 'Fixture directory not empty');
    }
}
