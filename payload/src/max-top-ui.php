<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Presentation helpers only. Reservation, quota charging and conflict decisions belong to the core. */
final class GitPostedMaxTopView
{
    public const MAX_MINUTES=525600;
    public const REASONS=['ready','post_locked','quota_exhausted','actor_denied','invalid_top','schedule_conflict','delivery_unknown','hold_active','stale_revision'];

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
        if(strlen($raw)>256)throw new InvalidArgumentException('Invalid top callback.');
        if(preg_match('/\Agp:top:(post|return):([0-9]+):(d|m)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'scope'=>$m[3]];
        if(preg_match('/\Agp:top:(set):([0-9]+):([01]):([0-9]+):(d|m)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'enabled'=>$m[3]==='1','revision'=>self::id($m[4],true),'scope'=>$m[5]];
        if(preg_match('/\Agp:top:(graph):([0-9]+):([0-9-]+)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'day'=>self::day($m[3])];
        if(preg_match('/\Agp:top:(month):([0-9]+):([0-9-]+)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>self::id($m[2]),'month'=>self::month($m[3])];
        if($raw==='gp:top:exit:settings')return['action'=>'exit'];
        return GitPostedMaxTopCalendar::parse($raw);
    }
    public static function keys(array $row,array $expected):void
    {$keys=array_keys($row);sort($keys);sort($expected);if($keys!==$expected)throw new InvalidArgumentException('Invalid top view data.');}
    public static function url(mixed $url):?string
    {
        if($url===null)return null;
        if(!is_string($url)||strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('Invalid publication link.');$p=parse_url($url);if(!is_array($p)||($p['scheme']??null)!=='https'||strtolower((string)($p['host']??''))!=='max.ru'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment'])||!str_starts_with($p['path']??'','/'))throw new InvalidArgumentException('Invalid publication link.');
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

/** Only private controls and SQL-backed views. No post preview, download or publication calls. */
trait GitPostedMaxTopUi
{
    use GitPostedMaxTopCalendarUi;
    private function maxTopHoldPostButton(array $post):array
    {
        $id=GitPostedMaxTopView::id($post['id']??null);
        try{$o=GitPostedMaxTopHold::load($this->pdo,$post);if(!is_bool($o['enabled']??null)||!is_int($o['duration_minutes']??null))throw new InvalidArgumentException('Invalid TOP.');$label=$o['enabled']?'✅ Топ: '.GitPostedMaxTopView::minutes($o['duration_minutes'],false):'☑️ Топ';}
        catch(Throwable){$label='⚠️ Топ: нужна проверка';}
        return GitPostedMaxApi::callbackButton($label,'gp:top:post:'.$id.':d');
    }
    private function maxTopPlacementGraphButton(string $actor,int $channelId):array
    {GitPostedMaxTopView::id($channelId);$day=GitPostedMaxTopView::day($this->maxToday($actor));return GitPostedMaxApi::callbackButton('График размещений','gp:top:graph:'.$channelId.':'.$day);}
    private function maxTopUiReply(string $callback,string $text,array $rows):void
    {$this->api->answerCallback($callback,$text,$rows,'',[],null,'html',true);}
    private function maxTopHoldUiText(string $actor,array $hold):string
    {
        $v=GitPostedMaxTopView::class;$hold=$v::hold($hold);$user=$this->user($actor);$zone=new DateTimeZone((string)($user['user_timezone']??'Europe/Moscow'));
        if(!$hold['uncertain']&&$hold['until']!==null)$text="\n\n⏳ <b>В канале занят топ</b>\nОсвобождение: ".$v::escape($v::moment($hold['until'],$zone)).' ('.$v::escape($zone->getName()).')';
        else$text="\n\n⏳ <b>Результат размещения проверяется</b>\nПока результат не подтверждён, свободный топ не обещается.";
        $text.=$hold['post_url']!==null?"\n".'<a href="'.$v::escape($hold['post_url']).'">Открыть публикацию</a>':"\nСсылка появится после подтверждения публикации.";
        return$text."\nОграничение действует для всех, включая владельца канала.";
    }
    private function handleMaxTopCallback(array $update):bool
    {
        $raw=$update['callback']['payload']??null;if(!is_string($raw)||!str_starts_with($raw,'gp:top:'))return false;
        $actor=$this->maxTopUiActor($update,true);if($actor===null)return true;$id=$actor['actor_id'];$cb=$update['callback']['callback_id']??null;if(!is_string($cb)||$cb==='')return true;
        try{$r=GitPostedMaxTopView::parse($raw);
            if($r['action']==='post')$this->showMaxTopPost($cb,$id,$r['id'],$r['scope']);
            elseif($r['action']==='set'){$o=$this->setMaxTopHold($id,$r['id'],$r['enabled'],$r['revision']);$this->showMaxTopPost($cb,$id,$r['id'],$r['scope'],$o);}
            elseif($r['action']==='return'){$o=GitPostedMaxTopView::options($this->maxTopHoldOptions($id,$r['id']));$this->maxTopUiReply($cb,'Настройки отправки. Предпросмотр публикации остаётся без изменений.',[[GitPostedMaxApi::callbackButton('← К настройкам отправки','gp:next:'.$r['id'])]]);}
            elseif($r['action']==='graph')$this->showMaxTopGraph($cb,$id,$r['id'],$r['day']);
            elseif($r['action']==='month')$this->showMaxTopGraphMonth($cb,$id,$r['id'],$r['month']);
            elseif(in_array($r['action'],['slot','pick','mark','book','custom','advertiser'],true))$this->handleMaxTopCalendarControl($cb,$id,$r);
            else$this->maxTopUiReply($cb,'Вернитесь к настройкам бота.',[[GitPostedMaxApi::callbackButton('← Настройки','gp:settings')]]);
        }catch(Throwable $e){error_log('GitPosted MAX TOP controls: '.$e::class);$text='Не удалось проверить топ или график. Обновите данные. Эта кнопка не запускает публикацию.';
            if($e instanceof GitPostedMaxTopHoldException){$text=match($e->reason){'quota_exhausted'=>'У автора закончился лимит топов для этого канала.','stale_revision'=>'Настройка изменилась. Откройте пост заново; старое действие не повторяется автоматически.','schedule_conflict'=>'Выбранное время пересекается с топом или другой публикацией. Выберите другое время.',default=>$text};if($e->blocking!==null)try{$text.=$this->maxTopHoldUiText($id,GitPostedMaxTopView::hold($e->blocking));}catch(Throwable){}}
            $this->maxTopUiReply($cb,$text,[[GitPostedMaxApi::callbackButton('← Настройки','gp:settings')]]);}
        return true;
    }
    private function showMaxTopPost(string $callback,string $actor,int $post,string $scope='d',?array $provided=null):void
    {
        $v=GitPostedMaxTopView::class;$v::id($post);$v::scope($scope);$o=$v::options($provided??$this->maxTopHoldOptions($actor,$post));
        $text='📌 <b>ТОП ПУБЛИКАЦИИ</b>'."\n\nТоп: ".($o['enabled']?'включён':'выключен');
        if($o['duration_minutes']>0)$text.="\nПродолжительность: ".$v::minutes($o['duration_minutes'],false);
        $text.="\nДоступная квота для этого поста: ".($o['unlimited']?'без ограничений':$o['quota_remaining']);
        $text.="\n\nСрок фиксируется при включении топа. Изменение общего срока повлияет только на новые отметки. После фактической публикации топ нельзя сократить переключателем или удалением поста.";
        if($o['hold']!==null)$text.=$this->maxTopHoldUiText($actor,$o['hold']);
        if($o['reason']!=='ready')$text.="\n\nНастройка сейчас ограничена. Права, квота и пересечения дат проверяются сервером.";
        $rows=[];if(!$o['enabled']&&$o['can_enable'])$rows[]=[GitPostedMaxApi::callbackButton('Включить топ','gp:top:set:'.$post.':1:'.$o['revision'].':'.$scope)];
        if($o['enabled']&&$o['can_disable'])$rows[]=[GitPostedMaxApi::callbackButton('Выключить топ для будущей публикации','gp:top:set:'.$post.':0:'.$o['revision'].':'.$scope)];
        $rows[]=[GitPostedMaxApi::callbackButton('Обновить','gp:top:post:'.$post.':'.$scope)];$rows[]=[GitPostedMaxApi::callbackButton('← К посту','gp:top:return:'.$post.':'.$scope)];$this->maxTopUiReply($callback,$text,$rows);
    }
    private function showMaxTopGraph(string $callback,string $actor,int $channel,string $day):void
    {$this->showMaxTopCalendarGraph($callback,$actor,$channel,$day);}
    private function showMaxTopGraphMonth(string $callback,string $actor,int $channel,string $month):void
    {
        $v=GitPostedMaxTopView::class;$v::month($month);$g=$v::graph($this->maxTopPlacementGraph($actor,$channel,$month.'-01'),$channel,$month.'-01');$date=new DateTimeImmutable($month.'-01 00:00:00',new DateTimeZone($g['timezone']));
        $rows=[[GitPostedMaxApi::callbackButton('←','gp:top:month:'.$channel.':'.$date->modify('-1 month')->format('Y-m')),GitPostedMaxApi::callbackButton($month,'gp:top:month:'.$channel.':'.$month),GitPostedMaxApi::callbackButton('→','gp:top:month:'.$channel.':'.$date->modify('+1 month')->format('Y-m'))]];
        $header=[];foreach(['Пн','Вт','Ср','Чт','Пт','Сб','Вс']as$label)$header[]=GitPostedMaxApi::callbackButton($label,'gp:top:month:'.$channel.':'.$month);$rows[]=$header;$offset=(int)$date->format('N')-1;
        for($week=0;$week<6;$week++){$row=[];for($column=0;$column<7;$column++){$n=$week*7+$column-$offset+1;$valid=$n>0&&$n<=(int)$date->format('t');$picked=$month.'-'.str_pad((string)$n,2,'0',STR_PAD_LEFT);$row[]=GitPostedMaxApi::callbackButton($valid?(($picked===$g['today']?'• ':'').$n):'·',$valid?'gp:top:graph:'.$channel.':'.$picked:'gp:top:month:'.$channel.':'.$month);}$rows[]=$row;}
        $rows[]=[GitPostedMaxApi::callbackButton('Сегодня','gp:top:graph:'.$channel.':'.$g['today'])];$rows[]=[GitPostedMaxApi::callbackButton('← К каналу','gp:channelsettings:'.$channel)];
        $this->maxTopUiReply($callback,'📅 <b>ГРАФИК РАЗМЕЩЕНИЙ</b>'."\n\n".$v::escape($v::short($g['channel_title'],60))."\n".$month.' · '.$v::escape($g['timezone']),$rows);
    }
}
