<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-multipost.php';
require_once __DIR__.'/telegram-multipost-fork.php';
require_once __DIR__.'/telegram-multipost-proof.php';

/** Account transfer includes durable plans, their dependencies and minted receipts. */
final class PostedTelegramMultipostTransfer
{
    public static function scope(PDO $pdo,int $source,int $target): array
    {
        $query=$pdo->prepare("SELECT setting_key,setting_value FROM posted_app_settings
            WHERE setting_key REGEXP '^telegram_multipost_[1-9][0-9]*$'
            AND CAST(JSON_EXTRACT(setting_value,'$.binding.actor_id') AS UNSIGNED) IN (?,?) ORDER BY setting_key");
        $query->execute([$source,$target]);$plans=[];$posts=[];$channels=[];$parents=[];
        foreach($query->fetchAll() as $row){
            $plan=json_decode((string)$row['setting_value'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($plan))throw new InvalidArgumentException('План мультипубликации повреждён.');
            PostedTelegramMultipost::validate($plan);
            $id=$plan['binding']['post_id'];
            if($row['setting_key']!==PostedTelegramMultipost::key($id)||!in_array($plan['binding']['actor_id'],[$source,$target],true))throw new InvalidArgumentException('Привязка мультипубликации изменилась.');
            $plans[$id]=$plan;$channels[]=$plan['binding']['channel_id'];
            foreach($plan['targets'] as $entry)$channels[]=$entry['channel_id'];
            foreach([$id,...array_column($plan['children'],'post_id')] as $postId){
                $find=$pdo->prepare('SELECT * FROM posted_posts WHERE id=? LIMIT 1');$find->execute([$postId]);$post=$find->fetch();
                if(!is_array($post)||(int)$post['author_id']!==$plan['binding']['actor_id'])throw new InvalidArgumentException('Владелец дочерней публикации изменился.');
                $posts[$postId]=$post;$channels[]=(int)$post['channel_id'];
            }
            foreach($plan['children']as$child){
                $find=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1');$find->execute(['telegram_multipost_parent_'.$child['post_id']]);$raw=$find->fetchColumn();
                $parent=$raw===false?null:json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR);
                if(!is_array($parent)||($parent['version']??null)!==1||($parent['post_id']??null)!==$child['post_id']||($parent['channel_id']??null)!==$child['channel_id']||($parent['root_post_id']??null)!==$id
                    ||(isset($parent['mode_override'])&&$parent['mode_override']!=='copy'))throw new InvalidArgumentException('Multipost child binding changed.');
                $parents[$child['post_id']]=$parent;
            }
        }
        $query=$pdo->prepare("SELECT setting_key,setting_value FROM posted_app_settings
            WHERE setting_key REGEXP '^telegram_multipost_fork_[1-9][0-9]*$'
            AND CAST(JSON_EXTRACT(setting_value,'$.actor_id') AS UNSIGNED) IN (?,?) ORDER BY setting_key");
        $query->execute([$source,$target]);$forks=[];
        foreach($query->fetchAll() as $row){
            $receipt=json_decode((string)$row['setting_value'],true,64,JSON_THROW_ON_ERROR);if(!is_array($receipt))throw new InvalidArgumentException('Batch authorization is invalid.');
            PostedTelegramMultipostFork::validate($receipt);
            $id=$receipt['post_id'];if($row['setting_key']!==PostedTelegramMultipostFork::key($id))throw new InvalidArgumentException('Batch identity changed.');
            $find=$pdo->prepare('SELECT post.*,channel.chat_id FROM posted_posts post INNER JOIN posted_channels channel ON channel.id=post.channel_id WHERE post.id=? LIMIT 1');$find->execute([$id]);$post=$find->fetch();
            if(!is_array($post))throw new InvalidArgumentException('Batch post is unavailable.');PostedTelegramMultipostFork::read($pdo,$post);
            $forks[$id]=$receipt;$posts[$id]=$post;$channels[]=$receipt['channel_id'];
        }
        ksort($plans,SORT_NUMERIC);ksort($forks,SORT_NUMERIC);ksort($posts,SORT_NUMERIC);$channels=array_values(array_unique($channels));sort($channels,SORT_NUMERIC);
        ksort($parents,SORT_NUMERIC);
        return ['plans'=>$plans,'forks'=>$forks,'parents'=>$parents,'posts'=>array_values($posts),'channel_ids'=>$channels];
    }

    public static function applyLocked(PDO $pdo,array $scope,int $source,int $target,array $legacyCategories): void
    {
        if(!$pdo->inTransaction())throw new LogicException('Multipost transfer requires the account transaction.');
        foreach($scope['plans'] as $old){
            $actor=$old['binding']['actor_id'];$newActor=$actor===$source?$target:$actor;
            $plan=PostedTelegramMultipost::rebindActor($old,$newActor,$legacyCategories[$actor]??[]);
            $update=$pdo->prepare('UPDATE posted_app_settings SET setting_value=? WHERE setting_key=? AND setting_value <=> CAST(? AS JSON)');
            $update->execute([PostedTelegramMultipost::encode($plan),PostedTelegramMultipost::key($plan['binding']['post_id']),PostedTelegramMultipost::encode($old)]);
            if($update->rowCount()===0){
                $find=$pdo->prepare('SELECT setting_value FROM posted_app_settings WHERE setting_key=?');$find->execute([PostedTelegramMultipost::key($plan['binding']['post_id'])]);
                $actual=json_decode((string)$find->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
                if(!is_array($actual)||!hash_equals(PostedTelegramMultipost::digest($actual),PostedTelegramMultipost::digest($plan)))throw new RuntimeException('План изменился во время переноса.');
            }
            foreach($plan['children'] as $child){
                if($child['mode']!=='forward')continue;
                $find=$pdo->prepare('SELECT import_json FROM posted_posts WHERE id=? LIMIT 1');$find->execute([$child['post_id']]);
                $rawImport=$find->fetchColumn();$import=$rawImport===null||$rawImport===false?[]:json_decode((string)$rawImport,true,512,JSON_THROW_ON_ERROR);$forward=$import[PostedTelegramMultipost::FORWARD_KEY]??null;
                if(($scope['parents'][$child['post_id']]['mode_override']??null)==='copy'){
                    if(is_array($forward))throw new InvalidArgumentException('Explicit child copy still contains a forward descriptor.');
                    continue;
                }
                if(!is_array($forward)||($forward['root_post_id']??null)!==$old['binding']['post_id']
                    ||($forward['root_nonce']??null)!==$old['nonce']||($forward['selection_digest']??null)!==$old['selection_digest']
                    ||($forward['child_post_id']??null)!==$child['post_id'])throw new InvalidArgumentException('Журнал дочерней пересылки изменился.');
                $import[PostedTelegramMultipost::FORWARD_KEY]['selection_digest']=$plan['selection_digest'];
                $pdo->prepare('UPDATE posted_posts SET import_json=? WHERE id=?')->execute([PostedTelegramMultipost::encode($import),$child['post_id']]);
            }
        }
        foreach($scope['forks'] as $receipt){
            if($receipt['actor_id']!==$source)continue;
            $receipt['actor_id']=$target;PostedTelegramMultipostFork::validate($receipt);
            $pdo->prepare('UPDATE posted_app_settings SET setting_value=? WHERE setting_key=?')->execute([
                PostedTelegramMultipost::encode($receipt),PostedTelegramMultipostFork::key($receipt['post_id']),
            ]);
        }
    }
}
