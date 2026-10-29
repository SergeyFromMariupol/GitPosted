<?php

declare(strict_types=1);

require_once __DIR__.'/telegram-policy.php';
require_once __DIR__ . '/telegram-post-options.php';
require_once __DIR__ . '/telegram-multipost-transfer.php';
require_once __DIR__ . '/telegram-top-transfer.php';

/** Move existing Telegram records between two users of this installation. */
final class PostedTelegramAccountTransfer
{
    private readonly Closure $telegram;
    private readonly ?Closure $operatorAllowed;
    private bool $locking = false;

    public function __construct(private readonly PDO $pdo, callable $telegram, ?callable $operatorAllowed=null)
    {
        $this->telegram = Closure::fromCallable($telegram);
        $this->operatorAllowed=$operatorAllowed!==null?Closure::fromCallable($operatorAllowed):null;
    }

    public function resolveRecipient(string $input): array
    {
        $input = trim($input);
        if (preg_match('/^[1-9][0-9]{0,15}$/D', $input) === 1) {
            $user = $this->activeUser((int) $input);
            return $this->profile($user);
        }
        if (preg_match('/^@([A-Za-z][A-Za-z0-9_]{3,31})$/D', $input, $match) !== 1) {
            throw new DomainException('Укажите @username или положительный числовой Telegram ID аккаунта.');
        }
        $rows = $this->rows('SELECT * FROM posted_users WHERE LOWER(username)=LOWER(?) AND is_active=1 ORDER BY telegram_id LIMIT 11', [$match[1]]);
        if (count($rows) > 10) throw new DomainException('Укажите числовой Telegram ID получателя.');
        $matches = [];
        foreach ($rows as $user) {
            try { $profile = $this->profile($user); }
            catch (DomainException) { continue; } // A cached name may belong to an unreachable old account.
            if (strcasecmp((string) ($profile['username'] ?? ''), $match[1]) === 0) $matches[] = $profile;
        }
        if (count($matches) !== 1) {
            throw new DomainException('Не удалось однозначно подтвердить @username. Получатель должен отправить /start этому боту; затем укажите его числовой Telegram ID.');
        }
        return $matches[0];
    }

    public function preview(int $sourceId, int $targetId): array
    {
        $this->assertIds($sourceId, $targetId);
        $scope = $this->scope($sourceId, $targetId);
        $this->validate($scope, $sourceId, $targetId);
        return $this->summary($scope, $sourceId, $targetId);
    }

    public function transfer(int $sourceId, int $targetId, string $fingerprint, string $nonce): array
    {
        $this->assertIds($sourceId, $targetId);
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || preg_match('/^[a-f0-9]{24}$/D', $nonce) !== 1) {
            throw new DomainException('Подтверждение переноса недействительно. Откройте перенос заново.');
        }
        if ($this->pdo->inTransaction()) throw new RuntimeException('Account transfer requires its own transaction.');
        $source = $this->activeUser($sourceId);
        $receipt = $this->receipt($source, $targetId, $fingerprint, $nonce);
        if ($receipt !== null) return $receipt;
        $this->confirmation($source, $targetId, $fingerprint, $nonce);
        // Telegram reads can take seconds. Never hold cron or MySQL locks
        // while waiting for the network; the locked snapshot is checked below.
        $before = $this->scope($sourceId, $targetId);
        $this->validate($before, $sourceId, $targetId);
        if (!hash_equals($fingerprint, $this->summary($before, $sourceId, $targetId)['fingerprint'])) {
            throw new DomainException('Данные или права изменились после подготовки. Откройте перенос заново и проверьте новый состав.');
        }
        $locks = [];
        try {
            $this->acquire('trevoga_posted_cron', $locks);
            foreach ($before['channels'] as $channel) {
                $this->acquire('gitposted_pin_' . substr(hash('sha256', (string) $channel['id']), 0, 32), $locks);
            }
            PostedTelegramRootedAccess::begin($this->pdo,PostedTelegramRootedAccess::current([]),true);
            // Compatible with publish/edit: channels, posts, actors, permissions.
            foreach ($before['channels'] as $channel) $this->rows('SELECT id FROM posted_channels WHERE id=? FOR UPDATE', [(int) $channel['id']]);
            $postIds=[];
            foreach ([$before['posts'],$before['target_legacy_posts'],$before['multipost']['posts']] as $posts) foreach ($posts as $post) $postIds[]=(int)$post['id'];
            $postIds=array_values(array_unique($postIds)); sort($postIds,SORT_NUMERIC);
            foreach ($postIds as $postId) $this->rows('SELECT id FROM posted_posts WHERE id=? FOR UPDATE', [$postId]);
            $ids = [$sourceId, $targetId]; sort($ids, SORT_NUMERIC);
            $this->rows('SELECT telegram_id FROM posted_users WHERE telegram_id IN (?,?) ORDER BY telegram_id FOR UPDATE', $ids);
            // New channel/post creation locks its actor before writing. Do not
            // lock their unindexed owner predicates: that would lock unrelated
            // channels/posts after actors and invert normal editor lock order.
            // Actor rows serialize even empty owner ranges: InnoDB gap locks
            // alone are compatible and cannot serialize concurrent inserts.
            foreach ($ids as $ownerId) {
                $this->rows('SELECT channel_id FROM posted_permissions WHERE user_id=? ORDER BY channel_id FOR UPDATE', [$ownerId]);
                $this->rows('SELECT id FROM posted_manager_categories WHERE user_id=? ORDER BY channel_id,name FOR UPDATE', [$ownerId]);
                $this->rows('SELECT id FROM posted_templates WHERE owner_id=? ORDER BY id FOR UPDATE', [$ownerId]);
                $folders = $this->rows('SELECT id FROM posted_folders WHERE owner_id=? ORDER BY id FOR UPDATE', [$ownerId]);
                foreach ($folders as $folder) $this->rows('SELECT channel_id FROM posted_folder_channels WHERE folder_id=? ORDER BY channel_id FOR UPDATE', [(int)$folder['id']]);
            }
            foreach ($before['channels'] as $channel) {
                $this->rows('SELECT user_id FROM posted_permissions WHERE channel_id=? ORDER BY user_id FOR UPDATE', [(int) $channel['id']]);
                $this->rows('SELECT id FROM posted_manager_categories WHERE channel_id=? ORDER BY id FOR UPDATE', [(int) $channel['id']]);
            }
            $this->locking = true;
            $source = $this->activeUser($sourceId);
            $receipt = $this->receipt($source, $targetId, $fingerprint, $nonce);
            if ($receipt !== null) { PostedTelegramRootedAccess::commit($this->pdo); return $receipt; }
            $state = $this->confirmation($source, $targetId, $fingerprint, $nonce);
            $scope = $this->scope($sourceId, $targetId);
            $scope['target_profile'] = $before['target_profile'];
            $this->validate($scope, $sourceId, $targetId, false);
            $result = $this->summary($scope, $sourceId, $targetId);
            if (!hash_equals($fingerprint, $result['fingerprint'])) {
                throw new DomainException('Данные или права изменились после подготовки. Откройте перенос заново и проверьте новый состав.');
            }
            $latest = $this->scope($sourceId, $targetId);
            $latest['target_profile'] = $scope['target_profile'];
            if (!hash_equals($fingerprint, $this->summary($latest, $sourceId, $targetId)['fingerprint'])) {
                throw new DomainException('Данные изменились во время проверки. Подготовьте перенос заново.');
            }
            // Only identity/UI columns change. Timeline, transport and content stay intact.
            // A colliding legacy quota ledger becomes an independent category.
            // Its post links are the only non-identity publication fields changed.
            PostedTelegramTopTransfer::applyLocked($this->pdo,$scope['top_hold'],$scope['channels'],$sourceId,$targetId);
            $legacyCategories=$this->transferPermissions($scope,$sourceId,$targetId);
            PostedTelegramMultipostTransfer::applyLocked($this->pdo,$scope['multipost'],$sourceId,$targetId,$legacyCategories);
            foreach ($scope['posts'] as $post) {
                $this->execute('UPDATE posted_posts SET author_id=?,telegram_preview_chat_id=NULL,telegram_preview_message_ids=NULL,telegram_preview_control_message_id=NULL,telegram_preview_fingerprint=NULL WHERE id=? AND author_id=?', [$targetId, (int)$post['id'], $sourceId]);
            }
            $this->execute('UPDATE posted_templates SET owner_id=? WHERE owner_id=?', [$targetId, $sourceId]);
            foreach ($scope['folders'] as $folder) {
                $title = $this->uniqueTitle('posted_folders', 'owner_id', $targetId, (string) $folder['title'], 64);
                $this->execute('UPDATE posted_folders SET owner_id=?,title=? WHERE id=? AND owner_id=?', [$targetId, $title, (int) $folder['id'], $sourceId]);
            }
            foreach ($scope['categories'] as $category) {
                if ((int) $category['user_id'] !== $sourceId) continue;
                $title = $this->uniqueTitle('posted_manager_categories', 'user_id', $targetId, (string) $category['name'], 100, (int) $category['channel_id']);
                $this->execute('UPDATE posted_manager_categories SET user_id=?,name=? WHERE id=? AND user_id=?', [$targetId, $title, (int) $category['id'], $sourceId]);
            }
            foreach ($scope['owned_ids'] as $channelId) {
                $this->execute('UPDATE posted_channels SET added_by=? WHERE id=? AND added_by=?', [$targetId, $channelId, $sourceId]);
                $channel=null;
                foreach($scope['channels'] as $candidate) if((int)$candidate['id']===$channelId) { $channel=$candidate; break; }
                if(!is_array($channel)) throw new LogicException('Transferred channel is missing from scope.');
                $hasDelegation=(!empty($channel['is_active'])&&empty($channel['admin_blocked']))
                    || self::channelTransferMarker($channel,$scope['markers'][$channelId]??null)!==null;
                if($hasDelegation) {
                    $marker=['version'=>1,'channel_id'=>$channelId,'chat_id'=>(string)$channel['chat_id'],'owner_id'=>$targetId,'source_id'=>$sourceId,'token'=>$nonce,'fingerprint'=>$fingerprint,'completed_at'=>time()];
                    $this->execute('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', ['telegram_channel_transfer_'.$channelId,self::json($marker)]);
                } else {
                    // Moving dormant history cannot revive authority lost when
                    // the bot was removed or the former human owner lost access.
                    $this->execute('DELETE FROM posted_app_settings WHERE setting_key=?',['telegram_channel_transfer_'.$channelId]);
                }
            }
            $this->execute('DELETE FROM posted_admin_tokens WHERE user_id=?', [$sourceId]);
            $done = ['source_id'=>$sourceId,'token'=>$nonce,'target_id'=>$targetId,'fingerprint'=>$fingerprint,'result'=>$result,'created_at'=>$state['created_at'],'completed_at'=>time(),'permission_history'=>$scope['permissions']];
            // One durable receipt per source survives navigation or a lost HTTP
            // reply. Its nonce prevents an old retry from moving newer records.
            $this->execute('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$this->receiptKey($sourceId), self::json($done)]);
            $this->execute("UPDATE posted_users SET state='account_transfer_done',state_data=? WHERE telegram_id=?", [self::json($done), $sourceId]);
            PostedTelegramRootedAccess::commit($this->pdo);
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) PostedTelegramRootedAccess::rollback($this->pdo);
            throw $error;
        } finally {
            $this->locking = false;
            foreach (array_reverse($locks) as $name) {
                try { $this->execute('SELECT RELEASE_LOCK(?)', [$name]); } catch (Throwable) { /* connection also releases locks */ }
            }
        }
    }

    private function scope(int $sourceId, int $targetId): array
    {
        $source = $this->activeUser($sourceId); $target = $this->activeUser($targetId);
        // During transfer the first consistent read is taken only after known
        // channel/post rows, both actors and indexed owner ranges are locked.
        // These scans deliberately avoid FOR UPDATE and its full-table locks.
        $owned = $this->rows('SELECT * FROM posted_channels WHERE added_by=? ORDER BY id', [$sourceId]);
        $posts = $this->rows('SELECT * FROM posted_posts WHERE author_id=? ORDER BY id', [$sourceId]);
        $templates = $this->rows('SELECT * FROM posted_templates WHERE owner_id=? ORDER BY id', [$sourceId]);
        $folders = $this->rows('SELECT * FROM posted_folders WHERE owner_id=? ORDER BY id', [$sourceId]);
        $sourcePermissions = $this->rows('SELECT * FROM posted_permissions WHERE user_id=? ORDER BY channel_id', [$sourceId]);
        $sourceCategories = $this->rows('SELECT * FROM posted_manager_categories WHERE user_id=? ORDER BY channel_id,id', [$sourceId]);
        $links = $this->rows('SELECT link.* FROM posted_folder_channels link INNER JOIN posted_folders folder ON folder.id=link.folder_id WHERE folder.owner_id=? ORDER BY link.folder_id,link.channel_id', [$sourceId]);
        $multipost=PostedTelegramMultipostTransfer::scope($this->pdo,$sourceId,$targetId);
        $channelIds = array_map(static fn(array $r):int=>(int)$r['id'], $owned);
        $channelIds=[...$channelIds,...$multipost['channel_ids'],...PostedTelegramTopTransfer::channelIds($this->pdo,$sourceId)];
        foreach ([$posts, $templates, $links, $sourcePermissions, $sourceCategories] as $records) foreach ($records as $record) if ((int)($record['channel_id']??0)>0) $channelIds[]=(int)$record['channel_id'];
        $channelIds = array_values(array_unique($channelIds)); sort($channelIds,SORT_NUMERIC);
        if (count($channelIds)>100) throw new DomainException('За один перенос поддерживается до 100 каналов. Обратитесь к администратору сервиса для переноса большого аккаунта.');
        $channels=[]; $permissions=[]; $categories=[]; $attempts=[]; $busy=[]; $edits=[]; $targetLegacyPosts=[]; $markers=[];
        foreach ($channelIds as $id) {
            $channel=$this->rows('SELECT * FROM posted_channels WHERE id=?',[$id]);
            if(count($channel)!==1) throw new DomainException('Один из каналов больше не существует. Перенос остановлен.');
            $channels[]=$channel[0];
            $channelPermissions=$this->rows('SELECT * FROM posted_permissions WHERE channel_id=? AND user_id IN (?,?) ORDER BY user_id',[$id,$sourceId,$targetId]);
            $permissions=[...$permissions,...$channelPermissions];
            if(count($channelPermissions)===2 && array_filter($channelPermissions,static fn(array $row):bool=>!empty($row['is_ad_manager']))!==[]) {
                $targetLegacyPosts=[...$targetLegacyPosts,...$this->rows('SELECT * FROM posted_posts WHERE channel_id=? AND author_id=? AND manager_category_id IS NULL ORDER BY id',[$id,$targetId])];
            }
            $categories=[...$categories,...$this->rows('SELECT * FROM posted_manager_categories WHERE channel_id=? AND user_id IN (?,?) ORDER BY id',[$id,$sourceId,$targetId])];
            $attempts=[...$attempts,...$this->rows('SELECT attempt.*,post.pin_after_publish AS transfer_pin_requested FROM posted_telegram_publish_attempts attempt INNER JOIN posted_posts post ON post.id=attempt.post_id WHERE attempt.channel_id=? ORDER BY attempt.post_id',[$id])];
            $busy=[...$busy,...$this->rows("SELECT id FROM posted_posts WHERE channel_id=? AND status IN ('publishing','telegram_pending') ORDER BY id",[$id])];
            $edits=[...$edits,...$this->rows('SELECT edit.user_id,edit.post_id FROM posted_edit_sessions edit INNER JOIN posted_posts post ON post.id=edit.post_id WHERE post.channel_id=? ORDER BY edit.post_id,edit.user_id',[$id])];
            if((int)($channel[0]['added_by']??0)===$sourceId) {
                $marker=$this->rows('SELECT setting_value FROM posted_app_settings WHERE setting_key=?',['telegram_channel_transfer_'.$id]);
                $markers[$id]=$marker[0]['setting_value']??null;
            }
        }
        $deliveryOptions=PostedTelegramPostOptions::scopeSnapshot($this->pdo,[...$posts,...$targetLegacyPosts],$templates);
        $topHold=PostedTelegramTopTransfer::scope($this->pdo,$sourceId,$targetId,$channels,[...$posts,...$targetLegacyPosts,...$multipost['posts']],$templates);
        return ['source'=>$source,'target'=>$target,'owned_ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$owned),'channels'=>$channels,'posts'=>$posts,'templates'=>$templates,'folders'=>$folders,'links'=>$links,'permissions'=>$permissions,'categories'=>$categories,'source_permissions'=>$sourcePermissions,'source_categories'=>$sourceCategories,'target_legacy_posts'=>$targetLegacyPosts,'delivery_options'=>$deliveryOptions,'multipost'=>$multipost,'top_hold'=>$topHold,'markers'=>$markers,'attempts'=>$attempts,'busy'=>$busy,'edits'=>$edits];
    }

    private function validate(array &$scope, int $sourceId, int $targetId, bool $checkTelegram = true): void
    {
        $this->assertOperatorAllowed($sourceId);$this->assertOperatorAllowed($targetId);
        $rootedProof=PostedTelegramRootedAccess::transferProof($this->pdo,PostedTelegramRootedAccess::current([]),$sourceId,$targetId);
        if($rootedProof!==null)$scope['rooted_access']=$rootedProof;
        if ($scope['owned_ids']===[] && $scope['posts']===[] && $scope['templates']===[] && $scope['folders']===[] && $scope['source_permissions']===[] && $scope['source_categories']===[] && $scope['top_hold']['source_stock_channels']===[]) {
            throw new DomainException('У этого аккаунта нет данных для переноса.');
        }
        if ($scope['busy']!==[] || $scope['edits']!==[]) throw new DomainException('В одном из каналов выполняется отправка или редактирование. Завершите действие и повторите перенос.');
        foreach ($scope['attempts'] as $attempt) {
            if (($attempt['state']??'')!=='completed' || ($attempt['gate_channel_id']??null)!==null
                || !empty($attempt['pin_started_at']) || !empty($attempt['pin_delivery_uncertain'])
                || (!empty($attempt['transfer_pin_requested']) && empty($attempt['pin_completed_at']))) {
                throw new DomainException('В канале есть незавершённая отправка или закрепление. Сначала дождитесь подтверждения результата; перенос ничего не изменил.');
            }
        }
        if ($checkTelegram) $scope['target_profile']=$this->profile($scope['target']);
        foreach ($scope['channels'] as $channel) {
            $id=(int)$channel['id'];
            // Dormant associations and history move without being reactivated.
            if(!$checkTelegram || !in_array($id,$scope['owned_ids'],true) || empty($channel['is_active']) || !empty($channel['admin_blocked'])) continue;
            if(self::channelTransferMarker($channel,$scope['markers'][$id]??null)===null) {
                $this->assertAdmin((string)$channel['chat_id'],$sourceId);
            }
            $this->assertBotCanPost((string)$channel['chat_id']);
        }
    }

    private function transferPermissions(array $scope,int $sourceId,int $targetId):array
    {
        $legacyCategories=[];
        $grants=[];
        foreach($scope['permissions'] as $permission) $grants[(int)$permission['channel_id']][(int)$permission['user_id']]=$permission;
        $capabilities=['can_post','can_schedule','can_manage_posts','can_manage_admins'];
        foreach($scope['channels'] as $channel) {
            $channelId=(int)$channel['id'];
            $source=$grants[$channelId][$sourceId]??null;
            $target=$grants[$channelId][$targetId]??null;
            $sourceOwner=in_array($channelId,$scope['owned_ids'],true);
            $targetOwner=(int)($channel['added_by']??0)===$targetId;
            if(!is_array($source) && !$sourceOwner) continue;

            // With no collision, changing the existing key preserves every
            // permission field, including read-only roles and exact balances.
            if(is_array($source) && !is_array($target)) {
                if($this->execute('UPDATE posted_permissions SET user_id=?,updated_at=updated_at WHERE user_id=? AND channel_id=?',[$targetId,$sourceId,$channelId])!==1) {
                    throw new DomainException('Права изменились во время переноса. Подготовьте перенос заново.');
                }
                if($sourceOwner || $targetOwner) {
                    $this->execute('UPDATE posted_permissions SET can_post=1,can_schedule=1,can_manage_posts=1,can_manage_admins=1,updated_at=updated_at WHERE user_id=? AND channel_id=?',[$targetId,$channelId]);
                }
                continue;
            }

            $source ??= ['can_post'=>1,'can_schedule'=>1,'can_manage_posts'=>1,'can_manage_admins'=>1,'is_ad_manager'=>0,'manager_description'=>null,'post_quota_remaining'=>null,'pin_quota_remaining'=>null];
            $target ??= ['can_post'=>0,'can_schedule'=>0,'can_manage_posts'=>0,'can_manage_admins'=>0,'is_ad_manager'=>0,'manager_description'=>null,'post_quota_remaining'=>null,'pin_quota_remaining'=>null];
            $merged=[];
            foreach($capabilities as $capability) $merged[$capability]=($sourceOwner||$targetOwner||!empty($source[$capability])||!empty($target[$capability]))?1:0;
            $sourceManager=!empty($source['is_ad_manager']);
            $targetManager=!empty($target['is_ad_manager']);
            $manager=$sourceManager||$targetManager;
            $hasCategories=false;
            foreach($scope['categories'] as $category) if((int)$category['channel_id']===$channelId && !empty($category['is_active'])) { $hasCategories=true; break; }
            $overflow=false;
            foreach(['post_quota_remaining','pin_quota_remaining'] as $column) {
                if($source[$column]!==null && $target[$column]!==null && (int)$source[$column]+(int)$target[$column]>4294967295) $overflow=true;
            }
            $normalize=$manager && ($sourceManager!==$targetManager || $hasCategories || $overflow);
            if($normalize) {
                foreach([[$sourceId,$source,$sourceManager],[$targetId,$target,$targetManager]] as [$actorId,$permission,$isManager]) {
                    $canPublish=!empty($permission['can_post'])||!empty($permission['can_schedule']);
                    $postQuota=$isManager?$permission['post_quota_remaining']:($canPublish?null:0);
                    $pinQuota=$isManager?$permission['pin_quota_remaining']:($canPublish?null:0);
                    $title=$this->uniqueTitle('posted_manager_categories','user_id',$targetId,'Перенос '.$actorId.' — остаток',100,$channelId);
                    $this->execute('INSERT INTO posted_manager_categories(user_id,channel_id,name,post_quota_remaining,pin_quota_remaining,is_active) VALUES(?,?,?,?,?,1)',[$targetId,$channelId,$title,$postQuota,$pinQuota]);
                    $categoryId=(int)$this->pdo->lastInsertId();
                    $legacyCategories[$actorId][$channelId]=$categoryId;
                    if($categoryId<=0) throw new RuntimeException('Quota category was not created.');
                    $posts=$actorId===$sourceId?$scope['posts']:$scope['target_legacy_posts'];
                    foreach($posts as $post) {
                        if((int)$post['channel_id']!==$channelId || $post['manager_category_id']!==null) continue;
                        if($this->execute('UPDATE posted_posts SET manager_category_id=? WHERE id=? AND author_id=? AND channel_id=? AND manager_category_id IS NULL',[$categoryId,(int)$post['id'],$actorId,$channelId])!==1) {
                            throw new DomainException('Публикация изменилась во время переноса. Подготовьте перенос заново.');
                        }
                    }
                }
                // Balances now live in distinct categories; keeping a second
                // copy in the legacy row would mint credits on category removal.
                $postQuota=0; $pinQuota=0;
            } elseif($manager) {
                $postQuota=$this->mergedQuota($source['post_quota_remaining'],$target['post_quota_remaining']);
                $pinQuota=$this->mergedQuota($source['pin_quota_remaining'],$target['pin_quota_remaining']);
            } else {
                $postQuota=$target['post_quota_remaining']; $pinQuota=$target['pin_quota_remaining'];
            }
            $description=trim((string)($target['manager_description']??''))!==''?$target['manager_description']:($source['manager_description']??null);
            $parameters=[$merged['can_post'],$merged['can_schedule'],$merged['can_manage_posts'],$merged['can_manage_admins'],$manager?1:0,$description,$postQuota,$pinQuota,$targetId,$channelId];
            if(isset($grants[$channelId][$targetId])) {
                $this->execute('UPDATE posted_permissions SET can_post=?,can_schedule=?,can_manage_posts=?,can_manage_admins=?,is_ad_manager=?,manager_description=?,post_quota_remaining=?,pin_quota_remaining=?,updated_at=updated_at WHERE user_id=? AND channel_id=?',$parameters);
            } else {
                $this->execute('INSERT INTO posted_permissions(can_post,can_schedule,can_manage_posts,can_manage_admins,is_ad_manager,manager_description,post_quota_remaining,pin_quota_remaining,user_id,channel_id) VALUES(?,?,?,?,?,?,?,?,?,?)',$parameters);
            }
            $this->execute('DELETE FROM posted_permissions WHERE user_id=? AND channel_id=?',[$sourceId,$channelId]);
        }
        return $legacyCategories;
    }

    private function mergedQuota(mixed $source,mixed $target):?int
    {
        return $source===null||$target===null?null:(int)$source+(int)$target;
    }

    /** Validate an explicit application-owner transfer, shared with runtime tenure checks. */
    public static function channelTransferMarker(array $channel,mixed $value):?array
    {
        if(!is_string($value)) return null;
        $marker=json_decode($value,true);
        if(!is_array($marker) || ($marker['version']??null)!==1
            || !is_int($marker['channel_id']??null) || $marker['channel_id']!==(int)($channel['id']??0) || $marker['channel_id']<=0
            || !is_string($marker['chat_id']??null) || $marker['chat_id']==='' || $marker['chat_id']!==(string)($channel['chat_id']??'')
            || !is_int($marker['owner_id']??null) || $marker['owner_id']<=0 || $marker['owner_id']!==(int)($channel['added_by']??0)
            || !is_int($marker['source_id']??null) || $marker['source_id']<=0 || $marker['source_id']===$marker['owner_id']
            || !is_string($marker['token']??null) || preg_match('/^[a-f0-9]{24}$/D',$marker['token'])!==1
            || !is_string($marker['fingerprint']??null) || preg_match('/^[a-f0-9]{64}$/D',$marker['fingerprint'])!==1
            || !is_int($marker['completed_at']??null) || $marker['completed_at']<=0 || $marker['completed_at']>time()+30) return null;
        return $marker;
    }

    private function assertBotCanPost(string $chatId):void
    {
        try {
            $me=($this->telegram)('getMe',[]);
            $bot=$me['result']??null;
            if(empty($me['ok'])||!is_array($bot)||($bot['is_bot']??null)!==true||!is_int($bot['id']??null)||$bot['id']<=0) throw new RuntimeException('Bot identity was not confirmed.');
            $response=($this->telegram)('getChatMember',['chat_id'=>$chatId,'user_id'=>$bot['id']]);
            $member=$response['result']??null;
            if(empty($response['ok'])||!is_array($member)||($member['user']['is_bot']??null)!==true||($member['user']['id']??null)!==$bot['id']
                || ($member['status']??null)!=='administrator'
                || ($member['can_post_messages']??null)!==true) throw new RuntimeException('Bot publishing rights were not confirmed.');
        } catch(Throwable $error) {
            throw new DomainException('Telegram не подтвердил право бота публиковать в передаваемом канале. Перенос ничего не изменил.',0,$error);
        }
    }

    private function summary(array $scope,int $sourceId,int $targetId):array
    {
        $document=$scope;
        // Conversation state changes while the confirmation is prepared.
        foreach(['source','target'] as $key) $document[$key]=['telegram_id'=>(int)$scope[$key]['telegram_id'],'is_active'=>(int)$scope[$key]['is_active']];
        // Reconciliation timestamps are bookkeeping, not a content change.
        foreach($document['channels'] as &$row) unset($row['updated_at']); unset($row);
        return ['source_id'=>$sourceId,'target_id'=>$targetId,'recipient'=>$scope['target_profile'],'channels'=>count($scope['owned_ids']),'posts'=>count($scope['posts']),'scheduled'=>count(array_filter($scope['posts'],static fn(array $p):bool=>$p['status']==='scheduled')),'templates'=>count($scope['templates']),'folders'=>count($scope['folders']),'permissions'=>count($scope['source_permissions']),'categories'=>count($scope['source_categories']),'fingerprint'=>hash('sha256',self::json($document))];
    }

    private function assertAdmin(string $chatId,int $userId):void
    {
        try { $response=($this->telegram)('getChatMember',['chat_id'=>$chatId,'user_id'=>$userId]); }
        catch(Throwable $error) { throw new DomainException('Telegram не подтвердил права аккаунтов. Перенос не выполнен, повторите проверку позже.',0,$error); }
        $member=$response['result']??null;
        if(empty($response['ok'])||!is_array($member)||!in_array($member['status']??'', ['creator','administrator'],true)||!empty($member['user']['is_bot'])||(int)($member['user']['id']??0)!==$userId) {
            throw new DomainException('Telegram не подтвердил действующие права исходного владельца канала. Проверьте доступ старого аккаунта; перенос ничего не изменил.');
        }
    }

    private function profile(array $user):array
    {
        $id=(int)$user['telegram_id'];
        $this->assertOperatorAllowed($id);
        try { $response=($this->telegram)('getChat',['chat_id'=>$id]); }
        catch(Throwable $error) { throw new DomainException('Не удалось проверить получателя. Он должен запустить этого бота через /start и разрешить сообщения.',0,$error); }
        $chat=$response['result']??null;
        if(empty($response['ok'])||!is_array($chat)||(int)($chat['id']??0)!==$id||($chat['type']??'')!=='private') throw new DomainException('Telegram не подтвердил личный аккаунт получателя. Укажите его числовой Telegram ID после /start.');
        return ['telegram_id'=>$id,'username'=>(string)($chat['username']??''),'first_name'=>(string)($chat['first_name']??'')];
    }

    private function activeUser(int $id):array
    {
        $this->assertOperatorAllowed($id);
        $rows=$this->rows('SELECT * FROM posted_users WHERE telegram_id=?'.($this->locking?' FOR UPDATE':''),[$id]);
        if(count($rows)!==1||empty($rows[0]['is_active'])) throw new DomainException('Оба аккаунта должны сначала запустить этого бота через /start и иметь действующий доступ к GitPosted.');
        return $rows[0];
    }

    private function receipt(array $source,int $targetId,string $fingerprint,string $nonce):?array
    {
        $state=$this->state($source);
        if(($source['state']??'')==='account_transfer_done' && ($state['token']??'')===$nonce && (int)($state['target_id']??0)===$targetId && ($state['fingerprint']??'')===$fingerprint && is_array($state['result']??null)) return $state['result'];
        $done=$this->storedReceipt((int)$source['telegram_id'],$nonce);
        if($done!==null && (int)($done['target_id']??0)===$targetId && ($done['fingerprint']??'')===$fingerprint) return $done['result'];
        return null;
    }

    public function completedTransfer(int $sourceId,string $nonce):?array
    {
        $this->activeUser($sourceId);
        return $this->storedReceipt($sourceId,$nonce);
    }

    private function storedReceipt(int $sourceId,string $nonce):?array
    {
        if(preg_match('/^[a-f0-9]{24}$/D',$nonce)!==1) return null;
        $rows=$this->rows('SELECT setting_value FROM posted_app_settings WHERE setting_key=?'.($this->locking?' FOR UPDATE':''),[$this->receiptKey($sourceId)]);
        $done=$rows===[]?null:json_decode((string)$rows[0]['setting_value'],true);
        return is_array($done) && (int)($done['source_id']??0)===$sourceId && ($done['token']??'')===$nonce && is_array($done['result']??null)?$done:null;
    }

    private function receiptKey(int $sourceId):string
    {
        return 'telegram_transfer_receipt_'.$sourceId;
    }

    private function confirmation(array $source,int $targetId,string $fingerprint,string $nonce):array
    {
        $state=$this->state($source);
        if(($source['state']??'')!=='confirm_account_transfer' || !hash_equals((string)($state['token']??''),$nonce)
            || !hash_equals((string)($state['fingerprint']??''),$fingerprint) || (int)($state['target_id']??0)!==$targetId
            || !is_int($state['created_at']??null) || $state['created_at']<time()-900 || $state['created_at']>time()+30) {
            throw new DomainException('Подтверждение устарело или открыто другое меню. Подготовьте перенос заново.');
        }
        return $state;
    }

    private function state(array $user):array
    {
        $data=json_decode((string)($user['state_data']??''),true);
        return is_array($data)?$data:[];
    }

    private function uniqueTitle(string $table,string $ownerColumn,int $ownerId,string $title,int $limit,?int $channelId=null):string
    {
        if(!in_array($table,['posted_folders','posted_manager_categories'],true)) throw new LogicException('Invalid transfer title scope.');
        $column=$table==='posted_folders'?'title':'name'; $candidate=$title;
        for($index=1;$index<=1000;$index++) {
            $params=[$ownerId,$candidate]; if($channelId!==null)$params[]=$channelId;
            $rows=$this->rows("SELECT id FROM {$table} WHERE {$ownerColumn}=? AND {$column}=?".($channelId===null?'':' AND channel_id=?').' LIMIT 1',$params);
            if($rows===[]) return $candidate;
            $suffix=' (перенос '.$index.')'; $candidate=mb_substr($title,0,$limit-mb_strlen($suffix)).$suffix;
        }
        throw new DomainException('Слишком много одинаковых названий папок. Переименуйте папки перед переносом.');
    }

    private function acquire(string $name,array &$locks):void
    {
        $rows=$this->rows('SELECT GET_LOCK(?,0) AS acquired',[$name]);
        if((int)($rows[0]['acquired']??0)!==1) throw new DomainException('Сейчас выполняется публикация или другое действие. Повторите перенос через минуту.');
        $locks[]=$name;
    }

    private function assertIds(int $sourceId,int $targetId):void
    {
        if($sourceId<=0||$targetId<=0||$sourceId===$targetId) throw new DomainException('Выберите другой личный Telegram-аккаунт для переноса.');
        $this->assertOperatorAllowed($sourceId);$this->assertOperatorAllowed($targetId);
    }

    private function assertOperatorAllowed(int $id): void
    {
        if($this->operatorAllowed!==null&&($this->operatorAllowed)($id)!==true)throw new DomainException('Этот аккаунт не включён в список доступа к боту. Перенос ничего не изменил.');
    }

    private function rows(string $sql,array $params=[]):array
    {
        $statement=$this->pdo->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function execute(string $sql,array $params=[]):int
    {
        $statement=$this->pdo->prepare($sql); $statement->execute($params);
        return $statement->rowCount();
    }

    private static function json(array $data):string
    {
        return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
