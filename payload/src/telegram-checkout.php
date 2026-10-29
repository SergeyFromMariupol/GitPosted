<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/telegram-billing.php';
require_once __DIR__.'/telegram-payment-http.php';
$paymentConfig=PostedConfig::get();
$paymentFactory=static fn():PostedTelegramBilling=>new PostedTelegramBilling(PostedDb::pdo($paymentConfig),$paymentConfig);
$paymentMethod=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$paymentBody=$paymentMethod==='POST'?(string)file_get_contents('php://input',false,null,0,4097):'';
$paymentHeaders=[];if(isset($_SERVER['HTTP_ORIGIN']))$paymentHeaders['Origin']=$_SERVER['HTTP_ORIGIN'];
[$paymentStatus,$paymentResponseHeaders,$paymentHtml]=PostedTelegramPaymentHttp::checkout($paymentMethod,$_GET,$paymentHeaders,$paymentBody,$_COOKIE,$paymentConfig,$paymentFactory);
http_response_code($paymentStatus);foreach($paymentResponseHeaders as$paymentName=>$paymentValue)header($paymentName.': '.$paymentValue);
echo$paymentHtml;
