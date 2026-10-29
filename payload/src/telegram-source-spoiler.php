<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-template-media.php';

/** Inactive source provenance. It never changes a native forward or overrides an ordinary user's OFF. */
final class PostedTelegramSourceSpoiler
{
    public static function key(int $id,string $kind='post'): string
    {self::id($id);if(!in_array($kind,['post','template'],true))throw new InvalidArgumentException('Invalid source spoiler kind.');return 'telegram_source_spoiler_'.($kind==='post'?'p_':'t_').$id;}

    public static function mediaFingerprint(array $object): string
    {return self::digest(self::media($object));}

    private static function media(array $object): array
    {
        $raw=$object['media_json']??null;
        if(is_string($raw)&&$raw!==''){$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
        if($raw!==null&&!is_array($raw))throw new InvalidArgumentException('Invalid spoiler media binding.');
        $items=[];
        foreach((array)$raw as$item){
            if(!is_array($item)||!in_array($item['type']??null,['photo','video','animation','document','audio','voice'],true)||!is_string($item['file_id']??null)||trim($item['file_id'])==='')throw new InvalidArgumentException('Invalid source media fingerprint.');
            $items[]=['type'=>$item['type'],'file_id'=>trim($item['file_id'])];
        }
        if($items===[]&&($object['media_type']??'none')!=='none'&&!empty($object['media_file_id']))$items[]=['type'=>(string)$object['media_type'],'file_id'=>(string)$object['media_file_id']];
        if(count($items)>10)throw new InvalidArgumentException('Invalid source media count.');return $items;
    }

    public static function validatePostRecord(array $record,array $post): array
    {return self::validate($record,$post,'post');}

    private static function validate(array $record,array $object,string $kind): array
    {
        $keys=array_keys($record);sort($keys);$expected=['version','kind','object_id','channel_id','channel_chat_id','source_chat_id','source_message_id','media_fingerprint','mode','state','media_spoiler'];sort($expected);
        $objectId=self::id($object['id']??null);$channelId=(int)($object['channel_id']??0);
        if($keys!==$expected||($record['version']??null)!==1||($record['kind']??null)!==$kind||($record['object_id']??null)!==$objectId
            ||($record['channel_id']??null)!==$channelId
            ||!in_array($record['mode']??null,['native','ordinary'],true)||!in_array($record['state']??null,['bound','unknown','detached'],true)
            ||!is_string($record['media_fingerprint']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$record['media_fingerprint'])!==1)throw new InvalidArgumentException('Source spoiler provenance binding changed.');
        if($kind==='post'||$channelId>0){if($channelId<=0||($record['channel_chat_id']??null)!==self::chat($object['chat_id']??null))throw new InvalidArgumentException('Source spoiler channel changed.');}
        elseif($channelId!==0||$record['channel_chat_id']!==null)throw new InvalidArgumentException('Invalid unscoped template spoiler binding.');
        self::chat($record['source_chat_id']??null);$message=$record['source_message_id']??null;
        if(!is_int($message)||$message<1||$message>2147483647)throw new InvalidArgumentException('Invalid source spoiler message identity.');
        if(($record['state']==='bound'&&!is_bool($record['media_spoiler']))||($record['state']==='unknown'&&$record['media_spoiler']!==null)
            ||($record['state']==='detached'&&$record['media_spoiler']!==null&&!is_bool($record['media_spoiler'])))throw new InvalidArgumentException('Invalid known/unknown source spoiler evidence.');
        if($record['state']!=='detached'&&!hash_equals($record['media_fingerprint'],self::mediaFingerprint($object)))throw new InvalidArgumentException('Source spoiler refers to different media.');
        if($kind==='post'){
            $native=self::native($object);
            if(($record['mode']==='native')!==$native||($record['state']==='detached'&&$native))throw new InvalidArgumentException('Source spoiler mode changed.');
            if($native&&($record['source_chat_id']!==self::chat($object['repost_chat_id']??null)||$record['source_message_id']!==(int)($object['repost_message_id']??0)))throw new InvalidArgumentException('Native source spoiler identity changed.');
        }elseif($record['mode']!=='ordinary')throw new InvalidArgumentException('Template source spoiler is not an ordinary copy.');
        return $record;
    }

    private static function row(PDO $pdo,int $id,string $kind): array
    {
        $sql=$kind==='post'?'SELECT p.*,c.chat_id FROM posted_posts p JOIN posted_channels c ON c.id=p.channel_id WHERE p.id=:id LIMIT 1':'SELECT t.*,c.chat_id FROM posted_templates t LEFT JOIN posted_channels c ON c.id=t.channel_id WHERE t.id=:id LIMIT 1';
        $query=$pdo->prepare($sql);$query->execute(['id'=>$id]);$row=$query->fetch();if(!is_array($row))throw new InvalidArgumentException('Source spoiler object disappeared.');return $kind==='template'?PostedTelegramTemplateMedia::hydrate($pdo,$row):$row;
    }

    private static function read(PDO $pdo,array $object,string $kind): ?array
    {
        $value=self::raw($pdo,self::id($object['id']??null),$kind);return $value===null?null:self::validate($value,$object,$kind);
    }

    private static function raw(PDO $pdo,int $id,string $kind): ?array
    {
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1');$query->execute(['key'=>self::key($id,$kind)]);$raw=$query->fetchColumn();
        if($raw===false)return null;
        try{$value=json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$error){throw new InvalidArgumentException('Invalid source spoiler registry.',0,$error);}
        if(!is_array($value))throw new InvalidArgumentException('Invalid source spoiler registry.');return $value;
    }

    /** Only PDO data can mint a frozen record; caller holds the source post/channel lock. */
    public static function freeze(PDO $pdo,array $post): ?array
    {
        $value=self::raw($pdo,self::id($post['id']??null),'post');if($value===null&&!self::native($post))return null;
        $actual=self::row($pdo,self::id($post['id']??null),'post');
        if((int)$actual['channel_id']!==(int)($post['channel_id']??0)||self::mediaFingerprint($actual)!==self::mediaFingerprint($post)
            ||self::native($actual)!==self::native($post)
            ||(self::native($actual)&&((string)$actual['repost_chat_id']!==(string)$post['repost_chat_id']||(int)$actual['repost_message_id']!==(int)$post['repost_message_id']))
            ||(isset($post['chat_id'])&&(string)$actual['chat_id']!==(string)$post['chat_id']))throw new InvalidArgumentException('Source spoiler snapshot changed.');
        if($value===null){
            $types=array_column(self::media($actual),'type');if(!self::native($actual)||$types===[]||array_diff($types,['photo','video','animation'])!==[])return null;
            return self::mint($actual,'post',self::chat($actual['repost_chat_id']),(int)$actual['repost_message_id'],'unknown',null);
        }
        return self::validate($value,$actual,'post');
    }

    public static function registerInboundNativeLocked(PDO $pdo,array $post,bool $spoiler): void
    {
        self::transaction($pdo);$actual=self::row($pdo,self::id($post['id']??null),'post');
        if(!self::native($actual)||self::mediaFingerprint($actual)!==self::mediaFingerprint($post))throw new InvalidArgumentException('Inbound native source changed.');
        $types=array_column(self::media($actual),'type');if($types===[]||array_diff($types,['photo','video','animation'])!==[])throw new InvalidArgumentException('Inbound spoiler type is unsupported.');
        $record=self::mint($actual,'post',self::chat($actual['repost_chat_id']),(int)$actual['repost_message_id'],'bound',$spoiler);
        self::insert($pdo,$record);
    }

    /** Called only while inserting a validated loader result, never while reopening import_json. */
    public static function registerVerifiedLinkNativeLocked(PDO $pdo,array $post): void
    {self::registerInboundNativeLocked($pdo,$post,false);}

    /** A frozen DTO is accepted only when exactly present in an authoritative persisted fanout receipt. */
    private static function sourceForClone(PDO $pdo,array $source,array $target): ?array
    {
        if(!array_key_exists('_telegram_source_spoiler',$source)){
            if(array_key_exists('_telegram_explicit_source_copy',$source)){
                [$receipt]=self::frozenReceipt($pdo,$source,$target);
                if(isset($receipt['snapshot']['_telegram_source_spoiler']))throw new InvalidArgumentException('Frozen source spoiler metadata was dropped.');
                return null;
            }
            $value=self::raw($pdo,self::id($source['id']??null),'post');if($value===null&&!self::native($source))return null;
            $actual=self::row($pdo,self::id($source['id']??null),'post');$record=self::freeze($pdo,$source);
            if((int)$actual['channel_id']!==(int)($source['channel_id']??0)||self::mediaFingerprint($actual)!==self::mediaFingerprint($source))throw new InvalidArgumentException('Spoiler clone source changed.');
            return $record;
        }
        $value=$source['_telegram_source_spoiler'];if(!is_array($value))throw new InvalidArgumentException('Invalid frozen source spoiler DTO.');
        [$receipt,$snapshot]=self::frozenReceipt($pdo,$source,$target);
        if(!is_array($receipt['snapshot']['_telegram_source_spoiler']??null)||self::digest($receipt['snapshot']['_telegram_source_spoiler'])!==self::digest($value))throw new InvalidArgumentException('Frozen spoiler source record changed.');
        return self::validatePostRecord($value,$snapshot);
    }

    private static function frozenReceipt(PDO $pdo,array $source,array $target): array
    {
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1');$query->execute(['key'=>'telegram_multipost_'.self::id($source['id']??null)]);$raw=$query->fetchColumn();
        if($raw===false)throw new InvalidArgumentException('Frozen spoiler has no authoritative source receipt.');
        $receipt=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($receipt)||!class_exists('PostedTelegramMultipost',false))throw new InvalidArgumentException('Frozen spoiler source receipt unavailable.');
        PostedTelegramMultipost::validate($receipt);
        if(!in_array($receipt['phase'],['ready','materialized'],true)||($receipt['binding']['post_id']??null)!==self::id($source['id']??null)
            ||($receipt['binding']['channel_id']??null)!==(int)($source['channel_id']??0))throw new InvalidArgumentException('Frozen spoiler receipt binding changed.');
        $snapshot=array_replace($receipt['snapshot'],['id'=>$receipt['binding']['post_id'],'channel_id'=>$receipt['binding']['channel_id'],'chat_id'=>$receipt['binding']['chat_id']]);
        if(self::mediaFingerprint($source)!==self::mediaFingerprint($snapshot)||(int)($target['copied_from_id']??0)!==$receipt['binding']['post_id'])throw new InvalidArgumentException('Frozen spoiler copied payload changed.');
        $explicit=$source['_telegram_explicit_source_copy']??null;if(!is_bool($explicit))throw new InvalidArgumentException('Explicit source copy intent is missing.');
        $mode=$explicit?'copy':'forward';$matched=false;
        foreach($receipt['targets']as$entry)if($entry['channel_id']===(int)$target['channel_id']&&$entry['chat_id']===(string)$target['chat_id']&&$entry['mode']===$mode)$matched=true;
        if(!$matched||($explicit&&self::native($target))||(!$explicit&&!self::native($target)))throw new InvalidArgumentException('Source spoiler target mode changed.');
        if(!$explicit&&((string)$target['repost_chat_id']!==($receipt['proof']['source_chat_id']??null)||(int)$target['repost_message_id']!==($receipt['proof']['source_message_ids'][0]??null)))throw new InvalidArgumentException('Forwarded spoiler source is not the fully published root.');
        return [$receipt,$snapshot];
    }

    /** Returns true ONLY for an intentional bound native-to-ordinary copy. Options clone performs activation. */
    public static function clonePostLocked(PDO $pdo,array $source,array $target): bool
    {
        self::transaction($pdo);
        if(!self::native($source)&&!array_key_exists('_telegram_source_spoiler',$source)&&!array_key_exists('_telegram_explicit_source_copy',$source)
            &&self::raw($pdo,self::id($source['id']??null),'post')===null&&!PostedTelegramPostOptions::load($pdo,$source)['media_spoiler'])return false;
        self::transaction($pdo);$actualTarget=self::row($pdo,self::id($target['id']??null),'post');$record=self::sourceForClone($pdo,$source,$actualTarget);
        if($record===null){
            if(self::native($actualTarget)&&array_key_exists('_telegram_explicit_source_copy',$source)){
                $types=array_column(self::media($actualTarget),'type');if($types===[]||array_diff($types,['photo','video','animation'])!==[])return false;
                [, $snapshot]=self::frozenReceipt($pdo,$source,$actualTarget);
                if(self::native($snapshot)||!is_array($snapshot['_telegram_post_options']??null))throw new InvalidArgumentException('Only a fully published ordinary source can prove its forwarded spoiler state.');
                $known=PostedTelegramPostOptions::normalize($snapshot['_telegram_post_options'],$snapshot)['media_spoiler'];
                self::compatible($snapshot,$actualTarget,false);
                self::insert($pdo,self::mint($actualTarget,'post',self::chat($actualTarget['repost_chat_id']),(int)$actualTarget['repost_message_id'],'bound',$known));
            }
            return false;
        }
        self::compatible($source,$actualTarget,false);
        $activate=$record['mode']==='native'&&!self::native($actualTarget)&&($record['state']==='unknown'||($record['state']==='bound'&&$record['media_spoiler']===true));
        if(self::native($actualTarget)&&$record['mode']==='ordinary'){
            $types=array_column(self::media($actualTarget),'type');if($types===[]||array_diff($types,['photo','video','animation'])!==[])return false;
            [, $snapshot]=self::frozenReceipt($pdo,$source,$actualTarget);if(self::native($snapshot)||!is_array($snapshot['_telegram_post_options']??null))throw new InvalidArgumentException('Only an ordinary source can mint a known forwarded spoiler.');$known=PostedTelegramPostOptions::normalize($snapshot['_telegram_post_options'],$snapshot)['media_spoiler'];
            self::insert($pdo,self::mint($actualTarget,'post',self::chat($actualTarget['repost_chat_id']),(int)$actualTarget['repost_message_id'],'bound',$known));return false;
        }
        $chat=self::native($actualTarget)?self::chat($actualTarget['repost_chat_id']):$record['source_chat_id'];
        $message=self::native($actualTarget)?(int)$actualTarget['repost_message_id']:$record['source_message_id'];
        self::insert($pdo,self::mint($actualTarget,'post',$chat,$message,$record['state'],$record['media_spoiler']));return $activate;
    }

    public static function clonePostToTemplateLocked(PDO $pdo,array $source,array $template): bool
    {
        self::transaction($pdo);
        if(!self::native($source)&&self::raw($pdo,self::id($source['id']??null),'post')===null)return false;
        self::transaction($pdo);$actualSource=self::row($pdo,self::id($source['id']??null),'post');$record=self::freeze($pdo,$actualSource);if($record===null)return false;
        $actualTarget=self::row($pdo,self::id($template['id']??null),'template');self::compatible($actualSource,$actualTarget,true);
        self::insert($pdo,self::mint($actualTarget,'template',$record['source_chat_id'],$record['source_message_id'],$record['state'],$record['media_spoiler']));return $record['mode']==='native'&&($record['state']==='unknown'||($record['state']==='bound'&&$record['media_spoiler']===true));
    }

    public static function cloneTemplateToPostLocked(PDO $pdo,array $template,array $post): void
    {
        self::transaction($pdo);
        if(self::raw($pdo,self::id($template['id']??null),'template')===null)return;
        self::transaction($pdo);$actualSource=self::row($pdo,self::id($template['id']??null),'template');$record=self::read($pdo,$actualSource,'template');if($record===null)return;
        $target=self::row($pdo,self::id($post['id']??null),'post');self::compatible($actualSource,$target,false);
        if(self::native($target))throw new InvalidArgumentException('Template spoiler provenance cannot mint a native source.');
        self::insert($pdo,self::mint($target,'post',$record['source_chat_id'],$record['source_message_id'],$record['state'],$record['media_spoiler']));
    }

    public static function transitionToCopyLocked(PDO $pdo,array $post): bool
    {
        self::transaction($pdo);
        if(!self::native($post))return false;
        self::transaction($pdo);$actual=self::row($pdo,self::id($post['id']??null),'post');$existing=self::raw($pdo,self::id($post['id']??null),'post');$record=self::freeze($pdo,$actual);if($record===null)return false;
        if($record['state']==='detached')throw new InvalidArgumentException('Source spoiler is detached. Import the source again.');
        $record['mode']='ordinary';if($existing===null)self::insert($pdo,$record);else self::update($pdo,$record);
        return $record['state']==='unknown'||$record['media_spoiler']===true;
    }

    public static function detachLocked(PDO $pdo,array $object,string $kind='post'): void
    {
        self::transaction($pdo);
        if(self::raw($pdo,self::id($object['id']??null),$kind)===null)return;
        self::transaction($pdo);$actual=self::row($pdo,self::id($object['id']??null),$kind);$record=self::read($pdo,$actual,$kind);if($record===null)return;
        if($record['mode']==='native')throw new InvalidArgumentException('Native source media must be explicitly copied before replacement.');
        $record['state']='detached';self::update($pdo,$record);
    }

    public static function transitionToNativeLocked(PDO $pdo,array $post,string $sourceChat,int $sourceMessage): void
    {
        self::transaction($pdo);if(self::native($post))return;
        $actual=self::row($pdo,self::id($post['id']??null),'post');$record=self::read($pdo,$actual,'post');if($record===null)return;
        if($record['state']==='detached'||$record['source_chat_id']!==self::chat($sourceChat)||$record['source_message_id']!==$sourceMessage)throw new InvalidArgumentException('Native source no longer matches the copied media. Import the source again.');
        $record['mode']='native';self::update($pdo,$record);
    }

    public static function scopeSnapshot(PDO $pdo,array $posts,array $templates): array
    {
        $result=['posts'=>[],'templates'=>[]];
        foreach($posts as$post){$record=self::freeze($pdo,$post);if($record!==null)$result['posts'][(int)$post['id']]=$record;}
        foreach($templates as$template){$value=self::raw($pdo,self::id($template['id']??null),'template');if($value===null)continue;$actual=self::row($pdo,self::id($template['id']??null),'template');$result['templates'][(int)$template['id']]=self::validate($value,$actual,'template');}
        ksort($result['posts'],SORT_NUMERIC);ksort($result['templates'],SORT_NUMERIC);return $result;
    }

    private static function compatible(array $source,array $target,bool $template): void
    {
        $left=self::media($source);$right=self::media($target);if($template&&empty($target['_telegram_template_vector']))$left=array_slice($left,0,1);
        if($left!==$right)throw new InvalidArgumentException('Source spoiler clone refers to different media.');
    }

    private static function mint(array $object,string $kind,string $sourceChat,int $sourceMessage,string $state,?bool $spoiler): array
    {
        if($sourceMessage<1||$sourceMessage>2147483647)throw new InvalidArgumentException('Invalid native spoiler source message.');
        $channel=(int)($object['channel_id']??0);$native=$kind==='post'&&self::native($object);
        return ['version'=>1,'kind'=>$kind,'object_id'=>self::id($object['id']??null),'channel_id'=>$channel,
            'channel_chat_id'=>$channel>0?self::chat($object['chat_id']??null):null,'source_chat_id'=>self::chat($sourceChat),'source_message_id'=>$sourceMessage,
            'media_fingerprint'=>self::mediaFingerprint($object),'mode'=>$native?'native':'ordinary','state'=>$state,'media_spoiler'=>$spoiler];
    }

    private static function insert(PDO $pdo,array $record): void
    {$pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)')->execute(['key'=>self::key($record['object_id'],$record['kind']),'value'=>json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private static function update(PDO $pdo,array $record): void
    {$pdo->prepare('UPDATE posted_app_settings SET setting_value=:value WHERE setting_key=:key')->execute(['key'=>self::key($record['object_id'],$record['kind']),'value'=>json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
    private static function transaction(PDO $pdo): void{if(!$pdo->inTransaction())throw new LogicException('Source spoiler mutations require the locked source transaction.');}
    private static function native(array $post): bool{return !empty($post['repost_chat_id'])&&!empty($post['repost_message_id']);}
    private static function id(mixed $id): int{if(is_string($id)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$id)===1&&(string)(int)$id===$id)$id=(int)$id;if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid source spoiler identity.');return $id;}
    private static function chat(mixed $chat): string{if(is_int($chat))$chat=(string)$chat;if(!is_string($chat)||preg_match('/\A-[1-9][0-9]{0,18}\z/D',$chat)!==1||(strlen($chat)===20&&strcmp(substr($chat,1),'9223372036854775808')>0))throw new InvalidArgumentException('Invalid source spoiler chat identity.');return $chat;}
    private static function digest(array $value): string
    {$canonical=static function(mixed$item)use(&$canonical):mixed{if(!is_array($item))return$item;if(!array_is_list($item))ksort($item);foreach($item as&$child)$child=$canonical($child);return$item;};return hash('sha256',json_encode($canonical($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}
