<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Discussion work is independent of channel publication and never runs during require. */
trait GitPostedTelegramCommentsIntegration
{
    private bool $telegramCommentsDeferred = false;
    private function observeTelegramDiscussionRoot(array $update):bool
    {
        if(($this->config['telegram_bot_enabled']??true)!==true)return false;
        if(!$this->telegramCommentsObservePrototype05($update))return false;
        $this->telegramCommentsDeferred=true;return true;
    }
    private function noteTelegramCommentsFullPublication():void{$this->telegramCommentsDeferred=true;}
    public function hasTelegramCommentsWork():bool{return$this->telegramCommentsDeferred;}
    public function maintainTelegramComments(int $limit=1):array
    {
        if($limit<1||$limit>PostedTelegramCommentsPrototype05::MAX_BATCH)throw new InvalidArgumentException('comments_batch_limit');
        if($this->pdo->inTransaction())throw new LogicException('comments_transport_outside_transaction_required');
        $this->telegramCommentsDeferred=false;
        if(($this->config['telegram_bot_enabled']??true)!==true)return['blocked'=>'telegram_disabled'];
        return$this->telegramCommentsMaintenanceV3($limit);
    }
}
