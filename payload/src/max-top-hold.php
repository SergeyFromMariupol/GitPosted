<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

final class GitPostedMaxTopHoldException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, public readonly ?array $blocking = null)
    {
        parent::__construct(match ($reason) {
            'hold_active' => 'В канале действует TOP. Следующая публикация доступна после окончания удержания.',
            'delivery_unknown' => 'Результат TOP-публикации ещё не подтверждён. Повторная отправка отключена.',
            'schedule_conflict' => 'Выбранное время пересекается с TOP или другой запланированной публикацией.',
            'quota_exhausted' => 'У автора закончился лимит TOP для этого канала.',
            'stale_revision' => 'Настройка TOP изменилась. Откройте пост заново.',
            default => 'Настройки TOP требуют проверки. Пост и дата сохранены.',
        });
    }
}

/** SQL-only MAX namespace. A MAX repeat has the same post ID and a NEW attempt ID. */
final class GitPostedMaxTopHold
{
    public const MAX_QUOTA = 1000000;
    public const MAX_REVISION = 2147483647;
    public const MAX_DURATION_MINUTES = 525600;
    private static function fail(string $reason = 'invalid_top', ?array $blocking = null): never { throw new GitPostedMaxTopHoldException($reason, $blocking); }
    private static function tx(PDO $pdo): void { if (!$pdo->inTransaction()) throw new LogicException('MAX TOP requires an existing locked transaction.'); }
    private static function id(int $id): int { if ($id < 1) self::fail(); return $id; }
    public static function userId(string $id): string { if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) self::fail('actor_denied'); return $id; }
    private static function keys(array $record, array $expected): void { $actual = array_keys($record); sort($actual); sort($expected); if ($actual !== $expected) self::fail(); }
    private static function rows(PDO $pdo, string $sql, array $args = []): array { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private static function raw(PDO $pdo, string $key, bool $lock = false): ?array
    {
        $r = self::rows($pdo, 'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock ? ' FOR UPDATE' : ''), [$key]);
        if ($r === []) return null;
        try { $value = json_decode($r[0]['setting_value'], true, 32, JSON_THROW_ON_ERROR); } catch (Throwable) { self::fail(); }
        if (!is_array($value)) self::fail(); return $value;
    }
    private static function write(PDO $pdo, string $key, array $value): void
    {
        self::tx($pdo); if (strlen($key) > 64) self::fail();
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
    public static function optionKey(int $id): string { return 'mxth_o_'.self::id($id); }
    public static function quotaKey(int $channel, string $user): string { return 'mxth_q_'.self::id($channel).'_'.self::userId($user); }
    public static function receiptKey(int $attempt): string { return 'mxth_a_'.self::id($attempt); }
    public static function channelKey(int $channel): string { return 'mxth_c_'.self::id($channel); }
    public static function templateKey(int $template): string { return 'mxth_t_'.self::id($template); }
    public static function defaults(): array { return ['version' => 1, 'duration_minutes' => 60]; }
    public static function normalizePolicy(mixed $value): array
    {
        if (!is_array($value)) self::fail(); self::keys($value, ['version', 'duration_minutes']);
        if ($value['version'] !== 1 || !is_int($value['duration_minutes']) || $value['duration_minutes'] < 1 || $value['duration_minutes'] > self::MAX_DURATION_MINUTES) self::fail(); return $value;
    }
    public static function fromConfig(array $config): array { return array_key_exists('telegram_top_hold', $config) ? self::normalizePolicy($config['telegram_top_hold']) : self::defaults(); }
    public static function normalize(mixed $value, array $post): array
    {
        if (!is_array($value)) self::fail(); self::keys($value, ['version','post_id','channel_id','enabled','duration_minutes','revision']);
        if ($value['version'] !== 1 || $value['post_id'] !== (int) $post['id'] || $value['channel_id'] !== (int) $post['channel_id'] || !is_bool($value['enabled']) || !is_int($value['revision']) || $value['revision'] < 0 || $value['revision'] > self::MAX_REVISION || !is_int($value['duration_minutes']) || $value['duration_minutes'] < 0 || $value['duration_minutes'] > self::MAX_DURATION_MINUTES || ($value['enabled'] && $value['duration_minutes'] < 1)) self::fail(); return $value;
    }
    public static function load(PDO $pdo, array $post, bool $lock = false): array
    {
        if (array_key_exists('_max_top_options', $post)) return self::normalize($post['_max_top_options'], $post);
        $value = self::raw($pdo, self::optionKey((int) $post['id']), $lock);
        return $value === null ? ['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'enabled'=>false,'duration_minutes'=>0,'revision'=>0] : self::normalize($value, $post);
    }
    public static function submission(PDO $pdo, array $post): ?array
    {
        $o = self::load($pdo, $post); return $o['enabled'] || $o['revision'] > 0 ? ['enabled'=>$o['enabled'],'duration_minutes'=>$o['duration_minutes'],'revision'=>$o['revision']] : null;
    }
    public static function quotaRecord(PDO $pdo, array $channel, string $user, bool $lock = false): array
    {
        $user = self::userId($user); $v = self::raw($pdo, self::quotaKey((int)$channel['id'], $user), $lock);
        if ($v === null) return ['version'=>1,'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'user_id'=>$user,'remaining'=>0,'revision'=>0,'generation'=>null];
        self::keys($v, ['version','channel_id','chat_id','user_id','remaining','revision','generation']);
        if ($v['version'] !== 1 || $v['channel_id'] !== (int)$channel['id'] || $v['chat_id'] !== (string)$channel['chat_id'] || $v['user_id'] !== $user || !is_int($v['remaining']) || $v['remaining'] < 0 || $v['remaining'] > self::MAX_QUOTA || !is_int($v['revision']) || $v['revision'] < 1 || $v['revision'] > self::MAX_REVISION || !is_string($v['generation']) || preg_match('/\A[a-f0-9]{32}\z/D', $v['generation']) !== 1) self::fail(); return $v;
    }
    public static function quota(PDO $pdo, array $channel, string $user, bool $lock = false): array { $v = self::quotaRecord($pdo,$channel,$user,$lock); return ['remaining'=>$v['remaining'],'revision'=>$v['revision']]; }
    public static function validateReceipt(array $v): array
    {
        self::keys($v,['version','attempt_id','post_id','channel_id','chat_id','duration_minutes','quota_user_id','quota_generation','charged','state','nonce','started_at','until_at','message_id','message_url']);
        if ($v['version'] !== 1 || !is_int($v['attempt_id']) || $v['attempt_id'] < 1 || !is_int($v['post_id']) || $v['post_id'] < 1 || !is_int($v['channel_id']) || $v['channel_id'] < 1 || !is_string($v['chat_id']) || $v['chat_id'] === '' || strlen($v['chat_id']) > 64 || !is_int($v['duration_minutes']) || $v['duration_minutes'] < 1 || $v['duration_minutes'] > self::MAX_DURATION_MINUTES || !is_string($v['quota_user_id']) || !is_bool($v['charged']) || !in_array($v['state'],['reserved','uncertain','active','refunded'],true) || !is_string($v['nonce']) || preg_match('/\A[a-f0-9]{32}\z/D',$v['nonce']) !== 1) self::fail();
        self::userId($v['quota_user_id']);
        if ($v['quota_generation'] !== null && (!is_string($v['quota_generation']) || preg_match('/\A[a-f0-9]{32}\z/D',$v['quota_generation']) !== 1)) self::fail();
        foreach (['started_at','until_at'] as $key) if ($v[$key] !== null && (!is_int($v[$key]) || $v[$key] < 1)) self::fail();
        if ($v['message_id'] !== null && (!is_string($v['message_id']) || $v['message_id'] === '' || strlen($v['message_id']) > 255 || preg_match('/[\x00-\x20\x7f]/',$v['message_id']))) self::fail();
        if ($v['message_url'] !== null && self::validUrl($v['message_url']) === null) self::fail();
        if ($v['charged'] && $v['quota_generation'] === null) self::fail();
        if (in_array($v['state'],['reserved','uncertain'],true) && ($v['started_at'] !== null || $v['until_at'] !== null || $v['message_id'] !== null || $v['message_url'] !== null)) self::fail();
        if ($v['state'] === 'active' && ($v['started_at'] === null || $v['until_at'] !== $v['started_at'] + $v['duration_minutes'] * 60 || $v['message_id'] === null)) self::fail(); return $v;
    }
    private static function assertAttemptBinding(array $receipt, array $attempt): void
    {
        if ($receipt['attempt_id'] !== (int)($attempt['id']??0) || $receipt['post_id'] !== (int)($attempt['post_id']??0) || $receipt['channel_id'] !== (int)($attempt['channel_id']??0) || $receipt['chat_id'] !== (string)($attempt['chat_id']??'')) self::fail();
    }
    public static function validUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return null;
        $p = parse_url($url); if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || strtolower((string)($p['host'] ?? '')) !== 'max.ru' || isset($p['pass']) || isset($p['user']) || isset($p['port']) || isset($p['fragment']) || !str_starts_with((string)($p['path'] ?? ''),'/')) return null; return $url;
    }
    public static function receipt(PDO $pdo, int $attempt, bool $lock = false): ?array
    {
        $v = self::raw($pdo,self::receiptKey($attempt),$lock); if ($v === null) return null; $v = self::validateReceipt($v); if ($v['attempt_id'] !== $attempt) self::fail(); return $v;
    }
    public static function hold(PDO $pdo, array $channel, bool $lock = false): ?array
    {
        $v = self::raw($pdo,self::channelKey((int)$channel['id']),$lock); if ($v === null) return null; $v = self::validateReceipt($v);
        if ($v['channel_id'] !== (int)$channel['id'] || $v['chat_id'] !== (string)$channel['chat_id']) self::fail(); return $v;
    }
    public static function publicHold(array $r): array { return ['post_id'=>$r['post_id'],'post_url'=>$r['message_url'],'until'=>$r['until_at'],'uncertain'=>in_array($r['state'],['reserved','uncertain'],true)]; }
    /** Exemption is an EXACT recorded attempt, never a post ID reused by repeat. */
    /** Filter held or malformed receipts BEFORE cron LIMIT; the locked reader is still final authority. */
    public static function dueSql(string $channelAlias = 'c'): string
    {
        if ($channelAlias !== 'c') throw new InvalidArgumentException('Fixed MAX TOP channel alias required.');
        $positive=['type'=>'integer','minimum'=>1];
        $schema=['type'=>'object','additionalProperties'=>false,'required'=>['version','attempt_id','post_id','channel_id','chat_id','duration_minutes','quota_user_id','quota_generation','charged','state','nonce','started_at','until_at','message_id','message_url'],'properties'=>[
            'version'=>['type'=>'integer','enum'=>[1]],'attempt_id'=>$positive,'post_id'=>$positive,'channel_id'=>$positive,
            'chat_id'=>['type'=>'string','minLength'=>1,'maxLength'=>64],
            'duration_minutes'=>['type'=>'integer','minimum'=>1,'maximum'=>self::MAX_DURATION_MINUTES],
            'quota_user_id'=>['type'=>'string','pattern'=>'^[1-9][0-9]{0,18}$'],
            'quota_generation'=>['type'=>['string','null'],'pattern'=>'^[a-f0-9]{32}$'],
            'charged'=>['type'=>'boolean'],'state'=>['type'=>'string','enum'=>['reserved','uncertain','active','refunded']],
            'nonce'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$'],
            'started_at'=>['type'=>['integer','null'],'minimum'=>1],'until_at'=>['type'=>['integer','null'],'minimum'=>1],
            'message_id'=>['type'=>['string','null'],'minLength'=>1,'maxLength'=>255,'pattern'=>'^[^\\x00-\\x20\\x7f]+$'],
            'message_url'=>['type'=>['string','null'],'maxLength'=>2048,'pattern'=>'^https://max[.]ru/[^\\x00-\\x20\\x7f\\\\#]*$'],
        ]];
        $json=json_encode($schema,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        // MySQL string literals need the JSON backslashes doubled independently.
        $literal="'".str_replace(["\\", "'"],["\\\\", "''"],$json)."'";
        $v='mxth_due.setting_value';$state="JSON_UNQUOTE(JSON_EXTRACT($v,'$.state'))";
        return "NOT EXISTS (SELECT 1 FROM posted_app_settings mxth_due WHERE mxth_due.setting_key=CONCAT('mxth_c_',c.id) AND CASE WHEN JSON_SCHEMA_VALID(".$literal.",".$v.")=1 AND JSON_EXTRACT(".$v.",'$.channel_id')=c.id AND BINARY JSON_UNQUOTE(JSON_EXTRACT(".$v.",'$.chat_id'))=BINARY c.chat_id AND (JSON_EXTRACT(".$v.",'$.charged')=false OR JSON_TYPE(JSON_EXTRACT(".$v.",'$.quota_generation'))='STRING') AND ((".$state."='refunded' AND JSON_EXTRACT(".$v.",'$.charged')=false) OR (".$state."='active' AND JSON_TYPE(JSON_EXTRACT(".$v.",'$.started_at'))='INTEGER' AND JSON_TYPE(JSON_EXTRACT(".$v.",'$.until_at'))='INTEGER' AND JSON_TYPE(JSON_EXTRACT(".$v.",'$.message_id'))='STRING' AND JSON_EXTRACT(".$v.",'$.until_at')=JSON_EXTRACT(".$v.",'$.started_at')+JSON_EXTRACT(".$v.",'$.duration_minutes')*60 AND JSON_EXTRACT(".$v.",'$.until_at')<=UNIX_TIMESTAMP())) THEN 0 ELSE 1 END=1)";
    }
    public static function assertAvailableLocked(PDO $pdo, array $channel, ?int $recordedAttempt = null): void
    {
        self::tx($pdo); $r = self::hold($pdo,$channel,true);
        if ($r === null || $r['state'] === 'refunded' || ($recordedAttempt !== null && $r['attempt_id'] === $recordedAttempt)) return;
        if (in_array($r['state'],['reserved','uncertain'],true)) self::fail('delivery_unknown',self::publicHold($r));
        if ($r['until_at'] > time()) self::fail('hold_active',self::publicHold($r));
    }
    private static function pendingBucket(PDO $pdo, array $channel, string $user): void
    {
        $hold = self::hold($pdo,$channel,true);
        if ($hold !== null && $hold['quota_user_id'] === $user && in_array($hold['state'],['reserved','uncertain'],true)) self::fail('delivery_unknown');
        $rows = self::rows($pdo,"SELECT s.setting_value FROM posted_max_publish_attempts a JOIN posted_app_settings s ON s.setting_key=CONCAT('mxth_a_',a.id) WHERE a.channel_id=? AND a.status IN ('sending','preparing','pending','needs_review') ORDER BY a.id LIMIT 1001",[(int)$channel['id']]);
        if (count($rows) > 1000) self::fail(); foreach ($rows as $row) { $r = self::validateReceipt(json_decode($row['setting_value'],true,32,JSON_THROW_ON_ERROR)); if ($r['quota_user_id'] === $user && in_array($r['state'],['reserved','uncertain'],true)) self::fail('delivery_unknown'); }
    }
    private static function setQuotaLocked(PDO $pdo, array $channel, string $target, int $remaining, int $revision): array
    {
        self::tx($pdo); self::userId($target); if ($remaining < 0 || $remaining > self::MAX_QUOTA || $revision < 0 || $revision >= self::MAX_REVISION || $target === (string)$channel['added_by']) self::fail('actor_denied');
        if (self::rows($pdo,'SELECT max_user_id FROM posted_max_users WHERE max_user_id=? AND is_active=1 LIMIT 1 FOR UPDATE',[$target]) === []) self::fail('actor_denied');
        $grant = self::rows($pdo,'SELECT can_post,can_schedule,can_manage FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1 FOR UPDATE',[(int)$channel['id'],$target]);
        if ($grant === [] || array_sum(array_map('intval',$grant[0])) < 1) self::fail('actor_denied');
        self::pendingBucket($pdo,$channel,$target); $b = self::quotaRecord($pdo,$channel,$target,true); if ($b['revision'] !== $revision) self::fail('stale_revision');
        $b['remaining'] = $remaining; $b['revision']++; $b['generation'] = bin2hex(random_bytes(16)); self::write($pdo,self::quotaKey((int)$channel['id'],$target),$b); return ['remaining'=>$remaining,'revision'=>$b['revision']];
    }
    public static function setQuotaForOwnerLocked(PDO $pdo, array $channel, string $issuer, string $target, int $remaining, int $revision): array
    {
        self::tx($pdo); $actual = self::rows($pdo,'SELECT * FROM posted_max_channels WHERE id=? LIMIT 1 FOR UPDATE',[(int)$channel['id']]);
        if ($actual === [] || (string)$actual[0]['added_by'] !== $issuer || (string)$actual[0]['chat_id'] !== (string)$channel['chat_id'] || empty($actual[0]['is_active'])) self::fail('actor_denied');
        $actors = array_values(array_unique([self::userId($issuer),self::userId($target)])); sort($actors,SORT_STRING);
        foreach ($actors as $actor) if (self::rows($pdo,'SELECT max_user_id FROM posted_max_users WHERE max_user_id=? AND is_active=1 LIMIT 1 FOR UPDATE',[$actor]) === []) self::fail('actor_denied');
        return self::setQuotaLocked($pdo,$actual[0],$target,$remaining,$revision);
    }
    public static function adminSetQuota(PDO $pdo, int $channelId, string $target, int $remaining, int $revision): array
    {
        if ($pdo->inTransaction()) throw new LogicException('MAX TOP admin quota starts outside SQL locks.'); $pdo->beginTransaction();
        try { $c = self::rows($pdo,'SELECT * FROM posted_max_channels WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE',[$channelId]); if ($c === []) self::fail('actor_denied'); $r = self::setQuotaLocked($pdo,$c[0],$target,$remaining,$revision); $pdo->commit(); return $r; } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    public static function epoch(string $value): int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone(date_default_timezone_get())); if (!$d || $d->format('Y-m-d H:i:s') !== $value) self::fail(); return $d->getTimestamp();
    }
    /** Current scheduled points plus known repeat occurrences; no historical ceiling. */
    private static function bookings(PDO $pdo, int $channelId, int $from, int $to): array
    {
        $rows = self::rows($pdo,"SELECT * FROM posted_max_posts WHERE channel_id=? AND ((status='scheduled' AND scheduled_at IS NOT NULL) OR (status='sent' AND repeat_remaining>0 AND repeat_next_at IS NOT NULL)) ORDER BY id LIMIT 10001",[$channelId]);
        if (count($rows) > 10000) self::fail(); $bookings = [];
        foreach ($rows as $post) {
            $option = self::load($pdo,$post); $at = self::epoch((string)($post['status'] === 'sent' ? $post['repeat_next_at'] : $post['scheduled_at'])); $interval = max(0,(int)($post['repeat_interval_minutes'] ?? 0)) * 60;
            $count = $post['status'] === 'sent' ? (int)$post['repeat_remaining'] : 1 + max(0,(int)($post['repeat_remaining'] ?? 0));
            if ($interval === 0) $count = 1;
            $rowFrom = $from - ($option['enabled'] ? $option['duration_minutes']*60 : 0);
            if ($at < $rowFrom && $interval > 0) { $skip = max(0,intdiv($rowFrom-$at+$interval-1,$interval)); $at += $skip*$interval; $count -= $skip; }
            for ($i = 0; $i < $count && $at < $to; $i++, $at += $interval) {
                if ($at + ($option['enabled'] ? $option['duration_minutes']*60 : 0) >= $from) $bookings[] = ['post'=>$post,'option'=>$option,'at'=>$at];
                if (count($bookings) > 10000) self::fail(); if ($interval === 0) break;
            }
        }
        return $bookings;
    }
    public static function assertRepeatLocked(PDO $pdo, array $post, int $minutes): void
    {
        self::tx($pdo); $o = self::load($pdo,$post,true); if ($minutes > 0 && $o['enabled'] && $minutes < $o['duration_minutes']) self::fail('schedule_conflict');
    }
    public static function assertScheduleLocked(PDO $pdo, array $channel, array $post, string $at, ?array $override = null): void
    {
        self::tx($pdo); $begin = self::epoch($at); $option = $override === null ? self::load($pdo,$post,true) : self::normalize($override,$post); $end = $begin + ($option['enabled'] ? $option['duration_minutes']*60 : 0);
        $hold = self::hold($pdo,$channel,true);
        // A schedule editing its source still cannot overlap an actual active hold of that source.
        if ($hold !== null && $hold['state'] !== 'refunded') {
            if (in_array($hold['state'],['reserved','uncertain'],true)) self::fail('delivery_unknown',self::publicHold($hold));
            if (($begin >= $hold['started_at'] && $begin < $hold['until_at']) || ($option['enabled'] && $begin < $hold['until_at'] && $end > $hold['started_at'])) self::fail('schedule_conflict',self::publicHold($hold));
        }
        foreach (self::bookings($pdo,(int)$channel['id'],$begin,max($begin+1,$end+1)) as $booking) {
            if ((int)$booking['post']['id'] === (int)$post['id']) continue;
            $b = $booking['at']; $other = $booking['option']; $e = $b + ($other['enabled'] ? $other['duration_minutes']*60 : 0);
            if (($other['enabled'] && $begin >= $b && $begin < $e) || ($option['enabled'] && (($b >= $begin && $b < $end) || ($other['enabled'] && $begin < $e && $end > $b)))) self::fail('schedule_conflict');
        }
    }
    public static function setLocked(PDO $pdo, array $config, array $post, array $channel, bool $enabled, int $revision): array
    {
        self::tx($pdo); if (!in_array($post['status'] ?? '',['draft','scheduled','failed'],true)) self::fail('post_locked'); $old = self::load($pdo,$post,true);
        if ($old['revision'] !== $revision || $revision >= self::MAX_REVISION) self::fail('stale_revision'); if ($old['enabled'] === $enabled) return $old;
        $hold = self::hold($pdo,$channel,true); if ($hold !== null && $hold['post_id'] === (int)$post['id'] && ($hold['state'] !== 'active' || $hold['until_at'] > time()) && $hold['state'] !== 'refunded') self::fail('post_locked');
        $next = $old; $next['enabled'] = $enabled; $next['revision']++;
        if ($enabled) { $next['duration_minutes'] = self::fromConfig($config)['duration_minutes']; if ((int)($post['repeat_interval_minutes'] ?? 0) > 0 && (int)$post['repeat_interval_minutes'] < $next['duration_minutes']) self::fail('schedule_conflict'); if ((string)$post['author_max_user_id'] !== (string)$channel['added_by'] && self::quotaRecord($pdo,$channel,(string)$post['author_max_user_id'],true)['remaining'] < 1) self::fail('quota_exhausted'); }
        if ($post['status'] === 'scheduled' && is_string($post['scheduled_at'] ?? null)) self::assertScheduleLocked($pdo,$channel,$post,$post['scheduled_at'],$next);
        self::write($pdo,self::optionKey((int)$post['id']),$next); return $next;
    }
    public static function cloneLocked(PDO $pdo, array $source, array $target): void
    {
        self::tx($pdo); $o = self::load($pdo,$source); if (!$o['enabled']) return; $o['post_id'] = (int)$target['id']; $o['channel_id'] = (int)$target['channel_id']; $o['revision'] = 1; self::write($pdo,self::optionKey((int)$target['id']),$o);
    }
    public static function toTemplateLocked(PDO $pdo, array $post, array $template): void { self::tx($pdo); $o = self::load($pdo,$post); if ($o['enabled']) self::write($pdo,self::templateKey((int)$template['id']),['version'=>1,'template_id'=>(int)$template['id'],'enabled'=>true,'duration_minutes'=>$o['duration_minutes']]); }
    public static function fromTemplateLocked(PDO $pdo, array $template, array $post): void
    {
        self::tx($pdo); $v = self::raw($pdo,self::templateKey((int)$template['id'])); if ($v === null) return; self::keys($v,['version','template_id','enabled','duration_minutes']);
        if ($v['version'] !== 1 || $v['template_id'] !== (int)$template['id'] || $v['enabled'] !== true || !is_int($v['duration_minutes']) || $v['duration_minutes'] < 1 || $v['duration_minutes'] > self::MAX_DURATION_MINUTES) self::fail();
        self::write($pdo,self::optionKey((int)$post['id']),['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'enabled'=>true,'duration_minutes'=>$v['duration_minutes'],'revision'=>1]);
    }
    public static function reserveLocked(PDO $pdo, array $post, array $channel, array $attempt, ?array $frozen = null): void
    {
        self::tx($pdo); self::assertAvailableLocked($pdo,$channel); $o = $frozen ?? self::load($pdo,$post,true); $o = self::normalize($o,$post);
        if (!$o['enabled']) return; if ((int)$attempt['post_id'] !== (int)$post['id'] || (int)$attempt['channel_id'] !== (int)$channel['id'] || (string)$attempt['chat_id'] !== (string)$channel['chat_id'] || $attempt['status'] !== 'sending') self::fail();
        $id = (int)$attempt['id']; if (self::receipt($pdo,$id,true) !== null) self::fail('delivery_unknown');
        self::assertRepeatLocked($pdo,array_replace($post,['_max_top_options'=>$o]),(int)($post['repeat_interval_minutes'] ?? 0)); self::assertScheduleLocked($pdo,$channel,$post,date('Y-m-d H:i:s'),$o);
        $user = self::userId((string)$post['author_max_user_id']); $charged = $user !== (string)$channel['added_by']; $generation = null;
        if ($charged) { $b = self::quotaRecord($pdo,$channel,$user,true); if ($b['remaining'] < 1 || $b['revision'] >= self::MAX_REVISION) self::fail('quota_exhausted'); $b['remaining']--; $b['revision']++; $generation = $b['generation']; self::write($pdo,self::quotaKey((int)$channel['id'],$user),$b); }
        $r = ['version'=>1,'attempt_id'=>$id,'post_id'=>(int)$post['id'],'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'duration_minutes'=>$o['duration_minutes'],'quota_user_id'=>$user,'quota_generation'=>$generation,'charged'=>$charged,'state'=>'reserved','nonce'=>bin2hex(random_bytes(16)),'started_at'=>null,'until_at'=>null,'message_id'=>null,'message_url'=>null];
        self::write($pdo,self::receiptKey($id),$r); self::write($pdo,self::channelKey((int)$channel['id']),$r);
    }
    public static function markUnknownLocked(PDO $pdo, array $attempt): void
    {
        self::tx($pdo); $r = self::receipt($pdo,(int)$attempt['id'],true); if ($r === null) return; self::assertAttemptBinding($r,$attempt); if (in_array($r['state'],['active','refunded'],true)) return;
        $r['state'] = 'uncertain'; self::write($pdo,self::receiptKey($r['attempt_id']),$r); self::write($pdo,self::channelKey($r['channel_id']),$r);
    }
    public static function finalizeKnownLocked(PDO $pdo, array $attempt, string $publishedAt, string $messageId, ?string $url = null): void
    {
        self::tx($pdo); $r = self::receipt($pdo,(int)$attempt['id'],true); if ($r === null) return; self::assertAttemptBinding($r,$attempt);
        if ($r['state'] === 'active') { if ($r['message_id'] !== $messageId) self::fail(); return; } if ($r['state'] === 'refunded' || $r['post_id'] !== (int)$attempt['post_id']) self::fail();
        $r['started_at'] = self::epoch($publishedAt); $r['until_at'] = $r['started_at'] + $r['duration_minutes']*60; $r['message_id'] = $messageId; $r['message_url'] = self::validUrl($url); $r['state'] = 'active'; $r = self::validateReceipt($r);
        $hold = self::raw($pdo,self::channelKey($r['channel_id']),true); if ($hold === null || self::validateReceipt($hold)['attempt_id'] !== $r['attempt_id']) self::fail();
        self::write($pdo,self::receiptKey($r['attempt_id']),$r); self::write($pdo,self::channelKey($r['channel_id']),$r);
    }
    public static function knownRejectLocked(PDO $pdo, array $attempt): void
    {
        self::tx($pdo); $r = self::receipt($pdo,(int)$attempt['id'],true); if ($r === null) return; self::assertAttemptBinding($r,$attempt); if ($r['state'] === 'refunded') return; if ($r['state'] === 'active') self::fail('delivery_unknown');
        if ($r['charged']) { $c = ['id'=>$r['channel_id'],'chat_id'=>$r['chat_id']]; $b = self::quotaRecord($pdo,$c,$r['quota_user_id'],true); if ($b['generation'] === $r['quota_generation']) { if ($b['remaining'] >= self::MAX_QUOTA || $b['revision'] >= self::MAX_REVISION) self::fail(); $b['remaining']++; $b['revision']++; self::write($pdo,self::quotaKey($r['channel_id'],$r['quota_user_id']),$b); } }
        $r['state'] = 'refunded'; $r['charged'] = false; self::write($pdo,self::receiptKey($r['attempt_id']),$r); $hold = self::raw($pdo,self::channelKey($r['channel_id']),true); if ($hold !== null && self::validateReceipt($hold)['attempt_id'] === $r['attempt_id']) self::write($pdo,self::channelKey($r['channel_id']),$r);
    }
    public static function state(PDO $pdo, array $config, array $post, array $channel, string $actor): array
    {
        self::userId($actor); $o = self::load($pdo,$post); $h = self::hold($pdo,$channel); $mutable = in_array($post['status'] ?? '',['draft','scheduled','failed'],true); $reason = $mutable ? 'ready' : 'post_locked';
        $unlimited = (string)$post['author_max_user_id'] === (string)$channel['added_by']; $q = $unlimited ? null : self::quotaRecord($pdo,$channel,(string)$post['author_max_user_id'])['remaining']; $blocking = null; $locked = false;
        if ($h !== null && $h['state'] !== 'refunded' && ($h['state'] !== 'active' || $h['until_at'] > time())) { $blocking = self::publicHold($h); $locked = true; $reason = $blocking['uncertain'] ? 'delivery_unknown' : 'hold_active'; }
        if (!$locked && !$unlimited && $q < 1) $reason = 'quota_exhausted';
        return ['enabled'=>$o['enabled'],'duration_minutes'=>$o['enabled'] ? $o['duration_minutes'] : self::fromConfig($config)['duration_minutes'],'revision'=>$o['revision'],'can_enable'=>$mutable && !$locked && ($unlimited || $q > 0),'can_disable'=>$mutable && !($locked && $h['post_id'] === (int)$post['id']),'quota_remaining'=>$q,'unlimited'=>$unlimited,'reason'=>$reason,'hold'=>$blocking];
    }
    public static function retireChannelQuotasLocked(PDO $pdo, array $channel): void
    {
        self::tx($pdo); $h = self::hold($pdo,$channel,true); if ($h !== null && in_array($h['state'],['reserved','uncertain'],true)) self::fail('delivery_unknown');
        $rows = self::rows($pdo,'SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP ? ORDER BY setting_key FOR UPDATE',['^mxth_q_'.(int)$channel['id'].'_[1-9][0-9]*$']);
        foreach ($rows as $r) { $v=json_decode($r['setting_value'],true,32,JSON_THROW_ON_ERROR);if(!is_array($v)||!is_string($v['user_id']??null))self::fail();$b=self::quotaRecord($pdo,$channel,$v['user_id'],true);if($r['setting_key']!==self::quotaKey((int)$channel['id'],$v['user_id'])||$b['revision']>=self::MAX_REVISION)self::fail();self::pendingBucket($pdo,$channel,$v['user_id']);$b['remaining']=0;$b['revision']++;$b['generation']=bin2hex(random_bytes(16));self::write($pdo,$r['setting_key'],$b); }
    }
    public static function transferQuotasLocked(PDO $pdo, array $channel, string $source, string $target, array $expectedSource, array $expectedTarget): void
    {
        self::tx($pdo); if ($source === $target) self::fail(); self::pendingBucket($pdo,$channel,$source);self::pendingBucket($pdo,$channel,$target);$a=self::quotaRecord($pdo,$channel,$source,true);$b=self::quotaRecord($pdo,$channel,$target,true);
        if($a!==$expectedSource||$b!==$expectedTarget)self::fail('stale_revision');if($a['revision']===0)return;if($a['remaining']+$b['remaining']>self::MAX_QUOTA||$b['revision']>=self::MAX_REVISION)self::fail();$b['remaining']+=$a['remaining'];$b['revision']++;$b['generation']=bin2hex(random_bytes(16));self::write($pdo,self::quotaKey((int)$channel['id'],$target),$b);$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::quotaKey((int)$channel['id'],$source)]);
    }
    /** Include raw exact sidecars in the existing transfer fingerprint; no historical receipt rewrite. */
    public static function quotaChannels(PDO $pdo, string $source): array
    {
        self::userId($source);$rows=self::rows($pdo,'SELECT setting_key FROM posted_app_settings WHERE setting_key REGEXP ? ORDER BY setting_key LIMIT 101',['^mxth_q_[1-9][0-9]*_'.$source.'$']);if(count($rows)>100)self::fail();$ids=[];foreach($rows as$row){if(preg_match('/\Amxth_q_([1-9][0-9]*)_[1-9][0-9]*\z/D',$row['setting_key'],$m)!==1)self::fail();$cid=(int)$m[1];$c=self::rows($pdo,'SELECT * FROM posted_max_channels WHERE id=? LIMIT 1',[$cid]);if($c===[])self::fail();self::quotaRecord($pdo,$c[0],$source);$ids[]=$cid;}sort($ids,SORT_NUMERIC);return $ids;
    }
    public static function transferScope(PDO $pdo, array $channels, array $posts, array $templates, string $source, string $target, array $attempts = []): array
    {
        $keys=[];foreach($channels as$c){$keys[]=self::channelKey((int)$c['id']);$keys[]=self::quotaKey((int)$c['id'],$source);$keys[]=self::quotaKey((int)$c['id'],$target);self::pendingBucket($pdo,$c,$source);self::pendingBucket($pdo,$c,$target);}
        foreach($posts as$post)$keys[]=self::optionKey((int)$post['id']);foreach($templates as$template)$keys[]=self::templateKey((int)$template['id']);foreach($attempts as$attempt)$keys[]=self::receiptKey((int)$attempt['id']);sort($keys,SORT_STRING);$values=[];foreach(array_unique($keys)as$key){$r=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1',[$key]);$values[$key]=$r===[]?null:$r[0]['setting_value'];}
        foreach($posts as$post)self::load($pdo,$post);foreach($channels as$c){self::hold($pdo,$c);self::quotaRecord($pdo,$c,$source);self::quotaRecord($pdo,$c,$target);}foreach($attempts as$a){$v=self::receipt($pdo,(int)$a['id']);if($v!==null&&($v['post_id']!==(int)$a['post_id']||$v['channel_id']!==(int)$a['channel_id']||$v['chat_id']!==(string)$a['chat_id']))self::fail();}
        foreach($templates as$t){$v=self::raw($pdo,self::templateKey((int)$t['id']));if($v!==null){self::keys($v,['version','template_id','enabled','duration_minutes']);if($v['version']!==1||$v['template_id']!==(int)$t['id']||$v['enabled']!==true||!is_int($v['duration_minutes'])||$v['duration_minutes']<1||$v['duration_minutes']>self::MAX_DURATION_MINUTES)self::fail();}}
        return $values;
    }
    public static function graph(PDO $pdo, array $config, array $channel, string $actor, string $day, string $timezone, bool $manage, ?int $durationMinutes = null): array
    {
        self::userId($actor); $zone = new DateTimeZone($timezone); $date = DateTimeImmutable::createFromFormat('!Y-m-d',$day,$zone); if (!$date || $date->format('Y-m-d') !== $day) self::fail();
        $start = $date->getTimestamp(); $end = $date->modify('+1 day')->getTimestamp(); $duration = $durationMinutes ?? self::fromConfig($config)['duration_minutes']; if ($duration < 1 || $duration > self::MAX_DURATION_MINUTES) self::fail(); $seconds = $duration*60; $intervals = []; $points = []; $known = true;
        $pending = self::rows($pdo,"SELECT id FROM posted_max_publish_attempts WHERE channel_id=? AND status IN ('sending','preparing','pending','needs_review') LIMIT 1",[(int)$channel['id']]); if ($pending !== []) $known = false;
        $h = self::hold($pdo,$channel); if ($h !== null && $h['state'] !== 'refunded') { if ($h['state'] !== 'active') $known = false; elseif ($h['until_at'] > $start && $h['started_at'] < $end+$seconds) $intervals[] = ['begin'=>$h['started_at'],'end'=>$h['until_at'],'kind'=>'active_top','label'=>'TOP','post_url'=>$h['message_url']]; }
        foreach (self::bookings($pdo,(int)$channel['id'],$start,$end+$seconds) as $b) {
            $p = $b['post']; $label = $manage || (string)$p['author_max_user_id'] === $actor ? mb_substr(trim((string)($p['text'] ?? '')) ?: 'Публикация',0,120) : 'Занято';
            if ($b['option']['enabled']) $intervals[] = ['begin'=>$b['at'],'end'=>$b['at']+$b['option']['duration_minutes']*60,'kind'=>'scheduled_top','label'=>$label,'post_url'=>null]; else $points[] = ['at'=>$b['at'],'label'=>$label,'post_url'=>null];
        }
        $truncated = count($intervals)>200 || count($points)>200; if ($truncated) $known = false;
        $forbidden = []; foreach ($intervals as $i) $forbidden[] = [max($start,$i['begin']-$seconds+1),min($end,$i['end'])]; foreach ($points as $p) $forbidden[] = [max($start,$p['at']-$seconds+1),min($end,$p['at']+1)]; usort($forbidden,static fn($a,$b)=>$a[0]<=>$b[0]);
        $free = []; $cursor = $start; foreach ($forbidden as [$a,$b]) { if ($b <= $a) continue; if ($a>$cursor) $free[]=['begin'=>$cursor,'end'=>$a]; $cursor=max($cursor,$b); } if ($cursor<$end) $free[]=['begin'=>$cursor,'end'=>$end];
        if (count($free)>200) { $known=false; $truncated=true; }
        return ['channel_id'=>(int)$channel['id'],'channel_title'=>(string)$channel['title'],'day'=>$day,'today'=>(new DateTimeImmutable('now',$zone))->format('Y-m-d'),'timezone'=>$timezone,'day_start'=>$start,'day_end'=>$end,'duration_minutes'=>$duration,'availability_known'=>$known,'intervals'=>array_slice($intervals,0,200),'points'=>array_slice($points,0,200),'free_intervals'=>$known?$free:[],'truncated'=>$truncated];
    }
}

trait GitPostedMaxTopHoldIntegration
{
    private function assertMaxTopScheduleLocked(int $postId, string $at): void
    {
        if(!$this->pdo->inTransaction())throw new LogicException('MAX schedule check requires locked transaction.');$s=$this->pdo->prepare('SELECT * FROM posted_max_posts WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$postId]);$p=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($p))throw new GitPostedMaxTopHoldException('post_locked');$c=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? LIMIT 1');$c->execute([(int)$p['channel_id']]);$channel=$c->fetch(PDO::FETCH_ASSOC);if(!is_array($channel))throw new GitPostedMaxTopHoldException('post_locked');GitPostedMaxTopHold::assertScheduleLocked($this->pdo,$channel,$p,$at);
    }
    private function restoreMaxTopBlockedPublication(int $postId, bool $repeat): void
    {
        if($repeat)return; $this->pdo->prepare("UPDATE posted_max_posts p SET p.status=IF(p.scheduled_at IS NULL,'draft','scheduled') WHERE p.id=? AND p.status='publishing' AND NOT EXISTS(SELECT 1 FROM posted_max_publish_attempts a WHERE a.gate_post_id=p.id)")->execute([$postId]);
    }
    private function maxTopHoldOptions(string $actor, int|array $post): array
    {
        $id = is_array($post) ? (int)$post['id'] : $post;
        return $this->withManagedMaxPostLock($actor,$id,'manage',['draft','scheduled','failed','sent','deleted','cancelled'],fn(array $p,array $c):array=>GitPostedMaxTopHold::state($this->pdo,$this->config,$p,$c,$actor));
    }
    private function setMaxTopHold(string $actor, int $postId, bool $enabled, ?int $revision = null): array
    {
        return $this->withManagedMaxPostLock($actor,$postId,'manage',['draft','scheduled','failed'],function(array $p,array $c)use($enabled,$revision,$actor,$postId):array{
            $o=GitPostedMaxTopHold::load($this->pdo,$p,true);$expected=$revision??$o['revision'];
            $effect=$this->completeMaxUpdateEffectLocked('max_top_option',function()use($p,$c,$enabled,$expected,$actor,$postId):array{GitPostedMaxTopHold::setLocked($this->pdo,$this->config,$p,$c,$enabled,$expected);return['actor_id'=>$actor,'post_id'=>$postId,'enabled'=>$enabled,'expected_revision'=>$expected];});
            if(($effect['actor_id']??null)!==$actor||($effect['post_id']??null)!==$postId||($effect['enabled']??null)!==$enabled||($effect['expected_revision']??null)!==$expected)throw new GitPostedMaxTopHoldException('stale_revision');
            return GitPostedMaxTopHold::state($this->pdo,$this->config,$p,$c,$actor);
        });
    }
    private function maxTopPlacementGraph(string $actor, int $channelId, string $day, ?int $postId = null): array
    {
        // Calendar reads stay SQL-only; an optional duration comes from an owned current draft.
        if ($this->pdo->inTransaction()) throw new LogicException('MAX TOP graph starts outside SQL locks.');
        $this->pdo->beginTransaction();
        try {
            $s=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? AND is_active=1 LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$c=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($c))throw new GitPostedMaxTopHoldException('actor_denied');
            $this->assertActiveMaxActorLocked($actor);$owner=(string)$c['added_by']===$actor;
            $g=$this->pdo->prepare('SELECT can_post,can_schedule,can_manage FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1 FOR UPDATE');$g->execute([$channelId,$actor]);$permission=$g->fetch(PDO::FETCH_ASSOC);
            if(!$owner&&(!is_array($permission)||array_sum(array_map('intval',$permission))<1))throw new GitPostedMaxTopHoldException('actor_denied');
            $duration=null;
            if($postId!==null){
                if(!$owner&&(int)($permission['can_schedule']??0)!==1)throw new GitPostedMaxTopHoldException('actor_denied');
                if(!empty($c['is_archived'])||!empty($c['admin_blocked']))throw new GitPostedMaxTopHoldException('actor_denied');
                $s=$this->pdo->prepare("SELECT * FROM posted_max_posts WHERE id=? AND channel_id=? AND author_max_user_id=? AND status='draft' LIMIT 1 FOR UPDATE");$s->execute([$postId,$channelId,$actor]);$p=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($p))throw new GitPostedMaxTopHoldException('actor_denied');
                $this->assertNoActivePublicationAttempt($postId);$o=GitPostedMaxTopHold::load($this->pdo,$p,true);if($o['enabled'])$duration=$o['duration_minutes'];
            }
            $u=$this->user($actor);$r=GitPostedMaxTopHold::graph($this->pdo,$this->config,$c,$actor,$day,(string)($u['user_timezone']??'Europe/Moscow'),$owner||!empty($permission['can_manage']),$duration);
            $this->pdo->commit();return$r;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
}
