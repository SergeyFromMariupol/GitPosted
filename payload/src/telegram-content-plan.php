<?php
declare(strict_types=1);

/** Captured content-plan fields; Source-observed compact rows and ordinary media labels. */
final class PostedTelegramContentPlanUi
{
    public static function date(array $post,DateTimeZone $storage,DateTimeZone $user,bool $allowCreated=false): ?DateTimeImmutable
    {
        $keys=($post['status']??'')==='sent'?['published_at','scheduled_at']:['scheduled_at'];
        if($allowCreated)$keys[]='created_at';
        foreach($keys as$key){
            $raw=$post[$key]??null;if(!is_string($raw)||$raw==='')continue;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$storage);
            if($date!==false&&$date->format('Y-m-d H:i:s')===$raw)return$date->setTimezone($user);
        }return null;
    }

    public static function rowTime(array $post,DateTimeZone $storage,DateTimeZone $user): string
    {return self::date($post,$storage,$user)?->format('G:i')??'--:--';}

    public static function russianDate(DateTimeImmutable $date): string
    {
        $months=[1=>'января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
        return $date->format('j').' '.$months[(int)$date->format('n')].' '.$date->format('Y').' г. в '.$date->format('H:i');
    }

    public static function scheduledPhrase(int $count): string
    {
        if($count<0)throw new InvalidArgumentException('Invalid post count.');
        $one=$count%10===1&&$count%100!==11;
        $word=$one?'пост':($count%10>=2&&$count%10<=4&&($count%100<12||$count%100>14)?'поста':'постов');
        return ($one?'запланирован ':'запланировано ').$count.' '.$word;
    }

    public static function effectiveDateSql(string $prefix='p.'): string
    {
        if(!in_array($prefix,['p.',''],true))throw new InvalidArgumentException('Invalid fixed SQL alias.');
        return "CASE WHEN {$prefix}status='sent' THEN COALESCE({$prefix}published_at,{$prefix}scheduled_at,{$prefix}created_at) ELSE COALESCE({$prefix}scheduled_at,{$prefix}created_at) END";
    }

    /** Only ordinary single unpaid photo, compact OFF/icons ON, has been observed. */
    public static function photoMarker(array $post,array $settings,array $media,array $options): string
    {
        $compact=array_key_exists('setting_compact_plan',$settings)?$settings['setting_compact_plan']:0;
        $icons=array_key_exists('setting_show_plan_icons',$settings)?$settings['setting_show_plan_icons']:1;
        if(!in_array($compact,[0,'0',false],true)||!in_array($icons,[1,'1',true],true))return'';
        if(!empty($post['repost_chat_id'])||!empty($post['repost_message_id'])||($options['paid_media_stars']??null)!==null)return'';
        return count($media)===1&&($media[0]['type']??null)==='photo'?'📷':'';
    }

    /** The caller supplies the existing excerpt. This method does not truncate user text. */
    public static function row(array $post,string $excerpt,DateTimeZone $storage,DateTimeZone $user,string $photoMarker=''): string
    {
        if(!in_array($photoMarker,['','📷'],true))throw new InvalidArgumentException('Unknown captured media marker.');
        $status=match((string)($post['status']??'')){'sent'=>'✅','telegram_pending'=>'⌛','failed'=>'⚠️','draft'=>'📝',default=>'⏳'};
        return implode(' ',array_values(array_filter([self::rowTime($post,$storage,$user),$status,$photoMarker,$excerpt],static fn(string$item):bool=>$item!=='')));
    }

    /** Observed Source heading; UTF-16 offsets apply only to entities. */
    public static function advancedHeader(): array
    {
        return ['text'=>"🎓 ПРОДВИНУТЫЕ НАСТРОЙКИ\n\nВ этом разделе вы можете настроить продвинутые функции бота.",
            'entities'=>[['type'=>'custom_emoji','offset'=>0,'length'=>2,'custom_emoji_id'=>'5258334872878980409'],['type'=>'bold','offset'=>3,'length'=>21]]];
    }

    public static function compact(array $settings): bool
    {return in_array($settings['setting_compact_plan']??0,[1,'1',true],true);}

    public static function excerpt(string $text): string
    {return mb_substr(str_replace(["\r","\n"],'',trim($text)),0,22,'UTF-8');}

    /** Ordinary stored media only. Paid/native-forward display keeps the old caller path. */
    public static function mediaPresentation(array $post,array $media,?array $native=null): array
    {
        $kind=(string)($post['media_type']??'none');$text=(string)($post['text']??'');
        $type=(string)($media[0]['type']??$kind);
        $marker=match($type){'photo'=>'📷','video'=>'📹','animation'=>'🎬','document','audio'=>'📎','voice'=>'🎤',default=>''};
        if(count($media)>=2&&$marker!=='')$marker.=' '.$marker.' ';
        if($kind==='video_note'){$marker='📺';if($text==='')$text='Видеосообщение';}
        elseif($kind==='location'){$marker='📍';if($text==='')$text='Локация';}
        elseif($kind==='contact'){$marker='👤';if($text==='')$text='Контакт';}
        elseif($kind==='poll'){$marker='📊';if($text==='')$text=is_array($native)&&($native['kind']??null)==='poll'?(string)($native['payload']['question']??'Опрос'):'Опрос';}
        elseif($kind==='sticker'){
            // Core v1 deliberately stores no sticker emoji: do not invent Source's 🧪.
            $marker='';if($text==='')$text='Стикер';
        }
        return ['marker'=>$marker,'text'=>$text];
    }

    public static function capturedRow(array $post,string $text,string $marker,DateTimeZone $storage,DateTimeZone $user,bool $compact): string
    {
        $status=match((string)($post['status']??'')){'sent'=>'✅','telegram_pending'=>'⌛','failed'=>'⚠️','draft'=>'📝',default=>'⏳'};
        $time=self::rowTime($post,$storage,$user);
        if($compact)return $time.$status.' ';
        $excerpt=self::excerpt($text);if($excerpt==='')$excerpt='[медиа]';
        return $time.' '.$status.' '.($marker!==''?$marker.' ':'').$excerpt;
    }

    public static function postRows(array $buttons,bool $compact): array
    {return array_chunk($buttons,$compact?4:1);}

    public static function publishedDetail(array $post,string $link,DateTimeZone $storage,DateTimeZone $user): array
    {
        if(($post['status']??null)!=='sent')throw new InvalidArgumentException('Published detail requires an actually sent post.');
        if($link!==''&&preg_match('~\At\.me/c/[1-9][0-9]*/[1-9][0-9]*\z~D',$link)!==1)throw new InvalidArgumentException('Detail link must come from this stored channel message.');
        $text='';$entities=[];
        $append=static function(string $value,?string $type=null,?string $emoji=null)use(&$text,&$entities):void{
            $offset=self::utf16Length($text);$text.=$value;if($type===null)return;
            $entity=['type'=>$type,'offset'=>$offset,'length'=>self::utf16Length($value)];if($emoji!==null)$entity['custom_emoji_id']=$emoji;$entities[]=$entity;
        };
        $append('Статус:','bold');$append(' ');$append('👀','custom_emoji','5260341314095947411');$append(' Опубликован');
        if($link!==''){$append("\n");$append('Ссылка:','bold');$append(' ');$append($link,'url');}
        $date=self::date($post,$storage,$user,true);
        if($date!==null){$append("\n");$append('Дата:','bold');$append(' '.self::russianDate($date));}
        return ['text'=>$text,'entities'=>$entities];
    }

    private static function utf16Length(string $value): int
    {
        $points=preg_match_all('/./us',$value);$pairs=preg_match_all('/[\xF0-\xF4][\x80-\xBF]{3}/',$value);
        if($points===false||$pairs===false)throw new InvalidArgumentException('Invalid UTF-8 detail.');return$points+$pairs;
    }
}

trait GitPostedTelegramContentPlanUi
{
    private function contentPlanRowTime(int $userId,array $post): string
    {return PostedTelegramContentPlanUi::rowTime($post,$this->storageTimezone(),$this->userTimezoneObject($userId));}

    private function contentPlanPhotoMarker(int $userId,array $post): string
    {
        if(($post['media_type']??'none')!=='photo')return'';
        return PostedTelegramContentPlanUi::photoMarker($post,$this->user($userId)??[],$this->postMedia($post),$this->telegramPostOptions($post));
    }

    /** Current ACL is established by the unchanged content-plan channel query. */
    private function contentPlanMediaPresentation(array $post): array
    {
        $native=null;
        if(($post['media_type']??null)==='poll'&&class_exists('PostedTelegramNativeStore',false)){
            try{$frozen=PostedTelegramNativeStore::freeze($this->pdo,$post);$native=$frozen['_telegram_native_content']??null;}catch(Throwable){/* A missing/corrupt display hint cannot erase this existing CP row. */}
        }
        return PostedTelegramContentPlanUi::mediaPresentation($post,$this->postMedia($post),$native);
    }

    private function contentPlanPublishedDetail(int $userId,array $post): array
    {return PostedTelegramContentPlanUi::publishedDetail($post,$this->telegramPostLink($post),$this->storageTimezone(),$this->userTimezoneObject($userId));}

    /** Reuses the existing status, single-message and locked manage_posts checks. */
    private function openContentPlanPublishedEditor(int $userId,int $postId): void
    {
        $post=$this->post($postId);
        if(!$post||($post['status']??null)!=='sent'||!$this->can($userId,(int)$post['channel_id'],'manage_posts')||(!$this->isSingleMessageEditableTelegramPost($post)&&!$this->telegramPublishedAlbumEditable($post))){
            $this->telegram->sendMessage($userId,'Пост недоступен для изменения или у вас нет необходимых прав.');return;
        }
        if(!$this->startEditSession($userId,$postId,false)){$this->telegram->sendMessage($userId,'Не удалось открыть редактор. Откройте пост заново.');return;}
        $this->showEditPostActions($userId,$postId,'Пост принят на изменение.');
    }
}
