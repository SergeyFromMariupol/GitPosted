<?php

declare(strict_types=1);

const POSTED_WEBHOOK_DEDUP_TTL = 604800;

function posted_webhook_storage(string $directory): string
{
    $config = PostedConfig::get();
    $dataRoot = trim((string) (getenv('POSTED_DATA_DIR') ?: ($config['data_dir'] ?? '/var/lib/gitposted')));
    if ($dataRoot === '') {
        throw new RuntimeException('GitPosted data directory is not configured');
    }
    $path = $dataRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $directory;
    if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Private storage unavailable');
    }
    return $path;
}

/**
 * Remember only non-secret hashes of former tokens for the lifetime of old
 * completion markers. The numeric bot identity remains stable on rotation.
 * This helper can also seed the index before deploying the new entry point.
 *
 * @return array{stable_fingerprint:string,legacy_fingerprints:list<string>}
 */
function posted_webhook_bot_namespaces(string $botToken): array
{
    if (preg_match('/\A([0-9]{6,12}):[A-Za-z0-9_-]{30,200}\z/D', $botToken, $match) !== 1
        || (int) $match[1] <= 0) {
        throw new RuntimeException('Configured Telegram Bot API token is invalid');
    }
    $botId = (string) (int) $match[1];
    $stable = hash('sha256', 'telegram-bot-id|' . $botId);
    $legacy = hash('sha256', $botToken);
    $directory = posted_webhook_storage('posted_webhook_updates');
    $indexPath = $directory . DIRECTORY_SEPARATOR . '.bot-' . $stable . '.namespaces.json';
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.bot-' . $stable . '.namespaces.lock';
    if (is_link($indexPath) || is_link($lockPath)) {
        throw new RuntimeException('Telegram deduplication index unavailable');
    }
    $handle = @fopen($lockPath, 'c+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('Telegram deduplication index unavailable');
    }
    $temporary = null;
    try {
        if (!@chmod($lockPath, 0600) || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Telegram deduplication index lock unavailable');
        }
        $fingerprints = [];
        $previous = null;
        if (file_exists($indexPath)) {
            if (!is_file($indexPath)) {
                throw new RuntimeException('Telegram deduplication index unavailable');
            }
            $input = @fopen($indexPath, 'rb');
            if (!is_resource($input)) {
                throw new RuntimeException('Telegram deduplication index unavailable');
            }
            try {
                $raw = stream_get_contents($input, 1048577);
            } finally {
                fclose($input);
            }
            if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
                throw new RuntimeException('Telegram deduplication index is invalid');
            }
            try {
                $previous = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Telegram deduplication index is invalid', 0, $exception);
            }
            if (!is_array($previous) || ($previous['version'] ?? null) !== 1
                || ($previous['bot_id'] ?? null) !== $botId || !is_array($previous['fingerprints'] ?? null)) {
                throw new RuntimeException('Telegram deduplication index is invalid');
            }
            $fingerprints = $previous['fingerprints'];
        }
        $now = time();
        foreach ($fingerprints as $fingerprint => $lastSeen) {
            if (!is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
                || !is_int($lastSeen) || $lastSeen < 1 || $lastSeen > $now) {
                throw new RuntimeException('Telegram deduplication index is invalid');
            }
            if ($lastSeen < $now - POSTED_WEBHOOK_DEDUP_TTL) {
                unset($fingerprints[$fingerprint]);
            }
        }
        $fingerprints[$legacy] = $now;
        ksort($fingerprints, SORT_STRING);
        $next = ['version' => 1, 'bot_id' => $botId, 'fingerprints' => $fingerprints];
        if ($next !== $previous) {
            $payload = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $indexPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $output = @fopen($temporary, 'xb');
            if (!is_resource($output)) {
                throw new RuntimeException('Cannot persist Telegram deduplication index');
            }
            try {
                if (!@chmod($temporary, 0600) || fwrite($output, $payload) !== strlen($payload) || !fflush($output)
                    || (function_exists('fsync') && !fsync($output))) {
                    throw new RuntimeException('Cannot persist Telegram deduplication index');
                }
            } finally {
                fclose($output);
            }
            if (!@rename($temporary, $indexPath)) {
                throw new RuntimeException('Cannot finalize Telegram deduplication index');
            }
            $temporary = null;
        }
        return ['stable_fingerprint' => $stable, 'legacy_fingerprints' => array_keys($fingerprints)];
    } finally {
        if (is_string($temporary) && is_file($temporary)) {
            @unlink($temporary);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @return array{handle:resource,done_path:string,lock_path:string,legacy_claims?:array}|null */
function posted_webhook_claim_for_bot(int $updateId, string $botToken): ?array
{
    if ($updateId < 1) {
        throw new InvalidArgumentException('Invalid Telegram update identity');
    }
    $namespaces = posted_webhook_bot_namespaces($botToken);
    // New workers all take the stable lock first. Legacy workers take only
    // their token lock, so they can finish without waiting on a new worker.
    $stable = posted_webhook_claim($updateId, $namespaces['stable_fingerprint']);
    $legacyClaims = [];
    $alreadyCompleted = $stable === null;
    try {
        foreach ($namespaces['legacy_fingerprints'] as $fingerprint) {
            $legacy = posted_webhook_claim($updateId, $fingerprint);
            if ($legacy === null) {
                $alreadyCompleted = true;
            } else {
                $legacyClaims[] = $legacy;
            }
        }
        if ($alreadyCompleted) {
            // Promote old completions and protect any overlapping old worker.
            foreach ($legacyClaims as $legacy) {
                posted_webhook_complete($legacy);
            }
            if ($stable !== null) {
                posted_webhook_complete($stable);
            }
            return null;
        }
        $stable['legacy_claims'] = $legacyClaims;
        return $stable;
    } catch (Throwable $exception) {
        foreach ($legacyClaims as $legacy) {
            posted_webhook_release($legacy);
        }
        if ($stable !== null) {
            posted_webhook_release($stable);
        }
        throw $exception;
    }
}

/** @return array{handle:resource,done_path:string,lock_path:string}|null */
function posted_webhook_claim(int $updateId, string $botFingerprint): ?array
{
    if ($botFingerprint === '') {
        throw new RuntimeException('Telegram bot fingerprint unavailable');
    }
    $directory = posted_webhook_storage('posted_webhook_updates');
    $base = $directory . DIRECTORY_SEPARATOR . hash('sha256', 'telegram|' . $botFingerprint . '|' . $updateId);
    $donePath = $base . '.done';
    $lockPath = $base . '.lock';
    $handle = @fopen($lockPath, 'c+b');
    if (!is_resource($handle) || !@chmod($lockPath, 0600) || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Webhook update lock unavailable');
    }
    if (is_file($donePath)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return null;
    }
    if (random_int(1, 100) === 1) {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.done') ?: [] as $candidate) {
            if (is_file($candidate) && filemtime($candidate) < time() - POSTED_WEBHOOK_DEDUP_TTL) {
                $oldLock = substr($candidate, 0, -5) . '.lock';
                $oldHandle = @fopen($oldLock, 'c+b');
                if (is_resource($oldHandle) && flock($oldHandle, LOCK_EX | LOCK_NB)) {
                    if (is_file($candidate) && filemtime($candidate) < time() - POSTED_WEBHOOK_DEDUP_TTL) @unlink($candidate);
                    flock($oldHandle, LOCK_UN);
                    fclose($oldHandle);
                    @unlink($oldLock);
                } elseif (is_resource($oldHandle)) {
                    fclose($oldHandle);
                }
            }
        }
    }
    return ['handle' => $handle, 'done_path' => $donePath, 'lock_path' => $lockPath];
}

/** @param array{handle:resource,done_path:string,lock_path:string,legacy_claims?:array} $claim */
function posted_webhook_complete(array $claim): void
{
    $handle = $claim['handle'] ?? null;
    $donePath = (string) ($claim['done_path'] ?? '');
    if (!is_resource($handle) || $donePath === '') {
        throw new RuntimeException('Invalid webhook update claim');
    }
    $temporary = $donePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        // Mark old token namespaces before releasing the stable identity lock.
        foreach ((array) ($claim['legacy_claims'] ?? []) as $legacy) {
            posted_webhook_complete($legacy);
        }
        $output = @fopen($temporary, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('Cannot persist webhook update result');
        }
        try {
            $payload = (string) time();
            if (!@chmod($temporary, 0600) || fwrite($output, $payload) !== strlen($payload) || !fflush($output)
                || (function_exists('fsync') && !fsync($output))) {
                throw new RuntimeException('Cannot persist webhook update result');
            }
        } finally {
            fclose($output);
        }
        if (!@rename($temporary, $donePath)) {
            throw new RuntimeException('Cannot finalize webhook update result');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
        foreach ((array) ($claim['legacy_claims'] ?? []) as $legacy) {
            posted_webhook_release($legacy);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @param array{handle:resource,done_path:string,lock_path:string,legacy_claims?:array} $claim */
function posted_webhook_release(array $claim): void
{
    foreach ((array) ($claim['legacy_claims'] ?? []) as $legacy) {
        posted_webhook_release($legacy);
    }
    $handle = $claim['handle'] ?? null;
    if (!is_resource($handle)) return;
    flock($handle, LOCK_UN);
    fclose($handle);
}
