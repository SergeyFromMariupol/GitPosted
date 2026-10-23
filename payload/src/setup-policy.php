<?php

declare(strict_types=1);

/** Explicit CLI takeover and safe summaries; this module performs no I/O. */
final class GitPostedSetupPolicyFailure extends RuntimeException {}

/** @return list<string> Exact foreign URLs are kept private for approved API calls. */
function gitpostedSetupRequireTakeover(array $rows, string $target, bool $takeover): array
{
    $foreign = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['url'] ?? null) || $row['url'] === '') {
            throw new GitPostedSetupPolicyFailure('Платформа не подтвердила список webhook. Настройка не изменена.');
        }
        if (!hash_equals($target, $row['url'])) $foreign[] = $row['url'];
    }
    if ($foreign !== [] && !$takeover) {
        throw new GitPostedSetupPolicyFailure('У бота уже подключён другой сервис. Для разрешённого переноса повторите команду с --takeover.');
    }
    return array_values(array_unique($foreign));
}

/** @return list<string> Foreign paths and queries can contain credentials. */
function gitpostedSetupSafeWebhookUrls(array $rows): array
{
    $safe = [];
    foreach ($rows as $row) {
        $parts = parse_url((string) ($row['url'] ?? ''));
        if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) continue;
        $path = $parts['path'] ?? '/';
        if (!in_array($path, ['/', '/webhook.php', '/max-webhook.php'], true)) $path = '/[configured-webhook]';
        $safe[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }
    return array_values(array_unique($safe));
}

function gitpostedSetupSafeError(Throwable $error): string
{
    return $error instanceof GitPostedSetupPolicyFailure
        ? $error->getMessage()
        : 'Проверка конфигурации или API не завершена. Проверьте параметры и повторите команду; состояние webhook требует проверки.';
}
