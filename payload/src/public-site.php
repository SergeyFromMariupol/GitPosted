<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Return only validated public bot links. Secrets and arbitrary config values
 * never reach the page. A missing МАКС username intentionally disables its CTA.
 *
 * @return array{telegram: ?string, max: ?string}
 */
function gitpostedPublicBotLinks(): array
{
    $links = ['telegram' => null, 'max' => null];

    try {
        $config = PostedConfig::get();

        $telegramUsername = ltrim(trim((string) ($config['bot_username'] ?? '')), '@');
        if (($config['telegram_bot_enabled'] ?? false) === true && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $telegramUsername) === 1) {
            $links['telegram'] = 'https://t.me/' . rawurlencode($telegramUsername);
        }

        $maxUsername = ltrim(trim((string) ($config['max_bot_username'] ?? '')), '@');
        if (($config['max_bot_enabled'] ?? false) === true && preg_match('/^[A-Za-z0-9_]{3,128}$/D', $maxUsername) === 1) {
            $links['max'] = 'https://max.ru/' . rawurlencode($maxUsername);
        }
    } catch (Throwable) {
        // Leave both CTAs unavailable until a valid local config can be read.
    }

    return $links;
}

/**
 * @return array{users: ?int, channels: ?int, posts: ?int, live: bool}
 */
function gitpostedPublicStats(): array
{
    $fallback = ['users' => null, 'channels' => null, 'posts' => null, 'live' => false];
    try {
        $config = PostedConfig::get();
        $pdo = PostedDb::pdo($config);
        $stmt = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM posted_users) AS users,
                (SELECT COUNT(*) FROM posted_channels WHERE is_active = 1) AS channels,
                (SELECT COUNT(*) FROM posted_posts WHERE status IN ('sent', 'scheduled')) AS posts"
        );
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return $fallback;
        }
        $stats = [];
        foreach (['users', 'channels', 'posts'] as $key) {
            $value = $row[$key] ?? null;
            if (!is_numeric($value) || (int) $value < 0) {
                return $fallback;
            }
            $stats[$key] = (int) $value;
        }

        /*
         * MAX is installed independently from the original Telegram schema.
         * Until all three public-counter tables exist, Telegram remains a
         * valid live source. Linked MAX accounts are excluded because their
         * Telegram identity is already counted above.
         */
        try {
            $maxTables = (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN (
                       'posted_max_users',
                       'posted_max_channels',
                       'posted_max_posts'
                   )"
            )->fetchColumn();
            if ($maxTables === 3) {
                $maxRow = $pdo->query(
                    "SELECT
                        (SELECT COUNT(*) FROM posted_max_users WHERE linked_telegram_id IS NULL) AS users,
                        (SELECT COUNT(*) FROM posted_max_channels WHERE is_active = 1) AS channels,
                        (SELECT COUNT(*) FROM posted_max_posts WHERE status IN ('sent', 'scheduled')) AS posts"
                )->fetch();
                if (is_array($maxRow)) {
                    foreach (['users', 'channels', 'posts'] as $key) {
                        $value = $maxRow[$key] ?? null;
                        if (is_numeric($value) && (int) $value >= 0) {
                            $stats[$key] += (int) $value;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // A missing or not-yet-migrated MAX schema must not break the site.
        }

        return [
            'users' => $stats['users'],
            'channels' => $stats['channels'],
            'posts' => $stats['posts'],
            'live' => true,
        ];
    } catch (Throwable) {
        return $fallback;
    }
}
