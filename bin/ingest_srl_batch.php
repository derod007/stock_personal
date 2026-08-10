<?php

declare(strict_types=1);

/**
 * SRL 배치: 가치 글 → entries, 나머지는 parked 카탈로그.
 *
 * Usage:
 *   php bin/ingest_srl_batch.php
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaClient;
use ChartEntryLab\FmkoreaPostParser;

$root = dirname(__DIR__);

/** @var list<string> */
$valuable = [
    '10016561104', // 기법 (엔벨·저점상향)
    '10005003740', // 하닉 단순분석
    '10002894034', // SOXS 3.36
    '9986794614',  // 하닉 손절≈시가
    '10014269367', // 국장↓·미장/환 대응 태도
];

/** @var list<string> */
$parked = [
    '10016671120',
    '9989270606',
    '9989208253',
    '9986481034',
    '9946184026',
    '9944146106',
];

/** @var list<string> */
$analyzeNext = [
    '9933313080',
    '9933222183',
    '9932310089',
    '9915741049',
    '9913498520',
];

$client = new FmkoreaClient($root . '/data/raw/cache', delaySeconds: 1.3);
$parser = new FmkoreaPostParser();
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($root . '/data/entries.json');
$curator = new EntryCurator();

$fetch = static function (string $srl) use ($client, $parser): array {
    $html = $client->fetchDocument($srl, useCache: true);
    $post = $parser->parse($html);
    if (($post['document_srl'] ?? null) === null) {
        $post['document_srl'] = $srl;
    }
    return $post;
};

$parkedDir = $root . '/data/parked';
if (!is_dir($parkedDir)) {
    mkdir($parkedDir, 0777, true);
}

$parkedRows = [];
$importEvents = [];

foreach ($valuable as $srl) {
    try {
        $post = $fetch($srl);
        $events = $extractor->extract($post);

        // SOXS 단가 한 줄 ("soxs 3.36") 수동 보강
        if ($srl === '10002894034' && $events === []) {
            $events[] = [
                'id' => 'fm-10002894034-soxs-336',
                'source_url' => 'https://www.fmkorea.com/10002894034',
                'document_srl' => '10002894034',
                'posted_at_kst' => $post['posted_at_kst'] ?? '2026-06-25T22:40:00+09:00',
                'author' => '노라무',
                'symbol' => 'SOXS',
                'related_underlying' => 'MU',
                'side' => 'long',
                'entry_price' => 3.36,
                'entry_price_ref' => 'raw_quote',
                'stop_price' => null,
                'target_price' => null,
                'product_type' => 'leveraged_etf',
                'tags' => ['parsed_entry', 'manual_soxs_line'],
                'raw_quote' => trim(($post['title'] ?? '') . "\n" . ($post['body'] ?? '')),
                'title' => $post['title'] ?? '',
                'source' => 'manual_seed_from_srl',
                'symbol_note' => '본문 soxs 3.36 → curate 시 MU 숏 바이어스',
            ];
        }

        // 하닉 손절 258 → 258만원 스케일 보강
        if ($srl === '10005003740') {
            foreach ($events as &$ev) {
                if (($ev['symbol'] ?? '') === '000660.KS' || str_contains((string) ($ev['raw_quote'] ?? ''), '하이닉스')) {
                    $ev['symbol'] = '000660.KS';
                    $ev['related_underlying'] = '000660.KS';
                    $ev['product_type'] = 'kr_stock';
                    $ev['stop_price'] = 2580000.0;
                    $ev['stop_price_ref'] = '258만_라인';
                    $ev['side'] = 'observe';
                    $ev['tags'] = array_values(array_unique(array_merge(
                        array_map('strval', $ev['tags'] ?? []),
                        ['hynix_watch', 'no_entry_yet']
                    )));
                    $ev['symbol_note'] = '손절/지지 258만 · 당시는 진입 안 함(엔벨·저점상향 후 검토)';
                }
            }
            unset($ev);
        }

        if ($srl === '9986794614' && $events === []) {
            $events[] = [
                'id' => 'fm-9986794614-hynix-stop-open',
                'source_url' => 'https://www.fmkorea.com/9986794614',
                'document_srl' => '9986794614',
                'posted_at_kst' => $post['posted_at_kst'] ?? '2026-06-22T10:33:00+09:00',
                'author' => '노라무',
                'symbol' => '000660.KS',
                'related_underlying' => '000660.KS',
                'side' => 'observe',
                'entry_price' => null,
                'stop_price' => null,
                'stop_rule' => 'today_open_or_scalp',
                'target_price' => null,
                'product_type' => 'kr_stock',
                'tags' => ['structure_or_view', 'hynix_stop_hint'],
                'raw_quote' => trim(($post['title'] ?? '') . "\n" . ($post['body'] ?? '')),
                'title' => $post['title'] ?? '',
                'source' => 'manual_seed_from_srl',
                'symbol_note' => '손절≈당일 시가 또는 스캘핑',
            ];
        }

        echo "VALUABLE {$srl} events=" . count($events) . ' ' . ($post['title'] ?? '') . "\n";
        foreach ($events as $e) {
            $importEvents[] = $e;
        }

        file_put_contents(
            $parkedDir . '/valuable_' . $srl . '.json',
            json_encode([$post], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $e) {
        echo "VALUABLE ERR {$srl}: {$e->getMessage()}\n";
    }
}

$added = $repo->mergeMany($importEvents);
$curated = $curator->curate($repo->all());
$repo->writeAll($curated['entries']);
echo "imported events=" . count($importEvents) . " merge={$added}\n";

foreach ($parked as $srl) {
    try {
        $post = $fetch($srl);
        $body = preg_replace('/\s+/u', ' ', (string) ($post['body'] ?? '')) ?? '';
        $row = [
            'document_srl' => $srl,
            'url' => 'https://www.fmkorea.com/' . $srl,
            'title' => $post['title'] ?? '',
            'posted_at_kst' => $post['posted_at_kst'] ?? null,
            'body_preview' => mb_substr($body, 0, 280),
            'status' => 'parked',
            'why_parked' => match ($srl) {
                '10016671120' => '시총 차트 잡담·레벨 없음',
                '9989270606' => '미장 추천이나 티커 미상(42.7 손절만)',
                '9989208253' => '조건부 나스닥 숏뷰·시황',
                '9986481034' => '방산 섹터만·코어 외',
                '9946184026' => '대한항공·코어 외 (방식만 나중에)',
                '9944146106' => '거시 재료 추측만',
                default => '우선순위 낮음',
            },
            'reuse_when' => match ($srl) {
                '9989270606' => '티커 확인되면 full 시드',
                '9946184026' => '대한항공/항공 유니버스 확장 시',
                '9986481034' => '방산 섹터 추적 시',
                '9989208253' => '지수 숏 바이어스 라벨 필요할 때',
                default => '시황 맥락 보강이 필요할 때',
            },
        ];
        $parkedRows[] = $row;
        file_put_contents(
            $parkedDir . '/post_' . $srl . '.json',
            json_encode([$post], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        echo "PARKED {$srl} " . ($post['title'] ?? '') . "\n";
    } catch (Throwable $e) {
        $parkedRows[] = ['document_srl' => $srl, 'status' => 'parked_fetch_error', 'error' => $e->getMessage()];
        echo "PARKED ERR {$srl}: {$e->getMessage()}\n";
    }
}

$analyzeReport = [];
foreach ($analyzeNext as $srl) {
    try {
        $post = $fetch($srl);
        $events = $curator->curate($extractor->extract($post))['entries'];
        $body = preg_replace('/\s+/u', ' ', (string) ($post['body'] ?? '')) ?? '';
        $analyzeReport[] = [
            'document_srl' => $srl,
            'url' => 'https://www.fmkorea.com/' . $srl,
            'title' => $post['title'] ?? '',
            'posted_at_kst' => $post['posted_at_kst'] ?? null,
            'body' => mb_substr($body, 0, 550),
            'events' => array_map(static fn(array $e): array => [
                'symbol' => $e['symbol'] ?? null,
                'source_instrument' => $e['source_instrument'] ?? null,
                'side' => $e['side'] ?? null,
                'proxy_bias' => $e['proxy_bias'] ?? null,
                'use' => $e['learning_use'] ?? null,
                'entry' => $e['entry_price'] ?? $e['leveraged_entry_price'] ?? null,
                'stop' => $e['stop_price'] ?? $e['leveraged_stop_price'] ?? null,
                'target' => $e['target_price'] ?? null,
                'quote' => mb_substr((string) ($e['raw_quote'] ?? ''), 0, 160),
            ], $events),
        ];
        file_put_contents(
            $parkedDir . '/analyze_' . $srl . '.json',
            json_encode([$post], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        echo "ANALYZE {$srl} " . ($post['title'] ?? '') . "\n";
    } catch (Throwable $e) {
        $analyzeReport[] = ['document_srl' => $srl, 'error' => $e->getMessage()];
        echo "ANALYZE ERR {$srl}: {$e->getMessage()}\n";
        sleep(8);
    }
}

file_put_contents(
    $parkedDir . '/catalog.json',
    json_encode([
        'updated_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
        'parked' => $parkedRows,
        'valuable_imported' => $valuable,
        'latest_analyze' => $analyzeReport,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$md = [];
$md[] = '# 보류 글 카탈로그 (parked)';
$md[] = '';
$md[] = '가치 낮은 글은 entries에 넣지 않고 여기·`data/parked/`에만 보관. 조건이 맞으면 import.';
$md[] = '';
$md[] = '| SRL | 날짜 | 제목 | 보류 이유 | 다시 쓸 때 |';
$md[] = '|-----|------|------|-----------|------------|';
foreach ($parkedRows as $r) {
    if (($r['status'] ?? '') !== 'parked') {
        continue;
    }
    $md[] = sprintf(
        '| [%s](%s) | %s | %s | %s | %s |',
        $r['document_srl'],
        $r['url'],
        substr((string) ($r['posted_at_kst'] ?? ''), 0, 10),
        str_replace('|', '/', (string) ($r['title'] ?? '')),
        $r['why_parked'] ?? '',
        $r['reuse_when'] ?? ''
    );
}
$md[] = '';
$md[] = '원본 JSON: `data/parked/post_*.json`, 목록: `data/parked/catalog.json`';
$md[] = '';
file_put_contents($root . '/docs/parked-posts.md', implode("\n", $md));

file_put_contents(
    $parkedDir . '/analyze_batch3.json',
    json_encode($analyzeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo json_encode([
    'curate_summary' => $curated['summary'],
    'parked_count' => count($parkedRows),
    'analyze_count' => count($analyzeReport),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
