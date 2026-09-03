<?php

declare(strict_types=1);

/**
 * “저점 높이면서 고점 재도전 + 아래에 여러 번 걸린 가로 매물대” 차트 발굴.
 *
 * 노라무 «대원전선 종가베팅»(fm-10194043747)의 그림을 조건으로 옮긴 것:
 *  - 저점이 계단식으로 올라옴 (rising_lows)
 *  - 현재가가 최근 고점 근처 (dist_swing_high_pct >= -limit)
 *  - 현재가 아래에 N회 이상 접촉된 가로 지지 (넓게 잡는 손절선)
 *
 * 사용:
 *   php bin/find_level_setups.php                       # 거래대금 상위 60종목
 *   php bin/find_level_setups.php --limit=120
 *   php bin/find_level_setups.php --symbols=006340.KS,000660.KS
 *   php bin/find_level_setups.php --interval=1h --range=3mo
 *   php bin/find_level_setups.php --json
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\FeatureEngine;
use ChartEntryLab\KrAmountLeadersClient;
use ChartEntryLab\SymbolMap;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);

$opts = [
    'limit' => 60,
    'symbols' => [],
    'range' => '6mo',
    'interval' => '1d',
    'min_touches' => 3,
    'min_rising_lows' => 1,
    'max_below_high' => 12.0,
    'max_above_support' => 15.0,
    'json' => false,
    'fresh' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--symbols=')) {
        $opts['symbols'] = array_values(array_filter(array_map(
            'trim',
            explode(',', substr($arg, 10))
        )));
    } elseif (str_starts_with($arg, '--range=')) {
        $opts['range'] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--interval=')) {
        $opts['interval'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--min-touches=')) {
        $opts['min_touches'] = (int) substr($arg, 14);
    } elseif (str_starts_with($arg, '--min-rising-lows=')) {
        $opts['min_rising_lows'] = (int) substr($arg, 18);
    } elseif (str_starts_with($arg, '--max-below-high=')) {
        $opts['max_below_high'] = (float) substr($arg, 17);
    } elseif (str_starts_with($arg, '--max-above-support=')) {
        $opts['max_above_support'] = (float) substr($arg, 20);
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    } elseif ($arg === '--fresh') {
        $opts['fresh'] = true;
    }
}

$client = new YahooChartClient($root . '/data/ohlcv');
$engine = new FeatureEngine();

/** @var array<string,string> $names */
$names = [];
$symbols = $opts['symbols'];
if ($symbols === []) {
    $leaders = (new KrAmountLeadersClient($root . '/data/cache'))
        ->topByAmount($opts['limit'], useCache: !$opts['fresh']);
    foreach ($leaders as $leader) {
        $yahoo = SymbolMap::toYahoo((string) $leader['yahoo']) ?? (string) $leader['yahoo'];
        if ($yahoo === '') {
            continue;
        }
        $symbols[] = $yahoo;
        $names[$yahoo] = (string) $leader['name'];
    }
}

$hits = [];
$skipped = [];
foreach ($symbols as $symbol) {
    try {
        $bars = $client->fetch(
            $symbol,
            $opts['range'],
            $opts['interval'],
            useCache: !$opts['fresh'],
            maxAgeSeconds: $opts['fresh'] ? 0 : null,
        );
    } catch (\Throwable $e) {
        $skipped[] = $symbol . ': ' . $e->getMessage();
        continue;
    }
    if (count($bars) < 40) {
        $skipped[] = $symbol . ': 봉 부족';
        continue;
    }

    $f = $engine->extract($bars);
    $price = (float) ($f['price'] ?? 0);
    $support = $f['level_support'] ?? null;
    $touches = (int) ($f['level_support_touches'] ?? 0);
    $rising = (int) ($f['rising_lows_count'] ?? 0);
    $distHigh = $f['dist_swing_high_pct'] ?? null;

    if ($price <= 0 || !is_numeric($support) || !is_numeric($distHigh)) {
        continue;
    }
    $aboveSupportPct = (($price - (float) $support) / $price) * 100;

    if (
        $touches < $opts['min_touches']
        || $rising < $opts['min_rising_lows']
        || (float) $distHigh < -$opts['max_below_high']
        || $aboveSupportPct <= 0
        || $aboveSupportPct > $opts['max_above_support']
    ) {
        continue;
    }

    // 손실폭 대비 남은 고점까지 여유 = 대략적인 손익비
    $risk = max(0.1, $aboveSupportPct);
    $reward = max(0.0, (float) $distHigh * -1);
    $setupScore = $touches * 10
        + $rising * 8
        + (!empty($f['level_support_flip']) ? 12 : 0)
        + (int) round(max(0, 15 - $risk))
        + (int) round((int) ($f['pullback_long_score'] ?? 0) / 5);

    $hits[] = [
        'symbol' => $symbol,
        'name' => $names[$symbol] ?? $symbol,
        'price' => round($price, 2),
        'support' => round((float) $support, 2),
        'touches' => $touches,
        'flip' => (bool) ($f['level_support_flip'] ?? false),
        'last_touch' => $f['level_support_last_kst'] ?? null,
        'rising_lows' => $rising,
        'dist_swing_high_pct' => round((float) $distHigh, 2),
        'above_support_pct' => round($aboveSupportPct, 2),
        'stop_tight' => $f['stop_tight'] ?? null,
        'stop_wide' => $f['stop_wide'] ?? null,
        'score' => (int) ($f['pullback_long_score'] ?? 0),
        'setup_score' => $setupScore,
        'rr_rough' => round($reward / $risk, 2),
        'note' => (string) ($f['levels_note'] ?? ''),
    ];
}

usort($hits, static fn(array $a, array $b): int => $b['setup_score'] <=> $a['setup_score']);

if ($opts['json']) {
    echo json_encode(
        ['count' => count($hits), 'hits' => $hits, 'skipped' => $skipped],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

printf(
    "저점 상향 + 고점 재도전 + 가로 매물대 (%s/%s · 접촉%d회↑ · 저점상향%d회↑ · 고점 -%.0f%% 이내 · 지지 위 %.0f%% 이내)\n\n",
    $opts['range'],
    $opts['interval'],
    $opts['min_touches'],
    $opts['min_rising_lows'],
    $opts['max_below_high'],
    $opts['max_above_support'],
);

if ($hits === []) {
    echo "조건에 맞는 종목 없음 (검사 " . count($symbols) . "종목)\n";
    exit(0);
}

/** 한글은 2칸을 차지하므로 표시폭 기준으로 채운다 */
$pad = static function (string $s, int $width, bool $left = true): string {
    $w = mb_strwidth($s, 'UTF-8');
    if ($w >= $width) {
        return mb_strimwidth($s, 0, $width, '', 'UTF-8');
    }
    $fill = str_repeat(' ', $width - $w);

    return $left ? $s . $fill : $fill . $s;
};

echo $pad('종목', 12) . ' ' . $pad('이름', 16) . ' '
    . $pad('현재가', 10, false) . ' ' . $pad('가로지지', 10, false) . ' '
    . $pad('접촉', 6, false) . ' ' . $pad('저점↑', 6, false) . ' '
    . $pad('고점대비', 9, false) . ' ' . $pad('지지위', 8, false) . ' '
    . $pad('발굴점', 7, false) . "\n";
echo str_repeat('-', 92) . "\n";
foreach ($hits as $h) {
    echo $pad($h['symbol'], 12) . ' ' . $pad($h['name'], 16) . ' '
        . $pad(number_format($h['price'], $h['price'] >= 100 ? 0 : 2), 10, false) . ' '
        . $pad(number_format($h['support'], $h['support'] >= 100 ? 0 : 2), 10, false) . ' '
        . $pad($h['touches'] . ($h['flip'] ? '*' : ''), 6, false) . ' '
        . $pad((string) $h['rising_lows'], 6, false) . ' '
        . $pad(sprintf('%.1f%%', $h['dist_swing_high_pct']), 9, false) . ' '
        . $pad(sprintf('%.1f%%', $h['above_support_pct']), 8, false) . ' '
        . $pad((string) $h['setup_score'], 7, false) . "\n";
}
echo "\n* = 저항→지지 전환 자리\n";
echo "손절 2단: 타이트=당일 저가(국내주는 네이버 일별시세 실측) / 넓게=가로 지지\n";
if ($skipped !== []) {
    echo "\n건너뜀 " . count($skipped) . "건\n";
}
