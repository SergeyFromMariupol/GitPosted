<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

final class PostedTelegramAlbumPendingException extends RuntimeException
{public function __construct(){parent::__construct('Альбом ещё загружается или его предпросмотр не подтверждён. Подождите и откройте карточку поста заново.');}}
final class PostedTelegramAlbumStaleInputException extends RuntimeException {}

/** Durable private-input assembly. This registry cannot authorize native reposts or publication. */
final class PostedTelegramAlbumCollector
{
    public const QUIET_MS=900;
    public const MAX_TAIL_MS=3500;
    public static function now():int{return(int)floor(microtime(true)*1000);}
    public static function group(array $m,int $actor):?string
    {
        $g=$m['media_group_id']??null;
        return is_string($g)&&$g!==''&&strlen($g)<=128&&preg_match('/[\x00-\x20\x7f]/',$g)!==1
            &&($m['chat']['type']??null)==='private'&&($m['chat']['id']??null)===$actor&&($m['from']['id']??null)===$actor
            &&($m['from']['is_bot']??false)===false&&is_int($m['message_id']??null)&&$m['message_id']>0?$g:null;
    }
    public static function key(int $actor,string $group):string
    {if($actor<1||$group===''||strlen($group)>128)throw new InvalidArgumentException('Invalid album binding.');return'tg_album_g_'.$actor.'_'.substr(hash('sha256',$group),0,24);}
    public static function postKey(int $post):string{if($post<1)throw new InvalidArgumentException('Invalid album post.');return'tg_album_p_'.$post;}
    public static function item(array $m,array $content):array
    {
        if(!is_int($m['message_id']??null)||$m['message_id']<=0||count($content['media']??[])!==1)throw new InvalidArgumentException('Invalid album item.');$media=$content['media'][0];
        if(!in_array($media['type']??null,['photo','video','document','audio'],true)||!is_string($media['file_id']??null)||$media['file_id']===''||strlen($media['file_id'])>4096||preg_match('/[\x00-\x20\x7f]/',$media['file_id']))throw new InvalidArgumentException('Unsupported album item.');
        if(!is_string($content['text']??null)||!is_array($content['text_entities']??null)||!array_is_list($content['text_entities'])||!is_bool($content['media_spoiler']??null))throw new InvalidArgumentException('Invalid album content.');
        return['message_id'=>$m['message_id'],'media'=>$media,'text'=>$content['text'],'entities'=>$content['text_entities'],'spoiler'=>$content['media_spoiler']];
    }
    public static function canonical(mixed $v):string
    {if(is_array($v)){if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=json_decode(self::canonical($x),true,32,JSON_THROW_ON_ERROR);}return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function payload(array $post):string
    {return hash('sha256',self::canonical(['text'=>(string)($post['text']??''),'entities'=>json_decode((string)($post['text_entities_json']??'null'),true),'media'=>json_decode((string)($post['media_json']??'null'),true),'media_type'=>(string)($post['media_type']??'none'),'media_file_id'=>$post['media_file_id']??null,'repost_chat_id'=>$post['repost_chat_id']??null,'repost_message_id'=>$post['repost_message_id']??null]));}
    public static function validate(array $r):array
    {
        $keys=array_keys($r);sort($keys);$want=['version','actor_id','post_id','channel_id','group_id','epoch','items','revision','first_ms','last_ms','payload_hash','phase','sending_revision','preview_fingerprint','preview_count','shown_revision'];sort($want);
        if($keys!==$want||$r['version']!==1||!is_int($r['actor_id'])||$r['actor_id']<=0||!is_int($r['post_id'])||$r['post_id']<=0||!is_int($r['channel_id'])||$r['channel_id']<=0||!is_string($r['group_id'])||$r['group_id']===''||strlen($r['group_id'])>128||!is_string($r['epoch'])||preg_match('/\A[a-f0-9]{32}\z/D',$r['epoch'])!==1||!is_int($r['revision'])||$r['revision']<1||!is_int($r['shown_revision'])||$r['shown_revision']<0||$r['shown_revision']>$r['revision']||!is_int($r['first_ms'])||$r['first_ms']<1||!is_int($r['last_ms'])||$r['last_ms']<$r['first_ms']||!is_string($r['payload_hash'])||preg_match('/\A[a-f0-9]{64}\z/D',$r['payload_hash'])!==1||!in_array($r['phase'],['collecting','sending','shown','unknown','closed'],true)||!is_array($r['items'])||!array_is_list($r['items'])||count($r['items'])<1||count($r['items'])>10)throw new RuntimeException('Invalid album record.');
        $last=0;foreach($r['items']as$i){$ik=is_array($i)?array_keys($i):[];sort($ik);if($ik!==['entities','media','message_id','spoiler','text']||!is_int($i['message_id'])||$i['message_id']<=$last||!is_array($i['media'])||!is_string($i['text'])||!is_array($i['entities'])||!array_is_list($i['entities'])||!is_bool($i['spoiler']))throw new RuntimeException('Invalid album order.');$mk=array_keys($i['media']);sort($mk);$m=$i['media'];if($mk!==['file_id','file_unique_id','media_group_id','type']||!in_array($m['type'],['photo','video','document','audio'],true)||!is_string($m['file_id'])||$m['file_id']===''||strlen($m['file_id'])>4096||preg_match('/[\x00-\x20\x7f]/',$m['file_id'])||preg_match('~\A(?:https?:|file:|attach:|/|\\\\)~i',$m['file_id'])||($m['file_unique_id']!==null&&!is_string($m['file_unique_id']))||$m['media_group_id']!==$r['group_id'])throw new RuntimeException('Invalid album media.');$last=$i['message_id'];}
        if($r['sending_revision']!==null&&(!is_int($r['sending_revision'])||$r['sending_revision']<1||$r['sending_revision']>$r['revision']))throw new RuntimeException('Invalid album attempt.');
        if($r['preview_fingerprint']!==null&&(!is_string($r['preview_fingerprint'])||preg_match('/\A[a-f0-9]{64}\z/D',$r['preview_fingerprint'])!==1))throw new RuntimeException('Invalid preview binding.');
        if($r['preview_count']!==null&&(!is_int($r['preview_count'])||$r['preview_count']<1||$r['preview_count']>110))throw new RuntimeException('Invalid preview count.');return$r;
    }
    public static function fresh(int $actor,array $post,string $group,array $item,?int $now=null):array
    {$now??=self::now();return self::validate(['version'=>1,'actor_id'=>$actor,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'group_id'=>$group,'epoch'=>bin2hex(random_bytes(16)),'items'=>[$item],'revision'=>1,'first_ms'=>$now,'last_ms'=>$now,'payload_hash'=>self::payload($post),'phase'=>'collecting','sending_revision'=>null,'preview_fingerprint'=>null,'preview_count'=>null,'shown_revision'=>0]);}
    public static function add(array $r,array $item,int $now):array
    {
        $r=self::validate($r);foreach($r['items']as$old)if($old['message_id']===$item['message_id']){if(self::canonical($old)!==self::canonical($item))throw new RuntimeException('Replayed album item changed.');return$r;}
        if(count($r['items'])>=10||$now<$r['last_ms'])throw new RuntimeException('Album limit or clock changed.');$r['items'][]=$item;usort($r['items'],static fn(array$a,array$b):int=>$a['message_id']<=>$b['message_id']);$r['revision']++;$r['last_ms']=$now;
        if(in_array($r['phase'],['shown','collecting'],true))$r['phase']='collecting';return self::validate($r);
    }
    public static function assembled(array $r,array $post):array
    {
        $r=self::validate($r);$media=[];$text=(string)($post['text']??'');$entities=json_decode((string)($post['text_entities_json']??'null'),true);$caption=false;
        foreach($r['items']as$i){$media[]=$i['media'];if(!$caption&&$i['text']!==''){$text=$i['text'];$entities=$i['entities'];$caption=true;}}
        $secondary=false;foreach(array_slice($r['items'],1)as$item)if($item['text']!==''||$item['entities']!==[])$secondary=true;if($secondary){$text=$r['items'][0]['text'];$entities=$r['items'][0]['entities'];}
        return['media'=>$media,'text'=>$text,'entities'=>$entities];
    }
    public static function due(array $r,int $now):bool
    {$r=self::validate($r);return count($r['items'])>=2&&$r['phase']==='collecting'&&$r['revision']>$r['shown_revision']&&$now>=$r['last_ms']&&(count($r['items'])===10||$now-$r['last_ms']>=self::QUIET_MS);}
    public static function read(PDO $pdo,string $key,bool $lock=false):?array
    {
        if(preg_match('/\Atg_album_(?:g_[1-9][0-9]{0,18}_[a-f0-9]{24}|p_[1-9][0-9]{0,18})\z/D',$key)!==1)throw new RuntimeException('Invalid album key.');$s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([$key]);$raw=$s->fetchColumn();if($raw===false)return null;if(!is_string($raw)||strlen($raw)>60000)throw new RuntimeException('Invalid album storage.');$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new RuntimeException('Invalid album storage.');return$v;
    }
    public static function write(PDO $pdo,string $key,array $r):void
    {if(!$pdo->inTransaction())throw new LogicException('Album write requires existing locks.');$raw=self::canonical($r);if(preg_match('/\Atg_album_(?:g_[1-9][0-9]{0,18}_[a-f0-9]{24}|p_[1-9][0-9]{0,18})\z/D',$key)!==1||strlen($key)>64||strlen($raw)>60000)throw new RuntimeException('Album storage limit.');$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$raw]);}
    public static function forPost(PDO $pdo,array $post,bool $lock=false):?array
    {
        $i=self::read($pdo,self::postKey((int)$post['id']),$lock);if($i===null)return null;$keys=array_keys($i);sort($keys);if($keys!==['epoch','key','version']||$i['version']!==1||!is_string($i['key'])||!is_string($i['epoch']))throw new PostedTelegramAlbumPendingException();
        $r=self::validate(self::read($pdo,$i['key'],$lock)??[]);if($r['post_id']!==(int)$post['id']||$r['channel_id']!==(int)$post['channel_id']||$i['epoch']!==$r['epoch']||$i['key']!==self::key($r['actor_id'],$r['group_id']))throw new PostedTelegramAlbumPendingException();return$r+['_key'=>$i['key']];
    }
    public static function assertReadyLocked(PDO $pdo,array $post):void
    {if(!$pdo->inTransaction())throw new LogicException('Album publication guard requires existing locks.');$r=self::forPost($pdo,$post,true);if($r!==null&&(count($r['items'])<2||$r['phase']!=='shown'||$r['shown_revision']!==$r['revision']))throw new PostedTelegramAlbumPendingException();}
    public static function submission(PDO $pdo,array $post):?array
    {if((int)($post['id']??0)<=0||(int)($post['channel_id']??0)<=0)return null;$r=self::forPost($pdo,$post);return$r===null?null:['epoch'=>$r['epoch'],'revision'=>$r['revision'],'shown_revision'=>$r['shown_revision'],'phase'=>$r['phase']];}
}

trait PostedTelegramAlbumUi
{
    private bool $telegramAlbumIngressActive=false;
    private ?array $telegramAlbumExpectedInput=null;
    private array $telegramAlbumDeferred=[];
    private function handleTelegramAlbumIngress(array $message):bool
    {
        if($this->telegramAlbumIngressActive)return false;$actor=$message['from']['id']??null;if(!is_int($actor)||$actor<=0||PostedTelegramAlbumCollector::group($message,$actor)===null)return false;
        $name='tg_album_input_'.substr(hash('sha256',(string)$actor),0,32);$s=$this->pdo->prepare('SELECT GET_LOCK(?,10)');$s->execute([$name]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Album input busy.');
        try{$this->telegramAlbumIngressActive=true;$this->telegramAlbumExpectedInput=$this->user($actor);$known=PostedTelegramAlbumCollector::read($this->pdo,PostedTelegramAlbumCollector::key($actor,(string)$message['media_group_id']));
            if($known!==null){$known=PostedTelegramAlbumCollector::validate($known);$input=$this->decodeJson((string)($this->telegramAlbumExpectedInput['state_data']??''));if(($this->telegramAlbumExpectedInput['state']??null)!=='collecting_media_group'||(int)($input['post_id']??0)!==$known['post_id']||($input['media_group_id']??null)!==$known['group_id'])return true;}
            try{$this->handleMessage($message);}catch(PostedTelegramAlbumStaleInputException){$this->telegram->sendMessage($actor,'Ввод альбома устарел. Текущее действие и сохранённый пост не изменены.');}
        }finally{$this->telegramAlbumIngressActive=false;$this->telegramAlbumExpectedInput=null;$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}return true;
    }
    private function handleTelegramAlbumPendingCallback(array $callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||preg_match('/\A(draft|mopt|mprice|post_option|preview_media|mp|series|top|cp):([^:]+):([1-9][0-9]{0,18})(?::|\z)/D',$raw,$m)!==1)return false;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;if(!is_int($actor)||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor)return true;
        $post=$this->post((int)$m[3]);if(!is_array($post))return false;$r=PostedTelegramAlbumCollector::forPost($this->pdo,$post);if($r===null||(count($r['items'])>=2&&$r['phase']==='shown'&&$r['shown_revision']===$r['revision']))return false;
        if(!$this->canManagePost($actor,(int)$post['id'])){$this->answerCallback($callback,'Недоступно.');return true;}
        if($m[1]==='draft'&&$m[2]==='cancel')return false;
        if($m[1]==='draft'&&in_array($m[2],['main','preview'],true)&&$r['phase']==='unknown')return false;
        $this->answerCallback($callback,(new PostedTelegramAlbumPendingException())->getMessage());if($r['phase']==='collecting')$this->telegramAlbumDeferred[$r['_key']]=true;return true;
    }
    private function clearTelegramAlbumInputIfCurrent(int $actor):void
    {$u=$this->telegramAlbumExpectedInput;if(!is_array($u))return;$s=$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)');$s->execute([$actor,$u['state']??null,$u['state_data']??null]);}
    /** Called inside the existing create/replace transaction before it commits. */
    private function registerTelegramAlbumLocked(int $actor,array $post,array $message):bool
    {
        $v=PostedTelegramAlbumCollector::class;$group=$v::group($message,$actor);if(!$this->telegramAlbumIngressActive||$group===null||$this->isNativeRepost($post))return false;
        if(!$this->pdo->inTransaction()||(int)($post['author_id']??0)!==$actor||($post['status']??null)!=='draft')throw new RuntimeException('Album post binding changed.');
        $expected=$this->telegramAlbumExpectedInput;$s=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$s->execute([$actor]);$u=$s->fetch();
        if(!$u||!is_array($expected)||($u['state']??null)!==($expected['state']??null)||($u['state_data']??null)!==($expected['state_data']??null))throw new PostedTelegramAlbumStaleInputException('Album workflow changed.');
        $key=$v::key($actor,$group);if($v::read($this->pdo,$key,true)!==null)throw new RuntimeException('Album was already registered.');$r=$v::fresh($actor,$post,$group,$v::item($message,$this->contentFromMessage($message)));
        $v::write($this->pdo,$key,$r);$v::write($this->pdo,$v::postKey((int)$post['id']),['version'=>1,'key'=>$key,'epoch'=>$r['epoch']]);
        $state=['post_id'=>(int)$post['id'],'media_group_id'=>$group];$s=$this->pdo->prepare('UPDATE posted_users SET state=?,state_data=? WHERE telegram_id=? AND state<=>? AND state_data<=>CAST(? AS JSON)');$s->execute(['collecting_media_group',json_encode($state,JSON_THROW_ON_ERROR),$actor,$u['state'],$u['state_data']]);if($s->rowCount()!==1)throw new RuntimeException('Album workflow changed.');$this->telegramAlbumDeferred[$key]=true;return true;
    }
    private function hasTelegramAlbumCollection(int $actor,int $postId,array $message):bool
    {$group=PostedTelegramAlbumCollector::group($message,$actor);if($group===null)return false;$r=PostedTelegramAlbumCollector::read($this->pdo,PostedTelegramAlbumCollector::key($actor,$group));return$r!==null&&PostedTelegramAlbumCollector::validate($r)['post_id']===$postId;}
    private function appendTelegramAlbumItem(int $actor,int $postId,array $message):bool
    {
        $v=PostedTelegramAlbumCollector::class;$group=$v::group($message,$actor);if($group===null)return false;$key=$v::key($actor,$group);$content=$this->contentFromMessage($message);
        $ok=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post)use($actor,$postId,$message,$group,$key,$content,$v):bool{
            $expected=$this->telegramAlbumExpectedInput;$stateStmt=$this->pdo->prepare('SELECT state,state_data FROM posted_users WHERE telegram_id=? AND is_active=1 LIMIT 1 FOR UPDATE');$stateStmt->execute([$actor]);$currentInput=$stateStmt->fetch();
            if(!is_array($expected)||!is_array($currentInput)||($currentInput['state']??null)!=='collecting_media_group'||($currentInput['state']??null)!==($expected['state']??null)||($currentInput['state_data']??null)!==($expected['state_data']??null))throw new PostedTelegramAlbumStaleInputException('Album workflow changed before append.');
            $input=$this->decodeJson((string)($currentInput['state_data']??''));if((int)($input['post_id']??0)!==$postId||($input['media_group_id']??null)!==$group)throw new PostedTelegramAlbumStaleInputException('Album input binding changed.');
            $raw=$v::read($this->pdo,$key,true);if($raw===null)throw new RuntimeException('Album receipt missing.');$r=$v::validate($raw);$index=$v::read($this->pdo,$v::postKey($postId),true);
            if($r['actor_id']!==$actor||$r['post_id']!==$postId||$r['group_id']!==$group||(int)$post['author_id']!==$actor||$this->isNativeRepost($post)||($index['key']??null)!==$key||($index['epoch']??null)!==$r['epoch']||!hash_equals($r['payload_hash'],$v::payload($post)))throw new RuntimeException('Album content changed.');
            $next=$v::add($r,$v::item($message,$content),$v::now());if($next===$r)return true;$a=$v::assembled($next,$post);
            if(!$this->telegramMediaOptionsCompatible($post,$a['media'])||!$this->telegramInboundSpoilerLocked($post,$content))throw new RuntimeException('Album media options changed.');PostedTelegramSourceSpoiler::detachLocked($this->pdo,$post);
            $this->pdo->prepare('UPDATE posted_posts SET media_type=?,media_file_id=?,media_json=?,text=?,text_entities_json=?,repost_chat_id=NULL,repost_message_id=NULL,repost_input_chat_id=NULL,repost_input_message_id=NULL WHERE id=? AND status=\'draft\'')->execute([$a['media'][0]['type'],$a['media'][0]['file_id'],$this->encodeJson($a['media']),$a['text']!==''?$a['text']:null,$a['entities']!==[]&&$a['entities']!==null?$this->encodeJson($a['entities']):null,$postId]);
            $updated=$this->post($postId);if(!is_array($updated))throw new RuntimeException('Album post missing.');PostedTelegramCaptionVector::captureLocked($this->pdo,$updated,$next['items']);$next['payload_hash']=$v::payload($updated);$v::write($this->pdo,$key,$next);return true;
        });if($ok===true)$this->telegramAlbumDeferred[$key]=true;return$ok===true;
    }
    public function hasTelegramAlbumPreviewWork():bool{return$this->telegramAlbumDeferred!==[];}
    /** Run only after a successful webhook acknowledgement, or as a bounded cron fallback. */
    public function flushTelegramAlbumPreviews(bool $tail=false,int $limit=5):array
    {
        if($this->pdo->inTransaction())throw new LogicException('Album flush starts outside SQL.');$v=PostedTelegramAlbumCollector::class;$keys=array_keys($this->telegramAlbumDeferred);$this->telegramAlbumDeferred=[];
        if(!$tail){$s=$this->pdo->query("SELECT s.setting_key FROM posted_app_settings s
            WHERE s.setting_key REGEXP '^tg_album_g_[1-9][0-9]{0,18}_[a-f0-9]{24}$'
              AND (JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.phase'))='sending'
                OR (JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.phase'))='collecting'
                  AND JSON_LENGTH(JSON_EXTRACT(s.setting_value,'$.items'))>=2
                  AND EXISTS(SELECT 1 FROM posted_posts p
                    JOIN posted_users u ON u.telegram_id=p.author_id AND u.is_active=1
                    JOIN posted_channels c ON c.id=p.channel_id AND c.is_active=1 AND c.added_by IS NOT NULL AND c.added_by>0
                    WHERE p.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.post_id')) AS UNSIGNED)
                      AND p.channel_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.channel_id')) AS UNSIGNED)
                      AND p.author_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.actor_id')) AS UNSIGNED)
                      AND p.status='draft' AND u.state='collecting_media_group'
                      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(u.state_data,'$.post_id')) AS UNSIGNED)=p.id
                      AND JSON_UNQUOTE(JSON_EXTRACT(u.state_data,'$.media_group_id'))=JSON_UNQUOTE(JSON_EXTRACT(s.setting_value,'$.group_id')))))
            ORDER BY s.updated_at LIMIT ".max(1,min(10,$limit)));$keys=$s->fetchAll(PDO::FETCH_COLUMN);}
        $shown=0;$unknown=0;foreach(array_slice(array_values(array_unique($keys)),0,max(1,min(10,$limit)))as$key){$lock='tg_album_flush_'.substr(hash('sha256',$key),0,32);$s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)continue;
            try{$deadline=$v::now()+($tail?$v::MAX_TAIL_MS:0);do{$raw=$v::read($this->pdo,$key);if($raw===null)break;$r=$v::validate($raw);if($r['phase']==='sending'){$this->closeTelegramAlbumAttempt($key,$r,false);$unknown++;break;}if($v::due($r,$v::now())){if($this->flushOneTelegramAlbum($key,$r))$shown++;else$unknown++;break;}if(!$tail||$r['phase']!=='collecting'||$v::now()>=$deadline)break;usleep(100000);}while(true);
            }catch(Throwable$error){error_log('GitPosted album preview flush: '.$error::class);}finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}}
        return['shown'=>$shown,'unconfirmed'=>$unknown];
    }
    private function flushOneTelegramAlbum(string $key,array $hint):bool
    {
        $v=PostedTelegramAlbumCollector::class;$input='tg_album_input_'.substr(hash('sha256',(string)$hint['actor_id']),0,32);$s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute([$input]);if((int)$s->fetchColumn()!==1)return false;
        try{$actor=$hint['actor_id'];$postId=$hint['post_id'];$claimed=$this->withTelegramManagedPostLock($actor,$postId,['draft'],function(array$post)use($v,$key,$hint):?array{
            $r=$v::validate($v::read($this->pdo,$key,true)??[]);$index=$v::read($this->pdo,$v::postKey((int)$post['id']),true);if($r['epoch']!==$hint['epoch']||!$v::due($r,$v::now()))return null;
            $user=$this->user($r['actor_id']);$state=$this->decodeJson((string)($user['state_data']??''));
            if((int)$post['author_id']!==$r['actor_id']||$this->isNativeRepost($post)||($user['state']??null)!=='collecting_media_group'||(int)($state['post_id']??0)!==(int)$post['id']||($state['media_group_id']??null)!==$r['group_id']||($index['key']??null)!==$key||($index['epoch']??null)!==$r['epoch']||!hash_equals($r['payload_hash'],$v::payload($post))){$r['phase']='closed';$v::write($this->pdo,$key,$r);return null;}
            $plan=$this->telegramPreviewPlan($post);$r['phase']='sending';$r['sending_revision']=$r['revision'];$r['preview_fingerprint']=$plan['fingerprint'];$r['preview_count']=PostedTelegramPreviewProof::expectedCount($plan,($plan['mode']??null)!=='group');$v::write($this->pdo,$key,$r);return$r;
        });if(!is_array($claimed))return false;$post=$this->post($postId);$target=['album_key'=>$key,'album_epoch'=>$claimed['epoch'],'album_revision'=>$claimed['revision']];$success=$this->sendDraftPreview($actor,$post,$this->draftMainKeyboard($post),'',$target);$this->closeTelegramAlbumAttempt($key,$claimed,$success);return$success;
        }finally{$this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$input]);}
    }
    private function assertTelegramAlbumPreviewClaim(array $post,?array $target):void
    {
        if(!isset($target['album_key'])){$r=PostedTelegramAlbumCollector::forPost($this->pdo,$post,true);if($r!==null&&(count($r['items'])<2||($r['shown_revision']!==$r['revision']&&count($r['items'])<10&&PostedTelegramAlbumCollector::now()-$r['last_ms']<PostedTelegramAlbumCollector::QUIET_MS)))throw new PostedTelegramAlbumPendingException();return;}$v=PostedTelegramAlbumCollector::class;$r=$v::validate($v::read($this->pdo,(string)$target['album_key'],true)??[]);
        if(count($r['items'])<2||$r['post_id']!==(int)$post['id']||$r['phase']!=='sending'||$r['epoch']!==($target['album_epoch']??null)||$r['sending_revision']!==($target['album_revision']??null)||!hash_equals($r['payload_hash'],$v::payload($post))||!hash_equals((string)$r['preview_fingerprint'],(string)$this->telegramPreviewPlan($post)['fingerprint']))throw new RuntimeException('Album preview changed before dispatch.');
    }
    private function confirmTelegramAlbumPreviewLocked(array $post,array $ids,int $controlId,string $fingerprint):void
    {
        $v=PostedTelegramAlbumCollector::class;$r=$v::forPost($this->pdo,$post,true);if($r===null)return;$key=$r['_key'];unset($r['_key']);
        if(count($r['items'])<2||$controlId<=0||($v::now()-$r['last_ms']<$v::QUIET_MS&&count($r['items'])<10))throw new PostedTelegramAlbumPendingException();
        $plan=$this->telegramPreviewPlan($post);if(!hash_equals($fingerprint,(string)$plan['fingerprint']))throw new RuntimeException('Album preview binding changed.');PostedTelegramPreviewProof::requireKnownIds($ids,PostedTelegramPreviewProof::expectedCount($plan,($plan['mode']??null)!=='group'));
        if(array_intersect(array_merge($ids,[$controlId]),array_column($r['items'],'message_id'))!==[])throw new RuntimeException('A preview response reused incoming album message IDs.');
        $r['phase']='shown';$r['shown_revision']=$r['revision'];$r['payload_hash']=$v::payload($post);$r['preview_fingerprint']=$fingerprint;$r['preview_count']=count($ids);$v::write($this->pdo,$key,$r);
    }
    private function closeTelegramAlbumAttempt(string $key,array $hint,bool $success):void
    {
        $this->pdo->beginTransaction();try{$v=PostedTelegramAlbumCollector::class;$r=$v::validate($v::read($this->pdo,$key,true)??[]);if($r['epoch']===$hint['epoch']&&$r['phase']==='sending'&&$r['sending_revision']===$hint['sending_revision']){$r['phase']=$success?'shown':'unknown';if($success)$r['shown_revision']=$r['sending_revision'];$v::write($this->pdo,$key,$r);}$this->pdo->commit();}catch(Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }
}
