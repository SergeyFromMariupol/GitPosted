<?php
declare(strict_types=1);

/** Captured cURL and fake time only: no platform network, config, or database. */
require dirname(__DIR__) . '/bin/application.php';
require dirname(__DIR__) . '/payload/src/max-bootstrap.php';

function networkAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

// Execute the actual SDK class in an isolated namespace. Only its cURL and
// sleep functions are supplied by this fixture; the retry code is unchanged.
$tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/payload/src/max-bootstrap.php'));
$sdk = '';
for ($i = 0, $length = count($tokens); $i < $length; ++$i) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) continue;
    for ($j = $i + 1; $j < $length && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; ++$j) {}
    if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== 'GitPostedMaxApi') continue;
    $opened = false; $depth = 0;
    for ($j = $i; $j < $length; ++$j) {
        $token = $tokens[$j]; $sdk .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $opened = true; }
        elseif ($token === '}') --$depth;
        if ($opened && $depth === 0) break;
    }
    break;
}
if ($sdk === '') throw new RuntimeException('SDK declaration was not found');
eval(<<<'PHP'
namespace GitPostedNetworkFixture;
use \RuntimeException;
use \InvalidArgumentException;
use \JsonException;
use \stdClass;
use \GitPostedMaxApiException;
function curl_init(string $url): object { $GLOBALS['network_state']['urls'][]=$url; return new \stdClass(); }
function curl_setopt_array(object $handle,array $options): bool { $GLOBALS['network_state']['options'][]=$options; return true; }
function curl_exec(object $handle): string|false {
    $state=&$GLOBALS['network_state'];$response=array_shift($state['responses']);
    if (!is_array($response)) throw new \RuntimeException('Unexpected network attempt');
    $state['last']=$response;$state['clock']+=$response['seconds'];
    return $response['raw'];
}
function curl_error(object $handle): string { return 'Captured timeout'; }
function curl_getinfo(object $handle,int $option): int { return $GLOBALS['network_state']['last']['status']; }
function curl_close(object $handle): void {}
function usleep(int $microseconds): void { $GLOBALS['network_state']['clock']+=$microseconds/1000000; }
PHP
. $sdk);

function networkState(array $responses): void {
    $GLOBALS['network_state']=['responses'=>$responses,'urls'=>[],'options'=>[],'clock'=>0.0,'last'=>[]];
}
function timeoutResponse(): array { return ['raw'=>false,'status'=>0,'seconds'=>15]; }
function successfulResponse(): array { return ['raw'=>'{"user_id":123,"username":"FixtureBot"}','status'=>200,'seconds'=>2]; }
function maxFixtureConfig(): array { return ['max_bot_token'=>'fixture-opaque-token','max_api_base_url'=>'https://api.fixture.example']; }

function telegramNetworkDeclaration(string $file, string $name): string {
    $tokens = token_get_all(file_get_contents($file));
    for ($i = 0, $length = count($tokens); $i < $length; ++$i) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) continue;
        for ($j = $i + 1; $j < $length && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; ++$j) {}
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][1] !== $name) continue;
        $declaration = ''; $opened = false; $depth = 0;
        for ($j = $i; $j < $length; ++$j) {
            $token = $tokens[$j]; $declaration .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $opened = true; }
            elseif ($token === '}') --$depth;
            if ($opened && $depth === 0) return $declaration;
        }
    }
    throw new RuntimeException('Telegram fixture declaration was not found');
}

// Actual application policy and actual Telegram SDK, with cURL alone captured.
// The fixture clock honours CURLOPT_TIMEOUT; no network or wall-clock wait occurs.
eval(<<<'PHP'
namespace GitPostedTelegramNetworkFixture;
use \RuntimeException;
use \InvalidArgumentException;
use \Throwable;
use \InstallerApplicationFailure;
class GitPostedPostLinkAssets { public static function containsMarker(array $payload): bool { return false; } }
function curl_init(string $url): object { $GLOBALS['telegram_network_state']['urls'][]=$url; return new \stdClass(); }
function curl_setopt_array(object $handle,array $options): bool { $handle->options=$options; $GLOBALS['telegram_network_state']['options'][]=$options; return true; }
function curl_setopt(object $handle,int $option,mixed $value): bool { return true; }
function curl_exec(object $handle): string|false {
    $state=&$GLOBALS['telegram_network_state'];$response=array_shift($state['responses']);
    if (!is_array($response)) throw new RuntimeException('Unexpected Telegram network attempt');
    $timeout=$handle->options[CURLOPT_TIMEOUT];$expired=$response['seconds']>$timeout;
    $state['clock']+=min($response['seconds'],$timeout);
    $state['last']=['status'=>$expired?0:200,'expired'=>$expired];
    return $expired?false:json_encode($response['body'],JSON_THROW_ON_ERROR);
}
function curl_error(object $handle): string { return $GLOBALS['telegram_network_state']['last']['expired']?'Captured Telegram timeout':''; }
function curl_getinfo(object $handle,int $option): int { return $GLOBALS['telegram_network_state']['last']['status']; }
function curl_close(object $handle): void {}
PHP
. telegramNetworkDeclaration(dirname(__DIR__).'/payload/src/bootstrap.php','PostedTelegramRequestException')
. telegramNetworkDeclaration(dirname(__DIR__).'/payload/src/bootstrap.php','PostedTelegram')
. telegramNetworkDeclaration(dirname(__DIR__).'/bin/application.php','InstallerApplication'));

function telegramNetworkState(float $identitySeconds, float $webhookSeconds): void {
    $events=['message','channel_post','edited_channel_post','callback_query','my_chat_member','chat_member'];
    $GLOBALS['telegram_network_state']=['clock'=>0.0,'urls'=>[],'options'=>[],'last'=>[], 'responses'=>[
        ['seconds'=>$identitySeconds,'body'=>['ok'=>true,'result'=>['id'=>123456,'is_bot'=>true,'username'=>'FixtureTelegramBot']]],
        ['seconds'=>$webhookSeconds,'body'=>['ok'=>true,'result'=>['url'=>'https://selfhost.example/webhook.php','allowed_updates'=>$events]]],
    ]];
}
function telegramNetworkToken(): string { return '123456:'.str_repeat('t',32); }

$tests=[];
$tests['Telegram interactive check stops before its two slow reads exceed the broker deadline'] = static function (): void {
    telegramNetworkState(29,8);
    $result=(new \GitPostedTelegramNetworkFixture\InstallerApplication())->run(['action'=>'check','platform'=>'telegram','token'=>telegramNetworkToken()]);
    networkAssert(!$result['ok'] && $result['error']['code']==='PLATFORM_API_FAILED',
        'Slow Telegram reads outlived the broker deadline: clock='.$GLOBALS['telegram_network_state']['clock'].'; calls='.count($GLOBALS['telegram_network_state']['urls']));
    networkAssert($GLOBALS['telegram_network_state']['clock']===15.0 && count($GLOBALS['telegram_network_state']['urls'])===1,'Interactive Telegram read was not bounded to fifteen seconds');
};
$tests['Telegram interactive check completes two allowed reads within thirty seconds'] = static function (): void {
    telegramNetworkState(14,14);
    $result=(new \GitPostedTelegramNetworkFixture\InstallerApplication())->run(['action'=>'check','platform'=>'telegram','token'=>telegramNetworkToken()]);
    networkAssert($result['ok'] && $result['result']['identity']['id']==='123456','Two bounded Telegram reads did not complete');
    networkAssert($GLOBALS['telegram_network_state']['clock']===28.0 && array_column($GLOBALS['telegram_network_state']['options'],CURLOPT_TIMEOUT)===[15,15],'Telegram policy did not bound both reads');
};
$tests['Telegram background verification keeps the normal thirty-second timeout per read'] = static function (): void {
    telegramNetworkState(29,29);
    $app=new \GitPostedTelegramNetworkFixture\InstallerApplication(null,static fn():array=>['table_count'=>33,'max_schema_version'=>5],[
        'bot_token'=>telegramNetworkToken(),'bot_username'=>'FixtureTelegramBot',
        'telegram_bot_enabled'=>false,'max_bot_enabled'=>false,'base_url'=>'https://selfhost.example']);
    $result=$app->run(['action'=>'verify','platforms'=>['telegram']]);
    networkAssert($result['ok'] && $GLOBALS['telegram_network_state']['clock']===58.0,'Background Telegram timeout was shortened');
    networkAssert(array_column($GLOBALS['telegram_network_state']['options'],CURLOPT_TIMEOUT)===[30,30],'Default Telegram read policy changed');
};
$tests['Telegram SDK rejects invalid timeouts before starting a request'] = static function (): void {
    telegramNetworkState(1,1);
    foreach ([0,31] as $timeout) {
        try { new \GitPostedTelegramNetworkFixture\PostedTelegram(telegramNetworkToken(),$timeout); throw new RuntimeException('Invalid Telegram timeout accepted'); }
        catch (InvalidArgumentException) {}
    }
    networkAssert($GLOBALS['telegram_network_state']['urls']===[],'Invalid Telegram policy reached transport');
};
$tests['the default SDK completes a read which recovers on its third attempt'] = static function (): void {
    networkState([timeoutResponse(),timeoutResponse(),successfulResponse()]);
    $result=(new \GitPostedNetworkFixture\GitPostedMaxApi(maxFixtureConfig()))->me();
    networkAssert($result['user_id']===123 && count($GLOBALS['network_state']['urls'])===3,'Default retries changed');
    networkAssert($GLOBALS['network_state']['clock']===33.0,'Unexpected bounded retry timing');
};
$tests['interactive SDK stops after one timeout without consuming recovery retries'] = static function (): void {
    networkState([timeoutResponse(),timeoutResponse(),successfulResponse()]);
    try { (new \GitPostedNetworkFixture\GitPostedMaxApi(maxFixtureConfig(),1))->me(); throw new RuntimeException('Timeout accepted'); }
    catch (GitPostedMaxApiException $error) { networkAssert($error->httpStatus===0,'Wrong read error'); }
    networkAssert(count($GLOBALS['network_state']['urls'])===1 && $GLOBALS['network_state']['clock']===15.0,'Interactive read exceeded one attempt');
};
$tests['default transport failure remains bounded to four attempts'] = static function (): void {
    networkState(array_fill(0,4,timeoutResponse()));
    try { (new \GitPostedNetworkFixture\GitPostedMaxApi(maxFixtureConfig()))->me(); throw new RuntimeException('Timeout accepted'); }
    catch (GitPostedMaxApiException) {}
    networkAssert(count($GLOBALS['network_state']['urls'])===4 && $GLOBALS['network_state']['clock']===62.5,'Default finite retry budget changed');
};
$tests['SDK rejects invalid attempt budgets before starting any request'] = static function (): void {
    networkState([]);
    foreach ([0,5] as $attempts) {
        try { new \GitPostedNetworkFixture\GitPostedMaxApi(maxFixtureConfig(),$attempts); throw new RuntimeException('Invalid budget accepted'); }
        catch (InvalidArgumentException) {}
    }
    networkAssert($GLOBALS['network_state']['urls']===[],'Invalid policy reached transport');
};
function applicationFixture(array &$calls, array &$rows): InstallerApplication {
    $transport=static function(string $platform,string $token,string $method,string $path,array $body,int $attempts) use (&$calls,&$rows): array {
        $calls[]=[$path,$attempts];
        if ($path==='/me') return ['user_id'=>123,'username'=>'FixtureBot'];
        if ($path==='/subscriptions') {
            if ($method==='POST') { $rows=[['url'=>$body['url'],'update_types'=>$body['update_types']]]; return ['success'=>true]; }
            if ($method==='GET') return ['subscriptions'=>$rows];
            throw new RuntimeException('Unexpected deletion');
        }
        if ($path==='/me/commands') return ['commands'=>$body['commands']];
        throw new RuntimeException('Unexpected fixture API call');
    };
    return new InstallerApplication($transport,static fn(): array=>['table_count'=>33,'max_schema_version'=>5],[
        'max_bot_token'=>'fixture-opaque-token','max_bot_username'=>'FixtureBot','max_webhook_secret'=>str_repeat('s',64),
        'telegram_bot_enabled'=>false,'max_bot_enabled'=>false,'base_url'=>'https://selfhost.example']);
}
$tests['interactive application check gives both read calls a single attempt'] = static function (): void {
    $calls=[];$rows=[];$app=applicationFixture($calls,$rows);
    $result=$app->run(['action'=>'check','platform'=>'max','token'=>'fixture-opaque-token']);
    networkAssert($result['ok'] && $calls===[['/me',1],['/subscriptions',1]],'Read check did not stay within its two-call budget');
};
$tests['background registration and verification keep the normal SDK retries'] = static function (): void {
    $calls=[];$rows=[];$app=applicationFixture($calls,$rows);
    $result=$app->run(['action'=>'register','platform'=>'max','takeover'=>false]);
    networkAssert($result['ok'] && count($calls)>2,'Background registration failed');
    networkAssert(array_unique(array_column($calls,1))===[4],'Read check policy leaked into registration');
    $calls=[];$result=$app->run(['action'=>'verify','platforms'=>['max']]);
    networkAssert($result['ok'] && $calls===[['/me',4],['/subscriptions',4]],'Read check policy leaked into verification');
};
foreach ($tests as $name=>$test) { $test();echo 'PASS ',$name,"\n"; }
echo 'network policy tests passed: ',count($tests),"\n";
