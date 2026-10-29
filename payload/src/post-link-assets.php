<?php

declare(strict_types=1);
require_once __DIR__.'/telegram-template-media.php';

/** Private immutable media. Only opaque generated markers enter post payloads. */
final class GitPostedPostLinkAssets
{
    private const PREFIX = 'post-link-asset:';

    public static function persist(array $config, string $source, string $mime, int $size, string $filename = ''): string
    {
        $base = GitPostedPostLink::dataRoot($config);
        $root = $base . '/storage/post-link-media';
        GitPostedPostLink::privateDirectory($root, $base . '/storage');
        $contentHash = hash_file('sha256', $source);
        if (!is_string($contentHash) || $size < 1 || $size > 536870912 || filesize($source) !== $size) throw new RuntimeException('Некорректное медиа поста.');
        $mime = preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D', $mime) === 1 ? $mime : 'application/octet-stream';
        $name = basename(str_replace('\\', '/', $filename !== '' ? $filename : $source));
        $name = mb_substr(preg_replace('/[\x00-\x1F\x7F"]/', '_', $name) ?? 'media', 0, 150);
        if ($name === '' || $name === '.' || $name === '..') $name = 'media';
        $hash = hash('sha256', "v1\0" . $contentHash . "\0" . $mime . "\0" . $name);
        $blob = $root . '/' . $hash . '.blob';
        $meta = $root . '/' . $hash . '.json';
        $lock = fopen($root . '/.write-lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Хранилище медиа занято.');
        try {
            if (file_exists($blob)) {
                if (is_link($blob) || !is_file($blob) || filesize($blob) !== $size || !hash_equals($contentHash, (string) hash_file('sha256', $blob))) throw new RuntimeException('Медиа в хранилище повреждено.');
            } else {
                $free = disk_free_space($root);
                if ($free === false || $free < $size + 1073741824) throw new RuntimeException('Недостаточно свободного места для импорта медиа.');
                $temporary = $root . '/.' . bin2hex(random_bytes(16));
                try {
                    if (!copy($source, $temporary) || !chmod($temporary, 0600) || filesize($temporary) !== $size || !hash_equals($contentHash, (string) hash_file('sha256', $temporary)) || !rename($temporary, $blob)) throw new RuntimeException('Не удалось сохранить медиа поста.');
                } finally { if (is_file($temporary) && !is_link($temporary)) unlink($temporary); }
            }
            if (!file_exists($meta)) {
                $temporary = $root . '/.' . bin2hex(random_bytes(16));
                try {
                    $encoded = json_encode(['asset_id' => $hash, 'sha256' => $contentHash, 'size' => $size, 'mime' => $mime, 'filename' => $name], JSON_THROW_ON_ERROR);
                    if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || !chmod($temporary, 0600) || !rename($temporary, $meta)) throw new RuntimeException('Не удалось сохранить описание медиа.');
                } finally { if (is_file($temporary) && !is_link($temporary)) unlink($temporary); }
            }
            if (is_link($meta) || !is_file($meta)) throw new RuntimeException('Описание медиа повреждено.');
            $existing = json_decode((string) file_get_contents($meta), true);
            if (!is_array($existing) || ($existing['asset_id'] ?? '') !== $hash || ($existing['sha256'] ?? '') !== $contentHash
                || ($existing['size'] ?? null) !== $size || ($existing['mime'] ?? '') !== $mime || ($existing['filename'] ?? '') !== $name) throw new RuntimeException('Описание медиа повреждено.');
            touch($blob);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return self::PREFIX . $hash;
    }

    public static function upload(string $marker, array $config): CURLFile
    {
        if (preg_match('/^post-link-asset:([a-f0-9]{64})$/D', $marker, $match) !== 1) throw new RuntimeException('Некорректный идентификатор медиа.');
        $base = GitPostedPostLink::dataRoot($config);
        $root = $base . '/storage/post-link-media';
        $blob = $root . '/' . $match[1] . '.blob'; $meta = $root . '/' . $match[1] . '.json';
        if (realpath($root) !== $root || is_link($root) || is_link($blob) || is_link($meta)
            || realpath($blob) !== $blob || realpath($meta) !== $meta || !is_file($blob) || !is_file($meta)) throw new RuntimeException('Медиа импортированного поста недоступно.');
        $data = json_decode((string) file_get_contents($meta), true);
        if (!is_array($data) || ($data['asset_id'] ?? '') !== $match[1] || preg_match('/^[a-f0-9]{64}$/D', (string) ($data['sha256'] ?? '')) !== 1
            || (int) ($data['size'] ?? -1) !== filesize($blob) || !hash_equals($data['sha256'], (string) hash_file('sha256', $blob))) throw new RuntimeException('Медиа импортированного поста повреждено.');
        $mime = (string) ($data['mime'] ?? 'application/octet-stream');
        if (preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D', $mime) !== 1) throw new RuntimeException('Некорректный тип медиа.');
        return new CURLFile($blob, $mime, basename((string) ($data['filename'] ?? 'media')));
    }

    /** Return multipart payload only for validated private-asset markers. */
    public static function telegramMultipart(string $method, array $payload, array $config): ?array
    {
        $fields = ['sendPhoto' => 'photo', 'sendVideo' => 'video', 'sendAnimation' => 'animation', 'sendDocument' => 'document', 'sendAudio' => 'audio', 'sendVoice' => 'voice'];
        if (isset($fields[$method])) {
            $field = $fields[$method];
            $value = $payload[$field] ?? null;
            if (!is_string($value) || !str_starts_with($value, self::PREFIX)) return null;
            $payload[$field] = self::upload($value, $config);
            return $payload;
        }
        if (!in_array($method, ['sendMediaGroup', 'sendPaidMedia', 'editMessageMedia'], true)) return null;
        $media = $payload['media'] ?? null;
        if (is_string($media)) $media = json_decode($media, true);
        if (!is_array($media)) return null;
        if($method==='sendPaidMedia'){
            if(!array_is_list($media)||$media===[]||count($media)>10)throw new InvalidArgumentException('Invalid paid media upload count.');
            foreach($media as$item)if(!is_array($item)||!in_array($item['type']??null,['photo','video'],true))throw new InvalidArgumentException('Invalid paid media upload type.');
        }
        $items = $method === 'editMessageMedia' ? [$media] : $media;
        $changed = false;
        foreach ($items as $index => &$item) {
            if (!is_array($item) || !is_string($item['media'] ?? null) || !str_starts_with($item['media'], self::PREFIX)) continue;
            $key = 'post_link_' . $index;
            $payload[$key] = self::upload($item['media'], $config);
            $item['media'] = 'attach://' . $key;
            $changed = true;
        }
        unset($item);
        if (!$changed) return null;
        $payload['media'] = $method === 'editMessageMedia' ? $items[0] : $items;
        return $payload;
    }

    public static function containsMarker(array $payload): bool
    {
        foreach ($payload as $value) {
            if (is_string($value) && str_contains($value, self::PREFIX)) return true;
            if (is_array($value) && self::containsMarker($value)) return true;
        }
        return false;
    }

    /** Expire only unreferenced abandoned imports; drafts, templates and plans retain media. */
    public static function cleanup(PDO $pdo, array $config): int
    {
        $base = GitPostedPostLink::dataRoot($config); $root = $base . '/storage/post-link-media';
        if (!file_exists($root)) return 0;
        if (realpath($root) !== $root || is_link($root) || $pdo->inTransaction()) throw new RuntimeException('Хранилище импорта недоступно.');
        $lock = fopen($root . '/.write-lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) fclose($lock); return 0; }
        try {
            $stamp = $root . '/.last-sweep';
            if (is_file($stamp) && filemtime($stamp) > time() - 3600) return 0;
            $used = [];
            foreach (['SELECT media_file_id,media_json,import_json FROM posted_posts', 'SELECT state_data FROM posted_users', 'SELECT media_file_id FROM posted_templates'] as $sql) {
                $rows = $pdo->query($sql);
                while ($row = $rows->fetch(PDO::FETCH_NUM)) foreach ($row as $value) {
                    preg_match_all('/post-link-asset:([a-f0-9]{64})/', (string) $value, $matches);
                    foreach ($matches[1] as $id) $used[$id] = true;
                }
            }
            $used += PostedTelegramTemplateMedia::assetReferences($pdo);
            $removed = 0;
            foreach (new DirectoryIterator($root) as $entry) {
                if ($removed >= 1000) break;
                if (!$entry->isFile() || $entry->isLink() || preg_match('/^([a-f0-9]{64})\.blob$/D', $entry->getFilename(), $match) !== 1
                    || isset($used[$match[1]]) || $entry->getMTime() >= time() - 86400) continue;
                foreach (['.blob', '.json'] as $extension) {
                    $path = $root . '/' . $match[1] . $extension;
                    if (is_file($path) && !is_link($path) && realpath($path) === $path && dirname($path) === $root) unlink($path);
                }
                ++$removed;
            }
            if (!is_link($stamp)) { touch($stamp); chmod($stamp, 0600); }
            return $removed;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
