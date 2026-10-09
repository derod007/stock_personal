<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
require __DIR__.'/../bin/paper/PullbackCompare.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$near=fn(float $got,float $want,int $dp)=>abs(round($got,$dp)-$want)<(10**-$dp)/2;

$review=json_decode(file_get_contents(__DIR__.'/../docs/pullback-review-20261010.json'),true,512,JSON_THROW_ON_ERROR);
$rows=[];
foreach($review['rows'] as $r){$r['period']=$r['period']==='first_6m'?'first_half':'second_half';$rows[]=$r;}
$p=PaperPullbackCompare::pullbackReport($rows);$a=$p['all'];$f=$p['first_half'];$s=$p['second_half'];
check($a['n']===70&&$a['wins']===24&&$near($a['mean_net_return_pct'],0.89,2)&&$near($a['median_net_return_pct'],-5.63,2),'70 closed trades keep the published mean and median');
check($f['n']===48&&$f['wins']===19&&$near($f['mean_net_return_pct'],3.55,2)&&$near($f['median_net_return_pct'],-4.62,2),'first half of the published review');
check($s['n']===22&&$s['wins']===5&&$near($s['mean_net_return_pct'],-4.91,2)&&$near($s['median_net_return_pct'],-11.14,2),'second half of the published review');
check($near($a['mean_win_pct'],22.55,2)&&$near($a['mean_loss_pct'],-10.41,2)&&$a['exits']['stop']===44&&$a['exits']['target']===20&&$a['exits']['time']===6,'wins, losses and exit reasons');
check($a['stop_buckets']['le_10']['n']===39&&$near($a['stop_buckets']['le_10']['mean_net_return_pct'],3.13,2)
    &&$a['stop_buckets']['gt_10_le_15']['n']===18&&$near($a['stop_buckets']['gt_10_le_15']['mean_net_return_pct'],1.14,2)
    &&$a['stop_buckets']['gt_15']['n']===13&&$near($a['stop_buckets']['gt_15']['mean_net_return_pct'],-6.19,2),'fixed 10% and 15% stop-width buckets');
check($f['stop_buckets']['le_10']['n']===31&&$s['stop_buckets']['gt_15']['n']===11,'bucket counts by half');
check($near($f['median_signal_risk_pct'],8.77,2)&&$near($s['median_signal_risk_pct'],15.26,2)
    &&$near($f['median_signal_atr_pct'],6.83,2)&&$near($s['median_signal_atr_pct'],12.94,2),'stop width and ATR medians');
check($near($f['mean_r'],0.468,3)&&$near($s['mean_r'],-0.221,3),'R is net return divided by the fill stop distance');
check($a['paths']['stops']===44&&$a['paths']['early_stops_within_3_bars']===12&&$a['paths']['rose_3pct_then_stop']===24&&$a['paths']['gap_stops']===7,'stop paths use the published definitions');
check($a['overlap']['clusters']===66&&$near($a['overlap']['equal_weight_mean_pct'],-0.10,2),'overlapping holdings collapse to 66 equal-weighted clusters');

$base=json_decode(file_get_contents(__DIR__.'/../docs/pattern-replay-20261008.json'),true,512,JSON_THROW_ON_ERROR);
$by=[];
foreach($base['selected_baseline_events'] as $e){
    if($e['outcome']['status']!=='closed')continue;
    $by[$e['pattern']][]=['period'=>$e['date']<'2026-04-08'?'first_half':'second_half','net_return_pct'=>$e['outcome']['net_return_pct'],'exit_reason'=>$e['outcome']['first_exit']];
}
$want=['breakout_retest'=>[9,-4.02,6,6.65],'trend_pullback'=>[48,3.55,22,-4.91],'trend_recovery'=>[8,5.86,7,11.63]];
foreach($want as $name=>$w){
    $g=$by[$name];$h=fn($p)=>PaperPullbackCompare::performance(array_values(array_filter($g,fn($r)=>$r['period']===$p)));
    $first=$h('first_half');$second=$h('second_half');
    check($first['n']===$w[0]&&$near($first['mean_net_return_pct'],$w[1],2)&&$second['n']===$w[2]&&$near($second['mean_net_return_pct'],$w[3],2),"published half means for $name");
}
$files=file_get_contents(__DIR__.'/../config/paper-strategy-files.json');
check(!str_contains($files,'PullbackCompare'),'comparison code is outside the strategy fingerprint');
echo "OK\n";
