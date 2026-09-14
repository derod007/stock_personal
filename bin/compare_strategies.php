<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\LegacyChartPlanEngine;
use ChartEntryLab\EntryBacktester;
use ChartEntryLab\TradeSimulator;

$o = getopt('', ['file:', 'symbol:', 'split:']);
if (!isset($o['file'], $o['symbol'])) { throw new InvalidArgumentException('--file and --symbol required'); }
$bars = CandleClock::completed(json_decode(file_get_contents($o['file']), true, 512, JSON_THROW_ON_ERROR), $o['symbol'], time());
if (count($bars) < 120) { throw new RuntimeException('Need 120+ completed daily bars'); }
$split = isset($o['split']) ? strtotime($o['split']) : $bars[(int)(count($bars)*0.7)]['available_at'];
$output = ['symbol'=>$o['symbol'], 'bars'=>count($bars), 'from'=>$bars[0]['available_at'],
    'to'=>end($bars)['available_at'], 'split'=>$split, 'results'=>[]];
foreach (['v1', 'v2_without_context', 'v2'] as $mode) {
    $engine = $mode === 'v1' ? new LegacyChartPlanEngine() : new ChartPlanEngine();
    $groups = ['development'=>[], 'holdout'=>[]];
    $visible = ['development'=>0, 'holdout'=>0];
    $patterns = [];
    $busy = 0;
    foreach ($bars as $i=>$bar) {
        if ($i<39) { continue; }
        $asOf=$bar['available_at'];
        $group=$asOf<$split ? 'development' : 'holdout';
        $past=array_slice($bars,0,$i+1);
        $analysis=$mode==='v1' ? $engine->analyze($past,$o['symbol'],$asOf)
            : $engine->analyze($past,$o['symbol'],$asOf,'account1',$mode==='v2');
        $p=$analysis['plan'];
        $visible[$group] += ($mode==='v1' ? !empty($p['ready']) : !empty($p['candidate_available'])) ? 1 : 0;
        if (!$p['ready'] || $asOf <= $busy) { continue; }
        $forward=array_slice($bars,$i+1);
        if ($group==='development') { $forward=array_values(array_filter($forward,fn($b)=>$b['available_at']<$split)); }
        $t=(new TradeSimulator())->simulate($p,$forward,10);
        $groups[$group][]=$t;
        $key=$p['pattern']??$p['version'];
        $patterns[$group][$key][]=$t;
        $end=min(count($bars)-1,$i+($p['order_valid_bars']??3));
        $busy=$t['exit_at']??($t['filled'] ? end($bars)['available_at'] : $bars[$end]['available_at']);
        if ($group==='development' && $busy>=$split) { $busy=$split-1; }
    }
    $agg=new EntryBacktester();
    $byPattern=[];
    foreach($patterns as $g=>$ps) { foreach($ps as $p=>$ts) { $byPattern[$g][$p]=$agg->aggregate($ts); } }
    $output['results'][$mode]=['candidate_days'=>$visible, 'trades'=>array_map(fn($g)=>$agg->aggregate($g),$groups), 'by_pattern'=>$byPattern];
}
echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
