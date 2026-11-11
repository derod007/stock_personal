<?php

declare(strict_types=1);

require __DIR__ . '/../bin/bootstrap.php';
require __DIR__ . '/../bin/paper/TargetResearch.php';

use ChartEntryLab\CandleClock;
use ChartEntryLab\SwingResearch;
use ChartEntryLab\TrendContext;

function sw(bool $ok, string $why): void
{
    if (!$ok) {
        throw new RuntimeException($why);
    }
    echo "SWING_PASS $why\n";
}

function bars(int $n, array $over): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $bar = ['available_at' => 1700000000 + $i * 86400, 'open' => 10, 'high' => 10, 'low' => 9, 'close' => 10, 'volume' => 1000];
        foreach ($over[$i] ?? [] as $key => $value) {
            $bar[$key] = $value;
        }
        $out[] = $bar;
    }
    return $out;
}

$symbol = 'TEST.KS';
$few = SwingResearch::diagnose(bars(4, []), $symbol, 1700000000 + 3 * 86400);
sw($few['retest_status'] === '자료 부족' && $few['score'] === null && $few['market_context'] === '자료 없음', 'insufficient bars stay undiagnosed');

$only = bars(15, [4 => ['high' => 40], 10 => ['high' => 30], 14 => ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31]]);
$asOnly = $only[14]['available_at'];
$broke = SwingResearch::diagnose($only, $symbol, $asOnly);
sw($broke['retest_status'] === '돌파만 발생' && $broke['breakout_level'] === 30.0 && $broke['invalidation'] === 30.0, 'breakout alone waits for a retest');

$held = bars(30, [
    4 => ['high' => 80], 10 => ['high' => 30],
    14 => ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31],
    16 => ['open' => 30, 'high' => 33, 'low' => 29, 'close' => 31],
    18 => ['open' => 35, 'high' => 36, 'low' => 34, 'close' => 36],
]);
foreach ($held as $i => $bar) {
    if ($i > 14 && !isset([16 => 1, 18 => 1][$i])) {
        $held[$i] = array_replace($bar, ['open' => 31, 'high' => 32, 'low' => 30, 'close' => 31]);
    }
}
$asHeld = $held[29]['available_at'];
$ok = SwingResearch::diagnose($held, $symbol, $asHeld);
sw($ok['retest_status'] === '확인' && $ok['next_resistance'] === 80.0 && $ok['reward_risk'] > 1 && $ok['score'] === null, 'retest confirmation keeps the next high and no score');
$later = $held;
$later[] = ['available_at' => $asHeld + 86400, 'open' => 200, 'high' => 220, 'low' => 190, 'close' => 210, 'volume' => 1000];
sw(SwingResearch::diagnose($later, $symbol, $asHeld) === $ok, 'bars after the judgment do not change it');

$lost = bars(17, [
    10 => ['high' => 30],
    14 => ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31],
    15 => ['open' => 30, 'high' => 30, 'low' => 27, 'close' => 28],
]);
$down = SwingResearch::diagnose($lost, $symbol, $lost[16]['available_at']);
sw($down['retest_status'] === '지지 이탈' && $down['invalidation'] === 30.0, 'close back through the level invalidates');

$tight = bars(30, [
    4 => ['high' => 40], 10 => ['high' => 30],
    14 => ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31],
    16 => ['open' => 30, 'high' => 33, 'low' => 29, 'close' => 31],
    18 => ['open' => 35, 'high' => 36, 'low' => 34, 'close' => 36],
]);
foreach ($tight as $i => $bar) {
    if ($i > 14 && !isset([16 => 1, 18 => 1][$i])) {
        $tight[$i] = array_replace($bar, ['open' => 31, 'high' => 32, 'low' => 30, 'close' => 31]);
    }
}
$wait = SwingResearch::diagnose($tight, $symbol, $tight[29]['available_at']);
sw($wait['retest_status'] === '보상 여유 없음' && $wait['next_resistance'] === 40.0 && $wait['reward_risk'] <= 1, 'nearby resistance waits without using the old 1.5 rule');

$done = CandleClock::completed($held, $symbol, $asHeld);
sw(count($done) === count($held), 'completed bars match CandleClock');
$trend = (new TrendContext())->analyze($done, $symbol, $asHeld);
sw($ok['stock_trend'] === $trend['label'], 'stock trend matches the existing context');
$near = PaperTargetResearch::inspect($held, $symbol, $asHeld, 36.0, 30.0, 1036.0);
sw($ok['next_resistance'] === $near['nearest_upper_resistance']['price'], 'resistance matches the existing nearest high');

$html = SwingResearch::page(null);
sw(str_contains($html, '스윙 연구') && str_contains($html, '연구 단계') && str_contains($html, '가격 자료가 없습니다')
    && str_contains($html, 'assets/app.css') && !str_contains($html, 'TEST.KS'), 'empty page is research only');
$filled = SwingResearch::page($broke);
sw(str_contains($filled, '돌파만 발생') && str_contains($filled, 'TEST.KS') && str_contains($filled, '고정 예시') === false, 'diagnosis text is available to the page');
echo "SWING_OK\n";
