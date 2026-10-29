<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-multipost.php';
require_once __DIR__.'/telegram-multipost-fork.php';

/** Constructed only after reading the current post's bound authorization from PDO. */
final class PostedTelegramMultipostUpstreamProof
{
    private function __construct(private readonly array $value){}

    public static function fromPost(PDO $pdo,array $post,bool $lock=false): ?self
    {
        if($lock&&!$pdo->inTransaction())throw new LogicException('Upstream certification requires the post transaction.');
        if(PostedTelegramMultipostFork::marked($post)){
            $authorization=PostedTelegramMultipostFork::read($pdo,$post);
            $request=PostedTelegramMultipostFork::request($authorization,$post);
        }else{
            $root=PostedTelegramMultipost::forwardRootPostId($post);if($root===null)return null;
            $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1'.($lock?' FOR UPDATE':''));$query->execute([PostedTelegramMultipost::key($root)]);
            $raw=$query->fetchColumn();$authorization=$raw===false?null:json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($authorization))throw new InvalidArgumentException('Source batch receipt is unavailable.');
            $request=PostedTelegramMultipost::forwardRequest($post,(string)$post['chat_id'],$authorization);
        }
        $value=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],'actor_id'=>(int)$post['author_id'],
            'source_chat_id'=>$request['payload']['from_chat_id'],'source_message_ids'=>$request['payload']['message_ids'],
            'expected_origins'=>$request['expected_origins'],'authorization_hash'=>PostedTelegramMultipost::digest($authorization)];
        $value['proof_hash']=self::hash($value);self::validateSnapshot($value,(int)$post['id'],(int)$post['channel_id']);return new self($value);
    }

    private static function hash(array $value):string
    {unset($value['proof_hash']);return PostedTelegramMultipost::digest($value);}

    public static function validateSnapshot(array $value,int $postId,int $channelId): void
    {
        $keys=array_keys($value);sort($keys);
        if($keys!==['actor_id','authorization_hash','channel_id','expected_origins','post_id','proof_hash','source_chat_id','source_message_ids','version']
            ||($value['version']??null)!==1||($value['post_id']??null)!==$postId||($value['channel_id']??null)!==$channelId
            ||!is_int($value['actor_id']??null)||$value['actor_id']<=0
            ||!is_string($value['authorization_hash']??null)||preg_match('/\A[a-f0-9]{64}\z/D',$value['authorization_hash'])!==1
            ||!is_string($value['proof_hash']??null)||!hash_equals(self::hash($value),$value['proof_hash']))throw new InvalidArgumentException('Certified upstream binding changed.');
        if(!is_string($value['source_chat_id'])||preg_match('/\A-[1-9][0-9]{0,19}\z/D',$value['source_chat_id'])!==1
            ||!is_array($value['source_message_ids'])||!array_is_list($value['source_message_ids'])||count($value['source_message_ids'])<1||count($value['source_message_ids'])>100
            ||!is_array($value['expected_origins'])||!array_is_list($value['expected_origins'])||count($value['expected_origins'])!==count($value['source_message_ids']))throw new InvalidArgumentException('Certified upstream source count changed.');
        $last=0;foreach($value['source_message_ids']as$index=>$id){
            $origin=$value['expected_origins'][$index];$chat=$origin['chat']['id']??null;
            if(!is_int($id)||$id<=$last||$id>2147483647||!is_array($origin)||($origin['type']??null)!=='channel'
                ||!is_string($chat)||preg_match('/\A-[1-9][0-9]{0,19}\z/D',$chat)!==1||!is_int($origin['message_id']??null)||$origin['message_id']<=0||$origin['message_id']>2147483647)throw new InvalidArgumentException('Certified upstream origin changed.');
            $last=$id;
        }
    }

    public function snapshot(array $post,?array $previous=null): array
    {
        if((int)$post['id']!==$this->value['post_id']||(int)$post['channel_id']!==$this->value['channel_id']||(int)$post['author_id']!==$this->value['actor_id'])throw new InvalidArgumentException('Certified upstream belongs to another post.');
        if($previous!==null){
            self::validateSnapshot($previous,(int)$post['id'],(int)$post['channel_id']);
            foreach(['source_chat_id','source_message_ids','expected_origins']as$key)if(PostedTelegramMultipost::digest([$previous[$key]])!==PostedTelegramMultipost::digest([$this->value[$key]]))throw new InvalidArgumentException('Certified upstream source changed after reservation.');
            return$previous;
        }
        return$this->value;
    }
}
