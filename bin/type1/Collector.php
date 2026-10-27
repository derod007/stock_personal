<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;
use ChartEntryLab\NaverHistoricalClose;
final class Collector
{
    public static function calendar(string $kospi,string $kosdaq,string $today): array
    {
        $a=array_keys(NaverHistoricalClose::parse($kospi,'KOSPI'));$b=array_keys(NaverHistoricalClose::parse($kosdaq,'KOSDAQ'));
        $a=array_values(array_filter($a,static fn($d)=>$d<=$today));sort($a);$b=array_values(array_filter($b,static fn($d)=>$d<=$today));sort($b);
        if(!$a||$a!==$b)throw new \RuntimeException('지수 거래일 불일치');return $a;
    }
    public static function capture(array $state,callable $leaders,callable $prices,callable $calendar,int $asOf): ?array
    {
        $local=(new \DateTimeImmutable('@'.$asOf))->setTimezone(new \DateTimeZone('Asia/Seoul'));$today=$local->format('Y-m-d');
        if($local->format('H:i')<'16:00'||$local->format('N')>=6||$state['last_day']===$today)return null;
        $days=$calendar($today);if(end($days)!==$today)return null;
        if($state['days']){$first=$days[0];$overlap=array_values(array_filter($state['days'],static fn($d)=>$d>=$first));
            if(!$overlap||array_slice($days,0,count($overlap))!==$overlap)throw new \RuntimeException('Calendar continuity unavailable');
            $days=array_merge(array_values(array_filter($state['days'],static fn($d)=>$d<$first)),$days);}
        $list=$leaders();if(count($list)!==100)throw new \RuntimeException('TOP100 incomplete');$symbols=array_column($list,'yahoo');
        foreach($state['trades'] as $t)if($t['status']!=='closed')$symbols[]=$t['symbol'];$quotes=[];$errors=[];
        foreach(array_unique($symbols) as $s){try{$quotes[$s]=$prices($s);}catch(\Throwable $e){$quotes[$s]=[];$errors[$s]='일봉 조회 실패';}}
        return ['schema'=>1,'day'=>$today,'as_of'=>$asOf,'days'=>$days,'leaders'=>$list,'prices'=>$quotes,'errors'=>$errors,
            'ranking_session_verified'=>false,'ranking_source'=>'KrAmountLeadersClient after-close fetch; provider session absent',
            'calendar_source'=>'Naver KOSPI/KOSDAQ matching daily dates; exchange calendar not independently verified'];
    }
}
