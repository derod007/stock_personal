<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryBacktester;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\SymbolMap;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$repo = new EntryRepository($root . '/data/entries.json');
$client = new YahooChartClient($root . '/data/ohlcv');
$bt = new EntryBacktester();

$results = [];
foreach ($repo->all() as $entry) {
    if (($entry['learning_use'] ?? '') !== 'full') {
        continue;
    }
    $sym = (string) ($entry['related_underlying'] ?? $entry['symbol'] ?? '');
    $yahoo = SymbolMap::toYahoo($sym);
    if ($yahoo === null) {
        $results[] = [
            'entry_id' => $entry['id'] ?? null,
            'error' => 'unmapped_or_no_chart',
            'symbol' => $sym,
        ];
        continue;
    }

    try {
        $bars = $client->fetch($yahoo, '6mo', '1d', useCache: true);
        $row = $bt->evaluate($entry, $bars, $yahoo);
        if ($row !== null) {
            $results[] = $row;
        }
    } catch (Throwable $e) {
        $results[] = [
            'entry_id' => $entry['id'] ?? null,
            'symbol' => $yahoo,
            'error' => $e->getMessage(),
        ];
    }
}

$summary = $bt->summarize($results);
$out = [
    'generated_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
    'summary' => $summary,
    'results' => $results,
];

$outDir = $root . '/data/backtests';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$stamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Ymd-His');
$jsonPath = $outDir . '/backtest-' . $stamp . '.json';
file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$mdPath = $root . '/docs/backtest-latest.md';
$md = [];
$md[] = '# 백테스트 요약 (latest)';
$md[] = '';
$md[] = '생성: ' . $out['generated_at_kst'];
$md[] = '';
$md[] = '원본 JSON: `' . str_replace($root . DIRECTORY_SEPARATOR, '', $jsonPath) . '`';
$md[] = '';
$md[] = '## 점수 밴드별';
$md[] = '';
$md[] = '| band | n | avg_score | h5 avg_ret | h5 win | h5 stop | h5 target | h10 avg_ret | h20 avg_ret |';
$md[] = '|------|--:|----------:|-----------:|-------:|--------:|----------:|------------:|------------:|';
foreach ($summary['by_band'] as $band => $row) {
    if (($row['n'] ?? 0) === 0) {
        $md[] = "| {$band} | 0 | | | | | | | |";
        continue;
    }
    $md[] = sprintf(
        '| %s | %d | %s | %s | %s | %s | %s | %s | %s |',
        $band,
        $row['n'],
        $row['avg_score'] ?? '',
        $row['h5']['avg_ret_close_pct'] ?? '',
        $row['h5']['win_rate_close'] ?? '',
        $row['h5']['stop_rate'] ?? '',
        $row['h5']['target_rate'] ?? '',
        $row['h10']['avg_ret_close_pct'] ?? '',
        $row['h20']['avg_ret_close_pct'] ?? ''
    );
}
$md[] = '';
$md[] = '## 건별';
$md[] = '';
$md[] = '| id | symbol | score | band | entry | stop | target | h5 ret | h5 first_exit |';
$md[] = '|----|--------|------:|------|------:|-----:|-------:|-------:|--------------|';
foreach ($results as $r) {
    if (isset($r['error'])) {
        $md[] = sprintf('| %s | %s | | | | | | | ERR:%s |', $r['entry_id'] ?? '?', $r['symbol'] ?? '?', $r['error']);
        continue;
    }
    $h5 = $r['horizons']['5'] ?? [];
    $md[] = sprintf(
        '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
        $r['entry_id'] ?? '?',
        $r['symbol'] ?? '?',
        $r['score'] ?? '',
        $r['score_band'] ?? '',
        $r['levels']['entry_price'] ?? '',
        $r['levels']['stop'] ?? '',
        $r['levels']['target'] ?? '',
        $h5['ret_close_pct'] ?? '',
        $h5['first_exit'] ?? '-'
    );
}
$md[] = '';
$md[] = '> 샘플 수가 적을 때 승률은 참고용. `full` 라벨을 늘린 뒤 다시 돌릴 것.';
$md[] = '';
file_put_contents($mdPath, implode("\n", $md));

echo json_encode([
    'json' => $jsonPath,
    'md' => $mdPath,
    'summary' => $summary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
