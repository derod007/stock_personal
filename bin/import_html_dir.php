<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use ChartEntryLab\EntryCurator;
use ChartEntryLab\EntryRepository;
use ChartEntryLab\EntrySignalExtractor;
use ChartEntryLab\FmkoreaPostParser;

/**
 * 브라우저로 저장한 게시글 HTML 디렉터리를 파싱해 entries.json에 병합.
 * 본문+작성자 댓글을 import_doc_srls와 같은 방식으로 남긴다.
 *
 * Usage:
 *   php bin/import_html_dir.php data/raw/browser_posts
 */

$root = dirname(__DIR__);
$dir = $argv[1] ?? ($root . '/data/raw/browser_posts');
if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(1);
}

$parser = new FmkoreaPostParser();
$extractor = new EntrySignalExtractor();
$repo = new EntryRepository($root . '/data/entries.json');

$files = glob($dir . '/*.{html,htm}', GLOB_BRACE) ?: [];
$allEvents = [];
foreach ($files as $file) {
    $html = (string) file_get_contents($file);
    $post = $parser->parse($html, '노라무');
    $srl = (string) ($post['document_srl'] ?? '');
    if ($srl === '' && preg_match('/(\d{8,})/', basename($file), $m) === 1) {
        $srl = $m[1];
        $post['document_srl'] = $srl;
    }
    $post['author'] = '노라무';
    $events = $extractor->extract($post);
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
            'tags' => ['structure_or_view', 'stub', 'with_comments', 'browser_html'],
            'raw_quote' => mb_substr(preg_replace('/\s+/u', ' ', $blob) ?? $blob, 0, 500),
            'product_type' => 'unknown',
            'learning_use' => 'needs_review',
            'learning_reasons' => ['no_signal_extracted'],
            'source' => 'fmkorea_scraper',
            'author_comments' => $post['author_comments'] ?? [],
            'collected_at_kst' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format(DATE_ATOM),
            'import_file' => basename($file),
        ];
    } else {
        foreach ($events as &$ev) {
            $ev['author'] = '노라무';
            $ev['author_comments'] = $post['author_comments'] ?? [];
            $ev['import_file'] = basename($file);
            $tags = is_array($ev['tags'] ?? null) ? $ev['tags'] : [];
            $tags[] = 'browser_html';
            if (($post['author_comments'] ?? []) !== []) {
                $tags[] = 'from_post_comments';
            }
            $ev['tags'] = array_values(array_unique($tags));
        }
        unset($ev);
    }

    echo sprintf(
        "%s\t%s\tcomments=%d\tevents=%d\n",
        basename($file),
        (string) ($post['title'] ?? ''),
        count($post['author_comments'] ?? []),
        count($events)
    );
    foreach ($events as $e) {
        $allEvents[] = $e;
    }
}

$added = $repo->mergeMany($allEvents);
$curated = (new EntryCurator())->curate($repo->all());
$repo->writeAll($curated['entries']);

echo json_encode([
    'files' => count($files),
    'events' => count($allEvents),
    'merge_touch' => $added,
    'summary' => $curated['summary'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
