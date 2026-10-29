<?php
declare(strict_types=1);
require_once __DIR__.'/telegram-top-calendar-view.php';

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Presentation helpers only. Reservation, quota charging and conflict decisions belong to the core. */
final class PostedTelegramTopView
{
    public const MAX_MINUTES=525600;
    public const REASONS=['','post_locked','quota_exhausted','actor_denied','invalid_top','schedule_conflict','delivery_unknown','hold_active','stale_revision'];

    public static function id(mixed $value,bool $zero=false):int
    {
        if(!is_int($value)&&!is_string($value))throw new InvalidArgumentException('Invalid identifier.');$raw=(string)$value;
        if(preg_match($zero?'/\A(?:0|[1-9][0-9]{0,18})\z/D':'/\A[1-9][0-9]{0,18}\z/D',$raw)!==1||(string)(int)$raw!==$raw||(int)$raw<($zero?0:1))throw new InvalidArgumentException('Invalid identifier.');
        return(int)$raw;
    }
    public static function day(string $day):string
    {
        if(preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D',$day)!==1)throw new InvalidArgumentException('Invalid graph date.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$day)throw new InvalidArgumentException('Invalid graph date.');return$day;
    }
    public static function month(string $month):string
    {if(preg_match('/\A[1-9][0-9]{3}-[0-9]{2}\z/D',$month)!==1)throw new InvalidArgumentException('Invalid graph month.');self::day($month.'-01');return$month;}
    public static function scope(string $scope):string
    {if(!in_array($scope,['d','m'],true))throw new InvalidArgumentException('Invalid post UI scope.');return$scope;}
    public static function parse(string $raw):array
    {
        if(strlen($raw)>64)throw new InvalidArgumentException('Invalid top callback.');
        if(preg_match('/\Atop:(post|return|more):([0-9]+):(d|m)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'scope'=>$m[3]];
        if(preg_match('/\Atop:(set):([0-9]+):([01]):([0-9]+):(d|m)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'enabled'=>$m[3]==='1','revision'=>self::id($m[4],true),'scope'=>$m[5]];
        if(preg_match('/\Atop:(slot|pick):([0-9]+):([0-9]+):([a-f0-9]{8})\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'at'=>PostedTelegramTopCalendarView::epoch($m[3]),'zone'=>$m[4]];
        if(preg_match('/\Atop:mark:([0-9]+):([0-9]+):([a-f0-9]{8}):([0-9]+)\z/D',$raw,$m)===1)return['action'=>'mark','id'=>self::id($m[1]),'at'=>PostedTelegramTopCalendarView::epoch($m[2]),'zone'=>$m[3],'revision'=>self::id($m[4],true)];
        if(preg_match('/\Atop:book:([0-9]+):([0-9]+):([a-f0-9]{8}):([a-f0-9]{12})\z/D',$raw,$m)===1)return['action'=>'book','id'=>self::id($m[1]),'at'=>PostedTelegramTopCalendarView::epoch($m[2]),'zone'=>$m[3],'token'=>$m[4]];
        if(preg_match('/\Atop:(advertiser|custom):([0-9]+):([0-9]{4}-[0-9]{2}-[0-9]{2})\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'day'=>self::day($m[3])];
        if(preg_match('/\Atop:(graph):([0-9]+):([0-9-]+)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'day'=>self::day($m[3])];
        if(preg_match('/\Atop:(month):([0-9]+):([0-9-]+)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'month'=>self::month($m[3])];
        if($raw==='top:exit:settings')return['action'=>'exit'];
        throw new InvalidArgumentException('Invalid top callback.');
    }
    public static function keys(array $row,array $expected):void
    {$keys=array_keys($row);sort($keys);sort($expected);if($keys!==$expected)throw new InvalidArgumentException('Invalid top view data.');}
    public static function url(mixed $url):?string
    {
        if($url===null)return null;
        if(!is_string($url)||strlen($url)>256||preg_match('~\Ahttps://t\.me/(?:c/[1-9][0-9]{0,18}|[A-Za-z0-9_]{1,64})/[1-9][0-9]{0,18}(?:\?single)?\z~D',$url)!==1)throw new InvalidArgumentException('Invalid publication link.');
        return$url;
    }
    public static function hold(mixed $row):?array
    {
        if($row===null)return null;if(!is_array($row))throw new InvalidArgumentException('Invalid hold view.');
        self::keys($row,['post_id','post_url','until','uncertain']);
        if(!is_int($row['post_id'])||!is_bool($row['uncertain']))throw new InvalidArgumentException('Invalid hold state.');self::id($row['post_id']);$row['post_url']=self::url($row['post_url']);
        if($row['until']!==null&&(!is_int($row['until'])||$row['until']<=0||$row['until']>253402300799))throw new InvalidArgumentException('Invalid hold time.');
        return$row;
    }
    public static function options(array $row):array
    {
        self::keys($row,['enabled','duration_minutes','revision','quota_remaining','unlimited','can_enable','can_disable','reason','hold']);
        if(!is_bool($row['enabled'])||!is_int($row['duration_minutes'])||$row['duration_minutes']<0||$row['duration_minutes']>self::MAX_MINUTES||($row['enabled']&&$row['duration_minutes']===0)
            ||!is_int($row['revision'])||$row['revision']<0||$row['revision']>2147483647||!is_bool($row['unlimited'])||!is_bool($row['can_enable'])||!is_bool($row['can_disable'])
            ||($row['quota_remaining']!==null&&(!is_int($row['quota_remaining'])||$row['quota_remaining']<0||$row['quota_remaining']>1000000000))
            ||!is_string($row['reason'])||!in_array($row['reason'],self::REASONS,true)||$row['unlimited']!==($row['quota_remaining']===null))throw new InvalidArgumentException('Invalid top options.');
        $row['hold']=self::hold($row['hold']);return$row;
    }
    public static function text(bool $en,string $ru,string $english):string{return$en?$english:$ru;}
    public static function escape(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function short(string $value,int $limit=50):string
    {$value=trim(preg_replace('/[\p{C}\s]+/u',' ',$value)??'');return mb_strlen($value,'UTF-8')>$limit?mb_substr($value,0,$limit-1,'UTF-8').'…':($value===''?'—':$value);}
    public static function minutes(int $minutes,bool $en):string
    {
        if($minutes<0||$minutes>self::MAX_MINUTES)throw new InvalidArgumentException('Invalid duration.');
        if($minutes>0&&$minutes%1440===0)return(int)($minutes/1440).self::text($en,' д',' d');
        if($minutes>0&&$minutes%60===0)return(int)($minutes/60).self::text($en,' ч',' h');return$minutes.self::text($en,' мин',' min');
    }
    public static function moment(int $timestamp,DateTimeZone $timezone,string $format='d.m.Y H:i'):string
    {return(new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format($format);}
    public static function interval(int $begin,int $end,DateTimeZone $timezone):string
    {
        if($end<=$begin)throw new InvalidArgumentException('Invalid interval.');
        $a=(new DateTimeImmutable('@'.$begin))->setTimezone($timezone);$b=(new DateTimeImmutable('@'.$end))->setTimezone($timezone);
        $format=($a->format('s')!=='00'||$b->format('s')!=='00')?'H:i:s':'H:i';
        return$a->format($format).'–'.$b->format($a->format('Y-m-d')===$b->format('Y-m-d')?$format:'d.m.Y '.$format);
    }
    public static function graph(array $row,int $channel,string $day):array
    {
        self::keys($row,['channel_id','channel_title','day','today','timezone','day_start','day_end','availability_known','intervals','points','free_intervals','truncated','duration_minutes']);
        if($row['channel_id']!==$channel||$row['day']!==self::day($day)||!is_string($row['today'])||!is_string($row['channel_title'])||mb_strlen($row['channel_title'],'UTF-8')>1000
            ||!is_string($row['timezone'])||strlen($row['timezone'])>64||!is_bool($row['availability_known'])||!is_bool($row['truncated'])
            ||!is_int($row['duration_minutes'])||$row['duration_minutes']<1||$row['duration_minutes']>self::MAX_MINUTES)throw new InvalidArgumentException('Invalid placement graph.');
        self::day($row['today']);$zone=new DateTimeZone($row['timezone']);$start=new DateTimeImmutable($day.' 00:00:00',$zone);$end=$start->modify('+1 day');
        if($start->format('Y-m-d')!==$day||$row['day_start']!==$start->getTimestamp()||$row['day_end']!==$end->getTimestamp())throw new InvalidArgumentException('Invalid graph timezone bounds.');
        foreach(['intervals','points','free_intervals']as$key)if(!is_array($row[$key])||!array_is_list($row[$key])||count($row[$key])>200)throw new InvalidArgumentException('Unbounded graph data.');
        foreach($row['intervals']as&$item){
            if(!is_array($item))throw new InvalidArgumentException('Invalid busy interval.');self::keys($item,['begin','end','kind','label','post_url']);
            if(!is_int($item['begin'])||!is_int($item['end'])||$item['begin']>=$item['end']||$item['begin']< -30610224000||$item['end']>253402300799
                ||!in_array($item['kind'],['active_top','scheduled_top','uncertain'],true)||!is_string($item['label'])||mb_strlen($item['label'],'UTF-8')>120)throw new InvalidArgumentException('Invalid busy interval.');
            $item['post_url']=self::url($item['post_url']);
        }unset($item);
        foreach($row['points']as&$item){
            if(!is_array($item))throw new InvalidArgumentException('Invalid placement point.');self::keys($item,['at','label','post_url']);
            if(!is_int($item['at'])||$item['at']< -30610224000||$item['at']>253402300799||!is_string($item['label'])||mb_strlen($item['label'],'UTF-8')>120)throw new InvalidArgumentException('Invalid placement point.');
            $item['post_url']=self::url($item['post_url']);
        }unset($item);
        if((!$row['availability_known']||$row['truncated'])&&$row['free_intervals']!==[])throw new InvalidArgumentException('Unconfirmed free windows.');
        $seconds=$row['duration_minutes']*60;$previous=null;
        foreach($row['free_intervals']as$item){
            if(!is_array($item))throw new InvalidArgumentException('Invalid free start window.');self::keys($item,['begin','end']);
            if(!is_int($item['begin'])||!is_int($item['end'])||$item['begin']>=$item['end']||$item['begin']<$row['day_start']||$item['end']>$row['day_end']
                ||($previous!==null&&$item['begin']<$previous))throw new InvalidArgumentException('Invalid free start window.');
            $previous=$item['end'];
            // Free windows describe integer-second START times for a new TOP, not a generic empty gap.
            foreach($row['intervals']as$busy)if(max($item['begin'],$busy['begin']-$seconds+1)<min($item['end'],$busy['end']))throw new InvalidArgumentException('Free window conflicts with TOP.');
            foreach($row['points']as$point)if(max($item['begin'],$point['at']-$seconds+1)<min($item['end'],$point['at']+1))throw new InvalidArgumentException('Free window conflicts with publication.');
        }
        return$row;
    }
}

/** No preview/source/media calls. All rights, snapshots, quota and booking decisions are delegated to the core. */
trait PostedTelegramTopUi
{
    private function telegramTopUiAllowed(int $actor):bool
    {return$actor>0&&PostedTelegramPolicy::operatorAllowed($this->config,$actor,$this->pdo)&&$this->isKnownActiveUser($this->user($actor));}
    private function telegramTopUiEnglish(int $actor):bool{return$this->telegramUiLanguage($actor)==='en';}
    private function telegramTopUiSend(int $actor,string $text,array $keyboard,?array $target):void
    {
        if(!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');
        if($target!==null&&(($target['chat_id']??null)!==$actor||!is_int($target['message_id']??null)||$target['message_id']<=0))throw new InvalidArgumentException('Invalid private target.');
        $this->sendOrEditMessage($actor,$text,['parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true],'reply_markup'=>['inline_keyboard'=>$keyboard]],$target);
    }
    /** Called only while rendering an already-authorized post. This helper never grants editing rights. */
    private function telegramTopHoldPostButton(array $post,string $scope='d'):array
    {
        $v=PostedTelegramTopView::class;$id=$v::id($post['id']??null);$v::scope($scope);
        try{$saved=PostedTelegramTopHold::load($this->pdo,$post);$enabled=$saved['enabled']??null;$duration=$saved['duration_minutes']??null;
            if(!is_bool($enabled)||!is_int($duration)||$duration<0||$duration>$v::MAX_MINUTES||($enabled&&$duration===0))throw new InvalidArgumentException('Invalid stored top option.');
            $label=($enabled?'✅ Топ: ':'☑️ Топ').($enabled?$v::minutes($duration,false):'');
        }catch(Throwable){$label='⚠️ Топ: нужна проверка';}
        return['text'=>$label,'callback_data'=>'top:post:'.$id.':'.$scope];
    }
    private function telegramTopPlacementGraphButton(int $actor,int $channel):array
    {
        $v=PostedTelegramTopView::class;$v::id($channel);$day=$v::day($this->todayForUser($actor));
        return['text'=>$v::text($this->telegramTopUiEnglish($actor),'График размещений','Placement schedule'),'callback_data'=>'top:graph:'.$channel.':'.$day];
    }
    private function handleTelegramTopCallback(array $callback):bool
    {
        $raw=$callback['data']??null;if(!is_string($raw)||!str_starts_with($raw,'top:'))return false;
        if($this->pdo->inTransaction())return true;
        $actor=$callback['from']['id']??null;$chat=$callback['message']['chat']??null;
        if(!is_int($actor)||$actor<=0||!is_array($chat)||($chat['type']??null)!=='private'||($chat['id']??null)!==$actor||!$this->telegramTopUiAllowed($actor)){$this->answerCallback($callback,'Недоступно.');return true;}
        $this->answerCallback($callback);$target=$this->callbackTarget($callback);
        try{$action=PostedTelegramTopView::parse($raw);match($action['action']){
            'post'=>$this->showTelegramTopPost($actor,$action['id'],$action['scope'],$target),
            'more'=>$this->showTelegramTopMoreSettings($actor,$action['id'],$action['scope'],$target),
            'set'=>$this->setTelegramTopFromUi($actor,$action['id'],$action['enabled'],$action['revision'],$action['scope'],$target),
            'return'=>$this->returnTelegramTopPost($actor,$action['id'],$action['scope'],$target),
            'graph'=>$this->showTelegramTopGraph($actor,$action['id'],$action['day'],$target),
            'slot'=>$this->showTelegramTopCalendarSlot($actor,$action['id'],$action['at'],$action['zone'],$target),
            'pick'=>$this->showTelegramTopCalendarPost($actor,$action['id'],$action['at'],$action['zone'],$target),
            'mark'=>$this->markTelegramTopCalendarPost($actor,$action['id'],$action['at'],$action['zone'],$action['revision'],$target),
            'book'=>$this->bookTelegramTopCalendarPost($actor,$action['id'],$action['at'],$action['zone'],$action['token']),
            'advertiser'=>$this->showTelegramTopCalendarAdvertiser($actor,$action['id'],$action['day'],$target),
            'custom'=>$this->showTelegramTopCalendarCustom($actor,$action['id'],$action['day'],$target),
            'month'=>$this->showTelegramTopGraphMonth($actor,$action['id'],$action['month'],$target),
            'exit'=>$this->showSettings($actor,$target),
        };}catch(Throwable$error){$en=$this->telegramTopUiEnglish($actor);$v=PostedTelegramTopView::class;
            $text=$v::text($en,'Не удалось проверить топ или график. Обновите данные. Эта кнопка не запускает публикацию.','The top setting or schedule could not be verified. Refresh the data. This button does not publish a post.');
            if($error instanceof PostedTelegramTopHoldException){
                $text=match($error->reason){
                    'schedule_conflict'=>$v::text($en,'Выбранное время пересекается с топом или другой публикацией. Выберите другое время.','This time conflicts with a TOP or another publication. Choose a different time.'),
                    'quota_exhausted'=>$v::text($en,'У автора закончилась квота топов для этого канала.','The author has no TOP units remaining for this channel.'),
                    'stale_revision'=>$v::text($en,'Настройка изменилась. Откройте пост заново; старое действие не повторяется автоматически.','This setting changed. Open the post again; stale actions are not retried automatically.'),
                    default=>$text,
                };
                if(is_array($error->blocking))try{$h=$error->blocking;$v::keys($h,['state','post_id','post_link','started_at','unlock_at']);
                    if(!in_array($h['state'],['reserved','active','uncertain'],true))throw new InvalidArgumentException('Invalid blocking state.');
                    $text.=$this->telegramTopHoldUiText($actor,$v::hold(['post_id'=>$h['post_id'],'post_url'=>$h['post_link'],'until'=>$h['unlock_at'],'uncertain'=>$h['state']!=='active']));
                }catch(Throwable){}
            }
            $this->telegramTopUiSend($actor,$text,[[['text'=>$v::text($en,'← Настройки','← Settings'),'callback_data'=>'top:exit:settings']]],$target);}
        return true;
    }
    private function showTelegramTopMoreSettings(int $actor,int $post,string $scope,?array $target):void
    {
        if($scope!=='m')throw new InvalidArgumentException('Invalid managed More scope.');
        PostedTelegramTopView::options($this->telegramTopHoldOptions($actor,$post)); // Fresh unchanged normal ACL; no rights are inferred from the menu.
        $row=$this->post($post);if(!is_array($row))throw new RuntimeException('post_missing');
        $this->telegramTopUiSend($actor,'Больше настроек',[[$this->telegramTopHoldPostButton($row,'m')],[['text'=>'← К посту','callback_data'=>'cp:post:'.$post]]],$target);
    }

    private function telegramTopHoldUiText(int $actor,array $hold):string
    {
        $v=PostedTelegramTopView::class;$en=$this->telegramTopUiEnglish($actor);$timezone=$this->userTimezoneObject($actor);
        if(!$hold['uncertain']&&$hold['until']!==null){
            $text=$v::text($en,"\n\n⏳ <b>В канале занят топ</b>","\n\n⏳ <b>The channel’s top slot is occupied</b>");
            $text.="\n".$v::text($en,'Освобождение: ','Unlock time: ').$v::escape($v::moment($hold['until'],$timezone)).' ('.$v::escape($timezone->getName()).')';
        }else $text=$v::text($en,"\n\n⏳ <b>Результат размещения проверяется</b>\nПока результат не подтверждён, свободный топ не обещается.","\n\n⏳ <b>The placement result is being checked</b>\nThe top slot is not confirmed free while this is unresolved.");
        if($hold['post_url']!==null)$text.="\n<a href=\"".$v::escape($hold['post_url']).'">'.$v::text($en,'Открыть публикацию','Open publication').'</a>';
        else$text.=$v::text($en,"\nСсылка появится после подтверждения публикации.","\nA link will be available after publication is confirmed.");
        return$text.$v::text($en,"\nОграничение действует для всех, включая владельца канала.","\nThis restriction applies to everyone, including the channel owner.");
    }
    private function showTelegramTopPost(int $actor,int $post,string $scope='d',?array $target=null,?array $provided=null):void
    {
        if(!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');
        $v=PostedTelegramTopView::class;$v::id($post);$v::scope($scope);$en=$this->telegramTopUiEnglish($actor);
        $options=$v::options($provided??$this->telegramTopHoldOptions($actor,$post));
        $text=$v::text($en,'📌 <b>ТОП ПУБЛИКАЦИИ</b>','📌 <b>PUBLICATION TOP SLOT</b>');
        $text.="\n\n".$v::text($en,'Топ: ','Top slot: ').($options['enabled']?$v::text($en,'включён','enabled'):$v::text($en,'выключен','disabled'));
        if($options['duration_minutes']>0)$text.="\n".$v::text($en,'Продолжительность: ','Duration: ').$v::minutes($options['duration_minutes'],$en);
        $text.="\n".$v::text($en,'Доступная квота для этого поста: ','Available quota for this post: ').($options['quota_remaining']===null?$v::text($en,'без ограничений','unlimited'):(string)$options['quota_remaining']);
        $text.=$v::text($en,"\n\nСрок фиксируется при включении топа. Изменение общего срока повлияет только на новые отметки. После фактической публикации топ нельзя сократить переключателем или удалением поста.","\n\nThe duration is saved when the top setting is enabled. Changing the global duration affects only new marks. Once published, the hold cannot be shortened by toggling this setting or deleting the post.");
        if($options['hold']!==null)$text.=$this->telegramTopHoldUiText($actor,$options['hold']);
        if($options['reason']!=='')$text.=$v::text($en,"\n\nНастройка сейчас ограничена. Права, квота и пересечения дат проверяются сервером.","\n\nThis setting is currently restricted. The server checks access, quota and date conflicts.");
        $keyboard=[];
        if(!$options['enabled']&&$options['can_enable'])$keyboard[]=[['text'=>$v::text($en,'Включить топ','Enable top slot'),'callback_data'=>'top:set:'.$post.':1:'.$options['revision'].':'.$scope]];
        if($options['enabled']&&$options['can_disable']&&($options['hold']===null||$options['hold']['post_id']!==$post))$keyboard[]=[['text'=>$v::text($en,'Выключить топ для будущей публикации','Disable for this future publication'),'callback_data'=>'top:set:'.$post.':0:'.$options['revision'].':'.$scope]];
        $keyboard[]=[['text'=>$v::text($en,'Обновить','Refresh'),'callback_data'=>'top:post:'.$post.':'.$scope]];
        $keyboard[]=[['text'=>$v::text($en,'← К посту','← Back to post'),'callback_data'=>'top:return:'.$post.':'.$scope]];
        $this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }
    private function setTelegramTopFromUi(int $actor,int $post,bool $enabled,int $revision,string $scope,?array $target):void
    {
        if(!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');
        $v=PostedTelegramTopView::class;$v::id($post);$v::id($revision,true);$v::scope($scope);
        // An explicit desired value and server revision make duplicate/stale clicks harmless. No automatic retry.
        $options=$v::options($this->setTelegramTopHold($actor,$post,$enabled,$revision));
        $this->showTelegramTopPost($actor,$post,$scope,$target,$options);
    }
    private function returnTelegramTopPost(int $actor,int $post,string $scope,?array $target):void
    {
        if(!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');$v=PostedTelegramTopView::class;$v::id($post);$v::scope($scope);
        $v::options($this->telegramTopHoldOptions($actor,$post)); // Fresh normal post ACL; a callback scope is never authority.
        $row=$this->post($post);if(!is_array($row)||(int)($row['id']??0)!==$post)throw new RuntimeException('post_missing');$en=$this->telegramTopUiEnglish($actor);
        if(($row['status']??null)==='draft'){
            $text=$v::text($en,'Настройки поста. Предпросмотр выше остаётся без изменений.','Post settings. The preview above is unchanged.');$keyboard=$this->draftMainKeyboard($row,true);
        }else{
            $text=$v::text($en,'Вернуться к обычному управлению публикацией.','Return to the normal publication controls.');
            $keyboard=[[['text'=>$v::text($en,'Открыть управление постом','Open publication controls'),'callback_data'=>'cp:post:'.$post]],
                [['text'=>$v::text($en,'← Настройки','← Settings'),'callback_data'=>'top:exit:settings']]];
        }
        $this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }
    private function showTelegramTopGraph(int $actor,int $channel,string $day,?array $target=null):void
    {
        if(!$this->telegramTopUiAllowed($actor)||$this->pdo->inTransaction())throw new RuntimeException('actor_denied');
        $v=PostedTelegramTopView::class;$v::id($channel);$v::day($day);$en=$this->telegramTopUiEnglish($actor);
        $g=$v::graph($this->telegramTopPlacementGraph($actor,$channel,$day),$channel,$day);$z=new DateTimeZone($g['timezone']);$date=new DateTimeImmutable($day.' 00:00:00',$z);
        $text=$v::text($en,'📅 <b>ГРАФИК РАЗМЕЩЕНИЙ · TOP</b>','📅 <b>PLACEMENT SCHEDULE · TOP</b>')."\n\n".$v::escape($v::short($g['channel_title'],70))."\n".$date->format('d.m.Y').' · '.$v::escape($g['timezone']);
        $text.="\n".$v::text($en,'Новый TOP: ','New TOP: ').$v::minutes($g['duration_minutes'],$en)."\n\n<b>".$v::text($en,'Расписание дня','Daily schedule').'</b>';
        if(!$g['availability_known']||$g['truncated'])$text.=$v::text($en,"\n❔ Свободное время не подтверждено: отправка проверяется или данные неполны.","\n❔ Free time is unconfirmed: delivery is unresolved or data is incomplete.");
        $events=PostedTelegramTopCalendarView::daily($g);$shown=0;
        foreach($events as$item){
            if($item['kind']==='point')$line='• '.PostedTelegramTopCalendarView::at($item['at'],$z).' — '.$v::escape($v::short($item['label'],32));
            else{
                $range=($item['at']<$g['day_start']?$v::moment($item['at'],$z,'d.m.Y '):'').PostedTelegramTopCalendarView::range($item['at'],$item['end'],$z);
                $line=match($item['kind']){
                    'free'=>'🟢 '.$v::escape($range).' — '.$v::text($en,'Свободно','Free'),
                    'top'=>'🔝 '.$v::escape($range).' — '.$v::escape($v::short($item['label'],32)).' · TOP',
                    default=>'❔ '.$v::escape($range).' — '.$v::escape($v::short($item['label'],32)),
                };
            }
            if($shown>=36||mb_strlen($text."\n".$line,'UTF-8')>2900)break;
            $text.="\n".$line;$shown++;
        }
        if($events===[])$text.=$v::text($en,"\nНа эту дату нет отображаемых событий или будущих часовых вариантов.","\nNo events or future hourly alternatives to display for this date.");
        if(count($events)>$shown)$text.="\n… ".$v::text($en,'Ещё строк расписания: ','More schedule rows: ').(count($events)-$shown);
        $text.=$v::text($en,"\n\n🟢 Строка — целый доступный TOP с началом в указанный час, а не бронь. Обычный пост показан точкой и не занимает час. Другую минуту можно выбрать через «Точное время».\nКонец TOP свободен для следующего размещения; доступность проверяется повторно.","\n\n🟢 Each row is one complete TOP starting at that hour, not a reservation. A regular post is a point, not an occupied hour. Use Custom time for another minute.\nTOP excludes its end; availability is checked again.");
        $text.=$v::text($en,"\n\n<b>Выберите начало TOP</b>\n🟢 подходит · 🔴 не подходит · ❔ не подтверждено · · прошло","\n\n<b>Choose a TOP start</b>\n🟢 available · 🔴 unavailable · ❔ unconfirmed · · past");
        $hash=PostedTelegramTopCalendarView::zoneFingerprint($g['timezone']);$keyboard=[];$row=[];
        foreach(PostedTelegramTopCalendarView::hours($g)as$hour){$icon=match($hour['state']){'free'=>'🟢','busy'=>'🔴','unknown'=>'❔',default=>'·'};$row[]=['text'=>$icon.' '.$hour['label'],'callback_data'=>$hour['state']==='free'?'top:slot:'.$channel.':'.$hour['at'].':'.$hash:'top:graph:'.$channel.':'.$day];if(count($row)===4){$keyboard[]=$row;$row=[];}}
        if($row!==[])$keyboard[]=$row;
        $nav=[];foreach(['-1 day'=>'←','+1 day'=>'→']as$step=>$label){$pick=$date->modify($step)->format('Y-m-d');if(preg_match('/\A[1-9][0-9]{3}-/',$pick)===1)$nav[]=['text'=>$label,'callback_data'=>'top:graph:'.$channel.':'.$pick];}array_splice($nav,1,0,[['text'=>$v::text($en,'Сегодня','Today'),'callback_data'=>'top:graph:'.$channel.':'.$g['today']]]);$keyboard[]=$nav;
        $keyboard[]=[['text'=>$date->format('m.Y').' ▦','callback_data'=>'top:month:'.$channel.':'.substr($day,0,7)],['text'=>$v::text($en,'Для рекламодателя','For advertiser'),'callback_data'=>'top:advertiser:'.$channel.':'.$day]];
        $keyboard[]=[['text'=>$v::text($en,'Точное время · свой черновик','Custom time · own draft'),'callback_data'=>'top:custom:'.$channel.':'.$day]];
        $keyboard[]=[['text'=>$v::text($en,'Обновить','Refresh'),'callback_data'=>'top:graph:'.$channel.':'.$day],['text'=>$v::text($en,'← Настройки','← Settings'),'callback_data'=>'top:exit:settings']];
        $this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }

    /** Readonly current graph choice; a callback never supplies a duration or authority. */
    private function telegramTopCalendarChoice(int $actor,int $channel,int $at,string $zoneHash,?int $postId=null):array
    {
        if($this->pdo->inTransaction()||!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');
        $zone=$this->userTimezoneObject($actor);if(!hash_equals(PostedTelegramTopCalendarView::zoneFingerprint($zone->getName()),$zoneHash))throw new InvalidArgumentException('Часовой пояс изменился. Откройте график заново.');
        $day=(new DateTimeImmutable('@'.$at))->setTimezone($zone)->format('Y-m-d');$graph=PostedTelegramTopView::graph($this->telegramTopPlacementGraph($actor,$channel,$day,$postId),$channel,$day);
        if(!PostedTelegramTopCalendarView::offered($graph,$at))throw new InvalidArgumentException('Это время уже недоступно для выбранного TOP. Обновите график.');
        return$graph;
    }

    private function showTelegramTopCalendarSlot(int $actor,int $channel,int $at,string $zoneHash,?array $target):void
    {
        $g=$this->telegramTopCalendarChoice($actor,$channel,$at,$zoneHash);if(!$this->can($actor,$channel,'schedule'))throw new RuntimeException('actor_denied');$v=PostedTelegramTopView::class;$zone=new DateTimeZone($g['timezone']);
        $text='📅 <b>ПЛАНИРОВАНИЕ TOP</b>'."\n\n".$v::escape($g['channel_title'])."\n".$v::moment($at,$zone).' · '.$v::escape($g['timezone'])."\n".$v::escape(PostedTelegramTopCalendarView::range($at,$at+$g['duration_minutes']*60,$zone)).' · '.$g['duration_minutes'].' мин';
        $q=$this->pdo->prepare("SELECT id,text,media_type FROM posted_posts WHERE channel_id=? AND author_id=? AND status='draft' ORDER BY id DESC LIMIT 21");$q->execute([$channel,$actor]);$drafts=$q->fetchAll();$keyboard=[];
        foreach(array_slice($drafts,0,20)as$p)if($this->canManagePost($actor,(int)$p['id']))$keyboard[]=[['text'=>'#'.$p['id'].' '.$v::short((string)$p['text'],38),'callback_data'=>'top:pick:'.$p['id'].':'.$at.':'.$zoneHash]];
        if($keyboard===[])$text.="\n\nСоздайте черновик в этом канале, затем выберите время в графике. Просмотр не бронирует место.";
        else$text.="\n\nВыберите свой черновик. Место пока не зарезервировано, квота не списана. Для поста с уже включённым TOP будет проверена его сохранённая длительность.";
        if(count($drafts)>20)$text.="\nПоказаны 20 последних черновиков. Остальные доступны через обычный редактор.";
        $keyboard[]=[['text'=>'← График','callback_data'=>'top:graph:'.$channel.':'.$g['day']]];$this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }

    private function telegramTopCalendarPost(int $actor,int $id):array
    {
        $post=$this->post($id);if(!is_array($post)||($post['status']??null)!=='draft'||(int)$post['author_id']!==$actor||!$this->canManagePost($actor,$id)||!$this->can($actor,(int)$post['channel_id'],'schedule'))throw new RuntimeException('actor_denied');
        $album=PostedTelegramAlbumCollector::forPost($this->pdo,$post);if($album!==null&&(count($album['items'])<2||$album['phase']!=='shown'||$album['shown_revision']!==$album['revision']))throw new PostedTelegramAlbumPendingException();return$post;
    }

    private function showTelegramTopCalendarPost(int $actor,int $id,int $at,string $zoneHash,?array $target):void
    {
        $p=$this->telegramTopCalendarPost($actor,$id);$g=$this->telegramTopCalendarChoice($actor,(int)$p['channel_id'],$at,$zoneHash,$id);$v=PostedTelegramTopView::class;$o=$v::options($this->telegramTopHoldOptions($actor,$p));$zone=new DateTimeZone($g['timezone']);$end=$at+$g['duration_minutes']*60;
        $text='📅 <b>ПЛАНИРОВАНИЕ TOP</b>'."\n\n".$v::escape($g['channel_title'])."\n".$v::moment($at,$zone).' · '.$v::escape($g['timezone'])."\nTOP ".$v::escape(PostedTelegramTopCalendarView::range($at,$end,$zone)).' · '.$g['duration_minutes'].' мин'."\n\nЧерновик #".$id.' · '.$v::escape($v::short((string)$p['text'],100));$keyboard=[];
        if(!$o['enabled']){
            $text.="\n\nTOP у этого черновика выключен. Включение — отдельное явное действие; место пока не бронируется.";
            if($o['can_enable'])$keyboard[]=[['text'=>'Включить TOP на '.$g['duration_minutes'].' мин','callback_data'=>'top:mark:'.$id.':'.$at.':'.$zoneHash.':'.$o['revision']]];
            else$text.="\nНе удалось включить TOP: проверьте квоту и права в настройках поста.";
        }else{
            $stored=(new DateTimeImmutable('@'.$at))->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');$token=$this->telegramDraftConfirmationKey($actor,$p,'schedule',$stored);
            $text.="\n\nНажмите «Запланировать», чтобы применить это время. Права, удержания и пересечения будут проверены повторно.";
            $keyboard[]=[['text'=>'Запланировать на '.$v::moment($at,$zone,'H:i'),'callback_data'=>'top:book:'.$id.':'.$at.':'.$zoneHash.':'.$token]];
        }
        $keyboard[]=[['text'=>'Точное время','callback_data'=>'draft:scheduletimes:'.$id.':'.str_replace('-','',$g['day'])]];
        $keyboard[]=[['text'=>'← Выбрать черновик','callback_data'=>'top:slot:'.$p['channel_id'].':'.$at.':'.$zoneHash],['text'=>'← График','callback_data'=>'top:graph:'.$p['channel_id'].':'.$g['day']]];$this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }

    private function markTelegramTopCalendarPost(int $actor,int $id,int $at,string $zoneHash,int $revision,?array $target):void
    {
        $p=$this->telegramTopCalendarPost($actor,$id);$this->telegramTopCalendarChoice($actor,(int)$p['channel_id'],$at,$zoneHash,$id);$this->setTelegramTopHold($actor,$id,true,$revision);$this->showTelegramTopCalendarPost($actor,$id,$at,$zoneHash,$target);
    }

    private function bookTelegramTopCalendarPost(int $actor,int $id,int $at,string $zoneHash,string $token):void
    {
        $p=$this->telegramTopCalendarPost($actor,$id);$this->telegramTopCalendarChoice($actor,(int)$p['channel_id'],$at,$zoneHash,$id);$o=PostedTelegramTopView::options($this->telegramTopHoldOptions($actor,$p));if(!$o['enabled'])throw new InvalidArgumentException('TOP у черновика выключен. Откройте планирование заново.');
        $stored=(new DateTimeImmutable('@'.$at))->setTimezone($this->storageTimezone())->format('Y-m-d H:i:s');
        // The existing locked scheduler rechecks this exact post/workflow token.
        // It alone updates status and clears the still-matching input after success.
        $this->scheduleDraft($actor,$id,$stored,$token,false,true);
    }

    private function showTelegramTopCalendarCustom(int $actor,int $channel,string $day,?array $target):void
    {
        if($this->pdo->inTransaction()||!$this->telegramTopUiAllowed($actor)||!$this->can($actor,$channel,'schedule'))throw new RuntimeException('actor_denied');
        $g=PostedTelegramTopView::graph($this->telegramTopPlacementGraph($actor,$channel,$day),$channel,$day);$text='🕔 <b>ТОЧНОЕ ВРЕМЯ</b>'."\n\n".PostedTelegramTopView::escape($g['channel_title'])."\n".$day.' · '.PostedTelegramTopView::escape($g['timezone']);$rows=[];
        if(!$g['availability_known']||$g['truncated'])$text.="\n\nДоступность пока не подтверждена. Обновите график после проверки отправки.";
        else{
            $q=$this->pdo->prepare("SELECT id,text FROM posted_posts WHERE channel_id=? AND author_id=? AND status='draft' ORDER BY id DESC LIMIT 20");$q->execute([$channel,$actor]);foreach($q->fetchAll()as$p)if($this->canManagePost($actor,(int)$p['id']))$rows[]=[['text'=>'#'.$p['id'].' '.PostedTelegramTopView::short((string)$p['text'],35),'callback_data'=>'draft:scheduletimes:'.$p['id'].':'.str_replace('-','',$day)]];
            $text.="\n\nОбычный пост показан точкой, а не занятым часом. В обычном планировании можно указать любую минуту, например 08:01 или 08:30. Система проверит весь выбранный TOP повторно. TOP включается в настройках черновика отдельно. Выберите свой черновик:";
            if($rows===[])$text.="\nСначала создайте черновик в этом канале.";
        }
        $rows[]=[['text'=>'← График','callback_data'=>'top:graph:'.$channel.':'.$day]];$this->telegramTopUiSend($actor,$text,$rows,$target);
    }

    private function showTelegramTopCalendarAdvertiser(int $actor,int $channel,string $day,?array $target):void
    {
        if($this->pdo->inTransaction())throw new LogicException('calendar_outside_sql');
        $g=PostedTelegramTopView::graph($this->telegramTopPlacementGraph($actor,$channel,$day),$channel,$day);$text=PostedTelegramTopCalendarView::advertiser($g);
        $this->telegramTopUiSend($actor,'<pre>'.PostedTelegramTopView::escape($text).'</pre>',[[['text'=>'← График','callback_data'=>'top:graph:'.$channel.':'.$day]]],$target);
    }

    private function showTelegramTopGraphMonth(int $actor,int $channel,string $month,?array $target=null):void
    {
        if(!$this->telegramTopUiAllowed($actor))throw new RuntimeException('actor_denied');
        $v=PostedTelegramTopView::class;$v::id($channel);$v::month($month);$en=$this->telegramTopUiEnglish($actor);
        // One SQL-only graph read establishes access and authoritative viewer timezone/today. No per-day API scans.
        $day=$month.'-01';$graph=$v::graph($this->telegramTopPlacementGraph($actor,$channel,$day),$channel,$day);$zone=new DateTimeZone($graph['timezone']);$date=new DateTimeImmutable($day.' 00:00:00',$zone);
        $text=$v::text($en,'📅 <b>ГРАФИК РАЗМЕЩЕНИЙ</b>','📅 <b>PLACEMENT SCHEDULE</b>')."\n\n".$v::escape($v::short($graph['channel_title'],60))."\n".$date->format('m.Y').' · '.$v::escape($graph['timezone']);
        $nav=[];foreach(['-1 month'=>'←','+1 month'=>'→']as$move=>$label){$candidate=$date->modify($move)->format('Y-m');if(preg_match('/\A[1-9][0-9]{3}-[0-9]{2}\z/D',$candidate)===1)$nav[]=['text'=>$label,'callback_data'=>'top:month:'.$channel.':'.$candidate];}
        $keyboard=[];if($nav!==[])$keyboard[]=$nav;
        $weekdays=$en?['Mo','Tu','We','Th','Fr','Sa','Su']:['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];$header=[];foreach($weekdays as$label)$header[]=['text'=>$label,'callback_data'=>'top:month:'.$channel.':'.$month];$keyboard[]=$header;
        $offset=(int)$date->format('N')-1;$days=(int)$date->format('t');
        for($week=0;$week<6;$week++){$row=[];for($column=0;$column<7;$column++){$n=$week*7+$column-$offset+1;
            if($n<1||$n>$days){$row[]=['text'=>'·','callback_data'=>'top:month:'.$channel.':'.$month];continue;}
            $picked=$month.'-'.str_pad((string)$n,2,'0',STR_PAD_LEFT);$row[]=['text'=>($picked===$graph['today']?'• ':'').$n,'callback_data'=>'top:graph:'.$channel.':'.$picked];
        }$keyboard[]=$row;}
        $keyboard[]=[['text'=>$v::text($en,'Сегодня','Today'),'callback_data'=>'top:graph:'.$channel.':'.$graph['today']]];
        $keyboard[]=[['text'=>$v::text($en,'← Настройки','← Settings'),'callback_data'=>'top:exit:settings']];
        $this->telegramTopUiSend($actor,$text,$keyboard,$target);
    }
}
