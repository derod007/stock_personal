<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
use ChartEntryLab\Type1Breakout;

/** After-close replay. Times are bar END times, never assumed execution times. */
final class BreakoutRetest
{
    public static function evaluate(string $day,float $level,?float $dailyOpen,array $bars): array
    {
        $r=['status'=>'미확정','reason'=>null,'open'=>null,'recovery'=>null,'retest'=>null];
        $start=(new \DateTimeImmutable($day.' 09:00:00',new \DateTimeZone('Asia/Seoul')))->getTimestamp();
        $slots=[];
        foreach($bars as $b){
            $t=(int)($b['time']??0);$delta=$t-$start;
            if($delta<0||$delta>=390*60)continue;
            $slots[intdiv($delta,300)][]=$b;
        }
        for($i=0;$i<78;$i++){
            $slot=$slots[$i]??[];
            if(count($slot)!==1||($slot[0]['time']??0)!=$start+$i*300||!Tracker::valid($slot[0])){
                $r['reason']='누락·중복·비정규 또는 무효 봉: '.gmdate('H:i',$start+32400+$i*300);return $r;
            }
            $b=$slot[0];
            if((float)$b['volume']===0.0 && $b['open']==$b['high'] && $b['high']==$b['low'] && $b['low']==$b['close']){
                $r['reason']='거래 없는 단일가격 봉: '.gmdate('H:i',$start+32400+$i*300);return $r;
            }
            if($i===0){
                $r['open']=$b['open'];
                if($dailyOpen===null||$dailyOpen!=(float)$b['open']){$r['reason']='일봉 시가와 첫 5분봉 시가 대조 불가';return $r;}
                if($dailyOpen>=$level){$r['status']='대상 아님';return $r;}
            }
            $event=['kst'=>gmdate('Y-m-d H:i',$start+32400+($i+1)*300),'low'=>$b['low'],'close'=>$b['close']];
            if($r['recovery']===null){
                if($b['close']>$level)$r['recovery']=$event;
                elseif($i===5){$r['status']='미회복';return $r;}
            }elseif($b['low']<=$level){
                $r['retest']=$event;$r['status']=$b['close']>=$level?'유지':'재이탈';return $r;
            }
        }
        $r['status']='재시험 없음';return $r;
    }

    public static function record(string $folder,array $state,string $today,callable $fetch,?callable $fetchDaily=null): array
    {
        if($state['last_day']!==$today)return ['status'=>'당일 마감 기록 없음'];
        $current=Charts::snapshot($folder,$state,$today);
        $idx=array_search($today,$current['days'],true);
        if($idx===false||$idx===0)return ['status'=>'전 거래일 미확정'];
        $anchor=$current['days'][$idx-1];
        if(!isset($state['captures'][$anchor]))return ['status'=>'전 거래일 후보 기록 없음'];
        $dir=$folder.'/breakout-retest';
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('관찰 폴더 생성 실패');
        $lock=fopen($dir.'/write.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('관찰 기록 잠금 실패');
        try{
            $path=$dir.'/'.$anchor.'.json';
            if(is_file($path))return ['status'=>'이미 저장','anchor'=>$anchor];
            $pre=Charts::snapshot($folder,$state,$anchor);$rows=[];
            foreach($state['captures'][$anchor]['rows'] as $row){
                $c=$row['classification']??null;if(!($c['matches']??false)||$c['analysis_date']!==$anchor)continue;
                $s=$row['symbol'];$e=Type1Breakout::evidence($pre['prices'][$s]??[],$s,$c,$pre['as_of']);
                if(!$e['confirmed'])continue;
                try{
                    $d=Tracker::byDay($current['prices'][$s]??[],$s,$current['as_of'])[$today]??null;
                    $dailySource='당일 스냅샷';
                    if(!$d && $fetchDaily){$dailySource='마감 일봉 추가 조회';$d=Tracker::byDay($fetchDaily($s),$s,$current['as_of'])[$today]??null;}
                    $open=$d&&Tracker::valid($d)?(float)$d['open']:null;
                    if($open!==null&&$open>=$e['level'])$event=['status'=>'대상 아님','open'=>$open];
                    elseif($open===null)$event=['status'=>'미확정','reason'=>'당일 일봉 시가 없음'];
                    else $event=self::evaluate($today,$e['level'],$open,$fetch($s));
                    $event['daily_open']=$open;$event['daily_source']=$dailySource;
                }catch(\Throwable){$event=['status'=>'미확정','reason'=>'일봉 또는 5분봉 조회 실패'];}
                $rows[$s]=['level'=>$e['level']]+$event;
            }
            Store::atomic($path,['version'=>1,'anchor'=>$anchor,'day'=>$today,'recorded_at'=>date(DATE_ATOM),'snapshot_sha256'=>$state['captures'][$anchor]['snapshot_sha256'],'day_snapshot_sha256'=>$state['captures'][$today]['snapshot_sha256'],'rows'=>$rows]);
            return ['status'=>'저장','anchor'=>$anchor,'rows'=>count($rows)];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
