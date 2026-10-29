<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}
require_once __DIR__.'/telegram-top-hold.php';

/** TOP account data follows the existing atomic transfer, never an inferred grant. */
final class PostedTelegramTopTransfer
{
    private static function rows(PDO$pdo,string$sql,array$args=[]):array{$s=$pdo->prepare($sql);$s->execute($args);return$s->fetchAll(PDO::FETCH_ASSOC);}
    private static function fail():never{throw new DomainException('Настройки или квота ТОП изменились либо ожидают результата отправки. Перенос ничего не изменил; проверьте данные и подготовьте его заново.');}
    private static function decode(string$raw):array{try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){self::fail();}if(!is_array($v))self::fail();return$v;}

    /** Retired/orphaned source stock remains data even without a live permission row. */
    public static function channelIds(PDO$pdo,int$source):array
    {
        if($source<=0)self::fail();$ids=[];
        $rows=self::rows($pdo,"SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP ? ORDER BY setting_key LIMIT 101",['^tgh_q_[1-9][0-9]*_'.$source.'$']);
        if(count($rows)>100)self::fail();
        foreach($rows as$row){if(preg_match('/\Atgh_q_([1-9][0-9]*)_'.preg_quote((string)$source,'/').'\z/D',$row['setting_key'],$m)!==1||(string)(int)$m[1]!==$m[1])self::fail();$id=(int)$m[1];
            $channel=self::rows($pdo,'SELECT * FROM posted_channels WHERE id=? LIMIT 1',[$id]);if($channel===[])self::fail();PostedTelegramTopHold::quotaRecord($pdo,$channel[0],$source);$ids[]=$id;}
        // An owner has no unit debit. Its unresolved TOP must nevertheless stop a transfer.
        $pending=self::rows($pdo,"SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP '^tgh_p_[1-9][0-9]*$' AND CAST(JSON_EXTRACT(setting_value,'$.quota_user_id') AS UNSIGNED)=? AND JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.state')) IN ('reserved','uncertain') ORDER BY setting_key LIMIT 101",[$source]);
        if(count($pending)>100)self::fail();foreach($pending as$row){$r=PostedTelegramTopHold::validateReceipt(self::decode($row['setting_value']));if($row['setting_key']!==PostedTelegramTopHold::receiptKey($r['post_id'])||$r['quota_user_id']!==$source)self::fail();$ids[]=$r['channel_id'];}
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return$ids;
    }

    public static function scope(PDO$pdo,int$source,int$target,array$channels,array$posts,array$templates=[]):array
    {
        $byChannel=[];foreach($channels as$c)$byChannel[(int)$c['id']]=$c;ksort($byChannel,SORT_NUMERIC);
        $postMap=[];foreach($posts as$post)$postMap[(int)$post['id']]=$post;ksort($postMap,SORT_NUMERIC);
        $quotas=[];$sourceStock=[];$options=[];$templateOptions=[];$raw=[];$holds=[];$receipts=[];
        foreach($byChannel as$id=>$channel){
            $a=PostedTelegramTopHold::quotaRecord($pdo,$channel,$source);$b=PostedTelegramTopHold::quotaRecord($pdo,$channel,$target);
            if($a['remaining']+$b['remaining']>PostedTelegramTopHold::MAX_QUOTA)self::fail();
            $quotas[$id]=['source'=>$a,'target'=>$b];if($a['revision']>0)$sourceStock[]=$id;
            foreach([$source,$target]as$user){$key=PostedTelegramTopHold::quotaKey($id,$user);$r=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1',[$key]);$raw[$key]=$r[0]['setting_value']??null;}
            $key=PostedTelegramTopHold::channelKey($id);$r=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1',[$key]);$raw[$key]=$r[0]['setting_value']??null;
            $hold=PostedTelegramTopHold::hold($pdo,$channel);if($hold!==null){if(in_array($hold['state'],['reserved','uncertain'],true))self::fail();$holds[$id]=$hold;}
        }
        foreach($postMap as$id=>$post){$options[$id]=PostedTelegramTopHold::load($pdo,$post);$key=PostedTelegramTopHold::optionKey($id);$r=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1',[$key]);$raw[$key]=$r[0]['setting_value']??null;
            $receipt=PostedTelegramTopHold::receipt($pdo,$id);if($receipt!==null&&($receipt['channel_id']!==(int)$post['channel_id']||!isset($byChannel[$receipt['channel_id']])))self::fail();}
        foreach($templates as$template){$id=(int)$template['id'];$key='tgh_t_'.$id;$rows=self::rows($pdo,'SELECT setting_value FROM posted_app_settings WHERE setting_key=? LIMIT 1',[$key]);$raw[$key]=$rows[0]['setting_value']??null;if($raw[$key]===null)continue;
            $v=self::decode($raw[$key]);$keys=array_keys($v);sort($keys);$expected=['duration_minutes','enabled','template_id','version'];
            if($keys!==$expected||$v['version']!==1||$v['template_id']!==$id||$v['enabled']!==true||!is_int($v['duration_minutes'])||$v['duration_minutes']<1||$v['duration_minutes']>PostedTelegramTopHold::MAX_DURATION_MINUTES)self::fail();$templateOptions[$id]=$v;}
        // Include every receipt in an affected channel, even an uncharged owner
        // or another author: publication gates are channel-wide.
        $all=self::rows($pdo,"SELECT setting_key,setting_value FROM posted_app_settings WHERE setting_key REGEXP '^tgh_p_[1-9][0-9]*$' ORDER BY setting_key LIMIT 10001");
        if(count($all)>10000)self::fail();foreach($all as$row){$r=PostedTelegramTopHold::validateReceipt(self::decode($row['setting_value']));
            if($row['setting_key']!==PostedTelegramTopHold::receiptKey($r['post_id']))self::fail();if(!isset($byChannel[$r['channel_id']])&&!isset($postMap[$r['post_id']]))continue;
            if(!isset($byChannel[$r['channel_id']])||$r['chat_id']!==(string)$byChannel[$r['channel_id']]['chat_id']||in_array($r['state'],['reserved','uncertain'],true))self::fail();
            $post=self::rows($pdo,'SELECT id,channel_id FROM posted_posts WHERE id=? LIMIT 1',[$r['post_id']]);if($post===[]||(int)$post[0]['channel_id']!==$r['channel_id'])self::fail();
            $receipts[$r['post_id']]=$r;$raw[$row['setting_key']]=$row['setting_value'];
        }
        foreach($holds as$hold){$r=$receipts[$hold['post_id']]??null;if($r===null||$r!==$hold)self::fail();}
        ksort($raw,SORT_STRING);ksort($receipts,SORT_NUMERIC);
        ksort($templateOptions,SORT_NUMERIC);return['quotas'=>$quotas,'source_stock_channels'=>$sourceStock,'options'=>$options,'template_options'=>$templateOptions,'holds'=>$holds,'receipts'=>$receipts,'raw'=>$raw];
    }

    public static function applyLocked(PDO$pdo,array$scope,array$channels,int$source,int$target):void
    {
        if(!$pdo->inTransaction())throw new LogicException('TOP transfer requires the existing account transaction.');
        $byChannel=[];foreach($channels as$c)$byChannel[(int)$c['id']]=$c;
        // The encompassing service has re-read and fingerprinted all raw TOP
        // records after its channel/post/actor locks. Core rechecks quota CAS.
        foreach($scope['source_stock_channels']as$id){if(!isset($byChannel[$id],$scope['quotas'][$id]))self::fail();$q=$scope['quotas'][$id];
            PostedTelegramTopHold::transferQuotasLocked($pdo,$byChannel[$id],$source,$target,$q['source'],$q['target']);}
        // Options have no author field; consumed/active receipts intentionally
        // retain original quota identity and known message/time. Never rewrite.
    }
}
