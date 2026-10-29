<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-billing-api.php';

/** One receiver, two fixed ledgers. Local typed bindings precede every authenticated provider read. */
final class GitPostedPaymentWebhookRouter
{
    private Closure $telegramFactory;
    private Closure $maxFactory;
    public function __construct(private PDO $pdo,array $config,?callable $telegramFactory=null,?callable $maxFactory=null)
    {
        $this->telegramFactory=$telegramFactory===null?static fn()=>new PostedTelegramBilling($pdo,$config):Closure::fromCallable($telegramFactory);
        $this->maxFactory=$maxFactory===null?static fn()=>new GitPostedMaxBilling($pdo,$config):Closure::fromCallable($maxFactory);
    }
    private function read(string $key):?array
    {
        if(strlen($key)>64||preg_match('/\A(?:telegram|max)_pay_(?:order|payment)_[a-f0-9]{32}\z/D',$key)!==1)throw new PostedTelegramBillingException('invalid_record');
        $q=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$q->execute([$key]);$raw=$q->fetchColumn();if($raw===false)return null;
        if(!is_string($raw)||strlen($raw)>60000)throw new PostedTelegramBillingException('invalid_record');
        try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new PostedTelegramBillingException('invalid_record');}
        if(!is_array($v))throw new PostedTelegramBillingException('invalid_record');return$v;
    }
    /** SQL-only route selection. User-controlled platform fields never select a factory. */
    public function platform(array $event):string
    {
        if($this->pdo->inTransaction())throw new LogicException('Webhook router requires no outer transaction.');
        if(($event['type']??null)!=='notification'||!in_array($event['event']??null,['payment.succeeded','payment.canceled','payment.waiting_for_capture','refund.succeeded'],true)||!is_array($event['object']??null))throw new PostedTelegramBillingException('unsupported_notification',400);
        $object=$event['object'];$id=PostedTelegramBillingApi::uuid($object['id']??null);$refund=$event['event']==='refund.succeeded';
        $pid=$refund?PostedTelegramBillingApi::uuid($object['payment_id']??null):$id;
        $metadata=$refund?[]:($object['metadata']??[]);if(!is_array($metadata))throw new PostedTelegramBillingException('invalid_notification',400);
        $refs=[];foreach(['telegram'=>'gitposted_order','max'=>'gitposted_max_order']as$platform=>$key)if(array_key_exists($key,$metadata))$refs[$platform]=PostedTelegramBillingApi::ref($metadata[$key]);
        if(count($refs)>1)throw new PostedTelegramBillingException('ambiguous_payment_platform',400);
        $known=[];
        foreach(['telegram','max']as$platform){
            $index=$this->read($platform.'_pay_payment_'.str_replace('-','',$pid));$ref=$refs[$platform]??null;
            if($index!==null){$keys=array_keys($index);sort($keys);if($keys!==['order_ref','payment_id','version']||($index['version']??null)!==1||($index['payment_id']??null)!==$pid)throw new PostedTelegramBillingException('invalid_record');
                $indexed=PostedTelegramBillingApi::ref($index['order_ref']??null);if($ref!==null&&$ref!==$indexed)throw new PostedTelegramBillingException('payment_binding_mismatch',400);$ref=$indexed;}
            if($ref===null)continue;$raw=$this->read($platform.'_pay_order_'.$ref);if($raw===null){if($index!==null)throw new PostedTelegramBillingException('invalid_record');continue;}
            $order=$platform==='telegram'?PostedTelegramBilling::order($raw):GitPostedMaxBilling::order($raw);
            if($order['order_ref']!==$ref||($order['payment_id']!==null&&$order['payment_id']!==$pid)||($index!==null&&$order['payment_id']!==$pid))throw new PostedTelegramBillingException('payment_binding_mismatch',400);
            $known[$platform]=$ref;
        }
        if(count($known)!==1)throw new PostedTelegramBillingException(count($known)>1?'ambiguous_payment_platform':'order_not_found',count($known)>1?400:404);
        $platform=array_key_first($known);if($refs!==[]&&!array_key_exists($platform,$refs))throw new PostedTelegramBillingException('payment_binding_mismatch',400);
        return$platform;
    }
    public function webhook(array $event):array
    {$platform=$this->platform($event);$factory=$platform==='telegram'?$this->telegramFactory:$this->maxFactory;return$factory()->webhook($event);}
}
