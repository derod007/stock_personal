<?php

declare(strict_types=1);

/**
 * CORE 종목에서 pullback_long_score≥70 이었던 일봉 as-of 날짜를 뽑고,
 * 근처 entries(±3일)와 매칭해 “라벨 수집 후보”를 만든다.
 *
 * Usage:
 *   php bin/find_ge70_windows.php [--weeks=20] [--min-score=70]
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\Account1Playbook;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\FeatureEngine;
use ChartEntryLab\LearnedLevels;
use ChartEntryLab\YahooChartClient;

$root = dirname(__DIR__);
$weeks = 20;
$minScore = 70;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--weeks=')) {
        $weeks = max(4, (int) substr($arg, strlen('--weeks=')));
    }
    if (str_starts_with($arg, '--min-score=')) {
        $minScore = max(55, (int) substr($arg, strlen('--min-score=')));
    }
}

$client = new YahooChartClient($root . '/data/ohlcv');
$engine = new FeatureEngine();
$repo = new EntryRepository($root . '/data/entries.json');
$tz = new DateTimeZone('Asia/Seoul');
$now = new DateTimeImmutable('now', $tz);

$days = [];
$cursor = $now;
while (count($days) < $weeks * 5) { // 거래일 대략
    if ((int) $cursor->format('N') <= 5) {
        $days[] = $cursor->setTime(23, 59, 59);
    }
    $cursor = $cursor->modify('-1 day');
}
$days = array_reverse($days);

$entries = $repo->all();
$learned = new LearnedLevels();
$windows = [];

$aliasHint = [
    'MU' => 'MU',
    'SNDK' => 'SNDK',
    '000660.KS' => '하이닉스',
    '005930.KS' => '삼성전자',
    '084370.KQ' => '유진테크',
];

foreach (Account1Playbook::CORE_SYMBOLS as $symbol) {
    try {
        $bars = $client->fetch($symbol, '1y', '1d', useCache: true);
    } catch (Throwable $e) {
        fwrite(STDERR, "{$symbol}: {$e->getMessage()}\n");
        continue;
    }

    foreach ($days as $asOf) {
        $slice = array_values(array_filter(
            $bars,
            static fn(array $b): bool => new DateTimeImmutable($b['time_kst'], $tz) <= $asOf
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
        if ($score < $minScore) {
            continue;
        }

        $asOfDate = $asOf->format('Y-m-d');
        $nearbyNoramu = [];
        $nearbyEngine = [];
        foreach ($entries as $e) {
            $esym = (string) ($e['related_underlying'] ?? $e['symbol'] ?? '');
            if ($esym !== $symbol) {
                continue;
            }
            $posted = (string) ($e['posted_at_kst'] ?? '');
            if ($posted === '') {
                continue;
            }
            try {
                $pd = new DateTimeImmutable($posted);
            } catch (Throwable) {
                continue;
            }
            $diff = abs($pd->getTimestamp() - $asOf->getTimestamp()) / 86400;
            if ($diff > 3.0) {
                continue;
            }
            $row = [
                'id' => $e['id'] ?? null,
                'learning_use' => $e['learning_use'] ?? null,
                'posted_at_kst' => $posted,
                'side' => $e['side'] ?? null,
                'raw_quote' => mb_substr((string) ($e['raw_quote'] ?? ''), 0, 80),
            ];
            if ($learned->isEngineSnapshot($e)) {
                $nearbyEngine[] = $row;
            } else {
                $nearbyNoramu[] = $row;
            }
        }

        $kw = $aliasHint[$symbol] ?? $symbol;
        $searchUrl = 'https://www.fmkorea.com/search.php?' . http_build_query([
            'mid' => 'stock',
            'search_keyword' => '노라무 ' . $kw,
            'search_target' => 'title_content',
        ], '', '&', PHP_QUERY_RFC3986);

        $windows[] = [
            'symbol' => $symbol,
            'asof' => $asOfDate,
            'score' => $score,
            'price' => $features['price'] ?? null,
            'half_retrace' => $features['half_retrace'] ?? null,
            'invalidation' => $features['invalidation_level'] ?? null,
            'swing_method' => $features['swing_method'] ?? null,
            'nearby_entries' => $nearbyNoramu,
            'nearby_engine_snapshots' => $nearbyEngine,
            'search_url' => $searchUrl,
            'needs_label' => $nearbyNoramu === [],
        ];
    }
}

// 같은 종목 연속 ge70은 첫날+최고점만 남기기(수집 부담 감소)
$collapsed = [];
$bySym = [];
foreach ($windows as $w) {
    $bySym[$w['symbol']][] = $w;
}
foreach ($bySym as $sym => $list) {
    usort($list, static fn(array $a, array $b): int => strcmp($a['asof'], $b['asof']));
    $cluster = [];
    $flushCluster = static function () use (&$cluster, &$collapsed): void {
        if ($cluster === []) {
            return;
        }
        usort($cluster, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $cluster[0];
        $best['cluster_days'] = count($cluster);
        $best['cluster_from'] = $cluster[array_key_last($cluster)]['asof'] < $cluster[0]['asof']
            ? min(array_column($cluster, 'asof'))
            : min(array_column($cluster, 'asof'));
        $best['cluster_to'] = max(array_column($cluster, 'asof'));
        $collapsed[] = $best;
        $cluster = [];
    };
    $prev = null;
    foreach ($list as $w) {
        if ($prev === null) {
            $cluster[] = $w;
            $prev = $w;
            continue;
        }
        $prevTs = strtotime($prev['asof']);
        $curTs = strtotime($w['asof']);
        if (($curTs - $prevTs) <= 4 * 86400) {
            $cluster[] = $w;
        } else {
            $flushCluster();
            $cluster[] = $w;
        }
        $prev = $w;
    }
    $flushCluster();
}

usort($collapsed, static function (array $a, array $b): int {
    if ($a['needs_label'] !== $b['needs_label']) {
        return $a['needs_label'] ? -1 : 1;
    }
    return $b['score'] <=> $a['score'];
});

$need = array_values(array_filter($collapsed, static fn(array $w): bool => $w['needs_label']));
$matched = array_values(array_filter($collapsed, static fn(array $w): bool => !$w['needs_label']));

$out = [
    'generated_at_kst' => $now->format(DATE_ATOM),
    'min_score' => $minScore,
    'weeks' => $weeks,
    'clusters' => count($collapsed),
    'needs_label_count' => count($need),
    'matched_count' => count($matched),
    'needs_label' => $need,
    'matched' => $matched,
];

$dir = $root . '/data/backtests';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$jsonPath = $dir . '/ge70-windows.json';
file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = [];
$md[] = '# ge70 구간 라벨 수집 후보';
$md[] = '';
$md[] = '생성: ' . $out['generated_at_kst'];
$md[] = '';
$md[] = sprintf(
    '일봉 점수 ≥%d 클러스터 %d개 · **노라무 라벨 없음 %d** · 근처 노라무 글 있음 %d',
    $minScore,
    count($collapsed),
    count($need),
    count($matched)
);
$md[] = '';
$md[] = '> 엔진 `engine_ge70_snapshot`은 근처 글로 치지 않음. 원글만 `needs_label`을 끈다.';
$md[] = '';
$md[] = '## 수집 우선 (근처 노라무 글 없음)';
$md[] = '';
$md[] = '| 종목 | 구간 | score | price | invalidation | 검색 |';
$md[] = '|------|------|------:|------:|-------------:|------|';
foreach (array_slice($need, 0, 25) as $w) {
    $md[] = sprintf(
        '| %s | %s~%s | %d | %s | %s | [에펨 검색](%s) |',
        $w['symbol'],
        $w['cluster_from'] ?? $w['asof'],
        $w['cluster_to'] ?? $w['asof'],
        $w['score'],
        (string) ($w['price'] ?? ''),
        (string) ($w['invalidation'] ?? ''),
        (string) ($w['search_url'] ?? '#')
    );
}
$md[] = '';
$md[] = '## 이미 근처 노라무 글 있음 (검수)';
$md[] = '';
$md[] = '| 종목 | asof | score | nearby ids |';
$md[] = '|------|------|------:|------------|';
foreach (array_slice($matched, 0, 20) as $w) {
    $ids = implode(', ', array_map(static fn(array $n): string => (string) ($n['id'] ?? '?'), $w['nearby_entries']));
    $md[] = sprintf('| %s | %s | %d | %s |', $w['symbol'], $w['asof'], $w['score'], $ids);
}
$md[] = '';
$md[] = '## 수집 방법';
$md[] = '';
$md[] = '1. 위 검색 링크로 해당 주 노라무 글(본주·진입·손절·목표가)을 브라우저로 저장';
$md[] = '2. `php bin/prepare_ge70_import.php` 로 템플릿 생성 → `data/raw/ge70_import/*.json` 채우기';
$md[] = '3. `php bin/import_json_posts.php data/raw/ge70_import/filled.json` 또는 `import_html_dir.php`';
$md[] = '4. `php bin/curate_entries.php` → `php bin/backtest_entries.php`';
$md[] = '5. (선택) `php bin/seed_ge70_snapshots.php` 는 엔진 스냅샷만 — 원글 대체 아님';
$md[] = '';
$md[] = '원본 JSON: `data/backtests/ge70-windows.json`';
$md[] = '';

$mdPath = $root . '/docs/ge70-label-hunt.md';
file_put_contents($mdPath, implode("\n", $md));

echo json_encode([
    'json' => $jsonPath,
    'md' => $mdPath,
    'clusters' => count($collapsed),
    'needs_label' => count($need),
    'matched' => count($matched),
    'top_needs' => array_slice($need, 0, 8),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
