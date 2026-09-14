<?php
declare(strict_types=1);
require __DIR__ . '/run.php';

use ChartEntryLab\PriceCandidate;
use ChartEntryLab\TrendPullback;
use ChartEntryLab\TrendContext;
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\TradeSimulator;

$pc = PriceCandidate::build(100, 102, 97, 110, 'test');
check($pc !== null && $pc['reward_risk']['low'] > $pc['reward_risk']['high'], 'Lower entry has better RR using same stop/target');
check(PriceCandidate::build(100, 102, 101, 110, 'test') === null, 'Invalid stop cannot produce a candidate');
check(PriceCandidate::build(100, 102, 97, 101, 'test') === null, 'Target must exceed whole candidate zone');
$wait = array_replace($live['plan'], ['ready' => false, 'candidate' => $pc, 'status' => 'context_wait']);
$projected = $engine->apply([], $wait);
check($projected['entry_zone']['low'] === 100.0 && !$projected['new_entry']['order_ready'], 'Weekly caution preserves displayed prices');
check((new TradeSimulator())->simulate($wait, [], 5)['status'] === 'no_signal', 'Visible candidate does not create a backtest order');
$projected = $engine->apply([], array_replace($wait, ['status' => 'stale_data']));
check(!$projected['new_entry']['available'] && $projected['entry_zone'] === null, 'Stale data cannot restore candidate prices');

$trendBars = [];
$start = new DateTimeImmutable('2025-10-06');
for ($i=0; $i<60; $i++) {
    $close = $i < 30 ? 50 + $i : 80 + ($i-30)*0.4;
    if ($i >= 55) { $close = [88,86,84.5,84,85.5][$i-55]; }
    $open = $i===59 ? 84.2 : $close - 0.2;
    $vol = $i>=56 && $i<=58 ? 500 : ($i===59 ? 1400 : 1000);
    $trendBars[] = bar($start->modify('+' . $i . ' weekdays')->format('Y-m-d'), $open, $close+0.8, min($open,$close)-0.4, $close, $vol);
}
$trendBars = CandleClock::completed($trendBars, '005930.KS', PHP_INT_MAX);
$pull = (new TrendPullback())->analyze($trendBars, ['atr14'=>2]);
check($pull['candidate'] !== null, 'Rising pullback produces a price zone');
check($pull['ready'], 'Volume contraction and subsequent recovery confirm pullback');
$noConfirm = array_slice($trendBars,0,-1);
$pending = (new TrendPullback())->analyze($noConfirm, ['atr14'=>2]);
check($pending['candidate'] !== null && !$pending['ready'], 'Pre-recovery pullback shows candidate without order');
$decline = array_reverse($trendBars);
check(!(new TrendPullback())->analyze($decline, ['atr14'=>2])['ready'], 'Declining structure is not a rising pullback');
$context = new TrendContext();
$fridayBars = array_slice($trendBars, 0, 55);
$fri = end($fridayBars)['available_at'];
$ctxFri = $context->analyze($fridayBars, '005930.KS', $fri);
$mondayBars = array_slice($trendBars, 0, 56);
$mon = end($mondayBars)['available_at'];
$ctxMon = $context->analyze($mondayBars, '005930.KS', $mon);
check($ctxFri['weekly_bars'] === $ctxMon['weekly_bars'], 'Unfinished Monday is not a completed weekly bar');
check($ctxMon['weekly_asof'] === $ctxFri['weekly_asof'], 'Weekly context cannot consume current Monday close');
echo 'V2 PASS ' . $checks . ' total checks' . PHP_EOL;
