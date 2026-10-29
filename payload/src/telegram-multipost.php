<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/** Pure planning/validation. This module never sends to Telegram. */
final class PostedTelegramMultipost
{
    public const MAX_TARGETS = 20;
    public const MAX_PARTS = 100;
    public const MAX_DOCUMENT_BYTES = 1048576;
    public const FORWARD_KEY = 'gitposted_multipost_forward';

    private const PAYLOAD_FIELDS = [
        'text','text_entities_json','autosignature_text','autosignature_entities_json',
        'media_type','media_file_id','media_json','buttons_json','delete_after_minutes',
        'delete_report_enabled','repeat_interval_minutes','repeat_until','silent',
        'pin_after_publish','comments_enabled','is_ad','replacement_url','link_preview_enabled',
        'link_preview_large','link_preview_above','reply_chat_id','reply_message_id',
        'repost_chat_id','repost_message_id','repost_input_chat_id','repost_input_message_id','import_json',
    ];
    private const NUMERIC_FIELDS = [
        'delete_after_minutes','delete_report_enabled','repeat_interval_minutes','silent',
        'pin_after_publish','comments_enabled','is_ad','link_preview_enabled','link_preview_large',
        'link_preview_above','reply_message_id','repost_message_id','repost_input_message_id',
    ];

    public static function key(int $rootPostId): string
    {
        self::positive($rootPostId);
        return 'telegram_multipost_' . $rootPostId;
    }

    public static function selection(array $post, array $channel, int $actorId, array $targets, string $nonce, bool $allowDualMode = false): array
    {
        self::positive($actorId); $postId=self::databaseId($post['id']??null);$channelId=self::databaseId($channel['id']??null);
        if (($post['status'] ?? '') !== 'draft' || (int)($post['author_id'] ?? 0) !== $actorId
            || self::databaseId($post['channel_id']??null) !== $channelId || !preg_match('/^[a-f0-9]{12}$/D', $nonce)) {
            throw new InvalidArgumentException('Черновик или выбор каналов устарел.');
        }
        $binding=['post_id'=>$postId,'channel_id'=>$channelId,'chat_id'=>self::chat($channel['chat_id'] ?? null),'actor_id'=>$actorId];
        $targets=self::targets($targets, $channelId, $allowDualMode);
        $document=['version'=>1,'binding'=>$binding,'nonce'=>$nonce,'revision'=>0,'allow_dual'=>$allowDualMode,
            'targets'=>$targets,'phase'=>'selection','snapshot'=>null,'expected_parts'=>null,'purpose'=>null,'proof'=>null,'children'=>[]];
        $document['selection_digest']=self::selectionDigest($document);
        return $document;
    }

    public static function replaceSelection(array $document, array $targets, string $expectedDigest): array
    {
        self::validate($document);
        if ($document['phase'] !== 'selection' || !hash_equals($document['selection_digest'], $expectedDigest)
            || $document['revision'] >= 9999) throw new InvalidArgumentException('Выбор каналов уже изменился. Откройте меню заново.');
        $document['targets']=self::targets($targets,$document['binding']['channel_id'],$document['allow_dual']);
        $document['revision']++;
        $document['selection_digest']=self::selectionDigest($document);
        return $document;
    }

    public static function freeze(array $document, array $post, array $channel, int $primaryParts, int $continuationParts, string $purpose,?PostedTelegramMultipostUpstreamProof $upstream=null): array
    {
        self::validate($document);self::assertBinding($document,$post,$channel);
        if (!in_array($purpose,['now','schedule'],true) || $primaryParts<1 || $continuationParts<0
            || $primaryParts+$continuationParts>self::MAX_PARTS || ($post['status']??'')!=='publishing') {
            throw new InvalidArgumentException('Не удалось подтвердить состав публикации.');
        }
        $snapshot=[];
        foreach(self::PAYLOAD_FIELDS as $field) {
            $value=$post[$field]??null;
            if($value!==null&&!is_scalar($value))throw new InvalidArgumentException('Некорректное содержимое исходного поста.');
            if($value!==null) $value=in_array($field,self::NUMERIC_FIELDS,true)?(int)$value:(string)$value;
            $snapshot[$field]=$value;
        }
        if(array_key_exists('_telegram_post_options',$post)) {
            if(!class_exists('PostedTelegramPostOptions',false)||!is_array($post['_telegram_post_options']))throw new InvalidArgumentException('Не подтверждены дополнительные настройки публикации.');
            $snapshot['_telegram_post_options']=PostedTelegramPostOptions::normalize($post['_telegram_post_options'],$post);
            if($snapshot['_telegram_post_options']['protect_content']) {
                foreach($document['targets'] as $target)if($target['mode']==='forward')throw new InvalidArgumentException('Защищённый пост нельзя переслать в другие каналы. Отключите защиту или выберите копирование.');
            }
            if(($snapshot['_telegram_post_options']['paid_media_stars']??null)!==null){
                foreach($document['targets'] as $target)if($target['mode']==='forward')throw new InvalidArgumentException('Платные медиа нельзя отправить мультипубликацией в режиме репоста. Выберите копирование.');
            }
        }
        if(array_key_exists('_telegram_native_content',$post)&&$post['_telegram_native_content']!==null){$native=PostedTelegramNativeContent::normalize($post['_telegram_native_content']);if(($snapshot['media_type']??null)!==$native['kind']||($snapshot['media_file_id']??null)!==($native['payload']['file_id']??null))throw new InvalidArgumentException('native_multipost_snapshot_binding');$snapshot['_telegram_native_content']=$native;$snapshot['_telegram_native_legacy_recorded']=false;}
        if(array_key_exists('_telegram_top_options',$post))$snapshot['_telegram_top_options']=PostedTelegramTopHold::normalize($post['_telegram_top_options'],$post);
        if(array_key_exists('_telegram_source_spoiler',$post)&&$post['_telegram_source_spoiler']!==null){
            if(!class_exists('PostedTelegramSourceSpoiler',false)||!is_array($post['_telegram_source_spoiler']))throw new InvalidArgumentException('Source spoiler provenance is unavailable.');
            $snapshot['_telegram_source_spoiler']=PostedTelegramSourceSpoiler::validatePostRecord($post['_telegram_source_spoiler'],array_replace($post,['chat_id'=>$document['binding']['chat_id']]));
        }
        $import=json_decode((string)($snapshot['import_json']??''),true);
        if(is_array($import)&&(array_key_exists(self::FORWARD_KEY,$import)||array_key_exists('gitposted_multipost_forward_fork',$import))) {
            if($upstream===null)throw new InvalidArgumentException('Для исходного batch-репоста требуется подтверждённый журнал всех частей.');
            $previous=$document['phase']==='frozen'?($document['snapshot']['_telegram_multipost_upstream']??null):null;
            $snapshot['_telegram_multipost_upstream']=$upstream->snapshot($post,$previous);
            if($continuationParts!==0||count($snapshot['_telegram_multipost_upstream']['source_message_ids'])!==$primaryParts)throw new InvalidArgumentException('Состав исходного batch-репоста изменился.');
            // The certified snapshot owns this transport source. Mutable receipt
            // actor/digest metadata must not change a frozen publication body.
            unset($import[self::FORWARD_KEY],$import['gitposted_multipost_forward_fork']);
            $snapshot['import_json']=$import===[]?null:self::encode($import);
        }
        $snapshotHash=self::digest($snapshot);
        if($document['phase']==='frozen') {
            if(!hash_equals((string)$document['snapshot_hash'],$snapshotHash)
                || $document['expected_parts']!==$primaryParts+$continuationParts || $document['purpose']!==$purpose) {
                throw new InvalidArgumentException('Пост изменился после запуска публикации.');
            }
            return $document;
        }
        if($document['phase']!=='selection') throw new InvalidArgumentException('Мультипубликация уже запущена.');
        $document['phase']='frozen';$document['snapshot']=$snapshot;$document['snapshot_hash']=$snapshotHash;
        $document['expected_parts']=$primaryParts+$continuationParts;$document['purpose']=$purpose;
        self::encode($document);
        return $document;
    }

    public static function knownFull(array $document, array $slots, bool $continuationComplete, bool $deliveryCertain): array
    {
        self::validate($document);
        if(!in_array($document['phase'],['frozen','ready','materialized'],true) || !$continuationComplete || !$deliveryCertain) {
            throw new InvalidArgumentException('Нет однозначного подтверждения всех частей исходного поста.');
        }
        $ids=self::messageIds($slots);
        if(count($ids)!==$document['expected_parts']) throw new InvalidArgumentException('Исходный пост опубликован не полностью.');
        $origins=self::expectedOrigins($document,$ids);
        $proof=['version'=>1,'post_id'=>$document['binding']['post_id'],'source_chat_id'=>$document['binding']['chat_id'],
            'snapshot_hash'=>$document['snapshot_hash'],'expected_parts'=>$document['expected_parts'],
            'source_message_ids'=>$ids,'expected_origins'=>$origins,'continuation_complete'=>true,'delivery_certain'=>true];
        if($document['proof']!==null && self::digest($document['proof'])!==self::digest($proof)) throw new InvalidArgumentException('Подтверждённые ID исходного поста уже изменились.');
        $document['proof']=$proof;
        if($document['phase']!=='materialized') $document['phase']='ready';
        return $document;
    }

    public static function childSpec(array $document, array $target, string $targetChat, ?int $categoryId): array
    {
        self::validate($document);
        if(!in_array($document['phase'],['ready','materialized'],true)||$document['proof']===null) {
            throw new InvalidArgumentException('Дождитесь полного подтверждения исходного поста.');
        }
        $matched=false;
        foreach($document['targets'] as $allowed) if(self::digest($allowed)===self::digest($target)) $matched=true;
        if(!$matched || self::chat($targetChat)!==$target['chat_id']) throw new InvalidArgumentException('Канал назначения изменился.');
        $snapshot=$document['snapshot'];
        $snapshot['id']=$document['binding']['post_id'];$snapshot['author_id']=$document['binding']['actor_id'];
        // This is a source snapshot for copyPost(source, destination, ...), not the inserted row.
        // Keep its source options binding intact; clonePostLocked rebinds it to the new row.
        $snapshot['channel_id']=$document['binding']['channel_id'];$snapshot['manager_category_id']=$categoryId;
        // The root repeat/series orchestrates each new fan-out. Child timers
        // must not create a second fan-out or reuse an earlier forward receipt.
        $snapshot['repeat_interval_minutes']=null;$snapshot['repeat_until']=null;
        $snapshot['_telegram_multipost_child']=true;
        $snapshot['_telegram_explicit_source_copy']=$target['mode']==='copy';
        $snapshot['reply_chat_id']=null;$snapshot['reply_message_id']=null;
        $import=json_decode((string)($snapshot['import_json']??''),true);
        if($target['mode']==='copy') {
            $text=(string)($snapshot['text']??'');$media=json_decode((string)($snapshot['media_json']??''),true);
            if($text==='' && empty($snapshot['media_file_id']) && (!is_array($media)||$media===[]) && !is_array($snapshot['_telegram_native_content']??null)) {
                throw new InvalidArgumentException('У исходного репоста нет сохранённого содержимого для явной копии.');
            }
            foreach(['repost_chat_id','repost_message_id','repost_input_chat_id','repost_input_message_id'] as $field) $snapshot[$field]=null;
            if(is_array($import)){
                unset($import[self::FORWARD_KEY],$import['gitposted_multipost_forward_fork']);
                $snapshot['import_json']=$import===[]?null:self::encode($import);
            }
            if(is_array($import) && ($import['version']??null)===1) {$import['mode']='copy';$snapshot['import_json']=self::encode($import);}
        } else {
            $proof=$document['proof'];
            $snapshot['repost_chat_id']=$proof['source_chat_id'];$snapshot['repost_message_id']=$proof['source_message_ids'][0];
            $snapshot['repost_input_chat_id']=$proof['source_chat_id'];$snapshot['repost_input_message_id']=$proof['source_message_ids'][0];
            $descriptor=['version'=>1,'root_post_id'=>$document['binding']['post_id'],'root_nonce'=>$document['nonce'],
                'selection_digest'=>$document['selection_digest'],'target_channel_id'=>$target['channel_id'],'target_chat_id'=>$targetChat,
                'proof'=>$proof];
            $snapshot['import_json']=self::encode([self::FORWARD_KEY=>$descriptor]);
        }
        return $snapshot;
    }

    /** Root lookup is not authorization. forwardRequest must still validate its locked receipt. */
    public static function forwardRootPostId(array $post): ?int
    {
        $import=json_decode((string)($post['import_json']??''),true);
        if(!is_array($import)||!array_key_exists(self::FORWARD_KEY,$import))return null;
        $root=$import[self::FORWARD_KEY]['root_post_id']??null;self::positive($root);return $root;
    }

    /** Pure request, authorized by its immutable root receipt. Reserve the normal attempt before HTTP. */
    public static function forwardRequest(array $post, string $targetChat, array $document): ?array
    {
        $import=json_decode((string)($post['import_json']??''),true);
        if(!is_array($import)||!array_key_exists(self::FORWARD_KEY,$import)) return null;
        $forward=$import[self::FORWARD_KEY];
        self::validate($document);
        if(!is_array($forward)||($forward['version']??null)!==1 || !is_array($forward['proof']??null)
            || $document['phase']!=='materialized' || ($forward['root_post_id']??null)!==$document['binding']['post_id']
            || ($forward['root_nonce']??null)!==$document['nonce'] || ($forward['selection_digest']??null)!==$document['selection_digest']
            || ($forward['child_post_id']??null)!==self::databaseId($post['id']??null)
            || self::databaseId($post['author_id']??null)!==$document['binding']['actor_id']
            || ($forward['target_channel_id']??null)!==(int)($post['channel_id']??0)
            || ($forward['target_chat_id']??null)!==self::chat($targetChat)) throw new InvalidArgumentException('Привязка пересылки к каналу назначения изменилась.');
        $authorized=false;
        foreach($document['children'] as $child)if($child['post_id']===$forward['child_post_id']&&$child['channel_id']===$forward['target_channel_id']&&$child['mode']==='forward')$authorized=true;
        if(!$authorized||self::digest($forward['proof'])!==self::digest($document['proof']))throw new InvalidArgumentException('Пересылка не подтверждена журналом исходного поста.');
        $proof=$forward['proof'];$ids=self::messageIds($proof['source_message_ids']??[]);
        if(($proof['version']??null)!==1 || ($proof['post_id']??null)!==($forward['root_post_id']??null)
            || ($proof['expected_parts']??null)!==count($ids) || ($proof['continuation_complete']??null)!==true
            || ($proof['delivery_certain']??null)!==true || !preg_match('/^[a-f0-9]{64}$/D',(string)($proof['snapshot_hash']??''))
            || count((array)($proof['expected_origins']??[]))!==count($ids)) throw new InvalidArgumentException('Нет полного подтверждения источника пересылки.');
        foreach($proof['expected_origins'] as $origin) self::origin($origin);
        $sourceChat=self::chat($proof['source_chat_id']??null);
        if($sourceChat===$targetChat || ($post['repost_chat_id']??null)!==$sourceChat || (int)($post['repost_message_id']??0)!==$ids[0]) {
            throw new InvalidArgumentException('Источник пересылки изменился.');
        }
        $payload=['chat_id'=>$targetChat,'from_chat_id'=>$sourceChat,'message_ids'=>$ids,'disable_notification'=>!empty($post['silent'])];
        if(array_key_exists('_telegram_post_options',$post)) {
            if(!class_exists('PostedTelegramPostOptions',false)||!is_array($post['_telegram_post_options']))throw new InvalidArgumentException('Не подтверждены настройки пересылки.');
            if(PostedTelegramPostOptions::normalize($post['_telegram_post_options'],$post)['protect_content'])$payload['protect_content']=true;
        }
        return ['method'=>'forwardMessages','payload'=>$payload,'expected_origins'=>$proof['expected_origins'],'expected_count'=>count($ids)];
    }

    /** Never throw a "known rejection" after an accepted HTTP response. Never retry a partial batch. */
    public static function classifyForwardResult(array $request, mixed $response): array
    {
        $expected=$request['expected_count']??0;$known=[];
        if(!is_int($expected)||$expected<1||$expected>self::MAX_PARTS) return ['state'=>'uncertain','known_ids'=>[],'retry_whole_batch'=>false];
        if(!is_array($response)||($response['ok']??null)!==true||!is_array($response['result']??null)||!array_is_list($response['result'])) {
            return ['state'=>'uncertain','known_ids'=>[],'retry_whole_batch'=>false];
        }
        $valid=true;$last=0;
        foreach($response['result'] as $item) {
            $id=is_array($item)?($item['message_id']??null):null;
            if(!is_int($id)||$id<=0||$id>2147483647||$id<=$last) {$valid=false;continue;}
            $known[]=$id;$last=$id;
        }
        $state=!$valid||count($known)>$expected?'uncertain':(count($known)===$expected?'complete':'partial');
        return ['state'=>$state,'known_ids'=>$known,'retry_whole_batch'=>false];
    }

    public static function validate(array $document): void
    {
        if(($document['version']??null)!==1 || !is_array($document['binding']??null) || !is_array($document['targets']??null)
            || !is_int($document['revision']??null) || $document['revision']<0 || $document['revision']>9999
            || !is_bool($document['allow_dual']??null) || !preg_match('/^[a-f0-9]{12}$/D',(string)($document['nonce']??''))
            || !in_array($document['phase']??null,['selection','frozen','ready','materialized'],true)
            || !is_array($document['children']??null)||!array_is_list($document['children'])) throw new InvalidArgumentException('Повреждён план мультипубликации.');
        foreach(['post_id','channel_id','actor_id'] as $field) self::positive($document['binding'][$field]??null);
        self::chat($document['binding']['chat_id']??null);
        self::targets($document['targets'],$document['binding']['channel_id'],$document['allow_dual']);
        if(!hash_equals(self::selectionDigest($document),(string)($document['selection_digest']??''))) throw new InvalidArgumentException('Состав каналов изменился.');
        if($document['phase']==='selection') {
            if(($document['snapshot']??null)!==null||($document['expected_parts']??null)!==null||($document['purpose']??null)!==null
                ||($document['proof']??null)!==null||$document['children']!==[])throw new InvalidArgumentException('Незапущенный план содержит результаты доставки.');
        } else {
            if(!is_array($document['snapshot']??null)||!hash_equals(self::digest($document['snapshot']),(string)($document['snapshot_hash']??''))
                || !is_int($document['expected_parts']??null) || $document['expected_parts']<1 || $document['expected_parts']>self::MAX_PARTS
                || !in_array($document['purpose']??null,['now','schedule'],true)) throw new InvalidArgumentException('Снимок исходной публикации изменился.');
            if(array_key_exists('_telegram_native_content',$document['snapshot'])){$native=PostedTelegramNativeContent::normalize($document['snapshot']['_telegram_native_content']);if(($document['snapshot']['media_type']??null)!==$native['kind']||($document['snapshot']['media_file_id']??null)!==($native['payload']['file_id']??null)||($document['snapshot']['_telegram_native_legacy_recorded']??null)!==false)throw new InvalidArgumentException('native_multipost_snapshot_binding');}
            if(array_key_exists('_telegram_top_options',$document['snapshot']))PostedTelegramTopHold::normalize($document['snapshot']['_telegram_top_options'],['id'=>$document['binding']['post_id'],'channel_id'=>$document['binding']['channel_id']]);
            if(array_key_exists('_telegram_post_options',$document['snapshot'])) {
                if(!class_exists('PostedTelegramPostOptions',false)||!is_array($document['snapshot']['_telegram_post_options']))throw new InvalidArgumentException('Замороженные настройки публикации недоступны.');
                PostedTelegramPostOptions::normalize($document['snapshot']['_telegram_post_options'],['id'=>$document['binding']['post_id'],'channel_id'=>$document['binding']['channel_id']]);
            }
            if(array_key_exists('_telegram_multipost_upstream',$document['snapshot'])){
                if(!class_exists('PostedTelegramMultipostUpstreamProof',false)||!is_array($document['snapshot']['_telegram_multipost_upstream']))throw new InvalidArgumentException('Certified upstream proof is unavailable.');
                PostedTelegramMultipostUpstreamProof::validateSnapshot($document['snapshot']['_telegram_multipost_upstream'],$document['binding']['post_id'],$document['binding']['channel_id']);
            }
            if(array_key_exists('_telegram_source_spoiler',$document['snapshot'])){
                if(!class_exists('PostedTelegramSourceSpoiler',false)||!is_array($document['snapshot']['_telegram_source_spoiler']))throw new InvalidArgumentException('Source spoiler provenance is unavailable.');
                PostedTelegramSourceSpoiler::validatePostRecord($document['snapshot']['_telegram_source_spoiler'],array_replace($document['snapshot'],[
                    'id'=>$document['binding']['post_id'],'channel_id'=>$document['binding']['channel_id'],'chat_id'=>$document['binding']['chat_id'],
                ]));
            }
        }
        if($document['phase']==='frozen'&&(($document['proof']??null)!==null||$document['children']!==[]))throw new InvalidArgumentException('Доставка ещё не подтверждена полностью.');
        if(in_array($document['phase'],['ready','materialized'],true)) {
            $proof=$document['proof']??null;
            if(!is_array($proof)||($proof['version']??null)!==1||($proof['snapshot_hash']??null)!==$document['snapshot_hash']
                || ($proof['post_id']??null)!==$document['binding']['post_id'] || ($proof['source_chat_id']??null)!==$document['binding']['chat_id']
                || ($proof['expected_parts']??null)!==$document['expected_parts'] || ($proof['continuation_complete']??null)!==true
                || ($proof['delivery_certain']??null)!==true || count(self::messageIds($proof['source_message_ids']??[]))!==$document['expected_parts']
                || count((array)($proof['expected_origins']??[]))!==$document['expected_parts']) throw new InvalidArgumentException('Подтверждение публикации повреждено.');
            foreach($proof['expected_origins'] as $origin) self::origin($origin);
            if(self::digest($proof['expected_origins'])!==self::digest(self::expectedOrigins($document,$proof['source_message_ids'])))throw new InvalidArgumentException('Исходный канал пересылки изменился.');
        }
        if($document['phase']==='ready'&&$document['children']!==[])throw new InvalidArgumentException('Дочерние посты ещё не зафиксированы.');
        if($document['phase']==='materialized') {
            if(count($document['children'])!==count($document['targets'])) throw new InvalidArgumentException('Журнал дочерних публикаций повреждён.');
            $seen=[];
            foreach($document['children'] as $index=>$child) {
                self::positive($child['post_id']??null);$target=$document['targets'][$index];
                if(($child['channel_id']??null)!==$target['channel_id']||($child['mode']??null)!==$target['mode']||isset($seen[$child['post_id']])) {
                    throw new InvalidArgumentException('Дочерняя публикация привязана к другому каналу.');
                }
                $seen[$child['post_id']]=true;
            }
        }
        self::encode($document);
    }

    public static function encode(array $document): string
    {
        $json=json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($json)>self::MAX_DOCUMENT_BYTES) throw new InvalidArgumentException('Публикация слишком велика для мультипубликации.');
        return $json;
    }

    public static function digest(array $value): string
    {
        $canonical=static function(mixed $item) use (&$canonical):mixed {
            if(!is_array($item)) return $item;
            if(!array_is_list($item)) ksort($item,SORT_STRING);
            foreach($item as &$child) $child=$canonical($child);
            return $item;
        };
        return hash('sha256',self::encode($canonical($value)));
    }

    public static function assertBinding(array $document, array $post, array $channel): void
    {
        $binding=$document['binding'];
        if((int)($post['id']??0)!==$binding['post_id'] || (int)($post['channel_id']??0)!==$binding['channel_id']
            || (int)($post['author_id']??0)!==$binding['actor_id'] || (int)($channel['id']??0)!==$binding['channel_id']
            || (string)($channel['chat_id']??'')!==$binding['chat_id']) throw new InvalidArgumentException('Исходная публикация или её владелец изменились.');
    }

    public static function cloneSelection(array $document,array $post,array $channel,int $actorId,string $nonce): array
    {
        self::validate($document);
        $post['status']='draft';$post['author_id']=$actorId;
        return self::selection($post,$channel,$actorId,$document['targets'],$nonce,$document['allow_dual']);
    }

    public static function rebindActor(array $document,int $actorId,array $legacyCategories=[]): array
    {
        self::validate($document);self::positive($actorId);
        $document['binding']['actor_id']=$actorId;
        foreach($document['targets'] as &$target)if($target['category_id']===0&&isset($legacyCategories[$target['channel_id']])){
            self::positive($legacyCategories[$target['channel_id']]);$target['category_id']=$legacyCategories[$target['channel_id']];
        }
        unset($target);
        $document['selection_digest']=self::selectionDigest($document);
        self::validate($document);return$document;
    }

    private static function selectionDigest(array $document): string
    {
        return self::digest(array_intersect_key($document,array_flip(['version','binding','nonce','revision','allow_dual','targets'])));
    }

    private static function targets(array $targets, int $rootChannel, bool $allowDual): array
    {
        if(!array_is_list($targets)||count($targets)>self::MAX_TARGETS) throw new InvalidArgumentException('Выбрано слишком много каналов.');
        $result=[];$pairs=[];$channels=[];
        foreach($targets as $target) {
            if(!is_array($target)) throw new InvalidArgumentException('Некорректный канал назначения.');
            self::positive($target['channel_id']??null);
            if($target['channel_id']===$rootChannel || !in_array($target['mode']??null,['copy','forward'],true)
                || !is_int($target['category_id']??null) || $target['category_id']<0) throw new InvalidArgumentException('Некорректный режим назначения.');
            $item=['channel_id'=>$target['channel_id'],'chat_id'=>self::chat($target['chat_id']??null),
                'mode'=>$target['mode'],'category_id'=>$target['category_id']];
            if(count($target)!==count($item)||array_diff_key($target,$item)!==[]) throw new InvalidArgumentException('Неизвестные параметры канала назначения.');
            $pair=$item['channel_id'].':'.$item['mode'];
            if(isset($pairs[$pair]) || (!$allowDual && isset($channels[$item['channel_id']]))) throw new InvalidArgumentException('Канал назначения выбран повторно.');
            $pairs[$pair]=true;$channels[$item['channel_id']]=true;$result[]=$item;
        }
        return $result;
    }

    private static function messageIds(mixed $ids): array
    {
        if(!is_array($ids)||!array_is_list($ids)||$ids===[]||count($ids)>self::MAX_PARTS) throw new InvalidArgumentException('Нет полного списка сообщений источника.');
        $last=0;
        foreach($ids as $id) {
            if(!is_int($id)||$id<=0||$id>2147483647||$id<=$last) throw new InvalidArgumentException('ID сообщений источника неоднозначны.');
            $last=$id;
        }
        return $ids;
    }

    private static function origin(mixed $origin): array
    {
        if(!is_array($origin)||($origin['type']??null)!=='channel'||!is_array($origin['chat']??null)) throw new InvalidArgumentException('Источник пересылки не подтверждён.');
        $chat=$origin['chat']['id']??null;if(is_int($chat))$chat=(string)$chat;$chat=self::chat($chat);$id=$origin['message_id']??null;
        if(!is_int($id)||$id<=0||$id>2147483647) throw new InvalidArgumentException('ID исходной пересылки не подтверждён.');
        return ['type'=>'channel','chat'=>['id'=>$chat],'message_id'=>$id];
    }

    private static function expectedOrigins(array $document, array $ids): array
    {
        $snapshot=$document['snapshot'];$origins=[];
        if(isset($snapshot['_telegram_multipost_upstream'])){
            $proof=$snapshot['_telegram_multipost_upstream'];
            if(count($proof['expected_origins'])!==count($ids))throw new InvalidArgumentException('Certified upstream count is incomplete.');
            return$proof['expected_origins'];
        }
        if(!empty($snapshot['repost_chat_id'])&&!empty($snapshot['repost_message_id'])) {
            if(count($ids)!==1)throw new InvalidArgumentException('Неоднозначный источник нативного репоста.');
            $import=json_decode((string)($snapshot['import_json']??''),true);
            $origin=is_array($import)?($import['source']['forward_origin']??null):null;
            if($origin===null)$origin=['type'=>'channel','chat'=>['id'=>(string)$snapshot['repost_chat_id']],'message_id'=>(int)$snapshot['repost_message_id']];
            $origins[]=self::origin($origin);
        } else {
            foreach($ids as $id)$origins[]=['type'=>'channel','chat'=>['id'=>$document['binding']['chat_id']],'message_id'=>$id];
        }
        return $origins;
    }

    private static function databaseId(mixed $id): int
    {
        if(is_string($id)&&preg_match('/^[1-9][0-9]{0,18}$/D',$id)===1&&(string)(int)$id===$id)$id=(int)$id;
        self::positive($id);return $id;
    }

    private static function positive(mixed $id): void
    {
        if(!is_int($id)||$id<=0) throw new InvalidArgumentException('Некорректный идентификатор мультипубликации.');
    }

    private static function chat(mixed $chat): string
    {
        if(!is_string($chat)||preg_match('/^-[1-9][0-9]{0,19}$/D',$chat)!==1) throw new InvalidArgumentException('Не подтверждён Telegram ID канала.');
        return $chat;
    }
}

/** Unconnected store/materializer; caller integration must supply normal publish-attempt hooks. */
trait GitPostedTelegramMultipost
{
    private function telegramMultipostRead(int $rootPostId, bool $lock=false): ?array
    {
        if($lock&&!$this->pdo->inTransaction()) throw new LogicException('Multipost row lock requires a transaction.');
        $query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1'.($lock?' FOR UPDATE':''));
        $query->execute(['key'=>PostedTelegramMultipost::key($rootPostId)]);$raw=$query->fetchColumn();
        if($raw===false)return null;
        $document=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($document))throw new InvalidArgumentException('Повреждён журнал мультипубликации.');
        PostedTelegramMultipost::validate($document);
        if($document['binding']['post_id']!==$rootPostId)throw new InvalidArgumentException('Журнал относится к другой публикации.');
        return $document;
    }

    private function telegramMultipostWriteLocked(array $document): void
    {
        if(!$this->pdo->inTransaction()) throw new LogicException('Multipost write requires source row locks.');
        PostedTelegramMultipost::validate($document);
        $this->pdo->prepare('UPDATE posted_app_settings SET setting_value=:value WHERE setting_key=:key')
            ->execute(['value'=>PostedTelegramMultipost::encode($document),'key'=>PostedTelegramMultipost::key($document['binding']['post_id'])]);
    }

    /** Invoke only from the existing channel/post/actor-locked mutable-draft operation. */
    private function telegramMultipostSelectLocked(array $post, array $channel, int $actorId, array $targets, string $nonce, ?string $expectedDigest=null, bool $allowDual=false): array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('Multipost selection requires a transaction.');
        $attempt=$this->pdo->prepare('SELECT post_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$attempt->execute([(int)$post['id']]);
        if($attempt->fetchColumn()!==false)throw new InvalidArgumentException('Публикация уже запускалась. Выбор каналов недоступен.');
        PostedTelegramTopHold::assertSelectionScheduleLocked($this->pdo,$post,$targets);
        $initial=PostedTelegramMultipost::selection($post,$channel,$actorId,$targets,$nonce,$allowDual);
        // Insert before selecting: avoid a compatible empty-gap lock followed by INSERT.
        $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)
            ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)')
            ->execute(['key'=>PostedTelegramMultipost::key((int)$post['id']),'value'=>PostedTelegramMultipost::encode($initial)]);
        $current=$this->telegramMultipostRead((int)$post['id'],true);
        PostedTelegramMultipost::assertBinding($current,$post,$channel);
        if($expectedDigest===null) {
            if($current['selection_digest']!==$initial['selection_digest'])throw new InvalidArgumentException('Это меню выбора каналов уже устарело.');
            return $current;
        }
        if($current['nonce']!==$nonce)throw new InvalidArgumentException('Это меню выбора каналов уже устарело.');
        $current=PostedTelegramMultipost::replaceSelection($current,$targets,$expectedDigest);
        $this->telegramMultipostWriteLocked($current);return $current;
    }

    /** Call inside actual attempt reservation, before any transport call. */
    private function telegramMultipostFreezeAttemptLocked(array $post, array $channel, int $primaryParts, int $continuationParts, string $purpose): ?array
    {
        $current=$this->telegramMultipostRead((int)$post['id'],true);
        if($current===null||$current['targets']===[])return null;
        if(class_exists('PostedTelegramPostOptions',false))$post=PostedTelegramPostOptions::freeze($this->pdo,$post);
        $post=PostedTelegramTopHold::freeze($this->pdo,$post);
        $post=PostedTelegramNativeStore::freeze($this->pdo,$post);
        if(class_exists('PostedTelegramSourceSpoiler',false))$post['_telegram_source_spoiler']=PostedTelegramSourceSpoiler::freeze($this->pdo,array_replace($post,['chat_id'=>$channel['chat_id']]));
        $upstream=PostedTelegramMultipostUpstreamProof::fromPost($this->pdo,$post,true);
        $current=PostedTelegramMultipost::freeze($current,$post,$channel,$primaryParts,$continuationParts,$purpose,$upstream);
        $this->telegramMultipostWriteLocked($current);return $current;
    }

    /** Call in the full-success finalization transaction, never in partial/unknown branches. */
    private function telegramMultipostKnownFullLocked(array $post, array $channel, array $allSlots, bool $continuationComplete, bool $deliveryCertain): void
    {
        if(method_exists($this,'telegramCommentsKnownFullPrototype05')){$this->telegramCommentsKnownFullPrototype05($post,$channel,$allSlots,$continuationComplete,$deliveryCertain);if(method_exists($this,'noteTelegramCommentsFullPublication'))$this->noteTelegramCommentsFullPublication();}
        $current=$this->telegramMultipostRead((int)$post['id'],true);
        if($current===null||$current['targets']===[])return;
        PostedTelegramMultipost::assertBinding($current,$post,$channel);
        if(($post['status']??'')!=='sent')throw new InvalidArgumentException('Исходная публикация ещё не завершена.');
        $current=PostedTelegramMultipost::knownFull($current,$allSlots,$continuationComplete,$deliveryCertain);
        $this->telegramMultipostWriteLocked($current);
    }

    /** Creates independent rows atomically, but performs no send. Replay returns the original IDs. */
    private function telegramMultipostMaterialize(int $rootPostId): array
    {
        if($this->pdo->inTransaction())throw new LogicException('Materialize outside the original publish transaction.');
        $hint=$this->telegramMultipostRead($rootPostId);
        if($hint===null)return ['state'=>'none','children'=>[]];
        $pauseIds=[$hint['binding']['channel_id'],...array_column($hint['targets'],'channel_id')];$pauseIds=array_values(array_unique($pauseIds));sort($pauseIds,SORT_NUMERIC);
        foreach($pauseIds as$pauseId){$check=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=? LIMIT 1');$check->execute([$pauseId]);$pauseChannel=$check->fetch();
            if(!$pauseChannel||PostedTelegramAuthorityPause::blocked($this->pdo,$pauseChannel))throw new PostedTelegramAuthorityPauseException('paused');}
        if($hint['phase']==='materialized')return ['state'=>'materialized','children'=>$hint['children']];
        if($hint['phase']!=='ready')return ['state'=>'waiting_source','children'=>[]];
        $channelIds=[$hint['binding']['channel_id'],...array_column($hint['targets'],'channel_id')];
        $channelIds=array_values(array_unique($channelIds));sort($channelIds,SORT_NUMERIC);
        // Existing tenure checks may read Telegram; finish them before SQL row locks.
        foreach($channelIds as $id) if(!$this->ensureTelegramChannelTenure($id))throw new InvalidArgumentException('Канал мультипубликации сейчас недоступен.');
        $this->pdo->beginTransaction();
        try {
            $channels=[];$actors=[$hint['binding']['actor_id']];
            foreach($channelIds as $id) {
                $query=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$id]);$channel=$query->fetch();
                if(!is_array($channel)||empty($channel['is_active'])||empty($channel['bot_can_post'])||(int)($channel['added_by']??0)<=0)throw new InvalidArgumentException('Канал мультипубликации отключён.');
                PostedTelegramAuthorityPause::assertDeliveryLocked($this->pdo,$channel);
                $channels[$id]=$channel;$actors[]=(int)$channel['added_by'];
            }
            $query=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$rootPostId]);$root=$query->fetch();
            if(!is_array($root))throw new InvalidArgumentException('Исходный пост удалён.');
            if(!$this->lockActiveTelegramActors($actors))throw new InvalidArgumentException('Автор или владелец канала отключён.');
            $current=$this->telegramMultipostRead($rootPostId,true);
            if($current===null||$current['selection_digest']!==$hint['selection_digest'])throw new InvalidArgumentException('Состав каналов изменился.');
            if($current['phase']==='materialized') {$this->pdo->commit();return ['state'=>'materialized','children'=>$current['children']];}
            if($current['phase']!=='ready')throw new InvalidArgumentException('Исходный пост ещё не подтверждён полностью.');
            PostedTelegramMultipost::assertBinding($current,$root,$channels[$current['binding']['channel_id']]);
            if(($root['status']??'')!=='sent')throw new InvalidArgumentException('Исходный пост уже недоступен для пересылки.');
            $storedIds=$this->postMessageIds($root);
            if($storedIds!==$current['proof']['source_message_ids'])throw new InvalidArgumentException('ID исходной публикации изменились.');
            $actor=$current['binding']['actor_id'];$specs=[];$groups=[];
            $this->telegramMultipostPolicy($actor,$channels[$current['binding']['channel_id']]);
            foreach($current['targets'] as $target) {
                $channel=$channels[$target['channel_id']];
                $this->telegramMultipostPolicy($actor,$channel);
                if((string)$channel['chat_id']!==$target['chat_id'])throw new InvalidArgumentException('Telegram ID канала назначения изменился.');
                $query=$this->pdo->prepare('SELECT * FROM posted_permissions WHERE user_id=:user AND channel_id=:channel LIMIT 1 FOR UPDATE');
                $query->execute(['user'=>$actor,'channel'=>$target['channel_id']]);$permission=$query->fetch();
                $needSchedule=$current['purpose']==='schedule'||(int)($current['snapshot']['repeat_interval_minutes']??0)>0;
                if(!is_array($permission)||(int)($permission['can_post']??0)!==1||($needSchedule&&(int)($permission['can_schedule']??0)!==1))throw new InvalidArgumentException('Нет необходимых прав в одном из каналов назначения.');
                $category=$this->resolveTelegramManagerCategoryLocked($actor,$target['channel_id'],$target['category_id'],$permission);
                $spec=PostedTelegramMultipost::childSpec($current,$target,(string)$channel['chat_id'],$category);
                $specs[]=['target'=>$target,'post'=>$spec];
                if($actor!==(int)$channel['added_by'] && (!empty($permission['is_ad_manager'])||$category!==null)) {
                    $group=$target['channel_id'].':'.($category??0);
                    if(!isset($groups[$group]))$groups[$group]=['channel'=>$target['channel_id'],'category'=>$category,'permission'=>$permission,'posts'=>0,'pins'=>0];
                    $groups[$group]['posts']++;$groups[$group]['pins']+=!empty($spec['pin_after_publish'])?1:0;
                }
            }
            foreach($groups as $group) $this->telegramMultipostCheckQuotaLocked($actor,$group);
            $children=[];
            foreach($specs as $entry) {
                $status=$current['purpose']==='schedule'?'scheduled':'draft';
                $scheduled=$status==='scheduled'?(string)($root['scheduled_at']??''):null;
                if($status==='scheduled'&&$scheduled==='')throw new InvalidArgumentException('Дата исходной публикации потеряна.');
                $id=$this->copyPost($entry['post'],$entry['target']['channel_id'],$actor,$scheduled,$status);
                if($id<=0)throw new RuntimeException('Не удалось создать отдельную публикацию.');
                $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)')->execute([
                    'telegram_multipost_parent_'.$id,PostedTelegramMultipost::encode(['version'=>1,'post_id'=>$id,'channel_id'=>$entry['target']['channel_id'],'root_post_id'=>$rootPostId]),
                ]);
                if($entry['target']['mode']==='forward') {
                    $import=json_decode((string)$entry['post']['import_json'],true,512,JSON_THROW_ON_ERROR);
                    $import[PostedTelegramMultipost::FORWARD_KEY]['child_post_id']=$id;
                    $this->pdo->prepare('UPDATE posted_posts SET import_json=:import WHERE id=:id')
                        ->execute(['import'=>PostedTelegramMultipost::encode($import),'id'=>$id]);
                }
                $children[]=['post_id'=>$id,'channel_id'=>$entry['target']['channel_id'],'mode'=>$entry['target']['mode']];
            }
            $current['children']=$children;$current['phase']='materialized';$this->telegramMultipostWriteLocked($current);
            $this->pdo->commit();return ['state'=>'materialized','children'=>$children];
        } catch(Throwable $exception) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();throw $exception;
        }
    }

    private function telegramMultipostCheckQuotaLocked(int $actor, array $group): void
    {
        $limits=$group['permission'];
        if($group['category']!==null) {
            $query=$this->pdo->prepare('SELECT post_quota_remaining,pin_quota_remaining FROM posted_manager_categories WHERE id=:id AND user_id=:user AND channel_id=:channel AND is_active=1 LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$group['category'],'user'=>$actor,'channel'=>$group['channel']]);$limits=$query->fetch();
            if(!is_array($limits))throw new InvalidArgumentException('Рекламная компания назначения отключена.');
        }
        $query=$this->pdo->prepare("SELECT manager_post_quota_charged,manager_pin_quota_charged,pin_after_publish
            FROM posted_posts WHERE channel_id=:channel AND author_id=:author
            AND status IN ('scheduled','publishing','telegram_pending') AND COALESCE(manager_category_id,0)=:category FOR UPDATE");
        $query->execute(['channel'=>$group['channel'],'author'=>$actor,'category'=>$group['category']??0]);
        $posts=$group['posts'];$pins=$group['pins'];
        foreach($query->fetchAll() as $queued) {
            if(empty($queued['manager_post_quota_charged']))$posts++;
            if(!empty($queued['pin_after_publish'])&&empty($queued['manager_pin_quota_charged']))$pins++;
        }
        if(($limits['post_quota_remaining']!==null&&$posts>(int)$limits['post_quota_remaining'])
            ||($limits['pin_quota_remaining']!==null&&$pins>(int)$limits['pin_quota_remaining']))throw new InvalidArgumentException('Недостаточно лимита публикаций или закрепов в канале назначения.');
    }
}
