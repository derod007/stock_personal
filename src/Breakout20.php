<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Display-only daily breakout evidence; never changes a recommendation score. */
final class Breakout20
{
    private static function valid(array $b): bool
    {
        if(!empty($b['synthetic']))return false;
        foreach(['open','high','low','close'] as $k)if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k])||$b[$k]<=0)return false;
        return $b['low']<=min($b['open'],$b['close']) && $b['high']>=max($b['open'],$b['close']);
    }
    public static function analyze(array $bars,string $symbol,int $now): array
    {
        $out=['status'=>'판단 불가','reason'=>'현재 봉과 직전 20개 유효 일봉이 필요합니다.','level'=>null,'distance_pct'=>null,'day'=>null,'complete'=>false,'points'=>[],'previous_breakout'=>null];
        $tz=new \DateTimeZone(str_ends_with($symbol,'.KS')||str_ends_with($symbol,'.KQ')?'Asia/Seoul':'America/New_York');
        usort($bars,static fn($a,$b)=>($a['time']??0)<=>($b['time']??0));$rows=[];$seen=[];
        foreach($bars as $b){
            if(!isset($b['time'])||$b['time']>$now)continue;
            $day=(new \DateTimeImmutable('@'.$b['time']))->setTimezone($tz)->format('Y-m-d');
            if(isset($seen[$day]))return array_replace($out,['reason'=>'동일 날짜의 일봉이 중복됐습니다.']);
            $seen[$day]=true;$b['day']=$day;$rows[]=$b;
        }
        $n=count($rows);if(!$n)return $out;
        $out['day']=$rows[$n-1]['day'];
        $last=$rows[$n-1];$complete=($last['is_complete']??true)!==false && CandleClock::closeTime($last,$symbol)<=$now;$out['complete']=$complete;
        // Invalid rows are never removed to reach back farther than twenty sessions.
        $levels=[];
        for($i=20;$i<$n;$i++){
            $prior=array_slice($rows,$i-20,20);$ok=self::valid($rows[$i]);
            foreach($prior as $b)if(!self::valid($b)||($b['is_complete']??true)===false||CandleClock::closeTime($b,$symbol)>$now)$ok=false;
            $levels[$i]=$ok?max(array_column($prior,'high')):null;
        }
        foreach(array_slice($rows,-40,null,true) as $i=>$b){$out['points'][]=['day'=>$b['day'],'valid'=>self::valid($b),'open'=>$b['open']??null,'high'=>$b['high']??null,'low'=>$b['low']??null,'close'=>$b['close']??null,'level'=>$levels[$i]??null];}
        $level=$levels[$n-1]??null;if($level===null)return $out;
        $out['level']=$level;$out['distance_pct']=($last['close']/$level-1)*100;
        for($i=max(20,$n-21);$i<$n-1;$i++){
            if(($levels[$i]??null)!==null && ($rows[$i]['is_complete']??true)!==false && CandleClock::closeTime($rows[$i],$symbol)<=$now && $rows[$i]['close']>$levels[$i])
                $out['previous_breakout']=['day'=>$rows[$i]['day'],'level'=>$levels[$i]];
        }
        if($complete && $last['close']>$level){$out['status']='종가 돌파 확인';$out['reason']='해당 일봉 종가가 직전 20봉 최고가를 넘었습니다.';}
        elseif($out['previous_breakout'] && $last['close']<$out['previous_breakout']['level']){$out['status']='돌파 후 기준선 아래';$out['reason']='최근 20봉 안의 마지막 종가 돌파 당시 기준선 아래입니다. '.($complete?'마감 기준.':'진행 봉 기준·미확정.');}
        elseif($last['high']>$level){$out['status']=$complete?'장중 돌파 후 종가 미유지':'장중 돌파 · 종가 미확정';$out['reason']='고가는 기준선을 넘었지만 종가 돌파는 '.($complete?'유지하지 못했습니다.':'아직 확정되지 않았습니다.');}
        else{$out['status']='돌파 전';$out['reason']='기준선과 같은 가격은 돌파로 세지 않습니다.';}
        return $out;
    }
}
