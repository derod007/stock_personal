<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Evidence tied to the SAME completed daily bar as the frozen Type1 classification. */
final class Type1Breakout
{
    public static function evidence(array $bars,string $symbol,array $classification,int $asOf): array
    {
        $unknown=['status'=>'판단 불가','reason'=>'유형1과 동일 기준일의 돌파 근거를 확인하지 못했습니다.','day'=>null,'level'=>null,'distance_pct'=>null,'complete'=>false,'confirmed'=>false];
        try {
            $closed=[];
            foreach($bars as $b){
                if(!isset($b['time']) && isset($b['time_kst']))$b['time']=(new \DateTimeImmutable($b['time_kst'],new \DateTimeZone('Asia/Seoul')))->getTimestamp();
                if(!isset($b['time']))return $unknown;
                if(($b['is_complete']??true)===false || CandleClock::closeTime($b,$symbol)>$asOf)continue;
                $day=(new \DateTimeImmutable('@'.$b['time']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
                if($day<=($classification['analysis_date']??''))$closed[]=$b;
            }
            $r=Breakout20::analyze($closed,$symbol,$asOf);
            if($r['day']!==($classification['analysis_date']??null)||!$r['complete'])return $unknown;
            unset($r['points']);
            usort($closed,static fn($a,$b)=>$a['time']<=>$b['time']);
            $r['pivot_low']=null;$r['pivot_day']=null;$r['pivot_distance_pct']=null;
            // Latest strict 2-left/2-right low within the last 20 completed bars.
            $window=array_slice($closed,-20);
            for($i=count($window)-3;$i>=2;$i--){
                $five=array_slice($window,$i-2,5);$valid=true;
                foreach($five as $b)if(!empty($b['synthetic'])||!isset($b['low'],$b['high'],$b['open'],$b['close'])||!is_numeric($b['low'])||!is_finite((float)$b['low'])||$b['low']<=0||$b['low']>min($b['open'],$b['close'])||$b['high']<max($b['open'],$b['close']))$valid=false;
                if(!$valid)continue;
                $low=$window[$i]['low'];
                if($low<min($window[$i-2]['low'],$window[$i-1]['low'],$window[$i+1]['low'],$window[$i+2]['low'])){
                    $r['pivot_low']=$low;
                    $r['pivot_day']=(new \DateTimeImmutable('@'.$window[$i]['time']))->setTimezone(new \DateTimeZone('Asia/Seoul'))->format('Y-m-d');
                    $close=end($closed)['close'];$r['pivot_distance_pct']=($low/$close-1)*100;break;
                }
            }
            $r['confirmed']=($classification['matches']??false) && $r['status']==='종가 돌파 확인';
            return $r;
        }catch(\Throwable){return $unknown;}
    }
}
