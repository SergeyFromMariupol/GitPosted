<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** MAX controls reuse the validated, SQL-free calendar presentation algorithms. */
final class GitPostedMaxTopCalendar
{
    public static function parse(string $raw):array
    {
        if(strlen($raw)>256)throw new InvalidArgumentException('Меню календаря устарело.');
        $v=GitPostedMaxTopView::class;$time=PostedTelegramTopCalendarView::class;
        if(preg_match('/\Agp:top:(slot|pick):([1-9][0-9]{0,18}):([1-9][0-9]{0,11}):([a-f0-9]{8})\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>$v::id($m[2]),'at'=>$time::epoch($m[3]),'zone_hash'=>$m[4]];
        if(preg_match('/\Agp:top:mark:([1-9][0-9]{0,18}):([1-9][0-9]{0,11}):([a-f0-9]{8}):([0-9]+):([a-f0-9]{32})\z/D',$raw,$m)===1)return['action'=>'mark','id'=>$v::id($m[1]),'at'=>$time::epoch($m[2]),'zone_hash'=>$m[3],'revision'=>$v::id($m[4],true),'token'=>$m[5]];
        if(preg_match('/\Agp:top:book:([1-9][0-9]{0,18}):([1-9][0-9]{0,11}):([a-f0-9]{8}):([a-f0-9]{32})\z/D',$raw,$m)===1)return['action'=>'book','id'=>$v::id($m[1]),'at'=>$time::epoch($m[2]),'zone_hash'=>$m[3],'token'=>$m[4]];
        if(preg_match('/\Agp:top:(custom|advertiser):([1-9][0-9]{0,18}):([0-9-]+)\z/D',$raw,$m)===1)return['action'=>$m[1],'id'=>$v::id($m[2]),'day'=>$v::day($m[3])];
        throw new InvalidArgumentException('Меню календаря устарело. Откройте график заново.');
    }

    /** A DATETIME must roundtrip to the selected instant; never choose another DST fold. */
    public static function stored(int $at):string
    {
        PostedTelegramTopCalendarView::epoch($at);
        $value=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        if(GitPostedMaxTopHold::epoch($value)!==$at)throw new InvalidArgumentException('Это время неоднозначно в системном часовом поясе. Выберите другое начало TOP.');
        return$value;
    }

    private static function row(array $row):array
    {
        ksort($row,SORT_STRING);
        foreach($row as$key=>&$value){if(!is_string($key)||(!is_null($value)&&!is_scalar($value)))throw new InvalidArgumentException('Снимок поста повреждён.');if($value!==null)$value=(string)$value;}unset($value);
        return$row;
    }

    /** A confirmation binds business data and the exact current private workflow; it grants no ACL. */
    public static function confirmation(string $mode,string $actor,array $post,array $channel,array $user,array $option,int $duration,int $at):string
    {
        if(!in_array($mode,['mark','book'],true)||$duration<1||$duration>GitPostedMaxTopHold::MAX_DURATION_MINUTES)throw new InvalidArgumentException('Некорректное подтверждение календаря.');
        GitPostedMaxTopHold::userId($actor);PostedTelegramTopCalendarView::epoch($at);
        if((string)($post['author_max_user_id']??'')!==$actor||(string)($user['max_user_id']??'')!==$actor||(int)($post['channel_id']??0)!==(int)($channel['id']??0)||(string)($post['status']??'')!=='draft')throw new InvalidArgumentException('Черновик изменился.');
        $authority=[];foreach(['id','chat_id','added_by','is_active','is_archived','admin_blocked','can_post']as$key)$authority[$key]=$channel[$key]??null;
        $workflow=[];foreach(['state','state_data','private_chat_id','user_timezone','is_active']as$key)$workflow[$key]=$user[$key]??null;
        $payload=['version'=>1,'mode'=>$mode,'actor'=>$actor,'post'=>self::row($post),'channel'=>self::row($authority),'workflow'=>self::row($workflow),'top'=>self::row(GitPostedMaxTopHold::normalize($option,$post)),'duration'=>$duration,'at'=>$at];
        return substr(hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),0,32);
    }

    public static function effect(array $effect,string $actor,array $request,string $kind):array
    {
        if(!in_array($kind,['mark','book'],true))throw new InvalidArgumentException('Некорректный вид действия календаря.');
        $base=['actor_id'=>$actor,'post_id'=>$request['id'],'at'=>$request['at'],'zone_hash'=>$request['zone_hash'],'token'=>$request['token']];
        foreach($base as$key=>$value)if(($effect[$key]??null)!==$value)throw new InvalidArgumentException('Сохранённое действие календаря относится к другому выбору.');
        $keys=array_keys($effect);sort($keys);$expected=array_merge(array_keys($base),['duration_minutes'],$kind==='book'?['scheduled_at']:['revision']);sort($expected);
        if($keys!==$expected||!is_int($effect['duration_minutes'])||$effect['duration_minutes']<1||$effect['duration_minutes']>GitPostedMaxTopHold::MAX_DURATION_MINUTES)throw new InvalidArgumentException('Сохранённое действие календаря повреждено.');
        if($kind==='book'){if(!is_string($effect['scheduled_at'])||GitPostedMaxTopHold::epoch($effect['scheduled_at'])!==$request['at'])throw new InvalidArgumentException('Сохранённое время календаря повреждено.');}
        elseif(!is_int($effect['revision'])||$effect['revision']<1)throw new InvalidArgumentException('Сохранённая версия TOP повреждена.');
        return$effect;
    }
}

trait GitPostedMaxTopCalendarUi
{
    private function maxTopCalendarSnapshot(string $actor,int $postId):array
    {
        if($this->pdo->inTransaction())throw new LogicException('Календарь открывается вне SQL-транзакции.');
        GitPostedMaxPolicy::requireOperator($this->config,$actor);
        $q=$this->pdo->prepare("SELECT p.* FROM posted_max_posts p JOIN posted_max_channels c ON c.id=p.channel_id LEFT JOIN posted_max_permissions g ON g.channel_id=c.id AND g.max_user_id=? WHERE p.id=? AND p.author_max_user_id=? AND p.status='draft' AND c.is_active=1 AND c.is_archived=0 AND c.admin_blocked=0 AND (c.added_by=? OR g.can_schedule=1) LIMIT 1");$q->execute([$actor,$postId,$actor,$actor]);$post=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($post))throw new InvalidArgumentException('Выберите свой доступный черновик для планирования.');
        $this->assertNoActivePublicationAttempt($postId);
        $q=$this->pdo->prepare('SELECT * FROM posted_max_channels WHERE id=? LIMIT 1');$q->execute([(int)$post['channel_id']]);$channel=$q->fetch(PDO::FETCH_ASSOC);$user=$this->user($actor);
        if(!is_array($channel)||(int)($user['is_active']??0)!==1||!is_string($user['private_chat_id']??null)||$user['private_chat_id']==='')throw new InvalidArgumentException('Доступ изменился. Откройте график заново.');
        $option=GitPostedMaxTopHold::load($this->pdo,$post);$duration=$option['enabled']?$option['duration_minutes']:GitPostedMaxTopHold::fromConfig($this->config)['duration_minutes'];
        return compact('post','channel','user','option','duration');
    }

    private function maxTopCalendarChoice(string $actor,int $channel,int $at,string $zoneHash,?int $postId=null):array
    {
        $user=$this->user($actor);$zone=new DateTimeZone((string)($user['user_timezone']??'Europe/Moscow'));
        if(!hash_equals(PostedTelegramTopCalendarView::zoneFingerprint($zone->getName()),$zoneHash))throw new InvalidArgumentException('Часовой пояс изменился. Откройте график заново.');
        $day=(new DateTimeImmutable('@'.$at))->setTimezone($zone)->format('Y-m-d');
        $g=GitPostedMaxTopView::graph($this->maxTopPlacementGraph($actor,$channel,$day,$postId),$channel,$day);
        if(!PostedTelegramTopCalendarView::offered($g,$at))throw new InvalidArgumentException('Это начало TOP уже недоступно. Обновите график.');
        return$g;
    }

    private function showMaxTopCalendarGraph(string $callback,string $actor,int $channel,string $day):void
    {
        $v=GitPostedMaxTopView::class;$g=$v::graph($this->maxTopPlacementGraph($actor,$channel,$day),$channel,$day);$z=new DateTimeZone($g['timezone']);$date=new DateTimeImmutable($day.' 00:00:00',$z);
        $text='📅 <b>ГРАФИК РАЗМЕЩЕНИЙ · TOP</b>'."\n\n".$v::escape($v::short($g['channel_title'],70))."\n".$date->format('d.m.Y').' · '.$v::escape($g['timezone'])."\nНовый TOP: ".$v::minutes($g['duration_minutes'],false)."\n\n<b>Расписание дня</b>";
        if(!$g['availability_known']||$g['truncated'])$text.="\n❔ Свободные окна не подтверждены: отправка проверяется или данные неполны.";
        $events=PostedTelegramTopCalendarView::daily($g);$shown=0;
        foreach($events as$i){
            if($i['kind']==='point')$line='• '.PostedTelegramTopCalendarView::at($i['at'],$z).' — '.$v::escape($v::short($i['label'],32));
            else{$range=($i['at']<$g['day_start']?$v::moment($i['at'],$z,'d.m.Y '):'').PostedTelegramTopCalendarView::range($i['at'],$i['end'],$z);$line=match($i['kind']){'free'=>'🟢 '.$v::escape($range).' — Свободно','top'=>'🔝 '.$v::escape($range).' — '.$v::escape($v::short($i['label'],32)).' · TOP',default=>'❔ '.$v::escape($range).' — '.$v::escape($v::short($i['label'],32))};}
            if($shown>=36||mb_strlen($text."\n".$line,'UTF-8')>2600)break;$text.="\n".$line;$shown++;
        }
        if($events===[])$text.="\nНа эту дату нет отображаемых событий или будущих часовых вариантов.";
        if(count($events)>$shown)$text.="\n… Ещё строк расписания: ".(count($events)-$shown);
        $text.="\n\n🟢 Строка — целый доступный TOP с началом в указанный час, а не бронь. Обычный пост показан точкой и не занимает час. Другую минуту можно выбрать через «Точное время».\nКонец TOP свободен для следующего размещения; доступность проверяется повторно.\n\n<b>Выберите начало TOP</b>\n🟢 подходит · 🔴 не подходит · ❔ не подтверждено · · прошло";
        $hash=PostedTelegramTopCalendarView::zoneFingerprint($g['timezone']);$rows=[];$row=[];
        foreach(PostedTelegramTopCalendarView::hours($g)as$hour){$icon=match($hour['state']){'free'=>'🟢','busy'=>'🔴','unknown'=>'❔',default=>'·'};$row[]=GitPostedMaxApi::callbackButton($icon.' '.$hour['label'],$hour['state']==='free'?'gp:top:slot:'.$channel.':'.$hour['at'].':'.$hash:'gp:top:graph:'.$channel.':'.$day);if(count($row)===4){$rows[]=$row;$row=[];}}
        if($row!==[])$rows[]=$row;
        $rows[]=[GitPostedMaxApi::callbackButton('←','gp:top:graph:'.$channel.':'.$date->modify('-1 day')->format('Y-m-d')),GitPostedMaxApi::callbackButton('Сегодня','gp:top:graph:'.$channel.':'.$g['today']),GitPostedMaxApi::callbackButton('→','gp:top:graph:'.$channel.':'.$date->modify('+1 day')->format('Y-m-d'))];
        $rows[]=[GitPostedMaxApi::callbackButton($date->format('m.Y').' ▦','gp:top:month:'.$channel.':'.substr($day,0,7)),GitPostedMaxApi::callbackButton('Для рекламодателя','gp:top:advertiser:'.$channel.':'.$day)];
        $rows[]=[GitPostedMaxApi::callbackButton('Точное время · свой черновик','gp:top:custom:'.$channel.':'.$day)];$rows[]=[GitPostedMaxApi::callbackButton('Обновить','gp:top:graph:'.$channel.':'.$day),GitPostedMaxApi::callbackButton('← К каналу','gp:channelsettings:'.$channel)];
        $links=[];foreach(array_merge($g['intervals'],$g['points'])as$i)if($i['post_url']!==null&&count($links)<4)$links[]=[GitPostedMaxApi::linkButton('Открыть: '.$v::short($i['label'],38),$i['post_url'])];
        $this->maxTopUiReply($callback,$text,array_merge($links,$rows));
    }

    private function showMaxTopCalendarSlot(string $callback,string $actor,int $channel,int $at,string $zoneHash):void
    {
        $g=$this->maxTopCalendarChoice($actor,$channel,$at,$zoneHash);$v=GitPostedMaxTopView::class;$z=new DateTimeZone($g['timezone']);
        $text='📅 <b>ПЛАНИРОВАНИЕ TOP</b>'."\n\n".$v::escape($g['channel_title'])."\n".$v::moment($at,$z).' · '.$v::escape($g['timezone'])."\n".$v::escape(PostedTelegramTopCalendarView::range($at,$at+$g['duration_minutes']*60,$z)).' · '.$g['duration_minutes'].' мин';
        $q=$this->pdo->prepare("SELECT p.id,p.text FROM posted_max_posts p JOIN posted_max_channels c ON c.id=p.channel_id LEFT JOIN posted_max_permissions g ON g.channel_id=c.id AND g.max_user_id=? WHERE p.channel_id=? AND p.author_max_user_id=? AND p.status='draft' AND (c.added_by=? OR g.can_schedule=1) ORDER BY p.id DESC LIMIT 21");$q->execute([$actor,$channel,$actor,$actor]);$drafts=$q->fetchAll(PDO::FETCH_ASSOC);$rows=[];
        foreach(array_slice($drafts,0,20)as$p)$rows[]=[GitPostedMaxApi::callbackButton('#'.$p['id'].' '.$v::short((string)$p['text'],38),'gp:top:pick:'.$p['id'].':'.$at.':'.$zoneHash)];
        $text.=$rows===[]?"\n\nСоздайте свой черновик в этом канале, затем выберите время в графике. Просмотр не бронирует место.":"\n\nВыберите свой черновик. Место пока не зарезервировано, квота не списана. Для уже включённого TOP проверяется сохранённая длительность.";
        if(count($drafts)>20)$text.="\nПоказаны 20 последних черновиков. Остальные доступны через обычный редактор.";
        $rows[]=[GitPostedMaxApi::callbackButton('← График','gp:top:graph:'.$channel.':'.$g['day'])];$this->maxTopUiReply($callback,$text,$rows);
    }

    private function showMaxTopCalendarPost(string $callback,string $actor,int $id,int $at,string $zoneHash):void
    {
        $s=$this->maxTopCalendarSnapshot($actor,$id);$p=$s['post'];$g=$this->maxTopCalendarChoice($actor,(int)$p['channel_id'],$at,$zoneHash,$id);$v=GitPostedMaxTopView::class;$z=new DateTimeZone($g['timezone']);
        if($g['duration_minutes']!==$s['duration'])throw new InvalidArgumentException('Настройка TOP изменилась. Откройте черновик заново.');
        $text='📅 <b>ПЛАНИРОВАНИЕ TOP</b>'."\n\n".$v::escape($g['channel_title'])."\n".$v::moment($at,$z).' · '.$v::escape($g['timezone'])."\nTOP ".$v::escape(PostedTelegramTopCalendarView::range($at,$at+$s['duration']*60,$z)).' · '.$s['duration']." мин\n\nЧерновик #".$id.' · '.$v::escape($v::short((string)$p['text'],100));$rows=[];
        if(!$s['option']['enabled']){$text.="\n\nTOP у этого черновика выключен. Включение — отдельное явное действие; место пока не бронируется.";$o=GitPostedMaxTopView::options(GitPostedMaxTopHold::state($this->pdo,$this->config,$p,$s['channel'],$actor));$mayMark=(string)$s['channel']['added_by']===$actor;if(!$mayMark){$q=$this->pdo->prepare('SELECT can_post FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1');$q->execute([(int)$p['channel_id'],$actor]);$mayMark=(int)$q->fetchColumn()===1;}if($o['can_enable']&&$mayMark){$token=GitPostedMaxTopCalendar::confirmation('mark',$actor,$p,$s['channel'],$s['user'],$s['option'],$s['duration'],$at);$rows[]=[GitPostedMaxApi::callbackButton('Включить TOP на '.$s['duration'].' мин','gp:top:mark:'.$id.':'.$at.':'.$zoneHash.':'.$s['option']['revision'].':'.$token)];}}
        else{$token=GitPostedMaxTopCalendar::confirmation('book',$actor,$p,$s['channel'],$s['user'],$s['option'],$s['duration'],$at);$text.="\n\nНажмите «Запланировать», чтобы применить это время. Права, удержания и пересечения будут проверены повторно.";$rows[]=[GitPostedMaxApi::callbackButton('Запланировать на '.PostedTelegramTopCalendarView::at($at,$z),'gp:top:book:'.$id.':'.$at.':'.$zoneHash.':'.$token)];}
        $rows[]=[GitPostedMaxApi::callbackButton('Точное время','gp:scheduletimes:'.$id.':'.str_replace('-','',$g['day']))];$rows[]=[GitPostedMaxApi::callbackButton('← Выбрать черновик','gp:top:slot:'.$p['channel_id'].':'.$at.':'.$zoneHash),GitPostedMaxApi::callbackButton('← График','gp:top:graph:'.$p['channel_id'].':'.$g['day'])];$this->maxTopUiReply($callback,$text,$rows);
    }

    private function maxTopCalendarLockedContext(string $actor,array $p,array $c,array $request,string $mode):array
    {
        if(!$this->pdo->inTransaction()||(string)$p['author_max_user_id']!==$actor)throw new InvalidArgumentException('Выберите свой черновик.');
        if((int)($c['is_active']??0)!==1||!empty($c['is_archived'])||!empty($c['admin_blocked']))throw new InvalidArgumentException('Канал сейчас недоступен для планирования.');
        if((string)$c['added_by']!==$actor){$q=$this->pdo->prepare('SELECT can_schedule FROM posted_max_permissions WHERE channel_id=? AND max_user_id=? LIMIT 1 FOR UPDATE');$q->execute([(int)$c['id'],$actor]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Право планирования изменилось.');}
        $q=$this->pdo->prepare('SELECT * FROM posted_max_users WHERE max_user_id=? LIMIT 1 FOR UPDATE');$q->execute([$actor]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($u)||(int)$u['is_active']!==1)throw new InvalidArgumentException('Доступ изменился.');
        $zone=new DateTimeZone((string)($u['user_timezone']??'Europe/Moscow'));if(!hash_equals(PostedTelegramTopCalendarView::zoneFingerprint($zone->getName()),$request['zone_hash']))throw new InvalidArgumentException('Часовой пояс изменился.');
        $o=GitPostedMaxTopHold::load($this->pdo,$p,true);$duration=$o['enabled']?$o['duration_minutes']:GitPostedMaxTopHold::fromConfig($this->config)['duration_minutes'];
        $token=GitPostedMaxTopCalendar::confirmation($mode,$actor,$p,$c,$u,$o,$duration,$request['at']);if(!hash_equals($token,$request['token']))throw new InvalidArgumentException('Черновик или текущий ввод изменился. Откройте планирование заново.');
        $day=(new DateTimeImmutable('@'.$request['at']))->setTimezone($zone)->format('Y-m-d');$g=GitPostedMaxTopView::graph(GitPostedMaxTopHold::graph($this->pdo,$this->config,$c,$actor,$day,$zone->getName(),true,$duration),(int)$c['id'],$day);
        if(!PostedTelegramTopCalendarView::offered($g,$request['at']))throw new InvalidArgumentException('Это начало TOP уже недоступно. Обновите график.');
        return compact('u','o','duration');
    }

    private function markMaxTopCalendarPost(string $callback,string $actor,array $r):void
    {
        $effect=$this->currentMaxUpdateEffect('max_top_calendar_mark');
        if($effect!==null){GitPostedMaxTopCalendar::effect($effect,$actor,$r,'mark');$this->maxTopUiReply($callback,'TOP уже включён этим действием. Откройте график, чтобы выбрать время.',[[GitPostedMaxApi::callbackButton('← Настройки','gp:settings')]]);return;}
        $effect=$this->withManagedMaxPostLock($actor,$r['id'],'manage',['draft'],function(array$p,array$c)use($actor,$r):array{
            $s=$this->maxTopCalendarLockedContext($actor,$p,$c,$r,'mark');
            if($s['o']['enabled']||$s['o']['revision']!==$r['revision'])throw new InvalidArgumentException('Версия TOP изменилась.');
            return$this->completeMaxUpdateEffectLocked('max_top_calendar_mark',function()use($actor,$r,$p,$c,$s):array{GitPostedMaxTopHold::setLocked($this->pdo,$this->config,$p,$c,true,$r['revision']);$o=GitPostedMaxTopHold::load($this->pdo,$p,true);return['actor_id'=>$actor,'post_id'=>$r['id'],'at'=>$r['at'],'zone_hash'=>$r['zone_hash'],'token'=>$r['token'],'duration_minutes'=>$s['duration'],'revision'=>$o['revision']];});
        });
        GitPostedMaxTopCalendar::effect($effect,$actor,$r,'mark');$this->showMaxTopCalendarPost($callback,$actor,$r['id'],$r['at'],$r['zone_hash']);
    }

    private function bookMaxTopCalendarPost(string $callback,string $actor,array $r):void
    {
        $effect=$this->currentMaxUpdateEffect('max_top_calendar_book');
        if($effect===null)$effect=$this->withManagedMaxPostLock($actor,$r['id'],'schedule',['draft'],function(array$p,array$c)use($actor,$r):array{
            $s=$this->maxTopCalendarLockedContext($actor,$p,$c,$r,'book');if(!$s['o']['enabled'])throw new InvalidArgumentException('TOP у черновика выключен.');$stored=GitPostedMaxTopCalendar::stored($r['at']);
            return$this->completeMaxUpdateEffectLocked('max_top_calendar_book',function()use($actor,$r,$p,$c,$s,$stored):array{
                GitPostedMaxTopHold::assertScheduleLocked($this->pdo,$c,$p,$stored);
                $q=$this->pdo->prepare("UPDATE posted_max_posts SET status='scheduled',scheduled_at=?,error_text=NULL WHERE id=? AND status='draft'");$q->execute([$stored,$r['id']]);if($q->rowCount()!==1)throw new InvalidArgumentException('Состояние черновика изменилось.');
                $data=$this->decodeJson((string)($s['u']['state_data']??''));
                if(($s['u']['state']??null)==='await_schedule'&&($data['post_id']??null)===$r['id']){
                    $q=$this->pdo->prepare("UPDATE posted_max_users SET state=NULL,state_data=NULL WHERE max_user_id=? AND state='await_schedule' AND CAST(CAST(state_data AS CHAR CHARACTER SET utf8mb4) AS BINARY) <=> CAST(? AS BINARY)");$q->execute([$actor,$s['u']['state_data']]);if($q->rowCount()!==1)throw new InvalidArgumentException('Текущий ввод изменился.');
                }
                return['actor_id'=>$actor,'post_id'=>$r['id'],'at'=>$r['at'],'zone_hash'=>$r['zone_hash'],'token'=>$r['token'],'duration_minutes'=>$s['duration'],'scheduled_at'=>$stored];
            });
        });
        GitPostedMaxTopCalendar::effect($effect,$actor,$r,'book');$z=new DateTimeZone((string)($this->user($actor)['user_timezone']??'Europe/Moscow'));
        $this->maxTopUiReply($callback,'Пост запланирован на '.GitPostedMaxTopView::moment($r['at'],$z).' · '.$z->getName().'.',[[GitPostedMaxApi::callbackButton('Контент-план','gp:plan')]]);
    }

    private function showMaxTopCalendarCustom(string $callback,string $actor,int $channel,string $day):void
    {
        $g=GitPostedMaxTopView::graph($this->maxTopPlacementGraph($actor,$channel,$day),$channel,$day);$v=GitPostedMaxTopView::class;$rows=[];$text='🕔 <b>ТОЧНОЕ ВРЕМЯ</b>'."\n\n".$v::escape($g['channel_title'])."\n".$day.' · '.$v::escape($g['timezone']);
        if(!$g['availability_known']||$g['truncated'])$text.="\n\nДоступность пока не подтверждена. Обновите график после проверки отправки.";
        else{$q=$this->pdo->prepare("SELECT p.id,p.text FROM posted_max_posts p JOIN posted_max_channels c ON c.id=p.channel_id LEFT JOIN posted_max_permissions g ON g.channel_id=c.id AND g.max_user_id=? WHERE p.channel_id=? AND p.author_max_user_id=? AND p.status='draft' AND (c.added_by=? OR g.can_schedule=1) ORDER BY p.id DESC LIMIT 20");$q->execute([$actor,$channel,$actor,$actor]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$p)$rows[]=[GitPostedMaxApi::callbackButton('#'.$p['id'].' '.$v::short((string)$p['text'],35),'gp:scheduletimes:'.$p['id'].':'.str_replace('-','',$day))];$text.="\n\nОбычный пост показан точкой, а не занятым часом. В планировании можно указать любую минуту, например 08:01 или 08:30. Система повторно проверит весь TOP. TOP включается отдельно. Выберите свой черновик:";if($rows===[])$text.="\nСначала создайте свой черновик в этом канале.";}
        $rows[]=[GitPostedMaxApi::callbackButton('← График','gp:top:graph:'.$channel.':'.$day)];$this->maxTopUiReply($callback,$text,$rows);
    }

    private function handleMaxTopCalendarControl(string $callback,string $actor,array $r):void
    {
        match($r['action']){
            'slot'=>$this->showMaxTopCalendarSlot($callback,$actor,$r['id'],$r['at'],$r['zone_hash']),
            'pick'=>$this->showMaxTopCalendarPost($callback,$actor,$r['id'],$r['at'],$r['zone_hash']),
            'mark'=>$this->markMaxTopCalendarPost($callback,$actor,$r),
            'book'=>$this->bookMaxTopCalendarPost($callback,$actor,$r),
            'custom'=>$this->showMaxTopCalendarCustom($callback,$actor,$r['id'],$r['day']),
            'advertiser'=>$this->maxTopUiReply($callback,'<pre>'.GitPostedMaxTopView::escape(PostedTelegramTopCalendarView::advertiser(GitPostedMaxTopView::graph($this->maxTopPlacementGraph($actor,$r['id'],$r['day']),$r['id'],$r['day']))).'</pre>',[[GitPostedMaxApi::callbackButton('← График','gp:top:graph:'.$r['id'].':'.$r['day'])]]),
            default=>throw new InvalidArgumentException('Некорректное действие календаря.'),
        };
    }
}
