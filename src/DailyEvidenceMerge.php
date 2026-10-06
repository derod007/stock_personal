<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** A prior provisional candle needs newly collected OHLCV, not just elapsed wall time. */
final class DailyEvidenceMerge
{
    public static function merge(array $rows,array $quotes,string $symbol,?int $fetchedAt,int $asOf):array
    {
        $tz=new \DateTimeZone('Asia/Seoul');$byDay=[];
        foreach($rows as $r)$byDay[(new \DateTimeImmutable('@'.(int)$r['time']))->setTimezone($tz)->format('Y-m-d')]=$r;
        $latest=$byDay?max(array_keys($byDay)):'';
        foreach($quotes as $day=>$q){
            $stamp=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$day.' 15:30:00',$tz);
            if(!$stamp||$stamp->format('Y-m-d')!==$day)continue;
            $close=$stamp->getTimestamp();
            foreach(['open','high','low','close','volume'] as $k)if(!isset($q[$k])||!is_numeric($q[$k])||!is_finite((float)$q[$k])||($k==='volume'?$q[$k]<0:$q[$k]<=0))continue 2;
            if($q['low']>min($q['open'],$q['close'])||$q['high']<max($q['open'],$q['close']))continue;
            $old=$byDay[$day]??null;
            if($old!==null&&!empty($old['is_complete'])&&empty($old['synthetic']))continue;
            if($old===null&&$day<=$latest)continue;
            $complete=$fetchedAt!==null&&$fetchedAt>=$close&&$fetchedAt<=$asOf&&$close<=$asOf;
            $r=$old??['time'=>$close,'time_kst'=>$day.' 15:30:00'];
            foreach(['open','high','low','close','volume'] as $k)$r[$k]=(float)$q[$k];
            $r['synthetic']=false;$r['is_complete']=$complete;
            $r['ohlcv_source']='naver_daily';$r['ohlcv_fetched_at']=$fetchedAt;
            $byDay[$day]=$r;
        }
        ksort($byDay,SORT_STRING);return array_values($byDay);
    }
}
