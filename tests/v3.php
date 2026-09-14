<?php
declare(strict_types=1);
require __DIR__.'/v2.php';
use ChartEntryLab\ResearchMetrics;
use ChartEntryLab\TradeSimulator;

$m=ResearchMetrics::summarize([
 ['status'=>'closed','exit_at'=>1,'net_return_pct'=>10],
 ['status'=>'closed','exit_at'=>2,'net_return_pct'=>-10],
 ['status'=>'unfilled']]);
check($m['closed']===2 && $m['orders']===3, 'Unfilled orders excluded from expectancy');
check(abs($m['expectancy_pct'])<0.0001 && abs($m['closed_trade_drawdown_pct']-10)<0.0001, 'Expectancy and closed equity drawdown');
check(ResearchMetrics::summarize([])['expectancy_pct']===null, 'No trades is unknown, not zero return');
$sim=new TradeSimulator(0,0);
$plan=['ready'=>true,'entry'=>100,'stop'=>90,'target'=>120,'signal_at'=>1,'order_valid_bars'=>3,
 'exit_mode'=>'trailing','trail_distance'=>5];
$future=[
 ['available_at'=>2,'open'=>100,'high'=>110,'low'=>98,'close'=>108],
 ['available_at'=>3,'open'=>106,'high'=>109,'low'=>102,'close'=>104]];
$t=$sim->simulate($plan,$future,20);
check($t['exit_at']===3 && $t['exit_fill']===103.0, 'Trailing stop uses previous completed close, not same-bar high');
$fixed=$plan; unset($fixed['exit_mode']);
check($sim->simulate($fixed,$future,20)['status']==='incomplete', 'Fixed stop unaffected by trailing option');
$gap=$future; $gap[1]['open']=99; $gap[1]['low']=97;
check($sim->simulate($plan,$gap,20)['exit_fill']===99.0, 'Trailing gap exits at open below stop');
$late=$future; $late[0]['available_at']=1;
check($sim->simulate($plan,$late,20)['entry_at']===null, 'Signal candle cannot fill order');
$analysis=$engine->analyze($trendBars,'005930.KS',end($trendBars)['available_at']);
check(isset($analysis['plan']['diagnostics']['patterns']['trend_pullback']), 'Both raw pattern statuses retained before final guards');
echo 'V3 PASS '.$checks.' total checks'.PHP_EOL;
