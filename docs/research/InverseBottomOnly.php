<?php
declare(strict_types=1);
require_once __DIR__.'/IndependentTradeDiagnostic.php';
use ChartEntryLab\NoramuTopPattern;
final class InverseBottomOnly {
    public static function score(string $status):int {return match($status){'confirmed'=>12,'warning'=>5,default=>0};}
    public static function valid(array $b):bool {
        foreach(['open','high','low','close','volume'] as $k)if(!is_numeric($b[$k]??null)||!is_finite((float)$b[$k])||$b[$k]<=0)return false;
        return ($b['is_complete']??false) && $b['low']<=min($b['open'],$b['close']) && $b['high']>=max($b['open'],$b['close']);
    }
    public static function evaluate(array $raw,string $day,array $events):array {
        $past=array_values(array_filter($raw,fn($b)=>$b['day']<=$day));
        $valid=array_values(array_filter($past,[self::class,'valid']));$why=[];
        if(!$valid||end($valid)['day']!==$day)$why[]='missing_anchor';
        if(count($valid)<65)$why[]='under65';
        $window=array_slice($valid,-65);
        foreach($past as $b)if(!self::valid($b)&&strtotime($day)-strtotime($b['day'])<=90*86400){$why[]='invalid_recent90';break;}
        foreach($events as $d)if($window&&$d>$window[0]['day']&&$d<=$day){$why[]='reference_inside65';break;}
        if($why)return ['eligible'=>false,'reasons'=>$why];
        $p=(new NoramuTopPattern())->analyze($window);
        return ['eligible'=>true,'score'=>self::score($p['inverse_bottom_status']),'status'=>$p['inverse_bottom_status'],
            'phase'=>$p['inverse_bottom_phase'],'bottom'=>$p['inverse_bottom'],'top_status_ignored'=>$p['top_status'],'close'=>end($window)['close']];
    }
    public static function path(array $raw,array $days,int $i,array $events):array {
        $bm=array_column($raw,null,'day');$out=[];$bars=[];$base=null;
        for($k=1;$k<=20;$k++){
            $d=$days[$i+$k];$b=$bm[$d]??null;
            if(in_array($d,$events,true)||!$b||!self::valid($b))return $out+['complete'=>false,'unknown_at'=>$d];
            $base??=$b['open'];$bars[]=$b;
            if(in_array($k,[1,5,10,20],true))$out['close'.$k.'_pct']=($b['close']/$base-1)*100;
        }
        return $out+['complete'=>true,'max20_pct'=>(max(array_column($bars,'high'))/$base-1)*100,'min20_pct'=>(min(array_column($bars,'low'))/$base-1)*100];
    }
}
