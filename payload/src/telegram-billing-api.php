<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

final class PostedTelegramBillingException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $httpStatus=503){parent::__construct('Telegram payment: '.$reason);}
}

/** Fixed provider transport. Credentials remain in memory; errors never include request/response bodies or secrets. */
final class PostedTelegramBillingApi
{
    public const API='https://api.yookassa.ru';
    public const CREATE_HORIZON=82800; // 23h; provider idempotence expires after 24h.
    private ?Closure $transport;
    private array $observationProfiles;
    private bool $observationOnly=false;
    public function __construct(private array $config,?callable $transport=null)
    {$this->observationProfiles=PostedTelegramPaymentProfiles::fromConfig($config);$this->config=self::fromConfig($config);$this->transport=$transport===null?null:Closure::fromCallable($transport);}
    /** Observation only: one recorded merchant, no creation/capture/refund mutation. */
    public function forObservation(string $shopId):self
    {
        $copy=clone $this;
        $copy->config=PostedTelegramPaymentProfiles::observation($this->config,$this->observationProfiles,$shopId);
        $copy->observationOnly=true;
        return $copy;
    }
    public static function fromConfig(array $config):array
    {
        if(!array_key_exists('telegram_payments',$config))return['version'=>1,'enabled'=>false,'provider'=>'yookassa','shop_id'=>'','secret_key'=>''];
        $v=$config['telegram_payments'];if(!is_array($v))throw new PostedTelegramBillingException('invalid_config');$keys=array_keys($v);sort($keys);
        if($keys!==['enabled','provider','secret_key','shop_id','version']||($v['version']??null)!==1||!is_bool($v['enabled']??null)||($v['provider']??null)!=='yookassa'
            ||!is_string($v['shop_id']??null)||!is_string($v['secret_key']??null)||strlen($v['secret_key'])>1024||preg_match('/[\x00-\x20\x7f]/',$v['secret_key']))throw new PostedTelegramBillingException('invalid_config');
        if($v['shop_id']!==''&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$v['shop_id'])!==1)throw new PostedTelegramBillingException('invalid_config');
        if($v['enabled']&&($v['shop_id']===''||strlen($v['secret_key'])<16))throw new PostedTelegramBillingException('invalid_config');return$v;
    }
    public static function configured(array $config):bool
    {try{return self::fromConfig($config)['enabled'];}catch(PostedTelegramBillingException){return false;}}
    public static function uuid(mixed $id):string
    {if(!is_string($id)||preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$id)!==1)throw new PostedTelegramBillingException('invalid_provider_id',400);return$id;}
    public static function randomUuid():string
    {$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    public static function ref(mixed $ref):string
    {if(!is_string($ref)||preg_match('/\A[a-f0-9]{32}\z/D',$ref)!==1)throw new PostedTelegramBillingException('invalid_order',400);return$ref;}
    public static function quoteToken(mixed $ref):string
    {if(!is_string($ref)||preg_match('/\A[a-f0-9]{24}\z/D',$ref)!==1)throw new PostedTelegramBillingException('invalid_quote',400);return$ref;}
    public static function minor(mixed $amount,bool $allowZero=false):int
    {
        if(!is_string($amount)||preg_match('/\A(0|[1-9][0-9]{0,8})\.([0-9]{2})\z/D',$amount,$m)!==1)throw new PostedTelegramBillingException('invalid_amount');
        $minor=(int)$m[1]*100+(int)$m[2];if($minor<0||(!$allowZero&&$minor===0))throw new PostedTelegramBillingException('invalid_amount');return$minor;
    }
    public static function amount(int $minor):string
    {if($minor<1||$minor>99999999999)throw new PostedTelegramBillingException('invalid_amount');return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
    public static function redirect(mixed $url):?string
    {
        if($url===null)return null;if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new PostedTelegramBillingException('invalid_provider_redirect');$p=parse_url($url);
        if(!is_array($p)||($p['scheme']??'')!=='https'||!in_array($p['host']??'', ['yoomoney.ru','yookassa.ru'],true)||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment'])||!str_starts_with($p['path']??'','/'))throw new PostedTelegramBillingException('invalid_provider_redirect');return$url;
    }
    /** Only a whitelist projection can leave this method; no card/payment-method data is persisted. */
    public static function payment(array $doc,array $order,string $metadataKey='gitposted_order'):array
    {
        if(!in_array($metadataKey,['gitposted_order','gitposted_max_order'],true))throw new PostedTelegramBillingException('invalid_metadata_key');
        $id=self::uuid($doc['id']??null);$status=$doc['status']??null;
        if((($order['payment_id']??null)!==null&&$id!==$order['payment_id'])||!in_array($status,['pending','waiting_for_capture','succeeded','canceled'],true)||!is_bool($doc['paid']??null)||($doc['test']??null)!==false
            ||($doc['recipient']['account_id']??null)!==$order['shop_id']||($doc['amount']['currency']??null)!=='RUB'
            ||self::minor($doc['amount']['value']??null)!==$order['amount_minor']||!is_array($doc['metadata']??null)
            ||($doc['metadata'][$metadataKey]??null)!==$order['order_ref'])throw new PostedTelegramBillingException('payment_binding_mismatch');
        if(($status==='succeeded'&&$doc['paid']!==true)||($status==='canceled'&&$doc['paid']!==false))throw new PostedTelegramBillingException('payment_status_mismatch');
        $refunded=0;if(array_key_exists('refunded_amount',$doc)){
            if(!is_array($doc['refunded_amount'])||($doc['refunded_amount']['currency']??null)!=='RUB')throw new PostedTelegramBillingException('invalid_refunded_amount');
            $refunded=self::minor($doc['refunded_amount']['value']??null,true);if($refunded>$order['amount_minor']||($refunded>0&&($status!=='succeeded'||$doc['paid']!==true)))throw new PostedTelegramBillingException('invalid_refunded_amount');
        }
        $url=null;if(isset($doc['confirmation'])){if(!is_array($doc['confirmation'])||($doc['confirmation']['type']??null)!=='redirect')throw new PostedTelegramBillingException('invalid_confirmation');$url=self::redirect($doc['confirmation']['confirmation_url']??null);}
        return['id'=>$id,'status'=>$status,'paid'=>$doc['paid'],'confirmation_url'=>$url,'refunded_minor'=>$refunded];
    }
    public static function refund(array $doc,array $order,string $expectedId):array
    {
        $id=self::uuid($doc['id']??null);$pid=self::uuid($doc['payment_id']??null);
        if($id!==self::uuid($expectedId)||$pid!==$order['payment_id']||($doc['status']??null)!=='succeeded'||($doc['amount']['currency']??null)!=='RUB')throw new PostedTelegramBillingException('refund_binding_mismatch');
        $minor=self::minor($doc['amount']['value']??null);if($minor>$order['amount_minor'])throw new PostedTelegramBillingException('refund_amount_mismatch');return['id'=>$id,'payment_id'=>$pid,'amount_minor'=>$minor,'status'=>'succeeded'];
    }
    public function request(string $method,string $path,?string $body=null,?string $idempotency=null):array
    {
        if($this->observationOnly&&$method!=='GET')throw new PostedTelegramBillingException('provider_operation_denied');
        if(!$this->config['enabled'])throw new PostedTelegramBillingException('payments_disabled');
        if($method==='POST'){
            if($path!=='/v3/payments'||$body===null||strlen($body)>8192||$idempotency===null)throw new PostedTelegramBillingException('provider_operation_denied');self::uuid($idempotency);
        }elseif($method==='GET'){
            if($body!==null||$idempotency!==null||preg_match('~\A/v3/(?:payments|refunds)/([a-f0-9-]{36})\z~D',$path,$m)!==1)throw new PostedTelegramBillingException('provider_operation_denied');self::uuid($m[1]);
        }else throw new PostedTelegramBillingException('provider_operation_denied');
        if($this->transport!==null){$result=($this->transport)($method,$path,$body,$idempotency);if(!is_array($result)||!is_int($result['status']??null)||!is_array($result['json']??null))throw new PostedTelegramBillingException('provider_unavailable');}
        else{
            $response='';$curl=curl_init(self::API.$path);$headers=['Accept: application/json','Content-Type: application/json'];if($idempotency!==null)$headers[]='Idempotence-Key: '.$idempotency;
            $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_USERPWD=>$this->config['shop_id'].':'.$this->config['secret_key'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',
                CURLOPT_WRITEFUNCTION=>static function($h,string$part)use(&$response):int{if(strlen($response)+strlen($part)>262144)return 0;$response.=$part;return strlen($part);}];
            if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;curl_setopt_array($curl,$opts);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            if($ok===false)throw new PostedTelegramBillingException('provider_unavailable');try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PostedTelegramBillingException('provider_unavailable');}
            if(!is_array($decoded))throw new PostedTelegramBillingException('provider_unavailable');$result=['status'=>$status,'json'=>$decoded];
        }
        if($result['status']<200||$result['status']>=300)throw new PostedTelegramBillingException('provider_unavailable');return$result['json'];
    }
}

/** Private config-only credentials for observing already recorded merchant orders. */
final class PostedTelegramPaymentProfiles
{
    public const KEY = 'telegram_payment_observation_profiles';
    public const MAX_PROFILES = 32;

    private static function shop(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
            throw new PostedTelegramBillingException('invalid_merchant_profile');
        }
        return $value;
    }

    private static function secret(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 16 || strlen($value) > 1024
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new PostedTelegramBillingException('invalid_merchant_profile');
        }
        return $value;
    }

    /** Absent namespace preserves legacy current-shop behavior. No tolerant fallback. */
    public static function fromConfig(array $config): array
    {
        if (!array_key_exists(self::KEY, $config)) return [];
        $value = $config[self::KEY];
        if (!is_array($value) || count($value) !== 2
            || !array_key_exists('version', $value) || !array_key_exists('profiles', $value)
            || $value['version'] !== 1 || !is_array($value['profiles']) || !array_is_list($value['profiles'])
            || count($value['profiles']) > self::MAX_PROFILES) {
            throw new PostedTelegramBillingException('invalid_merchant_profile');
        }
        $result = [];
        foreach ($value['profiles'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2
                || !array_key_exists('shop_id', $entry) || !array_key_exists('secret_key', $entry)) {
                throw new PostedTelegramBillingException('invalid_merchant_profile');
            }
            $shop = self::shop($entry['shop_id']);
            if (isset($result[$shop])) throw new PostedTelegramBillingException('invalid_merchant_profile');
            $result[$shop] = ['shop_id' => $shop, 'secret_key' => self::secret($entry['secret_key'])];
        }
        $result = array_values($result);
        usort($result, static fn(array $a, array $b): int => strlen($a['shop_id']) <=> strlen($b['shop_id']) ?: strcmp($a['shop_id'], $b['shop_id']));
        return $result;
    }

    /** Select one exact merchant; disabled-payment behavior stays unchanged. */
    public static function observation(array $current, array $profiles, string $shop): array
    {
        self::shop($shop);
        if (($current['enabled'] ?? null) !== true) throw new PostedTelegramBillingException('merchant_changed');
        if (($current['shop_id'] ?? null) === $shop) return $current;
        foreach ($profiles as $entry) {
            if ($entry['shop_id'] === $shop) {
                return ['version' => 1, 'enabled' => true, 'provider' => 'yookassa',
                    'shop_id' => $shop, 'secret_key' => $entry['secret_key']];
            }
        }
        throw new PostedTelegramBillingException('merchant_changed');
    }

    /** One in-memory next-config result, committed together with every other setting. */
    public static function transition(array $config, array $nextPayment): array
    {
        $current = PostedTelegramBillingApi::fromConfig($config);
        $next = PostedTelegramBillingApi::fromConfig(['telegram_payments' => $nextPayment]);
        $profiles = self::fromConfig($config);
        $byShop = [];
        foreach ($profiles as $entry) $byShop[$entry['shop_id']] = $entry;
        if ($next['shop_id'] !== $current['shop_id']) {
            if ($next['shop_id'] === '') throw new InvalidArgumentException('Для смены магазина укажите новый shopId и секретный ключ.');
            self::secret($next['secret_key']);
            if ($current['secret_key'] !== '' && hash_equals($current['secret_key'], $next['secret_key'])) {
                throw new InvalidArgumentException('При смене магазина укажите его новый секретный ключ.');
            }
            if ($current['shop_id'] !== '') {
                $oldShop = self::shop($current['shop_id']);
                $byShop[$oldShop] = ['shop_id' => $oldShop, 'secret_key' => self::secret($current['secret_key'])];
            }
        }
        if ($next['shop_id'] !== '') unset($byShop[$next['shop_id']]);
        if (count($byShop) > self::MAX_PROFILES) {
            throw new InvalidArgumentException('Слишком много прежних магазинов: требуется отдельная проверка незавершённых заказов.');
        }
        $result = $config;
        $result['telegram_payments'] = $next;
        if ($byShop !== [] || array_key_exists(self::KEY, $config)) {
            $result[self::KEY] = ['version' => 1, 'profiles' => array_values($byShop)];
            $result[self::KEY]['profiles'] = self::fromConfig($result);
        }
        return $result;
    }

    /** Only public merchant IDs may appear in a view; keys never leave config/RAM. */
    public static function publicShopIds(array $config): array
    {
        return array_column(self::fromConfig($config), 'shop_id');
    }
}
