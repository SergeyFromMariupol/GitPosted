<?php

declare(strict_types=1);

/** Move existing Telegram records between two users of this installation. */
final class PostedTelegramAccountTransfer
{
    private readonly Closure $telegram;
    private bool $locking = false;

    public function __construct(private readonly PDO $pdo, callable $telegram)
    {
        $this->telegram = Closure::fromCallable($telegram);
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
            $this->pdo->beginTransaction();
            // Compatible with publish/edit: channels, posts, actors, permissions.
            foreach ($before['channels'] as $channel) $this->rows('SELECT id FROM posted_channels WHERE id=? FOR UPDATE', [(int) $channel['id']]);
            foreach ($before['posts'] as $post) $this->rows('SELECT id FROM posted_posts WHERE id=? FOR UPDATE', [(int) $post['id']]);
            $ids = [$sourceId, $targetId]; sort($ids, SORT_NUMERIC);
            $this->rows('SELECT telegram_id FROM posted_users WHERE telegram_id IN (?,?) ORDER BY telegram_id FOR UPDATE', $ids);
            // New channel/post creation locks its actor before writing. Do not
            // lock their unindexed owner predicates: that would lock unrelated
            // channels/posts after actors and invert normal editor lock order.
            // Actor rows serialize even empty owner ranges: InnoDB gap locks
            // alone are compatible and cannot serialize concurrent inserts.
            foreach ($ids as $ownerId) {
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
            if ($receipt !== null) { $this->pdo->commit(); return $receipt; }
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
            foreach ($scope['posts'] as $post) {
                $this->execute('UPDATE posted_posts SET author_id=?,telegram_preview_chat_id=NULL,telegram_preview_message_ids=NULL,telegram_preview_control_message_id=NULL,telegram_preview_fingerprint=NULL WHERE id=? AND author_id=?', [$targetId, (int)$post['id'], $sourceId]);
            }
            $this->execute('UPDATE posted_templates SET owner_id=? WHERE owner_id=?', [$targetId, $sourceId]);
            foreach ($scope['folders'] as $folder) {
                $title = $this->uniqueTitle('posted_folders', 'owner_id', $targetId, (string) $folder['title'], 64);
                $this->execute('UPDATE posted_folders SET owner_id=?,title=? WHERE id=? AND owner_id=?', [$targetId, $title, (int) $folder['id'], $sourceId]);
            }
            foreach ($scope['categories'] as $category) {
                if ((int) $category['user_id'] !== $sourceId || !in_array((int) $category['channel_id'], $scope['owned_ids'], true)) continue;
                $title = $this->uniqueTitle('posted_manager_categories', 'user_id', $targetId, (string) $category['name'], 100, (int) $category['channel_id']);
                $this->execute('UPDATE posted_manager_categories SET user_id=?,name=? WHERE id=? AND user_id=?', [$targetId, $title, (int) $category['id'], $sourceId]);
            }
            foreach ($scope['owned_ids'] as $channelId) {
                $this->execute('UPDATE posted_channels SET added_by=? WHERE id=? AND added_by=?', [$targetId, $channelId, $sourceId]);
                $this->execute('INSERT INTO posted_permissions(user_id,channel_id,can_post,can_schedule,can_manage_posts,can_manage_admins) VALUES(?,?,1,1,1,1) ON DUPLICATE KEY UPDATE can_post=1,can_schedule=1,can_manage_posts=1,can_manage_admins=1,is_ad_manager=0,post_quota_remaining=NULL,pin_quota_remaining=NULL', [$targetId, $channelId]);
                $this->execute('DELETE FROM posted_permissions WHERE user_id=? AND channel_id=?', [$sourceId, $channelId]);
            }
            $this->execute('DELETE FROM posted_admin_tokens WHERE user_id=?', [$sourceId]);
            $done = ['source_id'=>$sourceId,'token'=>$nonce,'target_id'=>$targetId,'fingerprint'=>$fingerprint,'result'=>$result,'created_at'=>$state['created_at'],'completed_at'=>time()];
            // One durable receipt per source survives navigation or a lost HTTP
            // reply. Its nonce prevents an old retry from moving newer records.
            $this->execute('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$this->receiptKey($sourceId), self::json($done)]);
            $this->execute("UPDATE posted_users SET state='account_transfer_done',state_data=? WHERE telegram_id=?", [self::json($done), $sourceId]);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
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
        $links = $this->rows('SELECT link.* FROM posted_folder_channels link INNER JOIN posted_folders folder ON folder.id=link.folder_id WHERE folder.owner_id=? ORDER BY link.folder_id,link.channel_id', [$sourceId]);
        $channelIds = array_map(static fn(array $r):int=>(int)$r['id'], $owned);
        foreach ([$posts, $templates, $links] as $records) foreach ($records as $record) if ((int)($record['channel_id']??0)>0) $channelIds[]=(int)$record['channel_id'];
        $channelIds = array_values(array_unique($channelIds)); sort($channelIds,SORT_NUMERIC);
        if (count($channelIds)>100) throw new DomainException('За один перенос поддерживается до 100 каналов. Обратитесь к администратору сервиса для переноса большого аккаунта.');
        $channels=[]; $permissions=[]; $categories=[]; $attempts=[]; $busy=[]; $edits=[];
        foreach ($channelIds as $id) {
            $channel=$this->rows('SELECT * FROM posted_channels WHERE id=?',[$id]);
            if(count($channel)!==1) throw new DomainException('Один из каналов больше не существует. Перенос остановлен.');
            $channels[]=$channel[0];
            $permissions=[...$permissions,...$this->rows('SELECT * FROM posted_permissions WHERE channel_id=? AND user_id IN (?,?) ORDER BY user_id',[$id,$sourceId,$targetId])];
            $categories=[...$categories,...$this->rows('SELECT * FROM posted_manager_categories WHERE channel_id=? AND user_id IN (?,?) ORDER BY id',[$id,$sourceId,$targetId])];
            $attempts=[...$attempts,...$this->rows('SELECT attempt.*,post.pin_after_publish AS transfer_pin_requested FROM posted_telegram_publish_attempts attempt INNER JOIN posted_posts post ON post.id=attempt.post_id WHERE attempt.channel_id=? ORDER BY attempt.post_id',[$id])];
            $busy=[...$busy,...$this->rows("SELECT id FROM posted_posts WHERE channel_id=? AND status IN ('publishing','telegram_pending') ORDER BY id",[$id])];
            $edits=[...$edits,...$this->rows('SELECT edit.user_id,edit.post_id FROM posted_edit_sessions edit INNER JOIN posted_posts post ON post.id=edit.post_id WHERE post.channel_id=? ORDER BY edit.post_id,edit.user_id',[$id])];
        }
        return ['source'=>$source,'target'=>$target,'owned_ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$owned),'channels'=>$channels,'posts'=>$posts,'templates'=>$templates,'folders'=>$folders,'links'=>$links,'permissions'=>$permissions,'categories'=>$categories,'attempts'=>$attempts,'busy'=>$busy,'edits'=>$edits];
    }

    private function validate(array &$scope, int $sourceId, int $targetId, bool $checkTelegram = true): void
    {
        if ($scope['owned_ids']===[] && $scope['posts']===[] && $scope['templates']===[] && $scope['folders']===[]) {
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
            $id=(int)$channel['id']; $owner=(int)($channel['added_by']??0);
            if (empty($channel['is_active']) || $owner<=0 || !empty($channel['admin_blocked'])) throw new DomainException('Один из каналов отключён или заблокирован. Сначала восстановите его доступ.');
            if (in_array($id,$scope['owned_ids'],true)) {
                if ($checkTelegram) {
                    $this->assertAdmin((string)$channel['chat_id'],$sourceId);
                    $this->assertAdmin((string)$channel['chat_id'],$targetId);
                }
                foreach ($scope['permissions'] as $permission) {
                    if ((int)$permission['channel_id']===$id && !empty($permission['is_ad_manager'])) {
                        throw new DomainException('В передаваемом канале один из аккаунтов имеет роль рекламного менеджера. Сначала согласуйте с владельцем перенос квот и роли.');
                    }
                }
            } else {
                $grants=[];
                foreach($scope['permissions'] as $permission) if((int)$permission['channel_id']===$id) $grants[(int)$permission['user_id']]=$permission;
                foreach ([$sourceId,$targetId] as $actor) {
                    if(empty($grants[$actor]['can_post'])||empty($grants[$actor]['can_schedule'])||!empty($grants[$actor]['is_ad_manager'])) {
                        throw new DomainException('Есть данные в чужом канале. Его владелец должен заранее выдать новому аккаунту права публикации и планирования; рекламные квоты автоматически не передаются.');
                    }
                }
            }
        }
        foreach($scope['posts'] as $post) {
            if(!empty($post['manager_category_id'])||!empty($post['manager_post_quota_charged'])||!empty($post['manager_pin_quota_charged'])) {
                throw new DomainException('Есть публикации с рекламными категориями или списанными квотами. Для них требуется перенос, согласованный с владельцем канала.');
            }
        }
    }

    private function summary(array $scope,int $sourceId,int $targetId):array
    {
        $document=$scope;
        // Conversation state changes while the confirmation is prepared.
        foreach(['source','target'] as $key) $document[$key]=['telegram_id'=>(int)$scope[$key]['telegram_id'],'is_active'=>(int)$scope[$key]['is_active']];
        // Reconciliation timestamps are bookkeeping, not a content change.
        foreach($document['channels'] as &$row) unset($row['updated_at']); unset($row);
        return ['source_id'=>$sourceId,'target_id'=>$targetId,'recipient'=>$scope['target_profile'],'channels'=>count($scope['owned_ids']),'posts'=>count($scope['posts']),'scheduled'=>count(array_filter($scope['posts'],static fn(array $p):bool=>$p['status']==='scheduled')),'templates'=>count($scope['templates']),'folders'=>count($scope['folders']),'fingerprint'=>hash('sha256',self::json($document))];
    }

    private function assertAdmin(string $chatId,int $userId):void
    {
        try { $response=($this->telegram)('getChatMember',['chat_id'=>$chatId,'user_id'=>$userId]); }
        catch(Throwable $error) { throw new DomainException('Telegram не подтвердил права аккаунтов. Перенос не выполнен, повторите проверку позже.',0,$error); }
        $member=$response['result']??null;
        if(empty($response['ok'])||!is_array($member)||!in_array($member['status']??'', ['creator','administrator'],true)||!empty($member['user']['is_bot'])||(int)($member['user']['id']??0)!==$userId) {
            throw new DomainException('Оба аккаунта должны оставаться владельцем или администратором каждого передаваемого канала. Добавьте новый аккаунт в администраторы до переноса.');
        }
    }

    private function profile(array $user):array
    {
        $id=(int)$user['telegram_id'];
        try { $response=($this->telegram)('getChat',['chat_id'=>$id]); }
        catch(Throwable $error) { throw new DomainException('Не удалось проверить получателя. Он должен запустить этого бота через /start и разрешить сообщения.',0,$error); }
        $chat=$response['result']??null;
        if(empty($response['ok'])||!is_array($chat)||(int)($chat['id']??0)!==$id||($chat['type']??'')!=='private') throw new DomainException('Telegram не подтвердил личный аккаунт получателя. Укажите его числовой Telegram ID после /start.');
        return ['telegram_id'=>$id,'username'=>(string)($chat['username']??''),'first_name'=>(string)($chat['first_name']??'')];
    }

    private function activeUser(int $id):array
    {
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
    }

    private function rows(string $sql,array $params=[]):array
    {
        $statement=$this->pdo->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function execute(string $sql,array $params=[]):void
    {
        $statement=$this->pdo->prepare($sql); $statement->execute($params);
    }

    private static function json(array $data):string
    {
        return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
