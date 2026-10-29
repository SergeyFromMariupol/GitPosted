<?php

declare(strict_types=1);
require_once __DIR__.'/telegram-native-store.php';
require_once __DIR__.'/telegram-top-hold.php';
require_once __DIR__.'/telegram-template-media.php';
require_once __DIR__.'/telegram-source-spoiler.php';
require_once __DIR__.'/telegram-caption-vector.php';
require_once __DIR__.'/telegram-caption-placement.php';

/** Typed delivery metadata. Publication text and import provenance are separate. */
final class PostedTelegramPostOptions
{
    public static function key(int $postId): string
    {
        if ($postId <= 0) throw new InvalidArgumentException('Invalid Telegram post options identity.');
        return 'telegram_post_options_' . $postId;
    }

    public static function defaults(array $post): array
    {
        $id = (int) ($post['id'] ?? 0);$channel = (int) ($post['channel_id'] ?? 0);
        if ($id <= 0 || $channel <= 0) throw new InvalidArgumentException('Invalid Telegram post options binding.');
        return ['version'=>2,'post_id'=>$id,'channel_id'=>$channel,'protect_content'=>false,'preview_media'=>null,'media_spoiler'=>false,'paid_media_stars'=>null];
    }

    public static function normalize(array $value, array $post): array
    {
        $expected = self::defaults($post);
        // Previously persisted options and reserved attempts remain readable.
        if(($value['version']??null)===1){
            $legacyKeys=array_keys($value);sort($legacyKeys);
            if($legacyKeys!==['channel_id','post_id','preview_media','protect_content','version'])throw new InvalidArgumentException('Настройки публикации повреждены.');
            $value['version']=2;$value['media_spoiler']=false;$value['paid_media_stars']=null;
        }
        $keys = array_keys($value);sort($keys);$wanted=array_keys($expected);sort($wanted);
        if ($keys !== $wanted || ($value['version'] ?? null) !== 2
            || ($value['post_id'] ?? null) !== $expected['post_id'] || ($value['channel_id'] ?? null) !== $expected['channel_id']
            || !is_bool($value['protect_content'] ?? null)) {
            throw new InvalidArgumentException('Настройки публикации повреждены или относятся к другому посту.');
        }
        $expected['protect_content']=$value['protect_content'];
        $expected['preview_media']=self::preview($value['preview_media']);
        if(!is_bool($value['media_spoiler']??null)||($value['paid_media_stars']!==null&&(!is_int($value['paid_media_stars'])||$value['paid_media_stars']<1||$value['paid_media_stars']>10000))
            ||($value['media_spoiler']&&$value['paid_media_stars']!==null))throw new InvalidArgumentException('Некорректные настройки платного медиа или спойлера.');
        $expected['media_spoiler']=$value['media_spoiler'];$expected['paid_media_stars']=$value['paid_media_stars'];
        return $expected;
    }

    public static function behavior(array $options): array
    {
        if(!$options['protect_content']&&$options['preview_media']===null&&!$options['media_spoiler']&&$options['paid_media_stars']===null)return [];
        return array_intersect_key($options,array_flip(['protect_content','preview_media','media_spoiler','paid_media_stars']));
    }

    private static function preview(mixed $value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value)) throw new InvalidArgumentException('Некорректная ссылка на изображение превью.');
        $keys=array_keys($value);sort($keys);
        if ($keys!==['asset_id','version'] || ($value['version']??null)!==1
            || !is_string($value['asset_id']??null) || preg_match('/\A[a-f0-9]{64}\z/D',$value['asset_id'])!==1) {
            throw new InvalidArgumentException('Некорректная ссылка на изображение превью.');
        }
        return ['version'=>1,'asset_id'=>$value['asset_id']];
    }

    private static function raw(PDO $pdo,string $key): ?string
    {
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$query->execute([$key]);
        $value=$query->fetchColumn();return $value===false?null:(string)$value;
    }

    public static function load(PDO $pdo,array $post): array
    {
        if (array_key_exists('_telegram_post_options',$post)) {
            if (!is_array($post['_telegram_post_options'])) throw new InvalidArgumentException('Invalid frozen Telegram options.');
            return self::normalize($post['_telegram_post_options'],$post);
        }
        $default=self::defaults($post);$raw=self::raw($pdo,self::key($default['post_id']));
        if ($raw===null) return $default;
        try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$error){throw new InvalidArgumentException('Настройки публикации повреждены.',0,$error);}
        if (!is_array($value)) throw new InvalidArgumentException('Настройки публикации повреждены.');
        return self::normalize($value,$post);
    }

    public static function freeze(PDO $pdo,array $post): array
    {
        if(array_key_exists('_telegram_post_options',$post)){$post['_telegram_post_options']=self::load($pdo,$post);return PostedTelegramCaptionVector::freeze($pdo,$post);}
        $binding=self::defaults($post);
        $query=$pdo->prepare('SELECT attempt.submission_key,attempt.channel_id,attempt.state,attempt.gate_channel_id,
            post.channel_id AS post_channel_id,post.status AS post_status
            FROM posted_telegram_publish_attempts attempt INNER JOIN posted_posts post ON post.id=attempt.post_id
            WHERE attempt.post_id=? LIMIT 1');$query->execute([$binding['post_id']]);$attempt=$query->fetch();
        $submission=is_array($attempt)?$attempt['submission_key']:false;
        if(is_array($attempt)&&((int)$attempt['channel_id']!==$binding['channel_id']||(int)$attempt['post_channel_id']!==$binding['channel_id']))throw new InvalidArgumentException('Frozen publication channel changed.');
        $completed=is_array($attempt)&&$submission===null&&$attempt['state']==='completed'&&$attempt['gate_channel_id']===null
            &&in_array($attempt['post_status'],['sent','deleted'],true);
        if(is_array($attempt)&&$submission===null&&$attempt['state']==='completed'&&!$completed)throw new InvalidArgumentException('Completed publication cannot be restarted.');
        $raw=$submission===false?null:self::raw($pdo,'telegram_attempt_options_'.$binding['post_id']);
        if($raw!==null){
            try{$snapshot=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$error){throw new InvalidArgumentException('Frozen publication options are invalid.',0,$error);}
            $keys=is_array($snapshot)?array_keys($snapshot):[];sort($keys);
            if($keys!==['channel_id','options','post_id','submission_key','version']||($snapshot['version']??null)!==1
                ||($snapshot['post_id']??null)!==$binding['post_id']||($snapshot['channel_id']??null)!==$binding['channel_id']
                ||!is_string($snapshot['submission_key']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$snapshot['submission_key'])!==1
                ||(!$completed&&!hash_equals((string)$submission,$snapshot['submission_key']))||!is_array($snapshot['options']??null)) {
                throw new InvalidArgumentException('Frozen publication options binding changed.');
            }
            $post['_telegram_post_options']=self::normalize($snapshot['options'],$post);return PostedTelegramCaptionVector::freeze($pdo,$post);
        }
        $options=self::load($pdo,$post);
        if($submission!==false&&self::behavior($options)!==[])throw new InvalidArgumentException('Frozen publication options are unavailable.');
        $post['_telegram_post_options']=$options;return PostedTelegramCaptionVector::freeze($pdo,$post);
    }

    public static function saveAttemptSnapshotLocked(PDO $pdo,array $post,string $submissionKey): void
    {
        if(!$pdo->inTransaction()||preg_match('/\A[a-f0-9]{64}\z/D',$submissionKey)!==1)throw new LogicException('Attempt options require the reserved transaction.');
        $query=$pdo->prepare('SELECT submission_key FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$query->execute([(int)$post['id']]);
        if($query->fetchColumn()!==$submissionKey)throw new RuntimeException('Reserved publication identity changed.');
        $value=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'submission_key'=>$submissionKey,'options'=>self::load($pdo,$post)];
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['telegram_attempt_options_'.(int)$post['id'],json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        PostedTelegramCaptionVector::snapshotLocked($pdo,$post,$submissionKey);
    }

    public static function saveLocked(PDO $pdo,array $post,array $changes): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Telegram post options require the locked post transaction.');
        foreach ($changes as $key=>$value) if (!in_array($key,['protect_content','preview_media','media_spoiler','paid_media_stars'],true)) throw new InvalidArgumentException('Unknown Telegram post option.');
        unset($post['_telegram_post_options']);$options=self::load($pdo,$post);
        foreach ($changes as$key=>$value) $options[$key]=$value;
        $options=self::normalize($options,$post);
        if($options['paid_media_stars']!==null&&PostedTelegramCaptionPlacement::load($pdo,$post)!==null)throw new InvalidArgumentException('Платное медиа нельзя совместить с описанием над медиа. Настройки сохранены.');
        if($options['paid_media_stars']!==null&&PostedTelegramCaptionVector::load($pdo,$post)!==null)throw new InvalidArgumentException('Платное медиа не поддерживает отдельные описания файлов альбома.');
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([self::key((int)$post['id']),json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        return $options;
    }

    public static function clonePostLocked(PDO $pdo,array $source,array $target): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Option cloning requires the post insertion transaction.');
        $options=self::load($pdo,$source);
        if(PostedTelegramSourceSpoiler::clonePostLocked($pdo,$source,$target)){
            if($options['paid_media_stars']!==null)throw new InvalidArgumentException('Native spoiler copy cannot silently replace paid media.');
            $options['media_spoiler']=true;
        }
        if($options['media_spoiler']||$options['paid_media_stars']!==null){
            $query=$pdo->prepare('SELECT repost_chat_id,repost_message_id FROM posted_posts WHERE id=? LIMIT 1');$query->execute([(int)$target['id']]);$row=$query->fetch();
            if(!is_array($row))throw new RuntimeException('New publication disappeared.');
            if(!empty($row['repost_chat_id'])&&!empty($row['repost_message_id'])){
                if($options['paid_media_stars']!==null)throw new InvalidArgumentException('Paid media must not be downgraded to an ordinary native forward.');
                $options['media_spoiler']=false;
            }
        }
        self::writeNew($pdo,$target,$options);
        PostedTelegramCaptionVector::cloneLocked($pdo,$source,$target);
        PostedTelegramNativeStore::cloneLocked($pdo,$source,$target);
        PostedTelegramCaptionPlacement::cloneLocked($pdo,$source,$target);
    }

    private static function writeNew(PDO $pdo,array $target,array $options): void
    {
        $defaults=self::defaults($target);
        if (self::raw($pdo,self::key($defaults['post_id']))!==null) throw new RuntimeException('New post options already exist.');
        if (self::behavior($options)===[]) return;
        self::saveLocked($pdo,$target,self::behavior($options));
    }

    public static function loadTemplate(PDO $pdo,array $template): array
    {
        $id=(int)($template['id']??0);$channel=(int)($template['channel_id']??0);
        if($id<=0||$channel<0)throw new InvalidArgumentException('Invalid Telegram template options binding.');
        $post=['id'=>$id,'channel_id'=>$channel?:1];$defaults=self::defaults($post);
        $raw=self::raw($pdo,'telegram_template_options_'.$id);
        if($raw===null){$defaults['channel_id']=$channel;unset($defaults['post_id']);$defaults['template_id']=$id;return$defaults;}
        try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(Throwable$error){throw new InvalidArgumentException('Настройки шаблона повреждены.',0,$error);}
        if(!is_array($value)||($value['template_id']??null)!==$id||($value['channel_id']??null)!==$channel)throw new InvalidArgumentException('Настройки шаблона относятся к другому шаблону.');
        unset($value['template_id']);$value['post_id']=$id;$value['channel_id']=$channel?:1;
        $value=self::normalize($value,$post);unset($value['post_id']);$value['template_id']=$id;$value['channel_id']=$channel;return$value;
    }

    public static function cloneTemplateToPostLocked(PDO $pdo,array $template,array $post): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Template options require the insertion transaction.');
        if(PostedTelegramNativeTemplate::isNative($template))PostedTelegramNativeStore::cloneLocked($pdo,$template,$post,'t','p');
        PostedTelegramSourceSpoiler::cloneTemplateToPostLocked($pdo,$template,$post);
        self::writeNew($pdo,$post,self::loadTemplate($pdo,$template));
        PostedTelegramTopHold::fromTemplateLocked($pdo,$template,$post);
        PostedTelegramCaptionVector::cloneLocked($pdo,$template,$post,'t');
        PostedTelegramCaptionPlacement::cloneLocked($pdo,$template,$post,'t','p');
    }

    public static function saveTemplateLocked(PDO $pdo,array $template,array $changes): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Template metadata requires its locked transaction.');
        foreach($changes as$key=>$value)if(!in_array($key,['protect_content','preview_media','media_spoiler','paid_media_stars'],true))throw new InvalidArgumentException('Unknown template media option.');
        $id=(int)$template['id'];$scope=(int)($template['channel_id']??0);$options=self::loadTemplate($pdo,$template);unset($options['template_id']);$options['post_id']=$id;$options['channel_id']=$scope?:1;
        $options=self::normalize(array_replace($options,$changes),['id'=>$id,'channel_id'=>$scope?:1]);$type=(string)($template['media_type']??'none');
        if(($options['media_spoiler']&&!in_array($type,['photo','video','animation'],true))||($options['paid_media_stars']!==null&&!in_array($type,['photo','video'],true)))throw new InvalidArgumentException('Шаблон не поддерживает выбранные настройки медиа.');
        if($options['paid_media_stars']!==null&&PostedTelegramCaptionVector::load($pdo,PostedTelegramTemplateMedia::hydrate($pdo,$template),'t')!==null)throw new InvalidArgumentException('Платное медиа не поддерживает отдельные описания файлов альбома.');
        if($options['paid_media_stars']!==null&&PostedTelegramCaptionPlacement::load($pdo,$template,'t')!==null)throw new InvalidArgumentException('Платное медиа нельзя совместить с описанием над медиа. Шаблон сохранён.');
        unset($options['post_id']);$options['template_id']=$id;$options['channel_id']=$scope;
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute(['telegram_template_options_'.$id,json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }

    public static function clonePostToTemplateLocked(PDO $pdo,array $post,array $template): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Template options require the insertion transaction.');
        PostedTelegramTemplateMedia::clonePostLocked($pdo,$post,$template);$template=PostedTelegramTemplateMedia::hydrate($pdo,$template);
        PostedTelegramTopHold::toTemplateLocked($pdo,$post,$template);
        PostedTelegramCaptionVector::cloneLocked($pdo,$post,$template,'p','t');
        PostedTelegramCaptionPlacement::cloneLocked($pdo,$post,$template,'p','t');
        $options=self::load($pdo,$post);$id=(int)($template['id']??0);$channel=(int)($template['channel_id']??0);
        if($id<=0||$channel<0)throw new InvalidArgumentException('Invalid template binding.');
        if(PostedTelegramSourceSpoiler::clonePostToTemplateLocked($pdo,$post,$template)){
            if($options['paid_media_stars']!==null)throw new InvalidArgumentException('Native spoiler template cannot silently replace paid media.');
            $options['media_spoiler']=true;
        }
        if(self::raw($pdo,'telegram_template_options_'.$id)!==null)throw new RuntimeException('New template options already exist.');
        if(self::behavior($options)===[])return;
        unset($options['post_id']);$options['template_id']=$id;$options['channel_id']=$channel;
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)')
            ->execute(['telegram_template_options_'.$id,json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }

    public static function scopeSnapshot(PDO $pdo,array $posts,array $templates): array
    {
        $result=['posts'=>[],'templates'=>[]];
        foreach($posts as$post)$result['posts'][(int)$post['id']]=self::load($pdo,$post);
        foreach($templates as$template)$result['templates'][(int)$template['id']]=self::loadTemplate($pdo,$template);
        $nativePosts=[];foreach($posts as$post){$native=PostedTelegramNativeStore::postScopeSnapshot($pdo,$post);if($native!==[])$nativePosts[(int)$post['id']]=$native;}if($nativePosts!==[]){ksort($nativePosts,SORT_NUMERIC);$result['native_posts']=$nativePosts;}
        $templateMedia=PostedTelegramTemplateMedia::scopeSnapshot($pdo,$templates);if($templateMedia!==[])$result['template_media']=$templateMedia;
        $provenance=PostedTelegramSourceSpoiler::scopeSnapshot($pdo,$posts,$templates);
        if($provenance['posts']!==[]||$provenance['templates']!==[])$result['source_spoilers']=$provenance;
        $captions=PostedTelegramCaptionVector::scopeSnapshot($pdo,$posts,$templates);if($captions['posts']!==[]||$captions['templates']!==[])$result['caption_vectors']=$captions;
        $nativeTemplates=[];foreach($templates as$template)if(PostedTelegramNativeTemplate::isNative($template))$nativeTemplates[(int)$template['id']]=PostedTelegramNativeTemplate::scopeSnapshot($pdo,$template);if($nativeTemplates!==[])$result['native_templates']=$nativeTemplates;
        $placement=PostedTelegramCaptionPlacement::scopeSnapshot($pdo,$posts,$templates);if($placement['posts']!==[]||$placement['templates']!==[])$result['caption_placement']=$placement;
        ksort($result['posts'],SORT_NUMERIC);ksort($result['templates'],SORT_NUMERIC);return$result;
    }
}

trait GitPostedTelegramPostOptions
{
    private function telegramProtectionCallback(array $post,bool $desired): string
    {
        return 'post_option:protect:'.(int)$post['id'].':'.($desired?'1':'0').':'.substr($this->telegramSubmissionKey($post),0,16);
    }

    private function handleTelegramPostOptionsCallback(int $userId,string $data,?array $target=null): bool
    {
        if(preg_match('/\Apost_option:protect:([1-9][0-9]{0,18}):([01]):([a-f0-9]{16})\z/D',$data,$match)!==1)return false;
        $postId=(int)$match[1];$desired=$match[2]==='1';
        $changed=$this->setTelegramPostOptions($userId,$postId,['protect_content'=>$desired],null,
            fn(array $post):bool=>hash_equals($match[3],substr($this->telegramSubmissionKey($post),0,16)));
        if(!$changed){$this->telegram->sendMessage($userId,'Меню устарело. Откройте черновик заново.');return false;}
        $target??=[];$target['draft_ui_expanded']=true;
        $this->showDraftActions($userId,$postId,'',$target);
        return true;
    }

    private function telegramPostOptions(array $post): array
    {
        return PostedTelegramPostOptions::load($this->pdo,$post);
    }

    private function telegramDeliveryExtra(array $post): array
    {
        $extra=!empty($post['silent'])?['disable_notification'=>true]:[];
        if($this->telegramPostOptions($post)['protect_content'])$extra['protect_content']=true;
        return$extra;
    }

    private function setTelegramPostOptions(int $userId,int $postId,array $changes,?array $expectedInput=null,?callable $validateLockedPost=null): bool
    {
        return $this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $post,array $channel)use($userId,$changes,$expectedInput,$validateLockedPost):bool{
            // Fingerprints use the same channel binding as post()/mutableDraft().
            $post['chat_id']=(string)$channel['chat_id'];
            $post['channel_title']=(string)($channel['title']??'');
            $attempt=$this->pdo->prepare('SELECT post_id FROM posted_telegram_publish_attempts WHERE post_id=? LIMIT 1');$attempt->execute([(int)$post['id']]);
            if($attempt->fetchColumn()!==false)return false;
            if($validateLockedPost!==null&&$validateLockedPost($post)!==true)return false;
            if(array_key_exists('media_spoiler',$changes)||array_key_exists('paid_media_stars',$changes)){
                $options=array_replace($this->telegramPostOptions($post),$changes);
                if($this->isNativeRepost($post))return false;
                $media=$this->postMedia($post);$types=array_column($media,'type');
                if(($options['media_spoiler']||$options['paid_media_stars']!==null)&&($media===[]||count($media)>10))return false;
                if($options['media_spoiler']&&array_diff($types,['photo','video','animation'])!==[])return false;
                if($options['paid_media_stars']!==null&&array_diff($types,['photo','video'])!==[])return false;
            }
            if($expectedInput!==null){
                if(!is_string($expectedInput['state']??null)||!is_array($expectedInput['data']??null)||(int)($expectedInput['data']['post_id']??0)!==(int)$post['id'])return false;
                $current=$this->pdo->prepare('SELECT telegram_id FROM posted_users WHERE telegram_id=:user AND is_active=1 AND state=:state AND state_data <=> CAST(:data AS JSON) LIMIT 1 FOR UPDATE');
                $current->execute(['user'=>$userId,'state'=>$expectedInput['state'],'data'=>$this->encodeJson($expectedInput['data'])]);if($current->fetchColumn()===false)return false;
                if(isset($expectedInput['data']['post_fingerprint'])&&!hash_equals((string)$expectedInput['data']['post_fingerprint'],$this->telegramSubmissionKey($post)))return false;
            }
            if(array_key_exists('preview_media',$changes)&&$changes['preview_media']!==null){
                if($this->isNativeRepost($post)||$this->postMedia($post)!==[])return false;
                $payload=$this->effectivePostPayload($post);$hasUrl=preg_match('~https?://\S+~u',(string)($payload['text']??''))===1;
                foreach((array)($payload['entities']??[])as$entity)if(is_array($entity)&&in_array($entity['type']??null,['url','text_link'],true))$hasUrl=true;
                if(!$hasUrl)return false;
            }
            PostedTelegramPostOptions::saveLocked($this->pdo,$post,$changes);
            if($expectedInput!==null){
                $consume=$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user AND state=:state AND state_data <=> CAST(:data AS JSON)');
                $consume->execute(['user'=>$userId,'state'=>$expectedInput['state'],'data'=>$this->encodeJson($expectedInput['data'])]);
                if($consume->rowCount()!==1)throw new RuntimeException('Input changed before saving post options.');
            }
            return true;
        })===true;
    }
}
