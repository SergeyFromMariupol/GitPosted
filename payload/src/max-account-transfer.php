<?php

declare(strict_types=1);
require_once __DIR__.'/max-top-hold.php';

/** Move existing MAX records between users without changing account identities. */
final class PostedMaxAccountTransfer
{
    private readonly Closure $max;
    private bool $locking = false;

    /** The read transport receives GET, a complete path, and no request body. */
    public function __construct(private readonly PDO $pdo, callable $max)
    {
        $this->max = Closure::fromCallable($max);
    }

    public function resolveRecipient(string $input): array
    {
        $input = trim($input);
        if (self::validUserId($input)) return $this->profile($this->activeUser($input));
        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,63})$/D', $input, $match) !== 1) {
            throw new DomainException('Укажите @username или положительный числовой ID аккаунта МАКС.');
        }
        $users = $this->rows('SELECT * FROM posted_max_users WHERE LOWER(username)=LOWER(?) AND is_active=1 ORDER BY max_user_id LIMIT 11', [$match[1]]);
        if (count($users) > 10) throw new DomainException('Укажите числовой ID получателя в МАКС.');
        $matches = [];
        foreach ($users as $user) {
            try { $profile = $this->profile($user); }
            catch (DomainException) { continue; }
            if (strcasecmp((string) $profile['username'], $match[1]) === 0) $matches[] = $profile;
        }
        if (count($matches) !== 1) {
            throw new DomainException('Не удалось однозначно подтвердить @username. Получатель должен запустить этого бота; затем укажите его числовой ID МАКС.');
        }
        return $matches[0];
    }

    public function preview(string $sourceId, string $targetId): array
    {
        $this->assertIds($sourceId, $targetId);
        $scope = $this->scope($sourceId, $targetId);
        $this->validate($scope, $sourceId, $targetId);
        return $this->summary($scope, $sourceId, $targetId);
    }

    public function transfer(string $sourceId, string $targetId, string $fingerprint, string $nonce): array
    {
        $this->assertIds($sourceId, $targetId);
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || preg_match('/^[a-f0-9]{24}$/D', $nonce) !== 1) {
            throw new DomainException('Подтверждение переноса недействительно. Откройте перенос заново.');
        }
        if ($this->pdo->inTransaction()) throw new RuntimeException('MAX account transfer requires its own transaction.');
        $source = $this->activeUser($sourceId);
        $receipt = $this->receipt($source, $targetId, $fingerprint, $nonce);
        if ($receipt !== null) return $receipt;
        $this->confirmation($source, $targetId, $fingerprint, $nonce);
        // Every external read precedes advisory locks and the SQL transaction.
        $before = $this->scope($sourceId, $targetId);
        $this->validate($before, $sourceId, $targetId);
        if (!hash_equals($fingerprint, $this->summary($before, $sourceId, $targetId)['fingerprint'])) {
            throw new DomainException('Данные или права изменились. Откройте перенос заново и проверьте его состав.');
        }
        $destinationIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $before['channels']), true);
        $lockChannels = [];
        foreach ($before['channels'] as $channel) $lockChannels[(int)$channel['id']] = $channel;
        foreach ($before['native_source_records'] as $record) {
            if ($record['is_public'] === false && is_array($record['channel'])) {
                $lockChannels[(int)$record['channel']['id']] = $record['channel'];
            }
        }
        ksort($lockChannels, SORT_NUMERIC);
        $actorIds = [$sourceId, $targetId];
        foreach ($before['native_source_records'] as $record) {
            if ($record['is_public'] === false && is_array($record['owner'])) {
                $actorIds[] = (string)$record['owner']['max_user_id'];
            }
        }
        $actorIds = array_values(array_unique($actorIds));
        sort($actorIds, SORT_STRING);
        $locks = [];
        try {
            $this->acquire('gitposted_max_cron', $locks);
            foreach ($lockChannels as $channel) {
                $this->acquire('gitposted_max_channel_' . substr(hash('sha256', (string)$channel['chat_id']), 0, 32), $locks);
                if (isset($destinationIds[(int)$channel['id']])) {
                    $this->acquire('gitposted_max_pin_' . substr(hash('sha256', (string)$channel['id']), 0, 28), $locks);
                }
            }
            foreach ($before['channel_posts'] as $post) $this->acquire('gitposted_max_post_' . (int)$post['id'], $locks);
            $this->pdo->beginTransaction();
            // Known channel rows serialize all actions within those channels.
            foreach ($lockChannels as $channel) $this->rows('SELECT id FROM posted_max_channels WHERE id=? FOR UPDATE', [(int)$channel['id']]);
            $ids = [$sourceId, $targetId]; sort($ids, SORT_STRING);
            // Private source-owner activity is authority too. Lock those rows
            // in the same initial order, before any post/template locks.
            $this->rows('SELECT max_user_id FROM posted_max_users WHERE max_user_id IN ('
                . implode(',', array_fill(0, count($actorIds), '?')) . ') ORDER BY max_user_id FOR UPDATE', $actorIds);
            foreach ($before['channel_posts'] as $post) $this->rows('SELECT id FROM posted_max_posts WHERE id=? FOR UPDATE', [(int)$post['id']]);
            // Actor rows, not compatible empty gap locks, serialize new records.
            foreach ($ids as $owner) $this->rows('SELECT id FROM posted_max_templates WHERE owner_max_user_id=? ORDER BY id FOR UPDATE', [$owner]);
            foreach ($before['channels'] as $channel) {
                $this->rows('SELECT max_user_id FROM posted_max_permissions WHERE channel_id=? ORDER BY max_user_id FOR UPDATE', [(int)$channel['id']]);
                $this->rows('SELECT id FROM posted_max_manager_categories WHERE channel_id=? ORDER BY id FOR UPDATE', [(int)$channel['id']]);
            }
            foreach ($before['native_source_records'] as $record) {
                if ($record['is_public'] === false && is_array($record['channel'])
                    && !isset($destinationIds[(int)$record['channel']['id']]) && is_array($record['target_grant'])) {
                    $this->rows('SELECT max_user_id FROM posted_max_permissions WHERE max_user_id=? AND channel_id=? FOR UPDATE', [$targetId, (int)$record['channel']['id']]);
                }
            }
            $this->locking = true;
            $source = $this->activeUser($sourceId);
            $receipt = $this->receipt($source, $targetId, $fingerprint, $nonce);
            if ($receipt !== null) { $this->pdo->commit(); return $receipt; }
            $state = $this->confirmation($source, $targetId, $fingerprint, $nonce);
            // No consistent snapshot was opened before these channel/actor/
            // template locks. Commits during preflight are visible and rejected.
            $scope = $this->scope($sourceId, $targetId);
            $scope['target_profile'] = $before['target_profile'];
            $sourceFacts = [];
            foreach ($before['native_source_records'] as $record) $sourceFacts[$record['chat_id']] = $record['is_public'];
            foreach ($scope['native_source_records'] as &$record) {
                if (!array_key_exists($record['chat_id'], $sourceFacts)) {
                    throw new DomainException('Источники репостов изменились. Подготовьте перенос заново.');
                }
                $record['is_public'] = $sourceFacts[$record['chat_id']];
            }
            unset($record);
            $this->validate($scope, $sourceId, $targetId, false);
            $result = $this->summary($scope, $sourceId, $targetId);
            if (!hash_equals($fingerprint, $result['fingerprint'])) {
                throw new DomainException('Данные или права изменились во время проверки. Подготовьте перенос заново.');
            }
            foreach ($scope['channels'] as $channel) {
                $topSource=GitPostedMaxTopHold::quotaRecord($this->pdo,$channel,$sourceId,true);$topTarget=GitPostedMaxTopHold::quotaRecord($this->pdo,$channel,$targetId,true);
                GitPostedMaxTopHold::transferQuotasLocked($this->pdo,$channel,$sourceId,$targetId,$topSource,$topTarget);
            }
            // Keep every timeline, delivery journal and repost source unchanged.
            foreach ($scope['posts'] as $post) {
                $this->execute('UPDATE posted_max_posts SET author_max_user_id=? WHERE id=? AND author_max_user_id=?', [$targetId, (int)$post['id'], $sourceId]);
            }
            $this->execute('UPDATE posted_max_templates SET owner_max_user_id=? WHERE owner_max_user_id=?', [$targetId, $sourceId]);
            foreach ($scope['owned_ids'] as $channelId) {
                $this->execute('UPDATE posted_max_channels SET added_by=? WHERE id=? AND added_by=?', [$targetId, $channelId, $sourceId]);
                // MAX owners have implicit full access and must not also exist
                // as editor rows. Other editors and all foreign grants remain.
                $this->execute('DELETE FROM posted_max_permissions WHERE channel_id=? AND max_user_id IN (?,?)', [$channelId, $sourceId, $targetId]);
            }
            $done = ['source_id'=>$sourceId, 'token'=>$nonce, 'target_id'=>$targetId, 'fingerprint'=>$fingerprint,
                'result'=>$result, 'created_at'=>$state['created_at'], 'completed_at'=>time()];
            $this->execute('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$this->receiptKey($sourceId), self::json($done)]);
            $this->execute("UPDATE posted_max_users SET state='account_transfer_done',state_data=? WHERE max_user_id=?", [self::json($done), $sourceId]);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        } finally {
            $this->locking = false;
            foreach (array_reverse($locks) as $name) {
                try { $this->execute('SELECT RELEASE_LOCK(?)', [$name]); } catch (Throwable) { /* Connection close also releases locks. */ }
            }
        }
    }

    public function completedTransfer(string $sourceId, string $nonce): ?array
    {
        if (!self::validUserId($sourceId)) return null;
        $this->activeUser($sourceId);
        return $this->storedReceipt($sourceId, $nonce);
    }

    private function scope(string $sourceId, string $targetId): array
    {
        $source = $this->activeUser($sourceId); $target = $this->activeUser($targetId);
        $owned = $this->rows('SELECT * FROM posted_max_channels WHERE added_by=? ORDER BY id', [$sourceId]);
        $posts = $this->rows('SELECT * FROM posted_max_posts WHERE author_max_user_id=? ORDER BY id', [$sourceId]);
        $templates = $this->rows('SELECT * FROM posted_max_templates WHERE owner_max_user_id=? ORDER BY id', [$sourceId]);
        $sourceCategories = $this->rows('SELECT * FROM posted_max_manager_categories WHERE max_user_id=? ORDER BY id', [$sourceId]);
        $channelIds = array_map(static fn(array $row): int => (int)$row['id'], $owned);
        foreach ([$posts, $sourceCategories] as $records) foreach ($records as $row) $channelIds[] = (int)$row['channel_id'];
        $topQuotaChannels=GitPostedMaxTopHold::quotaChannels($this->pdo,$sourceId);$channelIds=[...$channelIds,...$topQuotaChannels];
        $channelIds = array_values(array_unique($channelIds)); sort($channelIds, SORT_NUMERIC);
        if (count($channelIds) > 100) throw new DomainException('За один перенос поддерживается до 100 каналов. Обратитесь к администратору для переноса большого аккаунта.');
        $channels=[]; $channelPosts=[]; $permissions=[]; $categories=[]; $attempts=[]; $deliveries=[]; $edits=[];
        foreach ($channelIds as $id) {
            $channel = $this->rows('SELECT * FROM posted_max_channels WHERE id=?', [$id]);
            if (count($channel) !== 1) throw new DomainException('Один из каналов больше не существует. Перенос остановлен.');
            $channels[] = $channel[0];
            $channelPosts = [...$channelPosts, ...$this->rows('SELECT * FROM posted_max_posts WHERE channel_id=? ORDER BY id', [$id])];
            $permissions = [...$permissions, ...$this->rows('SELECT * FROM posted_max_permissions WHERE channel_id=? AND max_user_id IN (?,?) ORDER BY max_user_id', [$id, $sourceId, $targetId])];
            $categories = [...$categories, ...$this->rows('SELECT * FROM posted_max_manager_categories WHERE channel_id=? AND max_user_id IN (?,?) ORDER BY id', [$id, $sourceId, $targetId])];
            $attempts = [...$attempts, ...$this->rows('SELECT * FROM posted_max_publish_attempts WHERE channel_id=? ORDER BY id', [$id])];
            $deliveries = [...$deliveries, ...$this->rows('SELECT delivery.* FROM posted_max_deliveries delivery INNER JOIN posted_max_posts post ON post.id=delivery.post_id WHERE post.channel_id=? ORDER BY delivery.id', [$id])];
            $edits = [...$edits, ...$this->rows("SELECT actor.max_user_id,actor.state,actor.state_data FROM posted_max_users actor INNER JOIN posted_max_posts post ON post.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(actor.state_data,'$.post_id')) AS UNSIGNED) WHERE post.channel_id=? AND actor.state IS NOT NULL AND actor.state<>'' ORDER BY actor.max_user_id", [$id])];
        }
        usort($channelPosts, static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        return ['source'=>$source, 'target'=>$target, 'owned_ids'=>array_map(static fn(array $row): int => (int)$row['id'], $owned),
            'channels'=>$channels, 'posts'=>$posts, 'templates'=>$templates, 'source_categories'=>$sourceCategories,
            'channel_posts'=>$channelPosts, 'permissions'=>$permissions, 'categories'=>$categories,
            'attempts'=>$attempts, 'deliveries'=>$deliveries, 'edits'=>$edits,
            'native_source_records'=>$this->nativeSourceRecords($posts, $targetId),
            'top_quota_channels'=>$topQuotaChannels,'top_sidecars'=>GitPostedMaxTopHold::transferScope($this->pdo,$channels,$channelPosts,$templates,$sourceId,$targetId,$attempts)];
    }

    private function validate(array &$scope, string $sourceId, string $targetId, bool $checkMax = true): void
    {
        if ($scope['owned_ids'] === [] && $scope['posts'] === [] && $scope['templates'] === [] && $scope['top_quota_channels'] === []) throw new DomainException('У этого аккаунта нет данных для переноса.');
        if ($scope['edits'] !== []) throw new DomainException('В одном из каналов открыт редактор публикации. Завершите действие и повторите перенос.');
        foreach ($scope['channel_posts'] as $post) {
            if (in_array($post['status'] ?? '', ['publishing','max_pending'], true)) throw new DomainException('В канале выполняется или подтверждается отправка. Дождитесь подтверждения результата.');
            if (str_contains((string)($post['error_text'] ?? ''), '[pin-result-unknown]')) {
                throw new DomainException('В канале остался неизвестный результат закрепления. Сначала подтвердите результат; перенос ничего не изменил.');
            }
        }
        $sourcePosts = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $scope['posts']), true);
        foreach ($scope['attempts'] as $attempt) {
            if (!in_array($attempt['status'] ?? '', ['completed','failed'], true)
                || ($attempt['gate_post_id'] ?? null) !== null || ($attempt['gate_chat_id'] ?? null) !== null
                || in_array($attempt['pin_status'] ?? '', ['pending','processing'], true)
                || !empty($attempt['pin_started_at']) || str_contains((string)($attempt['error_text'] ?? ''), '[pin-result-unknown]')) {
                throw new DomainException('В канале есть незавершённая отправка или неизвестный результат закрепления. Сначала подтвердите результат; перенос ничего не изменил.');
            }
            if (isset($sourcePosts[(int)$attempt['post_id']]) && (!empty($attempt['manager_post_quota_charged']) || !empty($attempt['manager_pin_quota_charged']))) {
                throw new DomainException('Есть публикации со списанными рекламными квотами. Их перенос нужно согласовать с владельцем канала.');
            }
        }
        foreach ($scope['deliveries'] as $delivery) {
            if (!empty($delivery['delete_started_at'])) throw new DomainException('В канале выполняется удаление публикации. Дождитесь завершения и повторите перенос.');
            $raw = (string)($delivery['comment_json'] ?? '');
            if ($raw !== '' && $raw !== 'null') {
                $comment = json_decode($raw, true);
                if (!is_array($comment) || !in_array($comment['state'] ?? '', ['sent','failed'], true)) {
                    throw new DomainException('Есть ожидающий комментарий или неизвестный результат его отправки. Сначала завершите проверку комментария.');
                }
            }
        }
        if ($scope['source_categories'] !== []) throw new DomainException('У аккаунта есть рекламные категории. Их перенос нужно согласовать с владельцем канала.');
        foreach ($scope['posts'] as $post) if (!empty($post['manager_category_id'])) throw new DomainException('Есть публикации с рекламными категориями. Их перенос нужно согласовать с владельцем канала.');
        if ($checkMax) $scope['target_profile'] = $this->profile($scope['target']);
        $this->validateNativeSources($scope, $sourceId, $targetId, $checkMax);
        foreach ($scope['channels'] as $channel) {
            $id = (int)$channel['id']; $owner = trim((string)($channel['added_by'] ?? ''));
            if (empty($channel['is_active']) || !self::validUserId($owner) || !empty($channel['admin_blocked'])) throw new DomainException('Один из каналов отключён или заблокирован. Сначала восстановите доступ.');
            $grants=[];
            foreach ($scope['permissions'] as $grant) if ((int)$grant['channel_id'] === $id) $grants[(string)$grant['max_user_id']] = $grant;
            foreach ([$sourceId,$targetId] as $actor) {
                if (!empty($grants[$actor]['is_ad_manager'])) throw new DomainException('Один из аккаунтов имеет роль рекламного менеджера. Перенос роли и квот нужно согласовать с владельцем канала.');
            }
            if (in_array($id, $scope['owned_ids'], true)) {
                if ($checkMax) $this->assertAdmins((string)$channel['chat_id'], $sourceId, $targetId);
                foreach ($scope['categories'] as $category) if ((int)$category['channel_id'] === $id) throw new DomainException('В передаваемом канале есть рекламная категория одного из аккаунтов. Перенос остановлен.');
            } else {
                foreach ([$sourceId,$targetId] as $actor) {
                    if ($actor === $owner) continue; // MAX owner rights are implicit.
                    if (empty($grants[$actor]['can_post']) || empty($grants[$actor]['can_schedule'])) {
                        throw new DomainException('Есть данные в чужом канале. Его владелец должен заранее выдать новому аккаунту права публикации и планирования.');
                    }
                }
            }
        }
    }

    /** Copy snapshots and manual forwards have no new native-source authority to grant. */
    private function nativeSourceRecords(array $posts, string $targetId): array
    {
        $sources = [];
        foreach ($posts as $post) {
            $forward = trim((string)($post['forward_message_id'] ?? ''));
            $raw = $post['options_json'] ?? null;
            if ($forward === '' || $raw === null || $raw === '') continue;
            try { $options = json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { throw new DomainException('Настройки источника репоста повреждены. Исправьте пост перед переносом.', 0, $error); }
            if (!is_array($options) || (array_is_list($options) && $options !== [])) {
                throw new DomainException('Настройки источника репоста повреждены. Исправьте пост перед переносом.');
            }
            if (!array_key_exists('post_link_source', $options)) continue;
            $source = $options['post_link_source'];
            if (!is_array($source) || ($source['version'] ?? null) !== 1 || ($source['platform'] ?? null) !== 'max'
                || !is_string($source['chat_id'] ?? null) || preg_match('/^-[1-9][0-9]{0,17}$/D', $source['chat_id']) !== 1
                || !is_string($source['message_id'] ?? null) || preg_match('/^mid\.[a-f0-9]{32}$/D', $source['message_id']) !== 1
                || !is_string($source['url'] ?? null) || !is_array($source['original'] ?? null)
                || !is_array($source['copy'] ?? null) || !is_array($source['warnings'] ?? null)) {
                throw new DomainException('Источник репоста по ссылке повреждён. Исправьте пост перед переносом.');
            }
            try {
                $parsed = GitPostedPostLink::parse($source['url']);
                if (!is_array($parsed) || ($parsed['platform'] ?? '') !== 'max'
                    || (preg_match('/^-?[1-9][0-9]*$/D', (string)$parsed['chat']) === 1 && $parsed['chat'] !== $source['chat_id'])) {
                    throw new InvalidArgumentException('Native MAX source URL identity mismatch.');
                }
                // The immutable chat ID is already known. Derive the message ID
                // using a numeric reference; never fetch a public landing page.
                $numeric = GitPostedPostLink::parse('https://max.ru/c/' . $source['chat_id'] . '/' . $parsed['message_id']);
                $reference = GitPostedPostLink::maxReference($numeric, static function (): never {
                    throw new RuntimeException('A numeric MAX source cannot require a landing page.');
                });
                if (!hash_equals($reference['message_id'], $source['message_id']) || !hash_equals($forward, $source['message_id'])) {
                    throw new InvalidArgumentException('Native MAX source message identity mismatch.');
                }
            } catch (Throwable $error) {
                throw new DomainException('Ссылка, источник и режим репоста не совпадают. Исправьте пост перед переносом.', 0, $error);
            }
            $chatId = $source['chat_id'];
            if (!isset($sources[$chatId])) {
                if (count($sources) >= 100) {
                    throw new DomainException('За один перенос поддерживается до 100 уникальных каналов-источников репостов. Для переноса большего аккаунта обратитесь к администратору.');
                }
                $sources[$chatId] = ['chat_id'=>$chatId, 'message_ids'=>[]];
            }
            $sources[$chatId]['message_ids'][] = $source['message_id'];
        }
        ksort($sources, SORT_STRING);
        $records = [];
        foreach ($sources as $source) {
            $source['message_ids'] = array_values(array_unique($source['message_ids']));
            sort($source['message_ids'], SORT_STRING);
            $channels = $this->rows('SELECT id,chat_id,added_by,is_active,is_archived,admin_blocked FROM posted_max_channels WHERE chat_id=?', [$source['chat_id']]);
            $channel = $channels[0] ?? null;
            $owner = null; $grant = null;
            if (is_array($channel)) {
                $owners = $this->rows('SELECT max_user_id,is_active FROM posted_max_users WHERE max_user_id=?', [(string)($channel['added_by'] ?? '')]);
                $owner = $owners[0] ?? null;
                $grants = $this->rows('SELECT max_user_id,channel_id,can_post,can_schedule,can_manage FROM posted_max_permissions WHERE max_user_id=? AND channel_id=?', [$targetId, (int)$channel['id']]);
                $grant = $grants[0] ?? null;
            }
            $records[] = $source + ['channel'=>$channel, 'owner'=>$owner, 'target_grant'=>$grant, 'is_public'=>null];
        }
        return $records;
    }

    /** Provider facts are refreshed before locks; private SQL dependencies are reread under locks. */
    private function validateNativeSources(array &$scope, string $sourceId, string $targetId, bool $checkMax): void
    {
        foreach ($scope['native_source_records'] as &$record) {
            if ($checkMax) {
                try { $chat = ($this->max)('GET', '/chats/' . rawurlencode($record['chat_id'])); }
                catch (Throwable $error) { throw new DomainException('Не удалось проверить источник репоста МАКС. Повторите перенос позже.', 0, $error); }
                if ((string)($chat['chat_id'] ?? '') !== $record['chat_id'] || ($chat['type'] ?? '') !== 'channel'
                    || ($chat['status'] ?? '') !== 'active') {
                    throw new DomainException('МАКС не подтвердил доступный канал источника репоста. Перенос остановлен.');
                }
                $record['is_public'] = ($chat['is_public'] ?? false) === true;
            }
            if (!is_bool($record['is_public'])) throw new DomainException('Источник репоста изменился. Подготовьте перенос заново.');
            if ($record['is_public']) {
                // Public access does not depend on a cached local owner or grant.
                $record['channel'] = null; $record['owner'] = null; $record['target_grant'] = null;
                continue;
            }
            $channel = $record['channel']; $owner = $record['owner']; $grant = $record['target_grant'];
            $available = is_array($channel) && (int)$channel['is_active'] === 1
                && (int)$channel['is_archived'] === 0 && (int)$channel['admin_blocked'] === 0
                && is_array($owner) && (int)$owner['is_active'] === 1;
            $transferredSource = $available && (string)$channel['added_by'] === $sourceId
                && in_array((int)$channel['id'], $scope['owned_ids'], true);
            $recipientAccess = $available && ((string)$channel['added_by'] === $targetId
                || (is_array($grant) && ((int)$grant['can_post'] === 1 || (int)$grant['can_schedule'] === 1 || (int)$grant['can_manage'] === 1)));
            if (!$transferredSource && !$recipientAccess) {
                throw new DomainException('У получателя нет действующих прав в закрытом источнике репоста. Выдайте ему доступ в GitPosted или явно выберите сохранённую копию перед переносом.');
            }
        }
        unset($record);
    }

    private function profile(array $user): array
    {
        $id = (string)$user['max_user_id']; $chatId = trim((string)($user['private_chat_id'] ?? ''));
        if (!self::validUserId($id) || preg_match('/^-?[1-9][0-9]{0,18}$/D', $chatId) !== 1) throw new DomainException('Получатель должен сначала запустить этого бота в МАКС.');
        try { $chat = ($this->max)('GET', '/chats/' . rawurlencode($chatId)); }
        catch (Throwable $error) { throw new DomainException('Не удалось проверить получателя в МАКС. Он должен запустить этого бота и разрешить сообщения.', 0, $error); }
        $person = $chat['dialog_with_user'] ?? null;
        if ((string)($chat['chat_id'] ?? '') !== $chatId || ($chat['type'] ?? '') !== 'dialog' || ($chat['status'] ?? '') !== 'active'
            || !is_array($person) || (string)($person['user_id'] ?? '') !== $id || ($person['is_bot'] ?? null) !== false) {
            throw new DomainException('МАКС не подтвердил личный аккаунт получателя. Укажите его числовой ID после запуска бота.');
        }
        $name = trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? ''));
        return ['max_user_id'=>$id, 'username'=>(string)($person['username'] ?? ''),
            'display_name'=>$name !== '' ? $name : 'ID ' . $id, 'private_chat_id'=>$chatId];
    }

    private function assertAdmins(string $chatId, string $sourceId, string $targetId): void
    {
        try { $response = ($this->max)('GET', '/chats/' . rawurlencode($chatId) . '/members?user_ids=' . rawurlencode($sourceId . ',' . $targetId)); }
        catch (Throwable $error) { throw new DomainException('МАКС не подтвердил права аккаунтов. Повторите проверку позже.', 0, $error); }
        if (!is_array($response['members'] ?? null)) throw new DomainException('МАКС не вернул подтверждение администраторов канала.');
        foreach ([$sourceId,$targetId] as $id) {
            $matches = array_values(array_filter($response['members'], static fn(mixed $member): bool => is_array($member) && (string)($member['user_id'] ?? $member['id'] ?? '') === $id));
            if (count($matches) !== 1 || ($matches[0]['is_bot'] ?? null) !== false
                || (($matches[0]['is_admin'] ?? null) !== true && ($matches[0]['is_owner'] ?? null) !== true)) {
                throw new DomainException('Оба аккаунта должны оставаться владельцем или администратором каждого передаваемого МАКС-канала. Сначала добавьте новый аккаунт в администраторы.');
            }
        }
    }

    private function summary(array $scope, string $sourceId, string $targetId): array
    {
        $document = $scope;
        foreach (['source','target'] as $key) $document[$key] = ['max_user_id'=>(string)$scope[$key]['max_user_id'], 'is_active'=>(int)$scope[$key]['is_active'], 'private_chat_id'=>(string)$scope[$key]['private_chat_id']];
        foreach ($document['channels'] as &$channel) unset($channel['updated_at'], $channel['rights_checked_at'], $channel['owner_checked_at']); unset($channel);
        return ['source_id'=>$sourceId, 'target_id'=>$targetId, 'recipient'=>$scope['target_profile'], 'channels'=>count($scope['owned_ids']),
            'posts'=>count($scope['posts']), 'scheduled'=>count(array_filter($scope['posts'], static fn(array $post): bool => $post['status'] === 'scheduled')),
            'templates'=>count($scope['templates']), 'folders'=>0, 'fingerprint'=>hash('sha256', self::json($document))];
    }

    private function activeUser(string $id): array
    {
        if (!self::validUserId($id)) throw new DomainException('Некорректный ID аккаунта МАКС.');
        $rows = $this->rows('SELECT * FROM posted_max_users WHERE max_user_id=?' . ($this->locking ? ' FOR UPDATE' : ''), [$id]);
        if (count($rows) !== 1 || empty($rows[0]['is_active']) || trim((string)($rows[0]['private_chat_id'] ?? '')) === '') {
            throw new DomainException('Оба аккаунта должны сначала запустить этого МАКС-бота и иметь действующий доступ к GitPosted.');
        }
        return $rows[0];
    }

    private function confirmation(array $source, string $targetId, string $fingerprint, string $nonce): array
    {
        $state = $this->state($source);
        if (($source['state'] ?? '') !== 'confirm_account_transfer' || !hash_equals((string)($state['token'] ?? ''), $nonce)
            || !hash_equals((string)($state['fingerprint'] ?? ''), $fingerprint) || (string)($state['target_id'] ?? '') !== $targetId
            || !is_int($state['created_at'] ?? null) || $state['created_at'] < time()-900 || $state['created_at'] > time()+30) {
            throw new DomainException('Подтверждение устарело или открыто другое меню. Подготовьте перенос заново.');
        }
        return $state;
    }

    private function receipt(array $source, string $targetId, string $fingerprint, string $nonce): ?array
    {
        $done = $this->state($source);
        if (($source['state'] ?? '') === 'account_transfer_done' && ($done['token'] ?? '') === $nonce
            && (string)($done['target_id'] ?? '') === $targetId && ($done['fingerprint'] ?? '') === $fingerprint && is_array($done['result'] ?? null)) return $done['result'];
        $done = $this->storedReceipt((string)$source['max_user_id'], $nonce);
        return $done !== null && (string)($done['target_id'] ?? '') === $targetId && ($done['fingerprint'] ?? '') === $fingerprint ? $done['result'] : null;
    }

    private function storedReceipt(string $sourceId, string $nonce): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $nonce) !== 1) return null;
        // The source actor and MAX cron already serialize receipt writers. A
        // plain read after those locks is fresh and avoids locking an absent
        // shared-settings gap against a simultaneous Telegram transfer.
        $rows = $this->rows('SELECT setting_value FROM posted_app_settings WHERE setting_key=?', [$this->receiptKey($sourceId)]);
        $done = $rows === [] ? null : json_decode((string)$rows[0]['setting_value'], true);
        return is_array($done) && (string)($done['source_id'] ?? '') === $sourceId && ($done['token'] ?? '') === $nonce && is_array($done['result'] ?? null) ? $done : null;
    }

    private function receiptKey(string $sourceId): string { return 'max_transfer_receipt_' . $sourceId; }
    private function state(array $user): array { $data=json_decode((string)($user['state_data'] ?? ''),true); return is_array($data)?$data:[]; }
    private static function validUserId(string $id): bool { return preg_match('/^[1-9][0-9]{0,18}$/D',$id)===1; }
    private function assertIds(string $sourceId, string $targetId): void
    {
        if (!self::validUserId($sourceId) || !self::validUserId($targetId) || $sourceId === $targetId) throw new DomainException('Выберите другой личный аккаунт МАКС для переноса.');
    }
    private function acquire(string $name, array &$locks): void
    {
        $rows=$this->rows('SELECT GET_LOCK(?,0) AS acquired',[$name]);
        if ((int)($rows[0]['acquired'] ?? 0)!==1) throw new DomainException('Выполняется публикация или другое действие МАКС. Повторите перенос позже.');
        $locks[]=$name;
    }
    private function rows(string $sql,array $params=[]): array
    {
        $statement=$this->pdo->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function execute(string $sql,array $params=[]): void { $statement=$this->pdo->prepare($sql); $statement->execute($params); }
    private static function json(array $data): string { return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
