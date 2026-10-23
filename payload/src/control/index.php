<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/admin-auth-policy.php';
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://hcaptcha.com https://*.hcaptcha.com; style-src 'self' 'unsafe-inline' https://hcaptcha.com https://*.hcaptcha.com; img-src 'self' data:; font-src 'self'; connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com; frame-src https://hcaptcha.com https://*.hcaptcha.com; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

$config = PostedConfig::get();
$appConfigPath = trim((string) (getenv('POSTED_CONFIG_FILE') ?: '/etc/gitposted/config.php'));
$baseUrl = rtrim((string) ($config['base_url'] ?? 'https://example.com'), '/');
$siteHostname = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'example.com'));
$hCaptchaHostnames = array_values(array_unique([
    $siteHostname,
    str_starts_with($siteHostname, 'www.')
        ? substr($siteHostname, 4)
        : 'www.' . $siteHostname,
]));
$dataDirectory = rtrim((string) ($config['data_dir'] ?? '/var/lib/gitposted'), '/\\');
$adminAuth = $config['admin_auth'] ?? null;
if (!is_array($adminAuth)) { throw new RuntimeException('Admin credentials are invalid.'); }
$hCaptchaSettings = is_array($config['hcaptcha'] ?? null)
    ? $config['hcaptcha']
    : [];
$hCaptchaRequired = gitpostedAdminCaptchaRequired($hCaptchaSettings);
$hCaptchaSiteKey = trim((string) ($hCaptchaSettings['sitekey'] ?? ''));
$hCaptchaReady =
    preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/Di', $hCaptchaSiteKey) === 1
    && preg_match('/\AES_[A-Za-z0-9_-]{20,128}\z/D', trim((string) ($hCaptchaSettings['secret'] ?? ''))) === 1;
session_name('posted_admin');
session_set_cookie_params(['lifetime'=>0,'path'=>'/control/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adminPage(mixed $value): string {
    $page = trim((string) $value);
    if ($page === 'bots') {
        return 'telegram';
    }
    if ($page === 'users') {
        return 'telegram-data';
    }
    $allowed = [
        'overview', 'telegram', 'max', 'website',
        'telegram-data', 'max-data', 'system',
    ];
    return in_array($page, $allowed, true) ? $page : 'overview';
}
function go(string $message = '', ?string $page = null): never {
    if ($message !== '') $_SESSION['flash'] = $message;
    $target = adminPage($page ?? ($_POST['return_page'] ?? $_GET['page'] ?? 'overview'));
    header('Location: /control/?page=' . rawurlencode($target), true, 303);
    exit;
}
function adminConfigWritableTarget(string $path, string $dataDirectory): string {
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Защищённый файл конфигурации недоступен.');
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || is_link($resolved)) {
        throw new RuntimeException('Защищённый файл конфигурации недоступен.');
    }
    if (!is_link($path)) {
        if (!hash_equals($resolved, $path)) {
            throw new RuntimeException('Путь конфигурации не является каноническим.');
        }
        return $resolved;
    }

    $dataTarget = realpath($dataDirectory);
    if ($dataTarget === false || !is_dir($dataTarget)) {
        throw new RuntimeException('Каталог данных конфигурации недоступен.');
    }
    $tenant = basename($dataTarget);
    $privateRoot = dirname(dirname($dataTarget));
    $expectedDirectory = realpath(
        $privateRoot . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . $tenant
    );
    if (
        $tenant === ''
        || $expectedDirectory === false
        || !is_dir($expectedDirectory)
        || !hash_equals($expectedDirectory, dirname($resolved))
        || basename($resolved) !== 'config.php'
    ) {
        throw new RuntimeException('Ссылка конфигурации ведёт за пределы защищённого каталога.');
    }
    return $resolved;
}
/** @return resource Lock lives until the admin request finishes. */
function adminAcquireConfigOperationLock(string $dataDirectory): mixed {
    $dataRoot = realpath($dataDirectory);
    $storage = realpath($dataDirectory . '/storage');
    if ($dataRoot === false || $storage === false || !is_dir($storage)
        || !hash_equals($dataRoot, dirname($storage)) || basename($storage) !== 'storage') {
        throw new RuntimeException('Защищённый каталог настроек недоступен.');
    }
    $path = $storage . '/admin-config.lock';
    if (is_link($path)) {
        throw new RuntimeException('Защищённая блокировка настроек недоступна.');
    }
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Не удалось заблокировать изменение настроек.');
    }
    try {
        if (!@chmod($path, 0600)) {
            throw new RuntimeException('Не удалось защитить блокировку настроек.');
        }
        clearstatcache(true, $path);
        if (((int) @fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Блокировка настроек получила небезопасные права.');
        }
        $deadline = microtime(true) + 10.0;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Настройки уже изменяются в другом окне. Повторите действие после его завершения.');
            }
            usleep(100000);
        }
        return $handle;
    } catch (Throwable $exception) {
        fclose($handle);
        throw $exception;
    }
}

function adminWriteConfig(string $path, array $config, string $dataDirectory): void {
    if (!is_resource($GLOBALS['adminConfigOperationLock'] ?? null)) {
        throw new RuntimeException('Изменение конфигурации требует блокировки настроек.');
    }
    $target = adminConfigWritableTarget($path, $dataDirectory);
    $directory = dirname($target);
    $temporary = $directory . '/.config-admin-' . bin2hex(random_bytes(12)) . '.php';
    $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
        . var_export($config, true) . ";\n";
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось подготовить защищённую конфигурацию.');
    }
    try {
        if (!@chmod($temporary, 0600)) {
            throw new RuntimeException('Не удалось защитить временную конфигурацию.');
        }
        clearstatcache(true, $temporary);
        if (((int) @fileperms($temporary) & 0777) !== 0600) {
            throw new RuntimeException('Временная конфигурация получила небезопасные права.');
        }
        $written = fwrite($handle, $payload);
        if ($written !== strlen($payload) || !fflush($handle)) {
            throw new RuntimeException('Не удалось записать защищённую конфигурацию.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Не удалось синхронизировать защищённую конфигурацию.');
        }
    } catch (Throwable $exception) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw $exception;
    } finally {
        fclose($handle);
    }
    try {
        $validated = require $temporary;
        if (!is_array($validated) || $validated !== $config) {
            throw new RuntimeException('Проверка защищённой конфигурации не пройдена.');
        }
        if (!hash_equals($target, adminConfigWritableTarget($path, $dataDirectory))) {
            throw new RuntimeException('Ссылка конфигурации изменилась во время сохранения.');
        }
        if (!@rename($temporary, $target)) {
            throw new RuntimeException('Не удалось применить защищённую конфигурацию.');
        }
        clearstatcache(true, $target);
        if (
            (((int) @fileperms($target) & 0777) !== 0600)
            && !@chmod($target, 0600)
        ) {
            throw new RuntimeException('Не удалось закрепить права защищённой конфигурации.');
        }
        clearstatcache(true, $target);
        if (((int) @fileperms($target) & 0777) !== 0600) {
            throw new RuntimeException('Защищённая конфигурация получила небезопасные права.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
            @opcache_invalidate($path, true);
        }
    } catch (Throwable $exception) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw $exception;
    }
}
function adminTelegramWebhookState(PostedTelegram $telegram, array $config, bool $enabled): void {
    if (!$enabled) {
        $telegram->call('deleteWebhook', ['drop_pending_updates' => false]);
        return;
    }
    $telegram->call('setWebhook', [
        'url' => rtrim((string) ($config['base_url'] ?? ''), '/') . '/webhook.php',
        'secret_token' => (string) ($config['webhook_secret'] ?? ''),
        'allowed_updates' => ['message', 'channel_post', 'edited_channel_post', 'callback_query', 'my_chat_member', 'chat_member'],
        'max_connections' => 1,
        'drop_pending_updates' => false,
    ]);
}

/** Persist a toggle and restore its former runtime/webhook state on failure. */
function adminToggleTelegram(PostedTelegram $telegram, array $config, bool $enabled, callable $persist): void {
    $next = $config;
    $next['telegram_bot_enabled'] = $enabled;
    try {
        adminTelegramWebhookState($telegram, $config, $enabled);
        $persist($next);
    } catch (Throwable $exception) {
        $restoredConfig = true;
        $restoredWebhook = true;
        try {
            $persist($config);
        } catch (Throwable) {
            $restoredConfig = false;
        }
        try {
            adminTelegramWebhookState($telegram, $config, ($config['telegram_bot_enabled'] ?? false) === true);
        } catch (Throwable) {
            $restoredWebhook = false;
        }
        throw new RuntimeException(
            'Переключение Telegram не завершено. '
                . ($restoredConfig && $restoredWebhook
                    ? 'Прежняя конфигурация и webhook восстановлены.'
                    : 'Проверьте состояние Telegram: восстановить конфигурацию или webhook полностью не удалось.'),
            0,
            $exception
        );
    }
}

/** A failed cleanup of a retired token must not undo a working replacement. */
function adminConfigureTelegram(
    PostedTelegram $telegram,
    array $config,
    array $next,
    string $newBotId,
    callable $persist
): string {
    $tokenChanged = !hash_equals((string) ($config['bot_token'] ?? ''), (string) ($next['bot_token'] ?? ''));
    $next['telegram_bot_enabled'] = true;
    $webhookAttempted = false;
    try {
        $persist($next);
        $webhookAttempted = true;
        adminTelegramWebhookState($telegram, $next, true);
    } catch (Throwable $exception) {
        $restored = true;
        if ($tokenChanged && $webhookAttempted) {
            try {
                adminTelegramWebhookState($telegram, $next, false);
            } catch (Throwable) {
                $restored = false;
            }
        }
        try {
            $persist($config);
        } catch (Throwable) {
            $restored = false;
        }
        if ($webhookAttempted && trim((string) ($config['bot_token'] ?? '')) !== '') {
            try {
                adminTelegramWebhookState(new PostedTelegram((string) $config['bot_token']), $config, ($config['telegram_bot_enabled'] ?? false) === true);
            } catch (Throwable) {
                $restored = false;
            }
        }
        throw new RuntimeException(
            'Настройки Telegram не применены. '
                . ($restored
                    ? 'Прежняя конфигурация и webhook сохранены.'
                    : 'Проверьте состояние Telegram: откат конфигурации или webhook завершился не полностью.'),
            0,
            $exception
        );
    }
    if ($tokenChanged && trim((string) ($config['bot_token'] ?? '')) !== '') {
        try {
            $oldTelegram = new PostedTelegram((string) $config['bot_token']);
            $oldMe = $oldTelegram->call('getMe');
            $oldBotId = trim((string) (($oldMe['result'] ?? [])['id'] ?? ''));
            if ($oldBotId === '' || $newBotId === '') {
                return ' Старый webhook не изменён: идентичность прежнего бота не подтверждена.';
            }
            if (!hash_equals($oldBotId, $newBotId)) {
                adminTelegramWebhookState($oldTelegram, $config, false);
            }
        } catch (Throwable) {
            return ' Новый бот работает. Прежний webhook не удалось проверить или отключить.';
        }
    }
    return '';
}

function adminStorageTimezone(array $config, mixed $submitted): string {
    $timezone = trim((string) $submitted);
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new RuntimeException('Некорректный часовой пояс.');
    }
    if (!hash_equals((string) ($config['timezone'] ?? 'Europe/Moscow'), $timezone)) {
        throw new RuntimeException('Часовой пояс хранения нельзя менять через панель: это сдвинет готовые публикации и таймеры. Часовой пояс отображения меняется в настройках бота.');
    }
    return $timezone;
}
function adminLinkAccounts(PDO $pdo, int $telegramId, string $maxId): void {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        PostedAccounts::linkTelegramMax($pdo, $telegramId, $maxId);
        $link = $pdo->prepare(
            'UPDATE posted_max_users SET linked_telegram_id=:telegram
             WHERE max_user_id=:max
               AND (linked_telegram_id IS NULL OR linked_telegram_id=:expected_telegram)'
        );
        $link->execute(['telegram' => $telegramId, 'max' => $maxId, 'expected_telegram' => $telegramId]);
        $check = $pdo->prepare('SELECT linked_telegram_id FROM posted_max_users WHERE max_user_id=:max LIMIT 1');
        $check->execute(['max' => $maxId]);
        $linked = $check->fetchColumn();
        if ($linked === false || $linked === null || (string) $linked !== (string) $telegramId) {
            throw new RuntimeException('Аккаунт МАКС исчез или уже связан с другим Telegram. Связь не сохранена.');
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
function adminBotUsername(mixed $value, string $label): string {
    $username = ltrim(trim((string) $value), '@');
    if (
        preg_match('/\A[A-Za-z0-9_]{5,32}\z/D', $username) !== 1
        || !str_ends_with(strtolower($username), 'bot')
    ) {
        throw new RuntimeException($label . ': укажите корректный username бота.');
    }
    return $username;
}
function adminSecretInput(mixed $value, string $label, int $minimum = 32): string {
    $secret = trim((string) $value);
    if ($secret !== '' && preg_match('/\A[A-Za-z0-9:_-]{' . $minimum . ',256}\z/D', $secret) !== 1) {
        throw new RuntimeException($label . ': недопустимый формат.');
    }
    return $secret;
}
function scalar(PDO $pdo, string $sql): int { return (int)$pdo->query($sql)->fetchColumn(); }
function adminWithChannelOperationLocks(
    PDO $pdo,
    string $cronLockName,
    string $channelLockName,
    callable $operation
): mixed {
    $cronLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
    $cronLock->execute(['lock_name'=>$cronLockName]);
    if ((int)($cronLock->fetchColumn() ?: 0) !== 1) {
        throw new RuntimeException('Завершается фоновая операция с каналами. Повторите действие.');
    }
    try {
        $channelLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
        $channelLock->execute(['lock_name'=>$channelLockName]);
        if ((int)($channelLock->fetchColumn() ?: 0) !== 1) {
            throw new RuntimeException('Канал занят другой операцией. Повторите действие.');
        }
        try {
            return $operation();
        } finally {
            $releaseChannelLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseChannelLock->execute(['lock_name'=>$channelLockName]);
        }
    } finally {
        $releaseCronLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseCronLock->execute(['lock_name'=>$cronLockName]);
    }
}

/** @return array<int,array<string,mixed>> */
function adminMaxSubscriptionRows(array $response): array
{
    if (array_key_exists('subscriptions',$response)) {
        if (!is_array($response['subscriptions'])) {
            throw new RuntimeException('МАКС вернул некорректный список webhook-подписок.');
        }
        $rows=$response['subscriptions'];
    } elseif (array_is_list($response)) {
        $rows=$response;
    } else {
        throw new RuntimeException('МАКС не вернул список webhook-подписок.');
    }
    return array_values(array_filter($rows,'is_array'));
}

function adminMaxSubscriptionCount(GitPostedMaxBot $app, string $url): int
{
    $count=0;
    foreach (adminMaxSubscriptionRows($app->subscriptions()) as $subscription) {
        if (hash_equals($url,trim((string)($subscription['url'] ?? '')))) {
            $count++;
        }
    }
    return $count;
}

/** @param array<string,mixed> $subscription */
function adminMaxSubscriptionIsCurrent(array $subscription, string $url): bool
{
    if (!hash_equals($url,trim((string)($subscription['url'] ?? '')))) {
        return false;
    }
    if (!isset($subscription['update_types']) || !is_array($subscription['update_types'])) {
        return false;
    }
    $actual=[];
    foreach ($subscription['update_types'] as $type) {
        if (!is_string($type) || trim($type)==='') {
            return false;
        }
        $actual[]=trim($type);
    }
    $actual=array_values(array_unique($actual));
    $expected=array_values(array_unique(GitPostedMaxBot::webhookUpdateTypes()));
    sort($actual,SORT_STRING);
    sort($expected,SORT_STRING);
    return $actual===$expected;
}

function adminMaxCurrentSubscriptionCount(GitPostedMaxBot $app, string $url): int
{
    $count=0;
    foreach (adminMaxSubscriptionRows($app->subscriptions()) as $subscription) {
        if (adminMaxSubscriptionIsCurrent($subscription,$url)) {
            $count++;
        }
    }
    return $count;
}

/** @return array<string,mixed> */
function adminCreateAndConfirmMaxSubscription(GitPostedMaxBot $app, string $url): array
{
    $result=$app->setupWebhook($url);
    if (adminMaxCurrentSubscriptionCount($app,$url) < 1) {
        throw new RuntimeException('МАКС не подтвердил URL и полный набор событий webhook-подписки.');
    }
    return $result;
}

function adminRemoveAndConfirmMaxSubscription(GitPostedMaxBot $app, string $url): void
{
    if (adminMaxSubscriptionCount($app,$url) === 0) {
        return;
    }
    $app->removeSubscription($url);
    if (adminMaxSubscriptionCount($app,$url) !== 0) {
        throw new RuntimeException('МАКС не подтвердил удаление webhook-подписки.');
    }
}

/** @param array<string,mixed> $post */
function adminRefundTelegramManagerQuota(
    PDO $pdo,
    array $post,
    bool $refundPostQuota,
    bool $refundPinQuota
): void {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Возврат лимита Telegram требует транзакцию.');
    }
    $refundPost = $refundPostQuota && !empty($post['manager_post_quota_charged']);
    $refundPin = $refundPinQuota && !empty($post['manager_pin_quota_charged']);
    if (!$refundPost && !$refundPin) {
        return;
    }

    $categoryId=(int)($post['manager_category_id'] ?? 0);
    if ($categoryId > 0) {
        $quota=$pdo->prepare(
            'UPDATE posted_manager_categories
             SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                 pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
             WHERE id=:category AND user_id=:user AND channel_id=:channel'
        );
        $quota->execute([
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'category'=>$categoryId,
            'user'=>(int)$post['author_id'],
            'channel'=>(int)$post['channel_id'],
        ]);
    } else {
        $quota=$pdo->prepare(
            'UPDATE posted_permissions
             SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                 pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
             WHERE user_id=:user AND channel_id=:channel'
        );
        $quota->execute([
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'user'=>(int)$post['author_id'],
            'channel'=>(int)$post['channel_id'],
        ]);
    }

    $clear=$pdo->prepare(
        'UPDATE posted_posts
         SET manager_post_quota_charged=IF(:refund_post=1,0,manager_post_quota_charged),
             manager_pin_quota_charged=IF(:refund_pin=1,0,manager_pin_quota_charged)
         WHERE id=:post'
    );
    $clear->execute([
        'refund_post'=>$refundPost ? 1 : 0,
        'refund_pin'=>$refundPin ? 1 : 0,
        'post'=>(int)$post['id'],
    ]);
}

function adminSettleTelegramChannelDetach(PDO $pdo, int $channelId): void
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Отключение Telegram-канала требует транзакцию.');
    }

    $activeAttempt=$pdo->prepare(
        "SELECT post_id
         FROM posted_telegram_publish_attempts
         WHERE channel_id=:channel
           AND (state<>'completed' OR gate_channel_id IS NOT NULL)
         LIMIT 1
         FOR UPDATE"
    );
    $activeAttempt->execute(['channel'=>$channelId]);
    if ($activeAttempt->fetchColumn() !== false) {
        throw new RuntimeException(
            'В канале есть незавершённая или требующая проверки публикация. Завершите её проверку перед отвязкой.'
        );
    }

    $pins=$pdo->prepare(
        "SELECT post.id,post.channel_id,post.author_id,post.manager_category_id,
                post.manager_post_quota_charged,post.manager_pin_quota_charged,
                attempt.pin_started_at,attempt.pin_delivery_uncertain,
                attempt.pin_started_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) AS pin_is_fresh
         FROM posted_telegram_publish_attempts attempt
         JOIN posted_posts post ON post.id=attempt.post_id
         WHERE attempt.channel_id=:channel
           AND attempt.state='completed'
           AND attempt.pin_completed_at IS NULL
         ORDER BY attempt.post_id
         FOR UPDATE"
    );
    $pins->execute(['channel'=>$channelId]);
    $pendingPins=$pins->fetchAll();
    foreach ($pendingPins as $pendingPin) {
        if (!empty($pendingPin['pin_started_at']) && !empty($pendingPin['pin_is_fresh'])) {
            throw new RuntimeException(
                'В канале завершается закрепление публикации. Повторите отвязку позже.'
            );
        }
    }
    foreach ($pendingPins as $pendingPin) {
        $postId=(int)$pendingPin['id'];
        $deliveryUnknown=!empty($pendingPin['pin_started_at'])
            || !empty($pendingPin['pin_delivery_uncertain']);
        if ($deliveryUnknown) {
            $pdo->prepare(
                "UPDATE posted_posts
                 SET error_text=CASE
                    WHEN status='sent' THEN :error
                    ELSE error_text
                 END
                 WHERE id=:post"
            )->execute([
                'error'=>'Результат закрепления неизвестен после прерванной операции; лимит закрепления сохранён как использованный.',
                'post'=>$postId,
            ]);
            $finish=$pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET pin_started_at=NULL,pin_delivery_uncertain=1,pin_completed_at=NOW()
                 WHERE post_id=:post AND state='completed' AND pin_completed_at IS NULL"
            );
            $finish->execute(['post'=>$postId]);
            // Close the unknown pin ledger without crediting it. This must
            // happen before safe-post refunds below.
            $pdo->prepare(
                'UPDATE posted_posts
                 SET manager_pin_quota_charged=0 WHERE id=:post'
            )->execute(['post'=>$postId]);
        } else {
            adminRefundTelegramManagerQuota($pdo,$pendingPin,false,true);
            $finish=$pdo->prepare(
                "UPDATE posted_telegram_publish_attempts
                 SET pin_started_at=NULL,pin_completed_at=NOW()
                 WHERE post_id=:post AND state='completed' AND pin_completed_at IS NULL"
            );
            $finish->execute(['post'=>$postId]);
        }
        if ($finish->rowCount() !== 1) {
            throw new RuntimeException('Состояние закрепления Telegram изменилось во время отвязки.');
        }
    }

    $safePosts=$pdo->prepare(
        "SELECT id,channel_id,author_id,manager_category_id,
                manager_post_quota_charged,manager_pin_quota_charged
         FROM posted_posts
         WHERE channel_id=:channel
           AND status IN ('draft','scheduled','failed','cancelled')
           AND (manager_post_quota_charged=1 OR manager_pin_quota_charged=1)
         ORDER BY id
         FOR UPDATE"
    );
    $safePosts->execute(['channel'=>$channelId]);
    foreach ($safePosts->fetchAll() as $safePost) {
        adminRefundTelegramManagerQuota($pdo,$safePost,true,true);
    }
}

/** @param array<string,mixed> $attempt */
function adminRefundMaxManagerQuota(
    PDO $pdo,
    array $attempt,
    bool $refundPostQuota,
    bool $refundPinQuota
): void {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Возврат лимита МАКС требует транзакцию.');
    }
    $refundPost=$refundPostQuota && !empty($attempt['manager_post_quota_charged']);
    $refundPin=$refundPinQuota && !empty($attempt['manager_pin_quota_charged']);
    if (!$refundPost && !$refundPin) {
        return;
    }

    $categoryId=(int)($attempt['manager_category_id'] ?? 0);
    if ($categoryId > 0) {
        $quota=$pdo->prepare(
            'UPDATE posted_max_manager_categories
             SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                 pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
             WHERE id=:category AND max_user_id=:user AND channel_id=:channel'
        );
        $quota->execute([
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'category'=>$categoryId,
            'user'=>(string)$attempt['author_max_user_id'],
            'channel'=>(int)$attempt['channel_id'],
        ]);
    } else {
        $quota=$pdo->prepare(
            'UPDATE posted_max_permissions
             SET post_quota_remaining=IF(:refund_post=1 AND post_quota_remaining IS NOT NULL,post_quota_remaining+1,post_quota_remaining),
                 pin_quota_remaining=IF(:refund_pin=1 AND pin_quota_remaining IS NOT NULL,pin_quota_remaining+1,pin_quota_remaining)
             WHERE max_user_id=:user AND channel_id=:channel'
        );
        $quota->execute([
            'refund_post'=>$refundPost ? 1 : 0,
            'refund_pin'=>$refundPin ? 1 : 0,
            'user'=>(string)$attempt['author_max_user_id'],
            'channel'=>(int)$attempt['channel_id'],
        ]);
    }

    $clear=$pdo->prepare(
        'UPDATE posted_max_publish_attempts
         SET manager_post_quota_charged=IF(:refund_post=1,0,manager_post_quota_charged),
             manager_pin_quota_charged=IF(:refund_pin=1,0,manager_pin_quota_charged)
         WHERE id=:attempt'
    );
    $clear->execute([
        'refund_post'=>$refundPost ? 1 : 0,
        'refund_pin'=>$refundPin ? 1 : 0,
        'attempt'=>(int)$attempt['id'],
    ]);
}

function adminSettleMaxChannelDetach(PDO $pdo, int $channelId): void
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Отключение МАКС-канала требует транзакцию.');
    }

    $activeAttempt=$pdo->prepare(
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
    $activeAttempt->execute(['channel'=>$channelId]);
    if ($activeAttempt->fetchColumn() !== false) {
        throw new RuntimeException(
            'В канале есть незавершённая или требующая проверки публикация. Завершите её проверку перед отвязкой.'
        );
    }

    $pins=$pdo->prepare(
        "SELECT attempt.id,attempt.post_id,attempt.channel_id,attempt.pin_status,
                attempt.pin_started_at,attempt.manager_post_quota_charged,
                attempt.manager_pin_quota_charged,
                post.author_max_user_id,post.manager_category_id,
                attempt.pin_started_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) AS pin_is_fresh
         FROM posted_max_publish_attempts attempt
         JOIN posted_max_posts post ON post.id=attempt.post_id
         WHERE attempt.channel_id=:channel
           AND attempt.status='completed'
           AND attempt.pin_status IN ('pending','processing')
         ORDER BY attempt.id
         FOR UPDATE"
    );
    $pins->execute(['channel'=>$channelId]);
    $pendingPins=$pins->fetchAll();
    foreach ($pendingPins as $pendingPin) {
        if (
            (string)$pendingPin['pin_status'] === 'processing'
            && !empty($pendingPin['pin_started_at'])
            && !empty($pendingPin['pin_is_fresh'])
        ) {
            throw new RuntimeException(
                'В канале завершается закрепление публикации. Повторите отвязку позже.'
            );
        }
    }
    foreach ($pendingPins as $pendingPin) {
        $attemptId=(int)$pendingPin['id'];
        if ((string)$pendingPin['pin_status'] === 'pending') {
            adminRefundMaxManagerQuota($pdo,$pendingPin,false,true);
            $pinResolution='Закрепление отменено при отвязке канала; лимит закрепления возвращён.';
            $finish=$pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status='not_required',pin_started_at=NULL,
                     error_text=COALESCE(error_text,:error)
                 WHERE id=:attempt AND pin_status='pending'"
            );
        } else {
            $pinResolution='Результат закрепления неизвестен после прерванной операции; лимит закрепления сохранён как использованный.';
            $finish=$pdo->prepare(
                "UPDATE posted_max_publish_attempts
                 SET pin_status='failed',pin_started_at=NULL,
                     manager_pin_quota_charged=0,
                     error_text=COALESCE(error_text,:error)
                 WHERE id=:attempt AND pin_status='processing'"
            );
        }
        $finish->execute([
            'error'=>$pinResolution,
            'attempt'=>$attemptId,
        ]);
        if ($finish->rowCount() !== 1) {
            throw new RuntimeException('Состояние закрепления МАКС изменилось во время отвязки.');
        }
    }

    $safeAttempts=$pdo->prepare(
        "SELECT attempt.id,attempt.post_id,attempt.channel_id,
                attempt.manager_post_quota_charged,attempt.manager_pin_quota_charged,
                post.author_max_user_id,post.manager_category_id
         FROM posted_max_publish_attempts attempt
         JOIN posted_max_posts post ON post.id=attempt.post_id
         WHERE attempt.channel_id=:channel
           AND post.status IN ('draft','scheduled','failed','cancelled')
           AND (attempt.manager_post_quota_charged=1 OR attempt.manager_pin_quota_charged=1)
         ORDER BY attempt.id
         FOR UPDATE"
    );
    $safeAttempts->execute(['channel'=>$channelId]);
    foreach ($safeAttempts->fetchAll() as $safeAttempt) {
        adminRefundMaxManagerQuota($pdo,$safeAttempt,true,true);
    }
}
function maxUserId(mixed $value): string {
    $id=trim((string)$value);
    if (preg_match('/\A[1-9][0-9]{0,63}\z/D', $id) !== 1) throw new RuntimeException('Некорректный МАКС ID пользователя.');
    return $id;
}
function adminClientIp(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        $forwarded = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) return $forwarded;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
}
function adminVerifyHCaptcha(
    array $settings,
    string $response,
    string $remoteIp,
    array $allowedHostnames
): bool {
    $siteKey = trim((string) ($settings['sitekey'] ?? ''));
    $secret = trim((string) ($settings['secret'] ?? ''));
    $response = trim($response);
    if (
        preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/Di', $siteKey) !== 1
        || preg_match('/\AES_[A-Za-z0-9_-]{20,128}\z/D', $secret) !== 1
        || $response === ''
        || strlen($response) > 16384
        || preg_match('/[\x00-\x1F\x7F]/D', $response) === 1
        || !function_exists('curl_init')
    ) {
        return false;
    }

    $request = [
        'secret' => $secret,
        'response' => $response,
        'sitekey' => $siteKey,
    ];
    if ($remoteIp !== 'unknown' && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
        $request['remoteip'] = $remoteIp;
    }

    $body = '';
    $curl = curl_init('https://api.hcaptcha.com/siteverify');
    if ($curl === false) {
        return false;
    }
    try {
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($request, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 2500,
            CURLOPT_TIMEOUT_MS => 6000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GitPosted-Control/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (!$configured || curl_exec($curl) !== true || (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            return false;
        }
    } catch (Throwable) {
        return false;
    } finally {
        curl_close($curl);
    }

    try {
        $verification = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }
    if (!is_array($verification) || ($verification['success'] ?? false) !== true) {
        return false;
    }
    $hostname = strtolower(rtrim(trim((string) ($verification['hostname'] ?? '')), '.'));
    return in_array($hostname, $allowedHostnames, true);
}
function adminLoginThrottle(string $path, string $ip, string $login, callable $verify): array {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Login security storage is unavailable.');
    }
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Login security storage is unavailable.');
    @chmod($path, 0640);
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Login security storage is unavailable.');
        rewind($handle);
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $buckets = is_array($decoded) && is_array($decoded['buckets'] ?? null) ? $decoded['buckets'] : [];
        $now = time();
        foreach ($buckets as $key => $bucket) {
            if (!is_string($key) || preg_match('/\A(?:ip|account):[a-f0-9]{32}\z/D', $key) !== 1 || !is_array($bucket)) {
                unset($buckets[$key]);
                continue;
            }
            $updatedAt = max(0, (int)($bucket['updated_at'] ?? 0));
            $blockedUntil = max(0, (int)($bucket['blocked_until'] ?? 0));
            if ($updatedAt < $now - 86400 && $blockedUntil <= $now) unset($buckets[$key]);
        }
        $normalizedLogin = mb_strtolower(trim($login), 'UTF-8');
        $keys = [
            'ip:' . substr(hash('sha256', 'ip|' . $ip), 0, 32) => 20,
            'account:' . substr(hash('sha256', 'account|' . $normalizedLogin), 0, 32) => 8,
        ];
        $blockedUntil = 0;
        foreach ($keys as $key => $limit) {
            $bucket = is_array($buckets[$key] ?? null) ? $buckets[$key] : [];
            $blockedUntil = max($blockedUntil, (int)($bucket['blocked_until'] ?? 0));
            $windowStarted = (int)($bucket['window_started'] ?? 0);
            $failures = (int)($bucket['failures'] ?? 0);
            if ($windowStarted <= 0 || $windowStarted <= $now - 900) $failures = 0;
        }
        $authorized = false;
        if ($blockedUntil <= $now) $authorized = (bool)$verify();
        if ($authorized) {
            foreach ($keys as $key => $_limit) unset($buckets[$key]);
        } elseif ($blockedUntil <= $now) {
            foreach ($keys as $key => $limit) {
                $bucket = is_array($buckets[$key] ?? null) ? $buckets[$key] : [];
                $windowStarted = (int)($bucket['window_started'] ?? 0);
                if ($windowStarted <= 0 || $windowStarted <= $now - 900) {
                    $windowStarted = $now;
                    $failures = 0;
                } else {
                    $failures = max(0, (int)($bucket['failures'] ?? 0));
                }
                $failures++;
                $bucket = [
                    'failures' => min($failures, $limit),
                    'window_started' => $windowStarted,
                    'blocked_until' => $failures >= $limit ? $now + 900 : 0,
                    'updated_at' => $now,
                ];
                $buckets[$key] = $bucket;
                $blockedUntil = max($blockedUntil, (int)$bucket['blocked_until']);
            }
        } else {
            foreach (array_keys($keys) as $key) {
                if (isset($buckets[$key]) && is_array($buckets[$key])) $buckets[$key]['updated_at'] = $now;
            }
        }
        ksort($buckets);
        $payload = json_encode(['version' => 1, 'buckets' => $buckets], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
            throw new RuntimeException('Login security storage is unavailable.');
        }
        flock($handle, LOCK_UN);
        return ['authorized' => $authorized, 'blocked_until' => $blockedUntil];
    } finally {
        fclose($handle);
    }
}
function parsePositiveId(mixed $value, string $label): ?int {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $raw) !== 1) throw new RuntimeException($label . ': укажите положительное целое число.');
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new RuntimeException($label . ': значение слишком велико.');
    return (int)$id;
}
$notificationTelegramId = (int)($config['notification_telegram_id'] ?? 0);
$notificationMaxId = trim((string)($config['notification_max_id'] ?? ''));
$page = adminPage($_GET['page'] ?? 'overview');
$authorized = !empty($_SESSION['posted_admin_authenticated']);
if (!isset($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(24));
if (!$authorized && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['login_csrf'], (string)($_POST['login_csrf'] ?? ''))) { http_response_code(403); exit('CSRF validation failed'); }
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $captchaVerified = !$hCaptchaRequired || adminVerifyHCaptcha(
        $hCaptchaSettings,
        (string) ($_POST['h-captcha-response'] ?? ''),
        adminClientIp(),
        $hCaptchaHostnames
    );
    try {
        $loginResult = adminLoginThrottle(
            $dataDirectory . '/storage/admin-login-throttle.json',
            adminClientIp(),
            $login,
            static fn(): bool => gitpostedAdminPasswordValid($adminAuth, $login, $password, $captchaVerified, $hCaptchaRequired)
        );
    } catch (Throwable) {
        http_response_code(503);
        exit('Login security storage is unavailable');
    }
    if ($loginResult['authorized']) {
        session_regenerate_id(true); $_SESSION['posted_admin_authenticated'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(24));
        unset($_SESSION['login_csrf']); $_SESSION['flash'] = 'Вход выполнен.'; header('Location: /control/?page=overview', true, 303); exit;
    }
    $_SESSION['login_csrf'] = bin2hex(random_bytes(24));
    if ((int)$loginResult['blocked_until'] > time()) {
        header('Retry-After: ' . max(1, (int)$loginResult['blocked_until'] - time()));
        $_SESSION['login_error'] = 'Слишком много попыток. Повторите вход позже.';
    } else {
        $_SESSION['login_error'] = 'Не удалось выполнить вход. Проверьте логин, пароль и проверку безопасности.';
    }
    header('Location: /control/?page=overview', true, 303);
    exit;
}
unset($hCaptchaSettings);
$pdo = null;
if ($authorized) {
    $pdo = PostedDb::pdo($config);
    PostedDb::assertSchema($pdo);
}
if ($authorized && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); exit('CSRF validation failed');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        header('Location: /control/?page=overview', true, 303);
        exit;
    }
    try {
        if (in_array($action, ['bot_toggle', 'telegram_config', 'max_config'], true)) {
            // Different browser sessions must serialize the API effects, the
            // complete config write, and its rollback against one fresh copy.
            $adminConfigOperationLock = adminAcquireConfigOperationLock($dataDirectory);
            $config = PostedConfig::get();
            $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://example.com'), '/');
            $dataDirectory = rtrim((string) ($config['data_dir'] ?? '/var/lib/gitposted'), '/\\');
            adminConfigWritableTarget($appConfigPath, $dataDirectory);
        }
        if ($action === 'bot_toggle') {
            $platform=(string)($_POST['platform'] ?? '');
            if (!in_array($platform, ['telegram','max'], true)) {
                throw new RuntimeException('Неизвестная платформа.');
            }
            $enabled=(string)($_POST['enabled'] ?? '0') === '1';
            $tokenKey = $platform === 'telegram' ? 'bot_token' : 'max_bot_token';
            if (trim((string) ($config[$tokenKey] ?? '')) === '') {
                throw new RuntimeException('Сначала подключите бота: укажите и проверьте его токен.');
            }
            $newConfig=$config;
            $configWritten=false;
            if ($platform === 'telegram') {
                $telegram=new PostedTelegram((string)$config['bot_token']);
                adminToggleTelegram($telegram, $config, $enabled,
                    static fn(array $value) => adminWriteConfig($appConfigPath, $value, $dataDirectory));
                $configWritten=true;
            } else {
                require_once dirname(__DIR__) . '/max-bootstrap.php';
                $maxApi=new GitPostedMaxApi($config);
                $maxApp=new GitPostedMaxBot($pdo,$maxApi,$config);
                $webhookUrl=$baseUrl.'/max-webhook.php';
                $hadSubscription=adminMaxSubscriptionCount($maxApp,$webhookUrl)>0;
                $wasEnabled=(($config['max_bot_enabled'] ?? false) === true);
                if ($enabled) {
                    $preserveOnFailure=$hadSubscription && $wasEnabled;
                    try {
                        // POST /subscriptions is also the documented update
                        // operation. Refresh even an existing URL, then prove
                        // that MAX stored the complete event set.
                        adminCreateAndConfirmMaxSubscription($maxApp,$webhookUrl);
                        $maxApp->setCommands();
                        $newConfig['max_bot_enabled']=true;
                        adminWriteConfig($appConfigPath,$newConfig,$dataDirectory);
                        $configWritten=true;
                    } catch (Throwable $exception) {
                        $rollbackErrors=[];
                        try {
                            adminWriteConfig($appConfigPath,$config,$dataDirectory);
                        } catch (Throwable $restoreConfigException) {
                            $rollbackErrors[]='конфигурация: '.$restoreConfigException->getMessage();
                        }
                        if (!$preserveOnFailure) {
                            try {
                                adminRemoveAndConfirmMaxSubscription($maxApp,$webhookUrl);
                            } catch (Throwable $cleanupException) {
                                $rollbackErrors[]='webhook: '.$cleanupException->getMessage();
                            }
                        }
                        throw new RuntimeException(
                            'Включение МАКС отменено.'
                                .($rollbackErrors===[]
                                    ? ' Старая конфигурация восстановлена.'
                                    : ' Ошибки отката: '.implode('; ',$rollbackErrors).'.'),
                            0,
                            $exception
                        );
                    }
                } else {
                    try {
                        if ($hadSubscription) {
                            adminRemoveAndConfirmMaxSubscription($maxApp,$webhookUrl);
                        }
                        $newConfig['max_bot_enabled']=false;
                        adminWriteConfig($appConfigPath,$newConfig,$dataDirectory);
                        $configWritten=true;
                    } catch (Throwable $exception) {
                        $rollbackErrors=[];
                        try {
                            adminWriteConfig($appConfigPath,$config,$dataDirectory);
                        } catch (Throwable $restoreConfigException) {
                            $rollbackErrors[]='конфигурация: '.$restoreConfigException->getMessage();
                        }
                        if ($hadSubscription && $wasEnabled) {
                            try {
                                if (adminMaxSubscriptionCount($maxApp,$webhookUrl) === 0) {
                                    adminCreateAndConfirmMaxSubscription($maxApp,$webhookUrl);
                                }
                            } catch (Throwable $restoreException) {
                                $rollbackErrors[]='webhook: '.$restoreException->getMessage();
                            }
                        } elseif (!$wasEnabled) {
                            try {
                                adminRemoveAndConfirmMaxSubscription($maxApp,$webhookUrl);
                            } catch (Throwable $cleanupException) {
                                $rollbackErrors[]='webhook: '.$cleanupException->getMessage();
                            }
                        }
                        throw new RuntimeException(
                            'Отключение МАКС не завершено.'
                                .($rollbackErrors===[]
                                    ? ' Исходное состояние восстановлено.'
                                    : ' Ошибки отката: '.implode('; ',$rollbackErrors).'.'),
                            0,
                            $exception
                        );
                    }
                }
            }
            if (!$configWritten) {
                adminWriteConfig($appConfigPath,$newConfig,$dataDirectory);
            }
            go(
                'Бот ' . ($enabled ? 'включён' : 'отключён') . '.',
                adminPage($_POST['return_page'] ?? $platform)
            );
        }
        if ($action === 'telegram_config') {
            $newConfig = $config;
            $newConfig['bot_username'] = adminBotUsername(
                $_POST['bot_username'] ?? '',
                'Username Telegram'
            );
            $newConfig['timezone'] = adminStorageTimezone($config, $_POST['timezone'] ?? '');
            $supportUsername = ltrim(trim((string) ($_POST['service_admin_username'] ?? '')), '@');
            if ($supportUsername !== '' && preg_match('/\A[A-Za-z0-9_]{5,32}\z/D', $supportUsername) !== 1) {
                throw new RuntimeException('Некорректный username поддержки.');
            }
            $newConfig['service_admin_username'] = $supportUsername;
            $newConfig['service_admin_url'] = $supportUsername !== '' ? 'https://t.me/' . $supportUsername : '';
            $submittedToken = trim((string) ($_POST['bot_token'] ?? ''));
            if ($submittedToken !== '') {
                if (preg_match('/\A[0-9]{6,12}:[A-Za-z0-9_-]{30,200}\z/D', $submittedToken) !== 1) {
                    throw new RuntimeException('Некорректный токен Telegram Bot API.');
                }
                $newConfig['bot_token'] = $submittedToken;
            }
            $telegramTokenChanged = !hash_equals(
                (string) $config['bot_token'],
                (string) $newConfig['bot_token']
            );
            $submittedWebhookSecret = adminSecretInput(
                $_POST['webhook_secret'] ?? '',
                'Секрет Telegram webhook'
            );
            if ($submittedWebhookSecret !== '') {
                if (
                    $telegramTokenChanged
                    && hash_equals((string) ($config['webhook_secret'] ?? ''), $submittedWebhookSecret)
                ) {
                    throw new RuntimeException(
                        'При смене Telegram-токена задайте новый секрет webhook или оставьте поле пустым для автогенерации.'
                    );
                }
                $newConfig['webhook_secret'] = $submittedWebhookSecret;
            } elseif ($telegramTokenChanged) {
                $newConfig['webhook_secret'] = bin2hex(random_bytes(32));
            }

            $telegram = new PostedTelegram((string) $newConfig['bot_token']);
            $me = $telegram->call('getMe');
            $actualUsername = ltrim((string) (($me['result'] ?? [])['username'] ?? ''), '@');
            if (
                $actualUsername === ''
                || strcasecmp($actualUsername, (string) $newConfig['bot_username']) !== 0
            ) {
                throw new RuntimeException(
                    'Токен принадлежит @' . ($actualUsername !== '' ? $actualUsername : 'unknown')
                    . ', а указан @' . $newConfig['bot_username'] . '.'
                );
            }
            $cleanupWarning = adminConfigureTelegram(
                $telegram, $config, $newConfig,
                trim((string) (($me['result'] ?? [])['id'] ?? '')),
                static fn(array $value) => adminWriteConfig($appConfigPath, $value, $dataDirectory)
            );
            go('Новый бот Telegram проверен, подключён и включён.' . $cleanupWarning, adminPage($_POST['return_page'] ?? 'telegram'));
        }
        if ($action === 'max_config') {
            require_once dirname(__DIR__) . '/max-bootstrap.php';
            $newConfig = $config;
            $newConfig['max_bot_username'] = adminBotUsername(
                $_POST['max_bot_username'] ?? '',
                'Username МАКС'
            );
            $maxApiUrl = rtrim(trim((string) ($_POST['max_api_base_url'] ?? '')), '/');
            if (!hash_equals('https://platform-api2.max.ru', $maxApiUrl)) {
                throw new RuntimeException('Разрешён только официальный API https://platform-api2.max.ru.');
            }
            $newConfig['max_api_base_url'] = $maxApiUrl;
            $submittedToken = adminSecretInput($_POST['max_bot_token'] ?? '', 'Токен МАКС');
            if ($submittedToken !== '') {
                $newConfig['max_bot_token'] = $submittedToken;
            }
            $maxTokenChanged = !hash_equals(
                (string) $config['max_bot_token'],
                (string) $newConfig['max_bot_token']
            );
            $submittedWebhookSecret = adminSecretInput(
                $_POST['max_webhook_secret'] ?? '',
                'Секрет webhook МАКС',
                16
            );
            if ($submittedWebhookSecret !== '') {
                if (
                    $maxTokenChanged
                    && hash_equals((string) ($config['max_webhook_secret'] ?? ''), $submittedWebhookSecret)
                ) {
                    throw new RuntimeException(
                        'При смене токена МАКС задайте новый секрет webhook или оставьте поле пустым для автогенерации.'
                    );
                }
                $newConfig['max_webhook_secret'] = $submittedWebhookSecret;
            } elseif ($maxTokenChanged) {
                $newConfig['max_webhook_secret'] = bin2hex(random_bytes(32));
            }

            $maxApi = new GitPostedMaxApi($newConfig);
            $botInfo = $maxApi->me();
            $actualUsername = ltrim(trim((string) ($botInfo['username'] ?? '')), '@');
            if (
                $actualUsername === ''
                || strcasecmp($actualUsername, (string) $newConfig['max_bot_username']) !== 0
            ) {
                throw new RuntimeException(
                    'Токен МАКС принадлежит @' . ($actualUsername !== '' ? $actualUsername : 'unknown')
                    . ', а указан @' . $newConfig['max_bot_username'] . '.'
                );
            }
            $maxWebhookUrl = $baseUrl . '/max-webhook.php';
            $maxApp = new GitPostedMaxBot($pdo, $maxApi, $newConfig);
            $hasOldMax = trim((string) ($config['max_bot_token'] ?? '')) !== '';
            $oldApi = gitpostedAdminPreviousClient($config, 'max_bot_token', static fn(array $old): GitPostedMaxApi => new GitPostedMaxApi($old));
            $oldApp = $oldApi !== null ? new GitPostedMaxBot($pdo, $oldApi, $config) : null;
            $sameMaxBot=$hasOldMax && !$maxTokenChanged;
            $maxBotIdentityKnown=!$hasOldMax || !$maxTokenChanged;
            if ($maxTokenChanged && $hasOldMax) {
                try {
                    $oldBotInfo=$oldApi->me();
                    $oldBotId=trim((string)($oldBotInfo['user_id'] ?? ''));
                    $newBotId=trim((string)($botInfo['user_id'] ?? ''));
                    if ($oldBotId!=='' && $newBotId!=='') {
                        $sameMaxBot=hash_equals($oldBotId,$newBotId);
                        $maxBotIdentityKnown=true;
                    } else {
                        $oldUsername=ltrim(trim((string)($oldBotInfo['username'] ?? '')),'@');
                        if ($oldUsername!=='') {
                            $sameMaxBot=strcasecmp($actualUsername,$oldUsername)===0;
                            $maxBotIdentityKnown=true;
                        }
                    }
                } catch (Throwable) {
                    // Keep the old subscription untouched when its identity
                    // cannot be proven; deleting it could delete a rotated
                    // token's newly-confirmed subscription on the same bot.
                    $sameMaxBot=false;
                    $maxBotIdentityKnown=false;
                }
            }
            $newHadSubscription=adminMaxSubscriptionCount($maxApp,$maxWebhookUrl)>0;
            if ($newHadSubscription && !$maxBotIdentityKnown) {
                throw new RuntimeException(
                    'У нового токена уже есть webhook, а принадлежность старого токена проверить нельзя. '
                    .'Сначала удалите лишнюю подписку вручную или восстановите старый токен.'
                );
            }
            if ($newHadSubscription && !$sameMaxBot) {
                throw new RuntimeException(
                    'У нового бота уже есть webhook на адрес GitPosted. Удалите его перед безопасным переключением.'
                );
            }
            if (
                $newHadSubscription
                && !hash_equals(
                    (string)($config['max_webhook_secret'] ?? ''),
                    (string)($newConfig['max_webhook_secret'] ?? '')
                )
            ) {
                if ($submittedWebhookSecret!=='') {
                    throw new RuntimeException(
                        'Нельзя безопасно заменить секрет действующей подписки МАКС. '
                        .'Сначала отключите МАКС-бота, затем сохраните новый секрет.'
                    );
                }
                // A token rotation for the same bot does not require rotating
                // the webhook secret; preserving it avoids a duplicate row.
                $newConfig['max_webhook_secret']=$config['max_webhook_secret'] ?? '';
            }
            $createdNewSubscription=false;
            try {
                // POST /subscriptions creates or updates the row. Even an
                // existing URL must be refreshed so its secret/event set is
                // confirmed by this token before the configuration switches.
                $createdNewSubscription=!$newHadSubscription;
                adminCreateAndConfirmMaxSubscription($maxApp,$maxWebhookUrl);
                $maxApp->setCommands();
                $newConfig['max_bot_enabled'] = true;
                adminWriteConfig($appConfigPath,$newConfig,$dataDirectory);
            } catch (Throwable $exception) {
                $rollbackErrors=[];
                try {
                    adminWriteConfig($appConfigPath,$config,$dataDirectory);
                } catch (Throwable $restoreConfigException) {
                    $rollbackErrors[]='конфигурация: '.$restoreConfigException->getMessage();
                }
                if ($createdNewSubscription) {
                    try {
                        adminRemoveAndConfirmMaxSubscription($maxApp,$maxWebhookUrl);
                    } catch (Throwable $removeNewException) {
                        $rollbackErrors[]='новая подписка: '.$removeNewException->getMessage();
                    }
                }
                throw new RuntimeException(
                    'МАКС принял токен, но безопасное переключение не завершено.'
                        .($rollbackErrors===[]
                            ? ' Старая конфигурация и webhook восстановлены.'
                            : ' Ошибки отката: '.implode('; ',$rollbackErrors).'.'),
                    0,
                    $exception
                );
            }
            $cleanupWarning='';
            if ($maxBotIdentityKnown && !$sameMaxBot && $oldApp !== null) {
                try {
                    adminRemoveAndConfirmMaxSubscription($oldApp,$maxWebhookUrl);
                } catch (Throwable $cleanupException) {
                    // The confirmed new subscription is live, so retaining the
                    // old one is safer than rolling back and creating a gap.
                    error_log('GitPosted MAX old webhook cleanup: '.$cleanupException->getMessage());
                    $cleanupWarning=' Старая подписка пока не удалена; новая уже работает.';
                }
            } elseif (!$maxBotIdentityKnown && $hasOldMax) {
                $cleanupWarning=' Старую подписку не удаляли: идентичность старого токена не подтверждена.';
            }
            go(
                'Новый бот МАКС проверен, подключён и включён.'.$cleanupWarning,
                adminPage($_POST['return_page'] ?? 'max')
            );
        }
        if ($action === 'account_link') {
            $telegramId=parsePositiveId($_POST['telegram_id'] ?? '', 'Telegram ID');
            $maxId=maxUserId($_POST['max_user_id'] ?? '');
            if ($telegramId === null) throw new RuntimeException('Telegram ID не указан.');
            $telegramLookup=$pdo->prepare('SELECT COUNT(*) FROM posted_users WHERE telegram_id=:id');
            $telegramLookup->execute(['id'=>$telegramId]);
            if (!(int)$telegramLookup->fetchColumn()) throw new RuntimeException('Пользователь Telegram не найден.');
            $maxLookup=$pdo->prepare('SELECT linked_telegram_id FROM posted_max_users WHERE max_user_id=:id LIMIT 1');
            $maxLookup->execute(['id'=>$maxId]);
            $linkedTelegram=$maxLookup->fetchColumn();
            if ($linkedTelegram === false) throw new RuntimeException('Пользователь МАКС не найден.');
            if ($linkedTelegram !== null && (int)$linkedTelegram !== $telegramId) {
                throw new RuntimeException('Этот аккаунт МАКС уже связан с другим Telegram.');
            }
            $telegramMaxLookup=$pdo->prepare(
                'SELECT max_user_id FROM posted_max_users
                 WHERE linked_telegram_id=:telegram AND max_user_id<>:max LIMIT 1'
            );
            $telegramMaxLookup->execute(['telegram'=>$telegramId,'max'=>$maxId]);
            if ($telegramMaxLookup->fetchColumn() !== false) {
                throw new RuntimeException('Этот Telegram уже связан с другим аккаунтом МАКС.');
            }
            adminLinkAccounts($pdo,$telegramId,$maxId);
            go(
                'Аккаунты Telegram и МАКС связаны.',
                adminPage($_POST['return_page'] ?? 'telegram-data')
            );
        }
        if ($action === 'user_status') {
            $uid=(int)($_POST['user_id'] ?? 0);
            if ($uid < 1) throw new RuntimeException('Пользователь Telegram не указан.');
            $pdo->prepare('UPDATE posted_users SET is_active=IF(is_active=1,0,1) WHERE telegram_id=:id')->execute(['id'=>$uid]);
            go('Статус пользователя Telegram изменён.');
        }
        if ($action === 'max_user_status') {
            $maxUser=maxUserId($_POST['max_user_id'] ?? '');
            $lookup=$pdo->prepare('SELECT max_user_id FROM posted_max_users WHERE max_user_id=:id LIMIT 1');
            $lookup->execute(['id'=>$maxUser]);
            if ($lookup->fetchColumn() === false) throw new RuntimeException('Пользователь МАКС не найден.');
            $stmt=$pdo->prepare('UPDATE posted_max_users SET is_active=IF(is_active=1,0,1) WHERE max_user_id=:id');
            $stmt->execute(['id'=>$maxUser]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Статус пользователя МАКС не изменён.');
            go('Статус пользователя МАКС изменён.');
        }
        if ($action === 'channel_detach') {
            $channelId=parsePositiveId($_POST['channel_id'] ?? '', 'ID канала');
            if ($channelId === null) throw new RuntimeException('Канал Telegram не указан.');
            $cronLockName='trevoga_posted_cron';
            $cronLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
            $cronLock->execute(['lock_name'=>$cronLockName]);
            if ((int)($cronLock->fetchColumn() ?: 0) !== 1) {
                throw new RuntimeException('Завершается фоновая операция с каналами. Повторите отвязку.');
            }
            try {
                $pinLockName='gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32);
                $pinLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
                $pinLock->execute(['lock_name'=>$pinLockName]);
                if ((int)($pinLock->fetchColumn() ?: 0) !== 1) {
                    throw new RuntimeException('В канале завершается закрепление публикации. Повторите отвязку.');
                }
                try {
                    $pdo->beginTransaction();
                    try {
                        $lookup=$pdo->prepare(
                        'SELECT title,added_by FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE'
                    );
                    $lookup->execute(['id'=>$channelId]);
                    $channel=$lookup->fetch();
                    if (!is_array($channel)) throw new RuntimeException('Канал Telegram не найден.');
                    $pdo->prepare(
                        'UPDATE posted_users telegram_user
                         JOIN posted_permissions permission
                           ON permission.user_id=telegram_user.telegram_id
                         SET telegram_user.state=NULL,telegram_user.state_data=NULL
                         WHERE permission.channel_id=:channel'
                    )->execute(['channel'=>$channelId]);
                    $owner=(int)($channel['added_by'] ?? 0);
                    if ($owner > 0) {
                        $pdo->prepare(
                            'UPDATE posted_users
                             SET state=NULL,state_data=NULL
                             WHERE telegram_id=:owner'
                        )->execute(['owner'=>$owner]);
                    }
                    $pdo->prepare(
                        'DELETE edit_session
                         FROM posted_edit_sessions edit_session
                         JOIN posted_posts post ON post.id=edit_session.post_id
                         WHERE post.channel_id=:channel'
                    )->execute(['channel'=>$channelId]);
                    adminSettleTelegramChannelDetach($pdo,$channelId);
                    $pdo->prepare(
                        "UPDATE posted_posts
                         SET status='cancelled',scheduled_at=NULL,publish_started_at=NULL,
                             repeat_interval_minutes=NULL,repeat_until=NULL,error_text=NULL
                         WHERE channel_id=:channel
                           AND status IN ('draft','scheduled','publishing','telegram_pending','failed')"
                    )->execute(['channel'=>$channelId]);
                    $pdo->prepare(
                        "UPDATE posted_posts
                         SET delete_after_minutes=NULL,delete_at=NULL,
                             repeat_interval_minutes=NULL,repeat_until=NULL,
                             manual_delete_required=0,delete_notice_sent_at=NULL,delete_attempts=0
                         WHERE channel_id=:channel
                           AND status IN ('sent','deleted')"
                    )->execute(['channel'=>$channelId]);
                    $pdo->prepare(
                        'UPDATE posted_manager_categories
                         SET is_active=0 WHERE channel_id=:channel'
                    )->execute(['channel'=>$channelId]);
                    // The detached grant is a closed accounting generation.
                    // Consumed/unknown effects must never credit a later grant.
                    $pdo->prepare(
                        'UPDATE posted_posts
                         SET manager_post_quota_charged=0,manager_pin_quota_charged=0
                         WHERE channel_id=:channel
                           AND (manager_post_quota_charged=1 OR manager_pin_quota_charged=1)'
                    )->execute(['channel'=>$channelId]);
                    $pdo->prepare('DELETE FROM posted_permissions WHERE channel_id=:channel')
                        ->execute(['channel'=>$channelId]);
                    $pdo->prepare(
                        'UPDATE posted_channels
                         SET added_by=NULL,is_active=0,admin_blocked=0,is_archived=0
                         WHERE id=:channel'
                    )->execute(['channel'=>$channelId]);
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                } finally {
                    $releasePinLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $releasePinLock->execute(['lock_name'=>$pinLockName]);
                }
            } finally {
                $releaseCronLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseCronLock->execute(['lock_name'=>$cronLockName]);
            }
            go(
                'Канал Telegram «'.(string)$channel['title'].'» отключён и отвязан от владельца.',
                adminPage($_POST['return_page'] ?? 'telegram-data')
            );
        }
        if ($action === 'max_channel_detach') {
            $channelId=parsePositiveId($_POST['channel_id'] ?? '', 'ID канала');
            if ($channelId === null) throw new RuntimeException('Канал МАКС не указан.');
            $abandonedDeleteCount=0;
            $cronLockName='gitposted_max_cron';
            $cronLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
            $cronLock->execute(['lock_name'=>$cronLockName]);
            if ((int)($cronLock->fetchColumn() ?: 0) !== 1) {
                throw new RuntimeException('Завершается фоновая операция с каналами. Повторите отвязку.');
            }
            try {
                $pinLockName='gitposted_max_pin_'.substr(hash('sha256',(string)$channelId),0,28);
                $pinLock=$pdo->prepare('SELECT GET_LOCK(:lock_name,10)');
                $pinLock->execute(['lock_name'=>$pinLockName]);
                if ((int)($pinLock->fetchColumn() ?: 0) !== 1) {
                    throw new RuntimeException('В канале завершается закрепление публикации. Повторите отвязку.');
                }
                try {
                    $pdo->beginTransaction();
                    try {
                        $lookup=$pdo->prepare(
                        'SELECT title,added_by FROM posted_max_channels
                         WHERE id=:id LIMIT 1 FOR UPDATE'
                    );
                    $lookup->execute(['id'=>$channelId]);
                    $channel=$lookup->fetch();
                    if (!is_array($channel)) throw new RuntimeException('Канал МАКС не найден.');
                    $pdo->prepare(
                        'UPDATE posted_max_users max_user
                         JOIN posted_max_permissions permission
                           ON permission.max_user_id=max_user.max_user_id
                         SET max_user.state=NULL,max_user.state_data=NULL
                         WHERE permission.channel_id=:channel'
                    )->execute(['channel'=>$channelId]);
                    $owner=trim((string)($channel['added_by'] ?? ''));
                    if ($owner !== '') {
                        $pdo->prepare(
                            'UPDATE posted_max_users
                             SET state=NULL,state_data=NULL
                             WHERE max_user_id=:owner'
                        )->execute(['owner'=>$owner]);
                    }
                    adminSettleMaxChannelDetach($pdo,$channelId);
                    $pdo->prepare(
                        "UPDATE posted_max_posts
                         SET status='cancelled',scheduled_at=NULL,
                             repeat_interval_minutes=NULL,repeat_remaining=0,
                             repeat_next_at=NULL,error_text=NULL
                         WHERE channel_id=:channel
                           AND status IN ('draft','scheduled','publishing','max_pending','failed')"
                    )->execute(['channel'=>$channelId]);
                    $pdo->prepare(
                        "UPDATE posted_max_posts
                         SET delete_after_minutes=NULL,repeat_interval_minutes=NULL,
                             repeat_remaining=0,repeat_next_at=NULL
                         WHERE channel_id=:channel
                           AND status IN ('sent','deleted')"
                    )->execute(['channel'=>$channelId]);
                    $abandonDeletes=$pdo->prepare(
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
                        'error'=>'Удаление остановлено администратором при отвязке канала; сообщение можно удалить вручную.',
                        'channel'=>$channelId,
                    ]);
                    $abandonedDeleteCount=$abandonDeletes->rowCount();
                    $pdo->prepare(
                        'UPDATE posted_max_manager_categories
                         SET is_active=0 WHERE channel_id=:channel'
                    )->execute(['channel'=>$channelId]);
                    // Preserve consumed/unknown effects, but close their old
                    // accounting generation before deleting its quota rows.
                    $pdo->prepare(
                        'UPDATE posted_max_publish_attempts
                         SET manager_post_quota_charged=0,manager_pin_quota_charged=0
                         WHERE channel_id=:channel
                           AND (manager_post_quota_charged=1 OR manager_pin_quota_charged=1)'
                    )->execute(['channel'=>$channelId]);
                    $pdo->prepare('DELETE FROM posted_max_permissions WHERE channel_id=:channel')
                        ->execute(['channel'=>$channelId]);
                    $pdo->prepare(
                        'UPDATE posted_max_channels
                         SET added_by=NULL,is_active=0,admin_blocked=0,is_archived=0
                         WHERE id=:channel'
                    )->execute(['channel'=>$channelId]);
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                } finally {
                    $releasePinLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    $releasePinLock->execute(['lock_name'=>$pinLockName]);
                }
            } finally {
                $releaseCronLock=$pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseCronLock->execute(['lock_name'=>$cronLockName]);
            }
            go(
                'Канал МАКС «'.(string)$channel['title'].'» отключён и отвязан от владельца.'
                    .($abandonedDeleteCount>0
                        ? ' Незавершённые удаления остановлены; сообщения можно удалить в канале вручную.'
                        : ''),
                adminPage($_POST['return_page'] ?? 'max-data')
            );
        }
        if ($action === 'channel_status') {
            $channelId=parsePositiveId($_POST['channel_id'] ?? '', 'ID канала');
            if ($channelId === null) throw new RuntimeException('Канал Telegram не указан.');
            adminWithChannelOperationLocks(
                $pdo,
                'trevoga_posted_cron',
                'gitposted_pin_'.substr(hash('sha256',(string)$channelId),0,32),
                static function () use ($pdo,$channelId): void {
                    $pdo->beginTransaction();
                    try {
                        $channelLock=$pdo->prepare(
                            'SELECT added_by,admin_blocked
                             FROM posted_channels
                             WHERE id=:channel
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $channelLock->execute(['channel'=>$channelId]);
                        $channel=$channelLock->fetch();
                        if (!is_array($channel)) throw new RuntimeException('Канал Telegram не найден.');
                        if ((int)($channel['added_by'] ?? 0) <= 0) {
                            throw new RuntimeException('Нельзя включить канал без владельца. Подключите его через бота.');
                        }
                        $activeAttempt=$pdo->prepare(
                            'SELECT post_id
                             FROM posted_telegram_publish_attempts
                             WHERE gate_channel_id=:channel
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $activeAttempt->execute(['channel'=>$channelId]);
                        if ($activeAttempt->fetchColumn() !== false) {
                            throw new RuntimeException('В канале завершается публикация. Повторите изменение статуса.');
                        }
                        $stmt=$pdo->prepare(
                            'UPDATE posted_channels
                             SET is_active=IF(
                                   admin_blocked=1,
                                   IF(added_by>0 AND bot_status IN (\'administrator\',\'member\'),1,0),
                                   0
                                 ),
                                 admin_blocked=IF(admin_blocked=1,0,1)
                             WHERE id=:id AND added_by>0'
                        );
                        $stmt->execute(['id'=>$channelId]);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Статус канала Telegram не изменён.');
                        }
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                }
            );
            go('Статус канала Telegram изменён.');
        }
        if ($action === 'max_channel_status') {
            $channelId=parsePositiveId($_POST['channel_id'] ?? '', 'ID канала');
            if ($channelId === null) throw new RuntimeException('Канал МАКС не указан.');
            adminWithChannelOperationLocks(
                $pdo,
                'gitposted_max_cron',
                'gitposted_max_pin_'.substr(hash('sha256',(string)$channelId),0,28),
                static function () use ($pdo,$channelId): void {
                    $pdo->beginTransaction();
                    try {
                        $channelLock=$pdo->prepare(
                            'SELECT added_by,admin_blocked
                             FROM posted_max_channels
                             WHERE id=:channel
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $channelLock->execute(['channel'=>$channelId]);
                        $channel=$channelLock->fetch();
                        if (!is_array($channel)) throw new RuntimeException('Канал МАКС не найден.');
                        if (trim((string)($channel['added_by'] ?? '')) === '') {
                            throw new RuntimeException('Нельзя включить канал без владельца. Подключите его через бота.');
                        }
                        $activeAttempt=$pdo->prepare(
                            'SELECT id
                             FROM posted_max_publish_attempts
                             WHERE channel_id=:channel
                               AND (gate_post_id IS NOT NULL OR gate_chat_id IS NOT NULL)
                             LIMIT 1
                             FOR UPDATE'
                        );
                        $activeAttempt->execute(['channel'=>$channelId]);
                        if ($activeAttempt->fetchColumn() !== false) {
                            throw new RuntimeException('В канале завершается публикация. Повторите изменение статуса.');
                        }
                        $legacyPin=$pdo->prepare(
                            "SELECT id
                             FROM posted_max_publish_attempts
                             WHERE channel_id=:channel AND status='completed'
                               AND pin_status='processing'
                               AND pin_started_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE)
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $legacyPin->execute(['channel'=>$channelId]);
                        if ($legacyPin->fetchColumn() !== false) {
                            throw new RuntimeException('В канале завершается закрепление. Повторите изменение статуса.');
                        }
                        $stmt=$pdo->prepare(
                            "UPDATE posted_max_channels
                             SET is_active=IF(
                                   admin_blocked=1,
                                   IF(
                                     added_by IS NOT NULL AND added_by<>''
                                     AND can_post=1
                                     AND (bot_status IS NULL OR bot_status='' OR bot_status='active'),
                                     1,
                                     0
                                   ),
                                   0
                                 ),
                                 admin_blocked=IF(admin_blocked=1,0,1)
                             WHERE id=:id
                               AND added_by IS NOT NULL AND added_by<>''"
                        );
                        $stmt->execute(['id'=>$channelId]);
                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Статус канала МАКС не изменён.');
                        }
                        $pdo->commit();
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                }
            );
            go('Статус канала МАКС изменён.');
        }
        throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $ex) {
        $_SESSION['flash_error']=$ex instanceof PDOException ? 'Операция с базой данных не выполнена.' : $ex->getMessage();
        go();
    }
}

$flash=(string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
$error=(string)($_SESSION['flash_error'] ?? $_SESSION['login_error'] ?? ''); unset($_SESSION['flash_error'],$_SESSION['login_error']);
$stats=[]; $users=[]; $channels=[]; $telegramEditorsByChannel=[];
$maxUsers=[]; $maxChannels=[]; $maxEditorsByChannel=[];
$maxLinkedByTelegram=[]; $maxSchemaInstalled=false; $maxDataError='';
$telegramConfigured=trim((string)($config['bot_token']??''))!=='' && trim((string)($config['bot_username']??''))!=='';
$maxConfigured=trim((string)($config['max_bot_token']??''))!=='' && trim((string)($config['max_bot_username']??''))!=='';
if ($authorized) {
    $stats=[
        'tg_users'=>scalar($pdo,'SELECT COUNT(*) FROM posted_users'),
        'tg_channels'=>scalar($pdo,'SELECT COUNT(*) FROM posted_channels WHERE is_active=1'),
        'tg_posts'=>scalar($pdo,"SELECT COUNT(*) FROM posted_posts WHERE status IN ('sent','scheduled')"),
        'max_users'=>0,
        'max_users_total'=>0,
        'max_users_linked'=>0,
        'max_channels'=>0,
        'max_posts'=>0,
    ];
    $users=$pdo->query(
        "SELECT u.telegram_id,u.username,u.first_name,u.role,u.is_active,u.created_at,
                (
                    SELECT m.max_user_id FROM posted_max_users m
                    WHERE m.linked_telegram_id=u.telegram_id
                    ORDER BY m.updated_at DESC LIMIT 1
                ) AS linked_max_user_id
         FROM posted_users u ORDER BY u.updated_at DESC LIMIT 100"
    )->fetchAll();
    $channels=$pdo->query(
        "SELECT c.id,c.chat_id,c.title,c.username,c.is_active,c.admin_blocked,c.bot_can_post,
                c.added_by,c.updated_at,
                owner_user.first_name AS owner_name,
                owner_user.username AS owner_username
         FROM posted_channels c
         LEFT JOIN posted_users owner_user ON owner_user.telegram_id=c.added_by
         ORDER BY c.updated_at DESC
         LIMIT 100"
    )->fetchAll();
    $telegramChannelIds=array_map(static fn(array $channel): int => (int)$channel['id'], $channels);
    if ($telegramChannelIds !== []) {
        $telegramEditorStatement=$pdo->prepare(
            "SELECT permission.channel_id,
                    permission.user_id,
                    telegram_user.first_name AS display_name,
                    telegram_user.username
             FROM posted_permissions permission
             JOIN posted_channels channel_row ON channel_row.id=permission.channel_id
             LEFT JOIN posted_users telegram_user ON telegram_user.telegram_id=permission.user_id
             WHERE permission.channel_id IN (" . implode(',', array_fill(0, count($telegramChannelIds), '?')) . ")
               AND permission.user_id<>COALESCE(channel_row.added_by,0)
               AND (
                   permission.can_post=1
                   OR permission.can_schedule=1
                   OR permission.can_manage_posts=1
               )
             ORDER BY permission.channel_id,telegram_user.first_name,telegram_user.username,permission.user_id"
        );
        $telegramEditorStatement->execute($telegramChannelIds);
        foreach ($telegramEditorStatement->fetchAll() as $editor) {
            $telegramEditorsByChannel[(int)$editor['channel_id']][]=$editor;
        }
    }
    try {
        $maxTableCount=(int)$pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name IN (
                   'posted_max_users',
                   'posted_max_channels',
                   'posted_max_permissions',
                   'posted_max_posts'
               )"
        )->fetchColumn();
        $maxSchemaInstalled=$maxTableCount === 4;
        if ($maxSchemaInstalled) {
            $stats['max_users_total']=scalar($pdo,'SELECT COUNT(*) FROM posted_max_users');
            $stats['max_users_linked']=scalar($pdo,'SELECT COUNT(*) FROM posted_max_users WHERE linked_telegram_id IS NOT NULL');
            $stats['max_users']=scalar($pdo,'SELECT COUNT(*) FROM posted_max_users WHERE linked_telegram_id IS NULL');
            $stats['max_channels']=scalar($pdo,'SELECT COUNT(*) FROM posted_max_channels WHERE is_active=1');
            $stats['max_posts']=scalar($pdo,"SELECT COUNT(*) FROM posted_max_posts WHERE status IN ('sent','scheduled')");
            $maxUsers=$pdo->query(
                "SELECT
                    u.max_user_id,u.username,u.display_name,u.private_chat_id,
                    u.linked_telegram_id,u.role,u.is_active,u.user_timezone,
                    u.created_at,u.updated_at,
                    COALESCE(pr.channel_count,0) AS channel_count,
                    COALESCE(pr.can_post_count,0) AS can_post_count,
                    COALESCE(pr.can_schedule_count,0) AS can_schedule_count,
                    COALESCE(pr.can_manage_count,0) AS can_manage_count
                 FROM posted_max_users u
                 LEFT JOIN (
                    SELECT
                        max_user_id,
                        COUNT(*) AS channel_count,
                        SUM(can_post=1) AS can_post_count,
                        SUM(can_schedule=1) AS can_schedule_count,
                        SUM(can_manage=1) AS can_manage_count
                    FROM posted_max_permissions
                    WHERE can_post=1 OR can_schedule=1 OR can_manage=1
                    GROUP BY max_user_id
                 ) pr ON pr.max_user_id=u.max_user_id
                 ORDER BY u.updated_at DESC
                 LIMIT 100"
            )->fetchAll();
            $maxChannels=$pdo->query(
                "SELECT
                    channel_row.id,channel_row.chat_id,channel_row.title,channel_row.chat_type,
                    channel_row.bot_status,channel_row.can_post,channel_row.can_pin,
                    channel_row.can_delete,channel_row.rights_json,channel_row.rights_checked_at,
                    channel_row.added_by,channel_row.is_active,channel_row.admin_blocked,
                    channel_row.updated_at,
                    owner_user.display_name AS owner_name,
                    owner_user.username AS owner_username
                 FROM posted_max_channels channel_row
                 LEFT JOIN posted_max_users owner_user ON owner_user.max_user_id=channel_row.added_by
                 ORDER BY channel_row.updated_at DESC
                 LIMIT 100"
            )->fetchAll();
            $maxChannelIds=array_map(static fn(array $channel): int => (int)$channel['id'], $maxChannels);
            if ($maxChannelIds !== []) {
                $maxEditorStatement=$pdo->prepare(
                    "SELECT permission.channel_id,
                            permission.max_user_id AS user_id,
                            max_user.display_name,
                            max_user.username
                     FROM posted_max_permissions permission
                     JOIN posted_max_channels channel_row ON channel_row.id=permission.channel_id
                     LEFT JOIN posted_max_users max_user ON max_user.max_user_id=permission.max_user_id
                     WHERE permission.channel_id IN (" . implode(',', array_fill(0, count($maxChannelIds), '?')) . ")
                       AND permission.max_user_id<>COALESCE(channel_row.added_by,'')
                       AND (
                           permission.can_post=1
                           OR permission.can_schedule=1
                           OR permission.can_manage=1
                       )
                     ORDER BY permission.channel_id,max_user.display_name,max_user.username,permission.max_user_id"
                );
                $maxEditorStatement->execute($maxChannelIds);
                foreach ($maxEditorStatement->fetchAll() as $editor) {
                    $maxEditorsByChannel[(int)$editor['channel_id']][]=$editor;
                }
            }
            $linkedRows=$pdo->query(
                "SELECT max_user_id,username,display_name,linked_telegram_id
                 FROM posted_max_users
                 WHERE linked_telegram_id IS NOT NULL"
            )->fetchAll();
            foreach ($linkedRows as $linked) {
                $telegramId=(int)($linked['linked_telegram_id'] ?? 0);
                if ($telegramId > 0) $maxLinkedByTelegram[$telegramId]=$linked;
            }
        } elseif ($maxTableCount > 0) {
            $maxDataError='Схема МАКС установлена не полностью. Запустите max-setup.php из CLI.';
        }
    } catch (Throwable) {
        $maxSchemaInstalled=false;
        $maxDataError='Данные МАКС временно недоступны; Telegram продолжает работать отдельно.';
    }
    $stats['users']=$stats['tg_users']+$stats['max_users'];
    $stats['channels']=$stats['tg_channels']+$stats['max_channels'];
    $stats['posts']=$stats['tg_posts']+$stats['max_posts'];
}
require __DIR__ . '/panel.php';
exit;
