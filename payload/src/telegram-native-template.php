<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-native-store.php';
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Native template control metadata is a v2 variant, never a synthetic file vector. */
final class PostedTelegramNativeTemplate
{
    public static function isNative(array $template):bool{return PostedTelegramNativeContent::isKind($template['media_type']??null);}
    public static function key(int $id):string{if($id<1)throw new InvalidArgumentException('native_template_identity');return'tg_template_media_'.$id;}
    public static function normalize(array $v,array $template,array $envelope):array
    {
        $keys=array_keys($v);sort($keys);
        if($keys!==['channel_id','content_sha256','draft_token','native_kind','native_revision','revision','saved','template_id','version']||($v['version']??null)!==2
            ||($v['template_id']??null)!==(int)$template['id']||($v['channel_id']??null)!==(int)($template['channel_id']??0)||!is_int($v['revision']??null)||$v['revision']<1||$v['revision']>2147483647
            ||!is_bool($v['saved']??null)||($v['saved']?$v['draft_token']!==null:(!is_string($v['draft_token'])||preg_match('/\A[a-f0-9]{24}\z/D',$v['draft_token'])!==1)))throw new InvalidArgumentException('native_template_header');
        $envelope=PostedTelegramNativeStore::normalize($envelope,$template,'t');
        $entities=$template['text_entities_json']??null;if(is_string($entities))$entities=json_decode($entities,true,32,JSON_THROW_ON_ERROR);
        if((string)($template['text']??'')!==''||($entities!==null&&$entities!==[]))throw new InvalidArgumentException('native_template_unexpected_caption');
        if($v['native_kind']!==$envelope['descriptor']['kind']||$v['native_revision']!==$envelope['revision']||!is_string($v['content_sha256'])||!hash_equals($envelope['content_sha256'],$v['content_sha256']))throw new InvalidArgumentException('native_template_binding');
        return$v;
    }
    public static function load(PDO $pdo,array $template,bool $lock=false):array
    {
        $id=(int)$template['id'];$s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$s->execute([self::key($id)]);$raw=$s->fetchColumn();
        if(!is_string($raw)||strlen($raw)>4096)throw new InvalidArgumentException('Native шаблон требует проверки: данные не найдены.');$v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($v))throw new InvalidArgumentException('native_template_header');$native=PostedTelegramNativeStore::load($pdo,$template,'t',$lock);if($native===null)throw new InvalidArgumentException('native_template_missing');return self::normalize($v,$template,$native);
    }
    public static function hydrate(PDO $pdo,array $template):array
    {
        $header=self::load($pdo,$template);$native=PostedTelegramNativeStore::load($pdo,$template,'t');if(PostedTelegramCaptionVector::load($pdo,$template,'t')!==null)throw new InvalidArgumentException('native_template_unexpected_caption');$template['media_json']='[]';$template['_telegram_template_vector']=true;$template['_telegram_template_captions']=null;$template['_telegram_template_native_header']=$header;$template['_telegram_template_native']=$native;return$template;
    }
    public static function saveLocked(PDO $pdo,array $template,?bool $saved=null,?string $draftToken=null,bool $fresh=false):array
    {
        if(!$pdo->inTransaction())throw new LogicException('native_template_transaction');$q=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$template['id']]);$actual=$q->fetch();if(!is_array($actual)||(int)$actual['owner_id']!==(int)$template['owner_id']||(int)($actual['channel_id']??0)!==(int)($template['channel_id']??0))throw new RuntimeException('native_template_owner_changed');
        $native=PostedTelegramNativeStore::load($pdo,$actual,'t',true);if($native===null)throw new InvalidArgumentException('native_template_missing');$q=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1 FOR UPDATE');$q->execute([self::key((int)$actual['id'])]);$old=$q->fetchColumn();
        if($fresh&&$old!==false)throw new RuntimeException('native_template_header_exists');$previous=$old===false?null:self::normalize(json_decode((string)$old,true,16,JSON_THROW_ON_ERROR),$actual,$native);$revision=($previous['revision']??0)+1;if($revision>2147483647)throw new RuntimeException('native_template_revision');$isSaved=$saved??($previous['saved']??true);$token=$isSaved?null:($draftToken??$previous['draft_token']??null);
        $value=self::normalize(['version'=>2,'template_id'=>(int)$actual['id'],'channel_id'=>(int)($actual['channel_id']??0),'revision'=>$revision,'saved'=>$isSaved,'draft_token'=>$token,'native_kind'=>$native['descriptor']['kind'],'native_revision'=>$native['revision'],'content_sha256'=>$native['content_sha256']],$actual,$native);
        $pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([self::key((int)$actual['id']),PostedTelegramNativeContent::json($value)]);return$value;
    }
    public static function clonePostLocked(PDO $pdo,array $post,array $template):void
    {
        if(!$pdo->inTransaction())throw new LogicException('native_template_transaction');$frozen=PostedTelegramNativeStore::freeze($pdo,$post);$descriptor=$frozen['_telegram_native_content']??null;if($descriptor===null)throw new InvalidArgumentException('native_template_legacy_post');$descriptor=PostedTelegramNativeContent::normalize($descriptor);$q=$pdo->prepare('SELECT * FROM posted_templates WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$template['id']]);$actual=$q->fetch();if(!is_array($actual)||(int)$actual['owner_id']!==(int)$template['owner_id'])throw new RuntimeException('native_template_owner_changed');
        $pdo->prepare('UPDATE posted_templates SET media_type=?,media_file_id=? WHERE id=? AND owner_id=?')->execute([$descriptor['kind'],$descriptor['payload']['file_id']??null,(int)$actual['id'],(int)$actual['owner_id']]);$actual['media_type']=$descriptor['kind'];$actual['media_file_id']=$descriptor['payload']['file_id']??null;PostedTelegramNativeStore::captureLocked($pdo,$actual,$descriptor,'t');self::saveLocked($pdo,$actual,true,null,true);
    }
    public static function clearLocked(PDO $pdo,array $template):void
    {
        if(!$pdo->inTransaction())throw new LogicException('native_template_transaction');$header=self::load($pdo,$template,true);$native=PostedTelegramNativeStore::load($pdo,$template,'t',true);PostedTelegramNativeStore::detachTemplateLocked($pdo,$template,$native['revision'],$native['content_sha256']);$q=$pdo->prepare('DELETE FROM posted_app_settings WHERE setting_key=?');$q->execute([self::key((int)$template['id'])]);if($q->rowCount()!==1)throw new RuntimeException('native_template_header_changed');
    }
    public static function fingerprint(array $template,array $options):string
    {
        $native=$template['_telegram_template_native']??null;$header=$template['_telegram_template_native_header']??null;if(!is_array($native)||!is_array($header))throw new InvalidArgumentException('native_template_not_hydrated');self::normalize($header,$template,$native);
        return PostedTelegramNativeContent::hash(['id'=>(int)$template['id'],'text'=>(string)($template['text']??''),'entities'=>json_decode((string)($template['text_entities_json']??'null'),true),'buttons'=>json_decode((string)($template['buttons_json']??'null'),true),'native'=>$native,'header'=>$header,'options'=>$options]);
    }
    public static function label(array $template):string
    {return match($template['media_type']??null){'video_note'=>'⚪️ Видеосообщение','sticker'=>'🧩 Стикер','poll'=>'📊 Опрос','location'=>'📍 Местоположение','contact'=>'👤 Контакт',default=>throw new InvalidArgumentException('native_template_kind')};}
    public static function scopeSnapshot(PDO $pdo,array $template):array
    {$value=self::load($pdo,$template,$pdo->inTransaction());if(!$value['saved'])throw new RuntimeException('Сначала завершите сохранение шаблона.');$native=PostedTelegramNativeStore::scopeSnapshot($pdo,$template,'t');if($native===null)throw new InvalidArgumentException('native_template_missing');return['header'=>$value,'native'=>$native];}
}
