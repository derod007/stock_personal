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
            $r['confirmed']=($classification['matches']??false) && $r['status']==='종가 돌파 확인';
            return $r;
        }catch(\Throwable){return $unknown;}
    }
}
