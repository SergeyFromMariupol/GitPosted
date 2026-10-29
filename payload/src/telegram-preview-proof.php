<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Proof for a private preview only. It never authorizes publication or a source import. */
final class PostedTelegramPreviewProof
{
    public static function expectedCount(array $plan,bool $withFinalControls):int
    {
        if(($plan['mode']??null)==='native'){if(($plan['media']??null)!==[]||($plan['text_parts']??null)!==[]||!is_array($plan['native_descriptor']??null)||!is_string($plan['native_notice']??null)||PostedTelegramNativeContent::request($plan['native_descriptor'])!==($plan['native_request']??null))throw new InvalidArgumentException('Invalid native preview plan.');return 1+($plan['native_notice']!==''?1:0);}
        $mode=$plan['mode']??null;$media=$plan['media']??null;$parts=$plan['text_parts']??null;
        if(!in_array($mode,['none','group','single','sequence','paid'],true)||!is_array($media)||!array_is_list($media)||count($media)>10||!is_array($parts)||!array_is_list($parts)||count($parts)>100)throw new InvalidArgumentException('Invalid preview plan.');
        $primary=$mode==='paid'?1:(in_array($mode,['group','single','sequence'],true)?count($media):0);
        $count=$primary+count($parts);
        if($withFinalControls&&$mode!=='group'&&$count===0)$count++;
        if($count<1||$count>110)throw new InvalidArgumentException('Invalid preview count.');return$count;
    }
    public static function requireKnownIds(array $ids,int $expected):array
    {
        if(!array_is_list($ids)||count($ids)!==$expected)throw new RuntimeException('Preview result is incomplete; the old preview is retained.');$seen=[];
        foreach($ids as$id){if(!is_int($id)||$id<1||isset($seen[$id]))throw new RuntimeException('Preview result has unknown or duplicate IDs; the old preview is retained.');$seen[$id]=true;}
        return$ids;
    }
}
