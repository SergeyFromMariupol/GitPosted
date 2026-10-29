<?php
declare(strict_types=1);

// Durable discussion-root control; publication and immediate-source evidence are independent.
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

final class PostedTelegramCommentsHoldoffPrototype05 extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly int $retryAfter)
    {if(!in_array($reason,['discussion_access_required','discussion_check_unavailable','discussion_rate_limited'],true)||$retryAfter<1||$retryAfter>3600)throw new InvalidArgumentException('invalid_comments_holdoff');parent::__construct($reason);}
}

/**
 * Typed technical parser. The fixed existing-bot provider supplies an authenticated exact
 * channels.getMessages result from the existing BOT, never webhook/user JSON.
 * Missing or unsupported immediate-source evidence never authorizes a deletion.
 */
final class PostedTelegramDiscussionSourceProofPrototype05
{
    private function __construct(private readonly array $value){}
    public function __wakeup(): never{throw new LogicException('discussion_proof_cannot_be_unserialized');}
    public function __unserialize(array $value): never{throw new LogicException('discussion_proof_cannot_be_unserialized');}
    private static function channelPeer(mixed $peer): string
    {
        if(!is_array($peer)||($peer['_']??null)!=='PeerChannel')throw new InvalidArgumentException('discussion_origin_unproven');
        $id=$peer['channel_id']??null;if(!is_int($id)||$id<1||$id>1000000000000)throw new InvalidArgumentException('discussion_origin_unproven');return (string)(-1000000000000-$id);
    }
    public static function fromRawChannelMessage(array $observation,int $verifiedBotId,array $raw,int $checkedAt): self
    {
        PostedTelegramCommentsPrototype05::validateObservation($observation);PostedTelegramCommentsPrototype05::id($verifiedBotId);PostedTelegramCommentsPrototype05::id($checkedAt);
        $forward=$raw['fwd_from']??null;$original=$observation['original'];
        if(($raw['_']??null)!=='Message'||($raw['id']??null)!==$observation['group_message_id']||($raw['date']??null)!==$observation['group_date']
            ||self::channelPeer($raw['peer_id']??null)!==$observation['group_chat_id']||self::channelPeer($raw['from_id']??null)!==$observation['sender_channel_id']
            ||!is_array($forward)||($forward['_']??null)!=='MessageFwdHeader'||($forward['imported']??false)!==false
            ||self::channelPeer($forward['from_id']??null)!==$original['chat_id']||($forward['channel_post']??null)!==$original['message_id']||($forward['date']??null)!==$original['date']
            ||self::channelPeer($forward['saved_from_peer']??null)!==$observation['sender_channel_id'])throw new InvalidArgumentException('discussion_origin_unproven');
        $immediateMid=$forward['saved_from_msg_id']??null;if(!is_int($immediateMid)||$immediateMid<1||$immediateMid>2147483647)throw new InvalidArgumentException('discussion_origin_unproven');
        $value=['version'=>2,'kind'=>'raw_saved_from','binding'=>$observation['binding'],'bot_id'=>$verifiedBotId,
            'source_chat_id'=>$observation['sender_channel_id'],'source_message_id'=>$immediateMid,'original'=>$original,
            'group_chat_id'=>$observation['group_chat_id'],'group_message_id'=>$observation['group_message_id'],
            'checked_at'=>$checkedAt,'expires_at'=>$checkedAt+PostedTelegramCommentsPrototype05::EVIDENCE_TTL];
        $value['proof_hash']=PostedTelegramCommentsPrototype05::hash($value);return new self($value);
    }
    public function snapshot(): array{return $this->value;}
    /** Typed only: a webhook/HTTP array with a recomputed hash is never a proof. */
    public static function matches(mixed $proof,array $observation,int $botId,int $now): bool
    {
        if(!$proof instanceof self)return false;$value=$proof->snapshot();$keys=array_keys($value);sort($keys);
        if($keys!==['binding','bot_id','checked_at','expires_at','group_chat_id','group_message_id','kind','original','proof_hash','source_chat_id','source_message_id','version']
            ||($value['version']??null)!==2||($value['kind']??null)!=='raw_saved_from'||($value['binding']??null)!==$observation['binding']||($value['bot_id']??null)!==$botId
            ||!is_int($value['checked_at']??null)||$value['checked_at']>$now||($value['expires_at']??null)!==$value['checked_at']+PostedTelegramCommentsPrototype05::EVIDENCE_TTL||$value['expires_at']<=$now
            ||!is_string($value['proof_hash']??null)||($value['source_chat_id']??null)!==$observation['sender_channel_id']
            ||!PostedTelegramCommentsPrototype05::same($value['original']??null,$observation['original']))return false;
        foreach(['group_chat_id','group_message_id']as$key)if(($value[$key]??null)!==$observation[$key])return false;
        try{PostedTelegramCommentsPrototype05::id($value['source_message_id']);}catch(InvalidArgumentException){return false;}
        $hash=$value['proof_hash'];unset($value['proof_hash']);return hash_equals(PostedTelegramCommentsPrototype05::hash($value),$hash);
    }
}

/** Frozen before dispatch, independently of whether a multipost target was selected. */
final class PostedTelegramKnownSendPlanPrototype05
{
    private function __construct(private readonly array $value){}
    public static function reserve(array $post,array $channel,int $primaryParts,int $continuationParts,string $submission): self
    {
        if(($post['status']??null)!=='publishing'||$primaryParts<1||$continuationParts<0||$primaryParts+$continuationParts>100||preg_match('/\A[a-f0-9]{64}\z/D',$submission)!==1)throw new InvalidArgumentException('invalid_known_send_reservation');
        $value=['version'=>1,'post_id'=>PostedTelegramCommentsPrototype05::dbId($post['id']??null),'channel_id'=>PostedTelegramCommentsPrototype05::dbId($channel['id']??null),
            'chat_id'=>PostedTelegramCommentsPrototype05::chatId($channel['chat_id']??null),'comments_enabled'=>PostedTelegramCommentsPrototype05::flag($post['comments_enabled']??null),
            'primary_parts'=>$primaryParts,'continuation_parts'=>$continuationParts,'expected_parts'=>$primaryParts+$continuationParts,'submission_key'=>$submission];
        if(PostedTelegramCommentsPrototype05::dbId($post['channel_id']??null)!==$value['channel_id'])throw new InvalidArgumentException('known_send_channel_mismatch');
        $value['plan_hash']=PostedTelegramCommentsPrototype05::hash($value);return new self($value);
    }
    public static function restore(array $value): self
    {
        $keys=array_keys($value);sort($keys);if($keys!==['channel_id','chat_id','comments_enabled','continuation_parts','expected_parts','plan_hash','post_id','primary_parts','submission_key','version'])throw new InvalidArgumentException('known_send_plan_corrupt');
        $expected=self::reserve(['id'=>$value['post_id'],'channel_id'=>$value['channel_id'],'status'=>'publishing','comments_enabled'=>$value['comments_enabled']],['id'=>$value['channel_id'],'chat_id'=>$value['chat_id']],$value['primary_parts'],$value['continuation_parts'],$value['submission_key'])->snapshot();
        if(!PostedTelegramCommentsPrototype05::same($expected,$value))throw new InvalidArgumentException('known_send_plan_corrupt');return new self($value);
    }
    public function snapshot(): array{return $this->value;}
}

/** Typed complete-send evidence, never constructed by a channel/group update or by status='sent' alone. */
final class PostedTelegramKnownFullSendPrototype05
{
    private function __construct(private readonly array $value){}
    public static function complete(PostedTelegramKnownSendPlanPrototype05 $plan,array $post,array $channel,array $allSlots,bool $continuationComplete,bool $deliveryCertain): self
    {
        $binding=$plan->snapshot();$ids=PostedTelegramCommentsPrototype05::mids($allSlots);
        if(!$continuationComplete||!$deliveryCertain||count($ids)!==$binding['expected_parts']||($post['status']??null)!=='sent'
            ||PostedTelegramCommentsPrototype05::dbId($post['id']??null)!==$binding['post_id']||PostedTelegramCommentsPrototype05::dbId($post['channel_id']??null)!==$binding['channel_id']
            ||PostedTelegramCommentsPrototype05::dbId($channel['id']??null)!==$binding['channel_id']||PostedTelegramCommentsPrototype05::chatId($channel['chat_id']??null)!==$binding['chat_id']
            ||PostedTelegramCommentsPrototype05::flag($post['comments_enabled']??null)!==$binding['comments_enabled'])throw new InvalidArgumentException('full_publication_not_confirmed');
        $stored=PostedTelegramCommentsPrototype05::mids($post['telegram_message_ids']??null);$sorted=$ids;sort($sorted);sort($stored);if($sorted!==$stored)throw new InvalidArgumentException('full_publication_mid_set_mismatch');
        $value=['version'=>1,'post_id'=>$binding['post_id'],'channel_id'=>$binding['channel_id'],'chat_id'=>$binding['chat_id'],'comments_enabled'=>$binding['comments_enabled'],
            'message_ids'=>$sorted,'expected_parts'=>$binding['expected_parts'],'plan_hash'=>$binding['plan_hash'],'delivery_certain'=>true,'continuation_complete'=>true];
        $value['proof_hash']=PostedTelegramCommentsPrototype05::hash($value);return new self($value);
    }
    public function snapshot(): array{return $this->value;}
    public static function validateSnapshot(array $value): void
    {
        $keys=array_keys($value);sort($keys);if($keys!==['channel_id','chat_id','comments_enabled','continuation_complete','delivery_certain','expected_parts','message_ids','plan_hash','post_id','proof_hash','version']
            ||($value['version']??null)!==1||($value['delivery_certain']??null)!==true||($value['continuation_complete']??null)!==true||!is_int($value['expected_parts']??null)
            ||!is_string($value['plan_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['plan_hash'])!==1||!is_string($value['proof_hash']??null))throw new InvalidArgumentException('known_full_send_proof_corrupt');
        PostedTelegramCommentsPrototype05::id($value['post_id']);PostedTelegramCommentsPrototype05::id($value['channel_id']);PostedTelegramCommentsPrototype05::chatId($value['chat_id']);PostedTelegramCommentsPrototype05::flag($value['comments_enabled']);
        $ids=PostedTelegramCommentsPrototype05::mids($value['message_ids']);$sorted=$ids;sort($sorted);if($sorted!==$ids||count($ids)!==$value['expected_parts'])throw new InvalidArgumentException('known_full_send_proof_corrupt');
        $hash=$value['proof_hash'];unset($value['proof_hash']);if(!hash_equals(PostedTelegramCommentsPrototype05::hash($value),$hash))throw new InvalidArgumentException('known_full_send_proof_corrupt');
    }
}

final class PostedTelegramCommentsPrototype05
{
    public const MAX_AGE=172500; // 48h minus five minutes, measured from the actual GROUP message.
    public const EVIDENCE_TTL=15;
    public const CLAIM_TTL=60;
    public const MAX_QUEUE=1000;
    public const MAX_BATCH=5;
    public const MAX_ATTEMPTS=10;

    public static function chatId(mixed $value): string
    {if(is_int($value))$value=(string)$value;if(!is_string($value)||preg_match('/\A-[1-9][0-9]{0,15}\z/D',$value)!==1)throw new InvalidArgumentException('invalid_chat_id');return $value;}
    public static function id(mixed $value): int
    {if(!is_int($value)||$value<1||$value>PHP_INT_MAX)throw new InvalidArgumentException('invalid_message_or_post_id');return $value;}
    public static function dbId(mixed $value): int
    {if(is_string($value)&&preg_match('/\A[1-9][0-9]{0,18}\z/D',$value)===1&&(strlen($value)<19||strcmp($value,(string)PHP_INT_MAX)<=0))$value=(int)$value;return self::id($value);}
    public static function flag(mixed $value): int
    {if($value===0||$value==='0')return 0;if($value===1||$value==='1')return 1;throw new InvalidArgumentException('invalid_comments_flag');}
    public static function canonical(mixed $value): mixed
    {if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as$key=>$part)$value[$key]=self::canonical($part);return $value;}
    public static function same(mixed $a,mixed $b): bool{return self::canonical($a)===self::canonical($b);}
    public static function hash(array $value): string{return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    public static function mids(mixed $value): array
    {
        if(is_string($value))$value=json_decode($value,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value)||$value===[]||count($value)>100)throw new InvalidArgumentException('actual_full_message_list_required');
        $ids=[];foreach($value as$id){self::id($id);if(isset($ids[$id]))throw new InvalidArgumentException('duplicate_source_mid');$ids[$id]=$id;}return array_values($ids);
    }
    public static function observation(array $update,int $receivedAt): ?array
    {
        $message=$update['message']??null;if(!is_array($message)||($message['is_automatic_forward']??null)!==true)return null;
        try{
            self::id($receivedAt);
            if(($message['chat']['type']??null)!=='supergroup'||($message['forward_origin']['type']??null)!=='channel'
                ||($message['forward_origin']['chat']['type']??null)!=='channel'||($message['sender_chat']['type']??null)!=='channel'
                ||isset($message['business_connection_id'])||isset($message['guest_query_id']))return null;
            $group=self::chatId($message['chat']['id']??null);$sender=self::chatId($message['sender_chat']['id']??null);$origin=self::chatId($message['forward_origin']['chat']['id']??null);
            if($group===$sender||$group===$origin)return null;
            $date=self::id($message['date']??null);$originDate=self::id($message['forward_origin']['date']??null);
            // Native forwards may have an arbitrarily old original date. It is
            // provenance, not publication freshness; only GROUP date bounds TTL.
            if($date>$receivedAt+30||$originDate>$date+5||$receivedAt-$date>=self::MAX_AGE)return null;
            $observation=['version'=>2,'sender_channel_id'=>$sender,
                'original'=>['chat_id'=>$origin,'message_id'=>self::id($message['forward_origin']['message_id']??null),'date'=>$originDate],
                'group_chat_id'=>$group,'group_message_id'=>self::id($message['message_id']??null),'group_date'=>$date,
                'received_at'=>$receivedAt,'expires_at'=>$date+self::MAX_AGE];
            $observation['binding']=self::hash(array_intersect_key($observation,array_flip(['sender_channel_id','original','group_chat_id','group_message_id','group_date'])));return $observation;
        }catch(InvalidArgumentException|JsonException){return null;}
    }
    public static function observationKey(array $observation): string
    {return 'tc5_o_'.substr(self::hash([self::chatId($observation['group_chat_id']),self::id($observation['group_message_id'])]),0,56);}
    public static function receiptKey(array $observation): string{return 'tc5_r_'.substr(self::observationKey($observation),6);}
    public static function publicationKey(int $postId): string{return 'tc5_p_'.self::id($postId);}
    public static function validateObservation(array $observation): void
    {
        $reconstructed=self::observation(['message'=>['is_automatic_forward'=>true,'chat'=>['type'=>'supergroup','id'=>$observation['group_chat_id']??null],
            'sender_chat'=>['type'=>'channel','id'=>$observation['sender_channel_id']??null],'message_id'=>$observation['group_message_id']??null,'date'=>$observation['group_date']??null,
            'forward_origin'=>['type'=>'channel','chat'=>['type'=>'channel','id'=>$observation['original']['chat_id']??null],'message_id'=>$observation['original']['message_id']??null,'date'=>$observation['original']['date']??null]]],self::id($observation['received_at']??null));
        if($reconstructed===null||!self::same($reconstructed,$observation))throw new RuntimeException('comments_observation_binding_corrupt');
    }

    /** Only the real full-publication completion path may create this DTO, never a group/channel update. */
    public static function publication(array $post,array $channel,array $actualMessages,PostedTelegramKnownFullSendPrototype05 $typedProof): array
    {
        $fullProof=$typedProof->snapshot();
        $postId=self::dbId($post['id']??null);$channelId=self::dbId($channel['id']??null);$chat=self::chatId($channel['chat_id']??null);
        if(self::dbId($post['channel_id']??null)!==$channelId||($post['status']??null)!=='sent'||($fullProof['version']??null)!==1
            ||($fullProof['continuation_complete']??null)!==true||($fullProof['delivery_certain']??null)!==true||($fullProof['post_id']??null)!==$postId||($fullProof['channel_id']??null)!==$channelId
            ||($fullProof['chat_id']??null)!==$chat||!is_int($fullProof['expected_parts']??null)||$fullProof['expected_parts']<1||$fullProof['expected_parts']>100)throw new InvalidArgumentException('full_publication_not_confirmed');
        $ids=self::mids($post['telegram_message_ids']??null);$proofIds=self::mids($fullProof['message_ids']??null);$sorted=$ids;$sortedProof=$proofIds;sort($sorted);sort($sortedProof);
        if($sorted!==$sortedProof||count($ids)!==$fullProof['expected_parts']||!array_is_list($actualMessages)||count($actualMessages)>count($ids))throw new InvalidArgumentException('full_publication_mid_set_mismatch');
        $dates=[];foreach($actualMessages as$message){if(!is_array($message)||($message['chat']['type']??null)!=='channel'||self::chatId($message['chat']['id']??null)!==$chat)throw new InvalidArgumentException('source_response_chat_mismatch');
            $id=self::id($message['message_id']??null);if(!in_array($id,$ids,true)||isset($dates[$id]))throw new InvalidArgumentException('source_response_mid_mismatch');$dates[$id]=self::id($message['date']??null);}
        ksort($dates,SORT_NUMERIC);$ids=$sorted;
        return ['version'=>1,'post_id'=>$postId,'channel_id'=>$channelId,'chat_id'=>$chat,'comments_enabled'=>self::flag($post['comments_enabled']??null),'message_ids'=>$ids,'source_dates'=>$dates,'expected_parts'=>$fullProof['expected_parts'],'full_send_proof'=>$fullProof];
    }

    public static function evidence(array $observation,int $botId,array $sourceResponse,array $groupResponse,array $memberResponse,int $now,?PostedTelegramDiscussionSourceProofPrototype05 $sourceProof=null): array
    {
        $proof=$sourceProof?->snapshot();if(!PostedTelegramDiscussionSourceProofPrototype05::matches($sourceProof,$observation,$botId,$now))throw new InvalidArgumentException('discussion_origin_unproven');
        foreach([$sourceResponse,$groupResponse,$memberResponse]as$response)if(($response['ok']??null)!==true||!is_array($response['result']??null))throw new InvalidArgumentException('discussion_access_required');
        $source=$sourceResponse['result'];$group=$groupResponse['result'];$member=$memberResponse['result'];
        try{$linked=($source['type']??null)==='channel'&&self::chatId($source['id']??null)===$observation['sender_channel_id']
            &&($group['type']??null)==='supergroup'&&self::chatId($group['id']??null)===$observation['group_chat_id']
            &&self::chatId($source['linked_chat_id']??null)===$observation['group_chat_id']&&self::chatId($group['linked_chat_id']??null)===$observation['sender_channel_id'];
        }catch(InvalidArgumentException){$linked=false;}
        if(!$linked)throw new InvalidArgumentException('discussion_link_changed');
        // A username or sender_chat alone never proves the current discussion link.
        if(($member['user']['id']??null)!==self::id($botId)||($member['user']['is_bot']??null)!==true||($member['status']??null)!=='administrator'||($member['can_delete_messages']??null)!==true)throw new InvalidArgumentException('discussion_delete_right_required');
        return ['version'=>1,'binding'=>$observation['binding'],'bot_id'=>$botId,'checked_at'=>$now,'expires_at'=>min($now+self::EVIDENCE_TTL,$proof['expires_at']),'immediate_source'=>$proof,'source_proof'=>$sourceProof];
    }

    public static function decision(array $observation,array $publication,array $post,array $channel,array $evidence,int $now): string
    {
        self::validateObservation($observation);
        self::validatePublication($publication);
        if($now>=$observation['expires_at'])return 'expired';
        if(($evidence['version']??null)!==1||($evidence['binding']??null)!==$observation['binding']||($evidence['checked_at']??PHP_INT_MAX)>$now||($evidence['expires_at']??0)<=$now)return 'evidence_stale';
        if(!PostedTelegramDiscussionSourceProofPrototype05::matches($evidence['source_proof']??null,$observation,(int)($evidence['bot_id']??0),$now)||!self::same($evidence['immediate_source']??null,$evidence['source_proof']->snapshot()))return 'discussion_origin_unproven';
        if(($publication['version']??null)!==1||(int)($post['id']??0)!==($publication['post_id']??null)||(int)($channel['id']??0)!==($publication['channel_id']??null)
            ||(int)($post['channel_id']??0)!==(int)$channel['id']||self::chatId($channel['chat_id']??null)!==$observation['sender_channel_id']||($publication['chat_id']??null)!==$observation['sender_channel_id'])return 'publication_binding_changed';
        if(($post['status']??null)!=='sent'||(int)($channel['is_active']??0)!==1||(int)($channel['is_archived']??0)!==0||(int)($channel['added_by']??0)<=0)return 'publication_not_current';
        if(self::flag($post['comments_enabled']??null)!==0||($publication['comments_enabled']??null)!==0)return 'comments_enabled';
        $current=self::mids($post['telegram_message_ids']??null);$expected=self::mids($publication['message_ids']??null);sort($current);sort($expected);
        if($current!==$expected||!in_array($evidence['immediate_source']['source_message_id'],$expected,true))return 'source_mid_not_confirmed';
        // No immediate-source date is invented from an upstream header or MessageId-only result.
        return 'delete';
    }
    public static function validatePublication(array $value): void
    {
        $proof=$value['full_send_proof']??null;if(!is_array($proof))throw new InvalidArgumentException('comments_full_send_proof_missing');PostedTelegramKnownFullSendPrototype05::validateSnapshot($proof);
        foreach(['version','post_id','channel_id','chat_id','comments_enabled','message_ids','expected_parts']as$key)if(!self::same($value[$key]??null,$proof[$key]??null))throw new InvalidArgumentException('comments_certificate_binding_corrupt');
        if(!is_array($value['source_dates']??null))throw new InvalidArgumentException('comments_source_dates_corrupt');foreach($value['source_dates']as$id=>$date)if(!in_array(self::dbId($id),$value['message_ids'],true)||!is_int($date)||$date<1)throw new InvalidArgumentException('comments_source_dates_corrupt');
    }
}

final class PostedTelegramCommentsStorePrototype05
{
    private bool $observationMutex=false;
    public function __construct(private PDO $pdo,private string $observationLock='gitposted_comments_observe')
    {if($observationLock!=='gitposted_comments_observe'&&(PHP_SAPI!=='cli'||preg_match('/\Agitposted_comments_observe_fixture_[0-9]+\z/D',$observationLock)!==1))throw new InvalidArgumentException('invalid_comments_lock_namespace');}
    private function read(string $key,bool $lock=false): ?array
    {
        $query=$this->pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=:key LIMIT 1'.($lock?' FOR UPDATE':''));$query->execute(['key'=>$key]);$raw=$query->fetchColumn();if($raw===false)return null;
        $value=json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($value))throw new RuntimeException('comments_record_corrupt');return $value;
    }
    private function put(string $key,array $value): void
    {
        if(!$this->pdo->inTransaction()||preg_match('/\Atc5_(?:[or]_[a-f0-9]{56}|[ps]_[1-9][0-9]{0,18})\z/D',$key)!==1)throw new LogicException('unexpected_comments_write');
        $this->pdo->prepare('INSERT INTO posted_app_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['key'=>$key,'value'=>json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }
    private function begin(): void{if($this->pdo->inTransaction())throw new LogicException('comments_store_requires_own_transaction');$this->pdo->beginTransaction();}
    public function assertNoTransaction(): void{if($this->pdo->inTransaction())throw new LogicException('comments_transport_outside_transaction_required');}
    private function rollback(Throwable $error): never{if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    private function beginObservation(): void
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_observer_requires_no_transaction');$query=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$query->execute([$this->observationLock]);
        if((int)$query->fetchColumn()!==1)throw new RuntimeException('comments_observer_busy_retry');$this->observationMutex=true;try{$this->pdo->beginTransaction();}catch(Throwable $error){$this->releaseObservation();throw $error;}
    }
    private function releaseObservation(): void
    {if($this->observationMutex){$query=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$this->observationLock]);$this->observationMutex=false;}}
    private function requireQueueCapacity(int $now): void
    {
        $query=$this->pdo->prepare("SELECT COUNT(*) FROM posted_app_settings WHERE setting_key LIKE 'tc5!_o!_%' ESCAPE '!' AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.state')) NOT IN ('done','expired','ignored') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.observation.expires_at')) AS UNSIGNED)>CAST(:now AS UNSIGNED)");$query->execute(['now'=>$now]);
        if((int)$query->fetchColumn()>=PostedTelegramCommentsPrototype05::MAX_QUEUE)throw new RuntimeException('comments_observation_queue_full');
    }
    private function channel(int $id,bool $lock=false): ?array
    {$query=$this->pdo->prepare('SELECT * FROM posted_channels WHERE id=:id LIMIT 1'.($lock?' FOR UPDATE':''));$query->execute(['id'=>$id]);$row=$query->fetch();return is_array($row)?$row:null;}
    private function post(int $id,bool $lock=false): ?array
    {$query=$this->pdo->prepare('SELECT * FROM posted_posts WHERE id=:id LIMIT 1'.($lock?' FOR UPDATE':''));$query->execute(['id'=>$id]);$row=$query->fetch();return is_array($row)?$row:null;}

    public function recordReservationLocked(array $post,array $channel,int $primaryParts,int $continuationParts,string $submission): PostedTelegramKnownSendPlanPrototype05
    {
        if(!$this->pdo->inTransaction())throw new LogicException('send_plan_requires_existing_transaction');$lockedChannel=$this->channel((int)$channel['id'],true);$lockedPost=$this->post((int)$post['id'],true);if($lockedChannel===null||$lockedPost===null)throw new RuntimeException('publication_disappeared');
        $plan=PostedTelegramKnownSendPlanPrototype05::reserve($lockedPost,$lockedChannel,$primaryParts,$continuationParts,$submission);$key='tc5_s_'.(int)$post['id'];$old=$this->read($key,true);
        if($old!==null&&($old['submission_key']??null)===$submission&&!PostedTelegramCommentsPrototype05::same($old,$plan->snapshot()))throw new RuntimeException('known_send_layout_changed');
        if($old===null||($old['submission_key']??null)!==$submission){if($this->read(PostedTelegramCommentsPrototype05::publicationKey((int)$post['id']),true)!==null)throw new RuntimeException('completed_send_plan_is_immutable');$this->put($key,$plan->snapshot());}return $plan;
    }
    public function knownFullLocked(array $post,array $channel,array $allSlots,bool $continuationComplete,bool $deliveryCertain,array $actualMessages=[]): ?array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('full_send_proof_requires_existing_transaction');$lockedChannel=$this->channel((int)$channel['id'],true);$lockedPost=$this->post((int)$post['id'],true);if($lockedChannel===null||$lockedPost===null)throw new RuntimeException('publication_disappeared');
        // Existing in-flight attempts predate this feature. Preserve their normal
        // finalize path; absence of a frozen plan grants NO Comments eligibility.
        $snapshot=$this->read('tc5_s_'.(int)$post['id'],true);if($snapshot===null)return null;$plan=PostedTelegramKnownSendPlanPrototype05::restore($snapshot);
        $proof=PostedTelegramKnownFullSendPrototype05::complete($plan,$lockedPost,$lockedChannel,$allSlots,$continuationComplete,$deliveryCertain);return $this->recordPublicationLocked($lockedPost,$lockedChannel,$actualMessages,$proof);
    }

    /** Caller holds the existing channel→post locks during a genuine full-publication finalize transaction. */
    public function recordPublicationLocked(array $post,array $channel,array $actualMessages,PostedTelegramKnownFullSendPrototype05 $fullProof): array
    {
        if(!$this->pdo->inTransaction())throw new LogicException('publication_certificate_requires_existing_transaction');
        $lockedChannel=$this->channel((int)$channel['id'],true);$lockedPost=$this->post((int)$post['id'],true);if($lockedChannel===null||$lockedPost===null)throw new RuntimeException('publication_disappeared');
        $certificate=PostedTelegramCommentsPrototype05::publication($lockedPost,$lockedChannel,$actualMessages,$fullProof);$key=PostedTelegramCommentsPrototype05::publicationKey($certificate['post_id']);$existing=$this->read($key,true);
        if($existing!==null&&!PostedTelegramCommentsPrototype05::same($existing,$certificate))throw new RuntimeException('publication_certificate_is_immutable');if($existing===null)$this->put($key,$certificate);return $certificate;
    }

    /** No Telegram user, permission, post, draft input or confirmation is created by an observation. */
    public function observe(array $update,int $now): bool
    {
        $observation=PostedTelegramCommentsPrototype05::observation($update,$now);if($observation===null)return false;
        $this->beginObservation();try{$query=$this->pdo->prepare('SELECT * FROM posted_channels WHERE chat_id=:chat AND is_active=1 AND is_archived=0 LIMIT 1 FOR UPDATE');$query->execute(['chat'=>$observation['sender_channel_id']]);$channel=$query->fetch();
            if(!is_array($channel)||(int)($channel['added_by']??0)<=0||(class_exists('PostedTelegramAuthorityPause',false)&&PostedTelegramAuthorityPause::blocked($this->pdo,$channel,true))){$this->pdo->commit();return false;}
            $key=PostedTelegramCommentsPrototype05::observationKey($observation);$existing=$this->read($key,true);
            if($existing!==null){if(($existing['observation']['binding']??null)!==$observation['binding']){if(($existing['state']??null)!=='done'){$existing['state']='blocked';$existing['reason']='observation_conflict';$existing['next_at']=$existing['observation']['expires_at'];$this->put($key,$existing);}$this->pdo->commit();return false;}$this->pdo->commit();return true;}
            $this->requireQueueCapacity($now);
            $this->put($key,['version'=>1,'channel_id'=>(int)$channel['id'],'observation'=>$observation,'state'=>'queued','reason'=>null,'next_at'=>$now,'claim_nonce'=>null,'claim_until'=>0,'attempts'=>0,'post_id'=>null]);$this->pdo->commit();return true;
        }catch(Throwable $error){$this->rollback($error);}finally{$this->releaseObservation();}
    }
    public function due(int $now,int $limit=PostedTelegramCommentsPrototype05::MAX_BATCH): array
    {
        if($limit<1||$limit>PostedTelegramCommentsPrototype05::MAX_BATCH)throw new InvalidArgumentException('comments_batch_limit');
        $query=$this->pdo->prepare("SELECT setting_key FROM posted_app_settings WHERE setting_key LIKE 'tc5!_o!_%' ESCAPE '!' AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.state')) IN ('queued','blocked','unknown','claimed') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.next_at')) AS UNSIGNED)<=:now ORDER BY CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.state'))='queued' THEN 0 WHEN JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.state'))='blocked' AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.reason'))='publication_not_finalized' THEN 1 ELSE 2 END,CAST(JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.next_at')) AS UNSIGNED),setting_key LIMIT ".$limit);$query->execute(['now'=>$now]);return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    /** Observation access intentionally performs NO certificate lookup. */
    public function context(string $eventKey): ?array
    {
        if(preg_match('/\Atc5_o_[a-f0-9]{56}\z/D',$eventKey)!==1)throw new InvalidArgumentException('invalid_comments_queue_key');$event=$this->read($eventKey);if($event===null)return null;
        $observation=$event['observation']??null;if(!is_array($observation)||PostedTelegramCommentsPrototype05::observationKey($observation)!==$eventKey)throw new RuntimeException('comments_observation_binding_corrupt');PostedTelegramCommentsPrototype05::validateObservation($observation);
        $channel=$this->channel((int)($event['channel_id']??0));
        if(!is_array($channel)||(string)$channel['chat_id']!==$observation['sender_channel_id']||(int)$channel['is_active']!==1||(int)$channel['is_archived']!==0||(int)($channel['added_by']??0)<=0
            ||(class_exists('PostedTelegramAuthorityPause',false)&&PostedTelegramAuthorityPause::blocked($this->pdo,$channel)))return ['event'=>$event,'current_blocked'=>true];
        return ['event'=>$event];
    }
    /** Invoked only AFTER an independently authenticated exact-group-MID read. */
    public function publicationForProof(array $observation,PostedTelegramDiscussionSourceProofPrototype05 $proof,int $botId,int $now): array
    {
        PostedTelegramCommentsPrototype05::validateObservation($observation);
        if(!PostedTelegramDiscussionSourceProofPrototype05::matches($proof,$observation,$botId,$now))throw new InvalidArgumentException('discussion_origin_unproven');$immediate=$proof->snapshot();
        $query=$this->pdo->prepare("SELECT setting_value FROM posted_app_settings WHERE setting_key LIKE 'tc5!_p!_%' ESCAPE '!' AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.chat_id'))=:chat AND JSON_CONTAINS(JSON_EXTRACT(setting_value,'$.message_ids'),CAST(:mid AS JSON)) LIMIT 2");$query->execute(['chat'=>$immediate['source_chat_id'],'mid'=>(string)$immediate['source_message_id']]);$rows=$query->fetchAll(PDO::FETCH_COLUMN);
        if(count($rows)>1)return ['publication'=>null,'reason'=>'publication_ambiguous'];$publication=$rows===[]?null:json_decode((string)$rows[0],true,64,JSON_THROW_ON_ERROR);
        if(is_array($publication))PostedTelegramCommentsPrototype05::validatePublication($publication);return ['publication'=>$publication,'reason'=>$publication===null?'publication_not_finalized':null];
    }
    public function defer(string $eventKey,string $reason,int $now,bool $terminal=false,int $delay=30): void
    {
        if($delay<1||$delay>3600||!in_array($reason,['publication_not_finalized','publication_ambiguous','discussion_access_required','discussion_check_unavailable','discussion_rate_limited','discussion_link_changed','discussion_delete_right_required','discussion_origin_unproven','expired','comments_enabled','evidence_stale','publication_binding_changed','publication_not_current','source_mid_not_confirmed','source_date_mismatch'],true))throw new InvalidArgumentException('invalid_comments_defer_reason');
        $this->begin();try{$event=$this->read($eventKey,true);if($event!==null&&($event['state']??null)!=='done'&&!($event['state']==='claimed'&&$event['claim_until']>$now)){
            $event['state']=$reason==='expired'?'expired':($terminal?'ignored':'blocked');$event['reason']=$reason;$event['next_at']=$terminal?$event['observation']['expires_at']:$now+$delay;$this->put($eventKey,$event);}$this->pdo->commit();}catch(Throwable $error){$this->rollback($error);}
    }

    /** Compatible channel→post→event→existing receipt locks. Commit happens before any deletion API. */
    public function claim(string $eventKey,array $publication,array $evidence,Closure $clock): ?array
    {
        $this->begin();try{$channel=$this->channel((int)$publication['channel_id'],true);$post=$this->post((int)$publication['post_id'],true);$event=$this->read($eventKey,true);
            if($channel===null||$post===null||$event===null){$this->pdo->commit();return null;}
            $current=$this->read(PostedTelegramCommentsPrototype05::publicationKey((int)$post['id']),true);$now=($clock)();if(!is_int($now)||$now<1)throw new LogicException('invalid_comments_claim_clock');
            if(!PostedTelegramCommentsPrototype05::same($current,$publication)||!in_array($event['state']??null,['queued','blocked','unknown','claimed'],true)||($event['reason']??null)==='observation_conflict'||($event['next_at']??PHP_INT_MAX)>$now||($event['state']==='claimed'&&$event['claim_until']>$now)){$this->pdo->commit();return null;}
            if((int)($event['channel_id']??0)!==(int)$channel['id'])throw new RuntimeException('comments_local_channel_binding_changed');
            if($event['attempts']>=PostedTelegramCommentsPrototype05::MAX_ATTEMPTS){$event['state']='blocked';$event['reason']='attempt_limit';$event['next_at']=$event['observation']['expires_at'];$this->put($eventKey,$event);$this->pdo->commit();return null;}
            $decision=class_exists('PostedTelegramAuthorityPause',false)&&PostedTelegramAuthorityPause::blocked($this->pdo,$channel,true)?'publication_not_current':PostedTelegramCommentsPrototype05::decision($event['observation'],$publication,$post,$channel,$evidence,$now);
            if($decision!=='delete'){$event['state']=$decision==='expired'?'expired':($decision==='comments_enabled'?'ignored':'blocked');$event['reason']=$decision;$event['next_at']=$now+30;$this->put($eventKey,$event);$this->pdo->commit();return null;}
            $receiptKey=PostedTelegramCommentsPrototype05::receiptKey($event['observation']);$receipt=$this->read($receiptKey,true);
            if($receipt!==null&&($receipt['binding']??null)!==$event['observation']['binding'])throw new RuntimeException('comments_receipt_identity_conflict');
            if($receipt!==null&&(($receipt['source_chat_id']??null)!==$evidence['immediate_source']['source_chat_id']||($receipt['source_message_id']??null)!==$evidence['immediate_source']['source_message_id']
                ||($receipt['post_id']??null)!==(int)$post['id']||($receipt['channel_id']??null)!==(int)$channel['id']))throw new RuntimeException('comments_immediate_source_conflict');
            if(($receipt['state']??null)==='done'){$event['state']='done';$this->put($eventKey,$event);$this->pdo->commit();return null;}
            $nonce=bin2hex(random_bytes(12));$event['state']='claimed';$event['reason']=null;$event['claim_nonce']=$nonce;$event['claim_until']=$now+PostedTelegramCommentsPrototype05::CLAIM_TTL;$event['next_at']=$event['claim_until'];$event['attempts']++;$event['post_id']=(int)$post['id'];
            $receipt=['version'=>1,'event_key'=>$eventKey,'binding'=>$event['observation']['binding'],'post_id'=>(int)$post['id'],'channel_id'=>(int)$channel['id'],
                'source_chat_id'=>$evidence['immediate_source']['source_chat_id'],'source_message_id'=>$evidence['immediate_source']['source_message_id'],'source_proof_hash'=>$evidence['immediate_source']['proof_hash'],'group_chat_id'=>$event['observation']['group_chat_id'],'group_message_id'=>$event['observation']['group_message_id'],
                'state'=>'claimed','nonce'=>$nonce,'claim_until'=>$event['claim_until'],'expires_at'=>$event['observation']['expires_at'],'attempt'=>$event['attempts'],'outcome'=>null,'finished_at'=>null];
            $this->put($receiptKey,$receipt);$this->put($eventKey,$event);$this->pdo->commit();return $receipt;
        }catch(Throwable $error){$this->rollback($error);}
    }
    public function finish(array $claim,string $outcome,int $now,int $retryAfter=60): bool
    {
        if(!in_array($outcome,['deleted','already_absent','unknown','rate_limited','delete_right_required','delete_rejected','evidence_stale'],true)||$retryAfter<1||$retryAfter>3600)throw new InvalidArgumentException('invalid_comments_outcome');
        $this->begin();try{$this->channel((int)$claim['channel_id'],true);$this->post((int)$claim['post_id'],true);$event=$this->read($claim['event_key'],true);
            if($event===null||($event['claim_nonce']??null)!==$claim['nonce']||($event['state']??null)==='done'){$this->pdo->commit();return false;}
            $key=PostedTelegramCommentsPrototype05::receiptKey($event['observation']);$receipt=$this->read($key,true);if($receipt===null||($receipt['nonce']??null)!==$claim['nonce']||($receipt['binding']??null)!==$claim['binding']||($receipt['state']??null)==='done'){$this->pdo->commit();return false;}
            $done=in_array($outcome,['deleted','already_absent'],true);$event['state']=$done?'done':($now>=$event['observation']['expires_at']?'expired':($outcome==='unknown'?'unknown':'blocked'));$event['reason']=$outcome;$event['next_at']=$now+$retryAfter;$event['claim_until']=0;
            $receipt['state']=$done?'done':$event['state'];$receipt['outcome']=$outcome;$receipt['finished_at']=$now;$receipt['claim_until']=0;
            $this->put($key,$receipt);$this->put($claim['event_key'],$event);$this->pdo->commit();return true;
        }catch(Throwable $error){$this->rollback($error);}
    }
}

final class PostedTelegramCommentsProcessorPrototype05
{
    public static function botApiAdapter(PostedTelegram $api): Closure
    {
        return static function(string $method,array $payload)use($api):array{
            if(!in_array($method,['getChat','getChatMember','deleteMessage'],true))throw new LogicException('unexpected_comments_api_method');
            try{return $api->call($method,$payload);}catch(PostedTelegramRequestException $error){
                if($error->deliveryMayBeUnknown())return ['ok'=>false,'transport_unknown'=>true];$status=$error->httpStatus();$result=['ok'=>false,'error_code'=>$status];
                if($status===400&&$error->getMessage()==='Bad Request: message to delete not found')$result['description']='Bad Request: message to delete not found';
                if($status===429){$retry=$error->retryAfter();$result['parameters']=['retry_after'=>is_int($retry)&&$retry>=1&&$retry<=3600?$retry:300];}return $result;
            }
        };
    }
    /** An omitted immediate-source provider deliberately permits zero deletions. */
    public function __construct(private PostedTelegramCommentsStorePrototype05 $store,private Closure $call,private int $botId,private Closure $clock,private ?Closure $sourceReader=null)
    {PostedTelegramCommentsPrototype05::id($botId);}
    private function now(): int{$now=($this->clock)();if(!is_int($now)||$now<1)throw new LogicException('invalid_comments_clock');return $now;}
    private function readApi(string $method,array $payload): array
    {
        $this->store->assertNoTransaction();
        $response=($this->call)($method,$payload);if(!is_array($response))throw new PostedTelegramCommentsHoldoffPrototype05('discussion_check_unavailable',60);
        if(($response['error_code']??null)===429){$retry=$response['parameters']['retry_after']??null;throw new PostedTelegramCommentsHoldoffPrototype05('discussion_rate_limited',is_int($retry)&&$retry>=1&&$retry<=3600?$retry:300);}
        if(($response['transport_unknown']??null)===true)throw new PostedTelegramCommentsHoldoffPrototype05('discussion_check_unavailable',60);
        if(($response['ok']??null)!==true||!is_array($response['result']??null))throw new PostedTelegramCommentsHoldoffPrototype05('discussion_access_required',300);return $response;
    }
    public function run(int $limit=PostedTelegramCommentsPrototype05::MAX_BATCH): array
    {
        $this->store->assertNoTransaction();
        $result=['observed'=>0,'deleted'=>0,'deferred'=>0];foreach($this->store->due($this->now(),$limit)as$key){$result['observed']++;$context=$this->store->context($key);if($context===null)continue;$event=$context['event'];$observation=$event['observation'];
            if(($context['current_blocked']??false)===true){$this->store->defer($key,'publication_not_current',$this->now());$result['deferred']++;continue;}
            if($this->now()>=$observation['expires_at']){$this->store->defer($key,'expired',$this->now(),true);continue;}
            try{
                // Reader FIRST, then lookup by independently proven immediate Y/MID.
                // No original X/MID fallback is allowed, even if X==Y.
                $proof=$this->sourceReader===null?null:($this->sourceReader)($observation,$this->botId,$this->now());
                if(!$proof instanceof PostedTelegramDiscussionSourceProofPrototype05||!PostedTelegramDiscussionSourceProofPrototype05::matches($proof,$observation,$this->botId,$this->now()))throw new InvalidArgumentException('discussion_origin_unproven');
                $resolved=$this->store->publicationForProof($observation,$proof,$this->botId,$this->now());$publication=$resolved['publication'];
                if(!is_array($publication)){$this->store->defer($key,$resolved['reason'],$this->now());$result['deferred']++;continue;}
                if(($publication['comments_enabled']??null)!==0){$this->store->defer($key,'comments_enabled',$this->now(),true);continue;}
                $source=$this->readApi('getChat',['chat_id'=>$observation['sender_channel_id']]);$group=$this->readApi('getChat',['chat_id'=>$observation['group_chat_id']]);$member=$this->readApi('getChatMember',['chat_id'=>$observation['group_chat_id'],'user_id'=>$this->botId]);
                $evidence=PostedTelegramCommentsPrototype05::evidence($observation,$this->botId,$source,$group,$member,$this->now(),$proof);
            }catch(Throwable $error){$reason=$error instanceof PostedTelegramCommentsHoldoffPrototype05?$error->reason:($error instanceof InvalidArgumentException?$error->getMessage():'discussion_check_unavailable');if(!in_array($reason,['discussion_access_required','discussion_check_unavailable','discussion_rate_limited','discussion_link_changed','discussion_delete_right_required','discussion_origin_unproven'],true))$reason='discussion_check_unavailable';$delay=$error instanceof PostedTelegramCommentsHoldoffPrototype05?$error->retryAfter:30;$this->store->defer($key,$reason,$this->now(),false,$delay);$result['deferred']++;if($reason==='discussion_rate_limited')break;continue;}
            $claim=$this->store->claim($key,$publication,$evidence,$this->clock);if($claim===null)continue;
            $dispatchNow=$this->now();if($dispatchNow>=$evidence['expires_at']||$dispatchNow>=$claim['expires_at']||$dispatchNow>=$claim['claim_until']){$this->store->finish($claim,'evidence_stale',$dispatchNow,30);continue;}
            $outcome='unknown';$retry=60;
            try{
                // Only this exact previously observed GROUP root is a deletion target. No source-channel fallback exists.
                $this->store->assertNoTransaction();
                if($claim['group_chat_id']===$claim['source_chat_id'])throw new LogicException('discussion_source_delete_forbidden');
                $response=($this->call)('deleteMessage',['chat_id'=>$claim['group_chat_id'],'message_id'=>$claim['group_message_id']]);
                if(($response['ok']??null)===true&&($response['result']??null)===true)$outcome='deleted';
                elseif(($response['error_code']??null)===400&&($response['description']??null)==='Bad Request: message to delete not found')$outcome='already_absent';
                elseif(($response['error_code']??null)===429){$outcome='rate_limited';$value=$response['parameters']['retry_after']??null;$retry=is_int($value)&&$value>=1&&$value<=3600?$value:300;}
                elseif(($response['error_code']??null)===403){$outcome='delete_right_required';$retry=300;}
                elseif(($response['error_code']??null)===400){$outcome='delete_rejected';$retry=300;}
            }catch(Throwable){$outcome='unknown';}
            if($this->store->finish($claim,$outcome,$this->now(),$retry)&&in_array($outcome,['deleted','already_absent'],true))$result['deleted']++;
            if($outcome==='rate_limited')break;
        }return $result;
    }
}

/** Runtime hooks; imports never start observation, transport or maintenance. */
trait PostedTelegramCommentsHooksPrototype05
{
    private function telegramCommentsStorePrototype05(): PostedTelegramCommentsStorePrototype05
    {return new PostedTelegramCommentsStorePrototype05($this->pdo,(string)($this->config['comments_prototype_observation_lock']??'gitposted_comments_observe'));}
    private function telegramCommentsObservePrototype05(array $update): bool
    {return $this->telegramCommentsStorePrototype05()->observe($update,time());}
    private function telegramCommentsReservePrototype05(array $post,array $channel,int $primaryParts,int $continuationParts): void
    {
        $post=array_replace($post,['chat_id'=>(string)$channel['chat_id']]);
        $this->telegramCommentsStorePrototype05()->recordReservationLocked($post,$channel,$primaryParts,$continuationParts,$this->telegramSubmissionKey($post));
    }
    private function telegramCommentsKnownFullPrototype05(array $post,array $channel,array $allSlots,bool $continuationComplete,bool $deliveryCertain): void
    {$this->telegramCommentsStorePrototype05()->knownFullLocked($post,$channel,$allSlots,$continuationComplete,$deliveryCertain);}
    private function telegramCommentsMaintenancePrototype05(int $limit=PostedTelegramCommentsPrototype05::MAX_BATCH): array
    {
        if($this->pdo->inTransaction())throw new LogicException('comments_transport_outside_transaction_required');$botId=$this->telegramBotId();if($botId<=0)return ['blocked'=>'bot_identity_unverified'];
        return (new PostedTelegramCommentsProcessorPrototype05($this->telegramCommentsStorePrototype05(),PostedTelegramCommentsProcessorPrototype05::botApiAdapter($this->telegram),$botId,static fn():int=>time()))->run($limit);
    }
}
