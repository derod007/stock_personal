<?php

declare(strict_types=1);

/**
 * 에펨 document_srl 목록을 받아 파싱·entries 병합·curate.
 * 댓글 여러 페이지(cpage)까지 합친다.
 *
 * Usage:
 *   php bin/import_doc_srls.php 10104757537 10108710418
 *   php bin/import_doc_srls.php --refresh 10104757537
 */

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaClient;
use ChartEntryLab\FmkoreaPostParser;

$root = dirname(__DIR__);
$refresh = false;
$rawArgs = array_slice($argv, 1);
$srls = [];
foreach ($rawArgs as $arg) {
    if ($arg === '--refresh') {
        $refresh = true;
        continue;
    }
    $digits = preg_replace('/\D+/', '', $arg) ?? '';
    if ($digits !== '') {
        $srls[] = $digits;
    }
}
$srls = array_values(array_unique($srls));
if ($srls === []) {
    fwrite(STDERR, "Usage: php bin/import_doc_srls.php [--refresh] SRL [SRL...]\n");
    exit(1);
}

$client = new FmkoreaClient($root . '/data/raw/cache', delaySeconds: 1.8);
$parser = new FmkoreaPostParser();
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($root . '/data/entries.json');

$all = [];
$meta = [];
foreach ($srls as $srl) {
    try {
        $pages = $client->fetchDocumentPages($srl, useCache: !$refresh);
        $post = $parser->parsePages($pages, '노라무');
        if (($post['document_srl'] ?? null) === null) {
            $post['document_srl'] = $srl;
        }
        $post['author'] = '노라무';
        $events = $extractor->extract($post);
        // 추출 실패해도 본문+댓글 요약은 structure로라도 남김
        if ($events === []) {
            $blob = trim(
                (string) ($post['title'] ?? '') . "\n"
                . (string) ($post['body'] ?? '') . "\n"
                . implode("\n", $post['author_comments'] ?? [])
            );
            $events[] = [
                'id' => 'fm-' . $srl . '-stub',
                'source_url' => 'https://www.fmkorea.com/' . $srl,
                'document_srl' => $srl,
                'title' => (string) ($post['title'] ?? ''),
                'posted_at_kst' => $post['posted_at_kst'] ?? null,
                'author' => '노라무',
                'symbol' => 'UNKNOWN',
                'side' => 'observe',
                'entry_price' => null,
                'tags' => ['structure_or_view', 'stub', 'with_comments'],
                'raw_quote' => mb_substr(preg_replace('/\s+/u', ' ', $blob) ?? $blob, 0, 500),
                'product_type' => 'unknown',
                'learning_use' => 'needs_review',
                'learning_reasons' => ['no_signal_extracted'],
                'source' => 'fmkorea_scraper',
                'author_comments' => $post['author_comments'] ?? [],
                'comment_count' => count($post['comments'] ?? []),
                'image_count' => count($post['images'] ?? []),
                'collected_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
            ];
        } else {
            foreach ($events as &$ev) {
                $ev['author'] = '노라무';
                $ev['author_comments'] = $post['author_comments'] ?? [];
                $ev['comment_count'] = count($post['comments'] ?? []);
                $ev['image_count'] = count($post['images'] ?? []);
                $archiveRel = 'data/important_posts/' . $srl . '.json';
                if (is_file($root . '/' . $archiveRel)) {
                    $ev['important_post_archive'] = $archiveRel;
                }
                $tags = is_array($ev['tags'] ?? null) ? $ev['tags'] : [];
                if (($post['author_comments'] ?? []) !== []) {
                    $tags[] = 'from_post_comments';
                }
                $ev['tags'] = array_values(array_unique($tags));
            }
            unset($ev);
        }

        $meta[] = [
            'srl' => $srl,
            'title' => $post['title'] ?? '',
            'pages' => count($pages),
            'author_comments' => count($post['author_comments'] ?? []),
            'comments' => count($post['comments'] ?? []),
            'images' => count($post['images'] ?? []),
            'events' => count($events),
        ];
        echo sprintf(
            "%s\t%s\tpages=%d\tcomments=%d\tevents=%d\n",
            $srl,
            (string) ($post['title'] ?? ''),
            count($pages),
            count($post['author_comments'] ?? []),
            count($events)
        );
        foreach ($events as $e) {
            $all[] = $e;
        }
    } catch (Throwable $e) {
        echo "{$srl}\tERR\t{$e->getMessage()}\n";
        $meta[] = ['srl' => $srl, 'error' => $e->getMessage()];
    }
}

$added = $repo->mergeMany($all);
$curated = (new EntryCurator())->curate($repo->all());
$repo->writeAll($curated['entries']);

echo json_encode([
    'meta' => $meta,
    'events' => count($all),
    'merge_touch' => $added,
    'summary' => $curated['summary'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
