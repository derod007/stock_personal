<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bin/bootstrap.php';

use ChartEntryLab\BreakoutRetest;
use ChartEntryLab\CandleClock;
use ChartEntryLab\ChartPlanEngine;
use ChartEntryLab\EntryBacktester;
use ChartEntryLab\TradeSimulator;

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) { throw new RuntimeException($message); }
}
function bar(string $day, float $open, float $high, float $low, float $close, int $volume = 1000): array {
    $t = new DateTimeImmutable($day . ' 09:00:00', new DateTimeZone('Asia/Seoul'));
    return ['time' => $t->getTimestamp(), 'time_kst' => $t->format('Y-m-d H:i:s'),
        'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $volume];
}
function forward(array $prices): array {
    $out = [];
    foreach ($prices as $i => $p) {
        $b = bar('2026-06-' . sprintf('%02d', $i + 1), ...$p);
        $b['available_at'] = $i + 2;
        $out[] = $b;
    }
    return $out;
}

$b = bar('2026-06-01', 100, 105, 99, 104);
$noon = (new DateTimeImmutable('2026-06-01T12:00:00+09:00'))->getTimestamp();
check(CandleClock::completed([$b], '005930.KS', $noon) === [], 'Intraday post must not see final daily OHLC');
check(count(CandleClock::completed([$b], '005930.KS', $noon + 4 * 3600)) === 1, 'Closed KR session available');
$bad = $b; $bad['synthetic'] = true;
check(CandleClock::completed([$bad], '005930.KS', $noon + 86400) === [], 'Synthetic bars excluded');
$us = $b; $us['time'] = strtotime('2026-07-01T09:30:00-04:00');
check(CandleClock::closeTime($us, 'MU') === strtotime('2026-07-01T16:00:00-04:00'), 'US daylight saving exchange close');
$us['time'] = strtotime('2026-01-05T09:30:00-05:00');
check(CandleClock::closeTime($us, 'MU') === strtotime('2026-01-05T16:00:00-05:00'), 'US winter exchange close');

$plan = ['ready' => true, 'entry' => 100, 'stop' => 95, 'target' => 110, 'signal_at' => 1, 'order_valid_bars' => 3];
$sim = new TradeSimulator(0, 0);
$r = $sim->simulate($plan, forward([[101, 105, 101, 103], [102, 104, 101, 103], [102, 105, 101, 104]]), 5);
check($r['status'] === 'unfilled' && $r['net_return_pct'] === null, 'Unreached limit must not produce a return');
$r = $sim->simulate($plan, forward([[100, 104, 94, 98], [98, 120, 97, 119]]), 5);
check($r['first_exit'] === 'stop' && $r['net_return_pct'] === -5.0 && !$r['hit_target'], 'Stop ends trade despite later rally');
$r = $sim->simulate($plan, forward([[100, 103, 99, 102], [92, 97, 90, 94]]), 5);
check($r['exit_fill'] === 92.0, 'Stop gap exits at open, not stop');
$r = $sim->simulate($plan, forward([[100, 103, 99, 102], [102, 111, 94, 105]]), 5);
check($r['first_exit'] === 'stop' && $r['ambiguous_bar'], 'Stop-first same-bar ambiguity');
$r = $sim->simulate($plan, forward([[100, 103, 99, 102], [112, 113, 94, 96]]), 5);
check($r['first_exit'] === 'target', 'Target opening gap occurs before later intrabar low');
$r = $sim->simulate($plan, forward([[100, 103, 99, 102]]), 5);
check($r['status'] === 'incomplete' && $r['net_return_pct'] === null, 'Incomplete holding horizon excluded');
$r = $sim->simulate(array_replace($plan, ['stop' => 105]), forward([[100, 103, 99, 102]]), 5);
check($r['status'] === 'invalid_levels', 'Reject stop above entry');
$r = (new TradeSimulator(10, 5))->simulate($plan, forward([[100, 103, 99, 102], [102, 103, 101, 102]]), 2);
check($r['net_return_pct'] < 2 && $r['first_exit'] === 'time', 'Time exit includes fees and slippage');
$r = $sim->simulate($plan, forward([[101, 112, 99, 102]]), 5);
check(!$r['filled'] && $r['ambiguous_bar'], 'Unknown target-before-fill chronology is not an assumed win');

// Price path: established box 94..100, volume breakout, quiet retest, confirming close.
$fixture = [];
$date = new DateTimeImmutable('2026-01-05');
for ($i = 0; $i < 45; $i++) {
    $day = $date->modify('+' . $i . ' weekdays')->format('Y-m-d');
    $fixture[] = bar($day, 97, $i % 5 === 0 ? 100 : 98, $i % 5 === 2 ? 94 : 96, 97);
}
$next = (new DateTimeImmutable(substr($fixture[44]['time_kst'], 0, 10)));
$fixture[] = bar($next->modify('+1 weekday')->format('Y-m-d'), 99.5, 101.5, 99, 101, 2000);
$fixture[] = bar($next->modify('+2 weekdays')->format('Y-m-d'), 101, 101.2, 99.8, 100.5, 700);
$fixture[] = bar($next->modify('+3 weekdays')->format('Y-m-d'), 100.7, 102.2, 100.4, 101.8, 1200);
$fixture = CandleClock::completed($fixture, '005930.KS', PHP_INT_MAX);
$pattern = new BreakoutRetest();
check($pattern->analyze(array_slice($fixture, 0, 46))['status'] === 'await_retest', 'Breakout alone is not entry');
check($pattern->analyze(array_slice($fixture, 0, 47))['status'] === 'await_confirmation', 'Retest alone is not entry');
$p = $pattern->analyze($fixture);
check($p['ready'] && $p['stop'] < $p['entry'] && $p['entry'] < $p['target'], 'Confirmed retest gives ordered plan');
check($p['reward_risk'] >= 1.5, 'Ready plan passes minimum reward/risk');
$overhead = $fixture; $overhead[0]['high'] = 103;
check($pattern->analyze($overhead)['status'] === 'rejected_rr', 'Nearby overhead resistance rejects poor reward/risk');
$broken = array_slice($fixture, 0, 47); $broken[46]['low'] = 94; $broken[46]['close'] = 95;
check($pattern->analyze($broken)['status'] === 'invalidated', 'Failed retest invalidates setup');
$engine = new ChartPlanEngine();
$asOf = $fixture[47]['available_at'];
$live = $engine->analyze($fixture, '005930.KS', $asOf);
$future = bar('2026-09-01', 200, 250, 150, 230);
$withFuture = $engine->analyze([...$fixture, $future], '005930.KS', $asOf);
check($live === $withFuture, 'Future candles cannot change historical signal');
$replay = (new EntryBacktester())->evaluate(['id' => 'test', 'learning_use' => 'full',
    'posted_at_kst' => date(DATE_ATOM, $asOf), 'entry_price' => 999, 'stop_price' => 2000], $fixture, '005930.KS');
check($replay['plan']['entry'] === $live['plan']['entry'] && $replay['plan']['stop'] === $live['plan']['stop'], 'Live and replay share plan, author prices never replace it');
$projection = $engine->apply(['action' => 'add_on_pullback', 'score' => 100], array_replace($live['plan'], ['ready' => false, 'status' => 'await_confirmation']));
check(!$projection['new_entry']['available'] && !$projection['new_entry']['buy_now'] && $projection['entry_zone'] === null, 'High score or author bias cannot bypass confirmation');
check($engine->analyze($fixture, '005930.KS', $asOf + 5 * 86400)['plan']['status'] === 'stale_data', 'Stale data blocks new orders');
$agg = (new EntryBacktester())->aggregate([$r, ['status' => 'incomplete', 'complete' => false]]);
check($agg['n'] === 0, 'Incomplete/unfilled trades excluded from performance');
echo 'PASS ' . $checks . ' checks' . PHP_EOL;
