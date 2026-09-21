<?php
declare(strict_types=1);
require_once __DIR__.'/EntryRelaxation.php';
final class PaperEntryGates
{
    public static function summarize(array $events,string $origin='forward'): array
    {
        if(!in_array($origin,['forward','catchup','replay'],true))throw new InvalidArgumentException('Invalid origin');
        $r=['origin'=>$origin,'snapshots'=>0,'quality_blocked'=>0,'ready'=>0,'statuses'=>[],
            'pattern_statuses'=>[],'gates'=>[],'volume_only'=>0,'incomplete_gates'=>0,
            'decisions'=>[],'orders'=>0,'fills'=>0,'cancellations'=>[]];
        $keys=[];
        foreach($events as $e)if($e['type']==='snapshot' && ($e['payload']['origin']??'')===$origin){
            $p=$e['payload'];$keys[$p['symbol'].':'.$p['session']]=true;$r['snapshots']++;
            if(empty($p['quality']['can_simulate']))$r['quality_blocked']++;
            $plan=$p['plan'];$r['ready']+=(int)!empty($plan['ready']);
            self::inc($r['statuses'],$plan['status']??'unknown');
            foreach($plan['diagnostics']['patterns']??[] as $name=>$pattern)self::inc($r['pattern_statuses'],$name.':'.($pattern['status']??'unknown'));
            $g=$plan['diagnostics']['patterns']['trend_pullback']['gates']??[];
            $complete=true;
            foreach(PaperEntryRelaxation::GATES as $name){
                $r['gates'][$name]??=['pass'=>0,'fail'=>0,'unknown'=>0,'only_failure'=>0];
                $value=$g[$name]??null;
                $r['gates'][$name][$value===true?'pass':($value===false?'fail':'unknown')]++;
                if(!is_bool($value))$complete=false;
            }
            if(!$complete)$r['incomplete_gates']++;
            else {
                $failed=array_keys(array_filter($g,fn($v)=>$v===false));
                if(count($failed)===1 && isset($r['gates'][$failed[0]]))$r['gates'][$failed[0]]['only_failure']++;
            }
            if(PaperEntryRelaxation::onlyVolumeMissing($plan))$r['volume_only']++;
        }
        foreach($events as $e){$p=$e['payload'];if(!isset($keys[($p['symbol']??'').':'.($p['session']??0)]))continue;
            if($e['type']==='decision')self::inc($r['decisions'],$p['reason']??'unknown');
            if($e['type']==='order')$r['orders']++;
            if($e['type']==='fill')$r['fills']++;
            if($e['type']==='order_cancelled')self::inc($r['cancellations'],$p['reason']??'unknown');
        }
        arsort($r['statuses']);arsort($r['decisions']);
        return $r;
    }
    private static function inc(array &$a,string $key):void{$a[$key]=($a[$key]??0)+1;}
}
