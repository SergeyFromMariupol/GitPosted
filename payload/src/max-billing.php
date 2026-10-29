<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-billing-api.php';
require_once __DIR__.'/max-policy.php';
require_once __DIR__.'/max-subscription-ui.php';

/** Typed orders and receipts. Provider calls are made only after committing the short claim transaction. */
final class GitPostedMaxBilling
{
    public const STATES=['creating','create_unknown','create_review','pending','waiting_capture','funded','canceled','paid_review','refund_review'];
    private Closure $clock;
    private PostedTelegramBillingApi $api;
    public function __construct(private PDO $pdo,private array $config,?callable $transport=null,?callable $clock=null)
    {$this->api=new PostedTelegramBillingApi($config,$transport);$this->clock=$clock===null?static fn():int=>time():Closure::fromCallable($clock);}
    private function now():int{$v=($this->clock)();if(!is_int($v)||$v<=0)throw new LogicException('Invalid billing clock.');return$v;}
    private function paidPricing():bool
    {try{return GitPostedMaxPolicy::fromConfig($this->config)['pricing_mode']==='paid';}catch(InvalidArgumentException){return false;}}
    private function noOuter():void{if($this->pdo->inTransaction())throw new LogicException('Billing entry requires no outer transaction.');}
    private static function sorted(mixed $v):mixed{if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=self::sorted($x);return$v;}
    public static function canonical(mixed $v):string{return json_encode(self::sorted($v),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function keys(array $v,array $want):void{$got=array_keys($v);sort($got);sort($want);if($got!==$want)throw new PostedTelegramBillingException('invalid_record');}
    private function rows(string $sql,array $args=[]):array{$s=$this->pdo->prepare($sql);$s->execute($args);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private static function orderKey(string $ref):string{return'max_pay_order_'.PostedTelegramBillingApi::ref($ref);}
    private static function quoteKey(string $token):string{return'max_checkout_quote_'.PostedTelegramBillingApi::quoteToken($token);}
    private static function indexKey(string $token):string{return'max_pay_quote_'.PostedTelegramBillingApi::quoteToken($token);}
    private static function paymentKey(string $id):string{return'max_pay_payment_'.str_replace('-','',PostedTelegramBillingApi::uuid($id));}
    private static function refundKey(string $id):string{return'max_pay_refund_'.str_replace('-','',PostedTelegramBillingApi::uuid($id));}
    private static function receivedKey(string $id):string{return'max_pay_received_'.str_replace('-','',PostedTelegramBillingApi::uuid($id));}
    private static function pairKey(string $kind,string $ref,int $channel):string
    {if(!in_array($kind,['scope','grant'],true)||$channel<=0)throw new PostedTelegramBillingException('invalid_record');return'max_pay_'.$kind.'_'.substr(hash('sha256',PostedTelegramBillingApi::ref($ref).':'.$channel),0,40);}
    private function read(string $key,bool $lock=false):?array
    {
        if(strlen($key)>64||preg_match('/\Amax_(?:pay_[a-z_]+|checkout_quote)_[a-f0-9]+\z/D',$key)!==1)throw new PostedTelegramBillingException('invalid_record');
        $r=$this->rows('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''),[$key]);if($r===[])return null;
        if(!is_string($r[0]['setting_value'])||strlen($r[0]['setting_value'])>60000)throw new PostedTelegramBillingException('invalid_record');
        try{$v=json_decode($r[0]['setting_value'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PostedTelegramBillingException('invalid_record');}if(!is_array($v))throw new PostedTelegramBillingException('invalid_record');return$v;
    }
    private function put(string $key,array $v,bool $insert=false):void
    {
        if(!$this->pdo->inTransaction()||strlen($key)>64)throw new LogicException('Billing write requires a transaction.');$raw=self::canonical($v);if(strlen($raw)>60000)throw new PostedTelegramBillingException('record_too_large');
        $sql='INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?)'.($insert?'':' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$this->pdo->prepare($sql)->execute([$key,$raw]);
    }
    public static function quote(array $q,string $token):array
    {
        self::keys($q,['version','token','user_id','channels','period_days','amount_rub','currency','created_at','expires_at']);
        if($q['version']!==1||$q['token']!==PostedTelegramBillingApi::quoteToken($token)||!is_string($q['user_id'])||preg_match('/\A[1-9][0-9]{0,18}\z/D',$q['user_id'])!==1||!is_array($q['channels'])||!array_is_list($q['channels'])||count($q['channels'])<1||count($q['channels'])>500
            ||!in_array($q['period_days'],[30,90,180,365],true)||!is_int($q['amount_rub'])||$q['amount_rub']<1||$q['amount_rub']>999999999||$q['currency']!=='RUB'
            ||!is_int($q['created_at'])||!is_int($q['expires_at'])||$q['created_at']<=0||$q['expires_at']<=$q['created_at'])throw new PostedTelegramBillingException('invalid_quote',400);
        $seen=[];foreach($q['channels']as$c){if(!is_array($c))throw new PostedTelegramBillingException('invalid_quote');self::keys($c,['channel_id','chat_id']);
            if(!is_int($c['channel_id'])||$c['channel_id']<=0||isset($seen[$c['channel_id']])||!is_string($c['chat_id'])||GitPostedMaxPolicy::chatId($c['chat_id'])!==$c['chat_id'])throw new PostedTelegramBillingException('invalid_quote');$seen[$c['channel_id']]=true;}
        return$q;
    }
    public static function order(array $o):array
    {
        self::keys($o,['version','order_ref','quote_token','quote_digest','user_id','channels','period_days','amount_minor','currency','shop_id','created_at','first_attempt_at','idempotency_key','request_json','request_sha256','revision','state','payment_id','confirmation_url','create_lease','status_lease','last_status_at','funded_at','result_code','provider_status']);
        PostedTelegramBillingApi::ref($o['order_ref']);PostedTelegramBillingApi::quoteToken($o['quote_token']);PostedTelegramBillingApi::uuid($o['idempotency_key']);
        if($o['version']!==1||!is_string($o['quote_digest'])||preg_match('/\A[a-f0-9]{64}\z/D',$o['quote_digest'])!==1||!is_string($o['user_id'])||preg_match('/\A[1-9][0-9]{0,18}\z/D',$o['user_id'])!==1
            ||!is_array($o['channels'])||!array_is_list($o['channels'])||count($o['channels'])<1||count($o['channels'])>500||!in_array($o['period_days'],[30,90,180,365],true)||!is_int($o['amount_minor'])||$o['amount_minor']<1||$o['currency']!=='RUB'
            ||!is_string($o['shop_id'])||preg_match('/\A[1-9][0-9]{0,18}\z/D',$o['shop_id'])!==1||!is_int($o['created_at'])||!is_int($o['first_attempt_at'])||$o['created_at']<=0||$o['first_attempt_at']<$o['created_at']
            ||!is_int($o['revision'])||$o['revision']<0||!in_array($o['state'],self::STATES,true)||!is_string($o['request_json'])||strlen($o['request_json'])>8192||!is_string($o['request_sha256'])||!hash_equals($o['request_sha256'],hash('sha256',$o['request_json']))
            ||!is_int($o['last_status_at'])||$o['last_status_at']<0||($o['funded_at']!==null&&(!is_int($o['funded_at'])||$o['funded_at']<=0))||($o['result_code']!==null&&(!is_string($o['result_code'])||preg_match('/\A[a-z_]{1,64}\z/D',$o['result_code'])!==1))
            ||($o['provider_status']!==null&&!in_array($o['provider_status'],['pending','waiting_for_capture','succeeded','canceled'],true)))throw new PostedTelegramBillingException('invalid_order');
        $seen=[];foreach($o['channels']as$c){if(!is_array($c))throw new PostedTelegramBillingException('invalid_order');self::keys($c,['channel_id','chat_id']);if(!is_int($c['channel_id'])||$c['channel_id']<=0||isset($seen[$c['channel_id']])||GitPostedMaxPolicy::chatId($c['chat_id'])!==$c['chat_id'])throw new PostedTelegramBillingException('invalid_order');$seen[$c['channel_id']]=true;}
        if($o['payment_id']!==null)PostedTelegramBillingApi::uuid($o['payment_id']);$o['confirmation_url']=PostedTelegramBillingApi::redirect($o['confirmation_url']);
        foreach(['create_lease','status_lease']as$k)if($o[$k]!==null){if(!is_array($o[$k]))throw new PostedTelegramBillingException('invalid_order');self::keys($o[$k],['nonce','until']);PostedTelegramBillingApi::ref($o[$k]['nonce']);if(!is_int($o[$k]['until'])||$o[$k]['until']<=0)throw new PostedTelegramBillingException('invalid_order');}
        $body=json_decode($o['request_json'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($body)||($body['capture']??null)!==true||($body['amount']['currency']??null)!=='RUB'||PostedTelegramBillingApi::minor($body['amount']['value']??null)!==$o['amount_minor']||($body['metadata']??null)!==['gitposted_max_order'=>$o['order_ref']]||($body['confirmation']['type']??null)!=='redirect')throw new PostedTelegramBillingException('invalid_order');
        return$o;
    }
    private function readOrder(string $ref,bool $lock=false):array
    {$v=$this->read(self::orderKey($ref),$lock);if($v===null)throw new PostedTelegramBillingException('order_not_found',404);$o=self::order($v);if($o['order_ref']!==$ref)throw new PostedTelegramBillingException('invalid_order');return$o;}
    private function forQuote(string $token):?array
    {$idx=$this->read(self::indexKey($token));if($idx===null)return null;self::keys($idx,['version','quote_token','order_ref']);if($idx['version']!==1||$idx['quote_token']!==$token)throw new PostedTelegramBillingException('invalid_record');$o=$this->readOrder($idx['order_ref']);if($o['quote_token']!==$token)throw new PostedTelegramBillingException('invalid_order');return$o;}
    private function locked(array $intent,callable $callback):mixed
    {
        $this->noOuter();$ids=array_column($intent['channels'],'channel_id');sort($ids,SORT_NUMERIC);$this->pdo->beginTransaction();
        try{$channels=[];foreach($ids as$id){$r=$this->rows('SELECT * FROM posted_max_channels WHERE id=? LIMIT 1 FOR UPDATE',[$id]);$channels[$id]=$r[0]??null;}
            $u=$this->rows('SELECT max_user_id,is_active FROM posted_max_users WHERE max_user_id=? LIMIT 1 FOR UPDATE',[$intent['user_id']]);$result=$callback($channels,$u[0]??null);$this->pdo->commit();return$result;
        }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function authority(array $binding,?array $channel,?array $user,bool $lock):?string
    {
        if($channel===null||$user===null||(int)$user['is_active']!==1||!GitPostedMaxPolicy::operatorAllowed($this->config,(string)$user['max_user_id'])
            ||(int)$channel['id']!==$binding['channel_id']||(string)$channel['chat_id']!==$binding['chat_id']||preg_match('/\A[1-9][0-9]{0,18}\z/D',(string)($channel['added_by']??''))!==1||empty($channel['is_active'])||!empty($channel['is_archived'])||!empty($channel['admin_blocked']))return null;
        $actor=(string)$user['max_user_id'];$owner=hash_equals((string)$channel['added_by'],$actor);
        $r=$this->rows('SELECT * FROM posted_max_permissions WHERE max_user_id=? AND channel_id=? LIMIT 1'.($lock?' FOR UPDATE':''),[$actor,$binding['channel_id']]);$g=$r[0]??null;
        if(!$owner&&(!is_array($g)||empty($g['can_post'])))return null;
        $flags=[];foreach(['can_post','can_schedule','can_manage','is_ad_manager']as$k)$flags[$k]=$owner?($k==='is_ad_manager'?0:1):(int)($g[$k]??0);
        return hash('sha256',self::canonical(['channel_id'=>(int)$channel['id'],'chat_id'=>(string)$channel['chat_id'],'owner_id'=>(string)$channel['added_by'],'actor_id'=>$actor,'owner'=>$owner,'flags'=>$flags,'grant_created_at'=>$owner?null:(string)$g['created_at']]));
    }
    private function sameAuthority(array $o,array $channels,?array $user):bool
    {
        $same=true;foreach($o['channels']as$b){$r=$this->read(self::pairKey('scope',$o['order_ref'],$b['channel_id']),true);$hash=$this->authority($b,$channels[$b['channel_id']]??null,$user,true);
            if($r===null||($r['version']??null)!==1||($r['order_ref']??null)!==$o['order_ref']||($r['channel_id']??null)!==$b['channel_id']||($r['chat_id']??null)!==$b['chat_id']||!is_string($r['authority_hash']??null)||$hash===null||!hash_equals($r['authority_hash'],$hash))$same=false;}
        return$same;
    }
    private function storeOrder(array $o):array{$o=self::order($o);$this->put(self::orderKey($o['order_ref']),$o);return$o;}
    public function checkout(string $token):array
    {
        $this->noOuter();$token=PostedTelegramBillingApi::quoteToken($token);$o=$this->forQuote($token);if($o!==null)return$o;
        $raw=$this->read(self::quoteKey($token));if($raw===null)throw new PostedTelegramBillingException('quote_not_found',404);$q=self::quote($raw,$token);
        if($q['expires_at']<=$this->now())throw new PostedTelegramBillingException('quote_expired',410);return['state'=>'quote','quote'=>$q];
    }
    /** Read-only checkout projection; never invokes the provider or changes a license/order. */
    public function page(string $token):array
    {
        $v=$this->checkout($token);$fresh=$v['state']==='quote';$intent=$fresh?$v['quote']:$v;$titles=[];
        if($fresh){if(!$this->paidPricing())throw new PostedTelegramBillingException('billing_not_required',403);$prices=GitPostedMaxSubscription::tariffs($this->config);if($intent['amount_rub']!==$prices[$intent['period_days']]*count($intent['channels']))throw new PostedTelegramBillingException('quote_stale',410);
            $u=$this->rows('SELECT max_user_id,is_active FROM posted_max_users WHERE max_user_id=? LIMIT 1',[$intent['user_id']]);
            foreach($intent['channels']as$b){$c=$this->rows('SELECT * FROM posted_max_channels WHERE id=? LIMIT 1',[$b['channel_id']]);if($this->authority($b,$c[0]??null,$u[0]??null,false)===null)throw new PostedTelegramBillingException('access_changed',403);$titles[]=mb_substr((string)$c[0]['title'],0,100,'UTF-8');}
        }else foreach($intent['channels']as$b){$s=$this->read(self::pairKey('scope',$intent['order_ref'],$b['channel_id']));$titles[]=is_string($s['title']??null)?mb_substr($s['title'],0,100,'UTF-8'):'Канал из заказа';}
        return['state'=>$intent['state']??'quote','amount_minor'=>$fresh?$intent['amount_rub']*100:$intent['amount_minor'],'period_days'=>$intent['period_days'],'channel_titles'=>$titles,
            'order_label'=>$fresh?null:substr($intent['order_ref'],0,12),'payment_known'=>!$fresh&&$intent['payment_id']!==null,'confirmation_url'=>$fresh?null:$intent['confirmation_url'],
            'can_pay'=>$fresh||(!$fresh&&$this->paidPricing()&&$intent['payment_id']===null&&in_array($intent['state'],['creating','create_unknown'],true)&&$this->now()>=$intent['first_attempt_at']&&$this->now()-$intent['first_attempt_at']<PostedTelegramBillingApi::CREATE_HORIZON),
            'can_check'=>!$fresh&&$intent['payment_id']!==null&&!in_array($intent['state'],['funded','paid_review','refund_review','canceled'],true)];
    }
    /** Explicit POST only. This method never constructs a second order or a new idempotency key for an existing quote. */
    public function pay(string $token):array
    {
        $this->noOuter();$token=PostedTelegramBillingApi::quoteToken($token);$existing=$this->forQuote($token);
        if($existing===null&&!$this->paidPricing())throw new PostedTelegramBillingException('billing_not_required',403);
        $raw=$existing===null?$this->read(self::quoteKey($token)):null;if($existing===null&&$raw===null)throw new PostedTelegramBillingException('quote_not_found',404);
        $hint=$existing??self::quote($raw,$token);$claim=$this->locked($hint,function(array$channels,?array$user)use($token,$existing,$hint):array{
            $index=$this->read(self::indexKey($token),true);$o=null;
            if($index!==null){if(($index['version']??null)!==1||($index['quote_token']??null)!==$token)throw new PostedTelegramBillingException('invalid_record');$o=$this->readOrder($index['order_ref'],true);}
            if($o===null){
                $raw=$this->read(self::quoteKey($token),true);if($raw===null)throw new PostedTelegramBillingException('quote_not_found',404);$q=self::quote($raw,$token);$now=$this->now();
                $prices=GitPostedMaxSubscription::tariffs($this->config);if(self::canonical($q)!==self::canonical($hint)||$q['expires_at']<=$now||$q['created_at']>$now+30||$q['amount_rub']!==$prices[$q['period_days']]*count($q['channels']))throw new PostedTelegramBillingException('quote_stale',410);
                $cfg=PostedTelegramBillingApi::fromConfig($this->config);if(!$cfg['enabled'])throw new PostedTelegramBillingException('payments_disabled');$ref=bin2hex(random_bytes(16));$scopes=[];
                foreach($q['channels']as$b){$h=$this->authority($b,$channels[$b['channel_id']]??null,$user,true);if($h===null)throw new PostedTelegramBillingException('access_changed',403);$scopes[]=['version'=>1,'order_ref'=>$ref,'channel_id'=>$b['channel_id'],'chat_id'=>$b['chat_id'],'authority_hash'=>$h,'title'=>mb_substr((string)$channels[$b['channel_id']]['title'],0,100,'UTF-8')];}
                $body=self::canonical(['amount'=>['value'=>PostedTelegramBillingApi::amount($q['amount_rub']*100),'currency'=>'RUB'],'capture'=>true,
                    'confirmation'=>['type'=>'redirect','return_url'=>GitPostedMaxPolicy::checkoutSelectionUrl((string)($this->config['base_url']??''),$token)],
                    'description'=>'GitPosted MAX: '.count($q['channels']).' канал(ов), '.$q['period_days'].' дней','metadata'=>['gitposted_max_order'=>$ref]]);
                $o=['version'=>1,'order_ref'=>$ref,'quote_token'=>$token,'quote_digest'=>hash('sha256',self::canonical($q)),'user_id'=>$q['user_id'],'channels'=>$q['channels'],'period_days'=>$q['period_days'],'amount_minor'=>$q['amount_rub']*100,'currency'=>'RUB','shop_id'=>$cfg['shop_id'],
                    'created_at'=>$now,'first_attempt_at'=>$now,'idempotency_key'=>PostedTelegramBillingApi::randomUuid(),'request_json'=>$body,'request_sha256'=>hash('sha256',$body),'revision'=>0,'state'=>'creating','payment_id'=>null,'confirmation_url'=>null,'create_lease'=>null,'status_lease'=>null,'last_status_at'=>0,'funded_at'=>null,'result_code'=>null,'provider_status'=>null];
                foreach($scopes as$s)$this->put(self::pairKey('scope',$ref,$s['channel_id']),$s,true);$this->put(self::orderKey($ref),self::order($o),true);$this->put(self::indexKey($token),['version'=>1,'quote_token'=>$token,'order_ref'=>$ref],true);
            }
            $now=$this->now();if($o['payment_id']!==null||in_array($o['state'],['funded','canceled','paid_review','refund_review','create_review'],true))return['order'=>$o,'call'=>false];
            if(!$this->paidPricing()){$o['state']='create_review';$o['result_code']='pricing_now_free';$o['create_lease']=null;$o['revision']++;return['order'=>$this->storeOrder($o),'call'=>false];}
            if($o['create_lease']!==null&&$o['create_lease']['until']>$now)return['order'=>$o,'call'=>false];
            $currentConfig=PostedTelegramBillingApi::fromConfig($this->config);
            if(!$currentConfig['enabled']||$currentConfig['shop_id']!==$o['shop_id']||$now<$o['first_attempt_at']||$now-$o['first_attempt_at']>=PostedTelegramBillingApi::CREATE_HORIZON||!$this->sameAuthority($o,$channels,$user)){$o['state']='create_review';$o['result_code']='create_requires_review';$o['create_lease']=null;$o['revision']++;return['order'=>$this->storeOrder($o),'call'=>false];}
            $o['create_lease']=['nonce'=>bin2hex(random_bytes(16)),'until'=>$now+30];$o['state']='creating';$o['revision']++;return['order'=>$this->storeOrder($o),'call'=>true];
        });
        $o=$claim['order'];if(!$claim['call'])return$o;$nonce=$o['create_lease']['nonce'];
        try{$this->noOuter();$doc=$this->api->request('POST','/v3/payments',$o['request_json'],$o['idempotency_key']);$payment=PostedTelegramBillingApi::payment($doc,$o,'gitposted_max_order');return$this->applyPayment($o,$payment,$nonce);}
        catch(Throwable$e){$current=$this->locked($o,function(array$c,?array$u)use($o,$nonce):array{$v=$this->readOrder($o['order_ref'],true);if(in_array($v['state'],['creating','create_unknown'],true)&&($v['create_lease']['nonce']??null)===$nonce){$v['state']='create_unknown';$v['create_lease']=null;$v['result_code']='provider_result_unknown';$v['revision']++;$v=$this->storeOrder($v);}return$v;});return$current;}
    }
    private function bindPayment(array &$o,string $id):void
    {
        if($o['payment_id']!==null&&$o['payment_id']!==$id)throw new PostedTelegramBillingException('payment_id_mismatch');$key=self::paymentKey($id);$index=$this->read($key,true);
        if($index!==null&&(($index['version']??null)!==1||($index['payment_id']??null)!==$id||($index['order_ref']??null)!==$o['order_ref']))throw new PostedTelegramBillingException('payment_id_mismatch');
        if($index===null)$this->put($key,['version'=>1,'payment_id'=>$id,'order_ref'=>$o['order_ref']],true);$o['payment_id']=$id;
    }
    private function applyPayment(array $hint,array $payment,?string $createNonce=null):array
    {
        return$this->locked($hint,function(array$channels,?array$user)use($hint,$payment,$createNonce):array{
            $o=$this->readOrder($hint['order_ref'],true);$this->bindPayment($o,$payment['id']);
            if($payment['status']==='succeeded'&&$payment['paid']&&$payment['refunded_minor']>0){
                $now=$this->now();$received=$this->read(self::receivedKey($payment['id']),true);
                if($received===null)$this->put(self::receivedKey($payment['id']),['version'=>1,'order_ref'=>$o['order_ref'],'payment_id'=>$payment['id'],'shop_id'=>$o['shop_id'],'amount_minor'=>$o['amount_minor'],'currency'=>'RUB','paid'=>true,'test'=>false,'verified_at'=>$now,'result'=>'refund_review'],true);
                elseif(($received['order_ref']??null)!==$o['order_ref']||($received['payment_id']??null)!==$payment['id'])throw new PostedTelegramBillingException('payment_binding_mismatch');
                $key='max_pay_refund_total_'.str_replace('-','',$payment['id']);$prior=$this->read($key,true);
                if($prior!==null&&(($prior['order_ref']??null)!==$o['order_ref']||($prior['payment_id']??null)!==$payment['id']))throw new PostedTelegramBillingException('payment_binding_mismatch');
                $this->put($key,['version'=>1,'order_ref'=>$o['order_ref'],'payment_id'=>$payment['id'],'amount_minor'=>max($payment['refunded_minor'],(int)($prior['amount_minor']??0)),'currency'=>'RUB','verified_at'=>$now,'state'=>'refund_review']);
                $o['state']='refund_review';$o['funded_at']??=$now;$o['provider_status']='succeeded';$o['create_lease']=null;$o['status_lease']=null;$o['result_code']='verified_refund_requires_review';$o['revision']++;return$this->storeOrder($o);
            }
            if(in_array($o['state'],['funded','paid_review','refund_review','canceled'],true))return$o;
            $o['provider_status']=$payment['status'];$o['confirmation_url']=$payment['confirmation_url'];$o['create_lease']=null;$o['status_lease']=null;$o['result_code']=null;$o['revision']++;
            if($payment['status']==='succeeded'&&$payment['paid']){
                $o['funded_at']=$this->now();$good=$this->sameAuthority($o,$channels,$user);$licensePlans=[];
                if(!$good)$o['result_code']='access_changed_after_order';
                if($good)try{foreach($o['channels']as$b){
                    $before=GitPostedMaxPolicy::readLicense($this->pdo,$channels[$b['channel_id']],true);$base=$o['funded_at'];if($before!==null&&$before['status']==='active')$base=max($base,(new DateTimeImmutable($before['expires_at'],new DateTimeZone('UTC')))->getTimestamp());
                    $after=['version'=>1,'channel_id'=>$b['channel_id'],'chat_id'=>$b['chat_id'],'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$base+$o['period_days']*86400),'status'=>'active'];
                    $licensePlans[$b['channel_id']]=['before'=>$before,'after'=>GitPostedMaxPolicy::normalizeLicense($after,$channels[$b['channel_id']])];
                }}catch(InvalidArgumentException){$good=false;$licensePlans=[];$o['result_code']='invalid_license_requires_review';}
                $o['state']=$good?'funded':'paid_review';
                $this->put(self::receivedKey($payment['id']),['version'=>1,'order_ref'=>$o['order_ref'],'payment_id'=>$payment['id'],'shop_id'=>$o['shop_id'],'amount_minor'=>$o['amount_minor'],'currency'=>'RUB','paid'=>true,'test'=>false,'verified_at'=>$o['funded_at'],'result'=>$o['state']],true);
                if($good)foreach($o['channels']as$b){$key=self::pairKey('grant',$o['order_ref'],$b['channel_id']);if($this->read($key,true)!==null)throw new PostedTelegramBillingException('grant_receipt_conflict');
                    $before=$licensePlans[$b['channel_id']]['before'];$after=$licensePlans[$b['channel_id']]['after'];
                    $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([GitPostedMaxPolicy::licenseKey($b['channel_id']),self::canonical($after)]);
                    $this->put($key,['version'=>1,'order_ref'=>$o['order_ref'],'payment_id'=>$payment['id'],'channel_id'=>$b['channel_id'],'chat_id'=>$b['chat_id'],'applied_at'=>$o['funded_at'],'before'=>$before,'after'=>$after],true);
                }
            }elseif($payment['status']==='canceled')$o['state']='canceled';elseif($payment['status']==='waiting_for_capture')$o['state']='waiting_capture';else$o['state']='pending';
            return$this->storeOrder($o);
        });
    }
    private function claimObservation(array $hint,string $paymentId,bool $refund=false):array
    {
        return$this->locked($hint,function(array$c,?array$u)use($hint,$paymentId,$refund):array{
            $o=$this->readOrder($hint['order_ref'],true);$this->api->forObservation($o['shop_id']);
            if($o['payment_id']!==null&&$o['payment_id']!==$paymentId)throw new PostedTelegramBillingException('payment_id_mismatch',400);
            if(!$refund&&in_array($o['state'],['funded','paid_review','refund_review','canceled'],true))return['order'=>$o,'call'=>false];
            $now=$this->now();if(($o['status_lease']!==null&&$o['status_lease']['until']>$now)||$o['last_status_at']>$now-5)throw new PostedTelegramBillingException('status_rate_limited');
            $o['status_lease']=['nonce'=>bin2hex(random_bytes(16)),'until'=>$now+30];$o['last_status_at']=$now;$o['revision']++;return['order'=>$this->storeOrder($o),'call'=>true];
        });
    }
    private function observationFailed(array $hint,string $nonce):void
    {
        $this->locked($hint,function(array$c,?array$u)use($hint,$nonce):void{$o=$this->readOrder($hint['order_ref'],true);
            if(($o['status_lease']['nonce']??null)===$nonce){$o['status_lease']=null;$o['revision']++;$this->storeOrder($o);}
        });
    }
    /** Reconciliation is called by explicit CSRF POST only. GET/HEAD use page(), with zero provider/license writes. */
    public function status(string $token,bool $sync=false):array
    {
        $this->noOuter();$view=$this->checkout($token);if(($view['state']??null)==='quote'||!$sync||$view['payment_id']===null||in_array($view['state'],['funded','paid_review','refund_review','canceled'],true))return$view;
        // The quote is a bearer capability. An inactive/denied original actor cannot trigger provider polling.
        $actor=$this->rows('SELECT is_active FROM posted_max_users WHERE max_user_id=? LIMIT 1',[$view['user_id']]);
        if($actor===[]||(int)$actor[0]['is_active']!==1||!GitPostedMaxPolicy::operatorAllowed($this->config,$view['user_id']))return$view;
        try{$claim=$this->claimObservation($view,$view['payment_id']);}catch(PostedTelegramBillingException$e){if($e->reason==='status_rate_limited')return$view;throw$e;}
        if(!$claim['call'])return$claim['order'];$o=$claim['order'];$nonce=$o['status_lease']['nonce'];
        try{$this->noOuter();$doc=$this->api->forObservation($o['shop_id'])->request('GET','/v3/payments/'.$o['payment_id']);$payment=PostedTelegramBillingApi::payment($doc,$o,'gitposted_max_order');return$this->applyPayment($o,$payment);}
        catch(Throwable$e){$this->observationFailed($o,$nonce);throw$e;}
    }
    private function knownFromNotification(string $paymentId,?string $ref):?array
    {
        $idx=$this->read(self::paymentKey($paymentId));if($idx!==null){self::keys($idx,['version','payment_id','order_ref']);if($idx['version']!==1||$idx['payment_id']!==$paymentId)throw new PostedTelegramBillingException('invalid_record');
            if($ref!==null&&$ref!==$idx['order_ref'])throw new PostedTelegramBillingException('payment_binding_mismatch',400);$o=$this->readOrder($idx['order_ref']);if($o['payment_id']!==$paymentId)throw new PostedTelegramBillingException('invalid_order');return$o;}
        if($ref===null)return null;
        // Unknown refs are rejected BEFORE a provider request; forged/random IDs cannot fan out authenticated API calls.
        $raw=$this->read(self::orderKey($ref));if($raw===null)return null;$o=self::order($raw);if($o['order_ref']!==$ref||($o['payment_id']!==null&&$o['payment_id']!==$paymentId))throw new PostedTelegramBillingException('payment_binding_mismatch',400);return$o;
    }
    public function webhook(array $event):array
    {
        $this->noOuter();if(($event['type']??null)!=='notification'||!is_array($event['object']??null)||!in_array($event['event']??null,['payment.succeeded','payment.canceled','payment.waiting_for_capture','refund.succeeded'],true))throw new PostedTelegramBillingException('unsupported_notification',400);
        $refund=$event['event']==='refund.succeeded';$object=$event['object'];$id=PostedTelegramBillingApi::uuid($object['id']??null);
        if($refund){$paymentId=PostedTelegramBillingApi::uuid($object['payment_id']??null);$ref=null;}
        else{$paymentId=$id;$ref=$object['metadata']['gitposted_max_order']??null;if($ref!==null)$ref=PostedTelegramBillingApi::ref($ref);}
        $hint=$this->knownFromNotification($paymentId,$ref);if($hint===null)throw new PostedTelegramBillingException('order_not_found',404);
        if($refund){$receipt=$this->read(self::refundKey($id));if($receipt!==null){if(($receipt['version']??null)!==1||($receipt['order_ref']??null)!==$hint['order_ref']||($receipt['refund_id']??null)!==$id||($receipt['payment_id']??null)!==$paymentId)throw new PostedTelegramBillingException('invalid_record');return['handled'=>true,'state'=>'refund_review','replay'=>true];}}
        $claim=$this->claimObservation($hint,$paymentId,$refund);if(!$claim['call'])return['handled'=>true,'state'=>$claim['order']['state'],'replay'=>true];$o=$claim['order'];$nonce=$o['status_lease']['nonce'];
        try{
            $this->noOuter();$expected=$o;$expected['payment_id']=$paymentId;
            if($refund){$refundDoc=$this->api->forObservation($expected['shop_id'])->request('GET','/v3/refunds/'.$id);$verifiedRefund=PostedTelegramBillingApi::refund($refundDoc,$expected,$id);}
            $paymentDoc=$this->api->forObservation($expected['shop_id'])->request('GET','/v3/payments/'.$paymentId);$payment=PostedTelegramBillingApi::payment($paymentDoc,$expected,'gitposted_max_order');
            if($refund){
                if($payment['status']!=='succeeded'||!$payment['paid'])throw new PostedTelegramBillingException('refund_payment_unconfirmed');
                $out=$this->locked($o,function(array$c,?array$u)use($o,$payment,$verifiedRefund):array{
                    $v=$this->readOrder($o['order_ref'],true);$this->bindPayment($v,$payment['id']);$key=self::refundKey($verifiedRefund['id']);$old=$this->read($key,true);
                    if($old===null)$this->put($key,['version'=>1,'order_ref'=>$v['order_ref'],'refund_id'=>$verifiedRefund['id'],'payment_id'=>$payment['id'],'amount_minor'=>$verifiedRefund['amount_minor'],'currency'=>'RUB','verified_at'=>$this->now(),'state'=>'refund_review'],true);
                    elseif(($old['order_ref']??null)!==$v['order_ref']||($old['refund_id']??null)!==$verifiedRefund['id'])throw new PostedTelegramBillingException('refund_binding_mismatch');
                    // v1 never claws back a manual/newer license. A verified refund is a durable operator-review receipt.
                    $v['state']='refund_review';$v['funded_at']??=$this->now();$v['provider_status']='succeeded';$v['status_lease']=null;$v['create_lease']=null;$v['result_code']='verified_refund_requires_review';$v['revision']++;return$this->storeOrder($v);
                });
            }else$out=$this->applyPayment($o,$payment);
            return['handled'=>true,'state'=>$out['state'],'replay'=>false];
        }catch(Throwable$e){$this->observationFailed($o,$nonce);throw$e;}
    }
}
