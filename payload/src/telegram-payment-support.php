<?php
declare(strict_types=1);

/** Honest placeholder, independent of subscription licenses and native paid media. */
final class GitPostedTelegramPaymentSupport
{
    public static function matches(string $text,array $config): bool
    {
        if(preg_match('/\A\/paysupport(?:@([A-Za-z0-9_]{5,32}))?(?:\s.*)?\z/isD',$text,$match)!==1)return false;
        if(!isset($match[1])||$match[1]==='')return true;
        $own=ltrim(trim((string)($config['bot_username']??'')),'@');
        return preg_match('/\A[A-Za-z0-9_]{5,32}\z/D',$own)===1&&strcasecmp($own,$match[1])===0;
    }

    public static function reply(array $config,string $language='ru'): array
    {
        $texts=[
            'ru'=>['Оплата подписок GitPosted пока не подключена. Бот не принимает оплату за подписку и не оформляет возвраты по ней.','Поддержка','Контакт поддержки пока не настроен. Обратитесь к администратору сервиса.'],
            'en'=>['Subscription payments are not connected in GitPosted yet. The bot does not accept subscription payments or issue subscription refunds.','Support','The support contact is not configured yet. Contact the service administrator.'],
            'uk'=>['Оплату підписок GitPosted поки не підключено. Бот не приймає оплату за підписку й не оформлює повернення за нею.','Підтримка','Контакт підтримки поки не налаштований. Зверніться до адміністратора сервісу.'],
        ];
        [$body,$label,$missing]=$texts[$language]??$texts['ru'];
        $username=ltrim(trim((string)($config['service_admin_username']??'')),'@');
        if($username!==''&&preg_match('/\A[A-Za-z0-9_]{5,32}\z/D',$username)!==1)throw new InvalidArgumentException('Invalid configured support username.');
        return ['text'=>$body."\n\n".($username!==''?$label.' — @'.htmlspecialchars($username,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):$missing),
            'parse_mode'=>'HTML','disable_web_page_preview'=>true];
    }
}

/** Hook AFTER the existing private operator gate, BEFORE generic input handling. */
trait GitPostedTelegramPaymentSupportHandler
{
    private function handleTelegramPaymentSupportMessage(int $userId,array $message): bool
    {
        if(!GitPostedTelegramPaymentSupport::matches((string)($message['text']??''),$this->config))return false;
        $reply=GitPostedTelegramPaymentSupport::reply($this->config,$this->telegramUiLanguage($userId));
        $text=$reply['text'];unset($reply['text']);$this->telegram->sendMessage($userId,$text,$reply);return true;
    }
}
