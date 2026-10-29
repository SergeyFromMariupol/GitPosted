<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Pure presentation only. Current actor/post rights and mutations stay in the caller. */
final class PostedTelegramButtonUi
{
    private const URL_PROMPT = '⛓ КНОПКИ: URL-кнопки

Отправьте боту список URL-кнопок в следующем формате:

Кнопка 1 - http://t.me
Кнопка 2 - http://t.me

Чтобы поставить кнопки в один ряд, используйте разделитель « | »:

Кнопка 1 - http://t.me | Кнопка 2 - http://t.me

Чтобы установить цвет кнопке, добавьте «!r», «!g» или «!b» перед кнопкой:

!r Красный - https://t.me | !g Зелёный - https://t.me';
    private const URL_ENTITIES = [['type'=>'custom_emoji','offset'=>0,'length'=>1,'custom_emoji_id'=>'5260730055880876557'],['type'=>'bold','offset'=>2,'length'=>18],['type'=>'blockquote','offset'=>77,'length'=>45],['type'=>'bold','offset'=>77,'length'=>11],['type'=>'url','offset'=>88,'length'=>11],['type'=>'bold','offset'=>88,'length'=>11],['type'=>'bold','offset'=>99,'length'=>12],['type'=>'url','offset'=>111,'length'=>11],['type'=>'bold','offset'=>111,'length'=>11],['type'=>'code','offset'=>184,'length'=>3],['type'=>'blockquote','offset'=>191,'length'=>47],['type'=>'bold','offset'=>191,'length'=>11],['type'=>'url','offset'=>202,'length'=>11],['type'=>'bold','offset'=>202,'length'=>11],['type'=>'bold','offset'=>213,'length'=>14],['type'=>'url','offset'=>227,'length'=>11],['type'=>'bold','offset'=>227,'length'=>11],['type'=>'code','offset'=>280,'length'=>2],['type'=>'code','offset'=>286,'length'=>2],['type'=>'code','offset'=>295,'length'=>2],['type'=>'blockquote','offset'=>315,'length'=>53],['type'=>'bold','offset'=>315,'length'=>13],['type'=>'url','offset'=>328,'length'=>12],['type'=>'bold','offset'=>328,'length'=>12],['type'=>'bold','offset'=>340,'length'=>16],['type'=>'url','offset'=>356,'length'=>12],['type'=>'bold','offset'=>356,'length'=>12]];
    private const INVALID_TEXT = '❌ Кнопки некорректные.';
    private const INVALID_ENTITIES = [['type'=>'custom_emoji','offset'=>0,'length'=>1,'custom_emoji_id'=>'5260342697075416641']];
    public static function urlPrompt():array{return['text'=>self::URL_PROMPT,'entities'=>self::URL_ENTITIES];}
    public static function invalidUrl():array{return['text'=>self::INVALID_TEXT,'entities'=>self::INVALID_ENTITIES];}

    /** Replace one button family without discarding other families or their style/hidden metadata. */
    public static function replaceTypeRows(array $current,array $replacement,string $type):array
    {
        if(!in_array($type,['url','reaction','hidden'],true))throw new InvalidArgumentException('Invalid button family.');
        $kind=static function(array $button):string{if(isset($button['url']))return'url';$callback=(string)($button['callback_data']??'');return str_starts_with($callback,'reaction:')?'reaction':(str_starts_with($callback,'hidden:')?'hidden':'other');};
        $keep=[];foreach($current as$row){if(!is_array($row))throw new InvalidArgumentException('Invalid stored button row.');$next=[];foreach($row as$button){if(!is_array($button))throw new InvalidArgumentException('Invalid stored button.');if($kind($button)!==$type)$next[]=$button;}if($next!==[])$keep[]=$next;}
        $rows=[];foreach($replacement as$row){if(!is_array($row)||$row===[])throw new InvalidArgumentException('Invalid replacement button row.');foreach($row as$button)if(!is_array($button)||$kind($button)!==$type)throw new InvalidArgumentException('Invalid replacement button family.');$rows[]=array_values($row);}
        return self::orderRows(array_merge($keep,$rows));
    }
    /** Source family order. Equal-family rows and every original row field remain stable. */
    public static function orderRows(array $rows):array
    {
        $ranked=[];foreach($rows as$i=>$row){if(!is_array($row))throw new InvalidArgumentException('Invalid stored button row.');$rank=3;foreach($row as$button){if(!is_array($button))throw new InvalidArgumentException('Invalid stored button.');$callback=(string)($button['callback_data']??'');$kind=isset($button['url'])?1:(str_starts_with($callback,'hidden:')?0:(str_starts_with($callback,'reaction:')?2:3));$rank=min($rank,$kind);}if($row!==[])$ranked[]=[$rank,count($ranked),$row];}
        usort($ranked,static fn(array$a,array$b):int=>($a[0]<=>$b[0])?:($a[1]<=>$b[1]));return array_column($ranked,2);
    }
    public static function typeKeyboard(int $postId,string $scope,string $selected='url',bool $canClear=false):array
    {
        if($postId<1||!in_array($scope,['draft','edit'],true)||!in_array($selected,['url','reactions','hidden'],true))throw new InvalidArgumentException('Invalid button menu identity.');
        $action=['url'=>'buttons','reactions'=>'reactions','hidden'=>'hidden'];
        $button=static function(string$key,string$label)use($scope,$postId,$selected,$action):array{$v=['text'=>$label,'callback_data'=>$scope.':'.$action[$key].':'.$postId];if($selected===$key)$v['style']='primary';return$v;};
        $rows=[[$button('url','URL-кнопки'),$button('reactions','Реакции')],[$button('hidden','Скрытое продолжение')]];
        if($canClear)$rows[]=[['text'=>'🗑️ Удалить кнопки','callback_data'=>$scope.':buttons_clear:'.$postId]];
        $rows[]=[['text'=>'← Назад','callback_data'=>$scope.':'.($scope==='draft'?'cancel_timer':'editor_back').':'.$postId]];
        foreach($rows as$row)foreach($row as$b)if(strlen($b['callback_data'])>64)throw new InvalidArgumentException('Button menu callback is too long.');
        return$rows;
    }
}
