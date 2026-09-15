<?php
declare(strict_types=1);
require __DIR__.'/v4.php';
use ChartEntryLab\RiskSizing;
use ChartEntryLab\TradeSimulator;
$a=RiskSizing::calculate(100,98,100,10000);
$b=RiskSizing::calculate(100,96,100,10000);
check($b['quantity']<$a['quantity'],'Wider stop reduces quantity');
check(abs($a['modeled_risk']-100)<1e-8 && abs($b['modeled_risk']-100)<1e-8,'Same fee/slippage-inclusive risk budget');
check(RiskSizing::calculate(100,99.99,100,10000)===null,'Capital limit rejects rather than lowering risk silently');
$plan=['ready'=>true,'entry'=>100,'stop'=>98,'target'=>110,'signal_at'=>1];
$t=(new TradeSimulator())->simulate($plan,[['available_at'=>2,'open'=>101,'high'=>105,'low'=>97,'close'=>99]],20);
$pnl=$a['quantity']*($t['exit_fill']*0.999-$t['entry_fill']*1.001);
check(abs($pnl+100)<0.001,'Modeled no-gap stop matches budget');
$t=(new TradeSimulator())->simulate($plan,[
 ['available_at'=>2,'open'=>101,'high'=>105,'low'=>99,'close'=>101],
 ['available_at'=>3,'open'=>95,'high'=>97,'low'=>94,'close'=>96]],20);
$pnl=$a['quantity']*($t['exit_fill']*0.999-$t['entry_fill']*1.001);
check($pnl < -100,'Gap loss may exceed budget');
echo 'V6 PASS '.$checks.' total checks'.PHP_EOL;
