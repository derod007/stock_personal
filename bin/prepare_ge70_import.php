<?php

declare(strict_types=1);

/**
 * ge70-label-hunt 상위 구간에 대해 브라우저 import용 JSON 템플릿을 만든다.
 *
 * Usage:
 *   php bin/prepare_ge70_import.php [--limit=8]
 *
 * 채운 뒤:
 *   php bin/import_json_posts.php data/raw/ge70_import/batch.json
 */

require __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$limit = 8;
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

$dir = $root . '/data/raw/ge70_import';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$alias = [
    'MU' => 'MU',
    'SNDK' => 'SNDK',
    '000660.KS' => '하이닉스',
    '005930.KS' => '삼성전자',
    '084370.KQ' => '유진테크',
];

$batch = [];
$readme = [];
$readme[] = '# ge70 브라우저 import 템플릿';
$readme[] = '';
$readme[] = '생성: ' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM);
$readme[] = '';
$readme[] = '각 stub의 `body`에 원글 본문·댓글을 붙여 넣고, `document_srl`·`posted_at_kst`·`title`을 맞춘 뒤';
$readme[] = '`php bin/import_json_posts.php data/raw/ge70_import/batch.json` 실행.';
$readme[] = '';

foreach (array_slice($needs, 0, $limit) as $i => $w) {
    if (!is_array($w)) {
        continue;
    }
    $sym = (string) ($w['symbol'] ?? '');
    $from = (string) ($w['cluster_from'] ?? $w['asof'] ?? '');
    $to = (string) ($w['cluster_to'] ?? $w['asof'] ?? '');
    $kw = $alias[$sym] ?? $sym;
    $stubId = sprintf('stub-%02d-%s-%s', $i + 1, preg_replace('/[^A-Za-z0-9._-]/', '', $sym), $from);
    $posted = $from !== '' ? ($from . 'T12:00:00+09:00') : null;
    $stub = [
        'document_srl' => 'REPLACE_WITH_FMKOREA_SRL',
        'title' => sprintf('[수집필요] %s %s~%s ge70', $kw, $from, $to),
        'author' => '노라무',
        'posted_at_kst' => $posted,
        'body' => implode("\n", [
            '여기에 에펨 원글 본문을 붙여넣으세요.',
            '가능하면 진입가·손절·목표가·구조(슈팅/절반/무효화) 문장을 포함.',
            sprintf('힌트: score=%s price=%s invalidation=%s', (string) ($w['score'] ?? ''), (string) ($w['price'] ?? ''), (string) ($w['invalidation'] ?? '')),
        ]),
        'author_comments' => [],
        '_hunt' => [
            'symbol' => $sym,
            'cluster_from' => $from,
            'cluster_to' => $to,
            'score' => $w['score'] ?? null,
            'search_url' => $w['search_url'] ?? null,
        ],
    ];
    $batch[] = $stub;
    $file = $dir . '/' . $stubId . '.json';
    file_put_contents($file, json_encode([$stub], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $readme[] = sprintf(
        '- `%s` · [%s %s~%s](%s)',
        basename($file),
        $sym,
        $from,
        $to,
        (string) ($w['search_url'] ?? '#')
    );
}

$batchPath = $dir . '/batch.json';
file_put_contents($batchPath, json_encode($batch, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($dir . '/README.md', implode("\n", $readme) . "\n");

echo json_encode([
    'dir' => $dir,
    'batch' => $batchPath,
    'stubs' => count($batch),
    'top' => array_map(static fn(array $s): array => [
        'title' => $s['title'],
        'search_url' => $s['_hunt']['search_url'] ?? null,
    ], $batch),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
