<?php
declare(strict_types=1);

/** A duplicated batch has its own authorization; it never borrows another child's ID. */
final class PostedTelegramMultipostFork
{
    public const MARKER='gitposted_multipost_forward_fork';
    public static function key(int $postId):string {if($postId<=0)throw new InvalidArgumentException('Invalid batch post.');return'telegram_multipost_fork_'.$postId;}
    public static function marked(array $post):bool {$import=json_decode((string)($post['import_json']??''),true);return is_array($import)&&array_key_exists(self::MARKER,$import);}
    public static function create(array $post,array $channel,int $actor,array $request):array
    {
        $receipt=['version'=>1,'post_id'=>(int)$post['id'],'channel_id'=>(int)$post['channel_id'],
            'chat_id'=>(string)$channel['chat_id'],'actor_id'=>$actor,'nonce'=>bin2hex(random_bytes(12)),
            'source_chat_id'=>$request['payload']['from_chat_id'],'source_message_ids'=>$request['payload']['message_ids'],
            'expected_origins'=>$request['expected_origins']];
        $receipt['proof_hash']=self::proofHash($receipt);self::validate($receipt);return$receipt;
    }
    private static function proofHash(array $value):string
    {return PostedTelegramMultipost::digest(array_intersect_key($value,array_flip(['source_chat_id','source_message_ids','expected_origins'])));}
    public static function validate(array $value):void
    {
        $keys=array_keys($value);sort($keys);
        if($keys!==['actor_id','channel_id','chat_id','expected_origins','nonce','post_id','proof_hash','source_chat_id','source_message_ids','version']
            ||($value['version']??null)!==1)throw new InvalidArgumentException('Batch authorization is invalid.');
        foreach(['post_id','channel_id','actor_id']as$key)if(!is_int($value[$key])||$value[$key]<=0)throw new InvalidArgumentException('Batch identity changed.');
        foreach(['chat_id','source_chat_id']as$key)if(!is_string($value[$key])||preg_match('/\A-[1-9][0-9]{0,19}\z/D',$value[$key])!==1)throw new InvalidArgumentException('Batch channel changed.');
        if($value['chat_id']===$value['source_chat_id']||!is_string($value['nonce'])||preg_match('/\A[a-f0-9]{24}\z/D',$value['nonce'])!==1
            ||!is_string($value['proof_hash'])||!hash_equals(self::proofHash($value),$value['proof_hash']))throw new InvalidArgumentException('Batch source proof changed.');
        if(!is_array($value['source_message_ids'])||!array_is_list($value['source_message_ids'])||count($value['source_message_ids'])<1||count($value['source_message_ids'])>100
            ||!is_array($value['expected_origins'])||!array_is_list($value['expected_origins'])||count($value['expected_origins'])!==count($value['source_message_ids']))throw new InvalidArgumentException('Batch source count changed.');
        $last=0;foreach($value['source_message_ids']as$index=>$id){
            if(!is_int($id)||$id<=$last||$id>2147483647)throw new InvalidArgumentException('Batch source IDs changed.');$last=$id;
            $origin=$value['expected_origins'][$index];$chat=$origin['chat']['id']??null;
            if(!is_array($origin)||($origin['type']??null)!=='channel'||!is_string($chat)||preg_match('/\A-[1-9][0-9]{0,19}\z/D',$chat)!==1
                ||!is_int($origin['message_id']??null)||$origin['message_id']<=0||$origin['message_id']>2147483647)throw new InvalidArgumentException('Batch origin changed.');
        }
    }
    public static function read(PDO $pdo,array $post):?array
    {
        if(!self::marked($post))return null;
        $query=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$query->execute([self::key((int)$post['id'])]);$raw=$query->fetchColumn();
        $receipt=$raw===false?null:json_decode((string)$raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($receipt))throw new InvalidArgumentException('Batch authorization is unavailable.');self::validate($receipt);
        $import=json_decode((string)$post['import_json'],true,64,JSON_THROW_ON_ERROR);$marker=$import[self::MARKER];
        if(!is_array($marker)||count($marker)!==3||($marker['version']??null)!==1||($marker['post_id']??null)!==$receipt['post_id']||($marker['nonce']??null)!==$receipt['nonce']
            ||(int)$post['id']!==$receipt['post_id']||(int)$post['channel_id']!==$receipt['channel_id']||(int)$post['author_id']!==$receipt['actor_id']
            ||(string)$post['chat_id']!==$receipt['chat_id']||($post['repost_chat_id']??null)!==$receipt['source_chat_id']
            ||(int)($post['repost_message_id']??0)!==$receipt['source_message_ids'][0])throw new InvalidArgumentException('Batch authorization binding changed.');
        return$receipt;
    }
    public static function request(array $receipt,array $post):array
    {
        self::validate($receipt);$payload=['chat_id'=>$receipt['chat_id'],'from_chat_id'=>$receipt['source_chat_id'],
            'message_ids'=>$receipt['source_message_ids'],'disable_notification'=>!empty($post['silent'])];
        if(($post['_telegram_post_options']['protect_content']??false)===true)$payload['protect_content']=true;
        return ['method'=>'forwardMessages','payload'=>$payload,'expected_origins'=>$receipt['expected_origins'],'expected_count'=>count($receipt['source_message_ids'])];
    }
}
