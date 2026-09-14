<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\TradeSimulator;
use ChartEntryLab\ResearchMetrics;

$o=getopt('', ['file:', 'symbol:', 'out:']);
foreach(['file','symbol','out'] as $k) { if(empty($o[$k])) throw new InvalidArgumentException('--'.$k.' required'); }
$bars=CandleClock::completed(json_decode(file_get_contents($o['file']),true,512,JSON_THROW_ON_ERROR),$o['symbol'],time());
$n=count($bars);
if($n<400) throw new RuntimeException('400 completed bars required');
$cut1=(int)floor($n*0.6); $cut2=(int)floor($n*0.8);
$modes=['confirmed_fixed','confirmed_trailing','candidate_fixed','candidate_trailing','candidate_recovery_fixed'];
$trades=$counts=$observations=[]; $busy=[];
$engine=new ChartPlanEngine();
$log=fopen($o['out'].'.jsonl','w');
if(!$log) throw new RuntimeException('Cannot write audit log');
for($i=119;$i<$n;$i++) {
    $g=$i<$cut1?'development':($i<$cut2?'validation':'holdout');
    $end=$g==='development'?$cut1:($g==='validation'?$cut2:$n);
    $asOf=$bars[$i]['available_at'];
    $a=$engine->analyze(array_slice($bars,0,$i+1),$o['symbol'],$asOf);
    $p=$a['plan']; $candidate=$p['candidate'];
    foreach($p['diagnostics']['patterns'] as $pattern=>$raw) {
        $key=$pattern.':'.$raw['status'];
        $counts[$g][$key]=($counts[$g][$key]??0)+1;
        foreach(($raw['gates']??[]) as $gate=>$passed) {
            $gateKey=$pattern.':gate:'.$gate.':'.($passed?'pass':'fail');
            $counts[$g][$gateKey]=($counts[$g][$gateKey]??0)+1;
        }
    }
    $key='final:'.$p['status']; $counts[$g][$key]=($counts[$g][$key]??0)+1;
    $future=array_slice($bars,$i+1,min(32,$end-$i-1));
    // Descriptive forward labels only, never fed back into decisions or selection.
    $outcome=null;
    if($candidate!==null && !$p['ready'] && count($future)>=10) {
        $ten=array_slice($future,0,10); $ref=$bars[$i]['close'];
        $outcome=['close_return_10_pct'=>($ten[9]['close']/$ref-1)*100,
            'mfe_10_pct'=>(max(array_column($ten,'high'))/$ref-1)*100,
            'mae_10_pct'=>(min(array_column($ten,'low'))/$ref-1)*100];
        $observations[$g][$p['status']][]=$outcome;
    }
    $row=['symbol'=>$o['symbol'],'asof'=>$asOf,'partition'=>$g,'diagnostics'=>$p['diagnostics'],
        'context'=>$p['context'],'candidate'=>$candidate,'excluded_forward_label'=>$outcome,'orders'=>[]];
    foreach($modes as $mode) {
        if($asOf<=($busy[$g][$mode]??0)) { $row['orders'][$mode]=['status'=>'busy']; continue; }
        $order=$p;
        if($mode==='candidate_recovery_fixed' && !\ChartEntryLab\RecoveryGate::passes(array_slice($bars,0,$i+1))) {
            $row['orders'][$mode]=['status'=>'recovery_gate_failed']; continue;
        }
        if(str_starts_with($mode,'candidate')) {
            // Explicit experimental early-entry alternative; production readiness is untouched.
            if($candidate===null || in_array($p['status'],['stale_data','blocked','risk_blocked','context_wait'],true)) continue;
            $entry=$candidate['mid']; $stop=$candidate['stop']; $target=$candidate['target'];
            if(($target-$entry)/($entry-$stop)<1.5) continue;
            $order=array_replace($p,['ready'=>true,'entry'=>$entry,'stop'=>$stop,'target'=>$target,
                'signal_at'=>$asOf,'order_valid_bars'=>3]);
        }
        if(!$order['ready']) continue;
        if(str_ends_with($mode,'trailing')) {
            $order['exit_mode']='trailing'; $order['trail_distance']=2*(float)$a['features']['atr14'];
            if($order['trail_distance']<=0) continue;
        }
        $t=(new TradeSimulator())->simulate($order,$future,20);
        $t['signal_at']=$asOf;
        $t['pattern']=$order['pattern'];
        $trades[$g][$mode][]=$t;
        $row['orders'][$mode]=$t;
        $busy[$g][$mode]=$t['exit_at']??($t['filled']?$bars[$end-1]['available_at']:
            $bars[min($end-1,$i+3)]['available_at']);
    }
    fwrite($log,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}
fclose($log);
$metrics=[];
foreach(['development','validation','holdout'] as $g) foreach($modes as $mode) {
    $metrics[$g][$mode]=ResearchMetrics::summarize($trades[$g][$mode]??[]);
}
$labels=[];
foreach($observations as $g=>$statuses) foreach($statuses as $status=>$rows) {
    $labels[$g][$status]=['n'=>count($rows)];
    foreach(['close_return_10_pct','mfe_10_pct','mae_10_pct'] as $k) $labels[$g][$status]['avg_'.$k]=array_sum(array_column($rows,$k))/count($rows);
}
$result=['symbol'=>$o['symbol'],'bars'=>$n,'from'=>$bars[0]['available_at'],'to'=>$bars[$n-1]['available_at'],
    'validation_from'=>$bars[$cut1]['available_at'],'holdout_from'=>$bars[$cut2]['available_at'],
    'metrics'=>$metrics,'stage_counts'=>$counts,'excluded_labels'=>$labels,
    'trades'=>$trades,'drawdown_scope'=>'per-symbol closed-trade equity; excludes unrealized drawdown',
    'label_scope'=>'overlapping candidate-days, descriptive only; not executable returns'];
file_put_contents($o['out'].'.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo json_encode(['symbol'=>$o['symbol'],'bars'=>$n,'metrics'=>$metrics],JSON_UNESCAPED_UNICODE)."\n";
