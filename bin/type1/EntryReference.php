<?php
declare(strict_types=1);
namespace ChartEntryLab\Type1Scan;

/** Read-only after-decision comparison; never fed back into candidate selection. */
final class EntryReference
{
    public static function nextOpen(string $day,string $symbol,array $e,float $close,?array $later,?string $error=null): array
    {
        $r=['day'=>null,'open'=>null,'gap_pct'=>null,'level_pct'=>null,'pivot_pct'=>null,'status'=>$error??'다음 거래일 저장 대기','source_day'=>$later['day']??null];
        if($error||!$later)return $r;
        $days=$later['days']??[];$i=array_search($day,$days,true);
        if($i===false||!isset($days[$i+1])){$r['status']='다음 거래일 달력 미확정';return $r;}
        $next=$days[$i+1];$r['day']=$next;
        try{$by=Tracker::byDay($later['prices'][$symbol]??[],$symbol,$later['as_of']);$b=$by[$next]??null;}
        catch(\Throwable){$b=null;}
        if(!$b||!Tracker::valid($b)){$r['status']='다음 거래일 유효 봉 없음';return $r;}
        $open=(float)$b['open'];$r['open']=$open;$r['gap_pct']=$close>0?($open/$close-1)*100:null;
        $level=$e['level']??null;
        if(is_numeric($level)&&$level>0){$r['level_pct']=($open/$level-1)*100;$r['status']=$open>$level?'기준선 위 출발':($open<$level?'기준선 아래 출발':'기준선에서 출발');}
        else $r['status']='돌파 기준선 미확정';
        if(isset($e['pivot_low'])&&$e['pivot_low']>0)$r['pivot_pct']=($e['pivot_low']/$open-1)*100;
        return $r;
    }
}
