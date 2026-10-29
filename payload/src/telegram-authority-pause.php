<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Automatic authority loss is a durable stop, never an instruction to discard content. */
final class PostedTelegramAuthorityPauseException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {parent::__construct($reason==='authority_unknown'?'Telegram не подтвердил права. Посты, даты и повторы сохранены.':'Публикация приостановлена. Посты, даты и повторы сохранены; требуется проверка доступа.');}
}

final class PostedTelegramAuthorityPause
{
    public const PHASES=['paused','review_required','resumed','closed_explicitly'];
    public const REASONS=['owner_role_lost','bot_removed','bot_rights_lost','author_permission_lost','author_inactive','category_unavailable','explicit_disable','explicit_admin_detach'];
    public const STATUSES=['draft','scheduled','failed','publishing','telegram_pending','sent','cancelled','deleted'];
    private const RECORD_KEYS=['version','channel_id','chat_id','generation','source_owner_id','bound_owner_id','phase','reason','since','changed_at','review_required','resumed_at','review'];

    private static function reject(string $reason='invalid_pause'):never{throw new PostedTelegramAuthorityPauseException($reason);}
    private static function id(mixed $value):int
    {if((!is_int($value)&&!is_string($value))||preg_match('/\A[1-9][0-9]{0,15}\z/D',(string)$value)!==1||(int)$value<=0)self::reject();return(int)$value;}
    private static function rows(PDO $pdo,string $sql,array $args=[]):array
    {$s=$pdo->prepare($sql);$s->execute($args);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private static function json(array $value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function tx(PDO $pdo):void{if(!$pdo->inTransaction())throw new LogicException('Authority transition requires the existing channel/post transaction.');}
    public static function key(int $channelId):string{return'telegram_authority_pause_'.self::id($channelId);}
    public static function claimKey(int $postId):string{return'telegram_publish_claim_'.self::id($postId);}

    public static function normalize(mixed $value,array $channel):array
    {
        if(is_string($value)){try{$value=json_decode($value,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){self::reject();}}
        if(!is_array($value))self::reject();$keys=array_keys($value);sort($keys);$expected=self::RECORD_KEYS;sort($expected);
        if($keys!==$expected||($value['version']??null)!==1||!is_int($value['channel_id']??null)||$value['channel_id']!==(int)($channel['id']??0)
            ||!is_string($value['chat_id']??null)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$value['chat_id'])!==1||$value['chat_id']!==(string)($channel['chat_id']??'')
            ||!is_string($value['generation']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$value['generation'])!==1
            ||!is_int($value['source_owner_id']??null)||$value['source_owner_id']<=0||!is_int($value['bound_owner_id']??null)||$value['bound_owner_id']<0
            ||!in_array($value['phase']??null,self::PHASES,true)||!in_array($value['reason']??null,self::REASONS,true)
            ||!is_int($value['since']??null)||$value['since']<=0||!is_int($value['changed_at']??null)||$value['changed_at']<$value['since']||$value['changed_at']>time()+30
            ||!is_bool($value['review_required']??null)||$value['review_required']!==in_array($value['phase'],['paused','review_required'],true)
            ||($value['resumed_at']!==null&&(!is_int($value['resumed_at'])||$value['resumed_at']<$value['since']||$value['resumed_at']>time()+30))
            ||($value['review']!==null&&!is_array($value['review'])))self::reject();
        if($value['review']!==null){$r=$value['review'];$rk=array_keys($r);sort($rk);$want=['actor_id','expires_at','fingerprint','generation','nonce'];sort($want);
            if($rk!==$want||!is_int($r['actor_id'])||$r['actor_id']<=0||!is_int($r['expires_at'])||$r['expires_at']<=0
                ||!is_string($r['nonce'])||preg_match('/\A[a-f0-9]{32}\z/D',$r['nonce'])!==1
                ||$r['generation']!==$value['generation']||!is_string($r['fingerprint'])||preg_match('/\A[a-f0-9]{64}\z/D',$r['fingerprint'])!==1)self::reject();}
        // Captured/source owner is provenance, never an authorization grant. A
        // transfer may change current ownership and author IDs while the stop stays.
        return$value;
    }
    public static function read(PDO $pdo,array $channel,bool $lock=false):?array
    {
        $rows=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''),[self::key((int)$channel['id'])]);
        return$rows===[]?null:self::normalize($rows[0]['setting_value'],$channel);
    }
    public static function dueSql(string $alias):string
    {
        if($alias!=='c')throw new LogicException('Only the fixed channel alias is allowed.');
        // CASE is mandatory: malformed historical JSON must not throw or consume due LIMIT slots.
        $key="CONCAT('telegram_authority_pause_',c.id)";
        $valid="JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.version'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.version')=1"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.channel_id'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.channel_id')=c.id"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.chat_id'))='STRING' AND JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.chat_id'))=c.chat_id"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.generation'))='STRING' AND JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.generation')) REGEXP '^[a-f0-9]{32}$'"
            ." AND JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.phase'))='resumed' AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.phase'))='STRING'"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.review_required'))='BOOLEAN' AND JSON_EXTRACT(s.setting_value,'$.review_required')=CAST('false' AS JSON)"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.source_owner_id'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.source_owner_id')>0"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.bound_owner_id'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.bound_owner_id')>=0"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.since'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.since')>0"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.changed_at'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.changed_at')>=JSON_EXTRACT(s.setting_value,'$.since') AND JSON_EXTRACT(s.setting_value,'$.changed_at')<=UNIX_TIMESTAMP()+30"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.resumed_at'))='INTEGER' AND JSON_EXTRACT(s.setting_value,'$.resumed_at')>=JSON_EXTRACT(s.setting_value,'$.since') AND JSON_EXTRACT(s.setting_value,'$.resumed_at')<=UNIX_TIMESTAMP()+30"
            ." AND JSON_TYPE(JSON_EXTRACT(s.setting_value,'$.review'))='NULL' AND JSON_LENGTH(s.setting_value)=13"
            ." AND JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.reason')) IN ('owner_role_lost','bot_removed','bot_rights_lost','author_permission_lost','author_inactive','category_unavailable','explicit_disable','explicit_admin_detach')";
        return"NOT EXISTS(SELECT 1 FROM posted_app_settings s WHERE s.setting_key={$key} AND CASE WHEN JSON_VALID(s.setting_value)=1 THEN NOT(COALESCE(({$valid}),0)) ELSE 1 END)";
    }
    public static function generation(PDO $pdo,array $channel,bool $lock=false):string
    {return self::read($pdo,$channel,$lock)['generation']??'';}
    public static function blocked(PDO $pdo,array $channel,bool $lock=false):bool
    {$record=self::read($pdo,$channel,$lock);return$record!==null&&$record['phase']!=='resumed';}
    public static function assertDeliveryLocked(PDO $pdo,array $channel):void
    {self::tx($pdo);if(self::blocked($pdo,$channel,true))self::reject('paused');}
    private static function write(PDO $pdo,array $record):void
    {
        self::tx($pdo);$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([self::key($record['channel_id']),self::json($record)]);
    }
    public static function pauseLocked(PDO $pdo,array $channel,string $reason):array
    {
        self::tx($pdo);if(!in_array($reason,array_slice(self::REASONS,0,6),true))self::reject();
        $owner=self::id($channel['added_by']??0);
        $actual=self::rows($pdo,'SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE',[(int)$channel['id']]);
        if($actual===[]||(string)$actual[0]['chat_id']!==(string)$channel['chat_id']||(int)$actual[0]['added_by']!==$owner)self::reject('stale_owner');
        $channel=$actual[0];$previous=self::read($pdo,$channel,true);$now=time();
        if($previous!==null&&in_array($previous['phase'],['paused','review_required'],true)){$record=$previous;if($record['review']!==null){$record['review']=null;$record['phase']='paused';$record['changed_at']=$now;}}
        else $record=['version'=>1,'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'generation'=>bin2hex(random_bytes(16)),
            'source_owner_id'=>$owner,'bound_owner_id'=>$owner,'phase'=>'paused','reason'=>$reason,'since'=>$now,'changed_at'=>$now,'review_required'=>true,'resumed_at'=>null,'review'=>null];
        self::write($pdo,$record);
        // No post/grant/category/attempt/charge/pin mutation belongs to a pause.
        $pdo->prepare('UPDATE posted_channels SET is_active=0 WHERE id=? AND chat_id=? AND added_by=?')
            ->execute([(int)$channel['id'],(string)$channel['chat_id'],$owner]);return$record;
    }
    public static function closeExplicitLocked(PDO $pdo,array $channel,string $reason):void
    {
        self::tx($pdo);if(!in_array($reason,['explicit_disable','explicit_admin_detach'],true))self::reject();
        $record=self::read($pdo,$channel,true);if($record===null){$owner=(int)($channel['added_by']??0);if($owner<=0)return;
            $record=['version'=>1,'channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'source_owner_id'=>$owner,'bound_owner_id'=>$owner,'since'=>time()];}
        $record=array_replace($record,['generation'=>bin2hex(random_bytes(16)),'phase'=>'closed_explicitly','reason'=>$reason,'changed_at'=>time(),'review_required'=>false,'resumed_at'=>null,'review'=>null]);self::write($pdo,$record);
    }
    public static function reopenExplicitLocked(PDO $pdo,array $channel,int $actor,array $proof):void
    {
        self::tx($pdo);self::reviewProof($channel,$actor,$proof);if((int)$channel['added_by']!==$actor)self::reject('stale_owner');
        $record=self::read($pdo,$channel,true);if($record===null||$record['phase']!=='closed_explicitly')self::reject('stale_review');
        $posts=self::rows($pdo,"SELECT id,status FROM posted_posts WHERE channel_id=? ORDER BY id LIMIT 1001 FOR UPDATE",[(int)$channel['id']]);
        if(count($posts)>1000)self::reject('review_blocked');foreach($posts as$p)if(in_array($p['status'],['publishing','telegram_pending','scheduled'],true))self::reject('review_blocked');
        $attempts=self::rows($pdo,'SELECT state,gate_channel_id,pin_started_at,pin_completed_at,pin_delivery_uncertain FROM posted_telegram_publish_attempts WHERE channel_id=? ORDER BY post_id FOR UPDATE',[(int)$channel['id']]);
        foreach($attempts as$a)if($a['state']!=='completed'||$a['gate_channel_id']!==null||!empty($a['pin_delivery_uncertain'])||($a['pin_started_at']!==null&&$a['pin_completed_at']===null))self::reject('review_blocked');
        $review=self::reviewSnapshotLocked($pdo,$channel,$actor,true);if($review['blocked_reasons']!==[])self::reject('review_blocked');
        $now=time();$record=array_replace($record,['generation'=>bin2hex(random_bytes(16)),'source_owner_id'=>$actor,'bound_owner_id'=>$actor,'phase'=>'resumed','since'=>$now,'changed_at'=>$now,'review_required'=>false,'resumed_at'=>$now,'review'=>null]);self::write($pdo,$record);
    }
    public static function submissionContext(PDO $pdo,array $post):?array
    {
        $rows=self::rows($pdo,'SELECT id,chat_id,added_by FROM posted_channels WHERE id=? LIMIT 1',[(int)$post['channel_id']]);
        if($rows===[])self::reject();$generation=self::generation($pdo,$rows[0]);return$generation===''?null:['generation'=>$generation];
    }
    public static function createClaimLocked(PDO $pdo,array $post,array $channel,string $prior):array
    {
        self::tx($pdo);self::assertDeliveryLocked($pdo,$channel);if(!in_array($prior,['draft','scheduled','failed'],true))self::reject('stale_claim');
        $claim=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'chat_id'=>(string)$channel['chat_id'],
            'owner_id'=>(int)$channel['added_by'],'author_id'=>(int)$post['author_id'],'generation'=>self::generation($pdo,$channel,true),
            'nonce'=>bin2hex(random_bytes(16)),'prior_status'=>$prior,'started_at'=>(string)$post['publish_started_at']];
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([self::claimKey($claim['post_id']),self::json($claim)]);return$claim;
    }
    private static function claim(PDO $pdo,int $id,bool $lock):?array
    {
        $rows=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''),[self::claimKey($id)]);if($rows===[])return null;
        try{$claim=json_decode($rows[0]['setting_value'],true,16,JSON_THROW_ON_ERROR);}catch(Throwable){self::reject('stale_claim');}
        $keys=is_array($claim)?array_keys($claim):[];sort($keys);$want=['version','post_id','channel_id','chat_id','owner_id','author_id','generation','nonce','prior_status','started_at'];sort($want);
        if($keys!==$want||!is_int($claim['channel_id']??null)||$claim['channel_id']<=0||!is_int($claim['owner_id']??null)||$claim['owner_id']<=0
            ||!is_int($claim['author_id']??null)||$claim['author_id']<=0||!is_string($claim['chat_id']??null)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$claim['chat_id'])!==1)self::reject('stale_claim');
        if(!is_array($claim)||($claim['version']??null)!==1||($claim['post_id']??null)!==$id||!is_string($claim['nonce']??null)
            ||preg_match('/\A[a-f0-9]{32}\z/D',$claim['nonce'])!==1||!is_string($claim['generation']??null)
            ||($claim['generation']!==''&&preg_match('/\A[a-f0-9]{32}\z/D',$claim['generation'])!==1)
            ||!in_array($claim['prior_status']??null,['draft','scheduled','failed'],true)||!is_string($claim['started_at']??null))self::reject('stale_claim');return$claim;
    }
    public static function ownedClaimLocked(PDO $pdo,array $request,array $current,array $channel,bool $delivery):?array
    {
        self::tx($pdo);$claim=self::claim($pdo,(int)$current['id'],true);$generation=self::generation($pdo,$channel,true);
        if($claim===null){
            // Only a genuinely pre-deploy, never-paused claim can be adopted.
            if($generation!==''||isset($request['_authority_claim_nonce'])||($request['publish_started_at']??null)!==($current['publish_started_at']??null))self::reject('stale_claim');
            return null;
        }
        if(($request['_authority_claim_nonce']??null)!==$claim['nonce']||$claim['channel_id']!==(int)$current['channel_id']
            ||$claim['author_id']!==(int)$current['author_id']||$claim['chat_id']!==(string)$channel['chat_id']
            ||$claim['started_at']!==(string)($current['publish_started_at']??''))self::reject('stale_claim');
        $record=self::read($pdo,$channel,true);if($record!==null&&$record['phase']==='closed_explicitly')self::reject('stale_claim');
        if($delivery&&($claim['generation']!==$generation||$claim['owner_id']!==(int)$channel['added_by']))self::reject('stale_claim');
        if($delivery)self::assertDeliveryLocked($pdo,$channel);return$claim;
    }
    public static function canEditReview(PDO $pdo,array $channel,int $actor,bool $lock=false):bool
    {
        $record=self::read($pdo,$channel,$lock);$r=$record['review']??null;
        return(int)($channel['added_by']??0)===$actor&&!empty($channel['is_active'])&&empty($channel['admin_blocked'])&&$record!==null&&$record['phase']==='review_required'
            &&is_array($r)&&$r['actor_id']===$actor&&$r['generation']===$record['generation']&&$r['expires_at']>=time();
    }
    public static function recoveryRequest(PDO $pdo,array $current):?array
    {
        $claim=self::claim($pdo,(int)$current['id'],false);if($claim===null)return null;
        if($claim['channel_id']!==(int)$current['channel_id']||$claim['author_id']!==(int)$current['author_id']||$claim['started_at']!==(string)($current['publish_started_at']??''))self::reject('stale_claim');
        return array_replace($current,['_authority_claim_nonce'=>$claim['nonce'],'_claimed_from_status'=>$claim['prior_status']]);
    }
    public static function forgetClaimLocked(PDO $pdo,int $postId,string $nonce):void
    {self::tx($pdo);$claim=self::claim($pdo,$postId,true);if($claim!==null&&hash_equals($claim['nonce'],$nonce))$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::claimKey($postId)]);}

    private static function activeActor(PDO $pdo,int $actor):void
    {if($actor<=0||self::rows($pdo,'SELECT telegram_id FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1',[$actor])===[])self::reject('actor_denied');}
    private static function counts(PDO $pdo,int $actor,int $channel):array
    {
        $out=array_fill_keys([...self::STATUSES,'future','overdue'],0);
        foreach(self::rows($pdo,'SELECT status,COUNT(*) AS n FROM posted_posts WHERE author_id=? AND channel_id=? GROUP BY status',[$actor,$channel])as$row)
            if(isset($out[$row['status']]))$out[$row['status']]=(int)$row['n'];
        $rows=self::rows($pdo,"SELECT SUM(scheduled_at>NOW()) AS future,SUM(scheduled_at<=NOW()) AS overdue FROM posted_posts WHERE author_id=? AND channel_id=? AND status='scheduled'",[$actor,$channel]);
        foreach(['future','overdue']as$key)$out[$key]=(int)($rows[0][$key]??0);return$out;
    }
    private static function channelDto(PDO $pdo,int $actor,array $channel,array $record):array
    {return['id'=>(int)$channel['id'],'title'=>(string)$channel['title'],'phase'=>$record['phase'],'reason'=>$record['reason'],'since'=>$record['since'],
        'generation'=>$record['generation'],'review_required'=>$record['review_required'],'own_counts'=>self::counts($pdo,$actor,(int)$channel['id'])];}
    private static function postDto(array $post):array
    {return['id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'author_id'=>(int)$post['author_id'],'status'=>(string)$post['status'],
        'scheduled_at'=>$post['scheduled_at']===null?null:(string)$post['scheduled_at'],'text'=>mb_substr((string)($post['text']??''),0,600,'UTF-8'),
        'media_type'=>(string)$post['media_type'],'repeat_interval_minutes'=>$post['repeat_interval_minutes']===null?null:(int)$post['repeat_interval_minutes'],
        'repeat_until'=>$post['repeat_until']===null?null:(string)$post['repeat_until']];}
    private static function authoredChannel(PDO $pdo,int $actor,int $channel):array
    {
        self::activeActor($pdo,$actor);$rows=self::rows($pdo,'SELECT c.* FROM posted_channels c WHERE c.id=? AND EXISTS(SELECT 1 FROM posted_posts p WHERE p.channel_id=c.id AND p.author_id=?) LIMIT 1',[$channel,$actor]);
        if($rows===[])self::reject('not_found');$record=self::read($pdo,$rows[0]);if($record===null)self::reject('not_found');return[$rows[0],$record];
    }
    public static function listForAuthor(PDO $pdo,int $actor,int $offset=0,int $limit=20):array
    {
        self::activeActor($pdo,$actor);if($offset<0||$offset>10000||$limit<1||$limit>20)throw new InvalidArgumentException('Invalid pause page.');
        // Validate every candidate before looking at phase/review flags. A
        // malformed record must produce an honest error rather than an empty list.
        $rows=self::rows($pdo,"SELECT c.id,c.chat_id,c.title,s.setting_value AS authority_record FROM posted_channels c JOIN posted_app_settings s ON s.setting_key=CONCAT('telegram_authority_pause_',c.id) WHERE EXISTS(SELECT 1 FROM posted_posts p WHERE p.channel_id=c.id AND p.author_id=?) ORDER BY c.id LIMIT 10001",[$actor]);
        if(count($rows)>10000)self::reject();$valid=[];$bytes=0;
        foreach($rows as$row){$bytes+=strlen($row['authority_record']);if($bytes>4194304)self::reject();$record=self::normalize($row['authority_record'],$row);if($record['review_required'])$valid[]=[$row,$record];}
        $items=[];foreach(array_slice($valid,$offset,$limit)as[$row,$record])$items[]=self::channelDto($pdo,$actor,$row,$record);
        self::activeActor($pdo,$actor);return['items'=>$items,'total'=>count($valid),'offset'=>$offset,'limit'=>$limit];
    }
    public static function postsForAuthor(PDO $pdo,int $actor,int $channelId,?int $cursor=null,int $limit=10):array
    {
        [$channel,$record]=self::authoredChannel($pdo,$actor,$channelId);if($limit<1||$limit>10||($cursor!==null&&$cursor<=0))throw new InvalidArgumentException('Invalid pause post page.');
        $rows=self::rows($pdo,'SELECT id,channel_id,author_id,status,scheduled_at,LEFT(text,601) AS text,media_type,repeat_interval_minutes,repeat_until FROM posted_posts WHERE channel_id=? AND author_id=?'.($cursor!==null?' AND id>?':'').' ORDER BY id LIMIT '.($limit+1),$cursor===null?[$channelId,$actor]:[$channelId,$actor,$cursor]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);self::activeActor($pdo,$actor);
        return['channel'=>self::channelDto($pdo,$actor,$channel,$record),'items'=>array_map(self::postDto(...),$rows),'next_cursor'=>$more?(int)$rows[array_key_last($rows)]['id']:null];
    }
    public static function postForAuthor(PDO $pdo,int $actor,int $postId):?array
    {
        self::activeActor($pdo,$actor);$rows=self::rows($pdo,'SELECT id,channel_id,author_id,status,scheduled_at,LEFT(text,601) AS text,media_type,repeat_interval_minutes,repeat_until FROM posted_posts WHERE id=? AND author_id=? LIMIT 1',[$postId,$actor]);if($rows===[])return null;
        [$channel,$record]=self::authoredChannel($pdo,$actor,(int)$rows[0]['channel_id']);self::activeActor($pdo,$actor);
        return['channel'=>self::channelDto($pdo,$actor,$channel,$record),'post'=>self::postDto($rows[0])];
    }
    public static function listForOwner(PDO $pdo,int $actor,int $offset=0,int $limit=20):array
    {
        self::activeActor($pdo,$actor);if($offset<0||$offset>10000||$limit<1||$limit>20)throw new InvalidArgumentException('Invalid owner pause page.');
        $rows=self::rows($pdo,"SELECT c.id,c.chat_id,c.title,s.setting_value AS authority_record FROM posted_channels c JOIN posted_app_settings s ON s.setting_key=CONCAT('telegram_authority_pause_',c.id) WHERE c.added_by=? ORDER BY c.id LIMIT 10001",[$actor]);
        if(count($rows)>10000)self::reject();$valid=[];$bytes=0;
        foreach($rows as$row){$bytes+=strlen($row['authority_record']);if($bytes>4194304)self::reject();$record=self::normalize($row['authority_record'],$row);if($record['review_required'])$valid[]=[$row,$record];}
        $items=[];foreach(array_slice($valid,$offset,$limit)as[$row,$record])$items[]=self::channelDto($pdo,$actor,$row,$record);
        self::activeActor($pdo,$actor);return['items'=>$items,'total'=>count($valid),'offset'=>$offset,'limit'=>$limit];
    }
    public static function reviewBinding(PDO $pdo,int $actor,array $channel,string $nonce):string
    {
        self::activeActor($pdo,$actor);$record=self::read($pdo,$channel);$review=$record['review']??null;
        if($record===null||!is_array($review)||($review['actor_id']??null)!==$actor||($review['nonce']??null)!==$nonce
            ||($review['expires_at']??0)<time()||($review['generation']??null)!==$record['generation'])self::reject('stale_review');return$record['generation'];
    }
    /** Trusted server proof is minted by the real private API preflight, never client input. */
    private static function reviewProof(array $channel,int $actor,array $proof):void
    {
        if(($proof['actor_id']??null)!==$actor||($proof['chat_id']??null)!==(string)$channel['chat_id']
            ||($proof['actor_admin']??null)!==true||($proof['bot_admin']??null)!==true
            ||!is_int($proof['monotonic']??null)||hrtime(true)-$proof['monotonic']<0||hrtime(true)-$proof['monotonic']>60000000000)self::reject('authority_unknown');
    }
    private static function reviewSnapshotLocked(PDO $pdo,array $channel,int $actor,bool $explicitRebind=false):array
    {
        self::tx($pdo);$id=(int)$channel['id'];$reasons=[];$future=0;$overdue=0;if(!empty($channel['admin_blocked'])||empty($channel['bot_can_post']))$reasons[]='author_permissions';
        if((int)$channel['added_by']!==$actor)$reasons[]='owner_change_requires_review';
        $binding=self::read($pdo,$channel,true);if(!$explicitRebind&&$binding!==null&&$binding['bound_owner_id']!==(int)$channel['added_by'])$reasons[]='owner_change_requires_review';
        $posts=self::rows($pdo,'SELECT * FROM posted_posts WHERE channel_id=? ORDER BY id LIMIT 1001 FOR UPDATE',[$id]);
        if(count($posts)>1000)$reasons[]='residual_publish';
        $actors=[$actor,(int)$channel['added_by']];foreach($posts as$post)$actors[]=(int)$post['author_id'];$actors=array_values(array_unique(array_filter($actors,static fn(int$v):bool=>$v>0)));sort($actors,SORT_NUMERIC);
        $active=[];foreach($actors as$userId){$user=self::rows($pdo,'SELECT telegram_id,is_active FROM posted_users WHERE telegram_id=? LIMIT 1 FOR UPDATE',[$userId]);$active[$userId]=$user!==[]&&(int)$user[0]['is_active']===1;}
        if(!($active[$actor]??false))self::reject('actor_denied');
        $grants=self::rows($pdo,'SELECT * FROM posted_permissions WHERE channel_id=? ORDER BY user_id FOR UPDATE',[$id]);$permission=[];foreach($grants as$row)$permission[(int)$row['user_id']]=$row;
        $categories=self::rows($pdo,'SELECT * FROM posted_manager_categories WHERE channel_id=? ORDER BY id FOR UPDATE',[$id]);$category=[];foreach($categories as$row)$category[(int)$row['id']]=$row;
        foreach($posts as$post){
            if(in_array($post['status'],['publishing','telegram_pending'],true))$reasons[]='residual_publish';
            if($post['status']!=='scheduled')continue;
            if($post['scheduled_at']===null){$reasons[]='missing_schedule';continue;}
            try{$at=new DateTimeImmutable($post['scheduled_at'],new DateTimeZone(date_default_timezone_get()));}catch(Throwable){$reasons[]='missing_schedule';continue;}
            if($at->getTimestamp()<=time()+300){$overdue++;$reasons[]='overdue_posts';}else$future++;
            $author=(int)$post['author_id'];$grant=$permission[$author]??null;
            if(!($active[$author]??false)||!is_array($grant)||empty($grant['can_post'])||empty($grant['can_schedule']))$reasons[]='author_permissions';
            $categoryId=(int)($post['manager_category_id']??0);
            if($categoryId>0&&($author!==(int)$channel['added_by'])&&(!isset($category[$categoryId])||empty($category[$categoryId]['is_active'])||(int)$category[$categoryId]['user_id']!==$author||empty($grant['is_ad_manager'])))$reasons[]='author_permissions';
        }
        $attempts=self::rows($pdo,'SELECT * FROM posted_telegram_publish_attempts WHERE channel_id=? ORDER BY post_id FOR UPDATE',[$id]);
        $postsById=[];foreach($posts as$p)$postsById[(int)$p['id']]=$p;
        foreach($attempts as$a){$p=$postsById[(int)$a['post_id']]??null;
            $noEffect=is_array($p)&&empty($p['pin_after_publish'])&&empty($p['manager_pin_quota_charged'])&&$a['pin_started_at']===null&&empty($a['pin_delivery_uncertain']);
            if($a['state']!=='completed'||$a['gate_channel_id']!==null||($a['pin_started_at']!==null&&$a['pin_completed_at']===null)||!empty($a['pin_delivery_uncertain'])||($a['pin_completed_at']===null&&!$noEffect))$reasons[]='pending_ledger';
        }
        // Conservative v1: never discard/guess a cross-channel fanout plan.
        $multipost=self::rows($pdo,"SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP '^telegram_multipost_[1-9][0-9]*$' ORDER BY setting_key LIMIT 1001");
        if(count($multipost)>1000)$reasons[]='pending_multipost';
        foreach($multipost as$row){try{$doc=json_decode($row['setting_value'],true,64,JSON_THROW_ON_ERROR);PostedTelegramMultipost::validate($doc);}catch(Throwable){$reasons[]='pending_multipost';continue;}
            $affected=(int)$doc['binding']['channel_id']===$id;foreach($doc['targets']as$target)if((int)$target['channel_id']===$id)$affected=true;if(!$affected)continue;
            $root=self::rows($pdo,'SELECT id,status FROM posted_posts WHERE id=? LIMIT 1',[$doc['binding']['post_id']]);
            $terminal=$root!==[]&&in_array($root[0]['status'],['sent','deleted','cancelled','failed'],true);$pending=!$terminal;
            $rootAttempt=self::rows($pdo,'SELECT state,gate_channel_id,pin_started_at,pin_completed_at,pin_delivery_uncertain FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1',[$doc['binding']['post_id']]);
            foreach($rootAttempt as$a)if($a['state']!=='completed'||$a['gate_channel_id']!==null||($a['pin_started_at']!==null&&$a['pin_completed_at']===null)||!empty($a['pin_delivery_uncertain'])||$a['pin_completed_at']===null)$pending=true;
            if($doc['phase']==='ready')$pending=true;
            if($doc['phase']==='materialized')foreach($doc['children']as$child){$childRows=self::rows($pdo,'SELECT status FROM posted_posts WHERE id=? LIMIT 1',[$child['post_id']]);if($childRows===[]||in_array($childRows[0]['status'],['draft','scheduled','publishing','telegram_pending'],true))$pending=true;}
            if($pending)$reasons[]='pending_multipost';
        }
        $reasons=array_values(array_unique($reasons));sort($reasons);
        return['fingerprint'=>hash('sha256',self::json([$posts,$grants,$categories,$attempts,$multipost])),'blocked_reasons'=>$reasons,'future_count'=>$future,'overdue_count'=>$overdue];
    }
    public static function prepareReviewLocked(PDO $pdo,array $channel,int $actor,array $proof):array
    {
        self::tx($pdo);self::reviewProof($channel,$actor,$proof);if((int)($channel['added_by']??0)!==$actor)self::reject('owner_change_requires_review');$record=self::read($pdo,$channel,true);if($record===null||!$record['review_required'])self::reject('stale_review');
        $snapshot=self::reviewSnapshotLocked($pdo,$channel,$actor);$nonce=bin2hex(random_bytes(16));$expiry=time()+600;
        $record['phase']='review_required';$record['changed_at']=time();$record['review']=['actor_id'=>$actor,'nonce'=>$nonce,'expires_at'=>$expiry,'generation'=>$record['generation'],'fingerprint'=>$snapshot['fingerprint']];self::write($pdo,$record);
        if(empty($channel['admin_blocked'])&&!in_array('owner_change_requires_review',$snapshot['blocked_reasons'],true))$pdo->prepare('UPDATE posted_channels SET is_active=1 WHERE id=? AND added_by=? AND admin_blocked=0')->execute([(int)$channel['id'],$actor]);
        return['channel_id'=>(int)$channel['id'],'title'=>(string)$channel['title'],'generation'=>$record['generation'],'nonce'=>$nonce,'expires_at'=>$expiry,
            'future_count'=>$snapshot['future_count'],'overdue_count'=>$snapshot['overdue_count'],'blocked_reasons'=>$snapshot['blocked_reasons'],'can_resume'=>$snapshot['blocked_reasons']===[]];
    }
    public static function confirmReviewLocked(PDO $pdo,array $channel,int $actor,array $proof,string $nonce,string $generation):void
    {
        self::tx($pdo);self::reviewProof($channel,$actor,$proof);$record=self::read($pdo,$channel,true);$review=$record['review']??null;
        if($record===null||!$record['review_required']||!is_array($review)||($review['actor_id']??null)!==$actor||($review['nonce']??null)!==$nonce
            ||($review['generation']??null)!==$generation||$record['generation']!==$generation||($review['expires_at']??0)<time())self::reject('stale_review');
        $snapshot=self::reviewSnapshotLocked($pdo,$channel,$actor);if($snapshot['blocked_reasons']!==[])self::reject('review_blocked');
        if(!hash_equals((string)($review['fingerprint']??''),$snapshot['fingerprint']))self::reject('stale_review');
        self::reviewProof($channel,$actor,$proof);$record['phase']='resumed';$record['changed_at']=time();$record['review_required']=false;$record['resumed_at']=time();$record['review']=null;self::write($pdo,$record);
        $pdo->prepare('UPDATE posted_channels SET is_active=1 WHERE id=? AND chat_id=? AND added_by=? AND admin_blocked=0')->execute([(int)$channel['id'],(string)$channel['chat_id'],$actor]);
        $check=self::rows($pdo,'SELECT added_by,is_active,admin_blocked FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE',[(int)$channel['id']]);
        if($check===[]||(int)$check[0]['added_by']!==$actor||empty($check[0]['is_active'])||!empty($check[0]['admin_blocked']))self::reject('stale_review');
    }
}


trait PostedTelegramAuthorityPauseCore
{
    private function telegramAuthorityProof(int $actor,int $channelId): array
    {
        if($this->pdo->inTransaction())throw new PostedTelegramAuthorityPauseException('authority_unknown');
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);
        if(!$this->isKnownActiveUser($this->user($actor)))throw new PostedTelegramAuthorityPauseException('actor_denied');
        $channel=$this->channel($channelId);if(!$channel)throw new PostedTelegramAuthorityPauseException('not_found');
        $started=hrtime(true);
        try{
            $me=$this->telegram->call('getMe');$identity=$me['result']??null;
            if(($me['ok']??null)!==true||!is_array($identity)||!is_int($identity['id']??null)||$identity['id']<=0||($identity['is_bot']??null)!==true)throw new RuntimeException('Unconfirmed identity.');
            $bot=$this->telegram->call('getChatMember',['chat_id'=>(string)$channel['chat_id'],'user_id'=>$identity['id']]);
            $human=$this->telegram->call('getChatMember',['chat_id'=>(string)$channel['chat_id'],'user_id'=>$actor]);
            $b=$bot['result']??[];$h=$human['result']??[];
            if(($bot['ok']??null)!==true||($human['ok']??null)!==true||($b['user']['id']??null)!==$identity['id']||($b['user']['is_bot']??null)!==true
                ||($h['user']['id']??null)!==$actor||($h['user']['is_bot']??null)!==false
                ||!in_array($h['status']??null,['creator','administrator'],true)||($b['status']??null)!=='administrator'||($b['can_post_messages']??null)!==true)
                throw new RuntimeException('Unconfirmed administrator role.');
        }catch(Throwable){throw new PostedTelegramAuthorityPauseException('authority_unknown');}
        return ['actor_id'=>$actor,'chat_id'=>(string)$channel['chat_id'],'actor_admin'=>true,'bot_admin'=>true,'monotonic'=>$started];
    }
    private function telegramAuthorityOwnerReviewPrepare(int $actor,int $channelId): array
    {
        $proof=$this->telegramAuthorityProof($actor,$channelId);PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try{$s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            if(!$channel)throw new PostedTelegramAuthorityPauseException('not_found');
            $out=PostedTelegramAuthorityPause::prepareReviewLocked($this->pdo,$channel,$actor,$proof);PostedTelegramRootedAccess::commit($this->pdo);return$out;
        }catch(Throwable$e){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$e;}
    }
    private function telegramAuthorityOwnerReviewBinding(int $actor,int $channelId,string $nonce): string
    {
        PostedTelegramPolicy::requireOperator($this->config,$actor,$this->pdo);$channel=$this->channel($channelId);
        if(!$channel)throw new PostedTelegramAuthorityPauseException('not_found');
        return PostedTelegramAuthorityPause::reviewBinding($this->pdo,$actor,$channel,$nonce);
    }
    private function telegramAuthorityOwnerReviewConfirm(int $actor,int $channelId,string $nonce,string $generation): array
    {
        $proof=$this->telegramAuthorityProof($actor,$channelId);PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try{$s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            if(!$channel)throw new PostedTelegramAuthorityPauseException('not_found');
            PostedTelegramAuthorityPause::confirmReviewLocked($this->pdo,$channel,$actor,$proof,$nonce,$generation);PostedTelegramRootedAccess::commit($this->pdo);
            return ['resumed'=>true,'notice'=>'Права проверены. Публикации возобновлены с сохранёнными датами.'];
        }catch(Throwable$e){if($this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$e;}
    }
    private function telegramAuthorityRestoreClaim(array $request,?string $error=null): bool
    {
        $postId=(int)($request['id']??0);$channelId=(int)($request['channel_id']??0);if($postId<=0||$channelId<=0)return false;
        $own=!$this->pdo->inTransaction();if($own)PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try{
            $s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            $s=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=? AND channel_id=? LIMIT 1 FOR UPDATE');$s->execute([$postId,$channelId]);$current=$s->fetch();
            if(!$channel||!$current||$current['status']!=='publishing'||$this->hasRecordedTelegramPublishAttempt($postId)){if($own)PostedTelegramRootedAccess::commit($this->pdo);return false;}
            try{$claim=PostedTelegramAuthorityPause::ownedClaimLocked($this->pdo,$request,$current,$channel,false);}catch(PostedTelegramAuthorityPauseException){if($own)PostedTelegramRootedAccess::commit($this->pdo);return false;}
            $prior=$claim['prior_status']??($request['_claimed_from_status']??'draft');if(!in_array($prior,['draft','scheduled','failed'],true))$prior='draft';
            $s=$this->pdo->prepare("UPDATE posted_posts SET status=?,publish_started_at=NULL,error_text=? WHERE id=? AND status='publishing' AND publish_started_at<=>? AND NOT EXISTS(SELECT 1 FROM posted_telegram_publish_attempts a WHERE a.post_id=posted_posts.id)");
            $s->execute([$prior,mb_substr($error??'Публикация приостановлена. Пост, дата и повторы сохранены.',0,4000),$postId,$current['publish_started_at']]);
            $ok=$s->rowCount()===1;if($ok&&$claim!==null)PostedTelegramAuthorityPause::forgetClaimLocked($this->pdo,$postId,$claim['nonce']);
            if($own)PostedTelegramRootedAccess::commit($this->pdo);return$ok;
        }catch(Throwable$e){if($own&&$this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$e;}
    }
    private function telegramAuthorityPauseChannel(int $channelId,int $owner,string $reason): bool
    {
        $own=!$this->pdo->inTransaction();if($own)PostedTelegramRootedAccess::begin($this->pdo,$this->config,true);
        try{$s=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([$channelId]);$channel=$s->fetch();
            if(!$channel||(int)$channel['added_by']!==$owner||$owner<=0){if($own)PostedTelegramRootedAccess::commit($this->pdo);return false;}
            PostedTelegramAuthorityPause::pauseLocked($this->pdo,$channel,$reason);if($own)PostedTelegramRootedAccess::commit($this->pdo);return true;
        }catch(Throwable$e){if($own&&$this->pdo->inTransaction())PostedTelegramRootedAccess::rollback($this->pdo);throw$e;}
    }
}
