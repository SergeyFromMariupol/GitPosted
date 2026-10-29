<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){http_response_code(404);exit;}

/** Readonly presentation. Existing graph/free_intervals remains the availability authority. */
final class PostedTelegramTopCalendarView
{
    public static function zoneFingerprint(string $timezone):string
    {new DateTimeZone($timezone);return substr(hash('sha256',$timezone),0,8);}

    public static function epoch(mixed $raw):int
    {
        if((!is_int($raw)&&!is_string($raw))||preg_match('/\A[1-9][0-9]{0,11}\z/D',(string)$raw)!==1||(string)(int)$raw!==(string)$raw||(int)$raw>253402300799)throw new InvalidArgumentException('Invalid calendar time.');
        return(int)$raw;
    }

    public static function offered(array $graph,int $at,?int $now=null):bool
    {
        $now??=time();
        if(!$graph['availability_known']||$graph['truncated']||$at<$graph['day_start']||$at>=$graph['day_end']||$at<$now+60)return false;
        $when=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone($graph['timezone']));
        if($when->format('Y-m-d')!==$graph['day']||$when->format('i:s')!=='00:00')return false;
        foreach($graph['free_intervals']as$window)if($at>=$window['begin']&&$at<$window['end'])return true;
        return false;
    }

    /** Real wall-clock hour starts; a DST repeat has distinct epochs and UTC offsets. */
    public static function hours(array $graph,?int $now=null):array
    {
        $now??=time();$zone=new DateTimeZone($graph['timezone']);$start=$graph['day_start'];$end=$graph['day_end'];
        if(!is_int($start)||!is_int($end)||$end<=$start||$end-$start>26*3600)throw new InvalidArgumentException('Unsupported calendar day.');
        $items=[];$counts=[];
        for($at=$start;$at<$end;$at+=60){
            $date=(new DateTimeImmutable('@'.$at))->setTimezone($zone);
            if($date->format('Y-m-d')!==$graph['day']||$date->format('i:s')!=='00:00')continue;
            $label=$date->format('H:i');$counts[$label]=($counts[$label]??0)+1;
            $state=$at<$now+60?'past':(!$graph['availability_known']||$graph['truncated']?'unknown':(self::offered($graph,$at,$now)?'free':'busy'));
            $items[]=['at'=>$at,'label'=>$label,'offset'=>$date->format('P'),'state'=>$state];
        }
        if(count($items)>26)throw new InvalidArgumentException('Unbounded calendar hours.');
        foreach($items as&$item)if($counts[$item['label']]>1)$item['label'].=' '.$item['offset'];unset($item);
        return$items;
    }

    public static function range(int $begin,int $end,DateTimeZone $zone):string
    {
        if($end<=$begin)throw new InvalidArgumentException('Invalid TOP interval.');
        $a=(new DateTimeImmutable('@'.$begin))->setTimezone($zone);$b=(new DateTimeImmutable('@'.$end))->setTimezone($zone);
        $format=$a->format('s')==='00'&&$b->format('s')==='00'?'H:i':'H:i:s';
        $offsets=$a->format('P')!==$b->format('P')||self::ambiguous($begin,$zone)||self::ambiguous($end,$zone);
        return$a->format($format).($offsets?' UTC'.$a->format('P'):'').'–'.$b->format($a->format('Y-m-d')===$b->format('Y-m-d')?$format:($a->format('Y')===$b->format('Y')?'d.m ':'d.m.Y ').$format).($offsets?' UTC'.$b->format('P'):'');
    }

    /** Both occurrences of a repeated wall-clock time require an explicit offset. */
    private static function ambiguous(int $at,DateTimeZone $zone):bool
    {
        $rows=$zone->getTransitions($at-86400,$at+86400);if(!is_array($rows))return false;$previous=null;
        foreach($rows as$row){$next=$row['offset'];if($previous!==null&&$next<$previous){$repeat=$previous-$next;if($at>=$row['ts']-$repeat&&$at<$row['ts']+$repeat)return true;}$previous=$next;}
        return false;
    }

    /** Exact point label, including an offset when its wall-clock occurrence repeats. */
    public static function at(int $at,DateTimeZone $zone):string
    {$d=(new DateTimeImmutable('@'.$at))->setTimezone($zone);return$d->format($d->format('s')==='00'?'H:i':'H:i:s').(self::ambiguous($at,$zone)?' UTC'.$d->format('P'):'');}

    /** Actual busy intervals, exact points and complete offered TOP alternatives, ordered by timestamp. */
    public static function daily(array $graph,?int $now=null):array
    {
        $rows=[];$sequence=0;
        foreach($graph['intervals']as$item)if($item['end']>$graph['day_start']&&$item['begin']<$graph['day_end'])$rows[]=['kind'=>$item['kind']==='uncertain'?'uncertain':'top','at'=>$item['begin'],'end'=>$item['end'],'label'=>$item['label'],'sequence'=>$sequence++];
        foreach($graph['points']as$item)if($item['at']>=$graph['day_start']&&$item['at']<$graph['day_end'])$rows[]=['kind'=>'point','at'=>$item['at'],'end'=>null,'label'=>$item['label'],'sequence'=>$sequence++];
        foreach(self::hours($graph,$now)as$hour)if($hour['state']==='free')$rows[]=['kind'=>'free','at'=>$hour['at'],'end'=>$hour['at']+$graph['duration_minutes']*60,'label'=>'','sequence'=>$sequence++];
        usort($rows,static fn(array$a,array$b):int=>$a['at']<=>$b['at']?:$a['sequence']<=>$b['sequence']);return$rows;
    }

    /** Copyable summary deliberately excludes event labels, message URLs and post contents. */
    public static function advertiser(array $graph,?int $now=null):string
    {
        $text="ГРАФИК TOP\n".$graph['channel_title']."\n".$graph['day'].' · '.$graph['timezone']."\nTOP: ".$graph['duration_minutes']." мин\n";
        if(!$graph['availability_known']||$graph['truncated'])return$text."\nСвободное время пока не подтверждено. Уточните доступность перед размещением.";
        $free=array_values(array_filter(self::hours($graph,$now),static fn(array$v):bool=>$v['state']==='free'));
        if($free===[])return$text."\nСвободных часовых начал TOP на выбранную дату нет.";
        $zone=new DateTimeZone($graph['timezone']);$text.="\nСвободные часовые варианты TOP:\n";
        foreach($free as$item)$text.='• '.self::range($item['at'],$item['at']+$graph['duration_minutes']*60,$zone)." свободно\n";
        return rtrim($text)."\n\nДля точного времени можно выбрать другую минуту в планировании. Доступность проверяется повторно. Конец TOP-интервала свободен для следующего размещения.";
    }
}
