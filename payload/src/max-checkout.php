<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/max-policy.php';
require_once __DIR__.'/max-subscription-ui.php';
require_once __DIR__.'/max-billing.php';
require_once __DIR__.'/max-payment-http.php';
$paymentConfig=PostedConfig::get();
$paymentFactory=static fn():GitPostedMaxBilling=>new GitPostedMaxBilling(PostedDb::pdo($paymentConfig),$paymentConfig);
$paymentMethod=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$paymentBody=$paymentMethod==='POST'?(string)file_get_contents('php://input',false,null,0,4097):'';
$paymentHeaders=[];if(isset($_SERVER['HTTP_ORIGIN']))$paymentHeaders['Origin']=$_SERVER['HTTP_ORIGIN'];
[$paymentStatus,$paymentResponseHeaders,$paymentHtml]=GitPostedMaxPaymentHttp::checkout($paymentMethod,$_GET,$paymentHeaders,$paymentBody,$_COOKIE,$paymentConfig,$paymentFactory);
http_response_code($paymentStatus);foreach($paymentResponseHeaders as$paymentName=>$paymentValue)header($paymentName.': '.$paymentValue);
echo$paymentHtml;
