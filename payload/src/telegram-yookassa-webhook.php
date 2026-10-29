<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/telegram-billing.php';
require_once __DIR__.'/telegram-payment-http.php';
require_once __DIR__.'/max-policy.php';
require_once __DIR__.'/max-subscription-ui.php';
require_once __DIR__.'/max-billing.php';
require_once __DIR__.'/payment-webhook-router.php';
$notificationConfig=PostedConfig::get();
$notificationFactory=static fn():GitPostedPaymentWebhookRouter=>new GitPostedPaymentWebhookRouter(PostedDb::pdo($notificationConfig),$notificationConfig);
$notificationMethod=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$notificationBody=$notificationMethod==='POST'?(string)file_get_contents('php://input',false,null,0,65537):'';
[$notificationStatus,$notificationHeaders,$notificationResponse]=PostedTelegramPaymentHttp::webhook($notificationMethod,$notificationBody,$notificationConfig,$notificationFactory);
http_response_code($notificationStatus);foreach($notificationHeaders as$notificationName=>$notificationValue)header($notificationName.': '.$notificationValue);
echo$notificationResponse;
