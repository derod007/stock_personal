<?php
declare(strict_types=1);
// Read-only paths of saved closed trades, not a new exit simulator.
require __DIR__.'/../../bin/bootstrap.php';
require __DIR__.'/../../bin/paper/PatternReplay.php';
use ChartEntryLab\CandleClock;

function pathMetrics(array $middle, float $fill): array {
    $trigger = null; $touch = false; $maxDraw = null; $peak = null;
    foreach ($middle as $b) {
        // Only a high from an earlier session can precede this low.
        if ($trigger !== null) {
            $touch = $touch || $b['low'] <= $fill;
            $maxDraw = max($maxDraw ?? 0, max(0, (1 - $b['low'] / $peak) * 100));
        }
        if ($trigger === null && $b['high'] >= $fill * 1.03) $trigger = $b['available_at'];
        if ($trigger !== null) $peak = max($peak ?? $b['high'], $b['high']);
    }
    return ['middle_sessions'=>count($middle), 'rise_3pct_at'=>$trigger,
        'later_session_available'=>$maxDraw !== null,
        'later_low_at_or_below_fill'=>$touch,
        'max_prior_high_to_later_low_drawdown_pct'=>$maxDraw];
}
if (in_array('--self-test', $argv, true)) {
    $bar=fn($t,$h,$l)=>['available_at'=>$t,'high'=>$h,'low'=>$l];
    $cases=[
        [[],false,false],
        [[$bar(1,104,98)],false,false], // Same-day low cannot be placed after the high.
        [[$bar(1,104,98),$bar(2,105,101)],true,false],
        [[$bar(1,104,102),$bar(2,105,99)],true,true],
        [[$bar(1,102,99),$bar(2,102,98)],false,false]
    ];
    foreach($cases as [$bars,$eligible,$touch]) {
        $r=pathMetrics($bars,100);
        if($r['later_session_available']!==$eligible || $r['later_low_at_or_below_fill']!==$touch) throw new RuntimeException('Path test failed');
    }
    if(abs(pathMetrics([$bar(1,104,102),$bar(2,120,100)],100)['max_prior_high_to_later_low_drawdown_pct']-(1-100/104)*100)>1e-9) throw new RuntimeException('Used same-day high');
    echo "6 path tests passed\n"; exit;
}
$o=getopt('',['dataset-dir:']); $dir=rtrim($o['dataset-dir']??'','/\\');
$root=dirname(__DIR__);
$read=fn($p)=>json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
$baseline=$read($root.'/pattern-replay-20261008.json');
$problems=PaperHistoryResearch::verify($dir);
if($problems) throw new RuntimeException(implode('; ',$problems));
foreach(['dataset','universe'] as $key) if(hash_file('sha256',"$dir/$key.json")!==$baseline[$key.'_hash']) throw new RuntimeException('Wrong dataset');
$hashes=array_column($baseline['source_symbols'],'bars_sha256','symbol');
$sources=['prior'=>['pattern-compare-20251002.json','pullback_rows',67],'recent'=>['pullback-review-20261010.json','rows',70]];
$out=['kind'=>'closed_pullback_paths_v1','source_dataset'=>'kr-saved-scan-20261009','source_hashes'=>[],'periods'=>[]];
$cache=[];
foreach($sources as $period=>[$file,$key,$expected]) {
    $source=$read("$root/$file");
    if($source['strategy_fingerprint']!==$baseline['strategy_fingerprint']) throw new RuntimeException('Different strategies');
    $out['source_hashes'][$file]=hash_file('sha256',"$root/$file"); $rows=[];
    if(count($source[$key])!==$expected) throw new RuntimeException('Unexpected cohort');
    foreach($source[$key] as $r) {
        $symbol=$r['symbol'];
        if(!isset($cache[$symbol])) {
            $p="$dir/bars/$symbol.json";
            $expectedHash=$hashes[$symbol]??($read("$dir/status/$symbol.json")['bars_sha256']??null);
            if(hash_file('sha256',$p)!==$expectedHash) throw new RuntimeException('Bars mismatch '.$symbol);
            $bars=$read($p)['rows'];
            foreach($bars as &$b) $b['available_at']=CandleClock::closeTime($b,$symbol); unset($b);
            usort($bars,fn($a,$b)=>$a['available_at']<=>$b['available_at']); $cache[$symbol]=$bars; $out['source_bars_sha256'][$symbol]=$expectedHash;
        }
        $holding=array_values(array_filter($cache[$symbol],fn($b)=>$b['available_at']>=$r['entry_at'] && $b['available_at']<=$r['exit_at']));
        if(count($holding)!==$r['bars_held'] || $holding[0]['available_at']!==$r['entry_at'] || end($holding)['available_at']!==$r['exit_at']) throw new RuntimeException('Holding range mismatch '.$r['id']);
        $seen=[];
        foreach($holding as $b) {
            if(isset($seen[$b['available_at']]) || !empty($b['synthetic']) || ($b['is_complete']??true)===false || $b['low']<=0 || $b['low']>min($b['open'],$b['close']) || $b['high']<max($b['open'],$b['close']) || $b['high']<$b['low']) throw new RuntimeException('Invalid path candle');
            $seen[$b['available_at']]=true;
        }
        $middle=array_values(array_filter($holding,fn($b)=>$b['available_at']>$r['entry_at'] && $b['available_at']<$r['exit_at']));
        $gain=$middle?max(0,(max(array_column($middle,'high'))/$r['entry_fill']-1)*100):null;
        $stored=$r['prior_full_session_max_up_pct'];
        if(($gain===null)!==($stored===null) || ($gain!==null && abs($gain-$stored)>1e-8)) throw new RuntimeException('Saved path mismatch '.$r['id']);
        $rows[]=array_intersect_key($r,array_flip(['id','symbol','name','date','net_return_pct','exit_reason','entry_at','exit_at','entry_fill']))+pathMetrics($middle,(float)$r['entry_fill']);
    }
    $groups=[];
    foreach(['all','win','loss','stop'] as $group) {
        $g=array_values(array_filter($rows,fn($r)=>match($group){'win'=>$r['net_return_pct']>0,'loss'=>$r['net_return_pct']<=0,'stop'=>$r['exit_reason']==='stop',default=>true}));
        $rise=array_values(array_filter($g,fn($r)=>$r['rise_3pct_at']!==null));
        $eligible=array_values(array_filter($rise,fn($r)=>$r['later_session_available']));
        $touch=array_values(array_filter($eligible,fn($r)=>$r['later_low_at_or_below_fill']));
        $draw=array_column($eligible,'max_prior_high_to_later_low_drawdown_pct');sort($draw);$n=count($draw);
        $groups[$group]=['closed'=>count($g),'rise_3pct'=>count($rise),'later_session_available'=>count($eligible),'later_touch_fill'=>count($touch),'median_drawdown_pct'=>$n?($draw[intdiv($n-1,2)]+$draw[intdiv($n,2)])/2:null];
    }
    $out['periods'][$period]=['groups'=>$groups,'rows'=>$rows];
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
