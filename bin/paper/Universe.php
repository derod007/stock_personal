<?php
declare(strict_types=1);
final class PaperUniverse
{
    public static function summarize(array $journal):array
    {
        $s=$journal['state'];$rows=[];$first=null;$issues=[];$closed=0;$net=0.0;
        foreach($s['config']['symbols'] as $sym=>$sector)$rows[$sym]=['symbol'=>$sym,'sector'=>$sector,'evaluated'=>0,'ready'=>0,'orders'=>0,'fills'=>0,'closed'=>0,'wins'=>0,'net_pnl'=>0.0,'excluded'=>[], 'origins'=>[],'last_snapshot'=>null];
        foreach($journal['events'] as $e){$p=$e['payload'];$type=$e['type'];$sym=$p['symbol']??'';
            if($type==='account_started')$first=$e['recorded_at'];
            if(!isset($rows[$sym]))continue;$r=&$rows[$sym];
            if($type==='snapshot'){$r['evaluated']++;$r['ready']+=(int)!empty($p['plan']['ready']);$origin=$p['origin']??'unknown';$r['origins'][$origin]=($r['origins'][$origin]??0)+1;$r['last_snapshot']=['session'=>$p['session'],'quality'=>$p['quality']['status']??'unknown','reasons'=>$p['quality']['reasons']??[]];}
            if($type==='order')$r['orders']++;
            if($type==='fill')$r['fills']++;
            if($type==='exit'){$r['closed']++;$r['wins']+=(int)($p['net_pnl']>0);$r['net_pnl']+=$p['net_pnl'];$closed++;$net+=$p['net_pnl'];}
            if($type==='decision'){$reason=$p['reason']??'unknown';$r['excluded'][$reason]=($r['excluded'][$reason]??0)+1;}
            unset($r);
        }
        if($closed!==$s['closed_trades'] || abs($net-$s['realized'])>0.01)$issues[]='account_realized_reconciliation_failed';
        $sectors=[];
        foreach($rows as $r){$sector=$r['sector'];if(!isset($sectors[$sector]))$sectors[$sector]=['sector'=>$sector,'symbols'=>0,'evaluated'=>0,'ready'=>0,'orders'=>0,'fills'=>0,'closed'=>0,'wins'=>0,'net_pnl'=>0.0];
            $sectors[$sector]['symbols']++;foreach(['evaluated','ready','orders','fills','closed','wins','net_pnl'] as $k)$sectors[$sector][$k]+=$r[$k];
        }
        return ['config'=>$s['config'],'started_at'=>$first,'last_session'=>$s['last_session'],'mode'=>$s['mode'],'halted'=>!empty($s['halted']),
            'symbols'=>array_values($rows),'sectors'=>array_values($sectors),'issues'=>$issues,'closed'=>$closed,'realized'=>$net];
    }
    public static function telemetry(string $folder):array
    {
        $latest=null;$invalid=0;
        foreach(glob($folder.'/*.json')?:[] as $path){
            try{$r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($r)||!is_int($r['started_at']??null)||!isset($r['status'],$r['run_id']))throw new RuntimeException('Invalid log');
                if($latest===null || [$r['started_at'],$r['finished_at']??PHP_INT_MAX,$r['run_id']]>[$latest['started_at'],$latest['finished_at']??PHP_INT_MAX,$latest['run_id']])$latest=$r;
            }catch(Throwable $e){$invalid++;}
        }
        return ['latest'=>$latest,'invalid_files'=>$invalid];
    }
}
