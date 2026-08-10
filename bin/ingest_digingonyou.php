<?php

declare(strict_types=1);

/**
 * 디깅온유 지정 SRL → data/alpha/digingonyou_entries.json
 *
 * Usage:
 *   php bin/ingest_digingonyou.php
 *   php bin/ingest_digingonyou.php --refresh
 *   php bin/ingest_digingonyou.php --dry-run
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaClient;
use ChartEntryLab\FmkoreaPostParser;

$root = dirname(__DIR__);
$refresh = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--refresh') {
        $refresh = true;
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/ingest_digingonyou.php [--refresh] [--dry-run]\n";
        exit(0);
    }
}

$srlListPath = $root . '/data/alpha/digingonyou_srls.json';
/** @var list<string> $srls */
$srls = json_decode((string) file_get_contents($srlListPath), true, 512, JSON_THROW_ON_ERROR);
$srls = array_values(array_unique(array_map('strval', $srls)));

$nick = '디깅온유';
$outPath = $root . '/data/alpha/digingonyou_entries.json';
$cacheDir = $root . '/data/alpha/raw';

$client = new FmkoreaClient($cacheDir, delaySeconds: 2.2);
$parser = new FmkoreaPostParser();
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($outPath);

$stats = [
    'nick' => $nick,
    'srls' => count($srls),
    'fetched' => 0,
    'events_extracted' => 0,
    'stub_needs_review' => 0,
    'errors' => [],
];

$importEvents = [];

foreach ($srls as $srl) {
    try {
        $html = $client->fetchDocument($srl, useCache: !$refresh);
        $stats['fetched']++;
        $post = $parser->parse($html, $nick);
        if (($post['document_srl'] ?? null) === null) {
            $post['document_srl'] = $srl;
        }
        $post['author'] = $nick;

        $events = $extractor->extract($post);
        if ($events === []) {
            $body = (string) ($post['body'] ?? '');
            $summary = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 180);
            $events[] = [
                'id' => 'dgo-' . $srl . '-stub',
                'source_url' => 'https://www.fmkorea.com/' . $srl,
                'document_srl' => $srl,
                'title' => (string) ($post['title'] ?? ''),
                'posted_at_kst' => $post['posted_at_kst'] ?? null,
                'author' => $nick,
                'source_author' => $nick,
                'symbol' => 'UNKNOWN',
                'side' => 'observe',
                'entry_price' => null,
                'stop_price' => null,
                'target_price' => null,
                'product_type' => 'unknown',
                'tags' => ['digingonyou', 'stub', 'needs_review'],
                'raw_quote' => $summary !== '' ? $summary : (string) ($post['title'] ?? ''),
                'learning_use' => 'needs_review',
                'learning_reasons' => ['no_signal_extracted'],
                'source' => 'digingonyou_ingest',
                'collected_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
            ];
            $stats['stub_needs_review']++;
        } else {
            foreach ($events as &$ev) {
                $ev['author'] = $nick;
                $ev['source_author'] = $nick;
                $ev['source'] = 'digingonyou_ingest';
                $tags = is_array($ev['tags'] ?? null) ? $ev['tags'] : [];
                if (!in_array('digingonyou', $tags, true)) {
                    $tags[] = 'digingonyou';
                }
                $ev['tags'] = $tags;
                if (!isset($ev['learning_use'])) {
                    $ev['learning_use'] = 'structure_only';
                }
            }
            unset($ev);
            $stats['events_extracted'] += count($events);
        }

        foreach ($events as $ev) {
            $importEvents[] = $ev;
        }
    } catch (Throwable $e) {
        $stats['errors'][] = $srl . ': ' . $e->getMessage();
        // 차단·실패해도 탭 목록에 보이도록 stub 보관
        $importEvents[] = [
            'id' => 'dgo-' . $srl . '-fetch-stub',
            'source_url' => 'https://www.fmkorea.com/' . $srl,
            'document_srl' => $srl,
            'title' => '(미수집) 디깅온유 ' . $srl,
            'posted_at_kst' => null,
            'author' => $nick,
            'source_author' => $nick,
            'symbol' => 'UNKNOWN',
            'side' => 'observe',
            'entry_price' => null,
            'stop_price' => null,
            'target_price' => null,
            'product_type' => 'unknown',
            'tags' => ['digingonyou', 'stub', 'fetch_failed', 'needs_review'],
            'raw_quote' => $e->getMessage(),
            'learning_use' => 'needs_review',
            'learning_reasons' => ['fetch_failed'],
            'source' => 'digingonyou_ingest',
            'collected_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
        ];
        $stats['stub_needs_review']++;
    }
}

if (!$dryRun) {
    $added = $repo->mergeMany($importEvents);
    $stats['events_merged_new'] = $added;
    $stats['events_total'] = count($repo->all());
    $stats['path'] = $outPath;
} else {
    $stats['dry_run'] = true;
    $stats['events_would_write'] = count($importEvents);
}

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($stats['errors'] === [] ? 0 : 1);
