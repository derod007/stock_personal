<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use ChartEntryLab\CandleClock;
use ChartEntryLab\RiskSizing;
use ChartEntryLab\TradeSimulator;
$o=getopt('',['root:','symbol:']);
if(empty($o['root']) || empty($o['symbol'])) throw new InvalidArgumentException('--root and --symbol required');
$root=rtrim($o['root'],'/');$symbol=$o['symbol'];
$summary=json_decode(file_get_contents("$root/$symbol-research.json"),true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents("$root/sources.json"),true,512,JSON_THROW_ON_ERROR);
$source=array_values(array_filter($manifest,fn($s)=>$s['symbol']===$symbol))[0]??null;
$file="$root/$symbol.json";
if(!$source || hash_file('sha256',$file)!==$source['sha256']) throw new RuntimeException('Input hash mismatch');
$bars=CandleClock::completed(json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR),$symbol,$summary['to']);
if(count($bars)!==$summary['bars']) throw new RuntimeException('Frozen completed bar count mismatch');
$index=array_flip(array_column($bars,'available_at'));
$ends=['development'=>$index[$summary['validation_from']],'validation'=>$index[$summary['holdout_from']],'holdout'=>count($bars)];
$modes=['structure','atr_floor_1','skip_under_1_atr'];
$orders=$busy=$counts=[];$all=[];
$fh=fopen("$root/$symbol-research.jsonl",'r');
while(($line=fgets($fh))!==false) {
    $row=json_decode($line,true,512,JSON_THROW_ON_ERROR);
    $g=$row['partition'];$asOf=$row['asof'];$i=$index[$asOf];$c=$row['candidate'];
    $status=$row['diagnostics']['final_status'];
    if($c===null || in_array($status,['stale_data','blocked','risk_blocked','context_wait'],true)) continue;
    $entry=(float)$c['mid'];$stop=(float)$c['stop'];$target=(float)$c['target'];
    if(($target-$entry)/($entry-$stop)<1.5) continue;
    $tr=[];for($j=$i-13;$j<=$i;$j++) {
        $b=$bars[$j];$pc=$bars[$j-1]['close'];
        $tr[]=max($b['high']-$b['low'],abs($b['high']-$pc),abs($b['low']-$pc));
    }
    $atr=round(array_sum($tr)/14,4);if($atr<=0) continue;
    $floorStop=min($stop,$entry-$atr);
    if($floorStop<=0) { $counts[$g]['common_invalid_stop']=($counts[$g]['common_invalid_stop']??0)+1;continue; }
    $baseSize=RiskSizing::calculate($entry,$stop);
    $floorSize=RiskSizing::calculate($entry,$floorStop);
    // Reject for ALL alternatives before position state, preserving a shared eligible candidate pool.
    if($baseSize===null || $floorSize===null) {
        $counts[$g]['common_capital_rejection']=($counts[$g]['common_capital_rejection']??0)+1;continue;
    }
    $counts[$g]['eligible_candidate_days']=($counts[$g]['eligible_candidate_days']??0)+1;
    foreach($modes as $mode) {
        if($asOf<=($busy[$g][$mode]??0)) continue;
        if($mode==='skip_under_1_atr' && $entry-$stop<$atr) continue;
        $chosenStop=$mode==='atr_floor_1'?$floorStop:$stop;
        $size=$mode==='atr_floor_1'?$floorSize:$baseSize;
        $plan=['ready'=>true,'entry'=>$entry,'stop'=>$chosenStop,'target'=>$target,
            'signal_at'=>$asOf,'order_valid_bars'=>3];
        $future=array_slice($bars,$i+1,min(32,$ends[$g]-$i-1));
        $t=(new TradeSimulator())->simulate($plan,$future,20);
        $t+=['symbol'=>$symbol,'partition'=>$g,'mode'=>$mode,'signal_at'=>$asOf,
            'planned_entry'=>$entry,'initial_stop'=>$chosenStop,'original_stop'=>$stop,'target'=>$target,
            'atr_at_signal'=>$atr,'size'=>$size];
        $t['net_r']=null;
        if($t['status']==='closed') {
            $pnl=$size['quantity']*($t['exit_fill']*0.999-$t['entry_fill']*1.001);
            $t['net_r']=$pnl/$size['risk_budget'];
        }
        $orders[$g][$mode][]=$t;$all[]=$t;
        $busy[$g][$mode]=$t['exit_at']??($t['filled']?$bars[$ends[$g]-1]['available_at']:
            $bars[min($ends[$g]-1,$i+3)]['available_at']);
    }
}
fclose($fh);
$metrics=[];
foreach(['development','validation','holdout'] as $g) foreach($modes as $mode) {
    $ts=$orders[$g][$mode]??[];$closed=array_values(array_filter($ts,fn($t)=>$t['status']==='closed'));
    $rs=array_column($closed,'net_r');$n=count($rs);
    $metrics[$g][$mode]=['orders'=>count($ts),'closed'=>$n,'mean_net_r'=>$n?array_sum($rs)/$n:null,
        'wins'=>count(array_filter($rs,fn($r)=>$r>0)),'status_counts'=>array_count_values(array_column($ts,'status')),
        'gap_losses_over_1r'=>count(array_filter($rs,fn($r)=>$r < -1.00001))];
}
$out=['symbol'=>$symbol,'metrics'=>$metrics,'candidate_counts'=>$counts,'trades'=>$all,
    'risk_budget'=>100,'capital_limit'=>10000,'fractional_research_units'=>true,
    'scope'=>'Per-order risk units, not portfolio return. Intrabar/gap execution uses existing simulator.'];
file_put_contents("$root/$symbol-risk.json",json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['symbol'=>$symbol,'metrics'=>$metrics]).PHP_EOL;
