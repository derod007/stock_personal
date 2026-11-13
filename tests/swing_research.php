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
$dir = sys_get_temp_dir() . '/swing-stored-' . getmypid();
mkdir($dir);
$source = known(32, 80, [28 => ['open' => 31, 'high' => 50, 'low' => 30, 'close' => 31]]);
$stored = [];
foreach ($source as $bar) {
    $stored[] = ['time' => $bar['available_at'], 'open' => $bar['open'], 'high' => $bar['high'], 'low' => $bar['low'], 'close' => $bar['close'], 'volume' => 1000];
}
$path = $dir . '/TEST.KS_2y_1d_closed_v2.json';
file_put_contents($path, json_encode($stored));
$before = hash_file('sha256', $path);
$day = (new DateTimeImmutable('@' . $source[24]['available_at']))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
$asOf = (new DateTimeImmutable($day . ' 15:30:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
$signal = SwingResearch::diagnose($stored, 'TEST.KS', $asOf);
$shown = SwingResearch::diagnose($stored, 'TEST.KS', $asOf, CandleClock::closeTime($stored[31], 'TEST.KS'));
sw($shown['entry'] === $signal['entry'] && $shown['next_resistance'] === $signal['next_resistance'] && $shown['reward_risk'] === $signal['reward_risk']
    && $shown['entry'] === 36.0 && $shown['next_resistance'] === 80.0 && $shown['current_resistance'] === 50.0
    && $shown['as_of_day'] === $day && $shown['current_as_of_day'] !== $shown['as_of_day'], 'bars after the judgment stay out of the signal');
$saved = SwingResearch::view($dir, ['symbol' => 'TEST.KS', 'day' => $day]);
$rr = (string) round($shown['reward_risk'], 3);
sw(hash_file('sha256', $path) === $before
    && str_contains($saved, '<th scope="row">진입 참고가</th><td>' . $shown['entry'] . '</td>')
    && str_contains($saved, '<th scope="row">신호 당시 저항</th><td>' . $shown['next_resistance'] . '</td>')
    && str_contains($saved, '<th scope="row">신호 당시 손익비</th><td>' . $rr . '</td>')
    && str_contains($saved, '<th scope="row">현재 자료 기준일</th><td>' . $shown['current_as_of_day'] . '</td>'), 'the page matches the stored bars');
file_put_contents($dir . '/BAD.KS_2y_1d_closed_v2.json', '{');
$bad = SwingResearch::view($dir, ['symbol' => 'BAD.KS', 'day' => $day]);
sw(str_contains($bad, '손상') && !str_contains($bad, '<td>36</td>'), 'a damaged file is shown and not repaired');
$missing = SwingResearch::view($dir, ['symbol' => 'ZZZ.KS', 'day' => $day]);
sw(str_contains($missing, '저장 일봉이 없습니다'), 'a missing symbol is not filled in');
$oddBars = $stored;
$oddBars[3]['high'] = 1;
file_put_contents($dir . '/ODD.KS_2y_1d_closed_v2.json', json_encode($oddBars));
$oddPath = $dir . '/ODD.KS_2y_1d_closed_v2.json';
$oddBefore = hash_file('sha256', $oddPath);
$odd = SwingResearch::view($dir, ['symbol' => 'ODD.KS', 'day' => $day]);
sw(str_contains($odd, '완료로 쓰지 않은 봉이 1개') && hash_file('sha256', $oddPath) === $oddBefore, 'an invalid bar stays invalid');
function seoulDay(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
}
$pack = $stored;
$fromDay = seoulDay($source[0]['available_at']);
$endDay = seoulDay($source[31]['available_at']);
$play = SwingResearch::replay($pack, 'TEST.KS', $fromDay, $endDay);
sw($play['error'] === null && str_contains($play['basis'], '현재 캐시') && str_contains($play['basis'], '판단 기록이 아닙니다'), 'replay is labeled as a cache recalculation');
foreach ($play['days'] as $row) {
    if ($row['diagnosis'] === null) {
        continue;
    }
    $cut = (new DateTimeImmutable($row['day'] . ' 15:30:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
    sw($row['diagnosis'] === SwingResearch::diagnose($pack, 'TEST.KS', $cut), 'replay day ' . $row['day'] . ' matches the single diagnosis');
}
$level30 = array_values(array_filter($play['signals'], static fn (array $s): bool => $s['breakout_level'] == 30));
sw(count($level30) === 1 && $level30[0]['entry'] === 36.0 && $level30[0]['resistance'] === 80.0 && $level30[0]['retest_day'] !== null, 'one persisting signal is counted once');
$stamp = $source[31]['available_at'];
do {
    $stamp += 86400;
    $nextDay = seoulDay($stamp);
    $week = (int) (new DateTimeImmutable($nextDay . ' 12:00:00', new DateTimeZone('Asia/Seoul')))->format('N');
} while ($week >= 6);
$more = $pack;
$more[] = ['time' => $stamp, 'open' => 55, 'high' => 60, 'low' => 54, 'close' => 60, 'volume' => 1000];
$laterPlay = SwingResearch::replay($more, 'TEST.KS', $fromDay, $nextDay);
$kept = array_values(array_filter($laterPlay['signals'], static fn (array $s): bool => $s['breakout_level'] == 30));
$second = array_values(array_filter($laterPlay['signals'], static fn (array $s): bool => $s['breakout_level'] == 50));
sw(count($kept) === 1 && count($second) === 1 && $kept[0]['confirm_day'] === $level30[0]['confirm_day']
    && $kept[0]['entry'] === 36.0 && $kept[0]['resistance'] === 80.0 && $kept[0]['reward_risk'] === $level30[0]['reward_risk'], 'a later breakout does not rewrite the earlier signal');
$future = $pack;
$future[] = ['time' => $stamp, 'open' => 200, 'high' => 220, 'low' => 190, 'close' => 210, 'volume' => 1000];
$unchangedPlay = SwingResearch::replay($future, 'TEST.KS', $fromDay, $endDay);
sw($unchangedPlay['days'] === $play['days'] && $unchangedPlay['signals'] === $play['signals'], 'a bar after the replay does not change earlier days');
$gap = $pack;
$gapDay = null;
foreach ($source as $i => $bar) {
    $candidate = seoulDay($bar['available_at']);
    $week = (int) (new DateTimeImmutable($candidate . ' 12:00:00', new DateTimeZone('Asia/Seoul')))->format('N');
    if ($week < 6 && $i >= 8 && $i < 12) {
        $gap[$i]['high'] = 1;
        $gapDay = $candidate;
        break;
    }
}
$gapped = SwingResearch::replay($gap, 'TEST.KS', $fromDay, $endDay);
$miss = array_values(array_filter($gapped['days'], static fn (array $row): bool => $row['day'] === $gapDay));
sw($gapDay !== null && ($miss[0]['status'] ?? '') === '미확정' && $miss[0]['diagnosis'] === null, 'a weekday without a bar stays undetermined');
$stamp2 = $stamp;
do {
    $stamp2 += 86400;
    $breakDay = seoulDay($stamp2);
    $week = (int) (new DateTimeImmutable($breakDay . ' 12:00:00', new DateTimeZone('Asia/Seoul')))->format('N');
} while ($week >= 6);
$both = $more;
$both[] = ['time' => $stamp2, 'open' => 28, 'high' => 29, 'low' => 24, 'close' => 25, 'volume' => 1000];
$brokenPlay = SwingResearch::replay($both, 'TEST.KS', $fromDay, $breakDay);
$old = array_values(array_filter($brokenPlay['signals'], static fn (array $s): bool => $s['breakout_level'] == 30));
$newer = array_values(array_filter($brokenPlay['signals'], static fn (array $s): bool => $s['breakout_level'] == 50));
sw(count($old) === 1 && count($newer) === 1 && $old[0]['break_day'] === $breakDay && $newer[0]['break_day'] === $breakDay
    && $old[0]['break_price'] === 30.0 && $newer[0]['break_price'] === 50.0
    && $old[0]['confirm_day'] === $level30[0]['confirm_day'] && $old[0]['entry'] === 36.0
    && $old[0]['resistance'] === 80.0 && $old[0]['reward_risk'] === $level30[0]['reward_risk'], 'a later close breaks both levels without rewriting the first signal');
$replayPage = SwingResearch::view($dir, ['symbol' => 'TEST.KS', 'from' => $fromDay, 'to' => $endDay, 'mode' => 'replay']);
sw(hash_file('sha256', $path) === $before && str_contains($replayPage, '실행: 재생') && str_contains($replayPage, '현재 캐시로 과거')
    && str_contains($replayPage, '판단 기록이 아닙니다') && str_contains($replayPage, '가격 경로 관찰')
    && str_contains($replayPage, '수익률이 아닙니다') && str_contains($replayPage, '<td>36</td>') && str_contains($replayPage, '<td>80</td>')
    && !str_contains($replayPage, '실행: 진단'), 'the replay page keeps the frozen signal');
$back = SwingResearch::view($dir, ['symbol' => 'TEST.KS', 'day' => $day, 'from' => $fromDay, 'to' => $endDay, 'mode' => 'diagnose']);
sw(str_contains($back, '실행: 진단') && str_contains($back, '신호 당시') && str_contains($back, '<th scope="row">진입 참고가</th><td>36</td>')
    && !str_contains($back, '날짜순 재생'), 'diagnosis mode returns after a replay range is still filled in');
function seoulBar(string $day, float $open, float $high, float $low, float $close): array
{
    $stamp = (new DateTimeImmutable($day . ' 10:00:00', new DateTimeZone('Asia/Seoul')))->getTimestamp();
    return ['time' => $stamp, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => 1000];
}
function pathSignal(): array
{
    return [
        'confirm_day' => '2024-11-01', 'entry' => 36.0, 'resistance' => 80.0, 'reward_risk' => 2.0,
        'breakout_level' => 30.0, 'breakout_day' => '2024-10-28',
        'wait_reason' => '손익비가 1보다 큽니다. 추천 점수는 아닙니다.',
    ];
}
$pathDays = ['2024-11-04', '2024-11-05', '2024-11-06', '2024-11-07', '2024-11-08', '2024-11-11', '2024-11-12'];
$pathBars = [];
foreach ($pathDays as $i => $pathDay) {
    $pathBars[] = seoulBar($pathDay, 100, $i === 4 ? 112 : 101, $i === 1 ? 98 : 99, $i === 4 ? 110 : 100);
}
$cut = SwingResearch::observe($pathBars, 'TEST.KS', [pathSignal()], '2024-11-08');
$cutAgain = SwingResearch::observe(array_merge($pathBars, [seoulBar('2024-11-11', 100, 101, 50, 50)]), 'TEST.KS', [pathSignal()], '2024-11-08');
sw($cut === $cutAgain && $cut[0]['horizons'][5]['status'] === '완료' && round($cut[0]['horizons'][5]['close_change'] * 100, 2) === 10.0
    && round($cut[0]['horizons'][5]['max_up'] * 100, 2) === 12.0 && round($cut[0]['horizons'][5]['max_down'] * 100, 2) === -2.0
    && $cut[0]['horizons'][10]['status'] === '미완료' && $cut[0]['horizons'][10]['close_change'] === null, 'bars after the observation day stay out of the path');
$skipped = $pathBars;
array_shift($skipped);
$skipped[0] = seoulBar('2024-11-05', 777, 778, 776, 777);
$missingOpen = SwingResearch::observe($skipped, 'TEST.KS', [pathSignal()], '2024-11-12');
sw($missingOpen[0]['ref_day'] === '2024-11-04' && $missingOpen[0]['ref_open'] === null && $missingOpen[0]['ref_status'] === '미확정'
    && $missingOpen[0]['horizons'][5]['status'] === '미확정', 'a missing first session is not replaced by the next open');
$partial = SwingResearch::observe($pathBars, 'TEST.KS', [pathSignal()], '2024-11-12');
sw($partial[0]['horizons'][5]['status'] === '완료' && $partial[0]['horizons'][10]['status'] === '미완료'
    && $partial[0]['horizons'][20]['status'] === '미완료' && $partial[0]['horizons'][10]['close_change'] === null, 'an unfinished horizon is not shown as complete');
$ambiguous = SwingResearch::observe([seoulBar('2024-11-04', 50, 80, 30, 40)], 'TEST.KS', [pathSignal()], '2024-11-04');
sw($ambiguous[0]['resistance_status'] === '도달' && $ambiguous[0]['resistance_day'] === '2024-11-04'
    && $ambiguous[0]['touch_status'] === '장중 접촉' && $ambiguous[0]['touch_day'] === '2024-11-04'
    && $ambiguous[0]['close_break_day'] === null && $ambiguous[0]['order_status'] === '선후관계 미확정', 'one bar touching both sides keeps the reach and marks only the order unknown');
$sequence = SwingResearch::observe([
    seoulBar('2024-11-04', 40, 42, 30, 31),
    seoulBar('2024-11-05', 32, 33, 28, 29),
], 'TEST.KS', [pathSignal()], '2024-11-05');
sw($sequence[0]['touch_day'] === '2024-11-04' && $sequence[0]['touch_status'] === '장중 접촉'
    && $sequence[0]['close_break_day'] === '2024-11-05' && $sequence[0]['close_break_status'] === '종가 이탈'
    && $sequence[0]['order_status'] === '없음', 'a next-day close break keeps the earlier intraday touch');
$sameClose = SwingResearch::observe([seoulBar('2024-11-04', 50, 80, 28, 29)], 'TEST.KS', [pathSignal()], '2024-11-04');
sw($sameClose[0]['resistance_status'] === '도달' && $sameClose[0]['resistance_day'] === '2024-11-04'
    && $sameClose[0]['touch_day'] === '2024-11-04' && $sameClose[0]['close_break_status'] === '종가 이탈'
    && $sameClose[0]['close_break_day'] === '2024-11-04' && $sameClose[0]['order_status'] === '선후관계 미확정', 'one bar keeps the reach and the close break beside an unknown order');
$touched = SwingResearch::observe([seoulBar('2024-11-04', 40, 42, 30, 31)], 'TEST.KS', [pathSignal()], '2024-11-04');
$closedThrough = SwingResearch::observe([seoulBar('2024-11-04', 32, 33, 28, 29)], 'TEST.KS', [pathSignal()], '2024-11-04');
sw($touched[0]['touch_status'] === '장중 접촉' && $touched[0]['close_break_day'] === null
    && $closedThrough[0]['close_break_status'] === '종가 이탈' && $closedThrough[0]['touch_day'] === '2024-11-04', 'an intraday touch stays distinct from a close break');
$eventPage = SwingResearch::page(null, [
    'symbols' => ['TEST.KS'], 'symbol' => 'TEST.KS', 'mode' => 'replay', 'day' => '', 'from' => '', 'to' => '', 'note' => null,
    'replay' => ['error' => null, 'basis' => '현재 캐시로 과거 거래일을 다시 계산한 결과입니다. 당시 저장했던 판단 기록이 아닙니다.', 'identity' => '신호는 한 번만 셉니다.', 'signals' => [], 'days' => [], 'paths' => [$sequence[0], $sameClose[0]]],
]);
sw(str_contains($eventPage, '<th>저항 도달</th>') && str_contains($eventPage, '<th>장중 하단 접촉</th>') && str_contains($eventPage, '<th>종가 이탈</th>') && str_contains($eventPage, '<th>순서</th>')
    && str_contains($eventPage, '장중 접촉 2024-11-04') && str_contains($eventPage, '종가 이탈 2024-11-05')
    && str_contains($eventPage, '도달 2024-11-04') && str_contains($eventPage, '종가 이탈 2024-11-04')
    && str_contains($eventPage, '선후관계 미확정 2024-11-04'), 'the page keeps the three events apart from the unknown order');
$stamp = $tight[24]['available_at'];
do {
    $stamp += 86400;
    $week = (int) (new DateTimeImmutable('@' . $stamp))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('N');
} while ($week >= 6);
$afterWait = $tight;
$afterWait[] = ['available_at' => $stamp, 'open' => 36, 'high' => 37, 'low' => 35, 'close' => 36, 'volume' => 1000];
$waitPlay = SwingResearch::replay($afterWait, 'TEST.KS', seoulDay($tight[0]['available_at']), seoulDay($stamp));
$waitPaths = SwingResearch::observe($afterWait, 'TEST.KS', $waitPlay['signals'], seoulDay($stamp));
sw(count($waitPaths) === 1 && $waitPaths[0]['wait_reason'] === '손익비가 1 이하이면 대기합니다.' && $waitPaths[0]['resistance'] == 40, 'a low reward confirmation stays in the path');
$openPlay = SwingResearch::replay($held, 'TEST.KS', seoulDay($held[0]['available_at']), seoulDay($held[29]['available_at']));
$openPaths = SwingResearch::observe($held, 'TEST.KS', $openPlay['signals'], seoulDay($held[29]['available_at']));
sw(count($openPaths) === 1 && $openPaths[0]['wait_reason'] === '다음 저항 미확정' && $openPaths[0]['resistance_status'] === '미확정', 'an unpriced confirmation stays in the path');
echo "SWING_OK\n";
