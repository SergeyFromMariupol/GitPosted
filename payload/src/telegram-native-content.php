<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** SQL authorization belongs to current channel/post/actor locks, never this content record. */
final class PostedTelegramNativeContent
{
    public const KINDS=['video_note','sticker','poll','location','contact'];
    public const LIMIT=60000;
    public static function isKind(mixed $kind):bool{return is_string($kind)&&in_array($kind,self::KINDS,true);}
    public static function json(mixed $v):string
    {if(is_array($v)){if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=json_decode(self::json($x),true,64,JSON_THROW_ON_ERROR);}return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);}
    public static function hash(mixed $v):string{return hash('sha256',self::json($v));}
    private static function keys(array $v,array $keys):void{$a=array_keys($v);sort($a);sort($keys);if($a!==$keys)throw new InvalidArgumentException('native_content_fields');}
    private static function text(mixed $v,int $min,int $max,bool $bytes=false):string
    {if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||str_contains($v,"\0")||($n=$bytes?strlen($v):mb_strlen($v,'UTF-8'))<$min||$n>$max)throw new InvalidArgumentException('native_content_text');return$v;}
    private static function integer(mixed $v,int $min,int $max):int{if(!is_int($v)||$v<$min||$v>$max)throw new InvalidArgumentException('native_content_integer');return$v;}
    private static function file(mixed $v,int $max=4096):string
    {$v=self::text($v,1,$max,true);if(preg_match('~[\x00-\x20\x7f:]|\A[/\\\\]~D',$v)===1)throw new InvalidArgumentException('native_content_file_id');return$v;}
    private static function number(mixed $v,float $min,float $max):float
    {if((!is_float($v)&&!is_int($v))||!is_finite((float)$v)||$v<$min||$v>$max)throw new InvalidArgumentException('native_content_number');return(float)$v;}
    private static function flag(array $v,string $key,bool $default):bool
    {if(!array_key_exists($key,$v))return$default;if(!is_bool($v[$key]))throw new InvalidArgumentException('native_content_boolean');return$v[$key];}
    private static function absent(array $v,array $keys):void
    {foreach($keys as$k)if(array_key_exists($k,$v)&&$v[$k]!==null&&$v[$k]!==false&&$v[$k]!==[]&&$v[$k]!=='')throw new InvalidArgumentException('native_content_unsupported_'.$k);}

    /** Only authenticated Bot API message field names. No unchecked MTProto aliases. */
    public static function fromMessage(array $message):?array
    {
        $kinds=array_values(array_filter(self::KINDS,static fn($k)=>array_key_exists($k,$message)));
        if($kinds===[])return null;
        foreach(['photo','video','animation','document','audio','voice','live_photo','rich_message']as$f)if(array_key_exists($f,$message)&&$message[$f]!==null)throw new InvalidArgumentException('native_content_ambiguous_media');
        foreach(['text','caption']as$f)if(array_key_exists($f,$message)&&!is_string($message[$f]))throw new InvalidArgumentException('native_content_text');
        if(count($kinds)!==1||isset($message['venue'])||isset($message['paid_media'])||!empty($message['media_group_id'])
            ||(string)($message['text']??'')!==''||(string)($message['caption']??'')!==''||!empty($message['entities'])||!empty($message['caption_entities'])
            ||self::flag($message,'has_media_spoiler',false)||self::flag($message,'show_caption_above_media',false))throw new InvalidArgumentException('Этот вид содержимого нельзя преобразовать или объединить с описанием.');
        $kind=$kinds[0];$source=$message[$kind];if(!is_array($source)||array_is_list($source))throw new InvalidArgumentException('native_content_object');
        if(in_array($kind,['video_note','sticker'],true)){
            $p=['file_id'=>self::file($source['file_id']??null),'file_unique_id'=>self::file($source['file_unique_id']??null,512)];
            if($kind==='video_note'){$p['length']=self::integer($source['length']??null,1,640);$p['duration']=self::integer($source['duration']??null,0,60);}
            else{
                if(($source['type']??'regular')!=='regular'||!array_key_exists('is_animated',$source)||!array_key_exists('is_video',$source)||self::flag($source,'is_animated',false)||self::flag($source,'is_video',false))throw new InvalidArgumentException('Пока поддерживается обычный статический стикер.');
                self::absent($source,['premium_animation','mask_position','custom_emoji_id']);$p['width']=self::integer($source['width']??null,1,512);$p['height']=self::integer($source['height']??null,1,512);
            }
        }elseif($kind==='poll'){
            $allowed=['id','question','question_entities','options','total_voter_count','is_closed','is_anonymous','type','allows_multiple_answers','allows_revoting','members_only','country_codes','correct_option_id','correct_option_ids','explanation','explanation_entities','explanation_media','open_period','close_date','description','description_entities','media','shuffle_options','allow_adding_options','hide_results_until_closes'];
            if(array_diff(array_keys($source),$allowed)!==[]||($source['type']??null)!=='regular'||!self::flag($source,'is_anonymous',false)||self::flag($source,'allows_multiple_answers',false)||self::flag($source,'is_closed',false))throw new InvalidArgumentException('Пока поддерживается обычный открытый анонимный опрос с одним ответом.');
            self::absent($source,['question_entities','correct_option_id','correct_option_ids','explanation','explanation_entities','explanation_media','open_period','close_date','description','description_entities','media','country_codes']);
            foreach(['shuffle_options','allow_adding_options','hide_results_until_closes','members_only']as$f)if(self::flag($source,$f,false))throw new InvalidArgumentException('native_content_unsupported_'.$f);
            $options=$source['options']??null;if(!is_array($options)||!array_is_list($options)||count($options)<2||count($options)>12)throw new InvalidArgumentException('native_content_poll_options');$texts=[];
            foreach($options as$o){if(!is_array($o)||array_diff(array_keys($o),['persistent_id','text','text_entities','media','voter_count','added_by_user','added_by_chat','addition_date'])!==[])throw new InvalidArgumentException('native_content_poll_option');self::absent($o,['text_entities','media','added_by_user','added_by_chat','addition_date']);$texts[]=self::text($o['text']??null,1,100);}
            $p=['question'=>self::text($source['question']??null,1,300),'options'=>$texts,'is_anonymous'=>true,'type'=>'regular','allows_multiple_answers'=>false,'allows_revoting'=>self::flag($source,'allows_revoting',true)];
        }elseif($kind==='location'){
            if(array_diff(array_keys($source),['latitude','longitude','horizontal_accuracy','live_period','heading','proximity_alert_radius'])!==[])throw new InvalidArgumentException('native_content_location_fields');self::absent($source,['live_period','heading','proximity_alert_radius']);
            $p=['latitude'=>self::number($source['latitude']??null,-90,90),'longitude'=>self::number($source['longitude']??null,-180,180),'horizontal_accuracy'=>array_key_exists('horizontal_accuracy',$source)?self::number($source['horizontal_accuracy'],0,1500):null];
        }else{
            if(array_diff(array_keys($source),['phone_number','first_name','last_name','user_id','vcard'])!==[])throw new InvalidArgumentException('native_content_contact_fields');$phone=self::text($source['phone_number']??null,1,32,true);if(preg_match('/\A\+?[0-9]{5,20}\z/D',$phone)!==1)throw new InvalidArgumentException('native_content_contact_phone');
            $p=['phone_number'=>$phone,'first_name'=>self::text($source['first_name']??null,1,128),'last_name'=>self::text($source['last_name']??'',0,128),'vcard'=>self::text($source['vcard']??'',0,2048,true),'source_user_id'=>isset($source['user_id'])?self::integer($source['user_id'],0,4503599627370495):null];
        }
        return self::normalize(['version'=>1,'kind'=>$kind,'payload'=>$p]);
    }
    public static function normalize(mixed $value):array
    {
        if(!is_array($value))throw new InvalidArgumentException('native_content_descriptor');self::keys($value,['version','kind','payload']);if(($value['version']??null)!==1||!self::isKind($value['kind']??null)||!is_array($value['payload']))throw new InvalidArgumentException('native_content_descriptor');
        $kind=$value['kind'];$p=$value['payload'];
        $keys=match($kind){'video_note'=>['file_id','file_unique_id','length','duration'],'sticker'=>['file_id','file_unique_id','width','height'],'poll'=>['question','options','is_anonymous','type','allows_multiple_answers','allows_revoting'],'location'=>['latitude','longitude','horizontal_accuracy'],'contact'=>['phone_number','first_name','last_name','vcard','source_user_id']};self::keys($p,$keys);
        // Validation below is kept explicit: normalize must not recurse into fromMessage.
        if(in_array($kind,['video_note','sticker'],true)){self::file($p['file_id']);self::file($p['file_unique_id'],512);if($kind==='video_note'){self::integer($p['length'],1,640);self::integer($p['duration'],0,60);}else{self::integer($p['width'],1,512);self::integer($p['height'],1,512);}}
        elseif($kind==='poll'){self::text($p['question'],1,300);if(!is_array($p['options'])||!array_is_list($p['options'])||count($p['options'])<2||count($p['options'])>12||$p['is_anonymous']!==true||$p['type']!=='regular'||$p['allows_multiple_answers']!==false||!is_bool($p['allows_revoting']))throw new InvalidArgumentException('native_content_poll');foreach($p['options']as$o)self::text($o,1,100);}
        elseif($kind==='location'){$p['latitude']=self::number($p['latitude'],-90,90);$p['longitude']=self::number($p['longitude'],-180,180);if($p['horizontal_accuracy']!==null)$p['horizontal_accuracy']=self::number($p['horizontal_accuracy'],0,1500);}
        else{if(preg_match('/\A\+?[0-9]{5,20}\z/D',self::text($p['phone_number'],1,32,true))!==1)throw new InvalidArgumentException('native_content_contact');self::text($p['first_name'],1,128);self::text($p['last_name'],0,128);self::text($p['vcard'],0,2048,true);if($p['source_user_id']!==null)self::integer($p['source_user_id'],0,4503599627370495);}
        $value['payload']=$p;if(strlen(self::json($value))>self::LIMIT-512)throw new InvalidArgumentException('native_content_size');return$value;
    }
    public static function request(array $descriptor):array
    {
        $v=self::normalize($descriptor);$p=$v['payload'];$params=match($v['kind']){
            'video_note'=>['video_note'=>$p['file_id']], 'sticker'=>['sticker'=>$p['file_id']],
            'poll'=>['question'=>$p['question'],'options'=>array_map(static fn($x)=>['text'=>$x],$p['options']),'is_anonymous'=>true,'type'=>'regular','allows_multiple_answers'=>false,'allows_revoting'=>$p['allows_revoting']],
            'location'=>array_filter($p,static fn($x)=>$x!==null),
            'contact'=>array_diff_key($p,['source_user_id'=>true]),
        };
        return['method'=>match($v['kind']){'video_note'=>'sendVideoNote','sticker'=>'sendSticker','poll'=>'sendPoll','location'=>'sendLocation','contact'=>'sendContact'},'params'=>$params];
    }
    public static function capabilities(string $kind):array
    {if(!self::isKind($kind))throw new InvalidArgumentException('native_content_kind');return['has_content'=>true,'caption'=>false,'replace_media'=>false,'album'=>false,'paid_media'=>false,'reconcile_semantic'=>in_array($kind,['video_note','sticker'],true)];}
    /** An expected part, never a fabricated API confirmation: MID is deliberately zero. */
    public static function expectedMessage(array $descriptor,string $chatId):array
    {
        $v=self::normalize($descriptor);$p=$v['payload'];$wire=$p;
        if($v['kind']==='sticker')$wire+=['type'=>'regular','is_animated'=>false,'is_video'=>false];
        if($v['kind']==='poll'){$wire['options']=array_map(static fn($x)=>['text'=>$x],$p['options']);$wire['is_closed']=false;}
        if($v['kind']==='location'&&$wire['horizontal_accuracy']===null)unset($wire['horizontal_accuracy']);
        if($v['kind']==='contact'){unset($wire['source_user_id']);if($p['source_user_id']!==null)$wire['user_id']=$p['source_user_id'];}
        return['message_id'=>0,'chat'=>['id'=>$chatId],$v['kind']=>$wire];
    }
    public static function identity(array $message):?array
    {
        $v=self::fromMessage($message);if($v===null)return null;$p=$v['payload'];
        if(in_array($v['kind'],['video_note','sticker'],true))return[$v['kind'],$p['file_unique_id'],true,[$p['file_unique_id'],$p['file_id']]];
        // This digest identifies content only. Callers MUST NOT reconcile an unknown send from it.
        return[$v['kind'],'native-v1:'.$v['kind'].':'.self::hash(self::request($v)['params']),true,[]];
    }
    /** Only a direct method result with actual positive MID/chat/native field can certify a new send. */
    public static function acknowledgement(array $descriptor,array $result,string $chatId):array
    {
        $expected=self::normalize($descriptor);$m=$result['result']??null;
        if(($result['ok']??null)!==true||!is_array($m)||array_is_list($m)||!is_int($m['message_id']??null)||$m['message_id']<1||!is_array($m['chat']??null)||(string)($m['chat']['id']??'')!==$chatId)return['known'=>false,'message_id'=>null,'reason'=>'native_ack_missing_or_wrong_peer'];
        try{$actual=self::fromMessage($m);}catch(InvalidArgumentException $e){return['known'=>false,'message_id'=>$m['message_id'],'reason'=>'native_ack_malformed'];}
        if($actual===null||$actual['kind']!==$expected['kind'])return['known'=>false,'message_id'=>$m['message_id'],'reason'=>'native_ack_wrong_kind'];
        $ep=$expected['payload'];$ap=$actual['payload'];
        if(in_array($actual['kind'],['video_note','sticker'],true)){unset($ep['file_id'],$ap['file_id']);$same=self::json($ep)===self::json($ap);}
        else $same=self::json(self::request($expected)['params'])===self::json(self::request($actual)['params']);
        return['known'=>$same,'message_id'=>$m['message_id'],'reason'=>$same?'native_ack_known':'native_ack_content_changed'];
    }
}

/** Only explicit migration code may add existing-column values or certify ingress. */
final class PostedTelegramNativeSchema
{
    public const KEY='telegram_native_content_capability_v1';
    public const OLD=['none','photo','video','animation','document','audio','voice'];
    public const COLUMNS=['posted_posts.media_type','posted_posts.original_media_type','posted_templates.media_type'];
    public static function values(string $type):?array
    {if(preg_match('/\Aenum\((.*)\)\z/iD',$type,$m)!==1)return null;$parts=explode(',',$m[1]);$out=[];foreach($parts as$p){if(preg_match("/\\A'([a-z_]+)'\\z/D",trim($p),$v)!==1)return null;$out[]=$v[1];}return count($out)===count(array_unique($out))?$out:null;}
    public static function supportedSuperset(string $actual,string $expected):bool
    {$a=self::values($actual);$e=self::values($expected);return$a!==null&&$e!==null&&array_diff($e,$a)===[];}
    public static function protectedColumn(string $table,string $column):bool{return in_array($table.'.'.$column,self::COLUMNS,true);}
    public static function columnType(PDO $pdo,string $table,string $column):string
    {if(!self::protectedColumn($table,$column))throw new InvalidArgumentException('native_schema_column');$q=$pdo->prepare('SHOW COLUMNS FROM `'.$table.'` WHERE Field=?');$q->execute([$column]);$row=$q->fetch(PDO::FETCH_ASSOC);$type=is_array($row)?($row['Type']??null):null;if(!is_string($type)||self::values($type)===null)throw new RuntimeException('native_schema_missing');return strtolower($type);}
    public static function columnTypes(PDO $pdo):array
    {$out=[];foreach(self::COLUMNS as$key){[$t,$c]=explode('.',$key);$out[$key]=self::columnType($pdo,$t,$c);}return$out;}
    /** Storage support is independent of the external-ingress certificate. */
    public static function requireStorageSupported(PDO $pdo):array
    {
        $columns=self::columnTypes($pdo);$all=array_merge(self::OLD,PostedTelegramNativeContent::KINDS);foreach($columns as$type)if(array_diff($all,self::values($type)??[])!==[])throw new RuntimeException('native_schema_incomplete');return$columns;
    }
    public static function requireEnabled(PDO $pdo):void
    {
        $s=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$s->execute([self::KEY]);$raw=$s->fetchColumn();if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('Новые виды медиа ещё не включены: требуется проверенная миграция.');$v=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $columns=self::requireStorageSupported($pdo);
        $keys=is_array($v)?array_keys($v):[];sort($keys);
        if(!is_array($v)||$keys!==['columns_sha256','kinds','version']||$v['version']!==1||$v['kinds']!==PostedTelegramNativeContent::KINDS||!is_string($v['columns_sha256'])||!hash_equals(PostedTelegramNativeContent::hash($columns),$v['columns_sha256']))throw new RuntimeException('native_schema_capability_mismatch');
    }
}
