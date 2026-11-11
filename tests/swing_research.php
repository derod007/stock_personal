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
sw($ok['retest_status'] === '확인' && $ok['entry'] === 36.0 && $ok['next_resistance'] === null && $ok['reward_risk'] === null
    && $ok['wait_reason'] === '다음 저항 미확정' && $ok['confirm_day'] === $ok['resistance_as_of_day']
    && $ok['confirm_day'] !== $ok['as_of_day'] && $ok['current_status'] === '새 저항' && $ok['current_resistance'] === 80.0
    && $ok['score'] === null, 'confirmation with too few bars stays unpriced');
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

function known(int $n, float $far, array $extra = []): array
{
    $bars = bars($n, array_replace([
        4 => ['high' => $far], 16 => ['high' => 30],
        20 => ['open' => 30, 'high' => 32, 'low' => 28, 'close' => 31],
        22 => ['open' => 30, 'high' => 33, 'low' => 29, 'close' => 31],
        24 => ['open' => 35, 'high' => 36, 'low' => 34, 'close' => 36],
    ], $extra));
    foreach ($bars as $i => $bar) {
        if ($i > 20 && !isset([22 => 1, 24 => 1][$i]) && !isset($extra[$i])) {
            $bars[$i] = array_replace($bar, ['open' => 31, 'high' => 32, 'low' => 30, 'close' => 31]);
        }
    }
    return $bars;
}

$base = known(25, 80);
$at = SwingResearch::diagnose($base, $symbol, $base[24]['available_at']);
$grown = known(32, 80, [28 => ['open' => 31, 'high' => 50, 'low' => 30, 'close' => 31]]);
$laterHigh = SwingResearch::diagnose($grown, $symbol, $grown[31]['available_at']);
sw($at['entry'] === 36.0 && $at['next_resistance'] === 80.0 && $at['reward_risk'] > 1
    && $laterHigh['entry'] === $at['entry'] && $laterHigh['next_resistance'] === $at['next_resistance']
    && $laterHigh['reward_risk'] === $at['reward_risk'] && $laterHigh['confirm_day'] === $at['confirm_day']
    && $laterHigh['resistance_as_of_day'] === $at['resistance_as_of_day'] && $laterHigh['resistance_as_of_day'] !== $laterHigh['as_of_day']
    && $laterHigh['current_status'] === '새 저항' && $laterHigh['current_resistance'] === 50.0, 'a later high does not rewrite the signal');
$same = known(32, 80);
$unchanged = SwingResearch::diagnose($same, $symbol, $same[31]['available_at']);
$goneBars = $base;
$goneBars[] = ['available_at' => $base[24]['available_at'] + 86400, 'open' => 80, 'high' => 82, 'low' => 79, 'close' => 81, 'volume' => 1000];
$gone = SwingResearch::diagnose($goneBars, $symbol, $goneBars[25]['available_at']);
foreach ([$unchanged, $laterHigh, $gone] as $now) {
    sw($now['entry'] === $at['entry'] && $now['next_resistance'] === 80.0 && $now['reward_risk'] === $at['reward_risk'], 'later bars keep the signal resistance');
}
sw($unchanged['current_resistance'] === 80.0 && $unchanged['current_status'] === '변화 없음', 'an unchanged 80 stays visible');
sw($laterHigh['current_resistance'] === 50.0 && $laterHigh['current_status'] === '새 저항', 'a changed resistance is separate from the signal');
sw($gone['current_resistance'] === null && $gone['current_status'] === '저항 소멸', 'a vanished resistance is not an unchanged one');
$samePage = SwingResearch::page($unchanged);
sw(str_contains($samePage, '<th scope="row">현재 저항</th><td>80</td>') && str_contains($samePage, '<th scope="row">현재 상태</th><td>변화 없음</td>'), 'the page shows 80 when the resistance did not change');

$brokeAfter = $base;
$brokeAfter[] = ['available_at' => $base[24]['available_at'] + 86400, 'open' => 30, 'high' => 30, 'low' => 27, 'close' => 28, 'volume' => 1000];
$still = SwingResearch::diagnose($brokeAfter, $symbol, $brokeAfter[25]['available_at']);
sw($still['retest_status'] === '확인' && $still['entry'] === $at['entry'] && $still['next_resistance'] === $at['next_resistance']
    && $still['reward_risk'] === $at['reward_risk'] && $still['current_resistance'] === 80.0
    && $still['current_status'] === '지지 이탈', 'a later support break stays in the current state');

$tight = known(25, 40);
$wait = SwingResearch::diagnose($tight, $symbol, $tight[24]['available_at']);
sw($wait['retest_status'] === '보상 여유 없음' && $wait['next_resistance'] === 40.0 && $wait['reward_risk'] <= 1
    && $wait['wait_reason'] === '손익비가 1 이하이면 대기합니다.', 'nearby resistance waits without using the old 1.5 rule');

$done = CandleClock::completed($held, $symbol, $asHeld);
sw(count($done) === count($held), 'completed bars match CandleClock');
$trend = (new TrendContext())->analyze($done, $symbol, $asHeld);
sw($ok['stock_trend'] === $trend['label'], 'stock trend matches the existing context');
$near = PaperTargetResearch::inspect($base, $symbol, $base[24]['available_at'], 36.0, 30.0, 1036.0);
sw($at['next_resistance'] === $near['nearest_upper_resistance']['price'], 'resistance matches the existing nearest high');

$html = SwingResearch::page(null);
sw(str_contains($html, '스윙 연구') && str_contains($html, '연구 단계') && str_contains($html, '가격 자료가 없습니다')
    && str_contains($html, '손익비가 1 이하이면 대기합니다') && str_contains($html, 'assets/app.css')
    && !str_contains($html, 'TEST.KS') && !str_contains($html, '임계값은 두지 않습니다'), 'empty page is research only');
$filled = SwingResearch::page($broke);
sw(str_contains($filled, '돌파만 발생') && str_contains($filled, 'TEST.KS') && str_contains($filled, '고정 예시') === false, 'diagnosis text is available to the page');
$split = SwingResearch::page($still);
sw(str_contains($split, '신호 당시') && str_contains($split, '현재 상태') && str_contains($split, '지지 이탈')
    && str_contains($split, '손익비가 1보다 큽니다'), 'the page keeps the signal beside the current state');
$waitPage = SwingResearch::page($wait);
sw(str_contains($waitPage, '손익비가 1 이하이면 대기합니다') && !str_contains($waitPage, '임계값은 두지 않습니다'), 'the page states the same one-to-one wait');
echo "SWING_OK\n";
