<?php
declare(strict_types=1);

final class PostedTelegramWebException extends RuntimeException
{
    public function __construct(public readonly string $reason,string $message,public readonly int $httpStatus=409){parent::__construct($message);}
}

/** Typed JSON protocol. Neither actor/post bindings nor callbacks are accepted from the browser. */
final class PostedTelegramWebRequest
{
    // Older preset requests remain valid; presentation follows the observed seven choices.
    public const TIMER_MINUTES=[0,5,15,30,60,120,240,360,720,1080,1440,2875,4320];
    public const TIMER_PRESETS=[0,60,120,360,1440,2875,4320];
    public const REPEAT_MINUTES=[0,60,120,240,360,720,1080,1440,2880,4320,7200,10080,14400];
    public const REPEAT_PRESETS=[0,60,120,360,1440,2880,4320];
    public const MAX_TIMER_CUSTOM_MINUTES=87839;
    public const MAX_REPEAT_CUSTOM_MINUTES=526980;
    public const ACTIONS=['read','read_calendar','read_target_metadata','set_timer','set_timer_custom','set_repeat','set_repeat_custom','set_top','set_schedule','set_targets','prepare_publish','prepare_schedule','confirm','operation_state'];

    public static function parse(string $json):array
    {
        if(strlen($json)>65536)self::invalid();
        try{$object=json_decode($json,false,24,JSON_THROW_ON_ERROR);$input=json_decode($json,true,24,JSON_THROW_ON_ERROR);}catch(Throwable){self::invalid();}
        if(!($object instanceof stdClass)||(property_exists($object,'payload')&&!($object->payload instanceof stdClass)))self::invalid();
        self::uniqueObjectKeys($json);
        if(!is_array($input)||array_is_list($input))self::invalid();
        self::keys($input,['init_data','launch','action','revision','request_id','payload'],['init_data','launch','action','request_id']);
        if(!is_string($input['init_data'])||strlen($input['init_data'])<1||strlen($input['init_data'])>16384
            ||!self::hex($input['launch'],48)||!self::hex($input['request_id'],32)||!is_string($input['action'])||!in_array($input['action'],self::ACTIONS,true))self::invalid();
        $revision=$input['revision']??null;
        if(!in_array($input['action'],['read','read_calendar','read_target_metadata','operation_state'],true)&&!self::hex($revision,64))self::invalid();
        if($revision!==null&&!self::hex($revision,64))self::invalid();
        $payload=$input['payload']??[];if(!is_array($payload)||($payload!==[]&&array_is_list($payload)))self::invalid();
        switch($input['action']){
            case'read':case'prepare_publish':self::keys($payload,[]);break;
            case'prepare_schedule':self::keys($payload,[]);break;
            case'read_calendar':self::calendarSelection($payload);break;
            case'read_target_metadata':self::targetMetadataId($payload);break;
            case'set_timer':case'set_repeat':
                self::keys($payload,['minutes'],['minutes']);$allowed=$input['action']==='set_timer'?self::TIMER_MINUTES:self::REPEAT_MINUTES;
                if(!is_int($payload['minutes'])||!in_array($payload['minutes'],$allowed,true))self::invalid();break;
            case'set_timer_custom':case'set_repeat_custom':self::customMinutes($input['action'],$payload);break;
            case'set_top':self::keys($payload,['enabled'],['enabled']);if(!is_bool($payload['enabled']))self::invalid();break;
            case'set_schedule':self::keys($payload,['local_value'],['local_value']);
                if(!is_string($payload['local_value'])||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/D',$payload['local_value'])!==1)self::invalid();break;
            case'set_targets':
                self::keys($payload,['targets'],['targets']);if(!is_array($payload['targets'])||!array_is_list($payload['targets'])||count($payload['targets'])>40)self::invalid();$channels=[];
                foreach($payload['targets']as$target){if(!is_array($target))self::invalid();self::keys($target,['id','mode','category_id'],['id','mode','category_id']);
                    if(!is_int($target['id'])||$target['id']<1||!is_int($target['category_id'])||$target['category_id']<0||$target['category_id']>4294967295||!in_array($target['mode'],['copy','forward'],true)||isset($channels[$target['id']]))self::invalid();$channels[$target['id']]=true;}
                break;
            case'confirm':self::keys($payload,['token'],['token']);if(!self::hex($payload['token'],32))self::invalid();break;
            case'operation_state':self::keys($payload,['request_id'],['request_id']);if(!self::hex($payload['request_id'],32))self::invalid();break;
        }
        return ['init_data'=>$input['init_data'],'launch'=>$input['launch'],'action'=>$input['action'],'revision'=>$revision,'request_id'=>$input['request_id'],'payload'=>$payload];
    }

    /** One existing local channel only; no chat IDs, actor IDs or grants are client supplied. */
    public static function targetMetadataId(array$payload):int
    {self::keys($payload,['id'],['id']);if(!is_int($payload['id'])||$payload['id']<1||$payload['id']>4294967295)self::invalid();return$payload['id'];}

    /** Canonical Gregorian selections only. The actor's timezone is checked by the bound reader. */
    public static function calendarSelection(array $payload):array
    {
        self::keys($payload,['month','day'],['month','day']);
        if(!is_string($payload['month'])||preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/D',$payload['month'])!==1
            ||!is_string($payload['day'])||preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])\z/D',$payload['day'])!==1
            ||substr($payload['day'],0,7)!==$payload['month']
            ||!checkdate((int)substr($payload['day'],5,2),(int)substr($payload['day'],8,2),(int)substr($payload['day'],0,4)))self::invalid();
        return$payload;
    }

    /** Validate components before arithmetic. Raw units stay in the request receipt hash. */
    public static function customMinutes(string $action,array $payload):int
    {
        if(!in_array($action,['set_timer_custom','set_repeat_custom'],true))self::invalid();
        $repeat=$action==='set_repeat_custom';$keys=$repeat?['days','hours']:['days','hours','minutes'];
        self::keys($payload,$keys,$keys);
        foreach($keys as$key){$maximum=$key==='days'?($repeat?365:60):($key==='hours'?23:59);
            if(!is_int($payload[$key])||$payload[$key]<0||$payload[$key]>$maximum)self::invalid();}
        $minutes=$payload['days']*1440+$payload['hours']*60+($repeat?0:$payload['minutes']);
        if($minutes>($repeat?self::MAX_REPEAT_CUSTOM_MINUTES:self::MAX_TIMER_CUSTOM_MINUTES)||($repeat&&$minutes>0&&$minutes<60))self::invalid();
        return$minutes;
    }

    public static function sameOrigin(string $baseUrl,string $origin,?string $fetchSite=null):bool
    {
        if(preg_match('/[\x00-\x20\x7f\\\\]/',$baseUrl.$origin)===1)return false;
        $base=parse_url($baseUrl);$supplied=parse_url($origin);
        if(!is_array($base)||!is_array($supplied)||($base['scheme']??null)!=='https'||($supplied['scheme']??null)!=='https'
            ||isset($base['user'])||isset($base['pass'])||isset($base['query'])||isset($base['fragment'])
            ||isset($supplied['user'])||isset($supplied['pass'])||isset($supplied['query'])||isset($supplied['fragment'])
            ||!in_array($supplied['path']??'',['','/'],true)||!is_string($base['host']??null)||!is_string($supplied['host']??null)
            ||strcasecmp($base['host'],$supplied['host'])!==0||($base['port']??443)!==($supplied['port']??443))return false;
        return $fetchSite===null||in_array($fetchSite,['same-origin','none'],true);
    }

    /** Canonical configured service URL only. It never validates user content URLs. */
    public static function serviceBaseUrl(mixed $value):?string
    {
        if(!is_string($value)||$value===''||strlen($value)>2048||$value!==trim($value)||preg_match('/[\x00-\x20\x7f\\\\]/',$value)===1)return null;
        $parts=parse_url($value);if(!is_array($parts)||!is_string($parts['host']??null))return null;
        $host=strtolower(rtrim($parts['host'],'.'));
        foreach(['example.com','example.org','example.net']as$placeholder)if($host===$placeholder||str_ends_with($host,'.'.$placeholder))return null;
        $origin='https://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        if(!self::sameOrigin($value,$origin))return null;
        $path=$parts['path']??'';
        if(preg_match('/%(?![a-f0-9]{2})/i',$path)===1)return null;
        $decoded=rawurldecode($path);
        if(preg_match('/[\x00-\x20\x7f\\\\]/',$decoded)===1||str_contains($decoded,'//')||preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D',$decoded)===1)return null;
        return rtrim($value,'/');
    }

    public static function hash(array $value):string
    {
        $canonical=static function(mixed$item)use(&$canonical):mixed{if(!is_array($item))return$item;if(!array_is_list($item))ksort($item);foreach($item as&$child)$child=$canonical($child);return$item;};
        return hash('sha256',json_encode($canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    public static function hex(mixed $value,int $length):bool{return is_string($value)&&strlen($value)===$length&&preg_match('/\A[a-f0-9]+\z/D',$value)===1;}
    /** Syntax/depth were checked by json_decode; scan only key tokens, never JSON-looking string values. */
    private static function uniqueObjectKeys(string $json):void
    {
        $stack=[];$length=strlen($json);
        for($i=0;$i<$length;$i++){
            $byte=$json[$i];
            if($byte==='"'){
                $start=$i;$i++;for(;$i<$length;$i++){if($json[$i]==='\\'){$i++;continue;}if($json[$i]==='"')break;}
                $last=count($stack)-1;
                if($last>=0&&$stack[$last]['kind']==='object'&&$stack[$last]['key']){
                    $key=json_decode(substr($json,$start,$i-$start+1),true,2,JSON_THROW_ON_ERROR);$index='key:'.$key;
                    if(isset($stack[$last]['seen'][$index]))self::invalid();$stack[$last]['seen'][$index]=true;$stack[$last]['key']=false;
                }
            }elseif($byte==='{')$stack[]=['kind'=>'object','key'=>true,'seen'=>[]];
            elseif($byte==='[')$stack[]=['kind'=>'array','key'=>false];
            elseif($byte==='}'||$byte===']')array_pop($stack);
            elseif($byte===','){$last=count($stack)-1;if($last>=0&&$stack[$last]['kind']==='object')$stack[$last]['key']=true;}
        }
    }
    private static function keys(array$value,array$allowed,array$required=[]):void{if(array_diff(array_keys($value),$allowed)!==[]||array_diff($required,array_keys($value))!==[])self::invalid();}
    private static function invalid():never{throw new PostedTelegramWebException('invalid_request','Не удалось прочитать действие. Откройте окно заново.',400);}
}
