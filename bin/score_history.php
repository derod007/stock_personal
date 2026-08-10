<?php

declare(strict_types=1);

/**
 * CORE 종목을 과거 금요일 as-of로 점수 분포 계산 → 임계값 참고.
 * Usage: php bin/score_history.php [--weeks=12]
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\FeatureEngine;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$weeks = 12;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--weeks=')) {
        $weeks = max(4, (int) substr($arg, strlen('--weeks=')));
    }
}

$client = new YahooChartClient($root . '/data/ohlcv');
$engine = new FeatureEngine();
$tz = new DateTimeZone('Asia/Seoul');
$now = new DateTimeImmutable('now', $tz);

$fridays = [];
$cursor = $now;
while (count($fridays) < $weeks) {
    if ((int) $cursor->format('N') === 5) {
        $fridays[] = $cursor->setTime(23, 59, 59);
    }
    $cursor = $cursor->modify('-1 day');
}
$fridays = array_reverse($fridays);

$bands = ['ge70' => 0, '55_69' => 0, 'mid' => 0, 'lt35' => 0];
$scores = [];
$rows = [];

foreach (Account1Playbook::CORE_SYMBOLS as $symbol) {
    try {
        $bars = $client->fetch($symbol, '6mo', '1d', useCache: true);
    } catch (Throwable $e) {
        continue;
    }
    foreach ($fridays as $asOf) {
        $slice = array_values(array_filter(
            $bars,
            static function (array $b) use ($asOf, $tz): bool {
                return new DateTimeImmutable($b['time_kst'], $tz) <= $asOf;
            }
        ));
        if (count($slice) < 30) {
            continue;
        }
        try {
            $features = $engine->extract($slice);
        } catch (Throwable) {
            continue;
        }
        $score = (int) ($features['pullback_long_score'] ?? 0);
        $scores[] = $score;
        if ($score >= 70) {
            $bands['ge70']++;
        } elseif ($score >= 55) {
            $bands['55_69']++;
        } elseif ($score < 35) {
            $bands['lt35']++;
        } else {
            $bands['mid']++;
        }
        $rows[] = [
            'asof' => $asOf->format('Y-m-d'),
            'symbol' => $symbol,
            'score' => $score,
            'action_hint' => $score >= 70 ? 'add_zone' : ($score >= 55 ? 'watch' : ($score < 35 ? 'trim' : 'wait')),
        ];
    }
}

sort($scores);
$n = count($scores);
$pct = static function (array $s, float $p) use ($n): ?float {
    if ($n === 0) {
        return null;
    }
    $idx = (int) floor(($n - 1) * $p);
    return (float) $s[$idx];
};

$summary = [
    'weeks' => $weeks,
    'n_samples' => $n,
    'bands' => $bands,
    'score_pct' => [
        'p25' => $pct($scores, 0.25),
        'p50' => $pct($scores, 0.50),
        'p75' => $pct($scores, 0.75),
        'p90' => $pct($scores, 0.90),
        'max' => $n > 0 ? max($scores) : null,
    ],
    'recommendation' => null,
];

$ge70Share = $n > 0 ? $bands['ge70'] / $n : 0;
$summary['recommendation'] = [
    'account1' => '유지: add≥70 / watch≥55 / trim<35 (라벨 ge70 부족, 히스토리 ge70 비중=' . round($ge70Share * 100, 1) . '%)',
    'isa' => '유지: add≥75 / watch≥60 / trim<40 (보수)',
    'note' => $ge70Share < 0.05
        ? 'ge70이 드묾 → 문턱을 낮추기보다 full 라벨·고점수 구간 샘플을 더 모을 것'
        : '히스토리에 ge70 충분 → 라벨 성과와 교차 검증 후 조정',
];

$outDir = $root . '/data/backtests';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$jsonPath = $outDir . '/score-history.json';
file_put_contents($jsonPath, json_encode([
    'summary' => $summary,
    'rows' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = [];
$md[] = '# 임계값 리뷰 (score history)';
$md[] = '';
$md[] = '생성: ' . (new DateTimeImmutable('now', $tz))->format(DATE_ATOM);
$md[] = '';
$md[] = sprintf('CORE 5종목 × 최근 %d주 금요일 as-of 일봉 점수 분포.', $weeks);
$md[] = '';
$md[] = '## 분포';
$md[] = '';
$md[] = '| band | n |';
$md[] = '|------|--:|';
foreach ($bands as $k => $v) {
    $md[] = "| {$k} | {$v} |";
}
$md[] = '';
$md[] = '## 분위수';
$md[] = '';
$md[] = '| p25 | p50 | p75 | p90 | max |';
$md[] = '|----:|----:|----:|----:|----:|';
$md[] = sprintf(
    '| %s | %s | %s | %s | %s |',
    $summary['score_pct']['p25'] ?? '',
    $summary['score_pct']['p50'] ?? '',
    $summary['score_pct']['p75'] ?? '',
    $summary['score_pct']['p90'] ?? '',
    $summary['score_pct']['max'] ?? ''
);
$md[] = '';
$md[] = '## 권고';
$md[] = '';
$md[] = '- **account1**: ' . $summary['recommendation']['account1'];
$md[] = '- **isa**: ' . $summary['recommendation']['isa'];
$md[] = '- ' . $summary['recommendation']['note'];
$md[] = '';
$md[] = '원본: `data/backtests/score-history.json`';
$md[] = '';

$mdPath = $root . '/docs/threshold-review.md';
file_put_contents($mdPath, implode("\n", $md));

echo json_encode([
    'json' => $jsonPath,
    'md' => $mdPath,
    'summary' => $summary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
