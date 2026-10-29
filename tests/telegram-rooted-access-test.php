<?php
declare(strict_types=1);
require_once __DIR__.'/../payload/src/telegram-policy.php';
$assert=static function(bool $value,string $why):void{if(!$value)throw new RuntimeException($why);};
$fixture=static function():array{
    $users=array_map(static fn(int $id):array=>['telegram_id'=>$id,'is_active'=>1],[1,2,3,4,5,6]);
    $channels=[['id'=>11,'added_by'=>1,'is_active'=>1,'is_archived'=>0,'admin_blocked'=>0],['id'=>22,'added_by'=>2,'is_active'=>1,'is_archived'=>0,'admin_blocked'=>0],['id'=>33,'added_by'=>4,'is_active'=>1,'is_archived'=>0,'admin_blocked'=>0],['id'=>44,'added_by'=>5,'is_active'=>1,'is_archived'=>0,'admin_blocked'=>0]];
    $permissions=[['channel_id'=>11,'user_id'=>2,'can_post'=>1],['channel_id'=>22,'user_id'=>3,'can_schedule'=>1],['channel_id'=>33,'user_id'=>5,'can_manage_posts'=>1],['channel_id'=>44,'user_id'=>4,'is_ad_manager'=>1]];
    return[$users,$channels,$permissions];
};
$control=static fn(int $id,bool $allow):array=>['version'=>1,'user_id'=>$id,'can_add_channels'=>$allow,'revision'=>1];
$tests=[];
$tests['Rooted admission is opt-in and preserves legacy policy shapes']=static function()use($assert):void{
    $assert(PostedTelegramPolicy::normalize(PostedTelegramPolicy::defaults())['access_mode']==='open','default changed');
    $p=PostedTelegramPolicy::fromAdmin(['telegram_pricing_mode'=>'paid','telegram_access_mode'=>'rooted','telegram_allowed_ids'=>'2, 1 2']);
    $assert($p['allowed_telegram_ids']===['1','2']&&$p['pricing_mode']==='paid','root ids/pricing lost');
    $assert(!PostedTelegramPolicy::operatorAllowed(['telegram_policy'=>$p],1),'rooted cannot infer graph without PDO');
};
$tests['Rooted assigned editor admitted but own channels initially excluded']=static function()use($fixture,$assert):void{[$u,$c,$p]=$fixture();$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[]);$assert(isset($g['users'][1],$g['users'][2],$g['channels'][11])&&!isset($g['users'][3],$g['channels'][22]),'own-channel default bypass');};
$tests['Rooted explicit employee toggle admits child channel and downstream employee']=static function()use($fixture,$control,$assert):void{[$u,$c,$p]=$fixture();$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[2=>$control(2,true)]);$assert(isset($g['users'][3],$g['channels'][22]),'descendant not admitted');};
$tests['Rooted disconnected cycles cannot self-admit even with own toggles']=static function()use($fixture,$control,$assert):void{[$u,$c,$p]=$fixture();$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[4=>$control(4,true),5=>$control(5,true)]);$assert(!isset($g['users'][4])&&!isset($g['users'][5])&&!isset($g['channels'][33])&&!isset($g['channels'][44]),'cycle admitted');};
$tests['Rooted edge revocation removes descendants without materialized inherited grants']=static function()use($fixture,$control,$assert):void{[$u,$c,$p]=$fixture();array_shift($p);$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[2=>$control(2,true)]);$assert(!isset($g['users'][2])&&!isset($g['users'][3])&&!isset($g['channels'][22]),'revoked edge cached');};
$tests['Rooted own toggle revocation keeps assigned access and closes descendant chain']=static function()use($fixture,$control,$assert):void{[$u,$c,$p]=$fixture();$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[2=>$control(2,false)]);$assert(isset($g['users'][2])&&!isset($g['users'][3])&&!isset($g['channels'][22]),'toggle revocation failed');};
$tests['Rooted inactive root and inactive employee cannot seed channels']=static function()use($fixture,$control,$assert):void{[$u,$c,$p]=$fixture();$u[0]['is_active']=0;$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[2=>$control(2,true)]);$assert($g['users']===[]&&$g['channels']===[],'inactive root admitted');$u[0]['is_active']=1;$u[1]['is_active']=0;$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[2=>$control(2,true)]);$assert(!isset($g['users'][2])&&!isset($g['channels'][22]),'inactive employee admitted');};
$tests['Rooted inactive archived or admin-blocked channel cannot grant admission']=static function()use($fixture,$assert):void{foreach(['is_active'=>0,'is_archived'=>1,'admin_blocked'=>1]as$key=>$value){[$u,$c,$p]=$fixture();$c[0][$key]=$value;$g=PostedTelegramRootedAccess::resolve([1],$u,$c,$p,[]);$assert(!isset($g['users'][2])&&!isset($g['channels'][11]),'closed channel admitted');}};
$tests['Rooted roles include manager and moderator but exclude zero permissions']=static function()use($fixture,$assert):void{[$u,$c]=$fixture();foreach(['can_manage_posts','can_manage_admins','is_ad_manager']as$flag){$g=PostedTelegramRootedAccess::resolve([1],$u,$c,[['channel_id'=>11,'user_id'=>2,$flag=>1]],[]);$assert(isset($g['users'][2]),'role not admitted');}$g=PostedTelegramRootedAccess::resolve([1],$u,$c,[['channel_id'=>11,'user_id'=>2,'can_post'=>0]],[]);$assert(!isset($g['users'][2]),'zero role admitted');};
$tests['Rooted absent root may start but cannot use channels before active user creation']=static function()use($fixture,$assert):void{[$u,$c,$p]=$fixture();$g=PostedTelegramRootedAccess::resolve([9],$u,$c,$p,[]);$assert(isset($g['users'][9])&&$g['channels']===[],'initial root bootstrap failed');};
$tests['Rooted control rejects corrupt future or mismatched actor and preserves exact default']=static function()use($control,$assert):void{$assert(PostedTelegramRootedAccess::control(null,2)['can_add_channels']===false,'default true');foreach([array_merge($control(2,true),['future'=>1]),$control(3,true),array_merge($control(2,true),['can_add_channels'=>1]),array_merge($control(2,true),['revision'=>-1])]as$bad){try{PostedTelegramRootedAccess::control($bad,2);throw new RuntimeException('accepted corrupt control');}catch(InvalidArgumentException){}}};
$passed=0;foreach($tests as$name=>$test){$test();$passed++;}
echo json_encode(['ok'=>true,'passed'=>$passed,'network'=>false,'database'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
