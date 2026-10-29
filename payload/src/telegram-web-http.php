<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-web-request.php';
require_once __DIR__.'/telegram-init-data.php';

/** Side-effect-free GET. Only authenticated same-origin POST can construct an application adapter. */
final class PostedTelegramWebHttp
{
    public static function dispatch(string$method,array$query,array$headers,string$body,callable$configReader,callable$backendFactory,?int$now=null):array
    {
        $now??=time();$responseHeaders=[
            'Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff',
            'Permissions-Policy'=>'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy'=>"default-src 'none'; script-src 'self' https://telegram.org; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org",
        ];
        if(($query['telegram_webapp']??null)!=='1'||array_diff(array_keys($query),['telegram_webapp','asset','launch'])!==[])return[404,$responseHeaders,''];
        if(in_array($method,['GET','HEAD'],true)){
            $asset=$query['asset']??null;
            $files=[null=>['web-mode.html','text/html; charset=utf-8'],'script'=>['web-mode.js','application/javascript; charset=utf-8'],'style'=>['web-mode.css','text/css; charset=utf-8']];
            if($asset!==null&&(!is_string($asset)||!in_array($asset,['script','style'],true)))return[404,$responseHeaders,''];
            [$name,$mime]=$files[$asset];$responseHeaders['Content-Type']=$mime;$bytes=file_get_contents(__DIR__.'/'.$name);
            if(!is_string($bytes)||strlen($bytes)>131072)return[503,$responseHeaders,''];return[200,$responseHeaders,$method==='HEAD'?'':$bytes];
        }
        $responseHeaders['Content-Type']='application/json; charset=utf-8';
        if($method!=='POST'){$responseHeaders['Allow']='GET, HEAD, POST';return self::json(405,$responseHeaders,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Это действие недоступно.']]);}
        try{
            if(isset($query['asset']))throw new PostedTelegramWebException('invalid_request','Не удалось прочитать действие.',400);
            $config=$configReader();
            if(!is_array($config)||!PostedTelegramWebRequest::sameOrigin((string)($config['base_url']??''),(string)($headers['origin']??''),isset($headers['sec-fetch-site'])?(string)$headers['sec-fetch-site']:null))throw new PostedTelegramWebException('origin_denied','Откройте окно из карточки поста в Telegram.',403);
            if(($config['telegram_web_window_enabled']??false)!==true||(array_key_exists('telegram_bot_enabled',$config)&&$config['telegram_bot_enabled']!==true)||PostedTelegramWebRequest::serviceBaseUrl($config['base_url']??null)===null)throw new PostedTelegramWebException('not_connected','Веб-окно пока недоступно. Вернитесь в бот.',503);
            if(preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/iD',(string)($headers['content-type']??''))!==1)throw new PostedTelegramWebException('invalid_request','Не удалось прочитать действие.',415);
            $request=PostedTelegramWebRequest::parse($body);$identity=PostedTelegramWebInitData::verify($request['init_data'],(string)($config['bot_token']??''),$now);
            // A query selector is public and never supplies actor/post authorization.
            if(isset($query['launch'])&&(!PostedTelegramWebRequest::hex($query['launch'],48)||$query['launch']!==$request['launch']))throw new PostedTelegramWebException('invalid_request','Откройте настройки поста заново.',400);
            $backend=$backendFactory($config);$result=$backend->telegramWebAction($identity,$request,$now);
            return self::json(200,$responseHeaders,$result);
        }catch(PostedTelegramWebInitDataException$error){
            $invalidConfig=$error->reason==='invalid_bot_config';return self::json($invalidConfig?503:401,$responseHeaders,['ok'=>false,'error'=>['code'=>$error->reason,'message'=>$invalidConfig?'Веб-окно пока недоступно. Вернитесь в бот.':'Откройте окно заново из Telegram.']]);
        }catch(PostedTelegramWebException$error){return self::json($error->httpStatus,$responseHeaders,['ok'=>false,'error'=>['code'=>$error->reason,'message'=>$error->getMessage(),'reload'=>in_array($error->reason,['stale_revision','stale_confirmation'],true)]]);}
        catch(Throwable$error){error_log('GitPosted web request: '.$error::class);return self::json(500,$responseHeaders,['ok'=>false,'error'=>['code'=>'request_failed','message'=>'Не удалось выполнить действие. Проверьте пост в боте.']]);}
    }
    private static function json(int$status,array$headers,array$value):array{return[$status,$headers,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];}
}
