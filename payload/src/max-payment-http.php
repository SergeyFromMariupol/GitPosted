<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-billing-api.php';
require_once __DIR__.'/max-policy.php';

/** HTTP is deliberately separate from the ledger so GET/HEAD and CSRF rules can be captured without a DB/provider. */
final class GitPostedMaxPaymentHttp
{
    private static function escape(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function headers():array
    {return['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store, private','Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow','X-Content-Type-Options'=>'nosniff',
        'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self' https://yoomoney.ru https://yookassa.ru; frame-ancestors 'none'"];}
    private static function html(string $title,string $body):string
    {return'<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::escape($title).' — GitPosted</title><style>body{margin:0;padding:24px;background:#0e1823;color:#e8eef5;font:16px/1.55 system-ui,sans-serif}main{max-width:640px;margin:6vh auto;padding:28px;background:#182736;border-radius:18px}h1{font-size:26px;line-height:1.25}a{color:#74bfff}button{padding:13px 20px;border:0;border-radius:10px;background:#ee9e00;color:#141414;font:inherit;font-weight:600;cursor:pointer}li{margin:5px 0}small{color:#b8c8d8}form{margin:20px 0}</style></head><body><main><strong>GitPosted</strong><h1>'.self::escape($title).'</h1>'.$body.'<p><a href="./">На главную GitPosted</a></p></main></body></html>';}
    public static function cookieName(string $token):string{return'gp_max_checkout_'.PostedTelegramBillingApi::quoteToken($token);}
    public static function csrf(array $config,string $token,string $cookie,int $bucket):string
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$cookie)!==1)throw new PostedTelegramBillingException('invalid_csrf',403);$c=PostedTelegramBillingApi::fromConfig($config);
        if(!$c['enabled'])throw new PostedTelegramBillingException('payments_disabled');return hash_hmac('sha256','gitposted-max-checkout-v1|'.PostedTelegramBillingApi::quoteToken($token).'|'.$cookie.'|'.$bucket,$c['secret_key']);
    }
    private static function origin(array $config):string
    {
        $base=(string)($config['base_url']??'');$p=parse_url($base);
        if(!is_array($p)||($p['scheme']??'')!=='https'||!is_string($p['host']??null)||$p['host']===''||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||preg_match('/[\x00-\x20\x7f\\\\]/',$base))throw new PostedTelegramBillingException('invalid_base_url');
        return'https://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    }
    private static function cookiePath(array $config):string
    {$path=(string)(parse_url((string)($config['base_url']??''),PHP_URL_PATH)??'');if(preg_match('~\A/[A-Za-z0-9_%./\~-]*\z~D',$path)!==1&&$path!=='')throw new PostedTelegramBillingException('invalid_base_url');return rtrim($path,'/').'/';}
    private static function support(array $config):string
    {
        $name=$config['service_admin_username']??null;if(!is_string($name)||preg_match('/\A@?[A-Za-z][A-Za-z0-9_]{3,31}\z/D',$name)!==1)return'контакт поддержки указан в боте';$name=ltrim($name,'@');
        return'<a href="https://t.me/'.self::escape($name).'">@'.self::escape($name).'</a>';
    }
    private static function fallback(string $method,array $query):array
    {
        $h=self::headers();if(!in_array($method,['GET','HEAD'],true)){$h['Allow']='GET, HEAD';return[405,$h,''];}
        try{if(array_key_exists('quote',$query))PostedTelegramBillingApi::quoteToken($query['quote']);else{GitPostedMaxPolicy::positiveId($query['channel']??null);GitPostedMaxPolicy::chatId($query['chat']??null);}}catch(Throwable){return[400,$h,$method==='HEAD'?'':self::html('Некорректная ссылка на подписку','<p>Откройте раздел подписки в боте ещё раз.</p>')];}
        $body='<p>Это заглушка страницы оплаты. Платёж не создаётся, деньги не списываются, подписка не активируется.</p><p>Подписка оформляется отдельно на каждый канал. Способ оплаты будет подключён позже.</p>';
        return[200,$h,$method==='HEAD'?'':self::html('Оплата подписки пока недоступна',$body)];
    }
    /** $factory is invoked only for valid configured requests; GET/HEAD call page() only. */
    public static function checkout(string $method,array $query,array $requestHeaders,string $body,array $cookies,array $config,callable $factory,?int $now=null):array
    {
        $method=strtoupper($method);$now??=time();if(!PostedTelegramBillingApi::configured($config))return self::fallback($method,$query);$h=self::headers();
        if(!in_array($method,['GET','HEAD','POST'],true)){$h['Allow']='GET, HEAD, POST';return[405,$h,''];}
        try{
            $token=PostedTelegramBillingApi::quoteToken($query['quote']??null);$origin=self::origin($config);$name=self::cookieName($token);$cookie=$cookies[$name]??null;
            if(!is_string($cookie)||preg_match('/\A[a-f0-9]{64}\z/D',$cookie)!==1)$cookie=null;
            if($method==='POST'){
                if(strlen($body)>4096)throw new PostedTelegramBillingException('invalid_request',400);$input=[];parse_str($body,$input);$keys=array_keys($input);sort($keys);
                if($keys!==['action','csrf']||!is_string($input['action'])||!in_array($input['action'],['pay','status'],true)||!is_string($input['csrf'])||preg_match('/\A[a-f0-9]{64}\z/D',$input['csrf'])!==1||$cookie===null)throw new PostedTelegramBillingException('invalid_csrf',403);
                $from=null;foreach($requestHeaders as$k=>$v)if(strtolower((string)$k)==='origin')$from=$v;
                if($from!==null&&$from!==$origin)throw new PostedTelegramBillingException('invalid_origin',403);
                $bucket=intdiv($now,1800);if(!hash_equals(self::csrf($config,$token,$cookie,$bucket),$input['csrf'])&&!hash_equals(self::csrf($config,$token,$cookie,$bucket-1),$input['csrf']))throw new PostedTelegramBillingException('invalid_csrf',403);
                $billing=$factory();$result=$input['action']==='pay'?$billing->pay($token):$billing->status($token,true);
                $url=$input['action']==='pay'?PostedTelegramBillingApi::redirect($result['confirmation_url']??null):null;
                if($url!==null){$h['Location']=$url;return[303,$h,''];}
                $h['Location']=GitPostedMaxPolicy::checkoutSelectionUrl((string)$config['base_url'],$token);return[303,$h,''];
            }
            $billing=$factory();$view=$billing->page($token);if($method==='HEAD')return[200,$h,''];
            if($cookie===null){$cookie=bin2hex(random_bytes(32));$h['Set-Cookie']=$name.'='.$cookie.'; Max-Age=3600; Path='.self::cookiePath($config).'; Secure; HttpOnly; SameSite=Strict';}
            $state=$view['state']??null;$labels=['quote'=>'Подписка на выбранные каналы MAX','creating'=>'Платёж создаётся','create_unknown'=>'Результат создания платежа проверяется','create_review'=>'Нужна проверка платежа','pending'=>'Ожидается оплата','waiting_capture'=>'Платёж ожидает подтверждения провайдера','funded'=>'Оплата подтверждена','canceled'=>'Платёж отменён','paid_review'=>'Оплата получена — нужна проверка','refund_review'=>'Возврат подтверждён — нужна проверка'];
            if(!is_string($state)||!isset($labels[$state])||!is_int($view['amount_minor']??null)||!is_int($view['period_days']??null)||!is_array($view['channel_titles']??null)||!is_bool($view['can_pay']??null)||!is_bool($view['can_check']??null))throw new PostedTelegramBillingException('invalid_page');
            $html='<p><strong>'.self::escape(PostedTelegramBillingApi::amount($view['amount_minor'])).' ₽</strong> · '.self::escape((string)$view['period_days']).' дней · каналов: '.count($view['channel_titles']).'</p><ul>';
            foreach($view['channel_titles']as$title){if(!is_string($title)||mb_strlen($title,'UTF-8')>100)throw new PostedTelegramBillingException('invalid_page');$html.='<li>'.self::escape($title).'</li>';}$html.='</ul>';
            if($view['order_label']!==null)$html.='<p><small>Номер заказа: '.self::escape((string)$view['order_label']).'</small></p>';
            $html.=match($state){
                'funded'=>'<p>Оплата подтверждена сервером YooKassa. Подписка была активирована на каналах из заказа. Повторное уведомление не продлевает её ещё раз.</p>',
                'paid_review'=>'<p>Оплата получена и сохранена. Не удалось безопасно подтвердить данные для активации подписки. Обратитесь в поддержку: '.self::support($config).'. Сохраните номер заказа.</p>',
                'refund_review'=>'<p>Возврат подтверждён. Подписки и более поздние продления автоматически не удаляются: требуется проверка поддержки '.self::support($config).'. Сохраните номер заказа.</p>',
                'create_review'=>'<p>Новый запрос оплаты не выполняется. Нужна проверка поддержки '.self::support($config).': результат прежнего запроса нельзя безопасно повторить. Сохраните номер заказа.</p>',
                'create_unknown'=>'<p>Запрос на оплату уже отправлен. Проверка не создаст второй платёж. Если результат недоступен, обратитесь в поддержку '.self::support($config).'.</p>',
                'canceled'=>'<p>Провайдер подтвердил отмену. Подписка по этому заказу не активируется. Новый выбор оформите в боте.</p>',
                default=>'<p>Сумма и срок заказа фиксируются перед созданием платежа. Карта и другие платёжные данные вводятся на странице YooKassa. Возврат на эту страницу сам по себе не подтверждает оплату.</p>',
            };
            $csrf=self::csrf($config,$token,$cookie,intdiv($now,1800));$form=static fn(string$action,string$text):string=>'<form method="post"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="csrf" value="'.$csrf.'"><button type="submit">'.self::escape($text).'</button></form>';
            if($view['can_pay'])$html.=$form('pay',$state==='quote'?'Перейти к оплате':'Проверить создание платежа');
            elseif(($view['confirmation_url']??null)!==null&&!in_array($state,['funded','paid_review','refund_review','canceled'],true))$html.='<p><a href="'.self::escape((string)PostedTelegramBillingApi::redirect($view['confirmation_url'])).'">Продолжить оплату в YooKassa</a></p>';
            if($view['can_check'])$html.=$form('status','Проверить оплату');
            $html.='<p><a href="'.self::escape(GitPostedMaxPolicy::checkoutSelectionUrl((string)$config['base_url'],$token)).'">Обновить состояние</a></p>';
            return[200,$h,self::html($labels[$state],$html)];
        }catch(PostedTelegramBillingException$e){if($e->reason==='billing_not_required')return[403,$h,$method==='HEAD'?'':self::html('Оплата не требуется','<p>Сейчас бот работает бесплатно. Новый платёж не требуется.</p>')];
            $status=in_array($e->httpStatus,[400,403,404,410],true)?$e->httpStatus:503;return[$status,$h,$method==='HEAD'?'':self::html('Не удалось проверить оплату','<p>Платёж не подтверждён этим экраном. Откройте подписку в боте или обратитесь в поддержку '.self::support($config).'. Новый платёж автоматически не создаётся.</p>')];}
        catch(Throwable){return[503,$h,$method==='HEAD'?'':self::html('Состояние оплаты проверяется','<p>Обновите состояние позже. Повторный платёж автоматически не создаётся.</p>')];}
    }
}
