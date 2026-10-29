<?php

declare(strict_types=1);

/** Validated catalogue filters and Bot API metadata; no transport is used here. */
final class PostedTelegramEmojiCatalog
{
    public const CATEGORIES = ['quality', 'fonts', 'icons', 'brands', 'statuses', 'memes'];
    public const TYPES = ['any', 'animated', 'static', 'video'];
    public const TRI_STATES = ['any', 'no', 'yes'];

    public static function defaults(): array
    {
        return ['version'=>1, 'type'=>'any', 'adaptive'=>'any', 'big'=>'any'];
    }

    public static function stateKey(int $userId): string
    {
        if ($userId <= 0) throw new InvalidArgumentException('Invalid Telegram catalogue user.');
        return 'telegram_emoji_filters_' . $userId;
    }

    public static function stateFromJson(string $json): array
    {
        $state = json_decode($json, true);
        if (!is_array($state) || array_is_list($state) || count($state)!==4
            || ($state['version']??null)!==1
            || !in_array($state['type']??null, self::TYPES, true)
            || !in_array($state['adaptive']??null, self::TRI_STATES, true)
            || !in_array($state['big']??null, self::TRI_STATES, true)) return self::defaults();
        return ['version'=>1, 'type'=>$state['type'], 'adaptive'=>$state['adaptive'], 'big'=>$state['big']];
    }

    public static function readState(PDO $pdo, int $userId): array
    {
        $statement=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1');
        $statement->execute(['key'=>self::stateKey($userId)]);
        return self::stateFromJson((string)($statement->fetchColumn()?:''));
    }

    /** Only the catalogue preference row is locked; rendering/API happen afterwards. */
    public static function cycleState(PDO $pdo, int $userId, string $field): array
    {
        if (!in_array($field, ['type','adaptive','big'], true)) throw new InvalidArgumentException('Invalid catalogue filter.');
        $key=self::stateKey($userId);
        $ownTransaction=!$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value)
                ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)')
                ->execute(['key'=>$key,'value'=>json_encode(self::defaults(), JSON_THROW_ON_ERROR)]);
            $statement=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1 FOR UPDATE');
            $statement->execute(['key'=>$key]);
            $state=self::stateFromJson((string)($statement->fetchColumn()?:''));
            $domain=$field==='type'?self::TYPES:self::TRI_STATES;
            $index=array_search($state[$field], $domain, true);
            $state[$field]=$domain[((int)$index+1)%count($domain)];
            $pdo->prepare('UPDATE posted_app_settings SET setting_value=:value WHERE setting_key=:key')
                ->execute(['key'=>$key,'value'=>json_encode($state, JSON_THROW_ON_ERROR)]);
            if ($ownTransaction) $pdo->commit();
            return $state;
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function validSetName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name)===1;
    }

    public static function itemSetName(array $item): ?string
    {
        $name=$item['set_name']??null;
        if ($name!==null && (!is_string($name) || !self::validSetName($name))) return null;
        $url=$item['url']??null;
        if (!is_string($url)) return null;
        $parts=parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme']??'')), ['http','https'], true)
            || !in_array(strtolower((string)($parts['host']??'')), ['t.me','www.t.me'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match('~^/addemoji/([A-Za-z][A-Za-z0-9_]{0,63})/?$~D', (string)($parts['path']??''), $matches)!==1) return null;
        if (is_string($name) && strcasecmp($name,$matches[1])!==0) return null;
        return strtolower($matches[1]);
    }

    public static function metadataKey(string $setName): string
    {
        if (!self::validSetName($setName)) throw new InvalidArgumentException('Invalid custom emoji set name.');
        // posted_app_settings.setting_key is VARCHAR(64); identity is also checked in JSON.
        return 'telegram_emoji_meta_' . substr(hash('sha256', strtolower($setName)), 0, 44);
    }

    /**
     * The modern Bot API permits mixed-format sets and reports format per Sticker.
     * An omitted optional needs_repainting means false; non-boolean flags fail closed.
     * Mixed format/repainting is represented as unknown for that particular criterion.
     */
    public static function metadataFromStickerSet(array $set, ?string $expectedName = null): ?array
    {
        $name=$set['name']??null;
        $stickers=$set['stickers']??null;
        if (!is_string($name) || !self::validSetName($name)
            || ($expectedName!==null && (!self::validSetName($expectedName) || strcasecmp($name,$expectedName)!==0))
            || ($set['sticker_type']??null)!=='custom_emoji'
            || !is_array($stickers) || !array_is_list($stickers) || count($stickers)<1 || count($stickers)>10000) return null;
        $formats=[];$repainting=[];
        foreach ($stickers as $sticker) {
            if (!is_array($sticker) || ($sticker['type']??null)!=='custom_emoji'
                || !is_bool($sticker['is_animated']??null) || !is_bool($sticker['is_video']??null)
                || ($sticker['is_animated'] && $sticker['is_video'])
                || !is_string($sticker['custom_emoji_id']??null)
                || preg_match('/^[1-9][0-9]{0,19}$/D', $sticker['custom_emoji_id'])!==1
                || (array_key_exists('needs_repainting',$sticker) && !is_bool($sticker['needs_repainting']))) return null;
            if (isset($sticker['set_name']) && (!is_string($sticker['set_name']) || strcasecmp($sticker['set_name'],$name)!==0)) return null;
            $format=$sticker['is_animated']?'animated':($sticker['is_video']?'video':'static');
            $formats[$format]=true;
            $repainting[!empty($sticker['needs_repainting'])?'yes':'no']=true;
        }
        if (array_key_exists('sticker_count',$set) && (!is_int($set['sticker_count']) || $set['sticker_count']!==count($stickers))) return null;
        return ['version'=>1, 'set_name'=>strtolower($name),
            'type'=>count($formats)===1?array_key_first($formats):null,
            'adaptive'=>count($repainting)===1?isset($repainting['yes']):null,
            'count'=>count($stickers)];
    }

    public static function metadataFromJson(string $json, string $expectedName): ?array
    {
        if (strlen($json)>4096 || !self::validSetName($expectedName)) return null;
        $metadata=json_decode($json,true);
        if (!is_array($metadata) || array_is_list($metadata) || count($metadata)!==5
            || ($metadata['version']??null)!==1 || !is_string($metadata['set_name']??null)
            || !self::validSetName($metadata['set_name']) || strcasecmp($metadata['set_name'],$expectedName)!==0
            || !array_key_exists('type',$metadata) || !array_key_exists('adaptive',$metadata)
            || ($metadata['type']!==null && !in_array($metadata['type'],['animated','static','video'],true))
            || ($metadata['adaptive']!==null && !is_bool($metadata['adaptive']))
            || !is_int($metadata['count']??null) || $metadata['count']<1 || $metadata['count']>10000) return null;
        return ['version'=>1, 'set_name'=>strtolower($metadata['set_name']), 'type'=>$metadata['type'],
            'adaptive'=>$metadata['adaptive'], 'count'=>$metadata['count']];
    }

    public static function hasFilter(array $state): bool
    {
        return $state['type']!=='any' || $state['adaptive']!=='any' || $state['big']!=='any';
    }

    /** null means the selected criterion cannot be confirmed from known metadata. */
    public static function matches(?array $metadata, array $state): ?bool
    {
        if (!self::hasFilter($state)) return true;
        if ($metadata===null) return null;
        $unknown=false;
        if ($state['type']!=='any') {
            if ($metadata['type']===null) $unknown=true;
            elseif ($metadata['type']!==$state['type']) return false;
        }
        if ($state['adaptive']!=='any') {
            if ($metadata['adaptive']===null) $unknown=true;
            elseif ($metadata['adaptive']!==($state['adaptive']==='yes')) return false;
        }
        if ($state['big']!=='any' && ($metadata['count']>=100)!==($state['big']==='yes')) return false;
        return $unknown?null:true;
    }

    public static function text(string $language, string $key): string
    {
        $strings=[
            'type.any'=>['Тип: любые','Type: any'],
            'type.animated'=>['Тип: анимированные','Type: animated'],
            'type.static'=>['Тип: статичные','Type: static'],
            'type.video'=>['Тип: видеоэмодзи','Type: video emoji'],
            'adaptive'=>['Адаптивные','Adaptive'], 'big'=>['Большие наборы','Big sets'],
            'empty'=>['В этой категории пока нет наборов.','There are no sets in this category yet.'],
            'no_match'=>['Нет наборов с выбранными фильтрами.','No sets match the selected filters.'],
            'pending'=>['Часть наборов пока не проверена для выбранных фильтров. Показаны только проверенные наборы.',
                'Some sets have not been checked for the selected filters yet. Only verified sets are shown.'],
        ];
        if (!isset($strings[$key])) throw new InvalidArgumentException('Unknown catalogue text.');
        return $strings[$key][$language==='en'?1:0];
    }
}

/** Uses PostedBot's actual catalogue, preview cache, language preference and transport. */
trait PostedTelegramEmojiCatalogUi
{
    private function handleTelegramEmojiFilterCallback(int $userId, string $data, ?array $target = null): bool
    {
        if (preg_match('/^emoji_filter:(type|adaptive|big):(quality|fonts|icons|brands|statuses|memes)$/D',$data,$matches)!==1) return false;
        PostedTelegramEmojiCatalog::cycleState($this->pdo,$userId,$matches[1]);
        $this->renderTelegramEmojiCatalog($userId,$matches[2],1,$target,true);
        return true;
    }

    private function telegramEmojiMetadata(array $items): array
    {
        $names=[];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name=PostedTelegramEmojiCatalog::itemSetName($item);
            if ($name!==null) $names[PostedTelegramEmojiCatalog::metadataKey($name)]=$name;
        }
        $metadata=[];
        foreach (array_chunk($names,200,true) as $chunk) {
            $placeholders=[];$parameters=[];
            foreach (array_keys($chunk) as $index=>$key) {
                $placeholder='metadata'.$index;$placeholders[]=':'.$placeholder;$parameters[$placeholder]=$key;
            }
            $statement=$this->pdo->prepare('SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key IN ('.implode(',',$placeholders).')');
            $statement->execute($parameters);
            foreach ($statement->fetchAll() as $row) {
                $name=$chunk[$row['setting_key']]??null;
                if ($name===null) continue;
                $value=PostedTelegramEmojiCatalog::metadataFromJson((string)$row['setting_value'],$name);
                if ($value!==null) $metadata[$name]=$value;
            }
        }
        return $metadata;
    }

    private function renderTelegramEmojiCatalog(int $userId, string $category = 'quality', int $page = 1, ?array $target = null, bool $showFilters = false): void
    {
        $categories=$this->emojiCategories();
        if (!isset($categories[$category])) $category='quality';
        $items=$this->emojiCatalog()[$category]??[];
        $state=PostedTelegramEmojiCatalog::readState($this->pdo,$userId);
        $filtered=PostedTelegramEmojiCatalog::hasFilter($state);
        $pending=0;
        if ($filtered) {
            $metadata=$this->telegramEmojiMetadata($items);$matching=[];
            foreach ($items as $item) {
                $name=PostedTelegramEmojiCatalog::itemSetName($item);
                $match=PostedTelegramEmojiCatalog::matches($name===null?null:($metadata[$name]??null),$state);
                if ($match===true) $matching[]=$item;
                elseif ($match===null) $pending++;
            }
            $items=$matching;
        }
        $totalPages=max(1,(int)ceil(count($items)/10));
        $page=min(max(1,$page),$totalPages);
        $pageItems=array_slice($items,($page-1)*10,10);
        $language=$this->telegramUiLanguage($userId);
        $lines=[$this->telegramUiText($userId,'catalog.title',$this->customEmojiHtml('5258328383183396223','📖').' <b>КАТАЛОГ ЭМОДЗИ</b>')];
        if ($pageItems===[]) {
            $lines[]='';$lines[]=PostedTelegramEmojiCatalog::text($language,$filtered?'no_match':'empty');
        } else {
            foreach ($pageItems as $item) {
                $details=$this->emojiSetDetails($item);
                $lines[]='';
                $lines[]=$details['preview_html'].'  <a href="'.htmlspecialchars((string)$item['url'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'
                    .$this->telegramUiText($userId,'catalog.open','Открыть').'</a> ['.(int)$details['count'].']';
            }
        }
        if ($pending>0) {$lines[]='';$lines[]=PostedTelegramEmojiCatalog::text($language,'pending');}
        $keyboard=[];$categoryButtons=[];
        foreach ($categories as $key=>$title) {
            $button=['text'=>$this->telegramUiText($userId,'catalog.'.$key,$title),'callback_data'=>'emoji:'.$key.':1'.($showFilters?':more':'')];
            if ($key===$category) $button['style']='primary';
            $categoryButtons[]=$button;
        }
        foreach (array_chunk($categoryButtons,2) as $row) $keyboard[]=$row;
        if ($showFilters) {
            $keyboard[]=[['text'=>PostedTelegramEmojiCatalog::text($language,'type.'.$state['type']),'callback_data'=>'emoji_filter:type:'.$category]];
            foreach (['adaptive','big'] as $field) {
                $icon=match($state[$field]) {'no'=>'☑️','yes'=>'✅️',default=>'❔'};
                $keyboard[]=[['text'=>$icon.' '.PostedTelegramEmojiCatalog::text($language,$field),'callback_data'=>'emoji_filter:'.$field.':'.$category]];
            }
        } else {
            $keyboard[]=[['text'=>$this->telegramUiText($userId,'catalog.more','↓ Больше фильтров'),'callback_data'=>'emoji:'.$category.':'.$page.':more']];
        }
        $suffix=$showFilters?':more':'';
        $keyboard[]=[
            ['text'=>'<','callback_data'=>'emoji:'.$category.':'.($page>1?$page-1:$totalPages).$suffix],
            ['text'=>$page.' / '.$totalPages,'callback_data'=>'emoji:'.$category.':'.$page.$suffix],
            ['text'=>'>','callback_data'=>'emoji:'.$category.':'.($page<$totalPages?$page+1:1).$suffix],
        ];
        $this->sendOrEditMessage($userId,implode("\n",$lines),[
            'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$keyboard],
        ],$target);
    }
}
