<?php

declare(strict_types=1);

/** Immutable, intentionally public-by-capability link-preview media outside webroot. */
final class PostedTelegramPreviewMedia
{
    public const MAX_BYTES = 5242880;
    private const MIME_EXTENSIONS = [
        'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif',
        'video/mp4'=>'mp4', 'video/webm'=>'webm', 'application/pdf'=>'pdf', 'text/plain'=>'txt',
    ];

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{64}$/D',$id)===1;
    }

    public static function reference(string $id): array
    {
        if (!self::validId($id)) throw new InvalidArgumentException('Некорректное медиа превью.');
        return ['version'=>1,'asset_id'=>$id];
    }

    private static function root(array $config, bool $create): string
    {
        $base=rtrim((string)($config['data_dir']??''),'/');
        if ($base==='' || !str_starts_with($base,'/') || realpath($base)!==$base || is_link($base) || !is_dir($base)) throw new RuntimeException('Хранилище превью недоступно.');
        $storage=$base.'/storage';$root=$storage.'/telegram-preview-media';
        foreach ([$storage,$root] as $directory) {
            if (!file_exists($directory) && $create && !mkdir($directory,0700)) throw new RuntimeException('Хранилище превью недоступно.');
            if (!is_dir($directory) || is_link($directory) || realpath($directory)!==$directory) throw new RuntimeException('Хранилище превью недоступно.');
        }
        if (($create && !chmod($root,0700)) || (fileperms($root)&0777)!==0700) throw new RuntimeException('Хранилище превью недоступно.');
        return $root;
    }

    public static function publicUrl(array $config, string $id): string
    {
        if (!self::validId($id)) throw new InvalidArgumentException('Некорректное медиа превью.');
        $base=rtrim((string)($config['base_url']??''),'/');$parts=parse_url($base);
        if (!is_array($parts) || ($parts['scheme']??null)!=='https' || !is_string($parts['host']??null)
            || filter_var($base,FILTER_VALIDATE_URL)===false || preg_match('/[\x00-\x20\x7f\\\\]/',$base)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port']!==443)
            || preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D',(string)($parts['path']??''))!==1) throw new RuntimeException('Публичный адрес превью не настроен.');
        return $base.'/?preview_media='.$id;
    }

    private static function verifiedMime(string $bytes, string $kind): string
    {
        if ($bytes==='' || strlen($bytes)>self::MAX_BYTES || !in_array($kind,['photo','video','animation','document'],true)) throw new InvalidArgumentException('Отправьте медиа весом не более 5 МБ.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) throw new InvalidArgumentException('Этот формат медиа не поддерживается для превью.');
        $allowed=match($kind) {
            'photo'=>['image/jpeg','image/png','image/webp'],
            'video'=>['video/mp4','video/webm'],
            'animation'=>['image/gif','video/mp4','video/webm'],
            default=>array_keys(self::MIME_EXTENSIONS),
        };
        if (!in_array($mime,$allowed,true)) throw new InvalidArgumentException('Формат файла не соответствует медиа.');
        if (str_starts_with($mime,'image/')) {
            $image=@getimagesizefromstring($bytes);
            if (!is_array($image) || ($image[0]??0)<1 || ($image[1]??0)<1 || ($image['mime']??null)!==$mime) throw new InvalidArgumentException('Изображение повреждено.');
        }
        return $mime;
    }

    private static function writeExclusive(string $path, string $bytes, array &$owned): void
    {
        $handle=@fopen($path,'xb');
        if ($handle===false) throw new RuntimeException('Не удалось сохранить медиа превью.');
        $owned[$path]=true;
        try {
            if (!chmod($path,0600)) throw new RuntimeException('Не удалось закрыть медиа превью.');
            $offset=0;$length=strlen($bytes);
            while ($offset<$length) {
                $written=fwrite($handle,substr($bytes,$offset));
                if (!is_int($written) || $written<1) throw new RuntimeException('Не удалось сохранить медиа превью.');
                $offset+=$written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Не удалось сохранить медиа превью.');
        } finally {fclose($handle);}
    }

    public static function persist(array $config, string $bytes, string $kind): array
    {
        $mime=self::verifiedMime($bytes,$kind);$root=self::root($config,true);
        $free=disk_free_space($root);
        if ($free===false || $free<strlen($bytes)+67108864) throw new RuntimeException('Недостаточно места для медиа превью.');
        $id=bin2hex(random_bytes(32));$blob=$root.'/'.$id.'.blob';$meta=$root.'/'.$id.'.json';
        $temporaryBlob=$root.'/.'.$id.'.blob.tmp';$temporaryMeta=$root.'/.'.$id.'.json.tmp';
        $manifest=['version'=>1,'asset_id'=>$id,'sha256'=>hash('sha256',$bytes),'size'=>strlen($bytes),'mime'=>$mime,'created_at'=>time()];
        $published=false;$owned=[];
        try {
            self::writeExclusive($temporaryBlob,$bytes,$owned);
            self::writeExclusive($temporaryMeta,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$owned);
            // Atomic no-replace publication: an existing capability is never overwritten.
            if (!@link($temporaryBlob,$blob)) throw new RuntimeException('Не удалось сохранить медиа превью.');
            $owned[$blob]=true;
            if (!@link($temporaryMeta,$meta)) throw new RuntimeException('Не удалось сохранить медиа превью.');
            $owned[$meta]=true;
            $published=true;
        } finally {
            foreach ([$temporaryBlob,$temporaryMeta] as $temporary) {
                if (isset($owned[$temporary]) && is_file($temporary) && !is_link($temporary) && realpath($temporary)===$temporary && dirname($temporary)===$root) unlink($temporary);
            }
            if (!$published) foreach ([$blob,$meta] as $partial) {
                if (isset($owned[$partial]) && is_file($partial) && !is_link($partial) && realpath($partial)===$partial && dirname($partial)===$root) unlink($partial);
            }
        }
        return self::reference($id);
    }

    public static function read(array $config, string $id): array
    {
        if (!self::validId($id)) throw new RuntimeException('Медиа не найдено.');
        $root=self::root($config,false);$blob=$root.'/'.$id.'.blob';$meta=$root.'/'.$id.'.json';
        foreach ([$blob,$meta] as $path) {
            if (!is_file($path) || is_link($path) || realpath($path)!==$path || dirname($path)!==$root || (fileperms($path)&0777)!==0600) throw new RuntimeException('Медиа не найдено.');
        }
        if (filesize($meta)>2048) throw new RuntimeException('Медиа не найдено.');
        $manifest=json_decode((string)file_get_contents($meta),true);
        if (!is_array($manifest) || count($manifest)!==6 || ($manifest['version']??null)!==1 || ($manifest['asset_id']??null)!==$id
            || !is_string($manifest['sha256']??null) || preg_match('/^[a-f0-9]{64}$/D',$manifest['sha256'])!==1
            || !is_int($manifest['size']??null) || $manifest['size']<1 || $manifest['size']>self::MAX_BYTES
            || !is_string($manifest['mime']??null) || !isset(self::MIME_EXTENSIONS[$manifest['mime']])
            || !is_int($manifest['created_at']??null) || $manifest['created_at']<1
            || filesize($blob)!==$manifest['size'] || !hash_equals($manifest['sha256'],(string)hash_file('sha256',$blob))
            || (new finfo(FILEINFO_MIME_TYPE))->file($blob)!==$manifest['mime']) throw new RuntimeException('Медиа не найдено.');
        return $manifest+['path'=>$blob];
    }

    /** The only accepted input is a file identifier from a verified Telegram message. */
    public static function descriptor(array $message): ?array
    {
        if (!empty($message['media_group_id'])) return null;
        $kind=null;$file=null;
        if (isset($message['photo']) && is_array($message['photo']) && array_is_list($message['photo']) && $message['photo']!==[]) {
            $photos=array_values(array_filter($message['photo'],'is_array'));
            usort($photos,static fn(array $a,array $b):int=>((int)($a['width']??0)*(int)($a['height']??0))<=>((int)($b['width']??0)*(int)($b['height']??0)));
            $file=$photos===[]?null:$photos[count($photos)-1];$kind='photo';
        } else foreach (['video','animation','document'] as $candidate) {
            if (isset($message[$candidate]) && is_array($message[$candidate])) {$kind=$candidate;$file=$message[$candidate];break;}
        }
        if (!is_array($file) || !is_string($file['file_id']??null) || preg_match('/^[A-Za-z0-9_-]{10,1024}$/D',$file['file_id'])!==1) return null;
        $size=$file['file_size']??null;
        if ($size!==null && (!is_int($size) || $size<1 || $size>self::MAX_BYTES)) throw new InvalidArgumentException('Отправьте медиа весом не более 5 МБ.');
        return ['kind'=>$kind,'file_id'=>$file['file_id'],'size'=>$size];
    }

    /** Only a newly created, undisclosed asset after a definitive failed bind. Never detach/GC shared references here. */
    public static function discardUnpublished(array $config, array $reference): void
    {
        $id=$reference['asset_id']??null;
        if (!self::validId($id)) return;
        self::read($config,$id);$root=self::root($config,false);
        foreach (['.json','.blob'] as $extension) {
            $path=$root.'/'.$id.$extension;
            if (is_file($path) && !is_link($path) && realpath($path)===$path && dirname($path)===$root) unlink($path);
        }
    }

    public static function filePath(array $response, array $descriptor): string
    {
        $file=$response['result']??null;
        if (($response['ok']??null)!==true || !is_array($file) || !is_string($file['file_id']??null)
            || !hash_equals($descriptor['file_id'],$file['file_id']) || !is_string($file['file_path']??null)
            || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$~D',$file['file_path'])!==1
            || strlen($file['file_path'])>512 || count(array_intersect(explode('/',$file['file_path']),['.','..']))>0) throw new RuntimeException('Не удалось загрузить медиа превью.');
        $size=$file['file_size']??null;
        if ($size!==null && (!is_int($size) || $size<1 || $size>self::MAX_BYTES
            || ($descriptor['size']!==null && $size!==$descriptor['size']))) throw new RuntimeException('Не удалось загрузить медиа превью.');
        return $file['file_path'];
    }

    /** Fixed Telegram file host, no redirects, bounded memory; credentials stay in memory. */
    public static function fetchBytes(array $config, string $filePath): string
    {
        $token=$config['bot_token']??null;
        if (!is_string($token) || preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,100}$/D',$token)!==1
            || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)+$~D',$filePath)!==1 || strlen($filePath)>512
            || count(array_intersect(explode('/',$filePath),['.','..']))>0 || !function_exists('curl_exec')) throw new RuntimeException('Не удалось загрузить медиа превью.');
        $handle=curl_init('https://api.telegram.org/file/bot'.$token.'/'.$filePath);$bytes='';
        if ($handle===false) throw new RuntimeException('Не удалось загрузить медиа превью.');
        try {
            curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($unused,string $chunk)use(&$bytes):int {
                    if(strlen($bytes)+strlen($chunk)>self::MAX_BYTES)return 0;
                    $bytes.=$chunk;return strlen($chunk);
                }]);
            if (curl_exec($handle)!==true || curl_errno($handle)!==0 || (int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200
                || $bytes==='' || strlen($bytes)>self::MAX_BYTES) throw new RuntimeException('Не удалось загрузить медиа превью.');
        } finally {curl_close($handle);}
        return $bytes;
    }

    /** Actual endpoint and fixture use the same validated response/range plan. */
    public static function responsePlan(array $config, string $id, string $method, ?string $range = null): array
    {
        $headers=['X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>"default-src 'none'; sandbox"];
        if (!in_array($method,['GET','HEAD'],true)) return ['status'=>405,'headers'=>$headers+['Allow'=>'GET, HEAD','Content-Length'=>'0'],'path'=>null,'offset'=>0,'length'=>0];
        try {$asset=self::read($config,$id);} catch(Throwable) {return ['status'=>404,'headers'=>$headers+['Content-Length'=>'0'],'path'=>null,'offset'=>0,'length'=>0];}
        $size=$asset['size'];$start=0;$end=$size-1;$status=200;
        if ($range!==null) {
            if (preg_match('/^bytes=([0-9]*)-([0-9]*)$/D',$range,$match)!==1 || ($match[1]===''&&$match[2]==='')
                || strlen($match[1])>10 || strlen($match[2])>10) return ['status'=>416,'headers'=>$headers+['Content-Range'=>'bytes */'.$size,'Content-Length'=>'0'],'path'=>null,'offset'=>0,'length'=>0];
            if ($match[1]==='') {$suffix=(int)$match[2];$start=max(0,$size-$suffix);if($suffix===0)$start=$size;}
            else {$start=(int)$match[1];if($match[2]!=='')$end=min($end,(int)$match[2]);}
            if ($start>$end || $start>=$size) return ['status'=>416,'headers'=>$headers+['Content-Range'=>'bytes */'.$size,'Content-Length'=>'0'],'path'=>null,'offset'=>0,'length'=>0];
            $status=206;$headers['Content-Range']='bytes '.$start.'-'.$end.'/'.$size;
        }
        $headers+=['Content-Type'=>$asset['mime'],'Content-Disposition'=>'inline; filename="preview.'.self::MIME_EXTENSIONS[$asset['mime']].'"',
            'Cache-Control'=>'public, max-age=31536000, immutable','Accept-Ranges'=>'bytes','Content-Length'=>(string)($end-$start+1)];
        return ['status'=>$status,'headers'=>$headers,'path'=>$asset['path'],'offset'=>$start,'length'=>$end-$start+1];
    }

    public static function serve(array $config, string $id, string $method, ?string $range = null): never
    {
        $plan=self::responsePlan($config,$id,$method,$range);http_response_code($plan['status']);
        foreach ($plan['headers'] as $name=>$value) header($name.': '.$value);
        if ($method==='HEAD' || $plan['path']===null) exit;
        $handle=fopen($plan['path'],'rb');
        if ($handle===false || fseek($handle,$plan['offset'])!==0) exit;
        try {$left=$plan['length'];while($left>0 && !feof($handle)){$chunk=fread($handle,min(65536,$left));if(!is_string($chunk)||$chunk==='')break;echo $chunk;$left-=strlen($chunk);}}
        finally {fclose($handle);}
        exit;
    }
}

trait PostedTelegramPreviewMediaUi
{
    private function telegramPreviewMediaEligible(array $post): bool
    {
        if ($this->isNativeRepost($post) || $this->postMedia($post)!==[]) return false;
        $payload=$this->effectivePostPayload($post);$text=(string)($payload['text']??'');
        if ($text==='' || $this->telegramUtf16Length($text)>4096) return false;
        preg_match_all('~https?://[^\s<>]+~ui',$text,$matches);
        $urls=$matches[0]??[];
        foreach ((array)($payload['entities']??[]) as $entity) {
            if (is_array($entity) && ($entity['type']??null)==='text_link' && is_string($entity['url']??null)) $urls[]=$entity['url'];
        }
        foreach ($urls as $url) {
            $parts=parse_url($url);
            if (is_array($parts) && in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true) && !empty($parts['host'])) return true;
        }
        return false;
    }

    private function telegramPreviewMediaInputData(array $data): ?array
    {
        if (count($data)!==6 || ($data['version']??null)!==1
            || !is_int($data['post_id']??null) || $data['post_id']<1
            || !is_int($data['channel_id']??null) || $data['channel_id']<1
            || !is_string($data['token']??null) || preg_match('/^[a-f0-9]{24}$/D',$data['token'])!==1
            || !is_int($data['expires_at']??null) || $data['expires_at']<time() || $data['expires_at']>time()+900
            || !is_string($data['post_fingerprint']??null) || preg_match('/^[a-f0-9]{64}$/D',$data['post_fingerprint'])!==1) return null;
        return $data;
    }

    private function telegramPreviewMediaSameInput(array $data, ?array $user): bool
    {
        if (!is_array($user) || ($user['state']??null)!=='await_draft_preview_media') return false;
        $stored=$this->stateData($user);
        if ($this->telegramPreviewMediaInputData($stored)===null) return false;
        ksort($stored);$expected=$data;ksort($expected);
        return $stored===$expected;
    }

    private function beginTelegramDraftPreviewMedia(int $userId, int $postId, ?array $target = null): bool
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Медиа превью требует отдельного запроса.');
        $observed=$this->user($userId);$post=$this->mutableDraft($userId,$postId);
        if (!$post || !$observed || !$this->telegramPreviewMediaEligible($post)) return false;
        $input=['version'=>1,'post_id'=>$postId,'channel_id'=>(int)$post['channel_id'],'token'=>bin2hex(random_bytes(12)),
            'expires_at'=>time()+600,'post_fingerprint'=>$this->telegramSubmissionKey($post)];
        $claimed=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function(array $lockedPost,array $lockedChannel)use($userId,$observed,$input):bool {
            $lockedPost['chat_id']=(string)$lockedChannel['chat_id'];
            if (!$this->telegramPreviewMediaEligible($lockedPost) || (int)$lockedPost['channel_id']!==$input['channel_id']
                || !hash_equals($input['post_fingerprint'],$this->telegramSubmissionKey($lockedPost))) return false;
            $statement=$this->pdo->prepare("UPDATE posted_users SET state='await_draft_preview_media',state_data=:input
                WHERE telegram_id=:user AND state <=> :observed_state AND state_data <=> CAST(:observed_data AS JSON)");
            $statement->execute(['input'=>$this->encodeJson($input),'user'=>$userId,
                'observed_state'=>$observed['state']??null,'observed_data'=>$observed['state_data']??null]);
            return $statement->rowCount()===1;
        });
        if ($claimed!==true) return false;
        $text=$this->telegramUiLanguage($userId)==='en'
            ?'Send a photo, video, GIF animation or document no larger than 5 MB to attach to the preview.'
            :'Отправьте фото, видео, GIF-анимацию или документ весом не более 5 МБ для закрепления в превью.';
        $this->sendOrEditMessage($userId,$text,['reply_markup'=>['inline_keyboard'=>[[[
            'text'=>$this->telegramUiLanguage($userId)==='en'?'← Back':'← Назад',
            'callback_data'=>'preview_media:back:'.$postId.':'.$input['token'],
        ]]]]],$target);
        return true;
    }

    /** Optional trusted download closure is used by captured fixtures; never supplied by an update. */
    private function handleTelegramDraftPreviewMediaInput(int $userId, array $data, array $message, ?callable $download = null): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Медиа превью требует отдельного запроса.');
        if ((int)($message['from']['id']??0)!==$userId || (int)($message['chat']['id']??0)!==$userId || ($message['chat']['type']??null)!=='private') return;
        $input=$this->telegramPreviewMediaInputData($data);
        $post=$input===null?null:$this->mutableDraft($userId,$input['post_id']);
        if ($input===null || !$post || !$this->telegramPreviewMediaSameInput($data,$this->user($userId))
            || (int)$post['channel_id']!==$input['channel_id'] || !$this->telegramPreviewMediaEligible($post)
            || !hash_equals($input['post_fingerprint'],$this->telegramSubmissionKey($post))) {
            $this->telegram->sendMessage($userId,'Меню устарело. Откройте добавление медиа заново.');return;
        }
        $reference=null;
        try {
            $descriptor=PostedTelegramPreviewMedia::descriptor($message);
            if ($descriptor===null) throw new InvalidArgumentException('Отправьте фото, видео, GIF-анимацию или поддерживаемый документ весом не более 5 МБ.');
            $response=$this->telegram->call('getFile',['file_id'=>$descriptor['file_id']]);
            $path=PostedTelegramPreviewMedia::filePath($response,$descriptor);
            $bytes=$download===null?PostedTelegramPreviewMedia::fetchBytes($this->config,$path):$download($path,PostedTelegramPreviewMedia::MAX_BYTES);
            if (!is_string($bytes) || $bytes==='' || strlen($bytes)>PostedTelegramPreviewMedia::MAX_BYTES
                || ($descriptor['size']!==null && strlen($bytes)!==$descriptor['size'])
                || (isset($response['result']['file_size']) && strlen($bytes)!==$response['result']['file_size'])) throw new InvalidArgumentException('Размер медиа не подтверждён.');
            $reference=PostedTelegramPreviewMedia::persist($this->config,$bytes,$descriptor['kind']);
            $bound=$this->setTelegramPostOptions($userId,$input['post_id'],['preview_media'=>$reference],
                ['state'=>'await_draft_preview_media','data'=>$data],
                function(array $lockedPost)use($input):bool {
                    return $input['expires_at']>=time() && (int)$lockedPost['channel_id']===$input['channel_id']
                        && $this->telegramPreviewMediaEligible($lockedPost)
                        && hash_equals($input['post_fingerprint'],$this->telegramSubmissionKey($lockedPost));
                });
            if ($bound!==true) {
                PostedTelegramPreviewMedia::discardUnpublished($this->config,$reference);
                $this->telegram->sendMessage($userId,'Меню устарело. Откройте добавление медиа заново.');return;
            }
        } catch(InvalidArgumentException $exception) {$this->telegram->sendMessage($userId,$exception->getMessage());return;}
        catch(Throwable) {$this->telegram->sendMessage($userId,'Не удалось прикрепить медиа к превью. Попробуйте ещё раз или откройте пост заново.');return;}
        try {$this->showDraftActions($userId,$input['post_id']);}
        catch(Throwable) {
            // The reference and exact input consumption already committed. A failed
            // private preview must not replay this attachment as another draft.
        }
    }

    private function telegramPreviewMediaDetachCallback(array $post): ?string
    {
        $reference=$this->telegramPostOptions($post)['preview_media']??null;
        if (!is_array($reference) || !PostedTelegramPreviewMedia::validId($reference['asset_id']??null)) return null;
        return 'preview_media:detach:'.(int)$post['id'].':'.substr(hash('sha256',$reference['asset_id']),0,16);
    }

    private function handleTelegramPreviewMediaCallback(int $userId, string $data, ?array $target = null): bool
    {
        if (preg_match('/^preview_media:(back|detach):([1-9][0-9]{0,18}):([a-f0-9]{16}|[a-f0-9]{24})$/D',$data,$match)!==1) return false;
        $postId=(int)$match[2];
        if ($match[1]==='back') {
            $user=$this->user($userId);$input=$this->telegramPreviewMediaInputData($this->stateData($user));
            if ($input===null || $input['post_id']!==$postId || !hash_equals($input['token'],$match[3])
                || !$this->telegramPreviewMediaSameInput($input,$user)) return false;
            $cleared=$this->withTelegramManagedPostLock($userId,$postId,['draft'],function()use($userId,$user):bool {
                $statement=$this->pdo->prepare('UPDATE posted_users SET state=NULL,state_data=NULL WHERE telegram_id=:user
                    AND state=:observed_state AND state_data <=> CAST(:observed_data AS JSON)');
                $statement->execute(['user'=>$userId,'observed_state'=>'await_draft_preview_media','observed_data'=>$user['state_data']]);
                return $statement->rowCount()===1;
            });
            if ($cleared===true) $this->showDraftActions($userId,$postId,'',$target);
            return $cleared===true;
        }
        $post=$this->mutableDraft($userId,$postId);
        if (!$post || $this->telegramPreviewMediaDetachCallback($post)!==$data) return false;
        $expected=$this->telegramPostOptions($post)['preview_media'];
        $detached=$this->setTelegramPostOptions($userId,$postId,['preview_media'=>null],null,
            fn(array $locked):bool=>($this->telegramPostOptions($locked)['preview_media']??null)===$expected);
        if ($detached===true) $this->showDraftActions($userId,$postId,'',$target);
        return $detached===true;
    }

    private function telegramCustomPreviewExtra(array $post): array
    {
        $reference=$this->telegramPostOptions($post)['preview_media']??null;
        if ($reference===null || !$this->telegramPreviewMediaEligible($post)) return [];
        if (!is_array($reference) || !PostedTelegramPreviewMedia::validId($reference['asset_id']??null)) throw new RuntimeException('Медиа превью недоступно.');
        PostedTelegramPreviewMedia::read($this->config,$reference['asset_id']);
        return ['link_preview_options'=>['url'=>PostedTelegramPreviewMedia::publicUrl($this->config,$reference['asset_id'])]];
    }
}

/** Include from index.php before any output; request data never chooses an include/path. */
function gitpostedServeTelegramPreviewMediaRequest(): never
{
    header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $id=$_GET['preview_media']??null;
    if (!in_array($method,['GET','HEAD'],true)) {header('Allow: GET, HEAD');http_response_code(405);exit;}
    if (!PostedTelegramPreviewMedia::validId($id)) {http_response_code(404);exit;}
    try {
        $configPath=trim((string)(getenv('POSTED_CONFIG_FILE')?:'/etc/gitposted/config.php'));
        if ($configPath==='' || !is_file($configPath)) throw new RuntimeException('Unavailable.');
        $config=require $configPath;
        if (!is_array($config)) throw new RuntimeException('Unavailable.');
        PostedTelegramPreviewMedia::serve($config,$id,$method,is_string($_SERVER['HTTP_RANGE']??null)?$_SERVER['HTTP_RANGE']:null);
    } catch(Throwable) {http_response_code(404);exit;}
}
