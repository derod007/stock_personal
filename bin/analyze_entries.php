<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\FeatureEngine;
use ChartEntryLab\SymbolMap;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$repo = new EntryRepository($root . '/data/entries.json');
$client = new YahooChartClient($root . '/data/ohlcv');
$engine = new FeatureEngine();

$onlyLearning = in_array('--learning', $argv, true);

foreach ($repo->all() as $entry) {
    $use = (string) ($entry['learning_use'] ?? '');
    if ($onlyLearning && !in_array($use, ['full', 'structure_only'], true)) {
        continue;
    }
    $sym = (string) ($entry['related_underlying'] ?? $entry['symbol'] ?? '');
    $yahoo = SymbolMap::toYahoo($sym);
    if ($yahoo === null) {
        echo str_repeat('=', 72) . PHP_EOL;
        echo ($entry['id'] ?? '?') . " | skip no-chart ({$sym})\n\n";
        continue;
    }
    echo str_repeat('=', 72) . PHP_EOL;
    echo ($entry['id'] ?? '?') . ' | use=' . ($use !== '' ? $use : 'unset') . ' | ' . ($entry['posted_at_kst'] ?? '') . PHP_EOL;
    echo ($entry['raw_quote'] ?? '') . PHP_EOL;

    if ($yahoo === null) {
        echo "차트 심볼 미매핑 (수기 종목/레버상품). underlying 구조만 별도 확인.\n\n";
        continue;
    }

    try {
        $bars = $client->fetch($yahoo, '3mo', '1d');
        // posted_at 이전 마지막 일봉까지로 피처 계산 (봉 시각은 KST로 해석 — 미래 누수 방지)
        $tz = new DateTimeZone('Asia/Seoul');
        $posted = isset($entry['posted_at_kst'])
            ? new DateTimeImmutable((string) $entry['posted_at_kst'])
            : null;
        if ($posted !== null) {
            $bars = array_values(array_filter(
                $bars,
                static function (array $b) use ($posted, $tz): bool {
                    $barTime = new DateTimeImmutable($b['time_kst'], $tz);
                    return $barTime <= $posted;
                }
            ));
        }
        if (count($bars) < 30) {
            echo "캔들 부족\n\n";
            continue;
        }
        $features = $engine->extract($bars);
        echo "symbol={$yahoo}\n";
        echo json_encode($features, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . PHP_EOL . PHP_EOL;
    }
}
