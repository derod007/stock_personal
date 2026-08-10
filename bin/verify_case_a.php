<?php

declare(strict_types=1);

/**
 * retrospective 케이스 A (SNDK 2026-07-09) vs FeatureEngine 대조.
 * 미래 누수 없이 posted_at 이전 봉만 사용 (봉 시각 = Asia/Seoul).
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\FeatureEngine;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$repo = new EntryRepository($root . '/data/entries.json');
$client = new YahooChartClient($root . '/data/ohlcv');
$engine = new FeatureEngine();

$entryId = 'e-20260709-sndk-structure-long';
$entry = null;
foreach ($repo->all() as $row) {
    if (($row['id'] ?? '') === $entryId) {
        $entry = $row;
        break;
    }
}
if ($entry === null) {
    fwrite(STDERR, "Entry not found: {$entryId}\n");
    exit(1);
}

$tz = new DateTimeZone('Asia/Seoul');
$posted = new DateTimeImmutable((string) $entry['posted_at_kst']);
// 글이 07-09 02:30 KST → 미국 07-08 세션(KST 07-08 22:30)까지 포함, 07-09 봉은 제외.
$bars = $client->fetch('SNDK', '3mo', '1d', useCache: true);
$barsAsOf = array_values(array_filter(
    $bars,
    static function (array $b) use ($posted, $tz): bool {
        $barTime = new DateTimeImmutable($b['time_kst'], $tz);
        return $barTime < $posted;
    }
));

if (count($barsAsOf) < 30) {
    fwrite(STDERR, "캔들 부족: " . count($barsAsOf) . "\n");
    exit(1);
}

$features = $engine->extract($barsAsOf);
$last = $barsAsOf[array_key_last($barsAsOf)];

$expected = [
    'flush_low' => 1485.0,
    'half_retrace_manual_approx' => 1767.0,
    'close_before_post_approx' => 1727.0,
    'target' => 1815.0,
    'note' => '수동 스윙은 ~1485–2050. 엔진은 최근 20봉 high/low를 씀.',
];

$half = (float) ($features['half_retrace'] ?? 0);
$price = (float) ($features['price'] ?? 0);
$flush = (bool) ($features['flush_bar_recent'] ?? false);
$higherLow = (bool) ($features['higher_low'] ?? false);
$invalidation = (float) ($features['invalidation_level'] ?? 0);
$distHalf = $features['dist_half_pct'] ?? null;

$core = [
    [
        'name' => '직전 봉이 07-08 세션(타임존 보정)',
        'pass' => str_starts_with($last['time_kst'], '2026-07-08'),
        'detail' => 'last_bar=' . $last['time_kst'] . ' close=' . $last['close'],
    ],
    [
        'name' => '하방 슈팅 피처(flush_bar_recent)',
        'pass' => $flush === true,
        'detail' => $flush ? 'true' : 'false',
    ],
    [
        'name' => '저점 상향(higher_low)',
        'pass' => $higherLow === true,
        'detail' => $higherLow ? 'true' : 'false',
    ],
    [
        'name' => '무효화≈1485 슈팅 저점',
        'pass' => abs($invalidation - 1485.0) <= 5.0,
        'detail' => 'invalidation=' . $invalidation,
    ],
];

$soft = [
    [
        'name' => '종가가 수동 절반(1767) 근처(±6%)',
        'pass' => abs($price - $expected['close_before_post_approx']) <= 40
            && abs($price - $expected['half_retrace_manual_approx']) / $expected['half_retrace_manual_approx'] <= 0.06,
        'detail' => sprintf('price=%.2f manual_close≈%.0f manual_half≈%.0f', $price, $expected['close_before_post_approx'], $expected['half_retrace_manual_approx']),
    ],
    [
        'name' => '엔진 절반 vs 수동 절반(±8%) — flush_pre_peak 스윙',
        'pass' => $half > 0 && abs($half - $expected['half_retrace_manual_approx']) / $expected['half_retrace_manual_approx'] <= 0.08,
        'detail' => sprintf(
            'engine_half=%.2f method=%s swing_high=%s swing_low=%s dist_half_pct=%s',
            $half,
            (string) ($features['swing_method'] ?? ''),
            (string) ($features['swing_high'] ?? ''),
            (string) ($features['swing_low'] ?? ''),
            $distHalf === null ? 'null' : (string) $distHalf
        ),
    ],
];

$corePassed = count(array_filter($core, static fn(array $c): bool => $c['pass']));
$softPassed = count(array_filter($soft, static fn(array $c): bool => $c['pass']));

$verdict = 'FAIL';
if ($corePassed === count($core) && $softPassed >= 1) {
    $verdict = 'PASS';
} elseif ($corePassed === count($core)) {
    $verdict = 'PASS_CORE'; // 구조 일치, 절반 수치는 스윙 윈도우 차이
} elseif ($corePassed >= 3) {
    $verdict = 'PARTIAL';
}

$report = [
    'entry_id' => $entryId,
    'posted_at_kst' => $entry['posted_at_kst'],
    'bars_used' => count($barsAsOf),
    'last_bar_kst' => $last['time_kst'],
    'last_bar_ohlc' => [
        'o' => $last['open'],
        'h' => $last['high'],
        'l' => $last['low'],
        'c' => $last['close'],
    ],
    'features' => $features,
    'expected_manual' => $expected,
    'core_checks' => $core,
    'soft_checks' => $soft,
    'summary' => [
        'core_passed' => $corePassed,
        'core_total' => count($core),
        'soft_passed' => $softPassed,
        'soft_total' => count($soft),
        'verdict' => $verdict,
        'gap' => '절반 불일치 시 swing_method/swing_high를 확인할 것. 목표: flush_pre_peak + 수동 절반 ±8%.',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(in_array($verdict, ['PASS', 'PASS_CORE'], true) ? 0 : 2);
