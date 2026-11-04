<?php
declare(strict_types=1);
require __DIR__.'/../bin/bootstrap.php';
foreach (['Tracker', 'BreakoutPath', 'Page'] as $f) {
    require __DIR__.'/../bin/type1/'.$f.'.php';
}
use ChartEntryLab\Type1Scan\BreakoutPath;

$n = 0;
function check($x, $m)
{
    global $n;
    if (!$x) {
        throw new RuntimeException($m);
    }
    $n++;
}

function candle(string $day, float $o, float $h, float $l, float $c, float $v = 100): array
{
    return [
        'time_kst' => $day.' 09:00:00',
        'open' => $o,
        'high' => $h,
        'low' => $l,
        'close' => $c,
        'volume' => $v,
        'is_complete' => true,
    ];
}

$days = [
    '2026-10-20',
    '2026-10-21',
    '2026-10-22',
    '2026-10-23',
    '2026-10-24',
    '2026-10-27',
    '2026-10-28',
];
$asOf = strtotime('2026-10-28 20:20 +0900');
$judgeDay = '2026-10-21';
$level = 100.0;

$rowBase = static function (string $symbol, string $name, int $rank) use ($judgeDay, $level): array {
    return [
        'symbol' => $symbol,
        'name' => $name,
        'rank' => $rank,
        'analysis_close' => 105.0,
        'breakout20' => ['confirmed' => true, 'level' => $level, 'day' => $judgeDay],
    ];
};

$snap = static function (string $day, array $pricesBySymbol) use ($days, $asOf): array {
    return [
        'day' => $day,
        'as_of' => $asOf,
        'days' => $days,
        'prices' => $pricesBySymbol,
    ];
};

// 1) Stay above the frozen line.
$staySym = '111111.KS';
$stayBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    candle('2026-10-22', 106, 110, 104, 108),
    candle('2026-10-23', 108, 112, 106, 110),
    candle('2026-10-24', 110, 115, 108, 112),
    candle('2026-10-27', 112, 118, 110, 114),
    candle('2026-10-28', 114, 120, 112, 116),
];
$judgeSnap = $snap($judgeDay, [$staySym => array_slice($stayBars, 0, 2)]);
$latestSnap = $snap('2026-10-28', [$staySym => $stayBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($staySym, '유지', 1), $judgeSnap, $latestSnap);
check($p['ok'] && $p['path_complete'], 'stay above complete');
check($p['entry_day'] === '2026-10-22' && $p['entry_open'] === 106.0, 'stay entry open');
check($p['first_close_below_day'] === null && $p['first_close_above_after_breach_day'] === null, 'no breach');
check($p['latest_vs_level'] === '위', 'stay latest above');
check(abs($p['max_high_pct'] - (120 / 106 - 1) * 100) < 1e-9, 'stay max high pct');
check(abs($p['max_low_pct'] - (104 / 106 - 1) * 100) < 1e-9, 'stay max low pct');
check($p['id'] === $judgeDay.'|'.$staySym, 'id is day|symbol');

// 2) Breach then recover (strict inequalities; equal close is neither).
$recSym = '222222.KS';
$recBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    candle('2026-10-22', 104, 108, 100, 105),
    candle('2026-10-23', 102, 103, 98, 99),   // first close < 100
    candle('2026-10-24', 99, 100, 97, 100),    // equal close — not recovery
    candle('2026-10-27', 100, 105, 99, 101),   // first close > 100 after breach
    candle('2026-10-28', 101, 106, 100, 102),
];
$judgeSnap = $snap($judgeDay, [$recSym => array_slice($recBars, 0, 2)]);
$latestSnap = $snap('2026-10-28', [$recSym => $recBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($recSym, '회복', 2), $judgeSnap, $latestSnap);
check($p['path_complete'], 'recover complete');
check($p['first_close_below_day'] === '2026-10-23', 'first breach day');
check($p['first_close_above_after_breach_day'] === '2026-10-27', 'recovery ignores equal close');
check($p['latest_vs_level'] === '위', 'recover latest above');

// 3) Latest close equal to frozen line.
$eqSym = '333333.KS';
$eqBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    candle('2026-10-22', 104, 108, 100, 105),
    candle('2026-10-23', 105, 106, 101, 104),
    candle('2026-10-24', 104, 105, 100, 103),
    candle('2026-10-27', 103, 104, 100, 102),
    candle('2026-10-28', 101, 102, 99, 100),
];
$judgeSnap = $snap($judgeDay, [$eqSym => array_slice($eqBars, 0, 2)]);
$latestSnap = $snap('2026-10-28', [$eqSym => $eqBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($eqSym, '동일', 3), $judgeSnap, $latestSnap);
check($p['latest_vs_level'] === '동일', 'equal close is 동일');
check($p['first_close_below_day'] === null, 'equal never counts as breach');

// 4) Exact next session bar missing — do not substitute later day.
$missNextSym = '444444.KS';
$missNextBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    // 2026-10-22 missing
    candle('2026-10-23', 108, 112, 106, 110),
    candle('2026-10-24', 110, 115, 108, 112),
    candle('2026-10-27', 112, 118, 110, 114),
    candle('2026-10-28', 114, 120, 112, 116),
];
$judgeSnap = $snap($judgeDay, [$missNextSym => array_slice($missNextBars, 0, 2)]);
$latestSnap = $snap('2026-10-28', [$missNextSym => $missNextBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($missNextSym, '다음누락', 4), $judgeSnap, $latestSnap);
check(!$p['ok'] && $p['entry_day'] === '2026-10-22', 'missing next names exact day');
check($p['entry_open'] === null && $p['path_complete'] === false, 'missing next not substituted');
check(($p['gap_kind'] ?? null) === 'next_bar_missing', 'missing next gap_kind');
check(str_contains((string)$p['reason'], '종목은 있으나'), 'missing next reason distinguishes presence');

// 5) Middle session gap — extremes/crosses not marked complete; confirmed segment still reported.
$gapSym = '555555.KS';
$gapBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    candle('2026-10-22', 106, 110, 104, 108),
    // 2026-10-23 missing
    candle('2026-10-24', 99, 101, 95, 98),
    candle('2026-10-27', 98, 110, 97, 109),
    candle('2026-10-28', 109, 112, 108, 110),
];
$judgeSnap = $snap($judgeDay, [$gapSym => array_slice($gapBars, 0, 2)]);
$latestSnap = $snap('2026-10-28', [$gapSym => $gapBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($gapSym, '중간누락', 5), $judgeSnap, $latestSnap);
check($p['ok'] && !$p['path_complete'] && !$p['extremes_complete'] && !$p['level_cross_complete'], 'gap incomplete flags');
check($p['missing_days'] === ['2026-10-23'], 'gap lists missing day');
check($p['entry_open'] === 106.0 && $p['latest_close'] === 110.0, 'gap keeps confirmed segment values');
check($p['first_close_below_day'] === '2026-10-24', 'gap still records seen breach');
check($p['first_close_above_after_breach_day'] === '2026-10-27', 'gap still records seen recovery');

// 6) Same symbol, different judgment days are separate observations.
$repSym = '666666.KS';
$daysA = ['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'];
$daysB = ['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'];
$barsA = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle('2026-10-21', 100, 106, 99, 105),
    candle('2026-10-22', 106, 110, 104, 108),
    candle('2026-10-23', 108, 112, 106, 110),
];
$barsB = [
    candle('2026-10-21', 100, 106, 99, 105),
    candle('2026-10-22', 106, 110, 104, 108),
    candle('2026-10-23', 100, 106, 99, 105),
    candle('2026-10-24', 104, 108, 100, 106),
];
$snapAJudge = ['day' => '2026-10-21', 'as_of' => strtotime('2026-10-21 20:20 +0900'), 'days' => $daysA, 'prices' => [$repSym => array_slice($barsA, 0, 2)]];
$snapALatest = ['day' => '2026-10-23', 'as_of' => strtotime('2026-10-23 20:20 +0900'), 'days' => $daysA, 'prices' => [$repSym => $barsA]];
$snapBJudge = ['day' => '2026-10-22', 'as_of' => strtotime('2026-10-22 20:20 +0900'), 'days' => $daysB, 'prices' => [$repSym => array_slice($barsB, 0, 2)]];
$snapBLatest = ['day' => '2026-10-24', 'as_of' => strtotime('2026-10-24 20:20 +0900'), 'days' => $daysB, 'prices' => [$repSym => $barsB]];
$rowA = [
    'symbol' => $repSym,
    'name' => '반복A',
    'rank' => 1,
    'analysis_close' => 105.0,
    'breakout20' => ['confirmed' => true, 'level' => 100.0, 'day' => '2026-10-21'],
];
$rowB = [
    'symbol' => $repSym,
    'name' => '반복B',
    'rank' => 2,
    'analysis_close' => 108.0,
    'breakout20' => ['confirmed' => true, 'level' => 105.0, 'day' => '2026-10-22'],
];
$pA = BreakoutPath::forRow('2026-10-21', $rowA, $snapAJudge, $snapALatest);
$pB = BreakoutPath::forRow('2026-10-22', $rowB, $snapBJudge, $snapBLatest);
check($pA['id'] !== $pB['id'], 'repeat candidates have distinct ids');
check($pA['level'] === 100.0 && $pB['level'] === 105.0, 'each judgment freezes its own level');
check(str_contains((string)$pA['note'], '별도 관찰'), 'repeat note present');

// 7) Cross-snapshot judgment-day OHLC conflict — do not silently mix versions.
$cfSym = '777777.KS';
$judgeBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
];
$latestBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 107, 99, 105), // high differs
    candle('2026-10-22', 106, 110, 104, 108),
    candle('2026-10-23', 108, 112, 106, 110),
    candle('2026-10-24', 110, 115, 108, 112),
    candle('2026-10-27', 112, 118, 110, 114),
    candle('2026-10-28', 114, 120, 112, 116),
];
$judgeSnap = $snap($judgeDay, [$cfSym => $judgeBars]);
$latestSnap = $snap('2026-10-28', [$cfSym => $latestBars]);
$p = BreakoutPath::forRow($judgeDay, $rowBase($cfSym, '충돌', 7), $judgeSnap, $latestSnap);
check($p['ok'] && !$p['path_complete'] && $p['conflict'] !== null, 'conflict flagged incomplete');
check(($p['conflict']['field'] ?? null) === 'high', 'conflict field high');
check($p['level'] === 100.0 && $p['entry_open'] === 106.0, 'level from judgment; path from latest only');
check(str_contains((string)$p['reason'], '충돌'), 'conflict reason');

// 7b) Volume-only revision is not a price conflict (real 10/28 028050/064400 pattern).
$volSym = '888888.KS';
$volJudge = [
    candle('2026-10-20', 90, 100, 89, 95, 1000),
    candle($judgeDay, 100, 106, 99, 105, 4357692),
];
$volLatest = [
    candle('2026-10-20', 90, 100, 89, 95, 1000),
    candle($judgeDay, 100, 106, 99, 105, 4237254),
    candle('2026-10-22', 106, 110, 104, 108, 2000),
    candle('2026-10-23', 108, 112, 106, 110, 2000),
    candle('2026-10-24', 110, 115, 108, 112, 2000),
    candle('2026-10-27', 112, 118, 110, 114, 2000),
    candle('2026-10-28', 114, 120, 112, 116, 2000),
];
$p = BreakoutPath::forRow(
    $judgeDay,
    $rowBase($volSym, '거래량만', 8),
    $snap($judgeDay, [$volSym => $volJudge]),
    $snap('2026-10-28', [$volSym => $volLatest])
);
check($p['ok'] && $p['path_complete'] && $p['conflict'] === null, 'volume-only not price conflict');
check($p['latest_vs_level'] === '위' && $p['entry_open'] === 106.0, 'volume-only path still complete');

// 8) Symbol key absent in latest snapshot — not the same as missing next bar.
$absSym = '101010.KQ';
$absJudge = [candle('2026-10-20', 90, 100, 89, 95), candle($judgeDay, 100, 106, 99, 105)];
$p = BreakoutPath::forRow(
    $judgeDay,
    $rowBase($absSym, '키없음', 10),
    $snap($judgeDay, [$absSym => $absJudge]),
    ['day' => '2026-10-28', 'as_of' => $asOf, 'days' => $days, 'prices' => ['999999.KS' => $stayBars]]
);
check(($p['gap_kind'] ?? null) === 'symbol_absent_in_latest', 'absent key gap_kind');
check(str_contains((string)$p['reason'], '일봉 키가 없습니다'), 'absent key reason');
check(str_contains((string)$p['reason'], '과거에도 없었다는 뜻이 아닙니다'), 'absent key not forever-missing');
check(($p['observation_as_of'] ?? null) === '2026-10-28', 'absent key names latest day');

// 9) Empty price array in latest.
$emptySym = '202020.KQ';
$p = BreakoutPath::forRow(
    $judgeDay,
    $rowBase($emptySym, '빈배열', 11),
    $snap($judgeDay, [$emptySym => $absJudge]),
    [
        'day' => '2026-10-28',
        'as_of' => $asOf,
        'days' => $days,
        'prices' => [$emptySym => []],
        'errors' => [$emptySym => '일봉 조회 실패'],
    ]
);
check(($p['gap_kind'] ?? null) === 'symbol_empty_in_latest', 'empty array gap_kind');
check(str_contains((string)$p['reason'], '비어 있습니다'), 'empty array reason');
check(str_contains((string)$p['reason'], '일봉 조회 실패'), 'empty array includes stored error');

// 10) Next bar present but invalid.
$badSym = '303030.KQ';
$badBars = [
    candle('2026-10-20', 90, 100, 89, 95),
    candle($judgeDay, 100, 106, 99, 105),
    ['time_kst' => '2026-10-22 09:00:00', 'open' => 106, 'high' => 110, 'low' => 104, 'close' => 108, 'volume' => 100, 'is_complete' => true, 'synthetic' => true],
    candle('2026-10-23', 108, 112, 106, 110),
];
$p = BreakoutPath::forRow(
    $judgeDay,
    $rowBase($badSym, '무효봉', 12),
    $snap($judgeDay, [$badSym => array_slice($badBars, 0, 2)]),
    $snap('2026-10-28', [$badSym => $badBars])
);
check(($p['gap_kind'] ?? null) === 'next_bar_invalid', 'invalid next gap_kind');
check(($p['entry_day'] ?? null) === '2026-10-22', 'invalid next keeps entry day');
check(str_contains((string)$p['reason'], '유효성 검사'), 'invalid next reason');

// 11) Next calendar day missing.
$calSym = '404040.KQ';
$calDays = ['2026-10-20', '2026-10-21'];
$calBars = [candle('2026-10-20', 90, 100, 89, 95), candle($judgeDay, 100, 106, 99, 105)];
$p = BreakoutPath::forRow(
    $judgeDay,
    $rowBase($calSym, '달력없음', 13),
    ['day' => $judgeDay, 'as_of' => $asOf, 'days' => $calDays, 'prices' => [$calSym => $calBars]],
    ['day' => $judgeDay, 'as_of' => $asOf, 'days' => $calDays, 'prices' => [$calSym => $calBars]]
);
check(($p['gap_kind'] ?? null) === 'next_calendar_missing', 'calendar missing gap_kind');

// Non-confirmed rows are skipped by batch helper.
$batch = BreakoutPath::forConfirmedRows($judgeDay, [
    $rowBase($staySym, '유지', 1),
    array_replace($rowBase('999999.KS', '비확인', 9), ['breakout20' => ['confirmed' => false, 'level' => 100]]),
], $snap($judgeDay, [$staySym => array_slice($stayBars, 0, 2)]), $snap('2026-10-28', [$staySym => $stayBars]));
check(count($batch) === 1 && $batch[0]['symbol'] === $staySym, 'batch only confirmed');

// UI: saved view shows post-path section and recent link anchor.
$report = [
    'ok' => true,
    'as_of' => date(DATE_ATOM, $asOf),
    'source' => 'saved',
    'saved_day' => $judgeDay,
    'scope' => 'breakout',
    'sort' => 'amount',
    'rows' => [array_merge($rowBase($staySym, '유지', 1), [
        'market' => 'KS',
        'price' => 105,
        'change_pct' => 1,
        'amount_won' => 1e9,
        'analysis_date' => $judgeDay,
        'analysis_close' => 105,
        'breakout20' => ['confirmed' => true, 'level' => 100, 'distance_pct' => 5, 'status' => '종가 돌파 확인'],
    ])],
    'errors' => [],
    'summary' => ['total' => 1, 'evaluated' => 1, 'matches' => 1, 'errors' => 0],
    'recent' => [[
        'day' => $judgeDay,
        'confirmed' => 1,
        'unevaluable' => 0,
        'recent_candidate' => true,
        'observation' => ['state' => '대기', 'label' => '다음 거래일 미도래 또는 미저장'],
    ]],
    'saved_days' => [$judgeDay],
    'post_paths' => [$p = BreakoutPath::forRow($judgeDay, $rowBase($staySym, '유지', 1), $snap($judgeDay, [$staySym => array_slice($stayBars, 0, 2)]), $snap('2026-10-28', [$staySym => $stayBars]))],
    'error' => null,
];
ob_start();
\ChartEntryLab\Type1Scan\render($report);
$html = ob_get_clean();
check(str_contains($html, 'id="post-path"'), 'post-path section');
check(str_contains($html, '#post-path'), 'recent link to post-path');
check(str_contains($html, '후보 이후 경로'), 'post-path heading');
check(str_contains($html, '고정 돌파선'), 'frozen line wording');
check(!str_contains($html, '수익 가능성'), 'no profit ranking copy');

echo "$n checks passed\n";
