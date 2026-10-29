<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-policy.php';

/** Fixed policy-aware command menus shared by installation and attachment. */
function gitpostedCommandPlatformEnabled(array $config,string $platform,bool $attachmentIntent=false):bool
{
    if(!in_array($platform,['telegram','max'],true))throw new InvalidArgumentException('Unknown command platform.');
    $key=$platform.'_bot_enabled';
    if(array_key_exists($key,$config)&&!is_bool($config[$key]))throw new InvalidArgumentException('Platform enable flags must be booleans.');
    $legacy=$platform==='telegram'||(is_string($config['max_bot_token']??null)&&trim($config['max_bot_token'])!=='');
    return $attachmentIntent||($config[$key]??$legacy);
}

function gitpostedCommandMenuMode(array $config,bool $attachmentIntent=false): array
{
    $enabled=gitpostedCommandPlatformEnabled($config,'telegram',$attachmentIntent);
    if(!$enabled)return ['telegram'=>false,'max'=>false,'pricing'=>'free','access'=>'open'];
    $policy=PostedTelegramPolicy::fromConfig($config);
    // The legacy runtime predates platform flags. An explicit false always wins.
    return ['telegram'=>true,'max'=>gitpostedCommandPlatformEnabled($config,'max'),'pricing'=>$policy['pricing_mode'],'access'=>$policy['access_mode']];
}

/** Fixed languages, no API calls. Access mode never changes a public command menu. */
function gitpostedTelegramCommandSets(array $config=[],bool $attachmentIntent=false): array
{
    $mode=gitpostedCommandMenuMode($config,$attachmentIntent);if(!$mode['telegram'])return[];
    $labels=[
        'ru'=>[
            'start'=>'Перезапустить бота','create'=>'Создать пост','help'=>'Помощь','lang'=>'Сменить язык',
            'edit'=>'Изменить пост','content'=>'Контент-план','templates'=>'Шаблоны','settings'=>'Настройки',
            'pay'=>'Приобрести подписку','catalog'=>'Каталог эмодзи','addemoji'=>'Добавить набор эмодзи',
            'paysupport'=>'Поддержка по оплате','support'=>'Поддержка','privacy'=>'Политика конфиденциальности','maxlink'=>'Связать аккаунт МАКС',
        ],
        'en'=>[
            'start'=>'Restart bot','create'=>'Create post','help'=>'Help','lang'=>'Change language',
            'edit'=>'Edit post','content'=>'Content plan','templates'=>'Templates','settings'=>'Settings',
            'pay'=>'Buy subscription','catalog'=>'Emoji catalog','addemoji'=>'Add emoji set',
            // Original says "Issue a refund". No refund action exists in this service.
            'paysupport'=>'Payment support','support'=>'Support','privacy'=>'Privacy policy','maxlink'=>'Link МАКС account',
        ],
        'uk'=>[
            'start'=>'Перезапустити бота','create'=>'Створити допис','help'=>'Допомога','lang'=>'Змінити мову',
            'edit'=>'Змінити допис','content'=>'Контент-план','templates'=>'Шаблони','settings'=>'Налаштування',
            'pay'=>'Придбати підписку','catalog'=>'Каталог емодзі','addemoji'=>'Додати набір емодзі',
            'paysupport'=>'Підтримка з оплати','support'=>'Підтримка','privacy'=>'Політика конфіденційності','maxlink'=>'Пов’язати акаунт МАКС',
        ],
    ];
    $sets=[];
    foreach(['default'=>'ru','ru'=>'ru','en'=>'en','uk'=>'uk']as$language=>$source){
        $commands=[];
        foreach($labels[$source]as$command=>$description){
            if(in_array($command,['pay','paysupport'],true)&&$mode['pricing']!=='paid')continue;
            if($command==='maxlink'&&!$mode['max'])continue;
            $commands[]=['command'=>$command,'description'=>$description];
        }
        $sets[$language]=$commands;
    }return$sets;
}

function gitpostedMaxCommands(array $config=[],bool $attachmentIntent=false): array
{
    if(!gitpostedCommandPlatformEnabled($config,'max',$attachmentIntent))return[];
    return [
        ['name'=>'start','description'=>'Запустить GitPosted'],
        ['name'=>'menu','description'=>'Открыть главное меню'],
        ['name'=>'help','description'=>'Открыть справку'],
        ['name'=>'cancel','description'=>'Отменить текущее действие'],
    ];
}

/** A reviewable registration plan. It does not contact either platform. */
function gitpostedBotCommandPlan(array $config=[]): array
{
    $telegram=[];
    foreach(gitpostedTelegramCommandSets($config)as$language=>$commands){
        $payload=['commands'=>$commands];if($language!=='default')$payload['language_code']=$language;
        $telegram[]=['method'=>'setMyCommands','payload'=>$payload];
    }
    return ['version'=>1,'telegram'=>$telegram,'max_commands'=>gitpostedMaxCommands($config)];
}

/** Exactly one identity read and at most four command writes; callers use a 5s transport. */
final class GitPostedTelegramCommandRegistrar
{
    public static function register(array $config,callable $transport,bool $attachmentIntent=false):array
    {
        $report=['state'=>'invalid_config','confirmed'=>[],'failed'=>[],'requests'=>0];
        try{
            if(!gitpostedCommandPlatformEnabled($config,'telegram',$attachmentIntent))return array_replace($report,['state'=>'disabled']);
            $sets=gitpostedTelegramCommandSets($config,$attachmentIntent);
            $token=$config['bot_token']??null;$username=$config['bot_username']??null;
            if(!is_string($token)||preg_match('/\A([1-9][0-9]{5,11}):[A-Za-z0-9_-]{30,200}\z/D',$token,$match)!==1
                ||!is_string($username)||preg_match('/\A[A-Za-z0-9_]{5,32}\z/D',$username)!==1)return $report;
        }catch(Throwable){return $report;}
        try{
            $report['requests']++;$me=$transport('getMe',[]);
            $identity=is_array($me)?($me['result']??null):null;
            if(!is_array($identity)||($me['ok']??null)!==true||($identity['is_bot']??null)!==true
                ||!is_int($identity['id']??null)&&!is_string($identity['id']??null)
                ||(string)$identity['id']!==$match[1]||!is_string($identity['username']??null)
                ||strcasecmp($identity['username'],$username)!==0)return array_replace($report,['state'=>'identity_mismatch']);
        }catch(Throwable){return array_replace($report,['state'=>'failed']);}
        foreach($sets as$language=>$commands){
            $payload=['commands'=>$commands];if($language!=='default')$payload['language_code']=$language;
            try{
                $report['requests']++;$reply=$transport('setMyCommands',$payload);
                if(!is_array($reply)||($reply['ok']??null)!==true||($reply['result']??null)!==true)throw new RuntimeException('Command acknowledgement missing.');
                $report['confirmed'][]=$language;
            }catch(Throwable){$report['failed'][]=$language;}
        }
        $report['state']=$report['failed']===[]?'ready':($report['confirmed']===[]?'failed':'partial');
        return $report;
    }

    public static function notice(array $report):string
    {
        return match($report['state']??''){
            'ready'=>' Меню команд Telegram обновлено для всех языков.',
            'disabled'=>' Telegram выключен; его меню команд не изменялось.',
            'partial'=>' Меню команд Telegram обновлено частично. Не подтверждены языки: '.implode(', ',array_intersect(['default','ru','en','uk'],(array)($report['failed']??[]))).'. Повторите обновление отдельной кнопкой.',
            default=>' Меню команд Telegram не обновлено. Настройки сохранены; проверьте подключение бота и повторите обновление отдельной кнопкой.',
        };
    }
}
