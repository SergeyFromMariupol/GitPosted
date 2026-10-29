<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Bounded settings transport only. No payment, message, SQL, persistence or retry loop. */
final class PostedAdminSettingsConnections
{
    private Closure $rpc;
    private Closure $current;
    private int $calls = 0;
    private float $deadline;
    private const TG_TYPES = ['message','channel_post','edited_channel_post','callback_query','my_chat_member','chat_member'];

    public function __construct(callable $rpc, callable $current, private array $maxTypes, float $seconds = 40.0)
    {
        if ($seconds < 1.0 || $seconds > 40.0 || $maxTypes === [] || !array_is_list($maxTypes)) throw new LogicException('Invalid connection budget.');
        foreach ($maxTypes as $type) if (!is_string($type) || $type === '') throw new LogicException('Invalid MAX events.');
        $this->rpc = Closure::fromCallable($rpc); $this->current = Closure::fromCallable($current);
        $this->deadline = microtime(true) + $seconds;
    }

    private function call(string $platform, array $config, string $method, array $params = []): array
    {
        $allowed = $platform === 'telegram'
            ? ['getMe','getWebhookInfo','setWebhook','deleteWebhook'] : ($platform === 'max'
                ? ['me','subscriptions','subscribe','unsubscribe'] : []);
        if (!in_array($method, $allowed, true) || ++$this->calls > 18 || microtime(true) >= $this->deadline) throw new RuntimeException('Проверка подключения не завершена.');
        ($this->current)(); // Exact saved revision before every read/effect, including cleanup.
        $result = ($this->rpc)($platform, $config, $method, $params);
        if (!is_array($result)) throw new RuntimeException('Подключение не подтверждено.');
        if ($platform === 'telegram') {
            if (($result['ok'] ?? null) !== true || !array_key_exists('result', $result)) throw new RuntimeException('Telegram не подтвердил подключение.');
        } elseif (($result['success'] ?? true) === false) throw new RuntimeException('МАКС не подтвердил подключение.');
        return $result;
    }

    private static function identity(array $response, string $platform, string $name): string
    {
        $data = $platform === 'telegram' ? ($response['result'] ?? null) : $response;
        $id = is_array($data) ? ($data[$platform === 'telegram' ? 'id' : 'user_id'] ?? null) : null;
        if (is_int($id)) $id = (string) $id;
        if (!is_array($data) || !is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1
            || !is_string($data['username'] ?? null) || strcasecmp(ltrim($data['username'], '@'), $name) !== 0
            || ($platform === 'telegram' && ($data['is_bot'] ?? null) !== true)) throw new RuntimeException('Username и токен принадлежат разным ботам или не подтверждены.');
        return $id;
    }

    private static function tgInfo(array $response): array
    {
        $v = $response['result'] ?? null;
        if (!is_array($v) || !is_string($v['url'] ?? null) || strlen($v['url']) > 4096) throw new RuntimeException('Telegram не вернул состояние webhook.');
        return $v;
    }

    private function maxInfo(array $response, string $url): array
    {
        $rows = $response['subscriptions'] ?? (array_is_list($response) ? $response : null);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) throw new RuntimeException('МАКС не вернул список подписок.');
        $matching = 0; $current = 0; $foreign = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null) || strlen($row['url']) > 4096) throw new RuntimeException('МАКС вернул некорректную подписку.');
            if ($row['url'] !== $url) { $foreign++; continue; }
            $matching++; $types = $row['update_types'] ?? null;
            if (!is_array($types) || !array_is_list($types)) continue;
            foreach ($types as $type) if (!is_string($type)) throw new RuntimeException('МАКС вернул некорректные события.');
            $actual = array_values(array_unique($types)); $expected = array_values(array_unique($this->maxTypes));
            sort($actual, SORT_STRING); sort($expected, SORT_STRING);
            if ($actual === $expected) $current++;
        }
        if ($matching > 1) throw new RuntimeException('Для этого адреса найдены неоднозначные подписки МАКС.');
        return ['matching'=>$matching,'current'=>$current,'foreign'=>$foreign];
    }

    public static function changes(array $before, array $after, array $pending = []): array
    {
        $result = [];
        foreach (['telegram'=>['bot_token','bot_username','webhook_secret'], 'max'=>['max_bot_token','max_bot_username','max_webhook_secret']] as $platform=>$keys) {
            $changed = PostedAdminSettings::effectiveEnabled($before,$platform) !== PostedAdminSettings::effectiveEnabled($after,$platform)
                || ($pending[$platform] ?? null) === true;
            foreach ($keys as $key) if (($before[$key] ?? '') !== ($after[$key] ?? '')) $changed = true;
            if ($changed) $result[] = $platform;
        }
        return $result;
    }

    /** RO preflight outside config/SQL locks. Only MAX same-bot secret selection can refine next. */
    public function preflight(array $before, array $next, array $options, array $pending = []): array
    {
        $plans = [];
        foreach (self::changes($before,$next,$pending) as $platform) {
            $tg = $platform === 'telegram'; $tokenKey = $tg ? 'bot_token' : 'max_bot_token'; $nameKey = $tg ? 'bot_username' : 'max_bot_username';
            $token = (string) ($next[$tokenKey] ?? '');
            if ($token === '' && !PostedAdminSettings::effectiveEnabled($next,$platform)) continue;
            $secretKey=$tg?'webhook_secret':'max_webhook_secret';
            if (($next[$secretKey] ?? '') !== ($before[$secretKey] ?? '') || PostedAdminSettings::effectiveEnabled($next,$platform)) {
                $pattern=$tg?'/\A[A-Za-z0-9_-]{32,256}\z/D':'/\A[A-Za-z0-9_-]{16,256}\z/D';
                if(preg_match($pattern,(string)($next[$secretKey]??''))!==1)throw new InvalidArgumentException('Недопустимый формат секрета webhook.');
            }
            $url = rtrim((string)($next['base_url'] ?? ''),'/') . ($tg ? '/webhook.php' : '/max-webhook.php');
            if (!str_starts_with($url,'https://') || filter_var($url,FILTER_VALIDATE_URL) === false) throw new InvalidArgumentException('Для подключения нужен корректный HTTPS-адрес сервиса.');
            $newId = self::identity($this->call($platform,$next,$tg?'getMe':'me'),$platform,(string)$next[$nameKey]);
            if($tg && $newId!==explode(':',$token,2)[0])throw new RuntimeException('Telegram не подтвердил ID токена.');
            $changedToken = ($before[$tokenKey] ?? '') !== $token; $oldId = $changedToken ? null : $newId;
            if ($changedToken && ($before[$tokenKey] ?? '') !== '') {
                try {
                    $oldId = self::identity($this->call($platform,$before,$tg?'getMe':'me'),$platform,(string)($before[$nameKey] ?? ''));
                    if($tg&&$oldId!==explode(':',(string)$before[$tokenKey],2)[0])$oldId=null;
                }
                catch (Throwable) { $oldId = null; }
            }
            $takeover = ($options[$tg?'telegram_takeover_webhook':'max_takeover_webhook'] ?? null) === '1';
            if ($tg) {
                $info = self::tgInfo($this->call('telegram',$next,'getWebhookInfo'));
                if ($info['url'] !== '' && $info['url'] !== $url && !$takeover) throw new InvalidArgumentException('У Telegram-бота другой webhook. Разрешите его замену в настройках подключения.');
            } else {
                $info = $this->maxInfo($this->call('max',$next,'subscriptions'),$url);
                if ($info['matching'] > 0 && ($oldId === null || $oldId !== $newId)) throw new InvalidArgumentException('Действующая подписка МАКС не привязана к подтверждённому прежнему боту.');
                if ($info['foreign'] > 0 && !$takeover) throw new InvalidArgumentException('У МАКС-бота есть другая подписка. Разрешите подключение в настройках.');
                if ($info['matching'] > 0 && ($next['max_webhook_secret'] ?? '') !== ($before['max_webhook_secret'] ?? '')) {
                    if (($options['max_webhook_secret'] ?? '') !== '') throw new InvalidArgumentException('Сначала отключите МАКС-бота, чтобы заменить секрет действующей подписки.');
                    $next['max_webhook_secret'] = $before['max_webhook_secret'] ?? '';
                }
                if (preg_match('/\A[A-Za-z0-9_-]{16,256}\z/D',(string)($next['max_webhook_secret'] ?? '')) !== 1) throw new InvalidArgumentException('Укажите секрет webhook МАКС длиной от 16 символов.');
            }
            if ($tg && PostedAdminSettings::effectiveEnabled($next,'telegram')
                && preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D',(string)($next['webhook_secret'] ?? '')) !== 1) throw new InvalidArgumentException('Укажите секрет webhook Telegram.');
            $plans[$platform] = ['url'=>$url,'new_id'=>$newId,'old_id'=>$oldId,'token_changed'=>$changedToken,
                'takeover'=>$takeover,'enabled'=>PostedAdminSettings::effectiveEnabled($next,$platform)];
        }
        return ['next'=>$next,'plans'=>$plans];
    }

    /** One explicit attempt per platform; any unknown holds pending, never rolls config back. */
    public function sync(array $before, array $saved, array $plans): array
    {
        $pending=[]; $notices=[]; $states=[];
        foreach ($plans as $platform=>$plan) {
            $pending[$platform]=true; $states[$platform]='pending';
            try {
                if ($platform === 'telegram') {
                    $info=self::tgInfo($this->call('telegram',$saved,'getWebhookInfo'));
                    if ($info['url'] !== '' && $info['url'] !== $plan['url'] && !$plan['takeover']) throw new RuntimeException('Webhook изменился.');
                    if ($plan['enabled']) {
                        $r=$this->call('telegram',$saved,'setWebhook',['url'=>$plan['url'],'secret_token'=>(string)$saved['webhook_secret'],
                            'allowed_updates'=>self::TG_TYPES,'max_connections'=>1,'drop_pending_updates'=>false]);
                        if ($r['result'] !== true) throw new RuntimeException('Нет подтверждения webhook.');
                    } elseif ($info['url'] !== '') {
                        $r=$this->call('telegram',$saved,'deleteWebhook',['drop_pending_updates'=>false]);
                        if ($r['result'] !== true) throw new RuntimeException('Нет подтверждения отключения.');
                    }
                    $after=self::tgInfo($this->call('telegram',$saved,'getWebhookInfo'));
                    if ($after['url'] !== ($plan['enabled']?$plan['url']:'')) throw new RuntimeException('Webhook не подтверждён.');
                } else {
                    $info=$this->maxInfo($this->call('max',$saved,'subscriptions'),$plan['url']);
                    if ($info['foreign']>0 && !$plan['takeover']) throw new RuntimeException('Список подписок изменился.');
                    if ($plan['enabled']) $this->call('max',$saved,'subscribe',['url'=>$plan['url'],'secret'=>(string)$saved['max_webhook_secret'],'update_types'=>$this->maxTypes]);
                    elseif ($info['matching']>0) $this->call('max',$saved,'unsubscribe',['url'=>$plan['url']]);
                    $after=$this->maxInfo($this->call('max',$saved,'subscriptions'),$plan['url']);
                    if ($plan['enabled'] ? $after['current']!==1 : $after['matching']!==0) throw new RuntimeException('Подписка не подтверждена.');
                }
                $pending[$platform]=false; $states[$platform]='confirmed';
                /* Old token cleanup is optional, only after confirmed new connection and distinct positive IDs. */
                if ($plan['token_changed'] && is_string($plan['old_id']) && $plan['old_id']!==$plan['new_id']) {
                    try {
                        if ($platform==='telegram') {
                            $old=self::tgInfo($this->call('telegram',$before,'getWebhookInfo'));
                            if ($old['url']===$plan['url']) {
                                $r=$this->call('telegram',$before,'deleteWebhook',['drop_pending_updates'=>false]);
                                if ($r['result']!==true) throw new RuntimeException('Нет подтверждения.');
                                if(self::tgInfo($this->call('telegram',$before,'getWebhookInfo'))['url']!=='')throw new RuntimeException('Прежний webhook не отключён.');
                            }
                        } else {
                            $old=$this->maxInfo($this->call('max',$before,'subscriptions'),$plan['url']);
                            if ($old['matching']>0) {
                                $this->call('max',$before,'unsubscribe',['url'=>$plan['url']]);
                                if($this->maxInfo($this->call('max',$before,'subscriptions'),$plan['url'])['matching']!==0)throw new RuntimeException('Прежняя подписка не отключена.');
                            }
                        }
                    } catch (Throwable) { $notices[]='Новое подключение подтверждено. Прежнее подключение не удалось проверить или отключить.'; }
                } elseif($plan['token_changed']&&$plan['old_id']===null) $notices[]='Прежнее подключение не изменялось: идентичность прежнего бота не подтверждена.';
            } catch (Throwable) {
                $notices[]=($platform==='telegram'?'Telegram':'МАКС').': настройки сохранены, подключение не подтверждено. Нажмите «Сохранить изменения» для повторной проверки.';
            }
        }
        return ['pending'=>$pending,'states'=>$states,'notices'=>$notices,'requests'=>$this->calls];
    }
}
