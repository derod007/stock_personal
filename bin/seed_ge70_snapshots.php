<?php

declare(strict_types=1);

/**
 * find_ge70_windows 결과 중 needs_label 클러스터를
 * engine_snapshot 라벨로 적재 (노라무 글이 아님 — 백테스트/분포용).
 *
 * tags에 engine_ge70_snapshot. curate 후 learning_use=structure_only (원글 아님).
 * Usage: php bin/seed_ge70_snapshots.php [--limit=10]
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;

$root = dirname(__DIR__);
$limit = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, strlen('--limit=')));
    }
}

$path = $root . '/data/backtests/ge70-windows.json';
if (!is_file($path)) {
    fwrite(STDERR, "Run php bin/find_ge70_windows.php first.\n");
    exit(1);
}

/** @var array<string,mixed> $data */
$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$needs = $data['needs_label'] ?? [];
if (!is_array($needs) || $needs === []) {
    echo "No needs_label windows.\n";
    exit(0);
}

$seeds = [];
foreach (array_slice($needs, 0, $limit) as $w) {
    if (!is_array($w)) {
        continue;
    }
    $sym = (string) ($w['symbol'] ?? '');
    $asof = (string) ($w['asof'] ?? '');
    if ($sym === '' || $asof === '') {
        continue;
    }
    $id = 'snap-ge70-' . preg_replace('/[^A-Za-z0-9._-]/', '', $sym) . '-' . $asof;
    $product = str_contains($sym, '.') ? 'kr_stock' : 'us_stock';
    $seeds[] = [
        'id' => $id,
        'source_url' => null,
        'posted_at_kst' => $asof . 'T15:00:00+09:00',
        'author' => 'engine_snapshot',
        'symbol' => $sym,
        'related_underlying' => $sym,
        'side' => 'long',
        'entry_price' => $w['price'] ?? null,
        'entry_price_ref' => 'engine_asof_close',
        'stop_price' => $w['invalidation'] ?? null,
        'target_price' => null,
        'product_type' => $product,
        'tags' => ['engine_ge70_snapshot', 'not_noramu_post', 'structure_level'],
        'raw_quote' => sprintf(
            '[engine] %s asof %s score=%s half=%s invalidation=%s cluster=%s~%s',
            $sym,
            $asof,
            (string) ($w['score'] ?? ''),
            (string) ($w['half_retrace'] ?? ''),
            (string) ($w['invalidation'] ?? ''),
            (string) ($w['cluster_from'] ?? $asof),
            (string) ($w['cluster_to'] ?? $asof)
        ),
        'source' => 'engine_ge70_snapshot',
        'symbol_note' => '노라무 글이 아님. 고점수 구간 엔진 스냅샷 — 성과 분포/백테스트 보강용',
    ];
}

$repo = new EntryRepository($root . '/data/entries.json');
$added = $repo->mergeMany($seeds);
$curator = new EntryCurator();
$result = $curator->curate($repo->all());
$repo->writeAll($result['entries']);

echo "snapshots_considered=" . count($seeds) . " merge_newish={$added}\n";
foreach ($result['summary'] as $k => $v) {
    echo "  {$k}={$v}\n";
}
