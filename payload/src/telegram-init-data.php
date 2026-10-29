<?php
declare(strict_types=1);

final class PostedTelegramWebInitDataException extends RuntimeException
{
    public readonly string $reason;
    public function __construct(string $reason='invalid_init_data')
    {
        $this->reason=in_array($reason,['invalid_init_data','expired_init_data','invalid_auth_clock','invalid_bot_config'],true)?$reason:'invalid_init_data';
        parent::__construct('Telegram authorization failed.');
    }
}

/** Pure server-side bot-token HMAC validator. It does not authorize a post or prevent action replay. */
final class PostedTelegramWebInitData
{
    private const MAX_BYTES=16384;
    private const MAX_PAIRS=32;
    private const MAX_USER_BYTES=8192;
    private const MAX_USER_ID=4503599627370495; // Telegram documents at most 52 significant bits.

    /**
     * @return array{actor_id:int,auth_date:int,expires_at:int,session_digest:string}
     * session_digest is server-only correlation data; do not echo/log raw initData or authentication material.
     */
    public static function verify(string $raw,string $botToken,int $now,int $ttl=300,int $futureSkew=30): array
    {
        if(PHP_INT_SIZE<8||$now<1||$ttl<1||$ttl>300||$futureSkew<0||$futureSkew>30)throw new PostedTelegramWebInitDataException('invalid_auth_clock');
        if(preg_match('/\A[1-9][0-9]{5,11}:[A-Za-z0-9_-]{30,200}\z/D',$botToken)!==1)throw new PostedTelegramWebInitDataException('invalid_bot_config');
        $fields=self::parse($raw);
        $hash=$fields['hash']??null;
        if(!is_string($hash)||preg_match('/\A[a-f0-9]{64}\z/D',$hash)!==1||!isset($fields['auth_date'],$fields['user']))throw new PostedTelegramWebInitDataException();
        unset($fields['hash']);ksort($fields,SORT_STRING);$lines=[];
        foreach($fields as$key=>$value)$lines[]=$key.'='.$value;
        $check=implode("\n",$lines);
        // Bot-token validation excludes only hash. Optional signature is signed too.
        // The separate third-party Ed25519 algorithm has different exclusions.
        $secret=hash_hmac('sha256',$botToken,'WebAppData',true);
        $expected=hash_hmac('sha256',$check,$secret);
        if(!hash_equals($expected,$hash))throw new PostedTelegramWebInitDataException();
        $date=$fields['auth_date'];
        if(preg_match('/\A[1-9][0-9]{0,9}\z/D',$date)!==1)throw new PostedTelegramWebInitDataException();
        $authDate=(int)$date;
        if((string)$authDate!==$date||$authDate>$now+$futureSkew)throw new PostedTelegramWebInitDataException();
        if($authDate<$now-$ttl)throw new PostedTelegramWebInitDataException('expired_init_data');
        $userRaw=$fields['user'];
        if(strlen($userRaw)>self::MAX_USER_BYTES)throw new PostedTelegramWebInitDataException();
        try{$user=json_decode($userRaw,false,8,JSON_THROW_ON_ERROR);}catch(JsonException){throw new PostedTelegramWebInitDataException();}
        if(!$user instanceof stdClass)throw new PostedTelegramWebInitDataException();
        $props=get_object_vars($user);
        // WebAppUser is a flat object. Unknown scalar fields may be signed but never become authority.
        foreach($props as$value)if(is_array($value)||is_object($value))throw new PostedTelegramWebInitDataException();
        self::uniqueJsonKeys($userRaw);
        $actor=$props['id']??null;
        if(!is_int($actor)||$actor<1||$actor>self::MAX_USER_ID)throw new PostedTelegramWebInitDataException();
        if(array_key_exists('is_bot',$props)&&$props['is_bot']!==false)throw new PostedTelegramWebInitDataException();
        return ['actor_id'=>$actor,'auth_date'=>$authDate,'expires_at'=>$authDate+$ttl,
            'session_digest'=>hash('sha256',$check."\nhash=".$hash)];
    }

    /** No parse_str: preserve key spelling and reject duplicate decoded names before signing. */
    private static function parse(string $raw): array
    {
        if($raw===''||strlen($raw)>self::MAX_BYTES)throw new PostedTelegramWebInitDataException();
        $pairs=explode('&',$raw);if(count($pairs)>self::MAX_PAIRS)throw new PostedTelegramWebInitDataException();
        $fields=[];
        foreach($pairs as$pair){
            $parts=explode('=',$pair,2);if(count($parts)!==2)throw new PostedTelegramWebInitDataException();
            [$key,$value]=array_map(static function(string$part):string{
                if(preg_match('/%(?![a-fA-F0-9]{2})/',$part))throw new PostedTelegramWebInitDataException();
                $decoded=rawurldecode(str_replace('+',' ',$part));
                if(preg_match('//u',$decoded)!==1||preg_match('/[\x00-\x1F\x7F]/',$decoded))throw new PostedTelegramWebInitDataException();
                return$decoded;
            },$parts);
            if(preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D',$key)!==1||array_key_exists($key,$fields))throw new PostedTelegramWebInitDataException();
            $fields[$key]=$value;
        }return$fields;
    }

    /** Full JSON syntax is checked first; inspect string tokens for duplicate flat-object keys. */
    private static function uniqueJsonKeys(string $json): void
    {
        $seen=[];$length=strlen($json);
        for($i=0;$i<$length;$i++){
            if($json[$i]!=='"')continue;
            $start=$i;$escaped=false;
            for($i++;$i<$length;$i++){
                if($escaped){$escaped=false;continue;}
                if($json[$i]==='\\'){$escaped=true;continue;}
                if($json[$i]==='"')break;
            }
            $next=$i+1;while($next<$length&&str_contains(" \t\r\n",$json[$next]))$next++;
            if($next>=$length||$json[$next]!==':')continue;
            try{$key=json_decode(substr($json,$start,$i-$start+1),true,2,JSON_THROW_ON_ERROR);}catch(JsonException){throw new PostedTelegramWebInitDataException();}
            if(!is_string($key)||array_key_exists($key,$seen))throw new PostedTelegramWebInitDataException();$seen[$key]=true;
        }
    }
}
