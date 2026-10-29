<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-web-http.php';

$method=(string)($_SERVER['REQUEST_METHOD']??'GET');$headers=[];
foreach(['origin'=>'HTTP_ORIGIN','content-type'=>'CONTENT_TYPE','sec-fetch-site'=>'HTTP_SEC_FETCH_SITE']as$key=>$serverKey)if(isset($_SERVER[$serverKey]))$headers[$key]=(string)$_SERVER[$serverKey];
$body=$method==='POST'?(string)file_get_contents('php://input',false,null,0,65537):'';
[$status,$responseHeaders,$responseBody]=PostedTelegramWebHttp::dispatch($method,$_GET,$headers,$body,
    static function():array{
        if(!is_file(__DIR__.'/bootstrap.php'))throw new PostedTelegramWebException('not_connected','Веб-окно ещё не подключено. Вернитесь в бот.',503);
        require_once __DIR__.'/bootstrap.php';return PostedConfig::get();
    },
    static function(array$config):object{
        $pdo=PostedDb::pdo($config);PostedDb::assertSchema($pdo);return new PostedBot($pdo,new PostedTelegram((string)$config['bot_token']),$config);
    }
);
http_response_code($status);foreach($responseHeaders as$name=>$value)header($name.': '.$value);echo$responseBody;
