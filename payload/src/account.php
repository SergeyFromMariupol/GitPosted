<?php

declare(strict_types=1);

/**
 * Canonical account shared by one Telegram identity and one MAX identity.
 */
final class PostedAccounts
{
    private const PLATFORMS = ['telegram', 'max'];

    /** @var array<int,bool> */
    private static array $schemaEnsured = [];

    public static function installSchema(PDO $pdo): void
    {
        $connectionId = spl_object_id($pdo);
        if (isset(self::$schemaEnsured[$connectionId])) {
            return;
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException(
                'Canonical account schema must be installed before opening a transaction.'
            );
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                merged_into_account_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_posted_accounts_merged (merged_into_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posted_account_identities (
                account_id BIGINT UNSIGNED NOT NULL,
                platform ENUM('telegram','max') NOT NULL,
                external_user_id VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (platform,external_user_id),
                UNIQUE KEY uq_posted_account_platform (account_id,platform),
                KEY idx_posted_account_identities_account (account_id),
                CONSTRAINT fk_posted_account_identities_account
                    FOREIGN KEY (account_id) REFERENCES posted_accounts(id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaEnsured[$connectionId] = true;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $connectionId = spl_object_id($pdo);
        if (isset(self::$schemaEnsured[$connectionId])) {
            return;
        }
        try {
            $pdo->query('SELECT 1 FROM posted_accounts LIMIT 1');
            $pdo->query('SELECT 1 FROM posted_account_identities LIMIT 1');
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Canonical account schema is not installed; run setup.php from CLI.',
                0,
                $exception
            );
        }
        self::$schemaEnsured[$connectionId] = true;
    }

    public static function forTelegram(PDO $pdo, int $telegramId): int
    {
        if ($telegramId <= 0) {
            throw new InvalidArgumentException('Invalid Telegram identity.');
        }
        self::ensureSchema($pdo);
        return self::accountForIdentity($pdo, 'telegram', (string) $telegramId);
    }

    public static function forMax(PDO $pdo, string $maxId): int
    {
        self::ensureSchema($pdo);
        return self::accountForIdentity(
            $pdo,
            'max',
            self::normalizeExternalId($maxId, 'MAX')
        );
    }

    public static function linkTelegramMax(
        PDO $pdo,
        int $telegramId,
        string $maxId
    ): int {
        if ($telegramId <= 0) {
            throw new InvalidArgumentException('Invalid Telegram identity.');
        }
        $maxId = self::normalizeExternalId($maxId, 'MAX');
        self::ensureSchema($pdo);

        $telegramAccount = self::accountForIdentity(
            $pdo,
            'telegram',
            (string) $telegramId
        );
        $maxAccount = self::accountForIdentity($pdo, 'max', $maxId);
        if ($telegramAccount === $maxAccount) {
            return $telegramAccount;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $telegramAccount = self::canonicalAccountId(
                $pdo,
                $telegramAccount,
                true
            );
            $maxAccount = self::canonicalAccountId($pdo, $maxAccount, true);
            if ($telegramAccount === $maxAccount) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $telegramAccount;
            }

            $lockedIds = [$telegramAccount, $maxAccount];
            sort($lockedIds, SORT_NUMERIC);
            $lock = $pdo->prepare(
                'SELECT id FROM posted_accounts WHERE id IN (?,?) ORDER BY id FOR UPDATE'
            );
            $lock->execute($lockedIds);
            if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
                throw new RuntimeException(
                    'Canonical account disappeared during linking.'
                );
            }

            self::assertIdentityCompatible(
                $pdo,
                $telegramAccount,
                'telegram',
                (string) $telegramId
            );
            self::assertIdentityCompatible(
                $pdo,
                $telegramAccount,
                'max',
                $maxId
            );
            self::assertIdentityCompatible(
                $pdo,
                $maxAccount,
                'telegram',
                (string) $telegramId
            );
            self::assertIdentityCompatible(
                $pdo,
                $maxAccount,
                'max',
                $maxId
            );

            $move = $pdo->prepare(
                "UPDATE posted_account_identities
                 SET account_id=:target
                 WHERE account_id=:source AND platform='max'
                   AND external_user_id=:external_id"
            );
            $move->execute([
                'target' => $telegramAccount,
                'source' => $maxAccount,
                'external_id' => $maxId,
            ]);
            if ($move->rowCount() !== 1) {
                throw new RuntimeException('MAX identity disappeared during linking.');
            }

            $merged = $pdo->prepare(
                'UPDATE posted_accounts
                 SET merged_into_account_id=:target
                 WHERE id=:source AND merged_into_account_id IS NULL'
            );
            $merged->execute([
                'target' => $telegramAccount,
                'source' => $maxAccount,
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $telegramAccount;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function telegramId(PDO $pdo, int $accountId): ?int
    {
        $externalId = self::identityId($pdo, $accountId, 'telegram');
        if ($externalId === null || preg_match('/^[1-9][0-9]*$/D', $externalId) !== 1) {
            return null;
        }
        $telegramId = (int) $externalId;
        return $telegramId > 0 ? $telegramId : null;
    }

    public static function maxId(PDO $pdo, int $accountId): ?string
    {
        return self::identityId($pdo, $accountId, 'max');
    }

    private static function accountForIdentity(
        PDO $pdo,
        string $platform,
        string $externalId
    ): int {
        self::assertPlatform($platform);
        $existing = self::identityAccount($pdo, $platform, $externalId);
        if ($existing !== null) {
            return self::canonicalAccountId($pdo, $existing);
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $existing = self::identityAccount(
                $pdo,
                $platform,
                $externalId,
                true
            );
            if ($existing !== null) {
                $accountId = self::canonicalAccountId($pdo, $existing, true);
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $accountId;
            }

            $pdo->exec('INSERT INTO posted_accounts(created_at) VALUES(NOW())');
            $accountId = (int) $pdo->lastInsertId();
            if ($accountId <= 0) {
                throw new RuntimeException('Unable to create canonical account.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO posted_account_identities(
                    account_id,platform,external_user_id
                 ) VALUES(:account,:platform,:external_id)'
            );
            $insert->execute([
                'account' => $accountId,
                'platform' => $platform,
                'external_id' => $externalId,
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $accountId;
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                $existing = self::identityAccount($pdo, $platform, $externalId);
                if ($existing !== null) {
                    return self::canonicalAccountId($pdo, $existing);
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function identityAccount(
        PDO $pdo,
        string $platform,
        string $externalId,
        bool $forUpdate = false
    ): ?int {
        $sql = 'SELECT account_id FROM posted_account_identities
                WHERE platform=:platform AND external_user_id=:external_id
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'platform' => $platform,
            'external_id' => $externalId,
        ]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private static function identityId(
        PDO $pdo,
        int $accountId,
        string $platform
    ): ?string {
        self::ensureSchema($pdo);
        self::assertPlatform($platform);
        $accountId = self::canonicalAccountId($pdo, $accountId);
        $stmt = $pdo->prepare(
            'SELECT external_user_id FROM posted_account_identities
             WHERE account_id=:account AND platform=:platform LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'platform' => $platform]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private static function canonicalAccountId(
        PDO $pdo,
        int $accountId,
        bool $forUpdate = false
    ): int {
        if ($accountId <= 0) {
            throw new InvalidArgumentException('Invalid canonical account.');
        }
        $seen = [];
        for ($depth = 0; $depth < 32; $depth++) {
            if (isset($seen[$accountId])) {
                throw new RuntimeException('Canonical account merge cycle detected.');
            }
            $seen[$accountId] = true;
            $sql = 'SELECT merged_into_account_id FROM posted_accounts
                    WHERE id=:account LIMIT 1';
            if ($forUpdate) {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['account' => $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('Canonical account does not exist.');
            }
            $next = (int) ($row['merged_into_account_id'] ?? 0);
            if ($next <= 0) {
                return $accountId;
            }
            $accountId = $next;
        }
        throw new RuntimeException('Canonical account merge chain is too deep.');
    }

    private static function assertIdentityCompatible(
        PDO $pdo,
        int $accountId,
        string $platform,
        string $expectedExternalId
    ): void {
        $stmt = $pdo->prepare(
            'SELECT external_user_id FROM posted_account_identities
             WHERE account_id=:account AND platform=:platform LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'platform' => $platform]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false && !hash_equals((string) $existing, $expectedExternalId)) {
            throw new RuntimeException(
                'Canonical account already contains another ' . $platform . ' identity.'
            );
        }
    }

    private static function assertPlatform(string $platform): void
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('Unsupported account platform.');
        }
    }

    private static function normalizeExternalId(string $externalId, string $label): string
    {
        $externalId = trim($externalId);
        if (
            $externalId === ''
            || strlen($externalId) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $externalId) === 1
        ) {
            throw new InvalidArgumentException('Invalid ' . $label . ' identity.');
        }
        return $externalId;
    }
}
