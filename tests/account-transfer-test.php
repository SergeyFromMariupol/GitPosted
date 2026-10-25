<?php

declare(strict_types=1);

/** Offline package and recipient checks. No native PDO connection or real API. */
if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this fixture with PHP CLI.');

function transferPackageAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function transferPackageRejects(callable $action): void
{
    try { $action(); }
    catch (DomainException) { return; }
    throw new RuntimeException('Unsafe or stale recipient was accepted.');
}

final class TransferPackageStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private readonly string $sql, private readonly Closure $select) {}

    public function execute(?array $params = null): bool
    {
        $this->rows = ($this->select)($this->sql, $params ?? []);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

final class TransferPackagePdo extends PDO
{
    public function __construct(private readonly Closure $select) {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/^SELECT\s/i', $query) !== 1) throw new RuntimeException('Offline lookup attempted a SQL write.');
        return new TransferPackageStatement($query, $this->select);
    }

    public function beginTransaction(): bool
    {
        throw new RuntimeException('Offline recipient lookup attempted a transaction.');
    }

    public function inTransaction(): bool { return false; }
}

final class TransferPackageFixture
{
    public const ID = '20002';
    public array $queries = [];
    public array $calls = [];
    public array $users;
    public array $response;
    public ?array $receipt = null;
    public bool $apiFailure = false;
    public readonly TransferPackagePdo $pdo;
    public readonly object $service;

    public function __construct(public readonly string $platform)
    {
        $max = $platform === 'max';
        $this->users = [$max
            ? ['max_user_id'=>self::ID, 'private_chat_id'=>'30003', 'username'=>'CachedName', 'is_active'=>1]
            : ['telegram_id'=>(int)self::ID, 'username'=>'CachedName', 'is_active'=>1]];
        $this->response = $max
            ? ['chat_id'=>30003, 'type'=>'dialog', 'status'=>'active',
                'dialog_with_user'=>['user_id'=>(int)self::ID, 'username'=>'FreshTarget', 'first_name'=>'Fresh', 'is_bot'=>false]]
            : ['ok'=>true, 'result'=>['id'=>(int)self::ID, 'type'=>'private', 'username'=>'FreshTarget', 'first_name'=>'Fresh']];
        $this->pdo = new TransferPackagePdo(function (string $sql, array $params) use ($max): array {
            $this->queries[] = [$sql, $params];
            if (str_contains($sql, ' FROM ' . ($max ? 'posted_max_users' : 'posted_users') . ' ')) return $this->users;
            if (str_contains($sql, ' FROM posted_app_settings ')) {
                $key = ($max ? 'max' : 'telegram') . '_transfer_receipt_' . self::ID;
                transferPackageAssert($params === [$key], 'Receipt escaped its platform and source account.');
                return $this->receipt === null ? [] : [['setting_value'=>json_encode($this->receipt, JSON_THROW_ON_ERROR)]];
            }
            throw new RuntimeException('Lookup reached an unexpected SQL table.');
        });
        $transport = function (string $method, string|array $request, ?array $body = null) use ($max): array {
            $this->calls[] = [$method, $request, $body];
            transferPackageAssert($max
                ? $method === 'GET' && $request === '/chats/30003' && $body === null
                : $method === 'getChat' && $request === ['chat_id'=>(int)self::ID] && $body === null,
                'Recipient resolution attempted an unexpected API operation.');
            if ($this->apiFailure) throw new RuntimeException('Captured provider failure.');
            return $this->response;
        };
        $this->service = $max ? new PostedMaxAccountTransfer($this->pdo, $transport)
            : new PostedTelegramAccountTransfer($this->pdo, $transport);
    }
}

$tests = [];
$tests['fresh package loads both transfer services and bot UI without installed configuration'] = static function (): void {
    $previous = getenv('POSTED_CONFIG_FILE');
    $missing = sys_get_temp_dir() . '/gitposted-unconfigured-' . bin2hex(random_bytes(12)) . '.php';
    transferPackageAssert(!file_exists($missing), 'Unexpected fixture path collision.');
    putenv('POSTED_CONFIG_FILE=' . $missing);
    try {
        require_once dirname(__DIR__) . '/payload/src/max-bootstrap.php';
        require_once dirname(__DIR__) . '/bin/application.php';
    } finally {
        putenv($previous === false ? 'POSTED_CONFIG_FILE' : 'POSTED_CONFIG_FILE=' . $previous);
    }
    foreach (['PostedTelegramAccountTransfer','PostedMaxAccountTransfer'] as $name) {
        $class = new ReflectionClass($name);
        foreach (['resolveRecipient','preview','transfer','completedTransfer'] as $method) {
            transferPackageAssert($class->getMethod($method)->isPublic(), 'Missing public transfer operation: ' . $method);
        }
    }
    foreach ([['PostedBot','GitPostedTelegramAccountTransferUi','handleTelegramAccountTransferCallback',[20002,'settings']],
        ['GitPostedMaxBot','GitPostedMaxAccountTransferUi','handleMaxAccountTransferCallback',['fixture-callback','20002','gp:settings']]] as [$bot,$trait,$handler,$args]) {
        $class = new ReflectionClass($bot);
        transferPackageAssert(in_array($trait, $class->getTraitNames(), true), 'Packaged bot omitted its transfer UI.');
        // An unrelated button must pass through without accessing uninitialized bot state.
        transferPackageAssert($class->getMethod($handler)->invokeArgs($class->newInstanceWithoutConstructor(), $args) === false,
            'Transfer handler captured another menu callback.');
    }
};
$tests['service constructors need neither a database connection nor platform credentials'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        $fixture = new TransferPackageFixture($platform);
        transferPackageAssert($fixture->queries === [] && $fixture->calls === [], 'Construction performed external work.');
    }
};
$tests['numeric recipient uses the exact fresh private peer rather than cached username'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        $fixture = new TransferPackageFixture($platform);
        $recipient = $fixture->service->resolveRecipient(TransferPackageFixture::ID);
        transferPackageAssert((string)$recipient[$platform === 'max' ? 'max_user_id' : 'telegram_id'] === TransferPackageFixture::ID
            && $recipient['username'] === 'FreshTarget', 'Recipient identity came from stale or different data.');
        transferPackageAssert(count($fixture->queries) === 1 && count($fixture->calls) === 1, 'Unexpected recipient lookup effects.');
    }
};
$tests['invalid references self transfer and invalid confirmation fail before external work'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        $fixture = new TransferPackageFixture($platform);
        foreach (['','0','-7','https://example.test/account','@xy','@bad-name','123abc',str_repeat('9',20)] as $input) {
            transferPackageRejects(static fn() => $fixture->service->resolveRecipient($input));
        }
        $id = $platform === 'max' ? TransferPackageFixture::ID : (int)TransferPackageFixture::ID;
        $other = $platform === 'max' ? '20003' : 20003;
        transferPackageRejects(static fn() => $fixture->service->preview($id, $id));
        transferPackageRejects(static fn() => $fixture->service->transfer($id, $other, 'invalid', 'invalid'));
        transferPackageAssert($fixture->queries === [] && $fixture->calls === [], 'Invalid input reached SQL or API.');
    }
};
$tests['fresh identity mismatch inaccessible dialog and provider failure reject the recipient'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        $cases = $platform === 'max' ? ['peer','chat','type','inactive','bot','missing-bot','string-bot','api-failure'] : ['peer','type','not-ok','api-failure'];
        foreach ($cases as $case) {
            $fixture = new TransferPackageFixture($platform);
            if ($platform === 'max') {
                if ($case === 'peer') $fixture->response['dialog_with_user']['user_id'] = 20003;
                if ($case === 'chat') $fixture->response['chat_id'] = 30004;
                if ($case === 'type') $fixture->response['type'] = 'channel';
                if ($case === 'inactive') $fixture->response['status'] = 'removed';
                if ($case === 'bot') $fixture->response['dialog_with_user']['is_bot'] = true;
                if ($case === 'missing-bot') unset($fixture->response['dialog_with_user']['is_bot']);
                if ($case === 'string-bot') $fixture->response['dialog_with_user']['is_bot'] = 'false';
            } else {
                if ($case === 'peer') $fixture->response['result']['id'] = 20003;
                if ($case === 'type') $fixture->response['result']['type'] = 'supergroup';
                if ($case === 'not-ok') $fixture->response['ok'] = false;
            }
            $fixture->apiFailure = $case === 'api-failure';
            transferPackageRejects(static fn() => $fixture->service->resolveRecipient(TransferPackageFixture::ID));
            transferPackageAssert(count($fixture->queries) === 1 && count($fixture->calls) === 1, 'Failed identity caused extra effects.');
        }
    }
};
$tests['unknown inactive or unstarted local accounts never reach a platform API'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        foreach (['unknown','inactive',...($platform === 'max' ? ['unstarted'] : [])] as $case) {
            $fixture = new TransferPackageFixture($platform);
            if ($case === 'unknown') $fixture->users = [];
            if ($case === 'inactive') $fixture->users[0]['is_active'] = 0;
            if ($case === 'unstarted') $fixture->users[0]['private_chat_id'] = '';
            transferPackageRejects(static fn() => $fixture->service->resolveRecipient(TransferPackageFixture::ID));
            transferPackageAssert(count($fixture->queries) === 1 && $fixture->calls === [], 'Unavailable account reached API.');
        }
    }
};
$tests['username resolution rechecks its current owner and refuses a reused cached name'] = static function (): void {
    foreach (['telegram','max'] as $platform) {
        $fixture = new TransferPackageFixture($platform);
        transferPackageRejects(static fn() => $fixture->service->resolveRecipient('@CachedName'));
        $fresh = new TransferPackageFixture($platform);
        $fresh->users[0]['username'] = 'FreshTarget';
        $recipient = $fresh->service->resolveRecipient('@FreshTarget');
        transferPackageAssert($recipient['username'] === 'FreshTarget' && count($fresh->calls) === 1, 'Fresh username did not resolve uniquely.');
    }
};
$tests['durable completion receipts use the existing schema and exact platform source nonce'] = static function (): void {
    $tables = (new ReflectionClass(InstallerApplication::class))->getConstant('TABLES');
    transferPackageAssert(is_array($tables) && count($tables) === 33 && count(array_unique($tables)) === 33
        && in_array('posted_app_settings', $tables, true), 'Fresh installer schema omitted shared receipt storage.');
    foreach (['telegram','max'] as $platform) {
        $fixture = new TransferPackageFixture($platform);
        $nonce = str_repeat('a',24);
        $source = $platform === 'max' ? TransferPackageFixture::ID : (int)TransferPackageFixture::ID;
        $fixture->receipt = ['source_id'=>$source, 'target_id'=>$platform === 'max' ? '20003' : 20003,
            'token'=>$nonce, 'result'=>['posts'=>3,'channels'=>1]];
        transferPackageAssert($fixture->service->completedTransfer($source, $nonce) === $fixture->receipt,
            'Durable receipt did not survive a separate lookup.');
        transferPackageAssert($fixture->service->completedTransfer($source, str_repeat('b',24)) === null,
            'A different confirmation nonce reused a completion receipt.');
        $fixture->receipt['source_id'] = $platform === 'max' ? '20004' : 20004;
        transferPackageAssert($fixture->service->completedTransfer($source, $nonce) === null && $fixture->calls === [],
            'Receipt belonging to another source account was accepted or sent externally.');
    }
};

$passed = 0;
foreach ($tests as $name => $test) {
    try { $test(); ++$passed; echo 'PASS ', $name, PHP_EOL; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL); exit(1); }
}
echo 'account transfer package tests passed: ', $passed, PHP_EOL;
