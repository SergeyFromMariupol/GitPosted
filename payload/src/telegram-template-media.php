<?php
declare(strict_types=1);
require_once __DIR__ . '/telegram-caption-placement.php';
require_once __DIR__.'/telegram-caption-vector.php';
require_once __DIR__.'/telegram-native-template.php';
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Immutable template identity; authorization always comes from the current SQL owner. */
final class PostedTelegramTemplateMedia
{
    public const LIMIT = 60000;
    public const QUIET_MS = 900;
    public const TAIL_MS = 3500;
    public static function now(): int { return (int)floor(microtime(true) * 1000); }
    public static function id(mixed $id): int
    { if (!is_int($id) || $id < 1) throw new InvalidArgumentException('Invalid template identity.'); return $id; }
    public static function key(int $id): string { return 'tg_template_media_' . self::id($id); }
    public static function collectionKey(int $id): string { return 'tg_template_collect_' . self::id($id); }
    public static function previewKey(int $id): string { return 'tg_template_preview_' . self::id($id); }
    public static function inputKey(int $actor, string $group): string
    { return 'tg_template_input_' . self::id($actor) . '_' . substr(hash('sha256', $group), 0, 24); }
    public static function json(mixed $value): string
    {
        if (is_array($value)) { if (!array_is_list($value)) ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[$key] = json_decode(self::json($item), true, 64, JSON_THROW_ON_ERROR); }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function vector(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 10) throw new InvalidArgumentException('Медиа шаблона повреждены.');
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('Медиа шаблона повреждены.');
            $keys = array_keys($item); sort($keys);
            if (array_diff($keys, ['type','file_id','file_unique_id','media_group_id']) !== []
                || !in_array($item['type'] ?? null, ['photo','video','animation','document','audio','voice'], true)
                || !is_string($item['file_id'] ?? null) || $item['file_id'] === '' || strlen($item['file_id']) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $item['file_id']) === 1
                || preg_match('~\A(?:https?:|file:|attach:|/|\\\\)~i', $item['file_id']) === 1
                || (str_contains($item['file_id'], ':') && preg_match('/\Apost-link-asset:[a-f0-9]{64}\z/D', $item['file_id']) !== 1)
                || (isset($item['file_unique_id']) && (!is_string($item['file_unique_id']) || strlen($item['file_unique_id']) > 512))
                || (isset($item['media_group_id']) && (!is_string($item['media_group_id']) || $item['media_group_id'] === '' || strlen($item['media_group_id']) > 128))) {
                throw new InvalidArgumentException('Медиа шаблона повреждены или недоступны.');
            }
            $result[] = ['type'=>$item['type'],'file_id'=>$item['file_id'],'file_unique_id'=>$item['file_unique_id'] ?? null,'media_group_id'=>$item['media_group_id'] ?? null];
        }
        if (count($result) > 1) {
            $types = array_values(array_unique(array_column($result, 'type')));
            if (array_diff($types, ['photo','video']) !== [] && $types !== ['audio'] && $types !== ['document']) throw new InvalidArgumentException('Этот набор медиа нельзя сохранить одним альбомом.');
        }
        if (strlen(self::json($result)) > self::LIMIT - 512) throw new InvalidArgumentException('Медиа шаблона превышают допустимый размер.');
        return $result;
    }
    public static function legacy(array $object): array
    {
        if(PostedTelegramNativeTemplate::isNative($object))return[];
        if (array_key_exists('media_json', $object) && $object['media_json'] !== null && $object['media_json'] !== '') {
            $items = is_string($object['media_json']) ? json_decode($object['media_json'], true, 32, JSON_THROW_ON_ERROR) : $object['media_json'];
            return self::vector($items);
        }
        if (($object['media_type'] ?? 'none') === 'none' || empty($object['media_file_id'])) return [];
        return self::vector([['type'=>$object['media_type'],'file_id'=>$object['media_file_id']]]);
    }
    public static function read(PDO $pdo, string $key, bool $lock = false): ?array
    {
        if (preg_match('/\Atg_template_(?:media|collect|preview)_[1-9][0-9]{0,18}\z/D', $key) !== 1
            && preg_match('/\Atg_template_input_[1-9][0-9]{0,18}_[a-f0-9]{24}\z/D', $key) !== 1) throw new InvalidArgumentException('Invalid template metadata key.');
        $stmt = $pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$key]); $raw = $stmt->fetchColumn(); if ($raw === false) return null;
        if (!is_string($raw) || strlen($raw) > self::LIMIT) throw new InvalidArgumentException('Сохранённые данные шаблона повреждены.');
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new InvalidArgumentException('Сохранённые данные шаблона повреждены.'); return $value;
    }
    public static function write(PDO $pdo, string $key, array $value): void
    {
        if (!$pdo->inTransaction()) throw new LogicException('Template metadata requires its owning transaction.');
        // Validate the key before writing, independently of the value's schema.
        if (strlen($key)>64 || (preg_match('/\Atg_template_(?:media|collect|preview)_[1-9][0-9]{0,18}\z/D',$key)!==1 && preg_match('/\Atg_template_input_[1-9][0-9]{0,18}_[a-f0-9]{24}\z/D',$key)!==1)) throw new InvalidArgumentException('Invalid template metadata key.');
        $raw=self::json($value); if(strlen($raw)>self::LIMIT) throw new InvalidArgumentException('Template metadata storage limit.');
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$raw]);
    }
    public static function normalize(array $value, array $template): array
    {
        $keys=array_keys($value);sort($keys);
        if($keys!==['album','draft_token','items','revision','saved','template_id','version'] || ($value['version']??null)!==1 || ($value['template_id']??null)!==self::id((int)($template['id']??0))
            || !is_int($value['revision']) || $value['revision']<1 || $value['revision']>2147483647 || !is_bool($value['saved'])
            || !is_bool($value['album'])
            || ($value['saved'] ? $value['draft_token']!==null : (!is_string($value['draft_token']) || preg_match('/\A[a-f0-9]{24}\z/D',$value['draft_token'])!==1))) throw new InvalidArgumentException('Медиа шаблона относятся к другой заготовке.');
        $value['items']=self::vector($value['items']);if(count($value['items'])>1&&!$value['album'])throw new InvalidArgumentException('Invalid template album layout.');
        $first=$value['items'][0]??null;
        if(($template['media_type']??'none')!==($first['type']??'none') || ($template['media_file_id']??null)!==($first['file_id']??null)) throw new InvalidArgumentException('Состав медиа шаблона изменился.');
        return $value;
    }
    public static function load(PDO $pdo, array $template, bool $lock = false): array
    {
        if(!array_key_exists('media_type',$template)||!array_key_exists('media_file_id',$template)){$s=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([(int)$template['id']]);$row=$s->fetch();if(!is_array($row))throw new InvalidArgumentException('Шаблон не найден.');$template=$row;}
        if(PostedTelegramNativeTemplate::isNative($template))return PostedTelegramNativeTemplate::load($pdo,$template,$lock);
        $value=self::read($pdo,self::key((int)$template['id']),$lock);
        return $value===null ? ['version'=>1,'template_id'=>(int)$template['id'],'revision'=>0,'saved'=>true,'draft_token'=>null,'album'=>false,'items'=>self::legacy($template)] : self::normalize($value,$template);
    }
    public static function hydrate(PDO $pdo, array $template): array
    {
        if(!array_key_exists('media_type',$template)||!array_key_exists('media_file_id',$template)||!array_key_exists('owner_id',$template)){$s=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1');$s->execute([(int)$template['id']]);$row=$s->fetch();if(!is_array($row))throw new InvalidArgumentException('Шаблон не найден.');$template=$row;}
        if(PostedTelegramNativeTemplate::isNative($template))return PostedTelegramNativeTemplate::hydrate($pdo,$template);
        $value=self::load($pdo,$template);$template['media_json']=self::json($value['items']);$template['_telegram_template_vector']=$value['revision']>0;$template['_telegram_template_captions']=PostedTelegramCaptionVector::load($pdo,$template,'t');$placement=PostedTelegramCaptionPlacement::load($pdo,$template,'t');if($placement!==null)$template['_telegram_template_caption_placement']=$placement;else unset($template['_telegram_template_caption_placement']);return $template;
    }
    public static function saveLocked(PDO $pdo, array $template, array $items, ?bool $saved = null, ?string $draftToken = null): array
    {
        if(!$pdo->inTransaction())throw new LogicException('Template vector requires its owning transaction.');
        $s=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$template['id']]);$actual=$s->fetch();if(!is_array($actual)||(isset($template['owner_id'])&&(int)$template['owner_id']!==(int)$actual['owner_id']))throw new InvalidArgumentException('Владелец шаблона изменился.');$template=$actual;
        if(PostedTelegramNativeTemplate::isNative($template)){
            $legacyPrivate=PostedTelegramNativeTemplatePreview::validateLegacy($pdo,(int)$template['id'],true);if($legacyPrivate!==null&&$legacyPrivate['phase']!=='shown')throw new RuntimeException('Прежний private-предпросмотр требует проверки. Исходное содержимое сохранено.');
            if($items!==[])throw new InvalidArgumentException('Сохраните этот вид содержимого отдельным шаблоном.');
            $native=PostedTelegramNativeTemplate::load($pdo,$template,true);PostedTelegramNativeTemplate::clearLocked($pdo,$template);
            $pdo->prepare("UPDATE posted_templates SET media_type='none',media_file_id=NULL WHERE id=? AND owner_id=?")->execute([(int)$template['id'],(int)$template['owner_id']]);$template['media_type']='none';$template['media_file_id']=null;
            // Preserve a monotonic control revision across native -> ordinary clear.
            self::write($pdo,self::key((int)$template['id']),['version'=>1,'template_id'=>(int)$template['id'],'revision'=>$native['revision'],'saved'=>$native['saved'],'draft_token'=>$native['draft_token'],'album'=>false,'items'=>[]]);
        }
        $previous=self::read($pdo,self::key((int)$template['id']),true);$revision=$previous===null?0:self::normalize($previous,$template)['revision'];
        $items=self::vector($items);$oldItems=$previous['items']??self::legacy($template);if(self::json($oldItems)!==self::json($items))PostedTelegramCaptionVector::detachLocked($pdo,$template,'t');$first=$items[0]??null;$next=$template;$next['media_type']=$first['type']??'none';$next['media_file_id']=$first['file_id']??null;
        $isSaved=$saved??($previous['saved']??true);$token=$isSaved?null:($draftToken??$previous['draft_token']??null);
        $record=self::normalize(['version'=>1,'template_id'=>(int)$template['id'],'revision'=>$revision+1,'saved'=>$isSaved,'draft_token'=>$token,'album'=>count($items)>1||($previous['album']??false),'items'=>$items],$next);
        $pdo->prepare('UPDATE posted_templates SET media_type=?,media_file_id=? WHERE id=? AND owner_id=?')->execute([$next['media_type'],$next['media_file_id'],(int)$template['id'],(int)$template['owner_id']]);
        self::write($pdo,self::key((int)$template['id']),$record);return $record;
    }
    public static function clonePostLocked(PDO $pdo,array $post,array $template):void
    { if(PostedTelegramNativeTemplate::isNative($post)){PostedTelegramNativeTemplate::clonePostLocked($pdo,$post,$template);return;}self::saveLocked($pdo,$template,self::legacy($post),true); }
    public static function deleteLocked(PDO $pdo,array $template):void
    {
        if(!$pdo->inTransaction())throw new LogicException('Template deletion requires owner locks.');
        if(PostedTelegramNativeTemplate::isNative($template))PostedTelegramNativeTemplate::clearLocked($pdo,$template);
        PostedTelegramCaptionVector::detachLocked($pdo,$template,'t');
        PostedTelegramCaptionPlacement::captureLocked($pdo,$template,false,'t');
        $id=(int)$template['id'];$collection=self::read($pdo,self::collectionKey($id),true);
        if($collection!==null){$collection=self::collection($collection);$collection['phase']='closed';self::write($pdo,self::collectionKey($id),$collection);}
        // Keep outcome/ingress receipts as tombstones; never delete shared imported media blobs.
        $pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?')->execute([self::key($id)]);
    }
    public static function fingerprint(array $template,array $options):string
    {if(PostedTelegramNativeTemplate::isNative($template))return PostedTelegramNativeTemplate::fingerprint($template,$options);$value=['id'=>(int)$template['id'],'text'=>(string)($template['text']??''),'entities'=>json_decode((string)($template['text_entities_json']??'null'),true),'buttons'=>json_decode((string)($template['buttons_json']??'null'),true),'media'=>self::legacy($template),'options'=>$options];if(($template['_telegram_template_captions']??null)!==null)$value['caption_vector']=$template['_telegram_template_captions'];if(($template['_telegram_template_caption_placement']??null)!==null)$value['caption_placement']=['version'=>1,'above'=>true];return hash('sha256',self::json($value));}
    public static function label(array $template):string
    {
        if(PostedTelegramNativeTemplate::isNative($template))return PostedTelegramNativeTemplate::label($template);
        $items=self::legacy($template);if(count($items)<2)return (string)$template['title'];
        $icons=[];foreach($items as$item)$icons[]=match($item['type']){'photo'=>'📷','video'=>'🎥','audio'=>'🎵',default=>'📎'};
        return implode(' ',$icons).'  '.mb_substr((string)$template['title'],0,48);
    }
    public static function collection(array $record):array
    {
        $keys=array_keys($record);sort($keys);$wanted=['version','template_id','actor_id','group_id','epoch','items','revision','last_ms','phase','input'];sort($wanted);
        if($keys!==$wanted||$record['version']!==1||!is_int($record['template_id'])||$record['template_id']<1||!is_int($record['actor_id'])||$record['actor_id']<1
            ||!is_string($record['group_id'])||$record['group_id']===''||strlen($record['group_id'])>128||!is_string($record['epoch'])||preg_match('/\A[a-f0-9]{32}\z/D',$record['epoch'])!==1
            ||!is_int($record['revision'])||$record['revision']<1||!is_int($record['last_ms'])||$record['last_ms']<1||!in_array($record['phase'],['collecting','shown','sending','unknown','closed'],true)
            ||!is_array($record['input'])||!is_array($record['items'])||!array_is_list($record['items'])||count($record['items'])<1||count($record['items'])>10)throw new InvalidArgumentException('Сборка шаблона повреждена.');
        $inputKeys=array_keys($record['input']);sort($inputKeys);if($inputKeys!==['state','state_data']||$record['input']['state']!=='collecting_template_media'||!is_string($record['input']['state_data']))throw new InvalidArgumentException('Ввод шаблона повреждён.');
        $binding=json_decode($record['input']['state_data'],true,16,JSON_THROW_ON_ERROR);$bindingKeys=is_array($binding)?array_keys($binding):[];sort($bindingKeys);
        if($bindingKeys!==['epoch','group_id','template_id']||$binding['template_id']!==$record['template_id']||$binding['group_id']!==$record['group_id']||$binding['epoch']!==$record['epoch'])throw new InvalidArgumentException('Ввод шаблона относится к другой сборке.');
        $last=0;$media=[];foreach($record['items']as$item){$ik=is_array($item)?array_keys($item):[];sort($ik);if($ik!==['entities','media','message_id','spoiler','text']||!is_int($item['message_id'])||$item['message_id']<=$last||!is_string($item['text'])||!is_array($item['entities'])||!array_is_list($item['entities'])||!is_bool($item['spoiler'])||!is_array($item['media'])||($item['media']['media_group_id']??null)!==$record['group_id'])throw new InvalidArgumentException('Состав альбома шаблона повреждён.');$media[]=$item['media'];$last=$item['message_id'];}self::vector($media);return $record;
    }
    public static function due(array $record,int $now):bool
    {$record=self::collection($record);return $record['phase']==='collecting'&&count($record['items'])>=2&&$now>=$record['last_ms']&&(count($record['items'])===10||$now-$record['last_ms']>=self::QUIET_MS);}
    public static function append(array $record,array $item,int $now):array
    {
        $record=self::collection($record);foreach($record['items']as$old)if($old['message_id']===$item['message_id']){if(self::json($old)!==self::json($item))throw new InvalidArgumentException('Повторный файл альбома изменился.');return $record;}
        if(count($record['items'])>=10||$now<$record['last_ms']||in_array($record['phase'],['sending','unknown','closed'],true))throw new RuntimeException('Сборка шаблона уже завершена или ожидает проверки.');
        $record['items'][]=$item;usort($record['items'],static fn(array$a,array$b):int=>$a['message_id']<=>$b['message_id']);$record['revision']++;$record['last_ms']=$now;$record['phase']='collecting';return self::collection($record);
    }
    public static function assembled(array $record,array $template):array
    {
        $record=self::collection($record);$media=[];$text=(string)($template['text']??'');$entities=json_decode((string)($template['text_entities_json']??'null'),true);$caption=false;$spoiler=false;
        foreach($record['items']as$item){$media[]=$item['media'];$spoiler=$spoiler||$item['spoiler'];if(!$caption&&$item['text']!==''){$text=$item['text'];$entities=$item['entities'];$caption=true;}}
        $secondary=false;foreach(array_slice($record['items'],1)as$item)if($item['text']!==''||$item['entities']!==[])$secondary=true;if($secondary){$text=$record['items'][0]['text'];$entities=$record['items'][0]['entities'];}
        return ['media'=>self::vector($media),'text'=>$text,'entities'=>$entities,'spoiler'=>$spoiler];
    }
    public static function assertReady(PDO $pdo,array $template):void
    {
        $raw=self::read($pdo,self::collectionKey((int)$template['id']),$pdo->inTransaction());if($raw!==null){$r=self::collection($raw);
            if($r['template_id']!==(int)$template['id']||!in_array($r['phase'],['shown','closed'],true))throw new RuntimeException('Альбом шаблона ещё загружается или его предпросмотр не подтверждён.');}
        if(PostedTelegramNativeTemplate::isNative($template)){PostedTelegramNativeTemplate::load($pdo,$template,$pdo->inTransaction());PostedTelegramNativeTemplatePreview::validateLegacy($pdo,(int)$template['id'],$pdo->inTransaction());return;}
        $preview=self::read($pdo,self::previewKey((int)$template['id']),$pdo->inTransaction());if($preview!==null&&self::preview($preview,(int)$template['id'])['phase']!=='shown')throw new RuntimeException('Предпросмотр шаблона не подтверждён. Повтор не выполнялся.');
    }
    public static function preview(array$r,int$id):array
    {
        $keys=array_keys($r);sort($keys);$want=['version','template_id','actor_id','fingerprint','mode','nonce','phase','expected','media_ids','control_id','previous'];sort($want);
        if($keys!==$want||$r['version']!==1||$r['template_id']!==$id||!is_int($r['actor_id'])||$r['actor_id']<1||!is_string($r['fingerprint'])||preg_match('/\A[a-f0-9]{64}\z/D',$r['fingerprint'])!==1||!is_string($r['nonce'])||preg_match('/\A[a-f0-9]{32}\z/D',$r['nonce'])!==1||!in_array($r['mode'],['editor','view'],true)||!in_array($r['phase'],['sending','media_accepted','shown','unknown'],true)||!is_int($r['expected'])||$r['expected']<1||$r['expected']>110||!is_array($r['media_ids'])||!array_is_list($r['media_ids'])||count($r['media_ids'])>110||($r['control_id']!==null&&(!is_int($r['control_id'])||$r['control_id']<1)))throw new RuntimeException('Предпросмотр шаблона повреждён.');
        $seen=[];foreach($r['media_ids']as$mid){if(!is_int($mid)||$mid<1||isset($seen[$mid]))throw new RuntimeException('Предпросмотр шаблона повреждён.');$seen[$mid]=true;}
        if($r['phase']==='shown'&&(count($r['media_ids'])!==$r['expected']||$r['control_id']===null||isset($seen[$r['control_id']])))throw new RuntimeException('Предпросмотр шаблона не подтверждён.');
        if($r['previous']!==null){$p=$r['previous'];$pk=is_array($p)?array_keys($p):[];sort($pk);if($pk!==['actor_id','control_id','fingerprint','media_ids','mode']||!is_int($p['actor_id'])||$p['actor_id']<1||!is_int($p['control_id'])||$p['control_id']<1||!is_string($p['fingerprint'])||preg_match('/\A[a-f0-9]{64}\z/D',$p['fingerprint'])!==1||!in_array($p['mode'],['editor','view'],true)||!is_array($p['media_ids'])||!array_is_list($p['media_ids'])||count($p['media_ids'])<1||count($p['media_ids'])>110)throw new RuntimeException('Предыдущий предпросмотр повреждён.');$seen=[];foreach($p['media_ids']as$mid){if(!is_int($mid)||$mid<1||isset($seen[$mid])||$mid===$p['control_id'])throw new RuntimeException('Предыдущий предпросмотр повреждён.');$seen[$mid]=true;}}
        return$r;
    }
    public static function scopeSnapshot(PDO $pdo,array $templates):array
    {
        $result=[];foreach($templates as$template){$id=(int)$template['id'];$raw=self::read($pdo,self::key($id),$pdo->inTransaction());if($raw!==null){$actual=self::hydrate($pdo,$template);self::assertReady($pdo,$actual);$result[$id]=PostedTelegramNativeTemplate::isNative($actual)?PostedTelegramNativeTemplate::load($pdo,$actual):self::normalize($raw,$actual);if(!$result[$id]['saved'])throw new RuntimeException('Сначала завершите сохранение шаблона.');}}ksort($result,SORT_NUMERIC);return$result;
    }
    public static function assetReferences(PDO $pdo):array
    {
        $used=[];$s=$pdo->query("SELECT t.* FROM posted_templates t JOIN posted_app_settings s ON s.setting_key=CONCAT('tg_template_media_',t.id)");
        while($template=$s->fetch(PDO::FETCH_ASSOC)){if(PostedTelegramNativeTemplate::isNative($template)){PostedTelegramNativeTemplate::load($pdo,$template);continue;}foreach(self::load($pdo,$template)['items']as$item)if(preg_match('/\Apost-link-asset:([a-f0-9]{64})\z/D',$item['file_id'],$m)===1)$used[$m[1]]=true;}
        return$used;
    }
}
